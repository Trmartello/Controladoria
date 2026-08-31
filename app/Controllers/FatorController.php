<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Fatores;
use App\Services\Quiz;

/** Fatores das etapas PESTEL, Porter e SWOT, com promoção e notas GUT. */
class FatorController
{
    /**
     * Teto de vozes num pedido de vínculo. Uma pergunta comporta, no limite,
     * `max_ideias` × participantes — algumas centenas numa oficina grande.
     */
    private const MAX_SUGESTOES = 500;

    private const CATEGORIAS = [
        'PESTEL' => ['POLITICO', 'ECONOMICO', 'SOCIAL', 'TECNOLOGICO', 'ECOLOGICO', 'LEGAL'],
        'PORTER' => ['RIVALIDADE', 'NOVOS_ENTRANTES', 'SUBSTITUTOS', 'PODER_FORNECEDORES', 'PODER_CLIENTES'],
        'SWOT'   => ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'],
    ];

    /** Tamanho do enfrentamento na Matriz GUT — lista branca do servidor. */
    private const ESFORCOS = ['PEQUENO', 'MEDIO', 'GRANDE'];

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $etapa  = $_GET['etapa'] ?? '';
        Auth::exigirAcessoPlanejamento($planId);
        if (!isset(self::CATEGORIAS[$etapa])) {
            Json::erro('Etapa inválida.');
        }
        // A análise é anual: com ?ano=YYYY retorna só aquele ano
        $ano = (int)($_GET['ano'] ?? 0);
        $filtroAno = $ano ? ' AND f.ano = ?' : '';
        $params = $ano ? [$planId, $etapa, $ano] : [$planId, $etapa];
        $fatores = Database::todos(
            "SELECT f.*, g.gravidade, g.urgencia, g.tendencia, g.score, g.esforco,
                    o.etapa AS origem_etapa, o.categoria AS origem_categoria,
                    (pr.id IS NOT NULL) AS promovido,
                    pr.id AS promovido_id, pr.categoria AS promovido_categoria,
                    pr.descricao AS promovido_descricao,
                    ci.id AS coleta_item_id, ca.nome AS coleta_autor, co.n AS coleta_vozes,
                    COALESCE(qz.n, 0) AS quiz_vozes,
                    COALESCE(cz.n, 0) AS cruzamentos,
                    ds.o_que AS acao_titulo, ds.projeto_id AS acao_projeto_id
             FROM fator f
             LEFT JOIN desdobramento ds ON ds.id = f.desdobramento_id
             LEFT JOIN gut g ON g.fator_id = f.id
             LEFT JOIN fator o ON o.id = f.promovido_de_id
             LEFT JOIN fator pr ON pr.promovido_de_id = f.id
             -- Uma ideia só por fator: quando a oficina agrupa vozes iguais,
             -- várias ideias apontam para o mesmo fator e o JOIN duplicaria o
             -- card. Só ideia da TEMPESTADE alimenta este selo: ele navega para
             -- a tela da Coleta, e a resposta de quiz não mora lá — o clique
             -- cairia numa lista que não a contém. As vozes da sala vêm em
             -- `quiz_vozes`, contadas à parte (o mesmo par do CenarioController).
             LEFT JOIN coleta_item ci ON ci.id = (
               SELECT MIN(x.id) FROM coleta_item x
               WHERE x.destino_tipo = 'FATOR' AND x.destino_id = f.id
                 AND x.origem = 'TEMPESTADE')
             LEFT JOIN (
               SELECT destino_id, COUNT(*) AS n FROM coleta_item
               WHERE destino_tipo = 'FATOR' AND origem = 'TEMPESTADE'
               GROUP BY destino_id) co ON co.destino_id = f.id
             LEFT JOIN (
               SELECT destino_id, COUNT(*) AS n FROM coleta_item
               WHERE destino_tipo = 'FATOR' AND origem = 'QUIZ'
               GROUP BY destino_id) qz ON qz.destino_id = f.id
             -- Quantos cruzamentos da SWOT citam este fator, dos DOIS lados
             -- (interno e externo): a FK é ON DELETE CASCADE, então excluir o
             -- fator os leva junto — e a tela precisa avisar antes disso.
             LEFT JOIN (
               SELECT fator_id, COUNT(*) AS n FROM (
                 SELECT fator_interno_id AS fator_id FROM swot_cruzamento
                 UNION ALL
                 SELECT fator_externo_id FROM swot_cruzamento) lados
               GROUP BY fator_id) cz ON cz.fator_id = f.id
             LEFT JOIN usuario ca ON ca.id = ci.autor_id
             WHERE f.planejamento_id = ? AND f.etapa = ?{$filtroAno}
             ORDER BY f.categoria, f.id",
            $params
        );

        // `acao_trava` é o que a tela usa para desabilitar o × ANTES do clique.
        // Sai da MESMA consulta com que o servidor recusa a exclusão
        // (`Fatores::acoesQuePrendem`), e por isso as duas nunca discordam.
        // `acao_titulo`, acima, não serve para isso: ele só enxerga o vínculo
        // direto e deixaria passar o promovido e o cruzamento — que são os dois
        // caminhos pelos quais a recusa mais acontece.
        $presos = Fatores::acoesQuePrendem(array_column($fatores, 'id'));
        foreach ($fatores as &$f) {
            $f['acao_trava'] = $presos[(int)$f['id']] ?? null;
        }
        Json::ok($fatores);
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);

        $categoria = $d['categoria'] ?? '';
        $descricao = trim($d['descricao'] ?? '');
        $fator = $id ? $this->exigirFator($id, $planId) : null;
        // Na edição, a ETAPA e o ANO saem da LINHA, nunca do corpo: eles são a
        // identidade do fator, não campos do formulário. Aceitando-os do corpo,
        // um pedido forjado gravava categoria de PESTEL numa linha SWOT — o
        // fator sumia das duas telas (a SWOT filtra por categoria dela, o
        // PESTEL por etapa) e virava órfão invisível, segurando vozes em ACEITO
        // que ninguém mais conseguia desvincular.
        $etapa = $fator ? (string)$fator['etapa'] : (string)($d['etapa'] ?? '');
        if (!isset(self::CATEGORIAS[$etapa]) || !in_array($categoria, self::CATEGORIAS[$etapa], true)) {
            Json::erro('Etapa ou categoria inválida.');
        }
        if ($descricao === '') {
            Json::erro('Informe a descrição do fator.');
        }

        if ($id) {
            $ano = (int)($fator['ano'] ?? 0);
            Database::executar(
                'UPDATE fator SET categoria = ?, descricao = ? WHERE id = ?',
                [$categoria, $descricao, $id]
            );
        } else {
            $ano = (int)($d['ano'] ?? 0);
            if ($ano < 2000 || $ano > 2100) {
                Json::erro('Informe o ano da análise.');
            }
            $id = (int)Database::executar(
                'INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao) VALUES (?, ?, ?, ?, ?)',
                [$planId, $ano, $etapa, $categoria, $descricao]
            );
        }
        $this->vincularSugestoes($d, $id, $planId, $etapa, $categoria, $ano);
        // Sempre, não só ao amarrar: a redação guardada precisa acompanhar as
        // edições seguintes do fator, senão a voz volta com um texto velho
        Quiz::guardarRedacao('FATOR', $id, $descricao);
        Json::ok(['id' => $id]);
    }

    /**
     * Vozes do quiz amarradas a este fator. O front manda o CONJUNTO (como
     * `fatores` na cascata e `sugestoes` no cenário): quem saiu é solto, quem
     * entrou é amarrado. Muitas vozes, um texto — o vínculo registra a origem;
     * o texto do fator é o que o condutor redigiu, que é a regra de aceitar do
     * encontro.
     *
     * Sem a chave `sugestoes` no corpo, nada é tocado — é o que faz uma edição
     * comum do fator preservar as vozes já registradas.
     */
    private function vincularSugestoes(
        array $d, int $id, int $planId, string $etapa, string $categoria, int $ano
    ): void {
        if (!array_key_exists('sugestoes', $d)) {
            return;
        }
        // `fator.ano` nasceu por ALTER e pode ser NULL em linha antiga. Com ano
        // 0 a guarda `qp.ano = ?` não casa com nada: o vínculo falharia em
        // SILÊNCIO — e pior, o "solta quem saiu" abaixo não depende do ano e
        // desamarraria as vozes que já estavam ali. Recusar é honesto; o fator
        // precisa de ano antes de receber voz.
        if ($ano <= 0) {
            Json::erro('Este fator não tem ano definido: edite o ano antes de vincular vozes da sala.');
        }
        $u = Auth::exigirLogin();
        $sugestoes = array_values(array_unique(array_map('intval', (array)$d['sugestoes'])));
        // A lista é medida ANTES de tocar o banco: é a mesma lição de
        // `Quiz::alvosCrus` — `php -S` é single-threaded, e 20 mil ids no corpo
        // seguravam o servidor inteiro por seis segundos.
        if (count($sugestoes) > self::MAX_SUGESTOES) {
            Json::erro('Sugestões demais num pedido só.');
        }
        $marcas = $sugestoes ? implode(',', array_fill(0, count($sugestoes), '?')) : '';
        // Solta quem saiu do conjunto: volta a NOVO, editável de novo pelo autor
        Database::executar(
            "UPDATE coleta_item SET destino_tipo = NULL, destino_id = NULL,
               situacao = 'NOVO', triado_por = NULL, triado_em = NULL
             WHERE destino_tipo = 'FATOR' AND destino_id = ? AND origem = 'QUIZ'
               AND planejamento_id = ?"
            . ($marcas ? " AND id NOT IN ({$marcas})" : ''),
            array_merge([$id, $planId], $sugestoes)
        );
        if (!$sugestoes) {
            return;
        }
        // UM comando para o conjunto inteiro, não um por id: o laço era o
        // amplificador que o teto acima passou a cortar, e a regra é a mesma
        // para todos.
        //
        // A guarda é o ALVO da pergunta, não a rodada: encontros diferentes
        // podem ter perguntado a mesma categoria, e todas essas vozes valem. Ela
        // recusa sugestão de outra CATEGORIA, etapa, ano ou plano, que não seja
        // do quiz, ou que já esteja amarrada a OUTRO fator — roubar o vínculo
        // deixaria o fator de origem perdendo vozes sem ninguém tocar nele.
        Database::executar(
            "UPDATE coleta_item ci
             JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
             SET ci.destino_tipo = 'FATOR', ci.destino_id = ?,
                 ci.situacao = 'ACEITO', ci.triado_por = ?, ci.triado_em = NOW()
             WHERE ci.id IN ({$marcas}) AND ci.planejamento_id = ? AND ci.origem = 'QUIZ'
               AND qp.alvo_tipo = 'FATOR' AND qp.etapa = ? AND qp.categoria = ? AND qp.ano = ?
               AND (ci.destino_id IS NULL
                    OR (ci.destino_tipo = 'FATOR' AND ci.destino_id = ?))",
            array_merge([$id, (int)$u['id']], $sugestoes,
                        [$planId, $etapa, $categoria, $ano, $id])
        );
    }

    /**
     * Fatores encaminhados ao plano de ação e ainda sem ação criada — de
     * QUALQUER etapa (PESTEL, Porter ou SWOT).
     *
     * Espelha ColetaController::aguardandoAcao(): é a mesma fila, lida pela
     * mesma tela de Projetos, e por isso devolve as mesmas chaves que o card
     * de lá já consome (`texto`, `autor`), com a origem declarada em `origem`.
     */
    public function aguardandoAcao(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // `origem` é a ETAPA, e não mais o literal 'SWOT': a fila passou a
        // receber fator de qualquer análise, e é a etapa que decide o selo na
        // tela de Projetos. O campo que fecha o vínculo continua sendo um só
        // (`fator_id`), porque a linha é a mesma tabela.
        Json::ok(Database::todos(
            "SELECT f.id, f.ano, f.etapa, f.categoria, f.descricao AS texto, f.acao_em,
                    COALESCE(u.nome, 'Diagnóstico') AS autor, f.etapa AS origem
             FROM fator f
             LEFT JOIN usuario u ON u.id = f.acao_por
             WHERE f.planejamento_id = ?
               AND f.acao_em IS NOT NULL AND f.desdobramento_id IS NULL
             ORDER BY f.acao_em, f.id",
            [$planId]
        ));
    }

    /**
     * Marca (ou desmarca) um fator como destino "Plano de ação".
     *
     * **Qualquer etapa — PESTEL, Porter ou SWOT.** Até aqui só a SWOT ia ao
     * plano, e a razão era de método: PESTEL e Porter descrevem o ambiente, e
     * obrigá-los a passar pela promoção a um quadrante forçava a síntese que a
     * SWOT existe para fazer.
     *
     * **A regra foi revogada por decisão do cliente** (2026-08-31): na prática,
     * há fator do PESTEL e do Porter que já nasce com dono e prazo — uma
     * mudança de lei com data marcada, um fornecedor que vai sair —, e mandar
     * inventar um quadrante só para poder agir produzia SWOT de fachada. A
     * promoção continua existindo e continua sendo o caminho recomendado quando
     * o fator PRECISA de síntese; o que mudou é que ela deixou de ser
     * obrigatória.
     *
     * O que NÃO mudou: a ação órfã continua proibida. Um fator que virou ação
     * segue travado para exclusão (`Fatores::acoesQuePrendem`), e desmarcar
     * depois de a ação existir continua recusado logo abaixo.
     */
    public function planoAcao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();
        $fator = $this->exigirFator($id, $planId);
        // `marcar` ausente vale como true: o botão da tela só envia o false
        $marcar = !array_key_exists('marcar', $d) || (bool)$d['marcar'];

        if (!$marcar) {
            // Desmarcar depois de a ação existir deixaria a ação sem origem e
            // o fator sem rastro dela. Quem quiser desfazer exclui a ação —
            // a FK ON DELETE SET NULL devolve o fator para a fila sozinho.
            if ($fator['desdobramento_id']) {
                Json::erro('Este fator já virou uma ação no plano. '
                    . 'Exclua a ação em Projetos para desfazer o encaminhamento.');
            }
            Database::executar(
                'UPDATE fator SET acao_em = NULL, acao_por = NULL WHERE id = ?', [$id]
            );
            Json::ok(['acao_em' => null]);
        }

        if ($fator['acao_em']) {
            Json::ok(['acao_em' => $fator['acao_em']]); // já estava na fila
        }
        Database::executar(
            'UPDATE fator SET acao_em = NOW(), acao_por = ? WHERE id = ?',
            [(int)$u['id'], $id]
        );
        Json::ok();
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirFator($id, $planId);
        // A guarda olha o fator E os promovidos a partir dele: o DELETE logo
        // abaixo leva o promovido junto, e é ELE quem costuma carregar o
        // vínculo com a ação — mesmo agora que o PESTEL e o Porter também vão
        // direto ao plano, a promoção à SWOT continua sendo o caminho mais
        // andado. Conferir só `$fator['desdobramento_id']` deixava passar
        // justamente o caso mais comum.
        Fatores::exigirSemAcao([$id], 'Este fator já virou uma ação no plano. '
            . 'Exclua a ação em Projetos antes de excluir o fator.');
        // Solta o vínculo da Coleta (deste fator e do promovido) antes de
        // apagar: sem isso a ideia apontaria para um id morto e o rastreio
        // exibiria link quebrado.
        // A ideia volta a SELECIONADO (mesmo estado do "Desmarcar" em
        // ColetaController::reabrir): deixá-la ACEITO sem destino nenhum a
        // prendia num beco sem saída — sem análise e sem conseguir ser
        // encaminhada de novo.
        Quiz::soltarVozes('FATOR', array_column(Database::todos(
            'SELECT id FROM fator WHERE id = ? OR promovido_de_id = ?', [$id, $id]
        ), 'id'));
        // Excluir o fator de origem leva junto o que foi promovido dele para a
        // SWOT e, com ele, a avaliação na Matriz GUT (FK gut ON DELETE CASCADE)
        Database::executar('DELETE FROM fator WHERE promovido_de_id = ?', [$id]);
        Database::executar('DELETE FROM fator WHERE id = ?', [$id]);
        Json::ok();
    }

    /** Promove um fator PESTEL/Porter para a SWOT (oportunidade ou ameaça). */
    public function promover(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $fator = $this->exigirFator($id, $planId);

        if ($fator['etapa'] === 'SWOT') {
            Json::erro('Este fator já está na SWOT.');
        }
        $quadrante = $d['quadrante'] ?? '';
        if (!in_array($quadrante, self::CATEGORIAS['SWOT'], true)) {
            Json::erro('Informe o quadrante da SWOT.');
        }
        $jaPromovido = Database::um('SELECT id FROM fator WHERE promovido_de_id = ?', [$id]);
        if ($jaPromovido) {
            Json::erro('Este fator já foi promovido para a SWOT.');
        }

        $novoId = (int)Database::executar(
            'INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao, promovido_de_id)
             VALUES (?, ?, \'SWOT\', ?, ?, ?)',
            [$planId, $fator['ano'], $quadrante, $fator['descricao'], $id]
        );
        Json::ok(['id' => $novoId]);
    }

    /** Registra/atualiza as notas GUT de um fator da SWOT. */
    public function avaliarGut(int $fatorId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $fator = $this->exigirFator($fatorId, $planId);
        if ($fator['etapa'] !== 'SWOT') {
            Json::erro('Notas GUT aplicam-se apenas a fatores da SWOT.');
        }
        $g = (int)($d['gravidade'] ?? 0);
        $u = (int)($d['urgencia'] ?? 0);
        $t = (int)($d['tendencia'] ?? 0);
        foreach ([$g, $u, $t] as $nota) {
            if ($nota < 1 || $nota > 5) {
                Json::erro('As notas G, U e T devem estar entre 1 e 5.');
            }
        }
        // O esforço saiu da avaliação: a tela pergunta G, U e T e mais nada, e
        // a letra P/M/G da tabela passou a vir da faixa do score. A coluna
        // continua aqui com as estimativas antigas — e é por isso que o UPDATE
        // só a toca quando o corpo DECLARA o campo: sem essa guarda, reabrir e
        // salvar uma avaliação já feita apagaria calado o esforço registrado
        // antes da mudança (o `?? ''` vira NULL, e VALUES(esforco) o grava).
        $temEsforco = array_key_exists('esforco', $d);
        $esforco = trim((string)($d['esforco'] ?? ''));
        if ($temEsforco && $esforco !== '' && !in_array($esforco, self::ESFORCOS, true)) {
            Json::erro('Esforço inválido.');
        }
        Database::executar(
            'INSERT INTO gut (fator_id, gravidade, urgencia, tendencia, esforco) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gravidade = VALUES(gravidade),
                                     urgencia = VALUES(urgencia),
                                     tendencia = VALUES(tendencia),
                                     esforco = ' . ($temEsforco ? 'VALUES(esforco)' : 'esforco'),
            [$fatorId, $g, $u, $t, $temEsforco && $esforco !== '' ? $esforco : null]
        );
        Json::ok(['score' => $g * $u * $t]);
    }

    /** Apaga as notas GUT de um fator para que a avaliação seja refeita do zero. */
    public function limparGut(int $fatorId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirFator($fatorId, $planId);
        Database::executar('DELETE FROM gut WHERE fator_id = ?', [$fatorId]);
        Json::ok();
    }

    private function exigirFator(int $id, int $planId): array
    {
        $fator = Database::um(
            'SELECT * FROM fator WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$fator) {
            Json::erro('Fator não encontrado neste planejamento.', 404);
        }
        return $fator;
    }
}

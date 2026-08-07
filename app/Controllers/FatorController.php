<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Quiz;

/** Fatores das etapas PESTEL, Porter e SWOT, com promoção e notas GUT. */
class FatorController
{
    private const CATEGORIAS = [
        'PESTEL' => ['POLITICO', 'ECONOMICO', 'SOCIAL', 'TECNOLOGICO', 'ECOLOGICO', 'LEGAL'],
        'PORTER' => ['RIVALIDADE', 'NOVOS_ENTRANTES', 'SUBSTITUTOS', 'PODER_FORNECEDORES', 'PODER_CLIENTES'],
        'SWOT'   => ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'],
    ];

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
        Json::ok(Database::todos(
            "SELECT f.*, g.gravidade, g.urgencia, g.tendencia, g.score,
                    o.etapa AS origem_etapa, o.categoria AS origem_categoria,
                    (pr.id IS NOT NULL) AS promovido,
                    pr.id AS promovido_id, pr.categoria AS promovido_categoria,
                    pr.descricao AS promovido_descricao,
                    ci.id AS coleta_item_id, ca.nome AS coleta_autor, co.n AS coleta_vozes,
                    COALESCE(qz.n, 0) AS quiz_vozes,
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
             LEFT JOIN usuario ca ON ca.id = ci.autor_id
             WHERE f.planejamento_id = ? AND f.etapa = ?{$filtroAno}
             ORDER BY f.categoria, f.id",
            $params
        ));
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);

        $etapa = $d['etapa'] ?? '';
        $categoria = $d['categoria'] ?? '';
        $descricao = trim($d['descricao'] ?? '');
        if (!isset(self::CATEGORIAS[$etapa]) || !in_array($categoria, self::CATEGORIAS[$etapa], true)) {
            Json::erro('Etapa ou categoria inválida.');
        }
        if ($descricao === '') {
            Json::erro('Informe a descrição do fator.');
        }

        if ($id) {
            $fator = $this->exigirFator($id, $planId);
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
        $this->vincularSugestoes($d, $id, $planId, $etapa, $ano);
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
    private function vincularSugestoes(array $d, int $id, int $planId, string $etapa, int $ano): void
    {
        if (!array_key_exists('sugestoes', $d)) {
            return;
        }
        $u = Auth::exigirLogin();
        $sugestoes = array_values(array_unique(array_map('intval', (array)$d['sugestoes'])));
        $marcas = $sugestoes ? implode(',', array_fill(0, count($sugestoes), '?')) : '';
        // Solta quem saiu do conjunto: volta a NOVO, editável de novo pelo autor
        Database::executar(
            "UPDATE coleta_item SET destino_tipo = NULL, destino_id = NULL,
               situacao = 'NOVO', triado_por = NULL, triado_em = NULL
             WHERE destino_tipo = 'FATOR' AND destino_id = ? AND origem = 'QUIZ'"
            . ($marcas ? " AND id NOT IN ({$marcas})" : ''),
            array_merge([$id], $sugestoes)
        );
        foreach ($sugestoes as $sugestaoId) {
            // A guarda é o ALVO da pergunta, não a rodada: encontros diferentes
            // podem ter perguntado a mesma categoria, e todas essas vozes valem.
            // O JOIN recusa sugestão de outra categoria, de outro ano, de outra
            // etapa, de outro plano ou que não seja do quiz.
            Database::executar(
                "UPDATE coleta_item ci
                 JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
                 SET ci.destino_tipo = 'FATOR', ci.destino_id = ?,
                     ci.situacao = 'ACEITO', ci.triado_por = ?, ci.triado_em = NOW()
                 WHERE ci.id = ? AND ci.planejamento_id = ? AND ci.origem = 'QUIZ'
                   AND qp.alvo_tipo = 'FATOR' AND qp.etapa = ? AND qp.ano = ?",
                [$id, (int)$u['id'], $sugestaoId, $planId, $etapa, $ano]
            );
        }
    }

    /**
     * Fatores da SWOT encaminhados ao plano de ação e ainda sem ação criada.
     *
     * Espelha ColetaController::aguardandoAcao(): é a mesma fila, lida pela
     * mesma tela de Projetos, e por isso devolve as mesmas chaves que o card
     * de lá já consome (`texto`, `autor`), com a origem declarada em `origem`.
     */
    public function aguardandoAcao(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        Json::ok(Database::todos(
            "SELECT f.id, f.ano, f.categoria, f.descricao AS texto, f.acao_em,
                    COALESCE(u.nome, 'Diagnóstico') AS autor, 'SWOT' AS origem
             FROM fator f
             LEFT JOIN usuario u ON u.id = f.acao_por
             WHERE f.planejamento_id = ? AND f.etapa = 'SWOT'
               AND f.acao_em IS NOT NULL AND f.desdobramento_id IS NULL
             ORDER BY f.acao_em, f.id",
            [$planId]
        ));
    }

    /**
     * Marca (ou desmarca) um fator da SWOT como destino "Plano de ação".
     *
     * Só a SWOT: PESTEL e Porter descrevem o ambiente e o caminho deles para o
     * plano é a promoção para um quadrante primeiro — deixar que virassem ação
     * direto pularia a síntese que a SWOT existe para fazer.
     */
    public function planoAcao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();
        $fator = $this->exigirFator($id, $planId);
        if ($fator['etapa'] !== 'SWOT') {
            Json::erro('Só um fator da SWOT vai direto para o plano de ação. '
                . 'Promova-o para um quadrante primeiro.');
        }
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
        $fator = $this->exigirFator($id, $planId);
        // Mesma recusa da ideia da Coleta que já virou ação: apagar aqui
        // deixaria a ação no plano sem nenhuma origem, e ninguém saberia de
        // onde ela veio nem por que existe.
        if ($fator['desdobramento_id']) {
            Json::erro('Este fator já virou uma ação no plano. '
                . 'Exclua a ação em Projetos antes de excluir o fator.');
        }
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
        Database::executar(
            'INSERT INTO gut (fator_id, gravidade, urgencia, tendencia) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gravidade = VALUES(gravidade),
                                     urgencia = VALUES(urgencia),
                                     tendencia = VALUES(tendencia)',
            [$fatorId, $g, $u, $t]
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

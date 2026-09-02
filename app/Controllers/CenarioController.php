<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Bloqueio;
use App\Services\Fatores;
use App\Services\Quiz;

class CenarioController
{
    /** Teto de vozes num pedido de vínculo — ver FatorController. */
    private const MAX_SUGESTOES = 500;

    /** O mesmo catálogo do fator, porque o `⇄` daqui cria um fator. */
    private const CATEGORIAS = Fatores::CATEGORIAS;

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // A análise é anual: com ?ano=YYYY retorna só aquele ano
        $ano = (int)($_GET['ano'] ?? 0);
        // O LEFT JOIN traz a origem na Coleta, quando o item nasceu de uma ideia.
        // Só ideias da TEMPESTADE alimentam esse selo: ele navega para a tela da
        // Coleta, e a resposta de quiz não mora lá — o clique cairia numa lista
        // que não a contém. As vozes da sala vêm em `quiz_vozes`, contadas à
        // parte e exibidas como selo próprio.
        // `acao_titulo` alimenta o selo "Virou ação ↗" e a trava do ×. No
        // cenário os dois saem do MESMO vínculo direto — diferente do fator,
        // onde a trava também nasce do promovido e do cruzamento e por isso
        // precisa de consulta própria (`Fatores::acoesQuePrendem`). Aqui só
        // existe um caminho: o item virou ação, ou não virou.
        $sql = "SELECT c.*, ci.id AS coleta_item_id, ca.nome AS coleta_autor, co.n AS coleta_vozes,
                       COALESCE(qz.n, 0) AS quiz_vozes, ds.o_que AS acao_titulo
                FROM cenario_item c
                LEFT JOIN desdobramento ds ON ds.id = c.desdobramento_id
                -- Uma ideia só por item: com vozes agrupadas na oficina, várias
                -- ideias apontam para o mesmo registro e o JOIN duplicaria a linha
                LEFT JOIN coleta_item ci ON ci.id = (
                  SELECT MIN(x.id) FROM coleta_item x
                  WHERE x.destino_tipo = 'CENARIO' AND x.destino_id = c.id
                    AND x.origem = 'TEMPESTADE')
                LEFT JOIN (
                  SELECT destino_id, COUNT(*) AS n FROM coleta_item
                  WHERE destino_tipo = 'CENARIO' AND origem = 'TEMPESTADE'
                  GROUP BY destino_id) co ON co.destino_id = c.id
                LEFT JOIN (
                  SELECT destino_id, COUNT(*) AS n FROM coleta_item
                  WHERE destino_tipo = 'CENARIO' AND origem = 'QUIZ'
                  GROUP BY destino_id) qz ON qz.destino_id = c.id
                LEFT JOIN usuario ca ON ca.id = ci.autor_id
                WHERE c.planejamento_id = ?";
        $params = [$planId];
        if ($ano) {
            $sql .= ' AND c.ano = ?';
            $params[] = $ano;
        }
        // `mover_trava` é a MESMA lista de motivos que `mover()` usa para
        // recusar, e sai da MESMA fonte — aqui, do vínculo com a ação que a
        // consulta acima já trouxe. É o padrão do tema 8: a tela desabilita o
        // botão antes do clique, e não pode remontar a regra por conta própria.
        //
        // Deste lado a lista tem no máximo um motivo, e isso é o desenho, não
        // uma simplificação: o item de cenário não tem GUT, cruzamento,
        // Cascata, Impacto nem promoção. Continua sendo um ARRAY, como o do
        // fator, para a tela ter um formato só a tratar.
        Json::ok(array_map(static function (array $c): array {
            $c['mover_trava'] = $c['acao_titulo']
                ? ['Já virou a ação “' . $c['acao_titulo'] . '” no plano. '
                   . 'Exclua a ação em Projetos antes de mover este item.']
                : [];
            return $c;
        }, Database::todos("$sql ORDER BY c.tipo, c.ordem, c.id", $params)));
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);

        $tipo = $d['tipo'] ?? '';
        $descricao = trim($d['descricao'] ?? '');
        $ordem = (int)($d['ordem'] ?? 0);
        if (!in_array($tipo, ['SITUACAO_ATUAL', 'TENDENCIA'], true) || $descricao === '') {
            Json::erro('Informe o tipo e a descrição.');
        }
        $ano = (int)($d['ano'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            Json::erro('Informe o ano da análise.');
        }

        if ($id) {
            $this->exigirItem($id, $planId);
            Bloqueio::exigirMeu('cenario_item', $id, (int)Auth::exigirLogin()['id'], 'este item');
            Database::executar(
                'UPDATE cenario_item SET tipo = ?, ordem = ?, descricao = ?, ano = ? WHERE id = ?',
                [$tipo, $ordem, $descricao, $ano, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao) VALUES (?, ?, ?, ?, ?)',
                [$planId, $ano, $tipo, $ordem, $descricao]
            );
        }
        $this->vincularSugestoes($d, $id, $planId, $ano);
        // Sempre, não só ao amarrar: ver o comentário em FatorController
        Quiz::guardarRedacao('CENARIO', $id, $descricao);
        Json::ok(['id' => $id]);
    }

    /**
     * Vozes do quiz amarradas a este item do cenário. O front manda o CONJUNTO
     * (como `fatores` na cascata): quem saiu é solto, quem entrou é amarrado.
     * Muitas vozes, um texto — o vínculo registra a origem; o texto do item é o
     * que o condutor redigiu, que é a regra de aceitar do encontro.
     */
    private function vincularSugestoes(array $d, int $id, int $planId, int $ano): void
    {
        if (!array_key_exists('sugestoes', $d)) {
            return;
        }
        // Ano zero não casaria com pergunta nenhuma e o vínculo falharia em
        // silêncio, enquanto o "solta quem saiu" desamarraria o que já estava
        if ($ano <= 0) {
            Json::erro('Informe o ano da análise antes de vincular vozes da sala.');
        }
        $u = Auth::exigirLogin();
        $sugestoes = array_values(array_unique(array_map('intval', (array)$d['sugestoes'])));
        // A lista é medida ANTES de tocar o banco: `php -S` é single-threaded, e
        // um laço de milhares de UPDATEs segura o servidor inteiro. Mesma lição
        // de `Quiz::alvosCrus`.
        if (count($sugestoes) > self::MAX_SUGESTOES) {
            Json::erro('Sugestões demais num pedido só.');
        }
        $marcas = $sugestoes ? implode(',', array_fill(0, count($sugestoes), '?')) : '';
        // Solta quem saiu do conjunto: volta a NOVO, editável de novo pelo autor.
        // O `JOIN quiz_pergunta` restringe o soltar ao que o painel poderia ter
        // oferecido — ver a nota gêmea em `FatorController::vincularSugestoes`:
        // sem ele, a primeira edição soltava calada as vozes que o `⇄` tinha
        // acabado de trazer de um fator.
        Database::executar(
            "UPDATE coleta_item ci
             JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
             SET ci.destino_tipo = NULL, ci.destino_id = NULL,
                 ci.situacao = 'NOVO', ci.triado_por = NULL, ci.triado_em = NULL
             WHERE ci.destino_tipo = 'CENARIO' AND ci.destino_id = ? AND ci.origem = 'QUIZ'
               AND ci.planejamento_id = ?
               AND qp.alvo_tipo = 'CENARIO' AND qp.ano = ?"
            . ($marcas ? " AND ci.id NOT IN ({$marcas})" : ''),
            array_merge([$id, $planId, $ano], $sugestoes)
        );
        if (!$sugestoes) {
            return;
        }
        // UM comando para o conjunto inteiro, não um por id. A guarda é o ALVO
        // da pergunta, não a rodada: encontros diferentes podem ter perguntado o
        // mesmo ano, e todas essas vozes valem. Recusa sugestão de outro ano, de
        // outro plano, de outra análise, que não seja do quiz, ou que já esteja
        // amarrada a OUTRO item — roubar o vínculo faria o item de origem perder
        // vozes sem ninguém tocar nele.
        Database::executar(
            "UPDATE coleta_item ci
             JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
             SET ci.destino_tipo = 'CENARIO', ci.destino_id = ?,
                 ci.situacao = 'ACEITO', ci.triado_por = ?, ci.triado_em = NOW()
             WHERE ci.id IN ({$marcas}) AND ci.planejamento_id = ? AND ci.origem = 'QUIZ'
               AND qp.alvo_tipo = 'CENARIO' AND qp.ano = ?
               AND (ci.destino_id IS NULL
                    OR (ci.destino_tipo = 'CENARIO' AND ci.destino_id = ?))",
            array_merge([$id, (int)$u['id']], $sugestoes, [$planId, $ano, $id])
        );
    }

    /**
     * Itens do cenário encaminhados ao plano de ação e ainda sem ação criada.
     *
     * Espelha `FatorController::aguardandoAcao()` e o do cruzamento: é a MESMA
     * fila, lida pela mesma tela de Projetos, e por isso devolve as mesmas
     * chaves que o card de lá consome (`texto`, `autor`, `origem`). A pergunta
     * que a fila responde continua sendo uma só — "o que ainda não virou
     * ação?" —, e o que muda por origem é o selo e o campo que fecha o vínculo.
     *
     * `categoria` carrega o TIPO (situação atual · tendência), que é o que o
     * selo mostra: sem ele a pendência do cenário chegaria à fila sem dizer se
     * descreve o hoje ou a aposta sobre o amanhã.
     */
    public function aguardandoAcao(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        Json::ok(Database::todos(
            "SELECT c.id, c.ano, c.tipo AS categoria, c.descricao AS texto, c.acao_em,
                    COALESCE(u.nome, 'Diagnóstico') AS autor, 'CENARIO' AS origem
             FROM cenario_item c
             LEFT JOIN usuario u ON u.id = c.acao_por
             WHERE c.planejamento_id = ?
               AND c.acao_em IS NOT NULL AND c.desdobramento_id IS NULL
             ORDER BY c.acao_em, c.id",
            [$planId]
        ));
    }

    /**
     * Marca (ou desmarca) um item do cenário como destino "Plano de ação".
     *
     * O cenário descreve o ambiente — é a análise que menos "pede ação" das
     * quatro, e por isso foi a última a ganhar este caminho. Mas a tendência
     * com data ("o frete sobe em janeiro") é exatamente o tipo de leitura que
     * já nasce sabendo o que fazer, e obrigá-la a virar fator antes só para
     * poder virar ação era o mesmo atrito que fez a regra da SWOT cair.
     *
     * Desmarcar depois de a ação existir continua recusado, pelo mesmo motivo
     * das outras três origens: deixaria a ação no plano sem origem nenhuma.
     * Quem quiser desfazer apaga a ação — a FK ON DELETE SET NULL devolve o
     * item para a fila sozinho.
     */
    public function planoAcao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();
        $item = $this->exigirItem($id, $planId);
        // `marcar` ausente vale como true: o botão da tela só envia o false
        $marcar = !array_key_exists('marcar', $d) || (bool)$d['marcar'];

        if ($item['desdobramento_id']) {
            Json::erro('Este item já virou uma ação no plano. '
                . 'Exclua a ação em Projetos para desfazer o encaminhamento.');
        }
        if (!$marcar) {
            Database::executar(
                'UPDATE cenario_item SET acao_em = NULL, acao_por = NULL
                 WHERE id = ? AND desdobramento_id IS NULL',
                [$id]
            );
            Json::ok(['acao_em' => null]);
        }
        if ($item['acao_em']) {
            Json::ok(['acao_em' => $item['acao_em']]); // já estava na fila
        }
        Database::executar(
            'UPDATE cenario_item SET acao_em = NOW(), acao_por = ?
             WHERE id = ? AND desdobramento_id IS NULL',
            [(int)$u['id'], $id]
        );
        Json::ok();
    }

    /**
     * O `⇄` do item de cenário: leva o item para o PESTEL, o Porter ou a SWOT.
     *
     * Espelha `FatorController::mover`, e é a metade mais simples do par: o
     * item de cenário sustenta bem menos que um fator — as vozes da sala e a
     * marca do plano de ação, e nada mais aponta para ele por chave. Não há
     * GUT, cruzamento, Cascata, Matriz de Impacto nem promoção deste lado, e
     * por isso a única recusa é a que vale em todo o sistema: **já virou ação**.
     *
     * A CATEGORIA é obrigatória pelo mesmo motivo do outro lado: as listas não
     * se correspondem, e "situação atual" não é categoria de análise nenhuma.
     * Sem escolher no destino, o item nasceria com uma categoria que a tela de
     * lá não sabe desenhar e sumiria das duas.
     *
     * Ordem: cria, leva as vozes, apaga — a mesma de `moverParaCenario`, e pelo
     * mesmo motivo (o pior caso vira registro repetido, nunca voz órfã).
     */
    public function mover(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $item = $this->exigirItem($id, $planId);
        Bloqueio::exigirMeu('cenario_item', $id, (int)Auth::exigirLogin()['id'], 'este item');

        $etapa = (string)($d['etapa'] ?? '');
        $categoria = (string)($d['categoria'] ?? '');
        if (!isset(self::CATEGORIAS[$etapa])) {
            Json::erro('Informe a análise de destino.');
        }
        if (!in_array($categoria, self::CATEGORIAS[$etapa], true)) {
            Json::erro('Informe a categoria no destino: as listas das análises não se correspondem.');
        }
        $ano = (int)($item['ano'] ?? 0);
        if ($ano <= 0) {
            Json::erro('Este item não tem ano definido: edite o ano antes de movê-lo.');
        }
        if ($item['desdobramento_id']) {
            $acao = Database::um('SELECT o_que FROM desdobramento WHERE id = ?',
                [(int)$item['desdobramento_id']]);
            Json::erro('Este item já virou uma ação no plano. '
                . 'Mudá-lo de análise trocaria a origem da ação no relatório — '
                . 'exclua a ação em Projetos antes de movê-lo.'
                . ($acao ? ' (ação: “' . $acao['o_que'] . '”)' : ''));
        }

        $novo = (int)Database::executar(
            'INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao, acao_em, acao_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$planId, $ano, $etapa, $categoria, (string)$item['descricao'],
             $item['acao_em'], $item['acao_por']]
        );
        Quiz::mudarDestino('CENARIO', $id, 'FATOR', $novo);
        Database::executar('DELETE FROM cenario_item WHERE id = ? AND planejamento_id = ?', [$id, $planId]);
        Json::ok(['id' => $novo, 'destino' => $etapa, 'categoria' => $categoria, 'ano' => $ano]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $item = $this->exigirItem($id, $planId);
        Bloqueio::exigirMeu('cenario_item', $id, (int)Auth::exigirLogin()['id'], 'este item');
        // Excluir um item que já virou ação é recusado, como no fator e no
        // cruzamento: a ação continuaria viva no plano sem origem nenhuma, e o
        // caminho de volta apontaria para uma linha morta. A FK do
        // `desdobramento_id` é SET NULL e não impediria o DELETE — a recusa
        // aqui é a regra, não uma consequência do banco.
        if ($item['desdobramento_id']) {
            $acao = Database::um('SELECT o_que FROM desdobramento WHERE id = ?',
                [(int)$item['desdobramento_id']]);
            Json::erro('Este item já virou uma ação no plano. '
                . 'Exclua a ação em Projetos antes de excluir o item.'
                . ($acao ? ' (ação: “' . $acao['o_que'] . '”)' : ''));
        }
        // Trata o vínculo da Coleta antes de apagar: sem isso a ideia ficaria
        // apontando para um id morto e o rastreio exibiria link quebrado.
        // A ideia da tempestade volta a SELECIONADO, como o "Desmarcar"
        // (ColetaController::reabrir): ACEITO sem destino nenhum a prendia num
        // beco sem saída. A voz do QUIZ é apagada de vez: excluir o item é
        // descartá-la, não devolvê-la ao painel como sugestão nova
        // (`Quiz::excluirVozes`).
        Quiz::excluirVozes('CENARIO', [$id]);
        Database::executar('DELETE FROM cenario_item WHERE id = ?', [$id]);
        Json::ok();
    }

    /**
     * O item, conferido contra o planejamento do pedido.
     *
     * Devolve a LINHA inteira (era `SELECT id` e `void`): quem confere já
     * precisa dos campos do encaminhamento — `acao_em` e `desdobramento_id` —,
     * e uma segunda consulta para lê-los abriria a janela em que a primeira
     * autoriza e a segunda lê outra coisa.
     */
    private function exigirItem(int $id, int $planId): array
    {
        $item = Database::um(
            'SELECT * FROM cenario_item WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$item) {
            Json::erro('Item não encontrado neste planejamento.', 404);
        }
        return $item;
    }
}

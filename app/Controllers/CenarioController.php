<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Bloqueio;
use App\Services\Quiz;

class CenarioController
{
    /** Teto de vozes num pedido de vínculo — ver FatorController. */
    private const MAX_SUGESTOES = 500;

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
        Json::ok(Database::todos("$sql ORDER BY c.tipo, c.ordem, c.id", $params));
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
        // Solta quem saiu do conjunto: volta a NOVO, editável de novo pelo autor
        Database::executar(
            "UPDATE coleta_item SET destino_tipo = NULL, destino_id = NULL,
               situacao = 'NOVO', triado_por = NULL, triado_em = NULL
             WHERE destino_tipo = 'CENARIO' AND destino_id = ? AND origem = 'QUIZ'
               AND planejamento_id = ?"
            . ($marcas ? " AND id NOT IN ({$marcas})" : ''),
            array_merge([$id, $planId], $sugestoes)
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
        // Solta o vínculo da Coleta antes de apagar: sem isso a ideia ficaria
        // apontando para um id morto e o rastreio exibiria link quebrado.
        // Volta a SELECIONADO, como o "Desmarcar" (ColetaController::reabrir):
        // ACEITO sem destino nenhum prendia a ideia num beco sem saída.
        Quiz::soltarVozes('CENARIO', [$id]);
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

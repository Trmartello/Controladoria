<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Cruzamentos;
use App\Services\Quiz;

/**
 * Cruzamentos da SWOT (TOWS): o par de um fator interno com um externo e a
 * estratégia que nasce dele.
 *
 * A regra que organiza tudo: o BLOCO não é escolhido, é consequência do par.
 * Força + oportunidade só pode ser "atacar"; fraqueza + ameaça só pode ser
 * "proteger". Por isso o tipo é calculado aqui e nunca lido do corpo — aceitar
 * o bloco do cliente permitiria gravar a linha no quadro errado, que é o mesmo
 * defeito que a etapa/ano do fator já custou (ver CLAUDE.md).
 */
class CruzamentoController
{
    private const MAX_ROTULO = 120;

    /** Teto de vozes num pedido de vínculo — ver FatorController. */
    private const MAX_SUGESTOES = 500;

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // A análise é anual, como a SWOT que ela lê
        $ano = (int)($_GET['ano'] ?? 0);
        $filtroAno = $ano ? ' AND c.ano = ?' : '';
        $params = $ano ? [$planId, $ano] : [$planId];

        Json::ok(Database::todos(
            "SELECT c.*,
                    fi.descricao AS interno_descricao, fi.categoria AS interno_categoria,
                    fe.descricao AS externo_descricao, fe.categoria AS externo_categoria,
                    u.nome AS autor,
                    -- Quantas vozes da sala sustentam este cruzamento: o mesmo
                    -- selo 🎤 que o fator e o item de cenário já mostram.
                    COALESCE(qz.n, 0) AS quiz_vozes
             FROM swot_cruzamento c
             LEFT JOIN (
               SELECT destino_id, COUNT(*) AS n FROM coleta_item
               WHERE destino_tipo = 'CRUZAMENTO' AND origem = 'QUIZ'
               GROUP BY destino_id) qz ON qz.destino_id = c.id
             JOIN fator fi ON fi.id = c.fator_interno_id
             JOIN fator fe ON fe.id = c.fator_externo_id
             LEFT JOIN usuario u ON u.id = c.criado_por
             WHERE c.planejamento_id = ?{$filtroAno}
             ORDER BY c.tipo, c.id",
            $params
        ));
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        // exigirEdicaoPlanejamento devolve a linha do PLANEJAMENTO, não o
        // usuário: quem grava `criado_por` precisa do login (ver CLAUDE.md — o
        // id do plano em autor_id já estourou FK em outro controller).
        $u = Auth::exigirLogin();

        $rotulo = trim($d['rotulo'] ?? '');
        $estrategia = trim($d['estrategia'] ?? '');
        if ($rotulo === '') {
            Json::erro('Informe um nome curto para o cruzamento (ex.: "Pecuária + proteína").');
        }
        if (mb_strlen($rotulo) > self::MAX_ROTULO) {
            Json::erro('O nome do cruzamento deve ter até ' . self::MAX_ROTULO . ' caracteres.');
        }
        if ($estrategia === '') {
            Json::erro('Escreva a estratégia — o que fazer com este cruzamento.');
        }

        if ($id) {
            // Na edição o PAR sai da LINHA, nunca do corpo: ele é a identidade
            // do cruzamento (é o que a chave única guarda), não um campo do
            // formulário. Trocar o par pelo corpo mudaria o bloco por baixo de
            // uma linha já discutida; para outro par, outro cruzamento.
            $atual = $this->exigirCruzamento($id, $planId);
            Database::executar(
                'UPDATE swot_cruzamento SET rotulo = ?, estrategia = ? WHERE id = ?',
                [$rotulo, $estrategia, $id]
            );
            // A cada salvamento, como fator e cenário: a redação guardada no
            // vínculo da voz acompanha o texto atual.
            Quiz::guardarRedacao('CRUZAMENTO', $id, $estrategia);
            Json::ok(['id' => (int)$atual['id'], 'tipo' => $atual['tipo']]);
        }

        // A conferência do par mora em `Services\Cruzamentos` desde que a sala
        // do encontro passou a montar cruzamento pelo celular: a MESMA regra
        // roda aqui, com login, e lá, sem. Duas escritas divergiriam, e a
        // frouxa seria a exposta.
        ['interno' => $interno, 'externo' => $externo, 'tipo' => $tipo, 'ano' => $ano] =
            Cruzamentos::parValidado(
                (int)($d['fator_interno_id'] ?? 0),
                (int)($d['fator_externo_id'] ?? 0),
                $planId
            );

        // O par é único por ano. A conferência é antes, para a mensagem ser
        // legível; o catch é o que segura a corrida de dois envios simultâneos,
        // em que os dois passam pelo SELECT e só a chave separa.
        $ja = Database::um(
            'SELECT id FROM swot_cruzamento
             WHERE planejamento_id = ? AND ano = ? AND fator_interno_id = ? AND fator_externo_id = ?',
            [$planId, $ano, $interno['id'], $externo['id']]
        );
        if ($ja) {
            Json::erro('Este par já foi cruzado neste ano. Edite o cruzamento existente.');
        }
        try {
            $novoId = (int)Database::executar(
                'INSERT INTO swot_cruzamento
                   (planejamento_id, ano, fator_interno_id, fator_externo_id, tipo, rotulo, estrategia, criado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$planId, $ano, $interno['id'], $externo['id'], $tipo, $rotulo, $estrategia, $u['id']]
            );
        } catch (\PDOException $e) {
            Json::erro('Este par já foi cruzado neste ano. Edite o cruzamento existente.');
        }
        $this->vincularSugestoes($d, $novoId, $planId, $tipo, $ano);
        Quiz::guardarRedacao('CRUZAMENTO', $novoId, $estrategia);
        Json::ok(['id' => $novoId, 'tipo' => $tipo]);
    }

    /**
     * Vozes da sala amarradas a este cruzamento — o espelho do que a Análise de
     * Cenário e o fator já fazem, com uma diferença que vale registrar: aqui a
     * voz trouxe o PAR junto, e o par já virou a identidade do registro. O que
     * o vínculo guarda é a autoria — quem, na oficina, propôs este encontro.
     *
     * Só no INSERT: o par é a identidade do cruzamento e não muda na edição,
     * então também não há vínculo a refazer.
     */
    private function vincularSugestoes(array $d, int $id, int $planId, string $tipo, int $ano): void
    {
        if (!array_key_exists('sugestoes', $d)) {
            return;
        }
        $u = Auth::exigirLogin();
        $sugestoes = array_values(array_unique(array_map('intval', (array)$d['sugestoes'])));
        if (!$sugestoes) {
            return;
        }
        if (count($sugestoes) > self::MAX_SUGESTOES) {
            Json::erro('Sugestões demais num pedido só.');
        }
        $marcas = implode(',', array_fill(0, count($sugestoes), '?'));
        // A guarda é o ALVO da pergunta, como nas outras telas: recusa voz de
        // outro bloco, de outro ano, de outro plano, que não seja do quiz, ou
        // que já esteja amarrada a OUTRO registro — roubar o vínculo faria o
        // registro de origem perder vozes sem ninguém tocar nele.
        Database::executar(
            "UPDATE coleta_item ci
             JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
             SET ci.destino_tipo = 'CRUZAMENTO', ci.destino_id = ?,
                 ci.situacao = 'ACEITO', ci.triado_por = ?, ci.triado_em = NOW()
             WHERE ci.id IN ({$marcas}) AND ci.planejamento_id = ? AND ci.origem = 'QUIZ'
               AND qp.alvo_tipo = 'CRUZAMENTO' AND qp.categoria = ? AND qp.ano = ?
               AND ci.destino_id IS NULL",
            array_merge([$id, (int)$u['id']], $sugestoes, [$planId, $tipo, $ano])
        );
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $c = $this->exigirCruzamento($id, $planId);
        // Mesma recusa do fator que virou ação: apagar deixaria a ação viva no
        // plano sem origem nenhuma, e o caminho de volta apontaria para uma
        // linha morta. Quem quiser desfazer apaga a AÇÃO — aí o cruzamento
        // volta sozinho para a fila (a FK é ON DELETE SET NULL).
        if ($c['desdobramento_id']) {
            $acao = Database::um('SELECT o_que FROM desdobramento WHERE id = ?', [$c['desdobramento_id']]);
            Json::erro('Este cruzamento já virou ação no plano e não pode ser excluído'
                . ($acao ? ' (ação: “' . $acao['o_que'] . '”)' : '') . '.');
        }
        // Trata as vozes antes de apagar: sem isto elas ficariam ACEITAS em
        // cima de um id morto — congeladas para o autor e "usadas" para o
        // condutor. A voz do QUIZ sai de vez (excluir o cruzamento é
        // descartá-la); a ideia da tempestade volta à matriz. É a mesma regra
        // dos outros caminhos que apagam um registro nascido da sala.
        Quiz::excluirVozes('CRUZAMENTO', [$id]);
        Database::executar('DELETE FROM swot_cruzamento WHERE id = ?', [$id]);
        Json::ok();
    }

    /**
     * Cruzamentos encaminhados ao plano de ação e ainda sem ação criada.
     *
     * Espelha `FatorController::aguardandoAcao()` e `ColetaController` — mesma
     * fila, mesma tela, mesmas chaves (`texto`, `autor`, `origem`). A pergunta
     * que a fila responde é uma só: "o que ainda não virou ação?".
     *
     * O texto é a ESTRATÉGIA, não o rótulo: é ela que descreve o que fazer, e é
     * dela que sai o "o quê" da ação. O rótulo vai junto como contexto.
     */
    public function aguardandoAcao(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        Json::ok(Database::todos(
            "SELECT c.id, c.ano, c.tipo AS categoria, c.rotulo, c.estrategia AS texto, c.acao_em,
                    COALESCE(u.nome, 'Diagnóstico') AS autor, 'TOWS' AS origem
             FROM swot_cruzamento c
             LEFT JOIN usuario u ON u.id = c.acao_por
             WHERE c.planejamento_id = ?
               AND c.acao_em IS NOT NULL AND c.desdobramento_id IS NULL
             ORDER BY c.acao_em, c.id",
            [$planId]
        ));
    }

    /**
     * Marca (ou desmarca) um cruzamento como destino "Plano de ação".
     *
     * O cruzamento vai DIRETO ao plano, sem passar pela cascata: ele já é a
     * estratégia que nasce do par, e a cascata decide outra coisa (em que
     * horizonte cada driver aposta). Fazê-lo virar célula primeiro obrigaria a
     * traduzir uma decisão que já está tomada.
     *
     * Desmarcar só vale enquanto a ação não existe — depois disso o vínculo é
     * de quem apaga a ação, não de quem desmarca aqui, senão a ação ficaria no
     * plano sem origem.
     */
    public function planoAcao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();
        $c = $this->exigirCruzamento($id, $planId);
        // `marcar` ausente vale como true: o botão da tela só envia o false
        $marcar = !array_key_exists('marcar', $d) || (bool)$d['marcar'];

        if ($c['desdobramento_id']) {
            Json::erro('Este cruzamento já virou ação no plano.');
        }
        if ($marcar) {
            Database::executar(
                'UPDATE swot_cruzamento SET acao_em = NOW(), acao_por = ?
                 WHERE id = ? AND desdobramento_id IS NULL',
                [(int)$u['id'], $id]
            );
        } else {
            Database::executar(
                'UPDATE swot_cruzamento SET acao_em = NULL, acao_por = NULL
                 WHERE id = ? AND desdobramento_id IS NULL',
                [$id]
            );
        }
        Json::ok(['acao_em' => $marcar]);
    }

    /** O cruzamento, conferido contra o planejamento do pedido. */
    private function exigirCruzamento(int $id, int $planId): array
    {
        $linha = Database::um(
            'SELECT * FROM swot_cruzamento WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$linha) {
            Json::erro('Cruzamento não encontrado.', 404);
        }
        return $linha;
    }

}

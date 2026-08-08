<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Plano de contingência: a resposta datada a uma ameaça já priorizada no GUT.
 *
 * O sistema já registrava o risco (fator com etapa SWOT e categoria AMEACA) e
 * já o priorizava (score GUT de 1 a 125). O que faltava era a outra metade —
 * "se acontecer, o que fazemos, quem faz, e como sabemos que aconteceu?".
 *
 * Ancorado na ameaça, e não dentro de cada projeto, porque o risco existe
 * mesmo sem projeto nenhum: dentro do projeto ele ficaria repetido em N
 * projetos ou órfão. O caminho é o inverso — quando o gatilho dispara, a
 * resposta VIRA um projeto no motor de execução que já existe.
 *
 * O vocabulário é "checagem na reunião", nunca "alerta": não há cron dentro do
 * app (o entrypoint roda migrate + php -S), e prometer disparo automático
 * criaria a expectativa de que alguém é avisado sem ninguém abrir a tela.
 */
class ContingenciaController
{
    private const SITUACOES = ['MONITORANDO', 'ACIONADO', 'ENCERRADO'];

    /**
     * Todos os planos do planejamento — de todos os anos, de propósito.
     *
     * A contingência é do ciclo: filtrar pelo ano do seletor faria o plano de
     * uma ameaça priorizada em 2026 desaparecer quando a tela virasse 2027,
     * que é exatamente o que a ausência da coluna `ano` existe para evitar. O
     * ano de origem viaja no JOIN e vira rótulo no cartão.
     */
    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        Json::ok(Database::todos(
            "SELECT c.*, f.descricao AS fator_descricao, f.ano AS fator_ano,
                    f.categoria AS fator_categoria, g.score AS gut_score
             FROM contingencia c
             LEFT JOIN fator f ON f.id = c.fator_id
             LEFT JOIN gut g ON g.fator_id = c.fator_id
             WHERE c.planejamento_id = ?
             ORDER BY FIELD(c.situacao, 'ACIONADO', 'MONITORANDO', 'ENCERRADO'),
                      COALESCE(g.score, 0) DESC, c.id",
            [$planId]
        ));
    }

    /**
     * Cria ou atualiza um plano. O MESMO método atende a checagem da reunião,
     * que manda só `situacao` e `verificado_em`: campo ausente no corpo mantém
     * o valor da linha. Exigir o registro inteiro no modal curto obrigaria a
     * tela a reenviar texto que ninguém olhou — e uma divergência de redação
     * entre as duas telas viraria perda silenciosa.
     */
    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);

        $atual = $id ? $this->exigirContingencia($id, $planId) : null;
        // Campo ausente no corpo vale o que já está gravado (ou o padrão, na
        // criação). `array_key_exists` e não `isset`: mandar null é apagar.
        $campo = function (string $nome, $padrao = null) use ($d, $atual) {
            if (array_key_exists($nome, $d)) {
                return $d[$nome];
            }
            return $atual !== null ? $atual[$nome] : $padrao;
        };

        // A ameaça de origem é a IDENTIDADE do plano, não um campo do
        // formulário: trocá-la na edição mudaria de qual risco este plano
        // responde sem que nada na tela dissesse isso. Quem errou a ameaça
        // exclui e cria de novo.
        $fatorId = $atual !== null
            ? ($atual['fator_id'] !== null ? (int)$atual['fator_id'] : null)
            : (!empty($d['fator_id']) ? (int)$d['fator_id'] : null);
        if ($fatorId !== null && $atual === null) {
            $this->exigirAmeaca($fatorId, $planId);
        }

        $risco    = trim((string)$campo('risco', ''));
        $gatilho  = trim((string)$campo('gatilho', ''));
        $fonte    = trim((string)$campo('fonte_gatilho', ''));
        $resp     = trim((string)$campo('responsavel', ''));
        $resposta = trim((string)$campo('resposta', ''));
        $situacao = (string)$campo('situacao', 'MONITORANDO');

        // Sem ameaça de origem o risco precisa estar escrito aqui; COM ameaça
        // ele vem do JOIN e não é copiado — cópia congelada divergiria do
        // fator no primeiro edit e a tela mostraria dois textos para o mesmo
        // risco, sem dizer qual vale.
        if ($fatorId === null && $risco === '') {
            Json::erro('Descreva o risco, ou vincule este plano a uma ameaça da SWOT.');
        }
        if ($fatorId !== null) {
            $risco = '';
        }
        if ($gatilho === '') {
            Json::erro('Informe o gatilho: o sinal observável que diz que o risco aconteceu.');
        }
        if ($resp === '') {
            Json::erro('Informe quem responde por este plano.');
        }
        if ($resposta === '') {
            Json::erro('Informe a resposta: o que será feito quando o gatilho disparar.');
        }
        if (!in_array($situacao, self::SITUACOES, true)) {
            Json::erro('Situação inválida.');
        }
        if (mb_strlen($fonte) > 120) {
            Json::erro('A fonte do gatilho deve ter até 120 caracteres.');
        }
        if (mb_strlen($resp) > 255) {
            Json::erro('O nome do responsável deve ter até 255 caracteres.');
        }

        $verificado = $this->dataOuNulo($campo('verificado_em'), 'A data da checagem');
        $acionado   = $this->dataOuNulo($campo('acionado_em'), 'A data do acionamento');

        // ACIONADO sem data seria um plano que disparou sem ninguém saber
        // quando; MONITORANDO com data de acionamento é uma contradição na
        // própria linha. ENCERRADO preserva a data — é o histórico do ciclo.
        if ($situacao === 'ACIONADO' && $acionado === null) {
            $acionado = date('Y-m-d');
        }
        if ($situacao === 'MONITORANDO') {
            $acionado = null;
        }

        if ($id) {
            Database::executar(
                'UPDATE contingencia SET risco = ?, gatilho = ?, fonte_gatilho = ?,
                        responsavel = ?, resposta = ?, situacao = ?,
                        verificado_em = ?, acionado_em = ?
                 WHERE id = ? AND planejamento_id = ?',
                [$risco ?: null, $gatilho, $fonte ?: null, $resp, $resposta,
                 $situacao, $verificado, $acionado, $id, $planId]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO contingencia (planejamento_id, fator_id, risco, gatilho,
                        fonte_gatilho, responsavel, resposta, situacao, verificado_em, acionado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$planId, $fatorId, $risco ?: null, $gatilho, $fonte ?: null,
                 $resp, $resposta, $situacao, $verificado, $acionado]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirContingencia($id, $planId);
        Database::executar(
            'DELETE FROM contingencia WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        Json::ok();
    }

    /**
     * A guarda de escopo: o id vem da URL e o planejamento vem do corpo, então
     * sem conferir o par qualquer pessoa com acesso a UM planejamento
     * alcançaria o plano de contingência de outro negócio.
     */
    private function exigirContingencia(int $id, int $planId): array
    {
        $linha = Database::um(
            'SELECT * FROM contingencia WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$linha) {
            Json::erro('Plano de contingência não encontrado neste planejamento.', 404);
        }
        return $linha;
    }

    /** A origem precisa ser uma ameaça da SWOT DESTE planejamento. */
    private function exigirAmeaca(int $fatorId, int $planId): void
    {
        $fator = Database::um(
            "SELECT id FROM fator
             WHERE id = ? AND planejamento_id = ? AND etapa = 'SWOT' AND categoria = 'AMEACA'",
            [$fatorId, $planId]
        );
        if (!$fator) {
            Json::erro('A origem precisa ser uma ameaça da SWOT deste planejamento.', 404);
        }
    }

    /**
     * Data no formato do banco, ou null. Recusa data no futuro: as duas datas
     * registram algo que JÁ aconteceu (a última checagem, o acionamento), e um
     * ano digitado errado envenenaria em silêncio a linha "sem checagem desde"
     * do relatório — que é justamente o que cobra o ritual.
     */
    private function dataOuNulo($valor, string $rotulo): ?string
    {
        $valor = trim((string)($valor ?? ''));
        if ($valor === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            Json::erro("$rotulo é inválida.");
        }
        if ($valor > date('Y-m-d')) {
            Json::erro("$rotulo não pode estar no futuro.");
        }
        return $valor;
    }
}

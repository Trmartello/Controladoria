<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Recorrencia;

/**
 * Diário de bordo: registros datados de acompanhamento (nunca sobrescritos).
 * Um registro com status/progresso também atualiza o item referenciado.
 */
class DiarioController
{
    private const STATUS = ['NAO_INICIADO', 'EM_ANDAMENTO', 'CONCLUIDO', 'ATRASADO', 'CANCELADO'];

    public function listar(): void
    {
        $planId  = (int)($_GET['planejamento_id'] ?? 0);
        $refTipo = $_GET['ref_tipo'] ?? '';
        $refId   = (int)($_GET['ref_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $this->validarRef($refTipo, $refId, $planId);

        Json::ok(Database::todos(
            'SELECT db.*, u.nome AS autor
             FROM diario_bordo db JOIN usuario u ON u.id = db.autor_id
             WHERE db.ref_tipo = ? AND db.ref_id = ?
             ORDER BY db.data_reg DESC, db.id DESC',
            [$refTipo, $refId]
        ));
    }

    public function criar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirLogin();
        Auth::exigirEdicaoPlanejamento($planId);

        $refTipo = $d['ref_tipo'] ?? '';
        $refId   = (int)($d['ref_id'] ?? 0);
        $this->validarRef($refTipo, $refId, $planId);

        $texto = trim($d['texto'] ?? '');
        if ($texto === '') {
            Json::erro('Descreva a situação atual no registro.');
        }
        $dataReg = $d['data_reg'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataReg)) {
            Json::erro('Data inválida.');
        }

        $status = $d['status_atual'] ?? '';
        $status = in_array($status, self::STATUS, true) ? $status : null;
        $progresso = ($d['progresso'] ?? '') !== '' && $d['progresso'] !== null
            ? max(0, min(100, (int)$d['progresso']))
            : null;

        $id = (int)Database::executar(
            'INSERT INTO diario_bordo (ref_tipo, ref_id, data_reg, autor_id, texto, status_atual, progresso)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$refTipo, $refId, $dataReg, $u['id'], $texto, $status, $progresso]
        );

        // Reflete status/progresso no item acompanhado
        if ($refTipo === 'PROJETO' && $status !== null) {
            Database::executar('UPDATE projeto SET status = ? WHERE id = ?', [$status, $refId]);
        }
        $reagendou = null;
        if ($refTipo === 'DESDOBRAMENTO' && ($status !== null || $progresso !== null)) {
            $reagendou = $this->aplicarNaAcao($refId, $status, $progresso);
        }
        Json::ok(['id' => $id, 'reagendada_para' => $reagendou]);
    }

    /**
     * Reflete o registro do diário na ação. Concluir uma ação que se repete
     * não a encerra: ela reabre na próxima data prevista, como acontece no
     * cadastro da ação. Devolve a nova data de fim quando houve reagendamento.
     */
    private function aplicarNaAcao(int $refId, ?string $status, ?int $progresso): ?string
    {
        $acao = Database::um('SELECT * FROM desdobramento WHERE id = ?', [$refId]);
        $reagendou = null;
        if ($acao && $status === 'CONCLUIDO' && $acao['status'] !== 'CONCLUIDO'
            && ($acao['recorrencia'] ?? 'NENHUMA') !== 'NENHUMA') {
            $reagendou = Recorrencia::reagendar(
                $acao['data_inicio'],
                $acao['recorrencia'],
                $acao['recorrencia_dia'] !== null ? (int)$acao['recorrencia_dia'] : null,
                $acao['recorrencia_ate'],
                $acao['data_fim']
            );
        }
        if ($reagendou !== null) {
            Database::executar(
                "UPDATE desdobramento SET status = 'NAO_INICIADO', progresso = 0, concluido_em = NULL,
                   data_inicio = ?, data_fim = ? WHERE id = ?",
                [$reagendou['data_inicio'], $reagendou['data_fim'], $refId]
            );
            return $reagendou['data_fim'];
        }
        if ($status !== null) {
            $concluidoEm = $status === 'CONCLUIDO'
                ? (($acao['concluido_em'] ?? null) ?: date('Y-m-d H:i:s'))
                : null;
            Database::executar(
                'UPDATE desdobramento SET status = ?, concluido_em = ? WHERE id = ?',
                [$status, $concluidoEm, $refId]
            );
        }
        if ($progresso !== null) {
            Database::executar('UPDATE desdobramento SET progresso = ? WHERE id = ?', [$progresso, $refId]);
        }
        return null;
    }

    /** Garante que a referência pertence ao planejamento informado. */
    private function validarRef(string $refTipo, int $refId, int $planId): void
    {
        $sql = match ($refTipo) {
            'PROJETO'       => 'SELECT id FROM projeto WHERE id = ? AND planejamento_id = ?',
            'DESDOBRAMENTO' => 'SELECT d.id FROM desdobramento d JOIN projeto p ON p.id = d.projeto_id
                                WHERE d.id = ? AND p.planejamento_id = ?',
            'INVESTIMENTO'  => 'SELECT id FROM investimento WHERE id = ? AND planejamento_id = ?',
            'CASCATA'       => 'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            default         => null,
        };
        if ($sql === null) {
            Json::erro('Tipo de referência inválido.');
        }
        if (!Database::um($sql, [$refId, $planId])) {
            Json::erro('Item não encontrado neste planejamento.', 404);
        }
    }
}

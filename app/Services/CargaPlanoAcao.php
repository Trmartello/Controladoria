<?php

namespace App\Services;

use PDO;

/**
 * Carga de PLANO DE AÇÃO: projetos, frentes e ações vindos de uma planilha do
 * negócio (pedidos do cliente de 2026-11-19, Fábrica de Rações e UTM).
 *
 * Grava o mesmo que a tela grava, sem passar pelo controller (o migrate não tem
 * sessão): projeto com o horizonte do ano, uma frente por projeto, e cada ação
 * como lista de datas com uma linha — o padrão da tela —, cujo fim é o prazo da
 * planilha. A coluna de acompanhamento da planilha entra como COMENTÁRIO da
 * ação, sem autor: é registro da planilha, não de um usuário.
 *
 * Status de cada ação vem da carga: CONCLUIDO leva `concluido_em` (a data que a
 * carga der, ou o prazo) e fecha o período; os demais seguem a regra da tela
 * (`ProjetoController::resolverStatus`) — prazo vencido sem conclusão é
 * ATRASADO. Projeto e frente não recebem status: `Consolidacao` os calcula das
 * ações em toda leitura.
 *
 * Idempotente por título: projeto que já existe no planejamento com o mesmo
 * título não é recriado (quem roda a carga de novo não duplica o plano).
 */
class CargaPlanoAcao
{
    /** @return int quantos projetos entraram */
    public static function aplicar(PDO $pdo, array $carga): int
    {
        $planoId = CargaConteudo::planosDoNegocio($pdo, $carga['negocio'], (int)$carga['ano'])[0] ?? null;
        if (!$planoId) {
            throw new \RuntimeException("nenhum planejamento do negócio {$carga['negocio']} para {$carga['ano']}");
        }
        $existe = $pdo->prepare('SELECT id FROM projeto WHERE planejamento_id = ? AND titulo = ?');
        $horiz = $pdo->prepare('SELECT h.id FROM horizonte h JOIN planejamento p ON p.ciclo_id = h.ciclo_id
            WHERE p.id = ? AND ? BETWEEN h.ano_inicio AND h.ano_fim ORDER BY h.ordem, h.id LIMIT 1');
        $insProjeto = $pdo->prepare("INSERT INTO projeto (planejamento_id, tipo, ano, titulo, descricao, responsavel,
                horizonte_id, classificacao, status, ordem)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'NORMAL', 'NAO_INICIADO', 0)");
        $insFrente = $pdo->prepare("INSERT INTO iniciativa (projeto_id, titulo, descricao, status, ordem)
            VALUES (?, ?, '', 'ABERTA', 0)");
        // Mesmo critério de `ProjetoController::usuarioPorNome`: o nome tem de
        // bater inteiro com um usuário ativo que enxerga o negócio.
        $usuario = $pdo->prepare("SELECT u.id FROM usuario u
            LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
            JOIN planejamento p ON p.id = ?
            WHERE u.ativo = 1 AND u.nome = ?
              AND (u.perfil IN ('ADMIN', 'CONTROLADORIA', 'DIRECAO') OR un.negocio_id = p.negocio_id)
            LIMIT 1");
        $insAcao = $pdo->prepare("INSERT INTO desdobramento (projeto_id, iniciativa_id, o_que, por_que, quem,
                quem_usuario_id, recorrencia, quando_, data_inicio, data_fim, onde, como, status, prioridade,
                progresso, concluido_em, ordem)
            VALUES (?, ?, ?, '', ?, ?, 'DATAS', '', NULL, ?, '', '', ?, 'MEDIA', ?, ?, ?)");
        $insPeriodo = $pdo->prepare('INSERT INTO desdobramento_periodo (desdobramento_id, data_inicio, data_fim, concluido_em)
            VALUES (?, NULL, ?, ?)');
        $insComentario = $pdo->prepare("INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto)
            VALUES ('DESDOBRAMENTO', ?, NULL, ?)");

        $criados = 0;
        $pdo->beginTransaction();
        try {
            foreach ($carga['projetos'] as $pj) {
                $existe->execute([$planoId, $pj['titulo']]);
                if ($existe->fetchColumn()) {
                    continue;
                }
                $horiz->execute([$planoId, (int)$carga['ano']]);
                $insProjeto->execute([$planoId, $pj['tipo'] ?? 'OPERACIONAL', (int)$carga['ano'], $pj['titulo'],
                    $pj['descricao'] ?? '', $pj['responsavel'], $horiz->fetchColumn() ?: null]);
                $projetoId = (int)$pdo->lastInsertId();
                $insFrente->execute([$projetoId, $pj['iniciativa']]);
                $frenteId = (int)$pdo->lastInsertId();
                foreach ($pj['acoes'] as $i => $a) {
                    $usuario->execute([$planoId, $a['quem']]);
                    $concluida = $a['status'] === 'CONCLUIDO';
                    $concluidoEm = $concluida ? ($a['concluido_em'] ?? $a['prazo']) . ' 00:00:00' : null;
                    $status = $concluida ? 'CONCLUIDO'
                        : ($a['prazo'] < date('Y-m-d') ? 'ATRASADO' : ($a['status'] === 'ATRASADO' ? 'NAO_INICIADO' : $a['status']));
                    $insAcao->execute([$projetoId, $frenteId, $a['o_que'], $a['quem'], $usuario->fetchColumn() ?: null,
                        $a['prazo'], $status, $concluida ? 100 : 0, $concluidoEm, $i]);
                    $acaoId = (int)$pdo->lastInsertId();
                    $insPeriodo->execute([$acaoId, $a['prazo'], $concluidoEm]);
                    if (($a['comentario'] ?? '') !== '') {
                        $insComentario->execute([$acaoId, $a['comentario']]);
                    }
                }
                $criados++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $criados;
    }
}

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/** Cascata de escolhas: células driver × horizonte com síntese e aberturas por eixo. */
class CascataController
{
    /** Estrutura completa da cascata do planejamento (matriz + escolhas + fatores). */
    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);

        $horizontes = Database::todos(
            'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
            [(int)$plan['ciclo_id']]
        );
        $drivers = Database::todos('SELECT * FROM driver WHERE ativo = 1 ORDER BY ordem, nome');
        $eixos   = Database::todos('SELECT * FROM eixo WHERE ativo = 1 ORDER BY ordem, nome');

        $escolhas = Database::todos(
            'SELECT * FROM cascata_escolha WHERE planejamento_id = ?',
            [$planId]
        );
        foreach ($escolhas as &$e) {
            $e['fatores'] = Database::todos(
                'SELECT f.id, f.categoria, f.descricao, g.score
                 FROM cascata_fator cf
                 JOIN fator f ON f.id = cf.fator_id
                 LEFT JOIN gut g ON g.fator_id = f.id
                 WHERE cf.cascata_id = ?
                 ORDER BY g.score DESC, f.id',
                [$e['id']]
            );
        }

        Json::ok([
            'horizontes' => $horizontes,
            'drivers'    => $drivers,
            'eixos'      => $eixos,
            'escolhas'   => $escolhas,
        ]);
    }

    /** Cria/atualiza a célula (síntese quando eixo_id é nulo; abertura quando informado). */
    public function salvar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        $driverId    = (int)($d['driver_id'] ?? 0);
        $eixoId      = !empty($d['eixo_id']) ? (int)$d['eixo_id'] : null;
        $escolha     = trim($d['escolha'] ?? '');
        $renuncia    = trim($d['renuncia'] ?? '');
        $fatores     = array_map('intval', $d['fatores'] ?? []);

        $horizonte = Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        );
        if (!$horizonte) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        if (!Database::um('SELECT id FROM driver WHERE id = ? AND ativo = 1', [$driverId])) {
            Json::erro('Driver inválido.');
        }
        if ($eixoId !== null && !Database::um('SELECT id FROM eixo WHERE id = ? AND ativo = 1', [$eixoId])) {
            Json::erro('Eixo inválido.');
        }
        if ($escolha === '') {
            Json::erro('Descreva a escolha.');
        }

        $existente = Database::um(
            'SELECT id FROM cascata_escolha
             WHERE planejamento_id = ? AND horizonte_id = ? AND driver_id = ?
               AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
            [$planId, $horizonteId, $driverId, $eixoId]
        );

        if ($existente) {
            $id = (int)$existente['id'];
            Database::executar(
                'UPDATE cascata_escolha SET escolha = ?, renuncia = ? WHERE id = ?',
                [$escolha, $renuncia, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO cascata_escolha
                   (planejamento_id, horizonte_id, driver_id, eixo_id, escolha, renuncia)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$planId, $horizonteId, $driverId, $eixoId, $escolha, $renuncia]
            );
        }

        // Vínculo com os fatores priorizados (SWOT/GUT) — substitui o conjunto
        Database::executar('DELETE FROM cascata_fator WHERE cascata_id = ?', [$id]);
        foreach (array_unique($fatores) as $fatorId) {
            $fator = Database::um(
                "SELECT id FROM fator WHERE id = ? AND planejamento_id = ? AND etapa = 'SWOT'",
                [$fatorId, $planId]
            );
            if ($fator) {
                Database::executar(
                    'INSERT INTO cascata_fator (cascata_id, fator_id) VALUES (?, ?)',
                    [$id, $fatorId]
                );
            }
        }
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $celula = Database::um(
            'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$celula) {
            Json::erro('Escolha não encontrada neste planejamento.', 404);
        }
        // Projetos originados desta escolha perdem o vínculo (a FK não tem ON DELETE)
        Database::executar('UPDATE projeto SET cascata_id = NULL WHERE cascata_id = ?', [$id]);
        Database::executar('DELETE FROM cascata_escolha WHERE id = ?', [$id]);
        Json::ok();
    }
}

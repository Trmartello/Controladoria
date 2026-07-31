<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class PlanejamentoController
{
    /**
     * Resolve o contexto (ciclo + negócio ou corporativo): retorna o
     * planejamento — criando-o se ainda não existir — e o checklist do método.
     */
    public function contexto(): void
    {
        $u = Auth::exigirLogin();
        $cicloId   = (int)($_GET['ciclo_id'] ?? 0);
        $corporativo = ($_GET['escopo'] ?? '') === 'CORPORATIVO';
        $negocioId = $corporativo ? null : (int)($_GET['negocio_id'] ?? 0);

        if (!$cicloId || (!$corporativo && !$negocioId)) {
            Json::erro('Informe o ciclo e o negócio (ou escopo corporativo).');
        }
        if ($corporativo && !Auth::veTudo($u)) {
            Json::erro('Sem acesso ao planejamento corporativo.', 403);
        }
        if (!$corporativo) {
            $escopo = Auth::escopoNegocios($u);
            if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
                Json::erro('Sem acesso a este negócio.', 403);
            }
        }

        $plan = Database::um(
            $corporativo
                ? "SELECT * FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'"
                : "SELECT * FROM planejamento WHERE ciclo_id = ? AND negocio_id = ?",
            $corporativo ? [$cicloId] : [$cicloId, $negocioId]
        );
        if (!$plan) {
            $id = (int)Database::executar(
                $corporativo
                    ? "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'CORPORATIVO', NULL)"
                    : "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'NEGOCIO', ?)",
                $corporativo ? [$cicloId] : [$cicloId, $negocioId]
            );
            $plan = Database::um('SELECT * FROM planejamento WHERE id = ?', [$id]);
        }

        Json::ok([
            'planejamento' => $plan,
            'checklist'    => $this->checklist((int)$plan['id'], $cicloId),
        ]);
    }

    /** Situação de cada etapa do método para o hub. */
    private function checklist(int $planId, int $cicloId): array
    {
        $conta = fn(string $sql, array $p) => (int)(Database::um($sql, $p)['n'] ?? 0);

        $cenario = $conta('SELECT COUNT(*) n FROM cenario_item WHERE planejamento_id = ?', [$planId]);
        $pestel  = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'PESTEL'", [$planId]);
        $porter  = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'PORTER'", [$planId]);
        $swot    = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'SWOT'", [$planId]);
        $gut     = $conta(
            "SELECT COUNT(*) n FROM gut g JOIN fator f ON f.id = g.fator_id WHERE f.planejamento_id = ?",
            [$planId]
        );

        $horizontes = $conta('SELECT COUNT(*) n FROM horizonte WHERE ciclo_id = ?', [$cicloId]);
        $drivers    = $conta('SELECT COUNT(*) n FROM driver WHERE ativo = 1', []);
        $eixos      = $conta('SELECT COUNT(*) n FROM eixo WHERE ativo = 1', []);
        $celulasTotal = $horizontes * $drivers * ($eixos + 1); // sínteses + aberturas
        $cascata    = $conta('SELECT COUNT(*) n FROM cascata_escolha WHERE planejamento_id = ?', [$planId]);

        $projetos      = $conta('SELECT COUNT(*) n FROM projeto WHERE planejamento_id = ?', [$planId]);
        $investimentos = $conta('SELECT COUNT(*) n FROM investimento WHERE planejamento_id = ?', [$planId]);
        $indicadores   = $conta('SELECT COUNT(*) n FROM indicador WHERE planejamento_id = ?', [$planId]);

        return [
            ['etapa' => 'Análise de Cenário',  'itens' => $cenario,       'meta' => null],
            ['etapa' => 'PESTEL',              'itens' => $pestel,        'meta' => null],
            ['etapa' => 'Porter (5 Forças)',   'itens' => $porter,        'meta' => null],
            ['etapa' => 'SWOT',                'itens' => $swot,          'meta' => null],
            ['etapa' => 'Matriz GUT',          'itens' => $gut,           'meta' => $swot ?: null],
            ['etapa' => 'Cascata de Escolhas', 'itens' => $cascata,       'meta' => $celulasTotal ?: null],
            ['etapa' => 'Metas e Indicadores', 'itens' => $indicadores,   'meta' => null],
            ['etapa' => 'Projetos',            'itens' => $projetos,      'meta' => null],
            ['etapa' => 'Investimentos',       'itens' => $investimentos, 'meta' => null],
        ];
    }
}

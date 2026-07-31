<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class CenarioController
{
    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // A análise é anual: com ?ano=YYYY retorna só aquele ano
        $ano = (int)($_GET['ano'] ?? 0);
        $sql = 'SELECT * FROM cenario_item WHERE planejamento_id = ?';
        $params = [$planId];
        if ($ano) {
            $sql .= ' AND ano = ?';
            $params[] = $ano;
        }
        Json::ok(Database::todos("$sql ORDER BY tipo, ordem, id", $params));
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
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirItem($id, $planId);
        Database::executar('DELETE FROM cenario_item WHERE id = ?', [$id]);
        Json::ok();
    }

    private function exigirItem(int $id, int $planId): void
    {
        $item = Database::um(
            'SELECT id FROM cenario_item WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$item) {
            Json::erro('Item não encontrado neste planejamento.', 404);
        }
    }
}

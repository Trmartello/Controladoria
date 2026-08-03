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
        // O LEFT JOIN traz a origem na Coleta, quando o item nasceu de uma ideia
        $sql = "SELECT c.*, ci.id AS coleta_item_id, ca.nome AS coleta_autor, co.n AS coleta_vozes
                FROM cenario_item c
                -- Uma ideia só por item: com vozes agrupadas na oficina, várias
                -- ideias apontam para o mesmo registro e o JOIN duplicaria a linha
                LEFT JOIN coleta_item ci ON ci.id = (
                  SELECT MIN(x.id) FROM coleta_item x
                  WHERE x.destino_tipo = 'CENARIO' AND x.destino_id = c.id)
                LEFT JOIN (
                  SELECT destino_id, COUNT(*) AS n FROM coleta_item
                  WHERE destino_tipo = 'CENARIO' GROUP BY destino_id) co ON co.destino_id = c.id
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
        // Solta o vínculo da Coleta antes de apagar: sem isso a ideia ficaria
        // apontando para um id morto e o rastreio exibiria link quebrado.
        // Volta a SELECIONADO, como o "Desmarcar" (ColetaController::reabrir):
        // ACEITO sem destino nenhum prendia a ideia num beco sem saída.
        Database::executar(
            "UPDATE coleta_item SET situacao = 'SELECIONADO', destino_tipo = NULL, destino_id = NULL,
               triado_por = NULL, triado_em = NULL
             WHERE destino_tipo = 'CENARIO' AND destino_id = ?",
            [$id]
        );
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

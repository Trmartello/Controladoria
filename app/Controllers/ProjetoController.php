<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class ProjetoController
{
    private const STATUS = ['NAO_INICIADO', 'EM_ANDAMENTO', 'CONCLUIDO', 'ATRASADO', 'CANCELADO'];

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $projetos = Database::todos(
            'SELECT p.*, h.nome AS horizonte_nome, ce.escolha AS escolha_origem
             FROM projeto p
             LEFT JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN cascata_escolha ce ON ce.id = p.cascata_id
             WHERE p.planejamento_id = ?
             ORDER BY p.tipo, p.ordem, p.id',
            [$planId]
        );
        foreach ($projetos as &$p) {
            $p['desdobramentos'] = Database::todos(
                'SELECT * FROM desdobramento WHERE projeto_id = ? ORDER BY ordem, id',
                [$p['id']]
            );
        }
        Json::ok($projetos);
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $tipo = $d['tipo'] ?? '';
        $titulo = trim($d['titulo'] ?? '');
        if (!in_array($tipo, ['ESTRATEGICO', 'OPERACIONAL'], true) || $titulo === '') {
            Json::erro('Informe o tipo e o título do projeto.');
        }
        $status = $d['status'] ?? 'NAO_INICIADO';
        if (!in_array($status, self::STATUS, true)) {
            Json::erro('Status inválido.');
        }
        $impacto = $d['impacto'] ?? null;
        if ($impacto !== null && $impacto !== ''
            && !in_array($impacto, ['RENTABILIDADE', 'FATURAMENTO', 'SUSTENTABILIDADE', 'PESSOAS'], true)) {
            Json::erro('Impacto inválido.');
        }
        $classificacao = $d['classificacao'] ?? 'NORMAL';
        if (!in_array($classificacao, ['PRIORITARIO', 'NORMAL'], true)) {
            Json::erro('Classificação inválida.');
        }

        $horizonteId = !empty($d['horizonte_id']) ? (int)$d['horizonte_id'] : null;
        if ($horizonteId !== null && !Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        $cascataId = !empty($d['cascata_id']) ? (int)$d['cascata_id'] : null;
        if ($cascataId !== null && !Database::um(
            'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            [$cascataId, $planId]
        )) {
            Json::erro('Escolha da cascata não pertence a este planejamento.');
        }

        $params = [
            $tipo, $titulo,
            mb_substr(trim($d['responsavel'] ?? ''), 0, 255),
            mb_substr(trim($d['prazo'] ?? ''), 0, 60),
            $horizonteId, $cascataId, $impacto ?: null, $classificacao, $status,
            (int)($d['ordem'] ?? 0),
        ];
        if ($id) {
            $this->exigirProjeto($id, $planId);
            Database::executar(
                'UPDATE projeto SET tipo = ?, titulo = ?, responsavel = ?, prazo = ?,
                   horizonte_id = ?, cascata_id = ?, impacto = ?, classificacao = ?,
                   status = ?, ordem = ? WHERE id = ?',
                [...$params, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO projeto (planejamento_id, tipo, titulo, responsavel, prazo,
                   horizonte_id, cascata_id, impacto, classificacao, status, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$planId, ...$params]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirProjeto($id, $planId);
        // Investimentos vinculados perdem o vínculo (a FK não tem ON DELETE)
        Database::executar('UPDATE investimento SET projeto_id = NULL WHERE projeto_id = ?', [$id]);
        Database::executar('DELETE FROM projeto WHERE id = ?', [$id]);
        Json::ok();
    }

    public function salvarDesdobramento(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $projetoId = (int)($d['projeto_id'] ?? 0);
        $this->exigirProjeto($projetoId, $planId);

        $oQue = trim($d['o_que'] ?? '');
        if ($oQue === '') {
            Json::erro('Descreva a ação (O quê?).');
        }
        $status = $d['status'] ?? 'NAO_INICIADO';
        if (!in_array($status, self::STATUS, true)) {
            Json::erro('Status inválido.');
        }
        $progresso = max(0, min(100, (int)($d['progresso'] ?? 0)));
        $quanto = ($d['quanto'] ?? '') !== '' && $d['quanto'] !== null ? (float)$d['quanto'] : null;

        $params = [
            $projetoId, $oQue, trim($d['por_que'] ?? ''),
            mb_substr(trim($d['quem'] ?? ''), 0, 255),
            mb_substr(trim($d['quando_'] ?? ''), 0, 60),
            mb_substr(trim($d['onde'] ?? ''), 0, 120),
            trim($d['como'] ?? ''),
            $quanto, $status, $progresso, (int)($d['ordem'] ?? 0),
        ];
        if ($id) {
            $this->exigirDesdobramento($id, $planId);
            Database::executar(
                'UPDATE desdobramento SET projeto_id = ?, o_que = ?, por_que = ?, quem = ?,
                   quando_ = ?, onde = ?, como = ?, quanto = ?, status = ?, progresso = ?, ordem = ?
                 WHERE id = ?',
                [...$params, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO desdobramento (projeto_id, o_que, por_que, quem, quando_, onde,
                   como, quanto, status, progresso, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluirDesdobramento(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirDesdobramento($id, $planId);
        Database::executar('DELETE FROM desdobramento WHERE id = ?', [$id]);
        Json::ok();
    }

    private function exigirProjeto(int $id, int $planId): void
    {
        if (!Database::um(
            'SELECT id FROM projeto WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        )) {
            Json::erro('Projeto não encontrado neste planejamento.', 404);
        }
    }

    private function exigirDesdobramento(int $id, int $planId): void
    {
        if (!Database::um(
            'SELECT d.id FROM desdobramento d JOIN projeto p ON p.id = d.projeto_id
             WHERE d.id = ? AND p.planejamento_id = ?',
            [$id, $planId]
        )) {
            Json::erro('Desdobramento não encontrado neste planejamento.', 404);
        }
    }
}

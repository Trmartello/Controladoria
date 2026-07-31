<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class CicloController
{
    public function listar(): void
    {
        Auth::exigirLogin();
        $ciclos = Database::todos('SELECT * FROM ciclo ORDER BY ano_inicio DESC');
        foreach ($ciclos as &$c) {
            $c['horizontes'] = Database::todos(
                'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
                [$c['id']]
            );
        }
        Json::ok($ciclos);
    }

    public function salvar(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $nome   = trim($d['nome'] ?? '');
        $anoBase   = (int)($d['ano_base'] ?? 0);
        $anoInicio = (int)($d['ano_inicio'] ?? 0);
        $anoFim    = (int)($d['ano_fim'] ?? 0);
        $status = $d['status'] ?? 'EM_ELABORACAO';
        if ($nome === '' || !$anoBase || !$anoInicio || !$anoFim) {
            Json::erro('Informe nome, ano do planejamento e período do ciclo.');
        }
        if ($anoFim < $anoInicio) {
            Json::erro('Ano final não pode ser menor que o inicial.');
        }
        if (!in_array($status, ['EM_ELABORACAO', 'VIGENTE', 'ENCERRADO'], true)) {
            Json::erro('Status inválido.');
        }

        if ($id) {
            Database::executar(
                'UPDATE ciclo SET nome = ?, ano_base = ?, ano_inicio = ?, ano_fim = ?, status = ? WHERE id = ?',
                [$nome, $anoBase, $anoInicio, $anoFim, $status, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO ciclo (nome, ano_base, ano_inicio, ano_fim, status) VALUES (?, ?, ?, ?, ?)',
                [$nome, $anoBase, $anoInicio, $anoFim, $status]
            );
            // Todo ciclo nasce com seu planejamento corporativo
            Database::executar(
                "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'CORPORATIVO', NULL)",
                [$id]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function salvarHorizonte(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $cicloId   = (int)($d['ciclo_id'] ?? 0);
        $nome      = trim($d['nome'] ?? '');
        $anoInicio = (int)($d['ano_inicio'] ?? 0);
        $anoFim    = (int)($d['ano_fim'] ?? 0);
        $tema      = trim($d['tema'] ?? '');
        $objetivo  = trim($d['objetivo'] ?? '');
        $ordem     = (int)($d['ordem'] ?? 0);
        if (!$cicloId || $nome === '' || !$anoInicio || !$anoFim || $tema === '' || $objetivo === '') {
            Json::erro('Preencha todos os campos do horizonte (nome, período, tema e objetivo).');
        }

        if ($id) {
            Database::executar(
                'UPDATE horizonte SET ciclo_id = ?, nome = ?, ano_inicio = ?, ano_fim = ?, tema = ?, objetivo = ?, ordem = ? WHERE id = ?',
                [$cicloId, $nome, $anoInicio, $anoFim, $tema, $objetivo, $ordem, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$cicloId, $nome, $anoInicio, $anoFim, $tema, $objetivo, $ordem]
            );
        }
        Json::ok(['id' => $id]);
    }
}

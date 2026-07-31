<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/** Cadastro das linhas bases (drivers) e das aberturas (eixos). */
class DriverEixoController
{
    private function tabela(string $tipo): string
    {
        return $tipo === 'drivers' ? 'driver' : 'eixo';
    }

    public function listar(string $tipo): void
    {
        Auth::exigirLogin();
        Json::ok(Database::todos("SELECT * FROM {$this->tabela($tipo)} ORDER BY ordem, nome"));
    }

    public function salvar(string $tipo, ?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $nome  = trim($d['nome'] ?? '');
        $ordem = (int)($d['ordem'] ?? 0);
        $ativo = isset($d['ativo']) ? (int)!!$d['ativo'] : 1;
        if ($nome === '') {
            Json::erro('Informe o nome.');
        }
        $tabela = $this->tabela($tipo);
        if ($id) {
            Database::executar(
                "UPDATE {$tabela} SET nome = ?, ordem = ?, ativo = ? WHERE id = ?",
                [$nome, $ordem, $ativo, $id]
            );
        } else {
            $id = (int)Database::executar(
                "INSERT INTO {$tabela} (nome, ordem, ativo) VALUES (?, ?, ?)",
                [$nome, $ordem, $ativo]
            );
        }
        Json::ok(['id' => $id]);
    }
}

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
        $ativo = isset($d['ativo']) ? (int)!!$d['ativo'] : 1;
        if ($nome === '') {
            Json::erro('Informe o nome.');
        }
        $tabela = $this->tabela($tipo);
        // A ordem é gerida pelo arrastar-e-soltar da lista: item novo entra no
        // fim; na edição a posição atual é preservada
        if ($id) {
            $ordem = array_key_exists('ordem', $d) ? (int)$d['ordem']
                : (int)(Database::um("SELECT ordem FROM {$tabela} WHERE id = ?", [$id])['ordem'] ?? 0);
            Database::executar(
                "UPDATE {$tabela} SET nome = ?, ordem = ?, ativo = ? WHERE id = ?",
                [$nome, $ordem, $ativo, $id]
            );
        } else {
            $ordem = array_key_exists('ordem', $d) ? (int)$d['ordem']
                : (int)(Database::um("SELECT COALESCE(MAX(ordem), 0) + 1 AS n FROM {$tabela}")['n'] ?? 1);
            $id = (int)Database::executar(
                "INSERT INTO {$tabela} (nome, ordem, ativo) VALUES (?, ?, ?)",
                [$nome, $ordem, $ativo]
            );
        }
        Json::ok(['id' => $id]);
    }

    /** Grava a ordem vinda do arrastar-e-soltar: posição na lista = prioridade. */
    public function reordenar(string $tipo): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $ids = array_values(array_filter(array_map('intval', $d['ids'] ?? []), fn($i) => $i > 0));
        if (!$ids) {
            Json::erro('Informe a nova ordem.');
        }
        $tabela = $this->tabela($tipo);
        foreach ($ids as $posicao => $id) {
            Database::executar("UPDATE {$tabela} SET ordem = ? WHERE id = ?", [$posicao + 1, $id]);
        }
        Json::ok();
    }
}

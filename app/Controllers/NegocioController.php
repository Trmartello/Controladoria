<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\QlikSync;

class NegocioController
{
    public function listar(): void
    {
        $u = Auth::exigirLogin();
        $sql = "SELECT n.*, CONCAT(n.cod_negocio, ' - ', n.nome) AS rotulo, u.nome AS gestor
                FROM negocio n LEFT JOIN usuario u ON u.id = n.gestor_id";
        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null) {
            if (!$escopo) {
                Json::ok([]);
            }
            $sql .= ' WHERE n.id IN (' . implode(',', array_fill(0, count($escopo), '?')) . ')';
        }
        $sql .= ' ORDER BY CAST(n.cod_negocio AS UNSIGNED), n.nome';
        Json::ok(Database::todos($sql, $escopo ?? []));
    }

    public function salvar(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $cod  = trim($d['cod_negocio'] ?? '');
        $nome = trim($d['nome'] ?? '');
        if ($cod === '' || $nome === '') {
            Json::erro('Informe código e nome do negócio.');
        }
        $gestor = !empty($d['gestor_id']) ? (int)$d['gestor_id'] : null;
        $ativo  = isset($d['ativo']) ? (int)!!$d['ativo'] : 1;

        $duplicado = Database::um(
            'SELECT id FROM negocio WHERE cod_negocio = ? AND id <> ?',
            [$cod, $id ?? 0]
        );
        if ($duplicado) {
            Json::erro("Já existe um negócio com o código {$cod}.");
        }

        if ($id) {
            Database::executar(
                'UPDATE negocio SET cod_negocio = ?, nome = ?, gestor_id = ?, ativo = ? WHERE id = ?',
                [$cod, $nome, $gestor, $ativo, $id]
            );
        } else {
            $id = (int)Database::executar(
                "INSERT INTO negocio (cod_negocio, nome, gestor_id, ativo, origem)
                 VALUES (?, ?, ?, ?, 'MANUAL')",
                [$cod, $nome, $gestor, $ativo]
            );
        }
        Json::ok(['id' => $id]);
    }

    /** Importa os negócios do app Comercial Global (Qlik). */
    public function sincronizar(): void
    {
        Auth::exigirAdministrador();
        $resultado = QlikSync::sincronizarNegocios();
        Json::ok($resultado);
    }
}

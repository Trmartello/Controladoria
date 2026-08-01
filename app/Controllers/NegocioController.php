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
        $sql = "SELECT n.*, CONCAT(n.cod_negocio, ' - ', n.nome) AS rotulo, u.nome AS gestor,
                       (SELECT GROUP_CONCAT(DISTINCT ug.nome ORDER BY ug.nome SEPARATOR '\n')
                        FROM usuario_negocio un
                        JOIN usuario ug ON ug.id = un.usuario_id
                        WHERE un.negocio_id = n.id AND ug.ativo = 1 AND ug.perfil = 'GESTOR')
                       AS gestores_vinculados
                FROM negocio n LEFT JOIN usuario u ON u.id = n.gestor_id";
        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null) {
            if (!$escopo) {
                Json::ok([]);
            }
            $sql .= ' WHERE n.id IN (' . implode(',', array_fill(0, count($escopo), '?')) . ')';
        }
        $sql .= ' ORDER BY CAST(n.cod_negocio AS UNSIGNED), n.nome';
        $negocios = Database::todos($sql, $escopo ?? []);
        // Gestores do negócio: o responsável principal + os usuários de perfil
        // GESTOR vinculados, sem duplicar
        foreach ($negocios as &$n) {
            $gestores = $n['gestor'] !== null ? [$n['gestor']] : [];
            foreach (explode("\n", (string)$n['gestores_vinculados']) as $nome) {
                if ($nome !== '' && !in_array($nome, $gestores, true)) {
                    $gestores[] = $nome;
                }
            }
            $n['gestores'] = $gestores;
            unset($n['gestores_vinculados']);
        }
        Json::ok($negocios);
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

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
                       AS gestores_vinculados,
                       (SELECT COUNT(*) FROM planejamento p WHERE p.negocio_id = n.id) AS planejamentos,
                       (SELECT COUNT(*) FROM usuario_negocio un2 WHERE un2.negocio_id = n.id) AS escopos
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
            // Excluível = ainda não atribuído em NENHUM cadastro (nem
            // planejamento, nem escopo de usuário) e fora da lista oficial
            // (que a sincronização recriaria). Caso contrário, o caminho é
            // desativar. A decisão mora aqui, no servidor — o front só mostra
            // (ou não) o botão; o excluir() reconfere as mesmas guardas.
            $n['excluivel'] = (int)(!$n['planejamentos'] && !$n['escopos']
                && !QlikSync::estaNaFonte((string)$n['cod_negocio']));
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

    /**
     * Tira o negócio do cadastro de vez. A regra é uma só: excluir vale apenas
     * para quem ainda NÃO foi atribuído em nenhum cadastro — de resto, o
     * caminho é desativar (esconde do seletor e preserva tudo).
     *
     * Três recusas, todas para não iludir quem clica:
     * - **planejamento vinculado**: a FK é RESTRICT, então o DELETE morreria com
     *   erro do banco. Some junto o diagnóstico, a cascata, os projetos e as
     *   metas daquele negócio — quem quiser mesmo apaga o planejamento antes.
     * - **escopo de usuário** (`usuario_negocio`): alguém foi configurado para
     *   trabalhar neste negócio. O CASCADE apagaria o vínculo em silêncio e o
     *   usuário perderia o escopo sem ninguém decidir isso — remova o vínculo
     *   em Usuários (ou desative o negócio) antes.
     * - **código da lista oficial**: a sincronização (e o passo do migrate)
     *   recriaria a linha no deploy seguinte. Excluir ali é trabalho perdido, e
     *   o certo é desativar — ou tirar o código de `QlikSync::NEGOCIOS_FONTE`.
     */
    public function excluir(int $id): void
    {
        Auth::exigirAdministrador();
        $negocio = Database::um('SELECT id, cod_negocio, nome FROM negocio WHERE id = ?', [$id]);
        if (!$negocio) {
            Json::erro('Negócio não encontrado.', 404);
        }
        $planos = (int)Database::um(
            'SELECT COUNT(*) AS n FROM planejamento WHERE negocio_id = ?',
            [$id]
        )['n'];
        if ($planos > 0) {
            Json::erro("«{$negocio['nome']}» tem {$planos} planejamento(s) e não pode ser excluído. "
                . 'Desative o negócio para tirá-lo das seleções sem perder o que já foi planejado.');
        }
        $escopos = (int)Database::um(
            'SELECT COUNT(*) AS n FROM usuario_negocio WHERE negocio_id = ?',
            [$id]
        )['n'];
        if ($escopos > 0) {
            Json::erro("«{$negocio['nome']}» está no escopo de {$escopos} usuário(s). "
                . 'Remova o vínculo na aba Usuários — ou desative o negócio — antes de excluir.');
        }
        if (QlikSync::estaNaFonte((string)$negocio['cod_negocio'])) {
            Json::erro("O código {$negocio['cod_negocio']} está na lista oficial do Comercial Global: "
                . 'a sincronização recriaria o negócio. Desative-o em vez de excluir.');
        }
        Database::executar('DELETE FROM negocio WHERE id = ?', [$id]);
        Json::ok(['excluido' => $id]);
    }

    /** Importa os negócios do app Comercial Global (Qlik). */
    public function sincronizar(): void
    {
        Auth::exigirAdministrador();
        $resultado = QlikSync::sincronizarNegocios();
        Json::ok($resultado);
    }
}

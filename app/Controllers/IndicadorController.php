<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Metas — Fase 6: indicadores por planejamento, métricas-âncora por horizonte
 * e tabela plurianual meta × real (metas revisadas preservam versões).
 */
class IndicadorController
{
    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);

        $ciclo = Database::um('SELECT * FROM ciclo WHERE id = ?', [(int)$plan['ciclo_id']]);
        $horizontes = Database::todos(
            'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
            [(int)$plan['ciclo_id']]
        );
        $indicadores = Database::todos(
            'SELECT i.*, h.nome AS horizonte_nome
             FROM indicador i LEFT JOIN horizonte h ON h.id = i.horizonte_id
             WHERE i.planejamento_id = ?
             ORDER BY i.metrica_ancora DESC, i.nome',
            [$planId]
        );

        // Escolhas da cascata que cada indicador mede. Uma query agregada para
        // TODOS eles, não uma por indicador: o laço abaixo já custa duas
        // consultas por linha, e a terceira é a que o modal precisa só para
        // marcar as caixas.
        $vinculos = [];
        foreach (Database::todos(
            'SELECT ic.indicador_id, ic.cascata_id
             FROM indicador_cascata ic
             JOIN indicador i ON i.id = ic.indicador_id
             WHERE i.planejamento_id = ?',
            [$planId]
        ) as $v) {
            $vinculos[(int)$v['indicador_id']][] = (int)$v['cascata_id'];
        }

        foreach ($indicadores as &$ind) {
            $ind['cascatas'] = $vinculos[(int)$ind['id']] ?? [];
            // Metas na versão mais recente de cada ano; anos com revisão ganham marcador
            $ind['metas'] = Database::todos(
                'SELECT v.ano, v.valor, v.versao_meta
                 FROM indicador_valor v
                 WHERE v.indicador_id = ? AND v.tipo = \'META\'
                   AND v.versao_meta = (SELECT MAX(v2.versao_meta) FROM indicador_valor v2
                                        WHERE v2.indicador_id = v.indicador_id
                                          AND v2.ano = v.ano AND v2.tipo = \'META\')
                 ORDER BY v.ano',
                [(int)$ind['id']]
            );
            $ind['reais'] = Database::todos(
                'SELECT ano, valor FROM indicador_valor
                 WHERE indicador_id = ? AND tipo = \'REAL\' ORDER BY ano',
                [(int)$ind['id']]
            );
        }

        Json::ok([
            'ciclo'       => $ciclo,
            'horizontes'  => $horizontes,
            'indicadores' => $indicadores,
        ]);
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $nome = mb_substr(trim($d['nome'] ?? ''), 0, 120);
        if ($nome === '') {
            Json::erro('Informe o nome do indicador.');
        }
        $unidade = mb_substr(trim($d['unidade'] ?? ''), 0, 20) ?: 'R$ mil';
        $sentido = ($d['sentido'] ?? '') === 'MENOR_MELHOR' ? 'MENOR_MELHOR' : 'MAIOR_MELHOR';
        $ancora = !empty($d['metrica_ancora']) ? 1 : 0;

        $horizonteId = !empty($d['horizonte_id']) ? (int)$d['horizonte_id'] : null;
        if ($horizonteId !== null && !Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }

        if ($id) {
            $this->exigirIndicador($id, $planId);
            Database::executar(
                'UPDATE indicador SET nome = ?, unidade = ?, sentido = ?, metrica_ancora = ?, horizonte_id = ?
                 WHERE id = ?',
                [$nome, $unidade, $sentido, $ancora, $horizonteId, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$planId, $nome, $unidade, $sentido, $ancora, $horizonteId]
            );
        }

        // Escolhas da cascata que este indicador mede — substitui o conjunto,
        // como `CascataController::salvar` faz com `cascata_fator`.
        //
        // O campo só entra no corpo quando o formulário o traz: quem chama a
        // API sem ele (outra tela, um teste) não pode ter os vínculos apagados
        // como efeito colateral de renomear o indicador.
        if (array_key_exists('cascatas', $d)) {
            $cascatas = array_values(array_unique(array_map('intval', (array)$d['cascatas'])));
            Database::executar('DELETE FROM indicador_cascata WHERE indicador_id = ?', [$id]);
            foreach ($cascatas as $cascataId) {
                // `exigirEdicaoPlanejamento` valida o PLANEJAMENTO, não os
                // filhos: sem conferir a escolha aqui, um GESTOR amarraria o
                // indicador dele a uma escolha de outro negócio passando o id.
                // Mesma guarda de `ProjetoController::salvar` para `cascata_id`.
                if (Database::um(
                    'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
                    [$cascataId, $planId]
                )) {
                    Database::executar(
                        'INSERT INTO indicador_cascata (indicador_id, cascata_id) VALUES (?, ?)',
                        [$id, $cascataId]
                    );
                }
            }
        }
        Json::ok(['id' => $id]);
    }

    /**
     * Lança a série plurianual de um indicador.
     * Corpo: { planejamento_id, tipo: META|REAL, valores: {ano: valor|null},
     *          nova_versao: bool } — nova_versao preserva a meta anterior.
     */
    public function salvarValores(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirIndicador($id, $planId);

        $tipo = ($d['tipo'] ?? '') === 'REAL' ? 'REAL' : 'META';
        $valores = is_array($d['valores'] ?? null) ? $d['valores'] : [];
        if (!$valores) {
            Json::erro('Informe ao menos um ano com valor.');
        }

        $versao = 1;
        if ($tipo === 'META') {
            $atual = (int)(Database::um(
                'SELECT MAX(versao_meta) v FROM indicador_valor WHERE indicador_id = ? AND tipo = \'META\'',
                [$id]
            )['v'] ?? 0);
            $versao = !empty($d['nova_versao']) ? $atual + 1 : max(1, $atual);
        }

        foreach ($valores as $ano => $valor) {
            $ano = (int)$ano;
            if ($ano < 2000 || $ano > 2100) {
                continue;
            }
            if ($valor === null || $valor === '') {
                // Limpar o ano remove todas as versões daquele ano — senão a
                // versão antiga voltaria a aparecer (a leitura usa a mais recente)
                Database::executar(
                    'DELETE FROM indicador_valor WHERE indicador_id = ? AND ano = ? AND tipo = ?',
                    [$id, $ano, $tipo]
                );
                continue;
            }
            Database::executar(
                'INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
                 VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$id, $ano, $tipo, $versao, (float)$valor]
            );
        }
        Json::ok(['versao' => $versao]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirIndicador($id, $planId);
        Database::executar('DELETE FROM indicador WHERE id = ?', [$id]);
        Json::ok();
    }

    private function exigirIndicador(int $id, int $planId): array
    {
        $ind = Database::um(
            'SELECT * FROM indicador WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$ind) {
            Json::erro('Indicador não encontrado neste planejamento.', 404);
        }
        return $ind;
    }
}

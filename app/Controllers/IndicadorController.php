<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Metas;

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

        // Escolhas da cascata que cada indicador mede. Numa consulta só, e não
        // no laço abaixo: ele já faz duas por indicador, e o modal precisa
        // apenas dos ids para marcar a lista.
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
        }
        unset($ind);
        // Metas e reais pela regra única (`App\Services\Metas`), que conhece o
        // mês, a versão e como 12 meses viram um ano. A Matriz de Execução e o
        // Relatório leem pela mesma função — três consultas próprias, com o mês
        // no meio, divergiriam na primeira revisão.
        $indicadores = Metas::anexarSeries($indicadores);

        Json::ok([
            'ciclo'         => $ciclo,
            'horizontes'    => $horizontes,
            'indicadores'   => $indicadores,
            // Qual ano a tela abre mês a mês. Vem do servidor porque é do ciclo:
            // o mesmo para todos os negócios e para o relatório.
            'ano_execucao'  => Metas::anoEmExecucao($ciclo),
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
        // Como os meses viram o ano. Valor desconhecido cai em SOMA, que é o
        // comportamento de quem só lança no ano — e não um erro de tela.
        $agregacao = in_array($d['agregacao'] ?? '', Metas::AGREGACOES, true)
            ? $d['agregacao'] : 'SOMA';
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
                'UPDATE indicador SET nome = ?, unidade = ?, sentido = ?, agregacao = ?,
                        metrica_ancora = ?, horizonte_id = ?
                 WHERE id = ?',
                [$nome, $unidade, $sentido, $agregacao, $ancora, $horizonteId, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO indicador (planejamento_id, nome, unidade, sentido, agregacao,
                                        metrica_ancora, horizonte_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$planId, $nome, $unidade, $sentido, $agregacao, $ancora, $horizonteId]
            );
        }
        $this->gravarCascatas($id, $planId, $d);
        Json::ok(['id' => $id]);
    }

    /**
     * As escolhas da cascata que este indicador mede — o conjunto substitui o
     * anterior, como `CascataController` faz com `cascata_fator`.
     *
     * A chave só é tocada quando vem no corpo: `salvar` é chamado por um modal
     * que pode não ter o campo (e um dia por outro que não o tenha), e tratar a
     * ausência como "lista vazia" apagaria vínculos que ninguém pediu para
     * apagar. É a mesma disciplina do `array_key_exists('sugestoes', $d)` da
     * cascata.
     *
     * A guarda é de IDOR, e é o motivo de a escolha ser conferida uma a uma:
     * `Auth::exigirEdicaoPlanejamento` valida o PLANEJAMENTO, não os filhos.
     * Sem ela, quem edita o indicador de um negócio amarra escolhas de outro
     * passando o id — o mesmo defeito que a guarda de `cascata_fator` fecha.
     * Id que não passa é ignorado calado: o front só oferece escolhas deste
     * planejamento, então um id de fora não é engano de quem clicou.
     */
    private function gravarCascatas(int $id, int $planId, array $d): void
    {
        if (!array_key_exists('cascatas', $d)) {
            return;
        }
        $cascatas = array_unique(array_map('intval', (array)$d['cascatas']));
        Database::executar('DELETE FROM indicador_cascata WHERE indicador_id = ?', [$id]);
        foreach ($cascatas as $cascataId) {
            $dela = Database::um(
                'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
                [$cascataId, $planId]
            );
            if ($dela) {
                Database::executar(
                    'INSERT INTO indicador_cascata (indicador_id, cascata_id) VALUES (?, ?)',
                    [$id, $cascataId]
                );
            }
        }
    }

    /**
     * Lança a série plurianual de um indicador.
     * Corpo: { planejamento_id, tipo: META|REAL, valores: {ano: valor|null},
     *          mes?: 0–12, nova_versao: bool } — nova_versao preserva a meta
     * anterior.
     *
     * O `mes` vale para TODOS os anos do corpo: ausente (ou 0) é o valor anual,
     * 1–12 é aquele mês de cada ano enviado. Uma chave só, e não um formato
     * novo de corpo, porque quem edita célula a célula manda um ano por vez e
     * quem lança a série inteira continua mandando o que sempre mandou — a
     * compatibilidade aqui não é cortesia, é o modal de revisão de meta.
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
        // Mês fora da faixa é RECUSADO, não encaixado: um 13 virando dezembro
        // (ou virando zero) gravaria no lugar errado sem ninguém ver. É a mesma
        // regra do texto que não é número, logo abaixo.
        $mes = $d['mes'] ?? Metas::ANO_INTEIRO;
        if (!is_numeric($mes) || (int)$mes != $mes || (int)$mes < 0 || (int)$mes > 12) {
            Json::erro('Mês inválido: use 0 para o ano inteiro ou de 1 a 12.');
        }
        $mes = (int)$mes;

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
                // Limpar remove todas as versões daquele ano+mês — senão a
                // versão antiga voltaria a aparecer (a leitura usa a mais
                // recente). Apaga só o GRÃO enviado: limpar o valor anual não
                // pode levar junto os doze meses, que são lançamento próprio.
                Database::executar(
                    'DELETE FROM indicador_valor
                      WHERE indicador_id = ? AND ano = ? AND mes = ? AND tipo = ?',
                    [$id, $ano, $mes, $tipo]
                );
                continue;
            }
            // Texto que não é número era gravado como 0,00 em silêncio — uma
            // meta "zero" com aparência de decisão.
            if (!is_numeric($valor)) {
                Json::erro("Valor inválido para {$ano}: informe um número.");
            }
            Database::executar(
                'INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor)
                 VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$id, $ano, $mes, $tipo, $versao, (float)$valor]
            );
        }
        // A série recalculada volta no mesmo pedido. Quem edita célula a célula
        // grava um valor e precisa do EFEITO dele — o total do ano muda quando
        // o lançamento é de mês —, e a alternativa seria a tela refazer a
        // agregação por conta própria: uma segunda cópia da regra, no idioma
        // errado. Recarregar a seção inteira custaria o foco de quem digita.
        $atualizado = Metas::anexarSeries([
            Database::um('SELECT id, agregacao FROM indicador WHERE id = ?', [$id]),
        ])[0];
        Json::ok([
            'versao' => $versao,
            'mes'    => $mes,
            'metas'  => $atualizado['metas'],
            'reais'  => $atualizado['reais'],
        ]);
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

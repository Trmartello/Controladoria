<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Email;
use App\Core\Json;
use App\Services\Avisos;

/**
 * Fase 6 — painéis (negócio, corporativo e consolidado) e relatório de
 * status da reunião por período/negócio (tela, impressão e Excel).
 */
class RelatorioController
{
    /**
     * Painel consolidado do ciclo: uma linha por planejamento visível ao
     * usuário (controladoria/direção: todos + corporativo; gestor: os seus).
     */
    public function painel(): void
    {
        $u = Auth::exigirLogin();
        $cicloId = (int)($_GET['ciclo_id'] ?? 0);
        $ciclo = $cicloId ? Database::um('SELECT * FROM ciclo WHERE id = ?', [$cicloId]) : null;
        if (!$ciclo) {
            Json::erro('Informe o ciclo.');
        }

        $horizontes = (int)(Database::um(
            'SELECT COUNT(*) n FROM horizonte WHERE ciclo_id = ?', [$cicloId]
        )['n'] ?? 0);
        $drivers = (int)(Database::um('SELECT COUNT(*) n FROM driver WHERE ativo = 1')['n'] ?? 0);
        $eixos   = (int)(Database::um('SELECT COUNT(*) n FROM eixo WHERE ativo = 1')['n'] ?? 0);
        $celulasTotal = $horizontes * $drivers * ($eixos + 1);

        $escopo = Auth::escopoNegocios($u);
        $sqlNeg = "SELECT id, CONCAT(cod_negocio, ' - ', nome) AS rotulo
                   FROM negocio WHERE ativo = 1";
        if ($escopo !== null) {
            $negocios = $escopo
                ? Database::todos(
                    "$sqlNeg AND id IN (" . implode(',', array_fill(0, count($escopo), '?')) . ")
                     ORDER BY CAST(cod_negocio AS UNSIGNED), nome",
                    $escopo
                )
                : [];
        } else {
            $negocios = Database::todos("$sqlNeg ORDER BY CAST(cod_negocio AS UNSIGNED), nome");
        }

        $linhas = [];
        if (Auth::veTudo($u)) {
            $linhas[] = $this->linhaPainel($cicloId, null, 'Corporativo', $celulasTotal);
        }
        foreach ($negocios as $n) {
            $linhas[] = $this->linhaPainel($cicloId, (int)$n['id'], $n['rotulo'], $celulasTotal);
        }

        $consolidado = [
            'cascata_feito' => array_sum(array_column($linhas, 'cascata_feito')),
            'cascata_total' => $celulasTotal * max(1, count($linhas)),
            'projetos'      => array_sum(array_column($linhas, 'projetos')),
            'atrasados'     => array_sum(array_column($linhas, 'atrasados')),
            'concluidos'    => array_sum(array_column($linhas, 'concluidos')),
            'envelope'      => array_sum(array_column($linhas, 'envelope')),
            'comprometido'  => array_sum(array_column($linhas, 'comprometido')),
            'ancoras'       => array_sum(array_column($linhas, 'ancoras')),
        ];

        Json::ok(['ciclo' => $ciclo, 'linhas' => $linhas, 'consolidado' => $consolidado]);
    }

    private function linhaPainel(int $cicloId, ?int $negocioId, string $rotulo, int $celulasTotal): array
    {
        $plan = Database::um(
            $negocioId === null
                ? "SELECT id FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'"
                : 'SELECT id FROM planejamento WHERE ciclo_id = ? AND negocio_id = ?',
            $negocioId === null ? [$cicloId] : [$cicloId, $negocioId]
        );
        $base = [
            'negocio_id' => $negocioId, 'rotulo' => $rotulo,
            'planejamento_id' => $plan ? (int)$plan['id'] : null,
            'cascata_feito' => 0, 'cascata_total' => $celulasTotal,
            'projetos' => 0, 'atrasados' => 0, 'concluidos' => 0, 'andamento' => 0,
            'envelope' => 0.0, 'comprometido' => 0.0, 'ancoras' => 0,
        ];
        if (!$plan) {
            return $base;
        }
        $pid = (int)$plan['id'];
        $conta = fn(string $sql) => (int)(Database::um($sql, [$pid])['n'] ?? 0);

        $base['cascata_feito'] = $conta('SELECT COUNT(*) n FROM cascata_escolha WHERE planejamento_id = ?');
        $base['projetos']   = $conta("SELECT COUNT(*) n FROM projeto WHERE planejamento_id = ? AND status <> 'CANCELADO'");
        $base['concluidos'] = $conta("SELECT COUNT(*) n FROM projeto WHERE planejamento_id = ? AND status = 'CONCLUIDO'");
        $base['andamento']  = $conta("SELECT COUNT(*) n FROM projeto WHERE planejamento_id = ? AND status = 'EM_ANDAMENTO'");
        // Atraso do projeto ou de qualquer desdobramento dele
        $base['atrasados'] = (int)(Database::um(
            "SELECT COUNT(DISTINCT p.id) n FROM projeto p
             LEFT JOIN desdobramento d ON d.projeto_id = p.id
             WHERE p.planejamento_id = ?
               AND (p.status = 'ATRASADO' OR d.status = 'ATRASADO')",
            [$pid]
        )['n'] ?? 0);
        $base['envelope'] = (float)(Database::um(
            'SELECT COALESCE(SUM(valor_limite), 0) v FROM envelope_capital WHERE planejamento_id = ?',
            [$pid]
        )['v'] ?? 0);
        $base['comprometido'] = (float)(Database::um(
            "SELECT COALESCE(SUM(valor), 0) v FROM investimento
             WHERE planejamento_id = ? AND situacao IN ('APROVADO', 'EXECUTADO', 'AUDITADO')",
            [$pid]
        )['v'] ?? 0);
        $base['ancoras'] = $conta('SELECT COUNT(*) n FROM indicador WHERE planejamento_id = ? AND metrica_ancora = 1');
        return $base;
    }

    /** Relatório de status em JSON (a tela renderiza; imprimir gera o PDF). */
    public function relatorio(): void
    {
        Json::ok($this->dadosRelatorio());
    }

    /** Exportação Excel (tabela HTML com content-type .xls — sem dependências). */
    public function exportar(): void
    {
        $r = $this->dadosRelatorio();
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $num = fn($v) => $v === null ? '' : number_format((float)$v, 2, ',', '.');

        $html = '<html><head><meta charset="UTF-8"></head><body>';
        $html .= '<h2>Relatório de Status — ' . $esc($r['rotulo']) . '</h2>';
        $html .= '<p>Ciclo ' . $esc($r['ciclo']['nome']) . ' · Período ' . $esc($r['periodo']['de'])
            . ' a ' . $esc($r['periodo']['ate']) . '</p>';

        $html .= '<h3>Metas e indicadores</h3><table border="1"><tr><th>Indicador</th><th>Unidade</th><th>Horizonte</th><th>Âncora</th>';
        foreach ($r['anos'] as $ano) {
            $html .= "<th>Meta $ano</th><th>Real $ano</th>";
        }
        $html .= '</tr>';
        foreach ($r['indicadores'] as $i) {
            $html .= '<tr><td>' . $esc($i['nome']) . '</td><td>' . $esc($i['unidade']) . '</td><td>'
                . $esc($i['horizonte_nome'] ?? '—') . '</td><td>' . ($i['metrica_ancora'] ? 'Sim' : '') . '</td>';
            $metas = array_column($i['metas'], 'valor', 'ano');
            $reais = array_column($i['reais'], 'valor', 'ano');
            foreach ($r['anos'] as $ano) {
                $html .= '<td>' . $num($metas[$ano] ?? null) . '</td><td>' . $num($reais[$ano] ?? null) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<h3>Projetos</h3><table border="1"><tr><th>Projeto</th><th>Ano</th><th>Responsável</th>
            <th>Prazo</th><th>Status</th><th>Progresso médio (%)</th></tr>';
        foreach ($r['projetos'] as $p) {
            $html .= '<tr><td>' . $esc($p['titulo']) . '</td><td>' . $esc($p['ano']) . '</td><td>'
                . $esc($p['responsavel']) . '</td><td>' . $esc($p['prazo']) . '</td><td>'
                . $esc($p['status']) . '</td><td>' . $esc($p['progresso']) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h3>Capital</h3><table border="1"><tr><th>Horizonte</th><th>Envelope (R$)</th><th>Comprometido (R$)</th></tr>';
        foreach ($r['capital'] as $c) {
            $html .= '<tr><td>' . $esc($c['horizonte']) . '</td><td>' . $num($c['envelope'])
                . '</td><td>' . $num($c['comprometido']) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h3>Decisões de investimento no período</h3><table border="1"><tr><th>Data</th><th>Investimento</th><th>Situação</th><th>Valor (R$)</th><th>Critério</th></tr>';
        foreach ($r['decisoes'] as $d) {
            $html .= '<tr><td>' . $esc($d['decisao_data']) . '</td><td>' . $esc($d['descricao'])
                . '</td><td>' . $esc($d['situacao']) . '</td><td>' . $num($d['valor'])
                . '</td><td>' . $esc($d['decisao_criterio']) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h3>Diário de bordo do período</h3><table border="1"><tr><th>Data</th><th>Referência</th><th>Registro</th><th>Autor</th></tr>';
        foreach ($r['diario'] as $d) {
            $html .= '<tr><td>' . $esc($d['data_reg']) . '</td><td>' . $esc($d['referencia'])
                . '</td><td>' . $esc($d['texto']) . '</td><td>' . $esc($d['autor']) . '</td></tr>';
        }
        $html .= '</table></body></html>';

        $arquivo = 'relatorio-status-' . preg_replace('/[^a-z0-9]+/i', '-', $r['rotulo'])
            . '-' . $r['periodo']['ate'] . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        echo "\u{FEFF}" . $html; // BOM: acentuação correta no Excel
        exit;
    }

    private function dadosRelatorio(): array
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);

        $hoje = date('Y-m-d');
        $de  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : date('Y-m-d', strtotime('-30 days'));
        $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : $hoje;

        $ciclo = Database::um('SELECT * FROM ciclo WHERE id = ?', [(int)$plan['ciclo_id']]);
        $rotulo = $plan['escopo'] === 'CORPORATIVO'
            ? 'Corporativo'
            : (Database::um(
                "SELECT CONCAT(cod_negocio, ' - ', nome) r FROM negocio WHERE id = ?",
                [(int)$plan['negocio_id']]
            )['r'] ?? '');

        $anos = range((int)$ciclo['ano_base'], (int)$ciclo['ano_fim']);

        $indicadores = Database::todos(
            'SELECT i.*, h.nome AS horizonte_nome
             FROM indicador i LEFT JOIN horizonte h ON h.id = i.horizonte_id
             WHERE i.planejamento_id = ? ORDER BY i.metrica_ancora DESC, i.nome',
            [$planId]
        );
        foreach ($indicadores as &$ind) {
            $ind['metas'] = Database::todos(
                'SELECT v.ano, v.valor FROM indicador_valor v
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
        unset($ind);

        $projetos = Database::todos(
            "SELECT p.id, p.titulo, p.ano, p.responsavel, p.status, p.classificacao,
                    COALESCE(
                      CONCAT(DATE_FORMAT(p.data_inicio, '%d/%m/%Y'), ' a ', DATE_FORMAT(p.data_fim, '%d/%m/%Y')),
                      CONCAT('a partir de ', DATE_FORMAT(p.data_inicio, '%d/%m/%Y')),
                      CONCAT('até ', DATE_FORMAT(p.data_fim, '%d/%m/%Y')),
                      p.prazo, ''
                    ) AS prazo,
                    h.nome AS horizonte_nome,
                    COALESCE(ROUND(AVG(d.progresso)), 0) AS progresso,
                    SUM(d.status = 'ATRASADO') AS desdobramentos_atrasados
             FROM projeto p
             LEFT JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN desdobramento d ON d.projeto_id = p.id
             WHERE p.planejamento_id = ? AND p.status <> 'CANCELADO'
             GROUP BY p.id
             ORDER BY p.ano, p.id",
            [$planId]
        );

        $capital = Database::todos(
            "SELECT h.nome AS horizonte,
                    COALESCE(e.valor_limite, 0) AS envelope,
                    COALESCE((SELECT SUM(i.valor) FROM investimento i
                              WHERE i.planejamento_id = ? AND i.horizonte_id = h.id
                                AND i.situacao IN ('APROVADO', 'EXECUTADO', 'AUDITADO')), 0) AS comprometido
             FROM horizonte h
             LEFT JOIN envelope_capital e ON e.horizonte_id = h.id AND e.planejamento_id = ?
             WHERE h.ciclo_id = ? ORDER BY h.ordem, h.ano_inicio",
            [$planId, $planId, (int)$plan['ciclo_id']]
        );

        $decisoes = Database::todos(
            "SELECT descricao, situacao, valor, decisao_data, decisao_criterio
             FROM investimento
             WHERE planejamento_id = ? AND decisao_data BETWEEN ? AND ?
             ORDER BY decisao_data, id",
            [$planId, $de, $ate]
        );

        // Diário do período com o rótulo da referência (projeto/5W2H/investimento/cascata)
        $diario = Database::todos(
            "SELECT db.data_reg, db.texto, db.status_atual, db.progresso, u.nome AS autor,
                    CASE db.ref_tipo
                      WHEN 'PROJETO' THEN CONCAT('Projeto: ', COALESCE(p.titulo, '?'))
                      WHEN 'DESDOBRAMENTO' THEN CONCAT('5W2H: ', COALESCE(dd.o_que, '?'))
                      WHEN 'INVESTIMENTO' THEN CONCAT('Investimento: ', COALESCE(i.descricao, '?'))
                      WHEN 'CASCATA' THEN CONCAT('Cascata: ', COALESCE(LEFT(ce.escolha, 80), '?'))
                      ELSE db.ref_tipo
                    END AS referencia
             FROM diario_bordo db
             JOIN usuario u ON u.id = db.autor_id
             LEFT JOIN projeto p ON db.ref_tipo = 'PROJETO' AND p.id = db.ref_id
             LEFT JOIN desdobramento dd ON db.ref_tipo = 'DESDOBRAMENTO' AND dd.id = db.ref_id
             LEFT JOIN projeto pd ON pd.id = dd.projeto_id
             LEFT JOIN investimento i ON db.ref_tipo = 'INVESTIMENTO' AND i.id = db.ref_id
             LEFT JOIN cascata_escolha ce ON db.ref_tipo = 'CASCATA' AND ce.id = db.ref_id
             WHERE db.data_reg BETWEEN ? AND ?
               AND (
                 (db.ref_tipo = 'PROJETO' AND p.planejamento_id = ?)
                 OR (db.ref_tipo = 'DESDOBRAMENTO' AND pd.planejamento_id = ?)
                 OR (db.ref_tipo = 'INVESTIMENTO' AND i.planejamento_id = ?)
                 OR (db.ref_tipo = 'CASCATA' AND ce.planejamento_id = ?)
               )
             ORDER BY db.data_reg DESC, db.id DESC",
            [$de, $ate, $planId, $planId, $planId, $planId]
        );

        return [
            'planejamento' => $plan,
            'rotulo'       => $rotulo,
            'ciclo'        => $ciclo,
            'periodo'      => ['de' => $de, 'ate' => $ate],
            'anos'         => $anos,
            'indicadores'  => $indicadores,
            'projetos'     => $projetos,
            'capital'      => $capital,
            'decisoes'     => $decisoes,
            'diario'       => $diario,
        ];
    }

    /**
     * Dispara na hora os avisos por e-mail (o mesmo que o agendamento diário
     * faria). Útil para conferir a configuração de SMTP e para o caso de o
     * agendamento ainda não estar ligado. Nada é reenviado no mesmo dia.
     */
    public function despacharAvisos(): void
    {
        Auth::exigirAdministrador();
        if (!Email::configurado()) {
            Json::erro('Envio de e-mail não configurado — defina SMTP_HOST e SMTP_REMETENTE no ambiente.');
        }
        try {
            Json::ok(Avisos::despachar('auto'));
        } catch (\Throwable $e) {
            Json::erro('Falha ao enviar: ' . $e->getMessage());
        }
    }
}

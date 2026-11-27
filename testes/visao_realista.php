<?php

/**
 * Provas da VISÃO REALISTA do ano corrente (pedido do cliente, 2026-11-18): a
 * regra do "Painel Balanço out/26", que a direção já viu, agora na Projeção.
 *
 * A âncora não é a fórmula — é o NÚMERO do painel: os 11 negócios, receita bruta
 * e resultado líquido de nov–dez, como o artefato os publicou (extração de
 * 15/11/2026, mesmos dados da carga de 17/11). Se a regra daqui divergir da de
 * lá, a direção veria dois números diferentes para a mesma coisa.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/visao_realista.php
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/Metas.php';
require_once __DIR__ . '/../app/Services/DesafioCota.php';
require_once __DIR__ . '/../app/Services/DesafioDrivers.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Services/DesafioAjuste.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\Projecao;

$ok = 0;
$bad = [];
function prova(string $nome, bool $cond, string $extra = ''): void
{
    global $ok, $bad;
    if ($cond) {
        $ok++;
        echo "  ✓ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $bad[] = $nome . ($extra ? " — {$extra}" : '');
        echo "  ✗ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

// ── 1. A regra, com números de mão ──────────────────────────────────────────
// Receita: vendeu 90 de 100 planejados até outubro → nov–dez = 20 × 0,9 = 18.
$r = Projecao::visaoRealista(['realizado' => 90, 'plan_ate' => 100, 'plan_resto' => 20], '1', 10);
prova('receita: planejado de nov–dez × aderência (20 × 90/100 = 18)', abs($r['realista_resto'] - 18) < 1e-9, (string)$r['realista_resto']);
prova('e as colunas do quadro: real + plan. 110, plano do ano 120, diferença −10 (−8,3%)',
    $r['real_plan'] == 110 && $r['plan_ano'] == 120 && $r['dif'] == -10 && abs($r['dif_pct'] + 10 / 120) < 1e-9);
prova('real + realista 108 e realista × plano −12', abs($r['real_realista'] - 108) < 1e-9 && abs($r['realista_x_plano'] + 12) < 1e-9);
// Resultado: errou 10 em 10 meses → −1 por mês → nov–dez = 5 + (−1 × 2) = 3.
$r = Projecao::visaoRealista(['realizado' => 30, 'plan_ate' => 40, 'plan_resto' => 5], '16', 10);
prova('resultado: planejado + desvio médio mensal × meses que faltam (5 + −1 × 2 = 3)', abs($r['realista_resto'] - 3) < 1e-9, (string)$r['realista_resto']);
$r = Projecao::visaoRealista(['realizado' => 30, 'plan_ate' => 40, 'plan_resto' => 5], '16', 9);
prova('o corte manda: com setembro fechado são 3 meses (5 + −10/9 × 3)', abs($r['realista_resto'] - (5 - 10 / 9 * 3)) < 1e-9);
$r = Projecao::visaoRealista(['realizado' => 0, 'plan_ate' => 0, 'plan_resto' => 7], '1', 10);
prova('sem plano até o corte não há aderência: vale o planejado (e não divisão por zero)', $r['realista_resto'] == 7.0);

// ── 2. Os números do Painel Balanço out/26, negócio a negócio ───────────────
$painel = [ // cód. negócio => [receita bruta nov–dez, resultado líquido nov–dez]
    '1' => [43472615.35, 3052383.5], '2' => [142940463.66, 2226773.66], '4' => [55457593.95, 141799.5],
    '6' => [46188969.56, 1071883.48], '7' => [213588.54, 139150.73], '8' => [226038188.43, 6219647.86],
    '9' => [50757963.02, 1020349.21], '11' => [12805223.89, 304178.97], '12' => [1155410.24, 261396.73],
    '13' => [1885653.76, -710289.85], '17' => [0.0, 97341.42],
];
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
prova('o fechamento no banco é o do painel (outubro de 2026)', $ano === 2026 && $corte === 10, "{$ano}/{$corte}");
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[$n['cod_negocio']] = (int)$n['id'];
}
$ctrl = new App\Controllers\ProjecaoController();
$ler = new ReflectionMethod($ctrl, 'visaoDoAno');
$ler->setAccessible(true);
$v = $ler->invoke($ctrl, array_values(array_intersect_key($ids, $painel)), $ano, $corte);
// A REGRA é a do painel: aplicada às MESMAS entradas que o painel usou (realizado
// jan–out, planejado jan–out e nov–dez, extração de 15/11/2026), dá os MESMOS
// números de nov–dez, negócio a negócio, ao centavo.
$entradas = [ // cód. => [[receita: real, plan. até out, plan. nov–dez], [resultado: idem]]
    '1' => [[472958223.28, 556104691.0, 51115139.0], [-15342534.57, -1063608.46, 5908168.72]],
    '2' => [[676011302.35, 731359363.0, 154643637.0], [9580825.1, 28454740.57, 6001556.76]],
    '4' => [[269937321.99, 230620000.0, 47380000.0], [917092.96, 658093.69, 89999.65]],
    '6' => [[276545388.33, 280808500.0, 46901000.0], [3630974.13, 702565.47, 486201.75]],
    '7' => [[1158793.01, 770400.0, 142000.0], [817197.36, 485566.3, 72824.52]],
    '8' => [[1001366486.79, 1113337158.0, 251313298.0], [13516733.58, 18119139.77, 7140129.1]],
    '9' => [[216062738.86, 219707322.0, 51614157.0], [6772801.49, 3573218.27, 380432.57]],
    '11' => [[59689694.3, 59958077.0, 12862800.0], [2353861.09, 1222160.81, 77838.92]],
    '12' => [[5580134.99, 5015446.0, 1038487.0], [1333681.5, 950879.02, 184836.23]],
    '13' => [[13321074.64, 13705000.0, 1940000.0], [1040857.49, 2309218.38, -456617.67]],
    '17' => [[0.0, 0.0, 0.0], [305707.1, 464464.7, 129092.94]],
];
$divergem = [];
foreach ($painel as $cod => [$rob, $rl]) {
    [$e1, $e16] = $entradas[$cod];
    $a = Projecao::visaoRealista(['realizado' => $e1[0], 'plan_ate' => $e1[1], 'plan_resto' => $e1[2]], '1', 10);
    $b = Projecao::visaoRealista(['realizado' => $e16[0], 'plan_ate' => $e16[1], 'plan_resto' => $e16[2]], '16', 10);
    if (abs($a['realista_resto'] - $rob) > 0.01 || abs($b['realista_resto'] - $rl) > 0.01) {
        $divergem[] = "{$cod}: " . round($a['realista_resto'], 2) . '/' . round($b['realista_resto'], 2);
    }
}
prova('com as entradas do painel, a regra dá os números do painel nos 11 negócios, ao centavo', !$divergem, implode(' | ', $divergem));

// E com os dados do BANCO (carga de 17/11), cada negócio fica a poucos reais do
// painel — com UMA diferença conhecida: entre 15/11 e 17/11 o Qlik moveu R$ 8,6 mil
// de resultado realizado da Fábrica de Rações (6) para as Agropecuárias (8), o que
// dá ±R$ 1,7 mil no resultado realista de nov–dez dos dois (8,6 mil ÷ 10 × 2). E a
// recarga de outubro de 25/11 (`dre_2026_fechamento_10_r2`) moveu mais R$ 6,0 mil
// entre os dois (custo da filial 106) — ±R$ 2,9 mil ao todo — e o resíduo da 14.1:
// +R$ 403 em Cereais (1) e −R$ 98 nos Supermercados (9), +R$ 81 e −R$ 20 no
// realista. O total não muda, e é o que a prova seguinte fixa.
$divergem = [];
foreach ($painel as $cod => [$rob, $rl]) {
    $x = $v[$ids[$cod]] ?? null;
    // chave numérica vira int no PHP
    $tolRl = in_array((string)$cod, ['6', '8'], true) ? 3000 : (in_array((string)$cod, ['1', '9'], true) ? 100 : 5);
    if (!$x || abs($x['1']['realista_resto'] - $rob) > 5 || abs($x['18']['realista_resto'] - $rl) > $tolRl) {
        $divergem[] = "{$cod}: " . ($x ? round($x['1']['realista_resto']) . '/' . round($x['18']['realista_resto']) : 'ausente');
    }
}
prova('com os dados do banco, os 11 negócios ficam no painel (R$ 5; ±R$ 3 mil em 6 e 8 e R$ 100 em 1 e 9, os lançamentos que mudaram de negócio)',
    !$divergem, implode(' | ', $divergem));

$tot = (new ReflectionMethod($ctrl, 'visaoDoAnoTotal'));
$tot->setAccessible(true);
$t = $tot->invoke($ctrl, array_values(array_intersect_key($ids, $painel)), $ano, $corte);
prova('total do painel: receita bruta realista de nov–dez R$ 580.915.670 e real + realista R$ 3.573.546.829',
    abs($t['1']['realista_resto'] - 580915670) < 20 && abs($t['1']['real_realista'] - 3573546829) < 20,
    round($t['1']['realista_resto']) . ' / ' . round($t['1']['real_realista']));
prova('total do painel: resultado líquido realista de nov–dez R$ 13.824.615, real + realista R$ 38.751.812, realista × plano −R$ 37.139.090',
    abs($t['18']['realista_resto'] - 13824615) < 20 && abs($t['18']['real_realista'] - 38751812) < 20
    && abs($t['18']['realista_x_plano'] + 37139090) < 20,
    round($t['18']['realista_resto']) . ' / ' . round($t['18']['real_realista']) . ' / ' . round($t['18']['realista_x_plano']));
prova('o total é a SOMA dos negócios, e não a regra aplicada ao total',
    abs($t['1']['realista_resto'] - array_sum(array_map(static fn ($x) => $x['1']['realista_resto'], $v))) < 0.01);

// ── As linhas do seletor (2026-11-25) ───────────────────────────────────────
// Receita líquida escala como a bruta (aderência); lucro bruto, despesas e
// resultado do negócio (15; até 2026-11-27, o 13 — antes dos rateios) seguem o desvio médio, como o líquido. De mão, com
// números em que as duas regras dão resultados diferentes: realizado 90, plano
// até o corte 100, resto 40, corte 10 — aderência 40 × 0,9 = 36; desvio
// 40 + (90 − 100)/10 × 2 = 38.
$e = ['realizado' => 90.0, 'plan_ate' => 100.0, 'plan_resto' => 40.0];
prova('receita líquida (3): aderência, 40 × 90/100 = 36', abs(Projecao::visaoRealista($e, '3', 10)['realista_resto'] - 36) < 1e-9);
foreach (['5', '7', '15'] as $c) {
    prova("linha {$c}: desvio médio, 40 + (90 − 100)/10 × 2 = 38", abs(Projecao::visaoRealista($e, $c, 10)['realista_resto'] - 38) < 1e-9);
}
$tem = array_map('strval', array_keys(reset($v) ?: []));
sort($tem);
prova('a visão do ano de cada negócio traz as seis linhas', $tem === ['1', '3', '5', '7', '15', '18'], json_encode($tem));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

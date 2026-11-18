<?php

/**
 * Provas da ESTRUTURA NOVA da DRE gerencial ("DRE - Gerencial Nova", pedido do
 * cliente, 2026-11-18) e da CBS de 2027 pela LC 214/2025.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/estrutura_dre_nova.php
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Services/CargaDre.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaDre;
use App\Services\Projecao;
use App\Services\Reforma;

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

// ── 1. A estrutura ──────────────────────────────────────────────────────────
$nomes = array_column(Database::todos('SELECT codigo, nome FROM dre_conta'), 'nome', 'codigo');
prova('13 = "11 - RESULTADO APÓS IMPOSTOS", 15 = "14 - RESULTADO DO NEGÓCIO", 18 = "RESULTADO LÍQUIDO"',
    ($nomes['13'] ?? '') === '11 - RESULTADO APÓS IMPOSTOS' && ($nomes['15'] ?? '') === '14 - RESULTADO DO NEGÓCIO'
    && ($nomes['18'] ?? '') === 'RESULTADO LÍQUIDO' && ($nomes['16.1'] ?? '') === 'Rateios Corporativos Administrativo',
    json_encode([$nomes['13'] ?? null, $nomes['15'] ?? null, $nomes['18'] ?? null]));
prova('o motor deriva a estrutura nova: 15 = 13 + 14, 16 = 16.1, 18 = 15 + 16, e Despesas Totais (17) sem proposta',
    Projecao::DERIVADAS['15'] === ['13', '14'] && Projecao::DERIVADAS['16'] === ['16.1']
    && Projecao::DERIVADAS['18'] === ['15', '16'] && Projecao::SEM_PROPOSTA === ['17']);

// ── 2. As identidades nos dados, negócio a negócio e mês a mês ──────────────
$v = [];
foreach (Database::todos("SELECT v.negocio_id, c.codigo, v.tipo, v.mes, v.valor FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
    WHERE v.ano = 2026 AND c.codigo IN ('13','14','14.1','14.2','14.3','15','16','16.1','18')") as $r) {
    $v[$r['negocio_id']][$r['tipo']][(int)$r['mes']][$r['codigo']] = (float)$r['valor'];
}
$quebras = [];
foreach ($v as $n => $tipos) {
    foreach ($tipos as $tipo => $meses) {
        foreach ($meses as $mes => $c) {
            $g = static fn ($k) => $c[$k] ?? 0.0;
            foreach ([
                ['14', $g('14.1') + $g('14.2') + $g('14.3')],
                ['15', $g('13') + $g('14')],
                ['16', $g('16.1')],
                ['18', $g('15') + $g('16')],
            ] as [$k, $esperado]) {
                if (abs($g($k) - $esperado) > 3) {
                    $quebras[] = "{$n}/{$tipo}/{$mes}/{$k}";
                }
            }
        }
    }
}
prova('14 = 14.1+14.2+14.3, 15 = 13+14, 16 = 16.1, 18 = 15+16 em todos os negócios e meses (R$ 3)', !$quebras && count($v) === 11,
    implode(' ', array_slice($quebras, 0, 6)));
$soma = static fn ($c) => (float)Database::um("SELECT SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
    WHERE c.codigo = ? AND v.ano = 2026 AND v.tipo = 'REAL' AND v.mes <= 10", [$c])['s'];
prova('jan–out: resultado líquido R$ 24.927.200 — o MESMO da DRE atual (o rateio só foi dividido)', abs($soma('18') - 24927200) < 5, (string)round($soma('18')));
prova('jan–out: rateios corporativos negócio −R$ 25,49 mi e administrativo −R$ 11,27 mi, como no app novo',
    abs($soma('14.3') + 25486929) < 20 && abs($soma('16.1') + 11268322) < 20, round($soma('14.3')) . ' / ' . round($soma('16.1')));
prova('jan–out: "11 - Resultado após impostos" R$ 137,0 mi, igual à linha 13 da DRE atual', abs($soma('13') - 136995298) < 5);

// ── 3. As filiais na estrutura nova ─────────────────────────────────────────
$f = [];
foreach (Database::todos("SELECT f.negocio_id, v.filial_id, c.codigo, v.realizado, v.projetado, v.corte FROM dre_valor_filial v
    JOIN filial f ON f.id = v.filial_id JOIN dre_conta c ON c.id = v.conta_id WHERE v.ano = 2026") as $r) {
    $f[$r['filial_id']][$r['codigo']] = [(float)$r['realizado'], (float)$r['projetado']];
    $f[$r['filial_id']]['_neg'] = (int)$r['negocio_id'];
}
$semNova = array_filter($f, static fn ($x) => !isset($x['16.1']));
prova('toda filial está na estrutura nova (tem a linha 16.1)', !$semNova && count($f) > 90, count($f) . ' filiais, ' . count($semNova) . ' sem');
$quebras = [];
foreach ($f as $id => $c) {
    $g = static fn ($k, $i) => $c[$k][$i] ?? 0.0;
    foreach ([0, 1] as $i) {
        if (abs($g('18', $i) - $g('15', $i) - $g('16', $i)) > 5 || abs($g('15', $i) - $g('13', $i) - $g('14', $i)) > 5) {
            $quebras[] = $id;
        }
    }
}
prova('nas filiais, 18 = 15 + 16 e 15 = 13 + 14 (realizado e projetado, R$ 5 de arredondamento da carga)', !$quebras, implode(',', array_slice(array_unique($quebras), 0, 5)));
// Pecuária (filiais no corte do negócio): a soma das filiais fecha com o negócio no líquido.
$pec = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '2'")['id'];
$somaFil = 0.0;
foreach ($f as $c) {
    if ($c['_neg'] === $pec) {
        $somaFil += $c['18'][0] ?? 0.0;
    }
}
$liqPec = (float)Database::um("SELECT SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
    WHERE v.negocio_id = ? AND c.codigo = '18' AND v.ano = 2026 AND v.tipo = 'REAL' AND v.mes <= 10", [$pec])['s'];
prova('Pecuária: o resultado líquido realizado das filiais soma o do negócio (a conversão não o mexeu)',
    abs($somaFil - $liqPec) < 50, round($somaFil) . ' vs ' . round($liqPec));
prova('a conversão é idempotente: rodar de novo não converte nada', CargaDre::migrarFiliaisParaEstruturaNova(Database::conn(), 2026) === 0);

// ── 4. A CBS de 2027 (LC 214) ───────────────────────────────────────────────
$trib = ['parte_nao_socio' => 0.2, 'taxa_pis_cofins' => 0.005];
// Lojas (8): 11,3% × (1 − 60%) = 4,52%; base = 1.000 × 20% = 200 → débito 9,04;
// crédito = custo 800 × 20% × 100% × 4,52% = 7,232 → CBS 1,808. PIS/COFINS = 5 → efeito +3,192.
$c = Reforma::cbs('8', $trib, 1000.0, 800.0);
prova('Lojas, de mão: alíquota efetiva 4,52%, débito 9,04, crédito 7,232, CBS 1,808',
    abs($c['aliquota_efetiva'] - 4.52) < 1e-9 && abs($c['debito'] - 9.04) < 1e-9 && abs($c['credito'] - 7.232) < 1e-9 && abs($c['cbs'] - 1.808) < 1e-9,
    json_encode([$c['aliquota_efetiva'], $c['debito'], $c['credito'], $c['cbs']]));
prova('efeito no resultado = PIS/COFINS que sai − CBS que entra (5 − 1,808 = +3,192)', abs($c['impacto'] - 3.192) < 1e-9);
prova('sem o regime específico (art. 271), a venda a sócio também é base (CBS 9,04 sobre 1.000)',
    abs($c['cbs_sem_regime'] - (1000 * 0.0452 - 800 * 0.0452)) < 1e-9, (string)$c['cbs_sem_regime']);
prova('combustíveis: monofásico, o posto não recolhe CBS', Reforma::cbs('11', $trib, 1000.0, 800.0)['cbs'] === 0.0);
prova('grãos comprados do associado não dão crédito (Cereais: CBS = débito)',
    abs(Reforma::cbs('1', $trib, 1000.0, 800.0)['credito']) < 1e-12);

// ── 5. A troca na proposta (linha 2.3) ──────────────────────────────────────
$ctrl = new App\Controllers\ProjecaoController();
$m = new ReflectionMethod($ctrl, 'montar');
$m->setAccessible(true);
$agro = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '8'")['id'];
$d = $m->invoke($ctrl, $agro, 2026, 10, 0);
$l = array_column($d['linhas'], null, 'codigo');
$c = $l['2.3']['cbs'] ?? null;
$fator = $l['1.1']['proposta'] / $l['1.1']['projecao'];
prova('Agropecuária: a linha 2.3 da proposta = projeção × fator da receita + PIS/COFINS de 2026 − CBS de 2027',
    $c && abs($l['2.3']['proposta'] - ($l['2.3']['projecao'] * $fator + $c['pis_cofins'] - $c['cbs'])) < 1
    && ($l['2.3']['proposta_regra'] ?? null) === 'CBS',
    json_encode([$l['2.3']['proposta'], $c['pis_cofins'] ?? null, $c['cbs'] ?? null]));
$dz = $d['desafio']['desafio'];
prova('no desafio: resultado com a CBS = resultado + (PIS/COFINS − CBS)',
    isset($dz['resultado_com_cbs']) && abs($dz['resultado_com_cbs'] - $dz['resultado'] - $dz['cbs']['impacto']) < 1e-6);

echo "\nComo é × como fica (o PIS/COFINS muda de linha)\n";
// O exemplo do cliente, de mão: RB 100, ICMS 40, PIS/COFINS 10, devoluções 10.
$mao = Reforma::comoEComoFica([
    ['codigo' => '1', 'realizado' => 100.0, 'projecao' => 100.0, 'proposta' => 100.0],
    ['codigo' => '1.1', 'realizado' => 100.0, 'projecao' => 100.0, 'proposta' => 100.0],
    ['codigo' => '2', 'realizado' => -60.0, 'projecao' => -60.0, 'proposta' => -60.0],
    ['codigo' => '2.1', 'realizado' => -10.0, 'projecao' => -10.0, 'proposta' => -10.0],
    ['codigo' => '2.2', 'realizado' => 0.0, 'projecao' => 0.0, 'proposta' => 0.0],
    ['codigo' => '2.3', 'realizado' => -50.0, 'projecao' => -50.0, 'proposta' => -50.0],
    ['codigo' => '3', 'realizado' => 40.0, 'projecao' => 40.0, 'proposta' => 40.0],
], ['cod' => '17', 'trib' => ['mes_ate' => 10, 'pis' => 2.0, 'cofins' => 8.0, 'taxa_pis_cofins' => 0.1,
    'parte_nao_socio' => 1.0, 'venda_socio' => 0.0, 'venda_nao_socio' => 100.0]], 10);
$r = $mao['colunas']['realizado'];
prova('exemplo do cliente: hoje RB 100, deduções 60, 2.3 = ICMS 40 + PIS/COFINS 10, RL 40',
    $r['como_e']['receita_bruta'] == 100 && $r['como_e']['deducoes'] == 60 && $r['como_e']['icms_iss'] == 40
    && $r['como_e']['pis_cofins'] == 10 && $r['como_e']['receita_liquida'] == 40, json_encode($r['como_e']));
prova('e como fica: CBS 10 informativa, RB 90, deduções 50, 2.3 = ICMS 40, a MESMA RL 40',
    $r['como_fica']['tributo'] == 10 && $r['como_fica']['receita_bruta'] == 90 && $r['como_fica']['deducoes'] == 50
    && $r['como_fica']['impostos'] == 40 && $r['como_fica']['receita_liquida'] == 40 && $r['efeito'] == 0, json_encode($r['como_fica']));

// O consolidado de verdade: o realizado jan–out reproduz o slide da controladoria.
$corp = (new ReflectionMethod($ctrl, 'montarCorporativo'))->invoke($ctrl,
    Database::todos('SELECT n.* FROM negocio n WHERE EXISTS (SELECT 1 FROM dre_valor v WHERE v.negocio_id = n.id) ORDER BY n.id'), 2026, 10);
$t = $corp['tributos_dre']['colunas']['realizado'];
$mi = static fn ($x) => round($x / 1e6, 1);
prova('Corporativo jan–out/26, como é: RB 2.992,6 · deduções 106,9 · 2.3 59,6 = ICMS e ISS 44,0 + PIS/COFINS 15,6 · RL 2.885,8 (o slide)',
    $mi($t['como_e']['receita_bruta']) == 2992.6 && $mi($t['como_e']['deducoes']) == 106.9 && $mi($t['como_e']['impostos']) == 59.6
    && $mi($t['como_e']['icms_iss']) == 44.0 && $mi($t['como_e']['pis_cofins']) == 15.6 && $mi($t['como_e']['receita_liquida']) == 2885.8,
    json_encode(array_map($mi, $t['como_e'])));
prova('e como fica: CBS 15,6 · RB 2.977,1 · deduções 91,3 · 2.3 44,0 · RL 2.885,8 (o slide)',
    $mi($t['como_fica']['tributo']) == 15.6 && $mi($t['como_fica']['receita_bruta']) == 2977.1 && $mi($t['como_fica']['deducoes']) == 91.3
    && $mi($t['como_fica']['impostos']) == 44.0 && $mi($t['como_fica']['receita_liquida']) == 2885.8,
    json_encode(array_map($mi, $t['como_fica'])));

// Na proposta a tabela já está no "como fica": a RL e o líquido dela são os do lado da CBS.
$tp = $d['tributos_dre']['colunas']['proposta'];
prova('Agropecuária 2027: o "como fica" é a tabela (RL e resultado líquido da proposta), com a CBS da 2.3',
    $d['tributos_dre']['cbs_aplicada'] && abs($tp['como_fica']['receita_liquida'] - $l['3']['proposta']) < 1
    && abs($tp['abaixo']['como_fica']['resultado'] - $l['18']['proposta']) < 1
    && abs($tp['como_fica']['tributo'] - $c['cbs']) < 1, json_encode([$tp['como_fica']['receita_liquida'], $l['3']['proposta']]));
prova('e o "como é" desfaz a troca: RL e resultado = tabela − (PIS/COFINS − CBS), igual em todas as linhas abaixo',
    abs($tp['efeito'] - ($c['pis_cofins'] - $c['cbs'])) < 1
    && abs($tp['abaixo']['como_e']['resultado'] - ($l['18']['proposta'] - $tp['efeito'])) < 1
    && abs($tp['abaixo']['como_e']['lucro_bruto'] - ($l['5']['proposta'] - $tp['efeito'])) < 1);
prova('o Corporativo soma os negócios (efeito da proposta = soma dos efeitos)',
    abs($corp['tributos_dre']['colunas']['proposta']['efeito'] - array_sum(array_map(
        static fn ($n) => (new ReflectionMethod($ctrl, 'montar'))->invoke($ctrl, (int)$n['id'], 2026, 10, 0)['tributos_dre']['colunas']['proposta']['efeito'],
        Database::todos('SELECT n.id FROM negocio n WHERE EXISTS (SELECT 1 FROM dre_valor v WHERE v.negocio_id = n.id)')
    ))) < 1);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

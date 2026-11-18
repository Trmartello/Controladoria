<?php

/**
 * Provas do APOIO AO DESAFIO (pedido do cliente, 2026-11-18): tributos sobre
 * vendas (PIS/COFINS destacados para a conta macro da reforma), margem do
 * negócio antes dos rateios, e as oportunidades e riscos de 2027 (itens não
 * recorrentes de 2026 e meses fora do padrão).
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/oportunidades.php
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
require_once __DIR__ . '/../app/Services/CargaApoio.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaApoio;
use App\Services\Oportunidades;

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
function invocar(object $o, string $m, ...$args)
{
    $r = new ReflectionMethod($o, $m);
    $r->setAccessible(true);
    return $r->invoke($o, ...$args);
}
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[$n['cod_negocio']] = (int)$n['id'];
}
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$ctrl = new App\Controllers\ProjecaoController();

// ── 1. Tributos ─────────────────────────────────────────────────────────────
$t = Oportunidades::tributos(array_values($ids), $ano);
prova('tributos de 10 negócios (a Usina não vende)', count($t) === 10, (string)count($t));
// Âncora: as vendas a sócio + a não sócio são a receita bruta realizada da DRE.
$divergem = [];
foreach ($t as $id => $x) {
    $rob = (float)Database::um("SELECT COALESCE(SUM(v.valor),0) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
        WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = 'REAL' AND v.mes <= ? AND c.codigo = '1'", [$id, $ano, $x['mes_ate']])['s'];
    if (abs($x['venda_socio'] + $x['venda_nao_socio'] - $rob) > 50) {
        $divergem[] = array_search($id, $ids, true) . ': ' . round($x['venda_socio'] + $x['venda_nao_socio']) . ' ≠ ' . round($rob);
    }
}
prova('vendas a sócio + a não sócio = receita bruta realizada da DRE, em todos os negócios', !$divergem, implode(' | ', $divergem));
// Âncora: PIS + COFINS + ICMS ≈ linha 2.3 (Imposto s/ Vendas) da DRE.
$divergem = [];
foreach ($t as $id => $x) {
    $l23 = -(float)Database::um("SELECT COALESCE(SUM(v.valor),0) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
        WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = 'REAL' AND v.mes <= ? AND c.codigo = '2.3'", [$id, $ano, $x['mes_ate']])['s'];
    $soma = $x['pis'] + $x['cofins'] + $x['icms'];
    if (abs($soma - $l23) > max(25000, 0.01 * $l23)) {
        $divergem[] = array_search($id, $ids, true) . ': ' . round($soma) . ' vs ' . round($l23);
    }
}
prova('PIS + COFINS + ICMS fecham com a linha 2.3 da DRE (até R$ 25 mil ou 1%)', !$divergem, implode(' | ', $divergem));
$s = $t[$ids['9']];
prova('Supermercados: PIS + COFINS = 2,93% do faturamento e 3,57% da venda a não sócio',
    abs($s['taxa_pis_cofins'] * 100 - 2.93) < 0.01 && abs($s['taxa_pis_cofins_nao_socio'] * 100 - 3.57) < 0.01,
    round($s['taxa_pis_cofins'] * 100, 3) . ' / ' . round($s['taxa_pis_cofins_nao_socio'] * 100, 3));

// O desafio do negócio traz o PIS + COFINS estimado = receita bruta × taxa.
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$m = $montar->invoke($ctrl, $ids['9'], $ano, $corte, 0);
$des = $m['desafio']['desafio'];
prova('desafio de Supermercados: PIS + COFINS estimado = R$ 282,8 mi × 2,93% (≈ R$ 8,3 mi)',
    abs($des['pis_cofins']['total'] - $des['receita_bruta'] * $s['taxa_pis_cofins']) < 1 && abs($des['pis_cofins']['total'] - 8295400) < 1000,
    (string)round($des['pis_cofins']['total']));
$pro = $m['desafio']['proposta'];
$l = array_column($m['linhas'], null, 'codigo');
prova('estrutura nova: margem antes dos rateios = linha 13 ÷ ROL, e margem do negócio = linha 15 ÷ ROL',
    abs($pro['margem_antes_rateios'] - $l['13']['proposta'] / $l['3']['proposta'] * 100) < 1e-9
    && abs($pro['margem_negocio'] - $l['15']['proposta'] / $l['3']['proposta'] * 100) < 1e-9,
    round($pro['margem_antes_rateios'], 3) . '% / ' . round($pro['margem_negocio'], 3) . '%');

// ── 2. Itens não recorrentes ────────────────────────────────────────────────
$it = Oportunidades::itens(array_values($ids), $ano);
$agro = $it[$ids['8']];
prova('Agropecuária: a PDD de R$ 7,34 mi é o maior item, e é oportunidade (+)',
    $agro['itens'][0]['conta'] === '3.11.03.01.01' && abs($agro['itens'][0]['efeito'] - 7336588) < 1);
prova('oportunidade e risco somam os efeitos com o sinal certo (Agro: +8.295.910 / −675.108)',
    abs($agro['oportunidade'] - 8295910) < 1 && abs($agro['risco'] + 675108) < 1,
    round($agro['oportunidade']) . ' / ' . round($agro['risco']));
$pec = $it[$ids['2']];
$rev = array_values(array_filter($pec['itens'], static fn ($i) => $i['conta'] === '3.05.03.01'))[0] ?? null;
prova('Pecuária: a reversão de provisão de custo (−R$ 8,5 mi) é RISCO — 2026 está melhor que a operação',
    $rev && $rev['efeito'] < -8000000 && $rev['grupo'] === 'PROVISAO');
prova('toda conta da carga está no catálogo (rótulo, grupo e linha da DRE)',
    !array_filter(array_merge(...array_column($it, 'itens')), static fn ($i) => $i['grupo'] === 'OUTRO'));

// ── 3. Meses fora do padrão, com números de mão ─────────────────────────────
// Uma linha com meses de −100 mil e um mês de −400 mil: mediana −100 mil, limite
// R$ 50 mil (metade dela), o mês de −400 mil fica 300 mil ACIMA do padrão.
$pdo = Database::conn();
$pdo->exec("INSERT INTO negocio (cod_negocio, nome, ativo) VALUES ('97', 'PROVA PICOS', 0)");
$nid = (int)$pdo->lastInsertId();
$c81 = (int)Database::um("SELECT id FROM dre_conta WHERE codigo = '8.1'")['id'];
$ins = $pdo->prepare("INSERT INTO dre_valor (negocio_id, conta_id, ano, mes, tipo, valor) VALUES (?, ?, 2099, ?, 'REAL', ?)");
foreach ([1 => -100000, 2 => -100000, 3 => -100000, 4 => -400000, 5 => -100000, 6 => -100000, 7 => -20000] as $mes => $v) {
    $ins->execute([$nid, $c81, $mes, $v]);
}
$p = Oportunidades::picos($nid, 2099, 7);
prova('o mês de −400 mil fica R$ 300 mil acima do padrão (mediana −100 mil)',
    count($p) === 1 && abs($p[0]['oportunidade'] - 300000) < 1 && abs($p[0]['mediana'] + 100000) < 1
    && $p[0]['grupo'] === 'Despesas operacionais', json_encode($p[0] ?? null));
prova('e o mês de −20 mil (abaixo do padrão em 80 mil) vira risco (pode faltar no plano)',
    abs(($p[0]['risco'] ?? 0) + 80000) < 1 && count($p[0]['meses'] ?? []) === 2);
prova('meses depois do corte não entram', count(Oportunidades::picos($nid, 2099, 3)) === 0);

// O caso do cliente: receita de JUROS concentrada num mês (11.1 Receitas
// Financeiras: 50 mil por mês e 1,2 mi em maio). Se não se repetir em 2027 é
// RISCO de R$ 1,15 mi — e a despesa financeira pontual, oportunidade.
$c111 = (int)Database::um("SELECT id FROM dre_conta WHERE codigo = '11.1'")['id'];
$c112 = (int)Database::um("SELECT id FROM dre_conta WHERE codigo = '11.2'")['id'];
foreach ([1 => 50000, 2 => 50000, 3 => 50000, 4 => 50000, 5 => 1200000, 6 => 50000, 7 => 50000] as $mes => $v) {
    $ins->execute([$nid, $c111, $mes, $v]);
    $ins->execute([$nid, $c112, $mes, $mes === 3 ? -900000 : -20000]);
}
$p = array_column(Oportunidades::picos($nid, 2099, 7), null, 'codigo');
prova('receita de juros concentrada num mês (maio, 1,2 mi) é RISCO de R$ 1,15 mi se não se repetir',
    isset($p['11.1']) && abs($p['11.1']['risco'] + 1150000) < 1 && $p['11.1']['oportunidade'] == 0
    && $p['11.1']['meses'][0]['mes'] === 5 && $p['11.1']['grupo'] === 'Resultado financeiro', json_encode($p['11.1'] ?? null));
prova('e a despesa financeira pontual (março, −900 mil) é OPORTUNIDADE de R$ 880 mil',
    isset($p['11.2']) && abs($p['11.2']['oportunidade'] - 880000) < 1);
$pdo->exec("DELETE FROM negocio WHERE id = {$nid}");

// ── 3b. A FILIAL, mês a mês (carga eventos_filiais_mensal) ──────────────────────
$fil = Database::um("SELECT f.id FROM filial f JOIN negocio n ON n.id = f.negocio_id WHERE n.cod_negocio = '8' AND f.cod_filial = '1'");
$pf = array_column(Oportunidades::picos(0, 2026, 10, (int)$fil['id']), null, 'codigo');
prova('Agropecuárias, filial 1: a despesa financeira de −R$ 3,08 mi em março sai como oportunidade',
    isset($pf['11.2']) && in_array(3, array_column($pf['11.2']['meses'], 'mes'), true) && $pf['11.2']['oportunidade'] > 2500000,
    json_encode($pf['11.2'] ?? null));
prova('e a receita de juros acima de R$ 1 mi em mar/mai/jun sai como risco',
    isset($pf['11.1']) && $pf['11.1']['risco'] < -1000000 && count(array_intersect([3, 5, 6], array_column($pf['11.1']['meses'], 'mes'))) === 3,
    json_encode(array_column($pf['11.1']['meses'] ?? [], 'mes')));
$nMes = (int)Database::um('SELECT COUNT(DISTINCT filial_id, conta_id) n FROM dre_valor_filial_mes WHERE ano = 2026')['n'];
prova('a carga mensal das filiais está no banco (183 combinações filial × linha)', $nMes === 183, (string)$nMes);
$soma = Database::um("SELECT SUM(m.valor) s, vf.realizado r FROM dre_valor_filial_mes m
    JOIN dre_valor_filial vf ON vf.filial_id = m.filial_id AND vf.conta_id = m.conta_id AND vf.ano = m.ano
    JOIN dre_conta c ON c.id = m.conta_id
   WHERE m.filial_id = ? AND c.codigo = '11.2' AND m.ano = 2026 AND m.mes <= vf.corte GROUP BY vf.realizado", [(int)$fil['id']]);
prova('e o mensal fecha com a DRE acumulada da filial (jan até o corte dela)', $soma && abs((float)$soma['s'] - (float)$soma['r']) < 12,
    json_encode($soma));

// ── 4. As cargas ────────────────────────────────────────────────────────────
$recusa = function (callable $f): string {
    try {
        $f();
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$tb = require __DIR__ . '/../database/tributos_2026_10.php';
$tb['ano'] = 2099;
$x = $tb;
$x['valores']['9'][2] = 999999999;
prova('tributo maior que as vendas é recusado', str_contains($recusa(fn () => CargaApoio::tributos($pdo, $x)), 'passam das vendas'));
$nr = require __DIR__ . '/../database/nao_recorrentes_2026_10.php';
$nr['ano'] = 2099;
$x = $nr;
$x['valores']['8']['9.9.9'] = 1;
prova('conta fora do catálogo é recusada', str_contains($recusa(fn () => CargaApoio::naoRecorrentes($pdo, $x)), 'fora do catálogo'));
prova('nada das cargas recusadas foi gravado',
    (int)Database::um('SELECT COUNT(*) n FROM negocio_tributo WHERE ano = 2099')['n'] === 0
    && (int)Database::um('SELECT COUNT(*) n FROM negocio_nao_recorrente WHERE ano = 2099')['n'] === 0);
CargaApoio::naoRecorrentes($pdo, $nr);
unset($nr['valores']['8']['3.11.03.01.01']);
CargaApoio::naoRecorrentes($pdo, $nr);
prova('recarregar sobrescreve o ano (a conta que saiu da carga sai do banco)',
    !Database::um("SELECT 1 FROM negocio_nao_recorrente WHERE ano = 2099 AND negocio_id = ? AND conta = '3.11.03.01.01'", [$ids['8']]));
$pdo->exec('DELETE FROM negocio_nao_recorrente WHERE ano = 2099');

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

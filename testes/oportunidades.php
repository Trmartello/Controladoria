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
require_once __DIR__ . '/../app/Services/Metas.php';
require_once __DIR__ . '/../app/Services/DesafioCota.php';
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

// ── 3c. O QUADRO ÚNICO (itens + eventos, com os meses e os totais) ─────────
$leite = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'];
$q = Oportunidades::quadro($leite, 2026, 10);
$ql = [];
foreach ($q['linhas'] as $l) {
    $ql[$l['tipo'] . ':' . $l['codigo']] = $l;
}
// Desde 2026-11-23 o quadro olha os 12 meses de nov/2025 a out/2026: a PDD do
// Leite é jan–out/2026 (7.182.466) + nov/2025 (−40) = 7.182.426.
prova('Leite: a PDD é ITEM com os meses da janela (out/26: −5.597.104; nov/25: +40) e efeito +7.182.426',
    isset($ql['ITEM:3.11.03.01.01']) && abs($ql['ITEM:3.11.03.01.01']['oportunidade'] - 7182426) < 1
    && in_array(['ano' => 2026, 'mes' => 10, 'valor' => -5597104.0], $ql['ITEM:3.11.03.01.01']['meses'], false)
    && in_array(['ano' => 2025, 'mes' => 11, 'valor' => 40.0], $ql['ITEM:3.11.03.01.01']['meses'], false),
    json_encode($ql['ITEM:3.11.03.01.01']['meses'] ?? null));
prova('e a linha 9.2 só mostra o que SOBRA em outubro sem a PDD (−1.578.000), não os −7,18 mi de novo',
    isset($ql['EVENTO:9.2']) && count($ql['EVENTO:9.2']['meses']) === 1 && abs($ql['EVENTO:9.2']['meses'][0]['valor'] + 1578000) < 1,
    json_encode($ql['EVENTO:9.2'] ?? null));
// A mediana passa a ser dos 12 meses (R$ 1.118): o risco é 7.231.275 − 1.118.
prova('a receita de juros de outubro (R$ 7,23 mi) é risco, contra a mediana dos 12 meses',
    isset($ql['EVENTO:11.1']) && abs($ql['EVENTO:11.1']['risco'] + 7230157) < 1, (string)($ql['EVENTO:11.1']['risco'] ?? ''));
prova('totais: oportunidades = soma das linhas, riscos idem, saldo = os dois (Leite +8.999.983 / −7.731.667, 12 meses)',
    abs($q['oportunidade'] - array_sum(array_column($q['linhas'], 'oportunidade'))) < 1e-6
    && abs($q['risco'] - array_sum(array_column($q['linhas'], 'risco'))) < 1e-6
    && abs($q['oportunidade'] - 8999983) < 2 && abs($q['risco'] + 7731667) < 2 && abs($q['saldo'] - $q['oportunidade'] - $q['risco']) < 1e-6,
    round($q['oportunidade']) . ' / ' . round($q['risco']));
prova('a janela do quadro é nov/2025 a out/2026', $q['periodo'] === ['de' => [2025, 11], 'ate' => [2026, 10]], json_encode($q['periodo']));
prova('o item traz o total dos 12 meses e o dos 12 meses antes (sinal da DRE): PDD do Leite −7.182.426 contra −110 (dez/24: 70 + abr/25: 40)',
    abs($ql['ITEM:3.11.03.01.01']['total'] + 7182426) < 1 && abs($ql['ITEM:3.11.03.01.01']['anterior'] + 110) < 1,
    json_encode([$ql['ITEM:3.11.03.01.01']['total'], $ql['ITEM:3.11.03.01.01']['anterior']]));
prova('sem avaliação, todos os itens estão a avaliar', $q['avaliacao']['avaliados'] === 0
    && $q['avaliacao']['pendentes'] === count(array_filter($q['linhas'], static fn ($l) => $l['tipo'] === 'ITEM')));
// Avaliar: considerar a PDD (vai se repetir) e desconsiderar outra conta.
$pdo = Database::conn();
$outra = array_values(array_filter($q['linhas'], static fn ($l) => $l['tipo'] === 'ITEM' && $l['codigo'] !== '3.11.03.01.01'))[0] ?? null;
try {
    $pdo->prepare("INSERT INTO negocio_item_avaliacao (negocio_id, ano, conta, decisao, avaliado_em) VALUES (?, 2027, '3.11.03.01.01', 'CONSIDERAR', NOW())")->execute([$leite]);
    if ($outra) {
        $pdo->prepare("INSERT INTO negocio_item_avaliacao (negocio_id, ano, conta, decisao, avaliado_em) VALUES (?, 2027, ?, 'DESCONSIDERAR', NOW())")->execute([$leite, $outra['codigo']]);
    }
    $q2 = Oportunidades::quadro($leite, 2026, 10);
    prova('considerar a PDD a põe no total "a planejar" (−7.182.426) e a marca como avaliada',
        abs($q2['avaliacao']['considerar'] + 7182426) < 1 && $q2['avaliacao']['avaliados'] === ($outra ? 2 : 1), json_encode($q2['avaliacao']));
    prova('desconsiderar outra põe o total dela em "oportunidade"', !$outra || abs($q2['avaliacao']['desconsiderar'] - $outra['total']) < 1);
    prova('a avaliação não muda os totais do quadro', abs($q2['oportunidade'] - $q['oportunidade']) < 1e-6 && abs($q2['risco'] - $q['risco']) < 1e-6);
} finally {
    $pdo->prepare('DELETE FROM negocio_item_avaliacao WHERE negocio_id = ? AND ano = 2027')->execute([$leite]);
}

$mensal = Database::um("SELECT COUNT(DISTINCT negocio_id, conta) n FROM negocio_nao_recorrente_mes WHERE ano = 2026")['n'];
$totais = Database::um("SELECT COUNT(*) n FROM negocio_nao_recorrente WHERE ano = 2026")['n'];
prova('a carga mensal dos itens tem as mesmas 62 combinações da de totais', (int)$mensal === 62 && (int)$totais === 62, "{$mensal} / {$totais}");
$dif = Database::um("SELECT COUNT(*) n FROM negocio_nao_recorrente t
    LEFT JOIN (SELECT negocio_id, ano, conta, SUM(valor) s FROM negocio_nao_recorrente_mes GROUP BY negocio_id, ano, conta) m
      ON m.negocio_id = t.negocio_id AND m.ano = t.ano AND m.conta = t.conta
   WHERE t.ano = 2026 AND (m.s IS NULL OR ABS(m.s - t.valor) > 5)")['n'];
prova('e o mês a mês de cada item soma o total da carga (R$ 5: dez meses arredondados ao real)', (int)$dif === 0, (string)$dif);
$qf = Oportunidades::quadro(0, 2026, 10, (int)$fil['id']);
prova('na filial o quadro tem só eventos (os itens por conta são do negócio), com total',
    $qf['linhas'] && !array_filter($qf['linhas'], static fn ($l) => $l['tipo'] === 'ITEM') && $qf['risco'] < 0 && $qf['oportunidade'] > 0);

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

// ── 5. Estoque: perdas, acerto e parados (pedido do cliente, 2026-11-18) ──────
echo "\n5. Estoque como oportunidade\n";
$a = Oportunidades::acimaDaMedia([1 => 100, 2 => 300, 3 => 200], 4);
prova('a média conta o mês sem lançamento como zero (600 / 4 = 150) e devolve no sinal da DRE',
    abs($a['media'] + 150) < 0.01 && abs($a['total'] + 600) < 0.01 && count($a['meses']) === 4, json_encode($a));
prova('a oportunidade é o que passou da média: (300−150) + (200−150) = 200, marcada nos meses',
    abs($a['oportunidade'] - 200) < 0.01 && $a['meses'][1]['excesso'] == 150 && $a['meses'][0]['excesso'] == 0);
$c = Oportunidades::acimaDaMedia([1 => -500, 2 => 100], 2);
prova('série que no ano é crédito (sobra de inventário) não tem oportunidade', $c['oportunidade'] == 0.0, json_encode($c));
prova('as duas contas caem no Custo das Vendas (4.1), como na estrutura da DRE Gerencial',
    array_unique(array_column(Oportunidades::ESTOQUE_CONTAS, 2)) === ['4.1']);

$ea = Oportunidades::estoque($ids['8'], 2026, 10);
$es = Oportunidades::estoque($ids['9'], 2026, 10);
$linha = static fn ($e, $t) => array_values(array_filter($e['linhas'], static fn ($l) => $l['tipo'] === $t))[0] ?? null;
prova('a régua é a MÉDIA ENTRE AS FILIAIS: Agropecuárias, perdas R$ 622.404 no ano, média de 9.725 em 64 filiais, 17 acima, oportunidade 313.873',
    $linha($ea, 'PERDA')['regua'] === 'FILIAIS' && round(-$linha($ea, 'PERDA')['total']) == 622404
    && round(-$linha($ea, 'PERDA')['media']) == 9725 && $linha($ea, 'PERDA')['filiais'] === 64 && $linha($ea, 'PERDA')['acima'] === 17
    && round($linha($ea, 'PERDA')['oportunidade']) == 313873, json_encode([$linha($ea, 'PERDA')['media'], $linha($ea, 'PERDA')['oportunidade']]));
$me = Oportunidades::mediaEntreFiliais($ids['8'], 2026, 10)['3.07.01.01.06'];
prova('a oportunidade do negócio é a soma do que cada filial passou da média (excesso = total − média)',
    abs(array_sum($me['excesso']) - $me['oportunidade']) < 0.01
    && !array_filter($me['excesso'], static fn ($x) => $x < 0)
    && abs($me['media'] - array_sum($me['totais']) / count($me['totais'])) < 0.01);
prova('acerto das Agropecuárias: média entre filiais é crédito (−938 de perda), régua zero — 40 de 72 filiais com falta, 435.621',
    round($linha($ea, 'ACERTO')['oportunidade']) == 435621 && $linha($ea, 'ACERTO')['acima'] === 40 && $linha($ea, 'ACERTO')['filiais'] === 72);
prova('os meses ficam como informação, sem destaque (a régua é o ano da filial)',
    !array_filter($linha($ea, 'PERDA')['meses'], static fn ($m) => $m['excesso'] > 0) && count($linha($ea, 'PERDA')['meses']) === 10);
prova('Agropecuárias: parado há mais de 2 anos R$ 4.046.879,81 em 21.829 itens',
    abs($ea['parado']['valor'] - 4046879.81) < 0.01 && $ea['parado']['itens'] === 21829 && $ea['parado']['dias'] === 730);
prova('Agropecuárias: montante = resultado (749.493) + caixa (4.046.880) = 4.796.373',
    round($ea['resultado']) == 749493 && round($ea['total']) == 4796373 && abs($ea['total'] - $ea['resultado'] - $ea['caixa']) < 0.01);
prova('Supermercados: parado há mais de 1 ano R$ 309.424,36; montante 833.459',
    abs($es['parado']['valor'] - 309424.36) < 0.01 && $es['parado']['dias'] === 365 && round($es['total']) == 833459);
prova('o ranking das filiais soma o resultado do negócio', abs(array_sum(array_column($ea['filiais'], 'resultado')) - $ea['resultado']) < 0.01);
prova('o parado das filiais + o que não casou com o cadastro (postos, R$ 159) = o do negócio',
    abs(array_sum(array_column($ea['filiais'], 'parado')) + 159.07 - $ea['parado']['valor']) < 0.01);
prova('ranking das filiais em ordem decrescente de total, Canoinhas primeiro',
    $ea['filiais'][0]['nome'] === 'Canoinhas' && $ea['filiais'][0]['total'] >= $ea['filiais'][1]['total']);
$filC = Database::um("SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = '33'", [$ids['9']]);
$ef = Oportunidades::estoque($ids['9'], 2026, 10, (int)$filC['id']);
prova('na filial (Super Concórdia): perdas de 221.040 contra a média de 90.777 das filiais = 130.263; parado dela; sem ranking',
    round($ef['resultado']) == 130263 && abs($ef['caixa'] - 16716.71) < 0.01 && !isset($ef['filiais']));
$ub = Oportunidades::estoque($ids['13'], 2026, 10);
prova('negócio com uma filial só (UBS): a média é ela mesma, sem oportunidade', $ub && $ub['resultado'] == 0.0);
$soma = Database::um("SELECT SUM(valor) s FROM estoque_perda_mes WHERE negocio_id = ? AND filial_id > 0 AND ano = 2026", [$ids['9']])['s'];
$neg = Database::um("SELECT SUM(valor) s FROM estoque_perda_mes WHERE negocio_id = ? AND filial_id = 0 AND ano = 2026", [$ids['9']])['s'];
prova('as filiais somam o negócio (arredondamento ≤ R$ 30 no ano)', abs($soma - $neg) <= 30, "$soma × $neg");
prova('negócio sem nada carregado não tem o bloco', Oportunidades::estoque($ids['17'] ?? 0, 2026, 10) === null);
prova('Rações: acerto que é crédito o ano todo dá oportunidade zero',
    ($e6 = Oportunidades::estoque($ids['6'], 2026, 10)) && $e6['resultado'] == 0.0);

$es0 = require __DIR__ . '/../database/estoque_oportunidade_2026_10.php';
$x = $es0;
$x['ano'] = 2099;
$x['parados']['data_base'] = '2099-01-01';
$x['perdas']['8']['9.9.9'] = array_fill(0, 10, 1);
prova('estoque: conta fora do catálogo é recusada', str_contains($recusa(fn () => CargaApoio::estoque($pdo, $x)), 'fora do catálogo'));
$x = $es0;
$x['ano'] = 2099;
$x['parados']['data_base'] = '2099-01-01';
$x['perdas_filiais']['8'][] = ['99999', '3.07.01.01.06', array_fill(0, 10, 1)];
prova('estoque: filial fora do cadastro é recusada', str_contains($recusa(fn () => CargaApoio::estoque($pdo, $x)), 'não existe'));
$x = $es0;
$x['ano'] = 2099;
$x['perdas']['9']['3.07.01.01.06'] = [1, 2, 3];
prova('estoque: série sem os meses do corte é recusada', str_contains($recusa(fn () => CargaApoio::estoque($pdo, $x)), 'sem os 10 meses'));
prova('nada das cargas de estoque recusadas foi gravado',
    (int)Database::um('SELECT COUNT(*) n FROM estoque_perda_mes WHERE ano = 2099')['n'] === 0
    && (int)Database::um("SELECT COUNT(*) n FROM estoque_parado WHERE data_base = '2099-01-01'")['n'] === 0);

// ── 6. Lançamentos do quadro e juros recebidos (pedido do cliente, 2026-11-18) ──
echo "\n6. Lançamentos e juros recebidos\n";
prova('o cliente sai do complemento: "<documento> <código>-<nome>"',
    Oportunidades::cliente('000902500 0350688-ALTAIR LORENZETT') === ['0350688', 'ALTAIR LORENZETT']
    && Oportunidades::cliente('REF RENEGOCIACAO 10/2026') === ['', 'REF RENEGOCIACAO 10/2026']);
$dup = Database::um("SELECT COUNT(*) n FROM (SELECT negocio_id, data, cod_filial, reduzida, historico, complemento, COUNT(*) c
    FROM dre_lancamento GROUP BY negocio_id, data, cod_filial, reduzida, historico, complemento HAVING c > 1) x")['n'];
prova('cada documento entra uma vez (as fontes de pisos diferentes não se repetem)', (int)$dup === 0, (string)$dup);
prova('todo lançamento tem a linha da DRE (mapa da estrutura ou catálogo de itens)',
    (int)Database::um('SELECT COUNT(*) n FROM dre_lancamento WHERE linha_dre IS NULL')['n'] === 0);
prova('juros ativos sócio e não sócio caem na linha 11.1 (Receitas Financeiras)',
    array_column(Database::todos("SELECT DISTINCT linha_dre FROM dre_lancamento WHERE reduzida IN ('3153','3155')"), 'linha_dre') === ['11.1']);
$ja = Oportunidades::juros($ids['8'], 2026);
prova('Agropecuárias: juros sócio 8.936.188,37 e não sócio 1.930.724,11 (todos os lançamentos do ano)',
    abs($ja['socio'] - 8936188.37) < 0.01 && abs($ja['nao_socio'] - 1930724.11) < 0.01, json_encode([$ja['socio'], $ja['nao_socio']]));
prova('Agropecuárias: 45 documentos acima de R$ 30 mil somam o risco de 3.526.819,47, o maior primeiro',
    count($ja['acima']) === 45 && abs($ja['risco'] - 3526819.47) < 0.01 && $ja['acima'][0]['cliente'] === 'RAFAEL DALMINA'
    && $ja['acima'][0]['valor'] >= $ja['acima'][1]['valor'], json_encode([count($ja['acima']), $ja['risco']]));
prova('Agropecuárias: o cliente que mais pagou juros é Rafael Dalmina (877.493,90 em 2 documentos, filial 128)',
    $ja['clientes'][0]['cliente_cod'] === '0328398' && abs($ja['clientes'][0]['valor'] - 877493.90) < 0.01
    && $ja['clientes'][0]['lancamentos'] === 2 && $ja['clientes'][0]['filiais'][0]['cod_filial'] === '128');
prova('todo cliente listado passa de R$ 30 mil', !array_filter($ja['clientes'], static fn ($c) => $c['valor'] < 30000));
$jl = Oportunidades::juros($ids['4'], 2026);
prova('Leite: R$ 7,23 mi de risco em 3 documentos — a renegociação de outubro (Altair Lorenzett, filial 16)',
    count($jl['acima']) === 3 && abs($jl['risco'] - 7229619.27) < 0.01 && $jl['clientes'][0]['cliente'] === 'ALTAIR LORENZETT');
prova('Supermercados: nenhum juro acima de R$ 30 mil', ($js = Oportunidades::juros($ids['9'], 2026)) && !$js['acima'] && $js['risco'] == 0.0);
$jf = Oportunidades::juros($ids['8'], 2026, '128');
prova('na filial 128 (Cruzaltinha), só os dela: 4 documentos, 1.094.364,53',
    count($jf['acima']) === 4 && abs($jf['risco'] - 1094364.53) < 0.01
    && !array_filter($jf['acima'], static fn ($a) => $a['cod_filial'] !== '128'));
$qa = Oportunidades::quadro($ids['8'], 2026, 10);
$pdd = array_values(array_filter($qa['linhas'], static fn ($l) => $l['codigo'] === '3.11.03.01.01'))[0];
prova('no quadro, a PDD das Agropecuárias abre os lançamentos dela (os 40 maiores, com filial e complemento)',
    $pdd['lancamentos']['quantos'] > 40 && count($pdd['lancamentos']['itens']) === 40
    && $pdd['lancamentos']['itens'][0]['cod_filial'] !== '' && abs($pdd['lancamentos']['itens'][0]['valor']) >= abs($pdd['lancamentos']['itens'][1]['valor']));
$lx = $pdd['lancamentos'];
prova('a abertura totaliza todos os lançamentos: oportunidade (despesas) + risco (receitas/reversões) = saldo = −soma',
    $lx['oportunidade'] > 0 && $lx['risco'] < 0 && abs($lx['oportunidade'] + $lx['risco'] - $lx['saldo']) < 0.01
    && abs($lx['saldo'] + $lx['soma']) < 0.01, json_encode([$lx['oportunidade'], $lx['risco'], $lx['saldo'], $lx['soma']]));
$ev = array_values(array_filter($qa['linhas'], static fn ($l) => $l['tipo'] === 'EVENTO'));
prova('no evento, só lançamentos da linha nos meses fora do padrão',
    !array_filter($ev, static function ($l) {
        $meses = array_column($l['meses'], 'mes');
        return array_filter($l['lancamentos']['itens'], static fn ($i) => !in_array($i['mes'], $meses, true));
    }));
$x = require __DIR__ . '/../database/lancamentos_2026_10.php';
$x['ano'] = 2099;
prova('lançamento com data fora do ano é recusado', str_contains($recusa(fn () => CargaApoio::lancamentos($pdo, $x)), 'data ou mês inválido'));
prova('e nada foi gravado', (int)Database::um('SELECT COUNT(*) n FROM dre_lancamento WHERE ano = 2099')['n'] === 0);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

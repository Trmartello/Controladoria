<?php

/**
 * Provas do DESAFIO de 2027 na Projeção (pedido do cliente, 2026-11-18: "os 2%
 * são margem líquida, carregue o desafio na projeção").
 *
 * Desde 2026-11-23 o desafio é CALCULADO (a proposta padrão sobre a projeção de
 * 2026, `DesafioDrivers`). Três âncoras: (1) o cabeçalho carregado e o total do
 * Corporativo com a conta dos 2%; (2) a ORIGEM — base × 1,06 sem driver, Usina só
 * resultado, Pecuária na planilha; (3) a carga de valores (o formato antigo) segue
 * estrita e sobrescreve o ano.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/desafio.php
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
require_once __DIR__ . '/../app/Services/CargaDesafio.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaDesafio;
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
function perto(float $a, float $b, float $tol = 0.01): bool
{
    return abs($a - $b) <= $tol;
}
function invocar(object $o, string $m, ...$args)
{
    $r = new ReflectionMethod($o, $m);
    $r->setAccessible(true);
    return $r->invoke($o, ...$args);
}

$carga = require __DIR__ . '/../database/desafio_2027.php';
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[$n['cod_negocio']] = (int)$n['id'];
}
$ctrl = new App\Controllers\ProjecaoController();
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];

// ── 1. O desafio CALCULADO (2026-11-23): só o cabeçalho é carga ─────────────
$d = invocar($ctrl, 'desafioDoAno', 2027);
prova('há desafio de 2027, com a meta de 2% de margem líquida', $d && $d['meta_margem'] == 2.0, json_encode($d['meta_margem'] ?? null));
prova('a carga de 2027 é a do desafio calculado (sem valores)', !empty($carga['calculado']) && empty($carga['valores']));
prova('o cabeçalho não traz valores: quem calcula é a Projeção', $d && !isset($d['por_negocio']));

// O Corporativo soma os desafios dos 11 negócios (números do banco local, out/26,
// base = projeção de 2026, jan–out realizado + nov–dez planejado; conferidos por `testes/desafio_drivers.php` negócio a negócio).
// Desde 2026-11-23 a 8.5 sai sem a conta reduzida 3227 (acerto do PPR): resultado de 45.565.066 para 45.755.843.
// E a 8.10 é a DEPRECIAÇÃO carregada (R$ 18,33 mi contra R$ 4,99 mi projetados): 45.755.843 → 32.415.590.
// Regravada sem o grupo contábil 1272 dos bens (R$ 16,16 mi, 2026-11-23): 32.415.590 → 34.584.560.
// Despesas operacionais acompanhando a receita no desafio (2026-11-23): 34.584.560 → 30.869.410;
// só para cima (a Pecuária, com receita em queda, não encolhe as despesas): → 29.606.988.
$negs = Database::todos('SELECT id FROM negocio WHERE ativo = 1');
$c = invocar($ctrl, 'montarCorporativo', $negs, $ano, $corte)['desafio'];
$t = $c['total']['desafio'];
prova('os 11 negócios entram no desafio do Corporativo', count($c['negocios']) === 11, (string)count($c['negocios']));
prova('total do desafio: ROB R$ 3.680.001.039, ROL R$ 3.548.348.607, resultado R$ 29.606.988',
    abs($t['receita_bruta'] - 3680001039) < 2 && abs($t['receita_liquida'] - 3548348607) < 2 && abs($t['resultado'] - 29606988) < 2,
    round($t['receita_bruta']) . ' / ' . round($t['receita_liquida']) . ' / ' . round($t['resultado']));
prova('margem líquida do desafio: 0,83%', abs($t['margem'] - 0.8344) < 0.0005, (string)round($t['margem'], 4));
prova('os 2% sobre a ROL do desafio são R$ 70,97 mi, e faltam R$ 41,36 mi',
    abs($t['resultado_meta'] - 70966972) < 2 && abs($t['falta'] - 41359984) < 2,
    round($t['resultado_meta']) . ' / ' . round($t['falta']));

// ── 2. De onde sai: base de 12 meses × premissas, ou o driver ───────────────
$cresceu = [];
foreach (['1', '6', '7', '8', '9', '11', '13'] as $cod) {
    $m = invocar($ctrl, 'montar', $ids[$cod], $ano, $corte);
    $base = array_column($m['linhas'], 'projecao', 'codigo')['1'];
    $rb = $m['desafio']['desafio']['receita_bruta'];
    if (abs($rb - $base * 1.06) > 2) {
        $cresceu[] = "{$cod}: " . round($rb) . ' vs ' . round($base * 1.06);
    }
}
prova('sem driver (e sem meta de volume no banco local): receita bruta = projeção de 2026 × 1,06 (IPCA 4,5 + PIB 1,5)',
    !$cresceu, implode(' | ', $cresceu));
$u = invocar($ctrl, 'montar', $ids['17'], $ano, $corte);
$ud = $u['desafio']['desafio'];
// A Usina tem o resultado da projeção (R$ 434.800) com a depreciação carregada no lugar
// da projetada: 497.438 → 357.688, R$ 139.750 a mais de resultado.
prova('Usina sem receita: sem margem, e com o resultado da projeção com a depreciação carregada (R$ 574.550)',
    $ud['margem'] === null && abs($ud['resultado'] - 574550) < 2, (string)round($ud['resultado']));
$pec = invocar($ctrl, 'montar', $ids['2'], $ano, $corte)['desafio']['desafio'];
prova('Pecuária no realista da planilha: R$ 732.218.694', abs($pec['receita_bruta'] - 732218693.78) < 1, (string)round($pec['receita_bruta']));

// ── 2b. No desafio as despesas operacionais acompanham a receita (2026-11-23) ─
// "O Desafio para o negócio deve levar em consideração um crescimento proporcional
// das despesas": cada subconta da linha 7 cresce na proporção da Receita de Vendas,
// menos o Pessoal (diretriz) e a Depreciação (carga). Rateios ficam na projeção. Na
// PROPOSTA, despesa não lançada continua na projeção (diretriz de 2027).
$l = static fn ($c, $p) => ['codigo' => $c, 'nome' => $c, 'nivel' => 2, 'percentual' => 0, 'realizado' => $p, 'projetado' => 0.0, 'projecao' => $p];
$sint = [$l('1.1', 1000.0), $l('4.1', -600.0), $l('8.1', -100.0), $l('8.5', -200.0), $l('8.10', -30.0),
    $l('8.11', -50.0), $l('8.17', 20.0), $l('14.3', -40.0), $l('16.1', -10.0)];
$cr = ['1.1' => 10.0, '8.5' => 5.0];
$fx = Projecao::fixosDaDepreciacao(['negocio' => array_fill(1, 12, -3.0), 'filiais' => []]);
$des = array_column(Projecao::aplicar($sint, [], $cr, null, $fx, Projecao::acompanhamReceitaNoDesafio()), 'proposta', 'codigo');
// De mão: receita +10% → 1.100; custo −660; 8.1 −110; 8.11 −55; 8.17 (recuperação) +22;
// pessoal −200 × 1,05 = −210; depreciação = a carga, −36; rateios na projeção.
prova('desafio: despesas operacionais +10% com a receita (8.1 −110, 8.11 −55, 8.17 +22), custo −660',
    perto($des['8.1'], -110.0) && perto($des['8.11'], -55.0) && perto($des['8.17'], 22.0) && perto($des['4.1'], -660.0),
    json_encode([$des['8.1'], $des['8.11'], $des['8.17'], $des['4.1']]));
prova('desafio: pessoal pela premissa (−210), depreciação pela carga (−36), rateios na projeção (−40, −10)',
    perto($des['8.5'], -210.0) && perto($des['8.10'], -36.0) && perto($des['14.3'], -40.0) && perto($des['16.1'], -10.0),
    json_encode([$des['8.5'], $des['8.10'], $des['14.3'], $des['16.1']]));
$cai = array_column(Projecao::aplicar($sint, [], ['1.1' => -10.0, '8.5' => 5.0], null, $fx, Projecao::acompanhamReceitaNoDesafio()), 'proposta', 'codigo');
// Receita −10% (a Pecuária): o custo cai junto (−540), as despesas NÃO encolhem (8.1 −100,
// 8.11 −50, 8.17 +20) — pedido do cliente, 2026-11-23.
prova('desafio com receita em queda: custo acompanha (−540), despesas ficam na projeção (8.1 −100, 8.11 −50, 8.17 +20)',
    perto($cai['4.1'], -540.0) && perto($cai['8.1'], -100.0) && perto($cai['8.11'], -50.0) && perto($cai['8.17'], 20.0),
    json_encode([$cai['4.1'], $cai['8.1'], $cai['8.11'], $cai['8.17']]));
$pec = invocar($ctrl, 'montar', $ids['2'], $ano, $corte)['desafio']['escada']['desafio'];
prova('Pecuária: despesas do desafio R$ 21.325.034, as mesmas de antes da regra (não encolhem com o preço do suíno)',
    abs($pec['despesas'] + 21325034) < 2, (string)round($pec['despesas']));
$prop = array_column(Projecao::aplicar($sint, [], $cr, null, $fx), 'proposta', 'codigo');
prova('proposta (sem lançamento): as mesmas despesas ficam na projeção (8.1 −100, 8.11 −50), o custo acompanha (−660)',
    perto($prop['8.1'], -100.0) && perto($prop['8.11'], -50.0) && perto($prop['4.1'], -660.0),
    json_encode([$prop['8.1'], $prop['8.11'], $prop['4.1']]));
// Agropecuárias no banco local: o número de antes e o de depois da regra, para a
// mudança no Corporativo (acima) ter endereço.
$ag = invocar($ctrl, 'montar', $ids['8'], $ano, $corte);
$esc = $ag['desafio']['escada']['desafio'];
prova('Agropecuárias: despesas do desafio R$ 91.823.889 (eram R$ 89.585.034 sem acompanhar a receita)',
    abs($esc['despesas'] + 91823889) < 2, (string)round($esc['despesas']));

// ── 3. A carga: estrita, e sobrescreve o ano inteiro ────────────────────────
$pdo = Database::conn();
$recusa = function (array $dados) use ($pdo): string {
    try {
        CargaDesafio::aplicar($pdo, $dados);
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
// Uma carga de valores (o formato do desafio de 18/11), para provar a carga estrita.
$teste = ['chave' => 'teste', 'ano' => 2099, 'meta_margem' => 2.0, 'fonte' => 'teste', 'metodo' => 'teste',
    'decidido_em' => '2026-11-23', 'valores' => []];
foreach (['1', '2', '4', '6', '7', '8', '9', '11', '12', '13', '17'] as $cod) {
    $teste['valores'][$cod] = ['receita_bruta' => 1000, 'receita_liquida' => 900, 'resultado' => 10, 'nota' => 'teste'];
}
$m = $teste;
$m['valores']['1']['resultado'] = 9101824.5;
prova('valor que não é inteiro em reais é recusado', str_contains($recusa($m), 'resultado inválido'), $recusa($m));
$m = $teste;
$m['valores']['1']['receita_liquida'] = $m['valores']['1']['receita_bruta'] + 1;
prova('receita líquida maior que a bruta é recusada', str_contains($recusa($m), 'maior que a bruta'));
$m = $teste;
$m['meta_margem'] = 0;
prova('meta de margem zero é recusada', str_contains($recusa($m), 'meta de margem'));
$m = $teste;
$m['valores']['4']['nota'] = ' ';
prova('desafio sem nota é recusado (o desafio diz de onde saiu)', str_contains($recusa($m), 'sem nota'));
prova('nada da carga recusada foi gravado', (int)Database::um('SELECT COUNT(*) n FROM negocio_desafio WHERE ano = 2099')['n'] === 0);

$r = CargaDesafio::aplicar($pdo, $teste);
prova('a carga de teste grava os 11 negócios', $r['negocios'] === 11 && !$r['ignorados']);
$m = $teste;
unset($m['valores']['13']);
$m['valores']['1']['resultado'] = 1;
$m['valores']['99'] = $m['valores']['4'];
$r = CargaDesafio::aplicar($pdo, $m);
$linhas = Database::todos('SELECT negocio_id, resultado FROM negocio_desafio WHERE ano = 2099');
$porId = array_column($linhas, 'resultado', 'negocio_id');
prova('recarregar SOBRESCREVE o ano: o negócio que saiu da carga sai do banco',
    count($linhas) === 10 && !isset($porId[$ids['13']]), (string)count($linhas));
prova('e o valor novo substitui o velho', isset($porId[$ids['1']]) && (float)$porId[$ids['1']] === 1.0);
prova('negócio fora do cadastro não derruba a carga: fica anotado', $r['ignorados'] === ['99'], json_encode($r['ignorados']));
$pdo->exec('DELETE FROM negocio_desafio WHERE ano = 2099');
$pdo->exec('DELETE FROM desafio_ano WHERE ano = 2099');
prova('o desafio de 2027 não foi tocado pela carga de teste',
    (invocar($ctrl, 'desafioDoAno', 2027)['meta_margem'] ?? null) == 2.0);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

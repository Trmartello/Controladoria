<?php

/**
 * Provas do DESAFIO CALCULADO de 2027 (pedido do cliente, 2026-11-23): o desafio é a
 * proposta padrão — projeção de 2026 (jan–out realizado + nov–dez planejado) × premissas, ou o driver de
 * volume (`DesafioDrivers`) — e é de onde a proposta parte.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/desafio_drivers.php
 *
 * Números de MÃO:
 *  - Pecuária: R$ 732.218.693,78, o realista da planilha (D41).
 *  - Cota de prova: 12 meses de 8.950 (mil litros) = 107.400.000 litros.
 *    Postos = 107.400.000 × R$ 0,055 = R$ 5.907.000; dezembro em 10.000 = +1.050.000
 *    litros × 0,055 = +R$ 57.750 → R$ 5.964.750.
 *    Leite = 107.400.000 × (Receita de Vendas de nov/25–out/26 ÷ 107.904.705 litros) × (1 + premissas).
 *  - Bovinos de prova: 12 meses de 4.800 t = 57.600 t. Rações = 57.600 × R$ 100 +
 *    (Receita de Vendas da projeção − 53.575,85 t × R$ 100) × (1 + premissas).
 * Cria as metas no planejamento do Leite e das Agropecuárias e apaga no fim.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
spl_autoload_register(static function (string $classe): void {
    $arq = __DIR__ . '/../' . str_replace(['App\\', '\\'], ['app/', '/'], $classe) . '.php';
    if (is_file($arq)) {
        require_once $arq;
    }
});

use App\Core\Database;
use App\Services\CargaDesafio;
use App\Services\DesafioCota;
use App\Services\DesafioDrivers;
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
$fmt = static fn ($v) => number_format((float)$v, 0, ',', '.');

$pdo = Database::conn();
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$anoPlan = $ano + 1;
prova('há desafio de 2027 e ele é o calculado', (bool)Database::um(
    "SELECT 1 FROM desafio_ano WHERE ano = ? AND fonte LIKE '%proposta padrão%'", [$anoPlan]));
prova('os valores do desafio carregado de 18/11 foram apagados',
    !Database::um('SELECT 1 FROM negocio_desafio WHERE ano = ?', [$anoPlan]));

$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$id = static fn (string $cod) => (int)Database::um('SELECT id FROM negocio WHERE cod_negocio = ?', [$cod])['id'];
$tela = static fn (string $cod) => $montar->invoke($ctrl, $id($cod), $ano, $corte);
$linha = static fn (array $t, string $c) => array_column($t['linhas'], null, 'codigo')[$c];

// ── Pecuária: o realista da planilha ───────────────────────────────────────
$t = $tela('2');
$d = $t['desafio']['desafio'];
$l11 = $linha($t, '1.1');
prova('Pecuária: o faturamento do desafio é o realista da planilha (R$ 732.218.694)',
    abs($d['receita_bruta'] - 732218693.78) < 1, $fmt($d['receita_bruta']));
prova('Pecuária: o piso da Receita de Vendas é o do driver, não IPCA + PIB',
    abs($l11['projecao'] * (1 + $l11['piso_percentual'] / 100) - 732218693.78) < 1
    && abs($t['driver']['crescimento'] - $l11['piso_percentual']) < 1e-9,
    sprintf('%.4f%%', $l11['piso_percentual']));
prova('Pecuária: a nota do desafio diz de onde veio', $d['driver'] === 'PLANILHA' && str_contains($d['nota'], 'REALISTA'), $d['nota']);

// ── Margem bruta preservada e pessoal pela diretriz ────────────────────────
$t = $tela('8');
$e = $t['desafio']['escada']['desafio'];
$base = array_column($t['linhas'], null, 'codigo');
$mbBase = $base['5']['projecao'] / $base['3']['projecao'] * 100;
prova('Agropecuárias: margem bruta do desafio = a da projeção de 2026 (preservada)',
    abs($e['margem_bruta'] - $mbBase) < 0.01, sprintf('%.3f%% × %.3f%%', $e['margem_bruta'], $mbBase));
$dig = Database::todos('SELECT chave, percentual FROM negocio_premissa WHERE negocio_id = ? AND ano = ?', [$id('8'), $anoPlan]);
$ef = Projecao::premissasEfetivas($anoPlan, array_column($dig, 'percentual', 'chave'));
$cresc = ($ef['IPCA'] ?? 0) + ($ef['PIB'] ?? 0) + ($ef['CRESCIMENTO'] ?? 0);
prova("Agropecuárias: Receita de Vendas = base × (1 + IPCA + PIB + crescimento = {$cresc}%)",
    // O cartão mostra a receita bruta SEM o PIS/COFINS, como a DRE (2026-11-27); o motor a
    // calcula com ele (formato da carga), então a CBS do desafio volta à soma aqui.
    abs($e['receita_bruta'] + $t['desafio']['escada']['cbs']['desafio'] - $base['1']['projecao'] * (1 + $cresc / 100)) < 2,
    $fmt($e['receita_bruta']));
prova('o desafio não tem degrau estimado: a escada sai da DRE dele', $t['desafio']['escada']['estimados'] === []);
prova('e o desafio não é a base parada: premissas e pessoal pela diretriz mexem no resultado',
    abs($t['desafio']['desafio']['resultado'] - $base['18']['projecao']) > 1000,
    $fmt($base['18']['projecao']) . ' → ' . $fmt($t['desafio']['desafio']['resultado']));

// ── Proposta nasce do desafio ──────────────────────────────────────────────
$semLanc = Database::um('SELECT COUNT(*) n FROM projecao_valor v JOIN projecao p ON p.id = v.projecao_id WHERE p.negocio_id = ? AND p.ano = ?',
    [$id('9'), $anoPlan])['n'];
$t = $tela('9');
// Desde 2026-11-23 o desafio difere da proposta padrão num ponto só: nele as demais
// despesas operacionais acompanham a receita; na proposta, sem lançamento, ficam na
// projeção (diretriz). Sem lançamento, a diferença de resultado é exatamente esse
// crescimento: demais despesas da projeção × (fator da receita − 1).
$pjs = array_column($t['linhas'], 'projecao', 'codigo');
$demais = 0.0;
foreach (Projecao::acompanhamReceitaNoDesafio() as $c) {
    $demais += $c[0] === '8' ? (float)($pjs[$c] ?? 0) : 0.0;
}
$fat = $t['desafio']['desafio']['receita_bruta'] / $pjs['1'];
prova('Supermercados sem lançamento: mesma receita que o desafio, e o resultado só difere pelas demais despesas acompanhando a receita',
    (int)$semLanc > 0 || (abs($t['desafio']['proposta']['receita_bruta'] - $t['desafio']['desafio']['receita_bruta']) < 1
        && abs(($t['desafio']['desafio']['resultado'] - $t['desafio']['proposta']['resultado']) - $demais * ($fat - 1)) < 1),
    $fmt($t['desafio']['proposta']['resultado']) . ' → ' . $fmt($t['desafio']['desafio']['resultado']) . ' (' . $fmt($demais * ($fat - 1)) . ')');

// ── Pendentes: driver a definir ────────────────────────────────────────────
foreach (['1' => 'Cereais', '7' => 'UTM', '13' => 'UBS'] as $cod => $nome) {
    $d = $tela((string)$cod)['desafio']['desafio'];
    prova("{$nome}: projeção × premissas, com a nota \"driver a definir\"",
        $d['driver'] === 'A_DEFINIR' && str_contains($d['nota'], 'driver de volume a definir'), $d['nota']);
}

// ── Cota Aurora e bovinos (metas de prova) ─────────────────────────────────
$plano = static fn (string $cod) => (int)Database::um(
    'SELECT p.id FROM planejamento p JOIN negocio n ON n.id = p.negocio_id JOIN ciclo c ON c.id = p.ciclo_id
      WHERE n.cod_negocio = ? AND ? BETWEEN c.ano_base AND c.ano_fim LIMIT 1', [$cod, $anoPlan])['id'];
prova('o banco de provas não tem cota nem bovinos próprios (a prova cria os seus)',
    !DesafioCota::cotaDoLeite($anoPlan) && !DesafioDrivers::bovinosDoAno($anoPlan));
$t12 = $tela('12');
prova('sem a cota, os Postos vão por projeção × premissas e a nota diz',
    $t12['desafio']['desafio']['driver'] === 'SEM_META', $t12['desafio']['desafio']['nota']);

$criar = static function (int $plan, string $nome, float $porMes) use ($pdo, $anoPlan): int {
    $pdo->prepare("INSERT INTO indicador (planejamento_id, nome, unidade, sentido, agregacao, metrica_ancora, ordem)
        VALUES (?, ?, 'Un', 'MAIOR_MELHOR', 'SOMA', 0, 999)")->execute([$plan, $nome]);
    $ind = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor) VALUES (?, ?, ?, 'META', 1, ?)");
    for ($m = 1; $m <= 12; $m++) {
        $ins->execute([$ind, $anoPlan, $m, $porMes]);
    }
    return $ind;
};
$cota = $criar($plano('4'), 'Cota Aurora', 8950);
$bov = $criar($plano('8'), 'Ração Bovinos (t)', 4800);
try {
    $c = DesafioCota::cotaDoLeite($anoPlan);
    prova('a cota do ano é a soma dos meses, lida em mil litros: 107.400.000 litros',
        $c && abs($c['litros'] - 107400000) < 0.5 && $c['em_mil_litros'], json_encode($c));

    $d = $tela('12')['desafio']['desafio'];
    prova('Postos: faturamento = 107.400.000 × R$ 0,055 = R$ 5.907.000', abs($d['receita_bruta'] - 5907000) < 1, $fmt($d['receita_bruta']));
    prova('Postos: a nota diz a cota e o R$ 0,055', str_contains($d['nota'], '107.400.000') && str_contains($d['nota'], '0,055'), $d['nota']);

    $t = $tela('4');
    $b11 = $linha($t, '1.1')['projecao'];
    // Preço dos 12 MESES REALIZADOS (R$ 313.732.042 ÷ 107.904.705 L), reajustado pelas
    // premissas do Leite (IPCA 4,5 + PIB 1,5 sugeridos = 6%).
    $rec12 = DesafioDrivers::receitaDozeMeses('4');
    $esperado = 107400000 * ($rec12 / 107904705) * 1.06;
    prova('Leite: Receita de Vendas = cota × preço médio dos 12 meses × (1 + IPCA + PIB + crescimento)',
        abs($t['driver']['valor'] - $esperado) < 1 && abs($t['driver']['crescimento'] - ($esperado / $b11 - 1) * 100) < 1e-9,
        $fmt($esperado) . ' (R$ ' . number_format($rec12 / 107904705, 4, ',', '.') . '/L × 1,06)');
    // As premissas mexem no Leite (pedido do cliente, 2026-11-23): +2% de crescimento.
    $pdo->prepare("INSERT INTO negocio_premissa (negocio_id, ano, chave, percentual) VALUES (?, ?, 'CRESCIMENTO', 2)")
        ->execute([$id('4'), $anoPlan]);
    try {
        $t2 = $tela('4');
        prova('Leite: +2% de crescimento desejado move o faturamento do driver (× 1,08 em vez de × 1,06)',
            abs($t2['driver']['valor'] - 107400000 * ($rec12 / 107904705) * 1.08) < 1, $fmt($t2['driver']['valor']));
    } finally {
        $pdo->prepare("DELETE FROM negocio_premissa WHERE negocio_id = ? AND ano = ? AND chave = 'CRESCIMENTO'")
            ->execute([$id('4'), $anoPlan]);
    }
    prova('Leite: a nota diz litros, preço e de onde veio o preço',
        str_contains($t['driver']['nota'], '107.904.705') && str_contains($t['driver']['nota'], 'preço médio'), $t['driver']['nota']);

    $pdo->prepare('UPDATE indicador_valor SET valor = 10000 WHERE indicador_id = ? AND mes = 12')->execute([$cota]);
    $d = $tela('12')['desafio']['desafio'];
    prova('mudar a cota de dezembro (10.000) muda o desafio na hora: +R$ 57.750 → R$ 5.964.750',
        abs($d['receita_bruta'] - 5964750) < 1, $fmt($d['receita_bruta']));

    $b = DesafioDrivers::bovinosDoAno($anoPlan);
    prova('a meta de bovinos das Agropecuárias é lida em toneladas: 57.600 t', $b && abs($b['toneladas'] - 57600) < 0.01, json_encode($b));
    $t = $tela('6');
    $b11 = $linha($t, '1.1')['projecao'];
    $premissa = ($t['driver']['crescimento'] ?? 0);
    $dig = Database::todos('SELECT chave, percentual FROM negocio_premissa WHERE negocio_id = ? AND ano = ?', [$id('6'), $anoPlan]);
    $ef = Projecao::premissasEfetivas($anoPlan, array_column($dig, 'percentual', 'chave'));
    $cr = ($ef['IPCA'] ?? 0) + ($ef['PIB'] ?? 0) + ($ef['CRESCIMENTO'] ?? 0);
    $esperado = 57600 * 100 + ($b11 - 53575.85 * 100) * (1 + $cr / 100);
    prova('Rações: bovinos 57.600 t × R$ 100 + o resto da base × premissas',
        abs($b11 * (1 + $premissa / 100) - $esperado) < 1, $fmt($esperado));
    prova('Rações: a nota fala dos bovinos e dos suínos a definir',
        str_contains($t['driver']['nota'], 'bovinos') && str_contains($t['driver']['nota'], 'Suínos: driver a definir'), $t['driver']['nota']);
} finally {
    foreach ([$cota, $bov] as $ind) {
        $pdo->prepare('DELETE FROM indicador_valor WHERE indicador_id = ?')->execute([$ind]);
        $pdo->prepare('DELETE FROM indicador WHERE id = ?')->execute([$ind]);
    }
}
prova('as metas de prova foram apagadas', !DesafioCota::cotaDoLeite($anoPlan) && !DesafioDrivers::bovinosDoAno($anoPlan));

// ── Corporativo: a soma dos negócios ───────────────────────────────────────
$mc = new ReflectionMethod($ctrl, 'montarCorporativo');
$mc->setAccessible(true);
$negs = Database::todos('SELECT id FROM negocio WHERE ativo = 1');
$c = $mc->invoke($ctrl, $negs, $ano, $corte);
$soma = 0.0;
foreach ($negs as $n) {
    $soma += $montar->invoke($ctrl, (int)$n['id'], $ano, $corte)['desafio']['desafio']['resultado'];
}
prova('Corporativo: o desafio total é a soma dos negócios', abs($c['desafio']['total']['desafio']['resultado'] - $soma) < 1,
    $fmt($soma));

// ── A carga ────────────────────────────────────────────────────────────────
try {
    CargaDesafio::aplicar($pdo, ['ano' => 2099, 'meta_margem' => 2.0, 'fonte' => 'x', 'metodo' => 'x', 'decidido_em' => '2026-11-23',
        'calculado' => true, 'valores' => ['8' => []]]);
    $msg = 'ACEITOU';
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
prova('a carga recusa desafio calculado com valores', str_contains($msg, 'ou um, ou outro'), $msg);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

<?php

/**
 * Provas do DESAFIO de 2027 na Projeção (pedido do cliente, 2026-11-18: "os 2%
 * são margem líquida, carregue o desafio na projeção").
 *
 * Três âncoras: (1) a carga — os números da página aprovada, o total e a conta
 * dos 2%; (2) a ORIGEM — o desafio sai da visão realista de 2026 que a mesma
 * tela mostra (× 1,06, margem nunca pior que a de 2026), então os dois não podem
 * contar histórias diferentes; (3) a carga é estrita e sobrescreve o ano.
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
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Services/CargaDesafio.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaDesafio;

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

$carga = require __DIR__ . '/../database/desafio_2027.php';
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[$n['cod_negocio']] = (int)$n['id'];
}
$ctrl = new App\Controllers\ProjecaoController();

// ── 1. O que está no banco é a página aprovada ──────────────────────────────
$d = invocar($ctrl, 'desafioDoAno', 2027);
prova('há desafio de 2027, com a meta de 2% de margem líquida', $d && $d['meta_margem'] == 2.0, json_encode($d['meta_margem'] ?? null));
prova('os 11 negócios da página estão no banco', $d && count($d['por_negocio']) === 11, (string)count($d['por_negocio'] ?? []));
$pec = $d['por_negocio'][$ids['2']] ?? null;
prova('Pecuária no cenário otimista do negócio: R$ 774.720.004 de receita bruta',
    $pec && $pec['receita_bruta'] == 774720004, json_encode($pec['receita_bruta'] ?? null));
$cer = $d['por_negocio'][$ids['1']] ?? null;
prova('Cereais volta à margem de 2025 (1,72%), e não à do plano de 2026 (0,82%)',
    $cer && abs($cer['margem'] - 1.7239) < 0.0005, json_encode($cer['margem'] ?? null));
prova('Usina sem receita: sem margem, e com resultado (R$ 421.184, 2026 realista + IPCA de 4,5% da diretriz)',
    ($u = $d['por_negocio'][$ids['17']] ?? null) && $u['margem'] === null && $u['resultado'] == 421184);

$propostas = [];
foreach ($ids as $cod => $id) {
    if (isset($d['por_negocio'][$id])) {
        $propostas[$id] = ['receita_bruta' => 0.0, 'receita_liquida' => 0.0, 'resultado' => 0.0, 'margem' => null];
    }
}
$c = invocar($ctrl, 'desafioCorporativo', 2027, $propostas);
$t = $c['total']['desafio'];
prova('total do desafio: ROB R$ 3.694.590.776, ROL R$ 3.563.582.221, resultado R$ 63.687.733',
    $t['receita_bruta'] == 3694590776 && $t['receita_liquida'] == 3563582221 && $t['resultado'] == 63687733,
    "{$t['receita_bruta']} / {$t['receita_liquida']} / {$t['resultado']}");
prova('margem líquida do desafio: 1,79%', abs($t['margem'] - 1.7872) < 0.0005, (string)round($t['margem'], 4));
prova('os 2% sobre a ROL do desafio são R$ 71,27 mi, e faltam R$ 7,58 mi',
    abs($t['resultado_meta'] - 71271644.42) < 1 && abs($t['falta'] - 7583911.42) < 1,
    round($t['resultado_meta']) . ' / ' . round($t['falta']));
prova('o lado da proposta usa a MESMA meta sobre a ROL dela (proposta zerada → falta zero)',
    $c['total']['proposta']['falta'] == 0.0 && $c['total']['proposta']['margem'] === null);

// ── 2. O desafio sai da visão realista de 2026 que a tela mostra ────────────
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$v = invocar($ctrl, 'visaoDoAno', array_values($ids), (int)$fech['ano'], (int)$fech['mes_fechado_ate']);
$divergem = [];
$margemPior = [];
foreach ($d['por_negocio'] as $id => $x) {
    $cod = array_search($id, $ids, true);
    $vis = $v[$id] ?? null;
    if (!$vis) {
        $divergem[] = "{$cod}: sem visão";
        continue;
    }
    if ((string)$cod === '17') {
        if (abs($x['resultado'] - $vis['18']['real_realista'] * 1.045) > 1800) {
            $divergem[] = "17: " . round($x['resultado']) . ' vs ' . round($vis['18']['real_realista'] * 1.045);
        }
        continue;
    }
    // ROB = 2026 realista × 1,06 — menos a Pecuária, que vem da planilha do negócio.
    if ((string)$cod !== '2' && abs($x['receita_bruta'] - $vis['1']['real_realista'] * 1.06) > 10) {
        $divergem[] = "{$cod}: " . round($x['receita_bruta']) . ' vs ' . round($vis['1']['real_realista'] * 1.06);
    }
    // Margem nunca pior que a de 2026 realista (ROL de 2026 pela mesma relação ROL/ROB).
    $rol26 = $vis['1']['real_realista'] * $x['receita_liquida'] / $x['receita_bruta'];
    $m26 = $vis['18']['real_realista'] / $rol26 * 100;
    // ±R$ 1,8 mil no resultado de 6 e 8 (lançamento que mudou de negócio entre a
    // extração do painel e a carga) vira ±0,001 p.p. de margem.
    if ($x['margem'] < $m26 - 0.002) {
        $margemPior[] = "{$cod}: " . round($x['margem'], 4) . ' < ' . round($m26, 4);
    }
}
prova('receita bruta = 2026 realista (desta tela) × 1,06 em 9 negócios, ao real; Usina = resultado realista × 1,045',
    !$divergem, implode(' | ', $divergem));
prova('preservar margem: nenhum negócio com margem de desafio pior que a de 2026 realista',
    !$margemPior, implode(' | ', $margemPior));

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
$teste = $carga;
$teste['ano'] = 2099;
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
    count(invocar($ctrl, 'desafioDoAno', 2027)['por_negocio']) === 11);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

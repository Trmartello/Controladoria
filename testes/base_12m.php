<?php

/**
 * Provas da BASE DE 12 MESES da Projeção (pedido do cliente, 2026-11-23: "12 meses
 * nov/25–out/26 em tudo"): a coluna que era o orçado de nov–dez/26 passa a ser o
 * REALIZADO de nov–dez/25, e a "projeção" é jan–out/26 + nov–dez/25.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/base_12m.php
 *
 * As âncoras são do Qlik (`DRE - Gerencial Nova`, extraído em 23/11/2026) e conferem
 * de MÃO com `database/dre_2025_11_12.php`: Leite, receita bruta 22.599.802 (nov) +
 * 21.194.916 (dez) = 43.794.718; resultado líquido 270.419 − 1.253.149 = −982.730.
 * Só lê — exceto a prova do carregador, que roda numa transação desfeita.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
spl_autoload_register(static function (string $classe): void {
    $arq = __DIR__ . '/../' . str_replace(['App\\', '\\'], ['app/', '/'], $classe) . '.php';
    if (is_file($arq)) {
        require_once $arq;
    }
});

use App\Core\Database;
use App\Services\CargaApoio;
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

$pdo = Database::conn();
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
prova('o fechamento é out/2026 (as âncoras são desse corte)', $ano === 2026 && $corte === 10, "{$ano}/{$corte}");

$leite = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'];
$cereais = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '1'")['id'];
$ctrl = new App\Controllers\ProjecaoController();
$m = static function (string $nome) use ($ctrl) {
    $r = new ReflectionMethod($ctrl, $nome);
    $r->setAccessible(true);
    return static fn (...$a) => $r->invoke($ctrl, ...$a);
};
$linhasDaDre = $m('linhasDaDre');
$linhasDaFilial = $m('linhasDaFilial');
$por = static fn (array $ls) => array_column($ls, null, 'codigo');

// ── Negócio ─────────────────────────────────────────────────────────────────
$l = $por($linhasDaDre($leite, $ano, $corte));
prova('Leite: a coluna depois do corte é o REALIZADO de nov–dez/25 (receita bruta 43.794.718)',
    abs($l['1']['projetado'] - 43794718) < 1, number_format($l['1']['projetado'], 0, ',', '.'));
prova('Leite: resultado líquido de nov–dez/25 = 270.419 − 1.253.149 = −982.730',
    abs($l['18']['projetado'] + 982730) < 1, number_format($l['18']['projetado'], 0, ',', '.'));
$realJanOut = (float)Database::um(
    "SELECT SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
      WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = 'REAL' AND v.mes <= ? AND c.codigo = '1'",
    [$leite, $ano, $corte]
)['s'];
prova('Leite: a base (projeção) é jan–out/26 + nov–dez/25, sem o orçado',
    abs($l['1']['projecao'] - ($realJanOut + 43794718)) < 1 && abs($l['1']['realizado'] - $realJanOut) < 1,
    number_format($l['1']['projecao'], 0, ',', '.'));
$meta = (float)Database::um(
    "SELECT SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
      WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = 'META' AND v.mes > ? AND c.codigo = '1'",
    [$leite, $ano, $corte]
)['s'];
prova('o orçado de nov–dez/26 continua no banco e NÃO entra na base', $meta != 0.0 && abs($l['1']['projetado'] - $meta) > 1000,
    'orçado ' . number_format($meta, 0, ',', '.'));

// O Painel e o Corporativo fazem a mesma conta.
$res = Projecao::resumoDosNegocios([$leite, $cereais], $ano, $corte, $ano + 1, ['1', '3', '18']);
prova('resumoDosNegocios (Painel/Corporativo) lê a mesma base da tela',
    abs($res[$leite]['projecao']['1'] - $l['1']['projecao']) < 1 && abs($res[$leite]['projecao']['18'] - $l['18']['projecao']) < 1);

// ── Filiais ─────────────────────────────────────────────────────────────────
$somaFiliais = static function (int $negocioId) use ($linhasDaFilial, $por, $ano, $corte): array {
    $s = ['1' => 0.0, '18' => 0.0];
    foreach (Database::todos(
        'SELECT DISTINCT f.id FROM filial f JOIN dre_valor_filial v ON v.filial_id = f.id WHERE f.negocio_id = ? AND v.ano = ?',
        [$negocioId, $ano]
    ) as $f) {
        $lf = $por($linhasDaFilial((int)$f['id'], $ano, $corte));
        $s['1'] += $lf['1']['projetado'];
        $s['18'] += $lf['18']['projetado'];
    }
    return $s;
};
$sl = $somaFiliais($leite);
prova('Leite: as filiais somam o nov–dez/25 do negócio (receita e resultado)',
    abs($sl['1'] - $l['1']['projetado']) < 20 && abs($sl['18'] - $l['18']['projetado']) < 20,
    number_format($sl['1'], 0, ',', '.') . ' / ' . number_format($sl['18'], 0, ',', '.'));
$lc = $por($linhasDaDre($cereais, $ano, $corte));
$sc = $somaFiliais($cereais);
$falta = $lc['1']['projetado'] - $sc['1'];
prova('Cereais: as 35 filiais de 2025 fora do cadastro de 2026 ficam só no negócio (~R$ 12,4 mi de receita)',
    abs($falta - 12365508) < 50, number_format($falta, 0, ',', '.'));

// ── O aviso de mês faltando ─────────────────────────────────────────────────
$montar = $m('montar');
$tela = $montar($leite, $ano, $corte);
prova('com nov–dez/25 carregados, nenhum mês falta na base', $tela['meses_sem_meta'] === [], json_encode($tela['meses_sem_meta']));

// ── O carregador ────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    CargaApoio::drePeriodo($pdo, ['ano' => 2025, 'meses' => [11, 12], 'negocios' => ['4' => ['6' => [1, 2]]]]);
    $msg = 'ACEITOU';
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
$pdo->rollBack();
prova('a carga recusa linha percentual (a tela a deriva)', str_contains($msg, 'percentual'), $msg);
$pdo->beginTransaction();
try {
    CargaApoio::drePeriodo($pdo, ['ano' => 2025, 'meses' => [11, 12], 'negocios' => ['4' => ['1.1' => [1]]]]);
    $msg = 'ACEITOU';
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
$pdo->rollBack();
prova('a carga recusa série sem os dois meses', str_contains($msg, 'sem os meses'), $msg);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

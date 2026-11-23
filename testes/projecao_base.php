<?php

/**
 * Provas da BASE da Projeção: realizado de jan até o corte + PLANEJADO (orçado) dos
 * meses que faltam, do MESMO ano (decisão do cliente, 2026-11-23: "jan–out deve ser
 * de 2026 e nov–dez deve pegar o planejado de 2026", revendo a base de 12 meses
 * nov/25–out/26 que valeu por algumas horas no mesmo dia).
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/projecao_base.php
 *
 * A DRE de nov–dez/2025 (`database/dre_2025_11_12.php`) continua carregada: é dela
 * que saem as oportunidades em 12 meses e o preço médio do Leite. Só lê — exceto a
 * prova do carregador, que roda numa transação desfeita.
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
prova('o fechamento é out/2026 (as âncoras são desse corte)', $ano === 2026 && $corte === 10, "{$ano}/{$corte}");

$leite = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'];
$ctrl = new App\Controllers\ProjecaoController();
$m = static function (string $nome) use ($ctrl) {
    $r = new ReflectionMethod($ctrl, $nome);
    $r->setAccessible(true);
    return static fn (...$a) => $r->invoke($ctrl, ...$a);
};
$linhasDaDre = $m('linhasDaDre');
$soma = static fn (int $n, int $a, string $tipo, string $mes) => (float)Database::um(
    "SELECT SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
      WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = ? AND v.mes {$mes} AND c.codigo = '1'",
    [$n, $a, $tipo, $corte]
)['s'];

$l = array_column($linhasDaDre($leite, $ano, $corte), null, 'codigo');
$real = $soma($leite, $ano, 'REAL', '<= ?');
$plan = $soma($leite, $ano, 'META', '> ?');
prova('Leite: Realizado é jan–out/26', abs($l['1']['realizado'] - $real) < 1, $fmt($real));
prova('Leite: a coluna do meio é o PLANEJADO de nov–dez/26 (orçado), não o realizado de nov–dez/25',
    abs($l['1']['projetado'] - $plan) < 1 && abs($l['1']['projetado'] - 43794718) > 1000, $fmt($plan));
prova('Leite: a projeção é a soma — a base da proposta', abs($l['1']['projecao'] - ($real + $plan)) < 1, $fmt($l['1']['projecao']));
$res = Projecao::resumoDosNegocios([$leite], $ano, $corte, $ano + 1, ['1', '18']);
prova('o Painel e o Corporativo (`resumoDosNegocios`) leem a mesma projeção',
    abs($res[$leite]['projecao']['1'] - $l['1']['projecao']) < 1 && abs($res[$leite]['projecao']['18'] - $l['18']['projecao']) < 1);
$tela = $m('montar')($leite, $ano, $corte);
prova('com o orçado de nov–dez/26 carregado, nenhum mês falta na projeção', $tela['meses_sem_meta'] === []);

// nov–dez/25 continua carregado: oportunidades e o preço do Leite.
prova('a DRE de nov–dez/25 segue carregada (Leite, receita bruta 22.599.802 + 21.194.916)',
    abs($soma($leite, $ano - 1, 'REAL', '> ?') - 43794718) < 1);
prova('o preço do Leite sai dos 12 meses realizados: Receita de Vendas de nov/25–out/26 = R$ 313.732.042',
    abs(DesafioDrivers::receitaDozeMeses('4') - 313732042) < 2, $fmt(DesafioDrivers::receitaDozeMeses('4')));

// O carregador da DRE de outro período.
foreach ([
    ['linha percentual', ['4' => ['6' => [1, 2]]], 'percentual'],
    ['série sem os dois meses', ['4' => ['1.1' => [1]]], 'sem os meses'],
] as [$nome, $neg, $espera]) {
    $pdo->beginTransaction();
    try {
        CargaApoio::drePeriodo($pdo, ['ano' => 2025, 'meses' => [11, 12], 'negocios' => $neg]);
        $msg = 'ACEITOU';
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
    }
    $pdo->rollBack();
    prova("a carga da DRE do período recusa {$nome}", str_contains($msg, $espera), $msg);
}

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

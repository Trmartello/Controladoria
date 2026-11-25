<?php

/**
 * Provas do RATEIO MATRIZ/CENTRAL (linha 14.1) ZERADO no planejamento (pedido do
 * cliente, 2026-11-25: "zere o valor para os negócios no planejado; o realizado
 * traga conforme estiver no Qlik"). A conta 9999 fecha em zero na cooperativa,
 * mas sobra resíduo em cada negócio — não é meta.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/rateio_matriz.php
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

$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$linha = static fn (array $linhas, string $c) => array_column($linhas, null, 'codigo')[$c];

// O realizado da 14.1 direto da carga do Qlik (dre_valor), por negócio.
$realQlik = [];
foreach (Database::todos(
    "SELECT v.negocio_id, SUM(v.valor) AS v FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
      WHERE c.codigo = '14.1' AND v.ano = ? AND v.mes <= ? GROUP BY v.negocio_id", [$ano, $corte]) as $r) {
    $realQlik[(int)$r['negocio_id']] = (float)$r['v'];
}
prova('há resíduo de verdade no realizado da carga (algum negócio com 14.1 diferente de zero)',
    count(array_filter($realQlik, static fn ($v) => abs($v) >= 1)) > 0, json_encode(array_map('round', $realQlik)));

$todosZero = true;
$desafioZero = true;
$realIgual = true;
$detalhe = [];
$negocios = Database::todos('SELECT id FROM negocio WHERE ativo = 1');
foreach ($negocios as $n) {
    $id = (int)$n['id'];
    $t = $montar->invoke($ctrl, $id, $ano, $corte, 0);
    $l = $linha($t['linhas'], '14.1');
    $todosZero = $todosZero && abs((float)$l['proposta']) < 0.005 && $l['proposta_origem'] === 'RATEIO_MATRIZ'
        && !empty($l['proposta_travada']) && abs((float)$l['projetado']) < 0.005;
    $realIgual = $realIgual && abs((float)$l['realizado'] - ($realQlik[$id] ?? 0.0)) < 0.01;
    $e = $t['desafio']['escada']['desafio'] ?? null;
    $detalhe[$id] = [round((float)$l['realizado'], 2), $l['proposta']];
    foreach ($t['filiais'] as $f) {
        $tf = $montar->invoke($ctrl, $id, $ano, $corte, (int)$f['id']);
        $lf = $linha($tf['filial']['linhas'], '14.1');
        $todosZero = $todosZero && abs((float)$lf['proposta']) < 0.005;
    }
}
prova('planejado nov–dez e proposta da 14.1 valem zero, travados, em todo negócio e filial', $todosZero, json_encode($detalhe));
prova('o realizado da 14.1 é o da carga do Qlik, sem mexer', $realIgual);

// O desafio (proposta padrão) também sem o resíduo: a 14.1 das linhas dele é zero.
$cer = Database::um("SELECT id FROM negocio WHERE cod_negocio = '1'");
$t = $montar->invoke($ctrl, (int)$cer['id'], $ano, $corte, 0);
$res = (float)$t['desafio']['desafio']['resultado'];
// (−R$ 19.501.365 desde a planilha nova do rateio administrativo, 25/11: 16.1 de R$ 59,0 para 37,7 mi.)
// (−R$ 19.646.536 desde (25/11: a base do pessoal sem a 3419, a 3398 e a 3339 projetadas para o ano).)
// (−R$ 19.550.065 (25/11, 2ª planilha do rateio administrativo: R$ 37,01 mi).)
prova('Cereais: o desafio fica −R$ 19.550.065 (os −R$ 245 do resíduo saem)', abs($res + 19550065) < 2, (string)round($res));

// O Corporativo: a 14.1 da proposta é zero.
$c = (new ReflectionMethod($ctrl, 'montarCorporativo'));
$c->setAccessible(true);
$corp = $c->invoke($ctrl, $negocios, $ano, $corte);
prova('no Corporativo a proposta da 14.1 é zero', abs((float)$linha($corp['linhas'], '14.1')['proposta']) < 0.005);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

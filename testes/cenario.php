<?php

/**
 * Provas do CENÁRIO de faturamento carregado de planilha (pedido do cliente,
 * 2026-11-19: "carregue o cenário realista da Pecuária para o planejamento; a
 * planilha está no formato da DRE antiga, precisa converter").
 *
 * A âncora é a planilha: o total realista da aba "Projetado 2027" (D41) é
 * R$ 732.218.693,78, e o que vai para a Receita de Vendas é ele sem a CBS.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/cenario.php
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

$c = require __DIR__ . '/../database/cenario_pecuaria_2027.php';
$tot = ['p' => 0.0, 'r' => 0.0, 'o' => 0.0];
foreach ($c['itens'] as [$p, $r, $o]) {
    $tot['p'] += $p;
    $tot['r'] += $r;
    $tot['o'] += $o;
}
prova('os itens realistas somam o total da planilha (D41 = 732.218.693,78)',
    abs($tot['r'] - 732218693.78) < 0.05 && abs($c['faturamento_antigo'] - 732218693.78) < 0.01, (string)$tot['r']);
prova('pessimista e otimista da planilha: 686.451.763,79 e 774.720.003,52',
    abs($tot['p'] - 686451763.79) < 0.05 && abs($tot['o'] - 774720003.52) < 0.05);
prova('a conversão: receita de vendas = faturamento × (1 − taxa de PIS/COFINS)',
    abs($c['faturamento_antigo'] * (1 - $c['taxa_pis_cofins']) - $c['receita_vendas']) < 0.01);

// A taxa da carga é a que o sistema usa para a Pecuária.
$id = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '2'")['id'];
$ctrl = new App\Controllers\ProjecaoController();
$m = (new ReflectionMethod($ctrl, 'montar'));
$m->setAccessible(true);
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$d = $m->invoke($ctrl, $id, (int)$fech['ano'], (int)$fech['mes_fechado_ate']);
prova('a taxa da carga é a de PIS/COFINS que o sistema usa para a Pecuária',
    abs($d['tributos_dre']['taxa'] - $c['taxa_pis_cofins']) < 1e-12, (string)$d['tributos_dre']['taxa']);

// Lançada: a proposta no formato antigo (o que o motor guarda) é o faturamento.
$l11 = array_values(array_filter($d['linhas'], static fn ($l) => $l['codigo'] === '1.1'))[0];
$lanc = Database::um("SELECT v.* FROM projecao_valor v JOIN projecao p ON p.id = v.projecao_id
    JOIN dre_conta c ON c.id = v.conta_id WHERE p.negocio_id = ? AND p.ano = 2027 AND v.filial_id = 0 AND c.codigo = '1.1'", [$id]);
prova('a Receita de Vendas da proposta está lançada como VALOR, no formato novo (sem a CBS)',
    // R$ 1: a bateria de tela tira e devolve o lançamento digitando o valor em reais.
    $lanc && $lanc['entrada'] === 'VALOR' && abs((float)$lanc['valor'] - $c['receita_vendas']) < 1, json_encode($lanc));
// O cenário da planilha ficou ABAIXO do mínimo de 2027 (IPCA + PIB + crescimento
// desejado): desde 2026-11-21 ele não vale — a linha fica no piso e guarda o
// recusado (formato do motor, com a CBS) em `lancado_abaixo`.
prova('o cenário da planilha (formato antigo, o do motor) ficou abaixo do piso e é guardado como recusado',
    !empty($l11['piso_aplicado']) && abs($l11['lancado_abaixo'] - $c['faturamento_antigo']) < 2, (string)($l11['lancado_abaixo'] ?? ''));
prova('e a proposta da Receita de Vendas é o piso: projeção × (1 + IPCA + PIB + desejado)',
    $l11['proposta_origem'] === 'PREMISSA' && abs($l11['proposta'] - $l11['projecao'] * (1 + $l11['piso_percentual'] / 100)) < 1
    && $l11['proposta'] > $c['faturamento_antigo'], (string)$l11['proposta']);
$tela = (new ReflectionMethod($ctrl, 'paraTela'));
$tela->setAccessible(true);
$t = $tela->invoke(null, $d);
$rb = array_values(array_filter($t['linhas'], static fn ($l) => $l['codigo'] === '1'))[0];
$cbs = array_values(array_filter($t['linhas'], static fn ($l) => $l['codigo'] === 'CBS'))[0] ?? null;
prova('na tela (formato 2027): Receita Bruta + CBS = a proposta do motor (o piso)',
    $cbs && abs($cbs['proposta'] + $rb['proposta'] - $l11['proposta']) < 2 && $rb['proposta'] > $c['receita_vendas'],
    json_encode([$rb['proposta'], $cbs['proposta'] ?? null]));
prova('o cenário vai para a tela com a conversão e os três totais',
    $d['cenario'] && $d['cenario']['cenario'] === 'REALISTA' && abs($d['cenario']['cbs'] - 419969.14) < 0.01
    && abs($d['cenario']['totais']['realista'] - 732218693.78) < 0.05 && count($d['cenario']['itens']) === 17);

// A carga recusa conversão que não fecha.
$x = $c;
$x['receita_vendas'] = $c['faturamento_antigo'];
$erro = '';
try {
    CargaApoio::cenario(Database::conn(), $x);
} catch (RuntimeException $e) {
    $erro = $e->getMessage();
}
prova('cenário com receita ≠ faturamento × (1 − taxa) é recusado', str_contains($erro, 'faturamento × (1 − taxa)'), $erro);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

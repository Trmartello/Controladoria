<?php

/**
 * Provas do NÍVEL 3 da DRE — a conta reduzida, como a aba "#2 Planejado X
 * Realizado" do Qlik (pedido do cliente, 2026-11-21): a carga, as somas contra o
 * nível 2, a correção do rateio corporativo das filiais, o formato de 2027 e a
 * conta reduzida das oportunidades.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/nivel3.php
 *
 * As âncoras são do Qlik (DRE - Gerencial Nova, jan–out/2026, conferidas na
 * extração): Leite, 3037 SALARIOS E REMUNERACOES = −1.398.030; 3002 RECEITA
 * VENDA A PRAZO SOCIO = 269.925.759. Lê o banco carregado pelo migrate; não
 * escreve nada.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/CargaNivel3.php';

use App\Core\Database;
use App\Services\CargaNivel3;
use App\Services\Oportunidades;
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
$leite = (int)Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'];

// ── 1. O catálogo e as âncoras ───────────────────────────────────────────────
$cat = Database::um("SELECT * FROM dre_reduzida WHERE codigo = '8.5.2'");
// 422 contas em 2026; com a carga de 2025 (questionamentos, 2026-11-23), as que só
// existiram em 2025 entram também: 437; e as 6 que só aparecem em nov–dez/2024
// (janelas de 12 meses): 443.
prova('o catálogo tem as 443 contas reduzidas do Qlik (nov/2024 a out/2026)',
    (int)Database::um('SELECT COUNT(*) n FROM dre_reduzida')['n'] === 443);
prova('e 2026 continua com as 422 que tiveram valor no ano',
    (int)Database::um('SELECT COUNT(DISTINCT codigo) n FROM dre_reduzida_valor WHERE ano = 2026')['n'] <= 422);
prova('8.5.2 é a 3037 SALARIOS E REMUNERACOES, da linha 8.5',
    $cat && $cat['reduzida'] === '3037' && $cat['nome'] === 'SALARIOS E REMUNERACOES' && $cat['linha'] === '8.5', json_encode($cat));
$n3 = Projecao::nivel3([$leite], 2026);
$acha = static fn ($linha, $red) => array_values(array_filter($n3[$linha] ?? [], static fn ($r) => $r['reduzida'] === $red))[0] ?? null;
prova('Leite: 3037 SALARIOS E REMUNERACOES = −1.398.030 (Qlik)', abs(($acha('8.5', '3037')['realizado'] ?? 0) + 1398030) <= 1);
prova('Leite: 3002 RECEITA VENDA A PRAZO SOCIO = 269.925.759 (Qlik)', abs(($acha('1.1', '3002')['realizado'] ?? 0) - 269925759) <= 1);
prova('as contas vêm na ordem do Qlik (3037 antes da 3182 na 8.5)',
    array_search('3037', array_column($n3['8.5'], 'reduzida'), true) < array_search('3182', array_column($n3['8.5'], 'reduzida'), true));

// ── 2. O nível 3 soma o nível 2, no negócio e na filial ─────────────────────
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$corte = (int)$fech['mes_fechado_ate'];
$dif = [];
foreach (Database::todos(
    "SELECT v.negocio_id, c.codigo, SUM(v.valor) s FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
      WHERE v.ano = 2026 AND v.tipo = 'REAL' AND v.mes <= ? AND c.nivel = 2 GROUP BY v.negocio_id, c.codigo", [$corte]
) as $r) {
    $s = Database::um('SELECT COALESCE(SUM(v.realizado), 0) s FROM dre_reduzida_valor v JOIN dre_reduzida d ON d.codigo = v.codigo
        WHERE v.negocio_id = ? AND v.filial_id = 0 AND v.ano = 2026 AND d.linha = ?', [$r['negocio_id'], $r['codigo']])['s'];
    if (abs((float)$s - (float)$r['s']) > 100) {
        $dif[] = $r['negocio_id'] . ':' . $r['codigo'];
    }
}
// As 7 da extração: o Qlik foi recarregado depois da carga do nível 2 (18/11).
prova('negócio a negócio, o nível 3 soma o nível 2 (salvo as 7 diferenças de recarga, até R$ 6 mil)', count($dif) <= 7, implode(', ', $dif));

$difF = [];
foreach (Database::todos(
    "SELECT v.filial_id, c.codigo, v.realizado FROM dre_valor_filial v JOIN dre_conta c ON c.id = v.conta_id
      WHERE v.ano = 2026 AND c.nivel = 2"
) as $r) {
    $s = Database::um('SELECT COALESCE(SUM(v.realizado), 0) s FROM dre_reduzida_valor v JOIN dre_reduzida d ON d.codigo = v.codigo
        WHERE v.filial_id = ? AND v.ano = 2026 AND d.linha = ?', [$r['filial_id'], $r['codigo']])['s'];
    if (abs((float)$s - (float)$r['realizado']) > 100) {
        $difF[] = $r['filial_id'] . ':' . $r['codigo'];
    }
}
prova('filial a filial, também — inclusive no rateio corporativo (14.3 e 16.1), já corrigido',
    count($difF) <= 6 && !array_filter($difF, static fn ($k) => preg_match('/:(14\.3|16\.1)$/', $k)), implode(', ', array_slice($difF, 0, 8)));

$quebra = (int)Database::um(
    "SELECT COUNT(*) n FROM (SELECT v.filial_id,
       SUM(CASE WHEN c.codigo = '13' THEN v.realizado END) r13, SUM(CASE WHEN c.codigo = '14' THEN v.realizado END) r14,
       SUM(CASE WHEN c.codigo = '15' THEN v.realizado END) r15, SUM(CASE WHEN c.codigo = '16' THEN v.realizado END) r16,
       SUM(CASE WHEN c.codigo = '18' THEN v.realizado END) r18
       FROM dre_valor_filial v JOIN dre_conta c ON c.id = v.conta_id WHERE v.ano = 2026 GROUP BY v.filial_id) x
     WHERE ABS(r15 - (r13 + r14)) > 2 OR ABS(r18 - (r15 + r16)) > 2"
)['n'];
prova('corrigido o rateio, os blocos da filial continuam fechando (15 = 13 + 14, 18 = 15 + 16)', $quebra === 0, (string)$quebra);
prova('a correção do rateio é idempotente: rodar de novo não mexe em nada', CargaNivel3::corrigirRateioDasFiliais($pdo, 2026) === 0);

$somaFil = Database::um("SELECT SUM(realizado) s FROM dre_reduzida_valor WHERE negocio_id = ? AND filial_id <> 0 AND ano = 2026 AND codigo = '8.5.2'", [$leite])['s'];
prova('a soma das filiais é o negócio (3037 do Leite)', abs((float)$somaFil + 1398030) <= 50, (string)$somaFil);

// ── 3. O formato de 2027 ─────────────────────────────────────────────────────
$base = ['1.1' => [['codigo' => '1.1.3', 'reduzida' => '3002', 'nome' => 'RECEITA', 'realizado' => 1000.0]],
    '2.3' => [['codigo' => '2.3.2', 'reduzida' => '3010', 'nome' => 'ICMS S/ VENDA SOCIO', 'realizado' => -100.0],
        ['codigo' => '2.3.7', 'reduzida' => '3018', 'nome' => 'COFINS S/ VENDA NAO SOCIO', 'realizado' => -70.0],
        ['codigo' => '2.3.8', 'reduzida' => '3019', 'nome' => 'PIS S/ VENDA NAO SOCIO', 'realizado' => -15.0]]];
$trib = ['colunas' => ['realizado' => ['como_e' => ['pis_cofins' => 85.0]]]];
$f = Projecao::nivel3FormatoNovo($base, $trib);
prova('formato de 2027: PIS e COFINS saem do Imposto s/ Vendas e vão para a CBS, no sinal dela',
    count($f['2.3']) === 1 && count($f['CBS']) === 2 && abs(array_sum(array_column($f['CBS'], 'realizado')) - 85) < 0.01, json_encode($f['CBS']));
prova('e a Receita de Vendas ganha a linha que tira o PIS/COFINS (soma 915, como a linha na tela)',
    abs(array_sum(array_column($f['1.1'], 'realizado')) - 915) < 0.01);

// ── 4. A conta reduzida das oportunidades ────────────────────────────────────
$red = Oportunidades::reduzidaDaConta('3.21.01.01.01.01');
prova('juros ativos sócio (3.21.01.01.01.01) é a 3153 JUROS ATIVOS SOCIO, como no Qlik',
    $red && $red['reduzida'] === '3153' && $red['nome'] === 'JUROS ATIVOS SOCIO', json_encode($red));
$q = Oportunidades::quadro($leite, 2026, $corte);
$itens = array_filter($q['linhas'], static fn ($l) => $l['tipo'] === 'ITEM');
prova('os itens do quadro trazem a conta reduzida (PDD: 3141)',
    (bool)array_filter($itens, static fn ($l) => ($l['reduzida'] ?? null) === '3141'), json_encode(array_column($itens, 'reduzida')));
$lanc = array_merge(...array_map(static fn ($l) => $l['lancamentos']['itens'], $q['linhas']));
prova('e cada lançamento também (a reduzida ao lado da conta contábil)',
    $lanc && !array_filter($lanc, static fn ($i) => ($i['reduzida'] ?? '') === ''), (string)count($lanc));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
if ($bad) {
    echo "\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

<?php

/**
 * Provas do planejamento de PESSOAS E BENEFÍCIOS por negócio (pedido do
 * cliente, 2026-11-18): a conta, a carga do quadro e a escolha da base.
 *
 * Ficam fora da bateria do navegador porque o que medem não tem tela: a conta
 * é pura, e a carga e a base ("o último mês do ano atual") só se exercitam
 * semeando linhas direto no banco.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/pessoas.php
 *
 * Os números da conta são de MÃO, não do código: 406 colaboradores
 * (Supermercados, nov/2026) × R$ 500 × 12 = R$ 2.436.000 — e é isso que se
 * confere, porque uma prova que recalculasse com a mesma fórmula passaria sem
 * provar nada. Monta o próprio negócio e o apaga no fim.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/CargaPessoas.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaPessoas;
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

// ── 1. A conta, com os números do cliente ───────────────────────────────────
$base406 = ['ano' => 2026, 'mes' => 11, 'colaboradores' => 406];
$r = Projecao::pessoas($base406, []);
prova('alimentação: 406 × R$ 500 × 12 = R$ 2.436.000', perto($r['anual']['alimentacao'], 2436000), (string)$r['anual']['alimentacao']);
prova('abono de assiduidade: 406 × R$ 200 × 12 = R$ 974.400', perto($r['anual']['assiduidade'], 974400), (string)$r['anual']['assiduidade']);
prova('abono de resultado: 406 × R$ 500 × 12 = R$ 2.436.000', perto($r['anual']['resultado'], 2436000), (string)$r['anual']['resultado']);
prova('total = R$ 5.846.400 e por mês R$ 487.200', perto($r['anual']['total'], 5846400) && perto($r['mensal']['total'], 487200),
    $r['anual']['total'] . ' / ' . $r['mensal']['total']);
prova('sem nunca atingir o resultado: só alimentação + assiduidade (R$ 3.410.400)', perto($r['sem_resultado_anual'], 3410400), (string)$r['sem_resultado_anual']);
prova('o teto é o orçamento cheio (R$ 5.846.400)', perto($r['maximo_anual'], 5846400));

// Previsão: nem todos cumprem a assiduidade, nem todo mês dá resultado.
$r = Projecao::pessoas($base406, ['PCT_ASSIDUIDADE' => 80, 'PCT_RESULTADO' => 50]);
prova('80% cumprem a assiduidade: R$ 779.520 no ano', perto($r['anual']['assiduidade'], 779520), (string)$r['anual']['assiduidade']);
prova('resultado em 50% dos meses: R$ 1.218.000 no ano', perto($r['anual']['resultado'], 1218000), (string)$r['anual']['resultado']);
prova('e o piso e o teto NÃO mudam com a previsão', perto($r['sem_resultado_anual'], 2436000 + 779520) && perto($r['maximo_anual'], 5846400),
    $r['sem_resultado_anual'] . ' / ' . $r['maximo_anual']);

// O cliente digita: contratação e valores próprios.
$r = Projecao::pessoas($base406, ['COLABORADORES' => 400, 'ALIMENTACAO' => 550]);
prova('colaboradores digitados valem no lugar do quadro (400 × R$ 550 × 12 = R$ 2.640.000)',
    perto($r['anual']['alimentacao'], 2640000) && $r['colaboradores'] === 400.0 && $r['colaboradores_base'] === 406.0,
    json_encode([$r['colaboradores'], $r['colaboradores_base'], $r['anual']['alimentacao']]));

// Sem quadro: sem número digitado não há o que calcular; com ele, há.
prova('sem quadro e sem número digitado a previsão não existe (null, e não zero)', Projecao::pessoas(null, []) === null);
$r = Projecao::pessoas(null, ['COLABORADORES' => 10]);
prova('sem quadro, o número digitado basta', $r !== null && perto($r['anual']['alimentacao'], 60000), (string)($r['anual']['alimentacao'] ?? 'null'));

// A divisão entre negócios: 10 pessoas, 3 num negócio e 7 no outro. O total
// pago é o de 10 pessoas — rateio por cima dividiria duas vezes.
$a = Projecao::pessoas(['ano' => 2026, 'mes' => 11, 'colaboradores' => 3], []);
$b = Projecao::pessoas(['ano' => 2026, 'mes' => 11, 'colaboradores' => 7], []);
$dez = Projecao::pessoas(['ano' => 2026, 'mes' => 11, 'colaboradores' => 10], []);
prova('quem divide o tempo é pago pelos dois, e a soma das partes é o custo da pessoa inteira',
    perto($a['anual']['total'] + $b['anual']['total'], $dez['anual']['total']),
    "{$a['anual']['total']} + {$b['anual']['total']} = " . ($a['anual']['total'] + $b['anual']['total']) . " vs {$dez['anual']['total']}");

// ── 2. As âncoras do Qlik (não saem da conta, saem do sistema de origem) ─────
$pdo = Database::conn();
$nov = $pdo->query(
    "SELECT n.cod_negocio, p.colaboradores FROM negocio_pessoas_mes p
       JOIN negocio n ON n.id = p.negocio_id WHERE p.ano = 2026 AND p.mes = 11"
)->fetchAll(PDO::FETCH_KEY_PAIR);
prova('âncora do Qlik: Supermercados tem 406 em novembro', (int)($nov['9'] ?? 0) === 406, (string)($nov['9'] ?? 'ausente'));
prova('âncora do Qlik: Agropecuárias tem 548 em novembro', (int)($nov['8'] ?? 0) === 548, (string)($nov['8'] ?? 'ausente'));
prova('âncora do Qlik: os 10 negócios com quadro somam 1.350 em novembro (1.564 com as áreas de apoio)',
    array_sum($nov) === 1350 && count($nov) === 10, array_sum($nov) . ' em ' . count($nov));

// ── 3. A carga: confere antes de escrever, e sobrescreve ─────────────────────
$cod = 'QPE' . substr((string)microtime(true), -5);
$pdo->prepare('INSERT INTO negocio (cod_negocio, nome, ativo) VALUES (?, ?, 0)')
    ->execute([$cod, "Negócio de prova {$cod}"]);
$negocioId = (int)$pdo->lastInsertId();
$conta = static fn () => (int)Database::conn()->query("SELECT COUNT(*) FROM negocio_pessoas_mes WHERE negocio_id = {$negocioId}")->fetchColumn();

$carga = ['ano' => 2026, 'meses' => [10, 11], 'valores' => [$cod => [50, 60]]];
$res = CargaPessoas::aplicar($pdo, $carga);
prova('a carga grava os meses do negócio', $res['valores'] === 2 && $conta() === 2, json_encode($res));
CargaPessoas::aplicar($pdo, $carga);
prova('reaplicar a mesma carga não duplica', $conta() === 2, (string)$conta());
CargaPessoas::aplicar($pdo, ['ano' => 2026, 'meses' => [11], 'valores' => [$cod => [65]]]);
$v11 = (int)$pdo->query("SELECT colaboradores FROM negocio_pessoas_mes WHERE negocio_id = {$negocioId} AND ano = 2026 AND mes = 11")->fetchColumn();
prova('a carga seguinte SOBRESCREVE o mês (número de fora não se empilha)', $v11 === 65, (string)$v11);

$recusa = static function (array $d) use ($pdo): ?string {
    try {
        CargaPessoas::aplicar($pdo, $d);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};
$m = $recusa(['ano' => 2026, 'meses' => [10, 11], 'valores' => [$cod => [1, 2, 3]]]);
prova('série com tamanho errado é recusada', $m !== null && str_contains($m, 'tamanho errado'), (string)$m);
$m = $recusa(['ano' => 2026, 'meses' => [11], 'valores' => [$cod => [-4]]]);
prova('colaboradores negativos são recusados', $m !== null && str_contains($m, 'inválido'), (string)$m);
$m = $recusa(['ano' => 2026, 'meses' => [11], 'valores' => [$cod => ['12']]]);
prova('texto no lugar do número é recusado', $m !== null && str_contains($m, 'inválido'), (string)$m);
$m = $recusa(['ano' => 2026, 'meses' => [13], 'valores' => [$cod => [1]]]);
prova('mês 13 é recusado', $m !== null && str_contains($m, 'mês inválido'), (string)$m);
$v11 = (int)$pdo->query("SELECT colaboradores FROM negocio_pessoas_mes WHERE negocio_id = {$negocioId} AND ano = 2026 AND mes = 11")->fetchColumn();
prova('carga recusada não deixa rastro (o mês continua com o valor anterior)', $v11 === 65 && $conta() === 2, "{$v11} / {$conta()}");

// ── 4. A base é o ÚLTIMO mês carregado do ano atual ──────────────────────────
$ctrl = new App\Controllers\ProjecaoController();
$ler = new ReflectionMethod($ctrl, 'pessoasDoNegocio');
$ler->setAccessible(true);
$p = $ler->invoke($ctrl, $negocioId, 2026, [2027]);
prova('a base é o último mês carregado (novembro: 65), e não o mais antigo',
    $p['base']['mes'] === 11 && $p['base']['colaboradores'] === 65, json_encode($p['base']));
// Dezembro chega, MENOR que novembro: a base tem de ser dezembro, e não o maior valor.
CargaPessoas::aplicar($pdo, ['ano' => 2026, 'meses' => [12], 'valores' => [$cod => [58]]]);
$p = $ler->invoke($ctrl, $negocioId, 2026, [2027]);
prova('quando dezembro chega a base anda sozinha, mesmo que o número caia (58 < 65)',
    $p['base']['mes'] === 12 && $p['base']['colaboradores'] === 58, json_encode($p['base']));
prova('e a previsão de 2027 usa a base nova: 58 × R$ 500 × 12 = R$ 348.000 de alimentação',
    perto($p['por_ano']['2027']['anual']['alimentacao'], 348000), (string)$p['por_ano']['2027']['anual']['alimentacao']);
// Quadro de outro ano não vira base do ano atual.
$pdo->prepare('INSERT INTO negocio_pessoas_mes (negocio_id, ano, mes, colaboradores) VALUES (?, 2025, 12, 999)')->execute([$negocioId]);
$p = $ler->invoke($ctrl, $negocioId, 2026, [2027]);
prova('mês de outro ano não é base do ano atual', $p['base']['ano'] === 2026 && $p['base']['colaboradores'] === 58, json_encode($p['base']));

// O que o cliente digita entra na previsão, só no ano certo.
$pdo->prepare('INSERT INTO negocio_beneficio (negocio_id, ano, chave, valor) VALUES (?, 2027, ?, ?), (?, 2028, ?, ?)')
    ->execute([$negocioId, 'ALIMENTACAO', 600, $negocioId, 'ALIMENTACAO', 700]);
$p = $ler->invoke($ctrl, $negocioId, 2026, [2027, 2028]);
// 2027 tem DIRETRIZ (Diretrizes para o Planejamento 2027): o vale é R$ 500 para
// todos e o digitado ali, mesmo gravado, não vale. Em 2028, sem diretriz, vale.
prova('em 2027 manda a diretriz (R$ 500, apesar dos 600 gravados); em 2028 vale o digitado (700)',
    perto($p['por_ano']['2027']['anual']['alimentacao'], 58 * 500 * 12) && perto($p['por_ano']['2028']['anual']['alimentacao'], 58 * 700 * 12),
    $p['por_ano']['2027']['anual']['alimentacao'] . ' / ' . $p['por_ano']['2028']['anual']['alimentacao']);
prova('e o digitado de 2027 continua guardado (a diretriz não apaga nada)',
    perto($p['digitados']['2027']['ALIMENTACAO'] ?? 0, 600));

// ── Limpeza ─────────────────────────────────────────────────────────────────
$pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([$negocioId]);
$resto = (int)$pdo->query("SELECT COUNT(*) FROM negocio_pessoas_mes WHERE negocio_id = {$negocioId}")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM negocio_beneficio WHERE negocio_id = {$negocioId}")->fetchColumn();
prova('a massa de prova foi apagada', $resto === 0, (string)$resto);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
if ($bad) {
    echo "\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

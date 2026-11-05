<?php

/**
 * Provas do importador da DRE (`App\Services\CargaDre`).
 *
 * Ficam fora da bateria do navegador porque o que elas medem não tem tela: a
 * carga é o ponto em que um número errado entra no sistema, e a partir dali
 * ele fica plausível. Quatro recusas e uma idempotência.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/carga_dre.php
 *
 * O banco é o de desenvolvimento: as provas de recusa não escrevem nada (a
 * validação roda antes da primeira escrita) e a de idempotência reaplica a
 * carga oficial, que por definição grava o que já estava lá.
 */

require_once __DIR__ . '/../app/Services/CargaDre.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'planejamento'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'app', getenv('DB_PASS') ?: 'app', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

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

/** Roda a carga e devolve a mensagem do erro, ou null se passou. */
function recusa(PDO $pdo, array $dados): ?string
{
    try {
        App\Services\CargaDre::aplicar($pdo, $dados);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$base = require __DIR__ . '/../database/dre_2026_fechamento_09.php';

// ── 1. Mês sem rateio é mês ABERTO ──────────────────────────────────────────
// A regra que a planilha do cliente não tinha: outubro trazia R$ 373,2 mi de
// receita e Rateios = 0. Declará-lo fechado tem de ser recusado AQUI, e não
// descoberto três reuniões depois.
$semRateio = $base;
$semRateio['valores'] = [];
foreach ($base['valores'] as $chave => $par) {
    [$neg, $conta] = explode('|', $chave, 2);
    if ($conta === '14') {
        // zera o rateio de MARÇO em todos os negócios
        $n = explode(';', $par[0]);
        $n[2] = '0';
        $par[0] = implode(';', $n);
    }
    $semRateio['valores'][$chave] = $par;
}
$msg = recusa($pdo, $semRateio);
prova('mês declarado fechado sem rateio é recusado',
    $msg !== null && str_contains($msg, 'rateio'), (string)$msg);
prova('e a recusa diz QUAL mês',
    $msg !== null && str_contains($msg, 'mês 3'), (string)$msg);

// ── 2. Conta fora da estrutura ──────────────────────────────────────────────
// Conta nova no Qlik com estrutura velha aqui: ignorar a linha faria a DRE
// fechar errado em silêncio, que é o pior modo de falha deste sistema.
$contaIntrusa = $base;
$contaIntrusa['valores']['2|99.9'] = ['1;1;1;1;1;1;1;1;1', '1;1;1;1;1;1;1;1;1;1;1;1'];
$msg = recusa($pdo, $contaIntrusa);
prova('conta fora da estrutura é recusada',
    $msg !== null && str_contains($msg, '99.9'), (string)$msg);

// ── 3. Série de tamanho errado ──────────────────────────────────────────────
// Um ';' a mais desalinha o ano inteiro daquele negócio, calado.
$curta = $base;
$curta['valores']['2|1'] = ['1;2;3', $base['valores']['2|1'][1]];
$msg = recusa($pdo, $curta);
prova('série com número de meses errado é recusada',
    $msg !== null && str_contains($msg, 'tamanho errado'), (string)$msg);

// ── 4. Valor não numérico ───────────────────────────────────────────────────
$texto = $base;
$serie = explode(';', $base['valores']['2|1'][0]);
$serie[4] = 'n/d';                       // o que vem de um CSV com célula vazia
$texto['valores']['2|1'] = [implode(';', $serie), $base['valores']['2|1'][1]];
$msg = recusa($pdo, $texto);
prova('valor não numérico é recusado',
    $msg !== null && str_contains($msg, 'não numérico'), (string)$msg);

// ── 5. Nada do que foi recusado chegou ao banco ─────────────────────────────
$intrusa = (int)$pdo->query("SELECT COUNT(*) FROM dre_conta WHERE codigo = '99.9'")->fetchColumn();
prova('carga recusada não deixa rastro no banco', $intrusa === 0, "conta 99.9: {$intrusa}");

// ── 6. Reaplicar sobrescreve, não duplica ───────────────────────────────────
// É o oposto da carga de conteúdo: número que veio de fora se reescreve.
$antes = $pdo->query('SELECT COUNT(*) FROM dre_valor')->fetchColumn();
$r = App\Services\CargaDre::aplicar($pdo, $base);
$depois = $pdo->query('SELECT COUNT(*) FROM dre_valor')->fetchColumn();
prova('reaplicar a mesma carga não duplica linha', $antes === $depois, "{$antes} → {$depois}");
prova('e grava os 11 negócios', $r['negocios'] === 11, (string)$r['negocios']);

// ── 7. O corte do fechamento ficou gravado ──────────────────────────────────
$f = $pdo->query('SELECT * FROM dre_fechamento WHERE ano = 2026')->fetch(PDO::FETCH_ASSOC);
prova('o corte do fechamento fica no banco, não no código',
    $f && (int)$f['mes_fechado_ate'] === 9, json_encode($f));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

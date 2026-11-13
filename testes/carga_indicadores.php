<?php

/**
 * Provas da carga de METAS DE INDICADOR (`CargaConteudo::aplicarIndicadores`).
 *
 * Ficam fora da bateria do navegador pela mesma razão das provas da DRE: o que
 * elas medem não tem tela. A carga é o ponto em que uma meta errada entra no
 * sistema, e a partir dali ela fica plausível — um número mil vezes maior num
 * indicador de "R$ mil" não acende nada em lugar nenhum.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/carga_indicadores.php
 *
 * Cada prova monta o seu próprio planejamento de teste e o apaga no fim (a FK
 * de `indicador` e `indicador_valor` é ON DELETE CASCADE). Nada do banco de
 * desenvolvimento é tocado fora dele.
 */

require_once __DIR__ . '/../app/Services/CargaConteudo.php';

use App\Services\CargaConteudo;

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

/**
 * Planejamento descartável, com os indicadores pedidos.
 *
 * O NEGÓCIO também é descartável, e não é zelo exagerado: `planejamento` tem
 * chave única (ciclo, escopo, negócio), então reusar um negócio do cadastro
 * faria a segunda prova colidir com o planejamento que a primeira criou — ou,
 * pior, escrever dentro de um planejamento de verdade.
 */
function montar(PDO $pdo, array $indicadores): array
{
    $ciclo = (int)$pdo->query('SELECT id FROM ciclo ORDER BY id LIMIT 1')->fetchColumn();
    $cod = 'QA' . substr((string)microtime(true), -6);
    $pdo->prepare('INSERT INTO negocio (cod_negocio, nome, ativo) VALUES (?, ?, 0)')
        ->execute([$cod, "Negócio de prova {$cod}"]);
    $neg = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'NEGOCIO', ?)")
        ->execute([$ciclo, $neg]);
    $plano = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO indicador (planejamento_id, nome, unidade) VALUES (?, ?, ?)');
    $ids = [];
    foreach ($indicadores as $nome => $unidade) {
        $ins->execute([$plano, $nome, $unidade]);
        $ids[$nome] = (int)$pdo->lastInsertId();
    }
    return [$plano, $ids, $neg];
}

/** Apaga o planejamento de prova e o negócio descartável que o segura. */
function limpar(PDO $pdo, int $plano, int $neg): void
{
    $pdo->prepare('DELETE FROM planejamento WHERE id = ?')->execute([$plano]);
    $pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([$neg]);
}

function metas(PDO $pdo, int $indicadorId, int $ano = 2027): array
{
    $st = $pdo->prepare(
        "SELECT mes, valor, versao_meta FROM indicador_valor
          WHERE indicador_id = ? AND ano = ? AND tipo = 'META' ORDER BY mes"
    );
    $st->execute([$indicadorId, $ano]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['mes']] = ['valor' => (float)$r['valor'], 'versao' => (int)$r['versao_meta']];
    }
    return $out;
}

$doze = range(100, 1200, 100); // 100, 200, ... 1200

echo "\nCarga de metas de indicador\n";

// ── 1. Preenche os doze meses de quem existe ─────────────────────────────────
[$plano, $ids, $neg] = montar($pdo, ['Faturamento Teste' => 'Reais']);
$gravados = CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027,
    'itens' => ['Faturamento Teste' => $doze],
], $plano);
$m = metas($pdo, $ids['Faturamento Teste']);
prova('grava os doze meses do indicador que existe',
    $gravados === 12 && count($m) === 12 && $m[1]['valor'] === 100.0 && $m[12]['valor'] === 1200.0,
    "gravados={$gravados} meses=" . count($m));
// O ANO não é gravado: a tela deriva da soma dos meses, e um anual digitado
// disputaria com o calculado.
prova('não grava o ano (mes 0), que a tela deriva dos meses', !isset($m[0]));
limpar($pdo, $plano, $neg);

// ── 2. Não cria indicador que não existe, e diz qual ─────────────────────────
// É a diferença desta carga para todas as outras. Um nome que diverge por uma
// letra criaria um SEGUNDO indicador parecido com o que já está na tela —
// duplicata que só se descobre olhando a lista inteira.
[$plano, $ids, $neg] = montar($pdo, ['Ração Suinos' => 'Ton']);
CargaConteudo::$indicadoresNaoAchados = [];
$gravados = CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027,
    'itens' => ['Ração Suinos' => $doze, 'Indicador Que Não Existe' => $doze],
], $plano);
$criados = (int)$pdo->query("SELECT COUNT(*) FROM indicador WHERE planejamento_id = {$plano}")->fetchColumn();
prova('não cria indicador ausente — grava só o que achou',
    $gravados === 12 && $criados === 1, "gravados={$gravados} indicadores={$criados}");
prova('e REPORTA pelo nome o que não achou',
    CargaConteudo::$indicadoresNaoAchados === ['Indicador Que Não Existe'],
    implode(' · ', CargaConteudo::$indicadoresNaoAchados));
limpar($pdo, $plano, $neg);

// ── 3. O nome casa por forma normalizada ─────────────────────────────────────
// Acento, caixa e espaço repetido não contam: é aí que planilha e tela
// divergem de verdade ("Faturamento Veterinaria" × "Faturamento Veterinária").
[$plano, $ids, $neg] = montar($pdo, ['Faturamento Veterinária' => 'Reais']);
CargaConteudo::$indicadoresNaoAchados = [];
$gravados = CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027,
    'itens' => ['faturamento  VETERINARIA' => $doze],
], $plano);
prova('casa o nome ignorando acento, caixa e espaço repetido',
    $gravados === 12 && !CargaConteudo::$indicadoresNaoAchados, "gravados={$gravados}");
limpar($pdo, $plano, $neg);

// ── 4. SOBRESCREVE o que já estava lançado ───────────────────────────────────
// Pedido do cliente (2026-11-13), e o contrário de todas as outras cargas.
[$plano, $ids, $neg] = montar($pdo, ['Faturamento Teste' => 'Reais']);
$id = $ids['Faturamento Teste'];
$pdo->prepare(
    "INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor)
     VALUES (?, 2027, 1, 'META', 1, 999999)"
)->execute([$id]);
CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027, 'itens' => ['Faturamento Teste' => $doze],
], $plano);
$m = metas($pdo, $id);
prova('sobrescreve o mês que já estava lançado',
    $m[1]['valor'] === 100.0, 'jan=' . $m[1]['valor']);
prova('e não duplica a linha do mês sobrescrito', count($m) === 12, 'linhas=' . count($m));
limpar($pdo, $plano, $neg);

// ── 5. Escreve na versão CORRENTE da meta, não numa nova ─────────────────────
// A carga preenche a revisão que está aberta. Abrir versão nova empurraria
// para o histórico uma meta que ninguém chegou a usar.
[$plano, $ids, $neg] = montar($pdo, ['Faturamento Teste' => 'Reais']);
$id = $ids['Faturamento Teste'];
$pdo->prepare(
    "INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor)
     VALUES (?, 2027, 1, 'META', 3, 555)"
)->execute([$id]);
CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027, 'itens' => ['Faturamento Teste' => $doze],
], $plano);
$m = metas($pdo, $id);
prova('escreve na versão corrente da meta (3), sem abrir a 4',
    $m[1]['versao'] === 3 && $m[12]['versao'] === 3 && $m[1]['valor'] === 100.0,
    "versao={$m[1]['versao']} jan={$m[1]['valor']}");
limpar($pdo, $plano, $neg);

// ── 6. Não toca em indicador fora da carga ───────────────────────────────────
// O caso real: "Ferragens Marca Coperdia" tem meta lançada na tela e o bloco
// dela está VAZIO na planilha. Apagá-la seria perder trabalho de quem digitou.
[$plano, $ids, $neg] = montar($pdo, ['Faturamento Teste' => 'Reais', 'Fora da Carga' => 'Unidades']);
$pdo->prepare(
    "INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor)
     VALUES (?, 2027, 5, 'META', 1, 50000)"
)->execute([$ids['Fora da Carga']]);
CargaConteudo::aplicar($pdo, [
    'destino' => 'INDICADOR', 'ano' => 2027, 'itens' => ['Faturamento Teste' => $doze],
], $plano);
$fora = metas($pdo, $ids['Fora da Carga']);
prova('indicador fora da carga fica intacto',
    count($fora) === 1 && $fora[5]['valor'] === 50000.0, 'linhas=' . count($fora));
limpar($pdo, $plano, $neg);

echo "\n✓ {$ok} passaram\n";
if ($bad) {
    echo "✗ " . count($bad) . " FALHARAM\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

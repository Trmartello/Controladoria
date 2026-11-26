<?php

/**
 * Prova de que a ATUALIZAÇÃO do sistema não mexe no que os negócios lançaram
 * (regra do cliente, 2026-11-26: "as alterações não poderiam impactar nos números
 * digitados pelos negócios, independente se o sinal está errado").
 *
 * O que roda a cada publicação na Railway é o `database/migrate.php` (entrypoint).
 * Esta prova tira a impressão digital das tabelas de lançamento, grava dois
 * lançamentos-sentinela — um deles com o SINAL TROCADO de propósito (custo
 * positivo, como os Custos do Leite em produção) —, roda o migrate DUAS vezes e
 * confere que nada mudou, nem uma casa decimal, nem o sinal. Apaga as sentinelas
 * no fim, passe ou falhe.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/lancamentos_intocados.php
 *
 * Se esta prova falhar, a mudança altera dado lançado: não mescle sem o cliente
 * aprovar explicitamente o que muda, conta por conta.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

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

// As tabelas onde mora o que o negócio (ou o ADMIN, no desafio) lançou, mais as
// cargas que alimentam a DRE dele. Tabela que não existe nesta base é pulada.
const TABELAS = [
    'projecao', 'projecao_valor', 'projecao_premissa', 'projecao_cenario',
    'negocio_premissa', 'negocio_desafio', 'negocio_desafio_valor', 'negocio_desafio_ajuste',
    'negocio_item_avaliacao', 'negocio_pessoas_mes', 'negocio_beneficio', 'negocio_folha',
    'negocio_depreciacao', 'negocio_tributo', 'negocio_nao_recorrente',
    'negocio_nao_recorrente_mes', 'desafio_ano',
];

$digital = static function () use ($pdo): array {
    $saida = [];
    foreach (TABELAS as $t) {
        $existe = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
        if (!$existe) {
            continue;
        }
        $n = count($pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll());
        $ordem = implode(',', range(1, $n));
        $linhas = $pdo->query("SELECT * FROM `{$t}` ORDER BY {$ordem}")->fetchAll(PDO::FETCH_NUM);
        $saida[$t] = [count($linhas), md5(json_encode($linhas))];
    }
    return $saida;
};

$migrar = static function (): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../database/migrate.php') . ' 2>&1';
    exec($cmd, $saida, $codigo);
    return [$codigo, implode("\n", array_slice($saida, -3))];
};

// Sentinelas numa filial que não existe (999999), para não cruzar com lançamento
// de verdade: um custo POSITIVO (o sinal trocado) e um percentual com quatro casas.
$projecao = (int)$pdo->query('SELECT id FROM projecao ORDER BY id LIMIT 1')->fetchColumn();
$conta = static function (string $codigo) use ($pdo): int {
    $st = $pdo->prepare('SELECT id FROM dre_conta WHERE codigo = ?');
    $st->execute([$codigo]);
    return (int)$st->fetchColumn();
};
$custo = $conta('4.1');
$receita = $conta('1.1');
if (!$projecao || !$custo || !$receita) {
    echo "  (pulada: base sem projeção ou sem as contas 4.1 e 1.1)\n";
    exit(0);
}
const FILIAL_SENTINELA = 999999;

$limpar = static function () use ($pdo, $projecao): void {
    $pdo->prepare('DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = ?')
        ->execute([$projecao, FILIAL_SENTINELA]);
};

try {
    $limpar();
    $ins = $pdo->prepare('INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor, percentual)
        VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$projecao, FILIAL_SENTINELA, $custo, 'VALOR', '300000000.00', null]);
    $ins->execute([$projecao, FILIAL_SENTINELA, $receita, 'PERCENTUAL', null, '12.3456']);

    $antes = $digital();
    $sentinelasAntes = $pdo->query('SELECT conta_id, entrada, valor, percentual FROM projecao_valor
        WHERE filial_id = ' . FILIAL_SENTINELA . ' ORDER BY conta_id')->fetchAll(PDO::FETCH_ASSOC);

    [$c1, $s1] = $migrar();
    [$c2, $s2] = $migrar();
    prova('o migrate (o que roda a cada publicação) termina sem erro, duas vezes seguidas',
        $c1 === 0 && $c2 === 0, $c1 === 0 && $c2 === 0 ? '' : "saída {$c1}/{$c2}: {$s1} | {$s2}");

    $depois = $digital();
    $mudaram = [];
    foreach ($antes as $t => [$n, $h]) {
        if (($depois[$t] ?? null) !== [$n, $h]) {
            $mudaram[] = "{$t} ({$n} → " . ($depois[$t][0] ?? '?') . ' linhas)';
        }
    }
    prova('nenhuma tabela de lançamento mudou com a atualização (' . count($antes) . ' tabelas conferidas)',
        !$mudaram, implode('; ', $mudaram));

    $sentinelasDepois = $pdo->query('SELECT conta_id, entrada, valor, percentual FROM projecao_valor
        WHERE filial_id = ' . FILIAL_SENTINELA . ' ORDER BY conta_id')->fetchAll(PDO::FETCH_ASSOC);
    prova('o lançamento com o sinal trocado (custo +300.000.000,00) continua exatamente como foi digitado',
        $sentinelasDepois === $sentinelasAntes && count($sentinelasDepois) === 2,
        json_encode($sentinelasDepois));
} finally {
    $limpar();
}

$sobrou = (int)$pdo->query('SELECT COUNT(*) FROM projecao_valor WHERE filial_id = ' . FILIAL_SENTINELA)->fetchColumn();
prova('as sentinelas foram apagadas', $sobrou === 0, (string)$sobrou);

echo "\n" . ($bad ? '✗ ' . count($bad) . " FALHARAM\n" : "✓ {$ok} passaram\n");
exit($bad ? 1 : 0);

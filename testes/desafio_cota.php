<?php

/**
 * Provas do desafio dos POSTOS DE RESFRIAMENTO pela cota Aurora do Leite
 * (pedido do cliente, 2026-11-23): faturamento = meta "Cota Aurora" do Leite ×
 * R$ 0,055 por litro (era 0,06 até 2026-11-23), lida na hora do banco.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/desafio_cota.php
 *
 * Os números são de MÃO: 12 meses de 8.950 (mil litros, como a tela mostra) =
 * 107.400.000 litros × R$ 0,055 = R$ 5.907.000. Cria a meta no planejamento do
 * Leite e apaga no fim; o desafio carregado (`negocio_desafio`) não é tocado.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
spl_autoload_register(static function (string $classe): void {
    $arq = __DIR__ . '/../' . str_replace(['App\\', '\\'], ['app/', '/'], $classe) . '.php';
    if (is_file($arq)) {
        require_once $arq;
    }
});

use App\Core\Database;
use App\Services\DesafioCota;

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
$ano = 2027;
$plan = Database::um("SELECT p.id FROM planejamento p JOIN negocio n ON n.id = p.negocio_id
    JOIN ciclo c ON c.id = p.ciclo_id WHERE n.cod_negocio = '4' AND ? BETWEEN c.ano_base AND c.ano_fim LIMIT 1", [$ano]);
$postos = (int)(Database::um("SELECT id FROM negocio WHERE cod_negocio = '12'")['id'] ?? 0);
$carregado = Database::um('SELECT * FROM negocio_desafio WHERE negocio_id = ? AND ano = ?', [$postos, $ano]);
prova('há planejamento do Leite no ciclo e desafio carregado dos Postos', $plan && $carregado);
if (!$plan || !$carregado) {
    echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
    exit(1);
}

$ja = Database::um("SELECT id FROM indicador WHERE planejamento_id = ? AND LOWER(nome) LIKE 'cota aurora%'", [(int)$plan['id']]);
prova('o banco de provas não tem cota Aurora própria (a prova cria a sua)', !$ja);

$ctrl = new App\Controllers\ProjecaoController();
$desafioDoAno = new ReflectionMethod($ctrl, 'desafioDoAno');
$desafioDoAno->setAccessible(true);

// Sem a cota: o desafio carregado, inalterado.
$d = $desafioDoAno->invoke($ctrl, $ano);
prova('sem a meta, os Postos ficam no desafio carregado', abs($d['por_negocio'][$postos]['receita_bruta'] - (float)$carregado['receita_bruta']) < 0.01);

// Com a cota: 12 meses de 8.950 (mil litros).
$pdo->prepare("INSERT INTO indicador (planejamento_id, nome, unidade, sentido, agregacao, metrica_ancora, ordem)
    VALUES (?, 'Cota Aurora', 'Litros', 'MAIOR_MELHOR', 'SOMA', 1, 999)")->execute([(int)$plan['id']]);
$ind = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor) VALUES (?, ?, ?, 'META', 1, ?)");
for ($m = 1; $m <= 12; $m++) {
    $ins->execute([$ind, $ano, $m, 8950]);
}
try {
    $c = DesafioCota::cotaDoLeite($ano);
    prova('a cota do ano é a soma dos meses, lida em mil litros: 107.400.000 litros',
        $c && abs($c['litros'] - 107400000) < 0.5 && $c['em_mil_litros'] && $c['meses'] === 12, json_encode($c));
    $d = $desafioDoAno->invoke($ctrl, $ano);
    $p = $d['por_negocio'][$postos];
    $razao = (float)$carregado['receita_liquida'] / (float)$carregado['receita_bruta'];
    $margem = (float)$carregado['resultado'] / (float)$carregado['receita_liquida'];
    prova('faturamento dos Postos = 107.400.000 × R$ 0,055 = R$ 5.907.000', abs($p['receita_bruta'] - 5907000) < 0.01, (string)$p['receita_bruta']);
    prova('receita líquida na relação ROL/ROB do desafio carregado', abs($p['receita_liquida'] - 5907000 * $razao) < 0.01);
    prova('resultado na margem do desafio carregado (preservar margem)', abs($p['resultado'] - 5907000 * $razao * $margem) < 0.01);
    prova('a nota diz de onde veio', str_contains($p['nota'], 'cota Aurora') && str_contains($p['nota'], '107.400.000'), $p['nota']);
    $outro = (int)(Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'] ?? 0);
    $leite = Database::um('SELECT * FROM negocio_desafio WHERE negocio_id = ? AND ano = ?', [$outro, $ano]);
    prova('os demais negócios não mudam (Leite)', !$leite || abs($d['por_negocio'][$outro]['receita_bruta'] - (float)$leite['receita_bruta']) < 0.01);

    // Mudou a cota no site: o desafio acompanha, sem carga.
    $pdo->prepare("UPDATE indicador_valor SET valor = 10000 WHERE indicador_id = ? AND mes = 12")->execute([$ind]);
    $p = $desafioDoAno->invoke($ctrl, $ano)['por_negocio'][$postos];
    prova('mudar a meta de dezembro (10.000) muda o desafio na hora: +1.050 mil litros × 0,055 = +R$ 57.750',
        abs($p['receita_bruta'] - 5964750) < 0.01, (string)$p['receita_bruta']);

    // Meta lançada em litros (e não em mil litros): lida como está.
    $pdo->prepare('DELETE FROM indicador_valor WHERE indicador_id = ?')->execute([$ind]);
    $pdo->prepare("INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor) VALUES (?, ?, 0, 'META', 1, 107400000)")
        ->execute([$ind, $ano]);
    $c = DesafioCota::cotaDoLeite($ano);
    prova('meta anual lançada em litros (107.400.000) é lida como litros, sem multiplicar',
        $c && abs($c['litros'] - 107400000) < 0.5 && !$c['em_mil_litros'], json_encode($c));
} finally {
    $pdo->prepare('DELETE FROM indicador_valor WHERE indicador_id = ?')->execute([$ind]);
    $pdo->prepare('DELETE FROM indicador WHERE id = ?')->execute([$ind]);
}
prova('a meta de prova foi apagada', !Database::um('SELECT id FROM indicador WHERE id = ?', [$ind]));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
if ($bad) {
    echo "\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

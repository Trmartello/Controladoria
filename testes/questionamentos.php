<?php

/**
 * Provas dos QUESTIONAMENTOS das oportunidades (pedido do cliente, 2026-11-23):
 * o que aconteceu numa janela de 12 meses e não na outra (nov/24–out/25 ×
 * nov/25–out/26), conta reduzida a conta reduzida — "parou" (deveria continuar?)
 * e "começou" (vai continuar?).
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/questionamentos.php
 *
 * Os números são de MÃO, num negócio de prova criado e apagado aqui. Âncora nos
 * dados reais: o negócio 1 tem filiais de 2025 que não existem mais nele em 2026
 * (mudança de estrutura), então tem contas que "pararam".
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
spl_autoload_register(static function (string $classe): void {
    $arq = __DIR__ . '/../' . str_replace(['App\\', '\\'], ['app/', '/'], $classe) . '.php';
    if (is_file($arq)) {
        require_once $arq;
    }
});

use App\Core\Database;
use App\Services\Oportunidades;

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
$contas = array_column(Database::todos("SELECT codigo, linha FROM dre_reduzida WHERE linha IN ('8.5', '8.8', '9.2', '14.3') ORDER BY ordem"), 'codigo', 'linha');
prova('o catálogo do nível 3 tem contas das linhas 8.5, 8.8, 9.2 e 14.3', count($contas) === 4, json_encode($contas));

$cod = 'QQ' . substr((string)microtime(true), -5);
$pdo->prepare('INSERT INTO negocio (cod_negocio, nome, ativo) VALUES (?, ?, 0)')->execute([$cod, "Negócio de prova {$cod}"]);
$neg = (int)$pdo->lastInsertId();
// As janelas de 12 meses (desde 2026-11-23): a que termina em out/2026 e a que
// termina em out/2025. Os "2025" e "2026" abaixo são o ano do FIM da janela.
$ins = $pdo->prepare('INSERT INTO dre_reduzida_12m (negocio_id, filial_id, ano_fim, mes_fim, codigo, realizado) VALUES (?, 0, ?, 10, ?, ?)');
try {
    // 8.5: −50 mil em 2025, nada em 2026 → PAROU.
    $ins->execute([$neg, 2025, $contas['8.5'], -50000]);
    // 8.8: nada em 2025, −30 mil em 2026 → COMEÇOU.
    $ins->execute([$neg, 2026, $contas['8.8'], -30000]);
    // 9.2: 100 mil em 2025 e 9 mil em 2026 (menos de 10%) → PAROU; o inverso não.
    $ins->execute([$neg, 2025, $contas['9.2'], 100000]);
    $ins->execute([$neg, 2026, $contas['9.2'], 9000]);
    // 14.3: rateio corporativo que parou — fica de fora.
    $ins->execute([$neg, 2025, $contas['14.3'], -80000]);

    $q = Oportunidades::questionamentos($neg, 2026, 10);
    $parou = array_column($q['parou'], 'atual', 'codigo');
    $comecou = array_column($q['comecou'], 'anterior', 'codigo');
    prova('compara nov/24–out/25 × nov/25–out/26', $q && $q['periodo'] === ['de' => [2025, 11], 'ate' => [2026, 10]]
        && $q['periodo_anterior'] === ['de' => [2024, 11], 'ate' => [2025, 10]], json_encode([$q['periodo'] ?? null, $q['periodo_anterior'] ?? null]));
    prova('−50 mil em 2025 e nada em 2026: PAROU', array_key_exists($contas['8.5'], $parou), json_encode(array_keys($parou)));
    prova('100 mil em 2025 e 9 mil em 2026 (menos de 10%): PAROU', array_key_exists($contas['9.2'], $parou));
    prova('e a maior primeiro (100 mil antes de 50 mil)', $q['parou'][0]['codigo'] === $contas['9.2']);
    prova('nada em 2025 e −30 mil em 2026: COMEÇOU', array_key_exists($contas['8.8'], $comecou) && count($q['comecou']) === 1);
    prova('o rateio corporativo (14.3) fica de fora', !array_key_exists($contas['14.3'], $parou));
    prova('a pergunta diz o que perguntar', str_contains($q['parou'][0]['pergunta'], 'deveria continuar em 2027')
        && str_contains($q['comecou'][0]['pergunta'], 'vai continuar em 2027'), $q['comecou'][0]['pergunta']);

    // 9 mil vira 11 mil em 2026 (mais de 10% de 100 mil): deixa de ser "parou".
    $pdo->prepare('UPDATE dre_reduzida_12m SET realizado = 11000 WHERE negocio_id = ? AND ano_fim = 2026 AND codigo = ?')
        ->execute([$neg, $contas['9.2']]);
    $q = Oportunidades::questionamentos($neg, 2026, 10);
    prova('com 11 mil em 2026 (mais de 10% dos 100 mil) a conta 9.2 não é questionada',
        !in_array($contas['9.2'], array_column($q['parou'], 'codigo'), true));
    // Abaixo de R$ 10 mil não é questionamento.
    $pdo->prepare('UPDATE dre_reduzida_12m SET realizado = -9000 WHERE negocio_id = ? AND ano_fim = 2026 AND codigo = ?')
        ->execute([$neg, $contas['8.8']]);
    $q = Oportunidades::questionamentos($neg, 2026, 10);
    prova('o que começou com menos de R$ 10 mil não é questionado', !$q['comecou']);
    // Só um ano carregado: sem comparação.
    $pdo->prepare('DELETE FROM dre_reduzida_12m WHERE negocio_id = ? AND ano_fim = 2025')->execute([$neg]);
    prova('sem a janela anterior não há questionamento (e não inventa "começou" em tudo)', Oportunidades::questionamentos($neg, 2026, 10) === null);
} finally {
    $pdo->prepare('DELETE FROM dre_reduzida_12m WHERE negocio_id = ?')->execute([$neg]);
    $pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([$neg]);
}

// Âncora nos dados reais.
$um = (int)(Database::um("SELECT id FROM negocio WHERE cod_negocio = '1'")['id'] ?? 0);
$q = Oportunidades::questionamentos($um, 2026, 10);
prova('negócio 1: há contas que pararam de uma janela para a outra (filiais que saíram dele)', $q && count($q['parou']) > 0,
    $q ? count($q['parou']) . ' parou / ' . count($q['comecou']) . ' começou' : 'sem dados');
$n = Database::todos('SELECT ano_fim, COUNT(*) n FROM dre_reduzida_12m WHERE mes_fim = 10 GROUP BY ano_fim ORDER BY ano_fim');
prova('as duas janelas estão no banco (29.014 e 29.944 valores)', array_column($n, 'n', 'ano_fim') == [2025 => 29014, 2026 => 29944], json_encode($n));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

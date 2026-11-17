<?php

/**
 * Provas das premissas da Projeção que NÃO têm tela: o que acontece com uma
 * linha gravada POR FILIAL antes de o cliente decidir que IPCA, PIB e a meta de
 * crescimento são do negócio (2026-11-17).
 *
 * Ficam fora da bateria do navegador porque a API agora RECUSA criar uma
 * premissa por filial — então só dá para semear esse estado direto no banco, e
 * o navegador não tem banco. O estado existe de verdade: quem lançou IPCA dentro
 * de uma filial antes dessa data tem a linha lá.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/projecao_premissas.php
 *
 * Monta o próprio negócio, filial e projeção, e os apaga no fim (as FKs são
 * ON DELETE CASCADE). Nada do que já está no banco de desenvolvimento é tocado.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Services/Projecao.php';

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

$pdo = Database::conn();

// ── Massa descartável ───────────────────────────────────────────────────────
$cod = 'QP' . substr((string)microtime(true), -6);
$pdo->prepare('INSERT INTO negocio (cod_negocio, nome, ativo) VALUES (?, ?, 0)')
    ->execute([$cod, "Negócio de prova {$cod}"]);
$negocioId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO filial (negocio_id, cod_filial, nome) VALUES (?, ?, ?)')
    ->execute([$negocioId, '1', 'Filial de prova']);
$filialId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO projecao (negocio_id, ano) VALUES (?, 2027)')->execute([$negocioId]);
$projecaoId = (int)$pdo->lastInsertId();

$grava = $pdo->prepare(
    'INSERT INTO projecao_premissa (projecao_id, filial_id, chave, percentual) VALUES (?, ?, ?, ?)'
);

// O negócio lançou 6 / 5 / 12; a filial tinha um IPCA PRÓPRIO (99) de antes.
$grava->execute([$projecaoId, 0, 'IPCA', 6]);
$grava->execute([$projecaoId, 0, 'PIB', 5]);
$grava->execute([$projecaoId, 0, 'CRESCIMENTO', 12]);
$grava->execute([$projecaoId, $filialId, 'IPCA', 99]);
$grava->execute([$projecaoId, $filialId, 'PIB', 88]);

// A leitura é um método PRIVADO do controlador, e é ele que se quer provar: um
// teste que reimplementasse a consulta aqui passaria sem provar nada. A
// reflexão o chama como a tela o chama.
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';
$ctrl = new App\Controllers\ProjecaoController();
$ler = new ReflectionMethod($ctrl, 'premissasLancadas');
$ler->setAccessible(true);

echo "\nPremissas por negócio, com uma linha legada por filial\n";

$lidas = $ler->invoke($ctrl, $projecaoId);
prova('a leitura devolve as três premissas do NEGÓCIO',
    ($lidas['IPCA'] ?? null) === 6.0 && ($lidas['PIB'] ?? null) === 5.0
    && ($lidas['CRESCIMENTO'] ?? null) === 12.0, json_encode($lidas));

// O ponto da prova: a linha da filial continua no banco e ninguém a lê.
$noBanco = (int)$pdo->query(
    "SELECT COUNT(*) FROM projecao_premissa WHERE projecao_id = {$projecaoId} AND filial_id = {$filialId}"
)->fetchColumn();
prova('a linha legada por filial CONTINUA no banco, intacta', $noBanco === 2, "linhas={$noBanco}");
prova('e nenhuma leitura a alcança: o IPCA 99 da filial não aparece',
    ($lidas['IPCA'] ?? null) !== 99.0 && ($lidas['PIB'] ?? null) !== 88.0, json_encode($lidas));

// A leitura NÃO aceita mais filial. Se alguém "respeitar o valor antigo"
// devolvendo o parâmetro, a filial voltaria a ter um IPCA que o negócio não
// conhece — e esta prova passa a falhar na assinatura, não em silêncio.
prova('o método de leitura não tem mais parâmetro de filial',
    $ler->getNumberOfParameters() === 1, 'parâmetros=' . $ler->getNumberOfParameters());

// ── O painel da filial usa as premissas do negócio ──────────────────────────
// `premissas()` recebe as linhas da filial e as premissas DIGITADAS do negócio.
$linhasFilial = [
    ['codigo' => '1', 'projecao' => 1000.0, 'proposta' => 1200.0],
    ['codigo' => '4', 'projecao' => 800.0, 'proposta' => 800.0],
];
$painel = Projecao::premissas($linhasFilial, $lidas);
prova('Cresc. Total da filial é o DELA (20%), lido das linhas dela',
    abs($painel['CRESC_TOTAL'] - 20.0) < 1e-9, (string)$painel['CRESC_TOTAL']);
prova('Cresc. Real da filial desconta o IPCA e o PIB do NEGÓCIO (20 − 6 − 5 = 9)',
    abs($painel['CRESC_REAL'] - 9.0) < 1e-9, (string)$painel['CRESC_REAL']);
prova('e a meta de crescimento chega ao painel da filial como registro (12)',
    $painel['CRESCIMENTO'] === 12.0, json_encode($painel['CRESCIMENTO']));

// A meta NÃO entra em conta: com ou sem ela, os derivados são os mesmos.
$semMeta = Projecao::premissas($linhasFilial, ['IPCA' => 6.0, 'PIB' => 5.0]);
prova('a meta de crescimento não move nenhum derivado',
    $semMeta['CRESC_TOTAL'] === $painel['CRESC_TOTAL']
    && $semMeta['CRESC_REAL'] === $painel['CRESC_REAL']
    && $semMeta['PCT_CUSTOS'] === $painel['PCT_CUSTOS']
    && $semMeta['CRESCIMENTO'] === null);

prova('"CRESCIMENTO" está entre as premissas que a API aceita',
    in_array('CRESCIMENTO', Projecao::PREMISSAS, true), json_encode(Projecao::PREMISSAS));
prova('e "CRESC_TOTAL" continua fora: é leitura, e não pode ter segunda fonte',
    !in_array('CRESC_TOTAL', Projecao::PREMISSAS, true));

// ── Limpeza ─────────────────────────────────────────────────────────────────
$pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([$negocioId]);
$sobrou = (int)$pdo->query("SELECT COUNT(*) FROM projecao WHERE id = {$projecaoId}")->fetchColumn();
prova('a massa de prova foi apagada (o CASCADE leva a projeção e as premissas)', $sobrou === 0);

echo "\n✓ {$ok} passaram\n";
if ($bad) {
    echo '✗ ' . count($bad) . " FALHARAM\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

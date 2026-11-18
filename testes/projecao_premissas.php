<?php

/**
 * Provas das premissas da Projeção que NÃO têm tela: a migração do que o
 * cliente já tinha digitado, e o que acontece com as linhas LEGADAS.
 *
 * Contexto. IPCA, PIB e o crescimento desejado eram penduradas na projeção (a
 * proposta de UM ano), com um par por filial. Por decisão do cliente
 * (2026-11-16 e 2026-11-17) passaram a ser do NEGÓCIO, valendo para todas as
 * filiais, com um valor para cada ANO — e a tabela nova é `negocio_premissa`.
 *
 * Ficam fora da bateria do navegador porque só dá para semear esse estado
 * direto no banco: a API não cria mais premissa por filial, e a tabela antiga
 * não é mais escrita por ninguém. O estado existe de verdade — é o que está em
 * produção hoje.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/projecao_premissas.php
 *
 * Monta o próprio negócio, filial e projeção, e os apaga no fim (as FKs são
 * ON DELETE CASCADE). Nada do que já está no banco de desenvolvimento é tocado:
 * a cópia da migração é restrita ao negócio de prova.
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

// O ESTADO ANTIGO: o negócio lançou 6 / 5 / 12 (o IPCA 6 e o PIB 5 são os do
// print do cliente) e a filial tinha um IPCA e um PIB PRÓPRIOS (99 e 88).
$legado = $pdo->prepare(
    'INSERT INTO projecao_premissa (projecao_id, filial_id, chave, percentual) VALUES (?, ?, ?, ?)'
);
$legado->execute([$projecaoId, 0, 'IPCA', 6]);
$legado->execute([$projecaoId, 0, 'PIB', 5]);
$legado->execute([$projecaoId, 0, 'CRESCIMENTO', 12]);
$legado->execute([$projecaoId, $filialId, 'IPCA', 99]);
$legado->execute([$projecaoId, $filialId, 'PIB', 88]);

$noNovo = static function () use ($pdo, $negocioId): array {
    $st = $pdo->prepare('SELECT ano, chave, percentual FROM negocio_premissa WHERE negocio_id = ? ORDER BY ano, chave');
    $st->execute([$negocioId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $out[(int)$l['ano']][$l['chave']] = (float)$l['percentual'];
    }
    return $out;
};

echo "\nMigração: do que estava na projeção para negócio e ano\n";

prova('antes da migração a tabela nova está vazia para o negócio', $noNovo() === []);

$entraram = Projecao::migrarPremissasParaNegocio($pdo, $negocioId);
$depois = $noNovo();
prova('copia as três premissas do NEGÓCIO', $entraram === 3, "entraram={$entraram}");
prova('com o ANO da projeção (2027), que era onde a tela as mostrava',
    array_keys($depois) === [2027]
    && ($depois[2027]['IPCA'] ?? null) === 6.0 && ($depois[2027]['PIB'] ?? null) === 5.0
    && ($depois[2027]['CRESCIMENTO'] ?? null) === 12.0, json_encode($depois));
prova('e NÃO copia a linha por filial: o IPCA 99 e o PIB 88 ficam de fora',
    !in_array(99.0, array_column($depois, 'IPCA'), true)
    && !in_array(88.0, array_column($depois, 'PIB'), true), json_encode($depois));

// A tabela antiga fica como está: é legado inerte, e quem quiser recuperar um
// valor de filial o encontra ali.
$naAntiga = (int)$pdo->query("SELECT COUNT(*) FROM projecao_premissa WHERE projecao_id = {$projecaoId}")->fetchColumn();
prova('a tabela antiga continua INTACTA (5 linhas, a por filial inclusive)', $naAntiga === 5, "linhas={$naAntiga}");

// INSERT IGNORE: o que já está na tabela nova não é sobrescrito. É por isso que
// o migrate pode copiar sem medo de pisar num valor já corrigido — mas NÃO basta
// para rodar a cada deploy (ver o próximo grupo).
$pdo->prepare("UPDATE negocio_premissa SET percentual = 7 WHERE negocio_id = ? AND ano = 2027 AND chave = 'IPCA'")
    ->execute([$negocioId]);
$denovo = Projecao::migrarPremissasParaNegocio($pdo, $negocioId);
prova('rodar de novo NÃO sobrescreve um valor já corrigido na tabela nova',
    ($noNovo()[2027]['IPCA'] ?? null) === 7.0 && $denovo === 0, 'IPCA=' . json_encode($noNovo()[2027]['IPCA'] ?? null) . " entraram={$denovo}");

// A armadilha que o INSERT IGNORE NÃO cobre, e por que o migrate roda uma vez só.
$pdo->prepare("DELETE FROM negocio_premissa WHERE negocio_id = ? AND ano = 2027 AND chave = 'PIB'")
    ->execute([$negocioId]);
$ressuscitou = Projecao::migrarPremissasParaNegocio($pdo, $negocioId);
prova('MAS ressuscita o que alguém apagou — por isso o migrate roda UMA vez, sob marca',
    $ressuscitou === 1 && ($noNovo()[2027]['PIB'] ?? null) === 5.0,
    "entraram={$ressuscitou} — o IGNORE sozinho não basta a cada deploy");

echo "\nLeitura por ano\n";

// A leitura é um método PRIVADO do controlador, e é ele que se quer provar: um
// teste que reimplementasse a consulta aqui passaria sem provar nada. A
// reflexão o chama como a tela o chama.
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';
$ctrl = new App\Controllers\ProjecaoController();
$ler = new ReflectionMethod($ctrl, 'premissasPorAno');
$ler->setAccessible(true);

// Um valor para 2028 e um para um ano FORA do ciclo oferecido (2019).
$ins = $pdo->prepare('INSERT INTO negocio_premissa (negocio_id, ano, chave, percentual) VALUES (?, ?, ?, ?)');
$ins->execute([$negocioId, 2028, 'IPCA', 3.5]);
$ins->execute([$negocioId, 2019, 'IPCA', 50]);
$pdo->prepare("UPDATE negocio_premissa SET percentual = 6 WHERE negocio_id = ? AND ano = 2027 AND chave = 'IPCA'")
    ->execute([$negocioId]);

$anos = Projecao::anosDasPremissas(2027, 2030);
$porAno = $ler->invoke($ctrl, $negocioId, $anos);
prova('a leitura devolve UM conjunto para cada ano pedido',
    array_keys($porAno) === [2027, 2028, 2029, 2030], json_encode(array_keys($porAno)));
// 2027 tem DIRETRIZ: o pessoal 5,5 vale para todos, por cima do digitado; IPCA
// e PIB são SUGERIDOS — o IPCA 6 e o PIB 5 que o negócio digitou valem
// (decisão do cliente, 2026-11-18). O crescimento desejado é do negócio.
prova('2027: IPCA e PIB digitados pelo negócio (6 e 5) valem, pessoal fixo (5,5) e o desejado do negócio',
    ($porAno[2027]['IPCA'] ?? null) === 6.0 && ($porAno[2027]['PIB'] ?? null) === 5.0
    && ($porAno[2027]['PESSOAS'] ?? null) === 5.5
    && ($porAno[2027]['CRESCIMENTO'] ?? null) === 12.0, json_encode($porAno[2027]));
prova('2028 traz o SEU valor, sem herdar o de 2027', $porAno[2028] === ['IPCA' => 3.5], json_encode($porAno[2028]));
prova('ano sem nada lançado volta com lista vazia, e não some da resposta',
    $porAno[2029] === [] && $porAno[2030] === []);
prova('ano fora do que foi pedido não vaza (o 2019 não aparece)', !isset($porAno[2019]));
prova('e nenhuma leitura alcança o IPCA 99 da filial', !in_array(99.0, array_column($porAno, 'IPCA'), true));
prova('o método de leitura não tem parâmetro de filial (assinatura: negócio e anos)',
    $ler->getNumberOfParameters() === 2, 'parâmetros=' . $ler->getNumberOfParameters());

echo "\nOs anos oferecidos\n";

$ciclo = Projecao::anosDasPremissas(2027, 2035);
prova('do ano da proposta ao fim do ciclo', $ciclo[0] === 2027 && end($ciclo) === 2035 && count($ciclo) === 9,
    count($ciclo) . ' anos');
prova('sem ciclo que cubra o ano, só o da proposta — nunca uma lista vazia',
    Projecao::anosDasPremissas(2027, null) === [2027]);
prova('ciclo que termina ANTES do ano da proposta não inverte a faixa',
    Projecao::anosDasPremissas(2027, 2025) === [2027]);

echo "\nO painel da filial usa as premissas do negócio\n";

// `premissas()` recebe as linhas da filial e as premissas DIGITADAS do negócio
// no ano da proposta.
$linhasFilial = [
    ['codigo' => '1', 'projecao' => 1000.0, 'proposta' => 1200.0],
    ['codigo' => '4', 'projecao' => 800.0, 'proposta' => 800.0],
];
$painel = Projecao::premissas($linhasFilial, $porAno[2027]);
prova('Cresc. Total da filial é o DELA (20%), lido das linhas dela',
    abs($painel['CRESC_TOTAL'] - 20.0) < 1e-9, (string)$painel['CRESC_TOTAL']);
prova('Cresc. Real da filial desconta o IPCA e o PIB que VALEM no negócio (20 − 6 − 5 = 9)',
    abs($painel['CRESC_REAL'] - 9.0) < 1e-9, (string)$painel['CRESC_REAL']);
prova('e o crescimento desejado chega ao painel da filial como registro (12)',
    $painel['CRESCIMENTO'] === 12.0, json_encode($painel['CRESCIMENTO']));

// O crescimento desejado NÃO entra em conta: com ou sem ele, os derivados são os mesmos.
$semDesejado = Projecao::premissas($linhasFilial, ['IPCA' => 6.0, 'PIB' => 5.0]);
prova('o crescimento desejado não move nenhum derivado',
    $semDesejado['CRESC_TOTAL'] === $painel['CRESC_TOTAL']
    && $semDesejado['CRESC_REAL'] === $painel['CRESC_REAL']
    && $semDesejado['PCT_CUSTOS'] === $painel['PCT_CUSTOS']
    && $semDesejado['CRESCIMENTO'] === null);

prova('"CRESCIMENTO" está entre as premissas que a API aceita',
    in_array('CRESCIMENTO', Projecao::PREMISSAS, true), json_encode(Projecao::PREMISSAS));
prova('e "CRESC_TOTAL" continua fora: é leitura, e não pode ter segunda fonte',
    !in_array('CRESC_TOTAL', Projecao::PREMISSAS, true));

// ── Limpeza ─────────────────────────────────────────────────────────────────
$pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([$negocioId]);
$sobrou = (int)$pdo->query("SELECT COUNT(*) FROM projecao WHERE id = {$projecaoId}")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM negocio_premissa WHERE negocio_id = {$negocioId}")->fetchColumn();
prova('a massa de prova foi apagada (o CASCADE leva a projeção e as premissas)', $sobrou === 0);

echo "\n✓ {$ok} passaram\n";
if ($bad) {
    echo '✗ ' . count($bad) . " FALHARAM\n";
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

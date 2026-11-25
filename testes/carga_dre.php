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
        if (isset($dados['filiais'])) {
            App\Services\CargaDre::aplicarFiliais($pdo, $dados);
        } else {
            App\Services\CargaDre::aplicar($pdo, $dados);
        }
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

// A base é a carga na ESTRUTURA NOVA da DRE gerencial (2026-11-18): é a que está
// no banco, e uma carga na estrutura velha é recusada por cima dela (prova 6b).
$base = require __DIR__ . '/../database/dre_2026_fechamento_10_nova.php';

// ── 1. Mês sem rateio é mês ABERTO ──────────────────────────────────────────
// A regra que a planilha do cliente não tinha: em 05/11 outubro trazia R$ 373,2
// mi de receita e Rateios = 0 (hoje é novembro que está nessa situação: R$ 171
// mi lançados, sem rateio). Declará-lo fechado tem de ser recusado AQUI, e não
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
$contaIntrusa['valores']['2|99.9'] = ['1;1;1;1;1;1;1;1;1;1', '1;1;1;1;1;1;1;1;1;1;1;1'];
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
// Aplica uma vez antes de medir: num banco que ainda não tinha o mês novo (a
// primeira rodada depois de uma carga nova) essa aplicação ACRESCENTA as linhas
// dele, e o que a prova mede é a REaplicação.
App\Services\CargaDre::aplicar($pdo, $base);
$antes = $pdo->query('SELECT COUNT(*) FROM dre_valor')->fetchColumn();
$r = App\Services\CargaDre::aplicar($pdo, $base);
$depois = $pdo->query('SELECT COUNT(*) FROM dre_valor')->fetchColumn();
prova('reaplicar a mesma carga não duplica linha', $antes === $depois, "{$antes} → {$depois}");
prova('e grava os 11 negócios', $r['negocios'] === 11, (string)$r['negocios']);

// ── 6b. Carga na estrutura VELHA não passa por cima da nova ──────────────────
// Os códigos 15, 16 e 17 mudaram de sentido: regravar a velha poria o resultado
// líquido no código do rateio administrativo, sem erro nenhum.
$msg = recusa($pdo, require __DIR__ . '/../database/dre_2026_fechamento_10.php');
prova('carga de DRE na estrutura velha é recusada sobre o banco na nova',
    $msg !== null && str_contains($msg, 'estrutura VELHA'), (string)$msg);
$liq = (float)$pdo->query("SELECT SUM(v.valor) FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
    WHERE c.codigo = '18' AND v.ano = 2026 AND v.tipo = 'REAL' AND v.mes <= 10")->fetchColumn();
prova('e o resultado líquido jan–out continua o da DRE (R$ 24.927.200, igual nas duas estruturas)',
    abs($liq - 24927200) < 5, (string)round($liq));

// ── 7. Filiais: a soma tem de bater com o negócio ───────────────────────────
// Uma filial faltando passa despercebida — cada tela fica certa e o total
// errado. É o modo de falha que esta guarda existe para pegar.
$fil = require __DIR__ . '/../database/dre_filiais_pecuaria_2026_10.php';
$semUma = $fil;
$semUma['filiais'] = array_diff_key($fil['filiais'], ['46' => 1]);
foreach (array_keys($semUma['valores']) as $k) {
    if (str_starts_with($k, '46|')) {
        unset($semUma['valores'][$k]);
    }
}
$msg = recusa($pdo, $semUma);
prova('carga de filiais com uma filial faltando é recusada',
    $msg !== null && str_contains($msg, 'não fecha com o negócio'), (string)$msg);

// ── 8. E o corte precisa ser o do fechamento gravado ────────────────────────
// Casar realizado de um período com orçamento de outro é somar duas verdades
// diferentes; os números da filial são acumulados e só valem para um corte.
$outroCorte = $fil;
$outroCorte['corte'] = 8;
$msg = recusa($pdo, $outroCorte);
prova('carga de filiais com corte diferente do fechamento é recusada',
    $msg !== null && str_contains($msg, 'corte'), (string)$msg);

// ── 9. TODA carga de filiais do repositório fecha com o seu negócio ─────────
// A prova 7 mede a guarda com um arquivo; esta mede os arquivos. Ela cresce
// sozinha: negócio novo carregado por filial entra aqui no mesmo commit, e se a
// extração dele tiver perdido uma filial a bateria fica vermelha antes do
// deploy, em vez de o Painel somar menos do que o negócio.
//
// Cada arquivo vale para o seu CORTE, e a carga recusa corte diferente do
// gravado — então o negócio do mesmo corte é recarregado antes de cada um
// (setembro para as filiais que ainda não foram re-extraídas, outubro para as
// demais). No fim o banco volta ao fechamento mais novo.
// O ACERTO das filiais (2026-11-25) não é carga de filiais: são trocas sobre
// elas, conferidas na prova 11.
$arquivos = array_values(array_filter(glob(__DIR__ . '/../database/dre_filiais_*.php'),
    static fn ($a) => !str_contains(basename($a), 'acerto')));
sort($arquivos);
prova('há carga de filiais no repositório', $arquivos !== [], count($arquivos) . ' arquivo(s)');
// Desde a estrutura nova o negócio só existe nela (a carga velha é recusada), e
// ela traz o realizado mês a mês: para conferir um arquivo de outro corte, o
// fechamento é posto naquele corte e volta no fim (prova 10).
foreach ($arquivos as $a) {
    $d = require $a;
    App\Services\CargaDre::aplicar($pdo, $base);
    $pdo->prepare('UPDATE dre_fechamento SET mes_fechado_ate = ? WHERE ano = ?')->execute([(int)$d['corte'], (int)$d['ano']]);
    $msg = recusa($pdo, $d);
    prova(
        'a soma das filiais de ' . basename($a, '.php') . ' fecha com o negócio',
        $msg === null,
        (string)$msg
    );
}

// ── 9b. Re-extrair apaga o que deixou de existir ─────────────────────────────
// A carga omite o que é zero; sem apagar antes, a linha que virou zero no corte
// novo ficava no banco com o valor e o corte do anterior (aconteceu em
// 17/11/2026: Embrapa, conta 8.17, R$ 1.184 de projetado em setembro).
$filPec = require __DIR__ . '/../database/dre_filiais_pecuaria_2026_10.php';
App\Services\CargaDre::aplicar($pdo, $base);
App\Services\CargaDre::aplicarFiliais($pdo, $filPec);
$negPec = (int)$pdo->query("SELECT id FROM negocio WHERE cod_negocio = '2'")->fetchColumn();
$filId = (int)$pdo->query("SELECT id FROM filial WHERE negocio_id = {$negPec} AND cod_filial = '46'")->fetchColumn();
// Planta uma linha de um corte antigo numa conta que o arquivo NÃO traz para
// esta filial — se a conta estivesse no arquivo, o upsert a sobrescreveria de
// qualquer jeito e a prova passaria sem provar nada.
$codigoAusente = null;
foreach ($pdo->query('SELECT codigo FROM dre_conta ORDER BY ordem')->fetchAll(PDO::FETCH_COLUMN) as $cod) {
    if (!isset($filPec['valores']["46|{$cod}"])) {
        $codigoAusente = $cod;
        break;
    }
}
prova('há conta ausente do arquivo para plantar o fantasma', $codigoAusente !== null, (string)$codigoAusente);
$st = $pdo->prepare('SELECT id FROM dre_conta WHERE codigo = ?');
$st->execute([$codigoAusente]);
$contaId = (int)$st->fetchColumn();
$pdo->prepare('REPLACE INTO dre_valor_filial (filial_id, conta_id, ano, corte, realizado, projetado) VALUES (?, ?, 2026, 9, 0, 999)')
    ->execute([$filId, $contaId]);
$fantasma = fn () => (int)$pdo->query("SELECT COUNT(*) FROM dre_valor_filial WHERE filial_id = {$filId} AND conta_id = {$contaId} AND corte = 9")->fetchColumn();
prova('a linha de corte antigo foi plantada (a prova é válida)', $fantasma() === 1);
App\Services\CargaDre::aplicarFiliais($pdo, $filPec);
prova('re-extrair apaga a linha que o arquivo novo não traz', $fantasma() === 0, 'fantasma: ' . $fantasma());
$cortes = $pdo->query("SELECT DISTINCT v.corte FROM dre_valor_filial v JOIN filial f ON f.id = v.filial_id WHERE f.negocio_id = {$negPec} AND v.ano = 2026")->fetchAll(PDO::FETCH_COLUMN);
prova('e a Pecuária fica toda num corte só', $cortes === ['10'] || $cortes === [10], json_encode($cortes));

// ── 10. O corte do fechamento ficou gravado ─────────────────────────────────
// Depois de passar pelos cortes antigos na prova 9, volta ao mais novo: a
// recarga de outubro de 25/11 (`dre_2026_fechamento_10_r2`).
$r2 = require __DIR__ . '/../database/dre_2026_fechamento_10_r2.php';
App\Services\CargaDre::aplicar($pdo, $r2);
$f = $pdo->query('SELECT * FROM dre_fechamento WHERE ano = 2026')->fetch(PDO::FETCH_ASSOC);
prova('o corte do fechamento fica no banco, não no código',
    $f && (int)$f['mes_fechado_ate'] === 10, json_encode($f));

// Reaplicar as cargas de filial (prova 9) volta o rateio corporativo das filiais
// à divisão aproximada da conversão; o migrate o deixa no do nível 3 (carga
// `dre_nivel3_*`, 2026-11-21). Devolve o banco a esse estado, para as suítes que
// vêm depois verem o que a produção tem.
require_once __DIR__ . '/../app/Services/CargaNivel3.php';
App\Services\CargaNivel3::corrigirRateioDasFiliais($pdo, 2026);

// ── 11. A recarga de outubro (2026-11-25) ───────────────────────────────────
// Só outubro mudou: jan–set e o orçamento da recarga são os de 18/11, valor a
// valor. E o acerto das filiais leva as mesmas trocas a elas — a própria carga
// recusa se a soma das filiais não bater com o negócio.
$mudou = [];
foreach ($r2['valores'] as $k => [$real, $meta]) {
    $velho = $base['valores'][$k] ?? null;
    $a = explode(';', $real);
    $b = $velho ? explode(';', $velho[0]) : [];
    if (!$velho || $meta !== $velho[1] || array_slice($a, 0, 9) !== array_slice($b, 0, 9)) {
        $mudou[] = $k;
    }
}
prova('a recarga de 25/11 só muda outubro: jan–set e orçamento iguais aos de 18/11', $mudou === [], implode(', ', array_slice($mudou, 0, 5)));
$acerto = require __DIR__ . '/../database/dre_filiais_acerto_2026_10.php';
$msg = null;
try {
    App\Services\CargaDre::aplicarAcertosFiliais($pdo, $acerto);
} catch (Throwable $e) {
    $msg = $e->getMessage();
}
prova('o acerto das filiais de outubro fecha com o negócio recarregado', $msg === null, (string)$msg);
$agro106 = $pdo->query("SELECT COALESCE(SUM(ABS(v.realizado)), 0) FROM dre_valor_filial v JOIN filial f ON f.id = v.filial_id
    JOIN negocio n ON n.id = f.negocio_id WHERE n.cod_negocio = '8' AND f.cod_filial = '106' AND v.ano = 2026")->fetchColumn();
prova('a filial 106 (fábrica de Rações) fica zerada nas Agropecuárias', abs((float)$agro106) < 0.01, (string)$agro106);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

<?php

/**
 * Provas do RATEIO FINANCEIRO na proposta (linha 14.2; pedido do cliente,
 * 2026-11-24: "atualize o rateio financeiro por negócio no projetado / orçado de
 * 2027, totalizando por ano, ou seja na coluna proposta. E bloqueie a célula").
 *
 * Os números de mão vêm da planilha `Rateio_Financeiro - site planejamento.xlsx`
 * (coluna "Rateio_fin_negocio", nov/25 a out/26).
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/rateio_financeiro.php
 *
 * A seção 4 (a API recusa o lançamento) bate na aplicação em APP_URL (padrão
 * http://127.0.0.1:8099); sem ela de pé, é pulada.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/Metas.php';
require_once __DIR__ . '/../app/Services/DesafioCota.php';
require_once __DIR__ . '/../app/Services/DesafioDrivers.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

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
function perto(float $a, float $b, float $tol = 0.01): bool
{
    return abs($a - $b) <= $tol;
}

$pdo = Database::conn();
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[(string)$n['cod_negocio']] = (int)$n['id'];
}
$cod = array_flip($ids);
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$anoPlan = $ano + 1;
$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$linha = static fn (array $linhas, string $c) => array_column($linhas, null, 'codigo')[$c];

// ── 1. A carga ───────────────────────────────────────────────────────────────
$carga = Projecao::cargaRateioFin($anoPlan);
prova('a carga é a planilha: −R$ 95.923.100 nos 11 negócios, 12 meses cada',
    $carga && perto((float)$carga['total'], -95923100.0) && count($carga['negocios']) === 11
    && array_keys($carga['negocios']['8']) === range(1, 12), json_encode($carga['total'] ?? null));
// De mão, da planilha: os 12 meses somados.
prova('Agropecuárias −52.598.435,23 e Cereais −19.934.396,65 no ano (a soma dos 12 meses da planilha)',
    perto(array_sum($carga['negocios']['8']), -52598435.2281, 0.001) && perto(array_sum($carga['negocios']['1']), -19934396.654, 0.001));
prova('o mês da planilha vai para o mesmo mês de 2027: Cereais jan = −1.213.955,26 (jan/26) e nov = −745.199,99 (nov/25)',
    perto($carga['negocios']['1'][1], -1213955.26, 0.01) && perto($carga['negocios']['1'][11], -745199.99, 0.01));

// ── 2. Na tela ───────────────────────────────────────────────────────────────
$agro = $montar->invoke($ctrl, $ids['8'], $ano, $corte, 0);
$l142 = $linha($agro['linhas'], '14.2');
prova('na tela, a 14.2 das Agropecuárias é a da planilha, travada, com os 12 meses',
    perto($l142['proposta'], -52598435.2281, 0.01) && $l142['proposta_origem'] === 'RATEIO_FIN'
    && $l142['proposta_travada'] === true && count($l142['proposta_meses']) === 12, (string)round($l142['proposta'], 2));
$filiais = 0.0;
foreach ($agro['filiais'] as $f) {
    $tf = $montar->invoke($ctrl, $ids['8'], $ano, $corte, (int)$f['id']);
    $lf = $linha($tf['filial']['linhas'], '14.2');
    $filiais += (float)$lf['proposta'];
}
prova('as filiais das Agropecuárias somam o rateio do negócio (pela projeção de 2026 da 14.2 de cada uma)',
    perto($filiais, $l142['proposta'], 0.05), round($filiais, 2) . ' / ' . round($l142['proposta'], 2));
$todos = array_map('intval', array_column(Database::todos('SELECT id FROM negocio WHERE ativo = 1'), 'id'));
$painel = Projecao::resumoDosNegocios($todos, $ano, $corte, $anoPlan, ['14.2']);
prova('o Painel faz a mesma conta (soma da 14.2 = −95.923.100)',
    perto(array_sum(array_map(static fn ($x) => (float)($x['proposta']['14.2'] ?? 0), $painel)), -95923100.0, 0.05));
prova('a 14.2 entra no bloco 12 e no desafio (travada também nele)',
    perto((float)$linha($agro['linhas'], '14')['proposta'], (float)$linha($agro['linhas'], '14.1')['proposta']
        + $l142['proposta'] + (float)$linha($agro['linhas'], '14.3')['proposta'], 1));

// ── 3. A API recusa o lançamento na 14.2 ─────────────────────────────────────
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'ratf');
$http = static function (string $metodo, string $url, ?array $corpo = null, array $cab = []) use ($jar): string {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cab)]);
    if ($corpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    $resp = curl_exec($c);
    curl_close($c);
    return (string)$resp;
};
if ($http('GET', "{$base}/login") === '') {
    echo "  ⏭  seção 3 pulada: a aplicação não responde em {$base}.\n";
} else {
    $http('POST', "{$base}/api/login", ['email' => getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', 'senha' => getenv('APP_SENHA') ?: 'trocar123']);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', "{$base}/"), $mm);
    $cab = ['X-CSRF-Token: ' . ($mm[1] ?? '')];
    $resp = json_decode($http('POST', "{$base}/api/projecao/valor",
        ['negocio_id' => $ids['8'], 'conta' => '14.2', 'entrada' => 'VALOR', 'valor' => -1], $cab), true);
    prova('a API recusa lançar no Rateio Financeiro',
        str_contains((string)($resp['erro'] ?? ''), 'não se lança'), json_encode($resp['erro'] ?? $resp, JSON_UNESCAPED_UNICODE));
}
@unlink($jar);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

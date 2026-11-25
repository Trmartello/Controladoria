<?php

/**
 * Provas do RATEIO CORPORATIVO ADMINISTRATIVO na proposta (linha 16.1; pedido do
 * cliente, 2026-11-24: "gere um critério de rateio por negócio, olhando para a
 * coluna K 'saldo' e por período / mês […] numa proporção de valor orçado. Bloqueia
 * o campo, a célula vai em rateio corporativo administrativo"). Decisões do cliente
 * no mesmo dia: o total vai INTEIRO para a 16.1 (a 14.3 continua como está) e o
 * critério se recalcula ao vivo.
 *
 * Os números de mão vêm da planilha `Rateio Corporativo 2027.xlsm` (coluna K
 * "Saldo"): total −R$ 37.735.231,41 (a linha "Total" dela) e janeiro −R$ 1.467.968,455.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/rateio_adm.php
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
require_once __DIR__ . '/../app/Services/DesafioAjuste.php';
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
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$anoPlan = $ano + 1;
$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$linha = static fn (array $tela, string $c) => array_column($tela['linhas'], null, 'codigo')[$c];

// ── 1. A carga: o saldo da planilha, mês a mês ──────────────────────────────
$carga = Projecao::cargaRateioAdm($anoPlan);
prova('a carga de 2027 é o saldo da planilha: −R$ 37.735.231,41 no ano, −R$ 1.467.968,455 em janeiro',
    $carga && perto((float)$carga['total'], -37735231.41) && perto((float)$carga['meses'][1], -1467968.455, 0.0001),
    json_encode([$carga['total'] ?? null, $carga['meses'][1] ?? null]));
prova('sem carga para o ano, não há rateio (a 16.1 volta a ser lançável)', Projecao::cargaRateioAdm(2099) === null);

// ── 2. O critério e as partes ────────────────────────────────────────────────
// De mão: o critério é a despesa proposta nas 8 linhas, positiva; o resto não conta.
$l = static fn ($c, $p) => ['codigo' => $c, 'proposta' => $p];
prova('o critério soma só vendas, promoções, pessoal, serv. profissionais, serv. em geral, manutenção, materiais e viagens',
    perto(Projecao::criterioRateioAdm([$l('8.1', -100.0), $l('8.2', -10.0), $l('8.5', -1000.0), $l('8.7', -1.0), $l('8.8', -2.0),
        $l('8.11', -3.0), $l('8.12', -4.0), $l('8.14', -5.0), $l('8.9', -999.0), $l('8.10', -999.0), $l('4.1', -999.0)]), 1125.0));
$r = Projecao::rateioAdm($ano, $corte, $anoPlan);
$soma = array_sum(array_column($r['negocios'], 'valor'));
$somaJan = array_sum(array_map(static fn ($x) => $x['meses'][1], $r['negocios']));
prova('os negócios somam o total da planilha (−37.735.231,41) e, em janeiro, o janeiro dela (−1.467.968,455)',
    perto($soma, -37735231.41) && perto($somaJan, -1467968.455), round($soma, 2) . ' / ' . round($somaJan, 3));
$prop = true;
foreach ($r['negocios'] as $x) {
    $prop = $prop && perto($x['parte'], $r['criterio_total'] > 0 ? $x['criterio'] / $r['criterio_total'] : 0.0, 1e-12)
        && perto($x['valor'], $x['parte'] * -37735231.41, 0.01);
}
prova('a parte de cada negócio é o critério dele sobre o de todos, e o valor é a parte × o total', $prop);
prova('a Usina, sem despesa no critério, não recebe rateio', perto($r['negocios'][$ids['17']]['valor'] ?? -1, 0.0));

// ── 3. Na tela: travada, filiais, Painel e ao vivo ───────────────────────────
$agro = $montar->invoke($ctrl, $ids['8'], $ano, $corte, 0);
$l161 = $linha($agro, '16.1');
prova('na tela, a 16.1 das Agropecuárias é a parte delas, travada, com os 12 meses',
    perto($l161['proposta'], $r['negocios'][$ids['8']]['valor']) && $l161['proposta_origem'] === 'RATEIO_ADM'
    && $l161['proposta_travada'] === true && count($l161['proposta_meses']) === 12,
    round($l161['proposta'], 2) . ' · ' . round($l161['proposta_parte'] * 100, 3) . '%');
$filiais = 0.0;
foreach ($agro['filiais'] as $f) {
    $tf = $montar->invoke($ctrl, $ids['8'], $ano, $corte, (int)$f['id']);
    $filiais += (float)array_column($tf['filial']['linhas'], null, 'codigo')['16.1']['proposta'];
}
prova('as filiais das Agropecuárias somam o rateio do negócio (dividido pelo critério de cada uma)',
    $agro['filiais'] && perto($filiais, $l161['proposta'], 0.05), round($filiais, 2) . ' / ' . round($l161['proposta'], 2));
$painel = Projecao::resumoDosNegocios(array_keys($r['negocios']), $ano, $corte, $anoPlan, ['16.1', '18']);
prova('o Painel faz a mesma conta (16.1 de cada negócio = a da tela; soma = o total)',
    perto((float)$painel[$ids['8']]['proposta']['16.1'], $l161['proposta'])
    && perto(array_sum(array_map(static fn ($x) => (float)$x['proposta']['16.1'], $painel)), -37735231.41));
prova('o rateio entra no resultado: resultado líquido = resultado do negócio + rateios administrativos (16.1)',
    perto((float)$linha($agro, '18')['proposta'], (float)$linha($agro, '15')['proposta'] + (float)$linha($agro, '16')['proposta'], 1)
    && perto((float)$linha($agro, '16')['proposta'], $l161['proposta'], 1));
prova('e o desafio também carrega a 16.1 travada (a parte do negócio)',
    isset($agro['desafio']['escada']['desafio']['rateios_adm'])
    && perto((float)$agro['desafio']['escada']['desafio']['rateios_adm'], $l161['proposta'], 1),
    (string)round((float)($agro['desafio']['escada']['desafio']['rateios_adm'] ?? 0)));

// Ao vivo: lançar o dobro do pessoal no Leite aumenta a parte dele e tira dos
// outros; o total continua o da planilha. A prova apaga o que lançou.
$leite = $ids['4'];
$proj = Database::um('SELECT id FROM projecao WHERE negocio_id = ? AND ano = ?', [$leite, $anoPlan]);
$criada = !$proj;
if ($criada) {
    $pdo->prepare("INSERT INTO projecao (negocio_id, ano, modo, versao, atualizado_em) VALUES (?, ?, 'NEGOCIO', 1, NOW())")
        ->execute([$leite, $anoPlan]);
    $proj = Database::um('SELECT id FROM projecao WHERE negocio_id = ? AND ano = ?', [$leite, $anoPlan]);
}
$conta85 = (int)Database::um("SELECT id FROM dre_conta WHERE codigo = '8.5'")['id'];
$antes = Database::um('SELECT entrada, valor, percentual FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?',
    [$proj['id'], $conta85]);
$p85 = (float)$linha($montar->invoke($ctrl, $leite, $ano, $corte, 0), '8.5')['proposta'];
try {
    $pdo->prepare('DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?')->execute([$proj['id'], $conta85]);
    $pdo->prepare("INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor) VALUES (?, 0, ?, 'VALOR', ?)")
        ->execute([$proj['id'], $conta85, round($p85 * 2, 2)]);
    $r2 = Projecao::rateioAdm($ano, $corte, $anoPlan);
    $l2 = $r2['negocios'][$leite];
    $l1 = $r['negocios'][$leite];
    prova('ao vivo: o Leite lança o dobro do pessoal, o critério dele sobe exatamente isso, a parte sobe e o total não muda',
        perto($l2['criterio'] - $l1['criterio'], -$p85, 1) && $l2['parte'] > $l1['parte']
        && perto(array_sum(array_column($r2['negocios'], 'valor')), -37735231.41)
        && $r2['negocios'][$ids['8']]['parte'] < $r['negocios'][$ids['8']]['parte'],
        round($l1['parte'] * 100, 3) . '% → ' . round($l2['parte'] * 100, 3) . '%');
} finally {
    $pdo->prepare('DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?')->execute([$proj['id'], $conta85]);
    if ($antes) {
        $pdo->prepare('INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor, percentual) VALUES (?, 0, ?, ?, ?, ?)')
            ->execute([$proj['id'], $conta85, $antes['entrada'], $antes['valor'], $antes['percentual']]);
    }
    if ($criada) {
        $pdo->prepare('DELETE FROM projecao WHERE id = ?')->execute([$proj['id']]);
    }
}
prova('depois de desfazer, o rateio volta ao de antes', perto(Projecao::rateioAdm($ano, $corte, $anoPlan)['negocios'][$leite]['valor'],
    $r['negocios'][$leite]['valor']));

// ── 4. A API recusa o lançamento na 16.1 ─────────────────────────────────────
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'rat');
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
    echo "  ⏭  seção 4 pulada: a aplicação não responde em {$base}.\n";
} else {
    $http('POST', "{$base}/api/login", ['email' => getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', 'senha' => getenv('APP_SENHA') ?: 'trocar123']);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', "{$base}/"), $mm);
    $cab = ['X-CSRF-Token: ' . ($mm[1] ?? '')];
    $resp = json_decode($http('POST', "{$base}/api/projecao/valor",
        ['negocio_id' => $ids['8'], 'conta' => '16.1', 'entrada' => 'VALOR', 'valor' => -1], $cab), true);
    prova('a API recusa lançar no Rateio Corporativo Administrativo',
        str_contains((string)($resp['erro'] ?? ''), 'não se lança'), json_encode($resp['erro'] ?? $resp, JSON_UNESCAPED_UNICODE));
    $resp = json_decode($http('POST', "{$base}/api/projecao/valor",
        ['negocio_id' => $ids['8'], 'conta' => '16.1', 'entrada' => 'VALOR', 'valor' => ''], $cab), true);
    prova('apagar (valor vazio) continua aceito, para limpar lançamento de antes', !isset($resp['erro']),
        substr(json_encode($resp['erro'] ?? 'ok', JSON_UNESCAPED_UNICODE), 0, 120));
}
@unlink($jar);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

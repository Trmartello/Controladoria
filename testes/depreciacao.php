<?php

/**
 * Provas da DEPRECIAÇÃO carregada na proposta (pedido do cliente, 2026-11-23:
 * "Pegue na depreciação por negócio, por filial e por mês. Na conta número
 * 3128. E este campo não deve permitir edição, pois esse já é o total da
 * depreciação").
 *
 * Os números de mão vêm da planilha de conferência entregue ao cliente
 * (`Depreciacao_2027_por_negocio_e_filial.xlsx`): bens existentes +
 * investimentos sem os destinos contábeis 1 e 3, 2027. Uma prova que refizesse a
 * soma com o mesmo código passaria sem provar nada.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/depreciacao.php
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
require_once __DIR__ . '/../app/Services/CargaDepreciacao.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaDepreciacao;
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

// ── 1. A carga ──────────────────────────────────────────────────────────────
$carga = require __DIR__ . '/../database/depreciacao_2027.php';
prova('o arquivo é da conta 3128, de 2027, com o total da planilha (R$ 18.595.630,53)',
    $carga['conta'] === '3128' && $carga['ano'] === 2027 && perto($carga['total'], 18595630.53));
$dep = Projecao::depreciacao(array_values($ids), $anoPlan);
// De mão, da planilha de conferência (aba "Por negócio").
$esperado = ['1' => 8872439.62, '2' => 6080.28, '4' => 52475.14, '6' => 2986822.68, '7' => 64700.14,
    '8' => 3051070.35, '9' => 1512823.52, '11' => 631279.11, '12' => 220683.04, '13' => 577306.92,
    '17' => 357688.18];
$diverge = [];
foreach ($esperado as $cod => $v) {
    $t = -array_sum($dep[$ids[$cod]]['negocio'] ?? []);
    if (!perto($t, $v, 0.01)) {
        $diverge[] = "{$cod}: " . round($t, 2) . " ≠ {$v}";
    }
}
prova('o total de cada um dos 11 negócios é o da planilha de conferência', !$diverge, implode(' | ', $diverge));
$total = 0.0;
foreach ($dep as $d) {
    $total -= array_sum($d['negocio']);
}
prova('carregados R$ 18.333.368,98: o total menos os negócios 18 e 20, que não existem no sistema (R$ 262.261,55)',
    perto($total, 18595630.53 - 31666.70 - 230594.85, 0.01), (string)round($total, 2));
$soFiliais = static fn (int $id): float => -array_sum(array_map('array_sum', $dep[$id]['filiais'] ?? []));
prova('as filiais somam o negócio; em Cereais faltam R$ 553,56 das filiais 47 e 100, fora do cadastro dele',
    perto($soFiliais($ids['8']), 3051070.35) && perto($soFiliais($ids['9']), 1512823.52)
    && perto(-array_sum($dep[$ids['1']]['negocio']) - $soFiliais($ids['1']), 163.80 + 389.76),
    (string)round(-array_sum($dep[$ids['1']]['negocio']) - $soFiliais($ids['1']), 2));
// Mês a mês: a Cereais, filial 10, de mão (bens + investimentos da planilha).
$f10 = Database::um("SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = '10'", [$ids['1']]);
prova('mês a mês: Cereais filial 10 tem R$ 4.820,43 em jan e R$ 4.776,25 em dez',
    $f10 && perto(-$dep[$ids['1']]['filiais'][(int)$f10['id']][1], 4820.43) && perto(-$dep[$ids['1']]['filiais'][(int)$f10['id']][12], 4776.25));
prova('a depreciação é despesa: gravada negativa',
    max(array_merge(...array_map(static fn ($d) => array_values($d['negocio']), array_values($dep)))) <= 0);

// As recusas, sem gravar nada (ano 2099).
$recusa = function (array $x) use ($pdo): string {
    try {
        CargaDepreciacao::aplicar($pdo, $x);
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$t = ['ano' => 2099, 'total' => 24.0, 'valores' => ['8' => ['2' => array_fill(0, 12, 2.0)]]];
$m = $t;
$m['total'] = 25.0;
prova('total declarado que não fecha com as linhas é recusado', str_contains($recusa($m), 'não fecha'));
$m = $t;
$m['valores']['8']['2'] = array_fill(0, 11, 2.0);
prova('filial sem os 12 meses é recusada', str_contains($recusa($m), '12 meses'));
$m = $t;
$m['valores']['8']['2'][3] = -1.0;
$m['total'] = 21.0;
prova('valor negativo (ou texto) é recusado', str_contains($recusa($m), 'valor inválido'));
prova('nada das cargas recusadas foi gravado', (int)Database::um('SELECT COUNT(*) n FROM negocio_depreciacao WHERE ano = 2099')['n'] === 0);
$r = CargaDepreciacao::aplicar($pdo, ['ano' => 2099, 'total' => 36.0, 'valores' => [
    '8' => ['2' => array_fill(0, 12, 2.0), '99999' => array_fill(0, 12, 1.0)],
]]);
$n = Database::um('SELECT SUM(valor) s FROM negocio_depreciacao WHERE ano = 2099 AND filial_id = 0');
prova('filial fora do cadastro do negócio entra só no total dele, e a carga avisa',
    isset($r['filiais_so_no_negocio']['8/99999']) && perto((float)$n['s'], -36.0), json_encode($r['filiais_so_no_negocio']));
$r = CargaDepreciacao::aplicar($pdo, ['ano' => 2099, 'total' => 12.0, 'valores' => ['999' => ['1' => array_fill(0, 12, 1.0)]]]);
prova('negócio fora do cadastro fica fora, avisado, e recarregar reescreve o ano',
    isset($r['negocios_ignorados']['999']) && (int)Database::um('SELECT COUNT(*) n FROM negocio_depreciacao WHERE ano = 2099')['n'] === 0);

// ── 2. O motor: a linha 8.10 travada ─────────────────────────────────────────
$linhas = [
    ['codigo' => '8.10', 'nome' => 'Depreciação', 'nivel' => 2, 'percentual' => 0, 'realizado' => -80.0, 'projetado' => -20.0, 'projecao' => -100.0],
    ['codigo' => '8.11', 'nome' => 'Manutenção', 'nivel' => 2, 'percentual' => 0, 'realizado' => -40.0, 'projetado' => -10.0, 'projecao' => -50.0],
];
$fixos = Projecao::fixosDaDepreciacao(['negocio' => array_fill(1, 12, -10.0), 'filiais' => []]);
$lanc = ['8.10' => ['entrada' => 'VALOR', 'valor' => -500.0, 'percentual' => null],
    '8.11' => ['entrada' => 'VALOR', 'valor' => -60.0, 'percentual' => null]];
$a = array_column(Projecao::aplicar($linhas, $lanc, [], null, $fixos), null, 'codigo');
prova('a 8.10 vale a carga (−120) mesmo com −500 lançado; a 8.11 segue o lançado (−60)',
    perto($a['8.10']['proposta'], -120.0) && $a['8.10']['proposta_origem'] === 'DEPRECIACAO' && $a['8.10']['proposta_travada'] === true
    && perto($a['8.11']['proposta'], -60.0) && empty($a['8.11']['proposta_travada']),
    json_encode([$a['8.10']['proposta'], $a['8.11']['proposta']]));
prova('o lançamento antigo fica visível como ignorado, e os 12 meses descem para a tela',
    ($a['8.10']['lancado_ignorado']['valor'] ?? null) === -500.0 && count($a['8.10']['proposta_meses']) === 12 && $a['8.10']['proposta_conta'] === '3128');
$sem = array_column(Projecao::aplicar($linhas, $lanc, [], null, []), null, 'codigo');
prova('negócio sem carga: a 8.10 volta a ser lançável (−500)', perto($sem['8.10']['proposta'], -500.0) && empty($sem['8.10']['proposta_travada']));
$fx = Projecao::fixosDaDepreciacao(['negocio' => array_fill(1, 12, -10.0), 'filiais' => [7 => array_fill(1, 12, -1.0)]], 8);
prova('filial sem depreciação num negócio com carga vale ZERO (a carga é o total)', perto($fx['8.10']['valor'], 0.0));

// ── 3. Com o banco: tela, filiais, Painel ────────────────────────────────────
$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$diverge = [];
foreach ($esperado as $cod => $v) {
    $l = array_column($montar->invoke($ctrl, $ids[$cod], $ano, $corte, 0)['linhas'], null, 'codigo')['8.10'];
    if (!perto(-$l['proposta'], $v, 0.01) || empty($l['proposta_travada'])) {
        $diverge[] = "{$cod}: " . round(-$l['proposta'], 2);
    }
}
prova('na tela, a 8.10 de cada negócio é a carga, travada', !$diverge, implode(' | ', $diverge));
$mAgro = $montar->invoke($ctrl, $ids['8'], $ano, $corte, 0);
$l = array_column($mAgro['linhas'], null, 'codigo')['8.10'];
prova('Agropecuárias: as filiais distribuem a depreciação inteira (resíduo zero)',
    perto((float)$l['distribuido'], (float)$l['proposta'], 0.05), round($l['distribuido'], 2) . ' / ' . round($l['proposta'], 2));
$l = array_column($montar->invoke($ctrl, $ids['1'], $ano, $corte, 0)['linhas'], null, 'codigo')['8.10'];
prova('Cereais: o resíduo a distribuir é o das filiais fora do cadastro (R$ 553,56)',
    perto((float)$l['distribuido'] - (float)$l['proposta'], 553.56, 0.05), (string)round((float)$l['distribuido'] - (float)$l['proposta'], 2));
$f = Database::um('SELECT f.id FROM filial f JOIN negocio_depreciacao d ON d.filial_id = f.id
    WHERE f.negocio_id = ? AND d.ano = ? GROUP BY f.id ORDER BY SUM(d.valor) LIMIT 1', [$ids['8'], $anoPlan]);
$mf = $montar->invoke($ctrl, $ids['8'], $ano, $corte, (int)$f['id']);
$lf = array_column($mf['filial']['linhas'], null, 'codigo')['8.10'];
prova('na filial, a 8.10 é a dela, travada',
    !empty($lf['proposta_travada']) && perto($lf['proposta'], array_sum($dep[$ids['8']]['filiais'][(int)$f['id']])));
$painel = Projecao::resumoDosNegocios(array_values($ids), $ano, $corte, $anoPlan, ['8.10', '18']);
$diverge = [];
foreach ($esperado as $cod => $v) {
    if (!perto(-($painel[$ids[$cod]]['proposta']['8.10'] ?? 0), $v, 0.01)) {
        $diverge[] = "{$cod}: " . round($painel[$ids[$cod]]['proposta']['8.10'] ?? 0, 2);
    }
}
prova('o Painel faz a mesma conta (8.10 de cada negócio = a carga)', !$diverge, implode(' | ', $diverge));
// Modo filial: o total do negócio continua sendo a carga (não a soma das filiais).
// Cereais pode não ter projeção gravada: a prova cria uma e a apaga no fim.
$projCer = Database::um('SELECT id, modo FROM projecao WHERE negocio_id = ? AND ano = ?', [$ids['1'], $anoPlan]);
$criada = !$projCer;
if ($criada) {
    $pdo->prepare('INSERT INTO projecao (negocio_id, ano, modo, atualizado_em) VALUES (?, ?, ?, NOW())')
        ->execute([$ids['1'], $anoPlan, 'NEGOCIO']);
    $projCer = Database::um('SELECT id, modo FROM projecao WHERE negocio_id = ? AND ano = ?', [$ids['1'], $anoPlan]);
}
{
    $pdo->prepare('UPDATE projecao SET modo = ? WHERE id = ?')->execute(['FILIAL', $projCer['id']]);
    try {
        $tela = array_column($montar->invoke($ctrl, $ids['1'], $ano, $corte, 0)['linhas'], 'proposta', 'codigo');
        $pn = Projecao::resumoDosNegocios([$ids['1']], $ano, $corte, $anoPlan, ['8.10', '18'])[$ids['1']]['proposta'];
        prova('modo filial: a 8.10 de Cereais é a carga inteira, e o Painel = tela (8.10 e resultado)',
            perto(-$tela['8.10'], 8872439.62) && perto($pn['8.10'], $tela['8.10']) && perto($pn['18'], $tela['18'], 1),
            json_encode([$tela['8.10'], $pn['8.10'], $tela['18'], $pn['18']]));
    } finally {
        $criada
            ? $pdo->prepare('DELETE FROM projecao WHERE id = ?')->execute([$projCer['id']])
            : $pdo->prepare('UPDATE projecao SET modo = ? WHERE id = ?')->execute([$projCer['modo'], $projCer['id']]);
    }
}

// ── 4. A API recusa o lançamento na 8.10 ─────────────────────────────────────
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'dep');
$http = static function (string $metodo, string $url, ?array $corpo = null, array $cab = []) use ($jar): string {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cab)]);
    if ($corpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    $r = curl_exec($c);
    curl_close($c);
    return (string)$r;
};
if ($http('GET', "{$base}/login") === '') {
    echo "  ⏭  seção 4 pulada: a aplicação não responde em {$base}.\n";
} else {
    $http('POST', "{$base}/api/login", ['email' => getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', 'senha' => getenv('APP_SENHA') ?: 'trocar123']);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', "{$base}/"), $mm);
    $cab = ['X-CSRF-Token: ' . ($mm[1] ?? '')];
    $resp = json_decode($http('POST', "{$base}/api/projecao/valor",
        ['negocio_id' => $ids['8'], 'conta' => '8.10', 'entrada' => 'VALOR', 'valor' => -1], $cab), true);
    prova('a API recusa lançar na Depreciação (é o total carregado)',
        str_contains((string)($resp['erro'] ?? ''), 'não se lança'), json_encode($resp, JSON_UNESCAPED_UNICODE));
    $resp = json_decode($http('POST', "{$base}/api/projecao/valor",
        ['negocio_id' => $ids['8'], 'conta' => '8.10', 'entrada' => 'VALOR', 'valor' => ''], $cab), true);
    prova('apagar (valor vazio) continua aceito, para limpar lançamento de antes da carga', !isset($resp['erro']),
        substr(json_encode($resp['erro'] ?? 'ok', JSON_UNESCAPED_UNICODE), 0, 120));
}
@unlink($jar);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

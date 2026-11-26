<?php

/**
 * Provas do RATEIO CORPORATIVO NEGÓCIO na proposta (linha 14.3; pedido do cliente,
 * 2026-11-24: "distribua o valor mensalmente por negócios […] olhando para essa
 * planilha […] respeitar a distribuição somente para o Negócio que está mencionado
 * na coluna H; quando a coluna F menciona as filiais, observar somente essas
 * filiais […]; lançar na célula rateio corporativo negócio, bloquear célula").
 *
 * Os números de mão vêm das planilhas `Rateio Corporativo Negocios 2027.xlsm`
 * (coluna K "Saldo") e `Critérios de rateios.xlsx`.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/rateio_negocios.php
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
$carga = Projecao::cargaRateioNegocios($anoPlan);
// A planilha de 25/11 (pedido do cliente: "vamos atualizar o rateio corporativo da mesma
// forma"): sem o 5163 CENTRAL DE COMPRAS (que ficava fora, "apenas planejar") e sem o 5112
// GERÊNCIA SUINOCULTURA; todos os centros dela têm regra.
prova('a carga é a planilha de 25/11: −R$ 37.678.709,766, inteira (sem o 5163 e o 5112, que ela não traz)',
    $carga && perto((float)$carga['total_planilha'], -37678709.766, 0.001) && perto((float)$carga['total'], -37678709.766, 0.001)
    && ($carga['fora'] ?? null) === [] && !isset($carga['centros'][5163]) && !isset($carga['centros'][5112]),
    json_encode([$carga['total_planilha'] ?? null, $carga['total'] ?? null]));
prova('as regras da planilha de critérios: 5185 (Regional 3) só Agro e Super, nas filiais listadas; 5224 (Tecnoeste) todos menos 17 e 5',
    $carga['centros'][5185]['negocios'] === ['8', '9'] && in_array(132, $carga['centros'][5185]['filiais'], true)
    && $carga['centros'][5224]['negocios'] === ['TODOS', 'exceto' => ['5', '17']] && $carga['centros'][5224]['filiais'] === 'TODAS');

// ── 2. A distribuição ────────────────────────────────────────────────────────
$r = Projecao::rateios($ano, $corte, $anoPlan)['neg'];
$neg = $r['negocios'];
$soma = array_sum(array_column($neg, 'valor'));
$jan = array_sum(array_map(static fn ($x) => $x['meses'][1], $neg));
$janPlanilha = array_sum(array_map(static fn ($c) => $c['meses'][1], $carga['centros']));
prova('os negócios somam o total rateado (−37.678.709,77), e janeiro soma o janeiro da planilha',
    perto($soma, -37678709.766) && perto($jan, $janPlanilha), round($soma, 2) . ' / ' . round($jan, 2));
$centro = static function (int $cc) use ($neg, $cod): array {
    $out = [];
    foreach ($neg as $id => $x) {
        if (isset($x['centros'][$cc])) {
            $out[$cod[$id]] = $x['centros'][$cc];
        }
    }
    return $out;
};
// De mão (coluna K, somada no ano): centros de um negócio só vão inteiros a ele.
prova('centro de um negócio só vai inteiro a ele: 5109 Gerência Cereais → Cereais −587.597,50; 5139 Gerência Indústrias → Rações −251.723,96',
    array_keys($centro(5109)) == ['1'] && perto($centro(5109)['1'], -587597.50)
    && array_keys($centro(5139)) == ['6'] && perto($centro(5139)['6'], -251723.96));
prova('e soma as filiais de origem: 5151 Agropecuária Compra/Venda (Matriz + Central de Compras) → Agro −1.897.205,21; 5170 Mercado-Geral → Super −5.881.622,45',
    perto($centro(5151)['8'] ?? 0, -1897205.21) && perto($centro(5170)['9'] ?? 0, -5881622.45));
$reg3 = $centro(5185);
prova('5185 Gerência Regional 3: só Agro e Super, somando os −412.257,64 da planilha',
    array_keys($reg3) == ['8', '9'] || array_keys($reg3) == ['9', '8'] ? perto(array_sum($reg3), -412257.64) : false, json_encode($reg3));
$tec = $centro(5224);
prova('5224 Tecnoeste: todos os negócios menos a Usina (17), somando os −730.675,46',
    !isset($tec['17']) && count($tec) >= 9 && perto(array_sum($tec), -730675.46), json_encode(array_map('round', $tec)));
$eng = $centro(5192);
prova('5192 Engenheiros agrônomos (faturamento de Cereais e Agro): só os dois, pela proporção da receita bruta proposta',
    count($eng) === 2 && isset($eng['1'], $eng['8']) && perto(array_sum($eng), -11631238.71), json_encode(array_map('round', $eng)));
$filiaisOk = true;
foreach ($neg as $x) {
    $filiaisOk = $filiaisOk && perto(array_sum(array_map('array_sum', $x['filiais'])), $x['valor'], 0.05);
}
prova('em cada negócio, as filiais somam o rateio dele', $filiaisOk);
// Pecuária: sem o 5112 (a planilha de 25/11 não o traz), leva só a parte do Tecnoeste.
$pec = $ids['2'];
prova('Pecuária: sem o 5112, o rateio dela é só a parte do Tecnoeste',
    perto((float)($neg[$pec]['valor'] ?? 0), (float)($tec['2'] ?? 0), 0.01) && !isset($neg[$pec]['centros'][5112]),
    round((float)($neg[$pec]['valor'] ?? 0)) . ' / ' . round((float)($tec['2'] ?? 0)));

// ── 3. Na tela: travada, filiais, Painel, desafio ────────────────────────────
$agro = $montar->invoke($ctrl, $ids['8'], $ano, $corte, 0);
$l143 = $linha($agro['linhas'], '14.3');
prova('na tela, a 14.3 das Agropecuárias é o rateio delas, travada, com os 12 meses',
    perto($l143['proposta'], $neg[$ids['8']]['valor']) && $l143['proposta_origem'] === 'RATEIO_NEG'
    && $l143['proposta_travada'] === true && count($l143['proposta_meses']) === 12, (string)round($l143['proposta'], 2));
$f0 = array_key_first($neg[$ids['8']]['filiais']);
$tf = $montar->invoke($ctrl, $ids['8'], $ano, $corte, (int)$f0);
prova('na filial, a 14.3 é a dela, travada', perto($linha($tf['filial']['linhas'], '14.3')['proposta'], array_sum($neg[$ids['8']]['filiais'][$f0]))
    && $linha($tf['filial']['linhas'], '14.3')['proposta_origem'] === 'RATEIO_NEG');
$painel = Projecao::resumoDosNegocios(array_keys($neg), $ano, $corte, $anoPlan, ['14.3']);
prova('o Painel faz a mesma conta (soma da 14.3 = o total rateado)',
    perto(array_sum(array_map(static fn ($x) => (float)$x['proposta']['14.3'], $painel)), -37678709.766));
prova('o bloco 12 (Rateios do negócio) soma 14.1 + 14.2 + 14.3, e o desafio carrega a 14.3 travada',
    perto((float)$linha($agro['linhas'], '14')['proposta'], (float)$linha($agro['linhas'], '14.1')['proposta']
        + (float)$linha($agro['linhas'], '14.2')['proposta'] + $l143['proposta'], 1)
    && isset($agro['rateio_neg']['centros'][5152]));

// Ao vivo: o Leite lança o dobro do pessoal; a parte dele no Tecnoeste (critério de
// despesas, todos os negócios) sobe, e o total não muda. A prova apaga o que lançou.
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
$p85 = (float)$linha($montar->invoke($ctrl, $leite, $ano, $corte, 0)['linhas'], '8.5')['proposta'];
try {
    $pdo->prepare('DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?')->execute([$proj['id'], $conta85]);
    $pdo->prepare("INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor) VALUES (?, 0, ?, 'VALOR', ?)")
        ->execute([$proj['id'], $conta85, round($p85 * 2, 2)]);
    $r2 = Projecao::rateios($ano, $corte, $anoPlan)['neg']['negocios'];
    prova('ao vivo: o Leite dobra o pessoal, a parte dele no Tecnoeste sobe e o total não muda',
        ($r2[$leite]['centros'][5224] ?? 0) < ($neg[$leite]['centros'][5224] ?? 0)
        && perto(array_sum(array_column($r2, 'valor')), -37678709.766),
        round($neg[$leite]['centros'][5224] ?? 0) . ' → ' . round($r2[$leite]['centros'][5224] ?? 0));
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

// ── 4. A API recusa o lançamento na 14.3 ─────────────────────────────────────
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'ratn');
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
        ['negocio_id' => $ids['8'], 'conta' => '14.3', 'entrada' => 'VALOR', 'valor' => -1], $cab), true);
    prova('a API recusa lançar no Rateio Corporativo Negócio',
        str_contains((string)($resp['erro'] ?? ''), 'não se lança'), json_encode($resp['erro'] ?? $resp, JSON_UNESCAPED_UNICODE));
}
@unlink($jar);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

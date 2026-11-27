<?php

/**
 * "Essas duas tabelas devem ser o reflexo da DRE" (cliente, 2026-11-27): o quadro
 * "2026: realizado, planejado e visão realista" e o cartão "Desafio 2027" mostram os
 * MESMOS números da DRE do negócio — a receita bruta inclusive, sem o PIS/COFINS, em
 * todas as colunas (2026 também). Só lê, pela API, como ADMIN; não grava nada.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/reflexo_dre.php
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
        echo "  ✓ {$nome}\n";
    } else {
        $bad[] = $nome;
        echo "  ✗ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'ref');
$http = static function (string $metodo, string $caminho, ?array $corpo = null) use ($jar, $base) {
    $c = curl_init($base . $caminho);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($corpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    $r = (string)curl_exec($c);
    curl_close($c);
    $j = json_decode($r, true);
    return $j['dados'] ?? $j;
};
if (@file_get_contents($base . '/login') === false) {
    echo "  ⏭  pulada: a aplicação não responde em {$base}.\n";
    exit(0);
}
$http('POST', '/api/login', ['email' => getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', 'senha' => getenv('APP_SENHA') ?: 'trocar123']);

$igual = static fn ($a, $b) => $a !== null && $b !== null && abs((float)$a - (float)$b) < 1;
$ids = array_map(static fn ($r) => (int)$r['id'], Database::todos('SELECT DISTINCT negocio_id AS id FROM dre_valor WHERE ano = 2026 ORDER BY 1'));
$falhas = ['quadro' => [], 'cartao' => [], 'escada' => [], 'partes' => [], 'ano' => []];
$somaRes = ['desafio' => 0.0, 'proposta' => 0.0];
foreach ($ids as $id) {
    $d = $http('GET', "/api/projecao?negocio_id={$id}");
    if (empty($d['linhas'])) {
        continue;
    }
    $dre = array_column($d['linhas'], null, 'codigo');
    $des = array_column($d['desafio_linhas'] ?? [], 'proposta', 'codigo');
    $q = $d['visao_ano'];
    $e = $d['desafio']['escada'] ?? null;
    // Quadro: receita bruta = linha 1 da DRE (sem o PIS/COFINS) no realizado, no Real + Plan.,
    // na proposta e no desafio; as demais linhas do quadro também.
    foreach (['1', '3', '5', '7', '15', '18'] as $c) {
        $par = [[$q[$c]['realizado'], $dre[$c]['realizado']], [$q[$c]['real_plan'], $dre[$c]['projecao']],
            [$q[$c]['proposta'] ?? null, $dre[$c]['proposta']], [$q[$c]['desafio'] ?? null, $des[$c] ?? null]];
        foreach ($par as $i => [$a, $b]) {
            if (!$igual($a, $b)) {
                $falhas['quadro'][] = "{$id}/{$c}/{$i}: {$a} × {$b}";
            }
        }
    }
    if (!$e) {
        $falhas['cartao'][] = "{$id}: sem escada";
        continue;
    }
    // Cartão: cada degrau do desafio e da proposta = a linha da DRE.
    $mapa = ['receita_bruta' => '1', 'receita_liquida' => '3', 'lucro_bruto' => '5', 'despesas' => '7',
        'resultado_antes_rateios' => '13', 'rateios_negocio' => '14', 'rateio_matriz' => '14.1',
        'rateio_financeiro' => '14.2', 'rateio_corporativo' => '14.3', 'resultado_negocio' => '15',
        'rateios_adm' => '16', 'resultado' => '18'];
    foreach ($mapa as $k => $c) {
        if (!$igual($e['proposta'][$k], $dre[$c]['proposta'])) {
            $falhas['cartao'][] = "{$id} proposta {$k}: {$e['proposta'][$k]} × {$dre[$c]['proposta']}";
        }
        if (!$igual($e['desafio'][$k], $des[$c] ?? null)) {
            $falhas['cartao'][] = "{$id} desafio {$k}";
        }
    }
    // Colunas de 2026 do cartão = as do quadro; e a escada fecha em todas as colunas.
    foreach (['receita_bruta' => '1', 'receita_liquida' => '3', 'resultado_negocio' => '15', 'resultado' => '18'] as $k => $c) {
        if (!$igual($e['real_plan'][$k], $q[$c]['real_plan']) || !$igual($e['realista'][$k], $q[$c]['real_realista'])) {
            $falhas['ano'][] = "{$id} {$k}";
        }
    }
    // Nas colunas de 2026 a própria carga da DRE traz arredondamento de poucos reais entre a
    // linha e a soma das partes (15 ≠ 13 + 14 por R$ 1 em alguns negócios): tolerância de R$ 5.
    foreach (['real_plan', 'realista', 'desafio', 'proposta'] as $col) {
        $x = $e[$col];
        $igual = static fn ($a, $b) => $a !== null && $b !== null && abs((float)$a - (float)$b) < ($col === 'real_plan' || $col === 'realista' ? 5 : 1);
        if (!$igual($x['resultado_antes_rateios'] + $x['rateios_negocio'], $x['resultado_negocio'])
            || !$igual($x['resultado_negocio'] + $x['rateios_adm'], $x['resultado'])) {
            $falhas['escada'][] = "{$id} {$col}";
        }
        if (!$igual(($x['rateio_matriz'] ?? 0) + ($x['rateio_financeiro'] ?? 0) + ($x['rateio_corporativo'] ?? 0), $x['rateios_negocio'])) {
            $falhas['partes'][] = "{$id} {$col}";
        }
    }
    $igual = static fn ($a, $b) => $a !== null && $b !== null && abs((float)$a - (float)$b) < 1;
    $somaRes['desafio'] += (float)$e['desafio']['resultado'];
    $somaRes['proposta'] += (float)$e['proposta']['resultado'];
}
prova('quadro do ano = DRE (realizado, Real + Plan., proposta e desafio), receita bruta SEM o PIS/COFINS, em ' . count($ids) . ' negócios',
    !$falhas['quadro'], implode('; ', array_slice($falhas['quadro'], 0, 4)));
prova('cartão do desafio = DRE, degrau a degrau (proposta e desafio), receita bruta como a linha 1',
    !$falhas['cartao'], implode('; ', array_slice($falhas['cartao'], 0, 4)));
prova('as colunas Real + Plan. e Real + realista 2026 do cartão são as do quadro do ano',
    !$falhas['ano'], implode('; ', array_slice($falhas['ano'], 0, 4)));
prova('a escada fecha em todas as colunas: 11 + 12 = 14 e 14 + 15 = líquido',
    !$falhas['escada'], implode('; ', array_slice($falhas['escada'], 0, 4)));
prova('os rateios abertos (Matriz/Central + financeiros + corporativos) somam os rateios do negócio (12)',
    !$falhas['partes'], implode('; ', array_slice($falhas['partes'], 0, 4)));

// Corporativo: as mesmas colunas de desafio e proposta, e o total = a soma dos negócios.
$c = $http('GET', '/api/projecao?corporativo=1');
$tc = $c['desafio']['total_comparar'] ?? [];
$chaves = ['receita_bruta', 'receita_liquida', 'despesas', 'resultado_negocio', 'resultado', 'margem'];
prova('Corporativo: desafio e proposta com as MESMAS colunas (receita bruta, líquida, despesas, resultado do negócio e líquido)',
    array_keys($tc['desafio'] ?? []) == array_keys($tc['proposta'] ?? []) && !array_diff($chaves, array_keys($tc['desafio'] ?? [])),
    json_encode(array_keys($tc['desafio'] ?? [])));
prova('Corporativo: o resultado líquido total é a soma do resultado de cada negócio (desafio e proposta)',
    $igual($tc['desafio']['resultado'] ?? null, $somaRes['desafio']) && $igual($tc['proposta']['resultado'] ?? null, $somaRes['proposta']),
    json_encode([$tc['desafio']['resultado'] ?? null, $somaRes['desafio'], $tc['proposta']['resultado'] ?? null, $somaRes['proposta']]));
prova('Corporativo: a receita bruta do total também sai sem o PIS/COFINS (= a soma da linha 1 de cada DRE)',
    $igual($tc['proposta']['receita_bruta'] ?? null, $c['visao_ano_total']['1']['proposta'] ?? null),
    json_encode([$tc['proposta']['receita_bruta'] ?? null, $c['visao_ano_total']['1']['proposta'] ?? null]));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

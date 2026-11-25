<?php

/**
 * Provas do DESAFIO da administração, CONTA A CONTA (pedido do cliente,
 * 2026-11-25): a coluna Desafio da DRE, que só o ADMIN vê e lança; o desafio é a
 * proposta padrão com esses lançamentos, pelo mesmo motor da proposta; o cartão,
 * o Corporativo e os grupos resumem. Apagar devolve a proposta padrão.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/desafio_ajuste.php
 *
 * Bate na aplicação em APP_URL (padrão http://127.0.0.1:8099); sem ela de pé, as
 * provas da API são puladas. Apaga o que grava e o usuário de prova que cria.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/DesafioAjuste.php';

use App\Core\Database;

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

// ── A API ────────────────────────────────────────────────────────────────────
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[(string)$n['cod_negocio']] = (int)$n['id'];
}
$sessao = static function () use ($base): callable {
    $jar = tempnam(sys_get_temp_dir(), 'daj');
    $http = static function (string $metodo, string $caminho, ?array $corpo = null, array $cab = []) use ($jar, $base): string {
        $c = curl_init($base . $caminho);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cab)]);
        if ($corpo !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corpo));
        }
        $r = curl_exec($c);
        curl_close($c);
        return (string)$r;
    };
    return $http;
};
$entrar = static function (callable $http, string $email, string $senha): array {
    $http('POST', '/api/login', ['email' => $email, 'senha' => $senha]);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', '/'), $mm);
    return ['X-CSRF-Token: ' . ($mm[1] ?? '')];
};
$http = $sessao();
if ($http('GET', '/login') === '') {
    echo "  ⏭  provas da API puladas: a aplicação não responde em {$base}.\n";
} else {
    $cab = $entrar($http, getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', getenv('APP_SENHA') ?: 'trocar123');
    $neg = $ids['1'] ?? reset($ids);  // Cereais
    $ler = static fn (array $r) => $r['dados'] ?? $r;
    $linha = static fn (array $ls, string $c) => array_column($ls, null, 'codigo')[$c] ?? null;
    $tela = $ler(json_decode($http('GET', "/api/projecao?negocio_id={$neg}"), true));
    prova('o administrador recebe a coluna Desafio: as linhas do desafio e nenhum lançamento',
        !empty($tela['desafio_linhas']) && ($tela['desafio_lancados'] ?? null) === [] && empty($tela['desafio']['desafio']['ajustado']),
        json_encode([count($tela['desafio_linhas'] ?? []), $tela['desafio_lancados'] ?? null]));
    prova('o cartão não tem mais o ajuste agregado (só resume)', !array_key_exists('ajustes', $tela['desafio'] ?? []));
    prova('a coluna Desafio é a escada do cartão: resultado da linha 18 = resultado do desafio',
        perto((float)$linha($tela['desafio_linhas'], '18')['proposta'], (float)$tela['desafio']['desafio']['resultado'], 0.5));
    $calc = (float)$tela['desafio']['desafio']['resultado'];
    $p81 = (float)$linha($tela['desafio_linhas'], '8.1')['proposta'];
    $corp = static fn () => (float)($ler(json_decode($http('GET', '/api/projecao?corporativo=1'), true))['desafio']['total']['desafio']['resultado'] ?? 0);
    $corpAntes = $corp();

    // R$ 1 mi a mais de Despesas com Vendas, digitado como o valor da linha.
    $r = $ler(json_decode($http('POST', '/api/projecao/desafio',
        ['negocio_id' => $neg, 'conta' => '8.1', 'entrada' => 'VALOR', 'valor' => $p81 - 1000000], $cab), true));
    prova('lançar na 8.1 do desafio: a linha vale o lançado e o resultado do desafio desce R$ 1 mi',
        perto((float)$linha($r['desafio_linhas'] ?? [], '8.1')['proposta'], $p81 - 1000000, 0.5)
        && perto((float)$r['desafio']['desafio']['resultado'], $calc - 1000000, 1) && ($r['desafio_lancados'] ?? []) === ['8.1']
        && !empty($r['desafio']['desafio']['ajustado']),
        json_encode([$r['erro'] ?? null, $r['desafio']['desafio']['resultado'] ?? null], JSON_UNESCAPED_UNICODE));
    prova('a proposta do negócio não muda com o desafio',
        perto((float)$linha($r['linhas'], '18')['proposta'], (float)$linha($tela['linhas'], '18')['proposta'], 0.5));
    prova('o Corporativo soma o desafio lançado: o total desce o mesmo R$ 1 mi', perto($corp() - $corpAntes, -1000000, 1));
    $grupo = $ler(json_decode($http('GET', "/api/projecao?corporativo=1&negocios={$neg}"), true))['desafio']['total']['desafio'] ?? [];
    prova('o grupo de um negócio só tem o desafio dele', perto((float)($grupo['resultado'] ?? 0), $calc - 1000000, 1));

    $r = $ler(json_decode($http('POST', '/api/projecao/desafio',
        ['negocio_id' => $neg, 'conta' => '1.1', 'entrada' => 'PERCENTUAL', 'valor' => 20], $cab), true));
    prova('lançar em % na receita de vendas: o faturamento do desafio sobe e o resultado também',
        (float)$r['desafio']['escada']['desafio']['receita_bruta'] > (float)$tela['desafio']['escada']['desafio']['receita_bruta']
        && (float)$r['desafio']['desafio']['resultado'] > $calc - 1000000,
        json_encode($r['erro'] ?? null, JSON_UNESCAPED_UNICODE));

    $e = json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'conta' => '16.1', 'valor' => -1], $cab), true);
    prova('rateio travado (16.1) não se desafia', str_contains((string)($e['erro'] ?? ''), 'travada'), (string)($e['erro'] ?? ''));
    $e = json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'conta' => '6', 'valor' => -1], $cab), true);
    prova('bloco (soma das subcontas) não se desafia', str_contains((string)($e['erro'] ?? ''), 'subcontas'), (string)($e['erro'] ?? ''));
    $e = json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'conta' => '8.1', 'entrada' => 'PERCENTUAL', 'valor' => 5000], $cab), true);
    prova('percentual fora de faixa é recusado', str_contains((string)($e['erro'] ?? ''), 'fora de faixa'), (string)($e['erro'] ?? ''));

    // Um perfil que não é ADMIN não vê nem lança.
    $email = 'prova.desafio.' . getmypid() . '@teste.local';
    Database::executar("INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES ('Prova desafio', ?, ?, 'CONTROLADORIA', 1)",
        [$email, password_hash('prova123', PASSWORD_DEFAULT)]);
    try {
        $http2 = $sessao();
        $cab2 = $entrar($http2, $email, 'prova123');
        $t2 = $ler(json_decode($http2('GET', "/api/projecao?negocio_id={$neg}"), true));
        prova('perfil CONTROLADORIA não recebe a coluna Desafio', !array_key_exists('desafio_linhas', $t2) && !empty($t2['linhas']));
        prova('e vê no cartão que o desafio tem lançamento da administração', !empty($t2['desafio']['desafio']['ajustado']));
        $e = json_decode($http2('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'conta' => '8.1', 'valor' => -1], $cab2), true);
        prova('e não lança (403)', str_contains((string)($e['erro'] ?? ''), 'administradores'), (string)($e['erro'] ?? ''));
    } finally {
        Database::executar('DELETE FROM usuario WHERE email = ?', [$email]);
    }

    foreach (['8.1', '1.1'] as $c) {
        $r = $ler(json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'conta' => $c, 'valor' => null], $cab), true));
    }
    prova('apagar devolve a proposta padrão', perto((float)$r['desafio']['desafio']['resultado'], $calc, 0.01)
        && ($r['desafio_lancados'] ?? null) === [] && empty($r['desafio']['desafio']['ajustado']));
    prova('e o Corporativo volta ao que era', perto($corp(), $corpAntes, 0.01));
}
prova('nenhum desafio de prova ficou no banco',
    (int)Database::um('SELECT COUNT(*) AS n FROM negocio_desafio_valor')['n'] === 0);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

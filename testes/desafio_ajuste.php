<?php

/**
 * Provas do DESAFIO EXTRA dos administradores (pedido do cliente, 2026-11-25):
 * o ADMIN digita na coluna Desafio do cartão do negócio, nas linhas de valor,
 * para o ano todo; resultados e margens saem em cascata; o Corporativo e os
 * grupos somam os desafios ajustados; apagar devolve o calculado.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/desafio_ajuste.php
 *
 * A seção 2 bate na aplicação em APP_URL (padrão http://127.0.0.1:8099); sem
 * ela de pé, é pulada. Ela apaga o que grava e o usuário de prova que cria.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/DesafioAjuste.php';

use App\Core\Database;
use App\Services\DesafioAjuste;

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

// ── 1. A cascata, numa escada de mão ────────────────────────────────────────
// RB 1000 · RL 900 · LB 300 · despesas −200 · (outras linhas −10) → R13 90 ·
// rateios do negócio −20 → R15 70 · rateios adm −30 → R18 40.
$c = [
    'receita_bruta' => 1000.0, 'receita_liquida' => 900.0, 'lucro_bruto' => 300.0, 'margem_bruta' => 300 / 9,
    'despesas' => -200.0, 'resultado_antes_rateios' => 90.0, 'margem_antes_rateios' => 10.0,
    'rateios_negocio' => -20.0, 'resultado_negocio' => 70.0, 'margem_negocio' => 70 / 9,
    'rateios_adm' => -30.0, 'resultado' => 40.0, 'margem' => 40 / 9,
];
prova('sem ajuste, a escada é a calculada', DesafioAjuste::aplicar($c, []) === $c);

$e = DesafioAjuste::aplicar($c, ['receita_bruta' => 1100.0]);
prova('faturamento +10%: RL e margem bruta acompanham (o % se mantém), e o resultado sobe a diferença da margem',
    perto($e['receita_liquida'], 990) && perto($e['lucro_bruto'], 330) && perto($e['margem_bruta'], $c['margem_bruta'])
    && perto($e['resultado_antes_rateios'], 120) && perto($e['resultado_negocio'], 100) && perto($e['resultado'], 70),
    json_encode([$e['receita_liquida'], $e['lucro_bruto'], $e['resultado']]));
prova('e a margem líquida se refaz sobre a RL nova', perto($e['margem'], 70 / 990 * 100));

$e = DesafioAjuste::aplicar($c, ['receita_bruta' => 1100.0, 'lucro_bruto' => 320.0]);
prova('margem bruta digitada vale mesmo com o faturamento ajustado', perto($e['lucro_bruto'], 320) && perto($e['resultado'], 60));

$e = DesafioAjuste::aplicar($c, ['despesas' => -150.0]);
prova('despesas −150: antes dos rateios 140, líquido 90, RL não muda',
    perto($e['resultado_antes_rateios'], 140) && perto($e['resultado'], 90) && perto($e['receita_liquida'], 900));

$e = DesafioAjuste::aplicar($c, ['rateios_negocio' => -5.0, 'rateios_adm' => -10.0]);
prova('rateios: o do negócio muda o resultado do negócio, o adm só o líquido',
    perto($e['resultado_antes_rateios'], 90) && perto($e['resultado_negocio'], 85) && perto($e['resultado'], 75));

prova('despesa digitada positiva vira negativa quando a calculada é negativa (celular sem menos)',
    DesafioAjuste::normalizar('despesas', 150, -200) === -150.0 && DesafioAjuste::normalizar('rateios_adm', 10, -30) === -10.0);
prova('faturamento e despesa que já é crédito ficam como digitados',
    DesafioAjuste::normalizar('receita_bruta', 150, 1000) === 150.0 && DesafioAjuste::normalizar('despesas', 5, 3) === 5.0);

// ── 2. A API ─────────────────────────────────────────────────────────────────
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
    echo "  ⏭  seção 2 pulada: a aplicação não responde em {$base}.\n";
} else {
    $cab = $entrar($http, getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', getenv('APP_SENHA') ?: 'trocar123');
    $neg = $ids['4'] ?? reset($ids);  // Cereais
    $ler = static fn (array $r) => $r['dados'] ?? $r;
    $tela = $ler(json_decode($http('GET', "/api/projecao?negocio_id={$neg}"), true));
    $x = $tela['desafio'] ?? [];
    prova('o administrador vê o desafio ajustável, nas cinco linhas de valor',
        !empty($x['pode_ajustar']) && ($x['ajustaveis'] ?? []) === DesafioAjuste::CHAVES && empty((array)($x['ajustes'] ?? [])),
        json_encode([$x['pode_ajustar'] ?? null, $x['ajustaveis'] ?? null]));
    $calc = $x['escada']['desafio'] ?? [];
    $corp = static fn () => $ler(json_decode($http('GET', '/api/projecao?corporativo=1'), true))['desafio']['total']['desafio'] ?? [];
    $corpAntes = $corp();

    $novaRb = round((float)$calc['receita_bruta'] * 1.10, 2);
    $r = $ler(json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'chave' => 'receita_bruta', 'valor' => $novaRb], $cab), true));
    $e = $r['desafio']['escada']['desafio'] ?? [];
    prova('gravar o faturamento: a escada vem ajustada, com a RL e a margem bruta em proporção',
        perto((float)($e['receita_bruta'] ?? 0), $novaRb, 0.01) && perto((float)$e['receita_liquida'], $calc['receita_liquida'] * $novaRb / $calc['receita_bruta'], 1)
        && perto((float)$e['margem_bruta'], (float)$calc['margem_bruta'], 0.0001),
        json_encode([$e['receita_bruta'] ?? null, $r['erro'] ?? null], JSON_UNESCAPED_UNICODE));
    prova('o calculado vai junto, intacto', perto((float)$r['desafio']['escada']['calculado']['receita_bruta'], (float)$calc['receita_bruta']));
    $delta = (float)$e['resultado'] - (float)$calc['resultado'];
    prova('os três números do desafio são os da escada ajustada',
        perto((float)$r['desafio']['desafio']['resultado'], (float)$e['resultado']) && !empty($r['desafio']['desafio']['ajustado']));

    $r = $ler(json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'chave' => 'despesas', 'valor' => 1000000], $cab), true));
    $e2 = $r['desafio']['escada']['desafio'] ?? [];
    prova('despesa digitada positiva grava negativa', perto((float)($e2['despesas'] ?? 0), -1000000),
        json_encode($e2['despesas'] ?? $r['erro'] ?? null, JSON_UNESCAPED_UNICODE));
    $delta = (float)$e2['resultado'] - (float)$calc['resultado'];

    $corpDepois = $corp();
    prova('o Corporativo soma o desafio ajustado: o resultado total muda o mesmo que o do negócio',
        perto((float)$corpDepois['resultado'] - (float)$corpAntes['resultado'], $delta, 1),
        json_encode([$corpDepois['resultado'] - $corpAntes['resultado'], $delta]));
    $grupo = $ler(json_decode($http('GET', "/api/projecao?corporativo=1&negocios={$neg}"), true))['desafio']['total']['desafio'] ?? [];
    prova('o grupo de um negócio só tem o desafio ajustado dele', perto((float)($grupo['resultado'] ?? 0), (float)$e2['resultado'], 1));

    $r = json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'chave' => 'resultado', 'valor' => 1], $cab), true);
    prova('linha de resultado não se digita (sai da cascata)', str_contains((string)($r['erro'] ?? ''), 'não se ajusta'));

    // Um perfil que não é ADMIN não ajusta.
    $email = 'prova.desafio.' . getmypid() . '@teste.local';
    Database::executar("INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES ('Prova desafio', ?, ?, 'CONTROLADORIA', 1)",
        [$email, password_hash('prova123', PASSWORD_DEFAULT)]);
    $http2 = $sessao();
    $cab2 = $entrar($http2, $email, 'prova123');
    $r = json_decode($http2('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'chave' => 'despesas', 'valor' => -1], $cab2), true);
    prova('perfil CONTROLADORIA não grava o desafio (403)', str_contains((string)($r['erro'] ?? ''), 'administradores'),
        json_encode($r['erro'] ?? null, JSON_UNESCAPED_UNICODE));
    $t2 = $ler(json_decode($http2('GET', "/api/projecao?negocio_id={$neg}"), true));
    prova('e vê o desafio ajustado, sem campo', empty($t2['desafio']['pode_ajustar']) && !empty($t2['desafio']['desafio']['ajustado']));
    Database::executar('DELETE FROM usuario WHERE email = ?', [$email]);

    foreach (['receita_bruta', 'despesas'] as $k) {
        $r = $ler(json_decode($http('POST', '/api/projecao/desafio', ['negocio_id' => $neg, 'chave' => $k, 'valor' => null], $cab), true));
    }
    prova('apagar devolve o desafio calculado',
        perto((float)$r['desafio']['escada']['desafio']['resultado'], (float)$calc['resultado'], 0.01)
        && empty((array)$r['desafio']['ajustes']) && empty($r['desafio']['desafio']['ajustado']));
    prova('e o Corporativo volta ao que era', perto((float)$corp()['resultado'], (float)$corpAntes['resultado'], 0.01));
}
prova('nenhum ajuste de prova ficou no banco',
    (int)Database::um('SELECT COUNT(*) AS n FROM negocio_desafio_ajuste')['n'] === 0);

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

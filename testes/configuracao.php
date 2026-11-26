<?php

/**
 * Provas das CONFIGURAÇÕES GERAIS (pedido do cliente, 2026-11-26): a chave
 * "Diagnóstico só leitura" — todo usuário lê, só o ADMIN grava; chave
 * desconhecida é recusada; a sessão (`/api/me`) já traz o valor.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/configuracao.php
 *
 * Bate na aplicação em APP_URL (padrão http://127.0.0.1:8099); sem ela de pé,
 * é pulada. Devolve a chave ao que era e apaga o usuário de prova.
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
        echo "  ✓ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $bad[] = $nome . ($extra ? " — {$extra}" : '');
        echo "  ✗ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$sessao = static function () use ($base): callable {
    $jar = tempnam(sys_get_temp_dir(), 'cfg');
    return static function (string $metodo, string $caminho, ?array $corpo = null, array $cab = []) use ($jar, $base): string {
        $c = curl_init($base . $caminho);
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
};
$entrar = static function (callable $http, string $email, string $senha): array {
    $http('POST', '/api/login', ['email' => $email, 'senha' => $senha]);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', '/'), $mm);
    return ['X-CSRF-Token: ' . ($mm[1] ?? '')];
};
$ler = static fn (string $r) => (json_decode($r, true)['dados'] ?? json_decode($r, true));

$http = $sessao();
if ($http('GET', '/login') === '') {
    echo "  ⏭  pulada: a aplicação não responde em {$base}.\n";
    exit(0);
}
$antes = Database::um("SELECT valor FROM configuracao WHERE chave = 'diagnostico_somente_leitura'")['valor'] ?? null;
$cab = $entrar($http, getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', getenv('APP_SENHA') ?: 'trocar123');
$email = 'prova.config.' . getmypid() . '@teste.local';
try {
    $r = $ler($http('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 1], $cab));
    prova('o ADMIN liga o Diagnóstico só leitura', ($r['diagnostico_somente_leitura'] ?? null) === true, json_encode($r));
    $me = $ler($http('GET', '/api/me'));
    prova('a sessão (/api/me) já traz a configuração', ($me['configuracoes']['diagnostico_somente_leitura'] ?? null) === true);
    $e = json_decode($http('POST', '/api/configuracoes', ['chave' => 'inventada', 'valor' => 1], $cab), true);
    prova('chave desconhecida é recusada', str_contains((string)($e['erro'] ?? ''), 'desconhecida'), (string)($e['erro'] ?? ''));

    Database::executar("INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES ('Prova config', ?, ?, 'CONTROLADORIA', 1)",
        [$email, password_hash('prova123', PASSWORD_DEFAULT)]);
    $http2 = $sessao();
    $cab2 = $entrar($http2, $email, 'prova123');
    $l2 = $ler($http2('GET', '/api/configuracoes'));
    prova('quem não é ADMIN lê a configuração', ($l2['diagnostico_somente_leitura'] ?? null) === true, json_encode($l2));
    $e = json_decode($http2('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 0], $cab2), true);
    prova('e não altera (403)', str_contains((string)($e['erro'] ?? ''), 'administradores'), (string)($e['erro'] ?? ''));

    $r = $ler($http('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 0], $cab));
    prova('o ADMIN desliga', ($r['diagnostico_somente_leitura'] ?? null) === false);
} finally {
    Database::executar('DELETE FROM usuario WHERE email = ?', [$email]);
    if ($antes === null) {
        Database::executar("DELETE FROM configuracao WHERE chave = 'diagnostico_somente_leitura'");
    } else {
        Database::executar("UPDATE configuracao SET valor = ? WHERE chave = 'diagnostico_somente_leitura'", [$antes]);
    }
}

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

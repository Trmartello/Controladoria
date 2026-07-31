<?php
// Toda configuração vem de variáveis de ambiente (Railway injeta as do MySQL).
// Nenhuma credencial neste repositório.

function env(string $chave, ?string $padrao = null): ?string
{
    $v = getenv($chave);
    return ($v === false || $v === '') ? $padrao : $v;
}

return [
    'db' => [
        'host'    => env('MYSQLHOST', env('DB_HOST', '127.0.0.1')),
        'port'    => env('MYSQLPORT', env('DB_PORT', '3306')),
        'name'    => env('MYSQLDATABASE', env('DB_NAME', 'planejamento')),
        'user'    => env('MYSQLUSER', env('DB_USER', 'root')),
        'pass'    => env('MYSQLPASSWORD', env('DB_PASS', '')),
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'nome'        => 'Planejamento Estratégico Copérdia',
        // Senha inicial do admin criado na primeira migração. Sem ADMIN_SENHA
        // no ambiente, a migração gera uma senha aleatória e a mostra no log
        // uma única vez — nunca uma senha fixa publicada no repositório.
        'admin_email' => env('ADMIN_EMAIL', 'admin@coperdia.com.br'),
        'admin_senha' => env('ADMIN_SENHA'),
    ],
    'qlik' => [
        'tenant'  => env('QLIK_TENANT', 'coperdia.br.qlikcloud.com'),
        'api_key' => env('QLIK_API_KEY'),
        'app_id'  => env('QLIK_APP_ID', '4aed35d9-bc8c-42dd-a5d7-ea13925a53b9'), // Comercial Global
    ],
];

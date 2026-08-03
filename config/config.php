<?php
// Toda configuração vem de variáveis de ambiente (Railway injeta as do MySQL).
// Nenhuma credencial neste repositório.

// Guardado porque este arquivo pode ser carregado mais de uma vez no mesmo
// processo (front controller, CLI de avisos, migração)
if (!function_exists('env')) {
    function env(string $chave, ?string $padrao = null): ?string
    {
        $v = getenv($chave);
        return ($v === false || $v === '') ? $padrao : $v;
    }
}

// O container roda em UTC e a cooperativa trabalha em UTC−3: das 21h à meia-noite
// o servidor já estava no dia seguinte. Isso marcava como ATRASADA a ação que
// vence só amanhã (sincronizarAtrasos), podia disparar o relatório semanal num
// domingo à noite (Avisos) e gravava data_reg do dia errado no diário. Este
// arquivo é carregado por todos os pontos de entrada — front controller,
// migração e CLI de avisos —, então o fuso vale para todos.
date_default_timezone_set(env('TZ_APP', 'America/Sao_Paulo'));

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
    // Avisos por e-mail (relatório da semana e pendências do dia). Sem
    // SMTP_HOST/SMTP_REMETENTE o envio fica desligado e o resto segue igual.
    'smtp' => [
        'host'           => env('SMTP_HOST'),
        'porta'          => env('SMTP_PORTA', '587'),
        'seguranca'      => strtolower(env('SMTP_SEGURANCA', 'tls')), // tls | ssl | nenhuma
        'usuario'        => env('SMTP_USUARIO'),
        'senha'          => env('SMTP_SENHA'),
        'remetente'      => env('SMTP_REMETENTE'),
        'nome_remetente' => env('SMTP_NOME_REMETENTE', 'Planejamento Estratégico Copérdia'),
        'dominio'        => env('SMTP_DOMINIO', 'coperdia.com.br'),
    ],
    'app_url' => rtrim(env('APP_URL', ''), '/'),

    'qlik' => [
        'tenant'  => env('QLIK_TENANT', 'coperdia.br.qlikcloud.com'),
        'api_key' => env('QLIK_API_KEY'),
        'app_id'  => env('QLIK_APP_ID', '4aed35d9-bc8c-42dd-a5d7-ea13925a53b9'), // Comercial Global
    ],
];

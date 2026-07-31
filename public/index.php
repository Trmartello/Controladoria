<?php
declare(strict_types=1);

// Autoloader App\ → app/
spl_autoload_register(function (string $classe): void {
    if (str_starts_with($classe, 'App\\')) {
        $caminho = __DIR__ . '/../app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
        if (is_file($caminho)) {
            require $caminho;
        }
    }
});

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

use App\Core\Auth;
use App\Core\Json;

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// ---- Páginas (as duas únicas do sistema) ----
if ($metodo === 'GET' && ($caminho === '/' || $caminho === '/login')) {
    if ($caminho === '/' && !Auth::usuario()) {
        header('Location: /login');
        exit;
    }
    if ($caminho === '/login' && Auth::usuario()) {
        header('Location: /');
        exit;
    }
    $csrf = Auth::tokenCsrf();
    $app  = $GLOBALS['config']['app'];
    require __DIR__ . '/../views/' . ($caminho === '/login' ? 'login.php' : 'shell.php');
    exit;
}

// ---- API JSON ----
if (!str_starts_with($caminho, '/api/')) {
    http_response_code(404);
    echo 'Não encontrado';
    exit;
}

// CSRF em toda escrita (o token chega no header X-CSRF-Token)
if ($metodo !== 'GET' && $caminho !== '/api/login') {
    Auth::validarCsrf();
}

use App\Controllers\AuthController;
use App\Controllers\CicloController;
use App\Controllers\DriverEixoController;
use App\Controllers\NegocioController;
use App\Controllers\PlanejamentoController;
use App\Controllers\UsuarioController;

try {
    $rota = "$metodo $caminho";
    switch (true) {
        case $rota === 'POST /api/login':          (new AuthController())->login();
        case $rota === 'POST /api/logout':         (new AuthController())->logout();
        case $rota === 'GET /api/me':              (new AuthController())->me();
        case $rota === 'POST /api/senha':          (new UsuarioController())->trocarSenha();

        case $rota === 'GET /api/negocios':        (new NegocioController())->listar();
        case $rota === 'POST /api/negocios':       (new NegocioController())->salvar();
        case $rota === 'POST /api/negocios/sync':  (new NegocioController())->sincronizar();
        case (bool)preg_match('#^POST /api/negocios/(\d+)$#', $rota, $m):
            (new NegocioController())->salvar((int)$m[1]);

        case $rota === 'GET /api/ciclos':          (new CicloController())->listar();
        case $rota === 'POST /api/ciclos':         (new CicloController())->salvar();
        case (bool)preg_match('#^POST /api/ciclos/(\d+)$#', $rota, $m):
            (new CicloController())->salvar((int)$m[1]);
        case $rota === 'POST /api/horizontes':     (new CicloController())->salvarHorizonte();
        case (bool)preg_match('#^POST /api/horizontes/(\d+)$#', $rota, $m):
            (new CicloController())->salvarHorizonte((int)$m[1]);

        case (bool)preg_match('#^GET /api/(drivers|eixos)$#', $rota, $m):
            (new DriverEixoController())->listar($m[1]);
        case (bool)preg_match('#^POST /api/(drivers|eixos)$#', $rota, $m):
            (new DriverEixoController())->salvar($m[1]);
        case (bool)preg_match('#^POST /api/(drivers|eixos)/(\d+)$#', $rota, $m):
            (new DriverEixoController())->salvar($m[1], (int)$m[2]);

        case $rota === 'GET /api/usuarios':        (new UsuarioController())->listar();
        case $rota === 'POST /api/usuarios':       (new UsuarioController())->salvar();
        case (bool)preg_match('#^POST /api/usuarios/(\d+)$#', $rota, $m):
            (new UsuarioController())->salvar((int)$m[1]);

        case $rota === 'GET /api/contexto':        (new PlanejamentoController())->contexto();

        default:
            Json::erro('Rota não encontrada.', 404);
    }
} catch (\PDOException $e) {
    error_log('ERRO SQL: ' . $e->getMessage());
    Json::erro('Erro interno de banco de dados.', 500);
} catch (\Throwable $e) {
    error_log('ERRO: ' . $e->getMessage());
    Json::erro('Erro interno.', 500);
}

<?php
declare(strict_types=1);

// Servidor embutido do PHP: arquivos estáticos (assets) são servidos direto
if (PHP_SAPI === 'cli-server') {
    $arquivo = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($arquivo)) {
        return false;
    }
}

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
use App\Controllers\CascataController;
use App\Controllers\CenarioController;
use App\Controllers\CicloController;
use App\Controllers\DiarioController;
use App\Controllers\DriverEixoController;
use App\Controllers\FatorController;
use App\Controllers\InvestimentoController;
use App\Controllers\NegocioController;
use App\Controllers\PlanejamentoController;
use App\Controllers\ProjetoController;
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

        case $rota === 'GET /api/cenario':         (new CenarioController())->listar();
        case $rota === 'POST /api/cenario':        (new CenarioController())->salvar();
        case (bool)preg_match('#^POST /api/cenario/(\d+)/excluir$#', $rota, $m):
            (new CenarioController())->excluir((int)$m[1]);
        case (bool)preg_match('#^POST /api/cenario/(\d+)$#', $rota, $m):
            (new CenarioController())->salvar((int)$m[1]);

        case $rota === 'GET /api/fatores':         (new FatorController())->listar();
        case $rota === 'POST /api/fatores':        (new FatorController())->salvar();
        case (bool)preg_match('#^POST /api/fatores/(\d+)/excluir$#', $rota, $m):
            (new FatorController())->excluir((int)$m[1]);
        case (bool)preg_match('#^POST /api/fatores/(\d+)/promover$#', $rota, $m):
            (new FatorController())->promover((int)$m[1]);
        case (bool)preg_match('#^POST /api/fatores/(\d+)/gut$#', $rota, $m):
            (new FatorController())->avaliarGut((int)$m[1]);
        case (bool)preg_match('#^POST /api/fatores/(\d+)$#', $rota, $m):
            (new FatorController())->salvar((int)$m[1]);

        case $rota === 'GET /api/cascata':          (new CascataController())->listar();
        case $rota === 'POST /api/cascata':         (new CascataController())->salvar();
        case (bool)preg_match('#^POST /api/cascata/(\d+)/excluir$#', $rota, $m):
            (new CascataController())->excluir((int)$m[1]);

        case $rota === 'GET /api/projetos':         (new ProjetoController())->listar();
        case $rota === 'POST /api/projetos':        (new ProjetoController())->salvar();
        case (bool)preg_match('#^POST /api/projetos/(\d+)/excluir$#', $rota, $m):
            (new ProjetoController())->excluir((int)$m[1]);
        case (bool)preg_match('#^POST /api/projetos/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvar((int)$m[1]);
        case $rota === 'POST /api/desdobramentos':  (new ProjetoController())->salvarDesdobramento();
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)/excluir$#', $rota, $m):
            (new ProjetoController())->excluirDesdobramento((int)$m[1]);
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvarDesdobramento((int)$m[1]);

        case $rota === 'GET /api/diario':           (new DiarioController())->listar();
        case $rota === 'POST /api/diario':          (new DiarioController())->criar();

        case $rota === 'GET /api/investimentos':    (new InvestimentoController())->listar();
        case $rota === 'POST /api/investimentos':   (new InvestimentoController())->salvar();
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/excluir$#', $rota, $m):
            (new InvestimentoController())->excluir((int)$m[1]);
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/decidir$#', $rota, $m):
            (new InvestimentoController())->decidir((int)$m[1]);
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/auditar$#', $rota, $m):
            (new InvestimentoController())->auditar((int)$m[1]);
        case (bool)preg_match('#^POST /api/investimentos/(\d+)$#', $rota, $m):
            (new InvestimentoController())->salvar((int)$m[1]);
        case $rota === 'POST /api/envelopes':       (new InvestimentoController())->salvarEnvelope();
        case (bool)preg_match('#^POST /api/envelopes/(\d+)$#', $rota, $m):
            (new InvestimentoController())->salvarEnvelope((int)$m[1]);

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

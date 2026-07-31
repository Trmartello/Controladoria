<?php
declare(strict_types=1);

// Servidor embutido do PHP: arquivos estáticos são servidos direto.
// /assets/* ganha Cache-Control (o cli-server não envia cache) e o realpath
// garante que nada fora de public/ seja servido.
if (PHP_SAPI === 'cli-server') {
    $caminhoUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $arquivo = realpath(__DIR__ . $caminhoUrl);
    if ($arquivo && is_file($arquivo) && str_starts_with($arquivo, __DIR__ . DIRECTORY_SEPARATOR)) {
        $tipos = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        ];
        $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
        if (str_starts_with($caminhoUrl, '/assets/') && isset($tipos[$ext])) {
            header('Content-Type: ' . $tipos[$ext]);
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . (string)filesize($arquivo));
            readfile($arquivo);
            exit;
        }
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

// Atrás do proxy do Railway o TLS termina na borda; X-Forwarded-Proto indica HTTPS
$https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS']);

// Sessão no banco (sobrevive a deploys) com validade de 30 dias
const SESSAO_DIAS = 30;
ini_set('session.gc_maxlifetime', (string)(SESSAO_DIAS * 86400));
session_set_save_handler(new \App\Core\SessaoBanco(), true);
session_set_cookie_params([
    'lifetime' => SESSAO_DIAS * 86400,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();

/** URL do asset com a versão do arquivo — atualizações furam o cache do navegador. */
function versao_asset(string $caminho): string
{
    $arquivo = __DIR__ . $caminho;
    return $caminho . '?v=' . (is_file($arquivo) ? filemtime($arquivo) : 1);
}

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

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
use App\Controllers\IndicadorController;
use App\Controllers\InvestimentoController;
use App\Controllers\NegocioController;
use App\Controllers\PlanejamentoController;
use App\Controllers\ProjetoController;
use App\Controllers\RelatorioController;
use App\Controllers\UsuarioController;

try {
    $rota = "$metodo $caminho";
    switch (true) {
        case $rota === 'POST /api/login':          (new AuthController())->login(); break;
        case $rota === 'POST /api/logout':         (new AuthController())->logout(); break;
        case $rota === 'GET /api/me':              (new AuthController())->me(); break;
        case $rota === 'POST /api/senha':          (new UsuarioController())->trocarSenha(); break;

        case $rota === 'GET /api/negocios':        (new NegocioController())->listar(); break;
        case $rota === 'POST /api/negocios':       (new NegocioController())->salvar(); break;
        case $rota === 'POST /api/negocios/sync':  (new NegocioController())->sincronizar(); break;
        case (bool)preg_match('#^POST /api/negocios/(\d+)$#', $rota, $m):
            (new NegocioController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/ciclos':          (new CicloController())->listar(); break;
        case $rota === 'POST /api/ciclos':         (new CicloController())->salvar(); break;
        case (bool)preg_match('#^POST /api/ciclos/(\d+)$#', $rota, $m):
            (new CicloController())->salvar((int)$m[1]); break;
        case $rota === 'POST /api/horizontes':     (new CicloController())->salvarHorizonte(); break;
        case (bool)preg_match('#^POST /api/horizontes/(\d+)$#', $rota, $m):
            (new CicloController())->salvarHorizonte((int)$m[1]); break;

        case (bool)preg_match('#^GET /api/(drivers|eixos)$#', $rota, $m):
            (new DriverEixoController())->listar($m[1]); break;
        case (bool)preg_match('#^POST /api/(drivers|eixos)$#', $rota, $m):
            (new DriverEixoController())->salvar($m[1]); break;
        case (bool)preg_match('#^POST /api/(drivers|eixos)/(\d+)$#', $rota, $m):
            (new DriverEixoController())->salvar($m[1], (int)$m[2]); break;

        case $rota === 'GET /api/usuarios':        (new UsuarioController())->listar(); break;
        case $rota === 'GET /api/responsaveis':    (new UsuarioController())->responsaveis(); break;
        case $rota === 'POST /api/usuarios':       (new UsuarioController())->salvar(); break;
        case (bool)preg_match('#^POST /api/usuarios/(\d+)$#', $rota, $m):
            (new UsuarioController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/contexto':        (new PlanejamentoController())->contexto(); break;

        case $rota === 'GET /api/cenario':         (new CenarioController())->listar(); break;
        case $rota === 'POST /api/cenario':        (new CenarioController())->salvar(); break;
        case (bool)preg_match('#^POST /api/cenario/(\d+)/excluir$#', $rota, $m):
            (new CenarioController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/cenario/(\d+)$#', $rota, $m):
            (new CenarioController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/fatores':         (new FatorController())->listar(); break;
        case $rota === 'POST /api/fatores':        (new FatorController())->salvar(); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/excluir$#', $rota, $m):
            (new FatorController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/promover$#', $rota, $m):
            (new FatorController())->promover((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/gut$#', $rota, $m):
            (new FatorController())->avaliarGut((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/gut/limpar$#', $rota, $m):
            (new FatorController())->limparGut((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)$#', $rota, $m):
            (new FatorController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/cascata':          (new CascataController())->listar(); break;
        case $rota === 'POST /api/cascata':         (new CascataController())->salvar(); break;
        case (bool)preg_match('#^POST /api/cascata/(\d+)/excluir$#', $rota, $m):
            (new CascataController())->excluir((int)$m[1]); break;

        case $rota === 'GET /api/projetos':         (new ProjetoController())->listar(); break;
        case $rota === 'POST /api/projetos':        (new ProjetoController())->salvar(); break;
        case (bool)preg_match('#^POST /api/projetos/(\d+)/excluir$#', $rota, $m):
            (new ProjetoController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/projetos/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvar((int)$m[1]); break;
        case $rota === 'POST /api/iniciativas':     (new ProjetoController())->salvarIniciativa(); break;
        case (bool)preg_match('#^POST /api/iniciativas/(\d+)/excluir$#', $rota, $m):
            (new ProjetoController())->excluirIniciativa((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/iniciativas/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvarIniciativa((int)$m[1]); break;
        case $rota === 'POST /api/desdobramentos':  (new ProjetoController())->salvarDesdobramento(); break;
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)/excluir$#', $rota, $m):
            (new ProjetoController())->excluirDesdobramento((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvarDesdobramento((int)$m[1]); break;

        case $rota === 'GET /api/diario':           (new DiarioController())->listar(); break;
        case $rota === 'POST /api/diario':          (new DiarioController())->criar(); break;

        case $rota === 'GET /api/investimentos':    (new InvestimentoController())->listar(); break;
        case $rota === 'POST /api/investimentos':   (new InvestimentoController())->salvar(); break;
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/excluir$#', $rota, $m):
            (new InvestimentoController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/decidir$#', $rota, $m):
            (new InvestimentoController())->decidir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/investimentos/(\d+)/auditar$#', $rota, $m):
            (new InvestimentoController())->auditar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/investimentos/(\d+)$#', $rota, $m):
            (new InvestimentoController())->salvar((int)$m[1]); break;
        case $rota === 'POST /api/envelopes':       (new InvestimentoController())->salvarEnvelope(); break;
        case (bool)preg_match('#^POST /api/envelopes/(\d+)$#', $rota, $m):
            (new InvestimentoController())->salvarEnvelope((int)$m[1]); break;

        case $rota === 'GET /api/indicadores':      (new IndicadorController())->listar(); break;
        case $rota === 'POST /api/indicadores':     (new IndicadorController())->salvar(); break;
        case (bool)preg_match('#^POST /api/indicadores/(\d+)/excluir$#', $rota, $m):
            (new IndicadorController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/indicadores/(\d+)/valores$#', $rota, $m):
            (new IndicadorController())->salvarValores((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/indicadores/(\d+)$#', $rota, $m):
            (new IndicadorController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/painel':           (new RelatorioController())->painel(); break;
        case $rota === 'GET /api/relatorio':        (new RelatorioController())->relatorio(); break;
        case $rota === 'GET /api/relatorio/exportar': (new RelatorioController())->exportar(); break;

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

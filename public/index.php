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
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'json' => 'application/json', 'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
        // Cabeçalhos mínimos valem para TODA resposta daqui, inclusive o 404:
        // este bloco sai antes do bloco geral de segurança lá embaixo, e sem
        // isto /index.php e /.htaccess respondiam sem CSP nem X-Frame-Options.
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        if (str_starts_with($caminhoUrl, '/assets/') && isset($tipos[$ext])) {
            header('Content-Type: ' . $tipos[$ext]);
            header('Cache-Control: public, max-age=86400');
            header('Content-Length: ' . (string)filesize($arquivo));
            readfile($arquivo);
            exit;
        }
        // Qualquer outro arquivo real de public/ vira 404 — inclusive asset de
        // extensão que não conhecemos, que antes escapava por `return false` e
        // era entregue sem Content-Type, sem cache e sem nosniff. Devolver
        // `false` também fazia o cli-server INCLUIR index.php de novo na mesma
        // requisição, e a redeclaração de versao_asset() derrubava o pedido com
        // fatal — bastava um robô pedir /index.php. Extensão nova de asset
        // entra no mapa acima; é uma linha.
        http_response_code(404);
        exit;
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

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// A tempestade é aberta ao público: iniciar sessão ali criaria uma linha em
// `sessao` por visitante anônimo, retida por 30 dias — crescimento sem fim
// para quem só escaneou o QR
$rotaPublica = $caminho === '/entrar' || str_starts_with($caminho, '/entrar/')
    || str_starts_with($caminho, '/api/publico/');

// Os cabeçalhos de segurança vêm ANTES da sessão: o handler de sessão consulta
// o MySQL, e uma queda do banco ali estourava a exceção antes destas linhas —
// a resposta 500 saía sem CSP, sem X-Frame-Options e com a versão do PHP à
// mostra, justamente nas rotas autenticadas.
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

// Sessão no banco (sobrevive a deploys) com validade de 30 dias
const SESSAO_DIAS = 30;
if (!$rotaPublica) {
    ini_set('session.gc_maxlifetime', (string)(SESSAO_DIAS * 86400));
    // Recusa id de sessão que o cliente inventou. A fixação já está coberta
    // pelo session_regenerate_id do login; isto é defesa em profundidade barata
    ini_set('session.use_strict_mode', '1');
    session_set_save_handler(new \App\Core\SessaoBanco(), true);
    session_set_cookie_params([
        'lifetime' => SESSAO_DIAS * 86400,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    try {
        session_start();
    } catch (\Throwable $e) {
        error_log('ERRO SESSAO: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'Serviço indisponível. Tente de novo em instantes.']);
        exit;
    }
}

/** URL do asset com a versão do arquivo — atualizações furam o cache do navegador. */
function versao_asset(string $caminho): string
{
    $arquivo = __DIR__ . $caminho;
    return $caminho . '?v=' . (is_file($arquivo) ? filemtime($arquivo) : 1);
}

use App\Core\Auth;
use App\Core\Json;
use App\Core\Versao;

// O pulso é fechado no ENCERRAMENTO, não no fim do switch: `Json::ok()` e
// `Json::erro()` terminam o script com `exit`, e o fim do switch nunca é
// alcançado numa requisição normal. Aqui pega os dois caminhos — inclusive o do
// endpoint que gravou e depois recusou por regra.
register_shutdown_function([Versao::class, 'registrar']);

// Corpo grande só faz sentido em rota autenticada; na pública o texto é curto
if ($rotaPublica && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    http_response_code(413);
    exit;
}

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
    // Página autenticada não pode ficar em cache de proxy corporativo
    header('Cache-Control: no-store');
    require __DIR__ . '/../views/' . ($caminho === '/login' ? 'login.php' : 'shell.php');
    exit;
}

// ---- Tela do participante da tempestade (sem login, entra pelo PIN) ----
if ($metodo === 'GET' && preg_match('#^/entrar(?:/(\\d{6}))?$#', $caminho, $mp)) {
    $pin = $mp[1] ?? '';
    require __DIR__ . '/../views/participante.php';
    exit;
}

// ---- API JSON ----
if (!str_starts_with($caminho, '/api/')) {
    http_response_code(404);
    echo 'Não encontrado';
    exit;
}

// CSRF em toda escrita (o token chega no header X-CSRF-Token).
// A tempestade fica de fora porque não tem sessão: sem autoridade ambiente não
// há o que um site de terceiro sequestrar. A guarda dessas rotas é o token do
// participante + rodada aberta + Content-Type JSON (ver PublicoController).
// A lista é explícita de propósito: com um prefixo, uma rota nova criada sob
// /api/publico/ perderia a proteção em silêncio.
$semCsrf = $caminho === '/api/login'
    || $caminho === '/api/publico/entrar'
    || $caminho === '/api/publico/esquecer'
    || $caminho === '/api/publico/ideia'
    || (bool)preg_match('#^/api/publico/ideia/\\d+$#', $caminho)
    || $caminho === '/api/publico/resposta'
    || (bool)preg_match('#^/api/publico/estrela/\\d+$#', $caminho)
    || (bool)preg_match('#^/api/publico/votar/\\d+$#', $caminho);
if ($metodo !== 'GET' && !$semCsrf) {
    Auth::validarCsrf();
}

use App\Controllers\AuthController;
use App\Controllers\BloqueioController;
use App\Controllers\CascataController;
use App\Controllers\QuizController;
use App\Controllers\CenarioController;
use App\Controllers\ColetaController;
use App\Controllers\CicloController;
use App\Controllers\ComentarioController;
use App\Controllers\CruzamentoController;
use App\Controllers\DriverEixoController;
use App\Controllers\FatorController;
use App\Controllers\ImpactoController;
use App\Controllers\IndicadorController;
use App\Controllers\InvestimentoController;
use App\Controllers\NegocioController;
use App\Controllers\PlanejamentoController;
use App\Controllers\ProjetoController;
use App\Controllers\PublicoController;
use App\Controllers\RodadaController;
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
        // A específica antes da genérica: /api/negocios/7/excluir cairia nela
        case (bool)preg_match('#^POST /api/negocios/(\d+)/excluir$#', $rota, $m):
            (new NegocioController())->excluir((int)$m[1]); break;
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
        case (bool)preg_match('#^POST /api/(drivers|eixos)/reordenar$#', $rota, $m):
            (new DriverEixoController())->reordenar($m[1]); break;
        case (bool)preg_match('#^POST /api/(drivers|eixos)/(\d+)$#', $rota, $m):
            (new DriverEixoController())->salvar($m[1], (int)$m[2]); break;

        case $rota === 'POST /api/avisos/despachar':
            (new RelatorioController())->despacharAvisos(); break;

        case $rota === 'GET /api/usuarios':        (new UsuarioController())->listar(); break;
        case $rota === 'GET /api/responsaveis':    (new UsuarioController())->responsaveis(); break;
        case $rota === 'POST /api/usuarios':       (new UsuarioController())->salvar(); break;
        // O que a pessoa segura hoje, para a tela perguntar antes de excluir
        case (bool)preg_match('#^GET /api/usuarios/(\d+)/vinculos$#', $rota, $m):
            (new UsuarioController())->vinculos((int)$m[1]); break;
        // A específica antes da genérica: /api/usuarios/7/excluir cairia nela
        case (bool)preg_match('#^POST /api/usuarios/(\d+)/excluir$#', $rota, $m):
            (new UsuarioController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/usuarios/(\d+)$#', $rota, $m):
            (new UsuarioController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/contexto':        (new PlanejamentoController())->contexto(); break;

        // O pulso: `[planejamento_id => versao]` dos planos visíveis do ciclo.
        // É a rota mais chamada do sistema quando há gente preenchendo junto —
        // uma por admin a cada poucos segundos —, e por isso ela lê UMA tabela
        // de duas colunas e não toca em nada do conteúdo.
        case $rota === 'GET /api/pulso':           (new PlanejamentoController())->pulso(); break;

        // O cadeado de edição. As três exigem edição do planejamento: pedir
        // cadeado é declarar intenção de gravar, e quem não grava também não
        // pode travar o item para os outros.
        case $rota === 'POST /api/bloqueio':        (new BloqueioController())->tomar(); break;
        case $rota === 'POST /api/bloqueio/renovar':(new BloqueioController())->renovar(); break;
        case $rota === 'POST /api/bloqueio/soltar': (new BloqueioController())->soltar(); break;

        // Rotas públicas da tempestade: sem sessão, guardadas pelo token
        case (bool)preg_match('#^GET /api/publico/rodada/(\\d{6})$#', $rota, $m):
            (new PublicoController())->rodada($m[1]); break;
        case $rota === 'GET /api/publico/minhas':  (new PublicoController())->minhas(); break;
        case $rota === 'GET /api/publico/votar':   (new PublicoController())->paraVotar(); break;
        // Fase da estrela do quiz: com o 🎤 fechado, o celular vota no que a
        // sala acabou de dizer (teto por PERGUNTA, não por rodada)
        case $rota === 'GET /api/publico/estrelas': (new PublicoController())->estrelas(); break;
        case (bool)preg_match('#^POST /api/publico/estrela/(\\d+)$#', $rota, $m):
            (new PublicoController())->estrela((int)$m[1]); break;
        case $rota === 'POST /api/publico/entrar': (new PublicoController())->entrar(); break;
        // "Não é você?": solta o aparelho da pessoa anterior, sem apagá-la.
        case $rota === 'POST /api/publico/esquecer': (new PublicoController())->esquecer(); break;
        case $rota === 'POST /api/publico/ideia':  (new PublicoController())->ideia(); break;
        // Resposta do quiz da cascata (escolha ou renúncia da pergunta ativa)
        case $rota === 'POST /api/publico/resposta': (new PublicoController())->resposta(); break;
        case (bool)preg_match('#^POST /api/publico/ideia/(\\d+)$#', $rota, $m):
            (new PublicoController())->editarIdeia((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/publico/votar/(\\d+)$#', $rota, $m):
            (new PublicoController())->votar((int)$m[1]); break;

        case $rota === 'GET /api/rodadas':         (new RodadaController())->listar(); break;
        case $rota === 'POST /api/rodadas':        (new RodadaController())->abrir(); break;
        case (bool)preg_match('#^POST /api/rodadas/(\\d+)/encerrar$#', $rota, $m):
            (new RodadaController())->encerrar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/rodadas/(\\d+)/votacao$#', $rota, $m):
            (new RodadaController())->votacao((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/rodadas/(\\d+)/pergunta$#', $rota, $m):
            (new RodadaController())->pergunta((int)$m[1]); break;

        case $rota === 'GET /api/coleta':          (new ColetaController())->listar(); break;
        case $rota === 'GET /api/coleta/aguardando-acao': (new ColetaController())->aguardandoAcao(); break;
        case $rota === 'POST /api/coleta':         (new ColetaController())->salvar(); break;
        // As específicas antes da genérica, senão /api/coleta/7/descartar cairia nela
        case (bool)preg_match('#^POST /api/coleta/(\d+)/encaminhar$#', $rota, $m):
            (new ColetaController())->encaminhar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/reabrir$#', $rota, $m):
            (new ColetaController())->reabrir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/descartar$#', $rota, $m):
            (new ColetaController())->descartar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/rodada/(\d+)/limpar$#', $rota, $m):
            (new ColetaController())->limparRodada((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/agrupar$#', $rota, $m):
            (new ColetaController())->agrupar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/desagrupar$#', $rota, $m):
            (new ColetaController())->desagrupar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/remover-grupo$#', $rota, $m):
            (new ColetaController())->removerDoGrupo((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/adiar$#', $rota, $m):
            (new ColetaController())->adiar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/priorizar$#', $rota, $m):
            (new ColetaController())->priorizar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/complementar$#', $rota, $m):
            (new ColetaController())->complementar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/dividir$#', $rota, $m):
            (new ColetaController())->dividir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)/excluir$#', $rota, $m):
            (new ColetaController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/coleta/(\d+)$#', $rota, $m):
            (new ColetaController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/cenario':         (new CenarioController())->listar(); break;
        // Antes das rotas com {id}: "aguardando-acao" não é um número, mas a
        // rota literal precisa vir primeiro para o leitor não ter de conferir
        case $rota === 'GET /api/cenario/aguardando-acao':
            (new CenarioController())->aguardandoAcao(); break;
        case (bool)preg_match('#^POST /api/cenario/(\d+)/plano-acao$#', $rota, $m):
            (new CenarioController())->planoAcao((int)$m[1]); break;
        case $rota === 'POST /api/cenario':        (new CenarioController())->salvar(); break;
        case (bool)preg_match('#^POST /api/cenario/(\d+)/excluir$#', $rota, $m):
            (new CenarioController())->excluir((int)$m[1]); break;
        // A específica antes da genérica: /api/cenario/7/mover cairia nela
        case (bool)preg_match('#^POST /api/cenario/(\d+)/mover$#', $rota, $m):
            (new CenarioController())->mover((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/cenario/(\d+)$#', $rota, $m):
            (new CenarioController())->salvar((int)$m[1]); break;

        case $rota === 'GET /api/fatores':         (new FatorController())->listar(); break;
        // Antes das rotas com {id}: "aguardando-acao" não é um número, mas a
        // rota literal precisa vir primeiro para o leitor não ter de conferir
        case $rota === 'GET /api/fatores/aguardando-acao':
            (new FatorController())->aguardandoAcao(); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/plano-acao$#', $rota, $m):
            (new FatorController())->planoAcao((int)$m[1]); break;
        case $rota === 'POST /api/fatores':        (new FatorController())->salvar(); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/excluir$#', $rota, $m):
            (new FatorController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/promover$#', $rota, $m):
            (new FatorController())->promover((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/mover$#', $rota, $m):
            (new FatorController())->mover((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/gut$#', $rota, $m):
            (new FatorController())->avaliarGut((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)/gut/limpar$#', $rota, $m):
            (new FatorController())->limparGut((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/fatores/(\d+)$#', $rota, $m):
            (new FatorController())->salvar((int)$m[1]); break;

        // Matriz de Impacto: as duas rotas NÃO recebem `planejamento_id`, e sim
        // `ciclo_id`. O plano corporativo é resolvido no servidor a partir dele —
        // aceitar o id pronto do cliente deixaria a tela escolher em que plano
        // grava, que é justamente o que a autorização por negócio evita.
        case $rota === 'GET /api/impacto':          (new ImpactoController())->listar(); break;
        case $rota === 'POST /api/impacto':         (new ImpactoController())->salvar(); break;

        // Cruzamentos da SWOT (TOWS): o par interno × externo e a estratégia
        case $rota === 'GET /api/cruzamentos':     (new CruzamentoController())->listar(); break;
        // Antes das rotas com {id}: "aguardando-acao" não é um número, mas a
        // ordem no `switch` é o que garante isso — o mesmo cuidado da fila de
        // fatores logo acima.
        case $rota === 'GET /api/cruzamentos/aguardando-acao':
            (new CruzamentoController())->aguardandoAcao(); break;
        case $rota === 'POST /api/cruzamentos':    (new CruzamentoController())->salvar(); break;
        case (bool)preg_match('#^POST /api/cruzamentos/(\d+)/excluir$#', $rota, $m):
            (new CruzamentoController())->excluir((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/cruzamentos/(\d+)/plano-acao$#', $rota, $m):
            (new CruzamentoController())->planoAcao((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/cruzamentos/(\d+)$#', $rota, $m):
            (new CruzamentoController())->salvar((int)$m[1]); break;

        // Quiz — a sala do PROJETO: um PIN para todas as análises. Rotas do
        // condutor (a escrita do participante é pública, em /api/publico).
        case $rota === 'GET /api/quiz':            (new QuizController())->estado(); break;
        case $rota === 'POST /api/quiz/abrir':     (new QuizController())->abrir(); break;
        case $rota === 'POST /api/quiz/perguntar': (new QuizController())->perguntar(); break;
        // O 🎤 de uma categoria/lado/célula: um alvo, um toque, a sala vira
        case $rota === 'POST /api/quiz/tela':      (new QuizController())->perguntarTela(); break;
        case $rota === 'POST /api/quiz/renomear':  (new QuizController())->renomear(); break;
        case $rota === 'POST /api/quiz/encerrar':  (new QuizController())->encerrar(); break;
        // Roteiro: abrir/reabrir, fechar sem abrir outra, tirar pendente
        case (bool)preg_match('#^POST /api/quiz/pergunta/(\d+)/ativar$#', $rota, $m):
            (new QuizController())->ativar((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/quiz/pergunta/(\d+)/encerrar$#', $rota, $m):
            (new QuizController())->encerrarPergunta((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/quiz/pergunta/(\d+)/remover$#', $rota, $m):
            (new QuizController())->removerPergunta((int)$m[1]); break;
        // Unificação de respostas (só com a pergunta fechada): a arrastada
        // passa a apontar para a que ficou; separar desfaz.
        case (bool)preg_match('#^POST /api/quiz/sugestao/(\d+)/unir$#', $rota, $m):
            (new QuizController())->unirSugestoes((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/quiz/sugestao/(\d+)/separar$#', $rota, $m):
            (new QuizController())->separarSugestao((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/quiz/sugestao/(\d+)/excluir$#', $rota, $m):
            (new QuizController())->excluirSugestao((int)$m[1]); break;

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
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)/progresso$#', $rota, $m):
            (new ProjetoController())->atualizarProgresso((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/desdobramentos/(\d+)$#', $rota, $m):
            (new ProjetoController())->salvarDesdobramento((int)$m[1]); break;

        // Comentários de acompanhamento (sucederam o diário de bordo).
        // `POST /api/comentarios` recebe multipart, não JSON: os anexos sobem
        // com o texto no mesmo pedido.
        case $rota === 'GET /api/comentarios':      (new ComentarioController())->listar(); break;
        case $rota === 'POST /api/comentarios':     (new ComentarioController())->criar(); break;
        case (bool)preg_match('#^POST /api/comentarios/(\d+)/excluir$#', $rota, $m):
            (new ComentarioController())->excluir((int)$m[1]); break;
        // Devolve BYTES (não JSON): a rota escreve os próprios cabeçalhos.
        case (bool)preg_match('#^GET /api/anexos/(\d+)$#', $rota, $m):
            (new ComentarioController())->baixar((int)$m[1]); break;
        // Remove UM anexo sem apagar o comentário que o carrega.
        case (bool)preg_match('#^POST /api/anexos/(\d+)/excluir$#', $rota, $m):
            (new ComentarioController())->excluirAnexo((int)$m[1]); break;

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

        case $rota === 'GET /api/reunioes':         (new RelatorioController())->listarReunioes(); break;
        case $rota === 'POST /api/reunioes':        (new RelatorioController())->salvarReuniao(); break;
        case (bool)preg_match('#^POST /api/reunioes/(\d+)$#', $rota, $m):
            (new RelatorioController())->salvarReuniao((int)$m[1]); break;
        case (bool)preg_match('#^POST /api/reunioes/(\d+)/excluir$#', $rota, $m):
            (new RelatorioController())->excluirReuniao((int)$m[1]); break;

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

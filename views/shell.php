<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
  <title><?= htmlspecialchars($app['nome']) ?></title>
  <link rel="stylesheet" href="<?= versao_asset('/assets/vendor/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= versao_asset('/assets/css/app.css') ?>">
</head>
<body>
  <!-- Barra superior: o menu recolhe e expande automaticamente (☰ ou borda esquerda) -->
  <!--
    Ícones desenhados uma vez e reaproveitados por <use>. A engrenagem aparece
    em dois lugares (o atalho da topbar e o "trocar o ciclo" do menu) e são o
    MESMO gesto — copiar o caminho duas vezes faria os dois divergirem na
    primeira revisão do ícone, e o leitor lê "o mesmo símbolo" como "o mesmo
    destino". Fica escondido pelo atributo `hidden` (não por CSS): o `<use>`
    referencia o símbolo mesmo com o dono fora da tela.
  -->
  <svg hidden aria-hidden="true">
    <symbol id="i-engrenagem" viewBox="0 0 16 16" fill="currentColor">
      <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
      <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115l.094-.319z"/>
    </symbol>
    <symbol id="i-chevron" viewBox="0 0 16 16" fill="currentColor">
      <path d="M3.28 5.72a.75.75 0 0 1 1.06 0L8 9.38l3.66-3.66a.75.75 0 1 1 1.06 1.06l-4.19 4.19a.75.75 0 0 1-1.06 0L3.28 6.78a.75.75 0 0 1 0-1.06z"/>
    </symbol>
  </svg>

  <header class="topbar d-flex align-items-center gap-2 px-3">
    <button class="btn btn-outline-light btn-sm" id="btn-menu" aria-label="Abrir menu"
      aria-controls="menu-lateral" aria-expanded="false">☰</button>
    <!--
      Atalho para os Cadastros. Ele mora ao lado do ☰ porque é a tela que se
      abre para AJUSTAR o sistema (usuários, negócios, horizontes) e não faz
      parte do percurso do planejamento — procurá-la no meio das dezesseis
      seções do menu custava duas decisões antes do primeiro clique.
      É um <a> com data-secao, o mesmo contrato dos itens do menu: sem onclick,
      que a CSP não permite.
    -->
    <a class="btn btn-outline-light btn-sm d-inline-flex align-items-center justify-content-center"
      id="btn-cadastros" href="#cadastros" data-secao="cadastros"
      title="Cadastros e configurações" aria-label="Cadastros e configurações">
      <svg width="15" height="15" aria-hidden="true" focusable="false"><use href="#i-engrenagem"/></svg>
    </a>
    <span class="marca">COPÉRDIA</span>
    <!-- Some no celular: com a engrenagem ao lado do ☰ o subtítulo passou a
         quebrar em DUAS linhas dentro de uma topbar de 52px (medido em 390px).
         Ele é decoração — a marca já identifica o sistema, e o menu lateral
         repete o nome inteiro logo abaixo dela. -->
    <span class="text-white-50 small d-none d-sm-inline">Planejamento Estratégico</span>
    <span class="text-white-50 small ms-auto d-none d-md-inline" id="topbar-contexto"></span>
  </header>
  <div class="backdrop-menu" id="backdrop-menu"></div>

  <div class="d-flex">
    <!-- Menu lateral -->
    <nav id="menu-lateral" class="menu-lateral d-flex flex-column p-3">
      <div class="marca mb-1">COPÉRDIA</div>
      <!--
        `d-sm-none`: o subtítulo aparece aqui só onde a topbar o esconde (abaixo
        de 576px). Nas outras larguras ele já está lá em cima, e repeti-lo
        custava uma faixa do cabeçalho do menu — altura que sai das seções.
      -->
      <div class="text-white-50 small mb-2 d-sm-none">Planejamento Estratégico</div>

      <div class="mb-2">
        <!--
          O ciclo é ESCOLHIDO em Cadastros › Ciclos & Horizontes; aqui ele é só
          mostrado. Ele é decisão plurianual — troca-se uma vez por ano, não a
          cada tela —, e um seletor no menu ao lado do negócio (que troca o dia
          inteiro) convidava ao mesmo gesto para as duas coisas.
          O rótulo, o valor e o atalho dividem UMA linha: em três, o cabeçalho do
          menu comia quatro itens de navegação. O atalho virou a engrenagem — a
          mesma da topbar, pelo mesmo <use> —, que diz "isto se ajusta ali" sem
          gastar a linha que "trocar em Cadastros" gastava.
        -->
        <div class="d-flex align-items-center gap-1">
          <span class="text-white-50 small">Ciclo</span>
          <span class="ciclo-atual small text-truncate" id="ciclo-atual"></span>
          <a class="icone-menu ms-auto" href="#cadastros" data-secao="cadastros"
            id="link-trocar-ciclo" title="Trocar o ciclo em Cadastros"
            aria-label="Trocar o ciclo em Cadastros">
            <svg width="14" height="14" aria-hidden="true" focusable="false"><use href="#i-engrenagem"/></svg>
          </a>
        </div>
        <label class="form-label text-white-50 small mb-1 mt-1" for="sel-negocio">Negócio</label>
        <select id="sel-negocio" class="form-select form-select-sm"></select>
      </div>

      <ul class="nav nav-pills flex-column gap-1" id="nav-secoes">
        <li><a class="nav-link" href="#painel" data-secao="painel">Painel</a></li>
        <li><a class="nav-link" href="#hub" data-secao="hub">Hub do Planejamento</a></li>
        <li><a class="nav-link" href="#cadastros" data-secao="cadastros">Cadastros</a></li>
        <li class="nav-item mt-2 text-white-50 small">Diagnóstico</li>
        <li><a class="nav-link" href="#coleta" data-secao="coleta">Coleta e Tempestade</a></li>
        <li><a class="nav-link" href="#cenario" data-secao="cenario">Análise de Cenário</a></li>
        <li><a class="nav-link" href="#pestel" data-secao="pestel">PESTEL</a></li>
        <li><a class="nav-link" href="#porter" data-secao="porter">Porter — 5 Forças</a></li>
        <li><a class="nav-link" href="#swot" data-secao="swot">SWOT</a></li>
        <li><a class="nav-link" href="#gut" data-secao="gut">Matriz GUT</a></li>
        <li><a class="nav-link" href="#cruzamentos" data-secao="cruzamentos">Cruzamentos</a></li>
        <li class="nav-item mt-2 text-white-50 small">Estratégia</li>
        <li><a class="nav-link" href="#cascata" data-secao="cascata">Cascata de Escolhas</a></li>
        <li class="nav-item mt-2 text-white-50 small">Execução</li>
        <li><a class="nav-link" href="#projetos" data-secao="projetos">Projetos · 5W2H</a></li>
        <li class="nav-item mt-2 text-white-50 small">Capital</li>
        <li><a class="nav-link" href="#investimentos" data-secao="investimentos">Investimentos</a></li>
        <li class="nav-item mt-2 text-white-50 small">Gestão</li>
        <li><a class="nav-link" href="#metas" data-secao="metas">Metas · Indicadores</a></li>
        <li><a class="nav-link" href="#relatorio" data-secao="relatorio">Relatório de Status</a></li>
        <li class="nav-item mt-2 text-white-50 small">Encontro</li>
        <li><a class="nav-link" href="#sala" data-secao="sala">Sala · PIN e QR code</a></li>
      </ul>

      <div class="mt-auto pt-3 border-top border-secondary">
        <div class="text-white small" id="usuario-nome"></div>
        <div class="text-white-50 small" id="usuario-perfil"></div>
        <div class="d-flex gap-2 mt-2">
          <button class="btn btn-sm btn-outline-light" id="btn-senha">Trocar senha</button>
          <button class="btn btn-sm btn-outline-light" id="btn-sair">Sair</button>
        </div>
      </div>
    </nav>

    <!-- Conteúdo -->
    <main class="conteudo flex-grow-1 p-4">
      <section id="secao-painel" class="secao d-none"></section>
      <section id="secao-hub" class="secao d-none"></section>
      <section id="secao-cadastros" class="secao d-none"></section>
      <section id="secao-coleta" class="secao d-none"></section>
      <section id="secao-cenario" class="secao d-none"></section>
      <section id="secao-pestel" class="secao d-none"></section>
      <section id="secao-porter" class="secao d-none"></section>
      <section id="secao-swot" class="secao d-none"></section>
      <section id="secao-gut" class="secao d-none"></section>
      <section id="secao-cruzamentos" class="secao d-none"></section>
      <section id="secao-cascata" class="secao d-none"></section>
      <section id="secao-projetos" class="secao d-none"></section>
      <section id="secao-investimentos" class="secao d-none"></section>
      <section id="secao-metas" class="secao d-none"></section>
      <section id="secao-relatorio" class="secao d-none"></section>
      <section id="secao-sala" class="secao d-none"></section>
    </main>
  </div>

  <!-- Modal genérico de cadastro -->
  <div class="modal fade" id="modal-form" tabindex="-1" aria-labelledby="modal-titulo">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h6" id="modal-titulo"></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"
            aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <form id="modal-campos"></form>
          <div id="modal-erro" class="alert alert-danger d-none py-2 mt-2" role="alert"></div>
          <!-- O corpo do modal rola, mas nada dizia isso: numa janela baixa o
               formulário mostrava os primeiros campos e o Salvar logo abaixo
               (ele mora no rodapé fixo), e quem preenchia o que via e salvava
               nunca soube que havia mais. Fica por último de propósito: como é
               `position: sticky; bottom: 0`, ele acompanha o fim da área
               visível enquanto sobrar campo, e só sai quando a rolagem acaba. -->
          <div id="modal-mais" class="aviso-rolagem d-none" role="status" aria-live="polite">mais campos abaixo ↓</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-danger btn-sm me-auto d-none" id="modal-extra"></button>
          <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-verde btn-sm" id="modal-salvar">Salvar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="<?= versao_asset('/assets/vendor/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= versao_asset('/assets/vendor/qrcode.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/modal.js') ?>"></script>
  <!-- As peças da sala do quiz (selo, 🎤, roteiro) são as mesmas em toda tela
       que conduz um encontro: carregam antes das seções, que as consomem -->
  <script src="<?= versao_asset('/assets/js/quiz.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/relatorio-analise.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/painel.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/hub.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/cadastros.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/diagnostico.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/cruzamentos.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/coleta.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/cascata.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/projetos.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/investimentos.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/metas.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/relatorio.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/sala.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/app.js') ?>"></script>
</body>
</html>

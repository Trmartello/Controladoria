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
  <header class="topbar d-flex align-items-center gap-2 px-3">
    <button class="btn btn-outline-light btn-sm" id="btn-menu" aria-label="Abrir menu"
      aria-controls="menu-lateral" aria-expanded="false">☰</button>
    <span class="marca">COPÉRDIA</span>
    <span class="text-white-50 small">Planejamento Estratégico</span>
    <span class="text-white-50 small ms-auto d-none d-md-inline" id="topbar-contexto"></span>
  </header>
  <div class="backdrop-menu" id="backdrop-menu"></div>

  <div class="d-flex">
    <!-- Menu lateral -->
    <nav id="menu-lateral" class="menu-lateral d-flex flex-column p-3">
      <div class="marca mb-1">COPÉRDIA</div>
      <div class="text-white-50 small mb-3">Planejamento Estratégico</div>

      <div class="mb-3">
        <label class="form-label text-white-50 small mb-1">Ciclo</label>
        <select id="sel-ciclo" class="form-select form-select-sm"></select>
        <label class="form-label text-white-50 small mb-1 mt-2">Negócio</label>
        <select id="sel-negocio" class="form-select form-select-sm"></select>
      </div>

      <ul class="nav nav-pills flex-column gap-1" id="nav-secoes">
        <li><a class="nav-link" href="#painel" data-secao="painel">Painel</a></li>
        <li><a class="nav-link" href="#hub" data-secao="hub">Hub do Planejamento</a></li>
        <li><a class="nav-link" href="#cadastros" data-secao="cadastros">Cadastros</a></li>
        <li class="nav-item mt-2 text-white-50 small">Diagnóstico</li>
        <li><a class="nav-link" href="#coleta" data-secao="coleta">Coleta de Ideias</a></li>
        <li><a class="nav-link" href="#cenario" data-secao="cenario">Análise de Cenário</a></li>
        <li><a class="nav-link" href="#pestel" data-secao="pestel">PESTEL</a></li>
        <li><a class="nav-link" href="#porter" data-secao="porter">Porter — 5 Forças</a></li>
        <li><a class="nav-link" href="#swot" data-secao="swot">SWOT</a></li>
        <li><a class="nav-link" href="#gut" data-secao="gut">Matriz GUT</a></li>
        <li class="nav-item mt-2 text-white-50 small">Estratégia</li>
        <li><a class="nav-link" href="#cascata" data-secao="cascata">Cascata de Escolhas</a></li>
        <li class="nav-item mt-2 text-white-50 small">Execução</li>
        <li><a class="nav-link" href="#projetos" data-secao="projetos">Projetos · 5W2H</a></li>
        <li class="nav-item mt-2 text-white-50 small">Capital</li>
        <li><a class="nav-link" href="#investimentos" data-secao="investimentos">Investimentos</a></li>
        <li class="nav-item mt-2 text-white-50 small">Gestão</li>
        <li><a class="nav-link" href="#metas" data-secao="metas">Metas · Indicadores</a></li>
        <li><a class="nav-link" href="#relatorio" data-secao="relatorio">Relatório de Status</a></li>
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
      <section id="secao-cascata" class="secao d-none"></section>
      <section id="secao-projetos" class="secao d-none"></section>
      <section id="secao-investimentos" class="secao d-none"></section>
      <section id="secao-metas" class="secao d-none"></section>
      <section id="secao-relatorio" class="secao d-none"></section>
    </main>
  </div>

  <!-- Modal genérico de cadastro -->
  <div class="modal fade" id="modal-form" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title h6" id="modal-titulo"></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="modal-campos"></form>
          <div id="modal-erro" class="alert alert-danger d-none py-2 mt-2"></div>
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
  <script src="<?= versao_asset('/assets/js/modal.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/painel.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/hub.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/cadastros.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/diagnostico.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/coleta.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/cascata.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/projetos.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/investimentos.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/metas.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/secoes/relatorio.js') ?>"></script>
  <script src="<?= versao_asset('/assets/js/app.js') ?>"></script>
</body>
</html>

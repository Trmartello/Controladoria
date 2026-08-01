<?php
/**
 * Tela do participante da tempestade de ideias.
 *
 * Página deliberadamente isolada: não carrega o shell do app, nem app.js, nem
 * nenhuma seção. Quem entra aqui não tem sessão e não pode ter acesso a nada
 * além de escrever a própria ideia na rodada.
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Tempestade de ideias — Copérdia</title>
  <link rel="stylesheet" href="<?= versao_asset('/assets/vendor/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= versao_asset('/assets/css/app.css') ?>">
</head>
<body class="corpo-participante">
  <div class="participante-topo">
    <span class="marca">COPÉRDIA</span>
    <span class="small">Tempestade de ideias</span>
  </div>
  <main class="participante-area" id="tela" data-pin="<?= htmlspecialchars($pin, ENT_QUOTES, 'UTF-8') ?>"></main>
  <script src="<?= versao_asset('/assets/js/participante.js') ?>"></script>
</body>
</html>

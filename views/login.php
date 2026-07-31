<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
  <title>Entrar — <?= htmlspecialchars($app['nome']) ?></title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body d-flex align-items-center justify-content-center vh-100">
  <div class="card shadow login-card">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <div class="marca">COPÉRDIA</div>
        <h1 class="h5 mt-2">Planejamento Estratégico</h1>
      </div>
      <form id="form-login">
        <div class="mb-3">
          <label class="form-label" for="email">E-mail</label>
          <input type="email" class="form-control" id="email" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="senha">Senha</label>
          <div class="input-group">
            <input type="password" class="form-control" id="senha" required>
            <button class="btn btn-outline-secondary" type="button" id="btn-ver-senha"
              aria-label="Mostrar senha" title="Mostrar senha">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
              </svg>
            </button>
          </div>
        </div>
        <div id="erro-login" class="alert alert-danger d-none py-2"></div>
        <button class="btn btn-verde w-100" type="submit">Entrar</button>
      </form>
    </div>
  </div>
  <script src="/assets/js/login.js"></script>
</body>
</html>

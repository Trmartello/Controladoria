<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
  <title>Entrar — <?= htmlspecialchars($app['nome']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
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
          <input type="password" class="form-control" id="senha" required>
        </div>
        <div id="erro-login" class="alert alert-danger d-none py-2"></div>
        <button class="btn btn-verde w-100" type="submit">Entrar</button>
      </form>
    </div>
  </div>
  <script>
    document.getElementById('form-login').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const resp = await fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: document.getElementById('email').value,
          senha: document.getElementById('senha').value,
        }),
      });
      const json = await resp.json();
      if (json.ok) {
        window.location.href = '/';
      } else {
        const erro = document.getElementById('erro-login');
        erro.textContent = json.erro || 'Falha no login.';
        erro.classList.remove('d-none');
      }
    });
  </script>
</body>
</html>

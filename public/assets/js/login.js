// Login — script externo (a CSP bloqueia scripts inline).

// Olho de conferir a senha antes de entrar
document.getElementById('btn-ver-senha').addEventListener('click', () => {
  const campo = document.getElementById('senha');
  const botao = document.getElementById('btn-ver-senha');
  const mostrar = campo.type === 'password';
  campo.type = mostrar ? 'text' : 'password';
  botao.title = mostrar ? 'Ocultar senha' : 'Mostrar senha';
  botao.setAttribute('aria-label', botao.title);
  botao.classList.toggle('active', mostrar);
  campo.focus();
});

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

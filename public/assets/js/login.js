// Login — script externo (a CSP bloqueia scripts inline).

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

// A tela pública da tempestade (/entrar/{pin}) no celular.
//
// É a única superfície de ESCRITA sem autenticação do sistema, e a que roda na
// mão de trinta pessoas ao mesmo tempo durante a oficina — por isso tem bateria
// própria, e por isso ela testa as invariantes que já custaram defeito: o
// polling que fecha o teclado no meio da frase e a sessão que não pode nascer.
//
//   node testes/participante.js <pin-de-uma-rodada-aberta>
const { BASE, chromiumExec, playwright, esperar, relatar } = require('./comum');

const ok = [], bad = [], erros = [];
const t = (n, c, e = '') => (c ? ok : bad).push(n + (e ? ` — ${e}` : ''));

(async () => {
  const pin = process.argv[2];
  if (!/^\d{6}$/.test(pin || '')) {
    console.error('uso: node testes/participante.js <pin>   (PIN de 6 dígitos de uma rodada ABERTA)');
    process.exit(2);
  }

  const { chromium } = playwright();
  const browser = await chromium.launch({ executablePath: chromiumExec() });
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', isMobile: true, hasTouch: true,
  });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/favicon|net::ERR_ABORTED/.test(m.text())) {
      erros.push('console: ' + m.text().slice(0, 160));
    }
  });

  await page.goto(`${BASE}/entrar/${pin}`);
  t('Abre pelo link com PIN, sem login', await esperar(page, "!!document.querySelector('#campo-nome')"));

  // A rota pública NÃO inicia sessão: se iniciasse, cada visitante anônimo
  // criaria uma linha em `sessao` retida por 30 dias.
  const temSessao = await page.evaluate(() => document.cookie.includes('PHPSESSID'));
  t('Não cria sessão no visitante anônimo', !temSessao);

  // São DOIS campos: #campo-pin (já preenchido pelo link) e #campo-nome.
  // Preencher `input` genérico apaga o PIN e a entrada falha.
  await page.fill('#campo-nome', 'Participante de teste');
  await page.click('#btn-entrar');
  const entrou = await esperar(page, "!!document.querySelector('#campo-ideia, textarea')", 12000);
  t('Entra na sala e vê o campo de ideia', entrou);
  if (!entrou) { await browser.close(); process.exit(relatar(ok, bad, erros)); }

  const texto = 'Ideia enviada pela bateria de teste';
  await page.fill('#campo-ideia, textarea', texto);
  await page.click('#btn-enviar, button[type=submit]');
  t('Ideia enviada aparece na própria tela',
    await esperar(page, `document.body.textContent.includes(${JSON.stringify(texto)})`, 10000));

  // A invariante mais cara da tela: o polling NUNCA redesenha com o campo em
  // foco ou com texto digitado. No celular, redesenhar tira o foco e FECHA O
  // TECLADO no meio da frase — o participante simplesmente não consegue
  // escrever. Dois ciclos de polling com o campo em uso.
  await page.focus('#campo-ideia, textarea');
  await page.type('#campo-ideia, textarea', 'texto a meio digitar');
  const ler = () => page.evaluate(() => document.activeElement.tagName + '|' + (document.activeElement.value || ''));
  const antes = await ler();
  await new Promise((r) => setTimeout(r, 7000));
  const depois = await ler();
  t('Polling não rouba o foco nem apaga o que está sendo digitado', antes === depois,
    `antes=${antes} depois=${depois}`);

  const rola = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  t('Não rola na horizontal', !rola);

  // Alvo de toque: o resto do sistema adota 2,25rem (36px) de propósito
  const pequenos = await page.$$eval('button', (bs) => bs
    .filter((b) => b.offsetParent !== null)
    .map((b) => ({ t: b.textContent.trim().slice(0, 20) || '(ícone)', h: Math.round(b.getBoundingClientRect().height) }))
    .filter((b) => b.h > 0 && b.h < 36));
  t('Botões com altura de toque de ao menos 36px', pequenos.length === 0, JSON.stringify(pequenos));

  // A identidade tem de ATRAVESSAR a aba. Ela era guardada no `sessionStorage`,
  // que morre com a aba: quem voltava pelo link entrava como OUTRA pessoa, via
  // as próprias estrelas apagadas e votava de novo — a mesma pessoa pesando
  // duas vezes no ranking, sem sinal nenhum na tela. Num encontro à distância,
  // voltar pelo link é o gesto comum e não a exceção.
  const meuToken = await page.evaluate(() => Participante.token);
  const aba2 = await ctx.newPage();
  await aba2.goto(`${BASE}/entrar/${pin}`);
  const seguiuDentro = await esperar(aba2,
    "!!document.querySelector('#campo-ideia, [data-votar], .tema-rodada')", 12000);
  t('Voltar pelo link numa aba nova mantém a pessoa dentro da sala', seguiuDentro);
  t('E mantém a MESMA identidade (o token não é recunhado)',
    seguiuDentro && (await aba2.evaluate(() => Participante.token)) === meuToken);
  await aba2.close();

  // Identidade local apagada, mas o APARELHO continua conhecido pelo servidor:
  // ele devolve o token sem pedir nada. É a rede de segurança de quem limpa os
  // dados do site no meio do encontro — ou de quem entra de casa por um
  // navegador que descarta armazenamento sozinho.
  await page.evaluate((p) => localStorage.removeItem(`tempestade:${p}`), pin);
  await page.reload();
  const voltouSozinho = await esperar(page,
    "!!document.querySelector('#campo-ideia, [data-votar], .tema-rodada')", 12000);
  t('Identidade apagada: o aparelho conhecido traz a pessoa de volta sozinho', voltouSozinho);
  t('E de volta com o mesmo token',
    voltouSozinho && (await page.evaluate(() => Participante.token)) === meuToken);

  // A contrapartida do armazenamento que persiste: num computador de
  // departamento, a segunda pessoa a sentar precisa conseguir sair da primeira.
  page.once('dialog', (d) => d.accept());
  await page.click('#btn-trocar-pessoa');
  t('"não é você?" devolve a tela de entrada',
    await esperar(page, "!!document.querySelector('#campo-nome')", 8000));
  t('E apaga a identidade guardada',
    await page.evaluate((p) => !localStorage.getItem(`tempestade:${p}`), pin));

  // PIN errado não pode devolver pista de PIN válido
  const page2 = await ctx.newPage();
  await page2.goto(`${BASE}/entrar/000000`);
  await esperar(page2, "!!document.querySelector('#campo-nome, .alert')");
  const vazou = await page2.evaluate(() =>
    /pin|token/i.test(document.body.innerHTML) && /\b\d{6}\b/.test(document.body.textContent));
  t('PIN inválido não revela outro PIN', !vazou);

  await browser.close();
  process.exit(relatar(ok, bad, erros));
})().catch((e) => { console.error('ERRO:', e.message); process.exit(2); });

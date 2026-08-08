// Peças compartilhadas pelas baterias de navegador.
//
// Ficam aqui e não em cada arquivo porque as duas precisam resolver o mesmo
// binário do Chromium e fazer o mesmo login: escritas separadas, divergiriam
// na primeira mudança de tela de login.
const fs = require('fs');
const path = require('path');

const BASE = process.env.APP_URL || 'http://127.0.0.1:8099';
const EMAIL = process.env.APP_EMAIL || 'admin@coperdia.com.br';
const SENHA = process.env.APP_SENHA || 'trocar123';

/**
 * Caminho do Chromium sem versão fixa no código.
 *
 * O Chromium novo REMOVEU o headless antigo, então `chromium.launch()` sem
 * `executablePath` não sobe neste ambiente — é preciso apontar para o
 * `headless_shell`. E o número da build muda a cada atualização da imagem:
 * fixá-lo faria a bateria parar de rodar sem ninguém entender por quê.
 */
function chromiumExec() {
  const raiz = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
  let dirs = [];
  try {
    dirs = fs.readdirSync(raiz).filter((d) => d.startsWith('chromium_headless_shell-'));
  } catch { /* raiz não existe: cai no padrão do Playwright */ }
  // Maior build primeiro, comparando o número e não o texto ("1194" > "999")
  dirs.sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]));
  for (const d of dirs) {
    const p = path.join(raiz, d, 'chrome-linux', 'headless_shell');
    if (fs.existsSync(p)) return p;
  }
  return undefined; // deixa o Playwright decidir
}

function playwright() {
  // A imagem instala o Playwright global; fora dela, o do próprio projeto
  try { return require('/opt/node22/lib/node_modules/playwright'); }
  catch { return require('playwright'); }
}

/**
 * Espera uma condição no navegador.
 *
 * Laço com `page.evaluate` em vez de `page.waitForFunction`: a CSP da aplicação
 * não tem `unsafe-eval`, e o `waitForFunction` avalia string como JS — ele é
 * bloqueado e o teste falha por um motivo que não é o testado.
 */
async function esperar(page, fn, ms = 12000) {
  const ate = Date.now() + ms;
  while (Date.now() < ate) {
    if (await page.evaluate(fn).catch(() => false)) return true;
    await new Promise((r) => setTimeout(r, 150));
  }
  return false;
}

/** Registra erro de página e de console para o relatório final. */
function vigiar(page, rotulo, erros) {
  page.on('pageerror', (e) => erros.push(`[${rotulo}] pageerror: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const txt = m.text();
    if (!/favicon|net::ERR_ABORTED/.test(txt)) erros.push(`[${rotulo}] console: ${txt.slice(0, 200)}`);
  });
}

/** Faz login e devolve a página já com o contexto corporativo escolhido. */
async function entrar(ctx, rotulo, erros) {
  const page = await ctx.newPage();
  if (erros) vigiar(page, rotulo, erros);
  await page.goto(`${BASE}/login`);
  await page.fill('#email', EMAIL);
  await page.fill('#senha', SENHA);
  await page.click('#form-login button[type=submit]');
  await esperar(page, "typeof App !== 'undefined' && !!document.querySelector('#nav-secoes')", 20000);
  // O contexto (ciclo + negócio) mora em App.contexto, não na URL: sem ele
  // toda seção do diagnóstico responde "Selecione o ciclo e o negócio".
  await page.evaluate(() => {
    App.contexto.cicloId = 1;
    App.contexto.negocioId = null;
    App.contexto.corporativo = true;
  });
  return page;
}

function relatar(ok, bad, erros = []) {
  console.log(`\n✓ ${ok.length} passaram`);
  ok.forEach((o) => console.log('  ✓ ' + o));
  if (bad.length) { console.log(`\n✗ ${bad.length} FALHARAM`); bad.forEach((b) => console.log('  ✗ ' + b)); }
  if (erros.length) {
    console.log(`\n⚠ ${erros.length} erro(s) de página/console:`);
    [...new Set(erros)].slice(0, 25).forEach((e) => console.log('  ⚠ ' + e));
  }
  return bad.length || erros.length ? 1 : 0;
}

module.exports = { BASE, EMAIL, SENHA, chromiumExec, playwright, esperar, vigiar, entrar, relatar };

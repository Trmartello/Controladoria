// Bateria de sistema: percorre as 16 seções em desktop e celular e afirma que
// cada uma PINTA de verdade — não só que a casca do shell existe. Registra todo
// erro de página e de console: um `pageerror` numa seção é falha, mesmo que a
// tela pareça certa.
//
//   node testes/sistema.js
const { chromiumExec, playwright, esperar, entrar, relatar } = require('./comum');

const ok = [], bad = [], erros = [];
const t = (nome, cond, extra = '') => (cond ? ok : bad).push(nome + (extra ? ` — ${extra}` : ''));

// Cada seção com a PROVA de que ela terminou de carregar: um seletor que só
// existe depois da carga. Esperar pelo `<section>` não serve — ele já está no
// shell, vazio, desde o começo.
const SECOES = [
  { id: 'painel', nome: 'Painel', prova: '#secao-painel table, #secao-painel .card, #secao-painel .alert' },
  { id: 'hub', nome: 'Hub do Planejamento', prova: '#secao-hub .card, #secao-hub .alert' },
  { id: 'cadastros', nome: 'Cadastros', prova: '#secao-cadastros .nav-tabs' },
  { id: 'coleta', nome: 'Coleta e Tempestade', prova: '#secao-coleta h1, #secao-coleta .card' },
  { id: 'cenario', nome: 'Análise de Cenário', prova: '#secao-cenario h1' },
  { id: 'pestel', nome: 'PESTEL', prova: '#secao-pestel h1' },
  { id: 'porter', nome: 'Porter', prova: '#secao-porter h1' },
  { id: 'swot', nome: 'SWOT', prova: '#secao-swot h1' },
  { id: 'gut', nome: 'Matriz GUT', prova: '#secao-gut .gut-legenda-barra' },
  { id: 'cruzamentos', nome: 'Cruzamentos (TOWS)', prova: '#secao-cruzamentos [data-coluna-categoria]' },
  { id: 'cascata', nome: 'Cascata de Escolhas', prova: '#secao-cascata h1' },
  { id: 'projetos', nome: 'Projetos · 5W2H', prova: '#secao-projetos h1' },
  { id: 'investimentos', nome: 'Investimentos', prova: '#secao-investimentos h1' },
  { id: 'metas', nome: 'Metas · Indicadores', prova: '#secao-metas h1' },
  { id: 'relatorio', nome: 'Relatório de Status', prova: '#secao-relatorio h1' },
  { id: 'sala', nome: 'Sala · PIN e QR', prova: '#secao-sala h1' },
];

async function percorrer(page, largura) {
  for (const s of SECOES) {
    await page.evaluate((id) => App.mostrarSecao(id), s.id);
    const pintou = await esperar(page, `!!document.querySelector('${s.prova}')`, 15000);
    t(`[${largura}] ${s.nome} pinta`, pintou);
    if (!pintou) continue;

    // Nenhuma seção pode terminar mostrando o alerta de falha de carga
    const falhou = await page.evaluate((id) => {
      const el = document.getElementById('secao-' + id);
      const al = el && el.querySelector('.alert-danger');
      return al ? al.textContent.trim().slice(0, 120) : null;
    }, s.id);
    t(`[${largura}] ${s.nome} sem alerta de erro`, !falhou, falhou || '');

    // Rolagem horizontal é defeito nas DUAS larguras, não só no celular. No
    // computador ela nasce de outro jeito: um texto que não quebra (um selo com
    // `white-space: nowrap`) vira a largura MÍNIMA da coluna, e essa mínima
    // sobe pela fila até o `<main>`, que é item de flex — a página inteira sai
    // da janela. Foi assim nos Cruzamentos, e o teste só olhava o celular.
    const rola = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    t(`[${largura}] ${s.nome} não rola na horizontal`, !rola,
      rola ? await page.evaluate(() => `${document.documentElement.scrollWidth}px > ${window.innerWidth}px`) : '');
  }
}

/**
 * Duas invariantes da Matriz GUT que já custaram defeito e não aparecem no
 * "a seção pinta": o cabeçalho que precisa GRUDAR ao rolar (sem ele as quatro
 * colunas de número viram números anônimos) e o aviso de campo abaixo da dobra
 * no modal (sem ele o Esforço ficava "não estimado" sem ninguém escolher isso).
 */
async function provasGut(page) {
  await page.evaluate(() => App.mostrarSecao('gut'));
  await esperar(page, "!document.getElementById('secao-gut').classList.contains('d-none')", 15000);

  await page.evaluate(() => window.scrollTo(0, 900));
  await new Promise((r) => setTimeout(r, 400));
  const fixo = await page.evaluate(() => {
    const topo = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topo-app'), 10);
    const cab = document.querySelector('#secao-gut .cabecalho-gut');
    const th = document.querySelector('#secao-gut .tabela-gut thead th');
    if (!cab || !th) return null;
    return { cab: Math.round(cab.getBoundingClientRect().top),
      th: Math.round(th.getBoundingClientRect().top), topo };
  });
  t('[desktop] Cabeçalho da GUT gruda abaixo da topbar ao rolar',
    !!fixo && fixo.cab === fixo.topo, JSON.stringify(fixo));
  t('[desktop] Cabeçalho da tabela gruda abaixo do título, sem cobri-lo',
    !!fixo && fixo.th > fixo.cab, JSON.stringify(fixo));

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.click('#secao-gut tbody tr:first-child [data-avaliar]');
  const abriu = await esperar(page, "!!document.getElementById('campo-esforco')", 8000);
  t('[desktop] Modal da GUT traz o campo de esforço', abriu);
  if (!abriu) return;
  await new Promise((r) => setTimeout(r, 400));
  const dobra = await page.evaluate(() => {
    const c = document.querySelector('.modal-body');
    const a = document.getElementById('modal-mais');
    return { sobra: c.scrollHeight - c.clientHeight > 8, aviso: !a.classList.contains('d-none') };
  });
  t('[desktop] Campo abaixo da dobra é anunciado (e só então)',
    dobra.sobra === dobra.aviso, JSON.stringify(dobra));
  await page.keyboard.press('Escape');
}

(async () => {
  const { chromium } = playwright();
  const browser = await chromium.launch({ executablePath: chromiumExec() });

  // `reducedMotion` é obrigatório: sem ele o modal do Bootstrap deixa a classe
  // `.show` pendurada por falta do `transitionend`, e "o modal fechou" vira
  // falso-negativo em qualquer teste que feche diálogo.
  // 700px de altura de propósito: é a janela de notebook em que o modal da GUT
  // não cabia inteiro, e a dobra é justamente o que se quer provar.
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 700 }, reducedMotion: 'reduce' });
  const page = await entrar(ctx, 'desktop', erros);
  await percorrer(page, 'desktop');
  await provasGut(page);

  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', isMobile: true, hasTouch: true,
  });
  await percorrer(await entrar(ctxM, 'celular', erros), 'celular');

  await browser.close();
  process.exit(relatar(ok, bad, erros));
})().catch((e) => { console.error('ERRO:', e.message); process.exit(2); });

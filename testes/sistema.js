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
 * O atalho ⚙ da topbar. Ele não é item do `#nav-secoes`, então o percurso das
 * seções não o exercita: o listener de navegação precisa alcançá-lo pelo
 * atributo, e não pelo lugar.
 *
 * A segunda prova é de LAYOUT e nasceu de uma regressão medida: com a
 * engrenagem ao lado do ☰, "Planejamento Estratégico" passou a quebrar em duas
 * linhas dentro de uma topbar de 52px no celular. Botão novo na topbar é
 * espaço tirado de alguém — quem confere é a altura da linha, não o olho.
 */
async function provasAtalhoCadastros(page, largura) {
  await page.evaluate(() => App.mostrarSecao('painel'));
  await page.click('#btn-cadastros');
  const abriu = await esperar(page,
    "App.secaoAtiva === 'cadastros' && !document.getElementById('secao-cadastros').classList.contains('d-none')");
  t(`[${largura}] ⚙ da topbar abre os Cadastros`, abriu);
  t(`[${largura}] ⚙ marca a tela ativa`,
    await page.evaluate(() => document.getElementById('btn-cadastros').getAttribute('aria-current') === 'page'));
  await page.evaluate(() => App.mostrarSecao('painel'));
  t(`[${largura}] ⚙ desmarca ao sair`,
    await page.evaluate(() => !document.getElementById('btn-cadastros').getAttribute('aria-current')));

  // Contar LINHAS DE TEXTO, com um Range sobre o conteúdo: ele devolve um
  // retângulo por linha. As duas medidas óbvias não servem, as duas testadas:
  // a altura da topbar é fixa em 52px com transbordo visível (continua 52 com o
  // texto quebrado) e `elemento.getClientRects()` devolve UM retângulo mesmo
  // com duas linhas, porque item de flex é blocificado.
  const quebrados = await page.evaluate(() =>
    [...document.querySelectorAll('.topbar > *')]
      .filter((el) => el.getClientRects().length && !el.querySelector('*'))
      .filter((el) => {
        const r = document.createRange();
        r.selectNodeContents(el);
        return r.getClientRects().length > 1;
      })
      .map((el) => el.textContent.trim().slice(0, 40)));
  t(`[${largura}] nada na topbar quebra em duas linhas`,
    quebrados.length === 0, JSON.stringify(quebrados));
}

/**
 * O ciclo saiu do menu lateral e passou a ser escolhido em Cadastros › Ciclos &
 * Horizontes; o menu só MOSTRA qual é. São duas telas que precisam concordar,
 * e o contexto do ciclo alimenta toda consulta do sistema — um rótulo vazio no
 * menu, ou um seletor apontando para outro ciclo, é o tipo de divergência que
 * só aparece quando alguém estranha o número na tela.
 */
async function provasCiclo(page, largura) {
  t(`[${largura}] o menu não tem mais seletor de ciclo`,
    await page.evaluate(() => !document.getElementById('sel-ciclo')));
  const rotulo = await page.evaluate(() => document.getElementById('ciclo-atual').textContent.trim());
  t(`[${largura}] o menu mostra o ciclo em uso`,
    rotulo.length > 0 && rotulo !== '(nenhum ciclo)', rotulo);

  // A compactação do cabeçalho do menu: rótulo, valor e ⚙ em UMA linha. Se o
  // nome do ciclo crescer e empurrar a engrenagem para baixo, o cabeçalho volta
  // a comer itens de navegação — e é por isso que o valor tem `text-truncate`.
  const linhaCiclo = await page.evaluate(() => {
    document.body.classList.add('menu-aberto');
    const alvos = [document.getElementById('ciclo-atual'), document.getElementById('link-trocar-ciclo')];
    const topos = alvos.map((el) => Math.round(el.getBoundingClientRect().top));
    return { topos, iguais: topos[0] === topos[1] || Math.abs(topos[0] - topos[1]) <= 8 };
  });
  t(`[${largura}] ciclo e ⚙ na mesma linha do menu`, linhaCiclo.iguais, JSON.stringify(linhaCiclo.topos));

  // O ⚙ do menu leva ao mesmo lugar que o da topbar — mesmo ícone, mesmo destino.
  await page.evaluate(() => { App.mostrarSecao('painel'); document.getElementById('link-trocar-ciclo').click(); });
  t(`[${largura}] o ⚙ do menu abre os Cadastros`,
    await esperar(page, "App.secaoAtiva === 'cadastros'"));

  // A aba é clicada em LAÇO, pelo DOM. Duas armadilhas já pagas aqui: chamar
  // `SecaoCadastros.carregar()` à mão corre com a repintura que `mostrarSecao`
  // dispara (vencia a que terminasse por último, às vezes a aba Negócios), e o
  // `page.click` do Playwright espera o elemento ficar estável — mas ele é
  // substituído a cada repintura, então a espera nunca termina.
  await page.evaluate(() => App.mostrarSecao('cadastros'));
  const temSeletor = await esperar(page, () => {
    if (document.getElementById('sel-ciclo-uso')) return true;
    document.querySelector('#abas-cadastro [data-aba="ciclos"]')?.click();
    return false;
  }, 20000);
  t(`[${largura}] Cadastros › Ciclos traz o seletor do ciclo em uso`, temSeletor);
  if (temSeletor) {
    // O seletor e o menu falam do MESMO ciclo — é a divergência que interessa.
    const bate = await page.evaluate(() =>
      Number(document.getElementById('sel-ciclo-uso').value) === App.contexto.cicloId
      && document.getElementById('ciclo-atual').textContent.includes(
        document.querySelector('#sel-ciclo-uso option:checked').textContent.trim().split(' (base')[0]));
    t(`[${largura}] o seletor e o menu apontam para o mesmo ciclo`, bate);
    t(`[${largura}] o ciclo em uso é marcado na lista`,
      await page.evaluate(() => !!document.querySelector('#conteudo-aba .badge.text-bg-success')));
  }
  await page.evaluate(() => App.mostrarSecao('painel'));
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

/**
 * O formulário da AÇÃO: a ordem dos campos e as duas linhas agrupadas.
 *
 * A ordem é pedido do cliente, e a agrupada é medida — "na mesma linha" só é
 * verdade se os três campos dividirem o mesmo topo. Com a mínima da coluna em
 * 12rem eles couberam DOIS por fileira no modal (~465px por dentro) e o
 * "Repetir até" desceu sozinho: passava numa conferência de olho, que vê três
 * campos agrupados, e falhava no que foi pedido.
 */
async function provasAcao(page) {
  const prj = await page.evaluate(async () => {
    const p = await App.api('/api/projetos', {
      planejamento_id: 1, titulo: 'Projeto prova ação', ano: 2027, responsavel: 'QA', descricao: 'x',
    });
    await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: p.id, titulo: 'Frente prova' });
    return p.id;
  });
  await page.evaluate(() => App.mostrarSecao('projetos'));
  await esperar(page, "!document.getElementById('secao-projetos').classList.contains('d-none')", 15000);
  const temBotao = await esperar(page, "!!document.querySelector('[data-nova-acao]')", 15000);
  t('[desktop] Iniciativa oferece "+ Ação"', temBotao);
  if (temBotao) {
    // O botão mora no acordeão recolhido; o formulário é o mesmo do clique.
    await page.evaluate(() => {
      const b = document.querySelector('[data-nova-acao]');
      SecaoProjetos.modalDesdobramento(parseInt(b.dataset.proj, 10), null, parseInt(b.dataset.novaAcao, 10));
    });
    const abriu = await esperar(page, "!!document.getElementById('campo-o_que')", 8000);
    t('[desktop] Modal da ação abre', abriu);
    if (abriu) {
      const ordem = await page.evaluate(() => [...document.querySelectorAll('#modal-campos .form-label')]
        .map((l) => l.textContent.trim().replace(/0%$/, '')));
      const esperada = ['O quê? *', 'Como? *', 'Quem? *', 'Quando? *', 'Prioridade', 'Repetição',
        'Repete toda', 'Repete todo dia', 'Repetir até', 'Status', 'Quanto custa? (R$)', 'Progresso'];
      t('[desktop] Campos da ação na ordem pedida',
        JSON.stringify(ordem) === JSON.stringify(esperada), JSON.stringify(ordem));

      await page.selectOption('#campo-recorrencia', 'SEMANAL');
      await new Promise((r) => setTimeout(r, 200));
      const linhas = await page.evaluate(() =>
        [...document.querySelectorAll('#modal-campos .grade-campos')].map((g) =>
          [...g.querySelectorAll('.mb-3')].filter((b) => !b.classList.contains('d-none'))
            .map((b) => ({ rotulo: b.querySelector('.form-label')?.textContent.trim(),
              topo: Math.round(b.getBoundingClientRect().top) }))));
      const mesmaLinha = (l) => l.length > 1 && l.every((c) => c.topo === l[0].topo);
      t('[desktop] Repetição, Repete toda e Repetir até na MESMA linha',
        !!linhas[0] && linhas[0].length === 3 && mesmaLinha(linhas[0]), JSON.stringify(linhas[0]));
      t('[desktop] Status e Quanto custa na MESMA linha',
        !!linhas[1] && linhas[1].length === 2 && mesmaLinha(linhas[1]), JSON.stringify(linhas[1]));
    }
    await page.keyboard.press('Escape');
  }
  await page.evaluate((id) => App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 }), prj);
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
  await provasAtalhoCadastros(page, 'desktop');
  await provasCiclo(page, 'desktop');
  await provasGut(page);
  await provasAcao(page);

  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', isMobile: true, hasTouch: true,
  });
  const pageM = await entrar(ctxM, 'celular', erros);
  await percorrer(pageM, 'celular');
  await provasAtalhoCadastros(pageM, 'celular');
  await provasCiclo(pageM, 'celular');

  await browser.close();
  process.exit(relatar(ok, bad, erros));
})().catch((e) => { console.error('ERRO:', e.message); process.exit(2); });

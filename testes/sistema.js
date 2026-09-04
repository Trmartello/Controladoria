// Bateria de sistema: percorre as 17 seções em desktop e celular e afirma que
// cada uma PINTA de verdade — não só que a casca do shell existe. Registra todo
// erro de página e de console: um `pageerror` numa seção é falha, mesmo que a
// tela pareça certa.
//
//   node testes/sistema.js
const { BASE, chromiumExec, playwright, esperar, entrar, relatar } = require('./comum');

const ok = [], bad = [], erros = [];
const t = (nome, cond, extra = '') => (cond ? ok : bad).push(nome + (extra ? ` — ${extra}` : ''));

/**
 * O ano da análise abre no primeiro ano PLANEJADO do ciclo (2027; ver
 * `Diag.ano`), mas a carga do deploy — PESTEL, Porter, SWOT, cenário — está
 * gravada no ano-base (2026). As provas que precisam de CONTEÚDO na tela (a
 * pesquisa, a GUT com fila, o dossiê) apontam para o ano da carga e devolvem o
 * padrão ao terminar; as que criam a própria massa usam `Diag.ano()`.
 */
const noAnoDaCarga = (page) => page.evaluate(() => {
  Diag.anoSelecionado = Number(Diag.cicloAtual().ano_base);
});
const noAnoPadrao = (page) => page.evaluate(() => { Diag.anoSelecionado = null; });

/**
 * Fecha o modal e só volta quando ele saiu MESMO da tela.
 *
 * Duas armadilhas, as duas já pagas nesta bateria:
 *
 * `Escape` depende de quem está com o foco, e formulário tem campo que engole a
 * tecla (o combobox do "Quem?" fecha o próprio painel e para a propagação ali).
 *
 * E o `hide()` do Bootstrap é um NO-OP enquanto a abertura ainda transiciona —
 * ele desiste calado se `_isTransitioning`. Pedir uma vez só deixava o modal de
 * pé; por isso o laço insiste até a classe `show` sair. Modal esquecido aberto
 * é invisível para a prova que o abriu e FATAL para a seguinte: o backdrop
 * intercepta o ponteiro, e o vermelho que aparece é um `hover` que não completa
 * numa tela que nem é a do defeito.
 */
async function fecharModal(page) {
  const ate = Date.now() + 8000;
  let quieto = 0;
  while (Date.now() < ate) {
    const aberto = await page.evaluate(() => {
      if (!document.getElementById('modal-form').classList.contains('show')) return false;
      Modal.bsModal?.hide();
      return true;
    });
    // Fechado precisa PERMANECER fechado por alguns ciclos. Aceitar o primeiro
    // "não está aberto" era a terceira armadilha: quando a espera pelo campo
    // estourava, o modal ainda não tinha SUBIDO, o fechamento respondia "já
    // está fechado" — e ele aparecia logo depois, para a prova seguinte.
    quieto = aberto ? 0 : quieto + 1;
    if (quieto >= 3) return true;
    await new Promise((r) => setTimeout(r, 150));
  }
  return false;
}

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
  { id: 'dossie', nome: 'Dossiê do plano', prova: '#secao-dossie [data-dossie-montar], #secao-dossie .alert' },
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
/**
 * Ao logar, o ano da análise é o PRIMEIRO ANO PLANEJADO do ciclo (ano_inicio,
 * 2027), e não o ano do relógio — pedido do cliente em 2026-09-03. O
 * `anoSelecionado` é zerado à mão porque o percurso anterior pode tê-lo
 * fixado (o dossiê e o quiz escrevem nele); zerado, ele reproduz o estado de
 * quem acabou de entrar.
 */
async function provasAnoPadrao(page, largura) {
  const r = await page.evaluate(() => {
    Diag.anoSelecionado = null;
    const c = Diag.cicloAtual();
    return { ano: Diag.ano(), inicio: Number(c?.ano_inicio), base: Number(c?.ano_base) };
  });
  t(`[${largura}] sem escolha, o ano da análise é o ano_inicio do ciclo`,
    r.ano === r.inicio, JSON.stringify(r));
  t(`[${largura}] o ano padrão é 2027, não o ano-base ${r.base}`, r.ano === 2027 && r.ano !== r.base);

  // E o seletor da tela mostra o mesmo ano: repintar o Cenário a partir de
  // outra seção, porque a seção não é destruída ao navegar.
  await page.evaluate(() => App.mostrarSecao('painel'));
  await page.evaluate(() => App.mostrarSecao('cenario'));
  const mostra = await esperar(page,
    () => Number(document.getElementById('sel-ano-cenario')?.value) === 2027, 15000);
  t(`[${largura}] o seletor da Análise de Cenário abre em 2027`, mostra);
  await page.evaluate(() => App.mostrarSecao('painel'));
}

/**
 * O painel da sala (as vozes que chegam pelo QR) fica À VISTA enquanto a
 * análise rola: ele mora dentro do cabeçalho fixo, e o cabeçalho das colunas
 * gruda ABAIXO dele. Relato do cliente (2026-09-03): na Análise de Cenário o
 * cabeçalho grudava e o painel sumia com a rolagem — quem conduzia a reunião
 * não via mais o que a sala respondia.
 *
 * A prova abre a pergunta do cenário para a sala pelo 🎤, rola a página até o
 * fim e mede: o painel inteiro dentro da janela, abaixo da topbar; a coluna
 * grudada abaixo do painel, e não por cima. No computador as colunas rolam
 * por dentro e a página pode nem rolar — a medida vale nos dois casos.
 */
async function provasPainelSalaFixo(page, largura) {
  const l = `[${largura}] Painel da sala:`;
  await page.evaluate(() => App.mostrarSecao('cenario'));
  await esperar(page, "!!document.querySelector('#secao-cenario [data-mic]')", 15000);
  // O 🎤 e o "Fechar" podem pedir confirmação (a sala em outro rito): aceitar
  // é o gesto do condutor. Ligado e desligado aqui, para não aceitar o
  // diálogo de uma prova seguinte.
  const aceitar = async (d) => { await d.accept(); };
  page.on('dialog', aceitar);
  try {
    await page.click('#secao-cenario [data-mic]');
    const aberta = await esperar(page,
      "!!document.querySelector('#secao-cenario .painel-quiz-vivo [data-mic-fechar]')", 15000);
    t(`${l} o 🎤 abre a pergunta do cenário para a sala`, aberta);
    if (!aberta) return;

    const m = await page.evaluate(async () => {
      window.scrollTo(0, document.documentElement.scrollHeight);
      await new Promise((r) => setTimeout(r, 350));
      const topo = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topo-app'), 10);
      const caixa = (s) => {
        const r = document.querySelector(s)?.getBoundingClientRect();
        return r ? { topo: Math.round(r.top), fundo: Math.round(r.bottom) } : null;
      };
      const cabecalho = caixa('#secao-cenario .canvas-analise > thead');
      // As DUAS colunas: no celular a página mostra uma categoria por vez e a
      // que já passou levou o cabeçalho junto (fica acima da janela). O que
      // interessa é o cabeçalho que está À VISTA — ele é que não pode estar
      // pintado por cima do painel.
      const colunas = [...document.querySelectorAll('#secao-cenario .cabecalho-coluna')]
        .map((c) => c.getBoundingClientRect())
        .filter((r) => r.height > 0 && r.bottom > 0 && r.top < window.innerHeight)
        .map((r) => ({ topo: Math.round(r.top), fundo: Math.round(r.bottom) }));
      return {
        rolou: Math.round(window.scrollY),
        alto: window.innerHeight,
        topo,
        painel: caixa('#secao-cenario .painel-quiz-vivo'),
        cabecalho,
        colunas,
        coluna: colunas.length ? colunas.reduce((a, b) => (a.topo <= b.topo ? a : b)) : null,
        noCabecalho: !!document.querySelector('#secao-cenario .canvas-analise > thead .painel-quiz-vivo'),
      };
    });
    t(`${l} o painel mora dentro do cabeçalho fixo`, m.noCabecalho);
    // No celular as colunas não rolam por dentro: a página tem de ter rolado
    // de verdade, senão a medida abaixo é de uma tela parada. Corre no ano da
    // carga (ver `noAnoDaCarga`) justamente para haver cartões que empurrem.
    if (largura === 'celular') t(`${l} a página rolou de verdade`, m.rolou > 0, JSON.stringify(m));
    t(`${l} com a página rolada, o painel continua inteiro à vista, abaixo da topbar`,
      !!m.painel && m.painel.topo >= m.topo && m.painel.fundo <= m.alto, JSON.stringify(m));
    t(`${l} o cabeçalho da coluna gruda ABAIXO do painel, não por cima`,
      !!m.coluna && !!m.cabecalho && m.coluna.topo >= m.cabecalho.fundo - 2, JSON.stringify(m));

    await page.click('#secao-cenario .painel-quiz-vivo [data-mic-fechar]');
    t(`${l} "Fechar para a sala" encerra a pergunta`, await esperar(page,
      "!document.querySelector('#secao-cenario .painel-quiz-vivo [data-mic-fechar]')", 15000));
  } finally {
    page.off('dialog', aceitar);
    await page.evaluate(() => window.scrollTo(0, 0));
  }
}

/**
 * Excluir uma ação que NASCEU de uma origem pergunta o que fazer com ela:
 * devolver à fila de "aguardando plano de ação" ou tirar de vez. E a própria
 * fila ganha o × que tira a pendência sem passar por ação nenhuma. Pedido do
 * cliente (2026-09-03).
 *
 * O que a prova guarda: a listagem conta as origens (é o número que decide
 * se há diálogo); o diálogo é um modal do sistema com as duas saídas e
 * "devolver" marcado por padrão; "tirar de vez" apaga a ação, deixa o fator
 * na SWOT e o mantém FORA da fila; o × da fila faz o mesmo com uma pendência
 * que nunca virou ação.
 */
async function provasExcluirComOrigens(page) {
  const l = '[desktop] Excluir com origens:';
  const m = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const ano = Diag.ano();
    const novo = (categoria, descricao) => App.api('/api/fatores',
      { planejamento_id: plan.id, etapa: 'SWOT', categoria, descricao, ano });
    const f1 = await novo('FRAQUEZA', 'Fraqueza que vira acao (prova origens)');
    await App.api(`/api/fatores/${f1.id}/plano-acao`, { planejamento_id: plan.id });
    const prj = await App.api('/api/projetos',
      { planejamento_id: plan.id, titulo: 'Projeto de prova (origens)', ano: 2027, responsavel: 'QA' });
    const acao = await App.api('/api/desdobramentos', {
      planejamento_id: plan.id, projeto_id: prj.id, iniciativa_nova: 'Frente de prova (origens)',
      o_que: 'Acao nascida da fraqueza', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31', fator_id: f1.id,
    });
    const f2 = await novo('AMEACA', 'Ameaca que sai da fila (prova origens)');
    await App.api(`/api/fatores/${f2.id}/plano-acao`, { planejamento_id: plan.id });
    return { plan: plan.id, ano, f1: f1.id, f2: f2.id, prj: prj.id, acao: acao.id };
  });
  try {
    await page.evaluate(() => App.mostrarSecao('painel'));
    await page.evaluate(() => App.mostrarSecao('projetos'));
    await esperar(page, `!!document.querySelector('[data-excluir-desd="${m.acao}"]')`, 15000);
    const origens = await page.evaluate((id) => (SecaoProjetos.projetos || [])
      .flatMap((p) => p.desdobramentos || []).find((a) => Number(a.id) === id)?.origens, m.acao);
    t(`${l} a listagem diz que a ação tem uma origem`, Number(origens) === 1, String(origens));

    // Pelo DOM, com `.click()`: o botão mora atrás da seta de detalhes e o
    // Playwright esperaria para sempre por um botão visível.
    await page.evaluate((id) => document.querySelector(`[data-excluir-desd="${id}"]`).click(), m.acao);
    const modal = await esperar(page, "!!document.querySelector('#modal-form.show #campo-origens')", 10000);
    t(`${l} excluir a ação abre o diálogo com as duas saídas (modal, não confirm)`, modal);
    if (modal) {
      await new Promise((r) => setTimeout(r, 300));
      const estado = await page.evaluate(() => ({
        opcoes: [...document.querySelectorAll('#campo-origens input')].map((i) => i.value),
        marcada: Modal.coletar().origens,
        botao: document.getElementById('modal-salvar')?.textContent.trim(),
      }));
      t(`${l} devolver e tirar, com devolver marcado por padrão e o botão dizendo Excluir`,
        JSON.stringify(estado.opcoes) === '["devolver","tirar"]' && estado.marcada === 'devolver'
        && estado.botao === 'Excluir', JSON.stringify(estado));
      await page.click('label[for="campo-origens-tirar"]');
      await page.click('#modal-salvar');
      await esperar(page, "!document.querySelector('#modal-form.show')", 10000);
    }
    const depois = await page.evaluate(async (x) => {
      const fatores = await App.api(`/api/fatores?planejamento_id=${x.plan}&etapa=SWOT&ano=${x.ano}`);
      const f = fatores.find((y) => Number(y.id) === x.f1);
      const projetos = await App.api(`/api/projetos?planejamento_id=${x.plan}`);
      return {
        fatorExiste: !!f, acaoEm: f?.acao_em ?? null, desd: f?.desdobramento_id ?? null,
        acaoExiste: projetos.flatMap((p) => p.desdobramentos || []).some((a) => Number(a.id) === x.acao),
        naFila: !!document.querySelector(`[data-ideia-acao="f${x.f1}"]`),
      };
    }, m);
    t(`${l} "tirar de vez": a ação saiu, o fator continua na SWOT e fora da fila`,
      depois.fatorExiste && !depois.acaoExiste && !depois.acaoEm && !depois.desd && !depois.naFila,
      JSON.stringify(depois));

    const temX = await esperar(page, `!!document.querySelector('[data-tirar-fila="f${m.f2}"]')`, 15000);
    t(`${l} a pendência da fila tem o × de tirar de vez`, temX);
    if (temX) {
      page.once('dialog', async (d) => { await d.accept(); });
      await page.click(`[data-tirar-fila="f${m.f2}"]`);
      const saiu = await esperar(page, `!document.querySelector('[data-ideia-acao="f${m.f2}"]')`, 15000);
      const f2 = await page.evaluate(async (x) => (await App.api(
        `/api/fatores?planejamento_id=${x.plan}&etapa=SWOT&ano=${x.ano}`)).find((y) => Number(y.id) === x.f2), m);
      t(`${l} o × tira a pendência da fila e o fator fica na análise`,
        saiu && !!f2 && !f2.acao_em, JSON.stringify({ saiu, acao_em: f2?.acao_em ?? null }));
    }
  } finally {
    await page.evaluate(async (x) => {
      await App.api(`/api/projetos/${x.prj}/excluir`, { planejamento_id: x.plan }).catch(() => {});
      for (const id of [x.f1, x.f2]) {
        await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: x.plan }).catch(() => {});
      }
    }, m);
  }
}

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
 * O cartão do cruzamento: UM "ver mais" para o cartão inteiro.
 *
 * O cartão tem três caixas cortadas (os dois fatores do par e a estratégia) e
 * um rodapé só. Com o "ver mais" genérico do diagnóstico, cada uma ganharia o
 * próprio botão — três empilhados no mesmo lugar, nenhum dizendo a qual texto
 * pertence. Estas provas guardam o botão único, o estado compartilhado e o
 * rodapé em UMA linha (expandir à esquerda, agir à direita).
 *
 * A massa é criada e apagada aqui: a instância de teste não tem cruzamento
 * nenhum garantido, e um teste que só roda "se houver dado" não prova nada.
 */
async function provasCartaoCruzamento(page) {
  const ids = await page.evaluate(async () => {
    const cria = async (cat, desc) => (await App.api('/api/fatores',
      { planejamento_id: 1, etapa: 'SWOT', categoria: cat, descricao: desc, ano: Diag.ano() })).id;
    // Textos longos de propósito: o botão só aparece no que foi MESMO cortado.
    const fi = await cria('FORCA', 'Força de teste com um texto deliberadamente longo para '
      + 'não caber em uma linha só dentro do selo do par, forçando o corte por line-clamp');
    const fe = await cria('OPORTUNIDADE', 'Oportunidade de teste, também longa o bastante '
      + 'para ser cortada em uma linha e precisar do ver mais para ser lida inteira');
    const c = await App.api('/api/cruzamentos', {
      planejamento_id: 1, fator_interno_id: fi, fator_externo_id: fe,
      rotulo: 'Par de teste do cartão',
      estrategia: 'Estratégia de teste, escrita com folga suficiente para passar das três '
        + 'linhas que o cartão mostra e exercitar o corte do texto da estratégia junto com '
        + 'os dois selos do par, que é justamente o que o botão único precisa expandir.',
    });
    return { fi, fe, c: c.id };
  });

  await page.evaluate(() => App.mostrarSecao('cruzamentos'));
  const pintou = await esperar(page, `!!document.querySelector('[data-card-cruzamento="${ids.c}"] .ver-mais')`);
  t('[desktop] cartão do cruzamento ganha o "ver mais"', pintou);

  if (pintou) {
    const sel = `[data-card-cruzamento="${ids.c}"]`;
    const medir = () => page.evaluate((s) => {
      const card = document.querySelector(s);
      const btn = card.querySelector('.ver-mais');
      const acoes = card.querySelector('.botoes-fator .ms-auto');
      return {
        botoes: card.querySelectorAll('.ver-mais').length,
        rotulo: card.querySelector('.ver-mais-texto').textContent.trim(),
        aria: btn.getAttribute('aria-expanded'),
        expandidas: card.querySelectorAll('.expandido').length,
        caixas: card.querySelectorAll('.selo-cruz-texto, .texto-fator').length,
        altura: Math.round(card.getBoundingClientRect().height),
        // Mesma linha: os dois blocos do rodapé começam na mesma altura.
        mesmaLinha: Math.abs(Math.round(btn.getBoundingClientRect().top)
          - Math.round(acoes.getBoundingClientRect().top)) <= 4,
        // E o de expandir vem ANTES das ações, na ordem da tela.
        esquerda: btn.getBoundingClientRect().left < acoes.getBoundingClientRect().left,
        rolagemH: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    }, sel);

    const fechado = await medir();
    t('[desktop] um único "ver mais" no cartão', fechado.botoes === 1, `${fechado.botoes}`);
    t('[desktop] as três caixas começam cortadas', fechado.expandidas === 0 && fechado.caixas === 3,
      JSON.stringify(fechado));
    t('[desktop] rodapé: expandir à esquerda e ações à direita, na mesma linha',
      fechado.mesmaLinha && fechado.esquerda, JSON.stringify(fechado));

    await page.click(`${sel} .ver-mais`);
    const aberto = await medir();
    // O estado é UM só: um clique abre as três caixas, não uma.
    t('[desktop] um clique expande as três caixas juntas', aberto.expandidas === 3,
      JSON.stringify(aberto));
    t('[desktop] o botão vira "ver menos"', aberto.rotulo === 'ver menos' && aberto.aria === 'true',
      JSON.stringify(aberto));
    t('[desktop] e o cartão cresce', aberto.altura > fechado.altura,
      `${fechado.altura} → ${aberto.altura}`);
    // O selo do par corta por line-clamp, nunca por nowrap: expandido, a largura
    // mínima dele não pode subir pela coluna até o <main> e rolar a página.
    t('[desktop] expandido não rola na horizontal', !aberto.rolagemH);

    await page.click(`${sel} .ver-mais`);
    const refechado = await medir();
    t('[desktop] "ver menos" recolhe as três de volta',
      refechado.expandidas === 0 && refechado.rotulo === 'ver mais', JSON.stringify(refechado));
  }

  await page.evaluate(async (ids) => {
    await App.api(`/api/cruzamentos/${ids.c}/excluir`, { planejamento_id: 1 });
    await App.api(`/api/fatores/${ids.fi}/excluir`, { planejamento_id: 1 });
    await App.api(`/api/fatores/${ids.fe}/excluir`, { planejamento_id: 1 });
  }, ids);
}

/**
 * O cartão de ação, em cinco linhas: situação + progresso + seta, o quê, como,
 * metadados e o rodapé de botões.
 *
 * O que estas provas guardam é a HIERARQUIA — a ordem das linhas e o que divide
 * linha com o quê. É o tipo de coisa que uma edição distraída desfaz sem
 * quebrar nada: o cartão continua pintando, só que ilegível.
 */
async function provasCartaoAcao(page, largura) {
  const ids = await page.evaluate(async () => {
    const pr = await App.api('/api/projetos',
      { planejamento_id: 1, titulo: 'Projeto do cartão de ação', ano: 2027, responsavel: 'QA', descricao: 'x' });
    const ini = await App.api('/api/iniciativas',
      { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente do cartão' });
    const ac = await App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: pr.id, iniciativa_id: ini.id,
      o_que: 'Ação de teste do cartão', como: 'Do jeito descrito aqui', quem: 'Fulana de Tal',
      quem_usuario_id: 1, prioridade: 'MEDIA', status: 'EM_ANDAMENTO', progresso: 86,
      recorrencia: 'NENHUMA', data_inicio: '2027-01-01', data_fim: '2027-12-31',
    });
    // Uma rotina de DOIS dias, com ganhos previstos: é no cartão que a grade
    // volta a virar frase, e é a frase que o condutor lê na reunião.
    const rot = await App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: pr.id, iniciativa_id: ini.id,
      o_que: 'Rotina do cartão', como: 'x', quem: 'Fulana de Tal', quem_usuario_id: 1,
      prioridade: 'MEDIA', status: 'NAO_INICIADO', progresso: 0,
      recorrencia: 'SEMANAL', recorrencia_dias: [3, 6], recorrencia_ate: '2027-12-31',
      quanto: 1500.5,
    });
    return { pr: pr.id, ac: ac.id, rot: rot.id };
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  const sel = `[data-card-acao="${ids.ac}"]`;
  const pintou = await esperar(page, `!!document.querySelector('${sel} .btn-mais')`);
  t(`[${largura}] cartão de ação pinta`, pintou);

  if (pintou) {
    // A seta começa recolhida e o detalhe está escondido.
    t(`[${largura}] a seta começa recolhida`,
      await page.evaluate((s) => document.querySelector(`${s} .btn-mais`).getAttribute('aria-expanded') === 'false', sel));
    await page.click(`${sel} .btn-mais`);

    const m = await page.evaluate((s) => {
      const c = document.querySelector(s);
      const meio = (el) => { const r = el.getBoundingClientRect(); return Math.round(r.top + r.height / 2); };
      const topo = c.querySelector('.linha-acao-topo');
      const pecas = [...topo.children];
      const titulo = c.querySelector('.fw-bold');
      const detalhe = c.querySelector('.detalhe-item');
      const linhas = [...detalhe.children];
      const rodape = detalhe.querySelector('.justify-content-between');
      const com = rodape.querySelector('[data-comentarios]');
      const acoes = rodape.querySelector('.ms-auto');
      return {
        // `align-items: center` alinha os CENTROS, não os topos: peças de
        // alturas diferentes na mesma linha têm `top` diferente e centro igual.
        centrosLinha1: [...new Set(pecas.map(meio))].length,
        pecasLinha1: pecas.length,
        temBarra: !!topo.querySelector('.faixa-verde, .faixa-progresso'),
        temSeta: !!topo.querySelector('.btn-mais'),
        setaAberta: c.querySelector('.btn-mais').getAttribute('aria-expanded'),
        // A ordem das linhas, de cima para baixo.
        tituloAbaixoDoTopo: titulo.getBoundingClientRect().top > topo.getBoundingClientRect().bottom - 1,
        detalheAbaixoDoTitulo: detalhe.getBoundingClientRect().top > titulo.getBoundingClientRect().bottom - 1,
        primeiraDoDetalhe: (linhas[0]?.textContent || '').trim().slice(0, 5),
        // Prazo → Quem → Prioridade, nessa ordem.
        ordemMeta: (detalhe.querySelector('.text-muted')?.textContent || '')
          .replace(/\s+/g, ' ').match(/Prazo:.*Quem:.*Prioridade:/) !== null,
        rodapeMesmaLinha: Math.abs(meio(com) - meio(acoes)) <= 4,
        comentariosEsquerda: com.getBoundingClientRect().left < acoes.getBoundingClientRect().left,
        rolagemH: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    }, sel);

    t(`[${largura}] linha 1: situação, barra e seta alinhadas`,
      m.centrosLinha1 === 1 && m.temBarra && m.temSeta, JSON.stringify(m));
    t(`[${largura}] a seta abre o detalhe`, m.setaAberta === 'true');
    t(`[${largura}] linha 2 é o "o quê", abaixo da linha 1`, m.tituloAbaixoDoTopo);
    t(`[${largura}] linha 3 é o "Como"`, m.primeiraDoDetalhe === 'Como:' && m.detalheAbaixoDoTitulo,
      m.primeiraDoDetalhe);
    t(`[${largura}] linha 4 traz Prazo, Quem e Prioridade nessa ordem`, m.ordemMeta);
    t(`[${largura}] linha 5: Comentários à esquerda, ✎ e × à direita, na mesma linha`,
      m.rodapeMesmaLinha && m.comentariosEsquerda, JSON.stringify(m));
    t(`[${largura}] o cartão aberto não rola na horizontal`, !m.rolagemH);

    // A grade volta a virar FRASE, e o dinheiro volta a ter dois centavos.
    // `toLocaleString` sozinho corta o zero à direita: R$ 1.500,50 saía como
    // "R$ 1.500,5", que parece valor truncado.
    const rot = await page.evaluate((id) => {
      const c = document.querySelector(`[data-card-acao="${id}"]`);
      c.querySelector('.btn-mais').click();
      return c.innerText.replace(/\s+/g, ' ');
    }, ids.rot);
    t(`[${largura}] a rotina de dois dias vira frase no cartão`,
      rot.includes('Repete: toda quarta-feira e sábado'), rot.slice(0, 180));
    t(`[${largura}] os ganhos previstos aparecem com os dois centavos`,
      rot.includes('Ganhos previstos: R$ 1.500,50'), rot.slice(0, 220));
  }

  await page.evaluate(async (ids) => {
    await App.api(`/api/projetos/${ids.pr}/excluir`, { planejamento_id: 1 });
  }, ids);
}

/**
 * O resumo por situação no cabeçalho do projeto e no de cada frente.
 *
 * O que se guarda aqui é a BASE do percentual: no projeto ele é sobre todas as
 * ações (as frentes somadas) e na frente é sobre as dela. Trocar a base é o
 * tipo de defeito que ninguém vê — os dois selos continuam existindo, com
 * números plausíveis, dizendo coisas diferentes do que dizem.
 */
async function provasResumoStatus(page, largura) {
  const ids = await page.evaluate(async () => {
    const pr = await App.api('/api/projetos',
      { planejamento_id: 1, titulo: 'Projeto do resumo', ano: 2027, responsavel: 'QA', descricao: '' });
    const i1 = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente A' });
    const i2 = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente B' });
    // ATRASADO e NAO_INICIADO são AUTOMÁTICOS: quem decide é a data-limite,
    // reconciliada na leitura. Um "ATRASADO" com prazo futuro volta como "No
    // prazo" — a massa atrasada precisa de prazo VENCIDO, senão a prova mede
    // outra coisa e passa por acaso.
    const mk = (ini, o_que, status, fim) => App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: pr.id, iniciativa_id: ini, o_que, como: 'x', quem: 'QA',
      quem_usuario_id: 1, prioridade: 'MEDIA', status, progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2026-01-01', data_fim: fim,
    });
    await mk(i1.id, 'atrasada 1', 'NAO_INICIADO', '2026-06-01');
    await mk(i1.id, 'atrasada 2', 'NAO_INICIADO', '2026-06-01');
    await mk(i1.id, 'no prazo 1', 'NAO_INICIADO', '2027-12-31');
    await mk(i2.id, 'no prazo 2', 'NAO_INICIADO', '2027-12-31');
    await mk(i2.id, 'andamento', 'EM_ANDAMENTO', '2027-12-31');
    await mk(i2.id, 'concluida', 'CONCLUIDO', '2027-12-31');
    return { pr: pr.id };
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  const sel = `[data-projeto="${ids.pr}"]`;
  const pintou = await esperar(page, `!!document.querySelector('${sel} .selo-resumo')`);
  t(`[${largura}] o cabeçalho do projeto traz o resumo por situação`, pintou);

  if (pintou) {
    const m = await page.evaluate((s) => {
      const c = document.querySelector(s);
      const textos = (raiz) => [...raiz.querySelectorAll('.selo-resumo')].map((x) => x.textContent.trim());
      const cabeca = c.querySelector('.projeto-cabeca');
      const frentes = [...c.querySelectorAll('.iniciativa-cabeca')].map((h) => ({
        nome: h.querySelector('strong').textContent.trim(),
        selos: textos(h),
        badges: [...h.querySelectorAll('.badge')].map((x) => x.textContent.trim()),
      }));
      const titulo = cabeca.querySelector('strong').getBoundingClientRect();
      const primeiro = cabeca.querySelector('.selo-resumo').getBoundingClientRect();
      return {
        projeto: textos(cabeca),
        frentes,
        selosNoCabecalho: cabeca.querySelectorAll('.badge, .selo-resumo').length,
        // "ao lado do título" = mesma linha, e depois dele. A medida é a
        // SOBREPOSIÇÃO vertical dos dois retângulos, não a distância entre os
        // centros: o cabeçalho do projeto alinha por linha de base, e peças de
        // tamanhos diferentes na mesma linha têm centros diferentes — a
        // primeira versão desta prova reprovava o celular por isso.
        naLinhaDoTitulo: Math.min(titulo.bottom, primeiro.bottom) - Math.max(titulo.top, primeiro.top) > 0
          && primeiro.left > titulo.left,
        // No celular o cabeçalho é estreito e a fila QUEBRA para a linha
        // seguinte — comportamento correto, não defeito. O que importa ali é
        // outra coisa: o resumo continua dentro do cabeçalho, acima da barra de
        // progresso, e não desceu para o meio do conteúdo do projeto.
        dentroDoCabecalho: !!c.querySelector('.projeto-cabeca .selo-resumo'),
        acimaDaBarra: primeiro.bottom
          <= c.querySelector('.panorama-projeto').getBoundingClientRect().top + 1,
        rolagemH: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    }, sel);

    // No projeto vai SÓ o atraso, e o percentual é sobre o TOTAL criado: 2 de
    // 6 ações = 33%. O denominador não é "as atrasadas" nem "as abertas".
    t(`[${largura}] o projeto aponta só o atraso, sobre o total de ações`,
      m.projeto.join(' | ') === 'Atrasada: 2 (33%)', JSON.stringify(m.projeto));
    // UM selo, não dois: o de situação do projeto saiu do cabeçalho porque
    // dizia "Atrasado" ao lado de "Atrasada: 2 (33%)" — a mesma notícia duas
    // vezes, uma delas sem o tamanho.
    t(`[${largura}] o cabeçalho do projeto tem UM selo só`, m.selosNoCabecalho === 1,
      `${m.selosNoCabecalho}`);
    // A frente mostra só o atraso, e o percentual é sobre as ações DELA:
    // Frente A tem 3, 2 atrasadas = 67% (no projeto as mesmas 2 dão 33%).
    const a = m.frentes.find((f) => f.nome === 'Frente A');
    t(`[${largura}] o percentual da frente é sobre as ações DELA`,
      a && a.selos.join(' | ') === 'Atrasada: 2 (67%)', JSON.stringify(a));
    // Frente B não tem atraso nenhum: nenhum selo, e nem por isso ela some.
    const bb = m.frentes.find((f) => f.nome === 'Frente B');
    t(`[${largura}] frente sem atraso não mostra selo`, bb && bb.selos.length === 0, JSON.stringify(bb));
    // A palavra "Aberta" saiu do cabeçalho: a seta ao lado do nome já diz se a
    // frente está aberta ou recolhida.
    t(`[${largura}] o cabeçalho da frente não tem mais o selo de situação`,
      m.frentes.every((f) => f.badges.length === 0), JSON.stringify(m.frentes.map((f) => f.badges)));
    // Situação sem nenhuma ação não vira selo com zero: numa fila de sete, seis
    // zerados, o que importa se perde no meio.
    t(`[${largura}] situação sem ação nenhuma não aparece`,
      !m.frentes.some((f) => f.selos.some((x) => / 0 \(/.test(x))), JSON.stringify(m.frentes));
    // O balão da frente é onde a distribuição inteira e a situação dela vivem
    // agora — o cabeçalho ficou com uma informação só.
    t(`[${largura}] a frente ainda tem popover com o resto`,
      await page.evaluate((s2) => !!document.querySelector(`${s2} .iniciativa-cabeca [data-popover-resumo]`), sel));
    if (largura === 'desktop') {
      t(`[${largura}] o resumo fica ao lado do título`, m.naLinhaDoTitulo);
    } else {
      t(`[${largura}] o resumo fica no cabeçalho, acima da barra`,
        m.dentroDoCabecalho && m.acimaDaBarra, JSON.stringify(m.naLinhaDoTitulo));
    }
    t(`[${largura}] o resumo não rola a página na horizontal`, !m.rolagemH);
  }

  // Projeto SEM atraso não ganha selo nenhum no cabeçalho: a ausência é a boa
  // notícia. Sem esta prova, um "Atrasada: 0 (0%)" em toda linha passaria — e é
  // justamente o que treina o olho a pular o selo quando ele deixa de ser zero.
  const limpo = await page.evaluate(async () => {
    const pr = await App.api('/api/projetos',
      { planejamento_id: 1, titulo: 'Projeto em dia', ano: 2027, responsavel: 'QA', descricao: '' });
    const ini = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente C' });
    await App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: pr.id, iniciativa_id: ini.id, o_que: 'no prazo', como: 'x',
      quem: 'QA', quem_usuario_id: 1, prioridade: 'MEDIA', status: 'NAO_INICIADO', progresso: 0,
      recorrencia: 'NENHUMA', data_inicio: '2027-01-01', data_fim: '2027-12-31',
    });
    return pr.id;
  });
  await page.evaluate(() => App.recarregarSecaoAtiva());
  await esperar(page, `!!document.querySelector('[data-projeto="${limpo}"] .projeto-cabeca')`);
  t(`[${largura}] projeto sem atraso não mostra selo nenhum`,
    await page.evaluate((id) =>
      !document.querySelector(`[data-projeto="${id}"] .projeto-cabeca .selo-resumo`), limpo));
  // A frente dele também não tem selo (nada atrasado ali), mas continua com o
  // POPOVER: é ele que carrega a distribuição desde que o cabeçalho passou a
  // mostrar só o atraso.
  t(`[${largura}] a frente sem atraso também fica sem selo`,
    await page.evaluate((id) =>
      !document.querySelector(`[data-projeto="${id}"] .iniciativa-cabeca .selo-resumo`), limpo));
  t(`[${largura}] mas ela mantém o popover com o resto`,
    await page.evaluate((id) =>
      !!document.querySelector(`[data-projeto="${id}"] .iniciativa-cabeca [data-popover-resumo]`), limpo));

  // Um argumento só: `page.evaluate` não aceita dois (a mensagem de erro é
  // clara, mas só aparece quando a prova já rodou inteira).
  await page.evaluate(async (alvos) => {
    for (const id of alvos) await App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 });
  }, [ids.pr, limpo]);
}

/**
 * O popover de resumo nos títulos — do projeto e da frente.
 *
 * Duas coisas guardadas aqui. A primeira é o ALINHAMENTO em coluna: nome à
 * esquerda, contagem à direita, o mesmo x em todas as linhas — é o que faz os
 * números serem comparáveis de relance, e é o que se perde numa edição
 * distraída sem quebrar nada.
 *
 * A segunda é o `dispose`. O balão do Bootstrap mora no `<body>`, fora da
 * seção: sem descartar as instâncias a cada pintura, uma tarde de uso deixa
 * dezenas deles empilhados — e o defeito não aparece na tela, só na memória.
 */
async function provasPopoverResumo(page, largura) {
  const ids = await page.evaluate(async () => {
    const pr = await App.api('/api/projetos',
      { planejamento_id: 1, titulo: 'Projeto do popover', ano: 2027, responsavel: 'QA', descricao: '' });
    const ini = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente pop' });
    const mk = (o, st, fim) => App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: pr.id, iniciativa_id: ini.id, o_que: o, como: 'x', quem: 'QA',
      quem_usuario_id: 1, prioridade: 'MEDIA', status: st, progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2026-01-01', data_fim: fim,
    });
    await mk('atrasada', 'NAO_INICIADO', '2026-06-01');
    await mk('aguardando', 'AGUARDANDO_VALIDACAO', '2027-12-31');
    await mk('no prazo', 'NAO_INICIADO', '2027-12-31');
    return { pr: pr.id, ini: ini.id };
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  const alvoProj = `[data-popover-resumo="proj-${ids.pr}"]`;
  await esperar(page, `!!document.querySelector('${alvoProj}')`);
  // `scrollIntoViewIfNeeded` para o elemento no TOPO da janela — que agora é
  // onde mora o cabeçalho fixo. O alvo fica debaixo dele, o hover bate no
  // cabeçalho e a espera nunca termina. Subir a rolagem tira o alvo de baixo:
  // é a mesma coisa que acontece com quem clica num link âncora.
  const mostrar = async (sel) => {
    await page.locator(sel).scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -140));
  };
  await mostrar(alvoProj);
  await page.hover(alvoProj);
  const abriu = await esperar(page, "!!document.querySelector('.popover-resumo')");
  t(`[${largura}] o título do projeto abre o popover`, abriu);

  if (abriu) {
    const m = await page.evaluate(() => {
      const linhas = [...document.querySelectorAll('.popover-resumo .linha-resumo')];
      const r = (el) => el.getBoundingClientRect();
      return {
        conteudo: linhas.map((l) => `${l.querySelector('.nome-status').textContent.trim()}=${
          l.querySelector('.qtd-status').textContent.trim()}`),
        // Coluna: um x só para os nomes, um x só para os números.
        esquerdas: [...new Set(linhas.map((l) => Math.round(r(l.querySelector('.nome-status')).left)))].length,
        direitas: [...new Set(linhas.map((l) => Math.round(r(l.querySelector('.qtd-status')).right)))].length,
        // O ponto colorido prova que a CLASSE sobreviveu ao sanitizador do
        // Bootstrap — com `style` ela teria sido descartada e o ponto sairia
        // preto, sem ninguém notar.
        pontoPintado: getComputedStyle(linhas[0].querySelector('.ponto-resumo')).backgroundColor,
        total: !!document.querySelector('.popover-resumo .total-resumo'),
      };
    });
    t(`[${largura}] o popover do projeto lista todas as situações`,
      m.conteudo.join(' | ') === 'Atrasada=1 (33%) | Aguardando validação=1 (33%) | No prazo=1 (33%)',
      JSON.stringify(m.conteudo));
    t(`[${largura}] nome à esquerda e número à direita, em coluna`,
      m.esquerdas === 1 && m.direitas === 1, `esquerdas=${m.esquerdas} direitas=${m.direitas}`);
    t(`[${largura}] a cor da situação sobrevive ao sanitizador`,
      m.pontoPintado === 'rgb(179, 38, 30)', m.pontoPintado);
    t(`[${largura}] e o popover fecha com o total`, m.total);
  }

  // O mesmo balão na frente de trabalho. O do projeto precisa SAIR antes: ele
  // abre para baixo e cobre o título da frente — o clique/hover seguinte bate
  // no balão, não no título, e a espera nunca termina. Tirar o mouse não basta
  // (o ponteiro fica onde estava); quem fecha é o `hide()`.
  await page.evaluate(() => document.querySelectorAll('[data-popover-resumo]')
    .forEach((el) => bootstrap.Popover.getInstance(el)?.hide()));
  await esperar(page, "!document.querySelector('.popover')");
  // O ponteiro sai para o MEIO da tela, nunca para (0,0): encostar na borda
  // esquerda é o gesto que abre o menu lateral no computador, e ele cobriria
  // justamente o título da frente que vem a seguir.
  await page.mouse.move(700, 400);
  const alvoIni = `[data-popover-resumo="ini-${ids.ini}"]`;
  await esperar(page, `!!document.querySelector('${alvoIni}')`);
  await mostrar(alvoIni);
  await page.hover(alvoIni);
  t(`[${largura}] o título da frente abre o mesmo popover`,
    await esperar(page, "!!document.querySelector('.popover-resumo .linha-resumo')"));

  // Repinta três vezes e conta os balões pendurados no body.
  for (let i = 0; i < 3; i++) await page.evaluate(() => App.recarregarSecaoAtiva());
  await esperar(page, `!!document.querySelector('${alvoProj}')`);
  t(`[${largura}] repintar não empilha popovers no body`,
    await page.evaluate(() => document.querySelectorAll('body > .popover').length <= 1),
    await page.evaluate(() => `${document.querySelectorAll('body > .popover').length} pendurado(s)`));

  await page.evaluate(async (id) => {
    await App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 });
  }, ids.pr);
}

/**
 * O cabeçalho de Projetos grudado abaixo da topbar.
 *
 * Os três botões de nível (Ações · Frentes · Projetos) são o controle que se usa
 * LENDO a lista: sem o cabeçalho fixo, trocar de visão no quinto projeto obriga
 * a subir a página inteira. A prova rola de verdade e confere onde o bloco
 * parou — `position: sticky` é fácil de quebrar sem querer, porque basta um
 * `overflow` num ancestral para ele virar estático e ninguém percebe.
 */
async function provasCabecalhoProjetos(page, largura) {
  const ids = await page.evaluate(async () => {
    const feitos = [];
    for (const nome of ['Projeto rolagem A', 'Projeto rolagem B']) {
      const pr = await App.api('/api/projetos',
        { planejamento_id: 1, titulo: nome, ano: 2027, responsavel: 'QA', descricao: '' });
      const ini = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente' });
      const ini2 = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: pr.id, titulo: 'Frente 2' });
      for (const q of ['a', 'b', 'c', 'd', 'e', 'f']) {
        await App.api('/api/desdobramentos', {
          planejamento_id: 1, projeto_id: pr.id, iniciativa_id: q < 'd' ? ini.id : ini2.id, o_que: `${nome} ${q}`,
          como: 'x', quem: 'QA', quem_usuario_id: 1, prioridade: 'MEDIA',
          // Uma concluída por projeto: é o que a prova da pesquisa por situação
          // usa para separar o joio do trigo.
          status: q === 'f' ? 'CONCLUIDO' : 'NAO_INICIADO',
          progresso: 50, recorrencia: 'NENHUMA', data_inicio: '2026-01-01', data_fim: '2027-12-31',
        });
      }
      feitos.push(pr.id);
    }
    return feitos;
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  // Esperar por um seletor GENÉRICO não serve aqui: a prova anterior já deixou
  // Projetos pintada, e `mostrarSecao` recarrega de forma assíncrona — o
  // `.cabecalho-projetos` da pintura VELHA satisfazia a espera na hora, e a
  // prova media a lista sem os projetos que ela acabou de criar (o cartão do
  // primeiro nem existia: `null.querySelectorAll` derrubava a bateria inteira,
  // antes de imprimir uma linha sequer). A espera é pelo PRÓPRIO projeto.
  await esperar(page, `!!document.querySelector('[data-projeto="${ids[0]}"] .projeto-cabeca-fixa')`);
  await page.evaluate(() => window.scrollTo(0, 700));
  const m = await page.evaluate(() => {
    const cab = document.querySelector('.cabecalho-projetos');
    const r = cab.getBoundingClientRect();
    const barra = document.querySelector('.topbar').getBoundingClientRect();
    const cs = getComputedStyle(cab);
    return {
      rolou: Math.round(window.scrollY) > 0,
      // Grudado EXATAMENTE embaixo da topbar: a folga é zero.
      folga: Math.round(r.top - barra.bottom),
      // Os controles seguem clicáveis, não escondidos atrás da barra verde.
      controlesVisiveis: [...document.querySelectorAll('.cabecalho-projetos [data-nivel]')]
        .every((b) => b.getBoundingClientRect().top >= barra.bottom - 1),
      // Fundo sólido: transparente deixaria os cartões aparecerem através dele.
      opaco: !/rgba\(.*,\s*0\)/.test(cs.backgroundColor) && cs.backgroundColor !== 'transparent',
      z: Number(cs.zIndex),
    };
  });

  t(`[${largura}] a página rola o bastante para provar o cabeçalho fixo`, m.rolou);

  // A PILHA: topbar → Projetos → projeto → frente, cada um encostado no de
  // cima. É o degrau que quebra em silêncio quando alguém mexe numa altura: as
  // barras continuam grudando, só que sobrepostas, e o nome do projeto some
  // atrás do da frente.
  const pilha = await page.evaluate(() => {
    const r = (el) => el.getBoundingClientRect();
    const sec = document.querySelector('.cabecalho-projetos');
    const proj = [...document.querySelectorAll('.projeto-cabeca-fixa')]
      .find((e) => Math.abs(r(e).top - r(sec).bottom) <= 1);
    if (!proj) return { achou: false };
    const frente = [...proj.closest('[data-projeto]').querySelectorAll('.iniciativa-cabeca')]
      .find((e) => Math.abs(r(e).top - r(proj).bottom) <= 1);
    const z = (el) => Number(getComputedStyle(el).zIndex);
    return {
      achou: true,
      frenteEncaixada: !!frente,
      // Quem está mais acima na pilha tem de ficar por CIMA: invertido, a
      // frente cobriria o nome do projeto no momento da troca.
      ordemZ: z(sec) > z(proj) && z(proj) > z(frente || proj),
      opacos: [sec, proj, frente].filter(Boolean)
        .every((e) => !/rgba\(.*,\s*0\)/.test(getComputedStyle(e).backgroundColor)),
    };
  });
  t(`[${largura}] o cabeçalho do projeto encosta no de Projetos`, pilha.achou);
  if (pilha.achou) {
    t(`[${largura}] o da frente encosta no do projeto`, pilha.frenteEncaixada);
    t(`[${largura}] a pilha respeita a ordem de camadas`, pilha.ordemZ, JSON.stringify(pilha));
    t(`[${largura}] os três degraus são opacos`, pilha.opacos);
  }
  t(`[${largura}] o cabeçalho de Projetos gruda abaixo da topbar`, m.folga === 0, `folga ${m.folga}px`);
  t(`[${largura}] os botões de nível seguem à vista ao rolar`, m.controlesVisiveis);
  t(`[${largura}] o cabeçalho fixo é opaco e fica acima dos cartões`, m.opaco && m.z >= 2,
    JSON.stringify(m));

  // As frentes EMPILHAM (decisão do cliente): lendo a última ação do projeto,
  // TODAS as frentes percorridas continuam à vista, uma sob a outra, até o
  // bloco do projeto acabar. É o degrau novo que quebra em silêncio: com o
  // sticky limitado à caixa da própria frente (o que era), a segunda toma o
  // lugar da primeira e a prova de "encosta no do projeto" continua passando.
  const empilha = await page.evaluate((pid) => {
    const cartao = document.querySelector(`[data-projeto="${pid}"]`);
    const acoes = cartao.querySelectorAll('[data-card-acao]');
    acoes[acoes.length - 1].scrollIntoView({ block: 'center' });
    const r = (el) => el.getBoundingClientRect();
    const [f1, f2] = cartao.querySelectorAll('.iniciativa-cabeca');
    const proj = cartao.querySelector('.projeto-cabeca-fixa');
    const z = (el) => Number(getComputedStyle(el).zIndex);
    return {
      primeiraEncosta: Math.abs(r(f1).top - r(proj).bottom) <= 1,
      segundaEmpilha: Math.abs(r(f2).top - r(f1).bottom) <= 1,
      // A frente de baixo desliza por BAIXO da de cima no fim do bloco.
      zDesce: z(f1) > z(f2) && z(proj) > z(f1),
    };
  }, ids[0]);
  t(`[${largura}] as frentes percorridas empilham sob o projeto`,
    empilha.primeiraEncosta && empilha.segundaEmpilha, JSON.stringify(empilha));
  t(`[${largura}] na pilha das frentes o z-index desce`, empilha.zDesce);

  // Projeto NÃO empilha embaixo de projeto: chegando ao segundo, o cabeçalho
  // dele toma a MESMA linha e o primeiro sai junto com as frentes dele.
  const troca = await page.evaluate((alvos) => {
    const [a, b] = alvos.map((id) => document.querySelector(`[data-projeto="${id}"]`));
    // A ÚLTIMA ação do segundo projeto: na primeira, o cartão mal entrou e o
    // cabeçalho dele ainda nem grudou — a prova mediria rolagem de menos.
    const acoesB = b.querySelectorAll('[data-card-acao]');
    acoesB[acoesB.length - 1].scrollIntoView({ block: 'center' });
    const r = (el) => el.getBoundingClientRect();
    const sec = document.querySelector('.cabecalho-projetos');
    const some = (el) => r(el).bottom <= r(sec).bottom + 1;
    return {
      novoEncosta: Math.abs(r(b.querySelector('.projeto-cabeca-fixa')).top - r(sec).bottom) <= 1,
      anteriorSumiu: some(a.querySelector('.projeto-cabeca-fixa'))
        && [...a.querySelectorAll('.iniciativa-cabeca')].every(some),
    };
  }, ids);
  t(`[${largura}] o projeto novo substitui o anterior — nada empilha entre projetos`,
    troca.novoEncosta && troca.anteriorSumiu, JSON.stringify(troca));

  // ---- Pesquisa do plano de ação (palavra + situação) ----
  // A palavra seleciona a ação junto com a frente e o projeto dela; o resto
  // some — projetos e frentes sem resultado inclusive. As asserções ficam nos
  // DOIS cartões da prova: a instância pode ter outros projetos com massa real.
  await page.evaluate(() => {
    const campo = document.querySelector('[data-filtro-texto]');
    campo.value = 'rolagem A e';
    campo.dispatchEvent(new Event('input', { bubbles: true }));
  });
  const filtro = await page.evaluate((alvos) => {
    const [a, b] = alvos.map((id) => document.querySelector(`[data-projeto="${id}"]`));
    const visiveis = [...a.querySelectorAll('[data-card-acao]')].filter((c) => !c.classList.contains('d-none'));
    const frentes = [...a.querySelectorAll('[data-iniciativa]')];
    return {
      projetoA: !a.classList.contains('d-none'),
      projetoBSumiu: b.classList.contains('d-none'),
      soAcaoCerta: visiveis.length === 1 && visiveis[0].textContent.includes('rolagem A e'),
      frenteDaAcao: !frentes[1].classList.contains('d-none'),
      outraFrenteSumiu: frentes[0].classList.contains('d-none'),
    };
  }, ids);
  t(`[${largura}] a palavra seleciona ação, frente e projeto juntos`,
    filtro.projetoA && filtro.soAcaoCerta && filtro.frenteDaAcao
    && filtro.outraFrenteSumiu && filtro.projetoBSumiu, JSON.stringify(filtro));

  // Situação sozinha: só as concluídas dos dois cartões ficam — e o casamento
  // é pelo data-status, nunca pelo rótulo.
  await page.evaluate(() => {
    const campo = document.querySelector('[data-filtro-texto]');
    campo.value = '';
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    const sel = document.querySelector('[data-filtro-status]');
    sel.value = 'CONCLUIDO';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  });
  const porStatus = await page.evaluate((alvos) => {
    const visiveis = alvos.flatMap((id) =>
      [...document.querySelectorAll(`[data-projeto="${id}"] [data-card-acao]`)]
        .filter((c) => !c.classList.contains('d-none')));
    return { total: visiveis.length, batem: visiveis.every((c) => c.dataset.status === 'CONCLUIDO') };
  }, ids);
  t(`[${largura}] a situação filtra sozinha (só as concluídas ficam)`,
    porStatus.total === 2 && porStatus.batem, JSON.stringify(porStatus));

  // Limpar devolve tudo — inclusive o recolhimento que o usuário tinha.
  await page.evaluate(() => {
    const sel = document.querySelector('[data-filtro-status]');
    sel.value = '';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  });
  t(`[${largura}] limpar a pesquisa devolve tudo`,
    await page.evaluate((pid) => {
      const a = document.querySelector(`[data-projeto="${pid}"]`);
      return !a.classList.contains('d-none')
        && ![...a.querySelectorAll('[data-card-acao]')].some((c) => c.classList.contains('d-none'));
    }, ids[0]));

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.evaluate(async (alvos) => {
    for (const id of alvos) await App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 });
  }, ids);
}

/**
 * A fila de "Aguardando plano de ação": texto à esquerda, selo de origem e
 * botão agrupados à direita.
 *
 * O que se guarda é o AGRUPAMENTO. Soltos, o selo e o botão quebravam em
 * lugares diferentes conforme o tamanho do texto de cada pendência, e a fila
 * saía com cada linha montada de um jeito. É defeito que só aparece com massa
 * real — texto curto e texto longo na mesma lista.
 */
async function provasFilaAcao(page, largura) {
  const ids = await page.evaluate(async () => {
    const cria = async (desc) => {
      const f = await App.api('/api/fatores',
        { planejamento_id: 1, etapa: 'SWOT', categoria: 'FRAQUEZA', descricao: desc, ano: Diag.ano() });
      await App.api(`/api/fatores/${f.id}/plano-acao`, { planejamento_id: 1 });
      return f.id;
    };
    // Um curto e um longo de propósito: é a diferença entre eles que fazia cada
    // linha da fila quebrar num lugar.
    return [await cria('Curto'), await cria('Pendência longa o bastante para ocupar a '
      + 'largura toda da linha e disputar espaço com o selo de origem e com o botão de '
      + 'transformar em ação, que é quando o defeito aparecia.')];
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  const pintou = await esperar(page, "!!document.querySelector('.card-ideias-acao .acoes-pendencia')");
  t(`[${largura}] a fila de aguardando plano de ação pinta`, pintou);

  if (pintou) {
    const m = await page.evaluate(() => {
      const linhas = [...document.querySelectorAll('.card-ideias-acao .ideia-acao')];
      const r = (el) => el.getBoundingClientRect();
      const sobrepoe = (a, b) => Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0;
      return {
        // Encostado à direita: a folga entre o fim do grupo e o fim da linha é
        // zero em TODAS as linhas — um valor só no conjunto.
        folgas: [...new Set(linhas.map((l) => Math.round(r(l).right - r(l.querySelector('.acoes-pendencia')).right)))],
        // O selo e o botão nunca se separam.
        grupoInteiro: linhas.every((l) => {
          const bt = l.querySelector('[data-virar-acao]');
          return !bt || sobrepoe(r(l.querySelector('.badge')), r(bt));
        }),
        // No computador o grupo divide a linha com o texto; no celular ele desce.
        naLinhaDoTexto: linhas.every((l) =>
          sobrepoe(r(l.querySelector('.texto-pendencia')), r(l.querySelector('.acoes-pendencia')))),
        rolagemH: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    });

    t(`[${largura}] o grupo fica encostado à direita em todas as linhas`,
      m.folgas.length === 1 && m.folgas[0] === 0, JSON.stringify(m.folgas));
    t(`[${largura}] selo e botão nunca se separam`, m.grupoInteiro);
    if (largura === 'desktop') {
      // Num flex que quebra, o navegador quebra a linha ANTES de encolher o
      // item: com `flex-wrap`, o texto longo empurrava o grupo para baixo mesmo
      // sobrando espaço depois de ele se acomodar em duas linhas.
      t(`[${largura}] o grupo fica na MESMA linha do texto, mesmo no item longo`, m.naLinhaDoTexto);
    }
    t(`[${largura}] a fila não rola a página na horizontal`, !m.rolagemH);

    // O "Transformar em ação" é o SEGUNDO formulário que escreve uma ação, e a
    // razão de `camposAcao` existir é que os dois não divirjam: já divergiram
    // uma vez, e quem direcionava criava a ação sem como, sem prazo e sem
    // repetição, tendo de reabri-la no cadastro para completá-la. A prova é
    // pelo que ele TEM em comum com o cadastro — a caixa da repetição e os
    // campos que só existem lá dentro.
    await page.evaluate(() => document.querySelector('[data-virar-acao]').click());
    const abriu = await esperar(page, "!!document.getElementById('campo-o_que')", 15000);
    t(`[${largura}] o "transformar em ação" abre o formulário da ação`, abriu);
    if (abriu) {
      // (o fechamento fica FORA deste bloco — ver o porquê logo abaixo)
      const igual = await page.evaluate(() => {
        const caixa = document.querySelector('#modal-campos .caixa-repeticao');
        return {
          caixa: !!caixa,
          campos: ['recorrencia', 'recorrencia_dias_semana', 'recorrencia_dias_mes',
            'recorrencia_ate', 'quando_periodo'].every((n) => !!caixa?.querySelector(`#campo-${n}`)),
          ganhos: document.querySelector('#campo-quanto')?.classList.contains('campo-moeda'),
          // E o que é só DELE continua lá: o destino da ideia
          destino: !!document.getElementById('campo-destino'),
        };
      });
      t(`[${largura}] direcionar uma ideia usa a MESMA lista de campos do cadastro`,
        igual.caixa && igual.campos && igual.ganhos && igual.destino, JSON.stringify(igual));
    }
    // O fechamento fica FORA do `if (abriu)`, e isso não é estilo: quando a
    // espera estourava, a prova pulava o fechamento junto com a medição — e o
    // modal, que abria logo depois, ficava de pé para a prova SEGUINTE. Foi
    // uma falha intermitente, que só aparecia com o banco cheio: o vermelho
    // saía no popover, três provas adiante, e não dizia nada sobre isto aqui.
    // Provado, e não presumido, pelo mesmo motivo.
    t(`[${largura}] o formulário fecha e libera a tela`, await fecharModal(page));
  }

  await page.evaluate(async (alvos) => {
    for (const id of alvos) await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: 1 });
  }, ids);
}

/**
 * Invariantes da Matriz GUT que não aparecem no "a seção pinta":
 *
 * - o cabeçalho precisa **grudar** ao rolar; sem ele as quatro colunas de
 *   número viram números anônimos;
 * - a avaliação é **G, U e T e nada mais** — a pergunta do esforço saiu, e um
 *   campo a mais voltando de uma refatoração passaria por "detalhe";
 * - a coluna **Prioridade** traz a LETRA da faixa do score, sempre preenchida
 *   quando há avaliação. É a prova que segura o corte: trocar `>=` por `>` num
 *   piso da faixa deixa a tabela plausível e a legenda mentindo;
 * - o aviso de campo abaixo da dobra continua casando com o transbordo REAL do
 *   modal (`sobra === aviso`), nas duas direções — anunciar o que não existe
 *   ensina a ignorar o aviso.
 */
/**
 * A **pesquisa dentro da análise** — achar o fator pelo que ele diz.
 *
 * O que estas provas seguram:
 * - o campo existe no cabeçalho FIXO das três análises (SWOT, PESTEL, Porter).
 *   A SWOT tem renderizador próprio e as outras duas compartilham
 *   `etapaFatores`: são dois lugares, e é exatamente por isso que a prova
 *   confere os três — corrigir um e esquecer o outro é o defeito natural aqui;
 * - filtrar de verdade: todo cartão que sobra CONTÉM o termo. Um filtro que
 *   esconde demais é visível; um que esconde de menos passa por bom;
 * - acento e caixa não contam — em português, exigir "logística" para achar
 *   "logistica" é o mesmo que não ter busca;
 * - o contador do quadrante vira `visíveis/total`, e volta ao número puro ao
 *   limpar: só o número dos visíveis faria parecer que fatores foram apagados;
 * - o termo NÃO vaza entre análises. O estado é por etapa, e a análise vizinha
 *   abrindo com metade dos cartões escondidos não teria explicação na tela.
 */
async function provasBuscaAnalise(page, largura) {
  const semAcento = (s) => s.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '');

  // As SEIS telas do diagnóstico. São QUATRO renderizadores diferentes —
  // `etapaFatores` (PESTEL, Porter), `SecaoSwot`, `SecaoCenario`, `SecaoGut` e
  // `SecaoCruzamentos` —, e é por isso que a prova percorre todas: acertar um e
  // esquecer o outro é o defeito natural aqui, e já aconteceu uma vez.
  // A GUT usa `.cabecalho-gut` no lugar de `.cabecalho-analise`; as duas
  // carregam `data-cabecalho-analise`, que é o que de fato gruda no topo.
  for (const [secao, nome] of [['swot', 'SWOT'], ['pestel', 'PESTEL'], ['porter', 'Porter'],
    ['cenario', 'Análise de Cenário'], ['gut', 'Matriz GUT'], ['cruzamentos', 'Cruzamentos']]) {
    await page.evaluate((s) => App.mostrarSecao(s), secao);
    const tem = await esperar(page, `!!document.querySelector('#secao-${secao} [data-busca-analise]')`, 15000);
    t(`[${largura}] ${nome} tem a pesquisa no cabeçalho fixo`,
      tem && await page.evaluate((s) =>
        !!document.querySelector(`#secao-${s} [data-cabecalho-analise] [data-busca-analise]`), secao));
  }

  // A Matriz GUT desenha o MESMO registro duas vezes — cartões no celular e
  // linhas no computador, com o mesmo `data-card-fator`. Duas provas que só ela
  // pode dar: o contador conta REGISTROS (senão diria "12 de 48" onde há 24
  // fatores) e a busca não varre as NOTAS (senão "5" casaria com G, U, T e
  // score, e o usuário veria um filtro que não sabe explicar).
  await page.evaluate(() => App.mostrarSecao('gut'));
  if (await esperar(page, "!!document.querySelector('#secao-gut [data-busca-analise]')", 15000)) {
    const gut = await page.evaluate(() => {
      const raiz = document.querySelector('#secao-gut');
      const nos = [...raiz.querySelectorAll('[data-card-fator]')];
      const c = raiz.querySelector('[data-busca-analise]');
      c.value = '5';
      c.dispatchEvent(new Event('input', { bubbles: true }));
      const vis = [...raiz.querySelectorAll('[data-card-fator]:not(.d-none)')];
      const comCinco = vis.filter((x) => {
        const p = x.querySelector('[data-busca-texto], .texto-fator');
        return (p ? p.textContent : '').includes('5');
      });
      const aviso = raiz.querySelector('[data-busca-resultado]')?.textContent.trim() || '';
      c.value = '';
      c.dispatchEvent(new Event('input', { bubbles: true }));
      return {
        nos: nos.length,
        registros: new Set(nos.map((x) => x.dataset.cardFator)).size,
        visiveis: vis.length,
        comCinco: comCinco.length,
        aviso,
      };
    });
    if (gut.registros) {
      t(`[${largura}] GUT: o contador conta registros, não nós repetidos`,
        !/\d+ de \d+/.test(gut.aviso) || Number(gut.aviso.split(' de ')[1]) === gut.registros,
        `${gut.nos} nós · ${gut.registros} registros · aviso "${gut.aviso}"`);
      t(`[${largura}] GUT: buscar "5" não casa por nota nem score`,
        gut.visiveis === gut.comCinco,
        `${gut.visiveis} visíveis, ${gut.comCinco} com "5" na descrição`);
    }
  }

  // O resto é medido na SWOT, que é a de renderizador próprio
  await page.evaluate(() => App.mostrarSecao('swot'));
  await esperar(page, "!!document.querySelector('#secao-swot [data-busca-analise]')", 15000);
  // O campo de busca já existe na pintura ANTERIOR da SWOT (a seção não é
  // destruída ao navegar): esperar só por ele media a tela velha, às vezes
  // antes de a repintura no ano da carga trazer os cartões — "nenhum cartão"
  // de vez em quando, sem defeito nenhum.
  await esperar(page, "document.querySelectorAll('#secao-swot [data-card-fator]').length > 0", 15000);
  const digitar = async (q) => {
    await page.evaluate((termo) => {
      const c = document.querySelector('#secao-swot [data-busca-analise]');
      c.value = termo;
      c.dispatchEvent(new Event('input', { bubbles: true }));
    }, q);
    await new Promise((r) => setTimeout(r, 120));
    return page.evaluate(() => ({
      total: document.querySelectorAll('#secao-swot [data-card-fator]').length,
      visiveis: document.querySelectorAll('#secao-swot [data-card-fator]:not(.d-none)').length,
      aviso: document.querySelector('#secao-swot [data-busca-resultado]')?.textContent.trim() || '',
      contadores: [...document.querySelectorAll('#secao-swot .contador-cards')].map((c) => c.textContent.trim()),
      textos: [...document.querySelectorAll('#secao-swot [data-card-fator]:not(.d-none) .texto-fator')]
        .map((x) => x.textContent.trim()),
    }));
  };

  const base = await digitar('');
  if (!base.total) { t(`[${largura}] SWOT tem fatores para pesquisar`, false, 'nenhum cartão'); return; }

  // Os termos saem dos PRÓPRIOS cartões, não de palavras fixas: com termo
  // escolhido à mão, ou a prova quebra quando alguém revisa o conteúdo da
  // carga, ou — pior — cai numa palavra que casa com tudo (buscar "a" achava
  // 24 de 24) e seguiria verde com o filtro quebrado.
  const palavras = base.textos.join(' ').split(/[^\p{L}\p{N}]+/u).filter((w) => w.length >= 7);
  const raro = palavras.find((w) =>
    base.textos.filter((x) => semAcento(x).includes(semAcento(w))).length < base.total);

  if (!raro) {
    t(`[${largura}] havia termo capaz de separar os cartões da SWOT`, false, 'nenhum encontrado');
  } else {
    const achou = await digitar(raro);
    t(`[${largura}] a pesquisa filtra os cartões da SWOT`,
      achou.visiveis > 0 && achou.visiveis < base.total,
      `"${raro}" → ${achou.visiveis} de ${base.total}`);
    const intrusos = achou.textos.filter((x) => !semAcento(x).includes(semAcento(raro)));
    t(`[${largura}] todo cartão que sobra contém mesmo o termo`, intrusos.length === 0,
      intrusos.length ? intrusos[0].slice(0, 60) : `${achou.textos.length} conferidos`);
    t(`[${largura}] o contador do quadrante vira visíveis/total`,
      achou.contadores.some((c) => c.includes('/')), JSON.stringify(achou.contadores));
  }

  // Acento: uma palavra ACENTUADA dos próprios cartões tem de ser achada
  // digitada sem acento. Em português, exigir o acento é não ter busca.
  const acentuada = palavras.find((w) => semAcento(w) !== w.toLocaleLowerCase('pt-BR'));
  if (acentuada) {
    const comAc = await digitar(acentuada);
    const semAc = await digitar(semAcento(acentuada));
    const alta = await digitar(acentuada.toLocaleUpperCase('pt-BR'));
    t(`[${largura}] a pesquisa ignora acento`,
      comAc.visiveis === semAc.visiveis && comAc.visiveis > 0,
      `"${acentuada}"=${comAc.visiveis} · sem acento=${semAc.visiveis}`);
    t(`[${largura}] a pesquisa ignora maiúsculas`,
      alta.visiveis === comAc.visiveis && alta.visiveis > 0, `${alta.visiveis}`);
  }

  const nada = await digitar('zzzznaoexisteisso');
  t(`[${largura}] termo sem resultado avisa, em vez de esvaziar calado`,
    // "nada encontrado", e não "nenhum fator": o mesmo aviso serve aos
    // Cruzamentos, onde o item é um par, não um fator
    nada.visiveis === 0 && /nada encontrado/i.test(nada.aviso), nada.aviso);
  t(`[${largura}] e cada quadrante vazio diz por quê`,
    await page.evaluate(() => [...document.querySelectorAll('#secao-swot [data-busca-vazio]')]
      .filter((v) => !v.classList.contains('d-none')).length > 0));

  const limpo = await digitar('');
  t(`[${largura}] limpar devolve todos os cartões`, limpo.visiveis === base.total,
    `${limpo.visiveis}/${base.total}`);
  t(`[${largura}] e os contadores voltam ao número puro`,
    limpo.contadores.every((c) => !c.includes('/')), JSON.stringify(limpo.contadores));
  t(`[${largura}] a SWOT com a pesquisa não rola a página na horizontal`,
    await page.evaluate(() =>
      document.documentElement.scrollWidth - document.documentElement.clientWidth <= 1));

  // Isolamento entre análises
  await page.evaluate(() => App.mostrarSecao('porter'));
  await esperar(page, "!!document.querySelector('#secao-porter [data-busca-analise]')", 15000);
  await page.evaluate(() => {
    const c = document.querySelector('#secao-porter [data-busca-analise]');
    c.value = 'zzzznaoexisteisso';
    c.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await page.evaluate(() => App.mostrarSecao('swot'));
  await esperar(page, "!!document.querySelector('#secao-swot [data-busca-analise]')", 15000);
  t(`[${largura}] a pesquisa do Porter não vaza para a SWOT`,
    await page.evaluate(() =>
      document.querySelector('#secao-swot [data-busca-analise]').value === ''
      && document.querySelectorAll('#secao-swot [data-card-fator]:not(.d-none)').length > 0));
}

async function provasGut(page) {
  // Corre no ano da carga (ver `noAnoDaCarga`): a GUT lista a SWOT do ano da
  // análise, e a fila que gruda e rola só existe onde há conteúdo.
  await page.evaluate(() => App.mostrarSecao('gut'));
  await esperar(page, `!!document.querySelector('#secao-gut tbody tr [data-avaliar]')`, 15000);
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
  // A letra da coluna Prioridade sai da faixa do score: confere linha a linha
  // contra os mesmos cortes da legenda (<27, 27–63, ≥64). Linha sem avaliação
  // não tem letra nenhuma — cor ali afirmaria uma prioridade que ninguém deu.
  const faixas = await page.evaluate(() => [...document.querySelectorAll('#secao-gut tbody tr')]
    .map((tr) => {
      const td = [...tr.querySelectorAll('td')];
      const score = parseInt(td[6]?.textContent.trim(), 10);
      return { score: Number.isNaN(score) ? null : score,
        letra: tr.querySelector('.selo-faixa')?.textContent.trim() || null };
    }));
  const esperada = (s) => (s === null ? '—' : s >= 64 ? 'G' : s >= 27 ? 'M' : 'P');
  const erradas = faixas.filter((f) => f.letra !== esperada(f.score));
  t('[desktop] Coluna Prioridade traz a letra da faixa do score',
    faixas.length > 0 && erradas.length === 0, JSON.stringify(erradas.slice(0, 3)));

  await page.click('#secao-gut tbody tr:first-child [data-avaliar]');
  const abriu = await esperar(page, "!!document.getElementById('campo-gravidade')", 8000);
  t('[desktop] Modal da GUT abre com as notas', abriu);
  if (!abriu) return;
  t('[desktop] Avaliação da GUT não pergunta esforço',
    !(await page.evaluate(() => !!document.getElementById('campo-esforco'))));
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
 * O formulário da AÇÃO: a ordem dos campos, a caixa da repetição e os dois
 * campos de texto compactos.
 *
 * O que esta prova guarda, e que quebra em silêncio:
 *
 * - **A repetição é que decide qual prazo existe.** Sem repetição vale o
 *   período digitado; com repetição, a grade de dias. Os dois na tela ao mesmo
 *   tempo faziam o usuário preencher um "fim previsto" que a primeira
 *   conclusão descartava — e nenhum dos dois é conferência de olho: um
 *   `visivelSe` que pare de casar deixa os dois visíveis, ou nenhum.
 * - **"Na mesma caixa" e "na mesma linha" são medidas.** A caixa é um retângulo
 *   com bordas, e o campo que escapa dela continua plausível na tela.
 * - **As duas grades aceitam VÁRIOS dias.** É a regra nova; um `select` de
 *   volta no lugar das fichas passaria despercebido numa leitura.
 * - **O campo de texto nasce compacto.** A altura é calculada em JS a partir de
 *   uma disputa de especificidade no CSS (o `:has()` da regra genérica vence o
 *   `[data-max-linhas]` sozinho) — já quebrou uma vez, e o defeito era o campo
 *   nascer com o dobro do tamanho, o que ninguém chama de erro.
 * - **O campo de dinheiro recusa o que não é número.** `type=number` deixava
 *   passar `e`, `+` e `-` e depois devolvia vazio.
 */
/**
 * A pesquisa do plano de ação achando as ações de uma PESSOA.
 *
 * São dois caminhos, e a prova guarda o que distingue um do outro — porque os
 * dois continuam plausíveis quando um deles quebra:
 *
 * - a **palavra** é o caminho largo: casa com o texto da ação, com os títulos
 *   acima dela e também com o nome e o **e-mail** de quem responde. O e-mail é
 *   o que não está escrito na tela: sem o `data-quem` no cartão ele deixa de
 *   ser encontrado, e a busca por e-mail passa a devolver nada — silenciosa,
 *   porque "não achou" é uma resposta que parece legítima;
 * - o **responsável** é o caminho exato: casa SÓ contra quem responde. É esta
 *   prova que segura a diferença: "ana" ali não pode trazer "toda semana"
 *   junto. Se alguém "simplificar" fazendo os dois campos compartilharem o
 *   mesmo casamento, a tela continua funcionando e a precisão some.
 *
 * E o «Sem usuário», que é escolha e não busca por pedaço: ele acha a ação
 * órfã — a de quem saiu do cadastro sem que ninguém assumisse — e NÃO pode
 * trazer junto ação que tem dono.
 */
async function provasFiltroResponsavel(page) {
  // Nenhuma prova pode depender da limpeza da anterior: um modal que tenha
  // ficado aberto captura TODO clique da página seguinte (o `.modal-body`
  // intercepta o ponteiro), e o vermelho aparece aqui, longe de onde nasceu.
  // Fecha pela API do Bootstrap, não por Escape — Escape depende de foco, e o
  // foco é justamente o que um modal órfão já perdeu.
  await page.evaluate(() => {
    document.querySelectorAll('.modal.show').forEach((m) => {
      window.bootstrap?.Modal.getInstance(m)?.hide();
    });
  });
  await esperar(page, "!document.querySelector('.modal.show')", 4000);

  const ids = await page.evaluate(async () => {
    const ana = await App.api('/api/usuarios', { nome: 'Ana Prova Filtro',
      email: 'ana.filtro@teste.local', senha: 'trocar123', perfil: 'CONTROLADORIA', negocios: [] });
    const sai = await App.api('/api/usuarios', { nome: 'Carlos Prova Filtro',
      email: 'carlos.filtro@teste.local', senha: 'trocar123', perfil: 'CONTROLADORIA', negocios: [] });
    const p = await App.api('/api/projetos', { planejamento_id: 1, titulo: 'Projeto filtro pessoa',
      ano: 2027, responsavel: 'QA', descricao: 'x' });
    const i = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: p.id,
      titulo: 'Frente filtro pessoa' });
    const base = { planejamento_id: 1, projeto_id: p.id, iniciativa_id: i.id, como: 'x',
      prioridade: 'MEDIA', status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31' };
    await App.api('/api/desdobramentos', { ...base, o_que: 'Revisar contratos', quem: 'Ana Prova Filtro' });
    // A armadilha do caminho largo: "ana" está dentro de "semana", e esta ação
    // é de OUTRA pessoa. O filtro de responsável não pode trazê-la.
    await App.api('/api/desdobramentos', { ...base, o_que: 'Reuniao toda semana', quem: 'Administrador' });
    await App.api('/api/desdobramentos', { ...base, o_que: 'Acao que ficou orfa', quem: 'Carlos Prova Filtro' });
    // Ação órfã só nasce de um jeito: o dono sai e ninguém assume.
    await App.api(`/api/usuarios/${sai.id}/excluir`, { sem_responsavel: true });
    return { ana: ana.id, projeto: p.id };
  });

  await page.evaluate(() => App.mostrarSecao('projetos'));
  await esperar(page, "!document.getElementById('secao-projetos').classList.contains('d-none')", 15000);
  await esperar(page, "document.querySelectorAll('#secao-projetos [data-card-acao]').length >= 3", 10000);

  const visiveis = () => page.evaluate(() =>
    [...document.querySelectorAll('#secao-projetos [data-card-acao]')]
      .filter((c) => !c.classList.contains('d-none'))
      .map((c) => c.querySelector('.fw-bold')?.textContent.trim()).sort());

  /**
   * Escreve num dos campos e SÓ VOLTA quando o estado da seção acompanhou.
   *
   * O estado (`filtroTexto`/`filtroResponsavel`) é a fonte da verdade e
   * sobrevive às repinturas — um `carregar()` que termine no meio do teste
   * redesenha o cabeçalho e reescreve os inputs a partir dele. Sem esta espera,
   * a tecla digitada era desfeita por uma repintura tardia e a prova lia o
   * resultado do filtro ANTERIOR: verde pelo motivo errado num caso, vermelho
   * sem defeito nenhum no outro.
   */
  const definir = async (campo, valor) => {
    const sel = `#secao-projetos [data-filtro-${campo === 'filtroTexto' ? 'texto' : 'responsavel'}]`;
    for (let tentativa = 0; tentativa < 4; tentativa += 1) {
      if (valor === '') {
        // `fill(sel, '')` é NO-OP num campo que já tem texto: o Chromium não
        // apaga a seleção quando o texto inserido é vazio, e a prova seguia com
        // o filtro anterior de pé — verde pelo motivo errado num caso, vermelho
        // sem defeito nenhum no outro. Limpar pelo teclado é o que o usuário
        // faz, e é o único caminho que dispara o `input` de verdade.
        await page.click(sel);
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.press('Backspace');
      } else {
        await page.fill(sel, valor);
      }
      const ok = await esperar(page,
        `SecaoProjetos.${campo} === ${JSON.stringify(valor)}`, 1500);
      if (ok) return true;
    }
    return false;
  };
  const porPalavra = async (q) => {
    await definir('filtroResponsavel', '');
    await definir('filtroTexto', q);
    await new Promise((r) => setTimeout(r, 250));
    return visiveis();
  };
  const porResp = async (q) => {
    await definir('filtroTexto', '');
    await definir('filtroResponsavel', q);
    await new Promise((r) => setTimeout(r, 250));
    return visiveis();
  };

  const lista = await page.evaluate(() =>
    [...document.querySelectorAll('#lista-responsaveis-acoes option')].map((o) => o.value));
  t('[desktop] «Sem usuário» é a PRIMEIRA opção da lista de responsáveis',
    lista[0] === 'Sem usuário', JSON.stringify(lista));
  t('[desktop] A lista traz quem tem ação no plano',
    lista.includes('Ana Prova Filtro'), JSON.stringify(lista));

  const porEmail = await porPalavra('ana.filtro@teste.local');
  t('[desktop] A palavra acha pelo E-MAIL do responsável',
    porEmail.length === 1 && porEmail[0] === 'Revisar contratos', JSON.stringify(porEmail));

  const respParcial = await porResp('ana');
  t('[desktop] O responsável casa por PARTE do nome, sem o nome inteiro',
    respParcial.length === 1 && respParcial[0] === 'Revisar contratos', JSON.stringify(respParcial));
  t('[desktop] E não traz a ação de outro só porque o texto contém "ana"',
    !respParcial.includes('Reuniao toda semana'), JSON.stringify(respParcial));

  const respEmail = await porResp('carlos.filtro@teste.local');
  t('[desktop] O responsável casa pelo e-mail — e o de quem SAIU não acha mais nada',
    respEmail.length === 0, JSON.stringify(respEmail));

  const orfas = await porResp('Sem usuário');
  t('[desktop] «Sem usuário» acha a ação órfã',
    orfas.includes('Acao que ficou orfa'), JSON.stringify(orfas));
  t('[desktop] «Sem usuário» não traz ação que tem dono',
    !orfas.includes('Revisar contratos') && !orfas.includes('Reuniao toda semana'),
    JSON.stringify(orfas));

  await definir('filtroResponsavel', '');
  await definir('filtroTexto', '');
  await new Promise((r) => setTimeout(r, 250));
  const todas = await visiveis();
  t('[desktop] Limpar os filtros devolve as três ações', todas.length === 3, JSON.stringify(todas));

  await page.evaluate(async (ids) => {
    await App.api(`/api/projetos/${ids.projeto}/excluir`, { planejamento_id: 1 });
    await App.api(`/api/usuarios/${ids.ana}/excluir`, { sem_responsavel: true }).catch(() => {});
  }, ids);
}

/**
 * Excluir um usuário: o formulário que PERGUNTA antes de apagar.
 *
 * O que esta prova segura não aparece em "a seção pinta", e cada item já é um
 * jeito conhecido de perder trabalho em silêncio:
 *
 * - **a contagem chega antes da escolha.** É ela que dá sentido à pergunta:
 *   sem "1 ação do plano" na tela, o formulário está pedindo uma assinatura em
 *   branco. Um `vinculos` que pare de contar deixa o modal plausível e vazio.
 * - **quem sai não aparece como destino de si mesmo**, e inativo também não —
 *   transferir para um inativo é o mesmo sumiço de "sem responsável", só que
 *   com um nome na tela dizendo que alguém está cuidando disso.
 * - **a decisão revela o que lhe pertence**: "deixar sem responsável" esconde a
 *   lista de quem assume. Um `visivelSe` que pare de casar deixa as duas
 *   perguntas na tela ao mesmo tempo, e aí não há resposta certa.
 * - **o botão do rodapé volta ao padrão no formulário SEGUINTE.** O
 *   `#modal-salvar` é o mesmo elemento em todos os modais: sem a reposição,
 *   um "Excluir usuário" vermelho fica no rodapé do cadastro de projeto pelo
 *   resto da sessão. É o defeito mais barato de introduzir e o mais difícil de
 *   enxergar, porque só aparece no modal de DEPOIS.
 */
async function provasExcluirUsuario(page) {
  const ids = await page.evaluate(async () => {
    const sai = await App.api('/api/usuarios', { nome: 'Zeca da Prova Visual',
      email: 'zeca.visual@teste.local', senha: 'trocar123', perfil: 'CONTROLADORIA', negocios: [] });
    const off = await App.api('/api/usuarios', { nome: 'Inativo da Prova Visual',
      email: 'off.visual@teste.local', senha: 'trocar123', perfil: 'CONTROLADORIA',
      ativo: false, negocios: [] });
    const p = await App.api('/api/projetos', { planejamento_id: 1, titulo: 'Projeto prova exclusão',
      ano: 2027, responsavel: 'QA', descricao: 'x' });
    const i = await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: p.id,
      titulo: 'Frente prova exclusão' });
    await App.api('/api/desdobramentos', { planejamento_id: 1, projeto_id: p.id, iniciativa_id: i.id,
      o_que: 'Ação do Zeca visual', como: 'x', quem: 'Zeca da Prova Visual', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31' });
    return { sai: sai.id, off: off.id, projeto: p.id };
  });

  await page.evaluate(() => App.mostrarSecao('cadastros'));
  await esperar(page, "!document.getElementById('secao-cadastros').classList.contains('d-none')", 15000);
  await page.click('[data-aba="usuarios"]');
  // O seletor é ESCOPADO à lista de usuários: as seções não são destruídas ao
  // navegar (só ganham `d-none`), e `[data-excluir]` também é o ✕ do cadastro de
  // negócios — sem o escopo, a prova casava com um botão escondido de outra aba
  // e ficava esperando por um clique que nunca acontece.
  const alvoBotao = `#lista-usuarios [data-excluir="${ids.sai}"]`;
  const temBotao = await esperar(page, `!!document.querySelector('${alvoBotao}')`, 8000);
  t('[desktop] Usuário excluível traz o ✕ no cartão', temBotao);
  if (temBotao) {
    await page.click(alvoBotao);
    const abriu = await esperar(page, "!!document.getElementById('campo-destino')", 8000);
    t('[desktop] O ✕ abre o formulário de exclusão', abriu);
    if (abriu) {
      await new Promise((r) => setTimeout(r, 400));
      const visto = await page.evaluate((ids) => ({
        conta: document.querySelector('.modal-body').textContent.includes('1 ação do plano'),
        temLista: !!document.getElementById('campo-transferir_para'),
        eleMesmo: !!document.querySelector(`#campo-transferir_para input[value="${ids.sai}"]`),
        inativo: !!document.querySelector(`#campo-transferir_para input[value="${ids.off}"]`),
        botao: document.getElementById('modal-salvar').textContent.trim(),
        perigo: document.getElementById('modal-salvar').classList.contains('btn-danger'),
      }), ids);
      t('[desktop] O formulário conta a carteira antes de perguntar', visto.conta, JSON.stringify(visto));
      t('[desktop] Transferir mostra a lista de quem assume', visto.temLista);
      t('[desktop] Quem está saindo não é destino de si mesmo', !visto.eleMesmo);
      t('[desktop] Usuário inativo não entra como destino', !visto.inativo);
      t('[desktop] O botão do rodapé diz que vai EXCLUIR, em vermelho',
        visto.botao === 'Excluir usuário' && visto.perigo, JSON.stringify(visto));

      await page.click('label[for="campo-destino-SEM_RESPONSAVEL"]');
      await new Promise((r) => setTimeout(r, 300));
      // ESCONDE, não remove: o `visivelSe` desliga a exibição e o elemento
      // continua no DOM. Afirmar a ausência dele reprovaria o comportamento
      // certo — o que importa é que ninguém consegue mais responder a pergunta
      // que deixou de valer.
      t('[desktop] "Sem responsável" esconde a lista de quem assume',
        await page.evaluate(() => {
          const el = document.getElementById('campo-transferir_para');
          return !el || el.offsetParent === null;
        }));
      await page.keyboard.press('Escape');
      await esperar(page, "!document.querySelector('.modal.show')", 4000);
    }
  }

  // O rodapé do formulário SEGUINTE: é aqui que a falta de reposição aparece.
  await page.evaluate(() => App.mostrarSecao('cadastros'));
  await page.click('[data-aba="usuarios"]');
  await esperar(page, "!!document.getElementById('btn-novo-usuario')", 8000);
  await page.click('#btn-novo-usuario');
  const abriuNovo = await esperar(page, "!!document.getElementById('campo-nome')", 8000);
  if (abriuNovo) {
    const rodape = await page.evaluate(() => {
      const b = document.getElementById('modal-salvar');
      return { texto: b.textContent.trim(), verde: b.classList.contains('btn-verde'),
        perigo: b.classList.contains('btn-danger') };
    });
    t('[desktop] O formulário seguinte volta a "Salvar", em verde',
      rodape.texto === 'Salvar' && rodape.verde && !rodape.perigo, JSON.stringify(rodape));
    await page.keyboard.press('Escape');
    await esperar(page, "!document.querySelector('.modal.show')", 4000);
  }

  await page.evaluate(async (ids) => {
    await App.api(`/api/projetos/${ids.projeto}/excluir`, { planejamento_id: 1 });
    for (const id of [ids.sai, ids.off]) {
      await App.api(`/api/usuarios/${id}/excluir`, { sem_responsavel: true }).catch(() => {});
    }
  }, ids);
}

async function provasAcao(page, largura) {
  const l = `[${largura}]`;
  const prj = await page.evaluate(async () => {
    const p = await App.api('/api/projetos', {
      planejamento_id: 1, titulo: 'Projeto prova ação', ano: 2027, responsavel: 'QA', descricao: 'x',
    });
    await App.api('/api/iniciativas', { planejamento_id: 1, projeto_id: p.id, titulo: 'Frente prova' });
    return p.id;
  });
  await page.evaluate(() => App.mostrarSecao('projetos'));
  await esperar(page, "!document.getElementById('secao-projetos').classList.contains('d-none')", 15000);
  // Pelo PROJETO desta prova, nunca por um `[data-nova-acao]` qualquer: a
  // pintura anterior continua na tela enquanto a recarga não volta, e o botão
  // dela aponta para uma frente de outro projeto (já apagado).
  const temBotao = await esperar(
    page, `!!document.querySelector('[data-projeto="${prj}"] [data-nova-acao]')`, 15000);
  t(`${l} Iniciativa oferece "+ Ação"`, temBotao);
  const abrirModal = async () => {
    await page.evaluate((id) => {
      const b = document.querySelector(`[data-projeto="${id}"] [data-nova-acao]`);
      SecaoProjetos.modalDesdobramento(parseInt(b.dataset.proj, 10), null, parseInt(b.dataset.novaAcao, 10));
    }, prj);
    return esperar(page, "!!document.getElementById('campo-o_que')", 8000);
  };

  if (temBotao) {
    const abriu = await abrirModal();
    t(`${l} Modal da ação abre`, abriu);
    if (abriu) {
      await new Promise((r) => setTimeout(r, 300));
      const ordem = await page.evaluate(() => [...document.querySelectorAll('#modal-campos .form-label')]
        .map((x) => x.textContent.trim()));
      const esperada = ['O quê? *', 'Como? *', 'Quem? *', 'Repetição',
        'Selecione o dia da semana para repetir:',
        'Selecione o dia ou dias do mês em que haverá repetição:',
        'Data fim da repetição', 'Quando? (Prazo de Execução) *',
        'Prioridade', 'Status', 'Ganhos previstos (R$)'];
      t(`${l} Campos da ação na ordem pedida`,
        JSON.stringify(ordem) === JSON.stringify(esperada), JSON.stringify(ordem));

      // ── A caixa da repetição ────────────────────────────────────────────
      // Todo campo da decisão mora DENTRO do painel; nenhum outro entra nele.
      const naCaixa = await page.evaluate(() => {
        const caixa = document.querySelector('#modal-campos .caixa-repeticao');
        if (!caixa) return null;
        const dentro = (id) => !!caixa.querySelector(`#campo-${id}`);
        return {
          existe: true,
          todos: ['recorrencia', 'recorrencia_dias_semana', 'recorrencia_dias_mes',
            'recorrencia_ate', 'quando_periodo'].every(dentro),
          // O que NÃO é da decisão fica de fora: senão a caixa deixa de
          // significar "isto depende da escolha acima"
          semIntrusos: !dentro('quem') && !dentro('status') && !dentro('quanto'),
        };
      });
      t(`${l} a repetição e o prazo moram numa caixa só`,
        !!naCaixa?.existe && naCaixa.todos, JSON.stringify(naCaixa));
      t(`${l} nenhum campo alheio entra na caixa da repetição`, !!naCaixa?.semIntrusos);

      // ── Um prazo OU o outro, nunca os dois ──────────────────────────────
      const visivel = (id) => page.evaluate((n) => {
        const bloco = document.getElementById(`campo-${n}`)?.closest('.mb-3');
        return !!bloco && !bloco.classList.contains('d-none');
      }, id);
      const trocar = async (modo) => {
        await page.selectOption('#campo-recorrencia', modo);
        await new Promise((r) => setTimeout(r, 250));
        return {
          periodo: await visivel('quando_periodo'),
          semana: await visivel('recorrencia_dias_semana'),
          mes: await visivel('recorrencia_dias_mes'),
          ate: await visivel('recorrencia_ate'),
        };
      };
      const nenhuma = await trocar('NENHUMA');
      t(`${l} sem repetição, só o período de execução aparece`,
        nenhuma.periodo && !nenhuma.semana && !nenhuma.mes && !nenhuma.ate, JSON.stringify(nenhuma));
      const semanal = await trocar('SEMANAL');
      t(`${l} toda semana troca o período pelos dias da semana`,
        !semanal.periodo && semanal.semana && !semanal.mes && semanal.ate, JSON.stringify(semanal));

      // ── As fichas dos dias, e a marcação MÚLTIPLA ───────────────────────
      const semana = await page.evaluate(() => {
        const g = document.getElementById('campo-recorrencia_dias_semana');
        const fichas = [...g.querySelectorAll('input[type=checkbox]')];
        fichas[0].click();
        fichas[3].click();
        return {
          quantas: fichas.length,
          rotulos: [...g.querySelectorAll('label')].map((x) => x.textContent.trim()),
          marcadas: fichas.filter((c) => c.checked).map((c) => Number(c.value)),
          // A grade nasce vazia: pré-marcar gravaria uma rotina que ninguém escolheu
          coletado: Modal.coletar().recorrencia_dias_semana,
        };
      });
      t(`${l} são sete fichas, de Segunda a Domingo`, semana.quantas === 7
        && semana.rotulos[0] === 'Segunda' && semana.rotulos[6] === 'Domingo',
      JSON.stringify(semana.rotulos));
      t(`${l} dá para marcar MAIS DE UM dia da semana`,
        JSON.stringify(semana.marcadas) === '[1,4]'
        && JSON.stringify(semana.coletado) === '[1,4]', JSON.stringify(semana));

      const mensal = await trocar('MENSAL');
      t(`${l} todo mês troca a semana pela grade do mês`,
        !mensal.periodo && !mensal.semana && mensal.mes && mensal.ate, JSON.stringify(mensal));
      const mes = await page.evaluate(() => {
        const g = document.getElementById('campo-recorrencia_dias_mes');
        const fichas = [...g.querySelectorAll('input[type=checkbox]')];
        fichas[4].click();
        fichas[19].click();
        return { quantas: fichas.length, coletado: Modal.coletar().recorrencia_dias_mes };
      });
      t(`${l} a grade do mês tem 31 dias e aceita vários`,
        mes.quantas === 31 && JSON.stringify(mes.coletado) === '[5,20]', JSON.stringify(mes));

      // ── Prioridade e Status na mesma fileira ────────────────────────────
      const linhas = await page.evaluate(() =>
        [...document.querySelectorAll('#modal-campos .grade-campos')].map((g) =>
          [...g.querySelectorAll('.mb-3')].filter((b) => !b.classList.contains('d-none'))
            .map((b) => ({ rotulo: b.querySelector('.form-label')?.textContent.trim(),
              topo: Math.round(b.getBoundingClientRect().top) }))));
      const par = linhas.find((x) => x.length === 2);
      t(`${l} Prioridade e Status na MESMA linha`,
        !!par && par.every((c) => c.topo === par[0].topo), JSON.stringify(linhas));

      // ── Os campos de texto: compactos, com as ferramentas no alto ───────
      const texto = await page.evaluate(() => {
        const t1 = document.getElementById('campo-o_que');
        const cabeca = t1.closest('.mb-3').querySelector('.linha-rotulo');
        const fer = cabeca?.querySelector('.campo-ferramentas');
        const r = (el) => el.getBoundingClientRect();
        const alturaLinha = Modal.alturaLinha(t1);
        return {
          compacto: Math.round(r(t1).height),
          umaLinha: Math.round(Modal.alturaMinima(t1)),
          alturaLinha: Math.round(alturaLinha),
          teto5: Math.round(5 * alturaLinha + Modal.bordasVerticais(t1)),
          rolando: t1.scrollHeight > t1.clientHeight + 1,
          // A SETA fica na linha do rótulo, encostada à direita e acima do campo
          seta: fer?.querySelector('.btn-expandir')?.textContent.trim(),
          setaADireita: !!fer && r(fer).right > r(cabeca.querySelector('.form-label')).right,
          setaAcima: !!fer && r(fer).bottom <= r(t1).top + 1,
          // O MICROFONE fica DENTRO da caixa de texto, no canto inferior
          // direito — o padrão de todo campo de texto do sistema. Nada de
          // microfone na barra do rótulo.
          micNaCaixa: !!t1.closest('.campo-voz')?.querySelector('.btn-ditar'),
          micNaBarra: !!fer?.querySelector('.btn-ditar'),
          micEmBaixo: (() => {
            const b = t1.closest('.campo-voz')?.querySelector('.btn-ditar');
            return !!b && r(b).bottom > r(t1).top + r(t1).height / 2
              && r(b).right <= r(t1).right + 1;
          })(),
          // E NÃO cobre a ALÇA de arrastar a altura, que mora no canto inferior
          // direito da caixa: coberta, o toque ligava o ditado em vez de
          // esticar o campo — foi assim que ela nasceu quebrada. A prova é a
          // NÃO-sobreposição com o retângulo REAL da alça (ela cresce no
          // celular, e um quadrado fixo de 16px reprovaria o tamanho certo).
          alcaLivre: (() => {
            const b = t1.closest('.campo-voz')?.querySelector('.btn-ditar');
            const a = t1.closest('.campo-voz')?.querySelector('.alca-campo');
            if (!b || !a) return false;
            const rb = r(b), ra = r(a);
            return rb.right < ra.left || rb.left > ra.right
              || rb.bottom < ra.top || rb.top > ra.bottom;
          })(),
          // A alça existe e está no canto de baixo à direita da caixa.
          alcaNoCanto: (() => {
            const a = t1.closest('.campo-voz')?.querySelector('.alca-campo');
            if (!a) return false;
            const c = r(t1), ra = r(a);
            return ra.right <= c.right + 2 && ra.right > c.right - 40
              && ra.bottom <= c.bottom + 2 && ra.bottom > c.bottom - 40;
          })(),
          // E cabe INTEIRO dentro da caixa: elevá-lo num campo de uma linha o
          // fazia transbordar por cima da borda de cima.
          micDentro: (() => {
            const b = t1.closest('.campo-voz')?.querySelector('.btn-ditar');
            return !!b && r(b).top >= r(t1).top - 1 && r(b).bottom <= r(t1).bottom + 1;
          })(),
          resize: getComputedStyle(t1).resize,
        };
      });
      // "Compacto" é medido, e a medida tem duas pontas. Piso: nunca menos que
      // as `rows` declaradas. Teto: nunca já nascer no limite de cinco linhas,
      // que é o que tornaria a palavra "compacto" mentira.
      // Entre as duas pontas há UMA linha de folga, e ela existe por um motivo
      // real: o Chrome conta o texto de exemplo no `scrollHeight`, então o
      // campo nasce com duas linhas quando o exemplo quebra — no celular, onde
      // a largura é um terço da do computador, é o que acontece. É o
      // comportamento certo: com o piso rígido, a segunda linha do exemplo
      // ficaria escondida atrás de uma barra de rolagem no campo VAZIO.
      t(`${l} "O quê?" nasce compacto, sem rolagem e longe do teto`,
        texto.compacto >= texto.umaLinha
        && texto.compacto <= texto.umaLinha + texto.alturaLinha
        && texto.compacto < texto.teto5 && !texto.rolando, JSON.stringify(texto));
      // A seta de aumentar o campo é a MESMA das frentes e dos projetos (▾/▴),
      // e fica no canto superior direito, na linha do rótulo.
      t(`${l} a seta de aumentar o campo fica no canto superior direito`,
        texto.seta === '▾' && texto.setaADireita && texto.setaAcima, JSON.stringify(texto));
      // O microfone é o padrão do sistema: DENTRO da caixa, no canto inferior
      // direito. Foi de lá que ele saiu por engano, e é para lá que voltou.
      t(`${l} o microfone fica dentro da caixa de texto, embaixo à direita`,
        texto.micNaCaixa && !texto.micNaBarra && texto.micEmBaixo && texto.micDentro,
        JSON.stringify(texto));
      // A alça é NOSSA (`.alca-campo`), movida por eventos de ponteiro: a
      // nativa (`resize: vertical`) não existe no celular — o iOS não a desenha
      // nem responde ao arraste —, e por isso ela fica desligada. Duas alças no
      // mesmo canto deixariam a de baixo inalcançável.
      t(`${l} a alça de altura fica no canto, sem o microfone por cima`,
        texto.alcaNoCanto && texto.alcaLivre && texto.resize === 'none', JSON.stringify(texto));

      // Existir no canto não prova que ela ESTICA o campo: o arraste é o
      // comportamento, e ele quebra em silêncio (basta o flex do modal voltar a
      // mandar na altura, ou o `pointerdown` cair noutro elemento).
      const alcaCampo = await page.evaluate(() => {
        const t1 = document.getElementById('campo-o_que');
        const a = t1.closest('.campo-voz').querySelector('.alca-campo');
        const r = a.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2,
          antes: Math.round(t1.getBoundingClientRect().height) };
      });
      await page.mouse.move(alcaCampo.x, alcaCampo.y);
      await page.mouse.down();
      for (let i = 1; i <= 6; i++) await page.mouse.move(alcaCampo.x, alcaCampo.y + (60 * i) / 6);
      await page.mouse.up();
      await new Promise((r) => setTimeout(r, 250));
      const depoisDaAlca = await page.evaluate(() =>
        Math.round(document.getElementById('campo-o_que').getBoundingClientRect().height));
      t(`${l} arrastar a alça estica o campo de texto`,
        depoisDaAlca >= alcaCampo.antes + 50, `${alcaCampo.antes} → ${depoisDaAlca}`);

      // E o "ver menos" DESFAZ a altura arrastada: é a única saída de quem
      // esticou demais, e sem ela o botão não tinha efeito nenhum justamente em
      // quem mais mexe no campo. (Também devolve o campo ao automático para as
      // provas seguintes, que medem o crescimento com o texto.)
      await page.click(`[data-alvo="campo-o_que"]`);
      await new Promise((r) => setTimeout(r, 200));
      await page.click(`[data-alvo="campo-o_que"]`);
      await new Promise((r) => setTimeout(r, 200));
      const depoisDoRecolher = await page.evaluate(() =>
        Math.round(document.getElementById('campo-o_que').getBoundingClientRect().height));
      t(`${l} "ver menos" devolve o campo à altura padrão`,
        depoisDoRecolher === alcaCampo.antes, `${depoisDaAlca} → ${depoisDoRecolher} (padrão ${alcaCampo.antes})`);

      // Existir na tela não prova que o botão continua ligado no ditado: a
      // prova é o toque acender e apagar.
      const ditado = await page.evaluate(() => {
        const b = document.querySelector('.campo-voz .btn-ditar[data-alvo="campo-o_que"]');
        b.click();
        const ligado = b.classList.contains('gravando') && Modal.botaoGravando === b;
        b.click();
        return { ligado, desligado: !b.classList.contains('gravando') && !Modal.botaoGravando };
      });
      t(`${l} o microfone dentro do campo liga e desliga o ditado`,
        ditado.ligado && ditado.desligado, JSON.stringify(ditado));

      // Cresce com o texto até o teto de cinco linhas, e ali passa a rolar
      const crescer = await page.evaluate(async () => {
        const t1 = document.getElementById('campo-o_que');
        const antes = Math.round(t1.getBoundingClientRect().height);
        t1.value = Array.from({ length: 12 }, (_, i) => `linha ${i} do texto`).join('\n');
        t1.dispatchEvent(new Event('input', { bubbles: true }));
        const cheio = Math.round(t1.getBoundingClientRect().height);
        const rola = t1.style.overflowY;
        const btn = document.querySelector('.btn-expandir[data-alvo="campo-o_que"]');
        btn.click();
        const aberto = Math.round(t1.getBoundingClientRect().height);
        const marca = btn.getAttribute('aria-expanded');
        const setaAberta = btn.textContent.trim();
        btn.click();
        return { antes, cheio, aberto, rola, marca, setaAberta,
          setaFechada: btn.textContent.trim(),
          voltou: Math.round(t1.getBoundingClientRect().height) };
      });
      t(`${l} o campo cresce com o texto e para no teto de linhas`,
        crescer.cheio > crescer.antes && crescer.rola === 'auto', JSON.stringify(crescer));
      t(`${l} a seta aumenta o campo além do teto e devolve ao compacto`,
        crescer.aberto > crescer.cheio && crescer.marca === 'true'
        && crescer.voltou === crescer.cheio, JSON.stringify(crescer));
      // A seta VIRA ao aumentar: apontando para baixo ela cresce, para cima
      // encolhe. Sem virar, o mesmo desenho diria as duas coisas.
      t(`${l} a seta vira para cima com o campo aberto`,
        crescer.setaAberta === '▴' && crescer.setaFechada === '▾', JSON.stringify(crescer));

      // ── Ganhos previstos: número e só número ────────────────────────────
      // Digitação de verdade (`type`), não `.value =`: é o `beforeinput` que
      // filtra, e atribuir o valor por script passaria por cima dele — a prova
      // ficaria verde com o filtro desligado.
      await page.click('#campo-quanto');
      await page.type('#campo-quanto', '1e5-00abc');
      const dinheiro1 = await page.inputValue('#campo-quanto');
      await page.fill('#campo-quanto', '');
      await page.type('#campo-quanto', '1500.50');
      const dinheiro2 = await page.inputValue('#campo-quanto');
      const coletado = await page.evaluate(() => Modal.coletar().quanto);
      t(`${l} o campo de ganhos recusa letra, sinal e notação científica`,
        dinheiro1 === '1500', dinheiro1);
      t(`${l} o ponto vira vírgula e o valor sai como número`,
        dinheiro2 === '1500,50' && coletado === 1500.5, `${dinheiro2} → ${coletado}`);

      // COLAR é outro caminho, e o mais provável na vida real: o valor vem de
      // uma planilha, formatado. O filtro precisa ler "R$ 1.234,56" como
      // 1234,56 — recusar por causa do ponto de milhar esvaziava o campo sem
      // dizer por quê. E precisa recusar o negativo INTEIRO: colar "-99" e ver
      // 99 seria o campo mentindo sobre o que recebeu.
      const colar = [];
      for (const bruto of ['R$ 1.234,56 reais', '-99', '12.3456']) {
        await page.evaluate((txt) => {
          const i = document.getElementById('campo-quanto');
          i.value = '';
          i.focus();
          return navigator.clipboard.writeText(txt);
        }, bruto);
        await page.keyboard.press('Control+V');
        await new Promise((r) => setTimeout(r, 120));
        colar.push(await page.inputValue('#campo-quanto'));
      }
      t(`${l} colar um valor de planilha entra como número em português`,
        colar[0] === '1234,56', JSON.stringify(colar));
      t(`${l} colar negativo ou com mais de dois centavos é recusado`,
        colar[1] === '' && colar[2] === '', JSON.stringify(colar));

      // Com o modal aberto, a página não pode passar a rolar na horizontal
      const rolaH = await page.evaluate(() =>
        document.documentElement.scrollWidth > window.innerWidth + 1);
      t(`${l} o formulário da ação não rola a página na horizontal`, !rolaH);

      // ── O caminho de verdade: preencher e clicar em Salvar ──────────────
      // Tudo acima mede o formulário PARADO. Esta é a única prova que passa
      // por `transformarAcao`, que é quem traduz as duas grades da tela na
      // única `recorrencia_dias` que o servidor conhece. Escrevendo direto na
      // API (o que o resto da bateria faz), essa tradução nunca é exercitada —
      // e ela é justamente a peça que este trabalho reescreveu.
      const salvou = await page.evaluate(async () => {
        const pv = (id, v) => {
          const el = document.getElementById(`campo-${id}`);
          el.value = v;
          // O "Quem?" guarda o nome num hidden, que não emite evento sozinho
          el.dispatchEvent(new Event('change', { bubbles: true }));
        };
        pv('o_que', 'Ação salva pela tela');
        pv('como', 'Pelo formulário, como um usuário faria');
        pv('quem', 'QA da bateria');
        document.getElementById('campo-recorrencia').value = 'SEMANAL';
        document.getElementById('campo-recorrencia')
          .dispatchEvent(new Event('change', { bubbles: true }));
        await new Promise((r) => setTimeout(r, 150));
        const g = document.getElementById('campo-recorrencia_dias_semana');
        [...g.querySelectorAll('input')].filter((c) => c.checked).forEach((c) => c.click());
        g.querySelector('input[value="3"]').click();
        g.querySelector('input[value="6"]').click();
        pv('recorrencia_ate', '2027-12-31');
        document.getElementById('modal-salvar').click();
        return true;
      });
      t(`${l} o Salvar do formulário responde`, salvou);
      const fechouSozinho = await esperar(
        page, "!document.getElementById('modal-form').classList.contains('show')", 8000);
      const erro = await page.evaluate(() => {
        const e = document.getElementById('modal-erro');
        return e.classList.contains('d-none') ? '' : e.textContent.trim();
      });
      t(`${l} salvar pela tela é aceito pelo servidor`, fechouSozinho && erro === '', erro);
      const gravada = await page.evaluate(async (id) => {
        const projetos = await App.api('/api/projetos?planejamento_id=1');
        const p = projetos.find((x) => x.id === id);
        const a = (p?.iniciativas[0].acoes || []).find((x) => x.o_que === 'Ação salva pela tela');
        return a ? { dias: a.recorrencia_dias, tipo: a.recorrencia, fim: a.data_fim } : null;
      }, prj);
      t(`${l} as duas fichas marcadas na tela chegam inteiras ao banco`,
        gravada?.dias === '3,6' && gravada.tipo === 'SEMANAL', JSON.stringify(gravada));
    }
    await fecharModal(page);
  }

  // ── O vaivém completo: gravar uma rotina de dois dias e reabri-la ───────
  // O ida-e-volta é o que prova que a grade sobrevive ao banco: o servidor
  // guarda um CSV e devolve `recorrencia_dias`, e a tela precisa remarcar as
  // fichas a partir dele. Sem esta prova, salvar dois dias e reabrir com um
  // só passaria despercebido até alguém reclamar do prazo errado.
  const acao = await page.evaluate(async (id) => {
    const projetos = await App.api(`/api/projetos?planejamento_id=1`);
    const p = projetos.find((x) => x.id === id);
    return App.api('/api/desdobramentos', {
      planejamento_id: 1, projeto_id: id, iniciativa_id: p.iniciativas[0].id,
      o_que: 'Rotina de duas pontas', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'SEMANAL',
      recorrencia_dias: [2, 5], recorrencia_ate: '2027-12-31',
    });
  }, prj);
  const voltou = await page.evaluate(async (ids) => {
    const projetos = await App.api(`/api/projetos?planejamento_id=1`);
    const p = projetos.find((x) => x.id === ids.prj);
    const dd = p.iniciativas[0].acoes.find((a) => a.id === ids.acao);
    SecaoProjetos.modalDesdobramento(ids.prj, dd, p.iniciativas[0].id);
    return dd;
  }, { prj, acao: acao.id });
  await esperar(page, "!!document.getElementById('campo-recorrencia_dias_semana')");
  await new Promise((r) => setTimeout(r, 300));
  const remarcou = await page.evaluate(() => Modal.coletar().recorrencia_dias_semana);
  t(`${l} a rotina de dois dias volta com as duas fichas marcadas`,
    JSON.stringify(remarcou) === '[2,5]', `${JSON.stringify(remarcou)} — ${voltou.recorrencia_dias}`);
  // A data de vencimento é DERIVADA da grade e gravada: é dela que vivem o
  // atraso automático, os avisos por e-mail e o prazo consolidado do projeto.
  t(`${l} o servidor deriva a data de vencimento da grade`,
    /^\d{4}-\d{2}-\d{2}$/.test(String(voltou.data_fim || '')), String(voltou.data_fim));
  await fecharModal(page);

  await page.evaluate((id) => App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 }), prj);
}

/**
 * Dossiê do plano — as etapas em sequência, por negócio.
 *
 * Ele pinta as outras seções DE LADO para fotografá-las, e é isso que precisa
 * de prova: pintar de lado mexe no contexto do aplicativo e nos filtros de cada
 * seção, que moram no objeto dela e sobrevivem à repintura. Se algum ficar para
 * trás, o defeito não aparece aqui — aparece na tela seguinte que a pessoa
 * abrir, com o negócio errado ou com um filtro que ela não pôs.
 *
 * As três coisas que a prova mede, nesta ordem de importância:
 *
 *  1. o que a pessoa tinha na tela VOLTA (contexto, ano, filtros, recolhidos);
 *  2. o filtro de quem clicou NÃO vai para o papel — o documento é o plano
 *     inteiro, e um dossiê filtrado em silêncio é o pior resultado possível
 *     numa prestação de contas;
 *  3. a pintura de lado não arma relógio de polling nenhum (`App.modoDossie`).
 */
async function provasDossie(page) {
  const l = '[desktop] Dossiê:';
  await page.evaluate(() => {
    window.__timers = 0;
    const orig = window.setInterval;
    window.setInterval = function (...a) { window.__timers += 1; return orig.apply(this, a); };
  });
  await page.evaluate(() => App.mostrarSecao('dossie'));
  await esperar(page, "!!document.querySelector('#secao-dossie [data-dossie-montar]')", 15000);

  // Sujeira de propósito, nos três lugares que guardam vista: a busca da SWOT,
  // o filtro de Projetos e os projetos recolhidos.
  const antes = await page.evaluate(() => {
    Diag.busca.SWOT = 'zzz-nao-existe';
    SecaoProjetos.filtroStatus = 'CONCLUIDO';
    SecaoProjetos.projetosFechados = new Set([1, 2]);
    return { ctx: JSON.stringify(App.contexto), ano: Diag.anoSelecionado, timers: window.__timers };
  });

  const doc = await page.evaluate(async () => {
    const el = document.getElementById('secao-dossie');
    const marcar = (nome, valores) => el.querySelectorAll(`[data-dossie-${nome}]`).forEach((c) => {
      c.checked = valores.includes(c.value);
      c.dispatchEvent(new Event('change', { bubbles: true }));
    });
    // Dois negócios, para provar a troca de contexto; duas etapas, para provar
    // a quebra entre elas. Mais que isso é tempo de bateria sem prova nova.
    const alvos = [...el.querySelectorAll('[data-dossie-alvo]')].map((c) => c.value).slice(0, 2);
    marcar('alvo', alvos);
    marcar('etapa', ['swot', 'cascata']);
    el.querySelector('[data-dossie-montar]').click();
    for (let i = 0; i < 400; i++) {
      if (el.querySelector('[data-dossie-imprimir]')) break;
      await new Promise((r) => setTimeout(r, 150));
    }
    const d = el.querySelector('[data-dossie-documento]');
    const cards = d.querySelectorAll('[data-card-fator]');
    return {
      pronto: !!el.querySelector('[data-dossie-imprimir]'),
      negocios: d.querySelectorAll('.dossie-negocio').length,
      capas: d.querySelectorAll('.dossie-capa').length,
      etapas: d.querySelectorAll('.dossie-etapa').length,
      falhas: d.querySelectorAll('.dossie-falha').length,
      ids: d.querySelectorAll('[id]').length,
      cards: cards.length,
      escondidos: [...cards].filter((c) => c.classList.contains('d-none')).length,
    };
  });
  t(`${l} monta o documento`, doc.pronto);
  t(`${l} um bloco e uma capa por negócio`, doc.negocios === 2 && doc.capas === 2,
    `${doc.negocios} blocos, ${doc.capas} capas`);
  t(`${l} negócios × etapas`, doc.etapas === 4, `${doc.etapas}`);
  t(`${l} nenhuma etapa falhou`, doc.falhas === 0, `${doc.falhas}`);
  // A foto duplicaria todo `id` da seção. A partir daí `getElementById` pode
  // cair na cópia morta em vez do elemento vivo — defeito que só apareceria
  // depois, longe daqui, e sem sintoma que aponte para o dossiê.
  t(`${l} a foto não duplica id nenhum`, doc.ids === 0, `${doc.ids} ids`);
  t(`${l} o filtro de quem clicou não filtrou o documento`,
    doc.cards > 0 && doc.escondidos === 0, `${doc.cards} cartões, ${doc.escondidos} escondidos`);

  const depois = await page.evaluate(() => ({
    ctx: JSON.stringify(App.contexto),
    modo: App.modoDossie,
    busca: Diag.busca.SWOT,
    ano: Diag.anoSelecionado,
    projStatus: SecaoProjetos.filtroStatus,
    projFechados: [...SecaoProjetos.projetosFechados].join(','),
    timers: window.__timers,
  }));
  t(`${l} o contexto do menu volta`, depois.ctx === antes.ctx, `${antes.ctx} → ${depois.ctx}`);
  t(`${l} o modo "só desenho" fica desligado`, depois.modo === false);
  t(`${l} a busca da SWOT volta`, depois.busca === 'zzz-nao-existe', String(depois.busca));
  t(`${l} o ano do diagnóstico volta`, depois.ano === antes.ano, `${antes.ano} → ${depois.ano}`);
  t(`${l} o filtro de Projetos volta`, depois.projStatus === 'CONCLUIDO', String(depois.projStatus));
  t(`${l} os projetos recolhidos voltam`, depois.projFechados === '1,2', depois.projFechados);
  t(`${l} a pintura de lado não arma relógio`, depois.timers === antes.timers,
    `${antes.timers} → ${depois.timers}`);

  // No papel: a montagem é comando e sai; o documento entra; e comando nenhum
  // sobra — com as DUAS exceções declaradas, que são conteúdo desenhado como
  // botão (o par do cruzamento e o "Virou ação ↗").
  await page.emulateMedia({ media: 'print' });
  const papel = await page.evaluate(() => {
    const el = document.getElementById('secao-dossie');
    const d = el.querySelector('[data-dossie-documento]');
    // Um filho de `display:none` devolve o display DELE em `getComputedStyle`,
    // não "none": sem subir a árvore, tudo dentro de um bloco oculto contaria
    // como visível.
    const vis = (n) => {
      if (!n) return false;
      for (let p = n; p && p !== document.body; p = p.parentElement) {
        if (getComputedStyle(p).display === 'none') return false;
      }
      return true;
    };
    const conteudo = (b) => b.classList.contains('selo-cruz-fator') || b.hasAttribute('data-ir-acao');
    return {
      montagem: vis(el.querySelector('.dossie-montagem')),
      documento: vis(d),
      capa: vis(d.querySelector('.dossie-capa')),
      comandos: [...d.querySelectorAll('button')].filter((b) => vis(b) && !conteudo(b))
        .map((b) => b.className).slice(0, 5),
      quebra: getComputedStyle(d.querySelector('.dossie-etapa')).breakBefore,
    };
  });
  await page.emulateMedia({ media: 'screen' });
  t(`${l} a tela de montagem não vai ao papel`, !papel.montagem);
  t(`${l} o documento e a capa vão`, papel.documento && papel.capa);
  t(`${l} comando nenhum sobra no documento`, papel.comandos.length === 0,
    JSON.stringify(papel.comandos));
  t(`${l} cada etapa abre uma folha`, papel.quebra === 'page', papel.quebra);

  // Devolve a vista limpa: a sujeira acima é desta prova, e as seguintes medem
  // busca e filtro de verdade.
  await page.evaluate(() => {
    Diag.busca = {};
    SecaoProjetos.filtroStatus = '';
    SecaoProjetos.projetosFechados = new Set();
    App.mostrarSecao('painel');
  });
}

/**
 * Matriz de Execução — a aba da Cascata que liga a DECISÃO ao que a mede e ao
 * que a executa (`indicador_cascata` + `projeto.cascata_id`).
 *
 * O que a prova mede, além de "a tabela pinta":
 *
 *  1. **A guarda de IDOR.** `Auth::exigirEdicaoPlanejamento` valida o
 *     planejamento, não os filhos: sem conferir cada escolha, quem edita o
 *     indicador de um negócio amarra escolhas de OUTRO passando o id. A prova
 *     cria uma escolha num segundo planejamento e tenta amarrá-la.
 *  2. **Salvar sem o campo não apaga o conjunto.** `salvar` é chamado por um
 *     modal que pode não ter a lista; tratar a ausência como "vazio" apagaria
 *     vínculos que ninguém mandou apagar.
 *  3. **O par meta × real sai da MESMA função da tela de Metas**
 *     (`SecaoMetas.metaReal`). Duas cópias diriam números diferentes do mesmo
 *     indicador em telas vizinhas.
 */
async function provasMatrizExecucao(page) {
  const l = '[desktop] Matriz de Execução:';

  const massa = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const c = await App.api(`/api/cascata?planejamento_id=${plan.id}`);
    const abertura = c.escolhas.find((e) => e.eixo_id);
    const sintese = c.escolhas.find((e) => !e.eixo_id);
    if (!abertura || !sintese) return { semCascata: true };
    const ind = await App.api('/api/indicadores', {
      planejamento_id: plan.id, nome: 'KPI de prova (matriz)', unidade: '%',
      sentido: 'MAIOR_MELHOR', metrica_ancora: 1, horizonte_id: '',
      cascatas: [String(abertura.id), String(sintese.id)],
    });
    await App.api(`/api/indicadores/${ind.id}/valores`, {
      planejamento_id: plan.id, tipo: 'META', valores: { 2027: 80 },
    });
    await App.api(`/api/indicadores/${ind.id}/valores`, {
      planejamento_id: plan.id, tipo: 'REAL', valores: { 2027: 85 },
    });
    const prj = await App.api('/api/projetos', {
      planejamento_id: plan.id, tipo: 'ESTRATEGICO', titulo: 'Projeto de prova (matriz)',
      ano: Number(c.horizontes[0].ano_inicio), responsavel: 'QA',
      cascata_id: abertura.id, classificacao: 'NORMAL',
    });
    return { plan: plan.id, ind: ind.id, prj: prj.id, abertura: abertura.id, sintese: sintese.id,
      horizonte: abertura.horizonte_id };
  });
  if (massa.semCascata) {
    ok.push(`${l} pulada — a base não tem escolhas da cascata`);
    return;
  }

  const fonte = await page.evaluate(async (m) => {
    const c = await App.api(`/api/cascata?planejamento_id=${m.plan}`);
    const i = (c.indicadores || []).find((x) => x.id == m.ind);
    const p = (c.projetos || []).find((x) => x.id == m.prj);
    return {
      cascatas: ((i || {}).cascatas || []).length,
      metas: ((i || {}).metas || []).length,
      reais: ((i || {}).reais || []).length,
      projetoNaEscolha: p ? Number(p.cascata_id) === Number(m.abertura) : false,
      soComEscolha: (c.projetos || []).every((x) => x.cascata_id),
    };
  }, massa);
  t(`${l} o indicador volta com as duas escolhas e as séries`,
    fonte.cascatas === 2 && fonte.metas === 1 && fonte.reais === 1,
    `${fonte.cascatas} escolhas, ${fonte.metas} metas, ${fonte.reais} reais`);
  t(`${l} o projeto vem na escolha que executa`, fonte.projetoNaEscolha);
  t(`${l} projeto sem escolha fica fora da fonte`, fonte.soComEscolha);

  // 1. IDOR: uma escolha de outro planejamento não pode ser amarrada aqui.
  const idor = await page.evaluate(async (m) => {
    const outro = await App.api('/api/contexto?ciclo_id=' + App.contexto.cicloId
      + '&negocio_id=' + (App.sessao.negocios[0] || {}).id).catch(() => null);
    if (!outro || !outro.planejamento || outro.planejamento.id == m.plan) return { pulada: true };
    const alheia = await App.api('/api/cascata', {
      planejamento_id: outro.planejamento.id,
      horizonte_id: (await App.api(`/api/cascata?planejamento_id=${outro.planejamento.id}`)).horizontes[0].id,
      driver_id: (await App.api(`/api/cascata?planejamento_id=${outro.planejamento.id}`)).drivers[0].id,
      eixo_id: '', escolha: 'Escolha de prova de outro negócio', renuncia: '',
    }).catch(() => null);
    if (!alheia) return { pulada: true };
    await App.api(`/api/indicadores/${m.ind}`, {
      planejamento_id: m.plan, nome: 'KPI de prova (matriz)', unidade: '%',
      sentido: 'MAIOR_MELHOR', metrica_ancora: 1, horizonte_id: '',
      cascatas: [String(m.abertura), String(m.sintese), String(alheia.id)],
    });
    const d = await App.api(`/api/indicadores?planejamento_id=${m.plan}`);
    const gravados = (d.indicadores.find((x) => x.id == m.ind).cascatas || []).map(Number);
    await App.api(`/api/cascata/${alheia.id}/excluir`, { planejamento_id: outro.planejamento.id })
      .catch(() => {});
    return { intruso: Number(alheia.id), gravados };
  }, massa);
  if (idor.pulada) {
    ok.push(`${l} IDOR pulada — não há um segundo planejamento com cascata`);
  } else {
    t(`${l} escolha de outro planejamento NÃO é amarrada`,
      !idor.gravados.includes(idor.intruso), `intruso ${idor.intruso} em [${idor.gravados}]`);
    t(`${l} as escolhas legítimas continuam amarradas`, idor.gravados.length === 2,
      `${idor.gravados.length}`);
  }

  // 2. Salvar sem a chave `cascatas` não pode limpar o conjunto.
  const semChave = await page.evaluate(async (m) => {
    await App.api(`/api/indicadores/${m.ind}`, {
      planejamento_id: m.plan, nome: 'KPI de prova (matriz)', unidade: '%',
      sentido: 'MAIOR_MELHOR', metrica_ancora: 1, horizonte_id: '',
    });
    const d = await App.api(`/api/indicadores?planejamento_id=${m.plan}`);
    return (d.indicadores.find((x) => x.id == m.ind).cascatas || []).length;
  }, massa);
  t(`${l} salvar sem o campo não apaga os vínculos`, semChave === 2, `${semChave}`);

  // 3. A tabela, e a regra do par meta × real.
  //
  // O horizonte é FIXADO no da escolha semeada, e não deixado no padrão: a
  // matriz mostra um horizonte por vez, e depender de qual abre por padrão
  // faria esta prova depender do estado que as anteriores deixaram — ela
  // ficaria vermelha por causa de outra tela.
  await page.evaluate((m) => {
    SecaoCascata.horizonteMatriz = Number(m.horizonte);
    App.mostrarSecao('cascata');
  }, massa);
  // A espera é pelo DADO, não pelo nó: `percorrer` já pintou esta seção no
  // início da bateria, e a aba de execução existe naquela pintura velha —
  // esperar por ela devolveria na hora, e a prova leria a tela de antes da massa.
  await esperar(page,
    "!!(SecaoCascata.dados && (SecaoCascata.dados.indicadores || [])"
    + ".some((x) => x.nome === 'KPI de prova (matriz)'))", 15000);
  await page.evaluate(() => document.querySelector('[data-aba-cascata="execucao"]').click());
  await esperar(page,
    "document.querySelector('#secao-cascata [data-painel-cascata=\"escolhas\"]').classList.contains('d-none')",
    10000);

  const matriz = await page.evaluate(() => {
    const el = document.getElementById('secao-cascata');
    const tab = el.querySelector('.tabela-execucao');
    const texto = tab.textContent.replace(/\s+/g, ' ');
    return {
      colunas: tab.querySelectorAll('thead th').length,
      raia1: (tab.querySelector('.celula-raia') || {}).textContent?.trim(),
      temKpi: texto.includes('KPI de prova (matriz)'),
      temProjeto: texto.includes('Projeto de prova (matriz)'),
      // Último real (85, de 2027) contra a meta do MESMO ano (80) — a regra de
      // `SecaoMetas.metaReal`, e não "a meta do ano corrente"
      temPar: /85(,00)? \(2027\) \/ meta 80/.test(texto),
      atingiu: !!tab.querySelector('.text-success.fw-bold'),
      seletor: !!el.querySelector('#sel-horizonte-matriz'),
      regra: (() => {
        const r = SecaoMetas.metaReal({
          sentido: 'MAIOR_MELHOR', metas: [{ ano: 2027, valor: 80 }], reais: [{ ano: 2027, valor: 85 }],
        });
        return `${r.ano}|${Number(r.meta.valor)}|${r.atingiu}`;
      })(),
    };
  });
  t(`${l} a tabela tem as cinco colunas`, matriz.colunas === 5, `${matriz.colunas}`);
  t(`${l} a síntese é a primeira raia`, matriz.raia1 === 'Síntese da célula', String(matriz.raia1));
  t(`${l} mostra o indicador amarrado`, matriz.temKpi);
  t(`${l} mostra o projeto que executa`, matriz.temProjeto);
  t(`${l} o par meta × real segue a regra da tela de Metas`, matriz.temPar);
  t(`${l} meta batida sai em verde`, matriz.atingiu);
  t(`${l} tem seletor de horizonte`, matriz.seletor);
  t(`${l} a regra vem de SecaoMetas.metaReal`, matriz.regra === '2027|80|true', matriz.regra);

  // O cabeçalho grudado. A prova é feita ROLADA até o fim: parada no topo, uma
  // tabela qualquer parece ter cabeçalho fixo, e o defeito só aparece depois de
  // umas centenas de pixels — que foi exatamente como ele chegou.
  const fixo = await page.evaluate(async () => {
    window.scrollTo(0, document.body.scrollHeight);
    await new Promise((r) => setTimeout(r, 200));
    const th = document.querySelector('.tabela-execucao thead th');
    const caixa = document.querySelector('.caixa-execucao');
    const tab = document.querySelector('.tabela-execucao');
    return {
      rolou: Math.round(window.scrollY),
      topoTh: Math.round(th.getBoundingClientRect().top),
      baseTopbar: Math.round(document.querySelector('.topbar').getBoundingClientRect().bottom),
      // Se a caixa voltar a rolar sozinha, o `sticky` gruda no topo DELA — que
      // sai da tela junto com a página — e o cabeçalho some sem erro nenhum.
      caixaRola: getComputedStyle(caixa).overflowY !== 'visible',
      // `separate` é o que torna o cabeçalho opaco: com `collapse` o texto das
      // células passa POR CIMA do fundo dele.
      colapso: getComputedStyle(tab).borderCollapse,
      rolaHorizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    };
  });
  t(`${l} a página rolou de verdade antes de medir`, fixo.rolou > 300, `${fixo.rolou}`);
  t(`${l} o cabeçalho fica grudado logo abaixo da topbar`,
    fixo.topoTh === fixo.baseTopbar, JSON.stringify(fixo));
  t(`${l} a caixa não rola sozinha — senão o grudado sai com ela`,
    fixo.caixaRola === false, JSON.stringify(fixo));
  t(`${l} bordas separadas, para o cabeçalho ser opaco`,
    fixo.colapso === 'separate', fixo.colapso);
  t(`${l} e a página continua sem rolagem horizontal`, fixo.rolaHorizontal === false);

  // Os dois atalhos: a tabela é leitura, e o vínculo se faz na tela de origem.
  const atalhos = await page.evaluate(() => {
    const bs = [...document.querySelectorAll('#secao-cascata .como-amarrar [data-ir]')];
    return { alvos: bs.map((b) => b.dataset.ir),
      texto: document.querySelector('#secao-cascata .como-amarrar').textContent.replace(/\s+/g, ' ') };
  });
  t(`${l} o aviso leva às duas telas onde o vínculo se faz`,
    atalhos.alvos.join() === 'metas,projetos', JSON.stringify(atalhos.alvos));
  t(`${l} e nomeia os campos exatos dos dois formulários`,
    /Escolhas da cascata que este indicador mede/.test(atalhos.texto)
    && /Escolha da Cascata que este projeto executa/.test(atalhos.texto), atalhos.texto.slice(0, 200));
  await page.evaluate(() =>
    document.querySelector('#secao-cascata .como-amarrar [data-ir="metas"]').click());
  await esperar(page, "!document.getElementById('secao-metas').classList.contains('d-none')", 10000);
  t(`${l} o atalho de Metas abre mesmo a tela de Metas`, true);

  await page.evaluate(async (m) => {
    await App.api(`/api/projetos/${m.prj}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    await App.api(`/api/indicadores/${m.ind}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    SecaoCascata.aba = 'escolhas';
    App.mostrarSecao('painel');
  }, massa);
}

/**
 * Excluir o que já está amarrado noutra tela — o aviso ANTES do clique.
 *
 * O que esta prova mede é a TELA. A recusa em si é regra de servidor e já tem
 * bateria própria em `funcional.sh` ("recusa excluir o fator do par que virou
 * ação"); repeti-la aqui só encheria o console de 400 deliberados, que o
 * `vigiar` conta como erro de página.
 *
 * A massa monta o caminho da trava que a tela sozinha NÃO enxergaria: um fator
 * do PESTEL promovido à SWOT, encaminhado e virado ação. Quem olhasse só o
 * `desdobramento_id` do fator pedido acharia os dois livres — e a promoção
 * continua sendo o caminho mais andado, mesmo depois de o PESTEL passar a
 * poder ir direto ao plano.
 */
async function provasExclusaoComVinculo(page) {
  const l = '[desktop] Exclusão com vínculo:';

  const massa = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const ano = Diag.ano();
    const pestel = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PESTEL',
      categoria: 'ECONOMICO', descricao: 'Fator PESTEL de prova (vínculo)', ano });
    const prom = await App.api(`/api/fatores/${pestel.id}/promover`,
      { planejamento_id: plan.id, quadrante: 'AMEACA' });
    const solto = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'SWOT',
      categoria: 'FORCA', descricao: 'Fator SWOT solto de prova (vínculo)', ano });
    const prj = await App.api('/api/projetos', { planejamento_id: plan.id, tipo: 'ESTRATEGICO',
      titulo: 'Projeto de prova (vínculo)', ano: 2027, responsavel: 'QA' });
    await App.api(`/api/fatores/${prom.id}/plano-acao`, { planejamento_id: plan.id, marcar: true });
    await App.api('/api/desdobramentos', {
      planejamento_id: plan.id, projeto_id: prj.id, iniciativa_nova: 'Frente de prova (vínculo)',
      o_que: 'Ação de prova (vínculo)', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31', fator_id: prom.id,
    });
    return { plan: plan.id, ano, pestel: pestel.id, prom: prom.id, solto: solto.id, prj: prj.id };
  });

  // A trava sai da mesma consulta da recusa, e cobre o promovido E a origem.
  const trava = await page.evaluate(async (m) => {
    const pestel = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=PESTEL&ano=${m.ano}`);
    const swot = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=SWOT&ano=${m.ano}`);
    const acha = (lista, id) => lista.find((f) => f.id == id) || {};
    return {
      promovido: acha(swot, m.prom).acao_trava,
      origem: acha(pestel, m.pestel).acao_trava,
      solto: acha(swot, m.solto).acao_trava,
    };
  }, massa);
  t(`${l} o fator promovido que virou ação vem travado`,
    /Ação de prova \(vínculo\)/.test(trava.promovido || ''), String(trava.promovido));
  t(`${l} a ORIGEM no PESTEL também — o DELETE dela leva o promovido`,
    /Ação de prova \(vínculo\)/.test(trava.origem || ''), String(trava.origem));
  t(`${l} fator sem ação NÃO vem travado`, !trava.solto, String(trava.solto));

  // A tela obedece à trava: desabilita, tira a ação do botão e diz o porquê.
  for (const [secao, alvo, rotulo] of [['swot', 'prom', 'SWOT'], ['pestel', 'pestel', 'PESTEL']]) {
    await page.evaluate((s) => App.mostrarSecao(s), secao);
    // Espera o CARTÃO da massa, não um cartão qualquer: `percorrer` já pintou
    // estas seções no início da bateria, e um seletor genérico casaria com a
    // pintura velha — de antes de o fator existir.
    await esperar(page,
      `!!document.querySelector('#secao-${secao} [data-card-fator="${massa[alvo]}"]')`, 15000);
    const b = await page.evaluate((x) => {
      const btn = document.querySelector(
        `#secao-${x.secao} [data-card-fator="${x.id}"] .btn-outline-danger`);
      if (!btn) return null;
      return {
        off: btn.disabled,
        semAcao: !btn.hasAttribute('data-excluir'),
        motivo: /Exclua a ação em Projetos/.test(btn.title),
        // Sem ponteiro o navegador não mostra `title` nenhum, e o Bootstrap o
        // desliga em todo `.btn:disabled` — o botão ficaria cinzento e mudo.
        ponteiro: getComputedStyle(btn).pointerEvents,
      };
    }, { secao, id: massa[alvo] });
    t(`${l} ${rotulo} desabilita o × travado, sem ação pendurada`,
      !!b && b.off === true && b.semAcao === true, JSON.stringify(b));
    t(`${l} ${rotulo} diz o motivo e o que fazer, com o ponteiro alcançando`,
      !!b && b.motivo === true && b.ponteiro === 'auto', JSON.stringify(b));
  }
  const controle = await page.evaluate((m) => {
    const btn = document.querySelector(`#secao-swot [data-card-fator="${m.solto}"] .btn-outline-danger`);
    return btn ? !btn.disabled && btn.hasAttribute('data-excluir') : null;
  }, massa);
  t(`${l} o × do fator sem vínculo continua ativo`, controle === true, String(controle));

  // As contagens que alimentam o `confirm()` vêm das listagens que já existem.
  const contagens = await page.evaluate(async (m) => {
    const c = await App.api(`/api/cascata?planejamento_id=${m.plan}`);
    const p = await App.api(`/api/projetos?planejamento_id=${m.plan}`);
    const i = await App.api(`/api/investimentos?planejamento_id=${m.plan}`);
    const proj = p.find((x) => x.id == m.prj) || {};
    return {
      escolha: c.escolhas.length ? 'comentarios' in c.escolhas[0] : null,
      projeto: 'investimentos_vinculados' in proj && 'comentarios' in proj,
      acoes: (proj.desdobramentos || []).length,
      invest: i.investimentos.length ? 'comentarios' in i.investimentos[0] : null,
    };
  }, massa);
  t(`${l} a escolha da cascata traz os comentários`, contagens.escolha !== false);
  t(`${l} o projeto traz investimentos soltos e comentários`, contagens.projeto === true);
  t(`${l} o projeto traz as ações que saem junto`, contagens.acoes === 1, `${contagens.acoes}`);
  t(`${l} o investimento traz os comentários`, contagens.invest !== false);

  // A frase: separa o que SAI do que fica sem o vínculo, e some quando não há
  // vínculo nenhum — "Sai junto: ." é pior que não dizer nada.
  const frases = await page.evaluate(() => ({
    vazia: Vinculos.aviso('Excluir?', { some: [Vinculos.quantos(0, 'comentário', 'comentários')] }),
    cheia: Vinculos.aviso('Excluir?', {
      some: [Vinculos.quantos(3, 'comentário', 'comentários'), Vinculos.quantos(2, 'ação', 'ações')],
      solta: [Vinculos.quantos(1, 'investimento', 'investimentos')],
    }),
    singular: Vinculos.quantos(1, 'voz da sala', 'vozes da sala'),
  }));
  t(`${l} sem vínculo a frase é só a pergunta`, frases.vazia === 'Excluir?', frases.vazia);
  t(`${l} separa o que sai do que fica sem o vínculo`,
    /Sai junto: 3 comentários e 2 ações\./.test(frases.cheia)
    && /Continua existindo, sem o vínculo: 1 investimento\./.test(frases.cheia), frases.cheia);
  t(`${l} o singular é respeitado`, frases.singular === '1 voz da sala', frases.singular);

  // Limpeza, nesta ordem: a ação é quem trava o resto.
  await page.evaluate(async (m) => {
    await App.api(`/api/projetos/${m.prj}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    for (const id of [m.pestel, m.solto]) {
      await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    }
    App.mostrarSecao('painel');
  }, massa);
}

/**
 * PESTEL e Porter indo DIRETO ao plano de ação.
 *
 * Até 2026-08 o servidor recusava com "só fatores da SWOT vão ao plano de
 * ação", e a fila só sabia dizer `origem: 'SWOT'`. A regra caiu por decisão do
 * cliente, e o que precisa ficar provado é a corrente inteira: o encaminhamento
 * é aceito, a fila declara a ETAPA (para o selo saber o rótulo), a ação criada
 * FECHA o vínculo pelo mesmo `fator_id` — e as duas recusas que não mudaram
 * (desmarcar e excluir depois da ação) continuam de pé.
 *
 * O fechamento do vínculo é o ponto que mais importa: era ele que o filtro
 * `etapa = 'SWOT'` do ProjetoController deixava passar em silêncio. Sem ele o
 * fator ficaria "aguardando ação" para sempre numa fila da qual já saiu.
 */
async function provasPlanoDiretoAnalise(page) {
  const l = '[desktop] Plano de ação direto:';

  const massa = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const ano = Diag.ano();
    const pestel = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PESTEL',
      categoria: 'LEGAL', descricao: 'Fator PESTEL de prova (direto)', ano });
    const porter = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PORTER',
      categoria: 'SUBSTITUTOS', descricao: 'Fator Porter de prova (direto)', ano });
    // Sem promoção à SWOT no meio: é exatamente isso que se quer provar.
    const ida = await App.api(`/api/fatores/${pestel.id}/plano-acao`,
      { planejamento_id: plan.id, marcar: true }).then(() => true).catch((e) => e.message);
    await App.api(`/api/fatores/${porter.id}/plano-acao`, { planejamento_id: plan.id, marcar: true });
    return { plan: plan.id, ano, pestel: pestel.id, porter: porter.id, ida };
  });
  t(`${l} o PESTEL é aceito no plano sem passar pela SWOT`, massa.ida === true, String(massa.ida));

  const fila = await page.evaluate(async (m) => {
    const linhas = await App.api(`/api/fatores/aguardando-acao?planejamento_id=${m.plan}`);
    const acha = (id) => linhas.find((x) => x.id == id) || {};
    return { pestel: acha(m.pestel).origem, porter: acha(m.porter).origem };
  }, massa);
  t(`${l} a fila declara a etapa, não o literal SWOT`,
    fila.pestel === 'PESTEL' && fila.porter === 'PORTER', JSON.stringify(fila));

  // O selo do card lê o catálogo da etapa: PESTEL/Porter têm categorias em
  // tuplas e a SWOT tem quadrantes. Um `if` a mais em cada tela era o caminho
  // para os rótulos divergirem, como já aconteceu com a Coleta.
  const selos = await page.evaluate(() => ({
    pestel: SecaoProjetos.rotuloCategoria('PESTEL', 'LEGAL'),
    porter: SecaoProjetos.rotuloCategoria('PORTER', 'PODER_CLIENTES'),
    swot: SecaoProjetos.rotuloCategoria('SWOT', 'AMEACA'),
    etapa: SecaoProjetos.rotuloEtapa('PORTER'),
    cor: SecaoProjetos.corCategoria('SWOT', 'FORCA'),
  }));
  t(`${l} o rótulo da categoria sai do catálogo de cada etapa`,
    selos.pestel === 'Legal' && selos.porter === 'Poder dos Clientes' && selos.swot === 'Ameaça',
    JSON.stringify(selos));
  t(`${l} Porter é sobrenome, não sigla, e a cor acompanha`,
    selos.etapa === 'Porter' && selos.cor === '#007a45', JSON.stringify(selos));

  // O botão do card do PESTEL — é a porta pela qual o usuário passa.
  await page.evaluate(() => App.mostrarSecao('pestel'));
  await esperar(page,
    `!!document.querySelector('#secao-pestel [data-card-fator="${massa.pestel}"]')`, 15000);
  const botao = await page.evaluate((id) => {
    const card = document.querySelector(`#secao-pestel [data-card-fator="${id}"]`);
    return card ? !!card.querySelector('[data-tirar-acao]') : null;
  }, massa.pestel);
  t(`${l} o card do PESTEL mostra o selo "Aguardando ação"`, botao === true, String(botao));

  // A ação criada fecha o vínculo — o filtro por etapa saiu do ProjetoController.
  const vinculo = await page.evaluate(async (m) => {
    const prj = await App.api('/api/projetos', { planejamento_id: m.plan, tipo: 'ESTRATEGICO',
      titulo: 'Projeto de prova (direto)', ano: 2027, responsavel: 'QA' });
    await App.api('/api/desdobramentos', {
      planejamento_id: m.plan, projeto_id: prj.id, iniciativa_nova: 'Frente de prova (direto)',
      o_que: 'Ação de prova (direto)', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31', fator_id: m.pestel,
    });
    const lista = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=PESTEL&ano=${m.ano}`);
    const f = lista.find((x) => x.id == m.pestel) || {};
    const fila = await App.api(`/api/fatores/aguardando-acao?planejamento_id=${m.plan}`);
    const desmarcar = await App.api(`/api/fatores/${m.pestel}/plano-acao`,
      { planejamento_id: m.plan, marcar: false }).then(() => 'passou').catch((e) => e.message);
    const excluir = await App.api(`/api/fatores/${m.pestel}/excluir`,
      { planejamento_id: m.plan }).then(() => 'passou').catch((e) => e.message);
    return { prj: prj.id, ligou: f.desdobramento_id, titulo: f.acao_titulo, trava: f.acao_trava,
      naFila: fila.some((x) => x.id == m.pestel), desmarcar, excluir };
  }, massa);
  t(`${l} a ação fecha o vínculo do fator do PESTEL`,
    !!vinculo.ligou && vinculo.titulo === 'Ação de prova (direto)', JSON.stringify(vinculo));
  t(`${l} e ele sai da fila de aguardando`, vinculo.naFila === false, String(vinculo.naFila));
  t(`${l} desmarcar depois da ação continua recusado`,
    /já virou uma ação/.test(vinculo.desmarcar), vinculo.desmarcar);
  t(`${l} excluir o fator preso continua recusado, dizendo a ação`,
    /Ação de prova \(direto\)/.test(vinculo.excluir), vinculo.excluir);

  await page.evaluate(async (m) => {
    await App.api(`/api/projetos/${m.prj}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    for (const id of [m.pestel, m.porter]) {
      await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    }
    App.mostrarSecao('painel');
  }, { ...massa, prj: vinculo.prj });
}

/**
 * A Análise de Cenário indo ao plano de ação — a quarta origem da mesma fila.
 *
 * O cenário NÃO é fator: `cenario_item` é outra tabela, com colunas de
 * encaminhamento próprias e rota própria. O que se prova aqui é que, apesar
 * disso, o comportamento visível é o mesmo das outras três — mesmo selo, mesma
 * fila, mesmas recusas — porque é isso que faz o gesto ser aprendido uma vez só.
 *
 * O ponto mais frágil é a CHAVE da fila: `cenario_item` e `fator` numeram
 * separado, e sem prefixo por origem dois registros diferentes ocupariam a
 * mesma linha — o "Virar ação" abriria a pendência errada, sem erro nenhum.
 */
async function provasCenarioPlanoAcao(page) {
  const l = '[desktop] Cenário no plano de ação:';

  const massa = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const ano = Diag.ano();
    const item = await App.api('/api/cenario', { planejamento_id: plan.id, ano,
      tipo: 'TENDENCIA', ordem: 0, descricao: 'Item de cenário de prova (plano)' });
    const ida = await App.api(`/api/cenario/${item.id}/plano-acao`,
      { planejamento_id: plan.id, marcar: true }).then(() => true).catch((e) => e.message);
    return { plan: plan.id, ano, item: item.id, ida };
  });
  t(`${l} o item é aceito no plano de ação`, massa.ida === true, String(massa.ida));

  const fila = await page.evaluate(async (m) => {
    const linhas = await App.api(`/api/cenario/aguardando-acao?planejamento_id=${m.plan}`);
    const meu = linhas.find((x) => x.id == m.item) || {};
    return { origem: meu.origem, categoria: meu.categoria, texto: meu.texto };
  }, massa);
  t(`${l} a fila declara a origem e o tipo`,
    fila.origem === 'CENARIO' && fila.categoria === 'TENDENCIA', JSON.stringify(fila));

  // A chave por origem: sem o prefixo, item de cenário e fator de mesmo id
  // disputariam a mesma linha da fila.
  await page.evaluate(() => App.mostrarSecao('projetos'));
  await esperar(page,
    `!!document.querySelector('#secao-projetos [data-virar-acao="n${massa.item}"]')`, 15000);
  const naFila = await page.evaluate((m) => {
    const b = document.querySelector(`#secao-projetos [data-virar-acao="n${m.item}"]`);
    const linha = b?.closest('.d-flex')?.parentElement || b?.parentElement;
    return { achou: !!b, selo: /Cenário · Tendência/.test(linha?.textContent || '') };
  }, massa);
  t(`${l} a pendência entra na fila com chave própria da origem`, naFila.achou === true);
  t(`${l} e o selo diz "Cenário · Tendência"`, naFila.selo === true, JSON.stringify(naFila));

  // O selo do card, na própria seção do cenário.
  await page.evaluate(() => App.mostrarSecao('cenario'));
  await esperar(page,
    `!!document.querySelector('#secao-cenario [data-card-fator="${massa.item}"]')`, 15000);
  const card = await page.evaluate((m) => {
    const c = document.querySelector(`#secao-cenario [data-card-fator="${m.item}"]`);
    return c ? !!c.querySelector('[data-tirar-acao]') : null;
  }, massa);
  t(`${l} o card do cenário mostra o selo "Aguardando ação"`, card === true, String(card));

  // A ação fecha o vínculo pelo `cenario_item_id`, e as duas recusas valem.
  const vinculo = await page.evaluate(async (m) => {
    const prj = await App.api('/api/projetos', { planejamento_id: m.plan, tipo: 'ESTRATEGICO',
      titulo: 'Projeto de prova (cenário)', ano: 2027, responsavel: 'QA' });
    await App.api('/api/desdobramentos', {
      planejamento_id: m.plan, projeto_id: prj.id, iniciativa_nova: 'Frente de prova (cenário)',
      o_que: 'Ação de prova (cenário)', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31', cenario_item_id: m.item,
    });
    const lista = await App.api(`/api/cenario?planejamento_id=${m.plan}&ano=${m.ano}`);
    const i = lista.find((x) => x.id == m.item) || {};
    const fila = await App.api(`/api/cenario/aguardando-acao?planejamento_id=${m.plan}`);
    const desmarcar = await App.api(`/api/cenario/${m.item}/plano-acao`,
      { planejamento_id: m.plan, marcar: false }).then(() => 'passou').catch((e) => e.message);
    const excluir = await App.api(`/api/cenario/${m.item}/excluir`,
      { planejamento_id: m.plan }).then(() => 'passou').catch((e) => e.message);
    return { prj: prj.id, ligou: i.desdobramento_id, titulo: i.acao_titulo,
      naFila: fila.some((x) => x.id == m.item), desmarcar, excluir };
  }, massa);
  t(`${l} a ação fecha o vínculo pelo cenario_item_id`,
    !!vinculo.ligou && vinculo.titulo === 'Ação de prova (cenário)', JSON.stringify(vinculo));
  t(`${l} e o item sai da fila de aguardando`, vinculo.naFila === false, String(vinculo.naFila));
  t(`${l} desmarcar depois da ação é recusado`,
    /já virou uma ação/.test(vinculo.desmarcar), vinculo.desmarcar);
  t(`${l} excluir o item preso é recusado, dizendo a ação`,
    /Ação de prova \(cenário\)/.test(vinculo.excluir), vinculo.excluir);

  // A tela obedece à trava, como no fator: × desabilitado e sem ação pendurada.
  await page.evaluate(() => App.mostrarSecao('cenario'));
  await esperar(page,
    `!!document.querySelector('#secao-cenario [data-card-fator="${massa.item}"] [data-ir-acao]')`,
    15000);
  const x = await page.evaluate((m) => {
    const btn = document.querySelector(
      `#secao-cenario [data-card-fator="${m.item}"] .btn-outline-danger`);
    return btn ? { off: btn.disabled, semAcao: !btn.hasAttribute('data-excluir'),
      motivo: /Exclua a ação em Projetos/.test(btn.title),
      ponteiro: getComputedStyle(btn).pointerEvents } : null;
  }, massa);
  t(`${l} o × travado fica desabilitado e sem ação pendurada`,
    !!x && x.off === true && x.semAcao === true, JSON.stringify(x));
  t(`${l} e diz o motivo com o ponteiro alcançando`,
    !!x && x.motivo === true && x.ponteiro === 'auto', JSON.stringify(x));

  // Apagada a ação, a FK SET NULL devolve o item para a fila sozinho — é o que
  // torna o "exclua a ação em Projetos" um conselho que funciona de verdade.
  const voltou = await page.evaluate(async (m) => {
    await App.api(`/api/projetos/${m.prj}/excluir`, { planejamento_id: m.plan });
    const lista = await App.api(`/api/cenario?planejamento_id=${m.plan}&ano=${m.ano}`);
    const i = lista.find((x) => x.id == m.item) || {};
    const fila = await App.api(`/api/cenario/aguardando-acao?planejamento_id=${m.plan}`);
    return { ligou: i.desdobramento_id, naFila: fila.some((x) => x.id == m.item) };
  }, { ...massa, prj: vinculo.prj });
  t(`${l} apagada a ação, o item volta sozinho para a fila`,
    !voltou.ligou && voltou.naFila === true, JSON.stringify(voltou));

  await page.evaluate(async (m) => {
    await App.api(`/api/cenario/${m.item}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    App.mostrarSecao('painel');
  }, massa);
}

/**
 * Mover um fator de uma análise para outra (PESTEL ⇄ Porter ⇄ SWOT).
 *
 * Mover um fator LIMPO é trivial; o tema é o fator amarrado, e por isso quase
 * toda esta bateria prova RECUSAS. Cada amarra levanta uma pergunta de processo
 * ainda em aberto (backlog, decisões 13 a 15), e enquanto elas não têm resposta
 * a única saída segura é recusar dizendo o que desfazer primeiro. Um movimento
 * recusado é um aborrecimento; um que apaga a nota da GUT ou invalida um
 * cruzamento em silêncio é dado perdido que ninguém nota a tempo.
 *
 * A prova mais importante é a da CATEGORIA: as listas das três análises não se
 * correspondem, e aceitar a antiga produziria um fator invisível nas duas telas
 * — o defeito que o `salvar()` já corrigiu uma vez, por outro caminho.
 */
async function provasMoverAnalise(page) {
  const l = '[desktop] Mover de análise:';

  const massa = await page.evaluate(async () => {
    const plan = await App.planejamento();
    const ano = Diag.ano();
    const novo = (etapa, categoria, descricao) => App.api('/api/fatores',
      { planejamento_id: plan.id, etapa, categoria, descricao, ano });
    const limpo = await novo('PESTEL', 'LEGAL', 'Fator limpo de prova (mover)');
    const promovido = await novo('PESTEL', 'SOCIAL', 'Fator promovido de prova (mover)');
    const comGut = await novo('SWOT', 'FORCA', 'Fator com GUT de prova (mover)');
    const interno = await novo('SWOT', 'FORCA', 'Fator interno de prova (mover)');
    const externo = await novo('SWOT', 'AMEACA', 'Fator externo de prova (mover)');
    await App.api(`/api/fatores/${promovido.id}/promover`,
      { planejamento_id: plan.id, quadrante: 'OPORTUNIDADE' });
    await App.api(`/api/fatores/${comGut.id}/gut`,
      { planejamento_id: plan.id, gravidade: 5, urgencia: 4, tendencia: 3 });
    const cruz = await App.api('/api/cruzamentos', { planejamento_id: plan.id, ano,
      tipo: 'ATACAR', fator_interno_id: interno.id, fator_externo_id: externo.id,
      rotulo: 'Par de prova (mover)', estrategia: 'Estratégia de prova (mover)' });
    return { plan: plan.id, ano, limpo: limpo.id, promovido: promovido.id,
      comGut: comGut.id, interno: interno.id, externo: externo.id, cruz: cruz.id };
  });

  const mover = (id, etapa, categoria) => page.evaluate(
    (a) => App.api(`/api/fatores/${a.id}/mover`,
      { planejamento_id: a.plan, etapa: a.etapa, categoria: a.categoria })
      .then(() => 'passou').catch((e) => e.message),
    { id, etapa, categoria, plan: massa.plan });

  // O caminho feliz, e a categoria trocada junto com a etapa.
  t(`${l} um fator limpo vai do PESTEL para o Porter`,
    (await mover(massa.limpo, 'PORTER', 'SUBSTITUTOS')) === 'passou');
  const depois = await page.evaluate(async (m) => {
    const lista = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=PORTER&ano=${m.ano}`);
    const f = lista.find((x) => x.id == m.limpo) || {};
    return { etapa: f.etapa, categoria: f.categoria, trava: f.mover_trava };
  }, massa);
  t(`${l} ele chega com a etapa E a categoria do destino`,
    depois.etapa === 'PORTER' && depois.categoria === 'SUBSTITUTOS', JSON.stringify(depois));
  t(`${l} e sem trava nenhuma, porque nada o prende`,
    Array.isArray(depois.trava) && depois.trava.length === 0, JSON.stringify(depois.trava));

  // Categoria da etapa ERRADA: é o defeito que produziria o fator invisível.
  t(`${l} categoria de outra análise é recusada`,
    /listas das análises não se correspondem/.test(await mover(massa.limpo, 'SWOT', 'LEGAL')));
  t(`${l} mover para a análise em que já está é recusado`,
    /já está nesta análise/.test(await mover(massa.limpo, 'PORTER', 'RIVALIDADE')));
  t(`${l} e o Porter vai para a SWOT com um quadrante`,
    (await mover(massa.limpo, 'SWOT', 'AMEACA')) === 'passou');

  // As três amarras que recusam, cada uma com a sua frase.
  t(`${l} a ORIGEM de uma promoção é recusada`,
    /Desfaça a promoção/.test(await mover(massa.promovido, 'PORTER', 'RIVALIDADE')));
  const promId = await page.evaluate(async (m) => {
    const swot = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=SWOT&ano=${m.ano}`);
    return (swot.find((f) => f.promovido_de_id == m.promovido) || {}).id;
  }, massa);
  t(`${l} e o PROMOVIDO também — a amarra tem dois lados`,
    /Desfaça a promoção/.test(await mover(promId, 'PESTEL', 'SOCIAL')));
  t(`${l} fator com nota na GUT é recusado`,
    /Matriz GUT/.test(await mover(massa.comGut, 'PESTEL', 'SOCIAL')));
  t(`${l} fator citado num cruzamento é recusado`,
    /cruzamento da SWOT/.test(await mover(massa.interno, 'PESTEL', 'SOCIAL')));
  t(`${l} pelos DOIS lados do par, não só o interno`,
    /cruzamento da SWOT/.test(await mover(massa.externo, 'PESTEL', 'SOCIAL')));

  // Fator que já virou ação: a trava é a MESMA da exclusão, não uma segunda.
  const comAcao = await page.evaluate(async (m) => {
    const f = await App.api('/api/fatores', { planejamento_id: m.plan, etapa: 'PESTEL',
      categoria: 'ECONOMICO', descricao: 'Fator com ação de prova (mover)', ano: m.ano });
    await App.api(`/api/fatores/${f.id}/plano-acao`, { planejamento_id: m.plan, marcar: true });
    const prj = await App.api('/api/projetos', { planejamento_id: m.plan, tipo: 'ESTRATEGICO',
      titulo: 'Projeto de prova (mover)', ano: 2027, responsavel: 'QA' });
    await App.api('/api/desdobramentos', {
      planejamento_id: m.plan, projeto_id: prj.id, iniciativa_nova: 'Frente de prova (mover)',
      o_que: 'Ação de prova (mover)', como: 'x', quem: 'QA', prioridade: 'MEDIA',
      status: 'NAO_INICIADO', progresso: 0, recorrencia: 'NENHUMA',
      data_inicio: '2027-01-01', data_fim: '2027-12-31', fator_id: f.id,
    });
    const recusa = await App.api(`/api/fatores/${f.id}/mover`,
      { planejamento_id: m.plan, etapa: 'PORTER', categoria: 'RIVALIDADE' })
      .then(() => 'passou').catch((e) => e.message);
    return { fator: f.id, prj: prj.id, recusa };
  }, massa);
  t(`${l} fator que já virou ação é recusado, dizendo qual ação`,
    /Ação de prova \(mover\)/.test(comAcao.recusa) && /origem da ação/.test(comAcao.recusa),
    comAcao.recusa);

  // A tela obedece: ⇄ desabilitado, sem ação pendurada, com TODOS os motivos.
  await page.evaluate(() => App.mostrarSecao('swot'));
  await esperar(page,
    `!!document.querySelector('#secao-swot [data-card-fator="${massa.comGut}"]')`, 15000);
  const botao = await page.evaluate((m) => {
    const ver = (id) => {
      const b = document.querySelector(
        `#secao-swot [data-card-fator="${id}"] [aria-label^="Mover"]`);
      return b ? { off: b.disabled, semAcao: !b.hasAttribute('data-mover'), motivo: b.title,
        ponteiro: getComputedStyle(b).pointerEvents } : null;
    };
    return { gut: ver(m.comGut), interno: ver(m.interno) };
  }, massa);
  t(`${l} o ⇄ do fator travado fica desabilitado e sem ação pendurada`,
    !!botao.gut && botao.gut.off === true && botao.gut.semAcao === true, JSON.stringify(botao.gut));
  t(`${l} e o motivo alcançável diz o que desfazer`,
    !!botao.gut && /Matriz GUT/.test(botao.gut.motivo) && botao.gut.ponteiro === 'auto',
    JSON.stringify(botao.gut));
  t(`${l} o fator do cruzamento também trava, com a frase dele`,
    !!botao.interno && botao.interno.off === true && /cruzamento/.test(botao.interno.motivo),
    JSON.stringify(botao.interno));

  // O modal pergunta a categoria do DESTINO, um campo por análise: com um campo
  // só, repintado, trocar de destino e voltar perdia a escolha já feita.
  await page.evaluate(() => App.mostrarSecao('pestel'));
  await esperar(page,
    `!!document.querySelector('#secao-pestel [data-mover="${massa.promovido}"], `
    + `#secao-pestel [data-card-fator="${massa.promovido}"]')`, 15000);
  const modal = await page.evaluate(async (m) => {
    const lista = await App.api(`/api/fatores?planejamento_id=${m.plan}&etapa=SWOT&ano=${m.ano}`);
    Diag.modalMoverFator(lista.find((f) => f.id == m.limpo), m.plan);
    await new Promise((r) => setTimeout(r, 300));
    const rotulos = [...document.querySelectorAll('#campo-etapa input')].map(
      (i) => i.closest('label')?.textContent.trim() || i.value);
    return {
      destinos: rotulos,
      camposCategoria: ['PESTEL', 'PORTER', 'SWOT']
        .filter((e) => document.getElementById(`campo-categoria_${e}`)),
      titulo: document.querySelector('.modal.show .modal-title')?.textContent.trim(),
    };
  }, massa);
  t(`${l} a análise em que o fator JÁ está não é oferecida`,
    !modal.destinos.includes('SWOT'), JSON.stringify(modal.destinos));
  // A Análise de Cenário entrou como quarto destino (fatia C-bis). Ela está
  // aqui, e não só na prova própria, porque é o mesmo campo: quem mexer nos
  // destinos tem de ver as duas coisas quebrarem juntas.
  t(`${l} e a Análise de Cenário entra como destino, ao lado das outras`,
    modal.destinos.includes('CENARIO') && modal.destinos.length === 3,
    JSON.stringify(modal.destinos));
  t(`${l} e um campo de categoria por destino, não um repintado`,
    modal.camposCategoria.length === 2 && !modal.camposCategoria.includes('SWOT'),
    JSON.stringify(modal.camposCategoria));

  await page.evaluate(async (m) => {
    window.bootstrap?.Modal.getInstance(document.querySelector('.modal.show'))?.hide();
    await App.api(`/api/projetos/${m.prj}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    await App.api(`/api/cruzamentos/${m.cruz}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    for (const id of [m.limpo, m.promovido, m.comGut, m.interno, m.externo, m.comAcao]) {
      await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: m.plan }).catch(() => {});
    }
    App.mostrarSecao('painel');
  }, { ...massa, prj: comAcao.prj, comAcao: comAcao.fator });
}

/**
 * O `⇄` que ATRAVESSA a tabela: análise → Cenário e de volta.
 *
 * As regras (o que viaja, o que é recusado, as vozes que acompanham) são do
 * servidor e estão na `funcional.sh` §9h. O que só o navegador responde é o
 * que esta prova mede: o formulário troca de pergunta conforme o destino — no
 * Cenário não existe categoria, existe TIPO —, e depois de salvar a pessoa é
 * LEVADA à tela nova.
 *
 * Esse último ponto não é enfeite. O item some da análise de origem, e um
 * cartão que desaparece sem nada dizendo para onde foi é indistinguível de um
 * cartão excluído — no meio de uma reunião, é o tipo de dúvida que para a
 * conversa.
 */
async function provasMoverEntreTabelas(page) {
  const l = '[desktop] Mover entre tabelas:';
  const TEXTO = 'Item que atravessa (prova)';
  const rotulos = () => page.evaluate(() =>
    [...document.querySelectorAll('.modal.show .grupo-botoes label')].map((b) => b.textContent.trim()));
  // Os grupos de escolha do modal são `<label>` sobre um radio escondido, não
  // `<button>`: clicar no texto é o que a pessoa faz, e é o que funciona.
  //
  // A busca é em TODO `label` do modal, não só nos `.grupo-botoes`: o quadrante
  // da SWOT é o campo `quadrantes` (a matriz 2×2), com marcação própria. Preso
  // ao grupo de botões, o clique em "Ameaça" não achava nada e a prova falhava
  // no passo seguinte, longe da causa.
  const escolher = (texto) => page.evaluate((t) => {
    const alvo = [...document.querySelectorAll('.modal.show label')]
      .find((x) => x.textContent.trim() === t || x.textContent.trim().startsWith(t));
    if (alvo) alvo.click();
    return !!alvo;
  }, texto);

  const fator = await page.evaluate(async (texto) => {
    const plan = await App.planejamento();
    const f = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PESTEL',
      categoria: 'SOCIAL', descricao: texto, ano: Diag.ano() });
    return f.id;
  }, TEXTO);
  let final = null;

  try {
    await page.evaluate(() => App.mostrarSecao('pestel'));
    await esperar(page, `!!document.querySelector('#secao-pestel [data-card-fator="${fator}"]')`, 15000);
    await page.click(`#secao-pestel [data-card-fator="${fator}"] [data-mover="${fator}"]`);
    await esperar(page, "!!document.querySelector('.modal.show')", 8000);
    const destinos = await rotulos();
    t(`${l} a Análise de Cenário é um destino do ⇄, como as outras`,
      destinos.includes('Análise de Cenário'), JSON.stringify(destinos));

    await escolher('Análise de Cenário');
    await new Promise((r) => setTimeout(r, 400));
    const pediuTipo = await page.evaluate(() =>
      document.querySelector('.modal.show').textContent.includes('Entra como'));
    t(`${l} escolhido o Cenário, o formulário pede o TIPO e não a categoria`, pediuTipo);
    await escolher('Tendência');
    await page.click('#modal-salvar');

    const levou = await esperar(page,
      "!document.getElementById('secao-cenario').classList.contains('d-none')", 12000);
    t(`${l} salvar LEVA quem moveu até a tela nova`, levou);
    const chegou = await esperar(page,
      `document.getElementById('secao-cenario').textContent.includes(${JSON.stringify(TEXTO)})`, 12000);
    t(`${l} e o item está lá, com o texto inteiro`, chegou);

    const noCenario = await page.evaluate((texto) => {
      const c = [...document.querySelectorAll('#secao-cenario [data-card-fator]')]
        .find((x) => x.textContent.includes(texto));
      return c ? Number(c.dataset.cardFator) : null;
    }, TEXTO);

    await page.click(`#secao-cenario [data-card-fator="${noCenario}"] [data-mover="${noCenario}"]`);
    await esperar(page, "!!document.querySelector('.modal.show')", 8000);
    const volta = await rotulos();
    t(`${l} o ⇄ do Cenário oferece as três análises, e não a si mesmo`,
      ['PESTEL', 'Porter', 'SWOT'].every((e) => volta.includes(e))
      && !volta.includes('Análise de Cenário'), JSON.stringify(volta));
    await escolher('SWOT');
    await new Promise((r) => setTimeout(r, 400));
    await escolher('Ameaça');
    await page.click('#modal-salvar');

    const naSwot = await esperar(page,
      `!document.getElementById('secao-swot').classList.contains('d-none')
       && document.getElementById('secao-swot').textContent.includes(${JSON.stringify(TEXTO)})`, 12000);
    t(`${l} e a volta cai na SWOT, no quadrante escolhido`, naSwot);
    final = await page.evaluate((texto) => {
      const c = [...document.querySelectorAll('#secao-swot [data-card-fator]')]
        .find((x) => x.textContent.includes(texto));
      return c ? Number(c.dataset.cardFator) : null;
    }, TEXTO);
  } finally {
    // Um id só, e não os dois: a travessia CONSUMIU o fator de origem, e pedir
    // a exclusão dele devolveria 404 — que o `vigiar` registra como erro de
    // página e que, sendo deliberado, ensinaria a ignorar erro de verdade.
    // O `fator` original só sobrevive se a prova morreu antes de mover.
    await page.evaluate(async (id) => {
      if (!id) return;
      const plan = await App.planejamento();
      await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: plan.id }).catch(() => {});
      App.mostrarSecao('painel');
    }, final || fator).catch(() => {});
  }
}

/**
 * Matriz de Impacto por Negócio — as DUAS leituras da mesma tabela.
 *
 * A autorização, que é o coração do tema, tem bateria própria em `funcional.sh`
 * (§9e): ela precisa de três sessões diferentes, e repeti-la aqui só encheria o
 * console de 403 deliberados, que o `vigiar` conta como erro de página.
 *
 * O que se mede AQUI é o que só o navegador responde: a grade cabe na tela sem
 * empurrar a página de lado, o cabeçalho e a coluna do fator ficam presos ao
 * rolar, e trocar o contexto de Corporativo para um negócio troca a GRADE pela
 * LISTA — que é a leitura que faz a tela valer para quem não é controladoria.
 */
async function provasImpactoNegocio(page) {
  const l = '[desktop] Impacto por Negócio:';

  const massa = await page.evaluate(async () => {
    const ciclo = App.contexto.cicloId;
    const ano = Diag.ano();
    const d = await App.api(`/api/impacto?ciclo_id=${ciclo}&ano=${ano}`);
    if (!d.fatores.length || d.negocios.length < 2) return { sem: true };
    const [f1, f2] = d.fatores;
    const [n1, n2] = d.negocios;
    const grava = (fator_id, negocio_id, sinal, texto) =>
      App.api('/api/impacto', { ciclo_id: ciclo, fator_id, negocio_id, sinal, texto });
    await grava(f1.id, n1.id, 'NEGATIVO', 'Aperta a margem de prova');
    await grava(f1.id, n2.id, 'POSITIVO', 'Abre espaço de prova');
    if (f2) await grava(f2.id, n1.id, 'POSITIVO', 'Segunda linha de prova');
    return { ciclo, ano, f1: f1.id, f2: f2?.id ?? null, n1: n1.id, n2: n2.id,
      fatores: d.fatores.length, negocios: d.negocios.length };
  });
  if (massa.sem) {
    ok.push(`${l} pulada — sem SWOT corporativa do ano ou com menos de dois negócios`);
    return;
  }

  await page.evaluate(() => App.mostrarSecao('impacto'));
  // Espera pelo DADO, não pelo nó: `percorrer` já pintou as seções no início da
  // bateria, e um seletor genérico casaria com a pintura de antes da massa.
  await esperar(page,
    "!!(SecaoImpacto.dados && SecaoImpacto.dados.celulas.some("
    + "(c) => (c.texto || '').includes('de prova')))", 15000);

  const grade = await page.evaluate((m) => {
    const t = document.querySelector('#secao-impacto .tabela-impacto');
    if (!t) return null;
    return {
      colunas: t.querySelectorAll('thead th').length,
      linhas: t.querySelectorAll('tbody tr').length,
      glifos: [...t.querySelectorAll('.celula-impacto span[aria-hidden]')].map((s) => s.textContent.trim()),
      // Cada coluna leva o rótulo completo em `title` e uma cópia para leitor de
      // tela: o cabeçalho mostra só o código, que sozinho não identifica nada.
      cabecalhoComRotulo: [...t.querySelectorAll('thead .col-negocio')]
        .every((th) => (th.getAttribute('title') || '').includes(' - ')),
      rolaPagina: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      esperadoColunas: m.negocios + 1,
    };
  }, massa);
  t(`${l} a grade tem uma coluna por negócio, mais a do fator`,
    !!grade && grade.colunas === grade.esperadoColunas, JSON.stringify(grade));
  t(`${l} uma linha por ameaça/oportunidade da SWOT corporativa`,
    !!grade && grade.linhas === massa.fatores, `${grade?.linhas} de ${massa.fatores}`);
  t(`${l} o sinal é FORMA, não só cor — ▲ e ▼ desenhados`,
    !!grade && grade.glifos.includes('▲') && grade.glifos.includes('▼'),
    JSON.stringify(grade?.glifos));
  t(`${l} o cabeçalho leva o nome inteiro do negócio, não só o código`,
    grade?.cabecalhoComRotulo === true);
  t(`${l} e a página não rola de lado`, grade?.rolaPagina === false);

  // O cabeçalho e a coluna do fator presos: numa grade de códigos numéricos,
  // perder o cabeçalho na rolagem torna as células ilegíveis.
  const fixo = await page.evaluate(async () => {
    const caixa = document.querySelector('#secao-impacto .caixa-impacto');
    caixa.scrollTop = caixa.scrollHeight;
    await new Promise((r) => setTimeout(r, 200));
    const cx = caixa.getBoundingClientRect();
    const th = caixa.querySelector('thead .col-negocio');
    const quina = caixa.querySelector('thead .col-fator');
    return {
      rolou: Math.round(caixa.scrollTop),
      thNoTopo: Math.round(th.getBoundingClientRect().top - cx.top),
      quinaNoTopo: Math.round(quina.getBoundingClientRect().top - cx.top),
      colunaPresa: getComputedStyle(caixa.querySelector('tbody .col-fator')).position,
      colapso: getComputedStyle(caixa.querySelector('.tabela-impacto')).borderCollapse,
    };
  });
  t(`${l} a caixa rolou de verdade antes de medir`, fixo.rolou > 100, `${fixo.rolou}`);
  t(`${l} o cabeçalho fica grudado no topo da caixa`,
    fixo.thNoTopo === 0 && fixo.quinaNoTopo === 0, JSON.stringify(fixo));
  t(`${l} a coluna do fator fica presa à esquerda`, fixo.colunaPresa === 'sticky', fixo.colunaPresa);
  t(`${l} bordas separadas, para o grudado ser opaco`, fixo.colapso === 'separate', fixo.colapso);

  // A outra leitura: no contexto de um NEGÓCIO a grade dá lugar à lista.
  const antes = await page.evaluate((m) => {
    const ctx = App.contexto;
    window.__ctxAntes = { ...ctx };
    App.contexto = { ...ctx, corporativo: false, negocioId: m.n1 };
    return true;
  }, massa);
  await page.evaluate(() => App.mostrarSecao('impacto'));
  await esperar(page,
    "!!document.querySelector('#secao-impacto [data-card-impacto], #secao-impacto .alert')", 15000);
  const lista = await page.evaluate(() => {
    const el = document.getElementById('secao-impacto');
    return {
      cards: el.querySelectorAll('[data-card-impacto]').length,
      temGrade: !!el.querySelector('.tabela-impacto'),
      texto: el.textContent.replace(/\s+/g, ' '),
    };
  });
  t(`${l} no contexto de um negócio a grade some e vira lista`,
    lista.temGrade === false && lista.cards > 0, JSON.stringify({ ...lista, texto: undefined }));
  t(`${l} a lista traz só o que impacta ESTE negócio, com a contagem`,
    /\d+ de \d+ fatores do diagnóstico corporativo/.test(lista.texto)
    && lista.texto.includes('Aperta a margem de prova')
    && !lista.texto.includes('Abre espaço de prova'),
    lista.texto.slice(0, 220));

  // A limpeza APAGA a massa e devolve o contexto — nesta ordem, e esperando a
  // repintura terminar. Esta prova é a única que troca `App.contexto`, e o
  // Dossiê, que roda logo depois, o troca dezenas de vezes: devolver o contexto
  // e sair com um `carregar()` ainda no ar deixa uma pintura órfã correndo
  // contra o contexto que a próxima prova já está mudando — exatamente o tipo
  // de corrida que produziu os falsos-negativos de pintura velha nesta bateria.
  await page.evaluate(async (m) => {
    for (const [f, n] of [[m.f1, m.n1], [m.f1, m.n2], [m.f2, m.n1]]) {
      if (f) {
        await App.api('/api/impacto',
          { ciclo_id: m.ciclo, fator_id: f, negocio_id: n, sinal: '' }).catch(() => {});
      }
    }
    App.contexto = window.__ctxAntes;
    delete window.__ctxAntes;
    App.mostrarSecao('painel');
  }, massa);
  await esperar(page, "!document.getElementById('secao-painel').classList.contains('d-none')", 10000);
  await esperar(page, "!!document.querySelector('#secao-painel *')", 10000);
  void antes;
}

/**
 * Duas telas abertas ao mesmo tempo — o "mais de um ADMIN preenchendo junto".
 *
 * É a única prova da bateria que precisa de DUAS sessões de navegador, e não dá
 * para ser de outro jeito: o que se mede é justamente o que a segunda tela faz
 * sozinha quando a primeira grava. Uma sessão só provaria o pulso do servidor,
 * que já tem prova própria em `funcional.sh`.
 *
 * A segunda tela NÃO é tocada depois de aberta: nada de clique, nada de
 * recarregar. Se o texto aparecer nela, apareceu pelo relógio.
 */
async function provasDuasTelas(browser) {
  const l = '[duas telas] Preenchimento simultâneo:';
  const ctxB = await browser.newContext({ viewport: { width: 1400, height: 800 }, reducedMotion: 'reduce' });
  const ctxA = await browser.newContext({ viewport: { width: 1400, height: 800 }, reducedMotion: 'reduce' });
  const A = await entrar(ctxA, 'A', []);
  const B = await entrar(ctxB, 'B', []);
  const TEXTO = 'Fator escrito pela outra tela (prova)';
  const TEXTO2 = 'Fator escrito com modal aberto (prova)';
  const criados = [];

  try {
    for (const p of [A, B]) {
      await p.evaluate(() => App.mostrarSecao('pestel'));
      await esperar(p, "!!document.querySelector('#secao-pestel .coluna-categoria')", 15000);
    }
    // O relógio é armado depois da pintura, no `recarregarSecaoAtiva`.
    await esperar(B, '!!Vivo.relogio', 8000);
    t(`${l} a segunda tela arma o relógio sozinha ao abrir a seção`, true);

    const antes = await B.evaluate((x) =>
      document.getElementById('secao-pestel').textContent.includes(x), TEXTO);
    t(`${l} e não mostra o que ainda não foi escrito`, antes === false);

    criados.push(await A.evaluate(async (x) => {
      const plan = await App.planejamento();
      const f = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PESTEL',
        categoria: 'ECONOMICO', descricao: x, ano: Diag.ano() });
      return f.id;
    }, TEXTO));

    let refletiu = true;
    const t0 = Date.now();
    await esperar(B,
      `document.getElementById('secao-pestel').textContent.includes(${JSON.stringify(TEXTO)})`,
      15000).catch(() => { refletiu = false; });
    t(`${l} o que uma escreve aparece na outra sem ninguém atualizar`,
      refletiu, refletiu ? `${Date.now() - t0}ms` : 'não apareceu em 15s');

    // A guarda que mais importa: repintar com um formulário aberto jogaria fora
    // o que a pessoa está escrevendo. Vale mais que a atualização em si.
    await B.evaluate(() => Modal.abrir({ titulo: 'Prova da guarda', url: '/api/nada',
      valores: { x: '' }, campos: [{ nome: 'x', rotulo: 'Campo' }] }));
    await esperar(B, "!!document.querySelector('.modal.show')", 5000);
    criados.push(await A.evaluate(async (x) => {
      const plan = await App.planejamento();
      const f = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'PESTEL',
        categoria: 'SOCIAL', descricao: x, ano: Diag.ano() });
      return f.id;
    }, TEXTO2));
    // Duas batidas do relógio (4s cada) com folga: se fosse repintar, repintaria.
    await new Promise((r) => setTimeout(r, 9500));
    const comModal = await B.evaluate((x) => ({
      aberto: !!document.querySelector('.modal.show'),
      repintou: document.getElementById('secao-pestel').textContent.includes(x),
    }), TEXTO2);
    t(`${l} com um formulário aberto, NÃO repinta por baixo dele`,
      comModal.aberto === true && comModal.repintou === false, JSON.stringify(comModal));

    // E a atualização não se perde: fechado o formulário, a próxima batida traz.
    await B.evaluate(() => {
      window.bootstrap?.Modal.getInstance(document.querySelector('.modal.show'))?.hide();
    });
    await esperar(B, "!document.querySelector('.modal.show')", 5000);
    let veioDepois = true;
    await esperar(B,
      `document.getElementById('secao-pestel').textContent.includes(${JSON.stringify(TEXTO2)})`,
      15000).catch(() => { veioDepois = false; });
    t(`${l} e o que ficou represado chega assim que o formulário fecha`, veioDepois);

    // Ao sair da seção o relógio se desarma: as seções não são destruídas ao
    // navegar, só ganham `d-none`, e um relógio por tela visitada ficaria batendo.
    await B.evaluate(() => App.mostrarSecao('painel'));
    await esperar(B, "!document.getElementById('secao-painel').classList.contains('d-none')", 10000);
    await new Promise((r) => setTimeout(r, 5000));
    const noPainel = await B.evaluate(() => Vivo.secaoId);
    t(`${l} ao trocar de seção o relógio passa a vigiar a nova`,
      noPainel === 'secao-painel', String(noPainel));
  } finally {
    await A.evaluate(async (ids) => {
      const plan = await App.planejamento();
      for (const i of ids) {
        await App.api(`/api/fatores/${i}/excluir`, { planejamento_id: plan.id }).catch(() => {});
      }
    }, criados).catch(() => {});
    await ctxA.close();
    await ctxB.close();
  }
}

/**
 * A oficina de Cruzamentos inteira: a sala propõe o PAR pelo celular e o
 * condutor aceita.
 *
 * É a única pergunta da sala em que o celular escolhe REGISTROS em vez de só
 * escrever, e por isso a prova percorre o caminho todo em vez de medir pontas:
 * as guardas do servidor estão na `funcional.sh` §9i, e o que só o navegador
 * responde é se o gesto FECHA — 🎤 na coluna, dois seletores no celular,
 * proposta aparecendo sozinha no painel, e o "Usar" abrindo o formulário já
 * com o par e a estratégia da pessoa.
 *
 * Dois contextos, um deles de celular de verdade (390×844): a tela pública é a
 * que a direção usa na mão, e provar o par num viewport de computador mediria
 * uma tela que ninguém vai ver.
 */
/**
 * O 🎤 da ETAPA INTEIRA: o cabeçalho do PESTEL abre a análise toda para a
 * sala, e é o CELULAR que escolhe em qual categoria a resposta entra — lendo,
 * ao escolher, a orientação do ⓘ daquela categoria. Pedido do cliente
 * (2026-09-03).
 *
 * O que a prova guarda, e que quebra em silêncio:
 *  - o catálogo servido (`App.sessao.categorias`, o que o celular desenha)
 *    bate com o do `Diag` (o que a análise desenha) — são duas cópias, e a
 *    divergência é exatamente o defeito que ninguém vê;
 *  - nenhuma categoria vem marcada: com dois lados o padrão é o primeiro, e
 *    o mesmo padrão aqui mandaria a resposta para "Político" sem escolha;
 *  - a orientação aparece AO ESCOLHER, e é o mesmo texto do ⓘ do condutor;
 *  - a voz chega ao condutor na coluna da categoria, e "Usar" abre o fator
 *    já com ela marcada.
 */
async function provasEtapaNaSala(browser) {
  const l = '[oficina] Etapa inteira na sala:';
  const ctxA = await browser.newContext({ viewport: { width: 1500, height: 900 }, reducedMotion: 'reduce' });
  const admin = await entrar(ctxA, 'admin', []);
  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce',
  });
  const aceitar = async (d) => { await d.accept(); };
  admin.on('dialog', aceitar);
  try {
    const dif = await admin.evaluate(() => {
      const s = App.sessao.categorias || {};
      const fora = [];
      for (const etapa of ['PESTEL', 'PORTER']) {
        const doDiag = Diag.CATEGORIAS_ETAPA[etapa] || [];
        doDiag.forEach(([v, r, cor, dica]) => {
          const x = s[etapa]?.[v];
          if (!x || x.rotulo !== r || x.cor !== cor || x.dica !== dica) fora.push(`${etapa}.${v}`);
        });
        if (Object.keys(s[etapa] || {}).length !== doDiag.length) fora.push(`${etapa}.quantidade`);
      }
      Object.keys(Diag.QUADRANTES).forEach((v) => {
        const x = s.SWOT?.[v];
        if (!x || x.cor !== Diag.CORES_QUADRANTE[v] || x.dica !== Diag.DICAS_QUADRANTE[v]) fora.push(`SWOT.${v}`);
      });
      return fora;
    });
    t(`${l} o catálogo servido ao celular bate com o do Diag (rótulo, cor, dica)`,
      dif.length === 0, dif.join(', '));

    await admin.evaluate(() => {
      Diag.anoSelecionado = Number(Diag.cicloAtual().ano_base);
      App.mostrarSecao('pestel');
    });
    const temMic = await esperar(admin,
      "!!document.querySelector('#secao-pestel [data-mic-etapa=\"PESTEL\"] [data-mic]')", 15000);
    t(`${l} o cabeçalho do PESTEL tem o 🎤 da análise inteira`, temMic);
    if (!temMic) return;
    await admin.click('#secao-pestel [data-mic-etapa="PESTEL"] [data-mic]');
    const abriu = await esperar(admin,
      "!!document.querySelector('#secao-pestel [data-mic-etapa=\"PESTEL\"] [data-mic-fechar]')", 15000);
    t(`${l} o 🎤 abre a etapa inteira para a sala, e acende`, abriu);
    if (!abriu) return;
    // Uma grade só, sem coluna por categoria: com seis colunas quase sempre
    // vazias o painel gastava a faixa fixa inteira em "nenhuma sugestão"
    t(`${l} o painel do condutor abre com uma grade só, sem coluna por categoria`,
      await admin.evaluate(() =>
        !document.querySelector('#secao-pestel .painel-quiz-vivo [data-quiz-categoria]')
        && !!document.querySelector('#secao-pestel .painel-quiz-vivo .coluna-quiz')));

    const sala = await admin.evaluate(async () => {
      const plan = await App.planejamento();
      const q = await App.api(`/api/quiz?planejamento_id=${plan.id}`);
      return { pin: q.sessao?.pin };
    });
    const cel = await ctxM.newPage();
    await cel.goto(`${BASE}/entrar/${sala.pin}`);
    await esperar(cel, "!!document.querySelector('#campo-nome')", 15000);
    await cel.fill('#campo-nome', 'Cooperado no celular');
    await cel.click('#btn-entrar');
    const cartoes = await esperar(cel,
      "document.querySelectorAll('.grade-categorias .quadrante-opcao').length === 6", 15000);
    t(`${l} o celular mostra as seis categorias em cartões`, cartoes);
    if (!cartoes) return;
    const antes = await cel.evaluate(() => ({
      marcado: !!document.querySelector('input[name="tipo-resposta"]:checked'),
      aviso: document.querySelector('[data-orientacao-lado]')?.textContent.trim() || '',
      rola: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      // O bloco do topo (enunciado, "Análise", "Como responder") saiu desta
      // pergunta a pedido do cliente: os quadrantes SÃO a pergunta, e o bloco
      // os empurrava para baixo da dobra.
      topo: !!document.querySelector('.contexto-pergunta'),
      cartoesAcima: (document.querySelector('.grade-categorias')?.getBoundingClientRect().top || 9999)
        < window.innerHeight / 2,
    }));
    t(`${l} nenhuma categoria vem marcada — a escolha é da pessoa`, !antes.marcado);
    t(`${l} sem o bloco do topo: os quadrantes vêm logo abaixo do PIN`, !antes.topo && antes.cartoesAcima,
      JSON.stringify({ topo: antes.topo, cartoesAcima: antes.cartoesAcima }));
    t(`${l} sem escolha, a tela pede para tocar numa categoria`,
      /Toque numa categoria/.test(antes.aviso), antes.aviso);
    t(`${l} os cartões não fazem a tela rolar na horizontal`, antes.rola === false);

    await cel.fill('#campo-ideia', 'Automacao das granjas com sensores');
    await cel.click('#btn-enviar');
    await new Promise((r) => setTimeout(r, 500));
    t(`${l} enviar sem categoria avisa antes de ir à rede`, /Escolha a categoria/.test(
      await cel.evaluate(() => document.getElementById('aviso-envio')?.textContent || '')));

    // Tecnológico é o quarto cartão (a ordem é a do catálogo)
    await cel.click('label[for="tipo-lado-3"]');
    const esperado = await admin.evaluate(() => App.sessao.orientacoes.TECNOLOGICO);
    const mostrou = await esperar(cel,
      "!!document.querySelector('[data-orientacao-lado=\"TECNOLOGICO\"]')", 8000);
    const texto = await cel.evaluate(() =>
      document.querySelector('[data-orientacao-lado="TECNOLOGICO"]')?.textContent || '');
    t(`${l} ao escolher, a orientação do ⓘ da categoria aparece — a mesma do condutor`,
      mostrou && texto.includes(esperado), texto.slice(0, 80));
    t(`${l} o rascunho sobrevive à escolha`,
      (await cel.evaluate(() => document.getElementById('campo-ideia')?.value)) === 'Automacao das granjas com sensores');
    await cel.click('#btn-enviar');
    t(`${l} a resposta com categoria é enviada`, await esperar(cel,
      "/enviada/.test(document.getElementById('aviso-envio')?.textContent || '')", 10000));
    t(`${l} e volta na lista da pessoa com o selo da categoria`, await esperar(cel,
      "[...document.querySelectorAll('.ideia-minha .badge')].some((b) => b.textContent.trim() === 'Tecnológico')", 10000));

    const chegou = await esperar(admin,
      "[...document.querySelectorAll('#secao-pestel .painel-quiz-vivo .ficha-sugestao .selo-categoria-voz')]"
      + ".some((s) => s.textContent.trim() === 'Tecnológico')", 15000);
    t(`${l} a voz chega ao condutor na grade, com a etiqueta do quadrante escolhido`, chegou);
    if (chegou) {
      await admin.click('#secao-pestel .painel-quiz-vivo .ficha-sugestao:has(.selo-categoria-voz) [data-usar-sugestao]');
      // O grupo de cartões tem o id do campo (`campo-categoria`); os rádios
      // dentro dele levam esse id como `name`, não o nome do campo.
      const modal = await esperar(admin, "!!document.querySelector('#modal-form.show #campo-categoria')", 10000);
      await new Promise((r) => setTimeout(r, 300));
      const marcada = modal ? await admin.evaluate(() => Modal.coletar()?.categoria) : null;
      t(`${l} "Usar" abre o fator já com a categoria da voz marcada`,
        modal && marcada === 'TECNOLOGICO', String(marcada));
      await fecharModal(admin);
    }
    await admin.click('#secao-pestel [data-mic-etapa="PESTEL"] [data-mic-fechar]');
    t(`${l} o 🎤 aceso fecha a pergunta`, await esperar(admin,
      "!document.querySelector('#secao-pestel [data-mic-etapa=\"PESTEL\"] [data-mic-fechar]')", 15000));
  } finally {
    admin.off('dialog', aceitar);
    await ctxM.close();
    await ctxA.close();
  }
}

/**
 * O QUESTIONÁRIO PRÉVIO da tempestade (pedido do cliente, 2026-09-03): a
 * rodada nasce com perguntas em ordem; o celular as percorre uma a uma, no
 * próprio ritmo, antes do encontro.
 *
 * O que a prova guarda, e que quebra em silêncio:
 *  - atingido o teto numa pergunta, o celular passa SOZINHO à próxima, e diz
 *    por quê — sem o aviso a troca de tela parece erro;
 *  - "Pular" passa sem responder, e o resumo conta o que faltou;
 *  - recarregar retoma de onde parou (a posição vive por PIN);
 *  - as fichas numeradas abrem qualquer pergunta;
 *  - na Coleta, cada ideia leva a etiqueta da pergunta e o filtro deixa uma
 *    pergunta por vez na nuvem.
 */
async function provasQuestionarioTempestade(browser) {
  const l = '[oficina] Questionário prévio:';
  const ctxA = await browser.newContext({ viewport: { width: 1500, height: 900 }, reducedMotion: 'reduce' });
  const admin = await entrar(ctxA, 'admin', []);
  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce',
  });
  let rodada = null;
  try {
    rodada = await admin.evaluate(async () => {
      const plan = await App.planejamento();
      return App.api('/api/rodadas', {
        planejamento_id: plan.id, ano: Diag.ano(), tema: 'Preparação do encontro (prova)',
        max_ideias: 2, max_votos: 2, confirmar_encerrar: true,
        perguntas: 'O que trava o crescimento?\nQue oportunidade estamos perdendo?\nOnde perdemos dinheiro?',
      });
    });
    t(`${l} abre a tempestade com três perguntas`, Number(rodada?.perguntas) === 3, JSON.stringify(rodada));
    if (!rodada?.pin) return;

    const cel = await ctxM.newPage();
    await cel.goto(`${BASE}/entrar/${rodada.pin}`);
    await esperar(cel, "!!document.querySelector('#campo-nome')", 15000);
    await cel.fill('#campo-nome', 'Cooperado que responde antes');
    await cel.click('#btn-entrar');
    const texto = () => cel.evaluate(() => document.body.textContent);
    t(`${l} o celular abre na pergunta 1 de 3, com as fichas numeradas`, await esperar(cel,
      "document.querySelectorAll('.chip-pergunta').length === 3 && /Pergunta 1 de 3/.test(document.body.textContent)", 15000));

    await cel.fill('#campo-ideia', 'Falta de sucessores nas propriedades');
    await cel.click('#btn-enviar');
    await esperar(cel, "document.querySelectorAll('.ideia-minha').length === 1", 10000);
    await cel.fill('#campo-ideia', 'Credito caro para o cooperado');
    await cel.click('#btn-enviar');
    t(`${l} atingido o teto de 2, o celular passa sozinho à pergunta 2`,
      await esperar(cel, "/Pergunta 2 de 3/.test(document.body.textContent)", 10000));
    t(`${l} e diz por quê`, /enviou as 2 ideias/.test(await texto()));

    await cel.click('.navegacao-perguntas .btn-verde');
    t(`${l} "Pular esta" leva à pergunta 3 sem responder`,
      await esperar(cel, "/Pergunta 3 de 3/.test(document.body.textContent)", 8000));
    await cel.fill('#campo-ideia', 'Perda de estoque no supermercado');
    await cel.click('#btn-enviar');
    await esperar(cel, "document.querySelectorAll('.ideia-minha').length === 1", 10000);
    await cel.click('.navegacao-perguntas .btn-verde');
    const resumo = await esperar(cel, "/Questionário concluído/.test(document.body.textContent)", 8000);
    t(`${l} "Concluir" mostra o resumo: 2 de 3 respondidas`, resumo && /2 de 3/.test(await texto()));
    // As ★ já no resumo, sem o condutor fechar a sala (pedido de 2026-09-04)
    t(`${l} o resumo traz as respostas com ★ para eleger as de maior impacto`, await esperar(cel,
      "document.querySelectorAll('.resumo-pergunta .ideia-votavel').length === 3"
      + " && /até 2 por pergunta/.test(document.body.textContent)", 8000));
    await cel.click('.resumo-pergunta .ideia-votavel');
    t(`${l} a estrela marca a resposta e desconta da pergunta`, await esperar(cel,
      "document.querySelectorAll('.resumo-pergunta .ideia-votavel.votada').length === 1"
      + " && /Resta 1 estrela nesta pergunta/.test(document.body.textContent)", 8000));

    await cel.reload();
    t(`${l} recarregar retoma de onde parou`,
      await esperar(cel, "/Questionário concluído/.test(document.body.textContent)", 15000));
    await cel.click('.chip-pergunta[data-ir-pergunta="1"]');
    t(`${l} a ficha 2 abre a pergunta 2, ainda por responder`, await esperar(cel,
      "/Pergunta 2 de 3/.test(document.body.textContent) && !!document.getElementById('campo-ideia')", 8000));

    await admin.evaluate(() => { SecaoColeta.filtroPergunta = null; App.mostrarSecao('coleta'); });
    t(`${l} na Coleta, cada ideia leva a etiqueta da pergunta`, await esperar(admin,
      "document.querySelectorAll('#secao-coleta .selo-pergunta').length >= 3", 15000));
    t(`${l} e o painel da rodada tem o filtro por pergunta`, await admin.evaluate(() =>
      document.querySelectorAll('#secao-coleta [data-filtro-pergunta] option').length === 4));
    // A fila em BLOCOS por pergunta (pedido de 2026-09-04): três blocos na
    // ordem, o enunciado em cima, e o da pergunta 2 avisando que está vazio.
    // (Ideia sem pergunta de outras provas cai num bloco extra, sem selo.)
    t(`${l} a fila vem em três blocos, a pergunta em cima das respostas`, await admin.evaluate(() => {
      const b = [...document.querySelectorAll('#secao-coleta .bloco-pergunta')]
        .filter((x) => x.querySelector('.titulo-bloco-pergunta .selo-pergunta'));
      const t = b.map((x) => x.querySelector('.tbp-enunciado').textContent);
      return b.length === 3 && /trava o crescimento/.test(t[0]) && /Onde perdemos/.test(t[2])
        && b[0].querySelectorAll('.ficha-nuvem').length === 2
        && /Nenhuma resposta ainda/.test(b[1].textContent);
    }));
    await admin.selectOption('#secao-coleta [data-filtro-pergunta]', { index: 3 });
    t(`${l} o filtro deixa na nuvem só as ideias da pergunta escolhida`, await esperar(admin,
      "document.querySelectorAll('#secao-coleta .nuvem:not(#nuvem-depois) .ficha-nuvem, "
      + "#secao-coleta .nuvem:not(#nuvem-depois) .grupo-caixa').length === 1"
      + " && document.querySelectorAll('#secao-coleta .bloco-pergunta .titulo-bloco-pergunta .selo-pergunta').length === 1", 15000));

    // Tocar na ficha abre a bancada com a PERGUNTA antes da ideia — e a matriz
    // não repete o texto em foco num card "Classificando" (pedido de 2026-09-04)
    await admin.click('#secao-coleta .nuvem:not(#nuvem-depois) .ficha-nuvem');
    t(`${l} a bancada abre pela pergunta que a ideia respondeu`, await esperar(admin,
      "/Onde perdemos dinheiro/.test(document.querySelector('#secao-coleta .bancada-pergunta')?.textContent || '')"
      + " && /Perda de estoque/.test(document.querySelector('#texto-bancada')?.value || '')", 10000));
    t(`${l} e a matriz não repete a ideia em foco`, await admin.evaluate(() =>
      !document.querySelector('#secao-coleta .cartao-foco')
      && !/Classificando/.test(document.querySelector('#secao-coleta .painel-prio')?.textContent || '')));
  } finally {
    await admin.evaluate(async (id) => {
      SecaoColeta.filtroPergunta = null;
      SecaoColeta.selecionado = null;
      if (!id) return;
      const plan = await App.planejamento();
      await App.api(`/api/rodadas/${id}/encerrar`, { planejamento_id: plan.id }).catch(() => {});
    }, rodada?.id || 0);
    await ctxM.close();
    await ctxA.close();
  }
}

async function provasCruzamentoNaSala(browser) {
  const l = '[oficina] Cruzamentos na sala:';
  const ctxA = await browser.newContext({ viewport: { width: 1500, height: 900 }, reducedMotion: 'reduce' });
  const admin = await entrar(ctxA, 'admin', []);
  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce',
  });
  let massa = null;

  try {
    massa = await admin.evaluate(async () => {
      const plan = await App.planejamento();
      const ano = Diag.ano();
      const novo = (categoria, descricao) => App.api('/api/fatores',
        { planejamento_id: plan.id, etapa: 'SWOT', categoria, descricao, ano });
      const f = await novo('FORCA', 'Forca da oficina (prova)');
      const o = await novo('OPORTUNIDADE', 'Oportunidade da oficina (prova)');
      return { plan: plan.id, ano, f: f.id, o: o.id };
    });

    await admin.evaluate(() => App.mostrarSecao('cruzamentos'));
    await esperar(admin,
      "!!document.querySelector('#secao-cruzamentos [data-coluna-categoria=\"ATACAR\"]')", 15000);
    t(`${l} cada bloco tem o 🎤, como as outras análises`, await admin.evaluate(() =>
      !!document.querySelector('#secao-cruzamentos [data-coluna-categoria="ATACAR"] [data-mic]')));

    // O 🎤 pode pedir confirmação (a sala já estar em outro rito): aceitar é o
    // gesto do condutor, e sem tratar o diálogo o clique fica pendurado.
    admin.once('dialog', async (d) => { await d.accept(); });
    await admin.click('#secao-cruzamentos [data-coluna-categoria="ATACAR"] [data-mic]');
    t(`${l} o 🎤 abre a pergunta do bloco para a sala`, await esperar(admin,
      "!!document.querySelector('#secao-cruzamentos [data-mic-fechar]')", 15000));

    const sala = await admin.evaluate(async (m) => {
      const q = await App.api(`/api/quiz?planejamento_id=${m.plan}`);
      return { pin: q.sessao?.pin, perg: q.pergunta?.id };
    }, massa);

    const cel = await ctxM.newPage();
    await cel.goto(`${BASE}/entrar/${sala.pin}`);
    await esperar(cel, "!!document.querySelector('#campo-nome')", 15000);
    await cel.fill('#campo-nome', 'Diretoria no celular');
    await cel.click('#btn-entrar');
    const viuPar = await esperar(cel, "!!document.getElementById('par-interno')", 15000);
    const rotulos = await cel.evaluate(() => ({
      i: document.querySelector('label[for="par-interno"]')?.textContent.trim(),
      e: document.querySelector('label[for="par-externo"]')?.textContent.trim(),
      campo: document.querySelector('label[for="campo-ideia"]')?.textContent.trim(),
      rola: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    }));
    t(`${l} o celular mostra os dois lados do bloco para escolher`,
      viuPar && rotulos.i === 'Forças' && rotulos.e === 'Oportunidades', JSON.stringify(rotulos));
    t(`${l} e o campo de texto pede a estratégia, não uma sugestão solta`,
      rotulos.campo === 'O que fazer com este encontro', JSON.stringify(rotulos.campo));
    t(`${l} a tela do par não rola na horizontal no celular`, rotulos.rola === false);

    // Sem o par, a tela barra ANTES da rede: o servidor recusaria igual, mas a
    // ida e volta com a frase escrita perdida é o que se evita aqui.
    await cel.fill('#campo-ideia', 'Sem par nenhum');
    await cel.click('#btn-enviar');
    await new Promise((r) => setTimeout(r, 600));
    t(`${l} sem escolher o par, a tela avisa antes de ir à rede`,
      /Escolha um item de cada lado/.test(
        await cel.evaluate(() => document.getElementById('aviso-envio')?.textContent || '')));

    await cel.selectOption('#par-interno', String(massa.f));
    await cel.selectOption('#par-externo', String(massa.o));
    // Uma batida do polling (4s) com folga: escolher dois fatores numa lista
    // custa mais que digitar, e perder isso a cada batida inutilizaria a tela.
    await new Promise((r) => setTimeout(r, 5200));
    const sobreviveu = await cel.evaluate(() => ({
      i: document.getElementById('par-interno')?.value,
      e: document.getElementById('par-externo')?.value,
    }));
    t(`${l} o par escolhido sobrevive à batida do polling`,
      String(sobreviveu.i) === String(massa.f) && String(sobreviveu.e) === String(massa.o),
      JSON.stringify(sobreviveu));

    await cel.fill('#campo-ideia', 'Usar a forca para abrir o canal novo ja em H1');
    await cel.click('#btn-enviar');
    await esperar(cel,
      "/enviada/i.test(document.getElementById('aviso-envio')?.textContent || '')", 10000);
    const limpou = await cel.evaluate(() => ({
      i: document.getElementById('par-interno')?.value,
      e: document.getElementById('par-externo')?.value,
    }));
    t(`${l} enviada a proposta, os seletores voltam ao vazio`,
      !limpou.i && !limpou.e, JSON.stringify(limpou));

    // O painel do condutor anda SOZINHO: é o relógio de 4s da sala.
    t(`${l} a proposta aparece no painel do condutor sem ninguém atualizar`,
      await esperar(admin,
        "!!document.querySelector('#secao-cruzamentos .ficha-sugestao .par-voz')", 15000));
    const ficha = await admin.evaluate(() => {
      const f = document.querySelector('#secao-cruzamentos .ficha-sugestao');
      return f ? [...f.querySelectorAll('.par-voz-lado')].map((x) => x.textContent.trim()) : null;
    });
    t(`${l} e a ficha mostra o PAR proposto, que é o que se lê para decidir`,
      ficha?.[0] === 'Forca da oficina (prova)'
      && ficha?.[1] === 'Oportunidade da oficina (prova)', JSON.stringify(ficha));

    await admin.click('#secao-cruzamentos [data-usar-sugestao]');
    await esperar(admin, "!!document.querySelector('.modal.show')", 8000);
    const form = await admin.evaluate(() => ({
      titulo: document.querySelector('.modal.show .modal-title')?.textContent.trim(),
      // Os campos do modal são `#campo-<nome>`: `[name=...]` não existe neste
      // formulário, e o seletor errado falha longe da causa.
      estrategia: document.getElementById('campo-estrategia')?.value,
      marcados: [...document.querySelectorAll('.modal.show input:checked')].map((i) => i.value),
    }));
    t(`${l} o "Usar" abre o formulário como proposta da sala`,
      /Aceitar cruzamento da sala/.test(form.titulo || ''), JSON.stringify(form.titulo));
    t(`${l} com o par JÁ escolhido pela pessoa que respondeu`,
      form.marcados.includes(String(massa.f)) && form.marcados.includes(String(massa.o)),
      JSON.stringify(form.marcados));
    t(`${l} e a estratégia dela como rascunho, para o condutor redigir`,
      form.estrategia === 'Usar a forca para abrir o canal novo ja em H1',
      JSON.stringify(form.estrategia));

    await admin.fill('#campo-rotulo', 'Par da oficina (prova)');
    await admin.click('#modal-salvar');
    t(`${l} salvar cria o cruzamento na coluna do bloco`, await esperar(admin,
      "document.getElementById('secao-cruzamentos').textContent.includes('Par da oficina (prova)')",
      12000));
    const depois = await admin.evaluate(() => {
      const card = [...document.querySelectorAll('#secao-cruzamentos [data-card-cruzamento]')]
        .find((c) => c.textContent.includes('Par da oficina (prova)'));
      return {
        selo: card ? card.textContent.includes('🎤') : null,
        fichas: document.querySelectorAll('#secao-cruzamentos .ficha-sugestao').length,
      };
    });
    t(`${l} o cartão registra a voz da sala que o sustenta`, depois.selo === true);
    t(`${l} e a voz sai do painel, porque virou registro`, depois.fichas === 0,
      `${depois.fichas} ficha(s)`);
  } finally {
    if (massa) {
      await admin.evaluate(async (m) => {
        const cs = await App.api(`/api/cruzamentos?planejamento_id=${m.plan}&ano=${m.ano}`)
          .catch(() => []);
        for (const c of cs.filter((x) => x.rotulo === 'Par da oficina (prova)')) {
          await App.api(`/api/cruzamentos/${c.id}/excluir`, { planejamento_id: m.plan }).catch(() => {});
        }
        await App.api('/api/quiz/encerrar', { planejamento_id: m.plan }).catch(() => {});
        for (const id of [m.f, m.o]) {
          await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: m.plan }).catch(() => {});
        }
        App.mostrarSecao('painel');
      }, massa).catch(() => {});
    }
    await ctxA.close();
    await ctxM.close();
  }
}

/**
 * O cadeado de edição, a duas sessões e com DOIS usuários.
 *
 * Dois usuários é o ponto: com a mesma conta nos dois navegadores todo cadeado
 * é "meu", todas as guardas passam e a prova ficaria verde medindo nada. Por
 * isso ela cria um segundo admin pela própria rota e o apaga no fim.
 *
 * O que se mede aqui é o que só existe no navegador — o contador dentro do
 * formulário, o "+1 minuto" e o nome de quem edita aparecendo sozinho no
 * cartão do outro. A regra propriamente dita (quem grava e quem é recusado) é
 * do servidor, e está provada na `funcional.sh`, seção 9g.
 */
async function provasCadeado(browser) {
  const l = '[cadeado] Edição de um por vez:';
  const ctxA = await browser.newContext({ viewport: { width: 1400, height: 800 }, reducedMotion: 'reduce' });
  const ctxB = await browser.newContext({ viewport: { width: 1400, height: 800 }, reducedMotion: 'reduce' });
  const A = await entrar(ctxA, 'A', []);
  const EMAIL_B = 'bruna.cadeado@teste.local';
  let fator = null, segundo = null;

  try {
    segundo = await A.evaluate((email) => App.api('/api/usuarios', {
      nome: 'Bruna do Cadeado', email, senha: 'trocar123', perfil: 'ADMIN', negocios: [],
    }).then((r) => r.id).catch(() => null), EMAIL_B);
    if (!segundo) { t(`${l} cria o segundo admin da prova`, false); return; }

    const B = await ctxB.newPage();
    await B.goto(`${BASE}/login`);
    await B.fill('#email', EMAIL_B);
    await B.fill('#senha', 'trocar123');
    await B.click('#form-login button[type=submit]');
    await esperar(B, "typeof App !== 'undefined' && !!document.querySelector('#nav-secoes')", 20000);
    await B.evaluate(() => {
      App.contexto.cicloId = 1; App.contexto.negocioId = null; App.contexto.corporativo = true;
    });

    fator = await A.evaluate(async () => {
      const plan = await App.planejamento();
      const f = await App.api('/api/fatores', { planejamento_id: plan.id, etapa: 'SWOT',
        categoria: 'FORCA', descricao: 'Fator do cadeado (prova)', ano: Diag.ano() });
      return f.id;
    });
    for (const p of [A, B]) {
      await p.evaluate(() => App.mostrarSecao('swot'));
      await esperar(p, `!!document.querySelector('#secao-swot [data-card-fator="${fator}"]')`, 15000);
    }

    // 1. Quem abre primeiro vê quanto tempo tem.
    await A.click(`#secao-swot [data-card-fator="${fator}"] [data-editar="${fator}"]`);
    const abriu = await esperar(A, "!!document.querySelector('.modal.show')"
      + " && !document.getElementById('modal-cadeado').classList.contains('d-none')", 8000);
    const tempo = await A.evaluate(() => document.querySelector('.tempo-cadeado')?.textContent || '');
    t(`${l} quem abre o item vê o contador do tempo que tem`,
      abriu && /^0[45]:\d\d$/.test(tempo), tempo || 'sem contador');

    // 2. O segundo NEM ABRE o formulário — e o aviso diz de quem é o item.
    //    Recusar só no salvar seria pior que não recusar: a pessoa escreveria
    //    o parágrafo inteiro para descobrir no fim que ele não ia entrar.
    let aviso = null;
    B.once('dialog', async (d) => { aviso = d.message(); await d.dismiss(); });
    await B.click(`#secao-swot [data-card-fator="${fator}"] [data-editar="${fator}"]`);
    await new Promise((r) => setTimeout(r, 1500));
    const abriuB = await B.evaluate(() => !!document.querySelector('.modal.show'));
    t(`${l} o segundo admin não consegue abrir o mesmo item`,
      abriuB === false && (aviso || '').includes('Administrador'), JSON.stringify(aviso));

    // 3. E o nome aparece no cartão dele SEM ele tocar em nada: é o pulso de 4s
    //    trazendo os cadeados junto com as versões.
    const pintou = await esperar(B,
      `!!document.querySelector('#secao-swot [data-cadeado="fator:${fator}"] .selo-editando')`, 14000);
    const selo = await B.evaluate((f) => document.querySelector(
      `#secao-swot [data-cadeado="fator:${f}"] .selo-editando`)?.textContent || '', fator);
    t(`${l} o cartão do outro mostra sozinho quem está editando`,
      pintou && selo.includes('Administrador'), JSON.stringify(selo));

    // 4. O "+1 minuto" ESTENDE. Escrito da forma óbvia (`NOW() + 60`) ele
    //    ENCURTARIA um cadeado recém-tomado — o botão de ganhar tempo tirando
    //    tempo de quem clicou nele.
    const antes = await A.evaluate(() => Cadeado.restam);
    await A.click('#modal-cadeado [data-mais-tempo]');
    await esperar(A, `Cadeado.restam > ${antes}`, 6000);
    const depois = await A.evaluate(() => Cadeado.restam);
    t(`${l} o +1 minuto estende em vez de encurtar`, depois > antes, `${antes} → ${depois}`);

    // 5. Fechado o formulário, o item volta a ser de todos — e a tela do outro
    //    avisa sozinha, que é o que evita o "já pode?" repetido na reunião.
    await fecharModal(A);
    const soltou = await esperar(B,
      `!document.querySelector('#secao-swot [data-cadeado="fator:${fator}"] .selo-editando')`, 14000);
    t(`${l} fechar solta o item e o aviso some da tela do outro`, soltou);

    await B.click(`#secao-swot [data-card-fator="${fator}"] [data-editar="${fator}"]`);
    const abriuAgora = await esperar(B, "!!document.querySelector('.modal.show')", 8000);
    t(`${l} e aí o segundo admin abre normalmente`, abriuAgora);
    await fecharModal(B);
    // O `soltar` do fechamento é assíncrono: a limpeza abaixo é feita por A, e
    // sem esperar o cadeado de B cair ela seria recusada pela própria guarda —
    // o fator ficaria para trás sujando a análise das rodadas seguintes.
    await esperar(B, 'Cadeado.atual === null', 5000);
    await new Promise((r) => setTimeout(r, 800));
  } finally {
    if (fator) {
      await A.evaluate(async (f) => {
        const plan = await App.planejamento();
        await App.api(`/api/fatores/${f}/excluir`, { planejamento_id: plan.id }).catch(() => {});
      }, fator).catch(() => {});
    }
    if (segundo) {
      await A.evaluate((u) => App.api(`/api/usuarios/${u}/excluir`,
        { transferir_para: 1 }).catch(() => {}), segundo).catch(() => {});
    }
    await ctxA.close();
    await ctxB.close();
  }
}

/**
 * "Tratar" fora da ordem na Coleta (pedido do cliente, 2026-09-02): a fila de
 * tratativa segue a ordem de chegada, mas qualquer cartão de "A tratar" pode
 * ser puxado para a fila na hora. A prova cria três ideias, puxa a ÚLTIMA,
 * confere que a fila a mostra (com o selo no cartão e sem o botão nele), e
 * que o Pular solta o foco e devolve a fila à ordem.
 */
async function provasTratarForaDaOrdem(page, largura) {
  const ids = await page.evaluate(async () => {
    const ano = Diag.ano();
    const cria = async (texto) => (await App.api('/api/coleta',
      { planejamento_id: 1, texto, ano })).id;
    const a = await cria('Ideia da fila — primeira a chegar');
    const b = await cria('Ideia da fila — segunda a chegar');
    const c = await cria('Ideia da fila — a última, que vai ser puxada');
    return { a, b, c };
  });

  await page.evaluate(() => App.mostrarSecao('coleta'));
  const pintou = await esperar(page, `!!document.querySelector('#secao-coleta [data-tratar="${ids.c}"]')`);
  t(`[${largura}] cartão "A tratar" da lista tem o botão Tratar`, pintou);

  if (pintou) {
    const estado = () => page.evaluate((i) => {
      const fila = document.querySelector('#secao-coleta .fila-coleta');
      const card = document.querySelector(`#secao-coleta .lista-ideias [data-card-ideia="${i.c}"]`);
      return {
        filaMostra: fila?.querySelector('.ideia-crua')?.textContent.trim() || '',
        filaDiz: fila?.querySelector('.flex-grow-1')?.textContent.trim() || '',
        seloNaFila: !!card?.querySelector('.badge.text-bg-success'),
        botaoNoCard: !!card?.querySelector('[data-tratar]'),
        comSelo: document.querySelectorAll('#secao-coleta .lista-ideias .card.na-fila').length,
      };
    }, ids);

    const antes = await estado();
    t(`[${largura}] antes: a fila NÃO está na última ideia`, !antes.filaMostra.includes('a última'), antes.filaMostra);
    t(`[${largura}] antes: um só cartão com o selo "na fila"`, antes.comSelo === 1, `${antes.comSelo}`);

    await page.click(`#secao-coleta [data-tratar="${ids.c}"]`);
    const puxou = await esperar(page,
      `(document.querySelector('#secao-coleta .fila-coleta .ideia-crua')?.textContent || '').includes('a última')`);
    t(`[${largura}] Tratar puxa a última ideia para a fila`, puxou);
    const depois = await estado();
    t(`[${largura}] a fila diz que foi puxada da lista`, depois.filaDiz.includes('Puxada da lista'), depois.filaDiz);
    t(`[${largura}] o cartão puxado ganha o selo e perde o botão`, depois.seloNaFila && !depois.botaoNoCard,
      JSON.stringify(depois));
    t(`[${largura}] continua um só cartão com o selo`, depois.comSelo === 1, `${depois.comSelo}`);

    await page.click(`#secao-coleta .fila-coleta [data-pular="${ids.c}"]`);
    const voltou = await esperar(page,
      `!(document.querySelector('#secao-coleta .fila-coleta .ideia-crua')?.textContent || '').includes('a última')`);
    t(`[${largura}] Pular solta o foco e a fila volta à ordem`, voltou);
    const final = await estado();
    t(`[${largura}] e o cartão volta a ter o botão Tratar`, final.botaoNoCard && !final.seloNaFila, JSON.stringify(final));
  }

  await page.evaluate(async (i) => {
    for (const id of [i.a, i.b, i.c]) {
      await App.api(`/api/coleta/${id}/excluir`, { planejamento_id: 1 }).catch(() => {});
    }
  }, ids);
}

/**
 * Menu lateral com tópicos recolhíveis (pedido do cliente, 2026-09-02, no
 * padrão do CRM Agro). O que se mede: os tópicos nascem fechados, salvo o da
 * tela ativa; o cabeçalho abre e fecha com aria-expanded; navegar para uma
 * tela de outro tópico abre esse tópico; e a escolha sobrevive à recarga.
 */
async function provasMenuRecolhido(page, largura) {
  await page.evaluate(() => { localStorage.removeItem('menu.grupos'); App.mostrarSecao('painel'); });
  await page.reload();
  await esperar(page, "typeof App !== 'undefined' && !!document.querySelector('#nav-secoes')", 20000);

  const estado = () => page.evaluate(() => {
    const grupos = [...document.querySelectorAll('#nav-secoes .grupo-menu')];
    return {
      total: grupos.length,
      abertos: grupos.filter((g) => g.classList.contains('aberto')).map((g) => g.dataset.grupo),
      ariaBate: grupos.every((g) =>
        g.querySelector('.cabecalho-grupo').getAttribute('aria-expanded') === String(g.classList.contains('aberto'))),
      // Link dentro de tópico fechado não pode ser alcançável nem visível
      escondidos: grupos.filter((g) => !g.classList.contains('aberto'))
        .every((g) => [...g.querySelectorAll('.nav-link')].every((a) => a.offsetParent === null)),
      ativoVisivel: (() => { const a = document.querySelector('#nav-secoes .nav-link.active'); return !!a && a.offsetParent !== null; })(),
    };
  });

  await page.click('#btn-menu');
  const inicio = await estado();
  t(`[${largura}] seis tópicos, todos fechados no Painel`, inicio.total === 6 && inicio.abertos.length === 0,
    JSON.stringify(inicio.abertos));
  t(`[${largura}] aria-expanded acompanha o estado`, inicio.ariaBate);
  t(`[${largura}] links de tópico fechado não ficam visíveis`, inicio.escondidos);
  t(`[${largura}] o item ativo (Painel) está visível`, inicio.ativoVisivel);

  await page.click('#nav-secoes [data-grupo="gestao"] .cabecalho-grupo');
  const abriu = await estado();
  t(`[${largura}] tocar no cabeçalho abre só aquele tópico`, abriu.abertos.join() === 'gestao' && abriu.ariaBate,
    JSON.stringify(abriu.abertos));
  await page.click('#nav-secoes [data-grupo="gestao"] .cabecalho-grupo');
  const fechou = await estado();
  t(`[${largura}] tocar de novo fecha`, fechou.abertos.length === 0, JSON.stringify(fechou.abertos));

  // Um tópico por vez: abrir um segundo fecha o primeiro (pedido do cliente
  // depois de ver a primeira versão, em que os dois ficavam abertos).
  await page.click('#nav-secoes [data-grupo="gestao"] .cabecalho-grupo');
  await page.click('#nav-secoes [data-grupo="capital"] .cabecalho-grupo');
  const segundo = await estado();
  t(`[${largura}] abrir um segundo tópico fecha o primeiro`, segundo.abertos.join() === 'capital' && segundo.ariaBate,
    JSON.stringify(segundo.abertos));

  // Navegar para uma tela de tópico fechado abre o tópico — pelo caminho que
  // o resto do sistema usa (App.mostrarSecao), não só pelo clique no menu.
  await page.evaluate(() => App.mostrarSecao('swot'));
  const navegou = await estado();
  t(`[${largura}] ir para a SWOT abre o Diagnóstico`, navegou.abertos.join() === 'diagnostico' && navegou.ativoVisivel,
    JSON.stringify(navegou));

  await page.reload();
  await esperar(page, "typeof App !== 'undefined' && !!document.querySelector('#nav-secoes')", 20000);
  const guardado = await page.evaluate(() =>
    [...document.querySelectorAll('#nav-secoes .grupo-menu.aberto')].map((g) => g.dataset.grupo));
  t(`[${largura}] o tópico aberto sobrevive à recarga`, guardado.includes('diagnostico'), JSON.stringify(guardado));
  // Recarregada, a tela volta ao Painel: o menu não pode ter deixado
  // rolagem horizontal nem o corpo travado
  const rola = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  t(`[${largura}] sem rolagem horizontal depois do menu`, !rola);
  await page.evaluate(() => { document.body.classList.remove('menu-aberto'); });
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
  await provasAnoPadrao(page, 'desktop');
  await provasCartaoCruzamento(page);
  await provasCartaoAcao(page, 'desktop');
  await provasResumoStatus(page, 'desktop');
  await provasFilaAcao(page, 'desktop');
  await provasExcluirComOrigens(page);
  await provasCabecalhoProjetos(page, 'desktop');
  await provasPopoverResumo(page, 'desktop');
  await noAnoDaCarga(page);
  await provasBuscaAnalise(page, 'desktop');
  await provasGut(page);
  await noAnoPadrao(page);
  await provasExcluirUsuario(page);
  await provasFiltroResponsavel(page);
  await provasMatrizExecucao(page);
  await provasExclusaoComVinculo(page);
  await provasPlanoDiretoAnalise(page);
  await provasCenarioPlanoAcao(page);
  await provasMoverAnalise(page);
  await provasMoverEntreTabelas(page);
  await provasTratarForaDaOrdem(page, 'desktop');
  await provasMenuRecolhido(page, 'desktop');
  await noAnoDaCarga(page);
  await provasPainelSalaFixo(page, 'desktop');
  await noAnoPadrao(page);
  await provasImpactoNegocio(page);
  await provasDuasTelas(browser);
  await provasCadeado(browser);
  await provasCruzamentoNaSala(browser);
  await provasEtapaNaSala(browser);
  await provasQuestionarioTempestade(browser);
  // Por último no percurso do desktop: ele pinta meia dúzia de seções de lado e
  // mexe no contexto para isso. Provar que devolve tudo é metade do que ele
  // mede — deixá-lo antes das outras faria o rastro dele virar falha delas.
  await noAnoDaCarga(page);
  await provasDossie(page);
  await noAnoPadrao(page);

  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', isMobile: true, hasTouch: true,
  });
  const pageM = await entrar(ctxM, 'celular', erros);
  await percorrer(pageM, 'celular');
  await provasAtalhoCadastros(pageM, 'celular');
  await provasCiclo(pageM, 'celular');
  await provasAnoPadrao(pageM, 'celular');
  await provasCartaoAcao(pageM, 'celular');
  await provasResumoStatus(pageM, 'celular');
  await provasFilaAcao(pageM, 'celular');
  await provasCabecalhoProjetos(pageM, 'celular');
  await noAnoDaCarga(pageM);
  await provasBuscaAnalise(pageM, 'celular');
  await noAnoPadrao(pageM);
  await provasTratarForaDaOrdem(pageM, 'celular');
  await provasMenuRecolhido(pageM, 'celular');
  await noAnoDaCarga(pageM);
  await provasPainelSalaFixo(pageM, 'celular');
  await noAnoPadrao(pageM);

  // O formulário da ação corre em contexto PRÓPRIO, nas duas larguras.
  //
  // Próprio porque a Web Speech API não existe no headless e o microfone só é
  // desenhado se o navegador a expuser: sem simulá-la, a prova das ferramentas
  // do campo de texto ficaria verde por ausência do que ela mede. Simular as
  // DUAS (`SpeechRecognition` e a `webkit`) porque o headless define a nativa,
  // que não fala, e o código prefere ela. E em contexto separado, não nos de
  // cima, para não trocar o layout das outras provas — todo textarea do sistema
  // ganharia um botão que hoje elas medem sem.
  //
  // Nas duas larguras porque as fichas dos dias são o caso em que o celular
  // difere de verdade: sete nomes numa fileira só cabem no computador.
  for (const [rot, vp] of [['desktop', { width: 1500, height: 700 }],
    ['celular', { width: 390, height: 844 }]]) {
    const ctxV = await browser.newContext({
      ...vp && { viewport: vp }, reducedMotion: 'reduce',
      isMobile: rot === 'celular', hasTouch: rot === 'celular',
    });
    // Sem a permissão, `clipboard.writeText` falha calado e o Ctrl+V não cola
    // nada: a prova de colar ficaria verde medindo um campo que ninguém tocou.
    await ctxV.grantPermissions(['clipboard-read', 'clipboard-write']);
    await ctxV.addInitScript(() => {
      class Reconhecedor { start() {} stop() {} }
      window.SpeechRecognition = Reconhecedor;
      window.webkitSpeechRecognition = Reconhecedor;
    });
    const pageV = await entrar(ctxV, rot, erros);
    await provasAcao(pageV, rot);
    await ctxV.close();
  }

  await browser.close();
  process.exit(relatar(ok, bad, erros));
})().catch((e) => { console.error('ERRO:', e.message); process.exit(2); });

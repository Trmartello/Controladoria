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
      { planejamento_id: 1, etapa: 'SWOT', categoria: cat, descricao: desc, ano: 2026 })).id;
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
    return { pr: pr.id, ac: ac.id };
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
        nome: h.querySelector('strong').textContent.trim(), selos: textos(h),
      }));
      const titulo = cabeca.querySelector('strong').getBoundingClientRect();
      const primeiro = cabeca.querySelector('.selo-resumo').getBoundingClientRect();
      return {
        projeto: textos(cabeca),
        frentes,
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
    // Frente A tem 3: 2 atrasadas (67%) e 1 no prazo (33%).
    const a = m.frentes.find((f) => f.nome === 'Frente A');
    t(`[${largura}] o percentual da frente é sobre as ações DELA`,
      a && a.selos.join(' | ') === 'Atrasada: 2 (67%) | No prazo: 1 (33%)', JSON.stringify(a));
    const bb = m.frentes.find((f) => f.nome === 'Frente B');
    t(`[${largura}] cada frente conta a própria carteira`,
      bb && bb.selos.join(' | ') === 'Em andamento: 1 (33%) | No prazo: 1 (33%) | Concluída: 1 (33%)',
      JSON.stringify(bb));
    // Situação sem nenhuma ação não vira selo com zero: numa fila de sete, seis
    // zerados, o que importa se perde no meio.
    t(`[${largura}] situação sem ação nenhuma não aparece`,
      !m.frentes.some((f) => f.selos.some((x) => / 0 \(/.test(x))), JSON.stringify(m.frentes));
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
  // E a frente dele continua com o resumo — o ajuste foi só no nível de cima.
  t(`[${largura}] mas a frente dele continua resumida`,
    await page.evaluate((id) =>
      !!document.querySelector(`[data-projeto="${id}"] .iniciativa-cabeca .selo-resumo`), limpo));

  // Um argumento só: `page.evaluate` não aceita dois (a mensagem de erro é
  // clara, mas só aparece quando a prova já rodou inteira).
  await page.evaluate(async (alvos) => {
    for (const id of alvos) await App.api(`/api/projetos/${id}/excluir`, { planejamento_id: 1 });
  }, [ids.pr, limpo]);
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
        { planejamento_id: 1, etapa: 'SWOT', categoria: 'FRAQUEZA', descricao: desc, ano: 2026 });
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
  }

  await page.evaluate(async (alvos) => {
    for (const id of alvos) await App.api(`/api/fatores/${id}/excluir`, { planejamento_id: 1 });
  }, ids);
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
  await provasCartaoCruzamento(page);
  await provasCartaoAcao(page, 'desktop');
  await provasResumoStatus(page, 'desktop');
  await provasFilaAcao(page, 'desktop');
  await provasGut(page);
  await provasAcao(page);

  const ctxM = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: 'reduce', isMobile: true, hasTouch: true,
  });
  const pageM = await entrar(ctxM, 'celular', erros);
  await percorrer(pageM, 'celular');
  await provasAtalhoCadastros(pageM, 'celular');
  await provasCiclo(pageM, 'celular');
  await provasCartaoAcao(pageM, 'celular');
  await provasResumoStatus(pageM, 'celular');
  await provasFilaAcao(pageM, 'celular');

  await browser.close();
  process.exit(relatar(ok, bad, erros));
})().catch((e) => { console.error('ERRO:', e.message); process.exit(2); });

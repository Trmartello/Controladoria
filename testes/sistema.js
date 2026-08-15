// Bateria de sistema: percorre as 16 seções em desktop e celular e afirma que
// cada uma PINTA de verdade — não só que a casca do shell existe. Registra todo
// erro de página e de console: um `pageerror` numa seção é falha, mesmo que a
// tela pareça certa.
//
//   node testes/sistema.js
const { chromiumExec, playwright, esperar, entrar, relatar } = require('./comum');

const ok = [], bad = [], erros = [];
const t = (nome, cond, extra = '') => (cond ? ok : bad).push(nome + (extra ? ` — ${extra}` : ''));

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
  await provasCabecalhoProjetos(page, 'desktop');
  await provasPopoverResumo(page, 'desktop');
  await provasBuscaAnalise(page, 'desktop');
  await provasGut(page);
  await provasExcluirUsuario(page);
  await provasFiltroResponsavel(page);

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
  await provasCabecalhoProjetos(pageM, 'celular');
  await provasBuscaAnalise(pageM, 'celular');

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

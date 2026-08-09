// Projetos estratégicos e operacionais: desdobramentos 5W2H + diário de bordo.

const STATUS_ROTULOS = {
  NAO_INICIADO: ['Não iniciado', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluído', 'text-bg-success'],
  ATRASADO: ['Atrasado', 'text-bg-danger'],
  CANCELADO: ['Cancelado', 'text-bg-secondary'],
};
const OPCOES_STATUS = Object.entries(STATUS_ROTULOS)
  .map(([valor, [rotulo]]) => ({ valor, rotulo }));

// Ações: "No prazo" e "Atrasada" saem da data-limite; os demais são manuais
const STATUS_ACAO = {
  NAO_INICIADO: ['No prazo', 'text-bg-info'],
  ATRASADO: ['Atrasada', 'text-bg-danger'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluída', 'text-bg-success'],
  CANCELADO: ['Cancelada', 'text-bg-secondary'],
  PAUSADO: ['Pausada', 'text-bg-warning'],
  AGUARDANDO_VALIDACAO: ['Aguardando validação', 'text-bg-light border'],
};
const STATUS_MANUAIS = ['EM_ANDAMENTO', 'CONCLUIDO', 'CANCELADO', 'PAUSADO', 'AGUARDANDO_VALIDACAO'];
const PRIORIDADES = {
  ALTA: ['Alta', '#b3261e'], MEDIA: ['Média', '#b08d4f'], BAIXA: ['Baixa', '#2c7fb8'],
};
// 1 = segunda … 7 = domingo (mesma numeração do PHP: date('N'))
const DIAS_SEMANA = [
  [1, 'Segunda-feira'], [2, 'Terça-feira'], [3, 'Quarta-feira'], [4, 'Quinta-feira'],
  [5, 'Sexta-feira'], [6, 'Sábado'], [7, 'Domingo'],
];
const STATUS_INICIATIVA = {
  ABERTA: ['Aberta', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDA: ['Concluída', 'text-bg-success'],
};

const SecaoProjetos = {
  plan: null,
  cascata: null,
  responsaveis: [],
  comentariosAbertos: null, // { refTipo, refId }
  // Recolhidos guardam quem está fechado; projeto e iniciativa começam
  // abertos e a escolha do usuário sobrevive aos recarregamentos da seção
  iniciativasFechadas: new Set(),
  projetosFechados: new Set(),
  detalhesAbertos: new Set(), // quem está com o detalhe (a seta) aberto
  // id da ação que a seção deve realçar ao abrir (vindo do "Virou ação ↗")
  destacarAcao: null,

  /**
   * Realça a ação alcançada pela navegação vinda da SWOT.
   *
   * Revelar vem antes de realçar: a ação pode estar dentro de um projeto ou de
   * uma iniciativa recolhidos, e piscar um cartão escondido não leva ninguém a
   * lugar nenhum. O `d-none` sai direto no DOM em vez de mexer nos conjuntos de
   * recolhidos — a preferência de quem recolheu continua valendo no próximo
   * carregamento, e o desvio é só desta visita.
   */
  aplicarDestaqueAcao(el) {
    const id = this.destacarAcao;
    if (!id) return;
    this.destacarAcao = null;
    const card = el.querySelector(`[data-card-acao="${id}"]`);
    if (!card) return;
    for (let pai = card.parentElement; pai && pai !== el; pai = pai.parentElement) {
      pai.classList.remove('d-none');
    }
    card.classList.add('card-destacado');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => card.classList.remove('card-destacado'), 2600);
  },

  /**
   * Alternador de detalhe de um item (projeto, iniciativa ou ação).
   *
   * É um CHEVRON, não as palavras "mostrar mais"/"mostrar menos" que estavam
   * aqui: na linha do cartão de ação ele divide espaço com o selo de situação e
   * a barra de progresso, e doze caracteres de texto ali custavam à barra
   * justamente a largura que ela usa para ser lida de relance. A seta gira com
   * o estado e o significado continua dito — em `aria-label`, para quem usa
   * leitor de tela, e em `title`, para quem passa o mouse.
   *
   * O mesmo botão serve aos três níveis: dois jeitos de expandir na mesma tela
   * seriam duas coisas para aprender.
   */
  botaoMais(chave, aberto) {
    const rotulo = aberto ? 'Recolher detalhes' : 'Mostrar detalhes';
    return `<button type="button" class="btn-mais" data-mais="${chave}"
      aria-expanded="${aberto}" aria-controls="detalhe-${chave}"
      aria-label="${rotulo}" title="${rotulo}">
      <svg width="16" height="16" aria-hidden="true" focusable="false"><use href="#i-chevron"/></svg>
    </button>`;
  },

  ligarBotoesMais(el) {
    el.querySelectorAll('[data-mais]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const chave = b.dataset.mais;
      const alvo = el.querySelector(`[data-detalhe="${chave}"]`);
      const abrir = this.detalhesAbertos.has(chave);
      if (abrir) this.detalhesAbertos.delete(chave);
      else this.detalhesAbertos.add(chave);
      alvo.classList.toggle('d-none', abrir);
      // O que muda é o ESTADO, não o conteúdo: quem gira a seta é o CSS pelo
      // `aria-expanded`. Trocar o texto do botão apagaria o `<svg>` de dentro.
      const rotulo = abrir ? 'Mostrar detalhes' : 'Recolher detalhes';
      b.setAttribute('aria-expanded', String(!abrir));
      b.setAttribute('aria-label', rotulo);
      b.setAttribute('title', rotulo);
    }));
  },

  /**
   * Até onde a tela está aberta hoje. Serve para acender o botão do nível —
   * e devolve vazio quando o usuário abriu/fechou itens à mão e o estado não
   * corresponde a nenhum dos três: nesse caso nenhum botão fica aceso, em vez
   * de mentir que a tela está num nível que ela não está.
   *
   * A ordem importa: com tudo recolhido as duas condições valem, e o nível é
   * PROJETOS — o mais fechado dos dois.
   */
  nivelAtual(projetos) {
    if (!projetos.length) return '';
    const iniciativas = projetos.flatMap((p) => p.iniciativas || []);
    if (projetos.every((p) => this.projetosFechados.has(p.id))) return 'PROJETOS';
    // `every` de lista vazia é verdadeiro: sem nenhuma iniciativa cadastrada,
    // "frentes recolhidas" não significa nada e o nível é o aberto
    if (iniciativas.length && iniciativas.every((i) => this.iniciativasFechadas.has(i.id))) {
      return 'FRENTES';
    }
    const nadaFechado = !projetos.some((p) => this.projetosFechados.has(p.id))
      && !iniciativas.some((i) => this.iniciativasFechadas.has(i.id));
    return nadaFechado ? 'ACOES' : '';
  },

  /**
   * Reacende o botão do nível depois de alguém abrir/recolher um item à mão.
   * Os acordeões mexem no DOM sem recarregar a seção (é o que os deixa
   * instantâneos), então sem isto o grupo continuaria mostrando "Ações" com as
   * ações já escondidas — o controle mentiria sobre a própria tela.
   */
  pintarNiveis(el, projetos) {
    const nivel = this.nivelAtual(projetos);
    el.querySelectorAll('[data-nivel]').forEach((b) => {
      const ativo = b.dataset.nivel === nivel;
      b.classList.toggle('btn-verde', ativo);
      b.classList.toggle('btn-outline-secondary', !ativo);
      b.setAttribute('aria-pressed', String(ativo));
    });
  },

  /**
   * Abre/recolhe a tela inteira até o nível pedido. Sempre parte do zero (os
   * dois conjuntos limpos), senão um item recolhido à mão antes sobreviveria ao
   * clique e o nível escolhido não seria o que a tela mostra.
   */
  aplicarNivel(nivel, projetos) {
    this.projetosFechados.clear();
    this.iniciativasFechadas.clear();
    if (nivel !== 'ACOES') {
      // Frentes e Projetos escondem as ações; Projetos esconde também as
      // frentes. Recolher a iniciativa é justamente o que oculta as ações dela.
      projetos.forEach((p) => {
        (p.iniciativas || []).forEach((i) => this.iniciativasFechadas.add(i.id));
        if (nivel === 'PROJETOS') this.projetosFechados.add(p.id);
      });
    }
    this.carregar();
  },

  /**
   * Panorama de um conjunto de ações: barra da média, o percentual e quantas
   * estão atrasadas. É o MESMO bloco no projeto e na iniciativa — escritos
   * separados, os dois níveis divergiriam na primeira mudança de regra (o
   * "atrasada(s)" do projeto já contava por status, e a iniciativa não contava
   * nada). A média é aritmética simples entre as ações, como sempre foi no
   * projeto: ponderar por esforço exigiria um peso que o cadastro não tem.
   */
  panorama(acoes, marcaMedia, marcaBarra, classe = '', titulo = 'Progresso médio das ações') {
    const media = acoes.length
      ? Math.round(acoes.reduce((s, a) => s + Number(a.progresso), 0) / acoes.length)
      : 0;
    const atrasadas = acoes.filter((a) => a.status === 'ATRASADO').length;
    return `<div class="d-flex align-items-center gap-2 mt-1 panorama-execucao ${classe}">
      <div class="faixa-progresso flex-grow-1" title="${Modal.esc(titulo)}">
        <span ${marcaBarra} style="width:${media}%"></span>
      </div>
      <span class="valor-progresso" ${marcaMedia}>${media}%</span>
      ${atrasadas ? `<span class="badge text-bg-danger">${atrasadas} atrasada(s)</span>` : ''}
    </div>`;
  },

  // Período escolhido no calendário; textos antigos (prazo/quando_) seguem valendo
  periodo(inicio, fim, legado) {
    const br = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : null);
    const de = br(inicio);
    const ate = br(fim);
    if (de && ate) return `${de} a ${ate}`;
    if (de) return `a partir de ${de}`;
    if (ate) return `até ${ate}`;
    return legado || null;
  },

  // Bloco da iniciativa (frente de trabalho) com as ações dentro
  blocoIniciativa(p, ini) {
    const acoes = ini.acoes || [];
    const feitas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
    const [rotIni, classeIni] = STATUS_INICIATIVA[ini.status] || [ini.status, 'text-bg-light'];
    const aberta = !this.iniciativasFechadas.has(ini.id);
    const cartoes = acoes.map((a) => this.cartaoAcao(p, ini, a)).join('');
    // Recolhida mostra só título e situação; o resto vai atrás da seta
    const chave = `ini-${ini.id}`;
    const detalhado = this.detalhesAbertos.has(chave);
    // Panorama da frente de trabalho, o mesmo do projeto um nível acima: média
    // do progresso das ações DELA e quantas estão atrasadas. Sem isto, saber
    // como vai uma iniciativa exigia somar as barras das ações com o olho — e,
    // com a iniciativa recolhida, não havia número nenhum.
    const panorama = this.panorama(acoes, `data-media-ini="${ini.id}"`, `data-barra-ini="${ini.id}"`,
      'panorama-iniciativa', 'Progresso médio das ações desta iniciativa');
    return `<div class="iniciativa mb-2" data-iniciativa="${ini.id}">
      <div class="d-flex align-items-center gap-2 flex-wrap iniciativa-cabeca" data-abrir-ini="${ini.id}">
        <span class="seta-iniciativa">${aberta ? '▾' : '▸'}</span>
        <strong class="small flex-grow-1">${Modal.esc(ini.titulo)}</strong>
        <span class="badge ${classeIni}">${rotIni}</span>
        ${this.botaoMais(chave, detalhado)}
      </div>
      ${panorama}
      <div class="detalhe-item ${detalhado ? '' : 'd-none'}" id="detalhe-${chave}" data-detalhe="${chave}">
        ${ini.descricao ? `<div class="small text-muted mt-1">${Modal.esc(ini.descricao)}</div>` : ''}
        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
          <span class="badge text-bg-light border" title="Ações concluídas">${feitas}/${acoes.length} ações</span>
          ${App.podeEditar() ? `
            <button class="btn btn-sm btn-verde" data-nova-acao="${ini.id}" data-proj="${p.id}">+ Ação</button>
            <button class="btn btn-sm btn-outline-secondary" data-editar-ini="${ini.id}" data-proj="${p.id}"
              title="Editar iniciativa" aria-label="Editar iniciativa">✎</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir-ini="${ini.id}"
              title="Excluir iniciativa" aria-label="Excluir iniciativa">×</button>` : ''}
        </div>
      </div>
      <div class="acoes-iniciativa ${aberta ? '' : 'd-none'}">
        ${cartoes || '<div class="text-muted small">Nenhuma ação nesta iniciativa.</div>'}
      </div>
    </div>`;
  },

  cartaoAcao(p, ini, a) {
    const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
    const [rotPrio, corPrio] = PRIORIDADES[a.prioridade] || PRIORIDADES.MEDIA;
    const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
    // O "Como" sai daqui: ele ganhou linha própria, logo abaixo do "o quê".
    // Os dois juntos são a ação — o que se faz e por onde —, e espremidos no
    // meio de sete metadados separados por ponto o caminho virava rodapé.
    const detalhes = [
      a.onde && `Onde: ${a.onde}`,
      a.por_que && `Por quê: ${a.por_que}`,
      a.quanto !== null && a.quanto !== undefined && `Quanto: R$ ${Number(a.quanto).toLocaleString('pt-BR')}`,
    ].filter(Boolean).map(Modal.esc).join(' · ');
    const timeline = this.comentariosAbertos?.refTipo === 'DESDOBRAMENTO' && this.comentariosAbertos?.refId === a.id
      ? `<div id="comentarios-DESDOBRAMENTO-${a.id}" class="mt-2"></div>` : '';
    const repeticao = a.recorrencia === 'SEMANAL'
      ? `toda ${(DIAS_SEMANA.find(([v]) => v == a.recorrencia_dia) || [, ''])[1].toLowerCase()}`
      : a.recorrencia === 'MENSAL' ? `todo dia ${a.recorrencia_dia}` : '';
    // Cinco linhas: situação+progresso+seta, o quê, como, metadados e o rodapé
    // de botões. As três últimas ficam atrás da seta.
    const chave = `acao-${a.id}`;
    const detalhado = this.detalhesAbertos.has(chave);
    // Prazo → Quem → Prioridade, nessa ordem: são as três perguntas que se faz
    // de um cartão de ação, e nessa sequência. O que sobra (repetição, onde,
    // por quê, quanto) segue na mesma linha, depois — tirá-los da tela para
    // cumprir a ordem seria perder informação que alguém digitou.
    const extras = [
      prazo && `<strong>Prazo:</strong> ${Modal.esc(prazo)}`,
      a.quem && `<strong>Quem:</strong> ${Modal.esc(a.quem)}`,
      `<strong>Prioridade:</strong> ${rotPrio}`,
      repeticao && `<strong>Repete:</strong> ${repeticao}`,
      detalhes,
    ].filter(Boolean).join(' · ');
    return `<div class="card acao-card mb-2" style="--cor-prio:${corPrio}" data-card-acao="${a.id}">
      <div class="card-body py-2 px-2">
        <!-- Linha 1: situação, progresso e o expandir. O selo e o chevron não
             encolhem (flex-shrink-0); quem cede largura é a barra, que é a
             única peça aqui que se lê por proporção e não por texto. -->
        <div class="d-flex align-items-center gap-2 linha-acao-topo">
          <span class="badge ${classe} flex-shrink-0">${rotulo}</span>
          ${App.podeEditar() ? `
          <input type="range" class="faixa-verde flex-grow-1" min="0" max="100" step="1"
            style="--pct:${a.progresso}%" value="${a.progresso}"
            data-progresso="${a.id}" data-proj="${p.id}"
            title="Arraste para ajustar o progresso" aria-label="Progresso da ação">
          <span class="valor-progresso" data-rotulo="${a.id}">${a.progresso}%</span>` : `
          <div class="faixa-progresso flex-grow-1" title="${a.progresso}%">
            <span style="width:${a.progresso}%"></span>
          </div>
          <span class="valor-progresso">${a.progresso}%</span>`}
          ${this.botaoMais(chave, detalhado)}
        </div>
        <!-- Linha 2: o quê -->
        <div class="small fw-bold mt-2">${Modal.esc(a.o_que)}</div>
        <div class="detalhe-item ${detalhado ? '' : 'd-none'}" id="detalhe-${chave}" data-detalhe="${chave}">
          <!-- Linha 3: como -->
          ${a.como ? `<div class="small"><strong>Como:</strong> ${Modal.esc(a.como)}</div>` : ''}
          <!-- Linha 4: metadados -->
          ${extras ? `<div class="small text-muted mt-1">${extras}</div>` : ''}
          <!-- Linha 5: rodapé — conversa à esquerda, edição à direita. O
               ms-auto é o que segura a direita quando o grupo da esquerda é só
               um botão, e sobrevive à quebra de linha no celular. -->
          <div class="d-flex justify-content-between align-items-center gap-1 flex-wrap mt-2">
            <button class="btn btn-sm btn-outline-success" data-comentarios="DESDOBRAMENTO:${a.id}">Comentários</button>
            ${App.podeEditar() ? `
              <span class="ms-auto d-flex gap-1">
                <button class="btn btn-sm btn-outline-secondary" data-editar-desd="${a.id}" data-proj="${p.id}"
                  title="Editar ação" aria-label="Editar ação">✎</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-desd="${a.id}"
                  title="Excluir ação" aria-label="Excluir ação">×</button>
              </span>` : ''}
          </div>
        </div>
        ${timeline}
      </div>
    </div>`;
  },

  async carregar() {
    const el = document.getElementById('secao-projetos');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    const [projetos, cascata, responsaveis, ideiasAcao, fatoresAcao, cruzamentosAcao] = await Promise.all([
      App.api(`/api/projetos?planejamento_id=${this.plan.id}`),
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      App.api(`/api/responsaveis?planejamento_id=${this.plan.id}`),
      App.api(`/api/coleta/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
      App.api(`/api/fatores/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
      App.api(`/api/cruzamentos/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
    ]);
    // Uma fila só: a origem muda o selo e o campo que fecha o vínculo, não o
    // lugar onde a pendência aparece. Duas listas separadas fariam quem
    // acompanha o plano ter de olhar em dois lugares para a mesma pergunta —
    // "o que ainda não virou ação?".
    const pendentes = [
      ...ideiasAcao.map((i) => ({ ...i, origem: 'COLETA', chave: `c${i.id}` })),
      ...fatoresAcao.map((f) => ({ ...f, chave: `f${f.id}` })),
      ...cruzamentosAcao.map((c) => ({ ...c, chave: `x${c.id}` })),
    ];
    this.cascata = cascata;
    this.responsaveis = responsaveis;
    this.projetos = projetos;   // guardado para o seletor de iniciativa ao converter ideias

    const badge = (status) => {
      const [rotulo, classe] = STATUS_ROTULOS[status] || [status, 'text-bg-light'];
      return `<span class="badge ${classe}">${rotulo}</span>`;
    };

    const cartoes = projetos.map((p) => {
      // O prazo é consequência das ações: menor início e maior fim entre elas
      const detalhes = [
        p.ano && `<strong>Ano:</strong> ${p.ano}`,
        p.responsavel && `<strong>Responsável:</strong> ${Modal.esc(p.responsavel)}`,
        this.periodo(p.data_inicio, p.data_fim, p.prazo)
          && `<strong>Prazo (das ações):</strong> ${Modal.esc(this.periodo(p.data_inicio, p.data_fim, p.prazo))}`,
      ].filter(Boolean).join(' · ');
      const descricao = p.descricao
        ? `<div class="small text-muted texto-fator mt-1">${Modal.esc(p.descricao)}</div>` : '';
      const origem = p.escolha_origem
        ? `<div class="small text-muted mt-1">↳ Escolha da cascata: “${Modal.esc(p.escolha_origem.slice(0, 90))}”</div>` : '';
      const iniciativas = (p.iniciativas || []).map((ini) => this.blocoIniciativa(p, ini)).join('');

      const timelineProjeto = this.comentariosAbertos?.refTipo === 'PROJETO' && this.comentariosAbertos?.refId === p.id
        ? `<div id="comentarios-PROJETO-${p.id}" class="mt-2"></div>` : '';

      // Panorama do projeto: soma no cabeçalho o que está dentro dele
      const acoes = p.desdobramentos || [];
      const concluidas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
      const aberto = !this.projetosFechados.has(p.id);

      // Recolhido mostra só título, situação e a barra; o resto atrás da seta
      const chave = `proj-${p.id}`;
      const detalhado = this.detalhesAbertos.has(chave);
      return `<div class="card mb-3" data-projeto="${p.id}">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="projeto-cabeca flex-grow-1" data-abrir-proj="${p.id}" role="button" tabindex="0">
              <span class="seta-projeto">${aberto ? '▾' : '▸'}</span>
              <strong>${Modal.esc(p.titulo)}</strong>
              ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning ms-1">Prioritário</span>' : ''}
              ${badge(p.status)}
            </div>
            ${this.botaoMais(chave, detalhado)}
          </div>
          ${this.panorama(acoes, 'data-media-projeto', 'data-barra-projeto', 'panorama-projeto',
            'Progresso médio das ações do projeto')}
          <div class="detalhe-item ${detalhado ? '' : 'd-none'}" id="detalhe-${chave}" data-detalhe="${chave}">
            ${descricao}
            ${detalhes ? `<div class="small text-muted mt-1">${detalhes}</div>` : ''}
            <div class="small text-muted mt-1">${(p.iniciativas || []).length} iniciativa(s) ·
              ${concluidas}/${acoes.length} ações concluídas</div>
            ${origem}
            <div class="d-flex gap-1 flex-wrap mt-2">
              <button class="btn btn-sm btn-outline-success" data-comentarios="PROJETO:${p.id}">Comentários</button>
              ${App.podeEditar() ? `
                <button class="btn btn-sm btn-verde" data-nova-ini="${p.id}">+ Iniciativa</button>
                <button class="btn btn-sm btn-outline-secondary" data-editar-proj="${p.id}">Editar</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-proj="${p.id}">×</button>` : ''}
            </div>
          </div>
          <div class="mt-3 iniciativas-projeto ${aberto ? '' : 'd-none'}">
            ${iniciativas || '<div class="text-muted small">Nenhuma iniciativa cadastrada. Crie uma frente de trabalho para organizar as ações.</div>'}
          </div>
          ${timelineProjeto}
        </div>
      </div>`;
    }).join('');

    // Até onde a tela mostra. Eram só dois extremos — tudo aberto ou o
    // "Recolher tudo", que sumia com projetos E frentes de uma vez. Faltava
    // justamente o meio: recolher as AÇÕES e ficar com o retrato dos projetos e
    // das suas frentes, que é como se lê o plano numa reunião.
    const nivel = this.nivelAtual(projetos);
    const botaoNivel = (valor, rotulo, dica) =>
      `<button type="button" class="btn ${nivel === valor ? 'btn-verde' : 'btn-outline-secondary'}"
        data-nivel="${valor}" title="${Modal.esc(dica)}"
        aria-pressed="${nivel === valor}">${rotulo}</button>`;
    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Projetos — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          ${projetos.length ? `<span class="small text-muted d-none d-sm-inline">Mostrar até</span>
          <div class="btn-group btn-group-sm niveis-visao" role="group" aria-label="Mostrar até">
            ${botaoNivel('ACOES', 'Ações', 'Abre tudo: projetos, frentes e as ações de cada uma')}
            ${botaoNivel('FRENTES', 'Frentes', 'Recolhe as ações — projetos e frentes continuam à vista, com o percentual de cada um')}
            ${botaoNivel('PROJETOS', 'Projetos', 'Recolhe tudo: só os projetos, com o percentual e os atrasos')}
          </div>` : ''}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-proj">+ Novo projeto</button>' : ''}
        </div>
      </div>
      <p class="text-muted">Toque no título para recolher e expandir um item; use a seta (⌄) para
        ver o detalhe.${App.podeEditar() ? ' <strong>Toque duas vezes</strong> num cartão para editá-lo.' : ''}</p>
      ${this.cartaoIdeiasAcao(pendentes)}
      <div class="pt-2">${cartoes || '<div class="text-muted">Nenhum projeto cadastrado.</div>'}</div>`;

    el.querySelectorAll('[data-virar-acao]').forEach((b) => b.addEventListener('click', () =>
      this.modalConverterAcao(pendentes.find((p) => p.chave === b.dataset.virarAcao))));

    el.querySelectorAll('[data-nivel]').forEach((b) =>
      b.addEventListener('click', () => this.aplicarNivel(b.dataset.nivel, projetos)));

    // Acordeão do projeto (clicar no cabeçalho abre/fecha as iniciativas)
    el.querySelectorAll('[data-abrir-proj]').forEach((c) => {
      const alternar = () => {
        const id = parseInt(c.dataset.abrirProj, 10);
        if (this.projetosFechados.has(id)) this.projetosFechados.delete(id);
        else this.projetosFechados.add(id);
        const cartao = el.querySelector(`[data-projeto="${id}"]`);
        const fechado = this.projetosFechados.has(id);
        cartao.querySelector('.iniciativas-projeto').classList.toggle('d-none', fechado);
        cartao.querySelector('.seta-projeto').textContent = fechado ? '▸' : '▾';
        this.pintarNiveis(el, projetos);
      };
      c.addEventListener('click', (ev) => {
        if (ev.target.closest('button, a, input')) return;
        alternar();
      });
      c.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); alternar(); }
      });
    });

    this.ligarBotoesMais(el);
    this.aplicarDestaqueAcao(el);

    el.querySelectorAll('[data-comentarios]').forEach((b) => b.addEventListener('click', () => {
      const [refTipo, refId] = b.dataset.comentarios.split(':');
      const mesmo = this.comentariosAbertos?.refTipo === refTipo
        && this.comentariosAbertos?.refId === Number(refId);
      this.comentariosAbertos = mesmo ? null : { refTipo, refId: Number(refId) };
      this.carregar();
    }));
    if (this.comentariosAbertos) this.renderComentarios();

    if (!App.podeEditar()) return;

    document.getElementById('btn-novo-proj').addEventListener('click', () => this.modalProjeto(null, projetos));
    el.querySelectorAll('[data-editar-proj]').forEach((b) => b.addEventListener('click', () =>
      this.modalProjeto(projetos.find((p) => p.id == b.dataset.editarProj), projetos)));
    el.querySelectorAll('[data-excluir-proj]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir o projeto e todos os seus desdobramentos?')) return;
      try {
        await App.api(`/api/projetos/${b.dataset.excluirProj}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-nova-ini]').forEach((b) => b.addEventListener('click', () =>
      this.modalIniciativa(parseInt(b.dataset.novaIni, 10), null)));
    el.querySelectorAll('[data-editar-ini]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      this.modalIniciativa(proj.id, proj.iniciativas.find((i) => i.id == b.dataset.editarIni));
    }));
    el.querySelectorAll('[data-excluir-ini]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      if (!confirm('Excluir a iniciativa e todas as ações dentro dela?')) return;
      try {
        await App.api(`/api/iniciativas/${b.dataset.excluirIni}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-nova-acao]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      this.modalDesdobramento(parseInt(b.dataset.proj, 10), null, parseInt(b.dataset.novaAcao, 10));
    }));
    el.querySelectorAll('[data-editar-desd]').forEach((b) => b.addEventListener('click', () => {
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      const acao = proj.desdobramentos.find((dd) => dd.id == b.dataset.editarDesd);
      // A barra do cartão pode ter mudado o progresso depois da carga da lista;
      // vale o que está na tela, senão o modal regravaria o valor antigo
      const barra = el.querySelector(`[data-progresso="${acao.id}"]`);
      const atual = barra ? { ...acao, progresso: Number(barra.value) } : acao;
      this.modalDesdobramento(proj.id, atual, acao.iniciativa_id);
    }));

    // Duplo clique (duplo toque no celular) abre a edição do cartão sob o
    // cursor — o mesmo que o ✎, sem precisar abrir o detalhe para
    // alcançá-lo. Os botões continuam onde estavam: são o caminho de quem usa
    // teclado, que não tem duplo clique.
    //
    // O listener vai em CADA cartão de projeto, não na seção: `el` sobrevive
    // aos recarregamentos (as seções não são destruídas, só ganham `d-none`) e
    // um listener nele empilharia uma cópia por `carregar()` — na terceira
    // visita à tela, um duplo clique abriria três modais.
    el.querySelectorAll('[data-projeto]').forEach((cartaoProj) => {
      cartaoProj.addEventListener('dblclick', (ev) => {
        // Controle é controle: dois cliques na barra de progresso, num botão ou
        // num link são interação com ele. O diário mora DENTRO do cartão e tem
        // os próprios registros — abrir a edição do projeto ao clicar duas
        // vezes num registro seria surpresa.
        if (ev.target.closest('button, a, input, select, textarea, label, [id^="comentarios-"]')) return;
        // O duplo clique seleciona a palavra sob o cursor, e ela ficaria acesa
        // atrás do modal
        window.getSelection?.()?.removeAllRanges();

        const proj = projetos.find((p) => p.id == cartaoProj.dataset.projeto);
        if (!proj) return;

        // Do mais interno para o mais externo: os três níveis são aninhados no
        // DOM, e sem esta ordem um duplo clique na ação abriria o projeto.
        const naAcao = ev.target.closest('[data-card-acao]');
        if (naAcao) {
          const acao = (proj.desdobramentos || []).find((dd) => dd.id == naAcao.dataset.cardAcao);
          if (!acao) return;
          // Mesma regra do ✎: vale o progresso que está na TELA, que a barra
          // pode ter mudado depois da carga da lista
          const barra = el.querySelector(`[data-progresso="${acao.id}"]`);
          const atual = barra ? { ...acao, progresso: Number(barra.value) } : acao;
          this.modalDesdobramento(proj.id, atual, acao.iniciativa_id);
          return;
        }
        const naIniciativa = ev.target.closest('[data-iniciativa]');
        if (naIniciativa) {
          const ini = (proj.iniciativas || []).find((i) => i.id == naIniciativa.dataset.iniciativa);
          if (ini) this.modalIniciativa(proj.id, ini);
          return;
        }
        this.modalProjeto(proj, projetos);
      });
    });
    // Acordeão das iniciativas (clicar no cabeçalho abre/fecha)
    el.querySelectorAll('[data-abrir-ini]').forEach((c) => c.addEventListener('click', (ev) => {
      if (ev.target.closest('button')) return;
      const id = parseInt(c.dataset.abrirIni, 10);
      if (this.iniciativasFechadas.has(id)) this.iniciativasFechadas.delete(id);
      else this.iniciativasFechadas.add(id);
      const bloco = el.querySelector(`[data-iniciativa="${id}"]`);
      bloco.querySelector('.acoes-iniciativa').classList.toggle('d-none', this.iniciativasFechadas.has(id));
      bloco.querySelector('.seta-iniciativa').textContent = this.iniciativasFechadas.has(id) ? '▸' : '▾';
      this.pintarNiveis(el, projetos);
    }));
    el.querySelectorAll('[data-excluir-desd]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este desdobramento?')) return;
      await App.api(`/api/desdobramentos/${b.dataset.excluirDesd}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));

    // Progresso ajustado arrastando a própria barra do cartão: a barra e o
    // rótulo acompanham o dedo e a gravação sai só ao soltar
    el.querySelectorAll('[data-progresso]').forEach((r) => {
      const id = r.dataset.progresso;
      const rotulo = el.querySelector(`[data-rotulo="${id}"]`);
      const pintar = (v) => {
        r.style.setProperty('--pct', `${v}%`);
        rotulo.textContent = `${v}%`;
      };
      r.addEventListener('input', () => pintar(r.value));
      r.addEventListener('change', async () => {
        const anterior = r.dataset.salvo ?? r.defaultValue;
        try {
          await App.api(`/api/desdobramentos/${id}/progresso`, {
            planejamento_id: this.plan.id, progresso: Number(r.value),
          });
          r.dataset.salvo = r.value;
          this.atualizarMedias(el, r);
        } catch (e) {
          // Falhou: devolve a barra ao valor que está no servidor
          r.value = anterior;
          pintar(anterior);
          alert(e.message);
        }
      });
    });
  },

  /**
   * Recalcula na tela os percentuais que a ação ajustada afeta: o da iniciativa
   * dela e o do projeto. Os dois níveis, não só o projeto — a barra da
   * iniciativa ficaria mostrando o número velho até a próxima carga, bem na
   * frente da ação que acabou de mudar.
   *
   * A média sai dos valores que estão na TELA (as barras), não dos dados da
   * carga: é a ação recém-arrastada que precisa entrar na conta.
   */
  atualizarMedias(el, barra) {
    const niveis = [
      [barra.closest('[data-iniciativa]'), '[data-media-ini]', '[data-barra-ini]'],
      [barra.closest('[data-projeto]'), '[data-media-projeto]', '[data-barra-projeto]'],
    ];
    for (const [raiz, marcaMedia, marcaBarra] of niveis) {
      const valores = [...(raiz?.querySelectorAll('[data-progresso]') || [])].map((x) => Number(x.value));
      const alvo = raiz?.querySelector(marcaMedia);
      if (!alvo || !valores.length) continue;
      const media = Math.round(valores.reduce((s, v) => s + v, 0) / valores.length);
      alvo.textContent = `${media}%`;
      const faixa = raiz.querySelector(marcaBarra);
      if (faixa) faixa.style.width = `${media}%`;
    }
  },

  // Anos de execução do ciclo vigente para o seletor "Ano do planejamento"
  anosDoCiclo() {
    const c = App.sessao.ciclos.find((x) => x.id === App.contexto.cicloId);
    if (!c) return [];
    const anos = [];
    const inicio = Number(c.ano_inicio || c.ano_base);
    for (let a = inicio; a <= Number(c.ano_fim); a++) anos.push(a);
    return anos;
  },

  modalProjeto(p, projetos) {
    const anos = this.anosDoCiclo();
    const anoPadrao = Math.min(Math.max(new Date().getFullYear(), anos[0] || 0), anos[anos.length - 1] || 9999);
    const mapaHorizontes = this.cascata.horizontes
      .map((h) => `${h.nome} ${h.ano_inicio}–${h.ano_fim}`).join(' · ');
    const opcoesAno = anos.map((a) => ({ valor: a, rotulo: String(a) }));
    // Projeto legado com ano fora da lista: mantém o valor à mostra em vez de
    // pular calado para o primeiro ano — o servidor pedirá um ano válido
    if (p?.ano && !anos.includes(Number(p.ano))) {
      opcoesAno.unshift({ valor: p.ano, rotulo: `${p.ano} (fora dos horizontes — escolha outro)` });
    }

    // De qual escolha da Cascata este projeto nasce. A coluna existia e o
    // cartão já mostrava "↳ Escolha da cascata", mas não havia como preencher:
    // nem campo no formulário, nem gravação no servidor.
    const nomeH = (id) => this.cascata.horizontes.find((h) => h.id == id)?.nome || '?';
    const nomeD = (id) => this.cascata.drivers.find((d) => d.id == id)?.nome || '?';
    const nomeE = (id) => (id ? this.cascata.eixos.find((e) => e.id == id)?.nome : 'Síntese') || 'Síntese';
    const opcoesCascata = [{ valor: '', rotulo: '(não vinculado a uma escolha)' }].concat(
      (this.cascata.escolhas || [])
        .map((e) => ({
          valor: e.id,
          rotulo: `${nomeH(e.horizonte_id)} · ${nomeD(e.driver_id)} · ${nomeE(e.eixo_id)} — `
            + `${String(e.escolha).replace(/\s+/g, ' ').slice(0, 70)}`,
        }))
        .sort((a, b) => a.rotulo.localeCompare(b.rotulo, 'pt-BR')));
    Modal.abrir({
      titulo: p ? 'Editar projeto' : 'Novo projeto',
      url: p ? `/api/projetos/${p.id}` : '/api/projetos',
      valores: p
        ? { ...p, cascata_id: p.cascata_id ?? '', impacto: p.impacto ?? '', planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, ano: anoPadrao },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        // Tipo é legado — mantém o valor dos projetos antigos sem exibi-lo
        { nome: 'tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: 'Ano do planejamento', obrigatorio: true, tipo: 'select',
          opcoes: opcoesAno,
          nota: mapaHorizontes
            ? `O horizonte é definido pelo ano: ${mapaHorizontes}.`
            : 'Cadastre os horizontes do ciclo em Cadastros para o ano ser aceito.' },
        { nome: 'titulo', rotulo: 'Nome do projeto', obrigatorio: true,
          exemplo: 'Ex.: 1ª onda de silos — unidade Capinzal' },
        { nome: 'descricao', rotulo: 'Descrição — para que serve o projeto', tipo: 'textarea', linhas: 3,
          exemplo: 'O que o projeto entrega e por quê.' },
        { nome: 'responsavel', rotulo: 'Responsável', tipo: 'selecao_livre', opcoes: this.responsaveis,
          obrigatorio: true, vazio: '(selecione o responsável)',
          ajuda: 'Pesquise um usuário cadastrado ou digite um nome de fora do sistema.' },
        { nome: 'cascata_id', rotulo: 'Escolha da Cascata que este projeto executa',
          tipo: 'select', opcoes: opcoesCascata,
          ajuda: opcoesCascata.length > 1
            ? 'Liga o projeto à decisão que o originou — é o que permite ler, na Cascata, o que cada escolha virou.'
            : 'Nenhuma escolha cadastrada ainda: preencha a Cascata para poder vincular.' },
      ],
    });
  },

  modalIniciativa(projetoId, ini) {
    Modal.abrir({
      titulo: ini ? 'Editar iniciativa' : 'Nova iniciativa',
      url: ini ? `/api/iniciativas/${ini.id}` : '/api/iniciativas',
      valores: ini
        ? { ...ini, planejamento_id: this.plan.id, projeto_id: projetoId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, status: 'ABERTA' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'titulo', rotulo: 'Iniciativa (frente de trabalho)', obrigatorio: true,
          exemplo: 'Ex.: Licenciamento e obra civil',
          ajuda: 'Agrupa as ações de uma mesma frente dentro do projeto.' },
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 2 },
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes:
          Object.entries(STATUS_INICIATIVA).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
      ],
    });
  },

  // Status da ação: os automáticos aparecem, mas não podem ser escolhidos
  opcoesStatusAcao(atual) {
    const automatico = atual === 'ATRASADO' ? 'ATRASADO' : 'NAO_INICIADO';
    const opcoes = [{
      valor: automatico,
      rotulo: `${STATUS_ACAO[automatico][0]} (automático)`,
    }];
    for (const v of STATUS_MANUAIS) opcoes.push({ valor: v, rotulo: STATUS_ACAO[v][0] });
    return opcoes;
  },

  // Pendências encaminhadas ao plano de ação e ainda sem ação criada: ideias da
  // coleta, fatores da SWOT e cruzamentos (TOWS), na mesma fila. O selo diz de
  // onde cada uma veio.
  cartaoIdeiasAcao(pendentes) {
    if (!pendentes || !pendentes.length) return '';
    const podeConverter = App.podeEditar();
    // Três origens, um selo cada. O do cruzamento mostra o BLOCO (atacar,
    // defender…), que é o que diz de que leitura da SWOT a estratégia nasceu —
    // o rótulo do par já vai no texto.
    const selo = (p) => {
      if (p.origem === 'TOWS') {
        const b = SecaoCruzamentos.bloco(p.categoria);
        return `<span class="badge text-bg-light border" title="Cruzamento da SWOT · ${
          Modal.esc(b?.rotulo || p.categoria)}">TOWS · ${Modal.esc(b?.verbo || p.categoria)}</span>`;
      }
      if (p.origem === 'SWOT') {
        return `<span class="badge text-bg-light border" title="Fator da SWOT · ${Modal.esc(
          Diag.QUADRANTES[p.categoria] || p.categoria)}">SWOT · ${Modal.esc(
          Diag.QUADRANTES[p.categoria] || p.categoria)}</span>`;
      }
      return `<span class="badge text-bg-light border">Coleta · ${Modal.esc(p.autor)}</span>`;
    };
    const linhas = pendentes.map((p) => `
      <div class="d-flex align-items-center gap-2 flex-wrap ideia-acao" data-ideia-acao="${p.chave}">
        <span class="small flex-grow-1">${Modal.esc(p.texto_tratado || p.texto)}
          <span class="text-muted">${p.votos ? ` · ★ ${p.votos}` : ''}</span></span>
        ${selo(p)}
        ${podeConverter ? `<button class="btn btn-sm btn-verde flex-shrink-0"
          data-virar-acao="${p.chave}">Transformar em ação</button>` : ''}
      </div>`).join('');
    return `<div class="card mb-3 card-ideias-acao"><div class="card-body py-2 px-3">
      <div class="rotulo-secao">Aguardando plano de ação (${pendentes.length})</div>
      <div class="small text-muted mb-2">Vindas da coleta, da SWOT e dos cruzamentos — atribua cada uma a uma iniciativa para virar ação.</div>
      ${linhas}
    </div></div>`;
  },

  // Transforma uma pendência numa ação (desdobramento) de uma iniciativa,
  // guardando o vínculo com a origem — a ideia da coleta ou o fator da SWOT.
  modalConverterAcao(ideia) {
    if (!ideia) return;
    const daSwot = ideia.origem === 'SWOT';
    const doCruzamento = ideia.origem === 'TOWS';
    // O campo que fecha o vínculo, um por origem. Mandar mais de um faria o
    // servidor fechar um vínculo que ninguém pediu.
    const campoOrigem = doCruzamento ? 'cruzamento_id' : (daSwot ? 'fator_id' : 'coleta_item_id');
    const blocoCruz = doCruzamento ? SecaoCruzamentos.bloco(ideia.categoria) : null;
    const projetos = this.projetos || [];
    const comIniciativas = projetos.filter((p) => (p.iniciativas || []).length);

    // A decisão vem PRIMEIRO e em voz alta: uma lista só, misturando
    // "Projeto › Iniciativa", "Projeto › ➕ nova iniciativa" e "➕ Novo projeto",
    // escondia as duas perguntas que importam — a frente de trabalho é nova ou
    // já existe? o projeto é novo ou já existe? — atrás de um seletor onde as
    // três coisas pareciam a mesma coisa. Pior: o nome digitado servia ora para
    // o projeto, ora para a iniciativa, ora para nada.
    // Só entram os caminhos possíveis: sem nenhum projeto cadastrado não há
    // iniciativa nem projeto existente para escolher.
    const destinos = [];
    if (comIniciativas.length) {
      destinos.push({ valor: 'INI', rotulo: 'Iniciativa que já existe' });
    }
    if (projetos.length) {
      destinos.push({ valor: 'NOVA_INI', rotulo: 'Nova iniciativa' });
    }
    destinos.push({ valor: 'NOVO_PROJ', rotulo: 'Projeto novo' });

    const opcoesIniciativa = comIniciativas.flatMap((p) => (p.iniciativas || []).map((ini) => ({
      // O par projeto+iniciativa anda junto: o servidor recusa iniciativa que
      // não pertença ao projeto informado, e mandar os dois de uma escolha só
      // torna impossível montar um par inválido pela tela.
      valor: `${p.id}:${ini.id}`, rotulo: `${p.titulo} › ${ini.titulo}`,
    })));
    const opcoesProjeto = projetos.map((p) => ({ valor: String(p.id), rotulo: p.titulo }));

    // Ano do projeto novo: era herdado da ideia, calado. Quando o ano dela caía
    // fora dos horizontes do ciclo, o salvamento morria com "nenhum horizonte
    // contempla o ano X" e não havia campo nenhum para corrigir.
    const anos = this.anosDoCiclo();
    const opcoesAno = anos.map((a) => ({ valor: a, rotulo: String(a) }));
    const anoIdeia = Number(ideia.ano) || 0;
    const anoPadrao = anos.includes(anoIdeia)
      ? anoIdeia
      : Math.min(Math.max(new Date().getFullYear(), anos[0] || 0), anos[anos.length - 1] || 9999);
    const mapaHorizontes = this.cascata.horizontes
      .map((h) => `${h.nome} ${h.ano_inicio}–${h.ano_fim}`).join(' · ');

    const nomeIdeia = ideia.texto_tratado || ideia.texto;
    Modal.abrir({
      titulo: doCruzamento ? 'Transformar cruzamento em ação'
        : (daSwot ? 'Transformar fator da SWOT em ação' : 'Transformar ideia em ação'),
      url: '/api/desdobramentos',
      valores: {
        planejamento_id: this.plan.id,
        [campoOrigem]: ideia.id,
        destino: destinos[0].valor,
        iniciativa_alvo: opcoesIniciativa[0]?.valor ?? '',
        projeto_alvo: opcoesProjeto[0]?.valor ?? '',
        iniciativa_nome: 'Ações',
        // No cruzamento o nome do projeto é o RÓTULO do par, não a estratégia:
        // o rótulo tem duas ou três palavras e a estratégia é um parágrafo.
        projeto_nome: doCruzamento ? (ideia.rotulo || nomeIdeia) : nomeIdeia,
        projeto_ano: anoPadrao,
        // A ação nasce aqui inteira: os mesmos padrões de uma ação nova no
        // cadastro, com o texto da ideia já no "O quê?"
        ...this.valoresNovaAcao(),
        o_que: nomeIdeia,
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: campoOrigem, rotulo: '', tipo: 'hidden' },
        { nome: 'ideia',
          rotulo: doCruzamento ? 'Cruzamento da SWOT'
            : (daSwot ? 'Fator da SWOT' : 'Ideia da coleta'),
          tipo: 'info',
          // No cruzamento o texto é a ESTRATÉGIA e o rótulo do par vai na barra:
          // é a estratégia que descreve o que fazer, e é dela que sai o "o quê".
          texto: ideia.texto,
          barra: doCruzamento
            ? { cor: blocoCruz?.cor || '#007a45',
                titulo: `${blocoCruz?.verbo || 'cruzamento'} — ${ideia.rotulo || ''}` }
            : (daSwot
              ? { cor: Diag.CORES_QUADRANTE[ideia.categoria] || '#007a45',
                  titulo: Diag.QUADRANTES[ideia.categoria] || 'SWOT' }
              : { cor: '#5a3e2b', titulo: ideia.autor }) },
        { nome: 'destino',
          rotulo: doCruzamento ? 'Onde este cruzamento vira ação?'
            : (daSwot ? 'Onde este fator vira ação?' : 'Onde esta ideia vira ação?'),
          tipo: 'botoes', opcoes: destinos,
          ajuda: 'A ação sempre entra numa iniciativa, e toda iniciativa vive dentro de um projeto.' },
        { nome: 'iniciativa_alvo', rotulo: 'Em qual iniciativa?', tipo: 'select',
          opcoes: opcoesIniciativa, visivelSe: { campo: 'destino', valores: ['INI'] },
          ajuda: 'A frente de trabalho que já existe — o projeto dela vem junto.' },
        { nome: 'projeto_alvo', rotulo: 'Em qual projeto entra a nova iniciativa?', tipo: 'select',
          opcoes: opcoesProjeto, visivelSe: { campo: 'destino', valores: ['NOVA_INI'] } },
        { nome: 'projeto_nome', rotulo: 'Nome do novo projeto', obrigatorio: true,
          visivelSe: { campo: 'destino', valores: ['NOVO_PROJ'] },
          exemplo: 'Ex.: Aproveitamento de coprodutos' },
        { nome: 'projeto_ano', rotulo: 'Ano do novo projeto', tipo: 'select', opcoes: opcoesAno,
          visivelSe: { campo: 'destino', valores: ['NOVO_PROJ'] },
          nota: mapaHorizontes
            ? `O horizonte é definido pelo ano: ${mapaHorizontes}.`
            : 'Cadastre os horizontes do ciclo em Cadastros para o ano ser aceito.' },
        { nome: 'iniciativa_nome', rotulo: 'Nome da nova iniciativa', obrigatorio: true,
          visivelSe: { campo: 'destino', valores: ['NOVA_INI', 'NOVO_PROJ'] },
          exemplo: 'Ex.: Licenciamento e obra civil',
          ajuda: 'A frente de trabalho que vai receber a ação. No projeto novo, é a primeira dele.' },
        // Daqui para baixo é a ação inteira, a MESMA lista do cadastro: quem
        // direciona a ideia sai daqui com prazo, repetição, custo e status
        // definidos, em vez de ter de reabrir a ação depois para completá-la.
        ...this.camposAcao(),
      ],
      transformar: (d) => {
        // Os campos que só decidem o DESTINO ficam fora do payload: o servidor
        // lê projeto_id/iniciativa_id/projeto_novo/iniciativa_nova, e mandar os
        // nomes da tela junto confundiria quem for ler a requisição. O resto é
        // a ação inteira, pela mesma regra de repetição do cadastro.
        const {
          destino, iniciativa_alvo: iniAlvo, projeto_alvo: projAlvo,
          projeto_nome: projNome, projeto_ano: projAno, iniciativa_nome: iniNome,
          ...acao
        } = d;
        const base = this.transformarAcao(acao);
        if (destino === 'INI') {
          const [pid, iid] = String(iniAlvo || '').split(':');
          return { ...base, projeto_id: Number(pid), iniciativa_id: Number(iid) };
        }
        if (destino === 'NOVA_INI') {
          return { ...base, projeto_id: Number(projAlvo), iniciativa_nova: iniNome };
        }
        return {
          ...base, projeto_novo: projNome, projeto_ano: Number(projAno),
          projeto_responsavel: base.quem, iniciativa_nova: iniNome,
        };
      },
      aoSalvar: () => this.carregar(),
    });
  },

  /**
   * Os campos da AÇÃO, em lista única para os dois formulários que a escrevem:
   * o cadastro (`modalDesdobramento`) e o direcionamento de uma ideia da coleta
   * (`modalConverterAcao`). O direcionamento pedia só o quê/quem/prioridade e
   * criava a ação sem como, sem prazo, sem repetição, sem custo e sem status —
   * quem direcionava tinha de abrir a ação de novo, no cadastro, para terminar
   * o serviço. Escritos separados, os dois formulários voltariam a divergir no
   * primeiro campo novo.
   *
   * Ordem pedida: o quê, como, quem, quando, prioridade, a linha da repetição,
   * a linha de status e custo, e o progresso. "Quem?" fica logo depois do
   * "Como?" — ele não estava na ordem pedida, mas é ele que amarra a ação a uma
   * pessoa: é de `quem_usuario_id` que saem os avisos por e-mail e o filtro de
   * "minhas ações". Tirá-lo do formulário deixaria a ação sem dono.
   */
  camposAcao(dd = null) {
    return [
      // "Por quê?" e "Onde?" saíram do formulário; os valores dos registros
      // antigos seguem preservados nos campos ocultos
      { nome: 'por_que', rotulo: '', tipo: 'hidden' },
      { nome: 'onde', rotulo: '', tipo: 'hidden' },
      { nome: 'o_que', rotulo: 'O quê?', obrigatorio: true, tipo: 'textarea', linhas: 2,
        exemplo: 'Ex.: Contratar projeto executivo dos silos' },
      { nome: 'como', rotulo: 'Como?', obrigatorio: true },
      { nome: 'quem', rotulo: 'Quem?', tipo: 'selecao_livre', opcoes: this.responsaveis,
        obrigatorio: true, vazio: '(selecione o responsável)',
        ajuda: 'Pesquise um usuário cadastrado ou digite um nome de fora do sistema.' },
      { nome: 'quando_', rotulo: '', tipo: 'hidden' },
      { nome: 'quando_periodo', rotulo: 'Quando?', tipo: 'periodo', obrigatorio: true,
        campos: [
          { nome: 'data_inicio', rotulo: 'Início' },
          { nome: 'data_fim', rotulo: 'Fim previsto' },
        ],
        ajuda: 'Toque no campo para abrir o calendário.' },
      { nome: 'prioridade', rotulo: 'Prioridade', tipo: 'botoes', opcoes:
        Object.entries(PRIORIDADES).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
      // As três decisões da repetição numa linha só: repetir? em que dia? até
      // quando? Empilhadas, custavam três faixas de tela para a ação que NÃO se
      // repete, que é a maioria — e as duas últimas nem aparecem nesse caso.
      { nome: 'recorrencia', rotulo: 'Repetição', tipo: 'select', linha: 'repeticao', opcoes: [
        { valor: 'NENHUMA', rotulo: 'Não se repete' },
        { valor: 'SEMANAL', rotulo: 'Toda semana' },
        { valor: 'MENSAL', rotulo: 'Todo mês' },
      ], ajuda: 'Ao concluir, reabre na próxima data.' },
      { nome: 'recorrencia_dia_semana', rotulo: 'Repete toda', tipo: 'select', linha: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['SEMANAL'] },
        opcoes: DIAS_SEMANA.map(([valor, rotulo]) => ({ valor, rotulo })) },
      { nome: 'recorrencia_dia_mes', rotulo: 'Repete todo dia', tipo: 'select', linha: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['MENSAL'] },
        opcoes: Array.from({ length: 31 }, (_, i) => ({ valor: i + 1, rotulo: String(i + 1) })),
        ajuda: 'Em meses curtos, cai no último dia.' },
      { nome: 'recorrencia_ate', rotulo: 'Repetir até', tipo: 'date', linha: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['SEMANAL', 'MENSAL'] },
        ajuda: 'Opcional — depois dela, encerra.' },
      { nome: 'status', rotulo: 'Status', tipo: 'select', linha: 'situacao',
        opcoes: this.opcoesStatusAcao(dd?.status),
        ajuda: '“No prazo” e “Atrasada” saem da data de fim.' },
      { nome: 'quanto', rotulo: 'Quanto custa? (R$)', tipo: 'number', linha: 'situacao' },
      // Passo de 1 para o modal nunca arredondar o que a barra do cartão gravou
      { nome: 'progresso', rotulo: 'Progresso', tipo: 'faixa', min: 0, max: 100, passo: 1, sufixo: '%' },
    ];
  },

  /** Valores iniciais de uma ação que ainda não existe. */
  valoresNovaAcao() {
    return {
      progresso: 0, prioridade: 'MEDIA', status: 'NAO_INICIADO', recorrencia: 'NENHUMA',
      recorrencia_dia_semana: 1, recorrencia_dia_mes: 1,
    };
  },

  /** O dia enviado depende do tipo de repetição escolhido. */
  transformarAcao(dados) {
    const { recorrencia_dia_semana: sem, recorrencia_dia_mes: mes, ...resto } = dados;
    return {
      ...resto,
      recorrencia_dia: resto.recorrencia === 'SEMANAL' ? Number(sem)
        : resto.recorrencia === 'MENSAL' ? Number(mes) : null,
    };
  },

  modalDesdobramento(projetoId, dd, iniciativaId = null) {
    Modal.abrir({
      titulo: dd ? 'Editar ação' : 'Nova ação',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId,
            iniciativa_id: dd.iniciativa_id ?? iniciativaId,
            recorrencia: dd.recorrencia || 'NENHUMA',
            recorrencia_ate: dd.recorrencia_ate ?? '',
            recorrencia_dia_semana: dd.recorrencia === 'SEMANAL' ? dd.recorrencia_dia : 1,
            recorrencia_dia_mes: dd.recorrencia === 'MENSAL' ? dd.recorrencia_dia : 1 }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, iniciativa_id: iniciativaId,
            ...this.valoresNovaAcao() },
      transformar: (dados) => this.transformarAcao(dados),
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'iniciativa_id', rotulo: '', tipo: 'hidden' },
        ...this.camposAcao(dd),
      ],
      aoSalvar: (r) => {
        this.avisarReagendamento(r);
        App.recarregarSecaoAtiva();
      },
    });
  },

  /**
   * Concluir uma ação que se repete não a encerra: ela volta na próxima data.
   * Sem este aviso o usuário marca "Concluída" e a vê reaparecer em aberto,
   * com outra data e 0%, sem entender o porquê.
   */
  avisarReagendamento(resposta) {
    const data = resposta?.reagendada_para;
    if (!data) return;
    alert(`Ocorrência concluída. Como esta ação se repete, ela volta em `
      + `${String(data).split('-').reverse().join('/')}.`);
  },

  /** dd/mm/aaaa hh:mm a partir do DATETIME que o banco devolve. */
  quando(iso) {
    if (!iso) return '';
    const [data, hora] = String(iso).split(' ');
    return `${data.split('-').reverse().join('/')}${hora ? ` ${hora.slice(0, 5)}` : ''}`;
  },

  /** 162,9 KB / 2,1 MB — o mesmo jeito de escrever tamanho do resto do sistema. */
  tamanho(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} KB`;
    return `${(n / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
  },

  /**
   * A miniatura do anexo. Imagem mostra a própria imagem; documento mostra o
   * selo da extensão, com a cor do tipo — o ícone genérico de "arquivo" obriga
   * a ler o nome para saber se é a proposta ou a planilha.
   * A imagem é a original, encolhida por CSS: não há GD na imagem do PHP para
   * gerar miniatura no servidor, e o teto de 5 MB por arquivo segura o peso.
   */
  miniaturaAnexo(a) {
    const url = `/api/anexos/${a.id}?planejamento_id=${this.plan.id}`;
    const ext = (a.nome.split('.').pop() || '').toUpperCase().slice(0, 4);
    const corpo = a.imagem
      ? `<img src="${url}" alt="${Modal.esc(a.nome)}" loading="lazy">`
      : `<span class="selo-ext selo-ext-${ext.toLowerCase()}">${Modal.esc(ext)}</span>`;
    // `download` no link do documento: ele já desce como anexo pelo servidor, e
    // o atributo evita a aba em branco que o navegador abre antes de baixar.
    return `<a class="anexo-mini" href="${url}" target="_blank" rel="noopener"
        ${a.imagem ? '' : 'download'} title="${Modal.esc(a.nome)}">
      <span class="anexo-face">${corpo}</span>
      <span class="anexo-nome">${Modal.esc(a.nome)}</span>
      <span class="anexo-tamanho">(${this.tamanho(a.tamanho)})</span>
    </a>`;
  },

  /**
   * Comentários com anexos — sucederam o diário de bordo. O bloco é o mesmo nos
   * dois níveis que o usam (projeto e ação): escritos separados, divergiriam na
   * primeira mudança.
   */
  async renderComentarios() {
    const { refTipo, refId } = this.comentariosAbertos;
    const alvo = document.getElementById(`comentarios-${refTipo}-${refId}`);
    if (!alvo) return;
    const lista = await App.api(
      `/api/comentarios?planejamento_id=${this.plan.id}&ref_tipo=${refTipo}&ref_id=${refId}`);
    const eu = App.sessao.usuario;

    const itens = lista.map((c) => {
      const inicial = (c.autor || '?').trim().charAt(0).toUpperCase();
      const anexos = (c.anexos || []).length
        ? `<div class="grade-anexos">${c.anexos.map((a) => this.miniaturaAnexo(a)).join('')}</div>` : '';
      const podeApagar = Number(c.autor_id) === Number(eu.id) || eu.perfil === 'ADMIN';
      return `<div class="comentario d-flex gap-2">
        <span class="avatar-inicial" aria-hidden="true">${Modal.esc(inicial)}</span>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-baseline gap-2 flex-wrap">
            <strong class="small">${Modal.esc(c.autor)}</strong>
            <span class="small text-muted flex-grow-1">${this.quando(c.criado_em)}</span>
            ${podeApagar ? `<button class="btn btn-sm btn-link p-0 text-danger"
              data-excluir-comentario="${c.id}" title="Excluir comentário"
              aria-label="Excluir comentário">×</button>` : ''}
          </div>
          ${c.texto ? `<div class="small texto-comentario">${Modal.esc(c.texto)}</div>` : ''}
          ${anexos}
        </div>
      </div>`;
    }).join('');

    const formulario = App.podeEditar() ? `
      <div class="novo-comentario mb-3">
        <div class="rotulo-secao">Novo comentário</div>
        <textarea class="form-control" id="texto-comentario" rows="3"
          placeholder="Descreva o andamento, bloqueios ou próximos passos..."></textarea>
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-2">
          <div>
            <label class="btn btn-sm btn-outline-secondary mb-0" for="arquivos-comentario">📎 Anexar</label>
            <input type="file" id="arquivos-comentario" class="d-none" multiple
              accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt">
            <span class="small text-muted ms-2" id="lista-escolhidos"></span>
          </div>
          <button class="btn btn-sm btn-verde" id="btn-enviar-comentario">Enviar</button>
        </div>
        <div class="form-text">Até 5 arquivos por comentário, 5 MB cada — imagem, PDF, Word, Excel, PowerPoint, CSV ou TXT.</div>
      </div>` : '';

    alvo.innerHTML = `<div class="card bg-light"><div class="card-body py-2">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small text-uppercase">Comentários</strong>
        <span class="small text-muted">${lista.length} registro(s)</span>
      </div>
      ${formulario}
      ${itens || '<div class="text-muted small">Nenhum comentário ainda.</div>'}
    </div></div>`;

    alvo.querySelectorAll('[data-excluir-comentario]').forEach((b) =>
      b.addEventListener('click', async () => {
        if (!confirm('Excluir este comentário? Os anexos dele saem junto.')) return;
        try {
          await App.api(`/api/comentarios/${b.dataset.excluirComentario}/excluir`,
            { planejamento_id: this.plan.id });
        } catch (e) {
          alert(e.message);
        }
        this.renderComentarios();
      }));

    if (!App.podeEditar()) return;
    const campo = alvo.querySelector('#texto-comentario');
    Modal.crescerTextarea(campo);
    const entrada = alvo.querySelector('#arquivos-comentario');
    const escolhidos = alvo.querySelector('#lista-escolhidos');
    entrada.addEventListener('change', () => {
      const nomes = [...entrada.files].map((f) => f.name);
      escolhidos.textContent = nomes.length
        ? `${nomes.length} arquivo(s): ${nomes.join(', ')}` : '';
    });

    alvo.querySelector('#btn-enviar-comentario').addEventListener('click', async (ev) => {
      const botao = ev.currentTarget;
      const texto = campo.value.trim();
      if (!texto && !entrada.files.length) {
        alert('Escreva o comentário ou anexe um arquivo.');
        return;
      }
      // O corpo é multipart porque leva arquivo: `App.api` fala JSON, então o
      // envio é `fetch` na mão — com o mesmo header de CSRF, que é o que a
      // rota confere.
      const dados = new FormData();
      dados.append('planejamento_id', this.plan.id);
      dados.append('ref_tipo', refTipo);
      dados.append('ref_id', refId);
      dados.append('texto', texto);
      for (const arquivo of entrada.files) dados.append('arquivos[]', arquivo);
      botao.disabled = true;
      botao.textContent = 'Enviando...';
      try {
        const resposta = await fetch('/api/comentarios', {
          method: 'POST',
          headers: { 'X-CSRF-Token': App.csrf },
          body: dados,
        });
        const corpo = await resposta.json().catch(() => ({}));
        if (!resposta.ok || corpo.ok === false) {
          throw new Error(corpo.erro || 'Não foi possível enviar o comentário.');
        }
        this.renderComentarios();
      } catch (e) {
        alert(e.message);
      } finally {
        botao.disabled = false;
        botao.textContent = 'Enviar';
      }
    });
  },
};

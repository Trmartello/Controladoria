// Seções de diagnóstico: Análise de Cenário, PESTEL, Porter, SWOT e Matriz GUT.
// As análises são ANUAIS (refeitas a cada ano do ciclo); os horizontes e a
// cascata seguem plurianuais.

const Diag = {
  // Ano da análise selecionado (compartilhado pelas 5 seções do diagnóstico)
  anoSelecionado: null,

  cicloAtual() {
    return App.sessao.ciclos.find((c) => c.id === App.contexto.cicloId);
  },

  // Ano vigente da análise, sempre dentro de [ano_base, ano_fim] do ciclo
  ano() {
    const c = this.cicloAtual();
    if (!c) return new Date().getFullYear();
    const a = this.anoSelecionado ?? new Date().getFullYear();
    return Math.min(Number(c.ano_fim), Math.max(Number(c.ano_base), a));
  },

  /**
   * `chave` deixa o id único por tela. As seções não são destruídas ao navegar
   * (só ganham d-none), então cinco "sel-ano-analise" coexistiam no documento:
   * o `for` do label casava sempre com o primeiro e clicar no rótulo da SWOT
   * mandava o foco para o select escondido do Cenário.
   */
  seletorAno(chave = 'geral') {
    const c = this.cicloAtual();
    if (!c) return '';
    const atual = this.ano();
    const id = `sel-ano-${String(chave).toLowerCase()}`;
    const anos = [];
    for (let a = Number(c.ano_base); a <= Number(c.ano_fim); a++) anos.push(a);
    return `<div class="d-flex align-items-center gap-2">
      <label class="small text-muted text-nowrap" for="${id}">Ano da análise</label>
      <select id="${id}" class="form-select form-select-sm sel-ano-analise" style="width:auto">
        ${anos.map((a) => `<option value="${a}" ${a === atual ? 'selected' : ''}>${a}</option>`).join('')}
      </select>
    </div>`;
  },

  ligarSeletorAno(el) {
    el.querySelector('.sel-ano-analise')?.addEventListener('change', (ev) => {
      this.anoSelecionado = parseInt(ev.target.value, 10);
      App.recarregarSecaoAtiva();
    });
  },

  // Base comum: resolve o planejamento do contexto ou instrui a seleção
  async preparar(idSecao) {
    const el = document.getElementById(idSecao);
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return null;
    }
    const plan = await App.planejamento();
    return { el, plan, ano: this.ano() };
  },

  // Filtro de categoria no celular: combo em forma de botão que mostra uma
  // categoria por vez (no computador todas as colunas seguem visíveis)
  filtroMovel: {},

  seletorCategoriaMovel(chave, opcoes, contagens) {
    const atual = this.filtroMovel[chave] || 'TODAS';
    const ops = [['TODAS', 'Todas as categorias'], ...opcoes]
      .map(([v, r]) => `<option value="${v}" ${v === atual ? 'selected' : ''}>${r}${v === 'TODAS' ? '' : ` (${contagens[v] || 0})`}</option>`)
      .join('');
    return `<div class="d-md-none mb-3">
      <select id="sel-cat-movel-${String(chave).toLowerCase()}"
        class="form-select sel-categoria-movel" aria-label="Categoria exibida">${ops}</select>
    </div>`;
  },

  ligarSeletorCategoriaMovel(el, chave) {
    const sel = el.querySelector('.sel-categoria-movel');
    if (!sel) return;
    const aplicar = () => {
      this.filtroMovel[chave] = sel.value;
      el.querySelectorAll('[data-coluna-categoria]').forEach((col) => {
        const ocultar = sel.value !== 'TODAS' && col.dataset.colunaCategoria !== sel.value;
        col.classList.toggle('d-none', ocultar);
        col.classList.toggle('d-md-block', ocultar); // no computador nunca some
      });
      this.ligarVerMais(el); // colunas recém-exibidas ganham o "ver mais"
    };
    sel.addEventListener('change', aplicar);
    aplicar();
  },

  // Cartão baixo: texto longo é cortado em 3 linhas (ver `.texto-fator`) e
  // ganha um "ver mais" para expandir/recolher, dando noção de quantos cards
  // existem sem obrigar a rolar um parágrafo por vez
  ligarVerMais(el) {
    el.querySelectorAll('.texto-fator').forEach((t) => {
      if (t.nextElementSibling?.classList.contains('ver-mais')) return;
      if (t.scrollHeight <= t.clientHeight + 1) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-link btn-sm p-0 ver-mais';
      btn.textContent = 'ver mais';
      btn.setAttribute('aria-expanded', 'false');
      btn.addEventListener('click', () => {
        const expandido = t.classList.toggle('expandido');
        btn.textContent = expandido ? 'ver menos' : 'ver mais';
        btn.setAttribute('aria-expanded', String(expandido));
      });
      t.after(btn);
    });
  },

  contadorCards(qtd, cor) {
    return `<span class="badge rounded-pill contador-cards" style="background:${cor}"
      title="${qtd} card(s) nesta categoria">${qtd}</span>`;
  },

  // Botão "+" à direita do título: adiciona um card já naquela categoria
  botaoAddCategoria(cat, rotulo, cor) {
    if (!App.podeEditar()) return '';
    return `<button class="btn btn-sm btn-add-cat ms-auto" style="--cor-cat:${cor}"
      data-add-categoria="${cat}" title="Adicionar em ${rotulo}" aria-label="Adicionar em ${rotulo}">+</button>`;
  },

  QUADRANTES: { FORCA: 'Força', FRAQUEZA: 'Fraqueza', OPORTUNIDADE: 'Oportunidade', AMEACA: 'Ameaça' },
  CORES_QUADRANTE: { FORCA: '#007a45', FRAQUEZA: '#b08d4f', OPORTUNIDADE: '#2c7fb8', AMEACA: '#8f3b3b' },
  // Eixos da matriz SWOT: origem (interno/externo) × efeito (ajuda/atrapalha)
  DICAS_QUADRANTE: {
    FORCA: 'Interno · Ajuda', FRAQUEZA: 'Interno · Atrapalha',
    OPORTUNIDADE: 'Externo · Ajuda', AMEACA: 'Externo · Atrapalha',
  },

  /**
   * Catálogo único das categorias de PESTEL e Porter: rótulo, cor e uma dica
   * curta. Serve às colunas da seção E aos formulários — antes cada lugar
   * repetia a sua lista, e o "Encaminhar" da Coleta usava rótulos com outra
   * caixa dos que a seção mostrava.
   *
   * A dica é de UMA linha, no espírito do "Interno · Ajuda" da SWOT: cabe no
   * cartão e serve de lembrete na hora de escolher. A explicação longa continua
   * no ⓘ da seção (ORIENTACOES_CATEGORIA), que é onde há espaço para ela.
   */
  CATEGORIAS_ETAPA: {
    PESTEL: [
      ['POLITICO', 'Político', '#7a3b8f', 'Governo · Regulação'],
      ['ECONOMICO', 'Econômico', '#b08d4f', 'Juros · Câmbio · Renda'],
      ['SOCIAL', 'Social', '#2c7fb8', 'Comportamento · Demografia'],
      ['TECNOLOGICO', 'Tecnológico', '#0d6e6e', 'Automação · Digital'],
      ['ECOLOGICO', 'Ecológico', '#007a45', 'Clima · Recursos · ESG'],
      ['LEGAL', 'Legal', '#8f3b3b', 'Leis · Compliance'],
    ],
    PORTER: [
      ['RIVALIDADE', 'Rivalidade', '#8f3b3b', 'Concorrentes diretos'],
      ['NOVOS_ENTRANTES', 'Novos Entrantes', '#b08d4f', 'Barreiras de entrada'],
      ['SUBSTITUTOS', 'Substitutos', '#7a3b8f', 'Soluções alternativas'],
      ['PODER_FORNECEDORES', 'Poder dos Fornecedores', '#2c7fb8', 'Quem nos abastece'],
      ['PODER_CLIENTES', 'Poder dos Clientes', '#0d6e6e', 'Quem compra de nós'],
    ],
  },

  /**
   * Campo de categoria em CARTÕES, como o quadrante da SWOT: todas as opções à
   * vista, escolhidas com um toque. Substitui o `select`, em que era preciso
   * abrir a lista para descobrir o que existe — e onde não cabia a dica.
   */
  campoCategoria(etapa, nome = 'categoria', rotulo = 'Categoria') {
    return {
      nome,
      rotulo,
      tipo: 'quadrantes',
      // Não é matriz: sem posição com significado, as opções fluem em colunas
      layout: 'lista',
      opcoes: (this.CATEGORIAS_ETAPA[etapa] || []).map(([valor, rot, cor, dica]) => ({
        valor, rotulo: rot, cor, dica,
      })),
    };
  },

  // O que considerar em cada tópico do macroambiente (PESTEL). O ícone ⓘ no
  // título abre e fecha esta orientação. Só aparece onde há texto definido.
  ORIENTACOES_CATEGORIA: {
    POLITICO: 'Mudanças na legislação, tributação e políticas setoriais; estabilidade política, '
      + 'incentivos e regulação do governo que afetam o setor.',
    ECONOMICO: 'Taxa de juros, inflação, poder de compra do consumidor, taxa de câmbio, crédito e '
      + 'crescimento — as forças econômicas que movem o mercado.',
    SOCIAL: 'Mudanças de comportamento, hábitos de consumo, demografia e valores culturais do público.',
    TECNOLOGICO: 'Automação, novas ferramentas, inteligência artificial e transformação digital que '
      + 'mudam como o setor opera.',
    ECOLOGICO: 'Clima, sustentabilidade, uso de recursos naturais, exigências ambientais e agenda ESG.',
    LEGAL: 'Leis trabalhistas, tributárias e setoriais, normas regulatórias, contratos e compliance '
      + 'que a empresa precisa cumprir.',
    // Porter — as 5 forças que medem a atratividade e a competitividade do setor
    RIVALIDADE: 'Quem são os concorrentes diretos e como disputam o mercado (preço, qualidade, marca)?',
    NOVOS_ENTRANTES: 'É fácil ou difícil surgirem novos concorrentes? Que barreiras protegem o setor?',
    SUBSTITUTOS: 'Existem alternativas que resolvem a mesma dor do cliente de forma diferente?',
    PODER_FORNECEDORES: 'A empresa depende de poucos fornecedores críticos que ditam preço e prazo?',
    PODER_CLIENTES: 'Quão exigentes ou sensíveis a preço os clientes são, e quanto poder têm na negociação?',
    // SWOT — o que costuma entrar em cada quadrante
    FORCA: 'Diferenciais competitivos, processos bem consolidados, equipe qualificada, '
      + 'boa margem de lucro, tecnologia própria.',
    FRAQUEZA: 'Falta de padronização, dependência de pessoas-chave, sistemas defasados, '
      + 'alto custo operacional, comunicação ruidosa.',
    OPORTUNIDADE: 'Nichos de mercado não atendidos, novas tecnologias disponíveis, '
      + 'mudanças regulatórias favoráveis, expansão de demanda.',
    AMEACA: 'Entrada de concorrentes agressivos em preço, instabilidade econômica, '
      + 'escassez de matéria-prima, mudanças bruscas no comportamento do consumidor.',
    // Análise de Cenário
    SITUACAO_ATUAL: 'Onde o negócio está hoje: os fatos e números que descrevem a realidade atual '
      + '— mercado, resultado, capacidade e posição competitiva.',
    TENDENCIA: 'Para onde o ambiente aponta: movimentos que já se desenham e devem se intensificar '
      + '— mercado, tecnologia, comportamento do cliente e regulação.',
  },

  // Ícone ⓘ e o painel de orientação de um tópico (só onde há texto definido).
  // Compartilhados entre PESTEL/Porter (etapaFatores) e a SWOT.
  iconeOrientacao(cat, cor, rotulo) {
    if (!this.ORIENTACOES_CATEGORIA[cat]) return '';
    return `<button type="button" class="btn-orientacao me-1" data-orientacao="${cat}"
      style="--cor-cat:${cor}" aria-expanded="false"
      title="O que considerar" aria-label="O que considerar em ${rotulo}">ⓘ</button>`;
  },
  painelOrientacao(cat, cor) {
    const o = this.ORIENTACOES_CATEGORIA[cat];
    return o ? `<div class="orientacao-categoria small d-none mb-2" data-orientacao-alvo="${cat}"
      style="--cor-cat:${cor}">${Modal.esc(o)}</div>` : '';
  },
  ligarOrientacoes(el) {
    el.querySelectorAll('[data-orientacao]').forEach((b) => b.addEventListener('click', () => {
      const alvo = el.querySelector(`[data-orientacao-alvo="${b.dataset.orientacao}"]`);
      const oculto = alvo.classList.toggle('d-none');
      b.setAttribute('aria-expanded', oculto ? 'false' : 'true');
    }));
  },

  // Navegação entre etapas: leva à seção de destino e destaca o card do fator
  destaque: null,

  irParaFator(secao, fatorId, chaveFiltro = null, categoria = null) {
    this.destaque = { secao, fatorId: String(fatorId) };
    // Garante que o card não fique escondido pelo filtro de categoria do celular
    if (chaveFiltro && categoria) this.filtroMovel[chaveFiltro] = categoria;
    App.mostrarSecao(secao);
  },

  /**
   * Selo de origem: mostra que este registro nasceu de uma ideia da Coleta e
   * de quem foi. É o vínculo que se perdia quando alguém redigitava a lista
   * crua à mão dentro do diagnóstico.
   */
  seloColeta(registro) {
    if (!registro.coleta_item_id) return '';
    return `<div class="mt-1"><button type="button" class="btn btn-sm selo-link"
      data-ir-coleta="${registro.coleta_item_id}"
      title="Ver a ideia original na Coleta">Coleta · ${Modal.esc(registro.coleta_autor || '—')}${
      Number(registro.coleta_vozes) > 1 ? ` +${Number(registro.coleta_vozes) - 1}` : ''}</button></div>`;
  },

  ligarSeloColeta(el, rotuloAnalise = '') {
    el.querySelectorAll('[data-ir-coleta]').forEach((b) => {
      b.addEventListener('click', () => {
        this.destaqueColeta = b.dataset.irColeta;
        App.mostrarSecao('coleta');
      });
      // Duplo clique no card leva a ideia à tempestade para reclassificar.
      // Não remove nada aqui: o item só sai da análise ao escolher o novo destino.
      const card = b.closest('[data-card-fator]');
      if (card && App.podeEditar()) {
        card.addEventListener('dblclick', (ev) => {
          if (ev.target.closest('button, a, input, textarea, select')) return;
          this.reclassificar(Number(b.dataset.irColeta), rotuloAnalise);
        });
      }
    });
  },

  destaqueColeta: null,
  // id da ideia que a coleta deve carregar na bancada ao voltar da análise
  reclassificarColeta: null,

  /**
   * "Reabrir e mover": remove o item da análise e leva a ideia de volta à
   * tempestade, carregada na bancada, para reclassificar.
   */
  // Não-destrutivo: apenas leva a ideia à tempestade. O item continua na
  // análise até o condutor escolher o novo destino (aí sim é reaberto e movido).
  reclassificar(coletaItemId, rotulo = '') {
    if (!confirm('Levar esta ideia para a tempestade e reclassificar? Você escolhe o novo destino lá; se cancelar, ela continua nesta análise.')) return;
    this.reclassificarColeta = { id: coletaItemId, rotulo };
    App.mostrarSecao('coleta');
  },

  aplicarDestaque(el, secao) {
    if (this.destaque?.secao !== secao) return;
    const { fatorId } = this.destaque;
    this.destaque = null;
    const alvos = [...el.querySelectorAll(`[data-card-fator="${fatorId}"]`)];
    const card = alvos.find((c) => c.offsetParent) || alvos[0];
    if (!card) return;
    card.classList.add('card-destacado');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => card.classList.remove('card-destacado'), 2600);
  },

  // Campo de modal com a matriz 2×2 (usado no cadastro de fator da SWOT)
  campoQuadrante(nome = 'categoria', rotulo = 'Quadrante da SWOT') {
    return {
      nome,
      rotulo,
      tipo: 'quadrantes',
      opcoes: Object.entries(this.QUADRANTES).map(([valor, rot]) => ({
        valor, rotulo: rot, cor: this.CORES_QUADRANTE[valor], dica: this.DICAS_QUADRANTE[valor],
      })),
    };
  },

  // Matriz 2×2 que abre embaixo do próprio card, sem modal: um toque no
  // quadrante promove (ou reclassifica) o fator na SWOT
  painelQuadrantes(f) {
    const promovido = Number(f.promovido);
    const celulas = Object.entries(this.QUADRANTES).map(([cat, rotulo]) => `
      <button type="button" class="quadrante-opcao ${promovido && f.promovido_categoria === cat ? 'selecionado' : ''}"
        style="--cor-quad:${this.CORES_QUADRANTE[cat]}" data-escolher-quadrante="${cat}" data-fator="${f.id}">
        <span class="quadrante-nome">${rotulo}</span>
        <span class="quadrante-dica">${this.DICAS_QUADRANTE[cat]}</span>
      </button>`).join('');
    return `<div class="painel-quadrantes d-none" data-painel="${f.id}">
      <div class="grade-quadrantes">${celulas}</div>
      ${promovido ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 mt-2 small"
        data-desvincular="${f.id}">Desvincular da SWOT</button>` : ''}
    </div>`;
  },

  // Botões compactos abaixo do texto: SWOT à esquerda, editar/excluir à direita.
  // Depois de promovido, o botão mostra a categoria atribuída e reabre a edição.
  botoesFator(f, planId, comPromocao) {
    if (!App.podeEditar()) return '';
    let swot = '';
    if (comPromocao) {
      // Promovido: à esquerda o botão da categoria (reclassificar/desvincular)
      // e, no meio, o atalho que abre o fator na análise SWOT
      swot = Number(f.promovido)
        ? `<button class="btn btn-sm btn-swot-cat" style="--cor-cat:${this.CORES_QUADRANTE[f.promovido_categoria] || '#007a45'}"
             data-editar-swot="${f.id}" title="Alterar a categoria na SWOT">${this.QUADRANTES[f.promovido_categoria] || 'SWOT'}</button>
           <button class="btn btn-sm btn-ver-swot" data-ir-swot="${f.promovido_id}"
             data-cat-swot="${f.promovido_categoria}" title="Abrir este fator na análise SWOT">Ver na SWOT ↗</button>`
        : `<button class="btn btn-sm btn-outline-success" data-promover="${f.id}" title="Promover para a SWOT">→ SWOT</button>`;
    }
    return `<div class="botoes-fator d-flex gap-1 mt-2 align-items-center flex-wrap">
      ${swot}
      <span class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}" title="Excluir" aria-label="Excluir">×</button>
      </span>
    </div>`;
  },

  // Renderiza uma etapa de fatores em colunas de categorias
  async etapaFatores({ idSecao, etapa, titulo, descricao, categorias, comPromocao }) {
    const base = await this.preparar(idSecao);
    if (!base) return;
    const { el, plan, ano } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=${etapa}&ano=${ano}`);

    const colunas = categorias.map(([cat, rotulo, cor]) => {
      const itens = fatores.filter((f) => f.categoria === cat);
      const cartoes = itens.map((f) => `
        <div class="card mb-2" data-card-fator="${f.id}"><div class="card-body py-2 px-2">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          ${this.seloColeta(f)}
          ${this.botoesFator(f, plan.id, comPromocao)}
          ${comPromocao && App.podeEditar() ? this.painelQuadrantes(f) : ''}
        </div></div>`).join('');
      return `<div class="col-12 col-sm-6 col-md-4 col-xl-2 coluna-categoria" data-coluna-categoria="${cat}">
        <div class="d-flex align-items-center mb-2">
          ${this.iconeOrientacao(cat, cor, rotulo)}
          <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
            ${this.contadorCards(itens.length, cor)}</span>
          ${this.botaoAddCategoria(cat, rotulo, cor)}
        </div>
        ${this.painelOrientacao(cat, cor)}
        ${cartoes || '<div class="text-muted small">—</div>'}
      </div>`;
    }).join('');

    const contagens = Object.fromEntries(
      categorias.map(([cat]) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>${titulo} — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          ${this.seletorAno(etapa)}
          ${App.podeEditar() ? `<button class="btn btn-verde btn-sm" data-novo-fator>+ Novo fator</button>` : ''}
        </div>
      </div>
      ${descricao ? `<p class="text-muted">${descricao} <em>A análise é anual — troque o ano acima para revisar ou consultar edições anteriores.</em></p>` : ''}
      ${this.seletorCategoriaMovel(etapa, categorias.map(([cat, rotulo]) => [cat, rotulo]), contagens)}
      <div class="row g-3">${colunas}</div>`;

    this.ligarSeletorAno(el);
    this.ligarSeletorCategoriaMovel(el, etapa);
    this.ligarVerMais(el);
    this.aplicarDestaque(el, idSecao.replace('secao-', ''));
    this.ligarSeloColeta(el, ({ PESTEL: 'PESTEL', PORTER: 'Porter', SWOT: 'SWOT' })[etapa] || etapa);
    this.ligarOrientacoes(el);
    if (!App.podeEditar()) return;
    const opcoesCat = categorias.map(([cat, rotulo]) => ({ valor: cat, rotulo }));

    const modalFator = (f = null, categoria = null) => Modal.abrir({
      titulo: f ? `Editar fator (${f.ano || ano})` : `Novo fator — ${titulo} · ${ano}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f
        ? { ...f, planejamento_id: plan.id }
        : { planejamento_id: plan.id, etapa, ano, ...(categoria ? { categoria } : {}) },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: etapa },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        Diag.campoCategoria(etapa),
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
    });

    // el.querySelector, não getElementById: PESTEL e Porter usam o mesmo id e
    // a seção anterior continua no DOM (oculta) — o global pegaria o botão errado
    el.querySelector('[data-novo-fator]')?.addEventListener('click', () => modalFator());
    el.querySelectorAll('[data-add-categoria]').forEach((b) => b.addEventListener('click', () =>
      modalFator(null, b.dataset.addCategoria)));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalFator(fatores.find((f) => f.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      const f = fatores.find((x) => x.id == b.dataset.excluir);
      const aviso = Number(f?.promovido)
        ? 'Excluir este fator? Ele também sai da SWOT e da Matriz GUT.'
        : 'Excluir este fator?';
      if (!confirm(aviso)) return;
      await App.api(`/api/fatores/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
    // "Ver na SWOT": abre a análise SWOT já rolando até o fator, com o filtro
    // de categoria do celular ajustado para o quadrante dele
    el.querySelectorAll('[data-ir-swot]').forEach((b) => b.addEventListener('click', () =>
      this.irParaFator('swot', b.dataset.irSwot, 'SWOT', b.dataset.catSwot)));

    // Botão da SWOT (promover ou trocar categoria): abre a matriz 2×2 embaixo
    // do próprio card, sem modal — um toque no quadrante já aplica a escolha
    const alternarPainel = (id) => {
      const alvo = el.querySelector(`[data-painel="${id}"]`);
      const abrindo = alvo.classList.contains('d-none');
      el.querySelectorAll('[data-painel]').forEach((p) => p.classList.add('d-none'));
      alvo.classList.toggle('d-none', !abrindo);
    };
    el.querySelectorAll('[data-promover], [data-editar-swot]').forEach((b) =>
      b.addEventListener('click', () => alternarPainel(b.dataset.promover || b.dataset.editarSwot)));

    el.querySelectorAll('[data-escolher-quadrante]').forEach((b) => b.addEventListener('click', async () => {
      const f = fatores.find((x) => x.id == b.dataset.fator);
      const quadrante = b.dataset.escolherQuadrante;
      if (Number(f.promovido) && f.promovido_categoria === quadrante) { alternarPainel(f.id); return; }
      b.disabled = true;
      try {
        if (Number(f.promovido)) {
          await App.api(`/api/fatores/${f.promovido_id}`, {
            planejamento_id: plan.id, etapa: 'SWOT', categoria: quadrante, descricao: f.promovido_descricao,
          });
        } else {
          await App.api(`/api/fatores/${f.id}/promover`, { planejamento_id: plan.id, quadrante });
        }
        App.recarregarSecaoAtiva();
      } catch (e) {
        b.disabled = false;
        alert(e.message);
      }
    }));

    el.querySelectorAll('[data-desvincular]').forEach((b) => b.addEventListener('click', async () => {
      const f = fatores.find((x) => x.id == b.dataset.desvincular);
      if (!confirm('Remover este fator da análise SWOT?')) return;
      try {
        await App.api(`/api/fatores/${f.promovido_id}/excluir`, { planejamento_id: plan.id });
        App.recarregarSecaoAtiva();
      } catch (e) {
        alert(e.message);
      }
    }));
  },
};

const SecaoCenario = {
  async carregar() {
    const base = await Diag.preparar('secao-cenario');
    if (!base) return;
    const { el, plan, ano } = base;
    const itens = await App.api(`/api/cenario?planejamento_id=${plan.id}&ano=${ano}`);

    const bloco = (tipo, titulo) => {
      const lista = itens.filter((i) => i.tipo === tipo);
      // data-card-fator é o que permite chegar aqui vindo da Coleta
      const linhas = lista.map((i, idx) => `
        <div class="card mb-2" data-card-fator="${i.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator"><strong>${idx + 1}.</strong> ${Modal.esc(i.descricao)}</div>
          ${Diag.seloColeta(i)}
          ${App.podeEditar() ? `<div class="botoes-fator d-flex gap-1 mt-2 justify-content-end">
            <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}" title="Editar" aria-label="Editar">✎</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}" title="Excluir" aria-label="Excluir">×</button>
          </div>` : ''}
        </div></div>`).join('');
      const cor = 'var(--verde-coperdia)';
      return `<div class="col-md-6" data-coluna-categoria="${tipo}">
        <div class="d-flex align-items-center">
          ${Diag.iconeOrientacao(tipo, cor, titulo)}
          <h2 class="h6 text-uppercase text-muted mb-0">${titulo} ${Diag.contadorCards(lista.length, cor)}</h2>
          ${Diag.botaoAddCategoria(tipo, titulo, cor)}
        </div>
        ${Diag.painelOrientacao(tipo, cor)}
        ${linhas || '<div class="text-muted small">Nenhum item.</div>'}
      </div>`;
    };

    const contagensCen = {
      SITUACAO_ATUAL: itens.filter((i) => i.tipo === 'SITUACAO_ATUAL').length,
      TENDENCIA: itens.filter((i) => i.tipo === 'TENDENCIA').length,
    };

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Análise de Cenário — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          ${Diag.seletorAno('cenario')}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-cenario">+ Novo item</button>' : ''}
        </div>
      </div>
      ${Diag.seletorCategoriaMovel('CENARIO', [
        ['SITUACAO_ATUAL', 'Situação Atual'], ['TENDENCIA', 'Tendências'],
      ], contagensCen)}
      <div class="row g-4">
        ${bloco('SITUACAO_ATUAL', 'Situação Atual')}
        ${bloco('TENDENCIA', 'Tendências')}
      </div>`;

    Diag.ligarSeletorAno(el);
    Diag.ligarSeletorCategoriaMovel(el, 'CENARIO');
    Diag.ligarVerMais(el);
    Diag.aplicarDestaque(el, 'cenario');
    Diag.ligarSeloColeta(el, 'Análise de Cenário');
    Diag.ligarOrientacoes(el);
    if (!App.podeEditar()) return;
    const modalItem = (i = null, tipoNovo = null) => Modal.abrir({
      titulo: i ? `Editar item do cenário (${i.ano || ano})` : `Novo item do cenário · ${ano}`,
      url: i ? `/api/cenario/${i.id}` : '/api/cenario',
      valores: i
        ? { ...i, planejamento_id: plan.id }
        : { planejamento_id: plan.id, ano, ...(tipoNovo ? { tipo: tipoNovo } : {}) },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: [
          { valor: 'SITUACAO_ATUAL', rotulo: 'Situação atual' },
          { valor: 'TENDENCIA', rotulo: 'Tendência' },
        ]},
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 4 },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: 0 },
      ],
    });
    document.getElementById('btn-novo-cenario').addEventListener('click', () => modalItem());
    el.querySelectorAll('[data-add-categoria]').forEach((b) => b.addEventListener('click', () =>
      modalItem(null, b.dataset.addCategoria)));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalItem(itens.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este item?')) return;
      await App.api(`/api/cenario/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

const SecaoPestel = {
  carregar: () => Diag.etapaFatores({
    idSecao: 'secao-pestel',
    etapa: 'PESTEL',
    titulo: 'PESTEL',
    descricao: '',
    comPromocao: true,
    categorias: Diag.CATEGORIAS_ETAPA.PESTEL,
  }),
};

const SecaoPorter = {
  carregar: () => Diag.etapaFatores({
    idSecao: 'secao-porter',
    etapa: 'PORTER',
    titulo: 'Porter — 5 Forças',
    descricao: '',
    comPromocao: true,
    categorias: Diag.CATEGORIAS_ETAPA.PORTER,
  }),
};

const SecaoSwot = {
  async carregar() {
    const base = await Diag.preparar('secao-swot');
    if (!base) return;
    const { el, plan, ano } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT&ano=${ano}`);

    const quadrante = (cat, rotulo, cor) => {
      const itens = fatores.filter((f) => f.categoria === cat);
      const cartoes = itens.map((f) => {
        // Selos levam à etapa de origem e à Matriz GUT, já no card correspondente
        const origem = f.origem_etapa
          ? `<button type="button" class="badge selo-link text-bg-light border" data-ir-origem="${f.promovido_de_id}"
               data-etapa-origem="${f.origem_etapa}" data-cat-origem="${f.origem_categoria}"
               title="Ver este fator no ${f.origem_etapa}">${f.origem_etapa}</button>` : '';
        const gut = f.score
          ? `<button type="button" class="badge selo-link text-bg-warning" data-ir-gut="${f.id}"
               title="Ver na Matriz GUT">GUT ${f.score}</button>` : '';
        return `<div class="card mb-2" data-card-fator="${f.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          <div class="botoes-fator d-flex gap-1 mt-2 align-items-center flex-wrap">
            ${origem}${gut}
            ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
              <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}" title="Excluir" aria-label="Excluir">×</button>
            </span>` : ''}
          </div>
        </div></div>`;
      }).join('');
      return `<div class="col-md-6" data-coluna-categoria="${cat}">
        <div class="p-2 rounded" style="background:${cor}18; border-top: 3px solid ${cor}">
          <div class="d-flex align-items-center mb-2">
            ${Diag.iconeOrientacao(cat, cor, rotulo)}
            <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
              <span class="ambiente-quadrante">(${Diag.DICAS_QUADRANTE[cat]})</span>
              ${Diag.contadorCards(itens.length, cor)}</span>
            ${Diag.botaoAddCategoria(cat, rotulo, cor)}
          </div>
          ${Diag.painelOrientacao(cat, cor)}
          ${cartoes || '<div class="text-muted small">Nenhum fator.</div>'}
        </div>
      </div>`;
    };

    const contagensSwot = Object.fromEntries(Object.keys(Diag.QUADRANTES)
      .map((cat) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>SWOT — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          ${Diag.seletorAno('swot')}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-swot">+ Novo fator</button>' : ''}
        </div>
      </div>
      ${Diag.seletorCategoriaMovel('SWOT', [
        ['FORCA', 'Forças'], ['FRAQUEZA', 'Fraquezas'],
        ['OPORTUNIDADE', 'Oportunidades'], ['AMEACA', 'Ameaças'],
      ], contagensSwot)}
      <div class="row g-3">
        ${quadrante('FORCA', 'Forças', '#007a45')}
        ${quadrante('FRAQUEZA', 'Fraquezas', '#b08d4f')}
        ${quadrante('OPORTUNIDADE', 'Oportunidades', '#2c7fb8')}
        ${quadrante('AMEACA', 'Ameaças', '#8f3b3b')}
      </div>`;

    Diag.ligarSeletorAno(el);
    Diag.ligarSeletorCategoriaMovel(el, 'SWOT');
    Diag.ligarVerMais(el);
    Diag.aplicarDestaque(el, 'swot');
    Diag.ligarSeloColeta(el, 'SWOT');
    Diag.ligarOrientacoes(el);

    el.querySelectorAll('[data-ir-origem]').forEach((b) => b.addEventListener('click', () => {
      const etapa = b.dataset.etapaOrigem;
      Diag.irParaFator(etapa.toLowerCase(), b.dataset.irOrigem, etapa, b.dataset.catOrigem);
    }));
    el.querySelectorAll('[data-ir-gut]').forEach((b) => b.addEventListener('click', () =>
      Diag.irParaFator('gut', b.dataset.irGut)));

    if (!App.podeEditar()) return;
    const modalFator = (f = null, categoria = null) => Modal.abrir({
      titulo: f ? `Editar fator da SWOT (${f.ano || ano})` : `Novo fator da SWOT · ${ano}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f
        ? { ...f, planejamento_id: plan.id }
        : { planejamento_id: plan.id, etapa: 'SWOT', ano, ...(categoria ? { categoria } : {}) },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: 'SWOT' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        Diag.campoQuadrante('categoria', 'Quadrante'),
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
    });
    document.getElementById('btn-novo-swot').addEventListener('click', () => modalFator());
    el.querySelectorAll('[data-add-categoria]').forEach((b) => b.addEventListener('click', () =>
      modalFator(null, b.dataset.addCategoria)));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalFator(fatores.find((f) => f.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      const f = fatores.find((x) => x.id == b.dataset.excluir);
      const aviso = f?.score
        ? 'Excluir este fator da SWOT? A avaliação dele na Matriz GUT também será apagada.'
        : 'Excluir este fator da SWOT?';
      if (!confirm(aviso)) return;
      await App.api(`/api/fatores/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

const SecaoGut = {
  // Severidade do score (1–125) colore o selo: alta, média ou baixa prioridade
  corScore(score) {
    if (score >= 64) return '#8f3b3b';
    if (score >= 27) return '#b08d4f';
    return '#007a45';
  },

  async carregar() {
    const base = await Diag.preparar('secao-gut');
    if (!base) return;
    const { el, plan, ano } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT&ano=${ano}`);
    const ordenados = [...fatores].sort((a, b) => (b.score || 0) - (a.score || 0));
    const editar = App.podeEditar();

    // Celular: cartões tocáveis. Computador: a tabela de ranking de sempre.
    const cartoes = ordenados.map((f, idx) => {
      const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
      const avaliado = !!f.score;
      const notas = [['G', f.gravidade], ['U', f.urgencia], ['T', f.tendencia]].map(([k, v]) => `
        <div class="text-center">
          <div class="gut-chave">${k}</div>
          <div class="gut-nota ${avaliado ? '' : 'text-black-50'}">${v ?? '—'}</div>
        </div>`).join('');
      return `<div class="card gut-card mb-2 ${avaliado ? '' : 'sem-nota'}" style="--cor-quad:${cor}"
        data-card-fator="${f.id}" ${editar ? `data-avaliar="${f.id}" role="button" tabindex="0"` : ''}>
        <div class="card-body py-2 px-3">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="gut-rank ${avaliado ? '' : 'text-black-50'}">${avaliado ? `${idx + 1}º` : '—'}</span>
            <span class="badge gut-tag" style="color:${cor};background:${cor}1f">${Diag.QUADRANTES[f.categoria]}</span>
            ${editar ? '<span class="ms-auto gut-acao">avaliar ✎</span>' : ''}
          </div>
          <div class="small texto-fator mb-2">${Modal.esc(f.descricao)}</div>
          <div class="d-flex align-items-end">
            <div class="d-flex gap-3">${notas}</div>
            <div class="ms-auto text-end">
              <div class="gut-chave">SCORE</div>
              <span class="badge gut-score" style="background:${avaliado ? this.corScore(f.score) : '#d8deda'}">${avaliado ? f.score : '—'}</span>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');

    const linhas = ordenados.map((f, idx) => {
      const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
      return `<tr data-card-fator="${f.id}">
        <td>${f.score ? `<strong>${idx + 1}º</strong>` : '—'}</td>
        <td><span class="badge gut-tag" style="color:${cor};background:${cor}1f">${Diag.QUADRANTES[f.categoria]}</span></td>
        <td class="small">${Modal.esc(f.descricao)}</td>
        <td class="text-center">${f.gravidade ?? '—'}</td>
        <td class="text-center">${f.urgencia ?? '—'}</td>
        <td class="text-center">${f.tendencia ?? '—'}</td>
        <td class="text-center">${f.score
          ? `<span class="badge gut-score" style="background:${this.corScore(f.score)}">${f.score}</span>` : '—'}</td>
        <td>${editar ? `<button class="btn btn-sm btn-outline-secondary" data-avaliar="${f.id}">Avaliar</button>` : ''}</td>
      </tr>`;
    }).join('');

    const vazio = 'Cadastre fatores na SWOT para avaliá-los aqui.';
    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Matriz GUT — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        ${Diag.seletorAno('gut')}
      </div>
      <div class="gut-legenda-barra small mb-3">
        <span class="gl-titulo">Prioridade = Gravidade × Urgência × Tendência (1–125)</span>
        <span class="gl-faixas">
          <span><i style="background:${this.corScore(64)}"></i>Alta (≥ 64) — tratar agora</span>
          <span><i style="background:${this.corScore(27)}"></i>Média (27–63)</span>
          <span><i style="background:${this.corScore(1)}"></i>Baixa (&lt; 27)</span>
        </span>
      </div>

      <div class="d-md-none">
        ${cartoes || `<div class="text-muted small">${vazio}</div>`}
        ${ordenados.length ? `<div class="legenda-quadrantes">
          ${Object.entries(Diag.QUADRANTES).map(([cat, rotulo]) =>
            `<span><i style="background:${Diag.CORES_QUADRANTE[cat]}"></i>${rotulo}</span>`).join('')}
        </div>` : ''}
      </div>

      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Ranking</th><th>Quadrante</th><th>Fator</th>
            <th class="text-center">G</th><th class="text-center">U</th><th class="text-center">T</th>
            <th class="text-center">Score</th><th></th>
          </tr></thead>
          <tbody>${linhas || `<tr><td colspan="8" class="text-muted">${vazio}</td></tr>`}</tbody>
        </table>
      </div>`;

    Diag.ligarSeletorAno(el);
    Diag.ligarVerMais(el);
    Diag.aplicarDestaque(el, 'gut');
    if (!editar) return;
    const escala = [1, 2, 3, 4, 5].map((n) => ({ valor: n, rotulo: String(n) }));
    const abrirAvaliacao = (f) => Modal.abrir({
      titulo: 'Avaliação GUT',
      url: `/api/fatores/${f.id}/gut`,
      valores: {
        planejamento_id: plan.id,
        gravidade: f.gravidade || 3, urgencia: f.urgencia || 3, tendencia: f.tendencia || 3,
      },
      campos: [
        { nome: 'fator_info', rotulo: 'Fator avaliado', tipo: 'info', texto: f.descricao,
          barra: {
            titulo: Diag.QUADRANTES[f.categoria] || 'SWOT',
            cor: Diag.CORES_QUADRANTE[f.categoria] || '#007a45',
            origem: `Análise SWOT · ${f.ano || ano}`,
          } },
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'gravidade', rotulo: 'Gravidade — "Qual é o tamanho do estrago?"', tipo: 'botoes', opcoes: escala,
          ajuda: '1 = leve · 5 = gravíssimo. Se não for resolvido, qual a intensidade do prejuízo — financeiro, operacional, legal ou de reputação?' },
        { nome: 'urgencia', rotulo: 'Urgência — "O que acontece se eu esperar?"', tipo: 'botoes', opcoes: escala,
          ajuda: '1 = pode esperar · 5 = agir já. Qual o prazo para agir e o quanto adiar pressiona o cronograma ou gera perdas imediatas?' },
        { nome: 'tendencia', rotulo: 'Tendência — "Se nada for feito, isso vira uma bola de neve?"', tipo: 'botoes', opcoes: escala,
          ajuda: '1 = estável · 5 = piora rápido. O problema tende a continuar do mesmo tamanho ou piorar rapidamente? (velocidade de deterioração)' },
      ],
      // Só há o que redefinir se o fator já tiver notas registradas. Redefinir
      // zera a avaliação e volta os botões ao padrão SEM fechar o modal, para
      // seguir editando (manterAberto).
      extra: f.score ? {
        rotulo: 'Redefinir',
        confirmar: 'Zerar as notas GUT deste fator para refazer a avaliação?',
        manterAberto: true,
        aoClicar: async () => {
          await App.api(`/api/fatores/${f.id}/gut/limpar`, { planejamento_id: plan.id });
          ['gravidade', 'urgencia', 'tendencia'].forEach((nome) => {
            const alvo = document.querySelector(`#campo-${nome} input[value="3"]`);
            if (alvo) alvo.checked = true;
          });
        },
      } : null,
    });

    el.querySelectorAll('[data-avaliar]').forEach((b) => {
      const abrir = () => abrirAvaliacao(fatores.find((x) => x.id == b.dataset.avaliar));
      b.addEventListener('click', abrir);
      // Cartão inteiro é tocável: teclado também abre a avaliação
      if (b.getAttribute('role') === 'button') {
        b.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); }
        });
      }
    });
  },
};

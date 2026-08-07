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

  /**
   * As notas de um fator no relatório: o que o cartão mostra em selo vira uma
   * linha de texto embaixo do item. Sem o NOME de quem sugeriu — relatório é
   * documento da análise, e a autoria da voz vive na sala, não nele.
   */
  notasFator(f) {
    const n = [];
    if (Number(f.promovido) && f.promovido_categoria) {
      n.push(`Promovido à SWOT: ${this.QUADRANTES[f.promovido_categoria] || f.promovido_categoria}`);
    }
    if (f.origem_etapa) n.push(`Origem: ${f.origem_etapa}`);
    if (f.score) n.push(`GUT ${f.score}`);
    if (f.acao_titulo) n.push(`Plano de ação: ${f.acao_titulo}`);
    else if (f.acao_em) n.push('Aguardando plano de ação');
    if (f.coleta_item_id) n.push('Veio da Coleta de ideias');
    if (Number(f.quiz_vozes)) n.push(`${f.quiz_vozes} voz(es) da sala`);
    return n;
  },

  /**
   * O cabeçalho da análise — título, ano, "+ Novo", selo da sala — fica FIXO
   * logo abaixo da topbar, e o cabeçalho de cada coluna gruda logo abaixo dele:
   * o contexto da análise acompanha a rolagem enquanto só os cartões se movem.
   *
   * O degrau é calculado aqui porque a altura do bloco muda com a largura (no
   * celular ele quebra em três linhas) e com o conteúdo (o selo da sala longe
   * ganha um botão). Em CSS isso só daria um palpite em `rem`, e um palpite
   * curto deixaria o cabeçalho da coluna POR CIMA do da análise.
   */
  observadoresCabecalho: {},

  ligarCabecalhoFixo(el) {
    const cab = el.querySelector('[data-cabecalho-analise]');
    if (!cab) return;
    const medir = () => {
      // Seção escondida mede zero — e zero aqui empilharia os dois cabeçalhos
      const h = Math.round(cab.getBoundingClientRect().height);
      if (h) el.style.setProperty('--altura-cabecalho', `${h}px`);
    };
    medir();
    // O observador é trocado a cada pintura: o elemento anterior já saiu do
    // documento (innerHTML novo) e observá-lo seria medir o que ninguém vê.
    this.observadoresCabecalho[el.id]?.disconnect();
    const ro = new ResizeObserver(medir);
    ro.observe(cab);
    this.observadoresCabecalho[el.id] = ro;
  },

  // Cartão baixo: texto longo é cortado em 3 linhas (ver `.texto-fator`) e
  // ganha um "ver mais" para expandir/recolher, dando noção de quantos cards
  // existem sem obrigar a rolar um parágrafo por vez
  ligarVerMais(el) {
    el.querySelectorAll('.texto-fator').forEach((t) => {
      // A marca fica no texto, não no irmão seguinte: o botão passou a morar
      // dentro do rodapé, e olhar `nextElementSibling` deixaria de reconhecer
      // o que já foi ligado — cada troca de filtro no celular empilharia mais
      // um "ver mais" no mesmo cartão.
      if (t.dataset.verMais) return;
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
      t.dataset.verMais = '1';

      // O "ver mais" entra na MESMA linha dos botões do cartão. Numa linha só
      // para ele, o cartão ganhava uma faixa vazia à direita do texto e outra
      // à esquerda dos botões — em dezessete itens de cenário, isso é uma tela
      // inteira de rolagem no celular.
      const rodape = t.parentElement?.querySelector('.botoes-fator');
      if (!rodape) {
        t.after(btn);
        return;
      }
      // Sem um `ms-auto` no rodapé (o cartão do Cenário), é o próprio "ver
      // mais" que empurra os botões para a direita; com ele, dois automáticos
      // dividiriam o vão e o que está no meio ficaria solto.
      if (!rodape.querySelector('.ms-auto')) btn.classList.add('me-auto');
      rodape.prepend(btn);
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
  /**
   * Os selos de ORIGEM do registro (a ideia da Coleta, as vozes da sala). São
   * inline de propósito: cada um numa linha própria custava duas linhas por
   * cartão, e um cartão de três palavras ficava com a altura de um parágrafo.
   * Quem os posiciona é a faixa dos botões, que já existe em todo cartão.
   */
  selosOrigem(registro) {
    return this.seloColeta(registro) + this.seloSala(registro);
  },

  seloColeta(registro) {
    if (!registro.coleta_item_id) return '';
    return `<button type="button" class="btn btn-sm selo-link"
      data-ir-coleta="${registro.coleta_item_id}"
      title="Ver a ideia original na Coleta">Coleta · ${Modal.esc(registro.coleta_autor || '—')}${
      Number(registro.coleta_vozes) > 1 ? ` +${Number(registro.coleta_vozes) - 1}` : ''}</button>`;
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

  /**
   * Estado do fator da SWOT em relação ao plano de ação, nos mesmos três
   * estados da ideia da Coleta: fora da fila (botão que encaminha), na fila
   * (selo que desfaz) e já convertida (selo que leva à ação em Projetos).
   *
   * O selo "Virou ação" NÃO oferece desfazer: a partir dali quem manda é a
   * ação, e desfazer aqui a deixaria no plano sem nenhuma origem. O servidor
   * recusa do mesmo jeito — este é o aviso antes da recusa, não no lugar dela.
   */
  seloPlanoAcao(f) {
    if (f.desdobramento_id) {
      return `<button type="button" class="badge selo-link text-bg-success" data-ir-acao="${f.desdobramento_id}"
        title="Ver a ação no plano: ${Modal.esc(f.acao_titulo || '')}">Virou ação ↗</button>`;
    }
    if (!App.podeEditar()) return '';
    if (f.acao_em) {
      return `<button type="button" class="badge selo-link text-bg-secondary" data-tirar-acao="${f.id}"
        title="Aguardando alocação em Projetos — clique para tirar da fila">Aguardando ação</button>`;
    }
    return `<button class="btn btn-sm btn-outline-primary" data-plano-acao="${f.id}"
      title="Encaminhar para o plano de ação">→ Plano de ação</button>`;
  },

  // Botões compactos abaixo do texto: SWOT à esquerda, editar/excluir à direita.
  // Depois de promovido, o botão mostra a categoria atribuída e reabre a edição.
  botoesFator(f, planId, comPromocao, selos = '') {
    // A faixa existe quando há selo, mesmo sem poder editar: a origem do
    // registro é informação de leitura, não de edição
    if (!App.podeEditar()) {
      return selos ? `<div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">${selos}</div>` : '';
    }
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
    return `<div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
      ${selos}${swot}
      <span class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}" title="Excluir" aria-label="Excluir">×</button>
      </span>
    </div>`;
  },

  // Renderiza uma etapa de fatores em colunas de categorias
  // ===== A sala nas telas de etapa (PESTEL, Porter, SWOT) =====
  // O estado é do DONO (a seção), nunca do Diag: as três coexistem no DOM
  // (as seções não são destruídas ao navegar, só ganham d-none) e um estado
  // compartilhado faria o polling de uma repintar a outra.

  /**
   * A pergunta ATIVA é desta etapa E deste ano? É o que o selo precisa saber
   * para dizer "na sala" — a seção sozinha não basta, porque o ano do
   * diagnóstico é um seletor à parte.
   */
  salaNestaEtapa(dono, etapa, ano) {
    const p = dono.quiz?.pergunta;
    return !!p && p.alvo_tipo === 'FATOR' && p.etapa === etapa
      && Number(p.ano) === Number(ano);
  },

  /** A pergunta em foco (ou a ativa) quando ela é desta etapa e ano. */
  quizFoco(dono, etapa, ano) {
    const p = dono.quiz?.foco || dono.quiz?.pergunta;
    // A sala é do projeto: a ativa pode ser de outra análise. Sem conferir o
    // alvo, uma pergunta de cenário (etapa nula) casaria com qualquer coluna.
    if (!p || p.alvo_tipo !== 'FATOR') return null;
    return p.etapa === etapa && Number(p.ano) === Number(ano) ? p : null;
  },

  /** O 🎤 de uma categoria — selo quando ela já é a pergunta ATIVA. */
  quizMic(dono, etapa, ano, cat, rotulo, cor) {
    const ativa = dono.quiz?.pergunta;
    const naSala = ativa && ativa.alvo_tipo === 'FATOR' && ativa.etapa === etapa
      && Number(ativa.ano) === Number(ano) && ativa.categoria === cat;
    return QuizSala.microfone(
      { alvo_tipo: 'FATOR', etapa, ano, alvos: [cat] }, rotulo,
      { ativo: naSala, cor, pergunta: naSala ? ativa.id : null });
  },

  /**
   * As sugestões da categoria em foco. O alvo FATOR não tem lado — a categoria
   * JÁ é a pergunta —, então é uma lista só. "Usar" leva o texto ao formulário
   * do fator: aceitar é ato de quem conduz, e o texto final é o dele.
   */
  quizPainel(dono, etapa, ano) {
    const p = this.quizFoco(dono, etapa, ano);
    if (!p) return '';
    const sugestoes = dono.quiz?.sugestoes || [];
    const recolhido = dono.quizUi?.painelRecolhido;
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      ${QuizSala.cabecalhoPainel(dono, p, sugestoes)}
      ${recolhido ? '' : `<div class="coluna-quiz coluna-escolha mt-2">
        ${QuizSala.fichas(sugestoes, { virou: 'fator' })}
      </div>`}
    </div></div>`;
  },

  /** Vozes da sala registradas no registro: selo próprio, sem link. */
  seloSala(f) {
    const n = Number(f.quiz_vozes || 0);
    return n ? `<span class="badge text-bg-light border" title="${n} sugestão(ões) da sala
      usada(s) aqui">🎤 ${n}</span>` : '';
  },

  /** Liga selo, 🎤 e as ações do painel de sugestões de uma tela de etapa. */
  quizLigarEtapa(dono, el, etapa, ano, modalFator) {
    QuizSala.ligarSelo(el);
    QuizSala.ligarMicrofones(dono, el);
    // Antes da saída por `modalFator`: o 👁 e a altura da grade valem para
    // LEITURA também, que acompanha o encontro sem aceitar sugestão nenhuma
    QuizSala.ligarVozes(dono, el);
    el.querySelectorAll('[data-reabrir-foco]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/quiz/pergunta/${b.dataset.reabrirFoco}/ativar`,
          { planejamento_id: dono.plan.id });
      } catch (e) {
        alert(e.message);
      }
      // Aberta para a sala, a pergunta vira a ativa: o foco volta ao padrão
      dono.perguntaFoco = null;
      App.recarregarSecaoAtiva();
    }));
    if (!modalFator) return;
    QuizSala.ligarRecolher(dono, el);
    el.querySelectorAll('[data-usar-sugestao]').forEach((b) => b.addEventListener('click', () => {
      const sg = (dono.quiz?.sugestoes || []).find((x) => x.id == b.dataset.usarSugestao);
      const p = this.quizFoco(dono, etapa, ano);
      if (!sg || !p) return;
      modalFator(null, p.categoria, sg);
    }));
    el.querySelectorAll('[data-excluir-sugestao]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta sugestão? Ela some para a sala também.')) return;
      try {
        await App.api(`/api/quiz/sugestao/${b.dataset.excluirSugestao}/excluir`,
          { planejamento_id: dono.plan.id });
      } catch (e) {
        alert(e.message);
      }
      App.recarregarSecaoAtiva();
    }));
  },

  async etapaFatores({ idSecao, etapa, titulo, descricao, categorias, comPromocao, dono }) {
    // Foco vindo do "Ver" da aba Sala: posiciona o ano ANTES de resolver a base
    const vindo = QuizSala.consumirFoco(idSecao.replace('secao-', ''));
    if (vindo) {
      dono.perguntaFoco = vindo.perguntaId;
      this.anoSelecionado = Number(vindo.pergunta.ano);
    }
    const base = await this.preparar(idSecao);
    if (!base) return;
    const { el, plan, ano } = base;
    dono.plan = plan;
    const [fatores, quiz] = await Promise.all([
      App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=${etapa}&ano=${ano}`),
      QuizSala.estado(plan.id, dono.perguntaFoco),
    ]);
    dono.quiz = quiz;

    const colunas = categorias.map(([cat, rotulo, cor]) => {
      const itens = fatores.filter((f) => f.categoria === cat);
      const cartoes = itens.map((f) => `
        <div class="card mb-2" data-card-fator="${f.id}"><div class="card-body py-2 px-2">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          ${this.botoesFator(f, plan.id, comPromocao, this.selosOrigem(f))}
          ${comPromocao && App.podeEditar() ? this.painelQuadrantes(f) : ''}
        </div></div>`).join('');
      return `<div class="col-12 col-sm-6 col-md-4 col-xl-2 coluna-categoria" data-coluna-categoria="${cat}">
        <div class="caixa-coluna">
          <div class="cabecalho-coluna d-flex align-items-center mb-2">
            ${this.iconeOrientacao(cat, cor, rotulo)}
            <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
              ${this.contadorCards(itens.length, cor)}</span>
            ${this.botaoAddCategoria(cat, rotulo, cor)}
            ${this.quizMic(dono, etapa, ano, cat, rotulo, cor)}
          </div>
          <div class="corpo-coluna">
            ${this.painelOrientacao(cat, cor)}
            ${cartoes || '<div class="text-muted small">—</div>'}
          </div>
        </div>
      </div>`;
    }).join('');

    const contagens = Object.fromEntries(
      categorias.map(([cat]) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    el.innerHTML = `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h1>${titulo} — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${this.seletorAno(etapa)}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? `<button class="btn btn-verde btn-sm" data-novo-fator>+ Novo fator</button>` : ''}
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap"
          data-selo-quiz>${QuizSala.selo(dono, idSecao.replace('secao-', ''),
            this.salaNestaEtapa(dono, etapa, ano))}</div>
      </div>
      ${descricao ? `<p class="text-muted">${descricao} <em>A análise é anual — troque o ano acima para revisar ou consultar edições anteriores.</em></p>` : ''}
      <div data-quiz-vivo>${this.quizPainel(dono, etapa, ano)}</div>
      ${this.seletorCategoriaMovel(etapa, categorias.map(([cat, rotulo]) => [cat, rotulo]), contagens)}
      <div class="row g-3">${colunas}</div>`;

    this.ligarCabecalhoFixo(el);
    // O relatório é montado no clique, com o que ESTA tela carregou: os mesmos
    // fatores, na mesma ordem das colunas, do mesmo ano do seletor.
    RelatorioAnalise.ligar(el, () => ({
      titulo: `${titulo} — ${App.rotuloContexto()} · ${ano}`,
      contexto: `Diagnóstico · análise de ${ano} · ${fatores.length} fator(es)`,
      secoes: categorias.map(([cat, rotulo, cor, dica]) => ({
        rotulo,
        cor,
        dica,
        itens: fatores.filter((f) => f.categoria === cat)
          .map((f) => ({ texto: f.descricao, notas: this.notasFator(f) })),
      })),
    }));
    this.ligarSeletorAno(el);
    this.ligarSeletorCategoriaMovel(el, etapa);
    this.ligarVerMais(el);
    this.aplicarDestaque(el, idSecao.replace('secao-', ''));
    this.ligarSeloColeta(el, ({ PESTEL: 'PESTEL', PORTER: 'Porter', SWOT: 'SWOT' })[etapa] || etapa);
    this.ligarOrientacoes(el);
    dono.assinaturaQuiz = QuizSala.assinatura(dono.quiz);
    QuizSala.armarRelogio(dono);
    if (!App.podeEditar()) {
      // O "Ir até lá" do selo vale para LEITURA também: acompanhar o encontro
      // não é escrever nele
      QuizSala.ligarSelo(el);
      return;
    }
    const opcoesCat = categorias.map(([cat, rotulo]) => ({ valor: cat, rotulo }));

    // `sugestao` chega do painel da sala: o texto entra como RASCUNHO (o condutor
    // redige antes de salvar) e o id viaja em `sugestoes`, que é o conjunto de
    // vozes amarradas ao fator. O vínculo vai pelo `transformar`, nunca por um
    // campo `hidden`: hidden guarda texto, e uma lista viraria a string "12".
    const modalFator = (f = null, categoria = null, sugestao = null) => Modal.abrir({
      titulo: sugestao ? `Aceitar sugestão da sala · ${ano}`
        : f ? `Editar fator (${f.ano || ano})` : `Novo fator — ${titulo} · ${ano}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f
        ? { ...f, planejamento_id: plan.id }
        : {
            planejamento_id: plan.id, etapa, ano,
            ...(categoria ? { categoria } : {}),
            ...(sugestao ? { descricao: sugestao.texto } : {}),
          },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: etapa },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        ...(sugestao ? [{ nome: 'origem_sala', rotulo: '', tipo: 'info',
          texto: `${sugestao.autor}: “${sugestao.texto}”`,
          barra: { cor: '#007a45', titulo: 'Voz da sala' } }] : []),
        Diag.campoCategoria(etapa),
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
      ...(sugestao ? { transformar: (dd) => ({ ...dd, sugestoes: [sugestao.id] }) } : {}),
    });
    Diag.quizLigarEtapa(dono, el, etapa, ano, modalFator);

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

// Análise de Cenário — a primeira tela a consumir a SALA DO PROJETO: o mesmo
// PIN da cascata, do PESTEL e da tempestade. O condutor abre a sala aqui, a
// sala responde por lado (situação atual / tendência) e o texto que vira item
// é o que ELE redige ao aceitar — as vozes ficam registradas como origem.
const SecaoCenario = {
  plan: null,
  quiz: null,
  relogioQuiz: null,
  assinaturaQuiz: null,
  quizUi: { qrAberto: false, roteiroAberto: false },
  secaoId: 'secao-cenario',
  perguntaFoco: null,
  itens: [],

  async carregar() {
    // Foco vindo do "Ver" da aba Sala: posiciona o ano ANTES de resolver a
    // base, senão a tela abriria no ano do seletor e o foco cairia fora
    const vindo = QuizSala.consumirFoco('cenario');
    if (vindo) {
      this.perguntaFoco = vindo.perguntaId;
      this.aoNavegar(vindo.pergunta);
    }
    const base = await Diag.preparar('secao-cenario');
    if (!base) return;
    const { el, plan, ano } = base;
    this.plan = plan;
    const [itens, quiz] = await Promise.all([
      App.api(`/api/cenario?planejamento_id=${plan.id}&ano=${ano}`),
      QuizSala.estado(plan.id, this.perguntaFoco),
    ]);
    this.itens = itens;
    this.quiz = quiz;

    const bloco = (tipo, titulo) => {
      const lista = itens.filter((i) => i.tipo === tipo);
      // data-card-fator é o que permite chegar aqui vindo da Coleta
      const linhas = lista.map((i, idx) => `
        <div class="card mb-2" data-card-fator="${i.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator"><strong>${idx + 1}.</strong> ${Modal.esc(i.descricao)}</div>
          ${Diag.selosOrigem(i) || App.podeEditar()
            ? `<div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
                ${Diag.selosOrigem(i)}
                ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
                  <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}" title="Editar" aria-label="Editar">✎</button>
                  <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}" title="Excluir" aria-label="Excluir">×</button>
                </span>` : ''}
              </div>` : ''}
        </div></div>`).join('');
      const cor = 'var(--verde-coperdia)';
      return `<div class="col-md-6" data-coluna-categoria="${tipo}">
        <div class="caixa-coluna">
          <div class="cabecalho-coluna d-flex align-items-center mb-2">
            ${Diag.iconeOrientacao(tipo, cor, titulo)}
            <h2 class="h6 text-uppercase text-muted mb-0">${titulo} ${Diag.contadorCards(lista.length, cor)}</h2>
            ${Diag.botaoAddCategoria(tipo, titulo, cor)}
          </div>
          <div class="corpo-coluna">
            ${Diag.painelOrientacao(tipo, cor)}
            ${linhas || '<div class="text-muted small">Nenhum item.</div>'}
          </div>
        </div>
      </div>`;
    };

    const contagensCen = {
      SITUACAO_ATUAL: itens.filter((i) => i.tipo === 'SITUACAO_ATUAL').length,
      TENDENCIA: itens.filter((i) => i.tipo === 'TENDENCIA').length,
    };

    el.innerHTML = `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h1>Análise de Cenário — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.seletorAno('cenario')}
            ${QuizSala.microfone({ alvo_tipo: 'CENARIO', ano }, `o cenário de ${ano}`,
              { ativo: this.perguntaDoAno()?.situacao === 'ATIVA',
                pergunta: this.perguntaDoAno()?.situacao === 'ATIVA' ? this.perguntaDoAno().id : null })}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-cenario">+ Novo item</button>' : ''}
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap"
          id="selo-quiz-cenario">${QuizSala.selo(this, 'cenario', this.salaNesteAno(ano))}</div>
      </div>
      <div id="quiz-vivo-cenario">${this.painelVivo()}</div>
      ${Diag.seletorCategoriaMovel('CENARIO', [
        ['SITUACAO_ATUAL', 'Situação Atual'], ['TENDENCIA', 'Tendências'],
      ], contagensCen)}
      <div class="row g-4">
        ${bloco('SITUACAO_ATUAL', 'Situação Atual')}
        ${bloco('TENDENCIA', 'Tendências')}
      </div>`;

    QuizSala.ligarSelo(el);
    QuizSala.ligarMicrofones(this, el);
    // A assinatura é semeada com o que acabou de ser pintado: sem isso a
    // primeira batida (4s) sempre difere e repinta tudo de graça
    this.assinaturaQuiz = QuizSala.assinatura(this.quiz);
    QuizSala.armarRelogio(this);
    Diag.ligarCabecalhoFixo(el);
    RelatorioAnalise.ligar(el, () => ({
      titulo: `Análise de Cenário — ${App.rotuloContexto()} · ${ano}`,
      contexto: `Diagnóstico · análise de ${ano} · ${itens.length} item(ns)`,
      secoes: [['SITUACAO_ATUAL', 'Situação Atual'], ['TENDENCIA', 'Tendências']]
        .map(([tipo, rotulo]) => ({
          rotulo,
          cor: '#007a45',
          itens: itens.filter((i) => i.tipo === tipo)
            .map((i) => ({ texto: i.descricao, notas: Diag.notasFator(i) })),
        })),
    }));
    Diag.ligarSeletorAno(el);
    Diag.ligarSeletorCategoriaMovel(el, 'CENARIO');
    Diag.ligarVerMais(el);
    Diag.aplicarDestaque(el, 'cenario');
    Diag.ligarSeloColeta(el, 'Análise de Cenário');
    Diag.ligarOrientacoes(el);
    if (!App.podeEditar()) return;
    // `sugestao` chega do painel da sala: o texto entra como RASCUNHO (o
    // condutor redige antes de salvar) e o id viaja em `sugestoes`, que é o
    // conjunto de vozes amarradas ao item — editar o item depois sem esse campo
    // não desfaz nada, porque o servidor só mexe no vínculo quando ele vem.
    const modalItem = (i = null, tipoNovo = null, sugestao = null) => Modal.abrir({
      titulo: sugestao ? `Aceitar sugestão da sala · ${ano}`
        : i ? `Editar item do cenário (${i.ano || ano})` : `Novo item do cenário · ${ano}`,
      url: i ? `/api/cenario/${i.id}` : '/api/cenario',
      valores: i
        ? { ...i, planejamento_id: plan.id }
        : {
            planejamento_id: plan.id, ano,
            ...(tipoNovo ? { tipo: tipoNovo } : {}),
            ...(sugestao ? { descricao: sugestao.texto } : {}),
          },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        ...(sugestao ? [
          { nome: 'origem_sala', rotulo: '', tipo: 'info',
            texto: `${sugestao.autor}: “${sugestao.texto}”`,
            barra: { cor: sugestao.tipo_resposta === 'TENDENCIA' ? '#8f3b3b' : '#007a45',
                     titulo: sugestao.tipo_resposta === 'TENDENCIA' ? 'Tendência' : 'Situação atual' } },
        ] : []),
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: [
          { valor: 'SITUACAO_ATUAL', rotulo: 'Situação atual' },
          { valor: 'TENDENCIA', rotulo: 'Tendência' },
        ]},
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 4 },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: 0 },
      ],
      // O vínculo viaja pelo transformar, nunca por um campo `hidden`: hidden
      // guarda texto, e uma lista viraria a string "12" no caminho de volta.
      // Sem a chave `sugestoes` o servidor não mexe em vínculo nenhum — é o que
      // faz uma edição comum do item preservar as vozes já registradas.
      ...(sugestao ? { transformar: (d) => ({ ...d, sugestoes: [sugestao.id] }) } : {}),
    });
    this.modalItem = modalItem;
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
    this.ligarPainelVivo(el);
  },

  // ---- A sala do projeto nesta tela ----
  /** Vozes da sala registradas no item: selo próprio, sem link (elas não moram na Coleta). */
  seloSala(i) {
    const n = Number(i.quiz_vozes || 0);
    return n ? `<div class="mt-1"><span class="badge text-bg-light border"
      title="Sugestões da sala usadas neste item">Sala · ${n} voz(es)</span></div>` : '';
  },

  /**
   * A pergunta ATIVA é o cenário do ano EXIBIDO? O selo precisa disto: o ano do
   * diagnóstico é um seletor à parte, e dizer "na sala" com a tela em 2027 e a
   * sala em 2026 deixa o condutor sem painel, sem 🎤 aceso e sem atalho.
   */
  salaNesteAno(ano) {
    const p = this.quiz?.pergunta;
    return !!p && p.alvo_tipo === 'CENARIO' && Number(p.ano) === Number(ano);
  },

  /** A pergunta em foco (ou a ativa) quando ela é o cenário do ano exibido. */
  perguntaDoAno() {
    const p = this.quiz?.foco || this.quiz?.pergunta;
    // A sala é do projeto: a ativa pode ser de outra análise. Sem conferir o
    // alvo, uma pergunta da cascata (ano nulo) casaria com qualquer ano.
    if (!p || p.alvo_tipo !== 'CENARIO') return null;
    return Number(p.ano) === Number(Diag.ano()) ? p : null;
  },

  /** Navegar pelo roteiro leva ESTA tela ao ano da pergunta examinada. */
  aoNavegar(pergunta) {
    if (pergunta.alvo_tipo !== 'CENARIO') return;
    Diag.anoSelecionado = Number(pergunta.ano);
  },

  async aoBater(quizNovo) {
    if (App.secaoAtiva !== 'cenario') return;
    const assinatura = QuizSala.assinatura(quizNovo);
    if (assinatura === this.assinaturaQuiz) return;
    this.assinaturaQuiz = assinatura;
    const el = document.getElementById(this.secaoId);
    const selo = document.getElementById('selo-quiz-cenario');
    if (selo) {
      selo.innerHTML = QuizSala.selo(this, 'cenario', this.salaNesteAno(Diag.ano()));
      QuizSala.ligarSelo(el);
    }
    const vivo = document.getElementById('quiz-vivo-cenario');
    if (vivo) {
      vivo.innerHTML = this.painelVivo();
      this.ligarPainelVivo(el);
    }
    if (!quizNovo.sessao) {
      clearInterval(this.relogioQuiz);
      this.relogioQuiz = null;
    }
  },

  /**
   * As duas áreas de coleta da pergunta do ano: Situação atual e Tendências.
   * "Usar" abre o modal do item com o texto da voz — o condutor redige e
   * salva, e o vínculo vai junto. Aceitar é ato de quem conduz (decisão do
   * encontro): a voz da sala é matéria-prima, não redação final.
   */
  painelVivo() {
    const p = this.perguntaDoAno();
    if (!p) return '';
    const sugestoes = this.quiz?.sugestoes || [];
    const recolhido = this.quizUi?.painelRecolhido;
    const coluna = (tipo, rotulo, classe) => {
      const fichas = sugestoes.filter((s) => s.tipo_resposta === tipo);
      // O contador é das ABERTAS: a voz já usada saiu da grade, e contá-la aqui
      // faria o número prometer trabalho que não existe mais
      const abertas = fichas.filter((s) => !Number(s.vinculada)).length;
      return `<div class="col-md-6"><div class="coluna-quiz ${classe}">
        <div class="fw-bold small text-uppercase mb-2">${rotulo}
          <span class="badge rounded-pill text-bg-secondary">${abertas}</span></div>
        ${QuizSala.fichas(fichas, { virou: 'item de cenário' })}
      </div></div>`;
    };
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      ${QuizSala.cabecalhoPainel(this, p, sugestoes)}
      ${recolhido ? '' : `<div class="row g-2 mt-1">
        ${coluna('SITUACAO_ATUAL', 'Situação atual', 'coluna-escolha')}
        ${coluna('TENDENCIA', 'Tendências', 'coluna-renuncia')}
      </div>`}
    </div></div>`;
  },

  ligarPainelVivo(el) {
    QuizSala.ligarVozes(this, el);
    el.querySelectorAll('[data-reabrir-foco]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/quiz/pergunta/${b.dataset.reabrirFoco}/ativar`, {
          planejamento_id: this.plan.id,
        });
      } catch (e) {
        alert(e.message);
      }
      // Aberta para a sala, a pergunta vira a ativa: o foco volta ao padrão
      this.perguntaFoco = null;
      App.recarregarSecaoAtiva();
    }));
    QuizSala.ligarRecolher(this, el);
    el.querySelectorAll('[data-usar-sugestao]').forEach((b) => b.addEventListener('click', () => {
      const s = (this.quiz?.sugestoes || []).find((x) => x.id == b.dataset.usarSugestao);
      if (!s || !this.modalItem) return;
      this.modalItem(null, s.tipo_resposta, s);
    }));
    el.querySelectorAll('[data-excluir-sugestao]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta sugestão? Ela some para a sala também.')) return;
      try {
        await App.api(`/api/quiz/sugestao/${b.dataset.excluirSugestao}/excluir`, {
          planejamento_id: this.plan.id,
        });
        this.quiz = await QuizSala.estado(this.plan.id, this.perguntaFoco);
        this.assinaturaQuiz = null;
        await this.aoBater(this.quiz);
      } catch (e) {
        alert(e.message);
      }
    }));
  },

};

/**
 * Fábrica das telas de etapa. Cada uma nasce com estado PRÓPRIO da sala: as
 * três seções coexistem no DOM (navegar só põe `d-none`), e um estado
 * compartilhado faria o polling de uma repintar a outra.
 */
function secaoEtapa({ idSecao, etapa, titulo, categorias }) {
  return {
    plan: null,
    quiz: null,
    relogioQuiz: null,
    assinaturaQuiz: null,
    quizUi: {},
    secaoId: idSecao,
    perguntaFoco: null,
    etapa,

    carregar() {
      return Diag.etapaFatores({
        idSecao, etapa, titulo, descricao: '', comPromocao: true, categorias, dono: this,
      });
    },

    /** Navegar pelo roteiro leva ESTA tela ao ano da pergunta examinada. */
    aoNavegar(pergunta) {
      if (pergunta.alvo_tipo === 'FATOR') Diag.anoSelecionado = Number(pergunta.ano);
    },

    aoBater(quizNovo) {
      // A batida pode chegar DEPOIS de o condutor ter navegado: o relógio só
      // confere `d-none` no começo, e `recarregarSecaoAtiva` recarrega a seção
      // de AGORA. Sem esta saída, abrir Projetos no meio de um voo do PESTEL
      // recarregava Projetos duas vezes e apagava o estado só-de-DOM dela
      // (acordeões, "ver mais", rolagem).
      if (App.secaoAtiva !== this.secaoId.replace('secao-', '')) return;
      const assinatura = QuizSala.assinatura(quizNovo);
      if (assinatura === this.assinaturaQuiz) return;
      this.assinaturaQuiz = assinatura;
      // A contagem de vozes por fator vem da listagem, não do estado da sala:
      // recarregar a seção inteira é o único jeito de o selo "Sala · N" andar
      // junto — e é o que já acontece quando o condutor aceita uma sugestão.
      App.recarregarSecaoAtiva();
    },
  };
}

const SecaoPestel = secaoEtapa({
  idSecao: 'secao-pestel', etapa: 'PESTEL', titulo: 'PESTEL',
  categorias: Diag.CATEGORIAS_ETAPA.PESTEL,
});

const SecaoPorter = secaoEtapa({
  idSecao: 'secao-porter', etapa: 'PORTER', titulo: 'Porter — 5 Forças',
  categorias: Diag.CATEGORIAS_ETAPA.PORTER,
});

const SecaoSwot = {
  plan: null,
  quiz: null,
  relogioQuiz: null,
  assinaturaQuiz: null,
  quizUi: {},
  secaoId: 'secao-swot',
  perguntaFoco: null,

  aoNavegar(pergunta) {
    if (pergunta.alvo_tipo === 'FATOR') Diag.anoSelecionado = Number(pergunta.ano);
  },

  aoBater(quizNovo) {
    // Ver o comentário em secaoEtapa.aoBater: a batida pode chegar depois de o
    // condutor ter navegado, e recarregaria a seção errada
    if (App.secaoAtiva !== 'swot') return;
    const assinatura = QuizSala.assinatura(quizNovo);
    if (assinatura === this.assinaturaQuiz) return;
    this.assinaturaQuiz = assinatura;
    App.recarregarSecaoAtiva();
  },

  async carregar() {
    const vindo = QuizSala.consumirFoco('swot');
    if (vindo) {
      this.perguntaFoco = vindo.perguntaId;
      this.aoNavegar(vindo.pergunta);
    }
    const base = await Diag.preparar('secao-swot');
    if (!base) return;
    const { el, plan, ano } = base;
    this.plan = plan;
    const [fatores, quiz] = await Promise.all([
      App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT&ano=${ano}`),
      QuizSala.estado(plan.id, this.perguntaFoco),
    ]);
    this.quiz = quiz;

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
        // Caminho do fator até o plano de ação, nos mesmos três estados da
        // ideia da Coleta: fora da fila, na fila e já convertido em ação.
        const acao = Diag.seloPlanoAcao(f);
        return `<div class="card mb-2" data-card-fator="${f.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          <div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
            ${Diag.selosOrigem(f)}${origem}${gut}${acao}
            ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
              <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}" title="Excluir" aria-label="Excluir">×</button>
            </span>` : ''}
          </div>
        </div></div>`;
      }).join('');
      return `<div class="col-md-6" data-coluna-categoria="${cat}">
        <div class="p-2 rounded caixa-coluna"
          style="--tinta-coluna:${cor}18; border-top: 3px solid ${cor}">
          <div class="cabecalho-coluna d-flex align-items-center mb-2">
            ${Diag.iconeOrientacao(cat, cor, rotulo)}
            <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
              <span class="ambiente-quadrante">(${Diag.DICAS_QUADRANTE[cat]})</span>
              ${Diag.contadorCards(itens.length, cor)}</span>
            ${Diag.botaoAddCategoria(cat, rotulo, cor)}
            ${Diag.quizMic(this, 'SWOT', ano, cat, rotulo, cor)}
          </div>
          <div class="corpo-coluna">
            ${Diag.painelOrientacao(cat, cor)}
            ${cartoes || '<div class="text-muted small">Nenhum fator.</div>'}
          </div>
        </div>
      </div>`;
    };

    const contagensSwot = Object.fromEntries(Object.keys(Diag.QUADRANTES)
      .map((cat) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    el.innerHTML = `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h1>SWOT — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.seletorAno('swot')}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-swot">+ Novo fator</button>' : ''}
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap"
          data-selo-quiz>${QuizSala.selo(this, 'swot', Diag.salaNestaEtapa(this, 'SWOT', ano))}</div>
      </div>
      <div data-quiz-vivo>${Diag.quizPainel(this, 'SWOT', ano)}</div>
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

    Diag.ligarCabecalhoFixo(el);
    RelatorioAnalise.ligar(el, () => ({
      titulo: `SWOT — ${App.rotuloContexto()} · ${ano}`,
      contexto: `Diagnóstico · análise de ${ano} · ${fatores.length} fator(es)`,
      // No plural, como as colunas: `Diag.QUADRANTES` guarda o singular do selo
      secoes: [['FORCA', 'Forças'], ['FRAQUEZA', 'Fraquezas'],
        ['OPORTUNIDADE', 'Oportunidades'], ['AMEACA', 'Ameaças']].map(([cat, rotulo]) => ({
        rotulo,
        cor: Diag.CORES_QUADRANTE[cat],
        dica: Diag.DICAS_QUADRANTE[cat],
        itens: fatores.filter((f) => f.categoria === cat)
          .map((f) => ({ texto: f.descricao, notas: Diag.notasFator(f) })),
      })),
    }));
    Diag.ligarSeletorAno(el);
    Diag.ligarSeletorCategoriaMovel(el, 'SWOT');
    Diag.ligarVerMais(el);
    Diag.aplicarDestaque(el, 'swot');
    Diag.ligarSeloColeta(el, 'SWOT');
    Diag.ligarOrientacoes(el);
    this.assinaturaQuiz = QuizSala.assinatura(this.quiz);
    QuizSala.armarRelogio(this);

    el.querySelectorAll('[data-ir-origem]').forEach((b) => b.addEventListener('click', () => {
      const etapa = b.dataset.etapaOrigem;
      Diag.irParaFator(etapa.toLowerCase(), b.dataset.irOrigem, etapa, b.dataset.catOrigem);
    }));
    el.querySelectorAll('[data-ir-gut]').forEach((b) => b.addEventListener('click', () =>
      Diag.irParaFator('gut', b.dataset.irGut)));
    // O selo "Virou ação" existe também para quem só lê, e por isso este
    // listener fica ANTES da saída por podeEditar()
    el.querySelectorAll('[data-ir-acao]').forEach((b) => b.addEventListener('click', () => {
      SecaoProjetos.destacarAcao = b.dataset.irAcao;
      App.mostrarSecao('projetos');
    }));

    if (!App.podeEditar()) {
      QuizSala.ligarSelo(el);
      return;
    }
    const encaminharAcao = async (id, marcar) => {
      try {
        await App.api(`/api/fatores/${id}/plano-acao`, { planejamento_id: plan.id, marcar });
        App.recarregarSecaoAtiva();
      } catch (e) {
        alert(e.message);
      }
    };
    el.querySelectorAll('[data-plano-acao]').forEach((b) => b.addEventListener('click', () =>
      encaminharAcao(b.dataset.planoAcao, true)));
    el.querySelectorAll('[data-tirar-acao]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Tirar este fator da fila do plano de ação?')) return;
      encaminharAcao(b.dataset.tirarAcao, false);
    }));
    const modalFator = (f = null, categoria = null, sugestao = null) => Modal.abrir({
      titulo: sugestao ? `Aceitar sugestão da sala · ${ano}`
        : f ? `Editar fator da SWOT (${f.ano || ano})` : `Novo fator da SWOT · ${ano}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f
        ? { ...f, planejamento_id: plan.id }
        : {
            planejamento_id: plan.id, etapa: 'SWOT', ano,
            ...(categoria ? { categoria } : {}),
            ...(sugestao ? { descricao: sugestao.texto } : {}),
          },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: 'SWOT' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        ...(sugestao ? [{ nome: 'origem_sala', rotulo: '', tipo: 'info',
          texto: `${sugestao.autor}: “${sugestao.texto}”`,
          barra: { cor: '#007a45', titulo: 'Voz da sala' } }] : []),
        Diag.campoQuadrante('categoria', 'Quadrante'),
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
      ...(sugestao ? { transformar: (dd) => ({ ...dd, sugestoes: [sugestao.id] }) } : {}),
    });
    Diag.quizLigarEtapa(this, el, 'SWOT', ano, modalFator);
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

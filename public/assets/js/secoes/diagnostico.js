// Seções de diagnóstico: Análise de Cenário, PESTEL, Porter, SWOT e Matriz GUT.
// As análises são ANUAIS (refeitas a cada ano do ciclo); os horizontes e a
// cascata seguem plurianuais.

const Diag = {
  // Ano da análise selecionado (compartilhado pelas 5 seções do diagnóstico)
  anoSelecionado: null,

  cicloAtual() {
    return App.sessao.ciclos.find((c) => c.id === App.contexto.cicloId);
  },

  // Ano vigente da análise, sempre dentro de [ano_base, ano_fim] do ciclo.
  // Enquanto o usuário não escolhe um ano, vale o PRIMEIRO ANO DE PLANEJAMENTO
  // do ciclo (ano_inicio, 2027 no ciclo 2027–2035) — pedido do cliente em
  // 2026-09-03: ao logar, o sistema já abre em 2027, e não no ano do relógio
  // (o ano_base, que é o de diagnóstico, e não o planejado).
  ano() {
    const c = this.cicloAtual();
    if (!c) return new Date().getFullYear();
    const padrao = c.ano_inicio != null ? Number(c.ano_inicio) : new Date().getFullYear();
    const a = this.anoSelecionado ?? padrao;
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

  /**
   * Pesquisa dentro da análise — achar o fator pelo que ele DIZ.
   *
   * O estado é por ETAPA (como o `filtroMovel`), e não global: sair da SWOT
   * para o PESTEL não pode levar junto o termo digitado na outra, ou a análise
   * vizinha abre com metade dos cartões escondidos e nenhuma explicação à vista.
   *
   * Filtra a TELA, não o dado: o relatório continua saindo com a análise
   * inteira, porque ele é o documento da análise e não a vista de quem procura.
   */
  busca: {},

  /** Minúsculas sem acento — "logistica" tem de achar "logística". */
  norm(s) {
    return String(s || '')
      .toLocaleLowerCase('pt-BR')
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '');
  },

  campoBusca(chave) {
    const v = this.busca[chave] || '';
    return `<div class="campo-busca-analise d-print-none">
      <input type="search" class="form-control form-control-sm" data-busca-analise
        value="${Modal.esc(v)}" placeholder="Pesquisar nesta análise…"
        aria-label="Pesquisar um fator desta análise" autocomplete="off">
      <span class="small text-muted text-nowrap" data-busca-resultado aria-live="polite"></span>
    </div>`;
  },

  /**
   * O texto que a busca varre num item, por ordem de precisão:
   *
   * 1. `[data-busca-texto]` — a marca explícita. É o caso da LINHA da Matriz
   *    GUT: varrer a linha inteira faria a busca por "5" casar com nota,
   *    score e posição no ranking;
   * 2. `.texto-fator` e `.selo-cruz-texto` — a descrição dos cartões. Nos
   *    Cruzamentos são os DOIS lados do par mais a estratégia: quem procura
   *    "dólar" quer o cruzamento que cita dólar em qualquer um dos três;
   * 3. o texto do item, quando nada acima existe.
   *
   * Nunca o item inteiro quando há marcação: os selos (GUT 64, "Virou ação",
   * "Coleta") entrariam na varredura, e buscar "gut" casaria com todo cartão
   * que tem score — resultado plausível e errado, o pior tipo.
   */
  textoBusca(item) {
    const partes = item.querySelectorAll('[data-busca-texto], .texto-fator, .selo-cruz-texto');
    return partes.length
      ? [...partes].map((n) => n.textContent).join(' ')
      : (item.textContent || '');
  },

  /**
   * Liga a pesquisa. Três cuidados que não são detalhe:
   *
   * - o texto de cada cartão é lido UMA vez, na ligação. Reler o DOM a cada
   *   tecla passaria a varrer também os selos e o "ver mais", e a busca por
   *   "gut" casaria com todo cartão que tem score — resultado plausível e
   *   errado, que é o pior tipo;
   * - o `d-none` daqui vai no CARTÃO; o do filtro de categoria do celular vai na
   *   COLUNA. São elementos diferentes de propósito: no mesmo, um desfaria o
   *   outro a cada troca;
   * - `ligarVerMais` roda de novo no fim. `scrollHeight` de elemento escondido é
   *   zero, então cartão que reaparece precisa ser medido outra vez — senão o
   *   texto longo volta cortado e sem o botão de expandir.
   */
  ligarBusca(el, chave, { itens = '[data-card-fator]', aposFiltrar = null } = {}) {
    const campo = el.querySelector('[data-busca-analise]');
    if (!campo) return;
    const aviso = el.querySelector('[data-busca-resultado]');
    const alvos = [...el.querySelectorAll(itens)].map((item) => ({
      item,
      // A CHAVE é o id do registro, não o nó: a Matriz GUT desenha a mesma
      // avaliação duas vezes — tabela no computador, cartões no celular, com o
      // mesmo `data-card-fator`. Contando nós, ela diria "12 de 48" onde há 24
      // fatores, e o número do topo é justamente o que se olha para confiar.
      chaveItem: item.dataset.cardFator || item.dataset.cardCruzamento || item,
      texto: this.norm(this.textoBusca(item)),
    }));
    const totalItens = new Set(alvos.map((a) => a.chaveItem)).size;

    const aplicar = () => {
      const termo = campo.value.trim();
      const q = this.norm(termo);
      this.busca[chave] = campo.value;

      const casados = new Set();
      alvos.forEach((a) => {
        const casa = q === '' || a.texto.includes(q);
        a.item.classList.toggle('d-none', !casa);
        if (casa) casados.add(a.chaveItem);
      });
      const achados = casados.size;

      el.querySelectorAll('[data-coluna-categoria]').forEach((col) => {
        const todos = col.querySelectorAll(itens).length;
        const visiveis = col.querySelectorAll(`${itens}:not(.d-none)`).length;
        // O contador passa a dizer o que a coluna MOSTRA sobre o que ela TEM:
        // só o número dos visíveis faria parecer que fatores sumiram do plano
        const contador = col.querySelector('.contador-cards');
        if (contador) {
          contador.textContent = q === '' ? todos : `${visiveis}/${todos}`;
          contador.title = q === ''
            ? `${todos} card(s) nesta categoria`
            : `${visiveis} de ${todos} card(s) desta categoria casam com a pesquisa`;
        }
        const vazio = col.querySelector('[data-busca-vazio]');
        if (vazio) vazio.classList.toggle('d-none', !(q !== '' && todos > 0 && visiveis === 0));
      });

      if (aviso) {
        aviso.textContent = q === ''
          ? ''
          : achados === 0
            ? 'nada encontrado'
            : `${achados} de ${totalItens}`;
      }
      // Aviso de tela inteira vazia — para quem não tem colunas (a GUT)
      const vazioGeral = el.querySelector('[data-busca-vazio-geral]');
      if (vazioGeral) vazioGeral.classList.toggle('d-none', !(q !== '' && achados === 0));
      this.ligarVerMais(el);
      // Gancho para quem tem "ver mais" PRÓPRIO — os Cruzamentos expandem o
      // cartão inteiro por um botão só, e o helper genérico não o alcança
      if (aposFiltrar) aposFiltrar();
    };

    campo.addEventListener('input', aplicar);
    // Esc limpa sem tirar a mão do teclado. O `type="search"` já traz o ✕ do
    // navegador, mas ele não existe em todos e não é alcançável por teclado.
    campo.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Escape' || campo.value === '') return;
      ev.stopPropagation(); // senão o Esc sobe e fecha algo atrás
      campo.value = '';
      aplicar();
    });
    aplicar();
  },

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

  ligarCabecalhoFixo(el, seletor = '[data-cabecalho-analise]') {
    // Mede o que GRUDA — no papel o cabeçalho vira `<thead>`, e na tela é ele
    // quem carrega o recuo e a sombra; medir só o bloco de dentro deixaria o
    // cabeçalho da coluna dez pixels alto demais, por cima do da análise.
    // O seletor é parâmetro porque a tela de Projetos usa o mesmo mecanismo com
    // outro bloco: quem gruda embaixo precisa saber a altura de quem está em
    // cima, e essa altura só se sabe medindo.
    const bloco = el.querySelector(seletor);
    const cab = bloco?.closest('thead') || bloco;
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

  // O que considerar em cada tópico. O ícone ⓘ no título abre e fecha esta
  // orientação; só aparece onde há texto definido.
  //
  // O catálogo vem do SERVIDOR (`App\Services\Quiz::ORIENTACAO_CATEGORIA`, em
  // `/api/me`): o mesmo texto desce ao celular junto com a pergunta da sala, e
  // duas cópias divergiriam na primeira revisão — deixando quem responde
  // orientado por uma coisa e quem conduz por outra.
  get ORIENTACOES_CATEGORIA() {
    return App.sessao?.orientacoes || {};
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
    // Nem por uma pesquisa deixada em outra análise. Quem foi MANDADO a um card
    // específico tem de vê-lo; chegar numa tela que só diz "nenhum fator
    // encontrado" pareceria registro apagado. Limpa todas porque `chaveFiltro` é
    // opcional, e o custo é só um termo digitado que ninguém está mais olhando.
    this.busca = {};
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
   * Liga os três estados do selo do plano de ação, numa tela qualquer do
   * diagnóstico.
   *
   * Vive aqui, e não em cada seção, porque PESTEL, Porter, SWOT e a Análise de
   * Cenário passaram a usar o MESMO caminho: com o gesto duplicado em quatro
   * telas, o "tirar da fila" ganharia confirmação numa e não nas outras na
   * primeira revisão.
   *
   * `recurso` é o único parâmetro que muda entre elas — `fatores` ou `cenario`
   * —, porque as tabelas são diferentes e cada uma tem a sua rota. O SELO é
   * idêntico nas quatro, e é isso que faz o gesto ser reconhecível: quem
   * aprendeu a encaminhar no PESTEL já sabe encaminhar no cenário.
   *
   * O "Virou ação ↗" é ligado ANTES da saída por `podeEditar`: o caminho de
   * volta até a ação é leitura, não edição — quem só acompanha o plano também
   * precisa dele.
   */
  /**
   * Liga o "⇄ Mover" numa tela de fatores. Vive aqui pelo mesmo motivo do selo
   * do plano de ação: PESTEL, Porter e SWOT usam o gesto idêntico, e duplicá-lo
   * faria as três divergirem no primeiro ajuste.
   */
  ligarMoverFator(el, fatores, planId) {
    if (!App.podeEditar()) return;
    el.querySelectorAll('[data-mover]').forEach((b) => b.addEventListener('click', () => {
      const f = fatores.find((x) => x.id == b.dataset.mover);
      if (f) this.modalMoverFator(f, planId);
    }));
  },

  ligarPlanoAcao(el, planId, recurso = 'fatores', substantivo = 'fator') {
    el.querySelectorAll('[data-ir-acao]').forEach((b) => b.addEventListener('click', () => {
      SecaoProjetos.destacarAcao = b.dataset.irAcao;
      App.mostrarSecao('projetos');
    }));
    if (!App.podeEditar()) return;
    const encaminhar = async (id, marcar) => {
      try {
        await App.api(`/api/${recurso}/${id}/plano-acao`, { planejamento_id: planId, marcar });
        App.recarregarSecaoAtiva();
      } catch (e) {
        alert(e.message);
      }
    };
    el.querySelectorAll('[data-plano-acao]').forEach((b) => b.addEventListener('click', () =>
      encaminhar(b.dataset.planoAcao, true)));
    el.querySelectorAll('[data-tirar-acao]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm(`Tirar este ${substantivo} da fila do plano de ação?`)) return;
      encaminhar(b.dataset.tirarAcao, false);
    }));
  },

  /**
   * Estado de um item do diagnóstico em relação ao plano de ação, nos mesmos
   * três estados da ideia da Coleta: fora da fila (botão que encaminha), na
   * fila (selo que desfaz) e já convertido (selo que leva à ação em Projetos).
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

  /**
   * O × do fator — desabilitado quando o servidor VAI recusar a exclusão.
   *
   * `acao_trava` chega da MESMA consulta com que o servidor recusa
   * (`Fatores::acoesQuePrendem`), e não de uma regra remontada aqui: a trava
   * nasce de três caminhos (o fator virou ação, um promovido dele virou, ou um
   * cruzamento que o cita virou) e reescrevê-la na tela erraria justamente nos
   * dois últimos, que são os mais comuns.
   *
   * Sem `data-excluir` quando travado: o botão não fica só cinzento, ele não
   * tem ação nenhuma pendurada. E o `title` diz o que FAZER — apagar a ação em
   * Projetos —, não apenas que não pode.
   */
  // Como cada análise se chama na tela. 'PORTER' é sobrenome, não sigla.
  ROTULO_ETAPA: { PESTEL: 'PESTEL', PORTER: 'Porter', SWOT: 'SWOT' },

  /**
   * O botão "⇄" que muda o fator de análise — desabilitado, com o motivo, onde
   * o servidor VAI recusar.
   *
   * `mover_trava` chega da MESMA consulta com que `FatorController::mover`
   * recusa (`travasDeMover`), somada à trava da ação (`acao_trava`), que é
   * compartilhada com a exclusão. Remontar qualquer uma das duas aqui erraria
   * nos casos difíceis — a promoção, o cruzamento — que são justamente os
   * comuns.
   *
   * Os motivos entram TODOS no `title`, um por linha: um fator promovido e
   * citado num cruzamento tem duas coisas a desfazer, e mostrar só a primeira
   * faria a segunda parecer um erro novo depois de o usuário já ter trabalhado.
   */
  botaoMoverFator(f) {
    const motivos = [...(f.mover_trava || [])];
    if (f.acao_trava) {
      motivos.unshift(`Já virou a ação “${f.acao_trava}” no plano. `
        + 'Exclua a ação em Projetos antes de mover este fator.');
    }
    if (motivos.length) {
      return `<button class="btn btn-sm btn-outline-secondary" ${Vinculos.travado(motivos.join('\n'))}
        aria-label="Mover de análise (bloqueado)">⇄</button>`;
    }
    return `<button class="btn btn-sm btn-outline-secondary" data-mover="${f.id}"
      title="Mover para outra análise" aria-label="Mover para outra análise">⇄</button>`;
  },

  /**
   * Modal do "⇄": para onde vai, e em que categoria lá.
   *
   * A categoria é perguntada SEMPRE, e o campo se repinta quando a análise de
   * destino muda: as listas não se correspondem (`LEGAL` não existe no Porter,
   * `RIVALIDADE` não existe na SWOT), e um valor herdado do PESTEL viraria uma
   * categoria que a tela de destino não sabe desenhar — o fator sumiria das
   * duas. O servidor recusa do mesmo jeito; aqui é o campo que impede.
   *
   * A SWOT entra como destino de pleno direito, ao lado da promoção: promover
   * COPIA (a origem fica no PESTEL, com o par visível nas duas telas), mover
   * TRANSFERE. São gestos diferentes e ambos legítimos — o que não pode é
   * fazer os dois no mesmo fator, e é por isso que a promoção trava o ⇄.
   *
   * A **Análise de Cenário** é o quarto destino, e para quem usa a tela é só
   * mais um botão na mesma fileira. Por baixo é outra coisa: lá não existe
   * categoria — existe TIPO (situação atual ou tendência) —, e o registro muda
   * de tabela, o que faz o id morrer. O formulário esconde essa diferença de
   * propósito: quem move um item não deveria precisar saber em quantas tabelas
   * o sistema guarda diagnóstico.
   */
  modalMoverFator(f, planId) {
    const destinos = [...Object.keys(this.ROTULO_ETAPA).filter((e) => e !== f.etapa), 'CENARIO'];
    const primeiro = destinos[0];
    const rotulo = (e) => this.ROTULO_ETAPA[e] || 'Análise de Cenário';
    const campoDe = (etapa) => {
      if (etapa === 'CENARIO') {
        return { nome: 'tipo', rotulo: 'Entra como', tipo: 'botoes',
          opcoes: Object.entries(SecaoCenario.TIPOS).map(([valor, t]) => ({ valor, rotulo: t.rotulo })),
          ajuda: 'A Análise de Cenário não tem categorias: tem situação atual e tendência.' };
      }
      return etapa === 'SWOT'
        ? { nome: 'categoria', rotulo: 'Quadrante na SWOT', tipo: 'quadrantes',
            opcoes: Object.entries(this.QUADRANTES).map(([valor, rot]) => ({
              valor, rotulo: rot, cor: this.CORES_QUADRANTE[valor], dica: this.DICAS_QUADRANTE[valor],
            })) }
        : this.campoCategoria(etapa, 'categoria', `Categoria no ${this.ROTULO_ETAPA[etapa]}`);
    };
    Modal.abrir({
      titulo: 'Mover para outra análise',
      url: `/api/fatores/${f.id}/mover`,
      // O cadeado do tema 11 vale aqui como no ✎: mover DESTRÓI a linha quando
      // o destino é o Cenário, e fazer isso debaixo de quem está editando o
      // mesmo item seria a pior versão da colisão que o cadeado existe para
      // evitar — a pessoa perderia o texto sem nem ver o item sumir.
      bloqueio: { recurso: 'fator', registro_id: f.id, planejamento_id: planId },
      valores: { planejamento_id: planId, etapa: primeiro, categoria: '' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'fator', rotulo: `Fator do ${this.ROTULO_ETAPA[f.etapa] || f.etapa}`, tipo: 'info',
          texto: f.descricao,
          barra: { cor: SecaoProjetos.corCategoria(f.etapa, f.categoria),
            titulo: SecaoProjetos.rotuloCategoria(f.etapa, f.categoria) } },
        { nome: 'etapa', rotulo: 'Para qual análise?', tipo: 'botoes',
          opcoes: destinos.map((e) => ({ valor: e, rotulo: rotulo(e) })),
          ajuda: 'O texto e as vozes da sala vão junto; só a análise e a categoria mudam.' },
        // Um campo por destino, revelado pelo `visivelSe`: o formulário não
        // remonta opções no meio do preenchimento, e o valor de cada lista fica
        // guardado no campo dela. Com UM campo repintado, trocar de destino e
        // voltar perdia a escolha já feita.
        ...destinos.map((e) => ({ ...campoDe(e), nome: `categoria_${e}`,
          visivelSe: { campo: 'etapa', valores: [e] } })),
      ],
      // O servidor recebe UMA categoria, a do destino escolhido: mandar as três
      // deixaria a validação passar pela lista errada. E para o Cenário o campo
      // escolhido não é categoria nenhuma — vai como `tipo`.
      transformar: (d) => ({
        planejamento_id: d.planejamento_id,
        etapa: d.etapa,
        ...(d.etapa === 'CENARIO'
          ? { tipo: d.categoria_CENARIO || '' }
          : { categoria: d[`categoria_${d.etapa}`] || '' }),
      }),
      // Indo para o Cenário o item MUDA DE TELA, e some desta. Levar quem
      // moveu até lá, com o cartão destacado, é o que fecha o gesto: só
      // recarregar a análise de origem deixaria o cartão sumindo sem nada
      // dizendo para onde foi — que é indistinguível de exclusão.
      //
      // Entre análises não há para onde ir: `mover` mantém o id e a tela de
      // origem já se recarrega sozinha, com o cartão fora dela.
      aoSalvar: (r) => {
        if (r?.destino === 'CENARIO' && r.id) {
          Diag.irParaFator('cenario', r.id);
          return;
        }
        App.recarregarSecaoAtiva();
      },
    });
  },

  botaoExcluirFator(f) {
    if (f.acao_trava) {
      return `<button class="btn btn-sm btn-outline-danger" ${Vinculos.travado(
        `Já virou a ação “${f.acao_trava}” no plano. Exclua a ação em Projetos `
        + 'antes de excluir este fator.')} aria-label="Excluir (bloqueado: virou ação)">×</button>`;
    }
    return `<button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}"
      title="Excluir" aria-label="Excluir">×</button>`;
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
    // O caminho para o plano vale em TODA análise, não só na SWOT: o mesmo
    // selo de três estados (encaminhar · aguardando · virou ação) que a SWOT
    // usa. Ele vem depois do botão da promoção porque os dois convivem — a
    // promoção continua sendo o caminho de quem quer a síntese antes de agir.
    return `<div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
      ${selos}${swot}${this.seloPlanoAcao(f)}
      <span class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
        ${this.botaoMoverFator(f)}
        ${this.botaoExcluirFator(f)}
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
   * O 🎤 da ETAPA INTEIRA, no cabeçalho da análise. A pergunta nasce sem
   * categoria e é o CELULAR que escolhe em qual quadrante a resposta entra —
   * lendo ali a orientação do ⓘ daquela categoria. Pedido do cliente
   * (2026-09-03): numa oficina a pessoa tem uma ideia e sabe onde ela cabe;
   * esperar o condutor abrir coluna por coluna perdia a ideia. O alvo vazio
   * (`alvos: ['']`) é o que o servidor lê como "toda a etapa"
   * (`Quiz::validarAlvos`). O 🎤 de cada coluna continua existindo, para a
   * condução que prefere perguntar um quadrante por vez.
   */
  quizMicEtapa(dono, etapa, ano, titulo) {
    const ativa = dono.quiz?.pergunta;
    const naSala = !!ativa && ativa.alvo_tipo === 'FATOR' && ativa.etapa === etapa
      && Number(ativa.ano) === Number(ano) && !ativa.categoria;
    return `<span data-mic-etapa="${etapa}">${QuizSala.microfone(
      { alvo_tipo: 'FATOR', etapa, ano, alvos: [''] },
      `a análise inteira (${titulo}; a sala escolhe a categoria)`,
      { ativo: naSala, pergunta: naSala ? ativa.id : null })}</span>`;
  },

  /**
   * As categorias de uma etapa como o PAINEL da sala as desenha: valor, rótulo
   * da coluna e cor. Vêm do catálogo servido (`App.sessao.categorias`, o mesmo
   * que o celular usa), para que o painel do condutor e a tela do participante
   * falem da mesma coisa com as mesmas palavras; sem ele, o do `Diag`.
   */
  categoriasDaEtapa(etapa) {
    const servidas = App.sessao?.categorias?.[etapa];
    if (servidas) return Object.entries(servidas).map(([v, c]) => [v, c.rotulo, c.cor]);
    if (etapa === 'SWOT') {
      return Object.entries(this.QUADRANTES).map(([v, r]) => [v, r, this.CORES_QUADRANTE[v]]);
    }
    return (this.CATEGORIAS_ETAPA[etapa] || []).map(([v, r, cor]) => [v, r, cor]);
  },

  /**
   * As sugestões da pergunta em foco, numa grade só, em ordem de chegada.
   *
   * Com o 🎤 de uma coluna, o alvo não tem lado — a categoria JÁ é a pergunta.
   * Com o 🎤 da etapa inteira, a categoria escolhida no celular viaja em
   * `tipo_resposta` e vira uma ETIQUETA na ficha, na cor da coluna da análise.
   * Já foi uma coluna por categoria: com cinco ou seis colunas quase sempre
   * vazias, o painel gastava a faixa fixa inteira dizendo "nenhuma sugestão"
   * (pedido do cliente, 2026-09-03, com a foto) — a etiqueta diz o quadrante
   * sem reservar lugar para ele. "Usar" leva o texto ao formulário do fator,
   * já com a categoria marcada: aceitar é ato de quem conduz, e o texto (e o
   * quadrante) final é o dele.
   */
  quizPainel(dono, etapa, ano) {
    const p = this.quizFoco(dono, etapa, ano);
    if (!p) return '';
    const sugestoes = dono.quiz?.sugestoes || [];
    const recolhido = dono.quizUi?.painelRecolhido;
    const podeUnir = App.podeEditar() && p.situacao !== 'ATIVA';
    const categorias = new Map(this.categoriasDaEtapa(etapa).map(([cat, rotulo, cor]) => [cat, { rotulo, cor }]));
    const selo = p.categoria ? null : (s) => {
      const c = categorias.get(s.tipo_resposta);
      return c ? `<span class="selo-categoria-voz" style="--cor-cat:${c.cor}"
        title="Quadrante escolhido no celular">${Modal.esc(c.rotulo)}</span>` : '';
    };
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      ${QuizSala.cabecalhoPainel(dono, p, sugestoes)}
      ${recolhido ? '' : `<div class="coluna-quiz coluna-escolha mt-2">
        ${QuizSala.fichas(sugestoes, { virou: 'fator', podeUnir, selo })}
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
    QuizSala.ligarUniao(dono, el);
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
      // O cartão pode reunir várias vozes: leva o texto de todas e amarra todas
      const sg = QuizSala.grupoDe(dono.quiz?.sugestoes, b.dataset.usarSugestao);
      const p = this.quizFoco(dono, etapa, ano);
      if (!sg || !p) return;
      // Pergunta da etapa inteira: a categoria é a que a pessoa escolheu no
      // celular — marcada no formulário, e o condutor troca se discordar.
      modalFator(null, p.categoria || sg.tipo_resposta || null, sg);
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
        <div class="card mb-2" data-card-fator="${f.id}" data-cadeado="fator:${f.id}"><div class="card-body py-2 px-2">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          ${this.botoesFator(f, plan.id, comPromocao, this.selosOrigem(f))}
          ${comPromocao && App.podeEditar() ? this.painelQuadrantes(f) : ''}
        </div></div>`).join('');
      // A largura vem do `row-cols-*` da FILA, não de um `col-*` fixo: com
      // `col-xl-2` (um sexto) o Porter, que tem cinco categorias, deixava um
      // sexto da tela vazio à direita e espremia os cartões à toa. Agora cada
      // análise divide a largura pelo número de categorias que ela tem.
      // O cabeçalho e o corpo vão numa `RelatorioAnalise.bloco`: no papel ela é
      // a tabela que repete o título da categoria quando os cartões atravessam
      // a quebra de página; na tela ela não existe (`display: contents`).
      return `<div class="coluna-categoria" data-coluna-categoria="${cat}">
        <div class="caixa-coluna">
          ${RelatorioAnalise.bloco({
            cabecalho: `<div class="cabecalho-coluna d-flex align-items-center mb-2">
              ${this.iconeOrientacao(cat, cor, rotulo)}
              <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
                ${this.contadorCards(itens.length, cor)}</span>
              ${this.botaoAddCategoria(cat, rotulo, cor)}
              ${this.quizMic(dono, etapa, ano, cat, rotulo, cor)}
            </div>`,
            corpo: `<div class="corpo-coluna">
              ${this.painelOrientacao(cat, cor)}
              ${cartoes || '<div class="text-muted small">—</div>'}
              <div class="text-muted small fst-italic d-none d-print-none" data-busca-vazio>
                Nada nesta categoria com esse termo.</div>
            </div>`,
          })}
        </div>
      </div>`;
    }).join('');

    const contagens = Object.fromEntries(
      categorias.map(([cat]) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    // O canvas é uma TABELA de verdade (`RelatorioAnalise.canvas`): só assim o
    // cabeçalho se repete em toda página impressa.
    el.innerHTML = RelatorioAnalise.canvas({
      cabecalho: `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="mb-0">${titulo} — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
            <div class="d-flex align-items-center gap-2 flex-wrap"
              data-selo-quiz>${QuizSala.selo(dono, idSecao.replace('secao-', ''),
                this.salaNestaEtapa(dono, etapa, ano))}</div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${this.campoBusca(etapa)}
            ${this.seletorAno(etapa)}
            ${this.quizMicEtapa(dono, etapa, ano, titulo)}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? `<button class="btn btn-verde btn-sm" data-novo-fator>+ Novo fator</button>` : ''}
          </div>
        </div>
      </div>
      <div data-quiz-vivo>${this.quizPainel(dono, etapa, ano)}</div>`,
      corpo: `
      ${descricao ? `<p class="text-muted">${descricao} <em>A análise é anual — troque o ano acima para revisar ou consultar edições anteriores.</em></p>` : ''}
      ${this.seletorCategoriaMovel(etapa, categorias.map(([cat, rotulo]) => [cat, rotulo]), contagens)}
      <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-md-3
        row-cols-xl-${categorias.length}">${colunas}</div>`,
    });

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
    // Depois do filtro de categoria e do "ver mais", antes do destaque: a busca
    // recompõe os dois (ela mede o "ver mais" de novo) e o destaque precisa
    // rolar até um card que já esteja na posição final
    this.ligarBusca(el, etapa);
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
      // Cadeado só na EDIÇÃO: item novo não é disputado por ninguém.
      bloqueio: f ? { recurso: 'fator', registro_id: f.id, planejamento_id: plan.id } : null,
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
      ...(sugestao ? { transformar: (dd) => ({ ...dd, sugestoes: sugestao.ids || [sugestao.id] }) } : {}),
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

    // PESTEL e Porter agora vão DIRETO ao plano de ação, sem passar pela
    // promoção à SWOT — o mesmo selo e o mesmo gesto da SWOT.
    this.ligarPlanoAcao(el, plan.id);
    this.ligarMoverFator(el, fatores, plan.id);

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
  /**
   * Os dois tipos do cenário, com rótulo e cor — catálogo único.
   *
   * Nasceu de uma duplicação real: o modal de "aceitar sugestão da sala" já
   * escolhia `'#8f3b3b'`/`'Tendência'` à mão, e a fila do plano de ação em
   * Projetos precisaria da mesma escolha. Duas cópias do mesmo par é como o
   * verde da situação atual vira outro verde na terceira tela.
   *
   * O rótulo aqui é o SINGULAR ("Tendência"): ele serve ao selo de um item.
   * As colunas da seção usam o plural ("Tendências"), que é outra coisa — o
   * título de um grupo — e por isso continua escrito onde a coluna é montada.
   */
  TIPOS: {
    SITUACAO_ATUAL: { rotulo: 'Situação atual', cor: '#007a45' },
    TENDENCIA: { rotulo: 'Tendência', cor: '#8f3b3b' },
  },

  /**
   * O × do item — desabilitado quando o servidor VAI recusar a exclusão.
   *
   * Mesmo padrão do `Diag.botaoExcluirFator`, com uma regra bem mais simples
   * atrás dele: no cenário o vínculo com a ação é DIRETO e único, e por isso
   * `acao_titulo` (que já vem da listagem) basta — não há promovido nem
   * cruzamento a consultar, que é o que obriga o fator a ter consulta própria.
   * Sem `data-excluir` quando travado: o botão não fica só cinzento, ele não
   * tem ação nenhuma pendurada.
   */
  botaoExcluir(i) {
    if (i.desdobramento_id) {
      return `<button class="btn btn-sm btn-outline-danger" ${Vinculos.travado(
        `Já virou a ação “${i.acao_titulo || ''}” no plano. Exclua a ação em Projetos `
        + 'antes de excluir este item.')} aria-label="Excluir (bloqueado: virou ação)">×</button>`;
    }
    return `<button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}"
      title="Excluir" aria-label="Excluir">×</button>`;
  },

  /**
   * O `⇄` do item — o mesmo gesto do fator, na direção contrária.
   *
   * `mover_trava` vem do servidor pela MESMA fonte que a recusa usa, como em
   * toda trava deste sistema: a tela não remonta a regra por conta própria, ela
   * exibe a que o servidor já respondeu. Deste lado a lista tem no máximo um
   * motivo (o item de cenário não tem GUT, cruzamento, Cascata nem Impacto),
   * mas continua sendo lista para o formato ser um só.
   */
  botaoMover(i) {
    const motivos = i.mover_trava || [];
    if (motivos.length) {
      return `<button class="btn btn-sm btn-outline-secondary" ${Vinculos.travado(motivos.join('\n'))}
        aria-label="Mover de análise (bloqueado)">⇄</button>`;
    }
    return `<button class="btn btn-sm btn-outline-secondary" data-mover="${i.id}"
      title="Mover para outra análise" aria-label="Mover para outra análise">⇄</button>`;
  },

  /**
   * Modal do `⇄` daqui: para qual análise, e em que categoria lá.
   *
   * Espelha `Diag.modalMoverFator` de propósito, campo por campo — é o mesmo
   * gesto, e duas telas com formulários diferentes para a mesma coisa fariam
   * quem conduz hesitar no meio da reunião. O que muda é só a direção: aqui
   * não se pergunta o tipo (o item está saindo do Cenário), pergunta-se a
   * categoria no destino.
   */
  modalMover(i, planId) {
    const destinos = Object.keys(Diag.ROTULO_ETAPA);
    const campoDe = (etapa) => (etapa === 'SWOT'
      ? { nome: 'categoria', rotulo: 'Quadrante na SWOT', tipo: 'quadrantes',
          opcoes: Object.entries(Diag.QUADRANTES).map(([valor, rot]) => ({
            valor, rotulo: rot, cor: Diag.CORES_QUADRANTE[valor], dica: Diag.DICAS_QUADRANTE[valor],
          })) }
      : Diag.campoCategoria(etapa, 'categoria', `Categoria no ${Diag.ROTULO_ETAPA[etapa]}`));
    Modal.abrir({
      titulo: 'Mover para outra análise',
      url: `/api/cenario/${i.id}/mover`,
      bloqueio: { recurso: 'cenario_item', registro_id: i.id, planejamento_id: planId },
      valores: { planejamento_id: planId, etapa: destinos[0] },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'item', rotulo: 'Item da Análise de Cenário', tipo: 'info',
          texto: i.descricao,
          barra: { cor: (this.TIPOS[i.tipo] || this.TIPOS.SITUACAO_ATUAL).cor,
            titulo: (this.TIPOS[i.tipo] || this.TIPOS.SITUACAO_ATUAL).rotulo } },
        { nome: 'etapa', rotulo: 'Para qual análise?', tipo: 'botoes',
          opcoes: destinos.map((e) => ({ valor: e, rotulo: Diag.ROTULO_ETAPA[e] })),
          ajuda: 'O texto e as vozes da sala vão junto; muda a análise e a categoria.' },
        ...destinos.map((e) => ({ ...campoDe(e), nome: `categoria_${e}`,
          visivelSe: { campo: 'etapa', valores: [e] } })),
      ],
      transformar: (d) => ({ planejamento_id: d.planejamento_id, etapa: d.etapa,
        categoria: d[`categoria_${d.etapa}`] || '' }),
      // Some daqui e nasce lá: leva quem moveu até o cartão novo, pelo mesmo
      // caminho que o `⇄` do fator usa na direção contrária.
      aoSalvar: (r) => {
        // A categoria vai junto: no celular a análise mostra uma coluna por
        // vez, e sem trocar o filtro o cartão recém-criado ficaria escondido —
        // a tela pareceria não ter feito nada.
        if (r?.id) Diag.irParaFator(String(r.destino).toLowerCase(), r.id, r.destino, r.categoria);
        else App.recarregarSecaoAtiva();
      },
    });
  },

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
        <div class="card mb-2" data-card-fator="${i.id}" data-cadeado="cenario_item:${i.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator"><strong>${idx + 1}.</strong> ${Modal.esc(i.descricao)}</div>
          ${Diag.selosOrigem(i) || Diag.seloPlanoAcao(i) || App.podeEditar()
            ? `<div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
                ${Diag.selosOrigem(i)}${Diag.seloPlanoAcao(i)}
                ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
                  <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}" title="Editar" aria-label="Editar">✎</button>
                  ${SecaoCenario.botaoMover(i)}
                  ${SecaoCenario.botaoExcluir(i)}
                </span>` : ''}
              </div>` : ''}
        </div></div>`).join('');
      const cor = 'var(--verde-coperdia)';
      return `<div class="col-md-6" data-coluna-categoria="${tipo}">
        <div class="caixa-coluna">
          ${RelatorioAnalise.bloco({
            cabecalho: `<div class="cabecalho-coluna d-flex align-items-center mb-2">
              ${Diag.iconeOrientacao(tipo, cor, titulo)}
              <h2 class="h6 text-uppercase text-muted mb-0">${titulo} ${Diag.contadorCards(lista.length, cor)}</h2>
              ${Diag.botaoAddCategoria(tipo, titulo, cor)}
            </div>`,
            corpo: `<div class="corpo-coluna">
              ${Diag.painelOrientacao(tipo, cor)}
              ${linhas || '<div class="text-muted small">Nenhum item.</div>'}
              <div class="text-muted small fst-italic d-none d-print-none" data-busca-vazio>
                Nada nesta coluna com esse termo.</div>
            </div>`,
          })}
        </div>
      </div>`;
    };

    const contagensCen = {
      SITUACAO_ATUAL: itens.filter((i) => i.tipo === 'SITUACAO_ATUAL').length,
      TENDENCIA: itens.filter((i) => i.tipo === 'TENDENCIA').length,
    };

    el.innerHTML = RelatorioAnalise.canvas({
      cabecalho: `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="mb-0">Análise de Cenário — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
            <div class="d-flex align-items-center gap-2 flex-wrap"
              id="selo-quiz-cenario">${QuizSala.selo(this, 'cenario', this.salaNesteAno(ano))}</div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.campoBusca('CENARIO')}
            ${Diag.seletorAno('cenario')}
            ${QuizSala.microfone({ alvo_tipo: 'CENARIO', ano }, `o cenário de ${ano}`,
              { ativo: this.perguntaDoAno()?.situacao === 'ATIVA',
                pergunta: this.perguntaDoAno()?.situacao === 'ATIVA' ? this.perguntaDoAno().id : null })}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-cenario">+ Novo item</button>' : ''}
          </div>
        </div>
      </div>
      <div id="quiz-vivo-cenario">${this.painelVivo()}</div>`,
      corpo: `
      ${Diag.seletorCategoriaMovel('CENARIO', [
        ['SITUACAO_ATUAL', 'Situação Atual'], ['TENDENCIA', 'Tendências'],
      ], contagensCen)}
      <div class="row g-4">
        ${bloco('SITUACAO_ATUAL', 'Situação Atual')}
        ${bloco('TENDENCIA', 'Tendências')}
      </div>`,
    });

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
    Diag.ligarBusca(el, 'CENARIO');
    Diag.aplicarDestaque(el, 'cenario');
    Diag.ligarSeloColeta(el, 'Análise de Cenário');
    Diag.ligarOrientacoes(el);
    // O cenário vai direto ao plano de ação, como PESTEL, Porter e SWOT — mesmo
    // selo, mesmo gesto, outra rota (é outra tabela).
    Diag.ligarPlanoAcao(el, plan.id, 'cenario', 'item');
    if (!App.podeEditar()) return;
    // `sugestao` chega do painel da sala: o texto entra como RASCUNHO (o
    // condutor redige antes de salvar) e o id viaja em `sugestoes`, que é o
    // conjunto de vozes amarradas ao item — editar o item depois sem esse campo
    // não desfaz nada, porque o servidor só mexe no vínculo quando ele vem.
    const modalItem = (i = null, tipoNovo = null, sugestao = null) => Modal.abrir({
      titulo: sugestao ? `Aceitar sugestão da sala · ${ano}`
        : i ? `Editar item do cenário (${i.ano || ano})` : `Novo item do cenário · ${ano}`,
      url: i ? `/api/cenario/${i.id}` : '/api/cenario',
      bloqueio: i ? { recurso: 'cenario_item', registro_id: i.id, planejamento_id: plan.id } : null,
      valores: i
        ? { ...i, planejamento_id: plan.id }
        : {
            planejamento_id: plan.id, ano,
            // O tipo NASCE marcado, mesmo sem vir do "+" de uma coluna: com
            // botões, nada vem escolhido por padrão (o `select` marcava o
            // primeiro sozinho), e o formulário abriria pedindo um campo
            // obrigatório que a pessoa não tem por que adivinhar. Situação
            // atual é o padrão porque é por onde a análise começa.
            tipo: tipoNovo || 'SITUACAO_ATUAL',
            ...(sugestao ? { descricao: sugestao.texto } : {}),
          },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        ...(sugestao ? [
          { nome: 'origem_sala', rotulo: '', tipo: 'info',
            texto: `${sugestao.autor}: “${sugestao.texto}”`,
            barra: { cor: (SecaoCenario.TIPOS[sugestao.tipo_resposta] || SecaoCenario.TIPOS.SITUACAO_ATUAL).cor,
                     titulo: (SecaoCenario.TIPOS[sugestao.tipo_resposta]
                       || SecaoCenario.TIPOS.SITUACAO_ATUAL).rotulo } },
        ] : []),
        // Botões, e não um `select`: são DUAS opções, e as duas cabem lado a
        // lado. O combobox escondia metade da escolha atrás de um toque — quem
        // abre o formulário para lançar uma tendência tinha de abrir a lista
        // para conferir que era isso mesmo. Com dois botões a escolha inteira
        // está à vista, e trocá-la é um toque em vez de três.
        //
        // A ordem é a das colunas da tela: situação atual à esquerda,
        // tendência à direita, como o item vai aparecer depois de salvo.
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'botoes',
          opcoes: Object.entries(SecaoCenario.TIPOS)
            .map(([valor, t]) => ({ valor, rotulo: t.rotulo })) },
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 4 },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: 0 },
      ],
      // O vínculo viaja pelo transformar, nunca por um campo `hidden`: hidden
      // guarda texto, e uma lista viraria a string "12" no caminho de volta.
      // Sem a chave `sugestoes` o servidor não mexe em vínculo nenhum — é o que
      // faz uma edição comum do item preservar as vozes já registradas.
      ...(sugestao ? { transformar: (d) => ({ ...d, sugestoes: sugestao.ids || [sugestao.id] }) } : {}),
    });
    this.modalItem = modalItem;
    document.getElementById('btn-novo-cenario').addEventListener('click', () => modalItem());
    el.querySelectorAll('[data-add-categoria]').forEach((b) => b.addEventListener('click', () =>
      modalItem(null, b.dataset.addCategoria)));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalItem(itens.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-mover]').forEach((b) => b.addEventListener('click', () => {
      const item = itens.find((i) => i.id == b.dataset.mover);
      if (item) this.modalMover(item, plan.id);
    }));
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
    const podeUnir = App.podeEditar() && p.situacao !== 'ATIVA';
    const coluna = (tipo, rotulo, classe) => {
      const fichas = sugestoes.filter((s) => s.tipo_resposta === tipo);
      // O contador é dos CARTÕES abertos (ver cascata.js): voz usada saiu da
      // grade e as unidas viraram um cartão só
      const abertas = QuizSala.contarCartoes(fichas);
      return `<div class="col-md-6"><div class="coluna-quiz ${classe}">
        <div class="fw-bold small text-uppercase mb-2">${rotulo}
          <span class="badge rounded-pill text-bg-secondary">${abertas}</span></div>
        ${QuizSala.fichas(fichas, { virou: 'item de cenário', podeUnir })}
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
    QuizSala.ligarUniao(this, el);
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
      // O cartão pode reunir várias vozes: leva o texto de todas e amarra todas
      const s = QuizSala.grupoDe(this.quiz?.sugestoes, b.dataset.usarSugestao);
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
        return `<div class="card mb-2" data-card-fator="${f.id}" data-cadeado="fator:${f.id}"><div class="card-body py-2 px-3">
          <div class="small texto-fator">${Modal.esc(f.descricao)}</div>
          <div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
            ${Diag.selosOrigem(f)}${origem}${gut}${acao}
            ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
              <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}" title="Editar" aria-label="Editar">✎</button>
              ${Diag.botaoMoverFator(f)}
              ${Diag.botaoExcluirFator(f)}
            </span>` : ''}
          </div>
        </div></div>`;
      }).join('');
      return `<div class="col-md-6" data-coluna-categoria="${cat}">
        <div class="p-2 rounded caixa-coluna"
          style="--tinta-coluna:${cor}18; border-top: 3px solid ${cor}">
          ${RelatorioAnalise.bloco({
            cabecalho: `<div class="cabecalho-coluna d-flex align-items-center mb-2">
              ${Diag.iconeOrientacao(cat, cor, rotulo)}
              <span class="fw-bold small text-uppercase" style="color:${cor}">${rotulo}
                <span class="ambiente-quadrante">(${Diag.DICAS_QUADRANTE[cat]})</span>
                ${Diag.contadorCards(itens.length, cor)}</span>
              ${Diag.botaoAddCategoria(cat, rotulo, cor)}
              ${Diag.quizMic(this, 'SWOT', ano, cat, rotulo, cor)}
            </div>`,
            corpo: `<div class="corpo-coluna">
              ${Diag.painelOrientacao(cat, cor)}
              ${cartoes || '<div class="text-muted small">Nenhum fator.</div>'}
              <div class="text-muted small fst-italic d-none d-print-none" data-busca-vazio>
                Nada neste quadrante com esse termo.</div>
            </div>`,
          })}
        </div>
      </div>`;
    };

    const contagensSwot = Object.fromEntries(Object.keys(Diag.QUADRANTES)
      .map((cat) => [cat, fatores.filter((f) => f.categoria === cat).length]));

    el.innerHTML = RelatorioAnalise.canvas({
      cabecalho: `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="mb-0">SWOT — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
            <div class="d-flex align-items-center gap-2 flex-wrap"
              data-selo-quiz>${QuizSala.selo(this, 'swot', Diag.salaNestaEtapa(this, 'SWOT', ano))}</div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.campoBusca('SWOT')}
            ${Diag.seletorAno('swot')}
            ${Diag.quizMicEtapa(this, 'SWOT', ano, 'SWOT')}
            ${RelatorioAnalise.botao()}
            ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-swot">+ Novo fator</button>' : ''}
          </div>
        </div>
      </div>
      <div data-quiz-vivo>${Diag.quizPainel(this, 'SWOT', ano)}</div>`,
      corpo: `
      ${Diag.seletorCategoriaMovel('SWOT', [
        ['FORCA', 'Forças'], ['FRAQUEZA', 'Fraquezas'],
        ['OPORTUNIDADE', 'Oportunidades'], ['AMEACA', 'Ameaças'],
      ], contagensSwot)}
      <div class="row g-3">
        ${quadrante('FORCA', 'Forças', '#007a45')}
        ${quadrante('FRAQUEZA', 'Fraquezas', '#b08d4f')}
        ${quadrante('OPORTUNIDADE', 'Oportunidades', '#2c7fb8')}
        ${quadrante('AMEACA', 'Ameaças', '#8f3b3b')}
      </div>`,
    });

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
    // Antes do destaque: a busca remede o "ver mais" e reposiciona os cartões,
    // e o destaque precisa rolar até um card que já esteja no lugar final
    Diag.ligarBusca(el, 'SWOT');
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
    // Os três estados do selo do plano — o mesmo helper que PESTEL e Porter
    // usam. Ele já trata o "Virou ação ↗" antes da saída por `podeEditar`.
    Diag.ligarPlanoAcao(el, plan.id);
    Diag.ligarMoverFator(el, fatores, plan.id);

    if (!App.podeEditar()) {
      QuizSala.ligarSelo(el);
      return;
    }
    const modalFator = (f = null, categoria = null, sugestao = null) => Modal.abrir({
      titulo: sugestao ? `Aceitar sugestão da sala · ${ano}`
        : f ? `Editar fator da SWOT (${f.ano || ano})` : `Novo fator da SWOT · ${ano}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      // Cadeado só na EDIÇÃO: item novo não é disputado por ninguém.
      bloqueio: f ? { recurso: 'fator', registro_id: f.id, planejamento_id: plan.id } : null,
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
      ...(sugestao ? { transformar: (dd) => ({ ...dd, sugestoes: sugestao.ids || [sugestao.id] }) } : {}),
    });
    Diag.quizLigarEtapa(this, el, 'SWOT', ano, modalFator);
    document.getElementById('btn-novo-swot').addEventListener('click', () => modalFator());
    el.querySelectorAll('[data-add-categoria]').forEach((b) => b.addEventListener('click', () =>
      modalFator(null, b.dataset.addCategoria)));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalFator(fatores.find((f) => f.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      const f = fatores.find((x) => x.id == b.dataset.excluir);
      // O que vai junto precisa ser dito ANTES, um item por vez: a GUT some por
      // cascata da FK e os cruzamentos também, e um "excluir?" seco escondia
      // que a estratégia escrita a partir deste fator ia embora com ele.
      const junto = [
        f?.score ? 'a avaliação dele na Matriz GUT' : '',
        Number(f?.cruzamentos) ? `${f.cruzamentos} cruzamento(s) da SWOT` : '',
      ].filter(Boolean);
      const aviso = junto.length
        ? `Excluir este fator da SWOT? Também será apagado: ${junto.join(' e ')}.`
        : 'Excluir este fator da SWOT?';
      if (!confirm(aviso)) return;
      await App.api(`/api/fatores/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

const SecaoGut = {
  // As três faixas do score (1–125). Elas aparecem por LETRA na barra da
  // legenda e na coluna "Prioridade" da tabela — o número entre parênteses é o
  // que diz de onde a letra vem.
  FAIXAS: [
    { letra: 'P', rotulo: 'pequena', piso: 1,  faixa: '&lt; 27', extenso: 'abaixo de 27', cor: '#007a45' },
    { letra: 'M', rotulo: 'média',   piso: 27, faixa: '27–63',   extenso: 'de 27 a 63',   cor: '#b08d4f' },
    { letra: 'G', rotulo: 'grande',  piso: 64, faixa: '≥ 64',    extenso: '64 ou mais',   cor: '#8f3b3b' },
  ],
  /**
   * A faixa de um score, ou null se ele ainda não foi avaliado.
   *
   * É a fonte única do P/M/G da tela: a barra da legenda, a cor do selo do
   * score e a letra da coluna "Prioridade" saem todos daqui. Escritos
   * separados, divergiriam na primeira revisão de paleta ou de corte, e a
   * legenda passaria a explicar uma cor (ou um limite) que a tabela não usa.
   */
  faixaDoScore(score) {
    let faixa = null;
    this.FAIXAS.forEach((f) => { if (score >= f.piso) faixa = f; });
    return faixa;
  },
  // Severidade do score colore o selo: alta, média ou baixa prioridade.
  corScore(score) {
    return (this.faixaDoScore(score) || this.FAIXAS[0]).cor;
  },

  /**
   * A faixa como UMA LETRA num selo redondo, na cor da faixa — P verde, M
   * dourado, G vermelho, a mesma paleta da legenda.
   *
   * Esta coluna já foi o **esforço** de enfrentar o fator, estimado à mão numa
   * quarta pergunta da avaliação. Ela saiu: quase ninguém estimava (a coluna
   * vivia de traços), a pergunta alongava um formulário que existe para ser
   * respondido em segundos, e P/M/G aparecia na mesma tela com dois
   * significados opostos — vermelho no score é "tratar agora", vermelho no
   * esforço era "caro de resolver". Agora a letra é consequência do score, e a
   * tela tem uma leitura só. A coluna `gut.esforco` continua no banco com as
   * estimativas antigas: nada foi apagado.
   */
  seloFaixa(score) {
    const f = this.faixaDoScore(score);
    // Sem nota não há faixa — e o traço não se pinta: cor aqui afirmaria uma
    // prioridade que ninguém avaliou.
    if (!f) return '<span class="selo-faixa vazio" title="Ainda sem avaliação">—</span>';
    const dica = `Prioridade ${f.rotulo} — score ${f.extenso}`;
    return `<span class="selo-faixa" style="background:${f.cor}"
      title="${dica}" aria-label="${dica}">${f.letra}</span>`;
  },

  async carregar() {
    const base = await Diag.preparar('secao-gut');
    if (!base) return;
    const { el, plan, ano } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT&ano=${ano}`);
    // Só o score ordena: o desempate por esforço saiu junto com a estimativa
    // manual. Empate mantém a ordem que veio do servidor (categoria e id), que
    // é estável entre recargas — sortear ali faria a fila dançar sozinha.
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
        data-card-fator="${f.id}" data-cadeado="fator:${f.id}"
        ${editar ? `data-avaliar="${f.id}" role="button" tabindex="0"` : ''}>
        <div class="card-body py-2 px-3">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="gut-rank ${avaliado ? '' : 'text-black-50'}">${avaliado ? `${idx + 1}º` : '—'}</span>
            <span class="badge gut-tag" style="color:${cor};background:${cor}1f">${Diag.QUADRANTES[f.categoria]}</span>
            ${avaliado ? this.seloFaixa(f.score) : ''}
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
      return `<tr data-card-fator="${f.id}" data-cadeado="fator:${f.id}">
        <td>${f.score ? `<strong>${idx + 1}º</strong>` : '—'}</td>
        <td><span class="badge gut-tag" style="color:${cor};background:${cor}1f">${Diag.QUADRANTES[f.categoria]}</span></td>
        <td class="small" data-busca-texto>${Modal.esc(f.descricao)}</td>
        <td class="text-center">${f.gravidade ?? '—'}</td>
        <td class="text-center">${f.urgencia ?? '—'}</td>
        <td class="text-center">${f.tendencia ?? '—'}</td>
        <td class="text-center">${f.score
          ? `<span class="badge gut-score" style="background:${this.corScore(f.score)}">${f.score}</span>` : '—'}</td>
        <td class="text-center">${this.seloFaixa(f.score)}</td>
        <td>${editar ? `<button class="btn btn-sm btn-outline-secondary" data-avaliar="${f.id}">Avaliar</button>` : ''}</td>
      </tr>`;
    }).join('');

    const vazio = 'Cadastre fatores na SWOT para avaliá-los aqui.';
    el.innerHTML = `
      <div class="cabecalho-gut" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h1 class="mb-0">Matriz GUT — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.campoBusca('GUT')}
            ${Diag.seletorAno('gut')}
          </div>
        </div>
      </div>
      <div class="alert alert-secondary py-2 d-none d-print-none" data-busca-vazio-geral>
        Nenhum fator desta matriz casa com o termo pesquisado.
      </div>
      <div class="gut-legenda-barra small mb-3">
        <span class="gl-titulo">Prioridade =
          <span class="d-none d-lg-inline">Gravidade × Urgência × Tendência</span>
          <span class="d-lg-none">G × U × T</span> (1–125)</span>
        <span class="gl-faixas">
          ${this.FAIXAS.map((f) => `<span title="Prioridade ${f.rotulo} — score ${f.extenso}"
            aria-label="Prioridade ${f.rotulo} — score ${f.extenso}"><i
            style="background:${this.corScore(f.piso)}"></i><b>${f.letra}</b> (${f.faixa})${
            f.piso === 64 ? '<span class="d-none d-md-inline"> — tratar agora</span>' : ''}</span>`).join('')}
          <button type="button" class="btn-orientacao" data-orientacao="PMG"
            style="--cor-cat:#5d6b64" aria-expanded="false"
            title="O que significam P, M e G" aria-label="O que significam P, M e G">ⓘ</button>
        </span>
      </div>
      <div class="orientacao-categoria small d-none mb-3" data-orientacao-alvo="PMG"
        style="--cor-cat:#5d6b64">
        <b>P</b>, <b>M</b> e <b>G</b> são as faixas da <strong>prioridade</strong> — a letra sai do
        score (G × U × T), não de uma estimativa à parte:
        ${this.FAIXAS.map((f) => `<b>${f.letra}</b> = ${f.rotulo} (${f.faixa})`).join(' · ')}.<br>
        É a mesma letra da coluna <strong>Prioridade</strong> da tabela: quem lê a fila enxerga a
        faixa sem precisar decorar onde cada corte do score começa.
      </div>

      <div class="d-md-none">
        ${cartoes || `<div class="text-muted small">${vazio}</div>`}
        ${ordenados.length ? `<div class="legenda-quadrantes">
          ${Object.entries(Diag.QUADRANTES).map(([cat, rotulo]) =>
            `<span><i style="background:${Diag.CORES_QUADRANTE[cat]}"></i>${rotulo}</span>`).join('')}
        </div>` : ''}
      </div>

      <div class="tabela-gut d-none d-md-block">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Ranking</th><th>Quadrante</th><th>Fator</th>
            <th class="text-center">G</th><th class="text-center">U</th><th class="text-center">T</th>
            <th class="text-center">Score</th>
            <th class="text-center" title="Faixa do score: P (menos de 27) · M (27 a 63) · G (64 ou mais)">Prioridade</th>
            <th></th>
          </tr></thead>
          <tbody>${linhas || `<tr><td colspan="9" class="text-muted">${vazio}</td></tr>`}</tbody>
        </table>
      </div>`;

    Diag.ligarSeletorAno(el);
    Diag.ligarVerMais(el);
    // A GUT desenha a MESMA avaliação duas vezes — cartões no celular, linhas
    // no computador —, com o mesmo `data-card-fator`. O `ligarBusca` conta por
    // id justamente por isso; esconder os dois é o certo, porque só um dos
    // blocos está visível de cada vez e o outro reaparece ao girar a tela.
    Diag.ligarBusca(el, 'GUT');
    Diag.ligarOrientacoes(el);
    // Mede `--altura-cabecalho` para o `<thead>` grudar logo ABAIXO do título,
    // e não por cima dele. É o mesmo helper das outras análises de propósito:
    // o bloco quebra em uma ou duas linhas conforme a largura, e um palpite em
    // `rem` deixaria os dois cabeçalhos empilhados na tela errada.
    Diag.ligarCabecalhoFixo(el);
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
        // A avaliação é a GUT e só ela: três perguntas. A quarta — o esforço de
        // enfrentar — saiu, e a letra P/M/G da tabela passou a vir da faixa do
        // score (ver `seloFaixa`). O corpo não manda mais `esforco`, e o
        // servidor preserva o que já estava gravado em vez de apagá-lo.
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

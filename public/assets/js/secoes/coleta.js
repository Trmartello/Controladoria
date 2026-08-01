// Coleta de Ideias — o passo 0 do diagnóstico.
// Qualquer participante registra o que pensou; a controladoria tria item a
// item, encaminhando para Cenário/PESTEL/Porter/SWOT ou descartando com
// motivo. O vínculo com o registro criado fica guardado nos dois sentidos.

const DESTINOS_SUGERIDOS = [
  ['NAO_SEI', 'Não sei ainda'],
  ['CENARIO', 'Análise de Cenário'],
  ['PESTEL', 'PESTEL'],
  ['PORTER', 'Porter (5 Forças)'],
  ['SWOT', 'SWOT'],
];

// Destinos da triagem, na ordem em que aparecem na fila
const DESTINOS_TRIAGEM = [
  { valor: 'CENARIO', rotulo: 'Cenário', cor: '#007a45' },
  { valor: 'PESTEL', rotulo: 'PESTEL', cor: '#2c7fb8' },
  { valor: 'PORTER', rotulo: 'Porter', cor: '#b08d4f' },
  { valor: 'SWOT', rotulo: 'SWOT', cor: '#6b4c9a' },
];

const CATEGORIAS_DESTINO = {
  PESTEL: [
    ['POLITICO', 'Político'], ['ECONOMICO', 'Econômico'], ['SOCIAL', 'Social'],
    ['TECNOLOGICO', 'Tecnológico'], ['ECOLOGICO', 'Ecológico'], ['LEGAL', 'Legal'],
  ],
  PORTER: [
    ['RIVALIDADE', 'Rivalidade'], ['NOVOS_ENTRANTES', 'Novos entrantes'],
    ['SUBSTITUTOS', 'Substitutos'], ['PODER_FORNECEDORES', 'Poder dos fornecedores'],
    ['PODER_CLIENTES', 'Poder dos clientes'],
  ],
};

const SITUACOES = {
  NOVO: ['A tratar', 'text-bg-warning'],
  ACEITO: ['Aceita', 'text-bg-success'],
  DESCARTADO: ['Descartada', 'text-bg-secondary'],
};

const SecaoColeta = {
  plan: null,
  itens: [],
  filtro: 'NOVO',
  // "Pular" vale só para esta sessão de triagem: a fila recarrega do servidor
  // a cada ação, então a escolha precisa morar aqui e não na ordem da lista
  pulados: new Set(),

  /** Próxima da fila: a mais antiga ainda não tratada que ninguém pulou. */
  proximaDaFila() {
    const novas = this.itens.filter((i) => i.situacao === 'NOVO');
    const restantes = novas.filter((i) => !this.pulados.has(i.id));
    // Todas puladas: recomeça a rodada em vez de deixar a fila vazia
    if (!restantes.length && novas.length) {
      this.pulados.clear();
      return novas[0];
    }
    return restantes[0];
  },

  data(iso) {
    return iso ? String(iso).slice(0, 10).split('-').reverse().join('/') : '';
  },

  async carregar() {
    const base = await Diag.preparar('secao-coleta');
    if (!base) return;
    const { el, plan, ano } = base;
    this.plan = plan;
    this.itens = await App.api(`/api/coleta?planejamento_id=${plan.id}&ano=${ano}`);

    const conta = (s) => this.itens.filter((i) => i.situacao === s).length;
    const naFila = conta('NOVO');
    const visiveis = this.itens.filter((i) => i.situacao === this.filtro);
    const podeTriar = App.podeEditar();

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Coleta de Ideias — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          ${Diag.seletorAno()}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-nova-ideia">+ Nova ideia</button>' : ''}
        </div>
      </div>
      <p class="text-muted">Registre o que veio da oficina antes de organizar no diagnóstico.
      Cada ideia é tratada uma a uma e vira item de cenário ou fator — ou é descartada com motivo.
      <em>A coleta é anual, como o resto do diagnóstico.</em></p>

      ${podeTriar && naFila ? `<div class="card mb-3 fila-coleta"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="small text-uppercase">Fila de tratativa</strong>
          <span class="badge text-bg-warning">${naFila} a tratar</span>
          <span class="small text-muted flex-grow-1">Uma ideia por vez, na ordem em que chegaram.</span>
        </div>
        ${this.cartaoFila(this.proximaDaFila())}
      </div></div>` : ''}

      <div class="btn-group btn-group-sm mb-3 filtro-coleta" role="group" aria-label="Situação">
        ${Object.entries(SITUACOES).map(([s, [rotulo]]) => `
          <button type="button" class="btn ${s === this.filtro ? 'btn-verde' : 'btn-outline-secondary'}"
            data-filtro="${s}">${rotulo} (${conta(s)})</button>`).join('')}
      </div>

      <div class="lista-ideias">
        ${visiveis.map((i) => this.cartaoIdeia(i)).join('')
          || '<div class="text-muted small">Nenhuma ideia nesta situação.</div>'}
      </div>`;

    Diag.ligarSeletorAno(el);
    Diag.ligarVerMais(el);
    this.destacarVindoDoDiagnostico(el);
    this.ligarEventos(el, ano);
  },

  // Cartão grande da fila: a ideia crua e os botões de destino
  cartaoFila(item) {
    if (!item) return '';
    const sugerido = item.destino_sugerido;
    const botoes = DESTINOS_TRIAGEM.map((d) => `
      <button class="btn btn-sm btn-destino ${d.valor === sugerido ? 'sugerido' : ''}"
        style="--cor-destino:${d.cor}" data-encaminhar="${item.id}" data-destino="${d.valor}"
        ${d.valor === sugerido ? 'title="Sugerido por quem escreveu"' : ''}>${d.rotulo}</button>`).join('');
    return `<div class="mt-2" data-card-ideia="${item.id}">
      <div class="small texto-fator ideia-crua">${Modal.esc(item.texto)}</div>
      <div class="small text-muted mt-1">${Modal.esc(item.autor)} · ${this.data(item.criado_em)}
        ${sugerido !== 'NAO_SEI'
          ? `· sugeriu ${Modal.esc((DESTINOS_SUGERIDOS.find(([v]) => v === sugerido) || [, sugerido])[1])}`
          : '· sem sugestão de destino'}</div>
      <div class="d-flex gap-1 flex-wrap mt-2">
        ${botoes}
        <button class="btn btn-sm btn-outline-danger" data-descartar="${item.id}">Descartar</button>
        <button class="btn btn-sm btn-outline-secondary ms-auto" data-pular="${item.id}"
          title="Deixar para depois e ver a próxima">Pular</button>
      </div>
    </div>`;
  },

  cartaoIdeia(i) {
    const [rotulo, classe] = SITUACOES[i.situacao] || [i.situacao, 'text-bg-light'];
    const podeMexer = i.minha && i.situacao === 'NOVO' && App.podeEditar();
    return `<div class="card mb-2" data-card-ideia="${i.id}"><div class="card-body py-2 px-3">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge ${classe}">${rotulo}</span>
        <span class="small text-muted flex-grow-1">${Modal.esc(i.autor)} · ${this.data(i.criado_em)}</span>
        ${podeMexer ? `
          <span class="d-flex gap-1 flex-shrink-0">
            <button class="btn btn-sm btn-outline-secondary" data-editar-ideia="${i.id}"
              title="Editar" aria-label="Editar">✎</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir-ideia="${i.id}"
              title="Excluir" aria-label="Excluir">×</button>
          </span>` : ''}
      </div>
      <div class="small texto-fator mt-1">${Modal.esc(i.texto)}</div>
      ${i.situacao === 'ACEITO' && i.destino_id ? `
        <div class="mt-1"><button type="button" class="btn btn-sm selo-link"
          data-ir-destino="${i.destino_id}" data-tipo-destino="${i.destino_tipo}"
          title="Abrir o registro criado">Virou ${i.destino_tipo === 'CENARIO' ? 'item de cenário' : 'fator'} ↗</button></div>` : ''}
      ${i.situacao === 'ACEITO' && !i.destino_id
        ? '<div class="small text-muted mt-1">Destino removido do diagnóstico.</div>' : ''}
      ${i.situacao === 'DESCARTADO' ? `
        <div class="small mt-1 motivo-descarte"><strong>Não entrou:</strong> ${Modal.esc(i.motivo || '')}
          ${i.triador ? `<span class="text-muted">· ${Modal.esc(i.triador)}</span>` : ''}</div>` : ''}
    </div></div>`;
  },

  // Chegou aqui clicando no selo "Coleta · Fulano" de um card do diagnóstico
  destacarVindoDoDiagnostico(el) {
    if (!Diag.destaqueColeta) return;
    const id = Diag.destaqueColeta;
    Diag.destaqueColeta = null;
    const item = this.itens.find((i) => String(i.id) === String(id));
    // A ideia pode não estar no filtro atual; troca para o dela e recarrega
    if (item && item.situacao !== this.filtro) {
      this.filtro = item.situacao;
      Diag.destaqueColeta = id;
      this.carregar();
      return;
    }
    const card = el.querySelector(`[data-card-ideia="${id}"]`);
    if (!card) return;
    card.classList.add('card-destacado');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => card.classList.remove('card-destacado'), 2600);
  },

  ligarEventos(el, ano) {
    el.querySelectorAll('[data-filtro]').forEach((b) => b.addEventListener('click', () => {
      this.filtro = b.dataset.filtro;
      this.carregar();
    }));
    // Volta ao registro que a ideia virou, destacando o card lá
    el.querySelectorAll('[data-ir-destino]').forEach((b) => b.addEventListener('click', async () => {
      const id = b.dataset.irDestino;
      if (b.dataset.tipoDestino === 'CENARIO') {
        Diag.irParaFator('cenario', id);
        return;
      }
      // Fator: descobrir a etapa para abrir a seção certa
      const item = this.itens.find((i) => String(i.destino_id) === String(id));
      const etapa = (item?.destino_sugerido || 'PESTEL').toLowerCase();
      Diag.irParaFator(['pestel', 'porter', 'swot'].includes(etapa) ? etapa : 'pestel', id);
    }));

    if (!App.podeEditar()) return;

    const modalIdeia = (i = null) => Modal.abrir({
      titulo: i ? 'Editar ideia' : `Nova ideia · ${ano}`,
      url: i ? `/api/coleta/${i.id}` : '/api/coleta',
      valores: i
        ? { ...i, planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, ano, destino_sugerido: 'NAO_SEI' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        { nome: 'texto', rotulo: 'A ideia, como você diria em voz alta', tipo: 'textarea', linhas: 4,
          obrigatorio: true,
          exemplo: 'Ex.: o custo do frete até o litoral está inviabilizando a venda de farelo' },
        { nome: 'destino_sugerido', rotulo: 'Onde isso entra?', tipo: 'select',
          opcoes: DESTINOS_SUGERIDOS.map(([valor, rotulo]) => ({ valor, rotulo })),
          ajuda: 'Chute sem medo — quem tria confere depois. Ajuda a acelerar a tratativa.' },
      ],
      aoSalvar: () => this.carregar(),
    });

    document.getElementById('btn-nova-ideia')?.addEventListener('click', () => modalIdeia());
    el.querySelectorAll('[data-editar-ideia]').forEach((b) => b.addEventListener('click', () =>
      modalIdeia(this.itens.find((i) => i.id == b.dataset.editarIdeia))));
    el.querySelectorAll('[data-excluir-ideia]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta ideia?')) return;
      try {
        await App.api(`/api/coleta/${b.dataset.excluirIdeia}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    // Pular deixa a ideia para o fim desta rodada de triagem
    el.querySelectorAll('[data-pular]').forEach((b) => b.addEventListener('click', () => {
      this.pulados.add(Number(b.dataset.pular));
      this.carregar();
    }));

    el.querySelectorAll('[data-encaminhar]').forEach((b) => b.addEventListener('click', () =>
      this.modalEncaminhar(this.itens.find((i) => i.id == b.dataset.encaminhar), b.dataset.destino)));

    el.querySelectorAll('[data-descartar]').forEach((b) => b.addEventListener('click', () => {
      const item = this.itens.find((i) => i.id == b.dataset.descartar);
      Modal.abrir({
        titulo: 'Descartar ideia',
        url: `/api/coleta/${item.id}/descartar`,
        valores: { planejamento_id: this.plan.id },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'ideia', rotulo: 'Ideia', tipo: 'info', texto: item.texto,
            barra: { cor: '#8f3b3b', texto: `${item.autor} · ${this.data(item.criado_em)}` } },
          { nome: 'motivo', rotulo: 'Por que não entra?', tipo: 'textarea', linhas: 3, obrigatorio: true,
            ajuda: 'O autor vê este motivo. É o que transforma um veto silencioso em aprendizado.' },
        ],
        aoSalvar: () => this.carregar(),
      });
    }));
  },

  // Cada destino pede os campos daquele destino; o texto vem editável
  modalEncaminhar(item, destino) {
    const rotuloDestino = (DESTINOS_TRIAGEM.find((d) => d.valor === destino) || {}).rotulo || destino;
    const campos = [
      { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
      { nome: 'destino', rotulo: '', tipo: 'hidden', padrao: destino },
      { nome: 'ideia', rotulo: 'Ideia original', tipo: 'info', texto: item.texto,
        barra: { cor: '#007a45', texto: `${item.autor} · ${this.data(item.criado_em)}` } },
    ];
    if (destino === 'CENARIO') {
      campos.push({ nome: 'tipo', rotulo: 'Tipo', tipo: 'botoes', opcoes: [
        { valor: 'SITUACAO_ATUAL', rotulo: 'Situação atual' },
        { valor: 'TENDENCIA', rotulo: 'Tendência' },
      ]});
    } else if (destino === 'SWOT') {
      campos.push(Diag.campoQuadrante());
    } else {
      campos.push({ nome: 'categoria', rotulo: 'Categoria', tipo: 'select',
        opcoes: CATEGORIAS_DESTINO[destino].map(([valor, rotulo]) => ({ valor, rotulo })) });
    }
    campos.push({ nome: 'texto_tratado', rotulo: 'Texto que vai para o diagnóstico',
      tipo: 'textarea', linhas: 4,
      ajuda: 'Ajuste a redação se precisar; a ideia original fica guardada como foi dita.' });

    Modal.abrir({
      titulo: `Encaminhar para ${rotuloDestino}`,
      url: `/api/coleta/${item.id}/encaminhar`,
      valores: { planejamento_id: this.plan.id, destino, texto_tratado: item.texto },
      campos,
      aoSalvar: () => this.carregar(),
    });
  },
};

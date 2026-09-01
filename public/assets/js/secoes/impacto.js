/**
 * Matriz de Impacto por Negócio.
 *
 * Duas leituras da MESMA tabela, escolhidas pelo contexto do menu:
 *
 * - **Corporativo** → a grade inteira, fator × negócio. É onde a controladoria
 *   preenche e onde a direção compara negócios.
 * - **Um negócio** → só a coluna dele, em lista: "o que o corporativo diz que
 *   me impacta neste ano". Essa leitura é o motivo de a tela existir — sem ela
 *   a matriz vira um slide bonito que só a controladoria abre.
 *
 * O servidor já devolve recortado (o gestor só recebe os negócios do escopo
 * dele, e sem o score da GUT). A tela NÃO refaz esse recorte: ela desenha o que
 * chegou. Reconstruir a regra aqui criaria uma segunda definição de "o que o
 * gestor pode ver", e as duas divergiriam na primeira mudança — que é
 * exatamente o defeito que a decisão de acesso não pode ter.
 */
const SecaoImpacto = {
  dados: null,
  ciclo: null,

  // Glifo, cor e rótulo de cada sinal — catálogo único, lido pela grade, pela
  // lista e pelo modal. Sinal é COR **e** forma: cor sozinha não sobrevive nem
  // ao daltonismo nem à impressão em preto e branco, e esta tela nasceu para
  // ser levada impressa a uma reunião.
  SINAIS: {
    POSITIVO: { glifo: '▲', cor: '#007a45', rotulo: 'Ajuda este negócio' },
    NEGATIVO: { glifo: '▼', cor: '#8f3b3b', rotulo: 'Atrapalha este negócio' },
  },

  // A linha é sempre da SWOT: só oportunidade e ameaça olham para fora.
  CATEGORIAS: { OPORTUNIDADE: 'Oportunidade', AMEACA: 'Ameaça' },

  async carregar() {
    const el = document.getElementById('secao-impacto');
    const ctx = App.contexto;
    if (!ctx.cicloId || (!ctx.negocioId && !ctx.corporativo)) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.ciclo = ctx.cicloId;
    const ano = Diag.ano();
    this.dados = await App.api(`/api/impacto?ciclo_id=${ctx.cicloId}&ano=${ano}`);

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Impacto por Negócio — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2 align-items-center">${Diag.seletorAno('impacto')}</div>
      </div>
      <p class="text-muted d-print-none">${ctx.corporativo
        ? 'O que o diagnóstico corporativo deste ano faz com cada negócio. As linhas são as '
          + 'ameaças e oportunidades da SWOT corporativa, na ordem da Matriz GUT — '
          + 'não há curadoria separada aqui.'
        : 'O que o diagnóstico corporativo deste ano diz que impacta este negócio. '
          + 'As linhas vêm da SWOT corporativa; quem as escreve é a controladoria.'}</p>
      ${this.corpo(ctx)}`;

    Diag.ligarSeletorAno(el);
    this.ligar(el);
  },

  corpo(ctx) {
    const { fatores, negocios } = this.dados;
    if (!fatores.length) {
      return `<div class="alert alert-secondary">
        Nenhuma ameaça ou oportunidade priorizada no plano corporativo de ${Diag.ano()}.
        A matriz se alimenta da <strong>SWOT corporativa</strong> do ano — preencha-a
        (e priorize na Matriz GUT) para as linhas aparecerem aqui.</div>`;
    }
    if (!negocios.length) {
      return '<div class="alert alert-secondary">Nenhum negócio ativo no seu escopo.</div>';
    }
    // Fora do contexto corporativo a grade não faz sentido nem cabe: o gestor
    // tem UMA coluna, e uma tabela de uma coluna é uma lista com bordas.
    return ctx.corporativo
      ? `${this.grade()}${this.cards()}`
      : this.lista(negocios[0]);
  },

  /** Índice das células por `fator:negocio` — um passe, não uma busca por célula. */
  indice() {
    const mapa = {};
    for (const c of this.dados.celulas) mapa[`${c.fator_id}:${c.negocio_id}`] = c;
    return mapa;
  },

  /**
   * A grade do contexto corporativo (computador).
   *
   * A coluna do fator é `sticky left: 0`: com doze negócios a tabela rola de
   * lado, e sem a coluna presa quem rola perde de vista qual linha está lendo —
   * que é o mesmo defeito que a Matriz de Execução tinha no cabeçalho.
   *
   * A célula mostra SÓ o glifo. Coluna estreita é o que faz doze negócios mais
   * a coluna do fator caberem numa tela de 1500px; o texto do impacto vive no
   * `title` e no modal, porque ele é a leitura de UMA célula, não da grade.
   */
  grade() {
    const { fatores, negocios } = this.dados;
    const mapa = this.indice();
    const cabecalho = negocios.map((n) => `
      <th class="col-negocio" title="${Modal.esc(n.rotulo)}">
        <span aria-hidden="true">${Modal.esc(n.cod_negocio)}</span>
        <span class="visually-hidden">${Modal.esc(n.rotulo)}</span>
      </th>`).join('');

    const linhas = fatores.map((f) => {
      const celulas = negocios.map((n) => {
        const c = mapa[`${f.id}:${n.id}`];
        const s = c ? this.SINAIS[c.sinal] : null;
        const dica = c
          ? `${n.rotulo} · ${s.rotulo}${c.texto ? ` — ${c.texto}` : ''}`
          : `${n.rotulo} · sem impacto registrado`;
        return `<td class="celula-impacto" data-fator="${f.id}" data-negocio="${n.id}"
          title="${Modal.esc(dica)}">
          ${s ? `<span style="color:${s.cor}" aria-hidden="true">${s.glifo}</span>
                 <span class="visually-hidden">${Modal.esc(dica)}</span>`
              : '<span class="visually-hidden">sem impacto registrado</span>'}
        </td>`;
      }).join('');
      return `<tr><th scope="row" class="col-fator">${this.rotuloFator(f)}</th>${celulas}</tr>`;
    }).join('');

    return `<div class="table-responsive caixa-impacto d-none d-md-block">
      <table class="table table-sm table-bordered align-middle tabela-impacto">
        <thead><tr><th class="col-fator">Ameaça / Oportunidade</th>${cabecalho}</tr></thead>
        <tbody>${linhas}</tbody>
      </table>
      <p class="small text-muted mt-2">
        <span style="color:${this.SINAIS.POSITIVO.cor}">▲</span> ajuda ·
        <span style="color:${this.SINAIS.NEGATIVO.cor}">▼</span> atrapalha ·
        célula vazia = sem impacto relevante.
        ${this.dados.pode_editar ? 'Clique numa célula para registrar.' : ''}</p>
    </div>`;
  },

  /** O rótulo da linha: categoria, texto e — só para quem vê tudo — o score. */
  rotuloFator(f) {
    const cat = this.CATEGORIAS[f.categoria] || f.categoria;
    const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
    return `<span class="badge gut-tag" style="color:${cor};background:${cor}1f">${cat}</span>
      ${f.score ? `<span class="badge badge-ancora ms-1" title="Prioridade na Matriz GUT">GUT ${f.score}</span>` : ''}
      <div class="small texto-fator mt-1">${Modal.esc(f.descricao)}</div>`;
  },

  /**
   * O celular, no contexto corporativo: um card por fator, com um chip por
   * negócio impactado.
   *
   * NÃO reusa `Diag.ligarSeletorCategoriaMovel`: ele faz
   * `classList.toggle('d-md-block')`, e `.d-md-block{display:block!important}`
   * aplicado a `<td>`/`<th>` desmonta a tabela no computador — pior, o estado
   * dele mora em `Diag.filtroMovel`, que é global e sobrevive à navegação.
   */
  cards() {
    const { fatores, negocios } = this.dados;
    const mapa = this.indice();
    const nomes = Object.fromEntries(negocios.map((n) => [n.id, n]));
    return `<div class="d-md-none">${fatores.map((f) => {
      const chips = negocios
        .map((n) => mapa[`${f.id}:${n.id}`])
        .filter(Boolean)
        .map((c) => {
          const s = this.SINAIS[c.sinal];
          return `<button type="button" class="badge chip-impacto"
            style="color:${s.cor};background:${s.cor}1f"
            data-fator="${f.id}" data-negocio="${c.negocio_id}"
            title="${Modal.esc(c.texto || s.rotulo)}">${s.glifo} ${
            Modal.esc(nomes[c.negocio_id]?.nome || c.negocio_id)}</button>`;
        }).join('');
      return `<div class="card mb-2"><div class="card-body py-2 px-3">
        ${this.rotuloFator(f)}
        <div class="d-flex gap-1 flex-wrap mt-2">${chips
          || '<span class="small text-muted fst-italic">Nenhum negócio impactado ainda.</span>'}</div>
      </div></div>`;
    }).join('')}</div>`;
  },

  /**
   * A coluna de UM negócio, em lista — a leitura do gestor.
   *
   * Só o que tem célula: uma lista com as doze linhas da SWOT corporativa, a
   * maioria vazia, faria o gestor procurar o que importa no meio do que não o
   * afeta. A contagem no topo diz quantas das linhas do ano o alcançam.
   */
  lista(negocio) {
    const { fatores } = this.dados;
    const mapa = this.indice();
    const meus = fatores
      .map((f) => ({ f, c: mapa[`${f.id}:${negocio.id}`] }))
      .filter((x) => x.c);
    if (!meus.length) {
      return `<div class="alert alert-secondary">
        Nenhum fator do diagnóstico corporativo de ${Diag.ano()} foi marcado como impacto
        deste negócio. Isso não quer dizer que não haja — quer dizer que ainda ninguém
        registrou.</div>`;
    }
    const linhas = meus.map(({ f, c }) => {
      const s = this.SINAIS[c.sinal];
      return `<div class="card mb-2" data-card-impacto="${f.id}">
        <div class="card-body py-2 px-3 d-flex gap-3 align-items-start">
          <span class="glifo-impacto" style="color:${s.cor}" aria-hidden="true">${s.glifo}</span>
          <div class="flex-grow-1">
            ${this.rotuloFator(f)}
            <div class="small mt-1"><strong>${s.rotulo}.</strong>
              ${c.texto ? Modal.esc(c.texto) : '<span class="text-muted fst-italic">Sem detalhamento.</span>'}</div>
          </div>
          ${this.dados.pode_editar
            ? `<button class="btn btn-sm btn-outline-secondary flex-shrink-0"
                 data-fator="${f.id}" data-negocio="${negocio.id}"
                 title="Editar o impacto neste negócio" aria-label="Editar">✎</button>` : ''}
        </div></div>`;
    }).join('');
    return `<p class="text-muted small">${meus.length} de ${fatores.length} fatores do
      diagnóstico corporativo de ${Diag.ano()} impactam este negócio.</p>${linhas}`;
  },

  ligar(el) {
    if (!this.dados.pode_editar) return;
    el.querySelectorAll('[data-fator][data-negocio]').forEach((alvo) =>
      alvo.addEventListener('click', () =>
        this.modalCelula(Number(alvo.dataset.fator), Number(alvo.dataset.negocio))));
  },

  modalCelula(fatorId, negocioId) {
    const f = this.dados.fatores.find((x) => x.id == fatorId);
    const n = this.dados.negocios.find((x) => x.id == negocioId);
    if (!f || !n) return;
    const atual = this.indice()[`${fatorId}:${negocioId}`];
    const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
    Modal.abrir({
      titulo: `Impacto em ${n.nome}`,
      url: '/api/impacto',
      valores: {
        ciclo_id: this.ciclo, fator_id: fatorId, negocio_id: negocioId,
        sinal: atual?.sinal ?? '', texto: atual?.texto ?? '',
      },
      campos: [
        { nome: 'ciclo_id', rotulo: '', tipo: 'hidden' },
        { nome: 'fator_id', rotulo: '', tipo: 'hidden' },
        { nome: 'negocio_id', rotulo: '', tipo: 'hidden' },
        { nome: 'fator', rotulo: `${this.CATEGORIAS[f.categoria] || f.categoria} do diagnóstico corporativo`,
          tipo: 'info', texto: f.descricao,
          barra: { cor, titulo: n.rotulo } },
        // "Sem impacto relevante" é uma OPÇÃO, e não um botão de excluir: é a
        // resposta mais comum numa grade de doze por doze, e escondê-la atrás
        // de um × faria a célula vazia parecer "ninguém analisou" quando na
        // verdade alguém analisou e disse que não afeta.
        { nome: 'sinal', rotulo: 'Este fator, para este negócio…', tipo: 'quadrantes',
          layout: 'lista',
          opcoes: [
            { valor: 'POSITIVO', rotulo: '▲ Ajuda', cor: this.SINAIS.POSITIVO.cor,
              dica: 'É uma oportunidade aqui' },
            { valor: 'NEGATIVO', rotulo: '▼ Atrapalha', cor: this.SINAIS.NEGATIVO.cor,
              dica: 'É uma ameaça aqui' },
            { valor: '', rotulo: '— Sem impacto relevante', cor: '#77877e',
              dica: 'Apaga a célula' },
          ] },
        { nome: 'texto', rotulo: 'Como impacta este negócio?', tipo: 'textarea', linhas: 3,
          exemplo: 'Ex.: encarece o frete da ração e aperta a margem do confinamento',
          ajuda: 'Uma frase. O detalhamento aparece ao passar o mouse na grade e na lista do gestor.' },
      ],
      aoSalvar: () => App.recarregarSecaoAtiva(),
    });
  },
};

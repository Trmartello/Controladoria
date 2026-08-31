// Dossiê do plano — o documento do PLANO, ao lado do Relatório de Status, que é
// o documento da REUNIÃO. Todas as etapas, em sequência, do negócio escolhido.
//
// O que tornava isto caro não é imprimir: é que só existe na tela a seção
// ATIVA. As outras `#secao-X` ficam vazias até alguém abri-las (`App.mostrar-
// Secao` → `carregar()`), e mandar imprimir agora produziria uma folha.
//
// A saída é pintar cada etapa DE LADO e tirar a foto do HTML dela. A foto é
// inerte por construção — atribuir `innerHTML` não carrega ouvinte nenhum —,
// então o documento é cópia morta e a tela viva continua intocada. É também o
// que permite onze negócios no mesmo dossiê: as seções são dezessete elementos
// FIXOS no shell, e não caberiam onze cópias de cada uma.
//
// O que NÃO se fez, e por quê: uma tela de dossiê que busca os dados e desenha
// o documento do zero. Não teria efeito colateral nenhum, mas duplicaria o
// desenho de cada análise — e essa cópia diverge na primeira revisão, que é
// exatamente o que `RelatorioAnalise` existe para evitar (o comentário no topo
// dela conta essa história).
//
// Este arquivo carrega DEPOIS de todas as seções (ver `views/shell.php`): a
// tabela de etapas aponta para cada objeto `Secao*`.

const SecaoDossie = {
  secaoId: 'secao-dossie',

  /**
   * As etapas do PLANO, na ordem do menu — que é a ordem em que o trabalho
   * acontece e, por isso, a ordem em que o documento se lê.
   *
   * Ficam de fora, e nenhuma por esquecimento:
   *
   * - **Painel** e **Hub** são do ciclo inteiro, não de um negócio: repeti-los
   *   por negócio imprimiria a mesma folha onze vezes.
   * - **Cadastros** e **Sala** são operação do sistema, não plano.
   * - **Relatório de Status** é o OUTRO documento — período próprio, ⤓ próprio.
   * - **Coleta e Tempestade** mostra uma situação por vez (`SecaoColeta.filtro`
   *   casa `situacao` exata) e não tem visão "todas". Qualquer página dela aqui
   *   seria uma fatia arbitrária do material da oficina, apresentada como se
   *   fosse a etapa inteira. Entra quando tiver uma visão de leitura própria.
   *
   * `anual` marca as etapas que o seletor de ano do diagnóstico governa: são
   * elas que o ano escolhido aqui alcança.
   */
  ETAPAS: [
    { chave: 'cenario', rotulo: 'Análise de Cenário', secao: () => SecaoCenario, anual: true, padrao: true },
    { chave: 'pestel', rotulo: 'PESTEL', secao: () => SecaoPestel, anual: true, padrao: true },
    { chave: 'porter', rotulo: 'Porter — 5 Forças', secao: () => SecaoPorter, anual: true, padrao: true },
    { chave: 'swot', rotulo: 'SWOT', secao: () => SecaoSwot, anual: true, padrao: true },
    { chave: 'gut', rotulo: 'Matriz GUT', secao: () => SecaoGut, anual: true, padrao: true },
    { chave: 'cruzamentos', rotulo: 'Cruzamentos', secao: () => SecaoCruzamentos, anual: true, padrao: true },
    { chave: 'cascata', rotulo: 'Cascata de Escolhas', secao: () => SecaoCascata, padrao: true },
    { chave: 'projetos', rotulo: 'Projetos · 5W2H', secao: () => SecaoProjetos, padrao: true },
    { chave: 'investimentos', rotulo: 'Investimentos', secao: () => SecaoInvestimentos, padrao: true },
    { chave: 'metas', rotulo: 'Metas · Indicadores', secao: () => SecaoMetas, padrao: true },
  ],

  /**
   * Acima disto, montar vira espera longa demais para não avisar antes: cada
   * documento é uma pintura completa da seção, com as chamadas de API dela.
   * Onze negócios × onze etapas são 121 — o número que o cliente pediu e o
   * número que precisa ser confirmado.
   */
  AVISO_VOLUME: 40,

  montando: false,
  cancelado: false,
  /** O que o último "Montar" produziu: `[{ negocio, etapas: [{rotulo}] }]`. */
  resumo: null,

  // ── Estado de VISTA das seções ──────────────────────────────────────────
  /**
   * Filtros e recolhimentos moram na seção e sobrevivem à repintura — é o que
   * faz a busca da SWOT continuar valendo quando se troca o ano, e o projeto
   * que alguém fechou continuar fechado.
   *
   * Num dossiê isso é veneno: quem tivesse "atrasado" no filtro de Projetos
   * levaria ao Conselho um plano em que só existem projetos atrasados, e nada
   * na folha diria que houve filtro. O documento é o plano INTEIRO; a vista de
   * quem clicou é devolvida no fim, porque ela também é escolha de alguém.
   */
  salvarVista() {
    return {
      busca: { ...Diag.busca },
      filtroMovel: { ...Diag.filtroMovel },
      ano: Diag.anoSelecionado,
      projTexto: SecaoProjetos.filtroTexto,
      projStatus: SecaoProjetos.filtroStatus,
      projResp: SecaoProjetos.filtroResponsavel,
      projFechados: new Set(SecaoProjetos.projetosFechados),
      iniFechadas: new Set(SecaoProjetos.iniciativasFechadas),
    };
  },

  aplicarVista(v) {
    Diag.busca = v.busca;
    Diag.filtroMovel = v.filtroMovel;
    Diag.anoSelecionado = v.ano;
    SecaoProjetos.filtroTexto = v.projTexto;
    SecaoProjetos.filtroStatus = v.projStatus;
    SecaoProjetos.filtroResponsavel = v.projResp;
    SecaoProjetos.projetosFechados = v.projFechados;
    SecaoProjetos.iniciativasFechadas = v.iniFechadas;
  },

  /** A vista do documento: sem filtro, sem recolhido, no ano pedido. */
  vistaDoDocumento(ano) {
    return {
      busca: {},
      filtroMovel: {},
      ano,
      projTexto: '',
      projStatus: '',
      projResp: '',
      projFechados: new Set(),
      iniFechadas: new Set(),
    };
  },

  // ── A foto ──────────────────────────────────────────────────────────────
  /**
   * O HTML pintado da seção, pronto para virar página do documento.
   *
   * Os `id` saem todos. Eles são únicos por definição, e a foto os duplicaria:
   * a partir daí `getElementById('documento-cascata')` passaria a poder cair na
   * cópia morta em vez do elemento vivo — um defeito que só apareceria depois,
   * longe daqui, e sem sintoma que aponte para cá. A cópia não precisa de
   * nenhum deles: ela não tem ouvinte para achar nada.
   */
  foto(origem) {
    const caixa = document.createElement('div');
    caixa.innerHTML = origem.innerHTML;
    caixa.querySelectorAll('[id]').forEach((n) => n.removeAttribute('id'));
    return caixa.innerHTML;
  },

  // ── A montagem ──────────────────────────────────────────────────────────
  /** Os negócios que o usuário pode pedir: os do escopo dele, e o corporativo. */
  alvos() {
    const lista = App.sessao.veTudo
      ? [{ chave: 'CORP', rotulo: 'Corporativo', corporativo: true, negocioId: null }]
      : [];
    for (const n of App.sessao.negocios) {
      lista.push({ chave: String(n.id), rotulo: n.rotulo, corporativo: false, negocioId: n.id });
    }
    return lista;
  },

  /** O alvo que está aberto no menu agora — a marcação inicial da tela. */
  alvoAtual() {
    return App.contexto.corporativo ? 'CORP' : String(App.contexto.negocioId ?? '');
  },

  data() {
    const d = new Date();
    const dois = (n) => String(n).padStart(2, '0');
    return `${dois(d.getDate())}/${dois(d.getMonth() + 1)}/${d.getFullYear()} `
      + `${dois(d.getHours())}:${dois(d.getMinutes())}`;
  },

  marcados(el, nome) {
    return [...el.querySelectorAll(`[data-${nome}]:checked`)].map((c) => c.value);
  },

  /**
   * Percorre negócio × etapa, pinta cada um de lado e guarda a foto.
   *
   * O contexto do aplicativo é mexido DE VERDADE aqui (`App.contexto`), porque
   * é dele que toda seção lê o planejamento — não há como pedir a uma seção que
   * se pinte "para outro negócio" sem trocar o contexto. Daí o `finally`: se
   * uma chamada falhar no meio, quem estava no menu não pode terminar noutro
   * negócio sem ter pedido isso.
   *
   * Efeito colateral declarado: `/api/contexto` CRIA o planejamento do negócio
   * quando ele ainda não existe. Um dossiê de "todos" nasce, portanto, criando
   * a linha vazia dos negócios que ninguém abriu ainda — exatamente o que
   * visitar a aba deles já fazia, só que de uma vez. É benigno (planejamento
   * sem conteúdo é o estado inicial de todos eles), mas é uma escrita, e quem
   * lê este laço tem de saber que ela acontece.
   */
  async montar(el, escolha) {
    const anterior = { ctx: { ...App.contexto }, vista: this.salvarVista() };
    const partes = [];
    const total = escolha.alvos.length * escolha.etapas.length;
    let feitos = 0;

    this.montando = true;
    this.cancelado = false;
    App.modoDossie = true;
    this.aplicarVista(this.vistaDoDocumento(escolha.ano));
    this.pintarBarra(el, 0, total, '');

    try {
      for (const alvo of escolha.alvos) {
        const etapas = [];
        for (const etapa of escolha.etapas) {
          if (this.cancelado) return null;
          this.pintarBarra(el, feitos, total, `${alvo.rotulo} · ${etapa.rotulo}`);
          App.contexto.corporativo = alvo.corporativo;
          App.contexto.negocioId = alvo.negocioId;
          const origem = document.getElementById(`secao-${etapa.chave}`);
          try {
            await etapa.secao().carregar();
            etapas.push({ rotulo: etapa.rotulo, html: this.foto(origem) });
          } catch (e) {
            // Uma etapa que falha não derruba o dossiê: ela entra dizendo o que
            // aconteceu. Sumir com ela deixaria um documento que parece
            // completo e não é — o pior resultado possível numa prestação de
            // contas.
            etapas.push({ rotulo: etapa.rotulo, erro: e.message });
          }
          feitos += 1;
        }
        partes.push({ alvo, etapas });
      }
    } finally {
      App.modoDossie = false;
      Object.assign(App.contexto, anterior.ctx);
      this.aplicarVista(anterior.vista);
      this.montando = false;
    }
    return partes;
  },

  pintarBarra(el, feitos, total, agora) {
    const alvo = el.querySelector('[data-dossie-progresso]');
    if (!alvo) return;
    const pct = total ? Math.round(100 * feitos / total) : 0;
    alvo.innerHTML = `
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="faixa-progresso mini-progresso flex-grow-1"><span style="width:${pct}%"></span></div>
        <span class="small text-muted text-nowrap">${feitos} de ${total}</span>
        <button type="button" class="btn btn-sm btn-outline-danger" data-dossie-cancelar>Cancelar</button>
      </div>
      <div class="small text-muted mt-1">${agora ? `Montando ${Modal.esc(agora)}…` : 'Preparando…'}</div>`;
    alvo.querySelector('[data-dossie-cancelar]')
      ?.addEventListener('click', () => { this.cancelado = true; });
  },

  // ── O documento ─────────────────────────────────────────────────────────
  capa(alvo, etapas, ciclo, ano) {
    return `<div class="dossie-capa">
      <div class="dossie-capa-marca">COPÉRDIA · Planejamento Estratégico</div>
      <h1 class="dossie-capa-titulo">${Modal.esc(alvo.rotulo)}</h1>
      <div class="dossie-capa-sub">Ciclo ${Modal.esc(ciclo ? ciclo.nome : '—')}
        · diagnóstico de ${ano}</div>
      <ol class="dossie-sumario">${etapas.map((e) =>
        `<li>${Modal.esc(e.rotulo)}</li>`).join('')}</ol>
      <div class="dossie-capa-pe">Gerado em ${this.data()} · ${Modal.esc(App.sessao.usuario.nome)}</div>
    </div>`;
  },

  documento(partes, ciclo, ano) {
    return partes.map((p) => `
      <section class="dossie-negocio">
        ${this.capa(p.alvo, p.etapas, ciclo, ano)}
        ${p.etapas.map((e) => `<section class="dossie-etapa">${e.erro
          ? `<h1>${Modal.esc(e.rotulo)}</h1>
             <p class="dossie-falha">Esta etapa não pôde ser carregada: ${Modal.esc(e.erro)}</p>`
          : e.html}</section>`).join('')}
      </section>`).join('');
  },

  // ── A tela ──────────────────────────────────────────────────────────────
  async carregar() {
    const el = document.getElementById(this.secaoId);
    if (!App.contexto.cicloId) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo no menu ☰.</div>';
      return;
    }
    const ciclo = Diag.cicloAtual();
    const alvos = this.alvos();
    if (!alvos.length) {
      el.innerHTML = '<div class="alert alert-info">Nenhum negócio vinculado ao seu acesso.</div>';
      return;
    }
    const atual = this.alvoAtual();
    const anos = [];
    if (ciclo) {
      for (let a = Number(ciclo.ano_base); a <= Number(ciclo.ano_fim); a++) anos.push(a);
    }
    const anoAtual = Diag.ano();

    const caixa = (nome, valor, rotulo, marcado) => `
      <label class="dossie-item">
        <input type="checkbox" class="form-check-input" data-${nome} value="${Modal.esc(valor)}"
          ${marcado ? 'checked' : ''}>
        <span>${Modal.esc(rotulo)}</span>
      </label>`;

    el.innerHTML = `
      <div class="dossie-montagem d-print-none">
        <h1>Dossiê do plano</h1>
        <p class="text-muted">Todas as etapas do plano, em sequência, num documento só —
        para levar impresso à reunião sem juntar as folhas à mão. Escolha o que entra,
        monte e mande imprimir (o navegador salva em PDF pela mesma caixa).</p>

        <div class="row g-3">
          <div class="col-lg-5">
            <div class="dossie-caixa">
              <div class="dossie-caixa-titulo">Negócios
                <button type="button" class="btn btn-sm btn-link p-0" data-dossie-todos="alvo">todos</button>
                <button type="button" class="btn btn-sm btn-link p-0" data-dossie-nenhum="alvo">nenhum</button>
              </div>
              <div class="dossie-lista">${alvos.map((a) =>
                caixa('dossie-alvo', a.chave, a.rotulo, a.chave === atual)).join('')}</div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="dossie-caixa">
              <div class="dossie-caixa-titulo">Etapas
                <button type="button" class="btn btn-sm btn-link p-0" data-dossie-todos="etapa">todas</button>
                <button type="button" class="btn btn-sm btn-link p-0" data-dossie-nenhum="etapa">nenhuma</button>
              </div>
              <div class="dossie-lista">${this.ETAPAS.map((e) =>
                caixa('dossie-etapa', e.chave, e.rotulo, e.padrao)).join('')}</div>
            </div>
          </div>
          <div class="col-lg-2">
            <div class="dossie-caixa">
              <div class="dossie-caixa-titulo">Ano da análise</div>
              <select class="form-select form-select-sm" data-dossie-ano>
                ${anos.map((a) => `<option value="${a}" ${a === anoAtual ? 'selected' : ''}>${a}</option>`).join('')}
              </select>
              <p class="small text-muted mt-2 mb-0">Vale para as etapas anuais do
              diagnóstico. A cascata, os projetos, os investimentos e as metas são
              plurianuais e não dependem dele.</p>
            </div>
          </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap mt-3">
          <button class="btn btn-verde" data-dossie-montar>Montar o dossiê</button>
          <span class="small text-muted" data-dossie-conta></span>
        </div>
        <div class="mt-3" data-dossie-progresso></div>
        <div class="mt-3" data-dossie-resultado></div>
      </div>
      <div class="dossie-doc d-none d-print-block" data-dossie-documento></div>`;

    const contar = () => {
      const n = this.marcados(el, 'dossie-alvo').length * this.marcados(el, 'dossie-etapa').length;
      const aviso = el.querySelector('[data-dossie-conta]');
      aviso.textContent = n
        ? `${n} documento(s)${n > this.AVISO_VOLUME ? ' — é bastante papel; confira a seleção' : ''}`
        : 'Marque ao menos um negócio e uma etapa.';
      aviso.classList.toggle('text-danger', n > this.AVISO_VOLUME);
    };
    el.querySelectorAll('[data-dossie-alvo], [data-dossie-etapa]')
      .forEach((c) => c.addEventListener('change', contar));
    contar();

    const alternar = (nome, valor) => {
      el.querySelectorAll(`[data-dossie-${nome}]`).forEach((c) => { c.checked = valor; });
      contar();
    };
    el.querySelectorAll('[data-dossie-todos]').forEach((b) =>
      b.addEventListener('click', () => alternar(b.dataset.dossieTodos, true)));
    el.querySelectorAll('[data-dossie-nenhum]').forEach((b) =>
      b.addEventListener('click', () => alternar(b.dataset.dossieNenhum, false)));

    el.querySelector('[data-dossie-montar]').addEventListener('click', async () => {
      if (this.montando) return;
      const chavesAlvo = this.marcados(el, 'dossie-alvo');
      const chavesEtapa = this.marcados(el, 'dossie-etapa');
      if (!chavesAlvo.length || !chavesEtapa.length) {
        alert('Marque ao menos um negócio e uma etapa.');
        return;
      }
      const escolha = {
        alvos: alvos.filter((a) => chavesAlvo.includes(a.chave)),
        // Na ordem da TABELA, não na ordem em que as caixas foram marcadas: o
        // documento tem uma ordem de leitura, e ela é a do trabalho.
        etapas: this.ETAPAS.filter((e) => chavesEtapa.includes(e.chave)),
        ano: parseInt(el.querySelector('[data-dossie-ano]').value, 10) || anoAtual,
      };
      const total = escolha.alvos.length * escolha.etapas.length;
      if (total > this.AVISO_VOLUME
        && !confirm(`São ${total} documentos — cada um é uma tela inteira, e montar todos leva `
          + 'um tempo. Continuar?')) return;

      const botao = el.querySelector('[data-dossie-montar]');
      botao.disabled = true;
      const partes = await this.montar(el, escolha).catch((e) => {
        alert(`Não foi possível montar o dossiê: ${e.message}`);
        return null;
      });
      botao.disabled = false;
      el.querySelector('[data-dossie-progresso]').innerHTML = '';
      if (!partes) {
        el.querySelector('[data-dossie-resultado]').innerHTML = this.cancelado
          ? '<div class="alert alert-secondary py-2 mb-0">Montagem cancelada.</div>' : '';
        return;
      }
      this.mostrarResultado(el, partes, ciclo, escolha.ano);
    });

    // Recarregar a seção joga fora o documento montado: ele é a foto de um
    // contexto, e o contexto pode ter mudado no menu desde então. Reconstruí-lo
    // calado seria pior — o botão está a um clique.
    el.querySelector('[data-dossie-documento]').innerHTML = '';
  },

  mostrarResultado(el, partes, ciclo, ano) {
    const falhas = partes.reduce((n, p) => n + p.etapas.filter((e) => e.erro).length, 0);
    el.querySelector('[data-dossie-documento]').innerHTML = this.documento(partes, ciclo, ano);
    el.querySelector('[data-dossie-resultado]').innerHTML = `
      <div class="alert alert-success py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="flex-grow-1">Dossiê montado ·
            ${partes.length} negócio(s), ${partes.reduce((n, p) => n + p.etapas.length, 0)} etapa(s)</strong>
          <button class="btn btn-sm btn-verde" data-dossie-imprimir>⤓ Imprimir · salvar em PDF</button>
        </div>
        <ul class="dossie-resumo small mb-0 mt-2">${partes.map((p) =>
          `<li><strong>${Modal.esc(p.alvo.rotulo)}</strong>: ${p.etapas.map((e) =>
            Modal.esc(e.rotulo) + (e.erro ? ' (falhou)' : '')).join(' · ')}</li>`).join('')}</ul>
        ${falhas ? `<div class="small text-danger mt-2">${falhas} etapa(s) não carregaram e
          entram no documento dizendo isso.</div>` : ''}
        <div class="small text-muted mt-2">O documento não aparece nesta tela — ele é o que sai
        no papel. Use a pré-visualização da caixa de impressão para conferir antes de gerar o PDF.</div>
      </div>`;
    el.querySelector('[data-dossie-imprimir]').addEventListener('click', () => window.print());
  },
};

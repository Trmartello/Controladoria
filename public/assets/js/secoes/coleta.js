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
  SELECIONADO: ['Na matriz', 'text-bg-info'],
  ACEITO: ['Aceita', 'text-bg-success'],
  DESCARTADO: ['Descartada', 'text-bg-secondary'],
  DIVIDIDO: ['Dividida', 'text-bg-light border'],
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

  // ---- Tempestade ao vivo ----
  rodadas: [],
  rodadaAberta: null,
  selecionado: null,   // id da ideia na bancada
  relogio: null,       // consulta periódica enquanto a rodada está aberta
  painelOculto: false, // o QR some depois que a sala entrou

  /** Sem acento e sem caixa, para agrupar quem disse a mesma coisa. */
  norm(s) {
    return String(s || '').toLocaleLowerCase('pt-BR').normalize('NFD')
      .replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
  },

  /**
   * Agrupa as ideias ainda não tratadas por texto equivalente. O peso é
   * quantas pessoas disseram o mesmo — é o que faz a ficha crescer na nuvem.
   */
  nuvem(adiadas = false) {
    const grupos = new Map();
    const rodadaId = this.rodadaAberta ? Number(this.rodadaAberta.id) : null;
    for (const i of this.itens) {
      if (i.situacao !== 'NOVO' && i.situacao !== 'SELECIONADO') continue;
      // A nuvem é da rodada em curso: misturar respostas de rodadas anteriores
      // e ideias avulsas do ano tiraria o sentido do "toque para tratar"
      if (rodadaId !== null && Number(i.rodada_id) !== rodadaId) continue;
      if (!adiadas && Number(i.adiado)) continue;
      if (adiadas && !Number(i.adiado)) continue;
      // O grupo é o que o condutor montou arrastando (ou o automático de texto
      // igual, que o servidor já resolve gravando o mesmo líder)
      const chave = String(i.agrupado_em_id || i.id);
      if (!grupos.has(chave)) grupos.set(chave, { representante: i, itens: [], votos: 0 });
      const g = grupos.get(chave);
      g.itens.push(i);
      g.votos += Number(i.votos || 0);
    }
    // Mais repetidas e mais votadas primeiro: é a leitura que interessa na sala
    return [...grupos.values()].sort((a, b) =>
      (b.itens.length - a.itens.length) || (b.votos - a.votos) || (a.representante.id - b.representante.id));
  },

  pararRelogio() {
    clearInterval(this.relogio);
    this.relogio = null;
  },

  /**
   * Enquanto a rodada está aberta, busca o que chegou. Sem SSE: o servidor
   * embutido do PHP é single-threaded e uma conexão presa travaria todo mundo.
   */
  ligarRelogio(ano) {
    this.pararRelogio();
    this.relogio = setInterval(async () => {
      const secao = document.getElementById('secao-coleta');
      if (!secao || secao.classList.contains('d-none')) return this.pararRelogio();
      // Redesenhar destrói o que está sendo digitado: o modal e a bancada
      // são campos vivos, e a oficina inteira digita neles
      if (document.querySelector('#modal-form.show')) return;
      const foco = document.activeElement;
      if (foco && (foco.tagName === 'TEXTAREA' || foco.tagName === 'INPUT')) return;
      const bancada = document.getElementById('texto-bancada');
      if (bancada && bancada.value !== bancada.defaultValue) return;
      try {
        const antes = JSON.stringify(this.itens.map((i) => [i.id, i.situacao, i.votos]));
        this.itens = await App.api(`/api/coleta?planejamento_id=${this.plan.id}&ano=${ano}`);
        this.rodadas = await App.api(`/api/rodadas?planejamento_id=${this.plan.id}&ano=${ano}`);
        const depois = JSON.stringify(this.itens.map((i) => [i.id, i.situacao, i.votos]));
        if (antes !== depois) this.carregar();
      } catch (e) { /* rede instável na oficina: tenta de novo no próximo ciclo */ }
    }, 3000);
  },

  async carregar() {
    const base = await Diag.preparar('secao-coleta');
    if (!base) return;
    const { el, plan, ano } = base;
    this.plan = plan;
    [this.itens, this.rodadas] = await Promise.all([
      App.api(`/api/coleta?planejamento_id=${plan.id}&ano=${ano}`),
      App.api(`/api/rodadas?planejamento_id=${plan.id}&ano=${ano}`).catch(() => []),
    ]);
    this.rodadaAberta = this.rodadas.find((r) => r.situacao === 'ABERTA') || null;

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

      ${podeTriar ? this.painelTempestade(ano) : ''}
      ${this.rodadaAberta ? this.telaConducao() : ''}

      ${podeTriar && naFila && !this.rodadaAberta ? `<div class="card mb-3 fila-coleta"><div class="card-body py-2 px-3">
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
    this.ligarTempestade(el, ano);
    if (this.rodadaAberta) this.ligarRelogio(ano); else this.pararRelogio();
  },

  // ---- Painel da rodada: PIN, QR e link para projetar ----
  painelTempestade(ano) {
    const r = this.rodadaAberta;
    if (!r) {
      return `<div class="card mb-3 painel-rodada"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="small text-uppercase">Tempestade de ideias</strong>
          <span class="small text-muted flex-grow-1">Abra uma rodada e projete o PIN:
            os participantes entram pelo celular, sem cadastro.</span>
          <button class="btn btn-sm btn-verde" id="btn-abrir-rodada">Abrir tempestade</button>
        </div>
      </div></div>`;
    }
    const url = `${location.origin}/entrar/${r.pin}`;
    // Depois que a sala entrou, o QR só ocupa espaço — some com um toque
    if (this.painelOculto) {
      return `<div class="card mb-3 painel-rodada"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="badge text-bg-light border">PIN ${Modal.esc(r.pin)}</span>
          <span class="badge text-bg-light border">${r.participantes} participante(s)</span>
          <span class="badge text-bg-light border">${r.ideias} ideia(s)</span>
          ${r.votacao === 'ABERTA' ? '<span class="badge text-bg-warning">votação aberta</span>' : ''}
          <span class="small text-muted flex-grow-1 text-truncate">${Modal.esc(r.tema)}</span>
          <button class="btn btn-sm btn-outline-secondary" id="btn-mostrar-painel">Mostrar QR</button>
          <button class="btn btn-sm btn-outline-secondary" id="btn-votacao">
            ${r.votacao === 'ABERTA' ? 'Fechar votação' : 'Abrir votação'}</button>
          <button class="btn btn-sm btn-outline-danger" id="btn-encerrar-rodada">Encerrar</button>
        </div>
      </div></div>`;
    }
    return `<div class="card mb-3 painel-rodada"><div class="card-body py-3 px-3">
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <div class="caixa-qr" id="qr-rodada" aria-hidden="true"></div>
        <div class="flex-grow-1" style="min-width:12rem">
          <div class="rotulo-secao">Entre em ${Modal.esc(location.host)}/entrar</div>
          <div class="pin-grande">${Modal.esc(r.pin)}</div>
          <div class="small text-muted mt-1">${Modal.esc(r.tema)}</div>
          <div class="d-flex gap-2 flex-wrap mt-2">
            <span class="badge text-bg-light border">${r.participantes} participante(s)</span>
            <span class="badge text-bg-light border">${r.ideias} ideia(s)</span>
            ${r.votacao === 'ABERTA' ? '<span class="badge text-bg-warning">votação aberta</span>' : ''}
          </div>
        </div>
        <div class="d-flex flex-column gap-1">
          <button class="btn btn-sm btn-outline-secondary" id="btn-ocultar-painel">Ocultar QR</button>
          <button class="btn btn-sm btn-outline-secondary" data-copiar-link="${Modal.esc(url)}">Copiar link</button>
          <button class="btn btn-sm btn-outline-secondary" id="btn-votacao">
            ${r.votacao === 'ABERTA' ? 'Fechar votação' : 'Abrir votação'}</button>
          <button class="btn btn-sm btn-outline-danger" id="btn-encerrar-rodada">Encerrar</button>
        </div>
      </div>
    </div></div>`;
  },

  /**
   * Uma ideia sozinha vira uma ficha; um grupo vira uma CAIXA com todas as
   * palavras juntadas à vista, para o condutor ver o que foi reunido. Tocar na
   * caixa — ou em qualquer palavra dela — leva o grupo à bancada, onde está o
   * quadrante de prioridade.
   */
  fichaOuCaixa(g, { adiada = false } = {}) {
    const i = g.representante;
    const multi = g.itens.length > 1;
    const desteGrupo = g.itens.some((x) => x.id === this.selecionado);
    const selo = `${multi ? `×${g.itens.length}` : ''}${g.votos ? ` ★${g.votos}` : ''}`.trim();
    // Adiada volta com um toque (data-retomar); ativa seleciona e arrasta
    const acao = adiada
      ? `data-retomar="${i.id}"`
      : `data-selecionar="${i.id}" data-arrastavel="${i.id}"`;

    if (!multi) {
      const rotulo = i.texto_tratado || i.texto;
      const dica = adiada ? 'Trazer de volta para a tempestade'
        : `${Modal.esc(i.autor)} — toque para tratar, arraste sobre outra para juntar`;
      return `<button type="button" class="ficha-nuvem ${adiada ? 'adiada' : ''} ${desteGrupo ? 'selecionada' : ''}"
        style="--peso:1" ${acao} title="${dica}">${Modal.esc(rotulo)}${
        selo ? ` <span class="repetida">${selo}</span>` : ''}</button>`;
    }
    const dica = adiada ? 'Trazer de volta para a tempestade'
      : `Grupo de ${g.itens.length} ideias — toque para tratar, arraste para juntar`;
    // Um ✕ em cada palavra tira só ela do grupo (juntou por engano), sem
    // desfazer o resto. Só na caixa ativa e para quem pode triar.
    const podeTirar = !adiada && App.podeEditar();
    return `<div class="grupo-caixa ${adiada ? 'adiada' : ''} ${desteGrupo ? 'selecionada' : ''}"
      role="button" tabindex="0" ${acao} title="${dica}">
      ${i.texto_tratado ? `<div class="grupo-descricao">${Modal.esc(i.texto_tratado)}</div>` : ''}
      <div class="grupo-palavras">
        ${g.itens.map((w) => `<span class="palavra-grupo">${Modal.esc(w.texto)}${
          podeTirar ? `<button type="button" class="palavra-x" data-remover-palavra="${w.id}"
            title="Tirar do grupo" aria-label="Tirar esta ideia do grupo">×</button>` : ''}</span>`).join('')}
      </div>
      <div class="grupo-rodape">${g.itens.length} ideias juntas${g.votos ? ` · ★ ${g.votos}` : ''}</div>
    </div>`;
  },

  // ---- Tela de condução: nuvem à esquerda, bancada à direita ----
  telaConducao() {
    const grupos = this.nuvem();
    // Busca por conteúdo, e não pelo representante: posicionar na matriz muda
    // a situação da ideia e, com ela, quem representa o grupo — a bancada
    // sumiria no meio da discussão
    const grupoSel = grupos.find((g) => g.itens.some((i) => i.id === this.selecionado));
    const item = grupoSel
      ? (grupoSel.itens.find((i) => i.id === this.selecionado) || grupoSel.representante)
      : null;
    const fichas = grupos.map((g) => this.fichaOuCaixa(g)).join('');

    const adiadas = this.nuvem(true);

    return `<div class="row g-3 mb-3">
      <div class="col-lg-7">
        <div class="card h-100"><div class="card-body py-2 px-3">
          <div class="rotulo-secao">Tempestade — toque para levar à bancada,
            arraste uma sobre a outra para juntar</div>
          <div class="nuvem">${fichas || '<span class="text-muted small">Aguardando as primeiras ideias...</span>'}</div>
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100 bancada"><div class="card-body py-2 px-3">
          <div class="rotulo-secao">Bancada</div>
          ${item ? this.bancada(item, grupoSel) : '<p class="text-muted small mb-0">Escolha uma ideia da tempestade para discutir com o grupo.</p>'}
        </div></div>
      </div>
    </div>
    ${adiadas.length ? `<div class="card mb-3 caixa-depois"><div class="card-body py-2 px-3">
      <div class="rotulo-secao">Tratar depois (${adiadas.length})</div>
      <div class="nuvem">
        ${adiadas.map((g) => this.fichaOuCaixa(g, { adiada: true })).join('')}
      </div>
    </div></div>` : ''}`;
  },

  bancada(item, grupo) {
    const quadrantes = [
      ['ALTO', 'BAIXO', 'Fazer agora', 'muito impacto, pouco esforço', '#007a45'],
      ['ALTO', 'ALTO', 'Planejar', 'muito impacto, muito esforço', '#2c7fb8'],
      ['BAIXO', 'BAIXO', 'Encaixar', 'pouco impacto, pouco esforço', '#b08d4f'],
      ['BAIXO', 'ALTO', 'Descartar', 'pouco impacto, muito esforço', '#8f3b3b'],
    ].map(([imp, esf, titulo, eixos, cor]) => `
      <button type="button" class="quadrante-prio ${item.impacto === imp && item.esforco === esf ? 'escolhido' : ''}"
        style="--cor-quad:${cor}" data-quadrante="${imp}:${esf}" data-item="${item.id}">
        <span class="q-titulo">${titulo}</span>
        <span class="q-eixos">${eixos}</span>
      </button>`).join('');

    // Quando a nuvem agrupou, a bancada trata o grupo inteiro de uma vez
    const ids = (grupo?.itens || [item]).map((i) => i.id);
    return `
      <div class="small text-muted">${Modal.esc(item.autor)}${
        ids.length > 1 ? ` e mais ${ids.length - 1}` : ''}${
        grupo?.votos ? ` · ★ ${grupo.votos} voto(s)` : ''}</div>
      ${ids.length > 1 ? `<div class="small text-muted">Tratar aqui resolve as
        ${ids.length} ideias iguais de uma vez.</div>` : ''}
      <input type="hidden" id="grupo-bancada" value="${ids.join(',')}">
      <textarea class="form-control mt-1" rows="3" id="texto-bancada" maxlength="400"
        aria-label="Texto complementado">${Modal.esc(item.texto_tratado || item.texto)}</textarea>
      <div class="d-flex gap-1 flex-wrap mt-2">
        <button class="btn btn-sm btn-outline-secondary" data-complementar="${item.id}">Salvar texto</button>
        <button class="btn btn-sm btn-outline-secondary" data-dividir="${item.id}">Dividir</button>
        ${ids.length > 1 ? `<button class="btn btn-sm btn-outline-secondary"
          data-desagrupar="${item.id}" title="Separar as ideias deste grupo">Desagrupar</button>` : ''}
        <button class="btn btn-sm btn-outline-secondary" data-adiar="${item.id}">Tratar depois</button>
      </div>

      <div class="rotulo-secao mt-3">Prioridade</div>
      <div class="grade-matriz">${quadrantes}</div>

      <div class="rotulo-secao mt-3">Destino</div>
      <div class="d-flex gap-1 flex-wrap">
        ${DESTINOS_TRIAGEM.map((d) => `
          <button class="btn btn-sm btn-destino" style="--cor-destino:${d.cor}"
            data-encaminhar="${item.id}" data-destino="${d.valor}">${d.rotulo}</button>`).join('')}
        <button class="btn btn-sm btn-outline-danger" data-descartar="${item.id}">Rejeitar</button>
      </div>`;
  },

  /**
   * Arrastar uma ficha sobre a outra junta as duas num grupo.
   *
   * Com eventos de ponteiro (e não a API de arrastar do HTML, que não existe
   * no toque), e ouvindo no `document`: a ficha se move no DOM durante o
   * arraste e listeners presos a ela morreriam no meio do gesto.
   */
  ligarArraste(el) {
    if (!App.podeEditar()) return;
    el.querySelectorAll('[data-arrastavel]').forEach((ficha) => {
      ficha.addEventListener('pointerdown', (ev) => {
        if (ev.button !== undefined && ev.button !== 0) return;
        // O ✕ de tirar uma palavra não inicia arraste
        if (ev.target.closest('[data-remover-palavra]')) return;
        const origem = { x: ev.clientX, y: ev.clientY };
        let arrastando = false;
        let alvoAtual = null;

        const mover = (e) => {
          const dist = Math.hypot(e.clientX - origem.x, e.clientY - origem.y);
          // Abaixo de 8px ainda é toque, não arraste
          if (!arrastando && dist < 8) return;
          if (!arrastando) {
            arrastando = true;
            ficha.classList.add('arrastando');
          }
          e.preventDefault();
          const sob = document.elementFromPoint(e.clientX, e.clientY);
          const alvo = sob?.closest('[data-arrastavel]');
          if (alvoAtual && alvoAtual !== alvo) alvoAtual.classList.remove('alvo-juntar');
          alvoAtual = alvo && alvo !== ficha ? alvo : null;
          if (alvoAtual) alvoAtual.classList.add('alvo-juntar');
        };

        const soltar = async (e) => {
          document.removeEventListener('pointermove', mover);
          document.removeEventListener('pointerup', soltar);
          document.removeEventListener('pointercancel', soltar);
          ficha.classList.remove('arrastando');
          alvoAtual?.classList.remove('alvo-juntar');
          if (!arrastando || !alvoAtual) return;
          ficha.dataset.arrastou = '1';
          const alvo = Number(alvoAtual.dataset.arrastavel);
          try {
            await App.api(`/api/coleta/${ficha.dataset.arrastavel}/agrupar`,
              { planejamento_id: this.plan.id, alvo });
            this.selecionado = alvo;
          } catch (erro) {
            alert(erro.message);
          }
          this.carregar();
        };

        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar);
        document.addEventListener('pointercancel', soltar);
      });
    });
  },

  /** Ids que a nuvem agrupou por texto equivalente, para tratar de uma vez. */
  grupoAtual(item) {
    const g = this.nuvem().find((x) => x.itens.some((i) => i.id === item.id));
    return (g?.itens || [item]).map((i) => i.id);
  },

  ligarTempestade(el, ano) {
    // O QR é desenhado por biblioteca vendorada (MIT); sem ela, o PIN basta
    const caixa = el.querySelector('#qr-rodada');
    if (caixa && this.rodadaAberta && typeof qrcode === 'function') {
      try {
        const q = qrcode(0, 'M');
        q.addData(`${location.origin}/entrar/${this.rodadaAberta.pin}`);
        q.make();
        caixa.innerHTML = q.createSvgTag({ cellSize: 4, margin: 1, scalable: true });
      } catch (e) {
        caixa.remove();
      }
    } else if (caixa) {
      caixa.remove();
    }

    document.getElementById('btn-ocultar-painel')?.addEventListener('click', () => {
      this.painelOculto = true;
      this.carregar();
    });
    document.getElementById('btn-mostrar-painel')?.addEventListener('click', () => {
      this.painelOculto = false;
      this.carregar();
    });

    el.querySelectorAll('[data-copiar-link]').forEach((b) => b.addEventListener('click', async () => {
      const url = b.dataset.copiarLink;
      try {
        await navigator.clipboard.writeText(url);
        b.textContent = 'Link copiado';
        setTimeout(() => { b.textContent = 'Copiar link'; }, 1800);
      } catch (e) {
        prompt('Copie o link da rodada:', url);
      }
    }));

    el.querySelectorAll('[data-selecionar]').forEach((b) => {
      const escolher = () => {
        // Um arraste que terminou em cima de outra ficha não é um toque
        if (b.dataset.arrastou === '1') {
          delete b.dataset.arrastou;
          return;
        }
        const id = Number(b.dataset.selecionar);
        this.selecionado = this.selecionado === id ? null : id;
        this.carregar();
      };
      b.addEventListener('click', escolher);
      // A caixa de grupo é um <div role="button">: precisa do teclado na mão
      if (b.getAttribute('role') === 'button') {
        b.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); escolher(); }
        });
      }
    });

    el.querySelectorAll('[data-retomar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/coleta/${b.dataset.retomar}/adiar`,
          { planejamento_id: this.plan.id, adiado: false });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    // Tirar uma palavra do grupo (o clique não pode selecionar nem arrastar a caixa)
    el.querySelectorAll('[data-remover-palavra]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      try {
        const r = await App.api(`/api/coleta/${b.dataset.removerPalavra}/remover-grupo`,
          { planejamento_id: this.plan.id });
        // Mantém o foco no grupo que sobrou (o líder restante)
        this.selecionado = r.lider || null;
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    this.ligarArraste(el);

    if (!App.podeEditar()) return;

    document.getElementById('btn-abrir-rodada')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Abrir tempestade de ideias',
      url: '/api/rodadas',
      valores: { planejamento_id: this.plan.id, ano, max_ideias: 5, max_votos: 3 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        { nome: 'tema', rotulo: 'A pergunta que abre a tempestade', tipo: 'text', obrigatorio: true,
          exemplo: 'O que pode atrapalhar o nosso resultado nos próximos três anos?' },
        { nome: 'max_ideias', rotulo: 'Ideias por participante', tipo: 'number', padrao: 5,
          ajuda: 'Um teto evita que uma pessoa domine a tempestade.' },
        { nome: 'max_votos', rotulo: 'Votos por participante', tipo: 'number', padrao: 3,
          ajuda: 'Só vale se você abrir a fase de votação depois.' },
      ],
      aoSalvar: () => this.carregar(),
    }));

    document.getElementById('btn-encerrar-rodada')?.addEventListener('click', async () => {
      if (!confirm('Encerrar a rodada? Os participantes não conseguem mais enviar ideias.')) return;
      try {
        await App.api(`/api/rodadas/${this.rodadaAberta.id}/encerrar`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    });

    document.getElementById('btn-limpar-rodada')?.addEventListener('click', async () => {
      if (!confirm('Apagar as ideias desta rodada que ainda não foram tratadas?')) return;
      try {
        const r = await App.api(`/api/coleta/rodada/${this.rodadaAberta.id}/limpar`,
          { planejamento_id: this.plan.id });
        alert(`${r.removidas} ideia(s) removida(s).`);
      } catch (e) {
        alert(e.message);
      }
      this.selecionado = null;
      this.carregar();
    });

    document.getElementById('btn-votacao')?.addEventListener('click', async () => {
      try {
        await App.api(`/api/rodadas/${this.rodadaAberta.id}/votacao`, {
          planejamento_id: this.plan.id, abrir: this.rodadaAberta.votacao !== 'ABERTA',
        });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    });

    el.querySelectorAll('[data-quadrante]').forEach((b) => b.addEventListener('click', async () => {
      const [impacto, esforco] = b.dataset.quadrante.split(':');
      try {
        await App.api(`/api/coleta/${b.dataset.item}/priorizar`, {
          planejamento_id: this.plan.id, impacto, esforco,
        });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    el.querySelectorAll('[data-complementar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/coleta/${b.dataset.complementar}/complementar`, {
          planejamento_id: this.plan.id,
          texto_tratado: el.querySelector('#texto-bancada').value,
        });
        b.textContent = 'Texto salvo';
        setTimeout(() => { b.textContent = 'Salvar texto'; }, 1500);
        this.itens.find((i) => i.id == b.dataset.complementar).texto_tratado =
          el.querySelector('#texto-bancada').value;
      } catch (e) {
        alert(e.message);
      }
    }));

    el.querySelectorAll('[data-dividir]').forEach((b) => b.addEventListener('click', () =>
      this.modalDividir(this.itens.find((i) => i.id == b.dataset.dividir))));

    el.querySelectorAll('[data-desagrupar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/coleta/${b.dataset.desagrupar}/desagrupar`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    el.querySelectorAll('[data-adiar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/coleta/${b.dataset.adiar}/adiar`,
          { planejamento_id: this.plan.id, adiado: true });
      } catch (e) {
        alert(e.message);
      }
      this.selecionado = null;
      this.carregar();
    }));
  },

  /** Quebra um despejo em várias ideias, guardando o vínculo com a original. */
  modalDividir(item) {
    Modal.abrir({
      titulo: 'Dividir em várias ideias',
      url: `/api/coleta/${item.id}/dividir`,
      valores: { planejamento_id: this.plan.id, p1: item.texto, p2: '', p3: '', p4: '' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'original', rotulo: 'Ideia original', tipo: 'info', texto: item.texto,
          barra: { cor: '#b08d4f', titulo: item.autor } },
        { nome: 'p1', rotulo: 'Parte 1', tipo: 'textarea', linhas: 2 },
        { nome: 'p2', rotulo: 'Parte 2', tipo: 'textarea', linhas: 2 },
        { nome: 'p3', rotulo: 'Parte 3 (opcional)', tipo: 'textarea', linhas: 2 },
        { nome: 'p4', rotulo: 'Parte 4 (opcional)', tipo: 'textarea', linhas: 2 },
      ],
      transformar: (d) => ({
        planejamento_id: d.planejamento_id,
        partes: [d.p1, d.p2, d.p3, d.p4].filter((t) => String(t || '').trim() !== ''),
      }),
      aoSalvar: () => {
        this.selecionado = null;
        this.carregar();
      },
    });
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
            barra: { cor: '#8f3b3b', titulo: item.autor, origem: this.data(item.criado_em) } },
          { nome: 'motivo', rotulo: 'Por que não entra?', tipo: 'textarea', linhas: 3, obrigatorio: true,
            ajuda: 'O autor vê este motivo. É o que transforma um veto silencioso em aprendizado.' },
        ],
        aoSalvar: () => {
          this.selecionado = null;
          this.carregar();
        },
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
        barra: { cor: '#007a45', titulo: item.autor, origem: this.data(item.criado_em) } },
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
      valores: {
        planejamento_id: this.plan.id, destino,
        texto_tratado: item.texto_tratado || item.texto,
      },
      campos,
      aoSalvar: () => {
        this.selecionado = null;
        this.carregar();
      },
    });
  },
};

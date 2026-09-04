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
  // Não é análise de diagnóstico: a ideia fica pendente para virar ação num plano
  { valor: 'ACAO', rotulo: 'Plano de ação', cor: '#5a3e2b' },
];

// Quadrantes da matriz impacto×esforço — fonte única de rótulo, cor e a ordem
// de prioridade da fila. "Descartar" (baixo impacto, alto esforço) é a posição
// que a matriz usa para decidir esquecer a ideia.
const QUADRANTES = {
  'ALTO:BAIXO': { titulo: 'Fazer agora', cor: '#007a45', rank: 0 },
  'ALTO:ALTO': { titulo: 'Planejar', cor: '#2c7fb8', rank: 1 },
  'BAIXO:BAIXO': { titulo: 'Encaixar', cor: '#b08d4f', rank: 2 },
  'BAIXO:ALTO': { titulo: 'Descartar', cor: '#8f3b3b', rank: 3 },
};

// Menu de encaminhamento da pílula, na hierarquia pedida pelo cliente:
// Encaminhar para → Cenário · Framework (SWOT/PESTEL/Porter) · Resultados
const MENU_DESTINOS = [
  { grupo: '', itens: [{ valor: 'CENARIO', rotulo: 'Cenário' }] },
  { grupo: 'Framework', itens: [
    { valor: 'SWOT', rotulo: 'SWOT' },
    { valor: 'PESTEL', rotulo: 'PESTEL' },
    { valor: 'PORTER', rotulo: 'Porter' },
  ] },
  { grupo: 'Resultados', itens: [{ valor: 'ACAO', rotulo: 'Plano de ação' }] },
];

const SITUACOES = {
  NOVO: ['A tratar', 'text-bg-warning'],
  SELECIONADO: ['Na matriz', 'text-bg-info'],
  ACEITO: ['Aceita', 'text-bg-success'],
  DESCARTADO: ['Descartada', 'text-bg-secondary'],
  DIVIDIDO: ['Dividida', 'text-bg-light border'],
};

const SecaoColeta = {
  /**
   * Fora do relógio compartilhado: a Coleta tem o seu, com as regras da oficina
   * (rodada aberta, voto, ficha sendo arrastada). Dois relógios na mesma tela
   * repintariam um por cima do outro, e o desta seção é o que sabe não tirar a
   * ficha da mão de quem a segura.
   */
  planosVigiados() { return []; },

  plan: null,
  itens: [],
  filtro: 'NOVO',
  // "Pular" vale só para esta sessão de triagem: a fila recarrega do servidor
  // a cada ação, então a escolha precisa morar aqui e não na ordem da lista
  pulados: new Set(),
  // Ideia PUXADA da lista pelo "Tratar" (pedido do cliente, 2026-09-02): a
  // fila segue a ordem de chegada por padrão, mas quem triagem nem sempre quer
  // seguir a ordem — a ideia mais urgente pode ser a última. O foco vale até
  // ela ser tratada, pulada ou sumir de "A tratar"; depois a fila volta à
  // ordem. Mora aqui pelo mesmo motivo do `pulados`.
  foco: null,
  atualDaFila: null, // a ideia que a fila está mostrando neste desenho

  /**
   * Próxima da fila: a puxada pelo "Tratar", se ainda está por tratar; senão
   * a mais antiga ainda não tratada que ninguém pulou.
   */
  proximaDaFila() {
    const novas = this.itens.filter((i) => i.situacao === 'NOVO');
    const focada = novas.find((i) => i.id === this.foco);
    if (focada) return focada;
    this.foco = null;
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
  arrastando: false,   // arraste em curso: o polling não redesenha por cima
  // Caixa-mãe com as palavras à mostra ("ver mais"). Uma por vez, para a nuvem
  // projetada não voltar a inchar. Mora aqui, e não no DOM, porque o relógio de
  // 3 s reescreve o HTML inteiro e fecharia a caixa no meio da oficina.
  caixaAberta: null,
  // "Tratar depois" nasce recolhido: o que importa na projeção é a tempestade;
  // a contagem no rótulo já diz quanta coisa está guardada ali
  depoisAberto: false,
  classificando: false, // trava de toque duplo no quadrante da bancada
  menuDestino: null,    // id da pílula com o menu "Encaminhar para" aberto
  reclassificando: null, // id da ideia reaberta do diagnóstico, à espera do novo destino
  reclassificarRotulo: '', // de onde a ideia saiu (ex.: "Porter"), só para exibir

  /** Sem acento e sem caixa, para agrupar quem disse a mesma coisa. */
  norm(s) {
    return String(s || '').toLocaleLowerCase('pt-BR').normalize('NFD')
      .replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
  },

  /**
   * Agrupa as ideias ainda não tratadas por texto equivalente. O peso é
   * quantas pessoas disseram o mesmo — é o que faz a ficha crescer na nuvem.
   */
  montarGrupos(adiadas = false) {
    const grupos = new Map();
    const rodadaId = this.rodadaAberta ? Number(this.rodadaAberta.id) : null;
    for (const i of this.itens) {
      // ACEITO entra: a ideia encaminhada CONTINUA na matriz, com a tag do
      // destino — sair de vista ao encaminhar apagava o retrato da sala
      if (!['NOVO', 'SELECIONADO', 'ACEITO'].includes(i.situacao)) continue;
      // A nuvem é da rodada em curso: misturar respostas de rodadas anteriores
      // e ideias avulsas do ano tiraria o sentido do "toque para tratar"
      if (rodadaId !== null && Number(i.rodada_id) !== rodadaId) continue;
      if (!adiadas && Number(i.adiado)) continue;
      if (adiadas && !Number(i.adiado)) continue;
      // O grupo é o que o condutor montou arrastando (ou o automático de texto
      // igual, que o servidor já resolve gravando o mesmo líder)
      const chave = String(i.agrupado_em_id || i.id);
      // A chave do agrupamento é a única estável: o representante muda quando a
      // situação muda, e é ela que identifica a caixa aberta entre redesenhos
      if (!grupos.has(chave)) grupos.set(chave, { chave, representante: i, itens: [], votos: 0 });
      const g = grupos.get(chave);
      g.itens.push(i);
      g.votos += Number(i.votos || 0);
    }
    return [...grupos.values()];
  },

  /** O líder do grupo — quem não aponta para ninguém. */
  liderDe(g) {
    return g.itens.find((x) => !x.agrupado_em_id) || g.representante;
  },

  /** Já encaminhada para o diagnóstico/plano de ação? */
  encaminhado(g) {
    return this.liderDe(g).situacao === 'ACEITO';
  },

  /**
   * Rótulo do destino, para a tag na pílula. `destino_tipo` só diz FATOR —
   * quem sabe se virou PESTEL, Porter ou SWOT é a etapa, que o listar()
   * traz por JOIN.
   */
  rotuloDestino(i) {
    if (i.situacao !== 'ACEITO') return '';
    if (i.destino_tipo === 'CENARIO') return 'Cenário';
    if (i.destino_tipo === 'ACAO') return i.destino_id ? 'Plano de ação' : 'Plano de ação · aguardando';
    if (i.destino_tipo === 'FATOR') {
      return { PESTEL: 'PESTEL', PORTER: 'Porter', SWOT: 'SWOT' }[i.destino_etapa] || 'Diagnóstico';
    }
    return '';
  },

  /**
   * Por que o servidor vai recusar apagar esta ideia — ou `null` se não vai.
   *
   * A guarda de `ColetaController::excluir` olha a CAIXA inteira, não a ideia:
   * excluir leva o grupo junto, e basta uma das vozes já ter virado ação num
   * projeto para a exclusão ser recusada. Por isso a trava é indexada pela
   * chave de agrupamento (`agrupado_em_id || id`), a mesma de `montarGrupos`.
   *
   * O mapa é montado numa passada só, a cada carga da lista: perguntar por
   * cartão faria uma varredura por botão numa tela que desenha dezenas.
   */
  mapearTravas() {
    this.travas = new Map();
    for (const i of this.itens) {
      if (i.situacao === 'ACEITO' && i.destino_tipo === 'ACAO') {
        this.travas.set(String(i.agrupado_em_id || i.id), true);
      }
    }
  },

  travaDaIdeia(item) {
    if (!item || !this.travas?.get(String(item.agrupado_em_id || item.id))) return null;
    return 'Esta ideia já virou ação num projeto. Exclua a ação em Projetos antes '
      + '— apagar por aqui deixaria a ação no plano sem origem nenhuma.';
  },

  /**
   * O × (ou o "Excluir") da ideia, desabilitado quando o servidor vai recusar.
   * `attrs` é o que muda entre os três lugares que desenham este botão.
   */
  botaoExcluirIdeia(item, classe, conteudo, attrs = '') {
    const trava = this.travaDaIdeia(item);
    return `<button type="button" class="${classe}" ${attrs} ${trava
      ? Vinculos.travado(trava)
      : `data-excluir-ideia="${item.id}"`}>${conteudo}</button>`;
  },

  /** Quadrante do grupo, quando classificado (a classificação vale para todos). */
  quadranteDe(g) {
    // Pelo LÍDER, não pelo representante: uma voz nova pode entrar no grupo já
    // classificado (o agrupamento automático por texto continua valendo) e o
    // representante é instável — o líder mantém a caixa no quadrante
    const lider = g.itens.find((x) => !x.agrupado_em_id) || g.representante;
    return QUADRANTES[`${lider.impacto}:${lider.esforco}`] || null;
  },

  /**
   * A fila mostra só o que AINDA NÃO foi classificado: quem ganhou
   * quadrante migra para o painel de prioridade. Mais repetidas e mais
   * votadas primeiro — é a leitura que interessa na sala.
   */
  nuvem(adiadas = false) {
    return this.montarGrupos(adiadas)
      // Fora da fila fica só o que está na matriz (tem quadrante). A ideia
      // tirada do quadrante volta para cá — inclusive a já encaminhada, que
      // volta com a tag do destino, para ninguém achar que está por tratar.
      .filter((g) => adiadas || !this.quadranteDe(g))
      .sort((a, b) =>
        (b.itens.length - a.itens.length) || (b.votos - a.votos) || (a.representante.id - b.representante.id));
  },

  /** Grupos já classificados: são as fichas do painel de prioridade. */
  priorizadas() {
    return this.montarGrupos(false)
      .filter((g) => this.quadranteDe(g))
      .sort((a, b) =>
        (b.votos - a.votos) || (b.itens.length - a.itens.length) || (a.representante.id - b.representante.id));
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
      // Arraste em curso: redesenhar arrancaria a ficha/caixa de baixo do dedo
      // (no toque o gesto leva 1-2s e passaria por cima de um ciclo do polling)
      if (this.arrastando) return;
      try {
        // impacto/esforço entram no retrato: no quadrante "Descartar" a ideia
        // segue NOVO, e sem eles a classificação não redesenharia os outros
        // telões da sala
        // texto_tratado e agrupado_em_id também entram: com dois condutores (ou
        // um telão e um notebook), juntar duas fichas ou salvar o texto na
        // bancada não redesenhava a outra tela — ela seguia mostrando as fichas
        // separadas e o texto velho até alguma outra mudança disparar o desenho
        const retrato = (l) => JSON.stringify(l.map((i) => [i.id, i.situacao, i.votos,
          i.impacto, i.esforco, i.adiado, i.destino_tipo, i.destino_id,
          i.texto_tratado, i.agrupado_em_id]));
        // A RODADA também entra no retrato: a fase da sala (recolhendo × ★), a
        // pergunta e o contador de participantes mudam sem que nenhuma ideia
        // mude — e o painel do outro condutor (ou o telão) seguia mostrando
        // "sala aberta" com a sala já fechada, com o botão errado no meio da
        // oficina.
        const retratoRodada = (l) => {
          const a = l.find((x) => x.situacao === 'ABERTA');
          return a ? JSON.stringify([a.id, a.tema, a.votacao, a.participantes, a.ideias]) : 'sem-rodada';
        };
        const antes = retrato(this.itens);
        const antesRodada = retratoRodada(this.rodadas);
        this.itens = await App.api(`/api/coleta?planejamento_id=${this.plan.id}&ano=${ano}`);
        this.mapearTravas();
        this.rodadas = (await App.api(`/api/rodadas?planejamento_id=${this.plan.id}&ano=${ano}`))
          .filter((r) => r.modo !== 'QUIZ');
        if (antes !== retrato(this.itens) || antesRodada !== retratoRodada(this.rodadas)) {
          this.carregar();
        }
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
    // Sessão do quiz da cascata fica FORA desta tela: é outra sala, com
    // outro rito (a condução mora na seção Cascata de Escolhas)
    this.rodadas = this.rodadas.filter((r) => r.modo !== 'QUIZ');
    this.rodadaAberta = this.rodadas.find((r) => r.situacao === 'ABERTA') || null;
    this.prepararReclassificacao();
    // Antes de qualquer desenho: os três lugares que mostram o botão de excluir
    // consultam este mapa para saber se o servidor vai recusar.
    this.mapearTravas();

    const conta = (s) => this.itens.filter((i) => i.situacao === s).length;
    const naFila = conta('NOVO');
    const visiveis = this.itens.filter((i) => i.situacao === this.filtro);
    const podeTriar = App.podeEditar();
    // Resolvida uma vez por desenho: a fila mostra esta, e o cartão dela na
    // lista ganha o selo "na fila" em vez do botão "Tratar".
    this.atualDaFila = podeTriar && naFila && !this.rodadaAberta ? this.proximaDaFila() : null;

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Coleta de Ideias — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          ${Diag.seletorAno()}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-nova-ideia">+ Nova ideia</button>' : ''}
        </div>
      </div>

      ${podeTriar ? this.painelReclassificar() : ''}
      ${podeTriar ? this.painelTempestade(ano) : ''}
      ${this.rodadaAberta ? this.painelPrioridade() : ''}
      ${this.rodadaAberta ? this.telaConducao() : ''}

      ${podeTriar && naFila && !this.rodadaAberta ? `<div class="card mb-3 fila-coleta"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="small text-uppercase">Fila de tratativa</strong>
          <span class="badge text-bg-warning">${naFila} a tratar</span>
          <span class="small text-muted flex-grow-1">${this.foco
            ? 'Puxada da lista — depois dela a fila volta à ordem de chegada.'
            : 'Uma ideia por vez, na ordem em que chegaram — ou toque em “Tratar” numa ideia da lista.'}</span>
        </div>
        ${this.cartaoFila(this.atualDaFila)}
      </div></div>` : ''}

      ${this.rodadaAberta ? '' : `
      <div class="btn-group btn-group-sm mb-3 filtro-coleta" role="group" aria-label="Situação">
        ${Object.entries(SITUACOES).map(([s, [rotulo]]) => `
          <button type="button" class="btn ${s === this.filtro ? 'btn-verde' : 'btn-outline-secondary'}"
            data-filtro="${s}">${rotulo} (${conta(s)})</button>`).join('')}
      </div>

      <div class="lista-ideias">
        ${visiveis.map((i) => this.cartaoIdeia(i)).join('')
          || '<div class="text-muted small">Nenhuma ideia nesta situação.</div>'}
      </div>`}`;

    Diag.ligarSeletorAno(el);
    Diag.ligarVerMais(el);
    this.destacarVindoDoDiagnostico(el);
    this.ligarEventos(el, ano);
    this.ligarTempestade(el, ano);
    if (this.rodadaAberta) this.ligarRelogio(ano); else this.pararRelogio();
  },

  /**
   * A sala (PIN, QR, link, pergunta, votação, encerrar) mora na aba
   * **Sala · PIN e QR code** — os dois ritos no mesmo lugar. Aqui fica só a
   * linha que diz em que estado a tela está e leva até lá: sem ela, a Coleta
   * mudava de comportamento (nuvem ao vivo em vez de fila) sem dizer por quê.
   */
  painelTempestade() {
    const r = this.rodadaAberta;
    if (!r) {
      return `<div class="card mb-3 painel-rodada"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="small text-uppercase">Tempestade de ideias</strong>
          <span class="small text-muted flex-grow-1">Nenhuma rodada aberta. O PIN e o QR
            para projetar ficam na aba Sala.</span>
          <button class="btn btn-sm btn-verde" data-ir-sala>Ir para a Sala</button>
        </div>
      </div></div>`;
    }
    // O ✎ da pergunta mora AQUI, e não na tela de projeção: é nesta tela que
    // quem conduz está — vendo as ideias chegarem — e é lendo as respostas que
    // se percebe que a pergunta precisa ser reformulada. Trocar o texto não
    // mexe no PIN nem no QR, então não há motivo para atravessar o sistema.
    // Só com alguém conectado: antes disso a pergunta se ajusta no formulário
    // que abre a rodada, e um botão a mais na tela vazia é ruído.
    //
    // O mesmo vale para FECHAR A SALA: é o gesto que troca a fase do encontro —
    // o celular para de escrever e passa a escolher com ★ — e ele pertence a
    // quem está aqui vendo a nuvem, não à tela de projeção. Sem ninguém
    // conectado não há fase nenhuma para trocar.
    const temSala = Number(r.participantes) > 0;
    const podeConduzir = temSala && App.podeEditar();
    const votando = r.votacao === 'ABERTA';
    const editar = podeConduzir
      ? '<button class="btn btn-sm btn-outline-secondary" data-editar-pergunta>✎ Editar pergunta</button>'
      : '';
    // O selo diz a FASE, não o estado de uma chave: "votação aberta" não
    // contava que era ele quem tinha tirado o campo de escrever dos celulares.
    const fase = votando
      ? '<span class="badge text-bg-warning">sala fechada · escolhendo com ★</span>'
      : '<span class="badge text-bg-light border">sala aberta · recolhendo ideias</span>';
    const botaoFase = podeConduzir
      ? `<button class="btn btn-sm ${votando ? 'btn-verde' : 'btn-outline-secondary'}"
          data-fase-sala="${votando ? 'abrir' : 'fechar'}">${
        votando ? 'Reabrir a sala' : 'Fechar a sala'}</button>`
      : '';
    // No questionário as ★ já estão liberadas para quem concluiu: fechar a
    // sala ali só tira o campo de escrever.
    const questionario = (r.perguntas || []).length > 0;
    const dicaFase = podeConduzir
      ? `<div class="small text-muted mt-1">${votando
        ? 'A sala está escolhendo as mais importantes com ★. Reabrir devolve o campo de escrever aos celulares.'
        : questionario
          ? 'Quem conclui o questionário já dá as ★ nas respostas de maior impacto. Fechar a sala só tira o campo de escrever dos celulares.'
          : 'Fechar a sala tira o campo de escrever dos celulares e abre as ★ para a sala eleger as ideias mais importantes.'
      }</div>`
      : '';
    // O QUESTIONÁRIO: um filtro por pergunta na nuvem. As ideias de cinco
    // perguntas misturadas na mesma fila viravam um vaivém entre assuntos; com
    // o filtro, o condutor trata uma pergunta por vez, na ordem do encontro.
    // A caixa é PRÓPRIA, não um <select> (pedido do cliente, 2026-09-04): o
    // select nativo corta a pergunta numa linha só, e a caixa precisa crescer
    // até a pergunta inteira caber — na escolha e na lista.
    const perguntas = r.perguntas || [];
    const escolhida = perguntas.find((q) => String(q.id) === String(this.filtroPergunta));
    const aberto = !!this.comboPerguntaAberto;
    // É um MENU de botões, não um listbox: sem setas/Home/End, `role=option`
    // prometia ao leitor de tela um comportamento que não existe.
    const opcao = (valor, atual, html) => `
      <button type="button" class="cp-opcao ${atual ? 'atual' : ''}"
        aria-pressed="${atual}" data-filtro-opcao="${valor}">${html}</button>`;
    const filtroPerguntas = perguntas.length ? `
      <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
        <span class="rotulo-secao mb-0">Questionário · ${perguntas.length} pergunta(s)${
          r.prazo ? ` · até ${this.dataHora(r.prazo)}` : ''}</span>
        <div class="combo-pergunta ${aberto ? 'aberto' : ''}" data-combo-pergunta data-filtro-pergunta>
          <button type="button" class="cp-atual" data-combo-alternar aria-haspopup="true"
            aria-expanded="${aberto}" aria-label="Mostrar as ideias de uma pergunta">
            <span class="cp-texto">${escolhida
              ? `<span class="fp-tag selo-pergunta">P${escolhida.ordem}</span> ${Modal.esc(escolhida.enunciado)}`
              : 'Todas as perguntas'}</span>
            <span class="cp-seta" aria-hidden="true">▾</span>
          </button>
          <div class="cp-lista" ${aberto ? '' : 'hidden'}>
            ${opcao('', !escolhida, 'Todas as perguntas')}
            ${perguntas.map((q) => opcao(q.id, escolhida === q,
              `<span class="fp-tag selo-pergunta">P${q.ordem}</span><span class="cp-enunciado">${
                Modal.esc(q.enunciado)}</span><span class="cp-conta">${q.ideias}</span>`)).join('')}
          </div>
        </div>
      </div>` : '';
    return `<div class="card mb-3 painel-rodada"><div class="card-body py-2 px-3">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <strong class="small text-uppercase">Tempestade aberta</strong>
        <span class="badge text-bg-light border">${r.participantes} participante(s)</span>
        <span class="badge text-bg-light border">${r.ideias} ideia(s)</span>
        ${fase}
        <span class="small text-muted flex-grow-1"></span>
        ${botaoFase}
        <button class="btn btn-sm btn-outline-secondary" data-ir-sala>PIN e QR na Sala</button>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
        <span class="rotulo-secao mb-0">${perguntas.length ? 'Tema do questionário' : 'Pergunta da sala'}</span>
        <span class="small flex-grow-1">${Modal.esc(r.tema)}</span>
        ${editar}
      </div>
      ${filtroPerguntas}
      ${dicaFase}
    </div></div>`;
  },

  /** dd/mm/aaaa hh:mm a partir do DATETIME do banco — o prazo do questionário. */
  dataHora(iso) {
    if (!iso) return '';
    const [data, hora] = String(iso).split(' ');
    return `${data.split('-').reverse().join('/')}${hora ? ` ${hora.slice(0, 5)}` : ''}`;
  },

  /** O grupo passa pelo filtro de pergunta do questionário? Sem filtro, tudo passa. */
  passaFiltroPergunta(g) {
    if (!this.filtroPergunta) return true;
    return g.itens.some((i) => String(i.pergunta_id) === String(this.filtroPergunta));
  },

  /** A etiqueta "P2" da ideia que respondeu a uma pergunta do questionário. */
  seloPergunta(i) {
    return i.pergunta_ordem
      ? `<span class="fp-tag selo-pergunta" title="Pergunta ${i.pergunta_ordem}: ${
        Modal.esc(i.pergunta_enunciado || '')}">P${i.pergunta_ordem}</span> `
      : '';
  },

  /**
   * Uma ideia sozinha vira uma ficha; um grupo vira uma CAIXA-MÃE: a ideia que
   * RECEBEU o arraste é o líder e dá o TÍTULO da caixa (editável na bancada,
   * via texto tratado); as arrastadas ficam dentro, como conteúdo. Tocar na
   * caixa — ou em qualquer palavra dela — leva o grupo INTEIRO à bancada: a
   * tratativa é sempre da caixa como um todo, nunca das filhas em separado.
   */
  fichaOuCaixa(g, { adiada = false } = {}) {
    const i = g.representante;
    const multi = g.itens.length > 1;
    // O líder é quem não aponta para ninguém (as filhas apontam para ele)
    const lider = g.itens.find((x) => !x.agrupado_em_id) || i;
    const desteGrupo = g.itens.some((x) => x.id === this.selecionado);
    const selo = `${multi ? `×${g.itens.length}` : ''}${g.votos ? ` ★${g.votos}` : ''}`.trim();
    // Sem selo de quadrante aqui: quem foi classificada nem aparece mais na
    // tempestade — ela migra para o painel de prioridade, que é quem conta essa
    // história agora.
    // Adiada volta com um toque (data-retomar); ativa seleciona e arrasta.
    // No grupo, o id é o do líder: selecionar e arrastar valem para a caixa toda.
    const acao = adiada
      ? `data-retomar="${lider.id}"`
      : `data-selecionar="${lider.id}" data-arrastavel="${lider.id}"`;

    if (!multi) {
      const rotulo = i.texto_tratado || i.texto;
      const dica = adiada ? 'Trazer de volta para a fila'
        : `${Modal.esc(i.autor)} — toque para tratar, arraste sobre outra para juntar`;
      // A encaminhada que voltou do quadrante carrega a etiqueta do destino:
      // sem ela pareceria uma ideia por tratar, e alguém a encaminharia de novo
      const destinoFicha = this.rotuloDestino(lider);
      return `<button type="button" class="ficha-nuvem ${adiada ? 'adiada' : ''} ${desteGrupo ? 'selecionada' : ''}"
        style="--peso:1" ${acao} title="${dica}">${this.seloPergunta(lider)}${Modal.esc(rotulo)}${
        selo ? ` <span class="repetida">${selo}</span>` : ''}${
        destinoFicha ? ` <span class="fp-tag">${Modal.esc(destinoFicha)}</span>` : ''}</button>`;
    }
    const titulo = lider.texto_tratado || lider.texto;
    const filhas = g.itens.filter((x) => x !== lider);
    const dica = adiada ? 'Trazer de volta para a fila'
      : `Caixa com ${g.itens.length} ideias — toque para tratar tudo junto, arraste para juntar a outra`;
    // Um ✕ em cada palavra FILHA tira só ela do grupo (juntou por engano), sem
    // desfazer o resto; o título (líder) não tem ✕ — para desfazer a caixa há o
    // Desagrupar na bancada. Só na caixa ativa e para quem pode triar.
    const podeTirar = !adiada && App.podeEditar();
    // A caixa nasce COMPACTA: como todas as palavras tratam da mesma coisa, o
    // que a sala precisa ver é o título. A contagem do rodapé é o próprio botão
    // que revela as palavras — assim não gasta largura nova na projeção nem
    // repete "N ideias juntas" duas vezes. O prefixo distingue a mesma caixa nas
    // duas nuvens (ativa e "tratar depois"), senão sairiam dois id iguais.
    const chave = `${adiada ? 'ad' : 'at'}-${g.chave}`;
    const aberta = this.caixaAberta === chave;
    return `<div class="grupo-caixa ${aberta ? '' : 'compacta'} ${adiada ? 'adiada' : ''} ${desteGrupo ? 'selecionada' : ''}"
      role="button" tabindex="0" ${acao} title="${dica}">
      <div class="grupo-titulo">${this.seloPergunta(lider)}${Modal.esc(titulo)}</div>
      <div class="grupo-rodape">
        <button type="button" class="btn-ver-palavras" data-ver-palavras="${chave}"
          aria-expanded="${aberta}" aria-controls="palavras-${chave}"
          title="${aberta ? 'Recolher' : 'Mostrar'} as ideias reunidas nesta caixa">${
          g.itens.length} ideias juntas · ${aberta ? 'ver menos' : 'ver mais'}</button>${
        g.votos ? `<span class="grupo-votos">★ ${g.votos}</span>` : ''}${
        this.rotuloDestino(lider) ? ` <span class="fp-tag">${
          Modal.esc(this.rotuloDestino(lider))}</span>` : ''}
      </div>
      <div class="grupo-palavras ${aberta ? '' : 'recolhida'}" id="palavras-${chave}">
        ${filhas.map((w) => `<span class="palavra-grupo">${Modal.esc(w.texto)}${
          podeTirar ? `<button type="button" class="palavra-x" data-remover-palavra="${w.id}"
            title="Tirar da caixa" aria-label="Tirar esta ideia da caixa">×</button>` : ''}</span>`).join('')}
      </div>
    </div>`;
  },

  /**
   * O quadro da sala: quatro quadrantes impacto × esforço, entre o painel do
   * QR e a tempestade. Quem ganha classificação SAI da nuvem e vira uma ficha
   * aqui dentro — e o quadrante cresce o quanto precisar, para nenhuma ficha
   * ficar cortada. A classificação continua sendo feita na bancada; este
   * painel é o retrato do que a sala já decidiu.
   */
  painelPrioridade() {
    const grupos = this.priorizadas();
    // O balde é decidido pelo MESMO critério de quadranteDe (o líder): uma voz
    // nova sem classificação que entra no grupo não pode sumir com a pílula
    const porQuadrante = (imp, esf) =>
      grupos.filter((g) => this.quadranteDe(g) === QUADRANTES[`${imp}:${esf}`])
        .map((g) => this.fichaPrio(g)).join('');
    // Esta é a ÚNICA matriz do sistema: é aqui que se classifica. Com uma ideia
    // escolhida na fila, os quadrantes viram alvo; sem seleção ficam inertes,
    // servindo só de leitura para a sala.
    const sel = this.grupoSelecionado();
    const lider = sel ? (sel.itens.find((x) => !x.agrupado_em_id) || sel.representante) : null;
    const podeClassificar = !!lider && App.podeEditar();
    // Ordem visual do gráfico: linha de cima = pouco esforço, colunas
    // pouco → muito impacto
    const celula = (imp, esf, area) => {
      const q = QUADRANTES[`${imp}:${esf}`];
      const escolhido = lider && lider.impacto === imp && lider.esforco === esf;
      // O quadrante ONDE a ideia já está não é alvo de toque: ele é o retrato
      // da posição atual. Tocar ali é o gesto de quem quer confirmar, e antes
      // isso desclassificava a ideia — que sumia da matriz sem ninguém pedir.
      const mover = podeClassificar && !escolhido;
      const alvo = mover
        ? `data-quadrante="${imp}:${esf}" data-item="${lider.id}" role="button" tabindex="0"
           title="Pôr «${Modal.esc(lider.texto_tratado || lider.texto)}» em ${q.titulo}"`
        : (escolhido
          ? `title="«${Modal.esc(lider.texto_tratado || lider.texto)}» já está em ${q.titulo}"`
          : '');
      // data-solta-quadrante é PERMANENTE (alvo do arraste, mesmo sem seleção);
      // data-quadrante só existe com uma ideia em foco (o toque que classifica)
      return `<div class="celula-prio ${area} ${escolhido ? 'escolhido' : ''} ${
        mover ? 'clicavel' : ''}" style="--cor-quad:${q.cor}"
        data-solta-quadrante="${imp}:${esf}" ${alvo}>
        <div class="cp-titulo">${q.titulo}</div>
        <div class="cp-fichas">${porQuadrante(imp, esf)}</div>
      </div>`;
    };
    // A ideia em foco NÃO se repete aqui (pedido do cliente, 2026-09-04): ela
    // já está destacada na fila e aberta na bancada, com a pergunta que
    // respondeu. Embaixo da matriz fica só a orientação, curta.
    const dica = podeClassificar
      ? 'Toque num quadrante para posicionar. Tocar no quadrante atual não muda nada; <strong>Descartar</strong> esquece a ideia (pede o motivo).'
      : 'Arraste uma ideia da fila até um quadrante — ou toque nela e depois no quadrante. O quadrante já define impacto e esforço.';
    return `<div class="card mb-3 painel-prio"><div class="card-body py-2 px-3">
      <div class="rotulo-secao">Prioridade</div>
      <div class="grade-prio">
        <div class="gp-impacto">Impacto</div>
        <div class="gp-col gp-col-1">Pouco</div>
        <div class="gp-col gp-col-2">Muito</div>
        <div class="gp-esforco d-none d-md-block">Esforço</div>
        <div class="gp-lin gp-lin-1"><span class="d-md-none">Pouco esforço</span><span
          class="d-none d-md-inline">Pouco</span></div>
        <div class="gp-lin gp-lin-2"><span class="d-md-none">Muito esforço</span><span
          class="d-none d-md-inline">Muito</span></div>
        ${celula('BAIXO', 'BAIXO', 'cp-bb')}
        ${celula('ALTO', 'BAIXO', 'cp-ab')}
        ${celula('BAIXO', 'ALTO', 'cp-ba')}
        ${celula('ALTO', 'ALTO', 'cp-aa')}
      </div>
      <div class="small text-muted mt-2">${dica}</div>
    </div></div>`;
  },

  /**
   * Põe a ideia (o grupo inteiro) num quadrante — o caminho único do clique e
   * do arraste. Só posiciona: tirar da matriz é o ✕ da pílula, que chama
   * `priorizar` com `limpar` direto.
   */
  async aplicarQuadrante(itemId, impacto, esforco) {
    // Trava de reentrância: na projeção o alvo é grande e dois toques seguidos
    // disparariam dois priorizar (e dois modais de descarte)
    if (this.classificando) return;
    this.classificando = true;
    const item = this.itens.find((i) => i.id == itemId);
    try {
      const r = await App.api(`/api/coleta/${itemId}/priorizar`,
        { planejamento_id: this.plan.id, impacto, esforco });
      // Posicionar é uma OPERAÇÃO COMPLETA: a interface volta ao neutro, sem
      // seleção e sem menu. Aqui vale para os dois caminhos — o arraste e o
      // toque no quadrante —, que passam ambos por este método.
      // Sem isso, o cartão seguia selecionado depois de posicionado e os
      // quadrantes continuavam armados: o toque seguinte em outro quadrante
      // movia a ideia de novo, sem que ninguém tivesse pedido.
      // Quem quiser reposicionar toca na pílula outra vez — o que também
      // reabre o menu de destinos dela.
      this.selecionado = null;
      this.menuDestino = null;
      // Aguarda o redesenho ANTES do modal de descarte, senão a tela troca
      // por baixo do modal recém-aberto
      await this.carregar();
      // Quadrante "Descartar": a matriz decide esquecer — abre o descarte já
      // com o motivo da própria posição.
      if (r.descartar && item) {
        this.abrirDescarte(item, 'Baixo impacto e alto esforço — fora da matriz de priorização.');
      }
    } catch (e) {
      alert(e.message);
    } finally {
      this.classificando = false;
    }
  },

  /** O grupo da ideia em foco — esteja ela na fila, no painel ou adiada. */
  grupoSelecionado() {
    if (!this.selecionado) return null;
    return [...this.montarGrupos(), ...this.montarGrupos(true)]
      .find((g) => g.itens.some((i) => i.id === this.selecionado)) || null;
  },

  /**
   * Ficha do painel: a pílula da ideia (ou da caixa-mãe inteira, com o ×N).
   * Toca para levar à bancada — lá se muda o quadrante (tocando em OUTRO), se
   * escolhe o destino ou se tira da matriz pelo ✕. Sem arraste: dentro do
   * painel não há o que juntar.
   */
  fichaPrio(g) {
    const lider = this.liderDe(g);
    const desteGrupo = g.itens.some((x) => x.id === this.selecionado);
    const selo = `${g.itens.length > 1 ? `×${g.itens.length}` : ''}${g.votos ? ` ★${g.votos}` : ''}`.trim();
    // Arrastável para mudar de quadrante (reclassificar). Dentro do painel o
    // arraste nunca agrupa: o alvo resolvido é sempre o quadrante.
    const destino = this.rotuloDestino(lider);
    // Tocar na pílula abre o menu flutuante: destinos, desmarcar o destino
    // atual e remover do quadrante — tudo num lugar só, sem botões extras
    // espremendo a ficha.
    const menuAberto = this.menuDestino === lider.id && App.podeEditar();
    const menu = menuAberto ? `<div class="fp-menu" role="menu">
        <div class="fpm-titulo">Encaminhar para</div>
        ${MENU_DESTINOS.map((s) => `
          ${s.grupo ? `<div class="fpm-grupo">${s.grupo}</div>` : ''}
          ${s.itens.map((d) => `<button type="button" class="fpm-item" role="menuitem"
            data-destino-menu="${d.valor}" data-item="${lider.id}">${d.rotulo}</button>`).join('')}`).join('')}
        ${destino ? `<button type="button" class="fpm-item fpm-desfazer" role="menuitem"
          data-desfazer-destino="${lider.id}">Desmarcar ${Modal.esc(destino)}</button>` : ''}
        <button type="button" class="fpm-item fpm-remover" role="menuitem"
          data-tirar-quadrante="${lider.id}">Remover do quadrante</button>
        ${this.botaoExcluirIdeia(lider, 'fpm-item fpm-excluir', 'Excluir ideia', 'role="menuitem"')}
      </div>` : '';
    return `<span class="ficha-prio-caixa">
      <button type="button" class="ficha-prio ${desteGrupo ? 'selecionada' : ''} ${
        destino ? 'encaminhada' : ''}" data-selecionar="${lider.id}" data-arrastavel="${lider.id}"
        aria-haspopup="true" aria-expanded="${menuAberto}"
        title="${Modal.esc(lider.autor || '')} — ${destino
          ? `encaminhada para ${destino}; toque para o menu, arraste para outro quadrante`
          : 'toque para o menu, arraste para outro quadrante'}">
        <span class="fp-texto">${Modal.esc(lider.texto_tratado || lider.texto)}</span>${
        selo ? ` <span class="repetida">${selo}</span>` : ''}${
        destino ? ` <span class="fp-tag">${Modal.esc(destino)}</span>` : ''}</button>${menu}</span>`;
  },

  // ---- Tela de condução (GTD): a fila à esquerda, a bancada à direita ----
  // A matriz fica acima das duas, em largura cheia: capturar/esclarecer aqui,
  // organizar lá. No celular empilha — a fila vem antes da bancada, para o
  // arraste até a matriz ser o mais curto possível.
  telaConducao() {
    const grupos = this.nuvem().filter((g) => this.passaFiltroPergunta(g));
    // A bancada procura o selecionado na tempestade E no painel de prioridade:
    // classificar move o grupo de um para o outro, e a bancada não pode sumir
    // no meio da tratativa (o Destino é escolhido depois de posicionar)
    const grupoSel = [...grupos, ...this.priorizadas()]
      .find((g) => g.itens.some((i) => i.id === this.selecionado));
    // Num grupo, a bancada é SEMPRE da caixa-mãe: o líder dá o título e toda
    // tratativa (texto, prioridade, destino) vale para a caixa como um todo —
    // não existe mais tratativa individual das filhas
    const item = grupoSel
      ? (grupoSel.itens.find((i) => !i.agrupado_em_id) || grupoSel.representante)
      : null;
    const fichas = this.blocosPorPergunta(grupos);

    const adiadas = this.nuvem(true).filter((g) => this.passaFiltroPergunta(g));

    // "Tratar depois" fica anexado à própria tempestade, logo abaixo dela e
    // separado só por uma linha pontilhada: é a mesma nuvem, guardada para o
    // fim da oficina — como card solto embaixo da bancada, parecia outra coisa.
    return `<div class="row g-3 mb-3">
      <div class="col-lg-7">
        <div class="card h-100"><div class="card-body py-2 px-3">
          <div class="rotulo-secao">Fila de ideias — arraste até um quadrante da
            matriz para classificar; toque para editar; arraste uma sobre a outra
            para juntar</div>
          ${fichas || '<div class="nuvem"><span class="text-muted small">Aguardando as primeiras ideias...</span></div>'}
          ${adiadas.length ? `<div class="caixa-depois">
            <button type="button" class="rotulo-secao btn-depois" data-ver-depois
              aria-expanded="${this.depoisAberto}" aria-controls="nuvem-depois">Tratar depois
              (${adiadas.length}) <span class="alterna-depois">· ${
                this.depoisAberto ? 'ver menos' : 'ver mais'}</span></button>
            <div class="nuvem ${this.depoisAberto ? '' : 'd-none'}" id="nuvem-depois">
              ${adiadas.map((g) => this.fichaOuCaixa(g, { adiada: true })).join('')}
            </div>
          </div>` : ''}
        </div></div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100 bancada"><div class="card-body py-2 px-3">
          <div class="rotulo-secao">Bancada</div>
          ${item ? this.bancada(item, grupoSel) : '<p class="text-muted small mb-0">Toque numa ideia da fila para editar, dividir ou agrupar. A prioridade é decidida na matriz, arrastando.</p>'}
        </div></div>
      </div>
    </div>`;
  },

  /**
   * A fila em BLOCOS por pergunta (pedido do cliente, 2026-09-04): a pergunta
   * em cima, as respostas embaixo — é assim que a reunião se conduz, uma
   * pergunta de cada vez. Sem questionário (nenhuma ideia com pergunta) a fila
   * é a nuvem única de sempre. A ideia sem pergunta — cadastrada à mão pelo
   * condutor, ou de uma tempestade de tema único — fica num bloco no fim.
   * Cada bloco é uma `.nuvem` própria: o arraste e as provas olham para ela.
   */
  blocosPorPergunta(grupos) {
    const nuvem = (gs) => `<div class="nuvem">${gs.map((g) => this.fichaOuCaixa(g)).join('')}</div>`;
    const perguntas = new Map();
    for (const q of (this.rodadaAberta?.perguntas || [])) {
      perguntas.set(String(q.id), { ordem: Number(q.ordem), enunciado: q.enunciado, grupos: [] });
    }
    const soltos = [];
    for (const g of grupos) {
      const lider = this.liderDe(g);
      // O grupo pertence à pergunta do LÍDER — quem recebeu o arraste manda
      const chave = lider.pergunta_id ? String(lider.pergunta_id) : '';
      if (!chave) { soltos.push(g); continue; }
      if (!perguntas.has(chave)) {
        perguntas.set(chave, { ordem: Number(lider.pergunta_ordem) || 0,
          enunciado: lider.pergunta_enunciado || `Pergunta ${lider.pergunta_ordem || ''}`, grupos: [] });
      }
      perguntas.get(chave).grupos.push(g);
    }
    if (!perguntas.size) return grupos.length ? nuvem(grupos) : '';
    const blocos = [...perguntas.values()].sort((a, b) => a.ordem - b.ordem)
      // Com o filtro ligado, só a pergunta escolhida; sem filtro, TODAS — a
      // vazia também, para o condutor ver que ninguém a respondeu ainda
      .filter((b) => !this.filtroPergunta || b.grupos.length);
    const html = blocos.map((b) => `
      <div class="bloco-pergunta">
        <div class="titulo-bloco-pergunta">
          <span class="fp-tag selo-pergunta">P${b.ordem || '?'}</span>
          <span class="tbp-enunciado">${Modal.esc(b.enunciado)}</span>
          <span class="tbp-conta">${b.grupos.length}</span>
        </div>
        ${b.grupos.length ? nuvem(b.grupos)
          : '<div class="nuvem"><span class="text-muted small">Nenhuma resposta ainda.</span></div>'}
      </div>`).join('');
    return html + (soltos.length ? `
      <div class="bloco-pergunta">
        <div class="titulo-bloco-pergunta">
          <span class="tbp-enunciado text-muted">Sem pergunta</span>
          <span class="tbp-conta">${soltos.length}</span>
        </div>
        ${nuvem(soltos)}
      </div>` : '');
  },

  /**
   * A bancada é um EDITOR, e só isso (método GTD: capturar/esclarecer aqui,
   * organizar na matriz). Não tem mais a matriz de prioridade — que virou a
   * única, no painel do topo — nem o "Rejeitar": descartar é pôr no quadrante
   * Descartar. Ver docs/REFATORACAO-GTD-COLETA.md.
   */
  bancada(item, grupo) {
    // Quando a nuvem agrupou, a bancada trata o grupo inteiro de uma vez
    const ids = (grupo?.itens || [item]).map((i) => i.id);
    // A pergunta vem ANTES da ideia (pedido do cliente, 2026-09-04): quem
    // classifica precisa ler o que foi perguntado para julgar a resposta — e
    // a matriz, logo acima, já não repete o texto em foco.
    const pergunta = item.pergunta_enunciado ? `
      <div class="bancada-pergunta">
        <span class="fp-tag selo-pergunta">P${Number(item.pergunta_ordem) || ''}</span>
        <span class="bp-enunciado">${Modal.esc(item.pergunta_enunciado)}</span>
      </div>
      <div class="rotulo-secao mt-2">Ideia enviada</div>` : '';
    return `
      ${pergunta}
      <div class="small text-muted">${Modal.esc(item.autor)}${
        ids.length > 1 ? ` e mais ${ids.length - 1}` : ''}${
        grupo?.votos ? ` · ★ ${grupo.votos} voto(s)` : ''}</div>
      ${ids.length > 1 ? `<div class="small text-muted">Este texto é o <strong>título
        da caixa</strong>; tratar aqui resolve as ${ids.length} ideias de uma vez.</div>` : ''}
      ${this.rotuloDestino(item) ? `<div class="small text-muted">Já encaminhada para
        <strong>${Modal.esc(this.rotuloDestino(item))}</strong> — salvar o texto aqui corrige
        lá também.</div>` : ''}
      <input type="hidden" id="grupo-bancada" value="${ids.join(',')}">
      <textarea class="form-control mt-1" rows="3" id="texto-bancada" maxlength="400"
        aria-label="Texto complementado">${Modal.esc(item.texto_tratado || item.texto)}</textarea>
      <div class="d-flex gap-1 flex-wrap mt-2">
        <button class="btn btn-sm btn-outline-secondary" data-complementar="${item.id}">Salvar texto</button>
        <button class="btn btn-sm btn-outline-secondary" data-dividir="${item.id}">Dividir</button>
        ${ids.length > 1 ? `<button class="btn btn-sm btn-outline-secondary"
          data-desagrupar="${item.id}" title="Separar as ideias deste grupo">Desagrupar</button>` : ''}
        <button class="btn btn-sm btn-outline-secondary" data-adiar="${item.id}">Tratar depois</button>
        ${App.podeEditar() ? this.botaoExcluirIdeia(item, 'btn btn-sm btn-outline-danger', 'Excluir',
          this.travaDaIdeia(item) ? '' : 'title="Apagar a ideia e o que ela virou no diagnóstico"') : ''}
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
        // O ✕ de tirar palavra do grupo nunca inicia arraste: é destrutivo, e um
        // dedo trêmulo ali desfaria um agrupamento ao vivo.
        // O "ver mais", ao contrário, PRECISA arrastar. Ele tem um ::after de
        // toque confortável que cobre o centro da caixa compacta, e vetá-lo
        // deixava ~30% da caixa — o miolo, justo onde a mão pega — sem resposta
        // ao arraste. O limiar de 8px já separa o toque (revela as palavras) do
        // arraste. A ficha simples é ela própria um <button> e segue arrastável.
        const botao = ev.target.closest('button');
        if (botao && botao !== ficha && !botao.matches('[data-ver-palavras]')) return;
        // Todo gesto iniciado numa ficha "engole" o clique que o navegador
        // dispara depois no quadrante. Sem isso, pegar uma pílula e largá-la
        // num quadrante vizinho (arraste curto demais para virar arraste)
        // chegaria ao quadrante como toque, e a ideia mudaria de posição por
        // um gesto que ninguém completou.
        this.gestoEmFicha = true;
        const origem = { x: ev.clientX, y: ev.clientY };
        let arrastando = false;
        let alvoAtual = null;   // ficha/caixa sob o dedo → agrupar
        let quadAtual = null;   // quadrante sob o dedo  → classificar
        let ultimo = { x: ev.clientX, y: ev.clientY };
        let quadroRolagem = null;

        const limparRealce = () => {
          alvoAtual?.classList.remove('alvo-juntar');
          quadAtual?.classList.remove('alvo-solta');
        };

        // O quadrante tem PRECEDÊNCIA sobre a ficha: soltar em cima de uma
        // pílula que está dentro de um quadrante classifica, nunca agrupa —
        // agrupar é coisa da fila.
        const atualizarAlvo = (x, y) => {
          const sob = document.elementFromPoint(x, y);
          const quad = sob?.closest('[data-solta-quadrante]') || null;
          const alvo = quad ? null : sob?.closest('[data-arrastavel]');
          const novoAlvo = alvo && alvo !== ficha ? alvo : null;
          if (quad !== quadAtual || novoAlvo !== alvoAtual) limparRealce();
          quadAtual = quad;
          alvoAtual = novoAlvo;
          quadAtual?.classList.add('alvo-solta');
          alvoAtual?.classList.add('alvo-juntar');
        };

        // A matriz fica acima da fila: no celular o gesto atravessa uma
        // rolagem. Perto das bordas a tela rola sozinha, e o alvo é recalculado
        // a cada quadro — senão o realce congelaria enquanto a página desliza.
        const rolar = () => {
          const margem = 90;
          const passo = 18;
          const alt = window.innerHeight;
          let d = 0;
          if (ultimo.y < margem) d = -passo * (1 - ultimo.y / margem);
          else if (ultimo.y > alt - margem) d = passo * (1 - (alt - ultimo.y) / margem);
          if (d) {
            window.scrollBy(0, d);
            atualizarAlvo(ultimo.x, ultimo.y);
          }
          quadroRolagem = requestAnimationFrame(rolar);
        };

        const mover = (e) => {
          const dist = Math.hypot(e.clientX - origem.x, e.clientY - origem.y);
          // Abaixo de 8px ainda é toque, não arraste
          if (!arrastando && dist < 8) return;
          if (!arrastando) {
            arrastando = true;
            this.arrastando = true;
            ficha.classList.add('arrastando');
            // O menu flutuante fica dentro da célula de ORIGEM. Sobre o
            // quadrante de destino ele rouba o elementFromPoint e o alvo
            // resolve para a origem — a solta vira "mesmo quadrante" e some em
            // silêncio (no celular o menu cobre boa parte da matriz). Durante o
            // gesto ele deixa de receber ponteiro; o estado dele não muda, para
            // a solta inválida ainda devolver o menu aberto.
            document.body.classList.add('arrastando-ficha');
            quadroRolagem = requestAnimationFrame(rolar);
          }
          e.preventDefault();
          ultimo = { x: e.clientX, y: e.clientY };
          atualizarAlvo(e.clientX, e.clientY);
        };

        const soltar = async () => {
          document.removeEventListener('pointermove', mover);
          document.removeEventListener('pointerup', soltar);
          document.removeEventListener('pointercancel', soltar);
          if (quadroRolagem) cancelAnimationFrame(quadroRolagem);
          this.arrastando = false;
          ficha.classList.remove('arrastando');
          document.body.classList.remove('arrastando-ficha');
          limparRealce();
          // Solta a trava só depois do clique que o navegador dispara em seguida
          setTimeout(() => { this.gestoEmFicha = false; }, 60);
          // Marca o gesto como arraste mesmo quando ele morre em área inválida:
          // o clique que o navegador dispara em seguida não pode ser lido como
          // toque (selecionaria a ficha, ou abriria/fecharia a caixa).
          // A marca EXPIRA junto com gestoEmFicha: quando o gesto morre fora de
          // alvo não há redesenho, e uma marca pendurada engolia o próximo
          // clique legítimo — era preciso tocar duas vezes para o menu abrir.
          if (arrastando) {
            ficha.dataset.arrastou = '1';
            setTimeout(() => { delete ficha.dataset.arrastou; }, 60);
          }
          if (!arrastando || (!alvoAtual && !quadAtual)) return;
          const id = ficha.dataset.arrastavel;

          // Soltou num quadrante: classifica (é o gesto central do GTD)
          if (quadAtual) {
            const [impacto, esforco] = quadAtual.dataset.soltaQuadrante.split(':');
            const arrastado = this.itens.find((i) => i.id == id);
            // Devolver ao mesmo quadrante não é desfazer nem reposicionar:
            // nada mudou, então a seleção e o menu continuam como estavam — é
            // uma tentativa, não uma operação. Mesma regra do toque.
            if (arrastado && arrastado.impacto === impacto && arrastado.esforco === esforco) return;
            // Movimento concluído = operação encerrada. Quem limpa a seleção e
            // o menu é o próprio aplicarQuadrante, ponto comum do arraste e do
            // toque — a regra mora num lugar só. Arrastar de novo continua
            // valendo sem reselecionar (data-solta-quadrante é permanente);
            // classificar por TOQUE volta a exigir escolher o cartão.
            await this.aplicarQuadrante(id, impacto, esforco);
            return;
          }

          const alvo = Number(alvoAtual.dataset.arrastavel);
          try {
            const r = await App.api(`/api/coleta/${id}/agrupar`,
              { planejamento_id: this.plan.id, alvo });
            // O alvo do arraste é quem manda: ele (ou o líder do grupo dele)
            // vira a caixa-mãe, e a bancada abre já nela
            this.selecionado = Number(r.lider) || alvo;
            // A caixa que acabou de receber abre: é o instante em que se percebe
            // um agrupamento errado, e os ✕ ficam à mão para desfazer na hora
            this.caixaAberta = `at-${this.selecionado}`;
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

  /** Ids do grupo do item — esteja ele na tempestade, no painel ou adiado. */
  grupoAtual(item) {
    const g = [...this.montarGrupos(), ...this.montarGrupos(true)]
      .find((x) => x.itens.some((i) => i.id === item.id));
    return (g?.itens || [item]).map((i) => i.id);
  },

  /**
   * Liga o clique e — quando o elemento é a caixa de grupo (`<div
   * role="button">`) — também o teclado (Enter/Espaço). Botão nativo já
   * responde ao teclado sozinho; só a caixa precisa disso na mão, e tanto
   * para selecionar quanto para retomar da caixa "Tratar depois".
   */
  ativarBotao(b, acao) {
    b.addEventListener('click', acao);
    if (b.getAttribute('role') === 'button') {
      b.addEventListener('keydown', (ev) => {
        // Só quando o foco está na própria caixa: senão Enter no "ver mais" (ou
        // no ✕) dispararia também a ação da caixa inteira
        if (ev.target !== b) return;
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); acao(ev); }
      });
    }
  },

  // A pergunta do questionário que a nuvem mostra (null = todas). Mora no
  // objeto, não no DOM: a nuvem é repintada a cada batida do relógio.
  filtroPergunta: null,
  // A lista do filtro está aberta? Mora aqui pelo mesmo motivo: o painel é
  // repintado quando chega ideia, e a lista não pode fechar na mão de quem
  // estava escolhendo.
  comboPerguntaAberto: false,

  /** Abre/fecha a lista do filtro sem repintar a seção. */
  alternarComboPergunta(aberto) {
    this.comboPerguntaAberto = aberto;
    const c = document.querySelector('[data-combo-pergunta]');
    if (!c) return;
    c.classList.toggle('aberto', aberto);
    c.querySelector('.cp-lista').hidden = !aberto;
    c.querySelector('[data-combo-alternar]').setAttribute('aria-expanded', String(aberto));
  },

  ligarTempestade(el, ano) {
    el.querySelectorAll('[data-ir-sala]').forEach((b) =>
      b.addEventListener('click', () => App.mostrarSecao('sala')));
    const combo = el.querySelector('[data-combo-pergunta]');
    if (combo) {
      combo.querySelector('[data-combo-alternar]').addEventListener('click', () =>
        this.alternarComboPergunta(!this.comboPerguntaAberto));
      combo.querySelectorAll('[data-filtro-opcao]').forEach((b) => b.addEventListener('click', () => {
        this.filtroPergunta = b.dataset.filtroOpcao || null;
        this.comboPerguntaAberto = false;
        this.carregar();
      }));
      // Toque fora (ou Esc) fecha a lista — registrado uma vez só
      if (!this.comboFechaFora) {
        this.comboFechaFora = true;
        document.addEventListener('click', (ev) => {
          if (this.comboPerguntaAberto && !ev.target.closest('[data-combo-pergunta]')) this.alternarComboPergunta(false);
        });
        document.addEventListener('keydown', (ev) => {
          if (ev.key === 'Escape' && this.comboPerguntaAberto) this.alternarComboPergunta(false);
        });
      }
    }

    // O mesmo modal da aba Sala (QuizSala.modalPergunta): uma redação só para
    // o campo, o rótulo e a ajuda, em qualquer tela que edite a pergunta.
    el.querySelector('[data-editar-pergunta]')?.addEventListener('click', () =>
      QuizSala.modalPergunta(this.plan.id, this.rodadaAberta, () => this.carregar()));

    // Fecha (ou reabre) a sala. No servidor isto é a FASE DE VOTAÇÃO: `abrir`
    // ali quer dizer "abrir as ★", que é exatamente o que fechar a sala faz —
    // o celular deixa de escrever e passa a escolher. O rótulo fala da sala
    // porque é assim que quem conduz pensa o gesto; o corpo fala da votação
    // porque é assim que a rodada guarda a fase.
    // Sem confirmação de propósito: é reversível no mesmo botão, e a dica ao
    // lado já diz a consequência antes do clique.
    el.querySelector('[data-fase-sala]')?.addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      const fechar = b.dataset.faseSala === 'fechar';
      b.disabled = true;
      try {
        await App.api(`/api/rodadas/${this.rodadaAberta.id}/votacao`,
          { planejamento_id: this.plan.id, abrir: fechar });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    });

    el.querySelectorAll('[data-selecionar]').forEach((b) => this.ativarBotao(b, () => {
      // Um arraste que terminou em cima de outra ficha não é um toque
      if (b.dataset.arrastou === '1') {
        delete b.dataset.arrastou;
        return;
      }
      const id = Number(b.dataset.selecionar);
      // Na fila, tocar de novo desmarca. Na matriz, NÃO: lá o toque é o que
      // ARMA a reclassificação (seleciona a pílula e acende os quadrantes), e
      // desmarcar por engano deixaria a bancada vazia sem nada em troca. Como
      // posicionar agora encerra a operação e desmarca sozinho, é este toque
      // que devolve o controle à pessoa quando ela quiser mover de novo.
      const naMatriz = !!b.closest('.cp-fichas');
      this.selecionado = (!naMatriz && this.selecionado === id) ? null : id;
      // Na matriz, o toque também abre o menu (destinos + remover do
      // quadrante); tocar de novo fecha. Na fila não há menu.
      this.menuDestino = naMatriz && this.menuDestino !== id ? id : null;
      this.carregar();
    }));

    el.querySelectorAll('[data-retomar]').forEach((b) => this.ativarBotao(b, async () => {
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
        // …e a caixa segue aberta, para tirar a segunda palavra sem reabrir
        this.caixaAberta = r.lider ? `at-${r.lider}` : null;
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));

    // "Tratar depois" recolhido/expandido — mesmo alternador do "ver mais" das
    // caixas. Troca local, sem recarregar; quem só lê também abre.
    el.querySelectorAll('[data-ver-depois]').forEach((b) => b.addEventListener('click', () => {
      this.depoisAberto = !this.depoisAberto;
      el.querySelector('#nuvem-depois')?.classList.toggle('d-none', !this.depoisAberto);
      b.setAttribute('aria-expanded', String(this.depoisAberto));
      const alterna = b.querySelector('.alterna-depois');
      if (alterna) alterna.textContent = `· ${this.depoisAberto ? 'ver menos' : 'ver mais'}`;
    }));

    // "ver mais / ver menos": revela as palavras da caixa. Não seleciona, não
    // retoma da caixa "tratar depois" e não recarrega — a troca é local e
    // instantânea. Fica antes da trava de edição: quem só lê também abre.
    el.querySelectorAll('[data-ver-palavras]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      // Agora o arraste pode começar neste botão. Só o clique que é rabicho de
      // um ARRASTE é ignorado — o toque simples continua revelando as palavras
      // (gestoEmFicha não serve aqui: ele é ligado em todo pointerdown).
      const caixa = b.closest('[data-arrastavel]');
      if (caixa?.dataset.arrastou === '1') {
        delete caixa.dataset.arrastou;
        return;
      }
      const chave = b.dataset.verPalavras;
      const abrir = this.caixaAberta !== chave;
      this.caixaAberta = abrir ? chave : null;
      // Uma aberta por vez: a que estava aberta recolhe junto
      el.querySelectorAll('[data-ver-palavras]').forEach((outro) => {
        const desta = outro === b && abrir;
        const caixa = outro.closest('.grupo-caixa');
        const palavras = caixa?.querySelector('.grupo-palavras');
        const quantas = outro.textContent.split('·')[0].trim();
        outro.textContent = `${quantas} · ${desta ? 'ver menos' : 'ver mais'}`;
        outro.setAttribute('aria-expanded', String(desta));
        outro.title = `${desta ? 'Recolher' : 'Mostrar'} as ideias reunidas nesta caixa`;
        palavras?.classList.toggle('recolhida', !desta);
        caixa?.classList.toggle('compacta', !desta);
      });
    }));

    // O menu de encaminhamento fecha ao tocar fora ou com Esc, como qualquer
    // camada flutuante. Registrado uma vez só, não a cada redesenho.
    if (!this.fecharMenuLigado) {
      this.fecharMenuLigado = true;
      document.addEventListener('click', (ev) => {
        // O clique que é rabicho de um arraste não conta como "tocou fora":
        // um arraste solto em área inválida deve deixar o menu como estava,
        // para tentar de novo sem reabrir nada. Mesma trava do [data-quadrante].
        if (this.gestoEmFicha) return;
        if (this.menuDestino !== null && !ev.target.closest('.ficha-prio-caixa')) {
          this.menuDestino = null;
          this.carregar();
        }
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && this.menuDestino !== null) {
          this.menuDestino = null;
          this.carregar();
        }
      });
    }

    this.ligarArraste(el);

    if (!App.podeEditar()) return;

    // Painel de reclassificação: escolher o novo destino abre o modal de
    // encaminhar (a ideia já está SELECIONADA depois do reabrir)
    el.querySelectorAll('[data-reclassificar]').forEach((b) => b.addEventListener('click', async () => {
      const item = this.itens.find((i) => i.id == b.dataset.reclassificar);
      if (!item) return;
      // Só agora a ideia sai da análise: reabre (remove o registro atual) e
      // então abre o encaminhar para o novo destino
      try {
        await App.api(`/api/coleta/${item.id}/reabrir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
        return;
      }
      this.reclassificando = null;
      this.reclassificarRotulo = '';
      this.modalEncaminhar(item, b.dataset.destino);
    }));
    el.querySelector('[data-cancelar-reclassificar]')?.addEventListener('click', () => {
      this.reclassificando = null;
      this.reclassificarRotulo = '';
      this.carregar();
    });

    // Abrir, encerrar e votação da tempestade moram na aba Sala, junto do PIN,
    // do QR e da pergunta: a sala é uma só, e tinha comando em duas telas.
    // (Os ouvintes antigos de `#btn-limpar-rodada` e `#btn-votacao` saíram:
    // o primeiro id não existe mais e o segundo é o botão da SALA — como as
    // seções não são destruídas, a Coleta pendurava um segundo handler nele.)

    el.querySelectorAll('[data-quadrante]').forEach((b) => this.ativarBotao(b, async (ev) => {
      // Tocar numa pílula DENTRO do quadrante leva aquela ideia à bancada —
      // não reclassifica a que está em foco
      if (ev?.target?.closest('[data-selecionar]')) return;
      // Clique que é o rabicho de um arraste iniciado numa ficha: ignora
      if (this.gestoEmFicha) return;
      const [impacto, esforco] = b.dataset.quadrante.split(':');
      const item = this.itens.find((i) => i.id == b.dataset.item);
      // Tocar no quadrante JÁ escolhido não faz nada — nem move, nem desmarca.
      // Desmarcar por toque tirava a ideia da matriz sem ninguém pedir: o
      // quadrante da ideia em foco fica realçado, e confirmar a posição
      // (tocando nele) é o gesto mais natural do mundo. Sair da matriz tem
      // caminho próprio e explícito: o ✕ da pílula ("Remover do quadrante").
      // Mesma regra do arraste, que já trata soltar no mesmo lugar como
      // tentativa, não como operação.
      if (item && item.impacto === impacto && item.esforco === esforco) return;
      await this.aplicarQuadrante(b.dataset.item, impacto, esforco);
    }));

    el.querySelectorAll('[data-destino-menu]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const item = this.itens.find((i) => i.id == b.dataset.item);
      this.menuDestino = null;
      if (item) this.modalEncaminhar(item, b.dataset.destinoMenu);
    }));

    // Desmarcar o destino: a ideia SAI da análise (o fator/item de cenário é
    // apagado) e a etiqueta some, mas ela CONTINUA no quadrante — a prioridade
    // que a sala decidiu não se perde junto.
    el.querySelectorAll('[data-desfazer-destino]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const id = b.dataset.desfazerDestino;
      const item = this.itens.find((i) => i.id == id);
      const destino = item ? this.rotuloDestino(item) : 'destino';
      if (!confirm(`Desmarcar ${destino}?\n\nA ideia sai de ${destino} (o registro de lá é apagado) e continua no quadrante.`)) return;
      try {
        await App.api(`/api/coleta/${id}/reabrir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.menuDestino = null;
      this.carregar();
    }));

    // ✕ da pílula: tira do quadrante e devolve à fila. Se a ideia já foi para
    // uma análise, pergunta antes se ela deve sair de lá também — remover da
    // SWOT apaga o fator, então nunca é feito em silêncio.
    el.querySelectorAll('[data-tirar-quadrante]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const id = b.dataset.tirarQuadrante;
      const item = this.itens.find((i) => i.id == id);
      const destino = item ? this.rotuloDestino(item) : '';
      if (!confirm(`Tirar «${item?.texto_tratado || item?.texto}» do quadrante e devolver à fila?`)) return;
      try {
        if (destino) {
          const tirarDeLa = confirm(
            `Esta ideia está em ${destino}.\n\n`
            + `OK — remover também de ${destino} (o registro de lá é apagado).\n`
            + `Cancelar — manter em ${destino}; ela volta à fila com a etiqueta.`);
          // reabrir só existe para Cenário/Fator; o plano de ação pendente não
          // tem registro no diagnóstico para apagar
          if (tirarDeLa && item.destino_tipo !== 'ACAO') {
            await App.api(`/api/coleta/${id}/reabrir`, { planejamento_id: this.plan.id });
          }
        }
        await App.api(`/api/coleta/${id}/priorizar`, { planejamento_id: this.plan.id, limpar: true });
      } catch (e) {
        alert(e.message);
      }
      this.selecionado = null;
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
        const campo = el.querySelector('#texto-bancada');
        this.itens.find((i) => i.id == b.dataset.complementar).texto_tratado = campo.value;
        // O texto salvo é o TÍTULO da caixa-mãe (e o rótulo da ficha no painel
        // de prioridade): atualiza na tela sem redesenhar (o aviso "Texto
        // salvo" fica visível) e libera a trava do relógio, que segura o
        // redesenho enquanto o textarea está editado
        campo.defaultValue = campo.value;
        el.querySelectorAll(`[data-selecionar="${b.dataset.complementar}"]`).forEach((n) => {
          const alvo = n.querySelector('.grupo-titulo, .fp-texto');
          if (alvo) alvo.textContent = campo.value;
        });
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
    // "Tratar" puxa a ideia para a fila fora da ordem de chegada. Só existe
    // onde a fila existe (quem triagem, sem rodada aberta) e não aparece no
    // cartão que a fila já está mostrando — esse ganha o selo "na fila".
    const naFila = this.atualDaFila && this.atualDaFila.id === i.id;
    const podeTratar = !naFila && i.situacao === 'NOVO' && App.podeEditar() && !this.rodadaAberta;
    return `<div class="card mb-2 ${naFila ? 'na-fila' : ''}" data-card-ideia="${i.id}"><div class="card-body py-2 px-3">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge ${classe}">${rotulo}</span>
        ${naFila ? '<span class="badge text-bg-success" title="É a ideia aberta na fila de tratativa, acima">na fila</span>' : ''}
        ${i.pergunta_ordem ? `<span class="badge text-bg-light border" title="${Modal.esc(i.pergunta_enunciado || '')}">Pergunta ${i.pergunta_ordem}</span>` : ''}
        <span class="small text-muted flex-grow-1">${Modal.esc(i.autor)} · ${this.data(i.criado_em)}</span>
        ${podeMexer || podeTratar ? `
          <span class="d-flex gap-1 flex-shrink-0">
            ${podeTratar ? `<button class="btn btn-sm btn-verde" data-tratar="${i.id}"
              title="Puxar esta ideia para a fila de tratativa agora">Tratar</button>` : ''}
            ${podeMexer ? `<button class="btn btn-sm btn-outline-secondary" data-editar-ideia="${i.id}"
              title="Editar" aria-label="Editar">✎</button>
            ${this.botaoExcluirIdeia(i, 'btn btn-sm btn-outline-danger', '×',
              this.travaDaIdeia(i) ? 'aria-label="Excluir (bloqueado: virou ação)"'
                : 'title="Excluir" aria-label="Excluir"')}` : ''}
          </span>` : ''}
      </div>
      <div class="small texto-fator mt-1">${Modal.esc(i.texto)}</div>
      ${i.situacao === 'ACEITO' && i.destino_tipo === 'ACAO' ? `
        <div class="mt-1">${i.destino_id
          ? '<span class="badge text-bg-success">Virou ação no plano</span>'
          : '<span class="badge text-bg-secondary">Aguardando plano de ação</span>'}</div>`
        : i.situacao === 'ACEITO' && i.destino_id ? `
        <div class="mt-1"><button type="button" class="btn btn-sm selo-link"
          data-ir-destino="${i.destino_id}" data-tipo-destino="${i.destino_tipo}"
          title="Abrir o registro criado">Virou ${i.destino_tipo === 'CENARIO' ? 'item de cenário' : 'fator'} ↗</button></div>`
        : i.situacao === 'ACEITO' && !i.destino_id
          ? '<div class="small text-muted mt-1">Destino removido do diagnóstico.</div>' : ''}
      ${i.situacao === 'DESCARTADO' ? `
        <div class="small mt-1 motivo-descarte"><strong>Não entrou:</strong> ${Modal.esc(i.motivo || '')}
          ${i.triador ? `<span class="text-muted">· ${Modal.esc(i.triador)}</span>` : ''}</div>` : ''}
    </div></div>`;
  },

  /**
   * Voltou do diagnóstico para reclassificar: a ideia foi reaberta (SELECIONADO)
   * e é carregada na bancada quando a rodada dela está aberta; senão, cai na
   * lista, destacada na situação atual.
   */
  prepararReclassificacao() {
    if (!Diag.reclassificarColeta) return;
    const ref = Diag.reclassificarColeta;
    Diag.reclassificarColeta = null;
    const alvo = this.itens.find((i) => String(i.id) === String(ref.id));
    if (!alvo) { this.reclassificando = null; return; }
    // Painel próprio (independe de rodada aberta): a ideia pode ser de outra
    // rodada e não apareceria na nuvem da rodada atual
    this.reclassificando = alvo.id;
    this.reclassificarRotulo = ref.rotulo || '';
  },

  /**
   * Ideia reaberta do diagnóstico: mostra o texto e de onde saiu, com os
   * botões de destino para reclassificar. Some quando não há reclassificação.
   */
  painelReclassificar() {
    if (!this.reclassificando) return '';
    const item = this.itens.find((i) => i.id === this.reclassificando);
    if (!item) { this.reclassificando = null; return ''; }
    return `<div class="card mb-3 painel-reclassificar"><div class="card-body py-2 px-3">
      <div class="rotulo-secao">Reclassificar ideia</div>
      <div class="small mb-1">${Modal.esc(item.texto_tratado || item.texto)}</div>
      <div class="small text-muted mb-2">${this.reclassificarRotulo
        ? `Saiu de <strong>${Modal.esc(this.reclassificarRotulo)}</strong>. Escolha o novo destino:`
        : 'Escolha o novo destino:'}</div>
      <div class="d-flex gap-1 flex-wrap">
        ${DESTINOS_TRIAGEM.map((d) => `<button class="btn btn-sm btn-destino" style="--cor-destino:${d.cor}"
          data-reclassificar="${item.id}" data-destino="${d.valor}">${d.rotulo}</button>`).join('')}
        <button class="btn btn-sm btn-outline-secondary" data-cancelar-reclassificar>Cancelar</button>
      </div>
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
      // A etapa é onde a ideia FOI PARAR (destino_etapa, que o listar() traz
      // com o LEFT JOIN em fator), não o destino_sugerido — este é o palpite de
      // quem escreveu, e o padrão dele é NAO_SEI. Pelo palpite, uma ideia sem
      // sugestão encaminhada para a SWOT abria a seção PESTEL e o destaque não
      // achava o card. O casamento também confere o tipo: um cenario_item de
      // mesmo id não pode ser escolhido no lugar do fator.
      const item = this.itens.find((i) => String(i.destino_id) === String(id)
        && i.destino_tipo === 'FATOR');
      const etapa = (item?.destino_etapa || '').toLowerCase();
      Diag.irParaFator(['pestel', 'porter', 'swot'].includes(etapa) ? etapa : 'swot', id);
    }));

    if (!App.podeEditar()) return;

    // Com uma rodada aberta, a ideia manual entra NA tempestade (leva o
    // rodada_id): senão ela nasce fora da rodada e a nuvem — que só mostra a
    // rodada em curso — a esconderia, dando a impressão de que não salvou.
    const rodadaAtual = this.rodadaAberta ? this.rodadaAberta.id : '';
    const modalIdeia = (i = null) => Modal.abrir({
      titulo: i ? 'Editar ideia' : (rodadaAtual ? 'Nova ideia na tempestade' : `Nova ideia · ${ano}`),
      url: i ? `/api/coleta/${i.id}` : '/api/coleta',
      valores: i
        ? { ...i, planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, ano, destino_sugerido: 'NAO_SEI', rodada_id: rodadaAtual },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden', padrao: ano },
        { nome: 'rodada_id', rotulo: '', tipo: 'hidden' },
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
    // Excluir apaga a ideia e o que ela virou no diagnóstico. Como é
    // irreversível, a confirmação diz exatamente o que vai embora: quantas
    // ideias da caixa e de qual análise.
    el.querySelectorAll('[data-excluir-ideia]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      const id = b.dataset.excluirIdeia;
      const item = this.itens.find((i) => i.id == id);
      const grupo = [...this.montarGrupos(), ...this.montarGrupos(true)]
        .find((g) => g.itens.some((i) => i.id == id));
      const quantas = grupo ? grupo.itens.length : 1;
      const destino = item ? this.rotuloDestino(item) : '';
      if (!confirm(`Excluir «${item?.texto_tratado || item?.texto}»?`
        + (quantas > 1 ? `\n\nSão ${quantas} ideias juntas nesta caixa: todas serão excluídas.` : '')
        + (destino ? `\n\nEla também sai de ${destino} — o registro de lá é apagado.` : '')
        + '\n\nNão dá para desfazer.')) return;
      try {
        await App.api(`/api/coleta/${id}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.selecionado = null;
      this.menuDestino = null;
      this.carregar();
    }));

    // Pular deixa a ideia para o fim desta rodada de triagem — e solta o foco,
    // senão a puxada voltaria à fila logo em seguida
    el.querySelectorAll('[data-pular]').forEach((b) => b.addEventListener('click', () => {
      this.pulados.add(Number(b.dataset.pular));
      this.foco = null;
      this.carregar();
    }));

    // "Tratar" na lista: a ideia vai para a fila agora, fora da ordem. Não
    // recarrega do servidor — nada mudou lá; redesenha e leva a pessoa até a
    // fila, porque no celular ela está acima, fora da vista.
    el.querySelectorAll('[data-tratar]').forEach((b) => b.addEventListener('click', () => {
      this.foco = Number(b.dataset.tratar);
      this.pulados.delete(this.foco);
      this.carregar().then(() => {
        document.querySelector('#secao-coleta .fila-coleta')
          ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }));

    el.querySelectorAll('[data-encaminhar]').forEach((b) => b.addEventListener('click', () =>
      this.modalEncaminhar(this.itens.find((i) => i.id == b.dataset.encaminhar), b.dataset.destino)));

    el.querySelectorAll('[data-descartar]').forEach((b) => b.addEventListener('click', () =>
      this.abrirDescarte(this.itens.find((i) => i.id == b.dataset.descartar))));
  },

  /**
   * Abre o descarte (esquecer) com motivo obrigatório. `motivoSugerido` chega
   * preenchido quando o descarte vem do quadrante "Descartar" da matriz — o
   * condutor confirma ou ajusta; se cancelar, a posição fica registrada e a
   * ideia continua em "A tratar".
   */
  abrirDescarte(item, motivoSugerido = '') {
    Modal.abrir({
      titulo: 'Descartar ideia',
      url: `/api/coleta/${item.id}/descartar`,
      valores: { planejamento_id: this.plan.id, motivo: motivoSugerido },
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
    const paraAcao = destino === 'ACAO';
    if (destino === 'CENARIO') {
      campos.push({ nome: 'tipo', rotulo: 'Tipo', tipo: 'botoes', opcoes: [
        { valor: 'SITUACAO_ATUAL', rotulo: 'Situação atual' },
        { valor: 'TENDENCIA', rotulo: 'Tendência' },
      ]});
    } else if (destino === 'SWOT') {
      campos.push(Diag.campoQuadrante());
    } else if (!paraAcao) {
      // Mesmos cartões da SWOT: as categorias ficam todas à vista, com a dica
      // do que entra em cada uma, em vez de escondidas atrás de um select
      campos.push(Diag.campoCategoria(destino));
    }
    campos.push({ nome: 'texto_tratado',
      rotulo: paraAcao ? 'Texto que vai para o plano de ação' : 'Texto que vai para o diagnóstico',
      tipo: 'textarea', linhas: 4,
      ajuda: paraAcao
        ? 'A ideia fica pendente e aparece em Projetos para virar uma ação de uma iniciativa.'
        : 'Ajuste a redação se precisar; a ideia original fica guardada como foi dita.' });

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

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

/**
 * A cor de cada situação como VALOR, para o selo do resumo pintar o ponto, o
 * fundo e a borda.
 *
 * É a mesma leitura de cor dos selos das ações (`STATUS_ACAO`), só que em hexa:
 * a classe do Bootstrap serve ao selo sólido do cartão, e aqui o selo é claro,
 * porque são vários lado a lado e uma fila de badges sólidos vira parede.
 * A regra é a da matriz GUT — **a cor junta, a forma separa**: mesma cor para a
 * mesma situação, tratamento diferente conforme o lugar. Duas paletas para o
 * mesmo estado divergiriam na primeira revisão.
 */
const CORES_STATUS = {
  NAO_INICIADO: '#0a8ea0',
  EM_ANDAMENTO: '#0d6efd',
  ATRASADO: '#b3261e',
  CONCLUIDO: '#007a45',
  PAUSADO: '#b08d4f',
  AGUARDANDO_VALIDACAO: '#5d6b64',
  CANCELADO: '#6c757d',
};

// Ordem do resumo: o que pede atenção primeiro. Não é a ordem do ENUM nem a
// alfabética — quem olha o cabeçalho de um projeto quer saber do atraso antes
// de saber do que já está pronto.
const ORDEM_RESUMO = ['ATRASADO', 'EM_ANDAMENTO', 'AGUARDANDO_VALIDACAO', 'PAUSADO',
  'NAO_INICIADO', 'CONCLUIDO', 'CANCELADO'];
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
// Origens que vivem na tabela `fator` e por isso fecham o vínculo pelo MESMO
// campo (`fator_id`). Até 2026-08 só a SWOT chegava aqui, porque só ela podia
// encaminhar ao plano; com PESTEL e Porter indo direto (decisão do cliente,
// ver `FatorController::planoAcao`), a fila passou a trazer as três etapas e o
// que muda de uma para a outra é só o CATÁLOGO de categorias.
//
// A lista e os nomes de tela saem de `Diag.ROTULO_ETAPA`, que é o catálogo
// único — o "⇄ Mover" do diagnóstico monta os destinos a partir dele, e duas
// listas de etapas divergiriam na primeira análise nova.
const ORIGENS_FATOR = Object.keys(Diag.ROTULO_ETAPA);

const SecaoProjetos = {
  plan: null,
  cascata: null,
  responsaveis: [],
  comentariosAbertos: null, // { refTipo, refId }

  /* ── Onde estou no plano ──────────────────────────────────────────────────
   * A tela deixou de ser uma árvore de três acordeões e virou mestre-detalhe:
   * a trilha diz ONDE se está, o centro mostra as ações de lá, a gaveta abre
   * UMA ação. Por isso o estado deixou de ser "quem está recolhido"
   * (`projetosFechados`, `iniciativasFechadas`, `detalhesAbertos`, que não
   * existem mais) e passou a ser "onde estou". São três campos, e os três são
   * de navegação: nenhum guarda dado do plano.
   */
  selecao: null, // { tipo: 'FILA' | 'PROJETO' | 'FRENTE', id }
  acaoAberta: null, // id da ação que a gaveta mostra
  trilhaAberta: new Set(), // projetos com as frentes à mostra
  // id da ação que a seção deve realçar ao abrir (vindo do "Virou ação ↗")
  destacarAcao: null,

  // A seleção sobrevive ao F5 pelo mesmo par que guarda a seção e o negócio
  // (`App.guardarLugar`/`App.lugarGuardado`). Uma chave só, no formato
  // "TIPO:id", porque o par é sempre lido junto — duas chaves abririam a porta
  // para um tipo sem id depois de uma gravação pela metade.
  LUGAR_NO: 'pe_plano_no',

  guardarNo() {
    const s = this.selecao;
    App.guardarLugar(this.LUGAR_NO, s ? `${s.tipo}:${s.id ?? ''}` : '');
  },


  /**
   * A chave de busca de quem responde por uma ação: nome e e-mail juntos, já
   * normalizados. Vazia quando não há dono — e é esse vazio, não um rótulo, que
   * o filtro «Sem usuário» procura.
   *
   * Uma fonte só para o `data-quem` do cartão e para a lista do cabeçalho:
   * escritas separadas, um acento tratado de um jeito aqui e de outro ali faria
   * a pessoa aparecer na lista e a escolha dela não devolver nada.
   */
  chaveQuem(a) {
    return a.quem ? this.normalizar(`${a.quem} ${a.quem_email || ''}`.trim()) : '';
  },

  /**
   * Quem aparece na lista do filtro de pessoa, em ordem alfabética e sem
   * repetir. São DUAS fontes, e nenhuma delas basta sozinha:
   *
   * - **quem tem ação neste plano** (das próprias ações carregadas): é o
   *   conjunto que consegue devolver resultado, e o único que traz o e-mail.
   *   Inclui quem já foi desativado mas ainda carrega ação — justamente as que
   *   precisam ser reatribuídas, e deixá-las fora esconderia o problema;
   * - **os responsáveis ativos** (`/api/responsaveis`, os mesmos nomes que o
   *   formulário da ação oferece): quem ainda não recebeu nada aparece na
   *   lista, e escolher a pessoa devolve tela vazia — que é a resposta certa
   *   para "o que fulano tem?" quando fulano não tem nada. Essa rota devolve
   *   só nomes, então essas entradas ficam sem e-mail.
   *
   * A chave é o NOME normalizado, não o nome cru: as duas fontes escrevem a
   * mesma pessoa com acentuação e caixa diferentes, e sem normalizar ela
   * entrava duas vezes na lista. Quem veio das ações tem precedência, porque
   * carrega o e-mail.
   */
  pessoasParaFiltro(projetos, ativos) {
    const porChave = new Map();
    (projetos || []).forEach((p) => (p.desdobramentos || []).forEach((a) => {
      if (!a.quem) return;
      const chave = this.normalizar(a.quem);
      if (!porChave.has(chave)) porChave.set(chave, { nome: a.quem, email: a.quem_email || '' });
    }));
    (ativos || []).forEach((nome) => {
      const chave = this.normalizar(String(nome));
      if (chave && !porChave.has(chave)) porChave.set(chave, { nome: String(nome), email: '' });
    });
    return [...porChave.values()].sort((x, y) => x.nome.localeCompare(y.nome, 'pt-BR'));
  },


  /* ── O filtro, agora de DADOS ─────────────────────────────────────────────
   * Ele era filtro de DOM: escondia cartão, frente e projeto por `d-none` e
   * ABRIA os acordeões à força para o resultado aparecer. Não havia outro
   * jeito numa árvore — a ação encontrada podia estar dentro de dois níveis
   * recolhidos.
   *
   * Com a trilha isso acabou. A lista do centro é derivada: filtra-se a lista
   * de ações ANTES de pintar, e quem está fora não chega a virar linha. O que
   * o acordeão à força fazia, a trilha faz melhor — com filtro ativo o centro
   * mostra os acertos do PLANO INTEIRO, agrupados por projeto e frente, e a
   * trilha marca os nós que têm acerto. Procurar deixou de depender de onde
   * você está.
   *
   * As duas precisões continuam inteiras, e não são redundantes: a PALAVRA é o
   * caminho largo (casa também o nome e o e-mail de quem responde, e por isso
   * "ana" também acha "semana"); o RESPONSÁVEL é o exato (casa só contra a
   * pessoa). Quem digita "Ana" no primeiro quer as ações dela sem saber onde o
   * nome aparece; quem usa o segundo quer as dela e nada mais.
   */
  temFiltro() {
    return !!(this.filtroTexto.trim() || this.filtroResponsavel.trim() || this.filtroStatus);
  },

  /**
   * Casa UMA ação contra os três campos do filtro.
   *
   * `projeto` e `frente` entram porque procurar pelo nome da frente deve
   * trazê-la inteira — era o que o filtro de DOM fazia ao casar contra o
   * título do bloco.
   */
  acaoCasa(a, projeto, frente) {
    const texto = this.normalizar(this.filtroTexto.trim());
    if (texto) {
      const alvo = this.normalizar([
        a.o_que, a.como, a.quem, a.quem_email, a.onde, a.por_que,
        projeto?.titulo, frente?.titulo,
      ].filter(Boolean).join(' '));
      if (!alvo.includes(texto)) return false;
    }
    const resp = this.filtroResponsavel.trim();
    if (resp) {
      // «Sem usuário» procura a chave VAZIA, não um rótulo: é a ação órfã, a
      // de quem saiu do cadastro sem que ninguém assumisse a carteira.
      if (this.normalizar(resp) === this.normalizar(this.ROTULO_SEM_DONO)) {
        if (this.chaveQuem(a)) return false;
      } else if (!this.chaveQuem(a).includes(this.normalizar(resp))) {
        return false;
      }
    }
    // Pelo CÓDIGO da situação, nunca pelo rótulo, que é refém da redação
    if (this.filtroStatus && a.status !== this.filtroStatus) return false;
    return true;
  },

  /**
   * Toda ação do plano que passa no filtro, com o projeto e a frente de cada
   * uma — é o que o centro mostra quando há filtro, e o que marca a trilha.
   */
  acertosDoPlano(projetos) {
    const fora = [];
    projetos.forEach((p) => {
      const porFrente = new Map((p.iniciativas || []).map((i) => [Number(i.id), i]));
      (p.desdobramentos || []).forEach((a) => {
        const frente = porFrente.get(Number(a.iniciativa_id)) || null;
        if (this.acaoCasa(a, p, frente)) fora.push({ acao: a, projeto: p, frente });
      });
    });
    return fora;
  },

  // As chaves de nó que têm acerto, para a trilha acender quem responde à
  // pesquisa — inclusive nós que não estão selecionados.
  nosComAcerto(acertos) {
    const chaves = new Set();
    acertos.forEach(({ projeto, frente }) => {
      chaves.add(`PROJETO:${projeto.id}`);
      if (frente) chaves.add(`FRENTE:${frente.id}`);
    });
    return chaves;
  },
  /**
   * Panorama de um conjunto de ações: barra da média, o percentual e quantas
   * estão atrasadas. É o MESMO bloco no projeto e na iniciativa — escritos
   * separados, os dois níveis divergiriam na primeira mudança de regra (o
   * "atrasada(s)" do projeto já contava por status, e a iniciativa não contava
   * nada). A média é aritmética simples entre as ações, como sempre foi no
   * projeto: ponderar por esforço exigiria um peso que o cadastro não tem.
   *
   * Ação CANCELADA fica fora da conta — no numerador E no denominador. Ela não
   * é trabalho parado em 0%: é trabalho que não será feito, e contá-la afundava
   * o percentual da frente por algo que ninguém vai executar (com uma ação
   * pronta e outra cancelada, a frente mostrava 50%). É a mesma regra que a
   * Consolidacao já aplica ao status e ao prazo do projeto e da frente.
   */
  panorama(acoes, marcaMedia, marcaBarra, classe = '', titulo = 'Progresso médio das ações') {
    const contam = acoes.filter((a) => a.status !== 'CANCELADO');
    const media = contam.length
      ? Math.round(contam.reduce((s, a) => s + Number(a.progresso), 0) / contam.length)
      : 0;
    // O antigo "N atrasada(s)" saiu daqui: o resumo por situação, no cabeçalho,
    // diz a mesma coisa com o percentual junto — e dizer duas vezes obrigava a
    // conferir se os dois números batiam.
    return `<div class="d-flex align-items-center gap-2 mt-1 panorama-execucao ${classe}">
      <div class="faixa-progresso flex-grow-1" title="${Modal.esc(titulo)}">
        <span ${marcaBarra} style="width:${media}%"></span>
      </div>
      <span class="valor-progresso" ${marcaMedia}>${media}%</span>
    </div>`;
  },

  /**
   * Resumo por situação: um selo por situação PRESENTE, com a contagem e o
   * percentual sobre o total de ações daquele nível.
   *
   * Vai no cabeçalho do projeto e no da frente de trabalho, com o mesmo código:
   * a pergunta é a mesma nos dois — "como está a execução daqui?" —, e escritos
   * separados os dois níveis divergiriam na primeira mudança de regra, como já
   * aconteceu com o panorama antes de ele virar um bloco só.
   *
   * A situação que não aparece **não é mostrada com zero**: numa fila de sete
   * selos, seis deles em zero, o único que importa fica escondido no meio. Vale
   * também para o projeto com `apenas`: sem ação atrasada, nenhum selo — a
   * ausência é a boa notícia, e um "Atrasada: 0 (0%)" em toda linha treinaria o
   * olho a pular justamente o selo que importa quando ele deixar de ser zero.
   *
   * `apenas` limita quais situações entram. O PROJETO usa isso para mostrar só
   * o atraso: no nível de cima a pergunta é uma — "o que está fora do prazo, e
   * quanto isso é do todo?". A distribuição inteira é da frente de trabalho,
   * onde ela cabe num punhado de ações e ainda se lê.
   *
   * O percentual é arredondado e por isso pode somar 99% ou 101% — a contagem é
   * que manda, e o `title` traz "N de T ações" para quem precisa do número
   * exato. Fingir uma soma redonda exigiria distribuir sobra entre as fatias, o
   * que faria um selo mostrar um percentual que não é o dele. O denominador é
   * sempre o TOTAL do nível, mesmo com `apenas`: o percentual do atraso é sobre
   * todas as ações criadas, não sobre as que sobraram do filtro.
   */
  resumoStatus(acoes, apenas = null) {
    const total = (acoes || []).length;
    if (!total) return '';
    return ORDEM_RESUMO
      .filter((st) => !apenas || apenas.includes(st))
      .map((st) => [st, acoes.filter((a) => a.status === st).length])
      .filter(([, n]) => n > 0)
      .map(([st, n]) => {
        const [rot] = STATUS_ACAO[st] || [st];
        const pct = Math.round((n * 100) / total);
        // A cor vem por CLASSE (`st-…`), nunca por `style`: o mesmo selo é
        // desenhado dentro do popover, cujo conteúdo passa pelo sanitizador do
        // Bootstrap — ele descarta `style`, e as cores sumiriam só de lá.
        return `<span class="selo-resumo st-${st}"
          title="${Modal.esc(rot)}: ${n} de ${total} ação(ões)">
          <span class="ponto-resumo"></span>${Modal.esc(rot)}: ${n} (${pct}%)</span>`;
      }).join('');
  },

  /**
   * O conteúdo do popover de resumo: uma linha por situação, com o NOME à
   * esquerda e a contagem com o percentual à direita.
   *
   * Vale para o projeto e para a frente — a diferença é só o conjunto de ações
   * que entra. Aqui aparecem TODAS as situações presentes, inclusive no
   * projeto, onde o cabeçalho mostra só o atraso: o selo responde "tem atraso?"
   * de relance e o popover responde "e o resto?" para quem parar em cima.
   *
   * Alinhado em coluna de propósito: em linha corrida, o olho procurava o
   * número no meio do texto de cada situação, e os números são justamente o que
   * se compara entre as linhas.
   */
  conteudoPopover(acoes, extra = null) {
    const linhaExtra = extra
      ? `<div class="total-resumo"><span>${Modal.esc(extra[0])}</span><span>${Modal.esc(extra[1])}</span></div>`
      : '';
    const total = (acoes || []).length;
    if (!total) {
      return `<div class="text-muted small">Nenhuma ação aqui ainda.</div>${linhaExtra}`;
    }
    const linhas = ORDEM_RESUMO
      .map((st) => [st, acoes.filter((a) => a.status === st).length])
      .filter(([, n]) => n > 0)
      .map(([st, n]) => {
        const [rot] = STATUS_ACAO[st] || [st];
        const pct = Math.round((n * 100) / total);
        return `<div class="linha-resumo st-${st}">
          <span class="nome-status"><span class="ponto-resumo"></span>${Modal.esc(rot)}</span>
          <span class="qtd-status">${n} (${pct}%)</span>
        </div>`;
      }).join('');
    return `${linhas}<div class="total-resumo"><span>Total</span><span>${total} ação(ões)</span></div>${linhaExtra}`;
  },
  popoversResumo: [],

  /**
   * Liga os popovers do resumo, um por título (projeto e frente).
   *
   * O conteúdo é montado AQUI e passado por opção, não por `data-bs-content`:
   * no atributo ele precisaria ser escapado duas vezes (é HTML dentro de HTML),
   * e a primeira aspa de um título com aspas quebraria a marcação.
   *
   * As instâncias antigas são DESCARTADAS a cada pintura: a seção se repinta e
   * os elementos antigos saem do documento, mas o balão que o Bootstrap pendura
   * no `<body>` fica — sem o `dispose`, uma tarde de uso deixa dezenas deles
   * empilhados fora da tela.
   */
  ligarPopoversResumo(el, conteudos) {
    this.popoversResumo.forEach((p) => p.dispose());
    this.popoversResumo = [];
    if (!window.bootstrap?.Popover) return;
    el.querySelectorAll('[data-popover-resumo]').forEach((alvo) => {
      const html = conteudos.get(alvo.dataset.popoverResumo);
      if (!html) return;
      this.popoversResumo.push(new bootstrap.Popover(alvo, {
        content: html,
        html: true,
        // `focus` junto do `hover` para quem navega por teclado — e `container:
        // body` porque o cartão do projeto tem `overflow` próprio, que cortaria
        // o balão pela metade.
        trigger: 'hover focus',
        placement: 'bottom',
        container: 'body',
        customClass: 'popover-resumo',
      }));
    });
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

  /**
   * A frase da repetição ("toda quarta-feira e sábado", "todo dia 5 e 20").
   *
   * Mora fora da pintura porque a gaveta e a ficha do celular dizem a mesma
   * coisa, e as duas grades podem vencer em vários dias — escrita duas vezes,
   * a frase divergiria na primeira correção.
   */
  fraseRepeticao(a) {
    const dias = this.diasDaAcao(a);
    if (!dias.length) return '';
    const enumerar = (lista) => (lista.length > 1
      ? `${lista.slice(0, -1).join(', ')} e ${lista.at(-1)}`
      : String(lista[0] ?? ''));
    if (a.recorrencia === 'SEMANAL') {
      return `toda ${enumerar(dias.map(
        (d) => (DIAS_SEMANA.find(([v]) => v === d) || [, ''])[1].toLowerCase()))}`;
    }
    if (a.recorrencia === 'MENSAL') return `todo dia ${enumerar(dias)}`;
    return '';
  },

  // Sempre com os DOIS centavos: `toLocaleString` sozinho corta o zero à
  // direita e R$ 1.500,50 saía como "R$ 1.500,5", que não é como se escreve
  // dinheiro — e ainda parecia valor truncado.
  dinheiro(v) {
    if (v === null || v === undefined || v === '') return '';
    return `R$ ${Number(v).toLocaleString('pt-BR',
      { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  },

  // Barra de leitura (não a arrastável): o número ao lado, porque proporção
  // sozinha não se lê em tabela densa.
  barraLeitura(pct, marcaBarra = '', marcaMedia = '') {
    return `<span class="barra-plano" role="img" aria-label="${pct}% concluído">
        <span style="width:${pct}%" ${marcaBarra}></span>
      </span><span class="pct-plano" ${marcaMedia}>${pct}%</span>`;
  },

  /* ── A trilha ─────────────────────────────────────────────────────────────
   * O plano inteiro numa coluna: a fila de pendências no topo, os projetos
   * abaixo e, sob o projeto onde se está, as frentes dele. É ela que dá o
   * contexto que a pilha de cabeçalhos grudados dava antes — e de graça, sem
   * medir altura nenhuma, sem escada de z-index e sem comer a tela.
   *
   * Só o projeto ABERTO mostra as frentes. Mostrar as de todos devolveria a
   * lista inteira em três níveis, que é justamente o que saiu daqui.
   */
  trilha(projetos, pendentes, acertos) {
    const comAcerto = this.temFiltro() ? this.nosComAcerto(acertos) : null;
    const sel = this.selecao;
    const marca = (chave) => (comAcerto && comAcerto.has(chave) ? ' tem-acerto' : '');

    const noFila = pendentes.length ? `
      <button type="button" class="no-plano no-fila${sel?.tipo === 'FILA' ? ' sel' : ''}"
        data-no="FILA" aria-current="${sel?.tipo === 'FILA'}">
        <span class="no-titulo">Aguardando plano de ação</span>
        <span class="badge text-bg-warning">${pendentes.length}</span>
      </button>` : '';

    const nos = projetos.map((p) => {
      const acoes = p.desdobramentos || [];
      const aberto = this.trilhaAberta.has(Number(p.id));
      const selP = sel?.tipo === 'PROJETO' && Number(sel.id) === Number(p.id);
      const frentes = aberto ? (p.iniciativas || []).map((ini) => {
        const selF = sel?.tipo === 'FRENTE' && Number(sel.id) === Number(ini.id);
        return `
        <button type="button" class="no-plano no-frente${selF ? ' sel' : ''}${marca(`FRENTE:${ini.id}`)}"
          data-no="FRENTE" data-id="${ini.id}" aria-current="${selF}">
          <span class="no-titulo">${Modal.esc(ini.titulo)}</span>
          <span class="no-linha2">${this.barraLeitura(this.mediaDe(ini.acoes || []),
    `data-barra-no="FRENTE:${ini.id}"`, `data-media-no="FRENTE:${ini.id}"`)}
            ${this.resumoStatus(ini.acoes || [], ['ATRASADO'])}</span>
        </button>`;
      }).join('') : '';
      return `
      <button type="button" class="no-plano no-projeto${selP ? ' sel' : ''}${marca(`PROJETO:${p.id}`)}"
        data-no="PROJETO" data-id="${p.id}" aria-current="${selP}"
        aria-expanded="${aberto}">
        <span class="no-linha1">
          <span class="seta-no" aria-hidden="true">${aberto ? '▾' : '▸'}</span>
          <span class="no-titulo">${Modal.esc(p.titulo)}</span>
          ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning">Prioritário</span>' : ''}
        </span>
        <span class="no-linha2">${this.barraLeitura(this.mediaDe(acoes),
    `data-barra-no="PROJETO:${p.id}"`, `data-media-no="PROJETO:${p.id}"`)}
          ${this.resumoStatus(acoes, ['ATRASADO'])}</span>
      </button>${frentes}`;
    }).join('');

    const totalAcoes = projetos.reduce((n, p) => n + (p.desdobramentos || []).length, 0);
    return `
      <div class="trilha-topo">
        <strong>Plano de ação</strong>
        <span class="text-muted small">${projetos.length} projeto(s) · ${totalAcoes} ação(ões)</span>
      </div>
      ${noFila}
      ${nos || '<p class="text-muted small p-2 mb-0">Nenhum projeto cadastrado.</p>'}`;
  },

  // A mesma conta de `panorama`, isolada para a trilha e os KPIs: média
  // aritmética simples com CANCELADO fora do numerador E do denominador.
  mediaDe(acoes) {
    const valem = acoes.filter((a) => a.status !== 'CANCELADO');
    if (!valem.length) return 0;
    return Math.round(valem.reduce((s, a) => s + Number(a.progresso || 0), 0) / valem.length);
  },

  /* ── O centro ─────────────────────────────────────────────────────────── */

  kpis(acoes) {
    const atrasadas = acoes.filter((a) => a.status === 'ATRASADO').length;
    const concluidas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
    const campo = (rot, val, alerta = false) => `
      <div class="kpi-plano"><div class="kpi-rot">${rot}</div>
        <div class="kpi-val${alerta && val ? ' alerta' : ''}" data-kpi="${rot}">${val}</div></div>`;
    return `<div class="kpis-plano">
      ${campo('Ações', acoes.length)}
      ${campo('Progresso médio', `${this.mediaDe(acoes)}%`)}
      ${campo('Atrasadas', atrasadas, true)}
      ${campo('Concluídas', concluidas)}
    </div>`;
  },

  /**
   * A linha da ação.
   *
   * Os atributos são os MESMOS do cartão antigo — `data-card-acao`,
   * `data-cadeado`, `data-status`, `data-quem` — e isso é decisão, não
   * sobra: é o que mantém o cadeado de edição concorrente (`cadeado.js`, que
   * acha o alvo por `closest`) funcionando sem uma linha de mudança, e o que
   * deixa boa parte da bateria mirando onde já mirava.
   *
   * Sem barra arrastável: ela foi para a gaveta. Numa tabela densa a barra
   * convida a arrastar enquanto se lê, e a leitura da reunião é justamente
   * passar o olho por trinta linhas.
   */
  linhaAcao(a, grupo = '') {
    const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
    const pct = a.status === 'CANCELADO' ? 0 : a.progresso;
    const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
    const repete = this.fraseRepeticao(a);
    const selos = (Number(a.comentarios) || 0) + (Number(a.anexos) || 0);
    const aberta = Number(this.acaoAberta) === Number(a.id);
    return `<tr class="linha-acao${aberta ? ' sel' : ''}" data-card-acao="${a.id}"
      data-cadeado="desdobramento:${a.id}"
      data-status="${a.status}" data-quem="${Modal.esc(this.chaveQuem(a))}" tabindex="0">
      ${grupo}
      <td><span class="badge ${classe}">${rotulo}</span></td>
      <td class="col-oque">${Modal.esc(a.o_que)}
        ${selos ? `<span class="clipe-plano" title="${a.comentarios} comentário(s), ${a.anexos} anexo(s)">📎 ${selos}</span>` : ''}</td>
      <td>${a.quem
    ? Modal.esc(a.quem)
    : '<span class="selo-sem-usuario" title="Ninguém responde por esta ação: ela não entra em cobrança nenhuma">Sem usuário</span>'}</td>
      <td class="col-quando">${prazo ? Modal.esc(prazo) : '—'}${repete ? `<span class="repete-plano">${Modal.esc(repete)}</span>` : ''}</td>
      <td class="col-progresso">${this.barraLeitura(pct)}</td>
    </tr>`;
  },

  cabecalhoTabela(comGrupo = false) {
    return `<thead><tr>
      ${comGrupo ? '<th class="col-grupo">Onde</th>' : ''}
      <th>Situação</th><th>O quê</th><th>Quem</th><th>Quando</th><th>Progresso</th>
    </tr></thead>`;
  },

  /**
   * O centro: o caminho, os KPIs e a tabela.
   *
   * Com filtro ativo ele deixa de ser "as ações deste nó" e passa a ser "o que
   * a pesquisa achou no plano inteiro", com uma coluna a mais dizendo de onde
   * cada linha veio. É o que substitui abrir acordeão à força.
   */
  centro(projetos, pendentes, acertos) {
    if (this.temFiltro()) {
      const linhas = acertos.map(({ acao, projeto, frente }) => this.linhaAcao(acao,
        `<td class="col-grupo">${Modal.esc(projeto.titulo)}${frente ? ` › ${Modal.esc(frente.titulo)}` : ''}</td>`))
        .join('');
      return `
        <div class="caminho-plano">
          <strong>Resultado da pesquisa</strong>
          <span class="text-muted small">${acertos.length} ação(ões) no plano inteiro</span>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-limpar-filtro>Limpar pesquisa</button>
        </div>
        ${this.kpis(acertos.map((x) => x.acao))}
        <div class="rolagem-plano">
          ${linhas
    ? `<table class="tabela-plano">${this.cabecalhoTabela(true)}<tbody>${linhas}</tbody></table>`
    : '<p class="text-muted p-3" data-filtro-vazio>Nenhuma ação encontrada com a pesquisa atual.</p>'}
        </div>`;
    }

    const sel = this.selecao;
    if (!sel) {
      return '<p class="text-muted p-3">Cadastre um projeto para começar o plano de ação.</p>';
    }
    if (sel.tipo === 'FILA') {
      return `<div class="caminho-plano"><strong>Aguardando plano de ação</strong>
          <span class="text-muted small">${pendentes.length} pendência(s)</span></div>
        <div class="rolagem-plano p-2">${this.cartaoIdeiasAcao(pendentes)}</div>`;
    }

    const p = this.projetoDoNo(projetos);
    const frente = this.frenteDoNo(projetos);
    const acoes = this.acoesDoNo(projetos);
    const pode = App.podeEditar();
    const botoes = pode ? (frente ? `
      <span class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-verde" data-nova-acao-proj="${p.id}">+ Ação</button>
        <button class="btn btn-sm btn-outline-secondary" data-editar-ini="${frente.id}" data-proj="${p.id}">Editar frente</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir-ini="${frente.id}" title="Excluir frente" aria-label="Excluir frente">×</button>
      </span>` : `
      <span class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-verde" data-nova-ini="${p.id}">+ Iniciativa</button>
        <button class="btn btn-sm btn-verde" data-nova-acao-proj="${p.id}">+ Ação</button>
        <button class="btn btn-sm btn-outline-secondary" data-editar-proj="${p.id}">Editar projeto</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir-proj="${p.id}" title="Excluir projeto" aria-label="Excluir projeto">×</button>
      </span>`) : '';

    const detalhes = [
      p.ano && `<strong>Ano:</strong> ${p.ano}`,
      p.responsavel && `<strong>Responsável:</strong> ${Modal.esc(p.responsavel)}`,
      this.periodo(p.data_inicio, p.data_fim, p.prazo)
        && `<strong>Prazo (das ações):</strong> ${Modal.esc(this.periodo(p.data_inicio, p.data_fim, p.prazo))}`,
    ].filter(Boolean).join(' · ');
    // De que decisão a frente nasceu. Mora aqui, e não no projeto, desde que a
    // escolha passou a ser da FRENTE: um projeto executa pedaços de mais de uma
    // decisão, e a linha no projeto diria só a primeira delas.
    const origem = frente?.escolha_origem
      ? `<div class="small text-muted">↳ Escolha da cascata: “${Modal.esc(String(frente.escolha_origem).slice(0, 90))}”</div>`
      : '';
    const descricao = (frente?.descricao || p.descricao)
      ? `<div class="small text-muted">${Modal.esc(frente?.descricao || p.descricao)}</div>` : '';

    return `
      <div class="caminho-plano">
        ${frente ? `<span class="raiz-caminho">${Modal.esc(p.titulo)} ›</span><strong>${Modal.esc(frente.titulo)}</strong>`
    : `<strong>${Modal.esc(p.titulo)}</strong>`}
        ${this.resumoStatus(acoes, ['ATRASADO'])}
        ${botoes}
      </div>
      ${detalhes || descricao || origem ? `<div class="sobre-plano">
        ${detalhes ? `<div class="small text-muted">${detalhes}</div>` : ''}
        ${descricao}${origem}
        <!-- A conversa é do PROJETO e da AÇÃO, nunca da frente: a API só
             valida esses dois tipos de referência (ComentarioController::
             validarRef), e um botão aqui no nó da frente pediria ao servidor
             um vínculo que ele recusa. -->
        ${frente ? '' : this.botaoComentarios('PROJETO', p.id, p.comentarios, p.anexos)}
      </div>` : ''}
      ${this.kpis(acoes)}
      <div class="rolagem-plano">
        ${acoes.length
    ? `<table class="tabela-plano">${this.cabecalhoTabela()}<tbody>${acoes.map((a) => this.linhaAcao(a)).join('')}</tbody></table>`
    : `<p class="text-muted p-3">${frente ? 'Nenhuma ação nesta frente.' : 'Nenhuma ação neste projeto.'}</p>`}
      </div>`;
  },

  /* ── A gaveta ─────────────────────────────────────────────────────────────
   * O detalhe de UMA ação, ao lado da lista — não no lugar dela. É aqui que
   * moram os campos que a tabela não comporta e o ajuste de progresso.
   *
   * O método em uso tem CINCO perguntas, não sete: o quê, como, quem, quando e
   * quanto. "Onde" e "Por quê" saíram do formulário há tempos (`camposAcao` os
   * mantém como `hidden` só para não apagar o que já estava gravado), e por
   * isso aparecem aqui SÓ quando o registro antigo tem valor — dar-lhes uma
   * linha fixa e vazia ensinaria um método que a cooperativa não pratica.
   */
  gaveta(projetos) {
    const a = this.acaoAberta ? this.acaoPorId(projetos, this.acaoAberta) : null;
    if (!a) {
      return `<div class="gaveta-vazia text-muted small">
        Escolha uma ação na lista para ver o detalhe.</div>`;
    }
    const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
    const [rotPrio] = PRIORIDADES[a.prioridade] || PRIORIDADES.MEDIA;
    const cancelada = a.status === 'CANCELADO';
    const pct = cancelada ? 0 : a.progresso;
    const pode = App.podeEditar();
    const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
    const repete = this.fraseRepeticao(a);
    const linha = (dt, dd) => (dd ? `<dt>${dt}</dt><dd>${dd}</dd>` : '');
    const timeline = this.comentariosAbertos?.refTipo === 'DESDOBRAMENTO'
      && this.comentariosAbertos?.refId === Number(a.id)
      ? `<div id="comentarios-DESDOBRAMENTO-${a.id}" class="mt-2"></div>` : '';

    return `
      <div class="gaveta-topo">
        <div><div class="gaveta-rot">Ação</div>
          <h4 data-cadeado="desdobramento:${a.id}">${Modal.esc(a.o_que)}</h4></div>
        <button type="button" class="btn-fechar-gaveta" data-fechar-gaveta
          aria-label="Fechar detalhe" title="Fechar detalhe">✕</button>
      </div>
      <div class="gaveta-situacao">
        <span class="badge ${classe}">${rotulo}</span>
        ${pode && !cancelada ? `
          <input type="range" class="faixa-verde flex-grow-1" min="0" max="100" step="5"
            style="--pct:${pct}%" value="${pct}"
            data-progresso="${a.id}" data-proj="${a.projeto_id}"
            title="Arraste para ajustar o progresso" aria-label="Progresso da ação">
          <span class="valor-progresso" data-rotulo="${a.id}">${pct}%</span>`
    : `<span class="faixa-progresso flex-grow-1 ${cancelada ? 'inativa' : ''}"
            title="${cancelada ? 'Ação cancelada — sem progresso e fora da média' : `${pct}%`}">
            <span style="width:${pct}%"></span></span>
          <span class="valor-progresso ${cancelada ? 'inativo' : ''}">${pct}%</span>`}
      </div>
      <dl class="campos-acao">
        ${linha('Como', Modal.esc(a.como || ''))}
        ${a.quem
    ? linha('Quem', Modal.esc(a.quem))
    : '<dt>Quem</dt><dd><span class="selo-sem-usuario" title="Ninguém responde por esta ação: ela não entra em cobrança nenhuma">Sem usuário</span></dd>'}
        ${linha('Quando', prazo ? Modal.esc(prazo) : '')}
        ${linha('Repete', repete ? Modal.esc(repete) : '')}
        ${linha('Ganhos', this.dinheiro(a.quanto))}
        ${linha('Prioridade', rotPrio)}
        ${linha('Onde', a.onde ? Modal.esc(a.onde) : '')}
        ${linha('Por quê', a.por_que ? Modal.esc(a.por_que) : '')}
      </dl>
      <div class="gaveta-botoes">
        ${this.botaoComentarios('DESDOBRAMENTO', a.id, a.comentarios, a.anexos)}
        ${pode ? `
          <button class="btn btn-sm btn-outline-secondary" data-editar-desd="${a.id}"
            data-proj="${a.projeto_id}">Editar ação</button>
          <button class="btn btn-sm btn-outline-danger" data-excluir-desd="${a.id}"
            title="Excluir ação" aria-label="Excluir ação">×</button>` : ''}
      </div>
      ${timeline}`;
  },

  /* ── Celular ──────────────────────────────────────────────────────────────
   * Três telas, uma seleção só: lista → ações → detalhe. Não é a mesma tela
   * estreitada — a tabela de cinco colunas não se lê em 390px, e a ficha sim.
   *
   * As duas composições saem da MESMA pintura, como a Matriz de Impacto já
   * faz (`impacto.js:136` desenha a tabela, `:171` as fichas). Quem troca é o
   * CSS; o JS só diz em que passo a navegação está, no `data-passo`.
   *
   * O que NÃO dá para reusar aqui é `Diag.ligarSeletorCategoriaMovel`: o
   * `d-md-block` dele aplicado a `<td>`/`<th>` desmonta a tabela no
   * computador, e o estado dele é global (está anotado em `impacto.js:162`).
   */
  fichasCelular(projetos, acertos) {
    const lista = this.temFiltro()
      ? acertos
      : this.acoesDoNo(projetos).map((acao) => ({ acao, projeto: null, frente: null }));
    if (!lista.length) return '<p class="text-muted p-3">Nenhuma ação aqui.</p>';
    return lista.map(({ acao: a, projeto, frente }) => {
      const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
      const pct = a.status === 'CANCELADO' ? 0 : a.progresso;
      const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
      return `<button type="button" class="ficha-acao" data-card-acao="${a.id}"
        data-cadeado="desdobramento:${a.id}"
        data-status="${a.status}" data-quem="${Modal.esc(this.chaveQuem(a))}">
        ${projeto ? `<span class="ficha-onde">${Modal.esc(projeto.titulo)}${frente ? ` › ${Modal.esc(frente.titulo)}` : ''}</span>` : ''}
        <span class="ficha-oque">${Modal.esc(a.o_que)}</span>
        <span class="ficha-meta"><span class="badge ${classe}">${rotulo}</span>
          ${prazo ? `<span class="ficha-quando">${Modal.esc(prazo)}</span>` : ''}</span>
        <span class="ficha-meta">${a.quem ? Modal.esc(a.quem)
    : '<span class="selo-sem-usuario">Sem usuário</span>'}
          <span class="ms-auto">${this.barraLeitura(pct)}</span></span>
        <span class="ficha-abrir">Ver detalhe ›</span>
      </button>`;
    }).join('');
  },

  // Em que passo a navegação do CELULAR está. No computador os três blocos
  // estão na tela ao mesmo tempo e este campo não governa nada — por isso ele
  // não entra no que é guardado entre visitas.
  passo: 'TRILHA',

  /* ── Modo documento ───────────────────────────────────────────────────────
   * O dossiê fotografa o `innerHTML` desta seção (`dossie.js:140`, `foto`) e
   * leva a folha ao Conselho. Numa tela mestre-detalhe isso seria veneno: a
   * foto pegaria as ações de UM nó — o que estivesse selecionado — e nada na
   * página diria que o resto do plano ficou de fora. É o mesmo perigo que o
   * comentário de `dossie.js:78-83` já descreve para o filtro, e por isso a
   * vista do documento agora também neutraliza a seleção.
   *
   * Com a bandeira ligada a seção se pinta PLANA: todo projeto, toda frente,
   * toda ação, sem trilha, sem gaveta e sem filtros. Não é uma segunda tela —
   * é a mesma linha de ação (`linhaAcao`) empilhada sob os títulos.
   */
  modoDocumento: false,

  async carregar() {
    const el = document.getElementById('secao-projetos');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    const [projetos, cascata, responsaveis, ideiasAcao, fatoresAcao, cruzamentosAcao,
      cenarioAcao] = await Promise.all([
      App.api(`/api/projetos?planejamento_id=${this.plan.id}`),
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      App.api(`/api/responsaveis?planejamento_id=${this.plan.id}`),
      App.api(`/api/coleta/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
      App.api(`/api/fatores/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
      App.api(`/api/cruzamentos/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
      App.api(`/api/cenario/aguardando-acao?planejamento_id=${this.plan.id}`).catch(() => []),
    ]);
    // Uma fila só: a origem muda o selo e o campo que fecha o vínculo, não o
    // lugar onde a pendência aparece. Duas listas separadas fariam quem
    // acompanha o plano ter de olhar em dois lugares para a mesma pergunta —
    // "o que ainda não virou ação?".
    const pendentes = [
      ...ideiasAcao.map((i) => ({ ...i, origem: 'COLETA', chave: `c${i.id}` })),
      ...fatoresAcao.map((f) => ({ ...f, chave: `f${f.id}` })),
      ...cruzamentosAcao.map((c) => ({ ...c, chave: `x${c.id}` })),
      // A chave é por ORIGEM, não pelo id: `cenario_item` e `fator` numeram
      // separado, e sem o prefixo dois registros diferentes disputariam a
      // mesma linha da fila — o "Virar ação" abriria a pendência errada.
      ...cenarioAcao.map((c) => ({ ...c, chave: `n${c.id}` })),
    ];
    this.cascata = cascata;
    this.responsaveis = responsaveis;
    this.projetos = projetos;
    this.pendentes = pendentes;

    // A navegação vinda da SWOT escolhe o nó ANTES de a seleção guardada ser
    // conferida: ela manda, e o que estava guardado é só o padrão.
    this.prepararDestaque(projetos);
    this.ajustarSelecao(projetos, pendentes);
    if (this.acaoAberta && !this.acaoPorId(projetos, this.acaoAberta)) this.acaoAberta = null;

    if (this.modoDocumento) {
      el.innerHTML = this.documentoPlano(projetos, pendentes);
      return;
    }

    const pessoasFiltro = this.pessoasParaFiltro(projetos, responsaveis);
    el.innerHTML = `
      <!-- O cabeçalho gruda abaixo da topbar, e ele é o ÚNICO degrau grudado
           que sobrou: a pilha de quatro (app → Projetos → projeto → frentes
           percorridas) foi embora junto com a árvore de acordeões. Quem dá o
           contexto agora é a trilha, que não custa altura nenhuma. -->
      <div class="cabecalho-projetos d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Plano de ação — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <div class="filtro-acoes d-flex gap-2 align-items-center">
            <!-- O placeholder é CURTO porque o campo tem 11rem e o texto é
                 cortado no meio: "Pesquisar palavra, pessoa ou e-mail…" morria
                 em "pessoa ou e-", que é pior que não prometer nada. -->
            <input type="search" class="form-control form-control-sm" data-filtro-texto
              value="${Modal.esc(this.filtroTexto)}" placeholder="Palavra ou pessoa…"
              title="Procura no texto da ação, no título da frente e do projeto e também no nome e no e-mail de quem responde pela ação"
              aria-label="Pesquisar ações por palavra, nome ou e-mail do responsável">
            <input type="search" list="lista-responsaveis-acoes" data-filtro-responsavel
              class="form-control form-control-sm campo-responsavel"
              value="${Modal.esc(this.filtroResponsavel)}" placeholder="Responsável…"
              aria-label="Filtrar pelo responsável da ação — escolha na lista ou digite parte do nome">
            <datalist id="lista-responsaveis-acoes">
              <!-- Primeira da lista, por pedido: a ação órfã não é cobrada de
                   ninguém, e é a fila que mais precisa ser vista. -->
              <option value="${Modal.esc(this.ROTULO_SEM_DONO)}">ações sem responsável</option>
              ${pessoasFiltro.map((r) =>
    `<option value="${Modal.esc(r.nome)}">${Modal.esc(r.email || 'sem e-mail')}</option>`).join('')}
            </datalist>
            <select class="form-select form-select-sm w-auto" data-filtro-status
              aria-label="Filtrar por situação">
              <option value="">Todas as situações</option>
              ${Object.entries(STATUS_ACAO).map(([v, [rot]]) =>
    `<option value="${v}" ${this.filtroStatus === v ? 'selected' : ''}>${rot}</option>`).join('')}
            </select>
          </div>
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-proj">+ Novo projeto</button>' : ''}
        </div>
      </div>
      <div class="grade-plano" data-grade data-passo="${this.passo}">
        <nav class="trilha-plano" data-trilha aria-label="Projetos e frentes do plano"></nav>
        <section class="centro-plano" data-centro></section>
        <aside class="gaveta-plano" data-gaveta aria-label="Detalhe da ação"></aside>
        <div class="veu-gaveta" data-veu hidden></div>
      </div>`;

    // Os filtros são ligados UMA vez, no esqueleto: a repintura troca só a
    // trilha, o centro e a gaveta, e por isso o foco de quem está digitando
    // sobrevive a cada tecla. Era esse o motivo de o filtro antigo mexer no
    // DOM em vez de recarregar — agora ele filtra dados e o foco continua de pé.
    const campoFiltro = el.querySelector('[data-filtro-texto]');
    campoFiltro?.addEventListener('input', () => {
      this.filtroTexto = campoFiltro.value;
      this.repintar(el);
    });
    // O responsável escuta `input`, não `change`: a caixa é de texto com lista,
    // e escolher um item dispara os DOIS — mas digitar parte do nome só dispara
    // `input`. Com `change`, quem digitasse ficaria sem filtro até sair do campo.
    const campoResp = el.querySelector('[data-filtro-responsavel]');
    campoResp?.addEventListener('input', () => {
      this.filtroResponsavel = campoResp.value;
      this.repintar(el);
    });
    const selFiltro = el.querySelector('[data-filtro-status]');
    selFiltro?.addEventListener('change', () => {
      this.filtroStatus = selFiltro.value;
      this.repintar(el);
    });
    document.getElementById('btn-novo-proj')
      ?.addEventListener('click', () => this.modalProjeto(null, this.projetos));
    Diag.ligarCabecalhoFixo(el, '.cabecalho-projetos');

    this.repintar(el);
  },

  /**
   * Repinta as três zonas e religa o que vive dentro delas.
   *
   * O esqueleto (cabeçalho, filtros, grade) fica de pé: é o que preserva o
   * foco do campo de pesquisa e evita religar os filtros a cada tecla.
   */
  repintar(el) {
    const projetos = this.projetos || [];
    const pendentes = this.pendentes || [];
    const acertos = this.temFiltro() ? this.acertosDoPlano(projetos) : [];
    const grade = el.querySelector('[data-grade]');
    if (!grade) return;

    el.querySelector('[data-trilha]').innerHTML = this.trilha(projetos, pendentes, acertos);
    el.querySelector('[data-centro]').innerHTML = `
      <div class="so-computador">${this.centro(projetos, pendentes, acertos)}</div>
      <div class="so-celular">${this.cabecaCelular(projetos)}${this.fichasCelular(projetos, acertos)}</div>`;
    el.querySelector('[data-gaveta]').innerHTML = this.gaveta(projetos);
    grade.dataset.passo = this.passo;
    grade.classList.toggle('com-gaveta', !!this.acaoAberta);
    el.querySelector('[data-veu]').hidden = !this.acaoAberta;

    // Os popovers de resumo são refeitos a cada pintura, e os antigos
    // DESCARTADOS: o balão que o Bootstrap pendura no <body> não sai com o
    // elemento, e sem o dispose uma tarde de uso deixa dezenas fora da tela.
    const conteudos = new Map();
    projetos.forEach((p) => {
      conteudos.set(`proj-${p.id}`, this.conteudoPopover(p.desdobramentos || []));
      (p.iniciativas || []).forEach((ini) => {
        const [rot] = STATUS_INICIATIVA[ini.status] || [ini.status];
        conteudos.set(`ini-${ini.id}`, this.conteudoPopover(ini.acoes || [], ['Frente', rot]));
      });
    });
    this.ligarPopoversResumo(el, conteudos);

    this.ligarTrilha(el);
    this.ligarCentro(el);
    this.ligarGaveta(el);
    if (this.comentariosAbertos) this.renderComentarios();
    this.realcarLinha(el);
  },

  /** O plano inteiro em folha corrida, para o dossiê. */
  documentoPlano(projetos, pendentes) {
    if (!projetos.length) return '<p class="text-muted">Nenhum projeto cadastrado.</p>';
    const blocos = projetos.map((p) => {
      const acoes = p.desdobramentos || [];
      const detalhes = [
        p.ano && `<strong>Ano:</strong> ${p.ano}`,
        p.responsavel && `<strong>Responsável:</strong> ${Modal.esc(p.responsavel)}`,
      ].filter(Boolean).join(' · ');
      const frentes = (p.iniciativas || []).map((ini) => {
        const suas = ini.acoes || [];
        return `<div class="doc-frente">
          <h3>${Modal.esc(ini.titulo)}</h3>
          <div class="doc-panorama">${this.barraLeitura(this.mediaDe(suas))}
            ${this.resumoStatus(suas, ['ATRASADO'])}</div>
          ${suas.length
    ? `<table class="tabela-plano">${this.cabecalhoTabela()}<tbody>${suas.map((a) => this.linhaAcao(a)).join('')}</tbody></table>`
    : '<p class="text-muted small">Nenhuma ação nesta frente.</p>'}
        </div>`;
      }).join('');
      // Ação sem frente existe no banco (`iniciativa_id` é anulável) e sumiria
      // da folha se o documento lesse só as listas aninhadas.
      const orfas = acoes.filter((a) => !a.iniciativa_id);
      return `<section class="doc-projeto">
        <h2>${Modal.esc(p.titulo)}
          ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning">Prioritário</span>' : ''}</h2>
        ${detalhes ? `<div class="small text-muted">${detalhes}</div>` : ''}
        <div class="doc-panorama">${this.barraLeitura(this.mediaDe(acoes))}
          ${this.resumoStatus(acoes, ['ATRASADO'])}</div>
        ${frentes}
        ${orfas.length ? `<div class="doc-frente"><h3>Sem frente de trabalho</h3>
          <table class="tabela-plano">${this.cabecalhoTabela()}<tbody>${orfas.map((a) => this.linhaAcao(a)).join('')}</tbody></table></div>` : ''}
      </section>`;
    }).join('');
    return `<h1>Plano de ação — ${Modal.esc(App.rotuloContexto())}</h1>${blocos}
      ${pendentes.length ? this.cartaoIdeiasAcao(pendentes) : ''}`;
  },

  // No celular a tela 2 precisa dizer de onde são estas ações e como voltar
  cabecaCelular(projetos) {
    const p = this.projetoDoNo(projetos);
    const frente = this.frenteDoNo(projetos);
    const nome = this.temFiltro() ? 'Resultado da pesquisa'
      : (frente ? frente.titulo : (p ? p.titulo : 'Plano de ação'));
    return `<div class="cabeca-celular">
      <button type="button" class="volta-celular" data-passo-ir="TRILHA"
        aria-label="Voltar para projetos e frentes">‹</button>
      <strong>${Modal.esc(nome)}</strong>
    </div>`;
  },

  /* ── Ligadores ───────────────────────────────────────────────────────── */

  ligarTrilha(el) {
    el.querySelectorAll('[data-no]').forEach((b) => b.addEventListener('click', () => {
      const tipo = b.dataset.no;
      const id = b.dataset.id ? Number(b.dataset.id) : null;
      const mesmoNo = this.selecao?.tipo === tipo && Number(this.selecao?.id) === Number(id);
      // Clicar no projeto onde já se está dobra e desdobra as frentes dele.
      // É o único gesto de abrir/fechar que sobrou na tela, e ele não esconde
      // nada: as ações do projeto continuam no centro, abertas ou não.
      if (tipo === 'PROJETO' && mesmoNo) {
        if (this.trilhaAberta.has(id)) this.trilhaAberta.delete(id);
        else this.trilhaAberta.add(id);
      } else if (tipo === 'PROJETO') {
        this.trilhaAberta.add(id);
      }
      this.selecao = { tipo, id };
      this.acaoAberta = null;
      this.passo = 'ACOES';
      this.guardarNo();
      this.repintar(el);
    }));
  },

  ligarCentro(el) {
    const projetos = this.projetos || [];
    // Escolher a linha abre a gaveta. No celular isso é a terceira tela.
    el.querySelectorAll('[data-card-acao]').forEach((linha) => {
      const abrir = (ev) => {
        if (ev.target.closest('button, a, input, select, textarea, label')) return;
        this.acaoAberta = Number(linha.dataset.cardAcao);
        this.passo = 'DETALHE';
        this.repintar(el);
      };
      linha.addEventListener('click', abrir);
      linha.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(ev); }
      });
    });
    el.querySelectorAll('[data-passo-ir]').forEach((b) => b.addEventListener('click', () => {
      this.passo = b.dataset.passoIr;
      this.repintar(el);
    }));
    el.querySelector('[data-limpar-filtro]')?.addEventListener('click', () => {
      this.filtroTexto = '';
      this.filtroResponsavel = '';
      this.filtroStatus = '';
      const t = el.querySelector('[data-filtro-texto]');
      const r = el.querySelector('[data-filtro-responsavel]');
      const s = el.querySelector('[data-filtro-status]');
      if (t) t.value = '';
      if (r) r.value = '';
      if (s) s.value = '';
      this.repintar(el);
    });

    // A fila de "Aguardando plano de ação", quando o nó dela está escolhido
    el.querySelectorAll('[data-virar-acao]').forEach((b) => b.addEventListener('click', () =>
      this.modalConverterAcao((this.pendentes || []).find((p) => p.chave === b.dataset.virarAcao))));
    el.querySelectorAll('[data-tirar-fila]').forEach((b) => b.addEventListener('click', () => {
      const p = (this.pendentes || []).find((x) => x.chave === b.dataset.tirarFila);
      this.tirarDaFila(b.dataset.tirarFila, p ? (p.texto_tratado || p.texto) : '');
    }));

    el.querySelectorAll('[data-comentarios]').forEach((b) => b.addEventListener('click', () => {
      const [refTipo, refId] = b.dataset.comentarios.split(':');
      const mesmo = this.comentariosAbertos?.refTipo === refTipo
        && this.comentariosAbertos?.refId === Number(refId);
      this.comentariosAbertos = mesmo ? null : { refTipo, refId: Number(refId) };
      this.repintar(el);
    }));

    if (!App.podeEditar()) return;

    el.querySelectorAll('[data-nova-ini]').forEach((b) => b.addEventListener('click', () =>
      this.modalIniciativa(Number(b.dataset.novaIni), null)));
    // Ação nova pelo centro: sem frente decidida quando o nó é o projeto —
    // ela é a primeira pergunta do formulário.
    el.querySelectorAll('[data-nova-acao-proj]').forEach((b) => b.addEventListener('click', () => {
      const frente = this.frenteDoNo(projetos);
      this.modalDesdobramento(Number(b.dataset.novaAcaoProj), null, frente ? Number(frente.id) : null);
    }));
    el.querySelectorAll('[data-editar-proj]').forEach((b) => b.addEventListener('click', () =>
      this.modalProjeto(projetos.find((p) => Number(p.id) === Number(b.dataset.editarProj)), projetos)));
    el.querySelectorAll('[data-editar-ini]').forEach((b) => b.addEventListener('click', () => {
      const proj = projetos.find((p) => Number(p.id) === Number(b.dataset.proj));
      this.modalIniciativa(proj.id,
        (proj.iniciativas || []).find((i) => Number(i.id) === Number(b.dataset.editarIni)));
    }));
    el.querySelectorAll('[data-excluir-proj]').forEach((b) => b.addEventListener('click', async () => {
      // O projeto é ponta de quatro vínculos, e só um deles aparecia na frase
      // antiga ("e todos os seus desdobramentos"). O investimento é o que mais
      // surpreende: ele NÃO some — a FK não tem ON DELETE e ele volta a ser um
      // investimento sem projeto —, mas quem apaga precisa saber que o vínculo
      // que ele montou vai embora.
      const p = projetos.find((x) => Number(x.id) === Number(b.dataset.excluirProj));
      const q = (n, s, pl) => Vinculos.quantos(n, s, pl);
      const aviso = Vinculos.aviso(`Excluir o projeto «${p?.titulo || ''}»?`, {
        some: [
          q((p?.desdobramentos || []).length, 'ação', 'ações'),
          q((p?.iniciativas || []).length, 'iniciativa', 'iniciativas'),
          q(p?.comentarios, 'comentário', 'comentários'),
        ],
        solta: [q(p?.investimentos_vinculados, 'investimento', 'investimentos')],
        nota: 'Não dá para desfazer.',
      });
      const origens = (p?.desdobramentos || []).reduce((n, a) => n + (Number(a.origens) || 0), 0);
      if (origens) {
        this.excluirComOrigens({
          titulo: 'Excluir o projeto', aviso, origens,
          url: `/api/projetos/${b.dataset.excluirProj}/excluir`,
        });
        return;
      }
      if (!confirm(aviso)) return;
      try {
        await App.api(`/api/projetos/${b.dataset.excluirProj}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      // O nó some junto com o projeto: sem zerar a seleção, a próxima carga
      // procuraria um projeto que não existe mais.
      this.selecao = null;
      this.acaoAberta = null;
      this.carregar();
    }));
    el.querySelectorAll('[data-excluir-ini]').forEach((b) => b.addEventListener('click', async () => {
      const iniId = Number(b.dataset.excluirIni);
      const ini = projetos.flatMap((x) => x.iniciativas || []).find((i) => Number(i.id) === iniId);
      const origens = (ini?.acoes || []).reduce((n, a) => n + (Number(a.origens) || 0), 0);
      const depois = () => { this.selecao = null; this.acaoAberta = null; this.carregar(); };
      if (origens) {
        this.excluirComOrigens({
          titulo: 'Excluir a iniciativa',
          aviso: `Excluir a iniciativa «${ini?.titulo || ''}» e todas as ações dentro dela?`,
          origens, url: `/api/iniciativas/${iniId}/excluir`,
        });
        return;
      }
      if (!confirm('Excluir a iniciativa e todas as ações dentro dela?')) return;
      try {
        await App.api(`/api/iniciativas/${iniId}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      depois();
    }));

    // Duplo clique abre a edição do que está sob o cursor — o mesmo que o
    // botão, sem precisar alcançá-lo. O listener vai no CENTRO e não na seção:
    // `el` sobrevive aos recarregamentos (as seções não são destruídas, só
    // ganham `d-none`) e um listener nele empilharia uma cópia por pintura —
    // na terceira visita à tela, um duplo clique abriria três modais.
    el.querySelector('[data-centro]')?.addEventListener('dblclick', (ev) => {
      if (ev.target.closest('button, a, input, select, textarea, label, [id^="comentarios-"]')) return;
      window.getSelection?.()?.removeAllRanges();
      const naAcao = ev.target.closest('[data-card-acao]');
      if (naAcao) {
        const acao = this.acaoPorId(projetos, naAcao.dataset.cardAcao);
        if (acao) this.modalDesdobramento(acao.projeto_id, acao, acao.iniciativa_id);
        return;
      }
      const frente = this.frenteDoNo(projetos);
      const p = this.projetoDoNo(projetos);
      if (frente) this.modalIniciativa(p.id, frente);
      else if (p) this.modalProjeto(p, projetos);
    });
  },

  ligarGaveta(el) {
    const projetos = this.projetos || [];
    const fechar = () => {
      this.acaoAberta = null;
      this.passo = 'ACOES';
      this.repintar(el);
    };
    el.querySelector('[data-fechar-gaveta]')?.addEventListener('click', fechar);
    el.querySelector('[data-veu]')?.addEventListener('click', fechar);
    // `Esc` fecha a gaveta, mas NUNCA quando há um modal aberto: ali o Esc é
    // do formulário, e fechar a gaveta por baixo tiraria o chão de quem salva.
    if (this.acaoAberta && !this.fechaPorEsc) {
      this.fechaPorEsc = (ev) => {
        if (ev.key !== 'Escape') return;
        if (document.querySelector('.modal.show')) return;
        if (!this.acaoAberta) return;
        fechar();
      };
      document.addEventListener('keydown', this.fechaPorEsc);
    }

    if (!App.podeEditar()) return;

    el.querySelectorAll('[data-editar-desd]').forEach((b) => b.addEventListener('click', () => {
      const acao = this.acaoPorId(projetos, b.dataset.editarDesd);
      if (!acao) return;
      // Vale o progresso que está na TELA, que a barra pode ter mudado depois
      // da carga da lista — senão o modal regravaria o valor antigo.
      const barra = el.querySelector(`[data-progresso="${acao.id}"]`);
      const atual = barra ? { ...acao, progresso: Number(barra.value) } : acao;
      this.modalDesdobramento(acao.projeto_id, atual, acao.iniciativa_id);
    }));
    el.querySelectorAll('[data-excluir-desd]').forEach((b) => b.addEventListener('click', async () => {
      const acaoId = Number(b.dataset.excluirDesd);
      const acao = this.acaoPorId(projetos, acaoId);
      if (Number(acao?.origens)) {
        this.excluirComOrigens({
          titulo: 'Excluir a ação',
          aviso: `Excluir a ação «${acao?.o_que || ''}»?`,
          origens: Number(acao.origens), url: `/api/desdobramentos/${acaoId}/excluir`,
        });
        return;
      }
      if (!confirm('Excluir esta ação?')) return;
      await App.api(`/api/desdobramentos/${acaoId}/excluir`, { planejamento_id: this.plan.id });
      this.acaoAberta = null;
      this.carregar();
    }));

    // Progresso ajustado arrastando a barra da GAVETA: a barra e o rótulo
    // acompanham o dedo e a gravação sai só ao soltar.
    el.querySelectorAll('[data-progresso]').forEach((r) => {
      const id = r.dataset.progresso;
      const rotulo = el.querySelector(`[data-rotulo="${id}"]`);
      const pintar = (v) => {
        r.style.setProperty('--pct', `${v}%`);
        if (rotulo) rotulo.textContent = `${v}%`;
      };
      r.addEventListener('input', () => pintar(r.value));
      r.addEventListener('change', async () => {
        const anterior = r.dataset.salvo ?? r.defaultValue;
        const acao = this.acaoPorId(projetos, id);
        const status = acao?.status;
        const voltar = () => { r.value = anterior; pintar(anterior); };

        // Chegar aos 100% CONCLUI, sem perguntar — a pergunta virava mais um
        // clique no gesto mais comum da tela. Exceção: a cancelada não é
        // concluída por arraste; a barra volta ao que estava, em silêncio.
        if (Number(r.value) === 100 && status !== 'CONCLUIDO') {
          if (status === 'CANCELADO') { voltar(); return; }
          try {
            const resp = await App.api(`/api/desdobramentos/${id}/progresso`, {
              planejamento_id: this.plan.id, progresso: 100, concluir: true,
            });
            r.dataset.salvo = String(resp.progresso);
            this.avisarReagendamento(resp);
            App.recarregarSecaoAtiva();
          } catch (e) {
            voltar();
            if (e.codigo !== 'ACAO_CANCELADA') alert(e.message);
          }
          return;
        }

        // Sair dos 100% abre o pop-up: a ação estava concluída, e alguém
        // precisa dizer em que situação ela fica. Fechado sem salvar, o gesto
        // é desfeito — a barra volta ao valor do servidor.
        if (Number(r.value) < 100 && status === 'CONCLUIDO') {
          let gravou = false;
          this.modalStatusAcao(id, Number(r.value), status, () => { gravou = true; });
          document.getElementById('modal-form').addEventListener('hidden.bs.modal', () => {
            if (!gravou) voltar();
          }, { once: true });
          return;
        }

        try {
          await App.api(`/api/desdobramentos/${id}/progresso`, {
            planejamento_id: this.plan.id, progresso: Number(r.value),
          });
          r.dataset.salvo = r.value;
          if (acao) acao.progresso = Number(r.value);
          this.atualizarPanoramas(el);
        } catch (e) {
          voltar();
          // A ação foi cancelada por outra pessoa depois que esta gaveta foi
          // desenhada: a barra aqui não deveria mais existir. Recarregar é o
          // que a troca em `alert` não faria — a tela volta com a faixa inativa.
          if (e.codigo === 'ACAO_CANCELADA') {
            App.recarregarSecaoAtiva();
            return;
          }
          alert(e.message);
        }
      });
    });
  },

  /**
   * Recalcula na tela o que o progresso ajustado afeta: as barras da trilha
   * (do projeto E da frente) e os quatro KPIs do centro.
   *
   * Não é uma repintura: a barra arrastável está sob o dedo de quem acabou de
   * soltar, e refazer a gaveta inteira tiraria o controle do meio do gesto. Os
   * números saem dos dados já atualizados em memória, pelo mesmo caminho de
   * `mediaDe` — CANCELADO fora do numerador e do denominador.
   */
  atualizarPanoramas(el) {
    const projetos = this.projetos || [];
    projetos.forEach((p) => {
      const porNo = [[`PROJETO:${p.id}`, p.desdobramentos || []],
        ...(p.iniciativas || []).map((i) => [`FRENTE:${i.id}`, i.acoes || []])];
      porNo.forEach(([chave, acoes]) => {
        const media = this.mediaDe(acoes);
        const barra = el.querySelector(`[data-barra-no="${chave}"]`);
        const num = el.querySelector(`[data-media-no="${chave}"]`);
        if (barra) barra.style.width = `${media}%`;
        if (num) num.textContent = `${media}%`;
      });
    });
    const acoes = this.temFiltro()
      ? this.acertosDoPlano(projetos).map((x) => x.acao)
      : this.acoesDoNo(projetos);
    const kpi = (rot, valor) => {
      const alvo = el.querySelector(`[data-kpi="${rot}"]`);
      if (alvo) alvo.textContent = valor;
    };
    kpi('Ações', acoes.length);
    kpi('Progresso médio', `${this.mediaDe(acoes)}%`);
    kpi('Atrasadas', acoes.filter((a) => a.status === 'ATRASADO').length);
    kpi('Concluídas', acoes.filter((a) => a.status === 'CONCLUIDO').length);
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

    Modal.abrir({
      titulo: p ? 'Editar projeto' : 'Novo projeto',
      url: p ? `/api/projetos/${p.id}` : '/api/projetos',
      bloqueio: p ? { recurso: 'projeto', registro_id: p.id, planejamento_id: this.plan.id } : null,
      valores: p
        ? { ...p, impacto: p.impacto ?? '', planejamento_id: this.plan.id }
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
      ],
    });
  },

  /**
   * De qual escolha da Cascata nasce o que está sendo executado.
   *
   * O rótulo traz a célula inteira — horizonte, driver, eixo e o começo do
   * texto —, porque é assim que a escolha é reconhecida: "H1 · Aonde Jogar ·
   * Síntese" sozinho não diz QUAL decisão é, e o texto sozinho não diz de que
   * ponto da matriz ele saiu.
   */
  opcoesCascata() {
    const nomeH = (id) => this.cascata.horizontes.find((h) => h.id == id)?.nome || '?';
    const nomeD = (id) => this.cascata.drivers.find((d) => d.id == id)?.nome || '?';
    const nomeE = (id) => (id ? this.cascata.eixos.find((e) => e.id == id)?.nome : 'Síntese') || 'Síntese';
    return [{ valor: '', rotulo: '(não vinculado a uma escolha)' }].concat(
      (this.cascata.escolhas || [])
        .map((e) => ({
          valor: e.id,
          rotulo: `${nomeH(e.horizonte_id)} · ${nomeD(e.driver_id)} · ${nomeE(e.eixo_id)} — `
            + `${String(e.escolha).replace(/\s+/g, ' ').slice(0, 70)}`,
        }))
        .sort((a, b) => a.rotulo.localeCompare(b.rotulo, 'pt-BR')));
  },

  modalIniciativa(projetoId, ini) {
    // A escolha da cascata é da FRENTE (decisão do cliente, 2026-10-26): era do
    // projeto, e um projeto executa pedaços de mais de uma decisão — quem
    // executa um pedaço é a frente. A Matriz de Execução lê por aqui.
    const opcoesCascata = this.opcoesCascata();
    Modal.abrir({
      titulo: ini ? 'Editar iniciativa' : 'Nova iniciativa',
      url: ini ? `/api/iniciativas/${ini.id}` : '/api/iniciativas',
      valores: ini
        ? { ...ini, cascata_id: ini.cascata_id ?? '', planejamento_id: this.plan.id, projeto_id: projetoId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, cascata_id: '' },
      // Sem campo de status: o da frente é consequência das ações (todas
      // concluídas fecham a frente sozinhas — Consolidacao), e um seletor aqui
      // gravaria um valor que a primeira leitura apaga.
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'titulo', rotulo: 'Iniciativa (frente de trabalho)', obrigatorio: true,
          exemplo: 'Ex.: Licenciamento e obra civil',
          ajuda: 'Agrupa as ações de uma mesma frente dentro do projeto.' },
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 2 },
        { nome: 'cascata_id', rotulo: 'Escolha da Cascata que esta frente executa',
          tipo: 'select', opcoes: opcoesCascata,
          ajuda: opcoesCascata.length > 1
            ? 'Liga a frente à decisão que a originou — é o que permite ler, na Cascata, o que cada escolha virou.'
            : 'Nenhuma escolha cadastrada ainda: preencha a Cascata para poder vincular.' },
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

  /**
   * Rótulo, nome da categoria e cor de um fator, seja ele de que etapa for.
   *
   * A fila do plano de ação recebe PESTEL, Porter e SWOT pela mesma porta, mas
   * o vocabulário de cada um mora num lugar diferente: a SWOT tem quadrantes
   * (`Diag.QUADRANTES`) e as outras duas têm categorias em tuplas
   * (`Diag.CATEGORIAS_ETAPA`, no formato `[valor, rótulo, cor, dica]`). Ler os
   * dois formatos aqui, e só aqui, evita que a fila e o modal de conversão
   * repitam o `if (origem === 'SWOT')` cada um do seu jeito — foi assim que a
   * Coleta acabou mostrando rótulos com outra caixa dos da seção.
   *
   * O fallback é o próprio valor cru: categoria desconhecida (etapa nova, dado
   * antigo) aparece feia, mas aparece — sumir do selo seria pior.
   */
  rotuloEtapa(origem) {
    return Diag.ROTULO_ETAPA[origem] || origem || '';
  },

  categoriaDoFator(origem, categoria) {
    if (origem === 'SWOT') {
      return { rotulo: Diag.QUADRANTES[categoria] || categoria || 'SWOT',
        cor: Diag.CORES_QUADRANTE[categoria] || '#007a45' };
    }
    const achado = (Diag.CATEGORIAS_ETAPA[origem] || []).find(([v]) => v === categoria);
    return { rotulo: achado ? achado[1] : (categoria || ''), cor: achado ? achado[2] : '#5a3e2b' };
  },

  rotuloCategoria(origem, categoria) {
    return this.categoriaDoFator(origem, categoria).rotulo;
  },

  corCategoria(origem, categoria) {
    return this.categoriaDoFator(origem, categoria).cor;
  },

  // Pendências encaminhadas ao plano de ação e ainda sem ação criada: ideias da
  // coleta, fatores do PESTEL/Porter/SWOT, cruzamentos (TOWS) e itens da Análise
  // de Cenário, na mesma fila. O selo diz de onde cada uma veio.
  cartaoIdeiasAcao(pendentes) {
    if (!pendentes || !pendentes.length) return '';
    const podeConverter = App.podeEditar();
    // Quatro origens, um selo cada. O do cruzamento mostra o BLOCO (atacar,
    // defender…), que é o que diz de que leitura da SWOT a estratégia nasceu —
    // o rótulo do par já vai no texto.
    const selo = (p) => {
      if (p.origem === 'TOWS') {
        const b = SecaoCruzamentos.bloco(p.categoria);
        return `<span class="badge text-bg-light border" title="Cruzamento da SWOT · ${
          Modal.esc(b?.rotulo || p.categoria)}">TOWS · ${Modal.esc(b?.verbo || p.categoria)}</span>`;
      }
      // O cenário mostra o TIPO — situação atual ou tendência —, que é o que
      // muda a leitura da pendência: uma descreve o hoje, a outra é aposta.
      if (p.origem === 'CENARIO') {
        const t = SecaoCenario.TIPOS[p.categoria];
        return `<span class="badge text-bg-light border" title="Análise de Cenário · ${
          Modal.esc(t?.rotulo || p.categoria)}">Cenário · ${
          Modal.esc(t?.rotulo || p.categoria)}</span>`;
      }
      // PESTEL, Porter e SWOT são a mesma tabela e o mesmo campo de vínculo; o
      // que muda é o rótulo da categoria, que vive num catálogo por etapa.
      if (ORIGENS_FATOR.includes(p.origem)) {
        const cat = SecaoProjetos.rotuloCategoria(p.origem, p.categoria);
        const etapa = SecaoProjetos.rotuloEtapa(p.origem);
        return `<span class="badge text-bg-light border" title="Fator do ${Modal.esc(etapa)} · ${
          Modal.esc(cat)}">${Modal.esc(etapa)} · ${Modal.esc(cat)}</span>`;
      }
      return `<span class="badge text-bg-light border">Coleta · ${Modal.esc(p.autor)}</span>`;
    };
    // O selo e o botão são UM bloco, encostado à direita. Soltos como irmãos do
    // texto, os três disputavam a mesma linha: com a pendência longa o selo
    // descia sozinho para baixo do texto e o botão ficava em cima, e cada linha
    // da fila quebrava num lugar diferente.
    //
    // `flex-sm-nowrap` é o que faz o grupo FICAR na linha do texto no
    // computador, e não é detalhe: num flex que quebra, o navegador quebra a
    // linha ANTES de encolher o item — então, com `flex-wrap`, um texto longo
    // empurrava o grupo para baixo mesmo havendo espaço de sobra depois de o
    // texto se acomodar em duas linhas. Sem a quebra, ele encolhe e o parágrafo
    // se ajeita sozinho ao lado dos botões.
    // No celular a quebra continua ligada (os dois não cabem em 390px), e ali o
    // `ms-auto` é que mantém o grupo à direita: margem automática vale por LINHA
    // no flex que quebra.
    const linhas = pendentes.map((p) => `
      <div class="d-flex align-items-center gap-2 flex-wrap flex-sm-nowrap ideia-acao" data-ideia-acao="${p.chave}">
        <span class="small flex-grow-1 texto-pendencia">${Modal.esc(p.texto_tratado || p.texto)}
          <span class="text-muted">${p.votos ? ` · ★ ${p.votos}` : ''}</span></span>
        <span class="ms-auto d-flex align-items-center gap-2 flex-shrink-0 acoes-pendencia">
          ${selo(p)}
          ${podeConverter ? `<button class="btn btn-sm btn-verde"
            data-virar-acao="${p.chave}">Transformar em ação</button>
          <button class="btn btn-sm btn-outline-danger" data-tirar-fila="${p.chave}"
            title="Tirar da fila de vez — o registro continua na análise dele"
            aria-label="Tirar da fila de vez">×</button>` : ''}
        </span>
      </div>`).join('');
    return `<div class="card mb-3 card-ideias-acao"><div class="card-body py-2 px-3">
      <div class="rotulo-secao">Aguardando plano de ação (${pendentes.length})</div>
      <div class="small text-muted mb-2">Vindas da coleta, da SWOT e dos cruzamentos — atribua cada uma a uma iniciativa para virar ação, ou tire da fila (×) o que não vai virar.</div>
      ${linhas}
    </div></div>`;
  },

  /**
   * O × da fila: tira a pendência de "aguardando plano de ação" DE VEZ. Pedido
   * do cliente (2026-09-03). O registro não é apagado — o fator segue na
   * análise, o cruzamento na SWOT, a ideia volta à matriz da Coleta —, só
   * deixa de esperar uma ação. É o mesmo "desmarcar" de cada origem
   * (`plano-acao` com `marcar: false`; `reabrir` na Coleta), chamado daqui
   * porque a fila é onde a pessoa vê a pendência que não quer mais.
   * A chave da pendência diz a origem pela primeira letra (ver `carregar`).
   */
  async tirarDaFila(chave, texto) {
    const id = Number(chave.slice(1));
    const rota = {
      c: [`/api/coleta/${id}/reabrir`, {}],
      f: [`/api/fatores/${id}/plano-acao`, { marcar: false }],
      x: [`/api/cruzamentos/${id}/plano-acao`, { marcar: false }],
      n: [`/api/cenario/${id}/plano-acao`, { marcar: false }],
    }[chave[0]];
    if (!rota) return;
    const resumo = (texto || '').length > 90 ? `${texto.slice(0, 90)}…` : texto;
    if (!confirm(`Tirar «${resumo}» da fila de plano de ação?\n\n`
      + 'O registro continua na análise de origem (a ideia volta à matriz da Coleta); '
      + 'só deixa de aguardar uma ação.')) return;
    try {
      await App.api(rota[0], { planejamento_id: this.plan.id, ...rota[1] });
    } catch (e) {
      alert(e.message);
    }
    this.carregar();
  },

  // Transforma uma pendência numa ação (desdobramento) de uma iniciativa,
  // guardando o vínculo com a origem — a ideia da coleta, o fator do
  // diagnóstico, o cruzamento da SWOT ou o item da Análise de Cenário.
  modalConverterAcao(ideia) {
    if (!ideia) return;
    // O vínculo é por TABELA, não por análise: PESTEL, Porter e SWOT são todos
    // `fator`, e fecham pelo mesmo `fator_id`. O cenário é outra tabela e por
    // isso é outro campo, embora o gesto do usuário seja o mesmo.
    const deFator = ORIGENS_FATOR.includes(ideia.origem);
    const doCruzamento = ideia.origem === 'TOWS';
    const doCenario = ideia.origem === 'CENARIO';
    // O campo que fecha o vínculo, um por origem. Mandar mais de um faria o
    // servidor fechar um vínculo que ninguém pediu.
    const campoOrigem = doCruzamento ? 'cruzamento_id'
      : (deFator ? 'fator_id' : (doCenario ? 'cenario_item_id' : 'coleta_item_id'));
    const blocoCruz = doCruzamento ? SecaoCruzamentos.bloco(ideia.categoria) : null;
    const tipoCen = doCenario ? SecaoCenario.TIPOS[ideia.categoria] : null;

    /**
     * As quatro frases que o modal muda conforme a origem: o título, o rótulo
     * do campo que mostra a pendência, a pergunta do destino e a barra colorida
     * ao lado do texto.
     *
     * Um objeto por origem, e não ternários aninhados: com três origens os
     * ternários já ocupavam quatro linhas cada, repetidos em quatro lugares do
     * formulário — e a quarta origem teria de ser encaixada em todos eles, na
     * mesma ordem, sem esquecer nenhum. Aqui a origem nova é uma chave a mais.
     */
    const falas = {
      TOWS: {
        titulo: 'Transformar cruzamento em ação',
        rotulo: 'Cruzamento da SWOT',
        pergunta: 'Onde este cruzamento vira ação?',
        // No cruzamento o texto é a ESTRATÉGIA e o rótulo do par vai na barra:
        // é a estratégia que descreve o que fazer, e é dela que sai o "o quê".
        barra: { cor: blocoCruz?.cor || '#007a45',
          titulo: `${blocoCruz?.verbo || 'cruzamento'} — ${ideia.rotulo || ''}` },
      },
      FATOR: {
        titulo: `Transformar fator do ${SecaoProjetos.rotuloEtapa(ideia.origem)} em ação`,
        rotulo: `Fator do ${SecaoProjetos.rotuloEtapa(ideia.origem)}`,
        pergunta: 'Onde este fator vira ação?',
        barra: { cor: SecaoProjetos.corCategoria(ideia.origem, ideia.categoria),
          titulo: SecaoProjetos.rotuloCategoria(ideia.origem, ideia.categoria) },
      },
      CENARIO: {
        titulo: 'Transformar item do cenário em ação',
        rotulo: 'Item da Análise de Cenário',
        pergunta: 'Onde este item vira ação?',
        barra: { cor: tipoCen?.cor || '#007a45', titulo: tipoCen?.rotulo || ideia.categoria || '' },
      },
      COLETA: {
        titulo: 'Transformar ideia em ação',
        rotulo: 'Ideia da coleta',
        pergunta: 'Onde esta ideia vira ação?',
        barra: { cor: '#5a3e2b', titulo: ideia.autor },
      },
    };
    const fala = falas[doCruzamento ? 'TOWS' : (deFator ? 'FATOR' : (doCenario ? 'CENARIO' : 'COLETA'))];
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
      titulo: fala.titulo,
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
        { nome: 'ideia', rotulo: fala.rotulo, tipo: 'info',
          texto: ideia.texto, barra: fala.barra },
        { nome: 'destino', rotulo: fala.pergunta,
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
   * Ordem: o quê, como, quem, a CAIXA da repetição e, por último, a linha
   * "prioridade + status" e os ganhos previstos. Duas regras dessa ordem não
   * são estéticas: o prazo mora DENTRO da caixa da repetição porque é a
   * repetição que decide qual prazo existe — a grade de dias ou o período de
   * execução —, e os ganhos, único campo opcional, ficam por último, no caminho
   * do Salvar. "Quem?" é o que amarra a ação a uma pessoa: é de
   * `quem_usuario_id` que saem os avisos por e-mail e o filtro de "minhas
   * ações".
   */
  camposAcao(dd = null) {
    return [
      // "Por quê?", "Onde?" e o "Quando?" em texto livre saíram do formulário;
      // os valores dos registros antigos seguem preservados nos campos ocultos.
      // Eles vêm todos JUNTOS no topo de propósito: um hidden no meio da lista
      // cortaria a vizinhança de que a caixa e a linha dependem para agrupar.
      { nome: 'por_que', rotulo: '', tipo: 'hidden' },
      { nome: 'onde', rotulo: '', tipo: 'hidden' },
      { nome: 'quando_', rotulo: '', tipo: 'hidden' },
      // O progresso NÃO é campo deste formulário: ele evolui pela barra do
      // próprio cartão, e pop-up só existe nas fronteiras dos 100% (concluir /
      // sair da conclusão). O hidden preserva o valor atual na edição — sem
      // ele, o UPDATE zeraria o percentual a cada ajuste de texto.
      { nome: 'progresso', rotulo: '', tipo: 'hidden' },
      // Os dois campos de texto da ação nascem com UMA linha e crescem com o
      // que está sendo escrito até cinco; passando disso rolam por dentro, e a
      // alça do canto estica quando a pessoa quiser mais espaço. `maxLinhas` é
      // também o que lhes dá o par de botões do alto (ditar e ver mais).
      { nome: 'o_que', rotulo: 'O quê?', obrigatorio: true, tipo: 'textarea', linhas: 1,
        maxLinhas: 5, exemplo: 'Ex.: Contratar projeto executivo dos silos' },
      { nome: 'como', rotulo: 'Como?', obrigatorio: true, tipo: 'textarea', linhas: 1,
        maxLinhas: 5, exemplo: 'Descreva como executar...' },
      { nome: 'quem', rotulo: 'Quem?', tipo: 'selecao_livre', opcoes: this.responsaveis,
        obrigatorio: true, vazio: '(selecione o responsável)',
        ajuda: 'Pesquise um usuário cadastrado ou digite um nome de fora do sistema.' },
      // ─── A CAIXA DA REPETIÇÃO ───────────────────────────────────────────
      // A escolha e tudo que ela revela ficam dentro de um painel só: os
      // campos que aparecem aqui NÃO são do formulário, são desta decisão.
      // Soltos, trocar "todo mês" por "não se repete" trocava blocos que
      // pareciam não ter relação um com o outro.
      { nome: 'recorrencia', rotulo: 'Repetição', tipo: 'select', caixa: 'repeticao', opcoes: [
        { valor: 'NENHUMA', rotulo: 'Não se repete' },
        { valor: 'SEMANAL', rotulo: 'Repetir toda semana' },
        { valor: 'MENSAL', rotulo: 'Repetir todo mês' },
      ] },
      // Semanal e mensal aceitam MAIS DE UM dia ("toda segunda e quinta",
      // "todo dia 5 e 20"): com um dia só, a mesma rotina virava duas ações.
      { nome: 'recorrencia_dias_semana', rotulo: 'Selecione o dia da semana para repetir:',
        tipo: 'dias', grade: 'semana', caixa: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['SEMANAL'] },
        opcoes: DIAS_SEMANA.map(([valor, rotulo]) => ({ valor, rotulo: rotulo.replace('-feira', '') })),
        ajuda: 'A ação se renovará automaticamente nos dias selecionados de cada semana.' },
      { nome: 'recorrencia_dias_mes', rotulo: 'Selecione o dia ou dias do mês em que haverá repetição:',
        tipo: 'dias', grade: 'mes', caixa: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['MENSAL'] },
        opcoes: Array.from({ length: 31 }, (_, i) => ({ valor: i + 1, rotulo: String(i + 1) })),
        ajuda: 'Clique nos números desejados para marcar os dias fixos de repetição mensal.' },
      // O fim da repetição é OPCIONAL: em branco, a rotina não tem prazo para
      // terminar — segue reabrindo até alguém encerrá-la. O filete acima separa
      // "que dias" de "até quando": são duas perguntas, e emendadas viravam
      // uma lista só.
      { nome: 'recorrencia_ate', rotulo: 'Data fim da repetição', tipo: 'date',
        caixa: 'repeticao', separador: true,
        visivelSe: { campo: 'recorrencia', valores: ['SEMANAL', 'MENSAL'] },
        ajuda: 'Opcional — em branco, a repetição fica por tempo indeterminado.' },
      // O período digitado só existe para a ação que NÃO se repete: quando ela
      // repete, quem diz a data de vencimento é a grade acima, e o servidor a
      // calcula. Ter os dois na tela fazia o usuário digitar um "fim previsto"
      // que a primeira conclusão descartava.
      { nome: 'quando_periodo', rotulo: 'Quando? (Prazo de Execução)', tipo: 'periodo',
        obrigatorio: true, caixa: 'repeticao',
        visivelSe: { campo: 'recorrencia', valores: ['NENHUMA'] },
        campos: [
          { nome: 'data_inicio', rotulo: 'Início' },
          { nome: 'data_fim', rotulo: 'Fim previsto' },
        ] },
      // ─── fim da caixa ───────────────────────────────────────────────────
      // Prioridade e Status dividem UMA linha (também no celular): as opções
      // da prioridade empilhadas na vertical para os rótulos não espremerem
      { nome: 'prioridade', rotulo: 'Prioridade', tipo: 'botoes', vertical: true,
        linha: 'prioridade-status', opcoes:
        Object.entries(PRIORIDADES).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
      { nome: 'status', rotulo: 'Status', tipo: 'select', linha: 'prioridade-status',
        opcoes: this.opcoesStatusAcao(dd?.status),
        ajuda: '“No prazo” e “Atrasada” saem da data de fim.' },
      // Os ganhos são o ÚLTIMO campo antes do Salvar: é o único opcional da
      // lista e o que menos gente preenche — quem só descreve a ação chega ao
      // botão sem passar por ele.
      { nome: 'quanto', rotulo: 'Ganhos previstos (R$)', tipo: 'moeda',
        exemplo: 'R$ 0,00', ajuda: 'Apenas números (ex.: 1500,00).' },
    ];
  },

  /**
   * Os dias da grade de repetição de uma ação. `recorrencia_dias` é o CSV do
   * mensal com vários dias; `recorrencia_dia` é o fallback das ações criadas
   * antes de a coluna existir — sem ele, editar uma ação mensal antiga abriria
   * o formulário com nenhum dia marcado e o salvamento seria recusado.
   */
  diasDaAcao(dd) {
    const csv = String(dd?.recorrencia_dias ?? '').trim();
    if (csv) return csv.split(',').map(Number).filter(Boolean);
    return dd?.recorrencia_dia ? [Number(dd.recorrencia_dia)] : [];
  },

  /**
   * Valores iniciais de uma ação que ainda não existe. As duas grades nascem
   * VAZIAS: pré-marcar um dia gravaria uma rotina que ninguém escolheu, e a
   * grade é justamente o que decide quando a ação vence.
   */
  valoresNovaAcao() {
    return {
      progresso: 0, prioridade: 'MEDIA', status: 'NAO_INICIADO', recorrencia: 'NENHUMA',
      recorrencia_dias_semana: [], recorrencia_dias_mes: [],
    };
  },

  /**
   * A grade de dias enviada depende do tipo de repetição: os dias da semana, os
   * do mês, ou nenhum. As datas da ação que se repete NÃO vão no corpo — quem
   * as calcula é o servidor, a partir da grade; mandá-las daqui gravaria o
   * período escondido do formulário, que é justamente o que não vale mais para
   * ela. As DUAS grades saem do payload nos dois casos: o formulário guarda a
   * que está escondida (para a troca de opção não apagar o que já foi marcado),
   * e o servidor só conhece `recorrencia_dias`.
   */
  transformarAcao(dados) {
    const { recorrencia_dias_semana: sem, recorrencia_dias_mes: mes, ...resto } = dados;
    if (resto.recorrencia === 'SEMANAL') {
      return { ...resto, data_inicio: '', data_fim: '', recorrencia_dias: (sem || []).map(Number) };
    }
    if (resto.recorrencia === 'MENSAL') {
      return { ...resto, data_inicio: '', data_fim: '', recorrencia_dias: (mes || []).map(Number) };
    }
    return { ...resto, recorrencia_dias: [], recorrencia_ate: '' };
  },

  /**
   * O formulário da ação.
   *
   * Quando a ação NASCE sem frente decidida — é o caso do "+ Ação" no
   * cabeçalho do projeto, o único caminho de cadastro desde que o botão saiu
   * de dentro de cada frente —, a frente de destino vira a PRIMEIRA pergunta,
   * com a opção de criar uma na hora. É o mesmo par de campos que
   * `modalConverterAcao` já usava para direcionar uma ideia da coleta, e o
   * servidor aceita os dois caminhos (`iniciativa_id` OU `iniciativa_nova`,
   * ver `ProjetoController::salvarDesdobramento`).
   *
   * Na EDIÇÃO a frente não é perguntada: ela já está escolhida e muda-se de
   * frente arrastando o cartão, não por um seletor escondido no meio do
   * formulário. Por isso o campo segue oculto sempre que já há `iniciativaId`.
   */
  modalDesdobramento(projetoId, dd, iniciativaId = null) {
    const escolherFrente = !dd && !iniciativaId;
    const frentes = ((this.projetos || [])
      .find((p) => Number(p.id) === Number(projetoId))?.iniciativas) || [];
    // O valor vazio é a "nova frente": o servidor lê `iniciativa_id` como
    // inteiro, e 0 é exatamente o que manda ele olhar o `iniciativa_nova`.
    const opcoesFrente = [
      ...frentes.map((i) => ({ valor: String(i.id), rotulo: i.titulo })),
      { valor: '', rotulo: '➕ Nova frente de trabalho' },
    ];
    const campoFrente = escolherFrente
      ? [
        { nome: 'iniciativa_id', rotulo: 'Em qual frente de trabalho?', tipo: 'select',
          opcoes: opcoesFrente,
          ajuda: 'Toda ação vive dentro de uma frente do projeto.' },
        { nome: 'iniciativa_nova', rotulo: 'Nome da nova frente', obrigatorio: true,
          visivelSe: { campo: 'iniciativa_id', valores: [''] },
          exemplo: 'Ex.: Licenciamento e obra civil' },
      ]
      : [{ nome: 'iniciativa_id', rotulo: '', tipo: 'hidden' }];
    Modal.abrir({
      titulo: dd ? 'Editar ação' : 'Nova ação',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      bloqueio: dd ? { recurso: 'desdobramento', registro_id: dd.id, planejamento_id: this.plan.id } : null,
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId,
            iniciativa_id: dd.iniciativa_id ?? iniciativaId,
            recorrencia: dd.recorrencia || 'NENHUMA',
            recorrencia_ate: dd.recorrencia_ate ?? '',
            recorrencia_dias_semana: dd.recorrencia === 'SEMANAL' ? this.diasDaAcao(dd) : [],
            recorrencia_dias_mes: dd.recorrencia === 'MENSAL' ? this.diasDaAcao(dd) : [] }
        : { planejamento_id: this.plan.id, projeto_id: projetoId,
            // Sem frente decidida, a primeira do projeto já vem escolhida — na
            // esmagadora maioria dos cadastros é nela que a ação entra, e o
            // projeto sem frente nenhuma cai direto na "nova frente".
            iniciativa_id: iniciativaId ?? (escolherFrente ? (opcoesFrente[0]?.valor ?? '') : null),
            iniciativa_nova: '',
            ...this.valoresNovaAcao() },
      transformar: (dados) => this.transformarAcao(dados),
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        ...campoFrente,
        ...this.camposAcao(dd),
      ],
      aoSalvar: (r) => {
        this.avisarReagendamento(r);
        App.recarregarSecaoAtiva();
      },
    });
  },

  /**
   * A exclusão de ação, frente ou projeto quando alguma ação NASCEU de uma
   * origem — fator, item de cenário, cruzamento, ideia da Coleta. Pedido do
   * cliente (2026-09-03): apagar a ação devolvia a origem para a fila de
   * "aguardando plano de ação" sem perguntar, e a fila enchia de pendências
   * que ninguém queria mais. Aqui a pessoa escolhe: devolver à fila (o
   * comportamento de sempre) ou tirar de vez — o registro continua na análise
   * dele, só deixa de esperar uma ação.
   *
   * É um MODAL do sistema, nunca `confirm()`: o navegador oferece "bloquear
   * caixas de diálogo" e, bloqueadas, o gesto simplesmente não acontecia.
   * Ação sem origem nenhuma não passa por aqui — perguntar o destino de uma
   * origem que não existe seria um diálogo sem sentido (quem chama confere
   * `origens` antes).
   */
  excluirComOrigens({ titulo, aviso, origens, url }) {
    const quantas = origens === 1 ? 'A origem desta ação' : `As ${origens} origens destas ações`;
    Modal.abrir({
      titulo,
      valores: { planejamento_id: this.plan.id, origens: 'devolver' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'aviso_exclusao', rotulo: '', tipo: 'info', texto: aviso },
        { nome: 'origens', rotulo: `${quantas} — o fator, o item de cenário, o cruzamento ou a ideia que virou ação:`,
          tipo: 'botoes', vertical: true, opcoes: [
            { valor: 'devolver', rotulo: 'Devolver para "Aguardando plano de ação"' },
            { valor: 'tirar', rotulo: 'Excluir de vez — sai da fila; o registro continua na análise dele' },
          ] },
      ],
      salvar: { rotulo: 'Excluir', perigo: true },
      enviar: (dados) => App.api(url, { planejamento_id: this.plan.id, origens: dados.origens }),
      aoSalvar: () => this.carregar(),
    });
  },

  /**
   * A saída dos 100% na barra do cartão: a ação estava concluída e alguém
   * precisa dizer em que status ela fica. (Chegar aos 100% não passa mais por
   * aqui: conclui direto, sem perguntar.) É um MODAL do sistema, nunca
   * `confirm()`: o navegador oferece "bloquear caixas de diálogo" e,
   * bloqueadas, o gesto simplesmente não acontecia — sem erro nenhum.
   * O percentual já vai junto, escondido; escolher "Concluída" devolve a
   * barra aos 100% (o servidor ignora o percentual rebaixado); fechar sem
   * salvar desfaz o ajuste (quem chama devolve a barra ao valor do servidor).
   */
  modalStatusAcao(acaoId, progresso, statusAtual, aoGravar) {
    Modal.abrir({
      titulo: 'A ação saiu dos 100%',
      url: `/api/desdobramentos/${acaoId}/progresso`,
      valores: { planejamento_id: this.plan.id, progresso, status: 'EM_ANDAMENTO' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'progresso', rotulo: '', tipo: 'hidden' },
        { nome: 'status', rotulo: `Com ${progresso}%, em que status a ação fica?`,
          tipo: 'select', opcoes: this.opcoesStatusAcao(statusAtual),
          ajuda: 'Ela estava concluída. Escolher "Concluída" mantém os 100%; fechar sem salvar mantém tudo como estava.' },
      ],
      aoSalvar: (r) => {
        if (aoGravar) aoGravar();
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
  miniaturaAnexo(a, podeApagar = false, ultimoSemTexto = false) {
    const url = `/api/anexos/${a.id}?planejamento_id=${this.plan.id}`;
    const ext = (a.nome.split('.').pop() || '').toUpperCase().slice(0, 4);
    const corpo = a.imagem
      ? `<img src="${url}" alt="${Modal.esc(a.nome)}" loading="lazy">`
      : `<span class="selo-ext selo-ext-${ext.toLowerCase()}">${Modal.esc(ext)}</span>`;
    // O × é IRMÃO do link, nunca filho: botão dentro de <a> é HTML inválido, e
    // o clique acabaria abrindo o anexo em vez de removê-lo.
    const excluir = podeApagar
      ? `<button type="button" class="anexo-excluir" data-excluir-anexo="${a.id}"
          data-anexo-nome="${Modal.esc(a.nome)}"${ultimoSemTexto ? ' data-ultimo="1"' : ''}
          title="Remover este anexo" aria-label="Remover o anexo ${Modal.esc(a.nome)}">×</button>`
      : '';
    // `download` no link do documento: ele já desce como anexo pelo servidor, e
    // o atributo evita a aba em branco que o navegador abre antes de baixar.
    return `<span class="anexo-item">
      <a class="anexo-mini" href="${url}" target="_blank" rel="noopener"
          ${a.imagem ? '' : 'download'} title="${Modal.esc(a.nome)}">
        <span class="anexo-face">${corpo}</span>
        <span class="anexo-nome">${Modal.esc(a.nome)}</span>
        <span class="anexo-tamanho">(${this.tamanho(a.tamanho)})</span>
      </a>
      ${excluir}
    </span>`;
  },

  /**
   * O botão que abre e fecha os comentários — e que, FECHADO, diz o que existe
   * atrás dele.
   *
   * Os comentários sempre foram um bloco que se abre a pedido, mas o botão era
   * mudo: para descobrir se uma ação tinha uma foto da obra anexada era preciso
   * abrir ação por ação. Com uma tela de dezenas de ações isso é o mesmo que
   * não ter o anexo. Agora o botão traz duas contagens — os comentários, num
   * selo redondo, e os anexos, atrás do clipe — e a ausência delas também
   * informa: botão liso é ação sem conversa nenhuma.
   *
   * Anexo é contado à parte de propósito: um comentário leva até cinco
   * arquivos e outro nenhum, então o número de comentários não responde
   * "quantas fotos há para ver".
   *
   * Aberto, o botão troca o rótulo por "Ocultar comentários" e larga os selos:
   * o bloco logo abaixo já traz o "N registro(s)", e repetir a conta ali seria
   * dizer duas vezes a mesma coisa a dois centímetros de distância.
   */
  botaoComentarios(refTipo, id, comentarios = 0, anexos = 0) {
    const aberto = this.comentariosAbertos?.refTipo === refTipo
      && this.comentariosAbertos?.refId === Number(id);
    const n = Number(comentarios) || 0;
    const ax = Number(anexos) || 0;
    const resumo = n
      ? [Vinculos.quantos(n, 'comentário', 'comentários'),
        Vinculos.quantos(ax, 'anexo', 'anexos')].filter(Boolean).join(' · ')
      : 'Nenhum comentário ainda';
    // Os selos são `aria-hidden`: quem usa leitor de tela ouve a mesma
    // contagem, em português, pelo `title` do botão — e ouvi-la duas vezes,
    // uma delas como "2 3" solto, é pior que não tê-la.
    const selos = aberto || !n ? '' : `<span class="selos-comentarios" aria-hidden="true"
      ><span class="badge rounded-pill selo-comentarios">${n}</span>${ax
        ? `<span class="selo-anexos"><svg width="12" height="12" focusable="false"
            ><use href="#i-clipe"/></svg>${ax}</span>` : ''}</span>`;
    return `<button class="btn btn-sm btn-outline-success btn-comentarios"
      data-comentarios="${refTipo}:${id}" aria-expanded="${aberto}"
      title="${Modal.esc(resumo)}">${aberto ? 'Ocultar comentários' : 'Comentários'}${selos}</button>`;
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
      const podeApagar = Number(c.autor_id) === Number(eu.id) || eu.perfil === 'ADMIN';
      // Remover o único anexo de um comentário sem texto apaga o comentário —
      // é a regra do servidor. A tela precisa saber disso ANTES de perguntar,
      // para avisar do que vai acontecer em vez de só relatar depois.
      const ultimoSemTexto = (c.anexos || []).length === 1 && !(c.texto || '').trim();
      const anexos = (c.anexos || []).length
        ? `<div class="grade-anexos">${c.anexos
            .map((a) => this.miniaturaAnexo(a, podeApagar, ultimoSemTexto)).join('')}</div>` : '';
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

    // Abrir os comentários mostra o que JÁ EXISTE (pedido do cliente): o
    // formulário saiu da frente da lista — quem clicava em "Comentários"
    // queria ler, e a caixa de novo comentário empurrava os registros para
    // baixo da dobra. Escrever é o gesto do "+" ao lado do título, que abre o
    // formulário em modal (`modalComentario`) — e ali o textarea já vem com o
    // ditado por voz e o crescer-com-o-texto de todo formulário do sistema.
    const btnNovo = App.podeEditar()
      ? `<button class="btn btn-sm btn-verde px-2 py-0" data-novo-comentario
          title="Novo comentário" aria-label="Novo comentário">+</button>` : '';

    alvo.innerHTML = `<div class="card bg-light"><div class="card-body py-2">
      <div class="d-flex align-items-center gap-2 mb-2">
        <strong class="small text-uppercase">Comentários</strong>
        ${btnNovo}
        <span class="small text-muted ms-auto">${lista.length} registro(s)</span>
      </div>
      ${itens || '<div class="text-muted small">Nenhum comentário ainda.</div>'}
    </div></div>`;

    alvo.querySelector('[data-novo-comentario]')
      ?.addEventListener('click', () => this.modalComentario(refTipo, refId));

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

    // Remover UM anexo. Antes disso, tirar o arquivo errado custava o
    // comentário inteiro — apagar tudo e reescrever, com o texto junto.
    alvo.querySelectorAll('[data-excluir-anexo]').forEach((b) =>
      b.addEventListener('click', async () => {
        const nome = b.dataset.anexoNome || 'este anexo';
        const pergunta = b.dataset.ultimo
          ? `Remover “${nome}”? É o único anexo e o comentário não tem texto — `
            + 'o comentário sai junto.'
          : `Remover o anexo “${nome}”? O comentário e os demais anexos ficam.`;
        if (!confirm(pergunta)) return;
        try {
          await App.api(`/api/anexos/${b.dataset.excluirAnexo}/excluir`,
            { planejamento_id: this.plan.id });
        } catch (e) {
          alert(e.message);
        }
        this.renderComentarios();
      }));
  },

  /**
   * O formulário do novo comentário, em modal — texto e anexos.
   *
   * É a fábrica de sempre (`Modal.abrir`), com `enviar` próprio: o corpo é
   * multipart porque leva arquivo, e `App.api` só fala JSON — este é o único
   * `fetch` na mão do sistema, com o mesmo header de CSRF, que é o que a rota
   * confere. Os arquivos não passam por `coletar()` (arquivo não viaja em
   * JSON): o `enviar` os lê direto do campo, por `Modal.arquivosDe`.
   */
  modalComentario(refTipo, refId) {
    Modal.abrir({
      titulo: 'Novo comentário',
      campos: [
        { nome: 'texto', rotulo: 'Comentário', tipo: 'textarea', linhas: 4,
          exemplo: 'Descreva o andamento, bloqueios ou próximos passos...' },
        { nome: 'arquivos', rotulo: 'Anexos', tipo: 'arquivos', multiplo: true,
          aceita: '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt',
          ajuda: 'Até 5 arquivos por comentário, 5 MB cada — imagem, PDF, Word, Excel, PowerPoint, CSV ou TXT.' },
      ],
      enviar: async (corpo) => {
        const texto = (corpo.texto || '').trim();
        const arquivos = Modal.arquivosDe('arquivos');
        // A recusa acontece AQUI, não no clique: lançada, ela aparece no aviso
        // do modal (`mostrarErro`), que é onde quem acabou de salvar está olhando.
        if (!texto && !arquivos.length) throw new Error('Escreva o comentário ou anexe um arquivo.');
        const dados = new FormData();
        dados.append('planejamento_id', this.plan.id);
        dados.append('ref_tipo', refTipo);
        dados.append('ref_id', refId);
        dados.append('texto', texto);
        for (const arquivo of arquivos) dados.append('arquivos[]', arquivo);
        const resposta = await fetch('/api/comentarios', {
          method: 'POST',
          headers: { 'X-CSRF-Token': App.csrf },
          body: dados,
        });
        const json = await resposta.json().catch(() => ({}));
        if (!resposta.ok || json.ok === false) {
          throw new Error(json.erro || 'Não foi possível enviar o comentário.');
        }
        return json;
      },
      aoSalvar: () => this.renderComentarios(),
    });
  },
};

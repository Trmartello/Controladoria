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
      const abrir = this.detalhesAbertos.has(chave);
      if (abrir) this.detalhesAbertos.delete(chave);
      else this.detalhesAbertos.add(chave);
      // TODOS os blocos da chave: a descrição mora acima da barra de progresso
      // e o resto do detalhe abaixo dela — a seta abre e fecha os dois juntos
      el.querySelectorAll(`[data-detalhe="${chave}"]`).forEach((alvo) =>
        alvo.classList.toggle('d-none', abrir));
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
   * Pesquisa do plano de ação (pedido do cliente): palavra e situação, lado a
   * lado no cabeçalho fixo. A palavra casa com o TEXTO do cartão da ação e
   * também com o título da frente e do projeto — procurar o nome de uma frente
   * deve trazê-la inteira — e o resultado mostra sempre os três níveis juntos:
   * a ação, a frente e o projeto dela. A situação casa pelo código
   * (`data-status` do cartão), nunca pelo rótulo, que é refém da redação.
   *
   * É filtro de DOM, não recarga: esconder com `d-none` preserva o foco de quem
   * digita (repintar a seção mataria o campo no meio da palavra), e o estado
   * (`filtroTexto`/`filtroStatus`) mora na seção, então sobrevive às repinturas
   * do `carregar()` — que reaplica o filtro ao terminar. Com filtro ativo os
   * acordeões abrem À FORÇA (ação encontrada dentro de frente recolhida é
   * resultado invisível); ao limpar, o recolhimento volta dos conjuntos
   * (`projetosFechados`/`iniciativasFechadas`), que o filtro nunca altera.
   * A pilha de cabeçalhos se reacomoda sozinha: esconder uma frente zera a
   * altura do cabeçalho dela e o ResizeObserver de `medirCabecalhosProjeto`
   * reempilha as que ficaram.
   *
   * **A pessoa entra por dois caminhos, e eles não são redundantes.**
   *
   * A PALAVRA passou a casar também com o nome e o e-mail de quem responde pela
   * ação. É o caminho largo: quem digita "Ana" acha as ações dela sem saber onde
   * o nome aparece na tela. O preço é o falso positivo — "ana" também está
   * dentro de "semana", e uma reunião semanal de outra pessoa vem junto.
   *
   * O RESPONSÁVEL é o caminho exato: casa SÓ contra o nome e o e-mail de quem
   * responde, nunca contra o texto da ação, e por isso "ana" ali devolve as
   * ações da Ana e nada mais. Ele é uma caixa de texto com lista
   * (`<datalist>`): dá para escolher da lista ou digitar parte do nome, que é o
   * que se faz quando não se lembra da grafia. Um `<select>` fechado obrigaria
   * a achar a pessoa numa lista longa, e um campo de texto puro não diria quem
   * existe.
   *
   * A lista é montada com quem TEM ação no plano — que é exatamente o conjunto
   * capaz de devolver resultado —, e inclui quem já foi desativado mas ainda
   * carrega ação: são justamente as que precisam ser reatribuídas, e deixá-las
   * fora do mapeamento seria esconder o problema. **«Sem usuário» é a primeira
   * opção**, fixa: a ação órfã não é cobrada de ninguém, e é a fila que mais
   * precisa ser vista.
   *
   * Os três filtros são E, não OU: palavra e responsável e situação. Somados,
   * respondem "o que a Ana tem de atrasado com a palavra contrato".
   */
  filtroTexto: '',
  filtroStatus: '',
  filtroResponsavel: '',

  /**
   * O rótulo do «Sem usuário» no filtro de pessoa — e, ao mesmo tempo, o valor
   * que o compara. Uma constante só de propósito: a caixa é de texto livre, o
   * que este item escreve nela é o que volta para o filtro, e um rótulo escrito
   * na lista e comparado à mão em outro lugar deixaria de casar na primeira vez
   * que alguém revisasse a redação — em silêncio, devolvendo tela vazia.
   */
  ROTULO_SEM_DONO: 'Sem usuário',

  normalizar(s) {
    return s.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '');
  },

  /**
   * O que o filtro de pessoa compara: nome e e-mail de quem responde pela ação,
   * já normalizados, num texto só. Sai do `data-quem` que o cartão carrega —
   * ler do texto visível não serviria, porque o e-mail não aparece na tela e o
   * nome está no meio de seis outros metadados.
   */
  quemDoCartao(card) {
    return card.dataset.quem || '';
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

  aplicarFiltro(el) {
    const texto = this.normalizar(this.filtroTexto.trim());
    const status = this.filtroStatus;
    const resp = this.filtroResponsavel.trim();
    // «Sem usuário» é ESCOLHA, não busca por pedaço: só o rótulo inteiro vale,
    // senão digitar "sem" mudaria o sentido do filtro no meio da palavra.
    const semDono = this.normalizar(resp) === this.normalizar(this.ROTULO_SEM_DONO);
    const respTexto = semDono ? '' : this.normalizar(resp);
    const ativo = !!(texto || status || resp);
    let algum = false;
    el.querySelectorAll('[data-projeto]').forEach((cartao) => {
      const projId = parseInt(cartao.dataset.projeto, 10);
      const tituloProj = this.normalizar(
        cartao.querySelector('.projeto-cabeca strong')?.textContent || '');
      let temNoProjeto = false;
      cartao.querySelectorAll('[data-iniciativa]').forEach((bloco) => {
        const iniId = parseInt(bloco.dataset.iniciativa, 10);
        const tituloIni = this.normalizar(
          bloco.querySelector('.iniciativa-cabeca strong')?.textContent || '');
        let temNaFrente = false;
        bloco.querySelectorAll('[data-card-acao]').forEach((card) => {
          const quem = this.quemDoCartao(card);
          // O `data-quem` do cartão é vazio quando ninguém responde pela ação —
          // é o mesmo vazio que desenha o selo «Sem usuário».
          const casaResp = !resp || (semDono ? quem === '' : quem.includes(respTexto));
          const bate = (!status || card.dataset.status === status)
            && casaResp
            // A palavra alcança o texto do cartão, os títulos acima dele e
            // TAMBÉM quem responde: o e-mail não está escrito na tela, então
            // sem o `data-quem` ele nunca seria encontrado por aqui.
            && (!texto || this.normalizar(card.textContent).includes(texto)
              || quem.includes(texto)
              || tituloIni.includes(texto) || tituloProj.includes(texto));
          card.classList.toggle('d-none', ativo && !bate);
          if (bate) temNaFrente = true;
        });
        // `d-none` vence o `display: contents` da frente (é !important): a
        // frente sem resultado some inteira, cabeçalho junto.
        bloco.classList.toggle('d-none', ativo && !temNaFrente);
        const fechadoIni = ativo ? !temNaFrente : this.iniciativasFechadas.has(iniId);
        bloco.querySelector('.acoes-iniciativa')?.classList.toggle('d-none', fechadoIni);
        const setaIni = bloco.querySelector('.seta-iniciativa');
        if (setaIni) setaIni.textContent = fechadoIni ? '▸' : '▾';
        if (temNaFrente) temNoProjeto = true;
      });
      cartao.classList.toggle('d-none', ativo && !temNoProjeto);
      const fechadoProj = ativo ? !temNoProjeto : this.projetosFechados.has(projId);
      cartao.querySelector('.iniciativas-projeto')?.classList.toggle('d-none', fechadoProj);
      const setaProj = cartao.querySelector('.seta-projeto');
      if (setaProj) setaProj.textContent = fechadoProj ? '▸' : '▾';
      if (temNoProjeto) algum = true;
    });
    el.querySelector('[data-filtro-vazio]')?.classList.toggle('d-none', !ativo || algum);
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
  /**
   * A pilha de cabeçalhos: app → Projetos → projeto → as frentes percorridas.
   *
   * Cada degrau precisa saber a altura do de cima, e a do PROJETO varia entre
   * cartões (o selo de atraso, o "Prioritário", o nome que quebra em duas
   * linhas no celular) — por isso a medida é POR CARTÃO, guardada numa variável
   * do próprio cartão, e não uma só para a seção. Uma média erraria em todos os
   * cartões menos num.
   *
   * As frentes EMPILHAM (decisão do cliente): dentro do projeto, cada cabeçalho
   * de frente fica grudado até o bloco do projeto acabar, deslocado pela soma
   * dos cabeçalhos de frente acima dele (`--desloca-frente`, por frente). Quem
   * permite isso é o `display: contents` da `.iniciativa` no CSS — o limite do
   * sticky passa a ser o bloco inteiro de frentes, não a caixa de cada uma. O
   * projeto seguinte varre a pilha: o cabeçalho dele é limitado ao próprio
   * cartão e usa o mesmo `top` dos demais, então substitui em vez de empilhar.
   *
   * O observador é trocado a cada pintura: o cartão anterior já saiu do
   * documento, e observá-lo seria medir o que ninguém vê.
   */
  observadoresProjeto: [],

  medirCabecalhosProjeto(el) {
    this.observadoresProjeto.forEach((o) => o.disconnect());
    this.observadoresProjeto = [];
    el.querySelectorAll('[data-projeto]').forEach((cartao) => {
      const cab = cartao.querySelector('.projeto-cabeca-fixa');
      if (!cab) return;
      const frentes = [...cartao.querySelectorAll('.iniciativa-cabeca')];
      const medir = () => {
        // Cartão recolhido ou seção escondida medem zero, e zero aqui empilharia
        // o cabeçalho da frente por cima do do projeto.
        const h = Math.round(cab.getBoundingClientRect().height);
        if (h) cartao.style.setProperty('--altura-projeto', `${h}px`);
        // O z-index DESCE a cada frente (14, 13, …) para a que sai no fim do
        // bloco deslizar por BAIXO das de cima — sempre abaixo do cabeçalho do
        // projeto (15) e, com o piso 1, sempre acima dos cartões de ação.
        let soma = 0;
        frentes.forEach((f, i) => {
          f.style.setProperty('--desloca-frente', `${soma}px`);
          f.style.zIndex = String(Math.max(1, 14 - i));
          soma += Math.round(f.getBoundingClientRect().height);
        });
      };
      medir();
      const ro = new ResizeObserver(medir);
      ro.observe(cab);
      // A altura de cada frente também varia (nome que quebra, selo de atraso):
      // mudou uma, as de baixo reempilham.
      frentes.forEach((f) => ro.observe(f));
      this.observadoresProjeto.push(ro);
    });
  },

  popoversResumo: [],

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
  blocoIniciativa(p, ini) {
    const acoes = ini.acoes || [];
    const feitas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
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
        <strong class="small" data-popover-resumo="ini-${ini.id}"
          title="Situação das ações desta frente">${Modal.esc(ini.titulo)}</strong>
        <!-- Só o ATRASO, como no projeto: a pergunta é a mesma nos dois níveis
             e a distribuição inteira mora no popover do título, a um passar de
             mouse. Cinco selos por frente numa tela com várias viravam parede.
             O ms-auto está no grupo da direita para o título não esticar e
             empurrar o selo para longe do nome que ele resume.
             O selo de situação da frente ("Aberta") saiu daqui: a seta ao lado
             do nome já diz se ela está aberta ou recolhida, e a situação
             cadastrada segue no popover e no formulário de edição. -->
        ${this.resumoStatus(acoes, ['ATRASADO'])}
        <span class="ms-auto d-flex align-items-center gap-2">
          ${this.botaoMais(chave, detalhado)}
        </span>
      </div>
      <!-- Com a seta aberta, a leitura é: descrição da frente e, logo abaixo
           dela, a barra de progresso — ordem pedida pelo cliente -->
      ${ini.descricao ? `<div class="detalhe-item small text-muted mt-1 ${detalhado ? '' : 'd-none'}"
        data-detalhe="${chave}">${Modal.esc(ini.descricao)}</div>` : ''}
      ${panorama}
      <div class="detalhe-item ${detalhado ? '' : 'd-none'}" id="detalhe-${chave}" data-detalhe="${chave}">
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
      <!-- O respiro no topo é para o primeiro cartão não nascer colado no
           cabeçalho grudado — sem ele, o cartão e o título dividem a mesma
           linha visual no instante em que a barra trava. -->
      <div class="acoes-iniciativa pt-1 ${aberta ? '' : 'd-none'}">
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
      // Sempre com os DOIS centavos: `toLocaleString` sozinho corta o zero à
      // direita e R$ 1.500,50 aparecia como "R$ 1.500,5", que não é como se
      // escreve dinheiro — e ainda parecia valor truncado.
      a.quanto !== null && a.quanto !== undefined
        && `Ganhos previstos: R$ ${Number(a.quanto).toLocaleString('pt-BR',
          { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
    ].filter(Boolean).map(Modal.esc).join(' · ');
    const timeline = this.comentariosAbertos?.refTipo === 'DESDOBRAMENTO' && this.comentariosAbertos?.refId === a.id
      ? `<div id="comentarios-DESDOBRAMENTO-${a.id}" class="mt-2"></div>` : '';
    // As DUAS grades podem vencer em vários dias ("toda segunda e quinta",
    // "todo dia 5 e 20"), e por isso a montagem da frase é a mesma nas duas
    const diasGrade = this.diasDaAcao(a);
    const enumerar = (lista) => (lista.length > 1
      ? `${lista.slice(0, -1).join(', ')} e ${lista.at(-1)}`
      : String(lista[0] ?? ''));
    const repeticao = !diasGrade.length ? ''
      : a.recorrencia === 'SEMANAL'
        ? `toda ${enumerar(diasGrade.map(
          (d) => (DIAS_SEMANA.find(([v]) => v === d) || [, ''])[1].toLowerCase()))}`
        : a.recorrencia === 'MENSAL' ? `todo dia ${enumerar(diasGrade)}` : '';
    // Ação cancelada não tem progresso: 0% numa barra inativa, sem controle
    // para arrastar. Mesmo que o banco ainda guarde um valor antigo (linha
    // anterior à regra, corrigida pelo migrate), a tela mostra 0 — a ação está
    // fora das médias, e um "70%" ali contradizia o percentual da frente logo
    // acima. Sem o `data-progresso`, ela sai sozinha de `atualizarMedias`.
    const cancelada = a.status === 'CANCELADO';
    const pct = cancelada ? 0 : a.progresso;
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
      // Ação sem dono é DITA, nunca omitida. Enquanto a linha só aparecia com
      // `quem` preenchido, a ação órfã — a de quem saiu do cadastro sem que
      // ninguém assumisse a carteira — ficava visualmente idêntica a uma ação
      // bem atribuída, com o "Quem:" simplesmente ausente no meio de outros
      // seis metadados. Ela não é cobrada por e-mail de ninguém, e é
      // exatamente por isso que precisa gritar na tela.
      a.quem
        ? `<strong>Quem:</strong> ${Modal.esc(a.quem)}`
        : '<span class="selo-sem-usuario" title="Ninguém responde por esta ação:'
          + ' ela não entra em cobrança nenhuma">Sem usuário</span>',
      `<strong>Prioridade:</strong> ${rotPrio}`,
      repeticao && `<strong>Repete:</strong> ${repeticao}`,
      detalhes,
    ].filter(Boolean).join(' · ');
    return `<div class="card acao-card mb-2" style="--cor-prio:${corPrio}" data-card-acao="${a.id}"
      data-status="${a.status}" data-quem="${Modal.esc(this.chaveQuem(a))}">
      <div class="card-body py-2 px-2">
        <!-- Linha 1: situação, progresso e o expandir. O selo e o chevron não
             encolhem (flex-shrink-0); quem cede largura é a barra, que é a
             única peça aqui que se lê por proporção e não por texto. -->
        <div class="d-flex align-items-center gap-2 linha-acao-topo">
          <span class="badge ${classe} flex-shrink-0">${rotulo}</span>
          ${App.podeEditar() && !cancelada ? `
          <input type="range" class="faixa-verde flex-grow-1" min="0" max="100" step="5"
            style="--pct:${pct}%" value="${pct}"
            data-progresso="${a.id}" data-proj="${p.id}"
            title="Arraste para ajustar o progresso" aria-label="Progresso da ação">
          <span class="valor-progresso" data-rotulo="${a.id}">${pct}%</span>` : `
          <div class="faixa-progresso flex-grow-1 ${cancelada ? 'inativa' : ''}"
            title="${cancelada ? 'Ação cancelada — sem progresso e fora da média' : `${pct}%`}">
            <span style="width:${pct}%"></span>
          </div>
          <span class="valor-progresso ${cancelada ? 'inativo' : ''}">${pct}%</span>`}
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

    // O conteúdo de cada popover, montado ANTES da pintura e guardado por
    // chave: o balão do projeto resume as ações de TODAS as frentes, e o de
    // cada frente, as dela — a mesma regra do selo do cabeçalho.
    const conteudos = new Map();
    projetos.forEach((p) => {
      conteudos.set(`proj-${p.id}`, this.conteudoPopover(p.desdobramentos || []));
      (p.iniciativas || []).forEach((ini) => {
        // A situação da frente vem no rodapé do balão dela: saiu do cabeçalho
        // a pedido, e sem lugar nenhum ela deixaria de existir na tela. Ela é
        // DERIVADA das ações (Consolidacao) — todas concluídas fecham a frente.
        const [rot] = STATUS_INICIATIVA[ini.status] || [ini.status];
        conteudos.set(`ini-${ini.id}`, this.conteudoPopover(ini.acoes || [], ['Frente', rot]));
      });
    });

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
          <!-- A linha do título GRUDA, um degrau abaixo do cabeçalho de
               Projetos: é ela que diz de qual projeto são as frentes e as ações
               que estão passando. Sem isso, três telas de rolagem adiante o
               nome do projeto já tinha ido embora. -->
          <div class="d-flex align-items-center gap-2 flex-wrap projeto-cabeca-fixa">
            <div class="projeto-cabeca flex-grow-1" data-abrir-proj="${p.id}" role="button" tabindex="0">
              <span class="seta-projeto">${aberto ? '▾' : '▸'}</span>
              <strong data-popover-resumo="proj-${p.id}"
                title="Situação das ações deste projeto">${Modal.esc(p.titulo)}</strong>
              ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning ms-1">Prioritário</span>' : ''}
              <!-- UM selo, e é o do atraso: quantas ações estão fora do prazo e
                   quanto isso é do total criado (todas as frentes somadas). O
                   selo de situação do projeto saiu daqui — ele dizia "Atrasado"
                   ao lado de "Atrasada: 1 (20%)", a mesma notícia duas vezes,
                   uma delas sem o tamanho. A situação agregada continua no
                   cadastro do projeto e a média, na barra logo abaixo.
                   A distribuição inteira fica na frente de trabalho, onde ela
                   cabe num punhado de ações e ainda se lê.
                   Sem ação atrasada, nenhum selo: a ausência é a boa notícia. -->
              ${this.resumoStatus(acoes, ['ATRASADO'])}
            </div>
            ${this.botaoMais(chave, detalhado)}
          </div>
          <!-- Com a seta aberta: descrição do projeto e, logo abaixo dela, a
               barra de progresso — ordem pedida pelo cliente -->
          ${p.descricao ? `<div class="detalhe-item ${detalhado ? '' : 'd-none'}"
            data-detalhe="${chave}">${descricao}</div>` : ''}
          ${this.panorama(acoes, 'data-media-projeto', 'data-barra-projeto', 'panorama-projeto',
            'Progresso médio das ações do projeto')}
          <div class="detalhe-item ${detalhado ? '' : 'd-none'}" id="detalhe-${chave}" data-detalhe="${chave}">
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
    // A lista do filtro de pessoa: quem tem ação (com e-mail) mais os
    // responsáveis ativos que ainda não receberam nenhuma.
    const pessoasFiltro = this.pessoasParaFiltro(projetos, responsaveis);
    const botaoNivel = (valor, rotulo, dica) =>
      `<button type="button" class="btn ${nivel === valor ? 'btn-verde' : 'btn-outline-secondary'}"
        data-nivel="${valor}" title="${Modal.esc(dica)}"
        aria-pressed="${nivel === valor}">${rotulo}</button>`;
    el.innerHTML = `
      <!-- O cabeçalho GRUDA abaixo da topbar: os três botões de nível são o
           controle que se usa lendo a lista, e rolar até o quinto projeto para
           trocar de visão obrigava a subir a página inteira de volta.
           O parágrafo de instruções fica FORA do bloco fixo, de propósito: ele
           se lê uma vez, e grudado custaria uma faixa de tela em toda rolagem,
           para sempre. Quem precisa dele está no começo, onde ele está. -->
      <div class="cabecalho-projetos d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Projetos — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          ${projetos.length ? `
          <!-- Pesquisa: enxuta de propósito — mora no cabeçalho fixo, e cada
               rem aqui é tela roubada da lista o tempo todo. Palavra à
               esquerda, situação ao lado; \`type=search\` traz o ✕ de limpar
               de graça. -->
          <div class="filtro-acoes d-flex gap-2 align-items-center">
            <!-- O placeholder é CURTO porque o campo tem 11rem e o texto é
                 cortado no meio: "Pesquisar palavra, pessoa ou e-mail…" morria
                 em "pessoa ou e-", que é pior que não prometer nada. O que ele
                 não cabe dizer vai no title e no aria-label, e o campo ao lado
                 já anuncia o caminho exato da pessoa. -->
            <input type="search" class="form-control form-control-sm" data-filtro-texto
              value="${Modal.esc(this.filtroTexto)}" placeholder="Palavra ou pessoa…"
              title="Procura no texto da ação, no título da frente e do projeto e também no nome e no e-mail de quem responde pela ação"
              aria-label="Pesquisar ações por palavra, nome ou e-mail do responsável">
            <!-- Caixa de texto COM lista: dá para escolher da lista ou digitar
                 parte do nome. Um <select> fechado obrigaria a achar a pessoa
                 numa lista longa; um texto puro não diria quem existe.
                 O type=search traz o ✕ de limpar de graça, como o campo ao
                 lado. -->
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
              ${Object.entries(STATUS_ACAO).map(([v, [rotulo]]) =>
                `<option value="${v}" ${this.filtroStatus === v ? 'selected' : ''}>${rotulo}</option>`).join('')}
            </select>
          </div>
          <span class="small text-muted d-none d-sm-inline">Mostrar até</span>
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
      <div class="pt-2">${cartoes || '<div class="text-muted">Nenhum projeto cadastrado.</div>'}</div>
      <div class="text-muted d-none" data-filtro-vazio>Nenhuma ação encontrada com a pesquisa atual.</div>`;

    el.querySelectorAll('[data-virar-acao]').forEach((b) => b.addEventListener('click', () =>
      this.modalConverterAcao(pendentes.find((p) => p.chave === b.dataset.virarAcao))));

    el.querySelectorAll('[data-nivel]').forEach((b) =>
      b.addEventListener('click', () => this.aplicarNivel(b.dataset.nivel, projetos)));

    // A pesquisa filtra o DOM a cada tecla (sem recarga, que mataria o foco) e
    // é reaplicada aqui porque a repintura acabou de redesenhar tudo visível.
    const campoFiltro = el.querySelector('[data-filtro-texto]');
    campoFiltro?.addEventListener('input', () => {
      this.filtroTexto = campoFiltro.value;
      this.aplicarFiltro(el);
    });
    // O responsável escuta `input`, não `change`: a caixa é de texto com lista,
    // e escolher um item da lista dispara os DOIS — mas digitar parte do nome
    // só dispara `input`. Com `change`, quem digitasse ficaria sem filtro
    // nenhum até sair do campo, que é justamente o uso que este campo existe
    // para atender.
    const campoResp = el.querySelector('[data-filtro-responsavel]');
    campoResp?.addEventListener('input', () => {
      this.filtroResponsavel = campoResp.value;
      this.aplicarFiltro(el);
    });
    const selFiltro = el.querySelector('[data-filtro-status]');
    selFiltro?.addEventListener('change', () => {
      this.filtroStatus = selFiltro.value;
      this.aplicarFiltro(el);
    });
    this.aplicarFiltro(el);

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
    // O cabeçalho da FRENTE gruda logo abaixo do de Projetos, e para isso
    // precisa saber a altura dele — medida a cada pintura, com ResizeObserver,
    // porque o bloco quebra em duas ou três linhas conforme a largura. Um
    // palpite em `rem` curto demais deixaria um cabeçalho por cima do outro.
    Diag.ligarCabecalhoFixo(el, '.cabecalho-projetos');
    this.medirCabecalhosProjeto(el);
    this.ligarPopoversResumo(el, conteudos);
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
        const status = r.closest('[data-status]')?.dataset.status;
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
            // O salvo muda antes da recarga: se ela falhar, o próximo arraste
            // ainda parte do valor que o servidor tem de fato
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
          this.atualizarMedias(el, r);
        } catch (e) {
          // Falhou: devolve a barra ao valor que está no servidor
          voltar();
          // A ação foi cancelada por outra pessoa depois que este cartão foi
          // desenhado: a barra aqui não deveria mais existir. Recarregar é o
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
   * Recalcula na tela os percentuais que a ação ajustada afeta: o da iniciativa
   * dela e o do projeto. Os dois níveis, não só o projeto — a barra da
   * iniciativa ficaria mostrando o número velho até a próxima carga, bem na
   * frente da ação que acabou de mudar.
   *
   * A média sai dos valores que estão na TELA (as barras), não dos dados da
   * carga: é a ação recém-arrastada que precisa entrar na conta. Ação cancelada
   * não tem barra com `data-progresso` (o cartão dela desenha uma faixa
   * inativa), então fica de fora daqui pelo mesmo caminho de `panorama`.
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
        : { planejamento_id: this.plan.id, projeto_id: projetoId },
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
            data-virar-acao="${p.chave}">Transformar em ação</button>` : ''}
        </span>
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

  modalDesdobramento(projetoId, dd, iniciativaId = null) {
    Modal.abrir({
      titulo: dd ? 'Editar ação' : 'Nova ação',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId,
            iniciativa_id: dd.iniciativa_id ?? iniciativaId,
            recorrencia: dd.recorrencia || 'NENHUMA',
            recorrencia_ate: dd.recorrencia_ate ?? '',
            recorrencia_dias_semana: dd.recorrencia === 'SEMANAL' ? this.diasDaAcao(dd) : [],
            recorrencia_dias_mes: dd.recorrencia === 'MENSAL' ? this.diasDaAcao(dd) : [] }
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

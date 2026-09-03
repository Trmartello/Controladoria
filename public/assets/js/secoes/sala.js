// Sala do encontro — a casa do PIN e do QR.
//
// Até a Fase 3 cada análise carregava a faixa inteira da sessão (PIN, QR,
// participantes, roteiro). Isso poluía todas as telas para repetir a mesma
// informação: o PIN é UM para o projeto inteiro. Aqui ele tem casa, e a aba é
// a **tela de projeção** do encontro — QR grande, PIN grande, para o telão.
//
// Os DOIS ritos moram aqui: a tempestade de ideias (`modo` TEMPESTADE) e o
// encontro com roteiro (`modo` QUIZ). A sala é uma só por planejamento, então
// no máximo um deles está aberto — e a aba mostra o que está no ar. Antes, o
// PIN e o QR da tempestade ficavam na tela da Coleta: a mesma sala aparecia em
// dois lugares diferentes conforme o rito, e quem procurava "onde está o PIN"
// tinha de saber de antemão qual dos dois havia sido aberto.
//
// O que NÃO mora aqui é o painel de sugestões: ele fica na análise, porque é
// onde a voz da sala vira fator, item de cenário ou escolha da cascata.

const SecaoSala = {
  // Fora do relógio compartilhado: a Sala é conduzida pelo relógio do QuizSala,
  // que acompanha a pergunta ativa e o foco do condutor no roteiro.
  planosVigiados() { return []; },

  plan: null,
  quiz: null,
  tempestade: null,    // rodada de tempestade aberta, se houver
  relogioQuiz: null,
  relogioTempestade: null,
  assinaturaQuiz: null,
  quizUi: { roteiroAberto: true },
  secaoId: 'secao-sala',
  perguntaFoco: null,

  async carregar() {
    const el = document.getElementById('secao-sala');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    // A rodada vem SEM filtro de ano: a sala é do planejamento, e a tempestade
    // aberta pode ter nascido num ano que não é o do seletor do diagnóstico —
    // filtrando por ano, a aba diria "nenhuma sessão" com a sala no ar.
    [this.quiz, this.tempestade] = await Promise.all([
      QuizSala.estado(this.plan.id, null),
      this.buscarTempestade(),
    ]);
    el.innerHTML = this.corpo();
    this.ligar(el);
    // Semeia a assinatura com o que acabou de ser pintado: sem isso a primeira
    // batida (4s) sempre difere, o corpo é repintado e o SVG do QR é REGERADO —
    // uma piscada no telão logo depois de abrir a aba.
    this.assinaturaQuiz = QuizSala.assinatura(this.quiz);
    QuizSala.armarRelogio(this);
    this.armarRelogioTempestade();
  },

  async buscarTempestade() {
    const rodadas = await App.api(`/api/rodadas?planejamento_id=${this.plan.id}`).catch(() => []);
    return rodadas.find((r) => r.modo !== 'QUIZ' && r.situacao === 'ABERTA') || null;
  },

  corpo() {
    if (this.tempestade) return this.corpoTempestade();
    if (this.quiz?.sessao) return this.corpoQuiz();
    return this.semSessao();
  },

  cabecalho(acao = '') {
    return `<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h1>Sala do encontro — ${Modal.esc(App.rotuloContexto())}</h1>
      ${acao}
    </div>`;
  },

  /**
   * O cartão de projeção, o mesmo para os dois ritos: QR grande, PIN grande e
   * o endereço, com os selos e as ações de quem conduz embaixo. Escritos
   * separados, tempestade e encontro divergiriam no primeiro ajuste do telão.
   * O PIN é credencial de escrita: perfil LEITURA não o recebe do servidor, e
   * sem ele não há QR — dizer isso é melhor que uma caixa vazia.
   */
  /** dd/mm/aaaa hh:mm a partir do DATETIME do banco — o prazo do questionário. */
  dataHora(iso) {
    if (!iso) return '';
    const [data, hora] = String(iso).split(' ');
    return `${data.split('-').reverse().join('/')}${hora ? ` ${hora.slice(0, 5)}` : ''}`;
  },

  cartaoProjecao({ pin, tema, selos, acoes, rodape }) {
    return `<div class="card mb-3 cartao-projecao"><div class="card-body">
      <div class="d-flex flex-wrap gap-4 align-items-center">
        ${pin ? '<div class="caixa-qr-grande" id="qr-sala" aria-hidden="true"></div>' : ''}
        <div class="flex-grow-1" style="min-width:14rem">
          <div class="rotulo-secao">Entre em ${Modal.esc(location.host)}/entrar</div>
          ${pin
            ? `<div class="pin-projecao">${Modal.esc(pin)}</div>`
            : '<div class="text-muted small mt-1">Seu perfil é somente leitura: o PIN não é exibido.</div>'}
          <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">${selos}</div>
          ${pin ? `<div class="mt-2">${QuizSala.compartilhar(pin, tema)}</div>` : ''}
          ${acoes ? `<div class="mt-2 d-flex gap-2 flex-wrap">${acoes}</div>` : ''}
          ${rodape || ''}
        </div>
      </div>
    </div></div>`;
  },

  // ---- Tempestade de ideias ----
  corpoTempestade() {
    const r = this.tempestade;
    const podeConduzir = App.podeEditar();
    // O selo diz a CONSEQUÊNCIA, não só o estado: abrir a votação tira o campo
    // de enviar ideia do celular de todo mundo, e "votação aberta" sozinho não
    // contava isso — o condutor via a sala parada sem saber que tinha sido ele.
    const votacaoAberta = r.votacao === 'ABERTA';
    const selos = [
      `<span class="badge text-bg-light border">${r.participantes} participante(s)</span>`,
      `<span class="badge text-bg-light border">${r.ideias} ideia(s)</span>`,
      // Mesmo par de rótulos da Coleta: é a MESMA fase, e duas redações para
      // ela fariam o condutor procurar duas chaves onde só existe uma.
      votacaoAberta
        ? '<span class="badge text-bg-warning">sala fechada · escolhendo com ★</span>'
        : '<span class="badge text-bg-light border">sala aberta · recolhendo ideias</span>',
    ].join('');
    // Votação aberta sem nenhuma ideia é o beco: não há o que votar e não dá
    // para escrever. O celular contorna (volta a recolher), mas quem conduz
    // precisa saber que a fase está no lugar errado.
    const aviso = votacaoAberta && !Number(r.ideias) && podeConduzir
      ? `<div class="alert alert-warning py-2 small mt-2 mb-0">A sala está fechada e ainda não
         há nenhuma ideia para escolher. Reabra a sala para os celulares voltarem a escrever —
         enquanto não houver ideia na lista, eles seguem recolhendo.</div>`
      : '';
    const acoes = podeConduzir ? `
      <button class="btn btn-sm ${votacaoAberta ? 'btn-verde' : 'btn-outline-secondary'}" id="btn-votacao">
        ${votacaoAberta ? 'Reabrir a sala' : 'Fechar a sala (ir para as ★)'}</button>
      <button class="btn btn-sm btn-outline-danger" id="btn-encerrar-rodada">Encerrar tempestade</button>` : '';

    // O QUESTIONÁRIO: as perguntas em ordem, com quantas ideias e quantas
    // pessoas cada uma já recebeu — é o acompanhamento de quem conduz antes do
    // encontro. O prazo vai junto, porque é ele que fecha a rodada sozinha.
    const perguntas = r.perguntas || [];
    const questionario = perguntas.length ? `
        <div class="mt-3 pergunta-sala">
          <div class="rotulo-secao">Questionário prévio · ${perguntas.length} pergunta(s)${
            r.prazo ? ` · responder até ${SecaoSala.dataHora(r.prazo)}` : ''}</div>
          <ol class="lista-perguntas-sala mb-1">${perguntas.map((q) => `
            <li><span>${Modal.esc(q.enunciado)}</span>
              <span class="text-muted small text-nowrap">${q.ideias} ideia(s) · ${q.respondentes} pessoa(s)</span></li>`).join('')}
          </ol>
          ${podeConduzir ? `<button class="btn btn-sm btn-outline-secondary" id="btn-mais-pergunta"
            title="Entra no fim da lista, para não mudar a numeração de quem já respondeu">+ Acrescentar pergunta</button>` : ''}
        </div>` : '';
    return `
      ${this.cabecalho()}
      ${this.cartaoProjecao({
        pin: r.pin, tema: r.tema, selos, acoes,
        // A PERGUNTA é o que a sala lê no celular, e é da condução: o rumo do
        // encontro muda no meio dele. Fica em destaque, com o ✎ ao lado —
        // encerrar a rodada só para reformular jogaria fora PIN, participantes
        // e as ideias já coletadas.
        rodape: `${aviso}
        <div class="mt-3 pergunta-sala">
          <div class="rotulo-secao">${perguntas.length ? 'Tema do questionário' : 'A pergunta que abre a tempestade'}</div>
          <p class="mb-0">${Modal.esc(r.tema)}</p>
        </div>${questionario}`,
      })}
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <button class="btn btn-verde btn-sm" data-ir-coleta>Ir para a Coleta de Ideias</button>
        <span class="text-muted small">É lá que as ideias chegam, são agrupadas, priorizadas e
          tratadas — e onde a pergunta da sala se reescreve durante o encontro.</span>
      </div>`;
  },

  // ---- Encontro com roteiro (quiz) ----
  corpoQuiz() {
    const q = this.quiz;
    const p = q.pergunta;
    const prog = q.progresso || { atual: null, total: 0 };
    const podeConduzir = App.podeEditar();
    const selos = `<span class="badge text-bg-light border" data-quiz-participantes>${
      q.sessao.participantes} participante(s)</span>
      ${prog.total ? `<span class="badge text-bg-light border">${prog.atual
        ? `Pergunta ${prog.atual} de ${prog.total}` : `${prog.total} no roteiro`}</span>` : ''}`;

    return `
      ${this.cabecalho(podeConduzir
        ? '<button class="btn btn-outline-danger btn-sm" id="btn-encerrar-sala">Encerrar sessão</button>' : '')}
      ${this.cartaoProjecao({
        pin: q.sessao.pin,
        tema: q.sessao.tema,
        selos,
        acoes: '',
        rodape: `<div class="mt-2">
            ${p ? `<span class="badge badge-horizonte">Perguntando agora: ${Modal.esc(QuizSala.rotulo(p))}</span>
                   <span class="small text-muted d-block mt-1">em ${Modal.esc(p.tela)}</span>`
                : '<span class="badge text-bg-secondary">nenhuma pergunta ativa</span>'}
          </div>
          <div class="small text-muted mt-2">${Modal.esc(q.sessao.tema)}
            ${podeConduzir ? '<button class="btn btn-sm btn-link p-0 ms-1" id="btn-renomear-sala">renomear</button>' : ''}</div>`,
      })}

      <h2 class="h6 text-uppercase text-muted">Roteiro do encontro</h2>
      ${q.roteiro?.length
        ? QuizSala.roteiro(this)
        : `<p class="text-muted small">Nenhuma pergunta ainda. Vá até a análise que quer
           trabalhar e toque no 🎤 da categoria — ela entra no roteiro e vai para a sala.</p>`}`;
  },

  semSessao() {
    return `
      ${this.cabecalho()}
      <p class="text-muted">A sala é <strong>uma só para o planejamento inteiro</strong>: os
      participantes escaneiam o QR uma vez e o celular acompanha o que você abrir.</p>
      <div class="card"><div class="card-body">
        <p class="mb-2">Nenhuma sessão aberta.</p>
        ${App.podeEditar() ? `
          <p class="small text-muted mb-3">São dois ritos, e a sala é a mesma: a
          <strong>tempestade</strong> recolhe ideias soltas sobre uma pergunta; o
          <strong>encontro com roteiro</strong> percorre as análises, e as perguntas dele
          nascem do 🎤 de cada categoria.</p>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-verde" id="btn-abrir-rodada">Abrir tempestade de ideias</button>
            <button class="btn btn-outline-secondary" id="btn-abrir-sala">Abrir encontro com roteiro</button>
          </div>`
          : '<p class="small text-muted mb-0">Seu perfil é somente leitura.</p>'}
      </div></div>`;
  },

  ligar(el) {
    QuizSala.desenharQr(el.querySelector('#qr-sala'), this.tempestade?.pin || this.quiz?.sessao?.pin);
    QuizSala.ligarCompartilhar(el);
    QuizSala.ligarRoteiro(this, el);

    // Ida e volta entre as duas telas do mesmo encontro: a Coleta manda para cá
    // pelo "PIN e QR na Sala", e daqui se volta por este botão. Sem o par, o
    // condutor caía no menu ☰ no meio da oficina.
    el.querySelector('[data-ir-coleta]')?.addEventListener('click', () => App.mostrarSecao('coleta'));

    el.querySelector('#btn-abrir-rodada')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Abrir tempestade de ideias',
      url: '/api/rodadas',
      // A tempestade é do ANO da análise, como a Coleta: as ideias nascem
      // amarradas ao ano em que o diagnóstico está sendo feito.
      valores: { planejamento_id: this.plan.id, ano: Diag.ano(), max_ideias: 5, max_votos: 3 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: '', tipo: 'hidden' },
        QuizSala.campoPergunta(),
        // O QUESTIONÁRIO PRÉVIO (pedido do cliente, 2026-09-03): com perguntas
        // aqui, a sala as responde em ordem no celular, antes do encontro, e o
        // teto de ideias vale em CADA pergunta. Sem perguntas, é a tempestade
        // de tema único de sempre.
        { nome: 'perguntas', rotulo: 'Questionário prévio — uma pergunta por linha (opcional)',
          tipo: 'textarea', linhas: 5,
          exemplo: 'O que mais trava o nosso crescimento hoje?\nQue oportunidade estamos deixando passar?',
          ajuda: 'Com perguntas, cada pessoa responde uma por vez no celular, na ordem, antes do '
            + 'encontro — e o teto de ideias vale em cada pergunta. Sem perguntas, a sala responde só o tema acima.' },
        { nome: 'prazo', rotulo: 'Responder até (opcional)', tipo: 'date',
          ajuda: 'A rodada fecha sozinha no fim desse dia, ou quando você encerrar — o que vier primeiro.' },
        { nome: 'max_ideias', rotulo: 'Ideias por participante (em cada pergunta)', tipo: 'number', padrao: 5,
          ajuda: 'Um teto evita que uma pessoa domine a tempestade. Atingido o teto numa pergunta, o celular passa à próxima.' },
        { nome: 'max_votos', rotulo: 'Votos por participante', tipo: 'number', padrao: 3,
          ajuda: 'Só vale se você abrir a fase de votação depois.' },
      ],
      // A sala é do PROJETO: com outra sessão aberta, o servidor devolve
      // 409/SALA_ABERTA e o QuizSala pergunta se encerra aquela. Sem este
      // gancho a pergunta virava um erro vermelho no modal, sem como responder.
      enviar: (corpo) => QuizSala.pedir('/api/rodadas', corpo),
      aoSalvar: () => this.carregar(),
    }));

    // Editar a pergunta mora na aba da TEMPESTADE (a Coleta), onde o condutor
    // já está trabalhando as ideias: trocar o texto não mexe no PIN nem no QR,
    // e vir até a tela de projeção só para isso era viagem perdida.

    el.querySelector('#btn-votacao')?.addEventListener('click', async () => {
      try {
        await App.api(`/api/rodadas/${this.tempestade.id}/votacao`,
          { planejamento_id: this.plan.id, abrir: this.tempestade.votacao !== 'ABERTA' });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    });

    // Pergunta a mais no questionário, sempre ao FIM: reordenar depois da
    // primeira resposta trocaria a "pergunta 2" que alguém já respondeu.
    el.querySelector('#btn-mais-pergunta')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Acrescentar pergunta ao questionário',
      url: `/api/rodadas/${this.tempestade.id}/perguntas`,
      valores: { planejamento_id: this.plan.id, perguntas: '' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'perguntas', rotulo: 'Perguntas novas — uma por linha', tipo: 'textarea', linhas: 3,
          obrigatorio: true, ajuda: 'Entram depois das que já existem.' },
      ],
      salvar: { rotulo: 'Acrescentar' },
      aoSalvar: () => this.carregar(),
    }));

    el.querySelector('#btn-encerrar-rodada')?.addEventListener('click', async () => {
      if (!confirm('Encerrar a tempestade? Os participantes não conseguem mais enviar ideias.')) return;
      try {
        await App.api(`/api/rodadas/${this.tempestade.id}/encerrar`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    });

    el.querySelector('#btn-abrir-sala')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Abrir o encontro com roteiro',
      url: '/api/quiz/abrir',
      valores: { planejamento_id: this.plan.id, tema: '', max_ideias: 5, max_votos: 3 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'tema', rotulo: 'Nome do encontro',
          exemplo: 'Ex.: Oficina de diagnóstico — diretoria, agosto/2026',
          ajuda: 'O PIN vale para o encontro inteiro: todas as análises usam a mesma sala.' },
        { nome: 'max_ideias', rotulo: 'Sugestões por pessoa (em cada pergunta)',
          tipo: 'number', padrao: 5 },
        // A estrela é a fase que abre sozinha quando o condutor fecha o 🎤: o
        // celular passa a votar no que a sala acabou de dizer. O teto é POR
        // PERGUNTA, e por isso ele se escolhe aqui, uma vez para o encontro.
        { nome: 'max_votos', rotulo: 'Estrelas por pessoa (em cada pergunta)',
          tipo: 'number', padrao: 3,
          ajuda: 'Fechada a pergunta, quem está na sala marca com estrela as respostas '
            + 'mais importantes — até este limite, em cada pergunta.' },
      ],
      // A sessão nasce SEM pergunta: o roteiro cresce com o 🎤 de cada análise.
      // Pedir um alvo aqui obrigaria a escolher a primeira pergunta antes de
      // saber por onde o encontro vai começar.
      enviar: (corpo) => QuizSala.pedir('/api/quiz/abrir', corpo),
      aoSalvar: () => App.recarregarSecaoAtiva(),
    }));

    el.querySelector('#btn-renomear-sala')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Renomear o encontro',
      url: '/api/quiz/renomear',
      valores: { planejamento_id: this.plan.id, tema: this.quiz.sessao.tema },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'tema', rotulo: 'Nome do encontro', obrigatorio: true },
      ],
      aoSalvar: () => App.recarregarSecaoAtiva(),
    }));

    el.querySelector('#btn-encerrar-sala')?.addEventListener('click', async () => {
      if (!confirm('Encerrar a sessão? Os celulares deixam de receber perguntas; as sugestões ficam guardadas.')) return;
      try {
        await App.api('/api/quiz/encerrar', { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      App.recarregarSecaoAtiva();
    });
  },

  /**
   * A tempestade tem relógio próprio: o de `QuizSala` acompanha a sessão de
   * roteiro (e sai fora quando não há uma). Aqui o que muda é o contador de
   * participantes, o de ideias e a fase de votação.
   */
  armarRelogioTempestade() {
    clearInterval(this.relogioTempestade);
    this.relogioTempestade = null;
    if (!this.tempestade) return;
    this.relogioTempestade = setInterval(async () => {
      const el = document.getElementById(this.secaoId);
      if (!el || el.classList.contains('d-none')) {
        clearInterval(this.relogioTempestade);
        this.relogioTempestade = null;
        return;
      }
      // Modal aberto ou campo em foco: repintar fecharia a edição da pergunta
      if (document.querySelector('.modal.show')) return;
      const ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT')) return;
      const nova = await this.buscarTempestade().catch(() => undefined);
      if (nova === undefined) return;  // rede piscou; a próxima batida tenta
      const retrato = (r) => (r ? JSON.stringify(
        [r.id, r.tema, r.participantes, r.ideias, r.votacao]) : 'sem-rodada');
      if (retrato(nova) === retrato(this.tempestade)) return;
      this.tempestade = nova;
      el.innerHTML = this.corpo();
      this.ligar(el);
      if (!nova) {
        clearInterval(this.relogioTempestade);
        this.relogioTempestade = null;
      }
    }, 4000);
  },

  /** Esta aba não mostra sugestões: navegar pelo roteiro leva à análise. */
  aoNavegar() {},

  async aoBater(quizNovo) {
    if (App.secaoAtiva !== 'sala') return;
    // Com a tempestade no ar, quem manda na tela é o relógio dela: repintar
    // pelo estado do quiz (que é "sem sessão") apagaria o PIN do telão.
    if (this.tempestade) return;
    const assinatura = QuizSala.assinatura(quizNovo);
    if (assinatura === this.assinaturaQuiz) return;
    this.assinaturaQuiz = assinatura;
    const el = document.getElementById(this.secaoId);
    if (!el) return;
    el.innerHTML = this.corpo();
    this.ligar(el);
    if (!quizNovo.sessao) {
      clearInterval(this.relogioQuiz);
      this.relogioQuiz = null;
    }
  },
};

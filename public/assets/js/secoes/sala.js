// Sala do encontro — a casa do PIN e do QR.
//
// Até a Fase 3 cada análise carregava a faixa inteira da sessão (PIN, QR,
// participantes, roteiro). Isso poluía todas as telas para repetir a mesma
// informação: o PIN é UM para o projeto inteiro. Aqui ele tem casa, e a aba é
// a **tela de projeção** do encontro — QR grande, PIN grande, para o telão.
//
// O que NÃO mora aqui é o painel de sugestões: ele fica na análise, porque é
// onde a voz da sala vira fator, item de cenário ou escolha da cascata.

const SecaoSala = {
  plan: null,
  quiz: null,
  relogioQuiz: null,
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
    this.quiz = await QuizSala.estado(this.plan.id, null);
    el.innerHTML = this.corpo();
    this.ligar(el);
    QuizSala.armarRelogio(this);
  },

  corpo() {
    const q = this.quiz;
    if (!q?.sessao) return this.semSessao();

    const p = q.pergunta;
    const prog = q.progresso || { atual: null, total: 0 };
    const podeConduzir = App.podeEditar();
    // O PIN é credencial de escrita: perfil LEITURA não o recebe do servidor.
    // Sem ele o QR não existe — e dizer isso é melhor que uma caixa vazia.
    const temPin = !!q.sessao.pin;

    return `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Sala do encontro — ${Modal.esc(App.rotuloContexto())}</h1>
        ${podeConduzir ? '<button class="btn btn-outline-danger btn-sm" id="btn-encerrar-sala">Encerrar sessão</button>' : ''}
      </div>

      <div class="card mb-3 cartao-projecao"><div class="card-body">
        <div class="d-flex flex-wrap gap-4 align-items-center">
          ${temPin
            ? '<div class="caixa-qr-grande" id="qr-sala" aria-hidden="true"></div>'
            : ''}
          <div class="flex-grow-1" style="min-width:14rem">
            <div class="rotulo-secao">Entre em ${Modal.esc(location.host)}/entrar</div>
            ${temPin
              ? `<div class="pin-projecao">${Modal.esc(q.sessao.pin)}</div>`
              : '<div class="text-muted small mt-1">Seu perfil é somente leitura: o PIN não é exibido.</div>'}
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
              <span class="badge text-bg-light border" data-quiz-participantes>${q.sessao.participantes} participante(s)</span>
              ${prog.total ? `<span class="badge text-bg-light border">${prog.atual
                ? `Pergunta ${prog.atual} de ${prog.total}` : `${prog.total} no roteiro`}</span>` : ''}
            </div>
            <div class="mt-2">
              ${p ? `<span class="badge badge-horizonte">Perguntando agora: ${Modal.esc(QuizSala.rotulo(p))}</span>
                     <span class="small text-muted d-block mt-1">em ${Modal.esc(p.tela)}</span>`
                  : '<span class="badge text-bg-secondary">nenhuma pergunta ativa</span>'}
            </div>
            <div class="small text-muted mt-2">${Modal.esc(q.sessao.tema)}
              ${podeConduzir ? '<button class="btn btn-sm btn-link p-0 ms-1" id="btn-renomear-sala">renomear</button>' : ''}</div>
          </div>
        </div>
      </div></div>

      <h2 class="h6 text-uppercase text-muted">Roteiro do encontro</h2>
      ${q.roteiro?.length
        ? QuizSala.roteiro(this)
        : `<p class="text-muted small">Nenhuma pergunta ainda. Vá até a análise que quer
           trabalhar e toque no 🎤 da categoria — ela entra no roteiro e vai para a sala.</p>`}`;
  },

  semSessao() {
    return `
      <h1>Sala do encontro — ${Modal.esc(App.rotuloContexto())}</h1>
      <p class="text-muted">A sala é <strong>uma só para o planejamento inteiro</strong>: os
      participantes escaneiam o QR uma vez e o celular acompanha a análise que você abrir.</p>
      <div class="card"><div class="card-body">
        <p class="mb-2">Nenhuma sessão aberta.</p>
        <p class="small text-muted mb-3">Você pode abrir a sala aqui, ou simplesmente ir até uma
        análise e tocar no 🎤 de uma categoria — o sistema pergunta antes de abrir a sessão.</p>
        ${App.podeEditar()
          ? '<button class="btn btn-verde" id="btn-abrir-sala">Abrir a sala</button>'
          : '<p class="small text-muted mb-0">Seu perfil é somente leitura.</p>'}
      </div></div>`;
  },

  ligar(el) {
    QuizSala.desenharQr(el.querySelector('#qr-sala'), this.quiz?.sessao?.pin);
    QuizSala.ligarRoteiro(this, el);

    el.querySelector('#btn-abrir-sala')?.addEventListener('click', () => Modal.abrir({
      titulo: 'Abrir a sala do encontro',
      url: '/api/quiz/abrir',
      valores: { planejamento_id: this.plan.id, tema: '', max_ideias: 5 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'tema', rotulo: 'Nome do encontro',
          exemplo: 'Ex.: Oficina de diagnóstico — diretoria, agosto/2026',
          ajuda: 'O PIN vale para o encontro inteiro: todas as análises usam a mesma sala.' },
        { nome: 'max_ideias', rotulo: 'Sugestões por pessoa (em cada pergunta)',
          tipo: 'number', padrao: 5 },
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

  /** Esta aba não mostra sugestões: navegar pelo roteiro leva à análise. */
  aoNavegar() {},

  async aoBater(quizNovo) {
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

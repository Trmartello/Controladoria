// Cascata de Escolhas: matriz drivers (linhas bases) × horizontes.
// Clique na célula abre o detalhe com a síntese + aberturas por eixo
// (cada uma com escolha e renúncia); edições via modal.
//
// O preenchimento pode ser COLABORATIVO: o condutor abre uma sessão (rodada
// modo CASCATA — a mesma sala da tempestade, com PIN e QR) e pergunta a célula
// à sala. As sugestões chegam ao vivo em duas colunas (Respostas e Renúncias)
// e o condutor as USA na célula: muitas vozes vinculadas, um texto por lado.

const SecaoCascata = {
  dados: null,
  plan: null,
  celulaAberta: null, // { horizonteId, driverId }
  quiz: null,         // estado da sessão do quiz (ou {sessao:null})
  relogioQuiz: null,
  assinaturaQuiz: null,
  // Estado visual da faixa compartilhada (QuizSala): sem guardar, cada batida
  // do polling reconstruía a faixa e RECOLHIA o QR projetado no telão
  quizUi: { qrAberto: false, roteiroAberto: false },
  secaoId: 'secao-cascata',
  // Pergunta em FOCO: a que o condutor está examinando pelo roteiro. Navegar
  // é local — só ativar/reabrir/encerrar mexe no celular da sala.
  perguntaFoco: null,

  async carregar() {
    const el = document.getElementById('secao-cascata');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    // Foco vindo do "Ver" da aba Sala: abre a célula da pergunta examinada
    const vindo = QuizSala.consumirFoco('cascata');
    if (vindo) {
      this.perguntaFoco = vindo.perguntaId;
      this.aoNavegar(vindo.pergunta);
    }
    this.plan = await App.planejamento();
    [this.dados, this.quiz] = await Promise.all([
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      QuizSala.estado(this.plan.id, this.perguntaFoco),
    ]);
    const { horizontes, drivers, eixos, escolhas } = this.dados;

    const totalAberturas = horizontes.length * drivers.length * eixos.length;
    const totalSinteses = horizontes.length * drivers.length;
    const feitasAberturas = escolhas.filter((e) => e.eixo_id).length;
    const feitasSinteses = escolhas.filter((e) => !e.eixo_id).length;

    const cabecalho = horizontes.map((h) => `
      <th class="celula-horizonte">
        <div>${Modal.esc(h.nome)} · ${h.ano_inicio}–${h.ano_fim}</div>
        <div class="small fw-normal fst-italic">“${Modal.esc(h.tema)}”</div>
      </th>`).join('');

    const linhas = drivers.map((d) => {
      const celulas = horizontes.map((h) => {
        const sintese = escolhas.find((e) => e.driver_id == d.id && e.horizonte_id == h.id && !e.eixo_id);
        const aberturas = escolhas.filter((e) => e.driver_id == d.id && e.horizonte_id == h.id && e.eixo_id).length;
        const ativa = this.celulaAberta
          && this.celulaAberta.driverId == d.id && this.celulaAberta.horizonteId == h.id;
        return `<td class="celula-cascata ${ativa ? 'ativa' : ''}" data-driver="${d.id}" data-horizonte="${h.id}">
          <div class="small">${sintese ? Modal.esc(sintese.escolha) : '<span class="text-muted">— definir síntese —</span>'}</div>
          <span class="badge ${aberturas === eixos.length ? 'text-bg-success' : 'text-bg-light border'} mt-1">${aberturas}/${eixos.length} eixos</span>
        </td>`;
      }).join('');
      return `<tr><th class="celula-driver">${Modal.esc(d.nome)}</th>${celulas}</tr>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Cascata de Escolhas — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2">
          <span class="badge text-bg-success fs-6">Aberturas ${feitasAberturas}/${totalAberturas}</span>
          <span class="badge badge-horizonte fs-6">Sínteses ${feitasSinteses}/${totalSinteses}</span>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2"
        id="selo-quiz">${QuizSala.selo(this, 'cascata')}</div>
      <p class="text-muted">Cada célula <em>driver × horizonte</em> tem uma síntese e ${eixos.length}
      aberturas por eixo — cada escolha declara também a sua renúncia. Clique na célula para detalhar.</p>
      <div class="table-responsive">
        <table class="table table-bordered tabela-cascata">
          <thead><tr><th class="celula-driver">LINHAS BASES</th>${cabecalho}</tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div>
      <div id="detalhe-celula"></div>`;

    el.querySelectorAll('.celula-cascata').forEach((td) => {
      td.addEventListener('click', () => {
        this.celulaAberta = {
          driverId: parseInt(td.dataset.driver, 10),
          horizonteId: parseInt(td.dataset.horizonte, 10),
        };
        // Clicar na matriz volta o foco à regra padrão (a pergunta ativa)
        this.perguntaFoco = null;
        el.querySelectorAll('.celula-cascata').forEach((c) => c.classList.remove('ativa'));
        td.classList.add('ativa');
        this.renderDetalhe();
        document.getElementById('detalhe-celula').scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    QuizSala.ligarSelo(el);
    // Sessão aberta: sem célula escolhida ainda, abre a da pergunta ativa —
    // quem entra na seção durante o encontro cai direto no que a sala vê.
    // Só a pergunta desta tela tem célula: a sala é do projeto e a ativa pode
    // ser um cenário ou um quadrante da SWOT, que não apontam para lugar nenhum
    // na matriz da cascata.
    if (!this.celulaAberta && this.quiz?.pergunta?.alvo_tipo === 'CASCATA') {
      this.celulaAberta = {
        driverId: Number(this.quiz.pergunta.driver_id),
        horizonteId: Number(this.quiz.pergunta.horizonte_id),
      };
      const td = el.querySelector(`.celula-cascata[data-driver="${this.celulaAberta.driverId}"][data-horizonte="${this.celulaAberta.horizonteId}"]`);
      if (td) td.classList.add('ativa');
    }
    if (this.celulaAberta) this.renderDetalhe();
    QuizSala.armarRelogio(this);
  },

  // ---- Sessão do quiz: a faixa é o componente compartilhado (QuizSala) ----
  /**
   * Chamado pelo QuizSala quando o condutor examina outra pergunta do roteiro:
   * posiciona ESTA tela na célula dela. Pergunta de outra análise (cenário, um
   * quadrante da SWOT) não tem célula — a matriz fica onde está e o condutor vê
   * as sugestões pela tela de origem.
   */
  aoNavegar(pergunta) {
    if (pergunta.alvo_tipo !== 'CASCATA') return;
    this.celulaAberta = {
      driverId: Number(pergunta.driver_id),
      horizonteId: Number(pergunta.horizonte_id),
    };
  },

  /**
   * Batida do polling: repinta a faixa e o detalhe quando algo mudou. Repintar
   * à toa custa o foco e, no celular, o teclado aberto.
   */
  async aoBater(quizNovo) {
    const el = document.getElementById(this.secaoId);
    // A célula e as vozes do detalhe saem de this.dados, e o estado do quiz não
    // fala delas: outro condutor salvando a escolha da célula não mexe em nada
    // que a assinatura do QuizSala enxergue. Rebuscar ANTES de comparar é o que
    // impede o detalhe de seguir mostrando o texto velho — e um modal aberto
    // desse estado reenviaria o conjunto antigo, desfazendo o recém-salvo.
    try {
      this.dados = await App.api(`/api/cascata?planejamento_id=${this.plan.id}`);
    } catch (e) {
      return;
    }
    const assinatura = QuizSala.assinatura(quizNovo)
      + JSON.stringify((this.dados.escolhas || []).map(
        (e) => [e.id, e.escolha, e.renuncia, (e.sugestoes || []).length]));
    if (assinatura === this.assinaturaQuiz) return;
    this.assinaturaQuiz = assinatura;
    const selo = document.getElementById('selo-quiz');
    if (selo) {
      selo.innerHTML = QuizSala.selo(this, 'cascata');
      QuizSala.ligarSelo(el);
    }
    if (!quizNovo.sessao) {
      clearInterval(this.relogioQuiz);
      this.relogioQuiz = null;
    }
    if (this.celulaAberta) this.renderDetalhe();
  },

  /**
   * A pergunta em FOCO (a examinada pelo roteiro; por padrão, a ativa),
   * quando ela pertence à célula aberta no detalhe.
   */
  /**
   * A pergunta ATIVA é exatamente esta parte da célula aberta? É o que decide
   * se o 🎤 do cartão vira selo — a categoria que já está na sala não é alvo de
   * toque (reativar reabriria a pergunta e zeraria o cronômetro dela).
   */
  perguntaAtivaDoAlvo(eixoId) {
    const p = this.quiz?.pergunta;
    if (!p || p.alvo_tipo !== 'CASCATA' || !this.celulaAberta) return false;
    return Number(p.driver_id) === Number(this.celulaAberta.driverId)
      && Number(p.horizonte_id) === Number(this.celulaAberta.horizonteId)
      && Number(p.eixo_id || 0) === Number(eixoId || 0);
  },

  perguntaDaCelulaAberta() {
    const p = this.quiz?.foco || this.quiz?.pergunta;
    // A sala é do projeto: a ativa pode ser de outra análise. Sem conferir o
    // alvo, uma pergunta de cenário (driver_id e horizonte_id nulos) casaria
    // com qualquer célula cujos ids também viessem indefinidos.
    if (!p || p.alvo_tipo !== 'CASCATA' || !this.celulaAberta) return null;
    return Number(p.driver_id) === Number(this.celulaAberta.driverId)
      && Number(p.horizonte_id) === Number(this.celulaAberta.horizonteId) ? p : null;
  },

  renderDetalhe() {
    const { horizontes, drivers, eixos, escolhas } = this.dados;
    const alvo = document.getElementById('detalhe-celula');
    const { driverId, horizonteId } = this.celulaAberta;
    const driver = drivers.find((d) => d.id == driverId);
    const horizonte = horizontes.find((h) => h.id == horizonteId);
    if (!driver || !horizonte) {
      // Célula aberta de um ciclo anterior — o contexto mudou; fecha o detalhe
      this.celulaAberta = null;
      alvo.innerHTML = '';
      return;
    }
    const daCelula = (eixoId) => escolhas.find((e) =>
      e.driver_id == driverId && e.horizonte_id == horizonteId &&
      (eixoId ? e.eixo_id == eixoId : !e.eixo_id));

    const sintese = daCelula(null);
    const cartaoEscolha = (rotulo, registro, eixoId) => {
      // Selo na cor do quadrante, igual ao da SWOT — o texto do fator no title
      const fatores = (registro?.fatores || []).map((f) => {
        const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
        return `<span class="badge" style="color:${cor};background:${cor}1f"
          title="${Modal.esc(f.descricao)}">${Diag.QUADRANTES[f.categoria] || f.categoria}${
          f.score ? ` · GUT ${f.score}` : ''}</span>`;
      }).join(' ');
      // Vozes do quiz vinculadas: registro de origem com ✕ para desvincular
      const vozes = (registro?.sugestoes || []).map((s) => `
        <span class="badge voz-vinculada ${s.tipo_resposta === 'RENUNCIA' ? 'voz-renuncia' : 'voz-escolha'}"
          title="${Modal.esc(s.autor)}: ${Modal.esc(s.texto)}">
          ${s.tipo_resposta === 'RENUNCIA' ? 'R' : 'E'} · ${Modal.esc(s.autor)}${Number(s.votos) ? ` ★${s.votos}` : ''}
          ${App.podeEditar() ? `<button type="button" class="btn-desvincular" data-desvincular="${s.id}"
            data-eixo-celula="${eixoId ?? ''}" title="Tirar esta voz da célula"
            aria-label="Tirar esta voz da célula">×</button>` : ''}
        </span>`).join(' ');
      return `<div class="card mb-2"><div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between gap-2">
          <div>
            <div class="fw-bold small text-uppercase">${Modal.esc(rotulo)}</div>
            <div class="small mt-1">${registro ? Modal.esc(registro.escolha) : '<span class="text-muted">Não definida.</span>'}</div>
            ${registro?.renuncia ? `<div class="small text-muted mt-1"><strong>Renúncia:</strong> ${Modal.esc(registro.renuncia)}</div>` : ''}
            ${fatores ? `<div class="mt-1 d-flex gap-1 flex-wrap">${fatores}</div>` : ''}
            ${vozes ? `<div class="mt-1 d-flex gap-1 flex-wrap">${vozes}</div>` : ''}
          </div>
          ${App.podeEditar() ? `<div class="d-flex gap-1 flex-shrink-0 align-items-start">
            ${QuizSala.microfone(
              { alvo_tipo: 'CASCATA', horizonte_id: horizonteId, driver_id: driverId,
                alvos: [eixoId ?? null] },
              rotulo,
              { ativo: this.perguntaAtivaDoAlvo(eixoId) })}
            <button class="btn btn-sm btn-outline-secondary" data-editar-celula="${eixoId ?? ''}">${registro ? 'Editar' : 'Definir'}</button>
            ${registro ? `<button class="btn btn-sm btn-outline-danger" data-excluir-celula="${registro.id}">×</button>` : ''}
          </div>` : ''}
        </div>
      </div></div>`;
    };

    const podePerguntar = App.podeEditar();
    const perguntaAqui = this.perguntaDaCelulaAberta();

    alvo.innerHTML = `
      <div class="card mt-3 border-success">
        <div class="card-header bg-success-subtle">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="flex-grow-1">
              <strong>${Modal.esc(driver.nome)}</strong> × ${Modal.esc(horizonte.nome)}
              (${horizonte.ano_inicio}–${horizonte.ano_fim} · “${Modal.esc(horizonte.tema)}”)
              <div class="small text-muted">${Modal.esc(horizonte.objetivo)}</div>
            </div>
            ${podePerguntar ? `<button class="btn btn-sm btn-outline-secondary"
              id="btn-perguntar-sala" title="Pôr várias partes desta célula no roteiro">
              Montar roteiro desta célula</button>` : ''}
            ${perguntaAqui ? '<span class="badge text-bg-success align-self-center">A sala está respondendo esta célula</span>' : ''}
          </div>
        </div>
        <div class="card-body">
          <div id="quiz-vivo">${this.painelVivo()}</div>
          ${cartaoEscolha('Síntese da célula (texto da matriz)', sintese, null)}
          <div class="row g-2 mt-1">
            ${eixos.map((x) => `<div class="col-md-6">${cartaoEscolha(`Eixo · ${x.nome}`, daCelula(x.id), x.id)}</div>`).join('')}
          </div>
        </div>
      </div>`;

    if (!App.podeEditar()) return;

    // Sempre clicável: com a pergunta ativa NESTA célula, o botão serve para
    // trocar o alvo (da síntese para um eixo, ou entre eixos) — desabilitá-lo
    // prendia o condutor na primeira pergunta da célula
    const btnPerguntar = alvo.querySelector('#btn-perguntar-sala');
    if (btnPerguntar) {
      btnPerguntar.addEventListener('click', () => this.perguntarASala());
    }

    alvo.querySelectorAll('[data-editar-celula]').forEach((b) => b.addEventListener('click', () => {
      const eixoId = b.dataset.editarCelula ? parseInt(b.dataset.editarCelula, 10) : null;
      this.abrirModalCelula(eixoId);
    }));

    // Tirar uma voz da célula: reenvia a célula com o conjunto sem ela — o
    // texto redigido não muda (quem decide se muda é quem escreveu)
    alvo.querySelectorAll('[data-desvincular]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      if (!confirm('Tirar esta voz da célula? O texto da célula não muda.')) return;
      const eixoId = b.dataset.eixoCelula ? parseInt(b.dataset.eixoCelula, 10) : null;
      const registro = daCelula(eixoId);
      if (!registro) return;
      try {
        await App.api('/api/cascata', {
          planejamento_id: this.plan.id,
          horizonte_id: horizonteId,
          driver_id: driverId,
          eixo_id: eixoId ?? '',
          escolha: registro.escolha,
          renuncia: registro.renuncia || '',
          fatores: (registro.fatores || []).map((f) => f.id),
          sugestoes: (registro.sugestoes || []).map((s) => s.id)
            .filter((id) => id !== Number(b.dataset.desvincular)),
        });
      } catch (e) {
        alert(e.message);
      }
      App.recarregarSecaoAtiva();
    }));

    alvo.querySelectorAll('[data-excluir-celula]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta escolha?')) return;
      try {
        await App.api(`/api/cascata/${b.dataset.excluirCelula}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      App.recarregarSecaoAtiva();
    }));

    QuizSala.ligarMicrofones(this, alvo);
    this.ligarPainelVivo(alvo);
  },

  // ---- Painel ao vivo: as duas áreas de coleta da pergunta ativa ----
  /**
   * Só aparece quando a pergunta ativa é a célula aberta. Duas colunas —
   * Respostas e Renúncias — cada ficha com autor e "Usar", que leva o texto ao
   * campo do MESMO lado no modal da célula. Vincular acumula; o texto é um só.
   */
  painelVivo() {
    const p = this.perguntaDaCelulaAberta();
    if (!p) return '';
    const sugestoes = this.quiz?.sugestoes || [];
    const recolhido = this.quizUi?.painelRecolhido;
    const coluna = (tipo, rotulo, classe) => {
      const fichas = sugestoes.filter((s) => s.tipo_resposta === tipo);
      return `<div class="col-md-6"><div class="coluna-quiz ${classe}">
        <div class="fw-bold small text-uppercase mb-2">${rotulo}
          <span class="badge rounded-pill text-bg-secondary">${fichas.length}</span></div>
        ${QuizSala.fichas(fichas, { virou: 'na célula' })}
      </div></div>`;
    };
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      ${QuizSala.cabecalhoPainel(this, p, sugestoes.length)}
      ${recolhido ? '' : `<div class="row g-2 mt-1">
        ${coluna('ESCOLHA', 'Respostas (escolha)', 'coluna-escolha')}
        ${coluna('RENUNCIA', 'Renúncias', 'coluna-renuncia')}
      </div>`}
    </div></div>`;
  },

  ligarPainelVivo(alvo) {
    QuizSala.ligarRecolher(this, alvo);
    alvo.querySelectorAll('[data-reabrir-foco]').forEach((b) => b.addEventListener('click', async () => {
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
    alvo.querySelectorAll('[data-usar-sugestao]').forEach((b) => b.addEventListener('click', () => {
      const s = (this.quiz?.sugestoes || []).find((x) => x.id == b.dataset.usarSugestao);
      const p = this.perguntaDaCelulaAberta();
      if (!s || !p) return;
      this.abrirModalCelula(p.eixo_id ? Number(p.eixo_id) : null, s);
    }));
    alvo.querySelectorAll('[data-excluir-sugestao]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta sugestão? Ela some para a sala também.')) return;
      try {
        await App.api(`/api/quiz/sugestao/${b.dataset.excluirSugestao}/excluir`, {
          planejamento_id: this.plan.id,
        });
        this.quiz = await App.api(`/api/quiz?planejamento_id=${this.plan.id}`);
        this.renderDetalhe();
      } catch (e) {
        alert(e.message);
      }
    }));
  },

  /**
   * Montar o roteiro desta célula: várias partes de uma vez ("as 6 aberturas de
   * *Como Vencer*"). É trabalho de PREPARAÇÃO — conduzir é o 🎤 de cada cartão,
   * de um toque. Por isso o padrão aqui é só enfileirar, sem mexer na sala.
   */
  perguntarASala() {
    const { driverId, horizonteId } = this.celulaAberta;
    const driver = this.dados.drivers.find((d) => d.id == driverId);
    // Vários alvos de uma vez: "as 6 aberturas de Como Vencer" entram juntas
    // no roteiro. 'S' marca a síntese — o transformar troca por null.
    const opcoesAlvo = [
      { valor: 'S', texto: 'Síntese da célula', selo: driver ? driver.nome : 'Síntese' },
      ...this.dados.eixos.map((x) => ({ valor: String(x.id), texto: `Eixo · ${x.nome}` })),
    ];
    const paraAlvos = (marcados) =>
      (marcados || []).map((v) => (v === 'S' ? null : Number(v)));

    if (this.quiz?.sessao) {
      Modal.abrir({
        titulo: 'Perguntar à sala',
        url: '/api/quiz/perguntar',
        valores: {
          planejamento_id: this.plan.id, alvo_tipo: 'CASCATA',
          horizonte_id: horizonteId, driver_id: driverId, alvos: ['S'], acao: 'AGORA',
        },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'alvo_tipo', rotulo: '', tipo: 'hidden' },
          { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
          { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
          { nome: 'alvos', rotulo: 'O que a sala responde nesta célula?', tipo: 'lista_marcavel',
            opcoes: opcoesAlvo, obrigatorio: true,
            ajuda: 'Marque um ou vários: cada um vira uma pergunta do roteiro.' },
          { nome: 'acao', rotulo: 'Quando?', tipo: 'botoes', opcoes: [
            { valor: 'ROTEIRO', rotulo: 'Só adicionar ao roteiro' },
            { valor: 'AGORA', rotulo: 'Abrir a primeira agora' },
          ], ajuda: 'Abrir agora muda o celular de todo mundo; o roteiro guarda para depois.' },
        ],
        transformar: (d) => {
          const { alvos, acao, ...resto } = d;
          return { ...resto, alvos: paraAlvos(alvos), ativar: acao !== 'ROTEIRO' };
        },
        aoSalvar: () => {
          this.perguntaFoco = null;
          this.quizUi.roteiroAberto = true;
          App.recarregarSecaoAtiva();
        },
      });
      return;
    }
    Modal.abrir({
      titulo: 'Abrir sessão colaborativa',
      url: '/api/quiz/abrir',
      valores: {
        planejamento_id: this.plan.id, alvo_tipo: 'CASCATA',
        horizonte_id: horizonteId, driver_id: driverId, alvos: ['S'], tema: '', max_ideias: 5,
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'alvo_tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
        { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
        { nome: 'alvos', rotulo: 'Primeiras perguntas (desta célula)', tipo: 'lista_marcavel',
          opcoes: opcoesAlvo, obrigatorio: true,
          ajuda: 'A sala entra pelo PIN (como na tempestade) e responde uma pergunta por vez; '
            + 'a primeira marcada já abre para a sala, as demais ficam no roteiro.' },
        { nome: 'tema', rotulo: 'Nome do encontro',
          exemplo: 'Ex.: Oficina da cascata — diretoria, agosto/2026' },
        { nome: 'max_ideias', rotulo: 'Sugestões por pessoa (em cada lado)', tipo: 'number', padrao: 5,
          ajuda: 'Vale por pergunta: N escolhas e N renúncias para cada participante.' },
      ],
      transformar: (d) => {
        const { alvos, ...resto } = d;
        return { ...resto, alvos: paraAlvos(alvos) };
      },
      // A sala é do PROJETO: se ela já está aberta em outra análise, o servidor
      // devolve 409/SALA_ABERTA e o QuizSala pergunta se encerra aquela
      enviar: (corpo) => QuizSala.pedir('/api/quiz/abrir', corpo),
      aoSalvar: () => {
        this.perguntaFoco = null;
        App.recarregarSecaoAtiva();
      },
    });
  },

  /**
   * Modal da célula. Com uma sugestão em mãos ("Usar"), o texto entra no campo
   * do MESMO lado — perguntando antes de substituir o que já está escrito — e
   * a voz entra no conjunto de vínculos. Vincular e redigir são operações
   * separadas: o texto oferecido é matéria-prima, a redação final é do
   * condutor.
   */
  async abrirModalCelula(eixoId, sugestao = null) {
    const { driverId, horizonteId } = this.celulaAberta;
    const { horizontes, drivers, escolhas } = this.dados;
    const driver = drivers.find((d) => d.id == driverId);
    const horizonte = horizontes.find((h) => h.id == horizonteId);
    const registro = escolhas.find((e) =>
      e.driver_id == driverId && e.horizonte_id == horizonteId &&
      (eixoId ? e.eixo_id == eixoId : !e.eixo_id));

    let escolhaValor = registro?.escolha || '';
    let renunciaValor = registro?.renuncia || '';
    const sugestoesIds = (registro?.sugestoes || []).map((s) => s.id);
    if (sugestao) {
      const lado = sugestao.tipo_resposta === 'RENUNCIA' ? 'renúncia' : 'escolha';
      const atual = sugestao.tipo_resposta === 'RENUNCIA' ? renunciaValor : escolhaValor;
      let usarTexto = true;
      if (atual.trim() && atual.trim() !== sugestao.texto.trim()) {
        usarTexto = confirm(`A célula já tem uma ${lado}:\n\n“${atual}”\n\nSubstituir pelo texto da sugestão? `
          + '(Cancelar mantém o texto atual; a voz entra na célula do mesmo jeito.)');
      }
      if (usarTexto) {
        if (sugestao.tipo_resposta === 'RENUNCIA') renunciaValor = sugestao.texto;
        else escolhaValor = sugestao.texto;
      }
      if (!sugestoesIds.includes(sugestao.id)) sugestoesIds.push(sugestao.id);
    }

    // Fatores da SWOT ordenados por score GUT para o vínculo. A descrição vai
    // inteira: quem amarra a evidência à decisão precisa ler o fator todo,
    // não um resumo cortado no meio.
    const swot = await App.api(`/api/fatores?planejamento_id=${this.plan.id}&etapa=SWOT`);
    const opcoesFatores = swot
      .sort((a, c) => (c.score || 0) - (a.score || 0))
      .map((f) => ({
        valor: f.id,
        texto: f.descricao,
        selo: Diag.QUADRANTES[f.categoria] || f.categoria,
        selo2: f.score ? `GUT ${f.score}` : null,
        cor: Diag.CORES_QUADRANTE[f.categoria] || '#007a45',
      }));
    const eixoNome = eixoId ? this.dados.eixos.find((x) => x.id == eixoId).nome : null;
    Modal.abrir({
      titulo: `${driver.nome} × ${horizonte.nome}${eixoNome ? ` · Eixo ${eixoNome}` : ' · Síntese'}`,
      url: '/api/cascata',
      valores: {
        planejamento_id: this.plan.id,
        horizonte_id: horizonteId,
        driver_id: driverId,
        eixo_id: eixoId ?? '',
        escolha: escolhaValor,
        renuncia: renunciaValor,
        fatores: (registro?.fatores || []).map((f) => f.id),
      },
      // O conjunto de vozes vai pelo transformar: num campo hidden o array
      // viraria a string "1,2" e o servidor amarraria vínculo nenhum
      transformar: (d) => ({ ...d, sugestoes: sugestoesIds }),
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
        { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
        { nome: 'eixo_id', rotulo: '', tipo: 'hidden' },
        ...(sugestao ? [{
          nome: 'voz', rotulo: 'Sugestão da sala', tipo: 'info', texto: sugestao.texto,
          barra: { cor: sugestao.tipo_resposta === 'RENUNCIA' ? '#8f3b3b' : '#007a45',
                   titulo: `${sugestao.autor} · ${sugestao.tipo_resposta === 'RENUNCIA' ? 'Renúncia' : 'Escolha'}` },
        }] : []),
        { nome: 'escolha', rotulo: 'Escolha (o que decidimos)', tipo: 'textarea', linhas: 3 },
        { nome: 'renuncia', rotulo: 'Renúncia (do que abrimos mão)', tipo: 'textarea', linhas: 2 },
        ...(opcoesFatores.length ? [{
          nome: 'fatores', rotulo: 'Fatores que fundamentam (SWOT/GUT)',
          tipo: 'lista_marcavel', opcoes: opcoesFatores,
          ajuda: 'Marque as evidências do diagnóstico que sustentam esta decisão. '
            + 'A lista vem ordenada pelo score da matriz GUT, do mais crítico ao menos.',
        }] : []),
      ],
      aoSalvar: () => App.recarregarSecaoAtiva(),
    });
  },
};

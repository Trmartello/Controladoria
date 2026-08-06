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
  // QR na caixa de expansão: sem guardar o estado, cada batida do polling
  // reconstruía a faixa e RECOLHIA o QR projetado no telão
  qrAbertoQuiz: false,
  roteiroAberto: false,
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
    this.plan = await App.planejamento();
    [this.dados, this.quiz] = await Promise.all([
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      App.api(`/api/cascata/quiz?planejamento_id=${this.plan.id}`
        + (this.perguntaFoco ? `&pergunta_id=${this.perguntaFoco}` : ''))
        .catch(() => ({ sessao: null })),
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
      <div id="faixa-quiz">${this.faixaSessao()}</div>
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

    this.ligarFaixaSessao(el);
    // Sessão aberta: sem célula escolhida ainda, abre a da pergunta ativa —
    // quem entra na seção durante o encontro cai direto no que a sala vê
    if (!this.celulaAberta && this.quiz?.pergunta) {
      this.celulaAberta = {
        driverId: Number(this.quiz.pergunta.driver_id),
        horizonteId: Number(this.quiz.pergunta.horizonte_id),
      };
      const td = el.querySelector(`.celula-cascata[data-driver="${this.celulaAberta.driverId}"][data-horizonte="${this.celulaAberta.horizonteId}"]`);
      if (td) td.classList.add('ativa');
    }
    if (this.celulaAberta) this.renderDetalhe();
    this.armarRelogioQuiz();
  },

  // ---- Sessão do quiz: faixa no topo (PIN, participantes, pergunta ativa) ----
  /** Rótulo curto de uma pergunta do roteiro. */
  rotuloPergunta(p) {
    return `${p.driver}${p.eixo ? ` · ${p.eixo}` : ' · Síntese'} (${p.horizonte})`;
  },

  faixaSessao() {
    const q = this.quiz;
    if (!q?.sessao) return '';
    const p = q.pergunta;
    const roteiro = q.roteiro || [];
    const prog = q.progresso || { atual: null, total: roteiro.length };
    // A próxima pendente na ordem — o botão "Próxima" abre essa
    const proxima = roteiro.find((x) => x.situacao === 'PENDENTE');
    const podeConduzir = App.podeEditar();

    const linhaRoteiro = (x, i) => {
      const selo = x.situacao === 'ATIVA'
        ? '<span class="badge text-bg-success">na sala</span>'
        : x.situacao === 'ENCERRADA'
          ? '<span class="badge text-bg-secondary">encerrada</span>'
          : '<span class="badge text-bg-light border">pendente</span>';
      const foco = this.perguntaFoco === x.id ? ' em-foco' : '';
      return `<li class="linha-roteiro${foco}" data-pergunta="${x.id}">
        <span class="small num-roteiro">${i + 1}.</span>
        <span class="small flex-grow-1">${Modal.esc(this.rotuloPergunta(x))}
          ${Number(x.sugestoes) ? `<span class="text-muted">· ${x.sugestoes} sugestão(ões)</span>` : ''}</span>
        ${selo}
        <button class="btn btn-sm btn-outline-secondary" data-ver-pergunta="${x.id}"
          title="Examinar as sugestões sem mexer na sala">Ver</button>
        ${podeConduzir && x.situacao !== 'ATIVA' ? `<button class="btn btn-sm btn-verde"
          data-ativar-pergunta="${x.id}">${x.situacao === 'ENCERRADA' ? 'Reabrir' : 'Abrir para a sala'}</button>` : ''}
        ${podeConduzir && x.situacao === 'ATIVA' ? `<button class="btn btn-sm btn-outline-secondary"
          data-encerrar-pergunta="${x.id}" title="Fechar sem abrir outra">Encerrar</button>` : ''}
        ${podeConduzir && x.situacao === 'PENDENTE' && !Number(x.sugestoes) ? `<button
          class="btn btn-sm btn-outline-danger" data-remover-pergunta="${x.id}"
          title="Tirar do roteiro" aria-label="Tirar do roteiro">×</button>` : ''}
      </li>`;
    };

    return `<div class="card mb-3 painel-rodada"><div class="card-body py-2 px-3">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        ${q.sessao.pin ? `<span class="badge text-bg-light border">PIN <strong class="pin-mini">${Modal.esc(q.sessao.pin)}</strong></span>` : ''}
        <span class="badge text-bg-light border" data-quiz-participantes>${q.sessao.participantes} participante(s)</span>
        ${prog.total ? `<span class="badge text-bg-light border">${prog.atual
          ? `Pergunta ${prog.atual} de ${prog.total}` : `${prog.total} no roteiro`}</span>` : ''}
        ${p ? `<span class="badge badge-horizonte">Perguntando: ${Modal.esc(this.rotuloPergunta(p))}</span>`
            : '<span class="badge text-bg-secondary">nenhuma pergunta ativa</span>'}
        <span class="small text-muted flex-grow-1 text-truncate">${Modal.esc(q.sessao.tema)}</span>
        ${podeConduzir && proxima ? `<button class="btn btn-sm btn-verde" id="btn-proxima-pergunta"
          title="${Modal.esc(this.rotuloPergunta(proxima))}">Próxima pergunta →</button>` : ''}
        ${podeConduzir ? `<button class="btn btn-sm btn-outline-danger" id="btn-encerrar-quiz">Encerrar sessão</button>` : ''}
      </div>
      ${roteiro.length ? `<details class="mt-2" id="det-roteiro"${this.roteiroAberto ? ' open' : ''}>
        <summary class="small">Roteiro do encontro (${roteiro.length} pergunta(s))</summary>
        <ol class="lista-roteiro mt-2">${roteiro.map(linhaRoteiro).join('')}</ol>
      </details>` : ''}
      ${q.sessao.pin ? `<details class="painel-qr mt-2" id="det-qr-quiz"${this.qrAbertoQuiz ? ' open' : ''}>
        <summary>QR code para projetar</summary>
        <div class="d-flex flex-wrap gap-3 align-items-start mt-2">
          <div class="caixa-qr" id="qr-quiz" aria-hidden="true"></div>
          <div class="flex-grow-1" style="min-width:12rem">
            <div class="rotulo-secao">Entre em ${Modal.esc(location.host)}/entrar</div>
            <div class="pin-grande">${Modal.esc(q.sessao.pin)}</div>
          </div>
        </div>
      </details>` : ''}
    </div></div>`;
  },

  ligarFaixaSessao(el) {
    const det = el.querySelector('#det-qr-quiz');
    if (det) det.addEventListener('toggle', () => { this.qrAbertoQuiz = det.open; });
    const detRot = el.querySelector('#det-roteiro');
    if (detRot) detRot.addEventListener('toggle', () => { this.roteiroAberto = detRot.open; });

    // Navegar: examina a pergunta SEM mexer na sala — abre a célula dela no
    // detalhe e traz as sugestões que ela já recebeu
    el.querySelectorAll('[data-ver-pergunta]').forEach((b) => b.addEventListener('click', async () => {
      const id = Number(b.dataset.verPergunta);
      const pergunta = (this.quiz?.roteiro || []).find((x) => x.id === id);
      if (!pergunta) return;
      this.perguntaFoco = id;
      this.celulaAberta = {
        driverId: Number(pergunta.driver_id),
        horizonteId: Number(pergunta.horizonte_id),
      };
      this.roteiroAberto = true;
      await this.carregar();
      document.getElementById('detalhe-celula')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));

    const conduzir = async (url) => {
      try {
        await App.api(url, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.roteiroAberto = true;
      App.recarregarSecaoAtiva();
    };
    el.querySelectorAll('[data-ativar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      // Abrir/reabrir MEXE na sala: o celular de todo mundo muda junto
      this.perguntaFoco = null;
      conduzir(`/api/cascata/quiz/pergunta/${b.dataset.ativarPergunta}/ativar`);
    }));
    el.querySelectorAll('[data-encerrar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Fechar esta pergunta? A sala vê "aguarde a próxima"; as sugestões ficam guardadas.')) return;
      conduzir(`/api/cascata/quiz/pergunta/${b.dataset.encerrarPergunta}/encerrar`);
    }));
    el.querySelectorAll('[data-remover-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Tirar esta pergunta do roteiro?')) return;
      conduzir(`/api/cascata/quiz/pergunta/${b.dataset.removerPergunta}/remover`);
    }));
    const btnProxima = el.querySelector('#btn-proxima-pergunta');
    if (btnProxima) {
      btnProxima.addEventListener('click', () => {
        const proxima = (this.quiz?.roteiro || []).find((x) => x.situacao === 'PENDENTE');
        if (!proxima) return;
        this.perguntaFoco = null;
        this.celulaAberta = {
          driverId: Number(proxima.driver_id),
          horizonteId: Number(proxima.horizonte_id),
        };
        conduzir(`/api/cascata/quiz/pergunta/${proxima.id}/ativar`);
      });
    }

    const btn = el.querySelector('#btn-encerrar-quiz');
    if (btn) {
      btn.addEventListener('click', async () => {
        if (!confirm('Encerrar a sessão? Os celulares deixam de receber perguntas; as sugestões ficam guardadas.')) return;
        try {
          await App.api('/api/cascata/quiz/encerrar', { planejamento_id: this.plan.id });
        } catch (e) {
          alert(e.message);
        }
        App.recarregarSecaoAtiva();
      });
    }
    const caixa = el.querySelector('#qr-quiz');
    if (caixa && this.quiz?.sessao && typeof qrcode === 'function') {
      try {
        const q = qrcode(0, 'M');
        q.addData(`${location.origin}/entrar/${this.quiz.sessao.pin}`);
        q.make();
        caixa.innerHTML = q.createSvgTag({ cellSize: 4, margin: 1, scalable: true });
      } catch (e) {
        caixa.remove();
      }
    } else if (caixa) {
      caixa.remove();
    }
  },

  /**
   * Consulta periódica da sessão, nos moldes da tempestade: 4s, e NUNCA
   * redesenha com um modal aberto ou um campo em foco — fecharia o modal do
   * condutor no meio da redação. Para sozinha quando a seção sai de cena (as
   * seções não são destruídas, só ganham d-none — o relógio sobreviveria).
   */
  armarRelogioQuiz() {
    clearInterval(this.relogioQuiz);
    this.relogioQuiz = null;
    if (!this.quiz?.sessao) return;
    this.relogioQuiz = setInterval(async () => {
      const el = document.getElementById('secao-cascata');
      if (!el || el.classList.contains('d-none')) {
        clearInterval(this.relogioQuiz);
        this.relogioQuiz = null;
        return;
      }
      if (document.querySelector('.modal.show')) return;
      const ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT')) return;
      // Captura o foco DO DISPARO: se o condutor navegar pelo roteiro com esta
      // resposta em voo, ela chega falando de outra pergunta — aplicá-la
      // apagaria o painel que ele acabou de abrir (ou deixaria o "Usar" mudo,
      // procurando ids que não estão mais em this.quiz)
      const focoPedido = this.perguntaFoco;
      let quizNovo;
      try {
        quizNovo = await App.api(`/api/cascata/quiz?planejamento_id=${this.plan.id}`
          + (focoPedido ? `&pergunta_id=${focoPedido}` : ''));
      } catch (e) {
        return; // rede piscou; a próxima batida tenta de novo
      }
      if (focoPedido !== this.perguntaFoco) return; // resposta de outra navegação
      this.quiz = quizNovo;
      const assinatura = JSON.stringify([
        this.quiz.sessao?.participantes, this.quiz.pergunta?.id, this.quiz.foco?.id,
        (this.quiz.roteiro || []).map((x) => [x.id, x.situacao, x.sugestoes]),
        (this.quiz.sugestoes || []).map((s) => [s.id, s.texto, s.votos, s.vinculada]),
        this.quiz.celula?.escolha, this.quiz.celula?.renuncia,
      ]);
      if (assinatura === this.assinaturaQuiz) return;
      this.assinaturaQuiz = assinatura;
      // A célula e as vozes do detalhe saem de this.dados: sem rebuscar aqui,
      // o renderDetalhe repintava texto e vínculos VELHOS — e um modal aberto
      // desse estado reenviaria o conjunto antigo, desfazendo o recém-salvo
      try {
        this.dados = await App.api(`/api/cascata?planejamento_id=${this.plan.id}`);
      } catch (e) {
        return;
      }
      const faixa = document.getElementById('faixa-quiz');
      if (faixa) {
        faixa.innerHTML = this.faixaSessao();
        this.ligarFaixaSessao(el);
      }
      if (!this.quiz.sessao) {
        clearInterval(this.relogioQuiz);
        this.relogioQuiz = null;
      }
      if (this.celulaAberta) this.renderDetalhe();
    }, 4000);
  },

  /**
   * A pergunta em FOCO (a examinada pelo roteiro; por padrão, a ativa),
   * quando ela pertence à célula aberta no detalhe.
   */
  perguntaDaCelulaAberta() {
    const p = this.quiz?.foco || this.quiz?.pergunta;
    if (!p || !this.celulaAberta) return null;
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
            ${podePerguntar ? `<button class="btn btn-sm ${perguntaAqui ? 'btn-outline-success' : 'btn-verde'}"
              id="btn-perguntar-sala">
              ${perguntaAqui ? 'Perguntar outra parte à sala'
                : this.quiz?.sessao ? 'Perguntar à sala' : 'Perguntar à sala (abrir sessão)'}</button>` : ''}
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
    const alvoEixo = p.eixo ? `Eixo ${p.eixo}` : 'Síntese';
    const coluna = (tipo, rotulo, classe) => {
      const fichas = sugestoes.filter((s) => s.tipo_resposta === tipo);
      const linhas = fichas.map((s) => `
        <div class="ficha-sugestao ${Number(s.vinculada) ? 'vinculada' : ''}">
          <div class="small">${Modal.esc(s.texto)}</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span class="small text-muted flex-grow-1">${Modal.esc(s.autor)}${Number(s.votos) ? ` · ★ ${s.votos}` : ''}
              ${Number(s.vinculada) ? ' · <strong>na célula</strong>' : ''}</span>
            ${App.podeEditar() && !Number(s.vinculada) ? `
              <button class="btn btn-sm btn-verde" data-usar-sugestao="${s.id}">Usar</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir-sugestao="${s.id}"
                title="Excluir sugestão" aria-label="Excluir sugestão">×</button>` : ''}
          </div>
        </div>`).join('');
      return `<div class="col-md-6"><div class="coluna-quiz ${classe}">
        <div class="fw-bold small text-uppercase mb-2">${rotulo}
          <span class="badge rounded-pill text-bg-secondary">${fichas.length}</span></div>
        ${linhas || '<div class="text-muted small">Nenhuma sugestão ainda.</div>'}
      </div></div>`;
    };
    // O foco pode ser uma pergunta encerrada (navegação pelo roteiro) ou
    // pendente: o painel mostra o que já foi coletado e oferece abrir/reabrir
    const situacao = p.situacao === 'ATIVA'
      ? '<span class="badge text-bg-success">na sala agora</span>'
      : p.situacao === 'ENCERRADA'
        ? '<span class="badge text-bg-secondary">pergunta encerrada</span>'
        : '<span class="badge text-bg-light border">ainda não aberta</span>';
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
        <strong class="small text-uppercase">Sugestões da sala — ${Modal.esc(alvoEixo)}</strong>
        ${situacao}
        <span class="small text-muted flex-grow-1">Use uma sugestão para levá-la ao campo da célula;
          as vozes ficam registradas embaixo do texto.</span>
        ${App.podeEditar() && p.situacao !== 'ATIVA' ? `<button class="btn btn-sm btn-verde"
          data-reabrir-foco="${p.id}">${p.situacao === 'ENCERRADA'
            ? 'Reabrir para a sala' : 'Abrir para a sala'}</button>` : ''}
      </div>
      <div class="row g-2">
        ${coluna('ESCOLHA', 'Respostas (escolha)', 'coluna-escolha')}
        ${coluna('RENUNCIA', 'Renúncias', 'coluna-renuncia')}
      </div>
    </div></div>`;
  },

  ligarPainelVivo(alvo) {
    alvo.querySelectorAll('[data-reabrir-foco]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/cascata/quiz/pergunta/${b.dataset.reabrirFoco}/ativar`, {
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
        await App.api(`/api/cascata/quiz/sugestao/${b.dataset.excluirSugestao}/excluir`, {
          planejamento_id: this.plan.id,
        });
        this.quiz = await App.api(`/api/cascata/quiz?planejamento_id=${this.plan.id}`);
        this.renderDetalhe();
      } catch (e) {
        alert(e.message);
      }
    }));
  },

  /**
   * "Perguntar à sala": com sessão aberta, só ativa a pergunta desta célula
   * (síntese; as aberturas entram pelo mesmo botão quando a célula do eixo
   * estiver em foco — Fase 1 pergunta a célula driver × horizonte na síntese).
   * Sem sessão, abre uma (tema + tetos) e já ativa a pergunta.
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
        url: '/api/cascata/quiz/perguntar',
        valores: {
          planejamento_id: this.plan.id, horizonte_id: horizonteId, driver_id: driverId,
          alvos: ['S'], acao: 'AGORA',
        },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
          { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
          { nome: 'alvos', rotulo: 'O que a sala responde nesta célula?', tipo: 'lista_marcavel',
            opcoes: opcoesAlvo, obrigatorio: true,
            ajuda: 'Marque um ou vários: cada um vira uma pergunta do roteiro.' },
          { nome: 'acao', rotulo: 'Quando?', tipo: 'botoes', opcoes: [
            { valor: 'AGORA', rotulo: 'Abrir a primeira agora' },
            { valor: 'ROTEIRO', rotulo: 'Só adicionar ao roteiro' },
          ], ajuda: 'Abrir agora muda o celular de todo mundo; o roteiro guarda para depois.' },
        ],
        transformar: (d) => {
          const { alvos, acao, ...resto } = d;
          return { ...resto, alvos: paraAlvos(alvos), ativar: acao !== 'ROTEIRO' };
        },
        aoSalvar: () => {
          this.perguntaFoco = null;
          this.roteiroAberto = true;
          App.recarregarSecaoAtiva();
        },
      });
      return;
    }
    Modal.abrir({
      titulo: 'Abrir sessão colaborativa',
      url: '/api/cascata/quiz/abrir',
      valores: {
        planejamento_id: this.plan.id, horizonte_id: horizonteId, driver_id: driverId,
        alvos: ['S'], tema: '', max_ideias: 5,
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
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

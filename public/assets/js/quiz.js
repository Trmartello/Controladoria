// A sala do PROJETO: um PIN para todas as análises.
//
// A faixa da sessão (PIN, QR, participantes, pergunta ativa, roteiro) é a
// MESMA em toda tela que conduz um quiz — Cenário, PESTEL, Porter, SWOT,
// Cascata. Escrita em cada seção, ela divergiria na primeira mudança de regra,
// como já aconteceu com `panorama()` e `camposAcao` no plano de ação.
//
// O componente não guarda estado: quem guarda é o DONO (a seção), num objeto
// com `plan`, `quiz`, `perguntaFoco` e `quizUi`. Duas seções compartilhando o
// "QR recolhido" recolheriam o QR uma da outra no meio do encontro.

const QuizSala = {
  /**
   * Estado ao vivo da sala. Devolve `{sessao: null}` em qualquer falha: a
   * faixa some, mas a tela continua funcionando sem o quiz.
   */
  async estado(planId, perguntaFoco) {
    return App.api(`/api/quiz?planejamento_id=${planId}`
      + (perguntaFoco ? `&pergunta_id=${perguntaFoco}` : ''))
      .catch(() => ({ sessao: null }));
  },

  /**
   * Abre a sala (ou acrescenta perguntas) tratando a COLISÃO: com a sala
   * aberta em outra análise o servidor responde 409/SALA_ABERTA com o nome da
   * tela, e aqui isso vira uma pergunta — confirmando, o mesmo pedido volta com
   * `confirmar_encerrar` e o servidor encerra a anterior e abre a nova de uma
   * vez só. Dois pedidos deixariam uma janela sem sala nenhuma.
   */
  async pedir(url, corpo) {
    try {
      return await App.api(url, corpo);
    } catch (e) {
      if (e.codigo !== 'SALA_ABERTA' || !confirm(e.message)) throw e;
      return App.api(url, { ...corpo, confirmar_encerrar: 1 });
    }
  },

  /** Rótulo curto da pergunta — o servidor já o calcula (App\Services\Quiz). */
  rotulo(p) {
    return p?.rotulo || '—';
  },

  // ---- A faixa ----

  /**
   * `dono` precisa de: quiz (estado), perguntaFoco, quizUi {qrAberto,
   * roteiroAberto}. Devolve '' quando não há sessão aberta.
   */
  faixa(dono) {
    const q = dono.quiz;
    if (!q?.sessao) return '';
    const ui = dono.quizUi || (dono.quizUi = {});
    const p = q.pergunta;
    const roteiro = q.roteiro || [];
    const prog = q.progresso || { atual: null, total: roteiro.length };
    const proxima = roteiro.find((x) => x.situacao === 'PENDENTE');
    const podeConduzir = App.podeEditar();

    const linhaRoteiro = (x, i) => {
      const selo = x.situacao === 'ATIVA'
        ? '<span class="badge text-bg-success">na sala</span>'
        : x.situacao === 'ENCERRADA'
          ? '<span class="badge text-bg-secondary">encerrada</span>'
          : '<span class="badge text-bg-light border">pendente</span>';
      const foco = dono.perguntaFoco === x.id ? ' em-foco' : '';
      return `<li class="linha-roteiro${foco}" data-pergunta="${x.id}">
        <span class="small num-roteiro">${i + 1}.</span>
        <span class="small flex-grow-1">${Modal.esc(this.rotulo(x))}
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
        ${p ? `<span class="badge badge-horizonte">Perguntando: ${Modal.esc(this.rotulo(p))}</span>`
            : '<span class="badge text-bg-secondary">nenhuma pergunta ativa</span>'}
        <span class="small text-muted flex-grow-1 text-truncate">${Modal.esc(q.sessao.tema)}</span>
        ${podeConduzir && proxima ? `<button class="btn btn-sm btn-verde" data-quiz-proxima
          title="${Modal.esc(this.rotulo(proxima))}">Próxima pergunta →</button>` : ''}
        ${podeConduzir ? '<button class="btn btn-sm btn-outline-danger" data-quiz-encerrar>Encerrar sessão</button>' : ''}
      </div>
      ${roteiro.length ? `<details class="mt-2" data-quiz-roteiro${ui.roteiroAberto ? ' open' : ''}>
        <summary class="small">Roteiro do encontro (${roteiro.length} pergunta(s))</summary>
        <ol class="lista-roteiro mt-2">${roteiro.map(linhaRoteiro).join('')}</ol>
      </details>` : ''}
      ${q.sessao.pin ? `<details class="painel-qr mt-2" data-quiz-qr${ui.qrAberto ? ' open' : ''}>
        <summary>QR code para projetar</summary>
        <div class="d-flex flex-wrap gap-3 align-items-start mt-2">
          <div class="caixa-qr" data-quiz-qrcode aria-hidden="true"></div>
          <div class="flex-grow-1" style="min-width:12rem">
            <div class="rotulo-secao">Entre em ${Modal.esc(location.host)}/entrar</div>
            <div class="pin-grande">${Modal.esc(q.sessao.pin)}</div>
          </div>
        </div>
      </details>` : ''}
    </div></div>`;
  },

  /**
   * Liga os eventos da faixa dentro de `el`. `dono.aoNavegar(pergunta)` é
   * chamado antes de recarregar quando o condutor examina outra pergunta —
   * é onde cada seção posiciona a própria tela (a célula da cascata, o ano do
   * cenário) sem que o componente precise saber o que ela mostra.
   */
  ligar(dono, el) {
    const ui = dono.quizUi || (dono.quizUi = {});
    const det = el.querySelector('[data-quiz-qr]');
    if (det) det.addEventListener('toggle', () => { ui.qrAberto = det.open; });
    const detRot = el.querySelector('[data-quiz-roteiro]');
    if (detRot) detRot.addEventListener('toggle', () => { ui.roteiroAberto = detRot.open; });

    // Navegar: examina a pergunta SEM mexer na sala
    el.querySelectorAll('[data-ver-pergunta]').forEach((b) => b.addEventListener('click', async () => {
      const id = Number(b.dataset.verPergunta);
      const pergunta = (dono.quiz?.roteiro || []).find((x) => x.id === id);
      if (!pergunta) return;
      dono.perguntaFoco = id;
      ui.roteiroAberto = true;
      if (dono.aoNavegar) dono.aoNavegar(pergunta);
      await dono.carregar();
    }));

    const conduzir = async (url) => {
      try {
        await App.api(url, { planejamento_id: dono.plan.id });
      } catch (e) {
        alert(e.message);
      }
      ui.roteiroAberto = true;
      App.recarregarSecaoAtiva();
    };
    el.querySelectorAll('[data-ativar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      // Abrir/reabrir MEXE na sala: o celular de todo mundo muda junto
      dono.perguntaFoco = null;
      conduzir(`/api/quiz/pergunta/${b.dataset.ativarPergunta}/ativar`);
    }));
    el.querySelectorAll('[data-encerrar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Fechar esta pergunta? A sala vê "aguarde a próxima"; as sugestões ficam guardadas.')) return;
      conduzir(`/api/quiz/pergunta/${b.dataset.encerrarPergunta}/encerrar`);
    }));
    el.querySelectorAll('[data-remover-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Tirar esta pergunta do roteiro?')) return;
      conduzir(`/api/quiz/pergunta/${b.dataset.removerPergunta}/remover`);
    }));

    const btnProxima = el.querySelector('[data-quiz-proxima]');
    if (btnProxima) {
      btnProxima.addEventListener('click', () => {
        const proxima = (dono.quiz?.roteiro || []).find((x) => x.situacao === 'PENDENTE');
        if (!proxima) return;
        dono.perguntaFoco = null;
        if (dono.aoNavegar) dono.aoNavegar(proxima);
        conduzir(`/api/quiz/pergunta/${proxima.id}/ativar`);
      });
    }

    const btnEncerrar = el.querySelector('[data-quiz-encerrar]');
    if (btnEncerrar) {
      btnEncerrar.addEventListener('click', async () => {
        if (!confirm('Encerrar a sessão? Os celulares deixam de receber perguntas; as sugestões ficam guardadas.')) return;
        try {
          await App.api('/api/quiz/encerrar', { planejamento_id: dono.plan.id });
        } catch (e) {
          alert(e.message);
        }
        App.recarregarSecaoAtiva();
      });
    }

    const caixa = el.querySelector('[data-quiz-qrcode]');
    if (caixa && dono.quiz?.sessao?.pin && typeof qrcode === 'function') {
      try {
        const q = qrcode(0, 'M');
        q.addData(`${location.origin}/entrar/${dono.quiz.sessao.pin}`);
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
   *
   * `dono.secaoId` é o id do elemento da seção; `dono.aoBater(quizNovo)` recebe
   * o estado novo e decide o que repintar.
   */
  armarRelogio(dono) {
    clearInterval(dono.relogioQuiz);
    dono.relogioQuiz = null;
    if (!dono.quiz?.sessao) return;
    dono.relogioQuiz = setInterval(async () => {
      const el = document.getElementById(dono.secaoId);
      if (!el || el.classList.contains('d-none')) {
        clearInterval(dono.relogioQuiz);
        dono.relogioQuiz = null;
        return;
      }
      if (document.querySelector('.modal.show')) return;
      const ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT')) return;
      // Captura o foco DO DISPARO: se o condutor navegar pelo roteiro com esta
      // resposta em voo, ela chega falando de outra pergunta — aplicá-la
      // apagaria o painel que ele acabou de abrir.
      const focoPedido = dono.perguntaFoco;
      let quizNovo;
      try {
        quizNovo = await App.api(`/api/quiz?planejamento_id=${dono.plan.id}`
          + (focoPedido ? `&pergunta_id=${focoPedido}` : ''));
      } catch (e) {
        return; // rede piscou; a próxima batida tenta de novo
      }
      if (focoPedido !== dono.perguntaFoco) return;
      dono.quiz = quizNovo;
      dono.aoBater(quizNovo);
    }, 4000);
  },

  /**
   * Assinatura do estado, para o polling só repintar quando algo mudou.
   * Repintar à toa custa o foco e, no celular, o teclado aberto.
   */
  assinatura(q) {
    if (!q?.sessao) return 'sem-sessao';
    return JSON.stringify([
      q.sessao.participantes,
      q.pergunta?.id || 0,
      q.foco?.id || 0,
      (q.roteiro || []).map((x) => [x.id, x.situacao, x.sugestoes]),
      (q.sugestoes || []).map((s) => [s.id, s.texto, s.votos, s.vinculada, s.tipo_resposta]),
    ]);
  },
};

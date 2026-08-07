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

  // ---- Peças ----

  /**
   * O roteiro do encontro, com os botões de condução. Mora aqui porque a aba
   * Sala e (por enquanto) a faixa mostram a MESMA lista — duas cópias
   * divergiriam no primeiro estado novo de pergunta.
   */
  roteiro(dono) {
    const lista = dono.quiz?.roteiro || [];
    if (!lista.length) return '';
    const podeConduzir = App.podeEditar();
    const linha = (x, i) => {
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
          data-secao-pergunta="${Modal.esc(x.secao || '')}"
          title="Examinar as sugestões na tela de origem">Ver</button>
        ${podeConduzir && x.situacao !== 'ATIVA' ? `<button class="btn btn-sm btn-verde"
          data-ativar-pergunta="${x.id}">${x.situacao === 'ENCERRADA' ? 'Reabrir' : 'Abrir para a sala'}</button>` : ''}
        ${podeConduzir && x.situacao === 'ATIVA' ? `<button class="btn btn-sm btn-outline-secondary"
          data-encerrar-pergunta="${x.id}" title="Fechar sem abrir outra">Encerrar</button>` : ''}
        ${podeConduzir && x.situacao === 'PENDENTE' && !Number(x.sugestoes) ? `<button
          class="btn btn-sm btn-outline-danger" data-remover-pergunta="${x.id}"
          title="Tirar do roteiro" aria-label="Tirar do roteiro">×</button>` : ''}
      </li>`;
    };
    return `<ol class="lista-roteiro">${lista.map(linha).join('')}</ol>`;
  },

  /**
   * O SELO de uma análise: uma linha dizendo onde a sala está. Substitui a
   * faixa inteira nas telas de análise (PIN, QR e roteiro moram na aba Sala).
   *
   * Ele fala mesmo quando a sala está LONGE ("a sala está em Porter ·
   * Rivalidade"), com atalho para lá: saber que a sala está em outro lugar sem
   * poder ir até ela é meia informação — e o silêncio seria lido como "não tem
   * sala aberta", que é justamente quando alguém abre uma segunda.
   */
  selo(dono, secaoDaTela) {
    const q = dono.quiz;
    if (!q?.sessao) {
      return '<span class="selo-sala vazio">sala fechada</span>';
    }
    const p = q.pergunta;
    const gente = `<span class="small text-muted">${q.sessao.participantes} na sala</span>`;
    if (!p) {
      return `<span class="selo-sala aberta">sala aberta · nenhuma pergunta</span> ${gente}`;
    }
    if (p.secao === secaoDaTela) {
      return `<span class="selo-sala aqui">🎤 ${Modal.esc(this.rotulo(p))} · na sala</span> ${gente}`;
    }
    return `<span class="selo-sala longe">a sala está em ${Modal.esc(p.tela)} ·
      ${Modal.esc(this.rotulo(p))}</span>
      <button class="btn btn-sm btn-outline-secondary" data-ir-sala="${Modal.esc(p.secao)}">Ir até lá</button>
      ${gente}`;
  },

  ligarSelo(el) {
    el.querySelectorAll('[data-ir-sala]').forEach((b) => b.addEventListener('click', () =>
      App.mostrarSecao(b.dataset.irSala)));
  },

  /**
   * O 🎤 de um alvo. `ativo` desenha o SELO em vez do botão: a categoria que já
   * está na sala não é alvo de toque — tocar de novo reabriria a pergunta e
   * zeraria o cronômetro dela. É a mesma lição do quadrante da Coleta, onde o
   * realçado clicável fazia a ideia sumir da matriz sem ninguém pedir.
   */
  microfone(alvo, rotulo, { ativo = false, cor = null } = {}) {
    if (!App.podeEditar()) return '';
    if (ativo) {
      return `<span class="mic-sala ativo" title="A sala está respondendo isto">🎤</span>`;
    }
    return `<button class="btn btn-sm mic-sala" data-mic='${Modal.esc(JSON.stringify(alvo))}'
      ${cor ? `style="--cor-cat:${cor}"` : ''}
      title="Perguntar ${rotulo} à sala" aria-label="Perguntar ${rotulo} à sala">🎤</button>`;
  },

  /**
   * Liga os 🎤 de `el`. Um toque manda o alvo para a sala; sem sala aberta, o
   * servidor devolve 409/SEM_SALA e aqui vira uma pergunta antes de criar a
   * sessão.
   */
  ligarMicrofones(dono, el) {
    el.querySelectorAll('[data-mic]').forEach((b) => b.addEventListener('click', async () => {
      let alvo;
      try {
        alvo = JSON.parse(b.dataset.mic);
      } catch (e) {
        return;
      }
      b.disabled = true;
      try {
        await this.perguntar(dono, alvo);
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));
  },

  /**
   * Liga os botões do roteiro. `dono.aoNavegar(pergunta)` é chamado antes de
   * recarregar quando o condutor examina outra pergunta — é onde cada tela se
   * posiciona (a célula da cascata, o ano do cenário) sem que o componente
   * precise saber o que ela mostra. Da aba Sala, "Ver" NAVEGA até a análise de
   * origem: examinar uma pergunta é ler as sugestões, e elas moram lá.
   */
  ligarRoteiro(dono, el) {
    const ui = dono.quizUi || (dono.quizUi = {});
    el.querySelectorAll('[data-ver-pergunta]').forEach((b) => b.addEventListener('click', async () => {
      const id = Number(b.dataset.verPergunta);
      const pergunta = (dono.quiz?.roteiro || []).find((x) => x.id === id);
      if (!pergunta) return;
      const secao = b.dataset.secaoPergunta;
      if (secao && secao !== App.secaoAtiva) {
        // O foco viaja para a seção de destino: ela é que sabe desenhar as
        // sugestões desta pergunta
        this.focoPendente = { secao, perguntaId: id, pergunta };
        App.mostrarSecao(secao);
        return;
      }
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
  },

  /**
   * Foco que atravessa a navegação: o "Ver" da aba Sala manda a seção de
   * destino abrir uma pergunta específica. Quem chega consome e limpa — senão
   * o foco voltaria a se aplicar na próxima visita à seção.
   */
  focoPendente: null,

  consumirFoco(secao) {
    if (this.focoPendente?.secao !== secao) return null;
    const f = this.focoPendente;
    this.focoPendente = null;
    return f;
  },

  /** Desenha o QR de uma sessão dentro de `caixa` (some se não der). */
  desenharQr(caixa, pin) {
    if (!caixa) return;
    if (!pin || typeof qrcode !== 'function') { caixa.remove(); return; }
    try {
      const q = qrcode(0, 'M');
      q.addData(`${location.origin}/entrar/${pin}`);
      q.make();
      caixa.innerHTML = q.createSvgTag({ cellSize: 6, margin: 1, scalable: true });
    } catch (e) {
      caixa.remove();
    }
  },

  /** POST /api/quiz/tela, tratando SEM_SALA (abre) e SALA_ABERTA (encerra). */
  async perguntar(dono, alvo) {
    const corpo = { planejamento_id: dono.plan.id, ...alvo };
    try {
      return await App.api('/api/quiz/tela', corpo);
    } catch (e) {
      if (e.codigo !== 'SEM_SALA' || !confirm(e.message)) throw e;
      // Abrir a sala pede um nome; sem ele o padrão do servidor serve, e o
      // condutor renomeia na aba Sala
      const tema = prompt('Nome do encontro (opcional):', '') ?? '';
      return this.pedir('/api/quiz/tela', { ...corpo, abrir_sala: 1, tema });
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

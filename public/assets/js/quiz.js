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
  selo(dono, secaoDaTela, aqui = null) {
    const q = dono.quiz;
    if (!q?.sessao) {
      return '<span class="selo-sala vazio">sala fechada</span>';
    }
    const p = q.pergunta;
    const gente = `<span class="small text-muted">${q.sessao.participantes} na sala</span>`;
    if (!p) {
      return `<span class="selo-sala aberta">sala aberta · nenhuma pergunta</span> ${gente}`;
    }
    // "Aqui" é a seção E o contexto que a tela mostra. Comparar só a seção fazia
    // o selo dizer "na sala" com a tela em 2027 e a sala em 2026: nenhum 🎤
    // aceso, nenhum painel, e nem o "Ir até lá" — que só aparecia quando a seção
    // diferia. Quem sabe se é o mesmo contexto é a tela, então ela responde.
    const mesmaTela = aqui === null ? p.secao === secaoDaTela : !!aqui;
    if (mesmaTela) {
      return `<span class="selo-sala aqui">🎤 ${Modal.esc(this.rotulo(p))} · na sala</span> ${gente}`;
    }
    return `<span class="selo-sala longe">a sala está em ${Modal.esc(p.tela)} ·
      ${Modal.esc(this.rotulo(p))}</span>
      <button class="btn btn-sm btn-outline-secondary" data-ir-sala="${Modal.esc(p.secao)}"
        data-ano-sala="${Modal.esc(p.ano ?? '')}">Ir até lá</button>
      ${gente}`;
  },

  ligarSelo(el) {
    el.querySelectorAll('[data-ir-sala]').forEach((b) => b.addEventListener('click', () => {
      // O atalho leva ao ANO da pergunta também: as análises de diagnóstico são
      // anuais e o seletor é compartilhado, então chegar na seção certa no ano
      // errado é chegar numa tela vazia.
      const ano = Number(b.dataset.anoSala);
      if (ano && typeof Diag !== 'undefined') Diag.anoSelecionado = ano;
      if (b.dataset.irSala === App.secaoAtiva) App.recarregarSecaoAtiva();
      else App.mostrarSecao(b.dataset.irSala);
    }));
  },

  /**
   * O 🎤 de um alvo. Aceso (`ativo`), ele é o INTERRUPTOR da pergunta: tocar de
   * novo a FECHA e a sala para de receber respostas ali mesmo, sem passar pela
   * aba Sala. Reabrir é tocar mais uma vez — a pergunta volta com as vozes que
   * já tinha.
   *
   * Fechar pede confirmação (em `ligarMicrofones`) e nunca deixa de pedir: o 🎤
   * é tocado duas vezes sem querer o tempo todo, e a segunda tocada não pode
   * calar a sala no meio da oficina. Foi por isso que ele já foi selo sem
   * toque nenhum; o que mudou é que agora existe o que fazer com o segundo
   * toque, não que o toque acidental tenha deixado de acontecer.
   */
  microfone(alvo, rotulo, { ativo = false, cor = null, pergunta = null } = {}) {
    if (!App.podeEditar()) return '';
    if (ativo) {
      if (!pergunta) {
        return '<span class="mic-sala ativo" title="A sala está respondendo isto">🎤</span>';
      }
      const t = 'A sala está respondendo isto — toque para fechar e parar de receber respostas';
      return `<button class="btn btn-sm mic-sala ativo" data-mic-fechar="${Number(pergunta)}"
        title="${t}" aria-label="${t}">🎤</button>`;
    }
    // O escape é feito AQUI, não pelo chamador: o rótulo pode ser texto que o
    // usuário edita (o nome de um eixo, em Cadastros), e um contrato em que
    // "cada chamador escapa o seu" quebra no primeiro que esquecer. A cor passa
    // pela mesma validação do campo `info` do modal — atributo `style` montado
    // com string de fora é a outra metade do mesmo problema.
    const seguro = Modal.esc(rotulo);
    const corOk = /^#[0-9a-f]{6}$/i.test(cor || '') ? cor : null;
    return `<button class="btn btn-sm mic-sala" data-mic='${Modal.esc(JSON.stringify(alvo))}'
      ${corOk ? `style="--cor-cat:${corOk}"` : ''}
      title="Perguntar ${seguro} à sala" aria-label="Perguntar ${seguro} à sala">🎤</button>`;
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
        const r = await this.perguntar(dono, alvo);
        // Desistiu no confirm: não é erro, não alerta e não mexe em nada —
        // mostrar a MESMA pergunta de volta como alerta vermelho é confuso
        if (r === null) return;
        // O 🎤 torna este alvo a ATIVA, então o foco volta ao padrão. Sem isto o
        // painel ficava preso na pergunta que o condutor tinha EXAMINADO: ele
        // abria a categoria nova para a sala, os celulares respondiam, e ele
        // seguia lendo as vozes velhas — sem saída, porque `perguntaFoco` mora
        // na seção e sobrevive à navegação.
        dono.perguntaFoco = null;
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));

    // O toque no 🎤 aceso FECHA a pergunta: a sala para de receber respostas
    // sem que o condutor precise ir até a aba Sala. Encerrar não apaga nada —
    // as vozes ficam, e o mesmo 🎤 reabre a pergunta com elas.
    el.querySelectorAll('[data-mic-fechar]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Fechar esta pergunta? A sala para de receber respostas '
        + '— as sugestões já enviadas ficam, e o 🎤 reabre quando você quiser.')) return;
      b.disabled = true;
      try {
        await App.api(`/api/quiz/pergunta/${b.dataset.micFechar}/encerrar`,
          { planejamento_id: dono.plan.id });
        // A pergunta fechada continua sendo a que o condutor está lendo; o foco
        // é o que mantém o painel de vozes na tela em vez de esvaziá-lo junto
        dono.perguntaFoco = Number(b.dataset.micFechar);
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));
  },

  /**
   * As fichas de um conjunto de sugestões, em GRADE. Mora aqui porque as três
   * telas que mostram sugestões desenhavam o mesmo cartão — e escrever este
   * layout três vezes seria criar a divergência na primeira mudança.
   *
   * A grade (e não uma pilha) é o que faz caber: resposta de oficina costuma
   * ter duas ou três palavras, e uma ficha por linha, na largura da página,
   * gastava meia tela com cinco respostas.
   */
  fichas(sugestoes, { acao = 'Usar', virou = 'registro' } = {}) {
    // A voz que já virou registro SAI do painel: o lugar dela agora é o
    // quadrante de destino, e mantê-la aqui com um ✓ fazia a fila de trabalho
    // crescer com o que já foi feito. Apagado o destino, ela volta sozinha
    // (`Quiz::soltarVozes`), já com o texto que o condutor redigiu.
    const abertas = sugestoes.filter((s) => !Number(s.vinculada));
    if (!abertas.length) {
      const usadas = sugestoes.length;
      return `<div class="text-muted small">${usadas
        ? `${usadas === 1 ? 'A única sugestão já virou' : `Todas as ${usadas} sugestões já viraram`}
           ${Modal.esc(virou)}.`
        : 'Nenhuma sugestão ainda.'}</div>`;
    }
    // O 👁 nasce em toda ficha e SAI (em `ligarVozes`) da que cabe em três
    // linhas: numa resposta de duas palavras ele seria só ruído — e só depois
    // de pintar dá para saber qual é qual. Ele vem antes do `podeEditar()`
    // porque ler a resposta inteira é direito de quem só acompanha também.
    const cartao = (s) => `
      <div class="ficha-sugestao">
        <div class="texto-voz" title="${Modal.esc(s.texto)}">${Modal.esc(s.texto)}</div>
        <div class="rodape-voz">
          <span class="autor-voz" title="${Modal.esc(s.autor)}">${Modal.esc(s.autor)}${
            Number(s.votos) ? ` · ★ ${s.votos}` : ''}</span>
          <button class="btn btn-outline-secondary btn-voz" data-ver-voz aria-expanded="false"
            title="Ver a resposta inteira" aria-label="Ver a resposta inteira">👁</button>
          ${App.podeEditar() ? `
            <button class="btn btn-verde btn-voz" data-usar-sugestao="${s.id}">${Modal.esc(acao)}</button>
            <button class="btn btn-outline-danger btn-voz" data-excluir-sugestao="${s.id}"
              title="Excluir sugestão" aria-label="Excluir sugestão">×</button>` : ''}
        </div>
      </div>`;
    return `<div class="grade-sugestoes">${abertas.map(cartao).join('')}</div>`;
  },

  /**
   * O que nas fichas depende de LAYOUT, e por isso só pode ser resolvido depois
   * de pintar: qual voz não coube em três linhas (ganha o 👁) e onde a grade
   * deve parar (duas fileiras; o resto rola, ou o condutor arrasta o canto).
   *
   * A altura escolhida no arraste mora no DONO, nunca no DOM: o polling repinta
   * a seção a cada mudança de assinatura e a grade voltaria sozinha ao padrão
   * bem no meio de uma leitura. Já o 👁 aberto é DOM puro, como o "ver mais"
   * dos cartões — o repintar o recolhe, e é isso mesmo: a voz mudou.
   */
  ligarVozes(dono, el) {
    const ui = dono.quizUi || (dono.quizUi = {});
    el.querySelectorAll('.ficha-sugestao').forEach((f) => {
      const texto = f.querySelector('.texto-voz');
      const olho = f.querySelector('[data-ver-voz]');
      if (!texto || !olho) return;
      if (texto.scrollHeight <= texto.clientHeight + 1) {
        olho.remove();
        return;
      }
      olho.addEventListener('click', () => {
        const aberta = f.classList.toggle('voz-aberta');
        olho.setAttribute('aria-expanded', String(aberta));
        olho.title = aberta ? 'Recolher a resposta' : 'Ver a resposta inteira';
      });
    });
    el.querySelectorAll('.grade-sugestoes').forEach((g) => {
      const ficha = g.querySelector('.ficha-sugestao');
      if (!ficha) return;
      const vao = parseFloat(getComputedStyle(g).rowGap) || 0;
      const duasFileiras = Math.round(ficha.offsetHeight * 2 + vao);
      if (ui.alturaGrade) g.style.height = `${ui.alturaGrade}px`;
      else if (g.scrollHeight > duasFileiras + 1) g.style.height = `${duasFileiras}px`;
      // O padrão fica guardado para o `pointerup` saber distinguir o arraste do
      // canto de um clique qualquer dentro da grade — que também solta o ponteiro
      // aqui e, sem essa comparação, congelaria a altura sem ninguém ter pedido.
      g.dataset.alturaPadrao = String(parseFloat(g.style.height) || 0);
      g.addEventListener('pointerup', () => {
        const altura = parseFloat(g.style.height) || 0;
        if (!altura) return;
        if (ui.alturaGrade || Math.abs(altura - Number(g.dataset.alturaPadrao)) > 1) {
          ui.alturaGrade = Math.round(altura);
        }
      });
    });
  },

  /**
   * O cabeçalho do painel: o que a sala responde, quantas vozes chegaram, e o
   * botão de recolher. Recolher atende o caso real — numa oficina cheia o
   * painel empurra as colunas da análise para fora da tela, e às vezes o
   * condutor quer trabalhar nos cartões.
   */
  cabecalhoPainel(dono, p, sugestoes) {
    const quantas = {
      total: sugestoes.length,
      abertas: sugestoes.filter((s) => !Number(s.vinculada)).length,
    };
    const ui = dono.quizUi || (dono.quizUi = {});
    // Fechada e sendo a ÚLTIMA fechada, a sala não está parada: os celulares
    // estão pondo estrela nas respostas dela. Quem conduz precisa saber disso —
    // é o momento de ler a sala antes de abrir a próxima pergunta.
    const pontuando = p.situacao === 'ENCERRADA' && dono.quiz?.estrelas_em === p.id;
    const situacao = p.situacao === 'ATIVA'
      ? '<span class="badge text-bg-success">na sala agora</span>'
      : pontuando
        ? '<span class="badge text-bg-warning text-dark" title="A sala está marcando com '
          + 'estrela as respostas mais importantes desta pergunta">★ a sala está pontuando</span>'
        : p.situacao === 'ENCERRADA'
          ? '<span class="badge text-bg-secondary">encerrada</span>'
          : '<span class="badge text-bg-light border">não aberta</span>';
    return `<div class="d-flex align-items-center gap-2 flex-wrap cabecalho-quiz">
      <strong class="small text-uppercase">${Modal.esc(p.rotulo)}</strong>
      ${situacao}
      <span class="badge text-bg-light border" title="Vozes ainda não usadas, do total recebido">${
        quantas.abertas} de ${quantas.total} voz(es)</span>
      ${App.podeEditar() ? (p.situacao === 'ATIVA'
        // O par do "Reabrir": no MESMO lugar, o botão alterna conforme a
        // pergunta está aberta ou fechada. É a segunda forma de fechar a sala
        // (a primeira é o 🎤 aceso) e usa o mesmo `data-mic-fechar` — mesma
        // confirmação, mesmo pedido, mesma volta ao foco.
        ? `<button class="btn btn-sm btn-outline-secondary" data-mic-fechar="${p.id}"
             title="Fechar e parar de receber respostas">Fechar para a sala</button>`
        : `<button class="btn btn-sm btn-verde" data-reabrir-foco="${p.id}">${
            p.situacao === 'ENCERRADA' ? 'Reabrir para a sala' : 'Abrir para a sala'}</button>`) : ''}
      <button class="btn btn-sm btn-outline-secondary ms-auto" data-recolher-painel
        aria-expanded="${ui.painelRecolhido ? 'false' : 'true'}">${
        ui.painelRecolhido ? 'Mostrar' : 'Recolher'}</button>
    </div>`;
  },

  /** Liga o botão de recolher. O estado é do DONO, como o resto. */
  ligarRecolher(dono, el) {
    el.querySelectorAll('[data-recolher-painel]').forEach((b) => b.addEventListener('click', () => {
      const ui = dono.quizUi || (dono.quizUi = {});
      ui.painelRecolhido = !ui.painelRecolhido;
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

  /**
   * POST /api/quiz/tela, tratando SEM_SALA (abre) e SALA_ABERTA (encerra).
   * Devolve `null` quando o condutor DESISTE — desistir não é erro.
   */
  async perguntar(dono, alvo) {
    const corpo = { planejamento_id: dono.plan.id, ...alvo };
    try {
      return await App.api('/api/quiz/tela', corpo);
    } catch (e) {
      if (e.codigo !== 'SEM_SALA') throw e;
      if (!confirm(e.message)) return null;
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
      // Em que pergunta a sala está pondo estrela: fechar o 🎤 já muda a ativa,
      // mas o selo "★ a sala está pontuando" também precisa aparecer quando
      // ela SAI (a próxima pergunta abre e a fase acaba)
      q.estrelas_em || 0,
      (q.roteiro || []).map((x) => [x.id, x.situacao, x.sugestoes]),
      (q.sugestoes || []).map((s) => [s.id, s.texto, s.votos, s.vinculada, s.tipo_resposta]),
    ]);
  },
};

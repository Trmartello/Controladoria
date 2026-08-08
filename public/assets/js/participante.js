// Tela do participante da tempestade de ideias.
// Roda sem sessão: a identidade é o token devolvido ao entrar com o PIN, e
// vive só no sessionStorage desta aba. Nada aqui fala com o resto do sistema.

const Participante = {
  pin: '',
  token: '',
  nome: '',
  rodada: null,
  minhas: [],
  votacao: null,
  // Fase da estrela do quiz (a votação do rito da tempestade é `votacao`)
  estrelas: null,
  relogio: null,
  ultimaAssinatura: null,
  editando: null,
  // Quiz da cascata: o lado que a pessoa escolheu responder (sobrevive ao
  // redesenho — o par de botões é re-pintado com ele)
  // Lado escolhido pelo participante; validado contra os lados DA PERGUNTA
  // em ladoAtual() — a sala troca de análise e o lado antigo pode não existir
  tipoResposta: null,

  // Ditado por voz (Web Speech API) — o microfone só aparece se o navegador
  // suportar. A lógica é replicada do modal.js porque esta página é autônoma
  // (não carrega o resto do JS do app); as classes .campo-voz/.btn-ditar vêm
  // do app.css, que a página já carrega.
  suporteVoz: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
  reconhecimento: null,
  botaoGravando: null,
  iconeMic: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>'
    + '<path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/></svg>',

  get tela() {
    return document.getElementById('tela');
  },

  esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  async api(url, corpo = null) {
    // O token vai no cabeçalho nas leituras: na query string ele acabaria no
    // log de acesso, no log da borda e no histórico do navegador
    const r = await fetch(url, corpo
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) }
      : { headers: this.token ? { 'X-Participante': this.token } : {} });
    // Mesmo envelope do resto da API: { ok, dados } ou { ok:false, erro }
    const json = await r.json().catch(() => ({}));
    if (!r.ok || !json.ok) throw new Error(json.erro || 'Falha na comunicação.');
    return json.dados;
  },

  iniciar() {
    this.pin = this.tela.dataset.pin || '';
    // A sessão da aba guarda a entrada: recarregar a página não perde o lugar
    const guardado = sessionStorage.getItem('tempestade');
    if (guardado) {
      const g = JSON.parse(guardado);
      if (g.pin === this.pin) {
        this.token = g.token;
        this.nome = g.nome;
      }
    }
    if (this.pin && this.token) this.entrarNaSala();
    else this.telaEntrada();
  },

  // ---- Entrada ----
  telaEntrada(erro = '') {
    this.tela.innerHTML = `
      <div class="cartao-participante">
        <h1 class="h4 mb-1">Entrar na tempestade</h1>
        <p class="text-muted small mb-3">Peça o PIN a quem está conduzindo, ou escaneie o QR do telão.</p>
        ${erro ? `<div class="alert alert-danger py-2 small">${this.esc(erro)}</div>` : ''}
        <label class="form-label small" for="campo-pin">PIN da rodada</label>
        <input id="campo-pin" class="form-control form-control-lg campo-pin" inputmode="numeric"
          maxlength="6" autocomplete="off" placeholder="000000" value="${this.esc(this.pin)}">
        <label class="form-label small mt-3" for="campo-nome">Seu nome</label>
        <input id="campo-nome" class="form-control form-control-lg" maxlength="60"
          autocomplete="name" placeholder="Como você quer aparecer" value="${this.esc(this.nome)}">
        <button class="btn btn-verde btn-lg w-100 mt-3" id="btn-entrar">Entrar</button>
      </div>`;
    const entrar = async () => {
      const pin = document.getElementById('campo-pin').value.trim();
      const nome = document.getElementById('campo-nome').value.trim();
      try {
        const r = await this.api('/api/publico/entrar', { pin, nome });
        this.pin = pin;
        this.token = r.token;
        this.nome = r.nome;
        sessionStorage.setItem('tempestade', JSON.stringify({ pin, token: r.token, nome: r.nome }));
        this.entrarNaSala();
      } catch (e) {
        this.telaEntrada(e.message);
      }
    };
    document.getElementById('btn-entrar').addEventListener('click', entrar);
    document.getElementById('campo-nome').addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') entrar();
    });
  },

  pararRelogio() {
    clearInterval(this.relogio);
    this.relogio = null;
  },

  async entrarNaSala() {
    await this.atualizar();
    // A rodada pode encerrar ou abrir a votação a qualquer momento
    clearInterval(this.relogio);
    this.relogio = setInterval(() => this.atualizar(true), 4000);
  },

  /**
   * Está escrevendo? Redesenhar destrói o campo e, no celular, isso fecha o
   * teclado no meio da frase — o participante não consegue nem digitar.
   */
  digitando() {
    // Ditando ou editando? O redesenho mataria o ditado / fecharia o editor.
    if (this.botaoGravando || this.editando !== null) return true;
    const ativo = document.activeElement;
    // Rádio/checkbox focado NÃO é "digitando": tocar o par Escolha/Renúncia
    // deixa o foco no input e, sem esta exceção, congelava o polling para
    // sempre — a pergunta nunca mais mudava naquele celular
    if (ativo && (ativo.tagName === 'TEXTAREA'
      || (ativo.tagName === 'INPUT' && !['radio', 'checkbox'].includes(ativo.type)))) return true;
    const campo = document.getElementById('campo-ideia');
    return !!(campo && campo.value.trim() !== '');
  },

  /** Só redesenha quando algo mudou de verdade. */
  assinatura() {
    return JSON.stringify([
      this.rodada?.situacao, this.rodada?.tema, this.votacao?.votacao,
      // A pergunta ativa do quiz: quando a condução avança (ou reabre), o
      // cabeçalho e a lista de respostas precisam acompanhar — e o progresso
      // muda até sem trocar a ativa (o roteiro cresceu)
      this.rodada?.pergunta?.id,
      this.rodada?.progresso?.atual, this.rodada?.progresso?.total,
      this.minhas.map((i) => [i.id, i.texto, i.situacao]),
      (this.votacao?.itens || []).map((i) => [i.id, i.votei]),
      this.votacao?.meus_votos,
      // A estrela entra na assinatura: sem isso o celular só descobriria a fase
      // na próxima mudança de pergunta — que é justamente o que não vai
      // acontecer enquanto a sala vota
      this.estrelas?.fase, this.estrelas?.pergunta?.id, this.estrelas?.meus_votos,
      (this.estrelas?.itens || []).map((i) => [i.id, i.votei, i.votos]),
    ]);
  },

  async atualizar(silencioso = false) {
    try {
      this.rodada = await this.api(`/api/publico/rodada/${this.pin}`);
      if (this.rodada.situacao !== 'ABERTA') {
        this.minhas = [];
        this.votacao = null;
        this.pararRelogio();
        this.render();
        return;
      }
      this.minhas = await this.api(`/api/publico/minhas?pin=${this.pin}`);
      // Dois ritos, duas rotas: a votação é da tempestade (teto por rodada) e a
      // estrela é do quiz (teto por pergunta, e só com o 🎤 fechado)
      if (this.rodada.modo === 'QUIZ') {
        this.votacao = null;
        this.estrelas = this.rodada.pergunta
          ? null
          : await this.api(`/api/publico/estrelas?pin=${this.pin}`);
      } else {
        this.estrelas = null;
        this.votacao = await this.api(`/api/publico/votar?pin=${this.pin}`);
      }
    } catch (e) {
      if (!silencioso) this.telaEntrada(e.message);
      return;
    }
    // A consulta periódica nunca interrompe quem está escrevendo, e só toca na
    // tela quando há novidade
    if (silencioso && (this.digitando() || this.assinatura() === this.ultimaAssinatura)) return;
    this.ultimaAssinatura = this.assinatura();
    this.render();
  },

  // ---- Sala ----
  render() {
    // Um redesenho invalida o botão do mic; encerra qualquer ditado em curso.
    this.pararDitado();
    const r = this.rodada;
    if (r.modo === 'QUIZ') {
      this.renderQuiz();
      return;
    }
    // O rótulo do topo é do JS nos dois ritos: o HTML nasce genérico porque a
    // mesma página serve a tempestade e o quiz de qualquer análise
    const rotuloTopo = document.getElementById('topo-rotulo');
    if (rotuloTopo) rotuloTopo.textContent = 'Tempestade de ideias';
    const rascunho = document.getElementById('campo-ideia')?.value ?? '';
    const encerrada = r.situacao !== 'ABERTA';
    // Votação aberta SEM nada para votar não é fase de votação: é um beco. A
    // fase substitui o campo de enviar ideia, então com a lista vazia o
    // participante ficava numa tela onde não dava para escrever nem para votar
    // — e, de fora, isso parece "bloqueado". Enquanto não houver ideia na
    // lista, o celular segue recolhendo; ele passa a votar assim que a
    // primeira chegar, que é o que a condução pediu ao abrir a fase.
    const paraVotar = (this.votacao?.itens || []).length;
    const votacaoAberta = this.votacao?.votacao === 'ABERTA';
    const votando = votacaoAberta && paraVotar > 0;
    const restam = r.max_ideias - this.minhas.length;

    this.tela.innerHTML = `
      <div class="cartao-participante">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge text-bg-light border">PIN ${this.esc(this.pin)}</span>
          <span class="small text-muted flex-grow-1">${this.esc(this.nome)}</span>
        </div>
        <h1 class="h5 tema-rodada">${this.esc(r.tema || 'Tempestade de ideias')}</h1>

        ${encerrada
          ? '<div class="alert alert-secondary py-2 small mt-3">Esta rodada foi encerrada. Obrigado por participar!</div>'
          : votando ? this.blocoVotacao() : `
            ${votacaoAberta ? `<div class="alert alert-warning py-2 small mt-3">A votação já está
              aberta, mas ainda não há ideia para votar. Envie a sua.</div>` : ''}
            ${this.blocoEnvio(restam)}`}

        ${this.minhas.length && !votando ? `
          <div class="mt-4">
            <div class="rotulo-secao">Suas ideias</div>
            ${this.minhas.map((i) => this.editando === i.id
              ? this.editorIdeia(i)
              : `<div class="ideia-minha d-flex align-items-start gap-2">
                   <span class="flex-grow-1">${this.esc(i.texto)}</span>
                   ${i.situacao === 'NOVO' ? `
                     <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none flex-shrink-0"
                       data-editar="${i.id}" aria-label="Editar ideia">✎ editar</button>` : ''}
                 </div>`).join('')}
          </div>` : ''}
      </div>`;

    if (!encerrada && !votando) {
      // Nada do que foi digitado se perde num redesenho inevitável
      const campo = document.getElementById('campo-ideia');
      if (campo && rascunho) campo.value = rascunho;
      this.ligarEnvio();
    }
    if (this.minhas.length && !votando) this.ligarEdicaoIdeias();
    if (votando) this.ligarVotacao();
    this.ligarDitado();
  },

  // ---- Quiz da cascata ----
  /**
   * A sala responde UMA célula da Cascata de Escolhas por vez. O participante
   * escolhe o lado (Escolha ou Renúncia) num par de botões, escreve em até 255
   * caracteres — com contador — e pode mandar várias de cada lado, até o teto.
   * Quando a condução troca a pergunta, o cabeçalho acompanha sozinho (via
   * assinatura do polling; nunca no meio da digitação).
   */
  renderQuiz() {
    const r = this.rodada;
    // O rascunho sobrevive a QUALQUER redesenho: do campo atual ou do que foi
    // guardado quando o campo não existia (lado com o teto esgotado)
    const rascunho = this.rascunhoPendente ?? (document.getElementById('campo-ideia')?.value ?? '');
    this.rascunhoPendente = null;
    const p = r.pergunta;
    const rotulo = document.getElementById('topo-rotulo');
    // O cabeçalho acompanha a tela que a condução abriu: a sala é do projeto,
    // e o participante nunca escaneia de novo ao trocar de análise
    if (rotulo) rotulo.textContent = p ? p.rotulo : (r.tema || 'Planejamento estratégico');
    const encerrada = r.situacao !== 'ABERTA';
    const minhasDaPergunta = p ? this.minhas.filter((i) => i.pergunta_id === p.id) : [];

    this.tela.innerHTML = `
      <div class="cartao-participante">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge text-bg-light border">PIN ${this.esc(this.pin)}</span>
          <span class="small text-muted flex-grow-1">${this.esc(this.nome)}</span>
        </div>
        ${encerrada
          ? '<div class="alert alert-secondary py-2 small mt-3">Esta sessão foi encerrada. Obrigado por participar!</div>'
          : p ? this.blocoQuiz(p, minhasDaPergunta)
            : this.estrelas?.fase === 'ESTRELAS' ? this.blocoEstrelas()
            : `
            <h1 class="h5 tema-rodada">${this.esc(r.tema || 'Planejamento estratégico')}</h1>
            <div class="alert alert-info py-2 small mt-3">Aguarde: a condução vai abrir a
              próxima pergunta no telão.</div>`}
      </div>`;

    if (!encerrada && p) {
      const campo = document.getElementById('campo-ideia');
      if (campo && rascunho) campo.value = rascunho;
      // Sem campo neste lado (teto esgotado), o rascunho espera o lado voltar
      else if (!campo && rascunho) this.rascunhoPendente = rascunho;
      this.ligarQuiz(p);
      if (minhasDaPergunta.length) this.ligarEdicaoIdeias();
    }
    if (!encerrada && !p && this.estrelas?.fase === 'ESTRELAS') this.ligarEstrelas();
    this.ligarDitado();
  },

  /**
   * A fase da estrela: fechado o 🎤, o celular vota no que a sala acabou de
   * dizer. As respostas vêm da ÚLTIMA pergunta fechada, e o teto é dela — num
   * encontro de dez perguntas, um teto por rodada acabaria na segunda.
   *
   * A própria resposta da pessoa aparece na lista, como na tempestade: tirar a
   * dela obrigaria a explicar por que aquela sumiu, e ninguém gasta as três
   * estrelas em si mesmo numa sala que está olhando.
   */
  blocoEstrelas() {
    const e = this.estrelas;
    const restam = Math.max(0, (e.max_votos || 0) - (e.meus_votos || 0));
    const lados = e.pergunta?.lados || [];
    const rotuloLado = (v) => (lados.find((l) => l.valor === v)?.rotulo) || '';
    const classeLado = (v) => (lados.findIndex((l) => l.valor === v) === 0 ? 'success' : 'danger');
    if (!e.itens.length) {
      return `
        <div class="small text-muted">${this.esc(e.pergunta?.rotulo || '')}</div>
        <h1 class="h5 mb-2">${this.esc(e.pergunta?.titulo || '')}</h1>
        <div class="alert alert-info py-2 small mt-3">Nenhuma resposta para votar nesta
          pergunta. Aguarde: a condução vai abrir a próxima no telão.</div>`;
    }
    return `
      <div class="small text-muted">${this.esc(e.pergunta?.rotulo || '')}</div>
      <h1 class="h5 mb-1">${this.esc(e.pergunta?.titulo || '')}</h1>
      <p class="small text-muted mb-2">A condução fechou esta pergunta. Marque com a estrela
        as respostas que você considera mais importantes.</p>
      <div class="alert ${restam ? 'alert-success' : 'alert-secondary'} py-2 small mb-2">
        ${restam
          ? `Você tem <strong>${restam}</strong> estrela(s) de ${e.max_votos}.`
          : `Você usou suas ${e.max_votos} estrela(s). Toque numa marcada para trocar.`}</div>
      ${this.listaEstrelas(e, lados, rotuloLado, classeLado)}`;
  },

  /**
   * As respostas em BLOCOS quando a pergunta tem lados: todas as escolhas
   * juntas, todas as renúncias juntas. Misturadas (a ordem de chegada), o
   * celular obrigava a ler o selo de cada ficha para saber de que lado ela era,
   * e a lista virava um vaivém entre duas conversas diferentes. Sem lados
   * (PESTEL, Porter, SWOT — a categoria já é a pergunta) continua lista única.
   */
  listaEstrelas(e, lados, rotuloLado, classeLado) {
    const ficha = (i) => `
      <button type="button" class="ideia-votavel${Number(i.votei) ? ' votada' : ''}"
        data-estrela="${i.id}" aria-pressed="${Number(i.votei) ? 'true' : 'false'}">
        <span class="voto-marca" aria-hidden="true">${Number(i.votei) ? '★' : '☆'}</span>
        <span class="flex-grow-1">${this.esc(i.texto)}</span>
        ${Number(i.votos) ? `<span class="badge text-bg-light border">${i.votos}</span>` : ''}
      </button>`;
    if (lados.length < 2) return e.itens.map(ficha).join('');
    // O selo sai da ficha e vira o TÍTULO do bloco: repetido em cada linha, ele
    // gastava um terço da largura do celular dizendo o que o bloco já diz.
    return lados.map((l) => {
      const doLado = e.itens.filter((i) => i.tipo_resposta === l.valor);
      if (!doLado.length) return '';
      return `<div class="bloco-estrelas">
        <div class="titulo-bloco-estrelas">
          <span class="badge text-bg-${classeLado(l.valor)}">${this.esc(l.rotulo)}</span>
          <span class="small text-muted">${doLado.length} resposta(s)</span>
        </div>
        ${doLado.map(ficha).join('')}
      </div>`;
    }).join('');
  },

  ligarEstrelas() {
    this.tela.querySelectorAll('[data-estrela]').forEach((b) => b.addEventListener('click', async () => {
      b.disabled = true;
      try {
        await this.api(`/api/publico/estrela/${b.dataset.estrela}`, {
          pin: this.pin, token: this.token,
        });
        // Redesenha com o estado do servidor: a contagem do teto é de lá, e
        // pintar a estrela no otimismo mentiria quando o teto recusasse
        await this.atualizar();
      } catch (erro) {
        alert(erro.message);
        b.disabled = false;
      }
    }));
  },

  /**
   * O lado escolhido, sempre válido PARA ESTA PERGUNTA. A sala é do projeto:
   * a condução pode sair da cascata (Escolha/Renúncia) para o cenário
   * (Situação atual/Tendência), e o lado guardado de antes não existe mais no
   * alvo novo — enviá-lo faria o servidor cair no primeiro lado sem a pessoa
   * saber. Alvo sem lados (PESTEL, Porter, SWOT) devolve null.
   */
  ladoAtual(p) {
    const lados = p.lados || [];
    if (!lados.length) return null;
    return lados.some((l) => l.valor === this.tipoResposta) ? this.tipoResposta : lados[0].valor;
  },

  blocoQuiz(p, minhas) {
    const prog = this.rodada?.progresso;
    const lados = p.lados || [];
    const lado = this.ladoAtual(p);
    const rotuloLado = (valor) => (lados.find((l) => l.valor === valor)?.rotulo) || 'sugestão';
    const usadas = (valor) => minhas.filter((i) => (i.tipo_resposta || null) === valor).length;
    const restam = this.rodada.max_ideias - usadas(lado);
    const maxTexto = Number(p.max_texto) || 255;
    // Duas cores para dois lados; o segundo lado é sempre o "contraponto"
    const classeLado = (i) => (i === 0 ? 'success' : 'danger');
    const area = `<textarea id="campo-ideia" class="form-control" rows="3" maxlength="${maxTexto}"
          placeholder="${this.esc(lado ? rotuloLado(lado) + '…' : 'Escreva sua sugestão…')}"></textarea>`;

    const contexto = (p.contexto || []).map((c) =>
      `<div class="small text-muted"><strong>${this.esc(c.rotulo)}:</strong> ${this.esc(c.valor)}</div>`
    ).join('');

    // Com o teto esgotado neste lado, dizer o que AINDA dá para fazer evita a
    // leitura de "acabou para mim" quando o outro lado segue aberto
    const outrosAbertos = lados
      .filter((l) => l.valor !== lado && this.rodada.max_ideias - usadas(l.valor) > 0)
      .map((l) => l.rotulo.toLowerCase());

    return `
      <div class="contexto-pergunta">
        <div class="small text-muted">${this.esc(p.rotulo)}${
          prog?.atual ? ` · Pergunta ${prog.atual} de ${prog.total}` : ''}</div>
        <h1 class="h5 mb-1">${this.esc(p.titulo)}</h1>
        ${contexto}
        ${p.orientacao ? `<div class="orientacao-pergunta">${this.esc(p.orientacao)}</div>` : ''}
      </div>

      ${lados.length ? `<div class="mt-3" role="radiogroup" aria-label="O que você vai sugerir">
        <div class="btn-group w-100 par-tipo-resposta">
          ${lados.map((l, i) => `
            <input type="radio" class="btn-check" name="tipo-resposta" id="tipo-lado-${i}"
              value="${this.esc(l.valor)}" ${l.valor === lado ? 'checked' : ''}>
            <label class="btn btn-outline-${classeLado(i)}" for="tipo-lado-${i}">${this.esc(l.rotulo)}</label>`).join('')}
        </div>
      </div>` : ''}

      ${restam <= 0
        ? `<div class="alert alert-success py-2 small mt-3">Você enviou todas as suas
             sugestões${lado ? ` de ${this.esc(rotuloLado(lado).toLowerCase())}` : ''} desta pergunta.
             ${outrosAbertos.length ? `Ainda pode sugerir ${this.esc(outrosAbertos.join(' e '))}.` : ''}</div>`
        : `<div class="mt-3">
          <label class="form-label small" for="campo-ideia">Sua sugestão${
            lado ? ` de ${this.esc(rotuloLado(lado).toLowerCase())}` : ''}</label>
          ${this.comVoz(area, 'campo-ideia')}
          <div class="d-flex align-items-center gap-2 mt-2">
            <span class="small text-muted" id="contador-resposta" data-max="${maxTexto}">0/${maxTexto}</span>
            <span class="small text-muted flex-grow-1">Pode enviar mais ${restam}.</span>
            <button class="btn btn-verde" id="btn-enviar">Enviar</button>
          </div>
          <div id="aviso-envio" class="small mt-2"></div>
        </div>`}

      ${minhas.length ? `
        <div class="mt-4">
          <div class="rotulo-secao">Suas sugestões nesta pergunta</div>
          ${minhas.map((i) => {
            if (this.editando === i.id) return this.editorIdeia(i);
            // O selo do lado e o ✎ dividem uma faixa PRÓPRIA, acima do texto.
            // Lado a lado com a frase, o selo comia um terço da largura do
            // celular e a resposta descia em coluna estreita — sete linhas para
            // o que cabe em quatro. A listra da borda segue o mesmo lado do
            // selo: verde em cima de selo vermelho fazia a ficha dizer duas
            // coisas ao mesmo tempo.
            const classe = i.tipo_resposta
              ? classeLado(lados.findIndex((l) => l.valor === i.tipo_resposta)) : '';
            return `<div class="ideia-minha${classe ? ` lado-${classe}` : ''}">
                 ${i.situacao === 'NOVO'
                   ? `<button type="button" class="btn btn-link btn-sm p-0 float-end ms-2 text-decoration-none"
                        data-editar="${i.id}" aria-label="Editar sugestão">✎ editar</button>`
                   : '<span class="small text-success float-end ms-2" title="Usada pela condução">✓ usada</span>'}
                 ${classe ? `<span class="badge text-bg-${classe} float-start me-2">${
                   this.esc(rotuloLado(i.tipo_resposta))}</span>` : ''}
                 <div>${this.esc(i.texto)}</div>
               </div>`;
          }).join('')}
        </div>` : ''}`;
  },

  ligarQuiz(p) {
    // O par de botões troca o lado; o rascunho digitado fica onde está
    this.tela.querySelectorAll('input[name="tipo-resposta"]').forEach((radio) =>
      radio.addEventListener('change', () => {
        // O valor sai do RÁDIO MARCADO, nunca do contêiner (a lição do
        // visivelSe: ler .value da div devolve undefined)
        this.tipoResposta = radio.checked ? radio.value : this.tipoResposta;
        // O rascunho atravessa a troca pelo mesmo canal do renderQuiz — mesmo
        // quando o lado de destino está esgotado e o campo não vai existir
        this.rascunhoPendente = document.getElementById('campo-ideia')?.value ?? '';
        this.render();
        this.atualizarContador();
      }));

    const campo = document.getElementById('campo-ideia');
    const btn = document.getElementById('btn-enviar');
    if (!campo || !btn) return;
    // Contador ao digitar: mexe SÓ no texto do span — redesenhar aqui fecharia
    // o teclado no meio da frase, a regra de ouro do polling vale para ele
    campo.addEventListener('input', () => this.atualizarContador());
    this.atualizarContador();

    btn.addEventListener('click', async () => {
      this.pararDitado();
      const texto = campo.value.trim();
      if (!texto) return;
      btn.disabled = true;
      try {
        // pergunta_id diz o que a pessoa estava VENDO: se a condução avançou
        // no meio da digitação, o servidor recusa em vez de gravar às cegas.
        // O lado vai resolvido contra ESTA pergunta, nunca o guardado de uma
        // análise anterior.
        await this.api('/api/publico/resposta', {
          pin: this.pin, token: this.token, pergunta_id: p.id,
          tipo: this.ladoAtual(p), texto,
        });
        campo.value = '';
        await this.atualizar(true);
        const novo = document.getElementById('aviso-envio');
        if (novo) {
          novo.className = 'small mt-2 text-success';
          novo.textContent = 'Sugestão enviada.';
        }
      } catch (e) {
        // A condução pode ter trocado a pergunta no meio da digitação (409).
        // Só mostrar a mensagem prendia a pessoa num beco: o campo cheio
        // suprime o redesenho do polling e cada reenvio repetia o mesmo
        // pergunta_id velho. Rebusca, redesenha COM o rascunho e aí avisa.
        this.rascunhoPendente = campo.value;
        try {
          this.rodada = await this.api(`/api/publico/rodada/${this.pin}`);
          this.minhas = await this.api(`/api/publico/minhas?pin=${this.pin}`);
        } catch { /* rede piscou; a tela atual continua valendo */ }
        this.render();
        const avisoNovo = document.getElementById('aviso-envio');
        if (avisoNovo) {
          avisoNovo.className = 'small mt-2 text-danger';
          avisoNovo.textContent = e.message;
        }
      } finally {
        btn.disabled = false;
      }
    });
  },

  atualizarContador() {
    const campo = document.getElementById('campo-ideia');
    const alvo = document.getElementById('contador-resposta');
    // O teto vem do ALVO da pergunta (data-max), não de um 255 fixo: alvos
    // diferentes têm limites diferentes, e um contador mentindo faz a pessoa
    // escrever até estourar sem aviso
    if (campo && alvo) alvo.textContent = `${campo.value.length}/${alvo.dataset.max || 255}`;
  },

  blocoEnvio(restam) {
    if (restam <= 0) {
      return `<div class="alert alert-success py-2 small mt-3">
        Você enviou todas as suas ideias. Aguarde a condução.</div>`;
    }
    const area = `<textarea id="campo-ideia" class="form-control" rows="4" maxlength="400"
          placeholder="Escreva ou dite como você diria em voz alta"></textarea>`;
    return `
      <div class="mt-3">
        <label class="form-label small" for="campo-ideia">Sua ideia</label>
        ${this.comVoz(area, 'campo-ideia')}
        <div class="d-flex align-items-center gap-2 mt-2">
          <span class="small text-muted flex-grow-1">Pode enviar mais ${restam}.</span>
          <button class="btn btn-verde" id="btn-enviar">Enviar</button>
        </div>
        <div id="aviso-envio" class="small mt-2"></div>
      </div>`;
  },

  ligarEnvio() {
    const btn = document.getElementById('btn-enviar');
    const campo = document.getElementById('campo-ideia');
    const aviso = document.getElementById('aviso-envio');
    btn.addEventListener('click', async () => {
      this.pararDitado();
      const texto = campo.value.trim();
      if (!texto) return;
      btn.disabled = true;
      try {
        // O nome vem do registro no servidor, não daqui: enviá-lo permitiria
        // assinar a ideia com o nome de outra pessoa
        await this.api('/api/publico/ideia', { pin: this.pin, token: this.token, texto });
        campo.value = '';
        // A confirmação vai depois do redesenho, senão ele a apaga na hora
        await this.atualizar(true);
        const novo = document.getElementById('aviso-envio');
        if (novo) {
          novo.className = 'small mt-2 text-success';
          novo.textContent = 'Ideia enviada.';
        }
      } catch (e) {
        aviso.className = 'small mt-2 text-danger';
        aviso.textContent = e.message;
      } finally {
        btn.disabled = false;
      }
    });
  },

  // ---- Corrigir a própria ideia ----
  editorIdeia(i) {
    // O limite vem do ALVO, calculado pelo servidor (`max_texto`), não do
    // tipo_resposta: alvo sem lado (PESTEL, Porter, SWOT) responde com tipo
    // nulo e o editor oferecia 400 num campo que o servidor corta em 255 —
    // perda silenciosa de texto. Quem garante continua sendo o servidor.
    const area = `<textarea id="campo-editar-${i.id}" class="form-control" rows="3"
          maxlength="${Number(i.max_texto) || 400}"
          data-editar-campo="${i.id}">${this.esc(i.texto)}</textarea>`;
    return `
      <div class="ideia-minha">
        ${this.comVoz(area, `campo-editar-${i.id}`)}
        <div class="d-flex align-items-center gap-2 mt-2">
          <button class="btn btn-verde btn-sm" data-salvar-edicao="${i.id}">Salvar</button>
          <button class="btn btn-outline-secondary btn-sm" data-cancelar-edicao="${i.id}">Cancelar</button>
          <span class="small text-danger flex-grow-1" data-erro-edicao="${i.id}"></span>
        </div>
      </div>`;
  },

  ligarEdicaoIdeias() {
    this.tela.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => {
      this.editando = Number(b.dataset.editar);
      this.render();
    }));
    this.tela.querySelectorAll('[data-cancelar-edicao]').forEach((b) => b.addEventListener('click', () => {
      this.editando = null;
      this.render();
    }));
    this.tela.querySelectorAll('[data-salvar-edicao]').forEach((b) => b.addEventListener('click', async () => {
      const id = Number(b.dataset.salvarEdicao);
      const campo = this.tela.querySelector(`[data-editar-campo="${id}"]`);
      const erro = this.tela.querySelector(`[data-erro-edicao="${id}"]`);
      const texto = campo.value.trim();
      if (!texto) { erro.textContent = 'Escreva a ideia.'; return; }
      b.disabled = true;
      try {
        await this.api(`/api/publico/ideia/${id}`, { pin: this.pin, token: this.token, texto });
        const it = this.minhas.find((x) => x.id === id);
        if (it) it.texto = texto;
        this.editando = null;
        this.render();
      } catch (e) {
        erro.textContent = e.message;
        b.disabled = false;
      }
    }));
    // Ao abrir o editor, foca e leva o cursor ao fim do texto.
    if (this.editando !== null) {
      const c = this.tela.querySelector(`[data-editar-campo="${this.editando}"]`);
      if (c) { c.focus(); c.setSelectionRange(c.value.length, c.value.length); }
    }
  },

  // ---- Ditado por voz ----
  /** Embrulha um campo no controle de voz, com o botão do microfone. */
  comVoz(html, alvoId) {
    if (!this.suporteVoz) return html;
    return `<div class="campo-voz">${html}<button class="btn-ditar" type="button" data-alvo="${alvoId}"
      title="Ditar por voz" aria-label="Ditar por voz">${this.iconeMic}</button></div>`;
  },

  ligarDitado() {
    this.tela.querySelectorAll('.btn-ditar').forEach((b) =>
      b.addEventListener('click', () => this.alternarDitado(b)));
  },

  // Toque para gravar (o botão pulsa em vermelho), fale, toque para parar — o
  // texto reconhecido é acrescentado ao campo, como no ditado do celular.
  alternarDitado(botao) {
    if (this.botaoGravando === botao) {
      this.pararDitado();
      return;
    }
    this.pararDitado();
    const campo = document.getElementById(botao.dataset.alvo);
    if (!campo) return;
    const Reconhecedor = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new Reconhecedor();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = false;
    rec.onresult = (ev) => {
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        if (ev.results[i].isFinal) {
          const texto = ev.results[i][0].transcript.trim();
          if (texto) {
            campo.value = campo.value ? `${campo.value.replace(/\s+$/, '')} ${texto}` : texto;
            // Atribuir .value por script não dispara 'input': sem este evento
            // o contador de caracteres do quiz ficava parado durante o ditado
            campo.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      }
    };
    rec.onend = () => this.pararDitado();
    rec.onerror = (ev) => {
      this.pararDitado();
      if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
        alert('Permita o acesso ao microfone no navegador para ditar por voz.');
      }
    };
    this.reconhecimento = rec;
    this.botaoGravando = botao;
    botao.classList.add('gravando');
    botao.title = 'Parar ditado';
    botao.setAttribute('aria-label', 'Parar ditado');
    try {
      rec.start();
    } catch {
      this.pararDitado();
    }
  },

  pararDitado() {
    if (this.reconhecimento) {
      this.reconhecimento.onend = null;
      try { this.reconhecimento.stop(); } catch { /* já parado */ }
    }
    if (this.botaoGravando) {
      this.botaoGravando.classList.remove('gravando');
      this.botaoGravando.title = 'Ditar por voz';
      this.botaoGravando.setAttribute('aria-label', 'Ditar por voz');
    }
    this.reconhecimento = null;
    this.botaoGravando = null;
  },

  // ---- Votação (fase opcional) ----
  blocoVotacao() {
    const v = this.votacao;
    const restam = v.max_votos - v.meus_votos;
    return `
      <div class="mt-3">
        <div class="alert alert-warning py-2 small">Escolha as ideias mais importantes.
          Restam <strong>${restam}</strong> voto(s). Toque de novo para desmarcar.</div>
        ${v.itens.map((i) => `
          <button type="button" class="ideia-votavel ${Number(i.votei) ? 'votada' : ''}"
            data-votar="${i.id}">
            <span class="voto-marca">${Number(i.votei) ? '★' : '☆'}</span>
            <span>${this.esc(i.texto)}</span>
          </button>`).join('') || '<p class="text-muted small">Nenhuma ideia para votar ainda.</p>'}
        <div id="aviso-voto" class="small mt-2"></div>
      </div>`;
  },

  ligarVotacao() {
    const aviso = document.getElementById('aviso-voto');
    this.tela.querySelectorAll('[data-votar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await this.api(`/api/publico/votar/${b.dataset.votar}`, { pin: this.pin, token: this.token });
        aviso.textContent = '';
        await this.atualizar(true);
      } catch (e) {
        aviso.className = 'small mt-2 text-danger';
        aviso.textContent = e.message;
      }
    }));
  },
};

document.addEventListener('DOMContentLoaded', () => Participante.iniciar());

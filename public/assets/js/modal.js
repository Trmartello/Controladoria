// Fábrica do modal genérico de cadastro: campos declarativos → formulário → POST JSON.

const Modal = {
  bsModal: null,
  config: null,

  // Ícones olho / olho riscado (Bootstrap Icons, MIT)
  iconeOlho: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>'
    + '<path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>',
  // Ditado por voz (Web Speech API) — o microfone só aparece se o navegador suportar
  suporteVoz: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
  reconhecimento: null,
  botaoGravando: null,
  iconeMic: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>'
    + '<path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/></svg>',

  iconeOlhoRiscado: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/>'
    + '<path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/>'
    + '<path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>',

  // `enviar` substitui o POST padrão quando salvar exige mais que uma chamada:
  // o caso real é o quiz, cujo 409 de "sala aberta em outra tela" vira uma
  // confirmação e um reenvio. Sem esse gancho a pergunta aparecia como erro
  // dentro do modal, sem nenhum jeito de responder sim.
  // `aoMudar(dados, raiz)` roda ao abrir e a cada mudança de campo, para o
  // formulário cujo TEXTO depende do que já foi escolhido. O caso real é o
  // cruzamento da SWOT: o bloco (atacar/defender/reforçar/proteger) é
  // consequência do par, e sem esse aviso o usuário só descobria em que quadro
  // a linha caiu depois de salvar. `visivelSe` não resolve — ele olha UM campo,
  // e aqui a resposta depende de dois.
  abrir({ titulo, campos, valores = {}, url, aoSalvar = null, transformar = null, extra = null,
          enviar = null, aoMudar = null }) {
    this.config = { campos, url, aoSalvar, transformar, extra, enviar };
    document.getElementById('modal-titulo').textContent = titulo;
    document.getElementById('modal-erro').classList.add('d-none');

    // Botão opcional à esquerda do rodapé (ex.: redefinir a avaliação)
    const btnExtra = document.getElementById('modal-extra');
    btnExtra.classList.toggle('d-none', !extra);
    btnExtra.textContent = extra ? extra.rotulo : '';
    btnExtra.onclick = extra ? () => this.executarExtra() : null;

    const form = document.getElementById('modal-campos');
    this.combos = {};
    form.innerHTML = campos.map((c) => this.renderCampo(c, valores[c.nome], valores)).join('');
    this.ligarBotoesSenha(form);
    this.ligarBotoesDitado(form);
    this.ligarSelecaoLivre(form);
    this.ligarListasMarcaveis(form);
    this.ligarDatasBr(form);
    this.ligarFaixas(form, campos);
    this.ligarCondicionais(form, campos);
    this.ligarTextareasElasticas(form);
    this.ligarMudanca(form, aoMudar);

    if (!this.bsModal) {
      this.bsModal = new bootstrap.Modal(document.getElementById('modal-form'));
      document.getElementById('modal-salvar').addEventListener('click', () => this.salvar());
      document.getElementById('modal-form').addEventListener('submit', (ev) => {
        ev.preventDefault();
        this.salvar();
      });
      document.getElementById('modal-form').addEventListener('hidden.bs.modal', () => this.pararDitado());
      // Só com o modal na tela dá para medir o que transborda
      document.getElementById('modal-form').addEventListener('shown.bs.modal', () =>
        this.aoAparecer(document.getElementById('modal-campos')));
    }
    this.bsModal.show();
  },

  renderCampo(c, valor, valores = {}) {
    const v = valor ?? c.padrao ?? '';
    const id = `campo-${c.nome}`;
    const exemplo = c.exemplo ? ` placeholder="${this.esc(c.exemplo)}"` : '';
    let controle;
    switch (c.tipo) {
      case 'periodo': {
        // Duas datas lado a lado (início e fim), cada uma com seu calendário
        const colunas = (c.campos || []).map((s) => `
          <div>
            <span class="sub-rotulo">${this.esc(s.rotulo)}</span>
            <input type="date" class="form-control" id="campo-${s.nome}"
              value="${this.esc(valores[s.nome] ?? '')}">
          </div>`).join('');
        controle = `<div class="grade-datas">${colunas}</div>`;
        break;
      }
      case 'textarea': {
        const area = `<textarea class="form-control" id="${id}" rows="${c.linhas || 3}"${exemplo}>${this.esc(v)}</textarea>`;
        controle = this.suporteVoz ? `<div class="campo-voz">${area}${this.botaoDitar(id)}</div>` : area;
        break;
      }
      case 'select': {
        const opcoes = (c.opcoes || [])
          .map((o) => `<option value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'selected' : ''}>${this.esc(o.rotulo)}</option>`)
          .join('');
        controle = `<select class="form-select" id="${id}">${opcoes}</select>`;
        break;
      }
      case 'multiselect': {
        const selecionados = Array.isArray(v) ? v.map(String) : [];
        const opcoes = (c.opcoes || [])
          .map((o) => `<option value="${this.esc(o.valor)}" ${selecionados.includes(String(o.valor)) ? 'selected' : ''}>${this.esc(o.rotulo)}</option>`)
          .join('');
        controle = `<select class="form-select" id="${id}" multiple size="${Math.min(8, (c.opcoes || []).length || 3)}">${opcoes}</select>`;
        break;
      }
      case 'lista_marcavel': {
        // Lista de itens marcáveis com o texto inteiro à vista — para escolhas
        // em que saber exatamente o que se está amarrando importa mais que
        // caber em uma linha. Com muitos itens, ganha campo de pesquisa.
        //
        // Com `unico: true` a lista escolhe UM item: os quadrados viram
        // redondos e o campo devolve o valor, não a lista. É o controle certo
        // sempre que o item precisa ser LIDO antes de escolhido — um `select`
        // com um parágrafo dentro obriga a abrir a lista para descobrir o que
        // existe, e no celular fica ilegível.
        const opcoes = c.opcoes || [];
        const marcados = c.unico
          ? (v === null || v === undefined || v === '' ? [] : [String(v)])
          : (Array.isArray(v) ? v : []).map(String);
        const itens = opcoes.map((o) => {
          const cor = /^#[0-9a-f]{6}$/i.test(o.cor || '') ? o.cor : '#007a45';
          const selos = [
            o.selo && `<span class="badge" style="color:${cor};background:${cor}1f">${this.esc(o.selo)}</span>`,
            o.selo2 && `<span class="badge text-bg-light border">${this.esc(o.selo2)}</span>`,
          ].filter(Boolean).join(' ');
          const busca = this.esc(`${o.selo || ''} ${o.selo2 || ''} ${o.texto || o.rotulo || ''}`);
          return `<label class="marcavel-item" data-busca="${busca}">
            <input class="form-check-input" type="${c.unico ? 'radio' : 'checkbox'}"
              ${c.unico ? `name="${id}-opcao"` : ''} value="${this.esc(o.valor)}"
              ${marcados.includes(String(o.valor)) ? 'checked' : ''}>
            <span class="marcavel-corpo">
              ${selos ? `<span class="marcavel-selos">${selos}</span>` : ''}
              <span class="marcavel-texto">${this.esc(o.texto || o.rotulo || '')}</span>
              <span class="marcavel-rodape"></span>
            </span>
          </label>`;
        }).join('');
        const pesquisa = opcoes.length > 5
          ? `<input type="search" class="form-control form-control-sm marcavel-busca"
               placeholder="Pesquisar na lista..." aria-label="Pesquisar na lista">`
          : '';
        controle = `<div class="lista-marcavel" id="${id}">
          ${pesquisa}
          <div class="marcavel-lista">${itens
            || '<div class="text-muted small p-2">Nenhum item disponível.</div>'}</div>
          <div class="marcavel-contador"></div>
        </div>`;
        break;
      }
      case 'selecao_livre': {
        // Combobox pesquisável: o toque abre um painel com busca no topo e a
        // lista de nomes em ordem alfabética; digitar filtra as sugestões e,
        // sem correspondência, o nome digitado é aceito como novo
        const opcoes = [...new Set((c.opcoes || []).map(String).filter((s) => s.trim() !== ''))]
          .sort((a, b) => a.localeCompare(b, 'pt-BR'));
        const valorStr = String(v ?? '');
        this.combos[id] = { opcoes, vazio: c.vazio || '(não definido)', obrigatorio: !!c.obrigatorio };
        controle = `<div class="combo-busca">
          <input type="hidden" id="${id}" value="${this.esc(valorStr)}">
          <button type="button" class="form-select text-start combo-alvo" id="${id}-alvo"
            aria-haspopup="listbox" aria-expanded="false">
            ${valorStr ? this.esc(valorStr) : `<span class="text-muted">${this.esc(c.vazio || '(não definido)')}</span>`}
          </button>
          <div class="combo-painel d-none">
            <input type="text" class="form-control combo-pesquisa" autocomplete="off"
              placeholder="Pesquisar ou digitar o nome...">
            <div class="combo-lista" role="listbox"></div>
          </div>
        </div>`;
        break;
      }
      case 'quadrantes': {
        // Matriz 2×2 clicável: a escolha é feita tocando no próprio quadrante
        const celulas = (c.opcoes || []).map((o) => {
          const cor = /^#[0-9a-f]{6}$/i.test(o.cor || '') ? o.cor : '#007a45';
          return `<input type="radio" class="btn-check" name="${id}" id="${id}-${this.esc(o.valor)}"
              value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'checked' : ''}>
            <label class="quadrante-opcao" for="${id}-${this.esc(o.valor)}" style="--cor-quad:${cor}">
              <span class="quadrante-nome">${this.esc(o.rotulo)}</span>
              ${o.dica ? `<span class="quadrante-dica">${this.esc(o.dica)}</span>` : ''}
            </label>`;
        }).join('');
        // A SWOT é uma MATRIZ: as quatro posições têm significado, então ficam
        // travadas em 2×2. PESTEL e Porter são só listas de categoria — com
        // `layout: 'lista'` os cartões fluem e acomodam 5, 6 ou quantos forem.
        const grade = c.layout === 'lista' ? 'grade-quadrantes grade-lista' : 'grade-quadrantes';
        controle = `<div class="${grade}" id="${id}" role="radiogroup"
          aria-label="${this.esc(c.rotulo)}">${celulas}</div>`;
        break;
      }
      case 'faixa': {
        // Controle deslizante com o valor atual à direita do rótulo
        const min = c.min ?? 0;
        const max = c.max ?? 100;
        const atual = v === '' || v === null || v === undefined ? min : Number(v);
        const pct = max > min ? Math.round(((atual - min) / (max - min)) * 100) : 0;
        controle = `<div class="campo-faixa">
          <input type="range" class="faixa-verde" id="${id}" min="${min}" max="${max}"
            step="${c.passo ?? 1}" value="${atual}" style="--pct:${pct}%">
          <div class="d-flex justify-content-between faixa-limites">
            <span>${min}${this.esc(c.sufixo || '')}</span>
            <span>${max}${this.esc(c.sufixo || '')}</span>
          </div>
        </div>`;
        break;
      }
      case 'botoes': {
        // Grupo de botões exclusivos (option buttons): um clique escolhe o valor
        const botoes = (c.opcoes || []).map((o) => `
          <input type="radio" class="btn-check" name="${id}" id="${id}-${this.esc(o.valor)}"
            value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'checked' : ''}>
          <label class="btn btn-opcao" for="${id}-${this.esc(o.valor)}">${this.esc(o.rotulo)}</label>`).join('');
        controle = `<div class="btn-group w-100 grupo-botoes" role="group" id="${id}"
          aria-label="${this.esc(c.rotulo)}">${botoes}</div>`;
        break;
      }
      case 'info': {
        // Bloco somente-leitura no topo do modal: mostra o conteúdo em até
        // ~10 linhas, com barra de rolagem para ler o restante. Com `barra`,
        // ganha um cabeçalho colorido identificando a origem (ex.: quadrante).
        const cor = /^#[0-9a-f]{6}$/i.test(c.barra?.cor || '') ? c.barra.cor : '#007a45';
        const barra = c.barra
          ? `<div class="info-barra" style="color:${cor};background:${cor}1f">
               <span>${this.esc(c.barra.titulo)}</span>
               ${c.barra.origem ? `<span class="info-barra-origem">${this.esc(c.barra.origem)}</span>` : ''}
             </div>`
          : '';
        // O id vai no bloco inteiro: `info` não tem controle, e é por ele que
        // um `aoMudar` alcança o texto para reescrevê-lo (o bloco do
        // cruzamento da SWOT, que só se conhece depois de escolhido o par).
        return `<div class="mb-3" id="${id}">
          ${c.rotulo ? `<label class="form-label rotulo-info">${this.esc(c.rotulo)}</label>` : ''}
          <div class="card card-info-modal">${barra}<div class="card-body py-2 px-3 small">${this.esc(c.texto ?? v)}</div></div>
        </div>`;
      }
      case 'hidden':
        // Sem rótulo nem espaçamento — o campo não aparece na tela
        return `<input type="hidden" id="${id}" value="${this.esc(v)}">`;
      case 'checkbox':
        return `<div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="${id}" ${v ? 'checked' : ''}>
          <label class="form-check-label" for="${id}">${this.esc(c.rotulo)}</label>
        </div>`;
      case 'password':
        controle = `<div class="input-group">
          <input type="password" class="form-control" id="${id}" value="${this.esc(v)}" autocomplete="new-password">
          <button class="btn btn-outline-secondary btn-ver-senha" type="button" data-alvo="${id}"
            aria-label="Mostrar senha" title="Mostrar senha">${this.iconeOlho}</button>
        </div>`;
        break;
      default: {
        // Com `sugestoes`, o campo vira lista de escolha que aceita digitar
        // um nome fora da lista (ex.: responsável que não é usuário do sistema)
        const lista = (c.sugestoes || []).length ? ` list="${id}-lista"` : '';
        const datalist = lista
          ? `<datalist id="${id}-lista">${c.sugestoes.map((s) => `<option value="${this.esc(s)}"></option>`).join('')}</datalist>`
          : '';
        const input = `<input type="${c.tipo || 'text'}" class="form-control" id="${id}" value="${this.esc(v)}"${exemplo}${lista}>${datalist}`;
        controle = (c.tipo || 'text') === 'text' && this.suporteVoz
          ? `<div class="campo-voz">${input}${this.botaoDitar(id)}</div>`
          : input;
      }
    }
    const ajuda = c.ajuda ? `<div class="form-text">${this.esc(c.ajuda)}</div>` : '';
    const nota = c.nota ? `<div class="nota-campo">${this.esc(c.nota)}</div>` : '';
    const obrigatorio = c.obrigatorio ? ' <span class="obrigatorio" title="Campo obrigatório">*</span>' : '';
    // Grupos (como o período) não apontam para um único campo
    const alvo = c.tipo === 'periodo' ? '' : ` for="${id}"`;
    // A faixa mostra o valor escolhido ao lado do rótulo
    const valorFaixa = c.tipo === 'faixa'
      ? `<span class="valor-faixa" id="${id}-valor">${v === '' || v === null || v === undefined ? (c.min ?? 0) : this.esc(v)}${this.esc(c.sufixo || '')}</span>`
      : '';
    return `<div class="mb-3"><label class="form-label"${alvo}>${this.esc(c.rotulo)}${obrigatorio}${valorFaixa}</label>${controle}${ajuda}${nota}</div>`;
  },

  botaoDitar(id) {
    return `<button class="btn btn-ditar" type="button" data-alvo="${id}"
      title="Ditar por voz" aria-label="Ditar por voz">${this.iconeMic}</button>`;
  },

  // Estado dos comboboxes pesquisáveis do formulário aberto (id → opções)
  combos: {},

  /**
   * Medidas que só fazem sentido com o modal já na tela: enquanto ele está
   * escondido toda altura vale zero, e nada transborda.
   */
  aoAparecer(raiz) {
    this.aplicarVerMais(raiz);
    raiz.querySelectorAll('textarea').forEach((t) => this.crescerTextarea(t));
    this.ligarAvisoRolagem(raiz.closest('.modal-body'));
  },

  /**
   * Avisa que há campo abaixo da dobra.
   *
   * O corpo rola desde sempre, mas nada indicava isso — e o Salvar mora no
   * rodapé fixo, sempre visível. Numa janela de notebook, o formulário de
   * quatro perguntas da matriz GUT mostrava três e o botão: quem respondia as
   * três e salvava deixava o esforço "não estimado" sem nunca ter escolhido
   * isso. Vale para todo modal do sistema, não só para o da GUT.
   *
   * O ouvinte de rolagem é ligado UMA vez: o corpo do modal é o mesmo elemento
   * em todos os formulários, e religá-lo a cada abertura empilharia uma cópia
   * por modal aberto na sessão.
   */
  ligarAvisoRolagem(corpo) {
    const aviso = document.getElementById('modal-mais');
    if (!corpo || !aviso) return;
    const medir = () => aviso.classList.toggle(
      'd-none', corpo.scrollHeight - corpo.clientHeight - corpo.scrollTop <= 8);
    if (!corpo.dataset.avisoLigado) {
      corpo.addEventListener('scroll', medir);
      aviso.addEventListener('click', () => corpo.scrollTo({
        top: corpo.scrollTop + corpo.clientHeight * 0.8, behavior: 'smooth',
      }));
      corpo.dataset.avisoLigado = '1';
    }
    medir();
  },

  /**
   * Descrição longa fica cortada em 3 linhas: um item extenso não pode engolir
   * a lista inteira. Só ganha "ver mais" quem realmente transborda.
   */
  aplicarVerMais(raiz) {
    raiz.querySelectorAll('.marcavel-item').forEach((item) => {
      const texto = item.querySelector('.marcavel-texto');
      const rodape = item.querySelector('.marcavel-rodape');
      if (!texto || !rodape || rodape.firstChild) return;
      if (texto.scrollHeight <= texto.clientHeight + 1) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-link btn-sm p-0 ver-mais';
      btn.textContent = 'ver mais';
      btn.addEventListener('click', (ev) => {
        // O botão vive dentro do <label>: sem isto o clique marcaria o item
        ev.preventDefault();
        ev.stopPropagation();
        const aberto = texto.classList.toggle('expandido');
        btn.textContent = aberto ? 'ver menos' : 'ver mais';
      });
      rodape.appendChild(btn);
    });
  },

  /**
   * O campo de texto acompanha o que está sendo escrito: cresce conforme o
   * texto, até o teto de 60% da altura da tela. Passando disso, ele para de
   * crescer e rola por dentro — senão o botão Salvar sairia do alcance.
   */
  crescerTextarea(t) {
    const teto = Math.round(window.innerHeight * 0.6);
    if (t.scrollHeight <= t.clientHeight + 1) return;
    const alvo = Math.min(t.scrollHeight + 2, teto);
    if (alvo > t.clientHeight) t.style.minHeight = `${alvo}px`;
  },

  ligarTextareasElasticas(raiz) {
    raiz.querySelectorAll('textarea').forEach((t) =>
      t.addEventListener('input', () => this.crescerTextarea(t)));
  },

  /** Pesquisa e contador das listas marcáveis. */
  ligarListasMarcaveis(raiz) {
    const norm = (s) => s.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '');
    raiz.querySelectorAll('.lista-marcavel').forEach((caixa) => {
      const itens = [...caixa.querySelectorAll('.marcavel-item')];
      const contador = caixa.querySelector('.marcavel-contador');
      const busca = caixa.querySelector('.marcavel-busca');

      // Na lista de escolha única não se conta o que está marcado (é sempre um
      // ou nenhum): o contador serve para dizer o que a pesquisa escondeu.
      const unico = !!caixa.querySelector('input[type=radio]');

      const contar = () => {
        const n = itens.filter((i) => i.querySelector('input').checked).length;
        const ocultos = itens.filter((i) => i.classList.contains('d-none')).length;
        contador.textContent = [
          unico ? (n ? '' : 'Nenhum item escolhido')
                : (n === 0 ? 'Nenhum item marcado' : `${n} marcado${n > 1 ? 's' : ''}`),
          ocultos ? `${ocultos} fora da pesquisa` : '',
        ].filter(Boolean).join(' · ');
      };

      caixa.querySelectorAll('input').forEach((ch) =>
        ch.addEventListener('change', contar));
      busca?.addEventListener('input', () => {
        const q = norm(busca.value.trim());
        // Um item marcado nunca some da lista: some sumiria da conta também
        itens.forEach((i) => i.classList.toggle(
          'd-none',
          q !== '' && !i.querySelector('input').checked && !norm(i.dataset.busca || '').includes(q)
        ));
        contar();
      });
      contar();
    });
  },

  ligarSelecaoLivre(raiz) {
    raiz.querySelectorAll('.combo-busca').forEach((caixa) => {
      const oculto = caixa.querySelector('input[type=hidden]');
      const alvo = caixa.querySelector('.combo-alvo');
      const painel = caixa.querySelector('.combo-painel');
      const pesquisa = caixa.querySelector('.combo-pesquisa');
      const lista = caixa.querySelector('.combo-lista');
      const { opcoes, vazio, obrigatorio } = this.combos[oculto.id]
        || { opcoes: [], vazio: '(não definido)', obrigatorio: false };

      const norm = (s) => s.toLocaleLowerCase('pt-BR')
        .normalize('NFD').replace(/[̀-ͯ]/g, '');

      const escolher = (valor) => {
        oculto.value = valor;
        alvo.innerHTML = valor
          ? this.esc(valor)
          : `<span class="text-muted">${this.esc(vazio)}</span>`;
        // O valor mora num `input[type=hidden]`, e campo escrito por código não
        // emite evento sozinho: sem este disparo, `visivelSe` e `aoMudar` nunca
        // ficariam sabendo da escolha feita neste controle.
        oculto.dispatchEvent(new Event('change', { bubbles: true }));
        fechar();
      };

      const montarLista = () => {
        const q = pesquisa.value.trim();
        const achados = q === '' ? opcoes : opcoes.filter((o) => norm(o).includes(norm(q)));
        const itens = [];
        // Campo obrigatório não oferece a opção de deixar em branco
        if (q === '' && !obrigatorio) {
          itens.push(`<button type="button" class="combo-item text-muted" data-valor="">${this.esc(vazio)}</button>`);
        }
        itens.push(...achados.map((o) =>
          `<button type="button" class="combo-item ${o === oculto.value ? 'ativo' : ''}"
             data-valor="${this.esc(o)}">${this.esc(o)}</button>`));
        // Sem correspondência exata: oferece usar o nome digitado
        if (q !== '' && !achados.some((o) => norm(o) === norm(q))) {
          itens.push(`<button type="button" class="combo-item combo-novo" data-valor="${this.esc(q)}">
            + Usar “${this.esc(q)}”</button>`);
        }
        lista.innerHTML = itens.join('')
          || `<div class="text-muted small px-2 py-1">Nenhum nome encontrado.</div>`;
        lista.querySelectorAll('.combo-item').forEach((b) =>
          b.addEventListener('click', () => escolher(b.dataset.valor)));
      };

      const abrir = () => {
        painel.classList.remove('d-none');
        alvo.setAttribute('aria-expanded', 'true');
        pesquisa.value = '';
        montarLista();
        pesquisa.focus();
      };
      const fechar = () => {
        painel.classList.add('d-none');
        alvo.setAttribute('aria-expanded', 'false');
      };

      alvo.addEventListener('click', () =>
        painel.classList.contains('d-none') ? abrir() : fechar());
      pesquisa.addEventListener('input', montarLista);
      pesquisa.addEventListener('keydown', (ev) => {
        // Enter escolhe o primeiro item (ou o nome digitado); Esc fecha só o painel
        if (ev.key === 'Enter') {
          ev.preventDefault();
          lista.querySelector('.combo-item:not(.text-muted)')?.click()
            || escolher(pesquisa.value.trim());
        } else if (ev.key === 'Escape') {
          ev.stopPropagation();
          fechar();
          alvo.focus();
        }
      });
      // Fechar ao clicar fora: o listener é do DOCUMENTO e o modal é reaberto
      // dezenas de vezes numa sessão. Registrado dentro do forEach e nunca
      // removido, cada abertura deixava mais um closure preso ao painel antigo,
      // que virava nó destacado. Sai junto com o modal.
      const foraDoCombo = (ev) => {
        if (!painel.classList.contains('d-none') && !ev.target.closest('.combo-busca')) fechar();
      };
      document.addEventListener('click', foraDoCombo);
      document.getElementById('modal-form')?.addEventListener('hidden.bs.modal', () => {
        document.removeEventListener('click', foraDoCombo);
      }, { once: true });
    });
  },

  /**
   * Valor atual de um campo, seja ele qual for. `botoes` e `quadrantes` não são
   * um controle só: o id fica numa div que agrupa os rádios, e `.value` nela é
   * `undefined` — um `visivelSe` apontado para um grupo de botões escondia o
   * campo dependente para sempre.
   */
  valorAtual(el) {
    if (!el) return '';
    if (el.tagName === 'DIV') return el.querySelector('input:checked')?.value ?? '';
    if (el.type === 'checkbox') return el.checked ? '1' : '';
    return el.value;
  },

  // Campos que só aparecem conforme o valor de outro (visivelSe: {campo, valores})
  ligarCondicionais(raiz, campos) {
    const condicionais = campos.filter((c) => c.visivelSe);
    if (!condicionais.length) return;
    const aplicar = () => {
      for (const c of condicionais) {
        const gatilho = document.getElementById(`campo-${c.visivelSe.campo}`);
        const bloco = document.getElementById(`campo-${c.nome}`)?.closest('.mb-3');
        if (!gatilho || !bloco) continue;
        bloco.classList.toggle('d-none', !c.visivelSe.valores.includes(this.valorAtual(gatilho)));
      }
    };
    new Set(condicionais.map((c) => c.visivelSe.campo)).forEach((nome) => {
      document.getElementById(`campo-${nome}`)?.addEventListener('change', aplicar);
    });
    aplicar();
  },

  /**
   * Avisa a tela dona a cada mudança de campo (ver `aoMudar` em `abrir`).
   *
   * O ouvinte fica no FORMULÁRIO e não em cada campo: o `change` sobe por
   * borbulhamento, e assim vale também para o que é desenhado por dentro
   * (`selecao_livre` guarda o valor num `input[type=hidden]`, que não emite
   * evento sozinho — quem o preenche dispara o `change` à mão).
   * O formulário é reconstruído a cada abertura (`innerHTML`), então não há
   * ouvinte antigo empilhado aqui.
   */
  ligarMudanca(raiz, aoMudar) {
    if (!aoMudar) return;
    const disparar = () => aoMudar(this.coletar(), raiz);
    raiz.addEventListener('change', disparar);
    raiz.addEventListener('input', disparar);
    disparar();
  },

  // O valor da faixa acompanha o arraste do controle
  ligarFaixas(raiz, campos) {
    raiz.querySelectorAll('input[type=range]').forEach((r) => {
      const sufixo = campos.find((c) => `campo-${c.nome}` === r.id)?.sufixo || '';
      const alvo = document.getElementById(`${r.id}-valor`);
      const min = Number(r.min || 0);
      const max = Number(r.max || 100);
      r.addEventListener('input', () => {
        if (alvo) alvo.textContent = `${r.value}${sufixo}`;
        // Pinta o trilho até a posição do pino
        const pct = max > min ? Math.round(((Number(r.value) - min) / (max - min)) * 100) : 0;
        r.style.setProperty('--pct', `${pct}%`);
      });
    });
  },

  // Campos de data sempre exibem dd/mm/aaaa: o texto nativo (que no iPhone
  // sai por extenso) fica transparente e um rótulo formatado é sobreposto;
  // o toque continua abrindo o calendário nativo
  ligarDatasBr(raiz) {
    raiz.querySelectorAll('input[type=date]').forEach((inp) => {
      if (inp.closest('.campo-data')) return;
      const caixa = document.createElement('div');
      caixa.className = 'campo-data';
      inp.parentNode.insertBefore(caixa, inp);
      caixa.appendChild(inp);
      const rotulo = document.createElement('span');
      rotulo.className = 'data-rotulo';
      caixa.appendChild(rotulo);
      const atualizar = () => {
        rotulo.textContent = inp.value ? inp.value.split('-').reverse().join('/') : 'dd/mm/aaaa';
        rotulo.classList.toggle('text-muted', !inp.value);
      };
      inp.addEventListener('input', atualizar);
      inp.addEventListener('change', atualizar);
      atualizar();
    });
  },

  ligarBotoesDitado(raiz) {
    raiz.querySelectorAll('.btn-ditar').forEach((b) =>
      b.addEventListener('click', () => this.alternarDitado(b)));
  },

  // Toque para gravar (botão pulsa em vermelho), fale, toque para parar —
  // o texto reconhecido é acrescentado ao campo, como no ditado do iPhone.
  alternarDitado(botao) {
    if (this.botaoGravando === botao) {
      this.pararDitado();
      return;
    }
    this.pararDitado();
    const campo = document.getElementById(botao.dataset.alvo);
    const Reconhecedor = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new Reconhecedor();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = false;
    rec.onresult = (ev) => {
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        if (ev.results[i].isFinal) {
          const texto = ev.results[i][0].transcript.trim();
          if (texto) campo.value = campo.value ? `${campo.value.replace(/\s+$/, '')} ${texto}` : texto;
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

  // Olho de conferir senha: alterna visível/oculto no campo alvo
  ligarBotoesSenha(raiz) {
    raiz.querySelectorAll('.btn-ver-senha').forEach((b) => b.addEventListener('click', () => {
      const campo = document.getElementById(b.dataset.alvo);
      const mostrar = campo.type === 'password';
      campo.type = mostrar ? 'text' : 'password';
      b.innerHTML = mostrar ? this.iconeOlhoRiscado : this.iconeOlho;
      b.title = mostrar ? 'Ocultar senha' : 'Mostrar senha';
      b.setAttribute('aria-label', b.title);
    }));
  },

  coletar() {
    const dados = {};
    for (const c of this.config.campos) {
      if (c.tipo === 'periodo') {
        for (const s of c.campos || []) {
          dados[s.nome] = document.getElementById(`campo-${s.nome}`)?.value ?? '';
        }
        continue;
      }
      // `info` é bloco de leitura: tem id (para ser reescrito por `aoMudar`)
      // mas não tem valor, e sem esta saída ele entraria no corpo como `undefined`
      if (c.tipo === 'info') continue;
      const el = document.getElementById(`campo-${c.nome}`);
      if (!el) continue;
      if (c.tipo === 'checkbox') dados[c.nome] = el.checked;
      else if (c.tipo === 'selecao_livre') dados[c.nome] = el.value.trim();
      else if (c.tipo === 'botoes' || c.tipo === 'quadrantes') {
        const marcado = el.querySelector('input:checked');
        dados[c.nome] = marcado ? (marcado.value === '' || isNaN(marcado.value) ? marcado.value : Number(marcado.value)) : null;
      }
      else if (c.tipo === 'multiselect') dados[c.nome] = Array.from(el.selectedOptions).map((o) => o.value);
      else if (c.tipo === 'lista_marcavel') {
        const marcados = [...el.querySelectorAll('input:checked')].map((ch) => ch.value);
        // `unico` devolve o valor, não a lista de um: quem consome é campo de
        // id, e um array aqui viraria "Array" no corpo do pedido.
        dados[c.nome] = c.unico ? (marcados[0] ?? null) : marcados;
      }
      else if (c.tipo === 'number' || c.tipo === 'faixa') dados[c.nome] = el.value === '' ? null : Number(el.value);
      else dados[c.nome] = el.value;
    }
    return dados;
  },

  // Ação do botão extra (ex.: apagar a avaliação para refazê-la)
  async executarExtra() {
    const { extra } = this.config;
    if (extra.confirmar && !confirm(extra.confirmar)) return;
    const botao = document.getElementById('modal-extra');
    botao.disabled = true;
    try {
      const resposta = await extra.aoClicar();
      if (extra.manterAberto) {
        // Redefinir sem sair da tela: atualiza a lista por baixo e some com o
        // botão (nada mais a redefinir); o usuário continua editando no modal
        botao.classList.add('d-none');
        App.recarregarSecaoAtiva();
      } else {
        this.bsModal.hide();
        if (this.config.aoSalvar) this.config.aoSalvar(resposta);
        else App.recarregarSecaoAtiva();
      }
    } catch (e) {
      this.mostrarErro(e.message);
    } finally {
      botao.disabled = false;
    }
  },

  async salvar() {
    const botao = document.getElementById('modal-salvar');
    botao.disabled = true; // evita duplo clique criando registros duplicados
    try {
      const dados = this.coletar();
      // A resposta chega ao aoSalvar: alguns formulários precisam dela (uma
      // ação que se repete devolve a data em que vai reabrir, por exemplo)
      const corpo = this.config.transformar ? this.config.transformar(dados) : dados;
      const resposta = this.config.enviar
        ? await this.config.enviar(corpo)
        : await App.api(this.config.url, corpo);
      this.bsModal.hide();
      if (this.config.aoSalvar) this.config.aoSalvar(resposta);
      else App.recarregarSecaoAtiva();
    } catch (e) {
      this.mostrarErro(e.message);
    } finally {
      botao.disabled = false;
    }
  },

  /**
   * A recusa do servidor aparece acima do rodapé — e o rodapé é justamente
   * onde está o Salvar que acabou de ser clicado. Com o corpo rolado até o
   * fim, o aviso nascia fora da área visível e o formulário parecia ter
   * simplesmente ignorado o clique. O `scrollIntoView` traz o aviso à vista; o
   * foco fica onde está, para não tirar o cursor do campo que precisa mudar.
   */
  mostrarErro(mensagem) {
    const erro = document.getElementById('modal-erro');
    if (!erro) return;
    erro.textContent = mensagem;
    erro.classList.remove('d-none');
    erro.scrollIntoView({ block: 'nearest' });
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  },
};

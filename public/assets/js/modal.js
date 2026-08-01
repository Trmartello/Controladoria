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

  abrir({ titulo, campos, valores = {}, url, aoSalvar = null, transformar = null, extra = null }) {
    this.config = { campos, url, aoSalvar, transformar, extra };
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
    this.ligarDatasBr(form);
    this.ligarFaixas(form, campos);
    this.ligarCondicionais(form, campos);

    if (!this.bsModal) {
      this.bsModal = new bootstrap.Modal(document.getElementById('modal-form'));
      document.getElementById('modal-salvar').addEventListener('click', () => this.salvar());
      document.getElementById('modal-form').addEventListener('submit', (ev) => {
        ev.preventDefault();
        this.salvar();
      });
      document.getElementById('modal-form').addEventListener('hidden.bs.modal', () => this.pararDitado());
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
        controle = `<div class="grade-quadrantes" id="${id}" role="radiogroup"
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
        return `<div class="mb-3">
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
      document.addEventListener('click', (ev) => {
        if (!painel.classList.contains('d-none') && !ev.target.closest('.combo-busca')) fechar();
      });
    });
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
        bloco.classList.toggle('d-none', !c.visivelSe.valores.includes(gatilho.value));
      }
    };
    new Set(condicionais.map((c) => c.visivelSe.campo)).forEach((nome) => {
      document.getElementById(`campo-${nome}`)?.addEventListener('change', aplicar);
    });
    aplicar();
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
      const el = document.getElementById(`campo-${c.nome}`);
      if (!el) continue;
      if (c.tipo === 'checkbox') dados[c.nome] = el.checked;
      else if (c.tipo === 'selecao_livre') dados[c.nome] = el.value.trim();
      else if (c.tipo === 'botoes' || c.tipo === 'quadrantes') {
        const marcado = el.querySelector('input:checked');
        dados[c.nome] = marcado ? (marcado.value === '' || isNaN(marcado.value) ? marcado.value : Number(marcado.value)) : null;
      }
      else if (c.tipo === 'multiselect') dados[c.nome] = Array.from(el.selectedOptions).map((o) => o.value);
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
      this.bsModal.hide();
      if (this.config.aoSalvar) this.config.aoSalvar(resposta);
      else App.recarregarSecaoAtiva();
    } catch (e) {
      const erro = document.getElementById('modal-erro');
      erro.textContent = e.message;
      erro.classList.remove('d-none');
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
      const resposta = await App.api(
        this.config.url, this.config.transformar ? this.config.transformar(dados) : dados);
      this.bsModal.hide();
      if (this.config.aoSalvar) this.config.aoSalvar(resposta);
      else App.recarregarSecaoAtiva();
    } catch (e) {
      const erro = document.getElementById('modal-erro');
      erro.textContent = e.message;
      erro.classList.remove('d-none');
    } finally {
      botao.disabled = false;
    }
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  },
};

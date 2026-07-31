// Fábrica do modal genérico de cadastro: campos declarativos → formulário → POST JSON.

const Modal = {
  bsModal: null,
  config: null,

  // Ícones olho / olho riscado (Bootstrap Icons, MIT)
  iconeOlho: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>'
    + '<path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>',
  iconeOlhoRiscado: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/>'
    + '<path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/>'
    + '<path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>',

  abrir({ titulo, campos, valores = {}, url, aoSalvar = null, transformar = null }) {
    this.config = { campos, url, aoSalvar, transformar };
    document.getElementById('modal-titulo').textContent = titulo;
    document.getElementById('modal-erro').classList.add('d-none');

    const form = document.getElementById('modal-campos');
    form.innerHTML = campos.map((c) => this.renderCampo(c, valores[c.nome])).join('');
    this.ligarBotoesSenha(form);

    if (!this.bsModal) {
      this.bsModal = new bootstrap.Modal(document.getElementById('modal-form'));
      document.getElementById('modal-salvar').addEventListener('click', () => this.salvar());
      document.getElementById('modal-form').addEventListener('submit', (ev) => {
        ev.preventDefault();
        this.salvar();
      });
    }
    this.bsModal.show();
  },

  renderCampo(c, valor) {
    const v = valor ?? c.padrao ?? '';
    const id = `campo-${c.nome}`;
    let controle;
    switch (c.tipo) {
      case 'textarea':
        controle = `<textarea class="form-control" id="${id}" rows="${c.linhas || 3}">${this.esc(v)}</textarea>`;
        break;
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
      default:
        controle = `<input type="${c.tipo || 'text'}" class="form-control" id="${id}" value="${this.esc(v)}">`;
    }
    const ajuda = c.ajuda ? `<div class="form-text">${this.esc(c.ajuda)}</div>` : '';
    return `<div class="mb-3"><label class="form-label" for="${id}">${this.esc(c.rotulo)}</label>${controle}${ajuda}</div>`;
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
      const el = document.getElementById(`campo-${c.nome}`);
      if (!el) continue;
      if (c.tipo === 'checkbox') dados[c.nome] = el.checked;
      else if (c.tipo === 'multiselect') dados[c.nome] = Array.from(el.selectedOptions).map((o) => o.value);
      else if (c.tipo === 'number') dados[c.nome] = el.value === '' ? null : Number(el.value);
      else dados[c.nome] = el.value;
    }
    return dados;
  },

  async salvar() {
    const botao = document.getElementById('modal-salvar');
    botao.disabled = true; // evita duplo clique criando registros duplicados
    try {
      const dados = this.coletar();
      await App.api(this.config.url, this.config.transformar ? this.config.transformar(dados) : dados);
      this.bsModal.hide();
      if (this.config.aoSalvar) this.config.aoSalvar();
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

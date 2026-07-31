// Fábrica do modal genérico de cadastro: campos declarativos → formulário → POST JSON.

const Modal = {
  bsModal: null,
  config: null,

  abrir({ titulo, campos, valores = {}, url, aoSalvar = null, transformar = null }) {
    this.config = { campos, url, aoSalvar, transformar };
    document.getElementById('modal-titulo').textContent = titulo;
    document.getElementById('modal-erro').classList.add('d-none');

    const form = document.getElementById('modal-campos');
    form.innerHTML = campos.map((c) => this.renderCampo(c, valores[c.nome])).join('');

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
      default:
        controle = `<input type="${c.tipo || 'text'}" class="form-control" id="${id}" value="${this.esc(v)}">`;
    }
    const ajuda = c.ajuda ? `<div class="form-text">${this.esc(c.ajuda)}</div>` : '';
    return `<div class="mb-3"><label class="form-label" for="${id}">${this.esc(c.rotulo)}</label>${controle}${ajuda}</div>`;
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
    }
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  },
};

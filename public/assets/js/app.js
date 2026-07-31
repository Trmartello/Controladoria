// Núcleo da one-page: sessão, contexto (ciclo × negócio) e navegação por seções.

const App = {
  sessao: null,          // { usuario, veTudo, negocios, ciclos }
  contexto: { cicloId: null, negocioId: null, corporativo: false },
  csrf: document.querySelector('meta[name="csrf"]').content,

  async api(url, corpo = null) {
    const opts = corpo
      ? {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
          body: JSON.stringify(corpo),
        }
      : { headers: { 'X-CSRF-Token': this.csrf } };
    const resp = await fetch(url, opts);
    if (resp.status === 401) { window.location.href = '/login'; throw new Error('sessão expirada'); }
    const json = await resp.json();
    if (!json.ok) throw new Error(json.erro || 'Erro inesperado');
    return json.dados;
  },

  async iniciar() {
    this.sessao = await this.api('/api/me');
    document.getElementById('usuario-nome').textContent = this.sessao.usuario.nome;
    document.getElementById('usuario-perfil').textContent = this.sessao.usuario.perfil;
    this.montarSeletores();

    document.getElementById('btn-sair').addEventListener('click', async () => {
      await this.api('/api/logout', {});
      window.location.href = '/login';
    });
    document.getElementById('btn-senha').addEventListener('click', () => {
      Modal.abrir({
        titulo: 'Trocar senha',
        campos: [
          { nome: 'senha_atual', rotulo: 'Senha atual', tipo: 'password' },
          { nome: 'senha_nova', rotulo: 'Nova senha (mín. 6 caracteres)', tipo: 'password' },
        ],
        url: '/api/senha',
      });
    });

    document.querySelectorAll('#nav-secoes [data-secao]').forEach((el) => {
      el.addEventListener('click', (ev) => {
        ev.preventDefault();
        this.mostrarSecao(el.dataset.secao);
      });
    });

    this.mostrarSecao('painel');
  },

  montarSeletores() {
    const selCiclo = document.getElementById('sel-ciclo');
    selCiclo.innerHTML = this.sessao.ciclos
      .map((c) => `<option value="${c.id}">${c.nome} (base ${c.ano_base})</option>`)
      .join('');

    const selNegocio = document.getElementById('sel-negocio');
    const opcoes = [];
    if (this.sessao.veTudo) opcoes.push('<option value="CORP">Corporativo</option>');
    for (const n of this.sessao.negocios) {
      opcoes.push(`<option value="${n.id}">${n.rotulo}</option>`);
    }
    selNegocio.innerHTML = opcoes.join('') || '<option value="">(nenhum negócio vinculado)</option>';

    const atualizar = () => {
      this.contexto.cicloId = parseInt(selCiclo.value, 10) || null;
      this.contexto.corporativo = selNegocio.value === 'CORP';
      this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
      this.recarregarSecaoAtiva();
    };
    selCiclo.addEventListener('change', atualizar);
    selNegocio.addEventListener('change', atualizar);
    this.contexto.cicloId = parseInt(selCiclo.value, 10) || null;
    this.contexto.corporativo = selNegocio.value === 'CORP';
    this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
  },

  secaoAtiva: null,

  mostrarSecao(nome) {
    this.secaoAtiva = nome;
    document.querySelectorAll('.secao').forEach((s) => s.classList.add('d-none'));
    document.querySelectorAll('#nav-secoes .nav-link').forEach((l) => l.classList.remove('active'));
    document.getElementById(`secao-${nome}`).classList.remove('d-none');
    document.querySelector(`#nav-secoes [data-secao="${nome}"]`).classList.add('active');
    this.recarregarSecaoAtiva();
  },

  // Contexto selecionado como querystring; null se incompleto
  contextoParams() {
    const ctx = this.contexto;
    if (!ctx.cicloId || (!ctx.negocioId && !ctx.corporativo)) return null;
    return ctx.corporativo
      ? `ciclo_id=${ctx.cicloId}&escopo=CORPORATIVO`
      : `ciclo_id=${ctx.cicloId}&negocio_id=${ctx.negocioId}`;
  },

  // Resolve (e cria se preciso) o planejamento do contexto atual
  async planejamento() {
    const params = this.contextoParams();
    if (!params) return null;
    return (await this.api(`/api/contexto?${params}`)).planejamento;
  },

  rotuloContexto() {
    return this.contexto.corporativo
      ? 'Corporativo'
      : this.sessao.negocios.find((n) => n.id === this.contexto.negocioId)?.rotulo || '';
  },

  podeEditar() {
    return this.sessao.usuario.perfil !== 'LEITURA';
  },

  recarregarSecaoAtiva() {
    const secoes = {
      painel: SecaoPainel, hub: SecaoHub, cadastros: SecaoCadastros,
      cenario: SecaoCenario, pestel: SecaoPestel, porter: SecaoPorter,
      swot: SecaoSwot, gut: SecaoGut, cascata: SecaoCascata,
    };
    const secao = secoes[this.secaoAtiva];
    if (secao) secao.carregar().catch((e) => {
      document.getElementById(`secao-${this.secaoAtiva}`).innerHTML =
        `<div class="alert alert-danger">${e.message}</div>`;
    });
  },
};

App.iniciar();

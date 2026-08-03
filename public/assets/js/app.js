// Núcleo da one-page: sessão, contexto (ciclo × negócio) e navegação por seções.

const App = {
  sessao: null,          // { usuario, veTudo, negocios, ciclos }
  contexto: { cicloId: null, negocioId: null, corporativo: false },
  csrf: document.querySelector('meta[name="csrf"]').content,

  // Data local (YYYY-MM-DD) — toISOString usaria UTC e viraria o dia após ~21h
  hoje(diasAtras = 0) {
    const d = new Date(Date.now() - diasAtras * 864e5);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  },

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
          { nome: 'senha_nova', rotulo: 'Nova senha (mín. 8 caracteres)', tipo: 'password' },
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

    this.iniciarMenu();
    this.mostrarSecao('painel');
  },

  // Menu automático (todas as telas): expande pelo ☰ ou ao encostar o mouse
  // na borda esquerda; recolhe sozinho ao navegar, ao trocar o contexto, ao
  // sair o mouse (desktop), ao tocar fora (mobile) ou com Esc.
  iniciarMenu() {
    const botao = document.getElementById('btn-menu');
    const menu = document.getElementById('menu-lateral');
    const desktop = window.matchMedia('(min-width: 992px)');
    const alternar = (aberto) => {
      document.body.classList.toggle('menu-aberto', aberto);
      botao.setAttribute('aria-expanded', String(aberto));
    };
    botao.addEventListener('click', () =>
      alternar(!document.body.classList.contains('menu-aberto')));
    document.getElementById('backdrop-menu').addEventListener('click', () => alternar(false));
    menu.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-secao]')) alternar(false);
    });
    ['sel-ciclo', 'sel-negocio'].forEach((id) =>
      document.getElementById(id).addEventListener('change', () => alternar(false)));

    // Desktop: expande ao encostar na borda esquerda e recolhe quando o
    // mouse se afasta do menu (o menu tem 280px; folga de 20px)
    document.addEventListener('mousemove', (ev) => {
      if (!desktop.matches) return;
      const aberto = document.body.classList.contains('menu-aberto');
      if (!aberto && ev.clientX <= 8) alternar(true);
      else if (aberto && ev.clientX > 300) alternar(false);
    });
    // Sair do menu por qualquer lado (inclusive para fora da janela) recolhe
    menu.addEventListener('mouseleave', () => {
      if (desktop.matches) alternar(false);
    });
    // Clique fora do menu recolhe (qualquer tamanho de tela)
    document.addEventListener('click', (ev) => {
      if (document.body.classList.contains('menu-aberto')
        && !ev.target.closest('#menu-lateral') && !ev.target.closest('#btn-menu')) {
        alternar(false);
      }
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') alternar(false);
    });
  },

  montarSeletores() {
    const selCiclo = document.getElementById('sel-ciclo');
    selCiclo.innerHTML = this.sessao.ciclos
      .map((c) => `<option value="${c.id}">${Modal.esc(c.nome)} (base ${c.ano_base})</option>`)
      .join('');

    const selNegocio = document.getElementById('sel-negocio');
    const opcoes = [];
    if (this.sessao.veTudo) opcoes.push('<option value="CORP">Corporativo</option>');
    for (const n of this.sessao.negocios) {
      opcoes.push(`<option value="${n.id}">${Modal.esc(n.rotulo)}</option>`);
    }
    selNegocio.innerHTML = opcoes.join('') || '<option value="">(nenhum negócio vinculado)</option>';

    const atualizar = () => {
      this.contexto.cicloId = parseInt(selCiclo.value, 10) || null;
      this.contexto.corporativo = selNegocio.value === 'CORP';
      this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
      this.atualizarTopbar();
      this.recarregarSecaoAtiva();
    };
    selCiclo.addEventListener('change', atualizar);
    selNegocio.addEventListener('change', atualizar);
    this.contexto.cicloId = parseInt(selCiclo.value, 10) || null;
    this.contexto.corporativo = selNegocio.value === 'CORP';
    this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
    this.atualizarTopbar();
  },

  // Com o menu recolhido, a barra superior mostra o contexto selecionado
  atualizarTopbar() {
    const alvo = document.getElementById('topbar-contexto');
    if (alvo) alvo.textContent = this.rotuloContexto();
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
      coleta: SecaoColeta,
      cenario: SecaoCenario, pestel: SecaoPestel, porter: SecaoPorter,
      swot: SecaoSwot, gut: SecaoGut, cascata: SecaoCascata,
      projetos: SecaoProjetos, investimentos: SecaoInvestimentos,
      metas: SecaoMetas, relatorio: SecaoRelatorio,
    };
    const secao = secoes[this.secaoAtiva];
    if (secao) secao.carregar().catch((e) => {
      // A mensagem vai como TEXTO: várias respostas de erro do servidor
      // devolvem entrada do usuário dentro delas, e interpolar isso em HTML
      // seria o único ponto do front fora da disciplina de escape
      const alvo = document.getElementById(`secao-${this.secaoAtiva}`);
      alvo.innerHTML = '';
      const aviso = document.createElement('div');
      aviso.className = 'alert alert-danger';
      aviso.textContent = e.message;
      alvo.appendChild(aviso);
    });
  },
};

App.iniciar();

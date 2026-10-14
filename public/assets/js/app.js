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
    if (!json.ok) {
      // O código vem junto do erro para quem precisa DECIDIR por ele, não só
      // mostrá-lo (a sala do quiz aberta em outra tela vira uma confirmação).
      // Casar por texto da mensagem seria refém da redação.
      const erro = new Error(json.erro || 'Erro inesperado');
      erro.codigo = json.codigo || null;
      erro.status = resp.status;
      throw erro;
    }
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

    // `[data-secao]` sem prefixo: o atalho da engrenagem mora na topbar, fora do
    // #nav-secoes. O seletor pega os dois porque o contrato é o atributo, não o
    // lugar — e ele NÃO alcança o `data-secao-pergunta` do quiz, que é outro
    // nome de atributo (seletor de atributo casa por nome exato).
    document.querySelectorAll('[data-secao]').forEach((el) => {
      el.addEventListener('click', (ev) => {
        ev.preventDefault();
        this.mostrarSecao(el.dataset.secao);
      });
    });

    this.iniciarMenu();
    this.mostrarSecao(this.secaoInicial());
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

    // Tópicos recolhíveis. O que a pessoa abriu fica guardado no navegador
    // (`menu.grupos`), para o menu não voltar todo fechado a cada recarga; o
    // tópico da tela ativa é aberto por `mostrarSecao`, sempre — o item
    // aceso nunca pode estar escondido dentro de um tópico fechado.
    menu.querySelectorAll('.cabecalho-grupo').forEach((cab) => {
      cab.addEventListener('click', () => {
        const grupo = cab.closest('.grupo-menu');
        this.abrirGrupo(grupo, !grupo.classList.contains('aberto'));
        this.guardarGrupos();
      });
    });
    // Só um tópico fica guardado; um navegador com a lista antiga (vários
    // abertos) recupera o primeiro dela.
    let guardados = [];
    try { guardados = JSON.parse(localStorage.getItem('menu.grupos') || '[]'); } catch { /* sem storage */ }
    const lembrado = Array.isArray(guardados) ? guardados[0] : null;
    menu.querySelectorAll('.grupo-menu').forEach((g) =>
      this.abrirGrupo(g, !!lembrado && g.dataset.grupo === lembrado));
    // Só o negócio: o ciclo saiu do menu e agora é escolhido em Cadastros.
    document.getElementById('sel-negocio').addEventListener('change', () => alternar(false));

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

  // Um tópico por vez (pedido do cliente, 2026-09-02): abrir um fecha os
  // outros, como uma sanfona. Quem abre um segundo tópico está indo para lá,
  // e o primeiro só empurraria a lista para baixo da dobra do celular.
  abrirGrupo(grupo, aberto) {
    if (aberto) {
      grupo.parentElement.querySelectorAll('.grupo-menu.aberto').forEach((outro) => {
        if (outro !== grupo) this.abrirGrupo(outro, false);
      });
    }
    grupo.classList.toggle('aberto', aberto);
    grupo.querySelector('.cabecalho-grupo').setAttribute('aria-expanded', String(aberto));
  },

  guardarGrupos() {
    const abertos = [...document.querySelectorAll('#nav-secoes .grupo-menu.aberto')]
      .map((g) => g.dataset.grupo);
    try { localStorage.setItem('menu.grupos', JSON.stringify(abertos)); } catch { /* sem storage */ }
  },

  /**
   * Onde a pessoa estava: a tela e o negócio (pedido do cliente, 2026-10-14).
   *
   * Recarregar a página — F5, a sessão expirando e voltando, o navegador
   * restaurando a aba — devolvia todo mundo ao Painel e ao primeiro negócio da
   * lista. Quem trabalha o dia inteiro dentro de um negócio refazia dois
   * gestos a cada recarga, e quem recarregava para ver dado novo perdia
   * justamente a tela que estava olhando.
   *
   * Mora no `localStorage`, como a preferência do menu: é de quem está usando
   * aquele navegador, não dado do planejamento. O ciclo fica de fora de
   * propósito — ele é escolha de Cadastros, feita uma vez por ano, e não foi
   * pedido.
   */
  LUGAR_SECAO: 'pe_secao',
  LUGAR_NEGOCIO: 'pe_negocio',

  guardarLugar(chave, valor) {
    try { localStorage.setItem(chave, valor); } catch { /* sem armazenamento */ }
  },

  lugarGuardado(chave) {
    // Janela anônima ou site bloqueado: segue sem memória, com o padrão.
    try { return localStorage.getItem(chave); } catch { return null; }
  },

  /**
   * A seção com que o sistema abre.
   *
   * A validação não é zelo: `mostrarSecao` acende o item do menu
   * (`link.classList.add`) e **estoura** se o nome não existir ali. Nome
   * gravado por uma versão anterior, seção removida desde então ou tela que
   * este perfil não tem no menu derrubariam o sistema no arranque — a pior
   * hora possível, porque não há tela para mostrar o erro. Por isso só se
   * restaura o que existe no documento E está no menu deste usuário.
   */
  secaoInicial() {
    const salva = this.lugarGuardado(this.LUGAR_SECAO);
    if (salva && document.getElementById(`secao-${salva}`)
      && document.querySelector(`#nav-secoes [data-secao="${salva}"]`)) return salva;
    return 'painel';
  },

  montarSeletores() {
    // O ciclo já não é um seletor do menu: quem o troca é a aba Cadastros ›
    // Ciclos & Horizontes, por `App.trocarCiclo`. Aqui só se define o inicial —
    // o primeiro da lista, como o `selected` implícito do <select> fazia.
    this.contexto.cicloId = this.contexto.cicloId || (this.sessao.ciclos[0]?.id ?? null);
    this.mostrarCicloAtual();

    const selNegocio = document.getElementById('sel-negocio');
    const opcoes = [];
    if (this.sessao.veTudo) opcoes.push('<option value="CORP">Corporativo</option>');
    for (const n of this.sessao.negocios) {
      opcoes.push(`<option value="${n.id}">${Modal.esc(n.rotulo)}</option>`);
    }
    selNegocio.innerHTML = opcoes.join('') || '<option value="">(nenhum negócio vinculado)</option>';

    // Repõe o negócio em que a pessoa estava — e só se ele ainda for UM DOS
    // DELA. O vínculo usuário×negócio muda, e o navegador é compartilhado: sem
    // conferir contra as opções recém-montadas, a tela abriria num negócio que
    // este usuário não enxerga mais (ou nunca enxergou), com as seções pedindo
    // dado que a API vai recusar. Não casando, vale o padrão de sempre — a
    // primeira opção da lista.
    const salvo = this.lugarGuardado(this.LUGAR_NEGOCIO);
    if (salvo && [...selNegocio.options].some((o) => o.value === salvo)) selNegocio.value = salvo;

    const atualizar = () => {
      this.contexto.corporativo = selNegocio.value === 'CORP';
      this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
      this.guardarLugar(this.LUGAR_NEGOCIO, selNegocio.value);
      this.atualizarTopbar();
      this.recarregarSecaoAtiva();
    };
    selNegocio.addEventListener('change', atualizar);
    this.contexto.corporativo = selNegocio.value === 'CORP';
    this.contexto.negocioId = this.contexto.corporativo ? null : parseInt(selNegocio.value, 10) || null;
    this.atualizarTopbar();
  },

  /** Rótulo do ciclo em uso, o mesmo texto no menu e na aba de Cadastros. */
  rotuloCiclo() {
    const c = (this.sessao.ciclos || []).find((x) => x.id === this.contexto.cicloId);
    return c ? `${c.nome} (base ${c.ano_base})` : '(nenhum ciclo)';
  },

  /**
   * No menu vai só o NOME do ciclo; o ano-base fica no `title`.
   * Numa linha só com o rótulo e o ⚙, "2027–2035 (base 2026)" era cortado
   * justamente no ano-base — e meia informação engana mais do que informação
   * nenhuma. O ano-base é detalhe de cadastro: quem precisa dele está na aba
   * onde ele é editado, e ali o rótulo aparece inteiro.
   */
  mostrarCicloAtual() {
    const el = document.getElementById('ciclo-atual');
    if (!el) return;
    const c = (this.sessao.ciclos || []).find((x) => x.id === this.contexto.cicloId);
    el.textContent = c ? c.nome : '(nenhum ciclo)';
    el.title = this.rotuloCiclo();
  },

  /**
   * Troca o ciclo em uso. Mora aqui, e não na seção, porque o ciclo é contexto
   * de TODAS as telas: quem o escolhe é a aba de Cadastros, mas quem o guarda
   * (e repinta o menu, a topbar e a seção aberta) continua sendo o núcleo.
   */
  trocarCiclo(id) {
    const novo = parseInt(id, 10) || null;
    if (!novo || novo === this.contexto.cicloId) return;
    this.contexto.cicloId = novo;
    this.mostrarCicloAtual();
    this.atualizarTopbar();
    this.recarregarSecaoAtiva();
  },

  // Com o menu recolhido, a barra superior mostra o contexto selecionado
  atualizarTopbar() {
    const alvo = document.getElementById('topbar-contexto');
    if (alvo) alvo.textContent = this.rotuloContexto();
  },

  secaoAtiva: null,

  /**
   * Modo "só desenho": o Dossiê está pintando uma seção DE LADO, para tirar a
   * foto do papel dela, e não para alguém olhar.
   *
   * Quem lê esta bandeira são os relógios de consulta periódica. Eles já param
   * sozinhos quando a seção está com `d-none` — mas só na primeira batida, 4
   * segundos depois: montar um dossiê de onze negócios armaria dezenas de
   * temporizadores para eles se desarmarem um a um, cada um custando uma
   * chamada à API pelo caminho. Aqui eles nem chegam a nascer.
   *
   * É bandeira, e não um parâmetro de `carregar()`, porque quem precisa dela
   * está três chamadas abaixo (`QuizSala.armarRelogio`) e passá-la de mão em
   * mão obrigaria a mudar a assinatura de toda seção — inclusive das que não
   * têm relógio nenhum.
   */
  modoDossie: false,

  mostrarSecao(nome) {
    this.secaoAtiva = nome;
    // A tela fica guardada para a próxima recarga. Aqui, e não em cada item do
    // menu, porque este é o funil por onde TODA navegação passa — inclusive a
    // que vem de um card ("→ SWOT", "→ Plano de ação") e a do atalho da topbar.
    // O dossiê não polui o registro: ele pinta as seções de lado, chamando o
    // `carregar()` de cada uma, sem navegar.
    this.guardarLugar(this.LUGAR_SECAO, nome);
    document.querySelectorAll('.secao').forEach((s) => s.classList.add('d-none'));
    document.querySelectorAll('#nav-secoes .nav-link').forEach((l) => l.classList.remove('active'));
    document.getElementById(`secao-${nome}`).classList.remove('d-none');
    const link = document.querySelector(`#nav-secoes [data-secao="${nome}"]`);
    link.classList.add('active');
    // O tópico da tela ativa abre sozinho (e fica guardado): item aceso
    // escondido dentro de um tópico fechado é o mesmo que menu sem item ativo.
    const grupo = link.closest('.grupo-menu');
    if (grupo && !grupo.classList.contains('aberto')) {
      this.abrirGrupo(grupo, true);
      this.guardarGrupos();
    }
    // O atalho da topbar não vira `.active` (ele não é item de lista): quem diz
    // que a tela é a dele é o `aria-current`, que o leitor de tela anuncia e o
    // CSS usa para acender o botão.
    const atalho = document.getElementById('btn-cadastros');
    if (nome === 'cadastros') atalho.setAttribute('aria-current', 'page');
    else atalho.removeAttribute('aria-current');
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
  // O plano do contexto, como a última resolução o devolveu. Serve ao relógio
  // do "duas telas juntas", que precisa do id DEPOIS da pintura e não pode
  // gastar mais uma ida ao servidor para descobri-lo.
  planAtual: null,

  async planejamento() {
    const params = this.contextoParams();
    if (!params) {
      this.planAtual = null;
      return null;
    }
    this.planAtual = (await this.api(`/api/contexto?${params}`)).planejamento;
    return this.planAtual;
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
      swot: SecaoSwot, gut: SecaoGut, impacto: SecaoImpacto, cruzamentos: SecaoCruzamentos,
      cascata: SecaoCascata,
      projetos: SecaoProjetos, investimentos: SecaoInvestimentos,
      metas: SecaoMetas, relatorio: SecaoRelatorio, dossie: SecaoDossie,
      sala: SecaoSala,
    };
    const nome = this.secaoAtiva;
    const secao = secoes[nome];
    if (!secao) return;
    // O relógio do "duas telas juntas" é armado AQUI, e não dentro de cada
    // `carregar()`: é um lugar só, por onde toda seção passa, e nenhuma precisa
    // lembrar de armar o seu. E é DEPOIS de a pintura terminar — armar antes
    // capturaria a versão de referência com a tela ainda lendo o conteúdo, e
    // uma escrita nesse intervalo passaria por "já vista".
    secao.carregar().then(() => {
      if (this.secaoAtiva !== nome) return; // navegou enquanto carregava
      Vivo.armar(`secao-${nome}`, this.planosDaSecao(secao));
    }).catch((e) => {
      Vivo.parar();
      // A mensagem vai como TEXTO: várias respostas de erro do servidor
      // devolvem entrada do usuário dentro delas, e interpolar isso em HTML
      // seria o único ponto do front fora da disciplina de escape
      const alvo = document.getElementById(`secao-${nome}`);
      alvo.innerHTML = '';
      const aviso = document.createElement('div');
      aviso.className = 'alert alert-danger';
      aviso.textContent = e.message;
      alvo.appendChild(aviso);
    });
  },

  /**
   * De quais planejamentos uma seção depende — a lista que o relógio vigia.
   *
   * O padrão é o plano do contexto, que serve a quase todas. Quem depende de
   * outro declara `planosVigiados()` em si mesma: a Matriz de Impacto é lida no
   * contexto de um NEGÓCIO e o conteúdo dela vive no plano CORPORATIVO, e é ela
   * quem sabe disso — não uma lista de exceções aqui, que envelheceria calada.
   *
   * `coleta` e `sala` devolvem lista vazia: as duas já têm relógio próprio, com
   * as regras da oficina (voto aberto, pergunta ativa, ficha sendo arrastada).
   * Um segundo relógio ali repintaria por cima do primeiro.
   */
  planosDaSecao(secao) {
    if (typeof secao.planosVigiados === 'function') return secao.planosVigiados();
    return [this.planAtual?.id].filter(Boolean);
  },
};

App.iniciar();

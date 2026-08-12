// Cadastros base — abas: Negócios, Ciclos & Horizontes, Drivers, Eixos, Usuários.
// Toda inclusão/edição abre o formulário modal (nunca troca de página).

const SecaoCadastros = {
  abaAtiva: 'negocios',

  /**
   * Pinturas SERIALIZADAS.
   *
   * Trocar de aba enquanto a anterior ainda carregava quebrava a seção inteira:
   * cada renderizador busca a lista dele (`await App.api`) e só então escreve no
   * `#conteudo-aba` e liga os botões. Voltando do await depois da troca, ele
   * escrevia no conteúdo que já era de OUTRA aba e procurava um botão que não
   * existe mais — `null.addEventListener`, e a tela virava alerta vermelho.
   * Enfileirar é o conserto certo: a próxima pintura só começa quando a
   * anterior termina, então a última pedida é a que fica. Descartar a pintura
   * velha por geração também resolveria o erro, mas deixaria a aba antiga
   * meio-pintada na tela no meio do caminho.
   */
  async carregar() {
    this.fila = Promise.resolve(this.fila).catch(() => {}).then(() => this.pintar());
    return this.fila;
  },

  async pintar() {
    const el = document.getElementById('secao-cadastros');
    const administra = ['ADMIN', 'CONTROLADORIA'].includes(App.sessao.usuario.perfil);
    const abas = [
      ['negocios', 'Negócios'],
      ['ciclos', 'Ciclos & Horizontes'],
      ['drivers', 'Drivers'],
      ['eixos', 'Eixos'],
    ];
    if (administra) abas.push(['usuarios', 'Usuários']);

    el.innerHTML = `
      <h1>Cadastros</h1>
      <ul class="nav nav-tabs mt-3" id="abas-cadastro">
        ${abas.map(([id, rotulo]) =>
          `<li class="nav-item"><a class="nav-link ${id === this.abaAtiva ? 'active' : ''}" href="#" data-aba="${id}">${rotulo}</a></li>`
        ).join('')}
      </ul>
      <div id="conteudo-aba" class="pt-3"></div>`;

    el.querySelectorAll('[data-aba]').forEach((a) => {
      a.addEventListener('click', (ev) => {
        ev.preventDefault();
        this.abaAtiva = a.dataset.aba;
        this.carregar();
      });
    });

    const renderizadores = {
      negocios: () => this.negocios(administra),
      ciclos: () => this.ciclos(administra),
      drivers: () => this.driversEixos('drivers', 'Driver (linha base)', administra),
      eixos: () => this.driversEixos('eixos', 'Eixo (abertura)', administra),
      usuarios: () => this.usuarios(),
    };
    await renderizadores[this.abaAtiva]();
  },

  // ---- Negócios ----
  async negocios(administra) {
    const lista = await App.api('/api/negocios');
    const alvo = document.getElementById('conteudo-aba');
    const linhas = lista.map((n) => `
      <tr class="${n.ativo == 1 ? '' : 'table-secondary'}">
        <td>
          <strong>${Modal.esc(n.cod_negocio)}</strong> — ${Modal.esc(n.nome)}
          ${(n.gestores || []).map((g) =>
            `<div class="small text-muted gestor-negocio">${Modal.esc(g)}</div>`).join('')}
        </td>
        <td>${n.ativo == 1 ? 'Ativo' : 'Inativo'}</td>
        <td class="text-nowrap">${administra ? `<button class="btn btn-sm btn-outline-secondary" data-editar="${n.id}"
          title="Editar" aria-label="Editar">✎</button>
          ${Number(n.excluivel) ? `<button class="btn btn-sm btn-outline-danger ms-1"
            data-excluir="${n.id}" title="Excluir do cadastro (ainda sem uso em nenhum cadastro)"
            aria-label="Excluir ${Modal.esc(n.nome)} do cadastro">✕</button>` : ''}` : ''}</td>
      </tr>`).join('');

    alvo.innerHTML = `
      ${administra ? `<button class="btn btn-verde btn-sm mb-2" id="btn-novo-negocio">+ Novo negócio</button>` : ''}
      <div class="table-responsive">
        <table class="table table-sm tabela-cadastro">
          <thead><tr><th>Negócio · gestores</th><th>Situação</th><th></th></tr></thead>
          <tbody>${linhas || '<tr><td colspan="3" class="text-muted">Nenhum negócio cadastrado.</td></tr>'}</tbody>
        </table>
      </div>`;

    if (!administra) return;
    const usuarios = await App.api('/api/usuarios').catch(() => []);
    const opcoesGestor = [{ valor: '', rotulo: '(sem gestor definido)' }]
      .concat(usuarios.map((u) => ({ valor: u.id, rotulo: u.nome })));

    const abrirModal = (n = null) => Modal.abrir({
      titulo: n ? `Editar negócio ${n.cod_negocio} - ${n.nome}` : 'Novo negócio',
      url: n ? `/api/negocios/${n.id}` : '/api/negocios',
      valores: n ? { ...n, gestor_id: n.gestor_id ?? '', ativo: n.ativo == 1 } : { ativo: true },
      campos: [
        { nome: 'cod_negocio', rotulo: 'Cód. Negócio', ajuda: 'Código do ERP (ex.: 8)' },
        { nome: 'nome', rotulo: 'Negócio', ajuda: 'Exibição nas seleções: "8 - Agropecuária"' },
        { nome: 'gestor_id', rotulo: 'Gestor responsável', tipo: 'select', opcoes: opcoesGestor },
        { nome: 'ativo', rotulo: 'Ativo', tipo: 'checkbox' },
      ],
    });

    document.getElementById('btn-novo-negocio').addEventListener('click', () => abrirModal());
    alvo.querySelectorAll('[data-editar]').forEach((b) => {
      b.addEventListener('click', () => abrirModal(lista.find((n) => n.id == b.dataset.editar)));
    });
    // Excluir só aparece em negócio ainda SEM uso em cadastro nenhum (sem
    // planejamento, sem escopo de usuário e fora da lista oficial) — quem já
    // foi atribuído em algum lugar só se desativa. O `excluivel` vem do
    // servidor, que é quem sabe, e o excluir() reconfere as mesmas guardas.
    alvo.querySelectorAll('[data-excluir]').forEach((b) => {
      b.addEventListener('click', async () => {
        const n = lista.find((x) => x.id == b.dataset.excluir);
        if (!confirm(`Excluir «${n.cod_negocio} — ${n.nome}» do cadastro?\n\n`
          + 'Ele ainda não foi usado em nenhum cadastro; a linha some da lista. '
          + 'Não há desfazer.')) return;
        try {
          await App.api(`/api/negocios/${n.id}/excluir`, { confirmar: true });
        } catch (e) {
          alert(e.message);
        }
        this.carregar();
      });
    });
  },

  // ---- Ciclos & Horizontes ----
  async ciclos(administra) {
    const lista = await App.api('/api/ciclos');
    const alvo = document.getElementById('conteudo-aba');
    const emUso = App.contexto.cicloId;

    const blocos = lista.map((c) => {
      // Cada horizonte é um card: período e tema em destaque, objetivo abaixo
      const horizontes = c.horizontes.map((h) => `
        <div class="col-12 col-md-4">
          <div class="card cartao-horizonte h-100"><div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge badge-horizonte">${Modal.esc(h.nome)}</span>
              <strong class="small">${h.ano_inicio}–${h.ano_fim}</strong>
              ${administra ? `<button class="btn btn-sm btn-outline-secondary ms-auto" data-editar-h="${h.id}"
                data-ciclo="${c.id}" title="Editar horizonte" aria-label="Editar horizonte">✎</button>` : ''}
            </div>
            <div class="fw-bold small text-uppercase">${Modal.esc(h.tema)}</div>
            <div class="small text-muted texto-fator mt-1">${Modal.esc(h.objetivo)}</div>
          </div></div>
        </div>`).join('');
      return `<div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <strong>${Modal.esc(c.nome)}</strong>
            ${c.id === emUso ? '<span class="badge text-bg-success ms-1">em uso</span>' : ''}
            <span class="text-muted small">· ano do planejamento: ${c.ano_base} · ${c.status}</span>
          </div>
          <div class="d-flex gap-2">
            ${administra ? `<button class="btn btn-sm btn-outline-secondary" data-editar-c="${c.id}">Editar ciclo</button>
            <button class="btn btn-sm btn-verde" data-novo-h="${c.id}">+ Horizonte</button>` : ''}
          </div>
        </div>
        <div class="row g-2 mt-1">
          ${horizontes || '<div class="text-muted small">Nenhum horizonte.</div>'}
        </div>
      </div></div>`;
    }).join('');

    // O ciclo EM USO é escolhido aqui — saiu do menu lateral, onde dividia
    // espaço com o negócio e convidava ao mesmo gesto: o negócio troca o dia
    // inteiro, o ciclo troca uma vez por ano. O menu passou a só MOSTRAR qual
    // é. Quem guarda a escolha continua sendo o núcleo (`App.trocarCiclo`),
    // porque o ciclo é contexto de todas as telas, não estado desta seção.
    // O seletor aparece mesmo para quem não administra: escolher em qual ciclo
    // se está trabalhando é leitura, não cadastro.
    alvo.innerHTML = `
      <div class="card mb-3"><div class="card-body py-2 px-3 d-flex align-items-center gap-2 flex-wrap">
        <label class="form-label mb-0 small fw-bold" for="sel-ciclo-uso">Ciclo em uso</label>
        <select id="sel-ciclo-uso" class="form-select form-select-sm w-auto">
          ${lista.map((c) => `<option value="${c.id}"${c.id === emUso ? ' selected' : ''}>${Modal.esc(c.nome)} (base ${c.ano_base})</option>`).join('')}
        </select>
        <span class="text-muted small">vale para todas as telas do sistema</span>
      </div></div>
      ${administra ? '<button class="btn btn-verde btn-sm mb-2" id="btn-novo-ciclo">+ Novo ciclo</button>' : ''}
      ${blocos || '<div class="text-muted">Nenhum ciclo cadastrado.</div>'}`;

    // `trocarCiclo` repinta a seção ativa — que é esta —, então o listener é
    // religado pelo próprio redesenho; nada a desfazer aqui.
    alvo.querySelector('#sel-ciclo-uso')?.addEventListener('change', (ev) => {
      App.trocarCiclo(ev.target.value);
    });

    Diag.ligarVerMais(alvo);
    if (!administra) return;

    const modalCiclo = (c = null) => Modal.abrir({
      titulo: c ? `Editar ciclo ${c.nome}` : 'Novo ciclo',
      url: c ? `/api/ciclos/${c.id}` : '/api/ciclos',
      valores: c || {},
      campos: [
        { nome: 'nome', rotulo: 'Nome do ciclo', ajuda: 'Ex.: 2027–2035' },
        { nome: 'ano_base', rotulo: 'Ano do planejamento (elaboração)', tipo: 'number',
          ajuda: 'Ano em que o plano é elaborado — primeiro ano disponível nas análises anuais e na tabela de metas' },
        { nome: 'ano_inicio', rotulo: 'Ano inicial do ciclo', tipo: 'number' },
        { nome: 'ano_fim', rotulo: 'Ano final do ciclo', tipo: 'number' },
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes: [
          { valor: 'EM_ELABORACAO', rotulo: 'Em elaboração' },
          { valor: 'VIGENTE', rotulo: 'Vigente' },
          { valor: 'ENCERRADO', rotulo: 'Encerrado' },
        ]},
      ],
    });

    const modalHorizonte = (cicloId, h = null) => Modal.abrir({
      titulo: h ? `Editar horizonte ${h.nome}` : 'Novo horizonte',
      url: h ? `/api/horizontes/${h.id}` : '/api/horizontes',
      valores: h ? { ...h } : { ciclo_id: cicloId },
      campos: [
        { nome: 'ciclo_id', rotulo: 'Ciclo', tipo: 'select',
          opcoes: lista.map((c) => ({ valor: c.id, rotulo: c.nome })) },
        { nome: 'nome', rotulo: 'Nome', ajuda: 'Ex.: H1' },
        { nome: 'ano_inicio', rotulo: 'Ano inicial', tipo: 'number' },
        { nome: 'ano_fim', rotulo: 'Ano final', tipo: 'number' },
        { nome: 'tema', rotulo: 'Tema', ajuda: 'Ex.: Recuperação' },
        { nome: 'objetivo', rotulo: 'Objetivo do horizonte', tipo: 'textarea' },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: 0 },
      ],
    });

    document.getElementById('btn-novo-ciclo').addEventListener('click', () => modalCiclo());
    alvo.querySelectorAll('[data-editar-c]').forEach((b) => {
      b.addEventListener('click', () => modalCiclo(lista.find((c) => c.id == b.dataset.editarC)));
    });
    alvo.querySelectorAll('[data-novo-h]').forEach((b) => {
      b.addEventListener('click', () => modalHorizonte(parseInt(b.dataset.novoH, 10)));
    });
    alvo.querySelectorAll('[data-editar-h]').forEach((b) => {
      const ciclo = lista.find((c) => c.id == b.dataset.ciclo);
      b.addEventListener('click', () =>
        modalHorizonte(ciclo.id, ciclo.horizontes.find((h) => h.id == b.dataset.editarH)));
    });
  },

  // ---- Drivers e Eixos ----
  async driversEixos(tipo, rotulo, administra) {
    const lista = await App.api(`/api/${tipo}`);
    const alvo = document.getElementById('conteudo-aba');
    alvo.innerHTML = `
      ${administra ? `<button class="btn btn-verde btn-sm mb-2" id="btn-novo-item">+ Novo</button>
        <p class="text-muted small mb-2">Arraste pelo ⠿ para reordenar a prioridade — a nova ordem é salva na hora.</p>` : ''}
      <div class="table-responsive">
        <table class="table table-sm tabela-cadastro">
          <thead><tr>${administra ? '<th></th>' : ''}<th title="Ordem">Nº</th><th>${rotulo}</th><th>Situação</th><th></th></tr></thead>
          <tbody id="linhas-ordenaveis">${lista.map((i) => `
            <tr data-id="${i.id}" class="${i.ativo == 1 ? '' : 'table-secondary'}">
              ${administra ? `<td class="celula-alca"><button type="button" class="alca-arrastar"
                title="Arrastar para reordenar (ou Ctrl + seta para cima/baixo)"
                aria-label="Reordenar: arraste, ou use Ctrl com as setas para cima e para baixo"
                >⠿</button></td>` : ''}
              <td class="celula-ordem">${i.ordem}</td>
              <td>${Modal.esc(i.nome)}</td>
              <td>${i.ativo == 1 ? 'Ativo' : 'Inativo'}</td>
              <td>${administra ? `<button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}"
                title="Editar" aria-label="Editar">✎</button>` : ''}</td>
            </tr>`).join('')}</tbody>
        </table>
      </div>`;

    if (!administra) return;
    this.ligarArrastar(document.getElementById('linhas-ordenaveis'), async (ids) => {
      await App.api(`/api/${tipo}/reordenar`, { ids });
    });

    const abrirModal = (i = null) => Modal.abrir({
      titulo: i ? `Editar ${rotulo.toLowerCase()}` : `Novo ${rotulo.toLowerCase()}`,
      url: i ? `/api/${tipo}/${i.id}` : `/api/${tipo}`,
      valores: i ? { ...i, ativo: i.ativo == 1 } : { ativo: true },
      campos: [
        { nome: 'nome', rotulo },
        { nome: 'ativo', rotulo: 'Ativo', tipo: 'checkbox' },
      ],
    });
    document.getElementById('btn-novo-item').addEventListener('click', () => abrirModal());
    alvo.querySelectorAll('[data-editar]').forEach((b) => {
      b.addEventListener('click', () => abrirModal(lista.find((i) => i.id == b.dataset.editar)));
    });
  },

  // Arrastar-e-soltar das linhas (mouse e toque): segure a alça ⠿ e mova; ao
  // soltar, a ordem das linhas vira a prioridade e é gravada via aoSoltar
  ligarArrastar(tbody, aoSoltar) {
    tbody.querySelectorAll('.alca-arrastar').forEach((alca) => {
      alca.addEventListener('pointerdown', (ev) => {
        ev.preventDefault();
        const linha = alca.closest('tr');
        linha.classList.add('linha-arrastando');
        // Listeners no document: mover a linha no DOM cancelaria a captura de
        // ponteiro da alça e o arrasto morreria no primeiro reposicionamento
        const mover = (e) => {
          const sob = document.elementFromPoint(e.clientX, e.clientY)?.closest('tr');
          if (!sob || sob === linha || sob.parentElement !== tbody) return;
          const r = sob.getBoundingClientRect();
          if (e.clientY < r.top + r.height / 2) tbody.insertBefore(linha, sob);
          else tbody.insertBefore(linha, sob.nextSibling);
        };
        const soltar = async () => {
          document.removeEventListener('pointermove', mover);
          document.removeEventListener('pointerup', soltar);
          document.removeEventListener('pointercancel', soltar);
          linha.classList.remove('linha-arrastando');
          const linhas = [...tbody.querySelectorAll('tr[data-id]')];
          try {
            await aoSoltar(linhas.map((t) => Number(t.dataset.id)));
            linhas.forEach((t, i) => {
              const cel = t.querySelector('.celula-ordem');
              if (cel) cel.textContent = i + 1;
            });
          } catch (e) {
            alert(e.message);
          }
        };
        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar);
        document.addEventListener('pointercancel', soltar);
      });

      // Alternativa por teclado: a alça é um <button> focável, mas só tinha
      // handler de ponteiro — sem mouse ou toque não havia como reordenar.
      // Ctrl/Alt + setas move a linha; sozinhas, as setas continuam rolando.
      alca.addEventListener('keydown', async (ev) => {
        const paraCima = ev.key === 'ArrowUp';
        const paraBaixo = ev.key === 'ArrowDown';
        if ((!paraCima && !paraBaixo) || !(ev.ctrlKey || ev.altKey)) return;
        ev.preventDefault();
        const linha = alca.closest('tr');
        const vizinho = paraCima ? linha.previousElementSibling : linha.nextElementSibling;
        if (!vizinho) return;
        if (paraCima) tbody.insertBefore(linha, vizinho);
        else tbody.insertBefore(vizinho, linha);
        const linhas = [...tbody.querySelectorAll('tr[data-id]')];
        try {
          await aoSoltar(linhas.map((t) => Number(t.dataset.id)));
          linhas.forEach((t, i) => {
            const cel = t.querySelector('.celula-ordem');
            if (cel) cel.textContent = i + 1;
          });
        } catch (e) {
          alert(e.message);
        }
        alca.focus(); // o foco acompanha a linha que se moveu
      });
    });
  },

  // ---- Usuários ----
  async usuarios() {
    const [lista, negocios] = await Promise.all([
      App.api('/api/usuarios'),
      App.api('/api/negocios'),
    ]);
    const alvo = document.getElementById('conteudo-aba');
    const nomeNegocio = (id) => negocios.find((n) => n.id == id)?.rotulo || id;

    const PERFIS = {
      ADMIN: ['Admin', '#06432a'],
      CONTROLADORIA: ['Controladoria', '#007a45'],
      DIRECAO: ['Direção', '#b08d4f'],
      GESTOR: ['Gestor', '#2c7fb8'],
      LEITURA: ['Leitura', '#6c757d'],
    };
    const cartoes = lista.map((u) => {
      const [rotuloPerfil, cor] = PERFIS[u.perfil] || [u.perfil, '#6c757d'];
      const negociosTxt = ['ADMIN', 'CONTROLADORIA', 'DIRECAO'].includes(u.perfil)
        ? '<em>todos os negócios</em>'
        : (u.negocios.map(nomeNegocio).map(Modal.esc).join(', ') || '—');
      return `<div class="col-12 col-md-6 col-xl-4" data-busca="${Modal.esc(`${u.nome} ${u.email}`.toLowerCase())}">
        <div class="card h-100 cartao-usuario ${u.ativo == 1 ? '' : 'cartao-inativo'}">
          <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-2">
              <strong class="small">${Modal.esc(u.nome)}</strong>
              ${u.ativo == 1 ? '' : '<span class="badge text-bg-secondary">Inativo</span>'}
              <button class="btn btn-sm btn-outline-secondary ms-auto" data-editar="${u.id}"
                title="Editar" aria-label="Editar">✎</button>
              ${Number(u.excluivel) ? `<button class="btn btn-sm btn-outline-danger" data-excluir="${u.id}"
                title="Excluir do cadastro" aria-label="Excluir ${Modal.esc(u.nome)}">✕</button>` : ''}
            </div>
            <div class="small text-muted">${Modal.esc(u.email)}</div>
            <div class="d-flex align-items-center gap-2 mt-1">
              <span class="badge" style="background:${cor}">${rotuloPerfil}</span>
              <span class="small text-muted">${negociosTxt}</span>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');

    alvo.innerHTML = `
      <button class="btn btn-verde btn-sm mb-2" id="btn-novo-usuario">+ Novo usuário</button>
      ${lista.length > 5 ? `<input type="search" id="busca-usuario" class="form-control mb-2"
        placeholder="Pesquisar usuário por nome ou e-mail..." autocomplete="off">` : ''}
      <div class="row g-2" id="lista-usuarios">
        ${cartoes || '<div class="text-muted">Nenhum usuário cadastrado.</div>'}
      </div>`;

    const abrirModal = (u = null) => Modal.abrir({
      titulo: u ? `Editar usuário ${u.nome}` : 'Novo usuário',
      url: u ? `/api/usuarios/${u.id}` : '/api/usuarios',
      valores: u ? { ...u, ativo: u.ativo == 1 } : { ativo: true, perfil: 'GESTOR' },
      campos: [
        { nome: 'nome', rotulo: 'Nome' },
        { nome: 'email', rotulo: 'E-mail', tipo: 'email' },
        { nome: 'senha', rotulo: u ? 'Nova senha (deixe vazio para manter)' : 'Senha inicial', tipo: 'password' },
        { nome: 'perfil', rotulo: 'Perfil', tipo: 'select', opcoes: [
          { valor: 'ADMIN', rotulo: 'Admin' },
          { valor: 'CONTROLADORIA', rotulo: 'Controladoria (vê tudo)' },
          { valor: 'DIRECAO', rotulo: 'Direção (vê tudo)' },
          { valor: 'GESTOR', rotulo: 'Gestor de negócio' },
          { valor: 'LEITURA', rotulo: 'Somente leitura' },
        ]},
        { nome: 'negocios', rotulo: 'Negócios vinculados (Gestor/Leitura)', tipo: 'lista_marcavel',
          opcoes: negocios.map((n) => ({ valor: n.id, texto: n.rotulo })),
          ajuda: 'Marque os negócios que este usuário enxerga.' },
        { nome: 'ativo', rotulo: 'Ativo', tipo: 'checkbox' },
      ],
    });

    document.getElementById('btn-novo-usuario').addEventListener('click', () => abrirModal());
    alvo.querySelectorAll('[data-editar]').forEach((b) => {
      b.addEventListener('click', () => abrirModal(lista.find((u) => u.id == b.dataset.editar)));
    });
    alvo.querySelectorAll('[data-excluir]').forEach((b) => {
      b.addEventListener('click', () => this.excluirUsuario(lista.find((u) => u.id == b.dataset.excluir)));
    });

    // Pesquisa (aparece com mais de 5 usuários): filtra por nome/e-mail ao digitar
    const busca = document.getElementById('busca-usuario');
    busca?.addEventListener('input', () => {
      const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      const q = norm(busca.value.trim());
      document.querySelectorAll('#lista-usuarios [data-busca]').forEach((col) => {
        col.classList.toggle('d-none', q !== '' && !norm(col.dataset.busca).includes(q));
      });
    });
  },

  /**
   * Excluir um usuário — o gesto que precisa PERGUNTAR antes, porque o que ele
   * apaga não é a pessoa, é o vínculo dela com o que ficou para trás.
   *
   * São três telas possíveis, e quem decide qual é o servidor (`/vinculos`),
   * não esta função: ele é que sabe contar. Perguntar ao banco antes de abrir
   * qualquer coisa evita o pior desenho possível aqui — abrir um formulário de
   * transferência para alguém que não tem nada, ou pior, um `confirm()` seco
   * para quem tem trinta ações penduradas.
   *
   * 1. **Não pode** (é você mesmo, ou o último administrador ativo): mostra o
   *    porquê e para. Não há formulário para uma decisão que não existe.
   * 2. **Não tem nada apontando para ela**: um `confirm` basta — não há destino
   *    a escolher, e um modal de duas perguntas em branco só faria pensar que
   *    alguma coisa foi esquecida.
   * 3. **Tem carteira ou autoria**: o formulário. Mostra o que ela segura,
   *    separado pelas duas naturezas, e obriga a escolher entre passar para
   *    alguém ou deixar sem responsável.
   *
   * A contagem aparece ANTES da escolha, e é ela que dá sentido à pergunta:
   * "8 ações do plano" e "1 comentário" pedem decisões diferentes, e sem o
   * número a tela estaria pedindo uma assinatura em branco.
   */
  async excluirUsuario(u) {
    if (!u) return;
    let info;
    try {
      info = await App.api(`/api/usuarios/${u.id}/vinculos`);
    } catch (e) {
      alert(e.message);
      return;
    }

    if (!info.pode_excluir) {
      alert(`Não é possível excluir «${u.nome}».\n\n${info.impedimentos.join('\n\n')}`);
      return;
    }

    if (!info.vinculos.length) {
      if (!confirm(`Excluir «${u.nome}» do cadastro?\n\n`
        + 'Não há nada no sistema apontando para esta pessoa. Não há desfazer.')) return;
      try {
        await App.api(`/api/usuarios/${u.id}/excluir`, { sem_responsavel: true });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
      return;
    }

    // As duas naturezas em caixas de cor diferente, cada uma na sua peça (é o
    // que o `itens` do campo `info` faz). Emendadas num parágrafo só, "3 ações
    // do plano" e "1 ata de reunião" liam-se como uma lista de coisas
    // equivalentes — e não são: a primeira é trabalho que alguém precisa
    // assumir amanhã, a segunda é registro do que já passou.
    const pecas = [];
    const carteira = info.vinculos.filter((v) => v.grupo === 'carteira');
    const autoria = info.vinculos.filter((v) => v.grupo === 'autoria');
    const soma = (vs) => vs.map((v) => `${v.total} ${v.rotulo}`).join(' · ');
    if (carteira.length) {
      pecas.push({ rotulo: 'Trabalho sob responsabilidade dela', texto: soma(carteira),
        cor: '#8f3b3b' });
    }
    if (autoria.length) {
      pecas.push({ rotulo: 'Registros que ela escreveu', texto: soma(autoria), cor: '#5d6b64' });
    }

    Modal.abrir({
      titulo: `Excluir usuário ${u.nome}`,
      url: `/api/usuarios/${u.id}/excluir`,
      salvar: { rotulo: 'Excluir usuário', perigo: true },
      valores: { destino: 'TRANSFERIR' },
      campos: [
        { nome: 'oque_tem', rotulo: 'O que sai do nome desta pessoa', tipo: 'info', texto: '',
          itens: pecas,
          barra: { titulo: `${u.nome} — ${u.email}`, cor: '#8f3b3b' } },
        // A decisão e o que ela revela moram na MESMA caixa: solto, o seletor de
        // quem recebe parecia um campo independente, e trocar para "sem
        // responsável" fazia sumir um bloco sem relação aparente com o clique.
        { nome: 'destino', rotulo: 'Para quem vai tudo isso?', tipo: 'botoes', caixa: 'destino',
          opcoes: [
            { valor: 'TRANSFERIR', rotulo: 'Passar para outra pessoa' },
            { valor: 'SEM_RESPONSAVEL', rotulo: 'Deixar sem responsável' },
          ],
          ajuda: 'Sem responsável, os registros continuam no sistema — as ações ficam '
            + 'marcadas como «Sem usuário» e param de ser cobradas de alguém por e-mail.' },
        { nome: 'transferir_para', rotulo: 'Quem assume', tipo: 'lista_marcavel', unico: true,
          obrigatorio: true, caixa: 'destino', visivelSe: { campo: 'destino', valores: ['TRANSFERIR'] },
          // Nome e e-mail no MESMO texto: o `lista_marcavel` não tem campo de
          // descrição, e o e-mail no `selo2` sairia antes do nome (os selos
          // vêm primeiro no corpo do item). Junto, ele também entra na busca.
          opcoes: info.destinos.map((d) => ({ valor: d.valor, texto: `${d.texto} · ${d.descricao}` })),
          ajuda: 'Só entram pessoas ativas: quem está inativo não receberia as cobranças.' },
      ],
      // O corpo é montado aqui porque a tela tem DOIS campos para uma decisão
      // que o servidor lê como uma: ou vem quem recebe, ou vem a confirmação de
      // que ninguém recebe. Mandar os dois deixaria a rota escolhendo por conta
      // própria qual obedecer.
      enviar: (corpo) => App.api(`/api/usuarios/${u.id}/excluir`, corpo.destino === 'SEM_RESPONSAVEL'
        ? { sem_responsavel: true }
        : { transferir_para: corpo.transferir_para }),
      aoSalvar: (r) => {
        alert(r.transferido
          ? `«${r.nome}» foi excluído. Os registros passaram para ${r.transferido}.`
          : `«${r.nome}» foi excluído. Os registros ficaram sem responsável.`);
        this.carregar();
      },
    });
  },
};

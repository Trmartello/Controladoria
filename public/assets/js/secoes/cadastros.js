// Cadastros base — abas: Negócios, Ciclos & Horizontes, Drivers, Eixos, Usuários.
// Toda inclusão/edição abre o formulário modal (nunca troca de página).

const SecaoCadastros = {
  abaAtiva: 'negocios',

  async carregar() {
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
        <td>${Modal.esc(n.cod_negocio)}</td>
        <td>${Modal.esc(n.nome)}</td>
        <td>${Modal.esc(n.gestor || '—')}</td>
        <td><span class="badge ${n.origem === 'QLIK' ? 'text-bg-success' : 'text-bg-secondary'}">${n.origem}</span></td>
        <td>${n.ativo == 1 ? 'Ativo' : 'Inativo'}</td>
        <td>${administra ? `<button class="btn btn-sm btn-outline-secondary" data-editar="${n.id}">Editar</button>` : ''}</td>
      </tr>`).join('');

    alvo.innerHTML = `
      ${administra ? `<div class="mb-2 d-flex gap-2">
        <button class="btn btn-verde btn-sm" id="btn-novo-negocio">+ Novo negócio</button>
        <button class="btn btn-outline-success btn-sm" id="btn-sync-negocio">Sincronizar Comercial Global</button>
      </div>` : ''}
      <div class="table-responsive">
        <table class="table table-sm tabela-cadastro">
          <thead><tr><th>Cód.</th><th>Negócio</th><th>Gestor</th><th>Origem</th><th>Situação</th><th></th></tr></thead>
          <tbody>${linhas || '<tr><td colspan="6" class="text-muted">Nenhum negócio cadastrado. Use a sincronização ou o cadastro manual.</td></tr>'}</tbody>
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
    document.getElementById('btn-sync-negocio').addEventListener('click', async () => {
      const r = await App.api('/api/negocios/sync', {});
      alert(`Sincronização concluída: ${r.inseridos} inserido(s), ${r.atualizados} atualizado(s).` +
        (r.desativados ? ` ${r.desativados} fora da fonte desativado(s).` : '') +
        (r.conflitos ? ` ${r.conflitos} código(s) em conflito com cadastro manual — confira na lista.` : '') +
        (r.conectividade ? ` Conectividade Qlik: ${r.conectividade}.` : ''));
      this.carregar();
    });
    alvo.querySelectorAll('[data-editar]').forEach((b) => {
      b.addEventListener('click', () => abrirModal(lista.find((n) => n.id == b.dataset.editar)));
    });
  },

  // ---- Ciclos & Horizontes ----
  async ciclos(administra) {
    const lista = await App.api('/api/ciclos');
    const alvo = document.getElementById('conteudo-aba');

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

    alvo.innerHTML = `
      ${administra ? '<button class="btn btn-verde btn-sm mb-2" id="btn-novo-ciclo">+ Novo ciclo</button>' : ''}
      ${blocos || '<div class="text-muted">Nenhum ciclo cadastrado.</div>'}`;

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
          <thead><tr>${administra ? '<th></th>' : ''}<th>Ordem</th><th>${rotulo}</th><th>Situação</th><th></th></tr></thead>
          <tbody id="linhas-ordenaveis">${lista.map((i) => `
            <tr data-id="${i.id}" class="${i.ativo == 1 ? '' : 'table-secondary'}">
              ${administra ? `<td class="celula-alca"><button type="button" class="alca-arrastar"
                title="Arrastar para reordenar" aria-label="Arrastar para reordenar">⠿</button></td>` : ''}
              <td class="celula-ordem">${i.ordem}</td>
              <td>${Modal.esc(i.nome)}</td>
              <td>${i.ativo == 1 ? 'Ativo' : 'Inativo'}</td>
              <td>${administra ? `<button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}">Editar</button>` : ''}</td>
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

    alvo.innerHTML = `
      <button class="btn btn-verde btn-sm mb-2" id="btn-novo-usuario">+ Novo usuário</button>
      <div class="table-responsive">
        <table class="table table-sm tabela-cadastro">
          <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Negócios vinculados</th><th>Situação</th><th></th></tr></thead>
          <tbody>${lista.map((u) => `
            <tr class="${u.ativo == 1 ? '' : 'table-secondary'}">
              <td>${Modal.esc(u.nome)}</td>
              <td>${Modal.esc(u.email)}</td>
              <td>${u.perfil}</td>
              <td class="small">${['ADMIN', 'CONTROLADORIA', 'DIRECAO'].includes(u.perfil)
                ? '<em>todos</em>'
                : (u.negocios.map(nomeNegocio).map(Modal.esc).join(', ') || '—')}</td>
              <td>${u.ativo == 1 ? 'Ativo' : 'Inativo'}</td>
              <td><button class="btn btn-sm btn-outline-secondary" data-editar="${u.id}">Editar</button></td>
            </tr>`).join('')}</tbody>
        </table>
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
        { nome: 'negocios', rotulo: 'Negócios vinculados (Gestor/Leitura)', tipo: 'multiselect',
          opcoes: negocios.map((n) => ({ valor: n.id, rotulo: n.rotulo })),
          ajuda: 'Segure Ctrl para selecionar mais de um' },
        { nome: 'ativo', rotulo: 'Ativo', tipo: 'checkbox' },
      ],
    });

    document.getElementById('btn-novo-usuario').addEventListener('click', () => abrirModal());
    alvo.querySelectorAll('[data-editar]').forEach((b) => {
      b.addEventListener('click', () => abrirModal(lista.find((u) => u.id == b.dataset.editar)));
    });
  },
};

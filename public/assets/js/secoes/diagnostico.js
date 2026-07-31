// Seções de diagnóstico: Análise de Cenário, PESTEL, Porter, SWOT e Matriz GUT.

const Diag = {
  // Base comum: resolve o planejamento do contexto ou instrui a seleção
  async preparar(idSecao) {
    const el = document.getElementById(idSecao);
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu lateral.</div>';
      return null;
    }
    const plan = await App.planejamento();
    return { el, plan };
  },

  botoesFator(f, planId, comPromocao) {
    if (!App.podeEditar()) return '';
    const promover = comPromocao && !Number(f.promovido)
      ? `<button class="btn btn-sm btn-outline-success" data-promover="${f.id}" title="Promover para a SWOT">→ SWOT</button>` : '';
    const promovido = comPromocao && Number(f.promovido)
      ? '<span class="badge text-bg-success" title="Já promovido para a SWOT">na SWOT</span>' : '';
    return `<div class="d-flex gap-1 flex-shrink-0">
      ${promover}${promovido}
      <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}">Editar</button>
      <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}">×</button>
    </div>`;
  },

  // Renderiza uma etapa de fatores em colunas de categorias
  async etapaFatores({ idSecao, etapa, titulo, descricao, categorias, comPromocao }) {
    const base = await this.preparar(idSecao);
    if (!base) return;
    const { el, plan } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=${etapa}`);

    const colunas = categorias.map(([cat, rotulo, cor]) => {
      const itens = fatores.filter((f) => f.categoria === cat);
      const cartoes = itens.map((f) => `
        <div class="card mb-2"><div class="card-body py-2 px-3 d-flex justify-content-between gap-2">
          <div class="small">${Modal.esc(f.descricao)}</div>
          ${this.botoesFator(f, plan.id, comPromocao)}
        </div></div>`).join('');
      return `<div class="col-md-4 col-lg-2 coluna-categoria">
        <div class="fw-bold small text-uppercase mb-2" style="color:${cor}">${rotulo}
          <span class="badge text-bg-light">${itens.length}</span></div>
        ${cartoes || '<div class="text-muted small">—</div>'}
      </div>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center">
        <h1>${titulo} — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? `<button class="btn btn-verde btn-sm" id="btn-novo-fator">+ Novo fator</button>` : ''}
      </div>
      <p class="text-muted">${descricao}</p>
      <div class="row g-3">${colunas}</div>`;

    if (!App.podeEditar()) return;
    const opcoesCat = categorias.map(([cat, rotulo]) => ({ valor: cat, rotulo }));

    const modalFator = (f = null) => Modal.abrir({
      titulo: f ? 'Editar fator' : `Novo fator — ${titulo}`,
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f ? { ...f, planejamento_id: plan.id } : { planejamento_id: plan.id, etapa },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: etapa },
        { nome: 'categoria', rotulo: 'Categoria', tipo: 'select', opcoes: opcoesCat },
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
    });

    document.getElementById('btn-novo-fator').addEventListener('click', () => modalFator());
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalFator(fatores.find((f) => f.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este fator?')) return;
      await App.api(`/api/fatores/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
    el.querySelectorAll('[data-promover]').forEach((b) => b.addEventListener('click', () =>
      Modal.abrir({
        titulo: 'Promover fator para a SWOT',
        url: `/api/fatores/${b.dataset.promover}/promover`,
        valores: { planejamento_id: plan.id },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'quadrante', rotulo: 'Quadrante de destino', tipo: 'select', opcoes: [
            { valor: 'OPORTUNIDADE', rotulo: 'Oportunidade' },
            { valor: 'AMEACA', rotulo: 'Ameaça' },
            { valor: 'FORCA', rotulo: 'Força' },
            { valor: 'FRAQUEZA', rotulo: 'Fraqueza' },
          ]},
        ],
      })));
  },
};

const SecaoCenario = {
  async carregar() {
    const base = await Diag.preparar('secao-cenario');
    if (!base) return;
    const { el, plan } = base;
    const itens = await App.api(`/api/cenario?planejamento_id=${plan.id}`);

    const bloco = (tipo, titulo) => {
      const lista = itens.filter((i) => i.tipo === tipo);
      const linhas = lista.map((i, idx) => `
        <div class="card mb-2"><div class="card-body py-2 px-3 d-flex justify-content-between gap-2">
          <div class="small"><strong>${idx + 1}.</strong> ${Modal.esc(i.descricao)}</div>
          ${App.podeEditar() ? `<div class="d-flex gap-1 flex-shrink-0">
            <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}">Editar</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}">×</button>
          </div>` : ''}
        </div></div>`).join('');
      return `<div class="col-md-6">
        <h2 class="h6 text-uppercase text-muted">${titulo} <span class="badge text-bg-light">${lista.length}</span></h2>
        ${linhas || '<div class="text-muted small">Nenhum item.</div>'}
      </div>`;
    };

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center">
        <h1>Análise de Cenário — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-cenario">+ Novo item</button>' : ''}
      </div>
      <p class="text-muted">Onde estamos (situação atual do negócio) e para onde o ambiente aponta (tendências).</p>
      <div class="row g-4">
        ${bloco('SITUACAO_ATUAL', 'Situação Atual')}
        ${bloco('TENDENCIA', 'Tendências')}
      </div>`;

    if (!App.podeEditar()) return;
    const modalItem = (i = null) => Modal.abrir({
      titulo: i ? 'Editar item do cenário' : 'Novo item do cenário',
      url: i ? `/api/cenario/${i.id}` : '/api/cenario',
      valores: i ? { ...i, planejamento_id: plan.id } : { planejamento_id: plan.id },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: [
          { valor: 'SITUACAO_ATUAL', rotulo: 'Situação atual' },
          { valor: 'TENDENCIA', rotulo: 'Tendência' },
        ]},
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 4 },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: 0 },
      ],
    });
    document.getElementById('btn-novo-cenario').addEventListener('click', () => modalItem());
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalItem(itens.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este item?')) return;
      await App.api(`/api/cenario/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

const SecaoPestel = {
  carregar: () => Diag.etapaFatores({
    idSecao: 'secao-pestel',
    etapa: 'PESTEL',
    titulo: 'PESTEL',
    descricao: 'Fatores do macroambiente. Promova os relevantes para a SWOT — o vínculo de origem é mantido.',
    comPromocao: true,
    categorias: [
      ['POLITICO', 'Político', '#7a3b8f'],
      ['ECONOMICO', 'Econômico', '#b08d4f'],
      ['SOCIAL', 'Social', '#2c7fb8'],
      ['TECNOLOGICO', 'Tecnológico', '#0d6e6e'],
      ['ECOLOGICO', 'Ecológico', '#007a45'],
      ['LEGAL', 'Legal', '#8f3b3b'],
    ],
  }),
};

const SecaoPorter = {
  carregar: () => Diag.etapaFatores({
    idSecao: 'secao-porter',
    etapa: 'PORTER',
    titulo: 'Porter — 5 Forças',
    descricao: 'Forças competitivas do setor. Promova as relevantes para a SWOT.',
    comPromocao: true,
    categorias: [
      ['RIVALIDADE', 'Rivalidade', '#8f3b3b'],
      ['NOVOS_ENTRANTES', 'Novos Entrantes', '#b08d4f'],
      ['SUBSTITUTOS', 'Substitutos', '#7a3b8f'],
      ['PODER_FORNECEDORES', 'Poder dos Fornecedores', '#2c7fb8'],
      ['PODER_CLIENTES', 'Poder dos Clientes', '#0d6e6e'],
    ],
  }),
};

const SecaoSwot = {
  async carregar() {
    const base = await Diag.preparar('secao-swot');
    if (!base) return;
    const { el, plan } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT`);

    const quadrante = (cat, rotulo, cor) => {
      const itens = fatores.filter((f) => f.categoria === cat);
      const cartoes = itens.map((f) => {
        const origem = f.origem_etapa
          ? `<span class="badge text-bg-light border" title="Promovido do ${f.origem_etapa}">${f.origem_etapa}</span>` : '';
        const gut = f.score ? `<span class="badge text-bg-warning" title="Score GUT">GUT ${f.score}</span>` : '';
        return `<div class="card mb-2"><div class="card-body py-2 px-3">
          <div class="d-flex justify-content-between gap-2">
            <div class="small">${Modal.esc(f.descricao)}</div>
            ${App.podeEditar() ? `<div class="d-flex gap-1 flex-shrink-0">
              <button class="btn btn-sm btn-outline-secondary" data-editar="${f.id}">Editar</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir="${f.id}">×</button>
            </div>` : ''}
          </div>
          <div class="d-flex gap-1 mt-1">${origem}${gut}</div>
        </div></div>`;
      }).join('');
      return `<div class="col-md-6">
        <div class="p-2 rounded" style="background:${cor}18; border-top: 3px solid ${cor}">
          <div class="fw-bold small text-uppercase mb-2" style="color:${cor}">${rotulo}
            <span class="badge text-bg-light">${itens.length}</span></div>
          ${cartoes || '<div class="text-muted small">Nenhum fator.</div>'}
        </div>
      </div>`;
    };

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center">
        <h1>SWOT — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-swot">+ Novo fator</button>' : ''}
      </div>
      <p class="text-muted">Ambiente interno (forças e fraquezas) e externo (oportunidades e ameaças).
      Fatores promovidos do PESTEL/Porter chegam com o selo de origem; priorize-os na Matriz GUT.</p>
      <div class="row g-3">
        ${quadrante('FORCA', 'Forças', '#007a45')}
        ${quadrante('FRAQUEZA', 'Fraquezas', '#b08d4f')}
        ${quadrante('OPORTUNIDADE', 'Oportunidades', '#2c7fb8')}
        ${quadrante('AMEACA', 'Ameaças', '#8f3b3b')}
      </div>`;

    if (!App.podeEditar()) return;
    const modalFator = (f = null) => Modal.abrir({
      titulo: f ? 'Editar fator da SWOT' : 'Novo fator da SWOT',
      url: f ? `/api/fatores/${f.id}` : '/api/fatores',
      valores: f ? { ...f, planejamento_id: plan.id } : { planejamento_id: plan.id, etapa: 'SWOT' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'etapa', rotulo: '', tipo: 'hidden', padrao: 'SWOT' },
        { nome: 'categoria', rotulo: 'Quadrante', tipo: 'select', opcoes: [
          { valor: 'FORCA', rotulo: 'Força' },
          { valor: 'FRAQUEZA', rotulo: 'Fraqueza' },
          { valor: 'OPORTUNIDADE', rotulo: 'Oportunidade' },
          { valor: 'AMEACA', rotulo: 'Ameaça' },
        ]},
        { nome: 'descricao', rotulo: 'Descrição do fator', tipo: 'textarea' },
      ],
    });
    document.getElementById('btn-novo-swot').addEventListener('click', () => modalFator());
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      modalFator(fatores.find((f) => f.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este fator?')) return;
      await App.api(`/api/fatores/${b.dataset.excluir}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

const SecaoGut = {
  async carregar() {
    const base = await Diag.preparar('secao-gut');
    if (!base) return;
    const { el, plan } = base;
    const fatores = await App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT`);
    const ordenados = [...fatores].sort((a, b) => (b.score || 0) - (a.score || 0));

    const nomeQuadrante = {
      FORCA: 'Força', FRAQUEZA: 'Fraqueza', OPORTUNIDADE: 'Oportunidade', AMEACA: 'Ameaça',
    };

    const linhas = ordenados.map((f, idx) => `
      <tr>
        <td>${f.score ? `<strong>${idx + 1}º</strong>` : '—'}</td>
        <td><span class="badge text-bg-secondary">${nomeQuadrante[f.categoria]}</span></td>
        <td class="small">${Modal.esc(f.descricao)}</td>
        <td class="text-center">${f.gravidade ?? '—'}</td>
        <td class="text-center">${f.urgencia ?? '—'}</td>
        <td class="text-center">${f.tendencia ?? '—'}</td>
        <td class="text-center">${f.score ? `<span class="badge text-bg-warning fs-6">${f.score}</span>` : '—'}</td>
        <td>${App.podeEditar() ? `<button class="btn btn-sm btn-outline-secondary" data-avaliar="${f.id}">Avaliar</button>` : ''}</td>
      </tr>`).join('');

    el.innerHTML = `
      <h1>Matriz GUT — ${Modal.esc(App.rotuloContexto())}</h1>
      <p class="text-muted">Priorize os fatores da SWOT: Gravidade × Urgência × Tendência (1–5).
      O ranking orienta as escolhas da cascata (Fase 3).</p>
      <table class="table table-sm align-middle">
        <thead><tr>
          <th>Ranking</th><th>Quadrante</th><th>Fator</th>
          <th class="text-center">G</th><th class="text-center">U</th><th class="text-center">T</th>
          <th class="text-center">Score</th><th></th>
        </tr></thead>
        <tbody>${linhas || '<tr><td colspan="8" class="text-muted">Cadastre fatores na SWOT para avaliá-los aqui.</td></tr>'}</tbody>
      </table>`;

    if (!App.podeEditar()) return;
    const escala = [1, 2, 3, 4, 5].map((n) => ({ valor: n, rotulo: String(n) }));
    el.querySelectorAll('[data-avaliar]').forEach((b) => b.addEventListener('click', () => {
      const f = fatores.find((x) => x.id == b.dataset.avaliar);
      Modal.abrir({
        titulo: 'Avaliação GUT',
        url: `/api/fatores/${f.id}/gut`,
        valores: {
          planejamento_id: plan.id,
          gravidade: f.gravidade || 3, urgencia: f.urgencia || 3, tendencia: f.tendencia || 3,
        },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'gravidade', rotulo: 'Gravidade (1 = leve · 5 = gravíssimo)', tipo: 'select', opcoes: escala },
          { nome: 'urgencia', rotulo: 'Urgência (1 = pode esperar · 5 = agir já)', tipo: 'select', opcoes: escala },
          { nome: 'tendencia', rotulo: 'Tendência (1 = estável · 5 = piora rápido)', tipo: 'select', opcoes: escala },
        ],
      });
    }));
  },
};

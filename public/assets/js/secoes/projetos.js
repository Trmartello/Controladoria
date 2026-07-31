// Projetos estratégicos e operacionais: desdobramentos 5W2H + diário de bordo.

const STATUS_ROTULOS = {
  NAO_INICIADO: ['Não iniciado', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluído', 'text-bg-success'],
  ATRASADO: ['Atrasado', 'text-bg-danger'],
  CANCELADO: ['Cancelado', 'text-bg-secondary'],
};
const OPCOES_STATUS = Object.entries(STATUS_ROTULOS)
  .map(([valor, [rotulo]]) => ({ valor, rotulo }));

const SecaoProjetos = {
  plan: null,
  cascata: null,
  responsaveis: [],
  filtroTipo: 'ESTRATEGICO',
  diarioAberto: null, // { refTipo, refId }

  // Período escolhido no calendário; textos antigos (prazo/quando_) seguem valendo
  periodo(inicio, fim, legado) {
    const br = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : null);
    const de = br(inicio);
    const ate = br(fim);
    if (de && ate) return `${de} a ${ate}`;
    if (de) return `a partir de ${de}`;
    if (ate) return `até ${ate}`;
    return legado || null;
  },

  async carregar() {
    const el = document.getElementById('secao-projetos');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    const [projetos, cascata, responsaveis] = await Promise.all([
      App.api(`/api/projetos?planejamento_id=${this.plan.id}`),
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      App.api(`/api/responsaveis?planejamento_id=${this.plan.id}`),
    ]);
    this.cascata = cascata;
    this.responsaveis = responsaveis;
    const doTipo = projetos.filter((p) => p.tipo === this.filtroTipo);

    const badge = (status) => {
      const [rotulo, classe] = STATUS_ROTULOS[status] || [status, 'text-bg-light'];
      return `<span class="badge ${classe}">${rotulo}</span>`;
    };

    const cartoes = doTipo.map((p) => {
      const detalhes = [
        p.responsavel && `<strong>Responsável:</strong> ${Modal.esc(p.responsavel)}`,
        this.periodo(p.data_inicio, p.data_fim, p.prazo)
          && `<strong>Prazo:</strong> ${Modal.esc(this.periodo(p.data_inicio, p.data_fim, p.prazo))}`,
        p.horizonte_nome && `<strong>Horizonte:</strong> ${Modal.esc(p.horizonte_nome)}`,
        p.impacto && `<strong>Impacto:</strong> ${p.impacto}`,
      ].filter(Boolean).join(' · ');
      const origem = p.escolha_origem
        ? `<div class="small text-muted mt-1">↳ Escolha da cascata: “${Modal.esc(p.escolha_origem.slice(0, 90))}”</div>` : '';
      const media = p.desdobramentos.length
        ? Math.round(p.desdobramentos.reduce((s, d) => s + Number(d.progresso), 0) / p.desdobramentos.length)
        : 0;

      const linhas = p.desdobramentos.map((dd) => {
        const cincoW = [
          dd.por_que && `Por quê: ${dd.por_que}`, dd.quem && `Quem: ${dd.quem}`,
          this.periodo(dd.data_inicio, dd.data_fim, dd.quando_)
            && `Quando: ${this.periodo(dd.data_inicio, dd.data_fim, dd.quando_)}`,
          dd.onde && `Onde: ${dd.onde}`,
          dd.como && `Como: ${dd.como}`,
          dd.quanto !== null && dd.quanto !== undefined && `Quanto: R$ ${Number(dd.quanto).toLocaleString('pt-BR')}`,
        ].filter(Boolean).map(Modal.esc).join(' · ');
        const timeline = this.diarioAberto?.refTipo === 'DESDOBRAMENTO' && this.diarioAberto?.refId === dd.id
          ? `<tr class="linha-diario"><td colspan="5"><div id="diario-DESDOBRAMENTO-${dd.id}" class="p-2"></div></td></tr>` : '';
        return `
          <tr>
            <td class="small">${Modal.esc(dd.o_que)}${cincoW ? `<div class="text-muted">${cincoW}</div>` : ''}</td>
            <td class="text-nowrap">${badge(dd.status)}</td>
            <td style="min-width:110px">
              <div class="progress" style="height:14px" title="${dd.progresso}%">
                <div class="progress-bar bg-success" style="width:${dd.progresso}%">${dd.progresso}%</div>
              </div>
            </td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-success" data-diario="DESDOBRAMENTO:${dd.id}">Diário</button>
            </td>
            <td class="text-nowrap">${App.podeEditar() ? `
              <button class="btn btn-sm btn-outline-secondary" data-editar-desd="${dd.id}" data-proj="${p.id}">Editar</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir-desd="${dd.id}">×</button>` : ''}</td>
          </tr>${timeline}`;
      }).join('');

      const timelineProjeto = this.diarioAberto?.refTipo === 'PROJETO' && this.diarioAberto?.refId === p.id
        ? `<div id="diario-PROJETO-${p.id}" class="mt-2"></div>` : '';

      return `<div class="card mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between gap-2 flex-wrap">
            <div>
              <strong>${Modal.esc(p.titulo)}</strong>
              ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning ms-1">Prioritário</span>' : ''}
              ${badge(p.status)}
              ${detalhes ? `<div class="small text-muted mt-1">${detalhes}</div>` : ''}
              ${origem}
            </div>
            <div class="d-flex gap-1 align-items-start flex-shrink-0">
              <span class="badge text-bg-light border" title="Progresso médio dos desdobramentos">${media}%</span>
              <button class="btn btn-sm btn-outline-success" data-diario="PROJETO:${p.id}">Diário</button>
              ${App.podeEditar() ? `
                <button class="btn btn-sm btn-verde" data-novo-desd="${p.id}">+ Ação</button>
                <button class="btn btn-sm btn-outline-secondary" data-editar-proj="${p.id}">Editar</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-proj="${p.id}">×</button>` : ''}
            </div>
          </div>
          ${p.desdobramentos.length ? `
          <div class="table-responsive">
            <table class="table table-sm mt-2 mb-0 align-middle">
              <thead><tr><th>Desdobramento (5W2H)</th><th>Status</th><th>Progresso</th><th></th><th></th></tr></thead>
              <tbody>${linhas}</tbody>
            </table>
          </div>` : '<div class="text-muted small mt-2">Nenhum desdobramento cadastrado.</div>'}
          ${timelineProjeto}
        </div>
      </div>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Projetos — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-proj">+ Novo projeto</button>' : ''}
      </div>
      <ul class="nav nav-tabs mt-2">
        <li class="nav-item"><a class="nav-link ${this.filtroTipo === 'ESTRATEGICO' ? 'active' : ''}" href="#" data-tipo="ESTRATEGICO">Estratégicos (plurianuais)</a></li>
        <li class="nav-item"><a class="nav-link ${this.filtroTipo === 'OPERACIONAL' ? 'active' : ''}" href="#" data-tipo="OPERACIONAL">Plano Operacional (ano)</a></li>
      </ul>
      <div class="pt-3">${cartoes || '<div class="text-muted">Nenhum projeto neste grupo.</div>'}</div>`;

    el.querySelectorAll('[data-tipo]').forEach((a) => a.addEventListener('click', (ev) => {
      ev.preventDefault();
      this.filtroTipo = a.dataset.tipo;
      this.diarioAberto = null;
      this.carregar();
    }));

    el.querySelectorAll('[data-diario]').forEach((b) => b.addEventListener('click', () => {
      const [refTipo, refId] = b.dataset.diario.split(':');
      const mesmo = this.diarioAberto?.refTipo === refTipo && this.diarioAberto?.refId === Number(refId);
      this.diarioAberto = mesmo ? null : { refTipo, refId: Number(refId) };
      this.carregar();
    }));
    if (this.diarioAberto) this.renderDiario();

    if (!App.podeEditar()) return;

    document.getElementById('btn-novo-proj').addEventListener('click', () => this.modalProjeto(null, projetos));
    el.querySelectorAll('[data-editar-proj]').forEach((b) => b.addEventListener('click', () =>
      this.modalProjeto(projetos.find((p) => p.id == b.dataset.editarProj), projetos)));
    el.querySelectorAll('[data-excluir-proj]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir o projeto e todos os seus desdobramentos?')) return;
      try {
        await App.api(`/api/projetos/${b.dataset.excluirProj}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-novo-desd]').forEach((b) => b.addEventListener('click', () =>
      this.modalDesdobramento(parseInt(b.dataset.novoDesd, 10), null)));
    el.querySelectorAll('[data-editar-desd]').forEach((b) => b.addEventListener('click', () => {
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      this.modalDesdobramento(proj.id, proj.desdobramentos.find((dd) => dd.id == b.dataset.editarDesd));
    }));
    el.querySelectorAll('[data-excluir-desd]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este desdobramento?')) return;
      await App.api(`/api/desdobramentos/${b.dataset.excluirDesd}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));
  },

  modalProjeto(p, projetos) {
    const opcoesHorizonte = [{ valor: '', rotulo: '(sem horizonte)' }].concat(
      this.cascata.horizontes.map((h) => ({ valor: h.id, rotulo: `${h.nome} · ${h.ano_inicio}–${h.ano_fim} · ${h.tema}` })));
    const nomeDriver = (id) => this.cascata.drivers.find((d) => d.id == id)?.nome || '';
    const opcoesCascata = [{ valor: '', rotulo: '(sem vínculo com a cascata)' }].concat(
      this.cascata.escolhas.map((e) => ({
        valor: e.id,
        rotulo: `${nomeDriver(e.driver_id)}: ${e.escolha.slice(0, 60)}`,
      })));
    // Projeto novo já nasce no primeiro horizonte do ciclo (o mais próximo)
    const horizontePadrao = this.cascata.horizontes[0];
    Modal.abrir({
      titulo: p ? 'Editar projeto' : 'Novo projeto',
      url: p ? `/api/projetos/${p.id}` : '/api/projetos',
      valores: p
        ? { ...p, horizonte_id: p.horizonte_id ?? '', cascata_id: p.cascata_id ?? '', impacto: p.impacto ?? '', planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, tipo: this.filtroTipo, horizonte_id: horizontePadrao?.id ?? '' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: [
          { valor: 'ESTRATEGICO', rotulo: 'Estratégico (plurianual)' },
          { valor: 'OPERACIONAL', rotulo: 'Operacional (ano)' },
        ]},
        { nome: 'titulo', rotulo: 'Projeto / ação planejada', obrigatorio: true,
          exemplo: 'Ex.: 1ª onda de silos — unidade Capinzal' },
        { nome: 'responsavel', rotulo: 'Responsável', sugestoes: this.responsaveis,
          exemplo: 'Escolha um usuário ou digite outro nome',
          ajuda: 'A lista traz os usuários cadastrados; você também pode digitar qualquer outro nome.' },
        // Preserva o prazo em texto dos projetos criados antes do calendário
        { nome: 'prazo', rotulo: '', tipo: 'hidden' },
        { nome: 'prazo_periodo', rotulo: 'Prazo da ação', tipo: 'periodo',
          campos: [
            { nome: 'data_inicio', rotulo: 'Início' },
            { nome: 'data_fim', rotulo: 'Fim previsto' },
          ],
          ajuda: 'Toque no campo para abrir o calendário. Em projetos plurianuais, use o mês de referência.' },
        { nome: 'horizonte_id', rotulo: 'Horizonte', tipo: 'select', opcoes: opcoesHorizonte,
          nota: horizontePadrao
            ? `Padrão: ${horizontePadrao.nome} (${horizontePadrao.ano_inicio}–${horizontePadrao.ano_fim}), o primeiro horizonte do ciclo. Troque se o projeto pertencer a outro.`
            : null },
        { nome: 'cascata_id', rotulo: 'Escolha da cascata que originou', tipo: 'select', opcoes: opcoesCascata },
        { nome: 'impacto', rotulo: 'Impacto', tipo: 'select', opcoes: [
          { valor: '', rotulo: '(não definido)' },
          { valor: 'RENTABILIDADE', rotulo: 'Rentabilidade' },
          { valor: 'FATURAMENTO', rotulo: 'Faturamento' },
          { valor: 'SUSTENTABILIDADE', rotulo: 'Sustentabilidade' },
          { valor: 'PESSOAS', rotulo: 'Pessoas' },
        ]},
        { nome: 'classificacao', rotulo: 'Classificação', tipo: 'select', opcoes: [
          { valor: 'NORMAL', rotulo: 'Normal' },
          { valor: 'PRIORITARIO', rotulo: 'Prioritário' },
        ]},
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes: OPCOES_STATUS },
        { nome: 'ordem', rotulo: 'Ordem', tipo: 'number', padrao: (projetos?.length || 0) + 1 },
      ],
    });
  },

  modalDesdobramento(projetoId, dd) {
    Modal.abrir({
      titulo: dd ? 'Editar desdobramento (5W2H)' : 'Novo desdobramento (5W2H)',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, progresso: 0 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'o_que', rotulo: 'O quê? (What)', obrigatorio: true, tipo: 'textarea', linhas: 2,
          exemplo: 'Ex.: Contratar projeto executivo dos silos' },
        { nome: 'por_que', rotulo: 'Por quê? (Why)' },
        { nome: 'quem', rotulo: 'Quem? (Who)', sugestoes: this.responsaveis,
          exemplo: 'Escolha um usuário ou digite outro nome' },
        { nome: 'quando_', rotulo: '', tipo: 'hidden' },
        { nome: 'quando_periodo', rotulo: 'Quando? (When)', tipo: 'periodo',
          campos: [
            { nome: 'data_inicio', rotulo: 'Início' },
            { nome: 'data_fim', rotulo: 'Fim previsto' },
          ],
          ajuda: 'Toque no campo para abrir o calendário.' },
        { nome: 'onde', rotulo: 'Onde? (Where)' },
        { nome: 'como', rotulo: 'Como? (How)' },
        { nome: 'quanto', rotulo: 'Quanto custa? R$ (How much)', tipo: 'number' },
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes: OPCOES_STATUS },
        { nome: 'progresso', rotulo: 'Progresso (%)', tipo: 'number' },
      ],
    });
  },

  async renderDiario() {
    const { refTipo, refId } = this.diarioAberto;
    const alvo = document.getElementById(`diario-${refTipo}-${refId}`);
    if (!alvo) return;
    const registros = await App.api(
      `/api/diario?planejamento_id=${this.plan.id}&ref_tipo=${refTipo}&ref_id=${refId}`);

    const itens = registros.map((r) => {
      const [rotulo, classe] = r.status_atual ? STATUS_ROTULOS[r.status_atual] : [null, null];
      return `<div class="border-start border-success border-3 ps-2 mb-2">
        <div class="small text-muted">${r.data_reg.split('-').reverse().join('/')} · ${Modal.esc(r.autor)}
          ${rotulo ? `<span class="badge ${classe}">${rotulo}</span>` : ''}
          ${r.progresso !== null && r.progresso !== undefined ? `<span class="badge text-bg-light border">${r.progresso}%</span>` : ''}
        </div>
        <div class="small">${Modal.esc(r.texto)}</div>
      </div>`;
    }).join('');

    alvo.innerHTML = `<div class="card bg-light"><div class="card-body py-2">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small text-uppercase">Diário de bordo</strong>
        ${App.podeEditar() ? `<button class="btn btn-sm btn-verde" id="btn-novo-registro">+ Registro</button>` : ''}
      </div>
      ${itens || '<div class="text-muted small">Nenhum registro ainda.</div>'}
    </div></div>`;

    if (!App.podeEditar()) return;
    document.getElementById('btn-novo-registro').addEventListener('click', () => Modal.abrir({
      titulo: 'Novo registro no diário de bordo',
      url: '/api/diario',
      valores: {
        planejamento_id: this.plan.id, ref_tipo: refTipo, ref_id: refId,
        data_reg: App.hoje(),
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ref_tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'ref_id', rotulo: '', tipo: 'hidden' },
        { nome: 'data_reg', rotulo: 'Data', tipo: 'date' },
        { nome: 'texto', rotulo: 'Situação atual / acompanhamento', tipo: 'textarea', linhas: 4 },
        ...(refTipo !== 'CASCATA' ? [
          { nome: 'status_atual', rotulo: 'Atualizar status do item (opcional)', tipo: 'select',
            opcoes: [{ valor: '', rotulo: '(manter status atual)' }, ...OPCOES_STATUS] },
        ] : []),
        ...(refTipo === 'DESDOBRAMENTO' ? [
          { nome: 'progresso', rotulo: 'Atualizar progresso % (opcional)', tipo: 'number' },
        ] : []),
      ],
    }));
  },
};

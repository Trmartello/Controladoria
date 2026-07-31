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

// Ações: "No prazo" e "Atrasada" saem da data-limite; os demais são manuais
const STATUS_ACAO = {
  NAO_INICIADO: ['No prazo', 'text-bg-info'],
  ATRASADO: ['Atrasada', 'text-bg-danger'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluída', 'text-bg-success'],
  CANCELADO: ['Cancelada', 'text-bg-secondary'],
  PAUSADO: ['Pausada', 'text-bg-warning'],
  AGUARDANDO_VALIDACAO: ['Aguardando validação', 'text-bg-light border'],
};
const STATUS_MANUAIS = ['EM_ANDAMENTO', 'CONCLUIDO', 'CANCELADO', 'PAUSADO', 'AGUARDANDO_VALIDACAO'];
const PRIORIDADES = {
  ALTA: ['Alta', '#b3261e'], MEDIA: ['Média', '#b08d4f'], BAIXA: ['Baixa', '#2c7fb8'],
};
const STATUS_INICIATIVA = {
  ABERTA: ['Aberta', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDA: ['Concluída', 'text-bg-success'],
};

const SecaoProjetos = {
  plan: null,
  cascata: null,
  responsaveis: [],
  filtroTipo: 'ESTRATEGICO',
  diarioAberto: null, // { refTipo, refId }
  iniciativasFechadas: new Set(), // por padrão as frentes abrem expandidas

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

  // Bloco da iniciativa (frente de trabalho) com as ações dentro
  blocoIniciativa(p, ini) {
    const acoes = ini.acoes || [];
    const feitas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
    const [rotIni, classeIni] = STATUS_INICIATIVA[ini.status] || [ini.status, 'text-bg-light'];
    const aberta = !this.iniciativasFechadas.has(ini.id);
    const cartoes = acoes.map((a) => this.cartaoAcao(p, ini, a)).join('');
    return `<div class="iniciativa mb-2" data-iniciativa="${ini.id}">
      <div class="d-flex align-items-center gap-2 flex-wrap iniciativa-cabeca" data-abrir-ini="${ini.id}">
        <span class="seta-iniciativa">${aberta ? '▾' : '▸'}</span>
        <strong class="small">${Modal.esc(ini.titulo)}</strong>
        <span class="badge ${classeIni}">${rotIni}</span>
        <span class="badge text-bg-light border" title="Ações concluídas">${feitas}/${acoes.length} ações</span>
        ${App.podeEditar() ? `<span class="ms-auto d-flex gap-1">
          <button class="btn btn-sm btn-verde" data-nova-acao="${ini.id}" data-proj="${p.id}">+ Ação</button>
          <button class="btn btn-sm btn-outline-secondary" data-editar-ini="${ini.id}" data-proj="${p.id}"
            title="Editar iniciativa" aria-label="Editar iniciativa">✎</button>
          <button class="btn btn-sm btn-outline-danger" data-excluir-ini="${ini.id}"
            title="Excluir iniciativa" aria-label="Excluir iniciativa">×</button>
        </span>` : ''}
      </div>
      <div class="acoes-iniciativa ${aberta ? '' : 'd-none'}">
        ${ini.descricao ? `<div class="small text-muted mb-2">${Modal.esc(ini.descricao)}</div>` : ''}
        ${cartoes || '<div class="text-muted small">Nenhuma ação nesta iniciativa.</div>'}
      </div>
    </div>`;
  },

  cartaoAcao(p, ini, a) {
    const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
    const [rotPrio, corPrio] = PRIORIDADES[a.prioridade] || PRIORIDADES.MEDIA;
    const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
    const detalhes = [
      a.quem && `Quem: ${a.quem}`,
      a.onde && `Onde: ${a.onde}`,
      a.como && `Como: ${a.como}`,
      a.por_que && `Por quê: ${a.por_que}`,
      a.quanto !== null && a.quanto !== undefined && `Quanto: R$ ${Number(a.quanto).toLocaleString('pt-BR')}`,
    ].filter(Boolean).map(Modal.esc).join(' · ');
    const timeline = this.diarioAberto?.refTipo === 'DESDOBRAMENTO' && this.diarioAberto?.refId === a.id
      ? `<div id="diario-DESDOBRAMENTO-${a.id}" class="mt-2"></div>` : '';
    return `<div class="card acao-card mb-2" style="--cor-prio:${corPrio}" data-card-acao="${a.id}">
      <div class="card-body py-2 px-2">
        <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
          <span class="badge selo-prioridade" style="color:${corPrio};background:${corPrio}1f">${rotPrio}</span>
          <span class="badge ${classe}">${rotulo}</span>
          ${prazo ? `<span class="small text-muted ms-auto">${Modal.esc(prazo)}</span>` : ''}
        </div>
        <div class="small">${Modal.esc(a.o_que)}</div>
        ${detalhes ? `<div class="small text-muted">${detalhes}</div>` : ''}
        <div class="d-flex align-items-center gap-2 mt-2">
          <div class="progress flex-grow-1" style="height:14px" title="${a.progresso}%">
            <div class="progress-bar bg-success" style="width:${a.progresso}%">${a.progresso}%</div>
          </div>
          <span class="d-flex gap-1 flex-shrink-0">
            <button class="btn btn-sm btn-outline-success" data-diario="DESDOBRAMENTO:${a.id}">Diário</button>
            ${App.podeEditar() ? `
              <button class="btn btn-sm btn-outline-secondary" data-editar-desd="${a.id}" data-proj="${p.id}"
                title="Editar ação" aria-label="Editar ação">✎</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir-desd="${a.id}"
                title="Excluir ação" aria-label="Excluir ação">×</button>` : ''}
          </span>
        </div>
        ${timeline}
      </div>
    </div>`;
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

      const iniciativas = (p.iniciativas || []).map((ini) => this.blocoIniciativa(p, ini)).join('');

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
            <div class="d-flex gap-1 align-items-start flex-shrink-0 flex-wrap justify-content-end">
              <span class="badge text-bg-light border" title="Progresso médio das ações">${media}%</span>
              <button class="btn btn-sm btn-outline-success" data-diario="PROJETO:${p.id}">Diário</button>
              ${App.podeEditar() ? `
                <button class="btn btn-sm btn-verde" data-nova-ini="${p.id}">+ Iniciativa</button>
                <button class="btn btn-sm btn-outline-secondary" data-editar-proj="${p.id}">Editar</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-proj="${p.id}">×</button>` : ''}
            </div>
          </div>
          <div class="mt-3">
            ${iniciativas || '<div class="text-muted small">Nenhuma iniciativa cadastrada. Crie uma frente de trabalho para organizar as ações.</div>'}
          </div>
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
    el.querySelectorAll('[data-nova-ini]').forEach((b) => b.addEventListener('click', () =>
      this.modalIniciativa(parseInt(b.dataset.novaIni, 10), null)));
    el.querySelectorAll('[data-editar-ini]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      this.modalIniciativa(proj.id, proj.iniciativas.find((i) => i.id == b.dataset.editarIni));
    }));
    el.querySelectorAll('[data-excluir-ini]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      if (!confirm('Excluir a iniciativa e todas as ações dentro dela?')) return;
      try {
        await App.api(`/api/iniciativas/${b.dataset.excluirIni}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-nova-acao]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      this.modalDesdobramento(parseInt(b.dataset.proj, 10), null, parseInt(b.dataset.novaAcao, 10));
    }));
    el.querySelectorAll('[data-editar-desd]').forEach((b) => b.addEventListener('click', () => {
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      const acao = proj.desdobramentos.find((dd) => dd.id == b.dataset.editarDesd);
      this.modalDesdobramento(proj.id, acao, acao.iniciativa_id);
    }));
    // Acordeão das iniciativas (clicar no cabeçalho abre/fecha)
    el.querySelectorAll('[data-abrir-ini]').forEach((c) => c.addEventListener('click', (ev) => {
      if (ev.target.closest('button')) return;
      const id = parseInt(c.dataset.abrirIni, 10);
      if (this.iniciativasFechadas.has(id)) this.iniciativasFechadas.delete(id);
      else this.iniciativasFechadas.add(id);
      const bloco = el.querySelector(`[data-iniciativa="${id}"]`);
      bloco.querySelector('.acoes-iniciativa').classList.toggle('d-none', this.iniciativasFechadas.has(id));
      bloco.querySelector('.seta-iniciativa').textContent = this.iniciativasFechadas.has(id) ? '▸' : '▾';
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

  modalIniciativa(projetoId, ini) {
    Modal.abrir({
      titulo: ini ? 'Editar iniciativa' : 'Nova iniciativa',
      url: ini ? `/api/iniciativas/${ini.id}` : '/api/iniciativas',
      valores: ini
        ? { ...ini, planejamento_id: this.plan.id, projeto_id: projetoId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, status: 'ABERTA' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'titulo', rotulo: 'Iniciativa (frente de trabalho)', obrigatorio: true,
          exemplo: 'Ex.: Licenciamento e obra civil',
          ajuda: 'Agrupa as ações de uma mesma frente dentro do projeto.' },
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 2 },
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes:
          Object.entries(STATUS_INICIATIVA).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
      ],
    });
  },

  // Status da ação: os automáticos aparecem, mas não podem ser escolhidos
  opcoesStatusAcao(atual) {
    const automatico = atual === 'ATRASADO' ? 'ATRASADO' : 'NAO_INICIADO';
    const opcoes = [{
      valor: automatico,
      rotulo: `${STATUS_ACAO[automatico][0]} (automático)`,
    }];
    for (const v of STATUS_MANUAIS) opcoes.push({ valor: v, rotulo: STATUS_ACAO[v][0] });
    return opcoes;
  },

  modalDesdobramento(projetoId, dd, iniciativaId = null) {
    Modal.abrir({
      titulo: dd ? 'Editar ação' : 'Nova ação',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId,
            iniciativa_id: dd.iniciativa_id ?? iniciativaId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, iniciativa_id: iniciativaId,
            progresso: 0, prioridade: 'MEDIA', status: 'NAO_INICIADO' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'iniciativa_id', rotulo: '', tipo: 'hidden' },
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
        { nome: 'prioridade', rotulo: 'Prioridade', tipo: 'botoes', opcoes:
          Object.entries(PRIORIDADES).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
        { nome: 'status', rotulo: 'Status', tipo: 'select',
          opcoes: this.opcoesStatusAcao(dd?.status),
          ajuda: '“No prazo” e “Atrasada” são definidos pela data de fim — escolha um status manual só quando quiser fixá-lo.' },
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

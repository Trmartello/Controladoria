// Relatório de Status — Fase 6: documento da reunião por período/negócio.
// Tela → Imprimir (gera o PDF pelo navegador) → Exportar Excel.

const STATUS_PROJETO = {
  NAO_INICIADO: ['Não iniciado', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-info'],
  CONCLUIDO: ['Concluído', 'text-bg-success'],
  ATRASADO: ['Atrasado', 'text-bg-danger'],
  CANCELADO: ['Cancelado', 'text-bg-secondary'],
};

const SecaoRelatorio = {
  plan: null,
  de: null,
  ate: null,

  fmt(v) {
    return v === null || v === undefined || v === ''
      ? '—'
      : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  },
  data(iso) {
    return iso ? iso.split('-').reverse().join('/') : '';
  },

  async carregar() {
    const el = document.getElementById('secao-relatorio');
    if (!App.contextoParams()) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu.</div>';
      return;
    }
    this.plan = await App.planejamento();
    this.ate = this.ate || App.hoje();
    this.de = this.de || App.hoje(30);

    const r = await App.api(
      `/api/relatorio?planejamento_id=${this.plan.id}&de=${this.de}&ate=${this.ate}`);

    const ancoras = r.indicadores.filter((i) => Number(i.metrica_ancora));
    const linhasIndicadores = (lista) => lista.map((i) => {
      const metas = Object.fromEntries(i.metas.map((m) => [m.ano, m.valor]));
      const reais = Object.fromEntries(i.reais.map((x) => [x.ano, x.valor]));
      return `<tr>
        <td rowspan="2"><strong>${Modal.esc(i.nome)}</strong>
          <div class="small text-muted">${Modal.esc(i.unidade)}${i.horizonte_nome ? ` · ${Modal.esc(i.horizonte_nome)}` : ''}</div></td>
        <td class="small text-muted">Meta</td>
        ${r.anos.map((a) => `<td class="valor">${this.fmt(metas[a])}</td>`).join('')}
      </tr>
      <tr class="linha-real">
        <td class="small text-muted">Real</td>
        ${r.anos.map((a) => `<td class="valor">${this.fmt(reais[a])}</td>`).join('')}
      </tr>`;
    }).join('');

    const linhasProjetos = r.projetos.map((p) => {
      const [rotulo, classe] = STATUS_PROJETO[p.status] || [p.status, 'text-bg-light'];
      return `<tr>
        <td>${p.classificacao === 'PRIORITARIO' ? '<span class="badge badge-horizonte me-1">P</span>' : ''}${Modal.esc(p.titulo)}</td>
        <td>${Modal.esc(p.responsavel || '—')}</td>
        <td>${Modal.esc(p.prazo || '—')}</td>
        <td><span class="badge ${classe}">${rotulo}</span>
          ${Number(p.desdobramentos_atrasados) ? `<span class="badge text-bg-danger ms-1">${p.desdobramentos_atrasados} 5W2H atrasado(s)</span>` : ''}</td>
        <td style="min-width:110px"><div class="progress mini-progresso">
          <div class="progress-bar bg-success" style="width:${p.progresso}%"></div>
        </div><span class="small text-muted">${p.progresso}%</span></td>
      </tr>`;
    }).join('');

    const linhasCapital = r.capital.map((cp) => {
      const pct = Number(cp.envelope) ? Math.round(100 * cp.comprometido / cp.envelope) : 0;
      return `<tr>
        <td>${Modal.esc(cp.horizonte)}</td>
        <td class="text-end">R$ ${this.fmt(cp.envelope)}</td>
        <td class="text-end ${pct > 100 ? 'text-danger fw-bold' : ''}">R$ ${this.fmt(cp.comprometido)} (${pct}%)</td>
      </tr>`;
    }).join('');

    const linhasDecisoes = r.decisoes.map((d) => `<tr>
      <td class="text-nowrap">${this.data(d.decisao_data)}</td>
      <td>${Modal.esc(d.descricao)}<div class="small text-muted">${Modal.esc(d.decisao_criterio || '')}</div></td>
      <td><span class="badge ${d.situacao === 'APROVADO' ? 'text-bg-success' : 'text-bg-danger'}">${d.situacao}</span></td>
      <td class="text-end">R$ ${this.fmt(d.valor)}</td>
    </tr>`).join('');

    const linhasDiario = r.diario.map((d) => `<tr>
      <td class="text-nowrap">${this.data(d.data_reg)}</td>
      <td class="small">${Modal.esc(d.referencia)}</td>
      <td>${Modal.esc(d.texto)}
        ${d.status_atual ? `<span class="badge ${(STATUS_PROJETO[d.status_atual] || ['', 'text-bg-light'])[1]} ms-1">${(STATUS_PROJETO[d.status_atual] || [d.status_atual])[0]}</span>` : ''}
        ${d.progresso !== null ? `<span class="badge text-bg-light border ms-1">${d.progresso}%</span>` : ''}</td>
      <td class="small text-muted">${Modal.esc(d.autor)}</td>
    </tr>`).join('');

    const urlExport = `/api/relatorio/exportar?planejamento_id=${this.plan.id}&de=${this.de}&ate=${this.ate}`;

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 nao-imprimir">
        <h1>Relatório de Status</h1>
        <div class="d-flex align-items-end flex-wrap gap-2">
          <div><label class="form-label small mb-0" for="rel-de">De</label>
            <input type="date" id="rel-de" class="form-control form-control-sm" value="${this.de}"></div>
          <div><label class="form-label small mb-0" for="rel-ate">Até</label>
            <input type="date" id="rel-ate" class="form-control form-control-sm" value="${this.ate}"></div>
          <button class="btn btn-sm btn-outline-secondary" id="rel-atualizar">Atualizar</button>
          <button class="btn btn-sm btn-verde" id="rel-imprimir">Imprimir / PDF</button>
          <a class="btn btn-sm btn-outline-success" href="${urlExport}">Exportar Excel</a>
        </div>
      </div>

      <div class="card mb-3"><div class="card-body py-2 px-3">
        <strong>${Modal.esc(r.rotulo)}</strong> · Ciclo ${Modal.esc(r.ciclo.nome)}
        <div class="small text-muted">Período da reunião: ${this.data(r.periodo.de)} a ${this.data(r.periodo.ate)}
          · Gerado em ${this.data(App.hoje())}</div>
      </div></div>

      <h2 class="h6 mt-3">1. Métricas-âncora</h2>
      ${ancoras.length ? `<div class="table-responsive"><table class="table table-sm table-bordered tabela-metas">
        <thead><tr><th style="min-width:200px">Indicador</th><th></th>
          ${r.anos.map((a) => `<th class="col-ano">${a}</th>`).join('')}</tr></thead>
        <tbody>${linhasIndicadores(ancoras)}</tbody></table></div>`
        : '<p class="text-muted small">Nenhuma métrica-âncora definida.</p>'}

      <h2 class="h6 mt-3">2. Projetos e execução</h2>
      ${r.projetos.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Projeto</th><th>Responsável</th><th>Prazo</th><th>Status</th><th>Progresso</th></tr></thead>
        <tbody>${linhasProjetos}</tbody></table></div>`
        : '<p class="text-muted small">Nenhum projeto cadastrado.</p>'}

      <h2 class="h6 mt-3">3. Capital — envelope × comprometido</h2>
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Horizonte</th><th class="text-end">Envelope</th><th class="text-end">Comprometido</th></tr></thead>
        <tbody>${linhasCapital || '<tr><td colspan="3" class="text-muted">Nenhum envelope definido.</td></tr>'}</tbody></table></div>

      <h2 class="h6 mt-3">4. Decisões de investimento no período</h2>
      ${r.decisoes.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Data</th><th>Investimento · critério</th><th>Decisão</th><th class="text-end">Valor</th></tr></thead>
        <tbody>${linhasDecisoes}</tbody></table></div>`
        : '<p class="text-muted small">Nenhuma decisão registrada no período.</p>'}

      <h2 class="h6 mt-3">5. Diário de bordo do período</h2>
      ${r.diario.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Data</th><th>Referência</th><th>Registro</th><th>Autor</th></tr></thead>
        <tbody>${linhasDiario}</tbody></table></div>`
        : '<p class="text-muted small">Nenhum registro no período.</p>'}`;

    document.getElementById('rel-atualizar').addEventListener('click', () => {
      this.de = document.getElementById('rel-de').value || this.de;
      this.ate = document.getElementById('rel-ate').value || this.ate;
      this.carregar();
    });
    document.getElementById('rel-imprimir').addEventListener('click', () => window.print());
  },
};

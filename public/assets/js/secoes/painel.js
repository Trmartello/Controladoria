// Painel — Fase 6: visão consolidada do ciclo (controladoria/direção: todos os
// negócios + corporativo; gestor: os seus), com avanço da cascata, atrasos e
// envelope × comprometido.

const SecaoPainel = {
  moeda(v) {
    return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  },

  barra(feito, total, titulo = '') {
    const pct = total ? Math.round(100 * feito / total) : 0;
    return `<div class="progress mini-progresso" title="${Modal.esc(titulo)}">
      <div class="progress-bar ${pct >= 100 ? 'bg-success' : 'bg-info'}" style="width:${pct}%"></div>
    </div><span class="small text-muted">${feito}/${total}</span>`;
  },

  async carregar() {
    const el = document.getElementById('secao-painel');
    const ctx = App.contexto;
    if (!ctx.cicloId) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo no menu ☰.</div>';
      return;
    }
    const dados = await App.api(`/api/painel?ciclo_id=${ctx.cicloId}`);
    const { ciclo, linhas, consolidado } = dados;
    const c = consolidado;
    const pctCascata = c.cascata_total ? Math.round(100 * c.cascata_feito / c.cascata_total) : 0;
    const pctCapital = c.envelope ? Math.round(100 * c.comprometido / c.envelope) : 0;

    const cartoes = `
      <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-2 px-3">
          <div class="text-muted small">Avanço da cascata</div>
          <div class="fs-4 fw-bold">${pctCascata}%</div>
          <div class="small text-muted">${c.cascata_feito} de ${c.cascata_total} células</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card h-100 ${c.atrasados ? 'border-danger' : ''}"><div class="card-body py-2 px-3">
          <div class="text-muted small">Projetos com atraso</div>
          <div class="fs-4 fw-bold ${c.atrasados ? 'text-danger' : 'text-success'}">${c.atrasados}</div>
          <div class="small text-muted">${c.concluidos} concluídos de ${c.projetos}</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card h-100 ${pctCapital > 100 ? 'border-danger' : ''}"><div class="card-body py-2 px-3">
          <div class="text-muted small">Envelope × comprometido</div>
          <div class="fs-4 fw-bold">${pctCapital}%</div>
          <div class="small text-muted">R$ ${this.moeda(c.comprometido)} de R$ ${this.moeda(c.envelope)}</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-2 px-3">
          <div class="text-muted small">Métricas-âncora</div>
          <div class="fs-4 fw-bold">${c.ancoras}</div>
          <div class="small text-muted">definidas no ciclo</div>
        </div></div>
      </div>`;

    const linhasTabela = linhas.map((l) => {
      const pctEnv = l.envelope ? Math.round(100 * l.comprometido / l.envelope) : 0;
      return `<tr>
        <td><strong>${Modal.esc(l.rotulo)}</strong></td>
        <td class="d-flex align-items-center gap-2">${this.barra(l.cascata_feito, l.cascata_total, 'Células da cascata preenchidas')}</td>
        <td class="text-center">${l.projetos}</td>
        <td class="text-center ${l.atrasados ? 'text-danger fw-bold' : 'text-muted'}">${l.atrasados}</td>
        <td class="text-center">${l.concluidos}</td>
        <td class="text-end">R$ ${this.moeda(l.envelope)}</td>
        <td class="text-end ${pctEnv > 100 ? 'text-danger fw-bold' : ''}">R$ ${this.moeda(l.comprometido)} <span class="text-muted small">(${pctEnv}%)</span></td>
        <td class="text-center">${l.ancoras}</td>
      </tr>`;
    }).join('');

    el.innerHTML = `
      <h1>Painel — Ciclo ${Modal.esc(ciclo.nome)}</h1>
      <p class="text-muted">${App.sessao.veTudo
        ? 'Visão consolidada: todos os negócios e o planejamento corporativo.'
        : 'Visão dos negócios sob sua gestão.'}</p>
      <div class="row g-3 mb-3">${cartoes}</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle tabela-painel">
          <thead><tr>
            <th>Planejamento</th><th>Cascata</th><th class="text-center">Projetos</th>
            <th class="text-center">Atrasos</th><th class="text-center">Concluídos</th>
            <th class="text-end">Envelope</th><th class="text-end">Comprometido</th>
            <th class="text-center">Âncoras</th>
          </tr></thead>
          <tbody>${linhasTabela || '<tr><td colspan="8" class="text-muted">Nenhum planejamento no seu escopo.</td></tr>'}</tbody>
        </table>
      </div>
      <p class="text-muted small">Detalhes de metas e indicadores na seção <strong>Metas · Indicadores</strong>;
      documento da reunião na seção <strong>Relatório de Status</strong>.</p>`;
  },
};

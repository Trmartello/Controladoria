// Painel — Fase 6: visão consolidada do ciclo (controladoria/direção: todos os
// negócios + corporativo; gestor: os seus), com avanço da cascata, atrasos e
// envelope × comprometido.

const SecaoPainel = {
  moeda(v) {
    return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  },

  nomeMes(m) {
    return ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho',
      'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][m - 1] || m;
  },

  barra(feito, total, titulo = '') {
    const pct = total ? Math.round(100 * feito / total) : 0;
    return `<div class="faixa-progresso mini-progresso" title="${Modal.esc(titulo)}">
      <span style="width:${pct}%"></span>
    </div><span class="small text-muted">${feito}/${total}</span>`;
  },

  /** `12,3%` com sinal, ou vazio quando não há base para comparar. */
  cresc(de, para) {
    if (!de || para === null || para === undefined) return '';
    const v = 100 * (para / de - 1);
    const s = `${v > 0 ? '+' : ''}${v.toLocaleString('pt-BR', {
      minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
    return `<span class="${v < 0 ? 'text-danger' : 'text-muted'}">${s}</span>`;
  },

  /**
   * Os dois cartões da Projeção de Resultados (fatia 4 do tema 13).
   *
   * Eles trazem o PRIMEIRO número financeiro de verdade a esta tela: o
   * envelope de capital aparece em R$ 0 enquanto ninguém lança investimento,
   * e a Direção chega à reunião querendo saber o que o ano dá e o que o ano
   * seguinte promete.
   *
   * O "de N negócios" não é enfeite: consolidado parcial lido como total da
   * cooperativa é o erro silencioso que esta seção inteira evita.
   */
  cartoesProjecao(c) {
    if (!c.ano_projecao || !c.com_projecao) return '';
    const cartao = (rotulo, proj, prop, nota) => `
      <div class="col-6 col-lg-3">
        <div class="card h-100"><div class="card-body py-2 px-3">
          <div class="text-muted small">${rotulo}</div>
          <div class="fs-4 fw-bold">R$ ${this.moeda(proj)}</div>
          <div class="small text-muted">proposta ${c.ano_proposta}:
            R$ ${this.moeda(prop)} ${this.cresc(proj, prop)}</div>
          <div class="small text-muted">${nota}</div>
        </div></div>
      </div>`;
    return cartao(`Receita líquida projetada ${c.ano_projecao}`,
      c.projecao_rol, c.proposta_rol, `de ${c.com_projecao} negócio(s) com DRE`)
      + cartao(`Resultado líquido projetado ${c.ano_projecao}`,
        c.projecao_liquido, c.proposta_liquido, 'depois dos rateios');
  },

  /**
   * Projeção e proposta de uma linha, em duas alturas: a ROL em cima, o
   * resultado líquido embaixo. São os dois números que a Direção pede, e
   * quatro colunas numa tabela que já tem oito seria ilegível.
   *
   * O Corporativo fica com "—": a Projeção é por NEGÓCIO, e somar a ela uma
   * linha corporativa que não existe seria inventar.
   */
  celulaProjecao(l) {
    if (l.projecao_rol === null || l.projecao_rol === undefined) {
      return '<td class="text-end text-muted">—</td><td class="text-end text-muted">—</td>';
    }
    const negativo = (v) => (v < 0 ? 'text-danger' : '');
    return `<td class="text-end">
        <div>${this.moeda(l.projecao_rol)}</div>
        <div class="small ${negativo(l.projecao_liquido)}">${this.moeda(l.projecao_liquido)}</div>
      </td>
      <td class="text-end">
        <div>${this.moeda(l.proposta_rol)} <span class="small">${
  this.cresc(l.projecao_rol, l.proposta_rol)}</span></div>
        <div class="small ${negativo(l.proposta_liquido)}">${this.moeda(l.proposta_liquido)}</div>
      </td>`;
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
      </div>
      ${this.cartoesProjecao(c)}`;

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
        ${this.celulaProjecao(l)}
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
            ${dados.consolidado.ano_projecao ? `
              <th class="text-end">Projeção ${dados.consolidado.ano_projecao}<br>
                <span class="fw-normal text-muted small">ROL · resultado</span></th>
              <th class="text-end">Proposta ${dados.consolidado.ano_proposta}<br>
                <span class="fw-normal text-muted small">ROL · resultado</span></th>` : ''}
          </tr></thead>
          <tbody>${linhasTabela || '<tr><td colspan="10" class="text-muted">Nenhum planejamento no seu escopo.</td></tr>'}</tbody>
        </table>
      </div>
      <p class="text-muted small">Detalhes de metas e indicadores na seção <strong>Metas · Indicadores</strong>;
      documento da reunião na seção <strong>Relatório de Status</strong>.
      ${dados.consolidado.ano_projecao ? `A DRE linha a linha, e a proposta de
      ${dados.consolidado.ano_proposta}, na seção <strong>Projeção de Resultados</strong> —
      realizado até ${this.nomeMes(dados.consolidado.mes_fechado_ate)} e orçamento depois.` : ''}</p>`;
  },
};

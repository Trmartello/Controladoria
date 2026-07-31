// Metas — Fase 6: métricas-âncora por horizonte + tabela plurianual meta × real.

const SecaoMetas = {
  plan: null,
  dados: null,

  fmt(v) {
    return v === null || v === undefined || v === ''
      ? '—'
      : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  },

  async carregar() {
    const el = document.getElementById('secao-metas');
    if (!App.contextoParams()) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    this.dados = await App.api(`/api/indicadores?planejamento_id=${this.plan.id}`);
    const { ciclo, horizontes, indicadores } = this.dados;
    const anos = [];
    for (let a = Number(ciclo.ano_base); a <= Number(ciclo.ano_fim); a++) anos.push(a);

    // ---- Cartões de métrica-âncora por horizonte
    const cartoes = horizontes.map((h) => {
      const ancoras = indicadores.filter((i) => Number(i.metrica_ancora) && i.horizonte_id == h.id);
      const itens = ancoras.map((i) => {
        // Último real lançado × meta do mesmo ano (ou a 1ª meta futura)
        const ultimoReal = i.reais[i.reais.length - 1] || null;
        const anoRef = ultimoReal ? ultimoReal.ano : (i.metas[0] || {}).ano;
        const meta = i.metas.find((m) => m.ano == anoRef);
        const atingiu = ultimoReal && meta
          ? (i.sentido === 'MENOR_MELHOR'
              ? Number(ultimoReal.valor) <= Number(meta.valor)
              : Number(ultimoReal.valor) >= Number(meta.valor))
          : null;
        return `<div class="d-flex justify-content-between align-items-baseline gap-2 mt-1">
          <span class="small">${Modal.esc(i.nome)} <span class="text-muted">(${Modal.esc(i.unidade)})</span></span>
          <span class="text-nowrap small ${atingiu === null ? 'text-muted' : atingiu ? 'text-success fw-bold' : 'text-danger fw-bold'}">
            ${ultimoReal ? `${this.fmt(ultimoReal.valor)} <span class="text-muted">(${ultimoReal.ano})</span>` : 'sem real'}
            ${meta ? ` / meta ${this.fmt(meta.valor)}` : ''}
          </span>
        </div>`;
      }).join('');
      return `<div class="col-md-4">
        <div class="card h-100">
          <div class="card-body py-2 px-3">
            <strong>${Modal.esc(h.nome)} · ${Modal.esc(h.tema)}</strong>
            <span class="badge badge-ancora ms-1">${ancoras.length} âncora${ancoras.length === 1 ? '' : 's'}</span>
            ${itens || '<div class="text-muted small mt-1">Nenhuma métrica-âncora definida para este horizonte.</div>'}
          </div>
        </div>
      </div>`;
    }).join('');

    // ---- Tabela plurianual (linha Meta + linha Real por indicador)
    const linhas = indicadores.map((i) => {
      const metas = Object.fromEntries(i.metas.map((m) => [m.ano, m]));
      const reais = Object.fromEntries(i.reais.map((r) => [r.ano, r.valor]));
      const revisada = i.metas.some((m) => Number(m.versao_meta) > 1);
      const acoes = App.podeEditar() ? `
        <button class="btn btn-sm btn-outline-success" data-valores="${i.id}" data-tipo="META" title="Lançar metas">M</button>
        <button class="btn btn-sm btn-outline-primary" data-valores="${i.id}" data-tipo="REAL" title="Lançar realizados">R</button>
        <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}" title="Editar indicador">✎</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}" title="Excluir">×</button>` : '';
      return `
        <tr>
          <td rowspan="2" class="align-middle">
            <strong>${Modal.esc(i.nome)}</strong>
            ${Number(i.metrica_ancora) ? '<span class="badge badge-ancora ms-1">âncora</span>' : ''}
            ${revisada ? '<span class="badge text-bg-warning ms-1" title="Meta revisada — versões anteriores preservadas">rev.</span>' : ''}
            <div class="small text-muted">${Modal.esc(i.unidade)} · ${i.sentido === 'MENOR_MELHOR' ? '↓ menor melhor' : '↑ maior melhor'}
              ${i.horizonte_nome ? ` · ${Modal.esc(i.horizonte_nome)}` : ''}</div>
          </td>
          <td class="small text-muted">Meta</td>
          ${anos.map((a) => `<td class="valor">${this.fmt(metas[a]?.valor)}</td>`).join('')}
          <td rowspan="2" class="align-middle text-nowrap">${acoes}</td>
        </tr>
        <tr class="linha-real">
          <td class="small text-muted">Real</td>
          ${anos.map((a) => `<td class="valor">${this.fmt(reais[a])}</td>`).join('')}
        </tr>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Metas · Indicadores — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-ind">+ Novo indicador</button>' : ''}
      </div>
      <p class="text-muted">Métricas-âncora dão o foco de cada horizonte; a tabela plurianual acompanha
      meta × real de ${ciclo.ano_base} a ${ciclo.ano_fim}. Revisões de meta preservam as versões anteriores.</p>
      <div class="row g-3 mb-3">${cartoes}</div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle tabela-metas">
          <thead><tr>
            <th style="min-width:220px">Indicador</th><th></th>
            ${anos.map((a) => `<th class="col-ano">${a}</th>`).join('')}
            <th></th>
          </tr></thead>
          <tbody>${linhas || `<tr><td colspan="${anos.length + 3}" class="text-muted">Nenhum indicador cadastrado.</td></tr>`}</tbody>
        </table>
      </div>`;

    if (!App.podeEditar()) return;
    document.getElementById('btn-novo-ind').addEventListener('click', () => this.modalIndicador(null));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      this.modalIndicador(indicadores.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este indicador e todos os seus valores?')) return;
      await App.api(`/api/indicadores/${b.dataset.excluir}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));
    el.querySelectorAll('[data-valores]').forEach((b) => b.addEventListener('click', () =>
      this.modalValores(indicadores.find((i) => i.id == b.dataset.valores), b.dataset.tipo, anos)));
  },

  modalIndicador(ind) {
    const opcoesHorizonte = [{ valor: '', rotulo: '(sem horizonte)' }].concat(
      this.dados.horizontes.map((h) => ({ valor: h.id, rotulo: `${h.nome} · ${h.tema}` })));
    Modal.abrir({
      titulo: ind ? 'Editar indicador' : 'Novo indicador',
      url: ind ? `/api/indicadores/${ind.id}` : '/api/indicadores',
      valores: ind
        ? { ...ind, horizonte_id: ind.horizonte_id ?? '', metrica_ancora: Number(ind.metrica_ancora),
            planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, unidade: 'R$ mil' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'nome', rotulo: 'Indicador', ajuda: 'Ex.: Margem bruta, Cobertura de juros, Armazenagem própria' },
        { nome: 'unidade', rotulo: 'Unidade', ajuda: 'R$ mil, %, x, ton...' },
        { nome: 'sentido', rotulo: 'Sentido', tipo: 'select', opcoes: [
          { valor: 'MAIOR_MELHOR', rotulo: 'Maior é melhor' },
          { valor: 'MENOR_MELHOR', rotulo: 'Menor é melhor' },
        ]},
        { nome: 'horizonte_id', rotulo: 'Horizonte de referência', tipo: 'select', opcoes: opcoesHorizonte },
        { nome: 'metrica_ancora', rotulo: 'Métrica-âncora (destaque no painel do horizonte)', tipo: 'checkbox' },
      ],
    });
  },

  modalValores(ind, tipo, anos) {
    const serie = tipo === 'REAL'
      ? Object.fromEntries(ind.reais.map((r) => [r.ano, r.valor]))
      : Object.fromEntries(ind.metas.map((m) => [m.ano, m.valor]));
    const valores = { planejamento_id: this.plan.id, tipo };
    const campos = [
      { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
      { nome: 'tipo', rotulo: '', tipo: 'hidden' },
    ];
    for (const ano of anos) {
      valores[`ano_${ano}`] = serie[ano] ?? '';
      campos.push({ nome: `ano_${ano}`, rotulo: String(ano), tipo: 'number' });
    }
    if (tipo === 'META') {
      campos.push({ nome: 'nova_versao', rotulo: 'Revisão de meta (preserva a versão anterior)', tipo: 'checkbox' });
    }
    Modal.abrir({
      titulo: `${tipo === 'REAL' ? 'Realizado' : 'Metas'} — ${ind.nome} (${ind.unidade})`,
      url: `/api/indicadores/${ind.id}/valores`,
      valores,
      campos,
      aoSalvar: () => this.carregar(),
      // Reagrupa os campos de ano no corpo JSON esperado pela API
      transformar: (d) => ({
        planejamento_id: d.planejamento_id,
        tipo: d.tipo,
        nova_versao: !!d.nova_versao,
        valores: Object.fromEntries(anos.map((ano) => [ano, d[`ano_${ano}`]])),
      }),
    });
  },
};

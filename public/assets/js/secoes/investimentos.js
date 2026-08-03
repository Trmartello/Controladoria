// Governança de Investimentos — funil: envelope → papel → ranking por taxa
// de retorno → decisão (critério registrado) → auditoria +12M.

const PAPEL_ROTULOS = {
  OBRIGATORIO: 'Obrigatório', MANUTENCAO: 'Manutenção', EFICIENCIA: 'Eficiência',
  CRESCIMENTO: 'Crescimento', ESTRATEGICO: 'Estratégico',
};
const SITUACAO_ROTULOS = {
  PROPOSTO: ['Proposto', 'text-bg-light border'],
  RANQUEADO: ['Ranqueado', 'text-bg-info'],
  APROVADO: ['Aprovado', 'text-bg-success'],
  REPROVADO: ['Reprovado', 'text-bg-danger'],
  EXECUTADO: ['Executado', 'text-bg-primary'],
  AUDITADO: ['Auditado', 'text-bg-dark'],
};
const moeda = (v) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 0 });

const SecaoInvestimentos = {
  plan: null,
  dados: null,

  async carregar() {
    const el = document.getElementById('secao-investimentos');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    this.dados = await App.api(`/api/investimentos?planejamento_id=${this.plan.id}`);
    const { horizontes, envelopes, investimentos } = this.dados;

    // ---- Envelopes por horizonte (com comprometido = aprovado+executado+auditado)
    const cartoesEnvelope = horizontes.map((h) => {
      const env = envelopes.find((e) => e.horizonte_id == h.id);
      const comprometido = investimentos
        .filter((i) => i.horizonte_id == h.id && ['APROVADO', 'EXECUTADO', 'AUDITADO'].includes(i.situacao))
        .reduce((s, i) => s + Number(i.valor), 0);
      const limite = env ? Number(env.valor_limite) : 0;
      const pct = limite ? Math.min(100, Math.round(100 * comprometido / limite)) : 0;
      return `<div class="col-md-4">
        <div class="card h-100 ${env ? 'border-success' : ''}">
          <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center">
              <strong>${Modal.esc(h.nome)} · ${Modal.esc(h.tema)}</strong>
              ${App.podeEditar() ? `<button class="btn btn-sm btn-outline-secondary" data-envelope="${h.id}">${env ? 'Editar' : 'Definir'}</button>` : ''}
            </div>
            ${env ? `
              <div class="small mt-1">Envelope: <strong>R$ ${moeda(limite)}</strong>
                ${Number(env.flex_percentual) ? `<span class="badge badge-horizonte">±${Number(env.flex_percentual)}%</span>` : ''}</div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div class="faixa-progresso flex-grow-1 ${pct >= 100 ? 'alerta' : ''}"
                  title="Comprometido R$ ${moeda(comprometido)}">
                  <span style="width:${Math.min(100, pct)}%"></span>
                </div>
                <span class="valor-progresso ${pct >= 100 ? 'text-danger' : ''}">${pct}%</span>
              </div>
              <div class="small text-muted mt-1">Comprometido: R$ ${moeda(comprometido)} · Disponível: R$ ${moeda(Math.max(0, limite - comprometido))}</div>
              ${env.regras ? `<div class="small text-muted mt-1"><strong>Guard-rails:</strong> ${Modal.esc(env.regras)}</div>` : ''}
            ` : '<div class="text-muted small mt-1">Envelope não definido — quanto há para este horizonte?</div>'}
          </div>
        </div>
      </div>`;
    }).join('');

    // ---- Ranking agrupado por papel (papel agrupa antes de ordenar)
    const grupos = ['OBRIGATORIO', 'MANUTENCAO', 'EFICIENCIA', 'CRESCIMENTO', 'ESTRATEGICO', null]
      .map((papel) => {
        const doGrupo = investimentos
          .filter((i) => (papel === null ? !i.papel : i.papel === papel))
          .sort((a, b) => (b.taxa_retorno === null ? -1 : Number(b.taxa_retorno))
            - (a.taxa_retorno === null ? -1 : Number(a.taxa_retorno)));
        if (!doGrupo.length) return '';
        const linhas = doGrupo.map((i, idx) => {
          const [rotulo, classe] = SITUACAO_ROTULOS[i.situacao] || [i.situacao, 'text-bg-light'];
          const decisao = i.decisao_criterio
            ? `<div class="small text-muted">Decisão ${i.decisao_data ? i.decisao_data.split('-').reverse().join('/') : ''}: ${Modal.esc(i.decisao_criterio)}</div>` : '';
          const auditoria = i.situacao === 'AUDITADO'
            ? `<div class="small text-muted">Auditoria: prometido R$ ${moeda(i.valor)} × realizado R$ ${moeda(i.valor_realizado)}${i.auditoria_nota ? ` — ${Modal.esc(i.auditoria_nota)}` : ''}</div>` : '';
          return `<tr>
            <td>${i.taxa_retorno !== null ? `<strong>${idx + 1}º</strong>` : '—'}</td>
            <td class="small">${Modal.esc(i.descricao)}
              ${i.projeto_titulo ? `<div class="text-muted">↳ ${Modal.esc(i.projeto_titulo.slice(0, 60))}</div>` : ''}
              ${decisao}${auditoria}</td>
            <td>${i.horizonte_nome ? Modal.esc(i.horizonte_nome) : '—'}</td>
            <td>${i.ano}</td>
            <td class="text-end">R$ ${moeda(i.valor)}</td>
            <td class="text-center">${i.taxa_retorno !== null ? `${Number(i.taxa_retorno).toFixed(1)}%` : '—'}</td>
            <td><span class="badge ${classe}">${rotulo}</span></td>
            <td class="text-nowrap">${App.podeEditar() ? `
              ${['PROPOSTO', 'RANQUEADO'].includes(i.situacao) ? `<button class="btn btn-sm btn-outline-success" data-decidir="${i.id}">Decidir</button>` : ''}
              ${['APROVADO', 'EXECUTADO'].includes(i.situacao) ? `<button class="btn btn-sm btn-outline-dark" data-auditar="${i.id}">Auditar</button>` : ''}
              <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}">Editar</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}"
                title="Excluir investimento" aria-label="Excluir investimento">×</button>` : ''}</td>
          </tr>`;
        }).join('');
        const total = doGrupo.reduce((s, i) => s + Number(i.valor), 0);
        return `<tbody>
          <tr class="table-light"><th colspan="4">${papel ? PAPEL_ROTULOS[papel] : 'Sem papel definido'}</th>
            <th class="text-end">R$ ${moeda(total)}</th><th colspan="3"></th></tr>
          ${linhas}
        </tbody>`;
      }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Governança de Investimentos — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-inv">+ Novo investimento</button>' : ''}
      </div>
      <p class="text-muted">Envelope (quanto há) → papel (agrupa antes de ordenar) → ranking por taxa
      de retorno → decisão com critério registrado → auditoria +12M. <em>A cascata dá direção, não aprovação.</em></p>
      <div class="row g-3 mb-3">${cartoesEnvelope}</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Rank</th><th>Investimento</th><th>Horizonte</th><th>Ano</th>
            <th class="text-end">Valor (R$)</th><th class="text-center">Taxa retorno</th><th>Situação</th><th></th></tr></thead>
          ${grupos || '<tbody><tr><td colspan="8" class="text-muted">Nenhum investimento proposto.</td></tr></tbody>'}
        </table>
      </div>`;

    if (!App.podeEditar()) return;
    const { investimentos: lista } = this.dados;

    document.getElementById('btn-novo-inv').addEventListener('click', () => this.modalInvestimento(null));
    el.querySelectorAll('[data-envelope]').forEach((b) => b.addEventListener('click', () =>
      this.modalEnvelope(parseInt(b.dataset.envelope, 10))));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      this.modalInvestimento(lista.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este investimento?')) return;
      await App.api(`/api/investimentos/${b.dataset.excluir}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));
    el.querySelectorAll('[data-decidir]').forEach((b) => b.addEventListener('click', () => Modal.abrir({
      titulo: 'Decisão sobre o investimento',
      url: `/api/investimentos/${b.dataset.decidir}/decidir`,
      valores: { planejamento_id: this.plan.id, decisao_data: App.hoje() },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'situacao', rotulo: 'Decisão', tipo: 'select', opcoes: [
          { valor: 'APROVADO', rotulo: 'Aprovar' },
          { valor: 'REPROVADO', rotulo: 'Reprovar' },
        ]},
        { nome: 'decisao_criterio', rotulo: 'Critério da decisão (obrigatório)', tipo: 'textarea', linhas: 3,
          ajuda: 'Registre o porquê — retorno, guard-rail, condição do envelope...' },
        { nome: 'decisao_data', rotulo: 'Data da decisão', tipo: 'date' },
      ],
    })));
    el.querySelectorAll('[data-auditar]').forEach((b) => b.addEventListener('click', () => {
      const inv = lista.find((i) => i.id == b.dataset.auditar);
      Modal.abrir({
        titulo: 'Auditoria +12M — prometido × realizado',
        url: `/api/investimentos/${inv.id}/auditar`,
        valores: { planejamento_id: this.plan.id, valor_realizado: inv.valor },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'valor_realizado', rotulo: `Valor realizado (prometido: R$ ${moeda(inv.valor)})`, tipo: 'number' },
          { nome: 'auditoria_nota', rotulo: 'Nota da auditoria', tipo: 'textarea', linhas: 3 },
        ],
      });
    }));
  },

  modalEnvelope(horizonteId) {
    const env = this.dados.envelopes.find((e) => e.horizonte_id == horizonteId);
    const h = this.dados.horizontes.find((x) => x.id == horizonteId);
    Modal.abrir({
      titulo: `Envelope de capital — ${h.nome} (${h.ano_inicio}–${h.ano_fim})`,
      url: env ? `/api/envelopes/${env.id}` : '/api/envelopes',
      valores: env
        ? { ...env, planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, horizonte_id: horizonteId, flex_percentual: 0 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
        { nome: 'valor_limite', rotulo: 'Valor limite (R$)', tipo: 'number' },
        { nome: 'flex_percentual', rotulo: 'Flexibilidade (%)', tipo: 'number',
          ajuda: 'Ex.: 5 = travado ±5% (H1) · 20 (H2) · 40 (H3)' },
        { nome: 'regras', rotulo: 'Guard-rails / condições', tipo: 'textarea', linhas: 2,
          ajuda: 'Ex.: só se ROIC > WACC e Gate 4 ≥ 80%' },
      ],
    });
  },

  modalInvestimento(inv) {
    const opcoesHorizonte = [{ valor: '', rotulo: '(sem horizonte)' }].concat(
      this.dados.horizontes.map((h) => ({ valor: h.id, rotulo: `${h.nome} · ${h.ano_inicio}–${h.ano_fim}` })));
    Modal.abrir({
      titulo: inv ? 'Editar investimento' : 'Novo investimento',
      url: inv ? `/api/investimentos/${inv.id}` : '/api/investimentos',
      valores: inv
        ? { ...inv, papel: inv.papel ?? '', horizonte_id: inv.horizonte_id ?? '',
            projeto_id: inv.projeto_id ?? '', taxa_retorno: inv.taxa_retorno ?? '',
            planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, ano: new Date().getFullYear() + 1 },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'descricao', rotulo: 'Investimento', tipo: 'textarea', linhas: 2 },
        { nome: 'papel', rotulo: 'Papel (agrupa antes de ordenar)', tipo: 'select', opcoes: [
          { valor: '', rotulo: '(definir depois)' },
          ...Object.entries(PAPEL_ROTULOS).map(([valor, rotulo]) => ({ valor, rotulo })),
        ]},
        { nome: 'ano', rotulo: 'Ano', tipo: 'number' },
        { nome: 'valor', rotulo: 'Valor estimado (R$)', tipo: 'number' },
        { nome: 'taxa_retorno', rotulo: 'Taxa de retorno estimada (%)', tipo: 'number',
          ajuda: 'Base do ranking — retorno por real investido' },
        { nome: 'horizonte_id', rotulo: 'Horizonte', tipo: 'select', opcoes: opcoesHorizonte },
        // Situação só é editável antes da decisão; APROVADO pode avançar para
        // EXECUTADO. EXECUTADO/REPROVADO/AUDITADO não mudam por edição — chegar
        // a EXECUTADO exige ter passado pela decisão, e voltar de lá apagaria um
        // investimento já comprometido do painel.
        ...(inv && ['PROPOSTO', 'RANQUEADO'].includes(inv.situacao) ? [{
          nome: 'situacao', rotulo: 'Situação', tipo: 'select', opcoes: [
            { valor: 'PROPOSTO', rotulo: 'Proposto' },
            { valor: 'RANQUEADO', rotulo: 'Ranqueado' },
          ]}] : []),
        ...(inv && inv.situacao === 'APROVADO' ? [{
          nome: 'situacao', rotulo: 'Situação', tipo: 'select', opcoes: [
            { valor: 'APROVADO', rotulo: 'Aprovado' },
            { valor: 'EXECUTADO', rotulo: 'Executado' },
          ]}] : []),
      ],
    });
  },
};

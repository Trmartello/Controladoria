// Painel — Fase 1: visão do contexto selecionado (evolui nas próximas fases).

const SecaoPainel = {
  async carregar() {
    const el = document.getElementById('secao-painel');
    const ctx = App.contexto;
    if (!ctx.cicloId || (!ctx.negocioId && !ctx.corporativo)) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu lateral.</div>';
      return;
    }
    const params = ctx.corporativo
      ? `ciclo_id=${ctx.cicloId}&escopo=CORPORATIVO`
      : `ciclo_id=${ctx.cicloId}&negocio_id=${ctx.negocioId}`;
    const dados = await App.api(`/api/contexto?${params}`);
    const total = dados.checklist.length;
    const iniciadas = dados.checklist.filter((e) => e.itens > 0).length;

    const rotulo = ctx.corporativo
      ? 'Corporativo'
      : App.sessao.negocios.find((n) => n.id === ctx.negocioId)?.rotulo || '';

    el.innerHTML = `
      <h1>Painel — ${Modal.esc(rotulo)}</h1>
      <div class="row g-3 mt-1">
        <div class="col-sm-4">
          <div class="card"><div class="card-body">
            <div class="text-muted small">Etapas do método iniciadas</div>
            <div class="fs-3 fw-bold">${iniciadas} / ${total}</div>
          </div></div>
        </div>
        <div class="col-sm-8">
          <div class="card"><div class="card-body">
            <div class="text-muted small">Próximo passo</div>
            <div>${iniciadas === 0
              ? 'Comece pela <strong>Análise de Cenário</strong> no Hub do Planejamento.'
              : 'Continue pelo <strong>Hub do Planejamento</strong> — as etapas de diagnóstico chegam na Fase 2.'}</div>
          </div></div>
        </div>
      </div>
      <p class="text-muted small mt-3">Os painéis consolidados (avanço da cascata, atrasos e envelope
      × comprometido) serão ativados conforme as fases 2–6 forem entregues.</p>`;
  },
};

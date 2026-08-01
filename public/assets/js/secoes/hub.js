// Hub do Planejamento: resolve o contexto e mostra o checklist do método.

const SecaoHub = {
  async carregar() {
    const el = document.getElementById('secao-hub');
    const ctx = App.contexto;
    if (!ctx.cicloId || (!ctx.negocioId && !ctx.corporativo)) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    const params = ctx.corporativo
      ? `ciclo_id=${ctx.cicloId}&escopo=CORPORATIVO`
      : `ciclo_id=${ctx.cicloId}&negocio_id=${ctx.negocioId}`;
    const dados = await App.api(`/api/contexto?${params}`);

    const rotulo = ctx.corporativo
      ? 'Corporativo'
      : App.sessao.negocios.find((n) => n.id === ctx.negocioId)?.rotulo || '';

    const cartoes = dados.checklist.map((etapa) => {
      const feito = etapa.itens > 0;
      const meta = etapa.meta ? ` / ${etapa.meta}` : '';
      // O cartão inteiro é um atalho para a etapa correspondente
      return `<div class="col-md-4">
        <div class="card cartao-etapa ${feito ? '' : 'pendente'}"
          data-ir-secao="${etapa.secao}" role="button" tabindex="0"
          title="Abrir ${Modal.esc(etapa.etapa)}">
          <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <span>${Modal.esc(etapa.etapa)}</span>
              <span class="badge ${feito ? 'text-bg-success' : 'text-bg-light'}">${etapa.itens}${meta}</span>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');

    el.innerHTML = `
      <h1>Hub do Planejamento — ${Modal.esc(rotulo)}</h1>
      <p class="text-muted">O método segue as etapas abaixo. Cada cartão mostra a quantidade de
      itens registrados; toque no cartão para abrir a etapa.</p>
      <div class="row g-2">${cartoes}</div>`;

    el.querySelectorAll('[data-ir-secao]').forEach((c) => {
      const abrir = () => App.mostrarSecao(c.dataset.irSecao);
      c.addEventListener('click', abrir);
      c.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrir(); }
      });
    });
  },
};

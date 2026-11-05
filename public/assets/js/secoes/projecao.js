/**
 * Projeção de Resultados — a DRE do ano na tela (fatia 1 do tema 13).
 *
 * Três colunas e uma conta: o REALIZADO dos meses fechados, o PROJETADO dos
 * meses que faltam (o orçamento lançado para eles) e a PROJEÇÃO do ano, que é
 * a soma. A proposta do ano de planejamento entra nas fatias seguintes; aqui a
 * tela é só de leitura, e já serve: é esta DRE que hoje circula em planilha
 * anexada no e-mail antes da reunião.
 *
 * Decisões que não devem ser desfeitas:
 *
 * - **A.V. é sobre a ROL**, não sobre a receita bruta. É a convenção da
 *   controladoria e é o que a planilha-maquete faz (`D9/D$15`). Trocar o
 *   denominador muda toda linha da tela sem mudar nada no banco.
 * - **As linhas de percentual (Margem Bruta, Desp./ROL, Margem Líquida) são
 *   CALCULADAS aqui.** Na extração elas vêm zeradas — são fórmula de tela no
 *   próprio Qlik. Exibir o valor guardado mostraria 0,00% em três linhas.
 * - **No celular a tabela não cabe.** Em vez de rolar sete colunas, o celular
 *   mostra os blocos (nível 1) com Realizado · Projetado · Projeção, e as
 *   subcontas abrem por toque.
 */
const SecaoProjecao = {
  negocioId: null,
  abertos: {},

  moeda(v) {
    return Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  },

  pct(v) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return `${(100 * v).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
  },

  nomeMes(m) {
    return ['jan', 'fev', 'mar', 'abr', 'mai', 'jun',
      'jul', 'ago', 'set', 'out', 'nov', 'dez'][m - 1] || m;
  },

  async carregar() {
    const el = document.getElementById('secao-projecao');
    const q = this.negocioId ? `?negocio_id=${this.negocioId}` : '';
    const d = await App.api(`/api/projecao${q}`);

    if (d.sem_carga || !d.negocios.length) {
      el.innerHTML = `<h1>Projeção de Resultados</h1>
        <div class="alert alert-info">${d.sem_carga
    ? 'Nenhuma DRE carregada ainda. A carga vem do Qlik pela controladoria, no deploy.'
    : 'Nenhum negócio com DRE carregada no seu escopo.'}</div>`;
      return;
    }
    this.negocioId = d.negocio_id;

    const linhaRol = d.linhas.find((l) => l.codigo === '3') || {};
    const rol = linhaRol.projecao || 0;
    // As três linhas de percentual, pelo código da conta. O denominador é
    // sempre a ROL — inclusive na margem líquida, que é como a planilha faz.
    const CALCULADAS = { 6: '5', 8: '7', 17: '16' };

    // Cada coluna tem a SUA ROL: a margem do realizado é sobre a receita
    // realizada, a do projetado sobre a projetada. Usar a ROL do ano inteiro
    // nas três daria uma margem de realizado diluída por meses que ainda não
    // aconteceram — número plausível e errado.
    const valor = (linha, campo) => {
      if (!linha.percentual) return this.moeda(linha[campo]);
      const base = linhaRol[campo] || 0;
      if (!base) return '—';
      const alvo = d.linhas.find((x) => x.codigo === CALCULADAS[linha.codigo]);
      return alvo ? this.pct(alvo[campo] / base) : '—';
    };

    const av = (linha) => {
      if (linha.percentual || !rol) return '';
      return this.pct(linha.projecao / rol);
    };

    const linhasHtml = d.linhas.map((l) => {
      const bloco = l.nivel === 1;
      const classe = bloco ? 'fw-bold linha-bloco' : 'linha-subconta';
      const negativo = (v) => (v < 0 ? 'text-danger' : '');
      return `<tr class="${classe}" data-conta="${Modal.esc(l.codigo)}" data-nivel="${l.nivel}">
        <td class="${bloco ? '' : 'ps-4 text-muted'}">${Modal.esc(l.nome)}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.realizado)}">${valor(l, 'realizado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projetado)}">${valor(l, 'projetado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projecao)}">${valor(l, 'projecao')}</td>
        <td class="text-end text-muted small">${av(l)}</td>
      </tr>`;
    }).join('');

    const opcoes = d.negocios.map((n) => `<option value="${n.id}"${
      Number(n.id) === Number(d.negocio_id) ? ' selected' : ''}>${Modal.esc(n.rotulo)}</option>`).join('');

    const faltando = d.meses_sem_meta.length
      ? `<div class="alert alert-warning py-2 px-3 small mb-3">
           <strong>Projeção incompleta.</strong> Não há orçamento lançado para
           ${d.meses_sem_meta.map((m) => this.nomeMes(m)).join(', ')} de ${d.ano} —
           esses meses entram como zero na coluna Projetado.
         </div>`
      : '';

    el.innerHTML = `
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="mb-0 me-auto">Projeção de Resultados — ${d.ano}</h1>
        <select id="projecao-negocio" class="form-select form-select-sm" style="max-width:18rem"
          aria-label="Negócio">${opcoes}</select>
      </div>
      <p class="text-muted">
        Realizado de <strong>jan a ${this.nomeMes(d.mes_fechado_ate)}</strong> (meses fechados) e
        projetado de <strong>${this.nomeMes(d.mes_fechado_ate + 1)} a dez</strong> pelo orçamento
        lançado. A proposta de <strong>${d.ano_planejamento}</strong> entra na próxima etapa.
      </p>
      ${faltando}
      <div class="table-responsive tabela-projecao">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Conta</th>
            <th class="text-end">Realizado<br><span class="fw-normal text-muted small">jan–${this.nomeMes(d.mes_fechado_ate)}</span></th>
            <th class="text-end">Projetado<br><span class="fw-normal text-muted small">${this.nomeMes(d.mes_fechado_ate + 1)}–dez</span></th>
            <th class="text-end">Projeção<br><span class="fw-normal text-muted small">do ano</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL</span></th>
          </tr></thead>
          <tbody>${linhasHtml}</tbody>
        </table>
      </div>
      <p class="text-muted small">
        Valores em R$. A.V. = análise vertical sobre a Receita Operacional Líquida.
        Fonte: ${Modal.esc(d.fonte || 'Qlik')}${d.extraido_em
  ? `, extraído em ${d.extraido_em.split('-').reverse().join('/')}` : ''}.
        Mês sem rateio é mês aberto e não entra como realizado.
      </p>`;

    el.querySelector('#projecao-negocio').addEventListener('change', (ev) => {
      this.negocioId = Number(ev.target.value);
      this.carregar();
    });
  },
};

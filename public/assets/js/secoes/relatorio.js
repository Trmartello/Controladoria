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
  reunioes: [],
  de: null,
  ate: null,

  /**
   * Uma seção numerada do documento. O título vai no `<thead>` do bloco: se as
   * linhas da seção atravessarem a quebra de página, o navegador repete
   * "3. Capital — envelope × comprometido" no topo da folha seguinte, em vez de
   * despejar uma tabela sem nome. Na tela o bloco não existe.
   */
  secao(titulo, corpo) {
    return RelatorioAnalise.bloco({
      cabecalho: `<h2 class="h6 mt-3">${titulo}</h2>`,
      corpo,
    });
  },

  fmt(v) {
    return v === null || v === undefined || v === ''
      ? '—'
      : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  },
  data(iso) {
    return iso ? iso.split('-').reverse().join('/') : '';
  },

  /**
   * Ata da reunião de acompanhamento. O período vem pré-preenchido com o que
   * está na tela — é o relatório que serviu de pauta.
   */
  modalReuniao(m) {
    Modal.abrir({
      titulo: m ? 'Editar registro da reunião' : 'Registrar reunião',
      url: m ? `/api/reunioes/${m.id}` : '/api/reunioes',
      valores: m
        ? { ...m, planejamento_id: this.plan.id }
        : {
            planejamento_id: this.plan.id, data_reuniao: App.hoje(),
            periodo_de: this.de, periodo_ate: this.ate,
          },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'data_reuniao', rotulo: 'Data da reunião', tipo: 'date', obrigatorio: true },
        { nome: 'periodo_de', rotulo: 'Período discutido — de', tipo: 'date', obrigatorio: true },
        { nome: 'periodo_ate', rotulo: 'Período discutido — até', tipo: 'date', obrigatorio: true },
        { nome: 'participantes', rotulo: 'Quem participou', tipo: 'textarea', linhas: 2,
          exemplo: 'Direção, controladoria e gestores dos negócios' },
        { nome: 'decisoes', rotulo: 'O que foi decidido', tipo: 'textarea', linhas: 4, obrigatorio: true },
        { nome: 'proximos_passos', rotulo: 'Próximos passos', tipo: 'textarea', linhas: 3,
          ajuda: 'O que ficou combinado até a próxima reunião.' },
      ],
    });
  },

  async carregar() {
    const el = document.getElementById('secao-relatorio');
    if (!App.contextoParams()) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    this.ate = this.ate || App.hoje();
    this.de = this.de || App.hoje(30);

    const [r, reunioes] = await Promise.all([
      App.api(`/api/relatorio?planejamento_id=${this.plan.id}&de=${this.de}&ate=${this.ate}`),
      App.api(`/api/reunioes?planejamento_id=${this.plan.id}`),
    ]);
    this.reunioes = reunioes;

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
        <td style="min-width:110px"><div class="faixa-progresso mini-progresso">
          <span style="width:${p.progresso}%"></span>
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

    // A ata responde "quando foi a última, quem estava, o que se decidiu" —
    // hoje isso ou não existe ou está espalhado no diário de cada projeto
    const cartoesReuniao = reunioes.slice(0, 5).map((m) => `
      <div class="card mb-2"><div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong class="small">${this.data(m.data_reuniao)}</strong>
          <span class="badge text-bg-light border">período ${this.data(m.periodo_de)} a ${this.data(m.periodo_ate)}</span>
          <span class="small text-muted flex-grow-1">registrada por ${Modal.esc(m.autor)}</span>
          ${App.podeEditar() ? `
            <button class="btn btn-sm btn-outline-secondary" data-editar-reuniao="${m.id}"
              title="Editar reunião" aria-label="Editar reunião">✎</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir-reuniao="${m.id}"
              title="Excluir reunião" aria-label="Excluir reunião">×</button>` : ''}
        </div>
        ${m.participantes ? `<div class="small mt-1"><strong>Participantes:</strong> ${Modal.esc(m.participantes)}</div>` : ''}
        <div class="small mt-1"><strong>Decisões:</strong> ${Modal.esc(m.decisoes)}</div>
        ${m.proximos_passos ? `<div class="small mt-1"><strong>Próximos passos:</strong> ${Modal.esc(m.proximos_passos)}</div>` : ''}
      </div></div>`).join('');

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
          ${App.podeEditar()
            ? '<button class="btn btn-sm btn-verde" id="rel-nova-reuniao">Registrar reunião</button>' : ''}
          ${App.sessao.usuario.perfil === 'ADMIN'
            ? '<button class="btn btn-sm btn-outline-secondary" id="rel-avisos">Enviar avisos por e-mail</button>' : ''}
        </div>
      </div>

      ${RelatorioAnalise.canvas({
        // O cartão de contexto é o cabeçalho REPETIDO em toda folha impressa —
        // na tela ele segue sendo o mesmo cartão do topo. Sem isso, quem pegava
        // a página 3 não sabia de que relatório, de que negócio nem de que
        // período ela era: o `<h1>` fica na barra de comandos, que não imprime.
        cabecalho: `<div class="card mb-3"><div class="card-body py-2 px-3">
          <strong><span class="somente-impressao">Relatório de Status — </span>${Modal.esc(r.rotulo)}</strong>
            · Ciclo ${Modal.esc(r.ciclo.nome)}
          <div class="small text-muted">Período da reunião: ${this.data(r.periodo.de)} a ${this.data(r.periodo.ate)}
            · Gerado em ${this.data(App.hoje())}</div>
        </div></div>`,
        corpo: `
        ${this.secao('1. Métricas-âncora',
          ancoras.length ? `<div class="table-responsive"><table class="table table-sm table-bordered tabela-metas">
            <thead><tr><th style="min-width:200px">Indicador</th><th></th>
              ${r.anos.map((a) => `<th class="col-ano">${a}</th>`).join('')}</tr></thead>
            <tbody>${linhasIndicadores(ancoras)}</tbody></table></div>`
            : '<p class="text-muted small">Nenhuma métrica-âncora definida.</p>')}

        ${this.secao('2. Projetos e execução',
          r.projetos.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Projeto</th><th>Responsável</th><th>Prazo</th><th>Status</th><th>Progresso</th></tr></thead>
            <tbody>${linhasProjetos}</tbody></table></div>`
            : '<p class="text-muted small">Nenhum projeto cadastrado.</p>')}

        ${this.secao('3. Capital — envelope × comprometido',
          `<div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Horizonte</th><th class="text-end">Envelope</th><th class="text-end">Comprometido</th></tr></thead>
            <tbody>${linhasCapital || '<tr><td colspan="3" class="text-muted">Nenhum envelope definido.</td></tr>'}</tbody></table></div>`)}

        ${this.secao('4. Decisões de investimento no período',
          r.decisoes.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Data</th><th>Investimento · critério</th><th>Decisão</th><th class="text-end">Valor</th></tr></thead>
            <tbody>${linhasDecisoes}</tbody></table></div>`
            : '<p class="text-muted small">Nenhuma decisão registrada no período.</p>')}

        ${this.secao('5. Diário de bordo do período',
          r.diario.length ? `<div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Data</th><th>Referência</th><th>Registro</th><th>Autor</th></tr></thead>
            <tbody>${linhasDiario}</tbody></table></div>`
            : '<p class="text-muted small">Nenhum registro no período.</p>')}

        ${this.secao('6. Últimas reuniões de acompanhamento',
          cartoesReuniao || `<p class="text-muted small">Nenhuma reunião registrada.
            ${App.podeEditar() ? 'Use “Registrar reunião” depois do encontro para guardar decisões e próximos passos.' : ''}</p>`)}`,
      })}`;

    // Os dois únicos campos de data fora de um modal no sistema; sem isto o
    // navegador desenha 02/07 como "07/02" e a tela contradiz o resto
    Modal.ligarDatasBr(el);

    document.getElementById('rel-atualizar').addEventListener('click', () => {
      this.de = document.getElementById('rel-de').value || this.de;
      this.ate = document.getElementById('rel-ate').value || this.ate;
      this.carregar();
    });
    document.getElementById('rel-imprimir').addEventListener('click', () => window.print());

    document.getElementById('rel-nova-reuniao')?.addEventListener('click', () => this.modalReuniao(null));
    el.querySelectorAll('[data-editar-reuniao]').forEach((b) => b.addEventListener('click', () =>
      this.modalReuniao(this.reunioes.find((m) => m.id == b.dataset.editarReuniao))));
    el.querySelectorAll('[data-excluir-reuniao]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir o registro desta reunião?')) return;
      try {
        await App.api(`/api/reunioes/${b.dataset.excluirReuniao}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    // Dispara na hora o mesmo pacote do agendamento (sem repetir o que já saiu)
    document.getElementById('rel-avisos')?.addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      b.disabled = true;
      const antes = b.textContent;
      b.textContent = 'Enviando...';
      try {
        const r = await App.api('/api/avisos/despachar', {});
        const resumo = Object.entries(r)
          .map(([q, x]) => `${q}: ${x.enviados} enviado(s), ${x.falhas} falha(s), ${x.ja_enviados} já enviado(s)`)
          .join('\n');
        alert(resumo || 'Nada previsto para hoje.');
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
        b.textContent = antes;
      }
    });
  },
};

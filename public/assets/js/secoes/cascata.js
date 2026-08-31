// Cascata de Escolhas: matriz drivers (linhas bases) × horizontes.
// Clique na célula abre o detalhe com a síntese + aberturas por eixo
// (cada uma com escolha e renúncia); edições via modal.
//
// O preenchimento pode ser COLABORATIVO: o condutor abre uma sessão (rodada
// modo CASCATA — a mesma sala da tempestade, com PIN e QR) e pergunta a célula
// à sala. As sugestões chegam ao vivo em duas colunas (Respostas e Renúncias)
// e o condutor as USA na célula: muitas vozes vinculadas, um texto por lado.

const SecaoCascata = {
  dados: null,
  plan: null,
  celulaAberta: null, // { horizonteId, driverId }
  quiz: null,         // estado da sessão do quiz (ou {sessao:null})
  relogioQuiz: null,
  assinaturaQuiz: null,
  // Estado visual da faixa compartilhada (QuizSala): sem guardar, cada batida
  // do polling reconstruía a faixa e RECOLHIA o QR projetado no telão
  quizUi: { qrAberto: false, roteiroAberto: false },
  secaoId: 'secao-cascata',
  // Pergunta em FOCO: a que o condutor está examinando pelo roteiro. Navegar
  // é local — só ativar/reabrir/encerrar mexe no celular da sala.
  perguntaFoco: null,
  // Cartões marcados com "Usado", por pergunta: { [perguntaId]: {mais, menos} }.
  // A verdade do servidor é o vínculo já salvo (`vinculada`); estes dois
  // conjuntos são a INTENÇÃO ainda não salva — o que o condutor acabou de
  // marcar e o que ele acabou de desmarcar. Guardar só uma lista pronta faria
  // a marca de um segundo condutor (que chega pelo polling) sumir da tela.
  usoQuiz: {},

  /**
   * Aba aberta: `escolhas` (a matriz de decisão) ou `execucao` (a Matriz de
   * Execução). Mora no dono, como todo estado de vista desta tela, para
   * sobreviver às repinturas do polling — quem estivesse lendo a execução
   * durante um encontro veria a tela voltar sozinha para a outra aba.
   */
  aba: 'escolhas',

  /** Horizonte lido na Matriz de Execução; `null` = o primeiro do ciclo. */
  horizonteMatriz: null,

  /**
   * Uma cor por horizonte, para o relatório separar as fases de relance. A
   * matriz da tela não precisa disso — ali o horizonte é a COLUNA, e a posição
   * já diz qual é. No documento as fases viram seções empilhadas, e sem cor
   * nenhuma três blocos iguais se leem como um só.
   */
  CORES_HORIZONTE: ['#007a45', '#2c7fb8', '#b08d4f', '#8f3b3b'],

  /**
   * O que já foi PREENCHIDO, agrupado por horizonte — a matéria-prima do
   * relatório nos dois formatos (o `.doc` e o papel).
   *
   * Célula vazia não entra. O pedido é o relatório do que já foi decidido, e uma
   * lista de "— definir síntese —" no meio das decisões esconderia justamente o
   * que existe. Quanto FALTA já está dito nos dois contadores do cabeçalho, que
   * vão no contexto do documento.
   *
   * Lê `this.dados` a cada chamada, nunca uma cópia: numa oficina a matriz muda
   * embaixo da tela a cada batida do polling, e um relatório montado na pintura
   * sairia com o texto de dez minutos atrás.
   */
  relatorioSecoes() {
    const { horizontes, drivers, eixos, escolhas } = this.dados || {};
    if (!horizontes) return [];
    return horizontes.map((h, i) => {
      const itens = [];
      drivers.forEach((d) => {
        const daCelula = (eixoId) => escolhas.find((e) =>
          e.driver_id == d.id && e.horizonte_id == h.id
          && (eixoId ? e.eixo_id == eixoId : !e.eixo_id));
        // A síntese primeiro, as aberturas depois — a ordem em que a célula é
        // lida na tela, e a ordem em que a decisão foi tomada.
        [{ rotulo: 'Síntese da célula', registro: daCelula(null) },
          ...eixos.map((x) => ({ rotulo: `Eixo · ${x.nome}`, registro: daCelula(x.id) }))]
          .filter((p) => p.registro && String(p.registro.escolha || '').trim())
          .forEach((p) => itens.push({ driver: d.nome, rotulo: p.rotulo, registro: p.registro }));
      });
      return { horizonte: h, cor: this.CORES_HORIZONTE[i % this.CORES_HORIZONTE.length], itens };
    }).filter((s) => s.itens.length);
  },

  /**
   * As notas de uma escolha: a renúncia e as evidências da SWOT que a
   * fundamentam. A renúncia é o que esta análise tem de próprio — uma escolha
   * publicada sem ela é só uma intenção — e por isso vai no documento mesmo
   * quando o cartão da tela a mostra recolhida.
   */
  notasEscolha(reg) {
    const n = [];
    if (String(reg.renuncia || '').trim()) n.push(`Renúncia: ${reg.renuncia}`);
    const fatores = (reg.fatores || []).map((f) =>
      `${Diag.QUADRANTES[f.categoria] || f.categoria}: ${f.descricao}${
        f.score ? ` (GUT ${f.score})` : ''}`);
    if (fatores.length) n.push(`Fundamenta (SWOT): ${fatores.join('; ')}`);
    return n;
  },

  /**
   * O documento no PAPEL — a outra metade do botão "Relatório", já que o PDF é
   * a impressão da própria tela.
   *
   * Ele existe porque a tela NÃO serve de documento aqui: a matriz mostra só as
   * sínteses, cortadas na largura da célula, e as aberturas por eixo só aparecem
   * na célula que estiver aberta. Imprimir o que está à vista publicaria uma
   * fração do preenchido. Este bloco é o preenchimento inteiro, escondido na
   * tela (`d-none`) e revelado só na impressão (`d-print-block`).
   *
   * A estrutura é a das colunas do diagnóstico de propósito — `caixa-coluna`,
   * `cabecalho-coluna`, `corpo-coluna` dentro de `RelatorioAnalise.bloco` —
   * porque é essa marcação que o `@media print` já sabe abrir em documento
   * vertical, com o título da seção repetido no topo de cada folha.
   */
  renderImpressao() {
    const alvo = document.getElementById('documento-cascata');
    if (!alvo) return;
    const secoes = this.relatorioSecoes();
    if (!secoes.length) {
      alvo.innerHTML = '<p class="fst-italic">Nenhuma escolha preenchida até aqui.</p>';
      return;
    }
    const cartao = (it) => `
      <div class="card mb-2"><div class="card-body py-2 px-3">
        <div class="fw-bold small">${Modal.esc(it.driver)} · ${Modal.esc(it.rotulo)}</div>
        <div class="small texto-fator texto-celula mt-1">${Modal.esc(it.registro.escolha)}</div>
        ${this.notasEscolha(it.registro).map((n) =>
          `<div class="small text-muted texto-fator mt-1">${Modal.esc(n)}</div>`).join('')}
      </div></div>`;

    alvo.innerHTML = `<div class="row g-3">${secoes.map((s) => `
      <div class="col-md-6" data-coluna-categoria="H${s.horizonte.id}">
        <div class="p-2 rounded caixa-coluna"
          style="--tinta-coluna:${s.cor}18; border-top: 3px solid ${s.cor}">
          ${RelatorioAnalise.bloco({
            cabecalho: `<div class="cabecalho-coluna d-flex align-items-center mb-2">
              <span class="fw-bold small text-uppercase" style="color:${s.cor}">${
                Modal.esc(s.horizonte.nome)} · ${s.horizonte.ano_inicio}–${s.horizonte.ano_fim}
                <span class="fw-normal fst-italic text-lowercase">“${
                  Modal.esc(s.horizonte.tema)}”</span></span>
            </div>`,
            corpo: `<div class="corpo-coluna">${s.itens.map(cartao).join('')}</div>`,
          })}
        </div>
      </div>`).join('')}</div>`;
  },

  // ── Matriz de Execução ──────────────────────────────────────────────────
  /**
   * A leitura que fecha o vão entre decidir e cobrar: por eixo, a escolha (com
   * a renúncia), os indicadores que a medem, o par meta × real de cada um e os
   * projetos que a executam.
   *
   * **Não é um mapa estratégico.** Não há raias com setas de causa-e-efeito, e
   * a caixa não é entidade nova: é a própria `cascata_escolha`, que já traz a
   * renúncia (que o BSC não tem) e os fatores SWOT/GUT que a fundamentam. As
   * raias são os EIXOS, que já estão cadastrados e ordenados pelo usuário.
   *
   * Um horizonte por vez, e não os três: com seis drivers e seis eixos, a
   * cascata inteira daria mais de cem linhas numa tabela só — e a pergunta que
   * se faz no trimestral é sempre sobre uma fase.
   */
  matrizExecucao() {
    const { horizontes, drivers, eixos, escolhas, indicadores, projetos } = this.dados;
    if (!horizontes.length) {
      return '<div class="alert alert-info">Cadastre os horizontes do ciclo em Cadastros.</div>';
    }
    const h = horizontes.find((x) => x.id == this.horizonteMatriz) || horizontes[0];
    const nome = (lista, id) => lista.find((x) => x.id == id)?.nome || '';

    // Índices num passe: a tabela é montada em memória, e não com uma varredura
    // por linha — com seis eixos × seis drivers, o laço aninhado ingênuo faria
    // milhares de comparações a cada pintura.
    const porEscolha = { indicadores: {}, projetos: {} };
    for (const i of indicadores || []) {
      for (const cid of i.cascatas || []) (porEscolha.indicadores[cid] ||= []).push(i);
    }
    for (const p of projetos || []) (porEscolha.projetos[p.cascata_id] ||= []).push(p);

    // As raias: um grupo por eixo, na ordem cadastrada, mais a SÍNTESE. Ela vem
    // primeiro porque é o texto que a matriz publica — as aberturas por eixo
    // detalham o que ela resume, e lê-las antes dela é ler o detalhe sem o todo.
    const raias = [
      { chave: 'S', rotulo: 'Síntese da célula', eixoId: null },
      ...eixos.map((x) => ({ chave: String(x.id), rotulo: x.nome, eixoId: x.id })),
    ];

    const fmt = (v) => SecaoMetas.fmt(v);
    const celulaKpi = (i) => {
      // A MESMA regra da tela de Metas, chamada de lá: duas cópias diriam
      // números diferentes do mesmo indicador em telas vizinhas.
      const { real, meta, atingiu } = SecaoMetas.metaReal(i);
      const cor = atingiu === null ? 'text-muted' : atingiu ? 'text-success fw-bold' : 'text-danger fw-bold';
      return `<div class="small text-nowrap ${cor}">
        ${real ? `${fmt(real.valor)} <span class="text-muted">(${real.ano})</span>` : 'sem real'}
        ${meta ? ` / meta ${fmt(meta.valor)}` : ''}</div>`;
    };

    const corpo = raias.map((raia) => {
      const daRaia = drivers
        .map((d) => escolhas.find((e) => e.horizonte_id == h.id && e.driver_id == d.id
          && (raia.eixoId ? e.eixo_id == raia.eixoId : !e.eixo_id)))
        .filter(Boolean)
        .map((e) => ({
          escolha: e,
          driver: nome(drivers, e.driver_id),
          kpis: porEscolha.indicadores[e.id] || [],
          acoes: porEscolha.projetos[e.id] || [],
        }));
      // Raia sem nenhuma escolha preenchida some da tabela: a matriz é a
      // leitura do que foi decidido, e uma faixa vazia por eixo empurraria o
      // que existe para fora da primeira tela.
      if (!daRaia.length) return '';

      // Uma linha por INDICADOR — é o que alinha o KPI com o número dele. A
      // escolha e as iniciativas se estendem por elas com `rowspan`; escolha
      // sem indicador ocupa uma linha, com a coluna do meio vazia.
      const alturaRaia = daRaia.reduce((n, x) => n + Math.max(1, x.kpis.length), 0);
      let primeiraDaRaia = true;
      return daRaia.map((x) => {
        const altura = Math.max(1, x.kpis.length);
        const celulaEixo = primeiraDaRaia
          ? `<td rowspan="${alturaRaia}" class="align-middle celula-raia">${Modal.esc(raia.rotulo)}</td>` : '';
        primeiraDaRaia = false;

        const celulaEscolha = `<td rowspan="${altura}" class="align-middle">
          <div class="fw-bold small text-uppercase text-muted">${Modal.esc(x.driver)}</div>
          <div class="small texto-celula">${Modal.esc(x.escolha.escolha)}</div>
          ${x.escolha.renuncia ? `<div class="small text-muted texto-celula mt-1">
            <strong>Renúncia:</strong> ${Modal.esc(x.escolha.renuncia)}</div>` : ''}</td>`;

        const celulaAcoes = `<td rowspan="${altura}" class="align-middle">${x.acoes.length
          ? x.acoes.map((p) => {
            // O mesmo catálogo de status da tela de Projetos e do Relatório de
            // Status (`STATUS_PROJETO`, em `projetos.js`): o selo do projeto tem
            // de ser o mesmo em toda tela que o mostra.
            const [rotulo, classe] = STATUS_PROJETO[p.status] || [p.status, 'text-bg-light border'];
            return `<div class="small mb-1">
              ${p.classificacao === 'PRIORITARIO' ? '<span class="badge badge-horizonte me-1">P</span>' : ''}
              ${Modal.esc(p.titulo)}
              <span class="badge ${classe} ms-1">${rotulo}</span>
              <span class="text-muted">${p.progresso}%</span></div>`;
          }).join('')
          : '<span class="small text-muted fst-italic">Sem projeto ligado a esta escolha.</span>'}</td>`;

        if (!x.kpis.length) {
          return `<tr>${celulaEixo}${celulaEscolha}
            <td colspan="2" class="small text-muted fst-italic">Sem indicador que meça esta escolha.</td>
            ${celulaAcoes}</tr>`;
        }
        return x.kpis.map((i, n) => `<tr>
          ${n === 0 ? celulaEixo + celulaEscolha : ''}
          <td class="small">${Modal.esc(i.nome)}
            ${Number(i.metrica_ancora) ? '<span class="badge badge-ancora ms-1">âncora</span>' : ''}
            <span class="text-muted">(${Modal.esc(i.unidade)})</span></td>
          <td>${celulaKpi(i)}</td>
          ${n === 0 ? celulaAcoes : ''}
        </tr>`).join('');
      }).join('');
    }).join('');

    const semVinculo = (indicadores || []).filter((i) => !(i.cascatas || []).length).length;

    return `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <p class="text-muted mb-0">Por eixo: o que foi decidido, o que mede a decisão e quem a
        executa. O indicador se amarra à escolha na tela <strong>Metas · Indicadores</strong>;
        o projeto, no campo <em>Escolha da Cascata</em> do próprio projeto.</p>
        <div class="d-flex align-items-center gap-2">
          <label class="small text-muted text-nowrap" for="sel-horizonte-matriz">Horizonte</label>
          <select id="sel-horizonte-matriz" class="form-select form-select-sm" style="width:auto">
            ${horizontes.map((x) => `<option value="${x.id}" ${x.id == h.id ? 'selected' : ''}>${
              Modal.esc(x.nome)} · ${x.ano_inicio}–${x.ano_fim}</option>`).join('')}
          </select>
        </div>
      </div>
      ${semVinculo ? `<div class="alert alert-secondary py-2 small d-print-none">
        ${semVinculo} indicador(es) ainda não medem escolha nenhuma — eles não aparecem aqui.
        Amarre-os em <strong>Metas · Indicadores</strong>, no campo “Escolhas da cascata que este
        indicador mede”.</div>` : ''}
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle tabela-execucao">
          <thead><tr>
            <th style="min-width:120px">Eixo</th>
            <th style="min-width:260px">Escolha (e a renúncia)</th>
            <th style="min-width:180px">Indicadores</th>
            <th style="min-width:150px">Meta / Real</th>
            <th style="min-width:200px">Iniciativas</th>
          </tr></thead>
          <tbody>${corpo || `<tr><td colspan="5" class="text-muted">Nenhuma escolha preenchida
            em ${Modal.esc(h.nome)}.</td></tr>`}</tbody>
        </table>
      </div>`;
  },

  /**
   * A troca de aba é só mostrar e esconder: os dois painéis são pintados
   * juntos, da MESMA `this.dados`, e trocar de aba não muda dado nenhum.
   * Recarregar a seção aqui custaria uma ida ao servidor por clique e ainda
   * derrubaria o detalhe que estivesse aberto na outra aba.
   */
  ligarAbas(el) {
    const mostrar = () => {
      el.querySelectorAll('[data-painel-cascata]').forEach((p) =>
        p.classList.toggle('d-none', p.dataset.painelCascata !== this.aba));
      el.querySelectorAll('[data-aba-cascata]').forEach((b) => {
        const ativa = b.dataset.abaCascata === this.aba;
        b.classList.toggle('active', ativa);
        b.setAttribute('aria-selected', String(ativa));
      });
    };
    el.querySelectorAll('[data-aba-cascata]').forEach((b) => b.addEventListener('click', () => {
      this.aba = b.dataset.abaCascata;
      mostrar();
    }));
    this.ligarHorizonteMatriz(el);
  },

  /**
   * Trocar de horizonte repinta SÓ a matriz de execução — e o `<select>` some
   * junto com o HTML anterior, por isso o ouvinte é religado no nó novo. Sem
   * essa religação, o seletor funcionava uma vez e ficava mudo na segunda.
   */
  ligarHorizonteMatriz(el) {
    el.querySelector('#sel-horizonte-matriz')?.addEventListener('change', (ev) => {
      this.horizonteMatriz = parseInt(ev.target.value, 10) || null;
      this.renderExecucao(el);
    });
  },

  renderExecucao(el) {
    const painel = el.querySelector('[data-painel-cascata="execucao"]');
    if (!painel) return;
    painel.innerHTML = this.matrizExecucao();
    this.ligarHorizonteMatriz(el);
  },

  async carregar() {
    const el = document.getElementById('secao-cascata');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    // Foco vindo do "Ver" da aba Sala: abre a célula da pergunta examinada
    const vindo = QuizSala.consumirFoco('cascata');
    if (vindo) {
      this.perguntaFoco = vindo.perguntaId;
      this.aoNavegar(vindo.pergunta);
    }
    this.plan = await App.planejamento();
    [this.dados, this.quiz] = await Promise.all([
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      QuizSala.estado(this.plan.id, this.perguntaFoco),
    ]);
    const { horizontes, drivers, eixos, escolhas } = this.dados;

    const totalAberturas = horizontes.length * drivers.length * eixos.length;
    const totalSinteses = horizontes.length * drivers.length;
    const feitasAberturas = escolhas.filter((e) => e.eixo_id).length;
    const feitasSinteses = escolhas.filter((e) => !e.eixo_id).length;

    const cabecalho = horizontes.map((h) => `
      <th class="celula-horizonte">
        <div>${Modal.esc(h.nome)} · ${h.ano_inicio}–${h.ano_fim}</div>
        <div class="small fw-normal fst-italic">“${Modal.esc(h.tema)}”</div>
      </th>`).join('');

    const linhas = drivers.map((d) => {
      const celulas = horizontes.map((h) => {
        const sintese = escolhas.find((e) => e.driver_id == d.id && e.horizonte_id == h.id && !e.eixo_id);
        const aberturas = escolhas.filter((e) => e.driver_id == d.id && e.horizonte_id == h.id && e.eixo_id).length;
        const ativa = this.celulaAberta
          && this.celulaAberta.driverId == d.id && this.celulaAberta.horizonteId == h.id;
        return `<td class="celula-cascata ${ativa ? 'ativa' : ''}" data-driver="${d.id}" data-horizonte="${h.id}">
          <div class="small texto-celula">${sintese ? Modal.esc(sintese.escolha) : '<span class="text-muted">— definir síntese —</span>'}</div>
          <span class="badge ${aberturas === eixos.length ? 'text-bg-success' : 'text-bg-light border'} mt-1">${aberturas}/${eixos.length} eixos</span>
        </td>`;
      }).join('');
      return `<tr><th class="celula-driver">${Modal.esc(d.nome)}</th>${celulas}</tr>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Cascata de Escolhas — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2 align-items-center">
          <span class="badge text-bg-success fs-6">Aberturas ${feitasAberturas}/${totalAberturas}</span>
          <span class="badge badge-horizonte fs-6">Sínteses ${feitasSinteses}/${totalSinteses}</span>
          ${RelatorioAnalise.botao()}
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2 d-print-none"
        id="selo-quiz">${QuizSala.selo(this, 'cascata')}</div>
      <!-- As duas leituras da mesma cascata: a MATRIZ é onde se decide (célula
           por célula, com a renúncia); a EXECUÇÃO é onde se cobra (o que mede e
           o que executa cada decisão). Aba, e não seção nova: pôr a leitura
           longe da decisão obrigaria a navegar para conferir se a escolha tem
           dono e número. -->
      <ul class="nav nav-tabs mb-3 d-print-none" role="tablist">
        <li class="nav-item"><button type="button" role="tab"
          class="nav-link${this.aba === 'execucao' ? '' : ' active'}"
          aria-selected="${this.aba !== 'execucao'}" data-aba-cascata="escolhas">Matriz de escolhas</button></li>
        <li class="nav-item"><button type="button" role="tab"
          class="nav-link${this.aba === 'execucao' ? ' active' : ''}"
          aria-selected="${this.aba === 'execucao'}" data-aba-cascata="execucao">Matriz de Execução</button></li>
      </ul>

      <div data-painel-cascata="escolhas"${this.aba === 'execucao' ? ' class="d-none"' : ''}>
        <p class="text-muted d-print-none">Cada célula <em>driver × horizonte</em> tem uma síntese e ${eixos.length}
        aberturas por eixo — cada escolha declara também a sua renúncia. Clique na célula para detalhar.</p>
        <div class="table-responsive">
          <table class="table table-bordered tabela-cascata">
            <thead><tr><th class="celula-driver">LINHAS BASES</th>${cabecalho}</tr></thead>
            <tbody>${linhas}</tbody>
          </table>
        </div>
        <!-- No papel a matriz acima é o SUMÁRIO (só as sínteses, como na tela) e
             este bloco é o documento: todas as células preenchidas, com as
             aberturas por eixo e as renúncias. O detalhe interativo não vai —
             é a célula que alguém deixou aberta, não uma escolha do relatório.
             Mora DENTRO da aba de escolhas porque é o documento dela: na aba de
             execução o que se imprime é a leitura que está à vista. -->
        <div class="d-none d-print-block" id="documento-cascata"></div>
        <div id="detalhe-celula" class="d-print-none"></div>
      </div>

      <div data-painel-cascata="execucao"${this.aba === 'execucao' ? '' : ' class="d-none"'}
        >${this.matrizExecucao()}</div>`;

    this.renderImpressao();
    this.ligarAbas(el);
    // O documento sai da MESMA fonte do bloco impresso (`relatorioSecoes`), para
    // que o `.doc` e o papel nunca discordem sobre o que já foi preenchido.
    RelatorioAnalise.ligar(el, () => ({
      titulo: `Cascata de Escolhas — ${App.rotuloContexto()}`,
      contexto: `Sínteses ${this.dados.escolhas.filter((e) => !e.eixo_id).length}/${totalSinteses}`
        + ` · aberturas ${this.dados.escolhas.filter((e) => e.eixo_id).length}/${totalAberturas}`
        + ' — o relatório traz só o que já foi preenchido',
      secoes: this.relatorioSecoes().map((s) => ({
        rotulo: `${s.horizonte.nome} · ${s.horizonte.ano_inicio}–${s.horizonte.ano_fim}`,
        cor: s.cor,
        dica: `“${s.horizonte.tema}”`,
        itens: s.itens.map((it) => ({
          texto: `${it.driver} · ${it.rotulo}: ${it.registro.escolha}`,
          notas: this.notasEscolha(it.registro),
        })),
      })),
    }));

    el.querySelectorAll('.celula-cascata').forEach((td) => {
      td.addEventListener('click', () => {
        this.celulaAberta = {
          driverId: parseInt(td.dataset.driver, 10),
          horizonteId: parseInt(td.dataset.horizonte, 10),
        };
        // Clicar na matriz volta o foco à regra padrão (a pergunta ativa)
        this.perguntaFoco = null;
        el.querySelectorAll('.celula-cascata').forEach((c) => c.classList.remove('ativa'));
        td.classList.add('ativa');
        this.renderDetalhe();
        document.getElementById('detalhe-celula').scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    QuizSala.ligarSelo(el);
    // Sessão aberta: sem célula escolhida ainda, abre a da pergunta ativa —
    // quem entra na seção durante o encontro cai direto no que a sala vê.
    // Só a pergunta desta tela tem célula: a sala é do projeto e a ativa pode
    // ser um cenário ou um quadrante da SWOT, que não apontam para lugar nenhum
    // na matriz da cascata.
    // A pergunta em FOCO conta junto com a ativa: fechada a sala, o foco cai na
    // última encerrada, e é nela que o condutor lê as estrelas, une as
    // respostas parecidas e as transforma em escolha da célula. Sem isso, quem
    // recarregasse a página depois de fechar caía numa matriz sem painel.
    const perguntaAbrir = this.quiz?.pergunta || this.quiz?.foco;
    if (!this.celulaAberta && perguntaAbrir?.alvo_tipo === 'CASCATA') {
      this.celulaAberta = {
        driverId: Number(perguntaAbrir.driver_id),
        horizonteId: Number(perguntaAbrir.horizonte_id),
      };
      const td = el.querySelector(`.celula-cascata[data-driver="${this.celulaAberta.driverId}"][data-horizonte="${this.celulaAberta.horizonteId}"]`);
      if (td) td.classList.add('ativa');
    }
    if (this.celulaAberta) this.renderDetalhe();
    QuizSala.armarRelogio(this);
  },

  // ---- Sessão do quiz: a faixa é o componente compartilhado (QuizSala) ----
  /**
   * Chamado pelo QuizSala quando o condutor examina outra pergunta do roteiro:
   * posiciona ESTA tela na célula dela. Pergunta de outra análise (cenário, um
   * quadrante da SWOT) não tem célula — a matriz fica onde está e o condutor vê
   * as sugestões pela tela de origem.
   */
  aoNavegar(pergunta) {
    if (pergunta.alvo_tipo !== 'CASCATA') return;
    this.celulaAberta = {
      driverId: Number(pergunta.driver_id),
      horizonteId: Number(pergunta.horizonte_id),
    };
  },

  /**
   * Batida do polling: repinta a faixa e o detalhe quando algo mudou. Repintar
   * à toa custa o foco e, no celular, o teclado aberto.
   */
  async aoBater(quizNovo) {
    const el = document.getElementById(this.secaoId);
    // A célula e as vozes do detalhe saem de this.dados, e o estado do quiz não
    // fala delas: outro condutor salvando a escolha da célula não mexe em nada
    // que a assinatura do QuizSala enxergue. Rebuscar ANTES de comparar é o que
    // impede o detalhe de seguir mostrando o texto velho — e um modal aberto
    // desse estado reenviaria o conjunto antigo, desfazendo o recém-salvo.
    try {
      this.dados = await App.api(`/api/cascata?planejamento_id=${this.plan.id}`);
    } catch (e) {
      return;
    }
    const assinatura = QuizSala.assinatura(quizNovo)
      + JSON.stringify((this.dados.escolhas || []).map(
        (e) => [e.id, e.escolha, e.renuncia, (e.sugestoes || []).length]));
    if (assinatura === this.assinaturaQuiz) return;
    this.assinaturaQuiz = assinatura;
    const selo = document.getElementById('selo-quiz');
    if (selo) {
      selo.innerHTML = QuizSala.selo(this, 'cascata');
      QuizSala.ligarSelo(el);
    }
    if (!quizNovo.sessao) {
      clearInterval(this.relogioQuiz);
      this.relogioQuiz = null;
    }
    // O bloco impresso acompanha a matriz. Ele é invisível na tela, então nada
    // denunciaria que ficou para trás: quem mandasse imprimir no fim da oficina
    // levaria para o papel a cascata de antes da última célula salva.
    this.renderImpressao();
    // A Matriz de Execução pelo mesmo motivo: ela lê as mesmas escolhas, e numa
    // oficina a célula que acabou de ser redigida tem de aparecer nas duas abas.
    this.renderExecucao(el);
    if (this.celulaAberta) this.renderDetalhe();
  },

  /**
   * A pergunta em FOCO (a examinada pelo roteiro; por padrão, a ativa),
   * quando ela pertence à célula aberta no detalhe.
   */
  /**
   * A pergunta ATIVA é exatamente esta parte da célula aberta? É o que decide
   * se o 🎤 do cartão vira selo — a categoria que já está na sala não é alvo de
   * toque (reativar reabriria a pergunta e zeraria o cronômetro dela).
   */
  perguntaAtivaDoAlvo(eixoId) {
    const p = this.quiz?.pergunta;
    if (!p || p.alvo_tipo !== 'CASCATA' || !this.celulaAberta) return false;
    return Number(p.driver_id) === Number(this.celulaAberta.driverId)
      && Number(p.horizonte_id) === Number(this.celulaAberta.horizonteId)
      && Number(p.eixo_id || 0) === Number(eixoId || 0);
  },

  perguntaDaCelulaAberta() {
    const p = this.quiz?.foco || this.quiz?.pergunta;
    // A sala é do projeto: a ativa pode ser de outra análise. Sem conferir o
    // alvo, uma pergunta de cenário (driver_id e horizonte_id nulos) casaria
    // com qualquer célula cujos ids também viessem indefinidos.
    if (!p || p.alvo_tipo !== 'CASCATA' || !this.celulaAberta) return null;
    return Number(p.driver_id) === Number(this.celulaAberta.driverId)
      && Number(p.horizonte_id) === Number(this.celulaAberta.horizonteId) ? p : null;
  },

  renderDetalhe() {
    const { horizontes, drivers, eixos, escolhas } = this.dados;
    const alvo = document.getElementById('detalhe-celula');
    const { driverId, horizonteId } = this.celulaAberta;
    const driver = drivers.find((d) => d.id == driverId);
    const horizonte = horizontes.find((h) => h.id == horizonteId);
    if (!driver || !horizonte) {
      // Célula aberta de um ciclo anterior — o contexto mudou; fecha o detalhe
      this.celulaAberta = null;
      alvo.innerHTML = '';
      return;
    }
    const daCelula = (eixoId) => escolhas.find((e) =>
      e.driver_id == driverId && e.horizonte_id == horizonteId &&
      (eixoId ? e.eixo_id == eixoId : !e.eixo_id));

    const sintese = daCelula(null);
    const cartaoEscolha = (rotulo, registro, eixoId) => {
      // Selo na cor do quadrante, igual ao da SWOT — o texto do fator no title
      const fatores = (registro?.fatores || []).map((f) => {
        const cor = Diag.CORES_QUADRANTE[f.categoria] || '#007a45';
        return `<span class="badge" style="color:${cor};background:${cor}1f"
          title="${Modal.esc(f.descricao)}">${Diag.QUADRANTES[f.categoria] || f.categoria}${
          f.score ? ` · GUT ${f.score}` : ''}</span>`;
      }).join(' ');
      // Sem pílula de voz aqui. A célula mostra o TEXTO — que é o que a matriz
      // publica —, e o rastro de quem o compôs é o cartão verde no painel de
      // respostas. Duas siglas ("E", "R") com um ✕ cada empilhavam-se debaixo
      // da síntese sem dizer nada legível, e o ✕ escondia uma ação destrutiva
      // (desvincular) num lugar de leitura.
      return `<div class="card mb-2"><div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between gap-2">
          <div>
            <div class="fw-bold small text-uppercase">${Modal.esc(rotulo)}</div>
            <div class="small mt-1 texto-celula">${registro ? Modal.esc(registro.escolha) : '<span class="text-muted">Não definida.</span>'}</div>
            ${registro?.renuncia ? `<div class="small text-muted mt-1 texto-celula"><strong>Renúncia:</strong> ${Modal.esc(registro.renuncia)}</div>` : ''}
            ${fatores ? `<div class="mt-1 d-flex gap-1 flex-wrap">${fatores}</div>` : ''}
          </div>
          ${App.podeEditar() ? `<div class="d-flex gap-1 flex-shrink-0 align-items-start">
            ${QuizSala.microfone(
              { alvo_tipo: 'CASCATA', horizonte_id: horizonteId, driver_id: driverId,
                alvos: [eixoId ?? null] },
              rotulo,
              { ativo: this.perguntaAtivaDoAlvo(eixoId),
                pergunta: this.perguntaAtivaDoAlvo(eixoId) ? this.quiz?.pergunta?.id : null })}
            <button class="btn btn-sm btn-outline-secondary" data-editar-celula="${eixoId ?? ''}">${registro ? 'Editar' : 'Definir'}</button>
            ${registro ? `<button class="btn btn-sm btn-outline-danger" data-excluir-celula="${registro.id}">×</button>` : ''}
          </div>` : ''}
        </div>
      </div></div>`;
    };

    const podePerguntar = App.podeEditar();
    const perguntaAqui = this.perguntaDaCelulaAberta();

    alvo.innerHTML = `
      <div class="card mt-3 border-success">
        <div class="card-header bg-success-subtle">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="flex-grow-1">
              <strong>${Modal.esc(driver.nome)}</strong> × ${Modal.esc(horizonte.nome)}
              (${horizonte.ano_inicio}–${horizonte.ano_fim} · “${Modal.esc(horizonte.tema)}”)
              <div class="small text-muted">${Modal.esc(horizonte.objetivo)}</div>
            </div>
            ${podePerguntar ? `<button class="btn btn-sm btn-outline-secondary"
              id="btn-perguntar-sala" title="Pôr várias partes desta célula no roteiro">
              Montar roteiro desta célula</button>` : ''}
            ${perguntaAqui ? '<span class="badge text-bg-success align-self-center">A sala está respondendo esta célula</span>' : ''}
          </div>
        </div>
        <div class="card-body">
          <div id="quiz-vivo">${this.painelVivo()}</div>
          ${cartaoEscolha('Síntese da célula (texto da matriz)', sintese, null)}
          <div class="row g-2 mt-1">
            ${eixos.map((x) => `<div class="col-md-6">${cartaoEscolha(`Eixo · ${x.nome}`, daCelula(x.id), x.id)}</div>`).join('')}
          </div>
        </div>
      </div>`;

    if (!App.podeEditar()) return;

    // Sempre clicável: com a pergunta ativa NESTA célula, o botão serve para
    // trocar o alvo (da síntese para um eixo, ou entre eixos) — desabilitá-lo
    // prendia o condutor na primeira pergunta da célula
    const btnPerguntar = alvo.querySelector('#btn-perguntar-sala');
    if (btnPerguntar) {
      btnPerguntar.addEventListener('click', () => this.perguntarASala());
    }

    alvo.querySelectorAll('[data-editar-celula]').forEach((b) => b.addEventListener('click', () => {
      const eixoId = b.dataset.editarCelula ? parseInt(b.dataset.editarCelula, 10) : null;
      this.abrirModalCelula(eixoId);
    }));

    alvo.querySelectorAll('[data-excluir-celula]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta escolha?')) return;
      try {
        await App.api(`/api/cascata/${b.dataset.excluirCelula}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      App.recarregarSecaoAtiva();
    }));

    QuizSala.ligarMicrofones(this, alvo);
    this.ligarPainelVivo(alvo);
  },

  // ---- Painel ao vivo: as duas áreas de coleta da pergunta ativa ----
  /**
   * Só aparece quando a pergunta ativa é a célula aberta. Duas colunas —
   * Respostas e Renúncias — e o "Usar" de cada cartão é um INTERRUPTOR: o
   * cartão fica onde está, marcado de verde, e o texto da célula é composto na
   * ordem em que os cartões aparecem na coluna (nunca na ordem dos cliques —
   * desmarcar e remarcar embaralharia a frase). Redigir é ato à parte: o
   * condutor abre a célula com o texto já composto e escreve a versão final.
   */
  painelVivo() {
    const p = this.perguntaDaCelulaAberta();
    if (!p) return '';
    const sugestoes = this.comUso(p);
    const recolhido = this.quizUi?.painelRecolhido;
    // Unir respostas é gesto de CONSOLIDAÇÃO: só com a pergunta já fechada para
    // a sala. Com ela aberta, a ficha sumindo no meio de um envio pareceria
    // resposta perdida para quem está respondendo.
    const podeUnir = App.podeEditar() && p.situacao !== 'ATIVA';
    const coluna = (tipo, rotulo, classe) => {
      const fichas = sugestoes.filter((s) => s.tipo_resposta === tipo);
      // Cartões, não linhas: unidas duas respostas, a coluna mostra uma. Marcar
      // NÃO mexe nesta conta — o cartão marcado continua na grade
      const cartoes = QuizSala.contarCartoes(fichas, { marcar: true });
      return `<div class="col-md-6"><div class="coluna-quiz ${classe}">
        <div class="fw-bold small text-uppercase mb-2">${rotulo}
          <span class="badge rounded-pill text-bg-secondary">${cartoes}</span></div>
        ${QuizSala.fichas(fichas, { virou: 'escolha da célula', podeUnir, marcar: true })}
      </div></div>`;
    };
    const marcados = QuizSala.agrupar(sugestoes)
      .filter((g) => [g.lider, ...g.unidas].some((x) => x.usado)).length;
    return `<div class="card mb-3 painel-quiz-vivo"><div class="card-body py-2 px-3">
      ${QuizSala.cabecalhoPainel(this, p, sugestoes, { marcar: true })}
      ${recolhido ? '' : `<div class="row g-2 mt-1">
        ${coluna('ESCOLHA', 'Respostas (escolha)', 'coluna-escolha')}
        ${coluna('RENUNCIA', 'Renúncias', 'coluna-renuncia')}
      </div>
      ${App.podeEditar() ? `<div class="d-flex align-items-center gap-2 flex-wrap mt-2">
        <span class="small text-muted flex-grow-1">Marque em “Usar” o que entra na célula;
          o texto vem na ordem dos cartões e você edita antes de salvar.</span>
        <button class="btn btn-sm btn-verde" data-redigir-celula>${marcados
          ? `Redigir com os marcados (${marcados})` : 'Redigir esta parte da célula'}</button>
      </div>` : ''}`}
    </div></div>`;
  },

  // ---- Marcação dos cartões ("Usado") ----
  /** Os dois conjuntos de intenção da pergunta, criados na primeira marcação. */
  usoDe(perguntaId) {
    const chave = String(perguntaId);
    if (!this.usoQuiz[chave]) this.usoQuiz[chave] = { mais: new Set(), menos: new Set() };
    return this.usoQuiz[chave];
  },

  /**
   * As sugestões da pergunta com o campo `usado` resolvido: o vínculo já salvo
   * no servidor, mais o que o condutor marcou agora, menos o que desmarcou.
   * É a única fonte do verde na tela e do texto composto — as duas leituras
   * separadas divergiriam no primeiro clique.
   */
  comUso(p) {
    const u = this.usoDe(p.id);
    return (this.quiz?.sugestoes || []).map((s) => ({
      ...s,
      usado: (Number(s.vinculada) === 1 || u.mais.has(Number(s.id))) && !u.menos.has(Number(s.id)),
    }));
  },

  /** Marca/desmarca o cartão INTEIRO (o líder e as respostas unidas a ele). */
  alternarUso(p, idLider) {
    const g = QuizSala.grupoDe(this.comUso(p), idLider, { marcar: true });
    if (!g) return;
    const u = this.usoDe(p.id);
    const ligar = !g.usado;
    g.ids.forEach((id) => {
      u.mais[ligar ? 'add' : 'delete'](id);
      u.menos[ligar ? 'delete' : 'add'](id);
    });
    this.renderDetalhe();
  },

  /**
   * O texto de um lado composto pelos cartões marcados, na ordem da coluna.
   * `sos` = `comUso(p)`; com `somenteSalvos`, considera só o que já está
   * vinculado no banco — é assim que se descobre se o texto guardado ainda é o
   * composto automaticamente ou se alguém o reescreveu à mão.
   */
  composicao(sos, tipo, { somenteSalvos = false } = {}) {
    const fichas = sos.filter((s) => s.tipo_resposta === tipo);
    return QuizSala.agrupar(fichas)
      .filter((g) => [g.lider, ...g.unidas].some(
        (x) => (somenteSalvos ? Number(x.vinculada) === 1 : x.usado)))
      .map((g) => QuizSala.textoDoGrupo(g))
      .join('\n');
  },

  ligarPainelVivo(alvo) {
    QuizSala.ligarRecolher(this, alvo);
    QuizSala.ligarVozes(this, alvo);
    QuizSala.ligarUniao(this, alvo);
    alvo.querySelectorAll('[data-reabrir-foco]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.api(`/api/quiz/pergunta/${b.dataset.reabrirFoco}/ativar`, {
          planejamento_id: this.plan.id,
        });
      } catch (e) {
        alert(e.message);
      }
      // Aberta para a sala, a pergunta vira a ativa: o foco volta ao padrão
      this.perguntaFoco = null;
      App.recarregarSecaoAtiva();
    }));
    // "Usar" é interruptor e não salva nada: marcar é escolher o que ENTRA na
    // síntese, e o compromisso acontece uma vez só, ao salvar a célula. Salvar a
    // cada clique obrigaria a célula a existir com texto antes da primeira
    // marca — e o servidor exige escolha preenchida.
    alvo.querySelectorAll('[data-usar-sugestao]').forEach((b) => b.addEventListener('click', () => {
      const p = this.perguntaDaCelulaAberta();
      if (p) this.alternarUso(p, b.dataset.usarSugestao);
    }));
    alvo.querySelectorAll('[data-redigir-celula]').forEach((b) => b.addEventListener('click', () => {
      const p = this.perguntaDaCelulaAberta();
      if (p) this.abrirModalCelula(p.eixo_id ? Number(p.eixo_id) : null);
    }));
    alvo.querySelectorAll('[data-excluir-sugestao]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir esta sugestão? Ela some para a sala também.')) return;
      try {
        await App.api(`/api/quiz/sugestao/${b.dataset.excluirSugestao}/excluir`, {
          planejamento_id: this.plan.id,
        });
        this.quiz = await App.api(`/api/quiz?planejamento_id=${this.plan.id}`);
        this.renderDetalhe();
      } catch (e) {
        alert(e.message);
      }
    }));
  },

  /**
   * Montar o roteiro desta célula: várias partes de uma vez ("as 6 aberturas de
   * *Como Vencer*"). É trabalho de PREPARAÇÃO — conduzir é o 🎤 de cada cartão,
   * de um toque. Por isso o padrão aqui é só enfileirar, sem mexer na sala.
   */
  perguntarASala() {
    const { driverId, horizonteId } = this.celulaAberta;
    const driver = this.dados.drivers.find((d) => d.id == driverId);
    // Vários alvos de uma vez: "as 6 aberturas de Como Vencer" entram juntas
    // no roteiro. 'S' marca a síntese — o transformar troca por null.
    const opcoesAlvo = [
      { valor: 'S', texto: 'Síntese da célula', selo: driver ? driver.nome : 'Síntese' },
      ...this.dados.eixos.map((x) => ({ valor: String(x.id), texto: `Eixo · ${x.nome}` })),
    ];
    const paraAlvos = (marcados) =>
      (marcados || []).map((v) => (v === 'S' ? null : Number(v)));

    if (this.quiz?.sessao) {
      Modal.abrir({
        titulo: 'Perguntar à sala',
        url: '/api/quiz/perguntar',
        valores: {
          planejamento_id: this.plan.id, alvo_tipo: 'CASCATA',
          horizonte_id: horizonteId, driver_id: driverId, alvos: ['S'], acao: 'AGORA',
        },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          { nome: 'alvo_tipo', rotulo: '', tipo: 'hidden' },
          { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
          { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
          { nome: 'alvos', rotulo: 'O que a sala responde nesta célula?', tipo: 'lista_marcavel',
            opcoes: opcoesAlvo, obrigatorio: true,
            ajuda: 'Marque um ou vários: cada um vira uma pergunta do roteiro.' },
          { nome: 'acao', rotulo: 'Quando?', tipo: 'botoes', opcoes: [
            { valor: 'ROTEIRO', rotulo: 'Só adicionar ao roteiro' },
            { valor: 'AGORA', rotulo: 'Abrir a primeira agora' },
          ], ajuda: 'Abrir agora muda o celular de todo mundo; o roteiro guarda para depois.' },
        ],
        transformar: (d) => {
          const { alvos, acao, ...resto } = d;
          return { ...resto, alvos: paraAlvos(alvos), ativar: acao !== 'ROTEIRO' };
        },
        aoSalvar: () => {
          this.perguntaFoco = null;
          this.quizUi.roteiroAberto = true;
          App.recarregarSecaoAtiva();
        },
      });
      return;
    }
    Modal.abrir({
      titulo: 'Abrir sessão colaborativa',
      url: '/api/quiz/abrir',
      valores: {
        planejamento_id: this.plan.id, alvo_tipo: 'CASCATA',
        horizonte_id: horizonteId, driver_id: driverId, alvos: ['S'], tema: '', max_ideias: 5,
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'alvo_tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
        { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
        { nome: 'alvos', rotulo: 'Primeiras perguntas (desta célula)', tipo: 'lista_marcavel',
          opcoes: opcoesAlvo, obrigatorio: true,
          ajuda: 'A sala entra pelo PIN (como na tempestade) e responde uma pergunta por vez; '
            + 'a primeira marcada já abre para a sala, as demais ficam no roteiro.' },
        { nome: 'tema', rotulo: 'Nome do encontro',
          exemplo: 'Ex.: Oficina da cascata — diretoria, agosto/2026' },
        { nome: 'max_ideias', rotulo: 'Sugestões por pessoa (em cada lado)', tipo: 'number', padrao: 5,
          ajuda: 'Vale por pergunta: N escolhas e N renúncias para cada participante.' },
      ],
      transformar: (d) => {
        const { alvos, ...resto } = d;
        return { ...resto, alvos: paraAlvos(alvos) };
      },
      // A sala é do PROJETO: se ela já está aberta em outra análise, o servidor
      // devolve 409/SALA_ABERTA e o QuizSala pergunta se encerra aquela
      enviar: (corpo) => QuizSala.pedir('/api/quiz/abrir', corpo),
      aoSalvar: () => {
        this.perguntaFoco = null;
        App.recarregarSecaoAtiva();
      },
    });
  },

  /**
   * Modal da célula — o ÚNICO ponto em que a marcação vira compromisso. O texto
   * de cada lado chega composto pelos cartões marcados (na ordem da coluna) e é
   * editável: a voz da sala é matéria-prima, a redação final é do condutor.
   *
   * Só o lado da pergunta EM FOCO é composto: com o painel na síntese, editar o
   * cartão de um eixo não pode reescrever o texto dele nem mexer nos vínculos
   * dele — a marcação é da pergunta, não da célula inteira.
   */
  async abrirModalCelula(eixoId) {
    const { driverId, horizonteId } = this.celulaAberta;
    const { horizontes, drivers, escolhas } = this.dados;
    const driver = drivers.find((d) => d.id == driverId);
    const horizonte = horizontes.find((h) => h.id == horizonteId);
    const registro = escolhas.find((e) =>
      e.driver_id == driverId && e.horizonte_id == horizonteId &&
      (eixoId ? e.eixo_id == eixoId : !e.eixo_id));

    let escolhaValor = registro?.escolha || '';
    let renunciaValor = registro?.renuncia || '';
    let sugestoesIds = (registro?.sugestoes || []).map((s) => Number(s.id));

    const foco = this.perguntaDaCelulaAberta();
    const p = foco && Number(foco.eixo_id || 0) === Number(eixoId || 0) ? foco : null;
    if (p) {
      const sos = this.comUso(p);
      const compor = (tipo, atual) => {
        const nova = this.composicao(sos, tipo);
        // O texto guardado ainda é o composto automaticamente? Então acompanha
        // a marcação — inclusive encolhendo, que é o desmarcar tirando a
        // contribuição do cartão. Reescrito à mão, ele é de quem escreveu:
        // trocá-lo calado apagaria a redação no meio do encontro.
        const salva = this.composicao(sos, tipo, { somenteSalvos: true });
        if (!atual.trim() || atual.trim() === salva.trim()) return nova;
        if (!nova.trim()) return atual;
        const lado = tipo === 'RENUNCIA' ? 'renúncia' : 'escolha';
        return confirm(`A ${lado} desta célula foi redigida à mão:\n\n“${atual}”\n\n`
          + 'Substituir pelo texto dos cartões marcados?\n'
          + '(Cancelar mantém o que está escrito; os cartões marcados continuam marcados.)')
          ? nova : atual;
      };
      escolhaValor = compor('ESCOLHA', escolhaValor);
      renunciaValor = compor('RENUNCIA', renunciaValor);
      // O conjunto de vozes é o marcado AGORA, preservando o que veio de outro
      // encontro: vínculo de uma pergunta antiga não aparece neste painel e
      // seria solto pelo servidor se saísse da lista.
      const noPainel = new Set(sos.map((s) => Number(s.id)));
      sugestoesIds = [
        ...sugestoesIds.filter((id) => !noPainel.has(id)),
        ...sos.filter((s) => s.usado).map((s) => Number(s.id)),
      ];
    }

    // Fatores da SWOT ordenados por score GUT para o vínculo. A descrição vai
    // inteira: quem amarra a evidência à decisão precisa ler o fator todo,
    // não um resumo cortado no meio.
    const swot = await App.api(`/api/fatores?planejamento_id=${this.plan.id}&etapa=SWOT`);
    const opcoesFatores = swot
      .sort((a, c) => (c.score || 0) - (a.score || 0))
      .map((f) => ({
        valor: f.id,
        texto: f.descricao,
        selo: Diag.QUADRANTES[f.categoria] || f.categoria,
        selo2: f.score ? `GUT ${f.score}` : null,
        cor: Diag.CORES_QUADRANTE[f.categoria] || '#007a45',
      }));
    const eixoNome = eixoId ? this.dados.eixos.find((x) => x.id == eixoId).nome : null;
    Modal.abrir({
      titulo: `${driver.nome} × ${horizonte.nome}${eixoNome ? ` · Eixo ${eixoNome}` : ' · Síntese'}`,
      url: '/api/cascata',
      valores: {
        planejamento_id: this.plan.id,
        horizonte_id: horizonteId,
        driver_id: driverId,
        eixo_id: eixoId ?? '',
        escolha: escolhaValor,
        renuncia: renunciaValor,
        fatores: (registro?.fatores || []).map((f) => f.id),
      },
      // O conjunto de vozes vai pelo transformar: num campo hidden o array
      // viraria a string "1,2" e o servidor amarraria vínculo nenhum
      transformar: (d) => ({ ...d, sugestoes: sugestoesIds }),
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'horizonte_id', rotulo: '', tipo: 'hidden' },
        { nome: 'driver_id', rotulo: '', tipo: 'hidden' },
        { nome: 'eixo_id', rotulo: '', tipo: 'hidden' },
        ...(p ? [{
          nome: 'origem', rotulo: 'De onde vem este texto', tipo: 'info',
          texto: 'Os campos abaixo já vêm com as respostas marcadas na sala, na ordem em que '
            + 'aparecem nas colunas. Edite à vontade: o que for salvo aqui é o texto da matriz, '
            + 'e as respostas marcadas ficam registradas como origem da decisão.',
          barra: { cor: '#007a45', titulo: Modal.esc(p.rotulo || 'Respostas da sala') },
        }] : []),
        { nome: 'escolha', rotulo: 'Escolha (o que decidimos)', tipo: 'textarea', linhas: 3 },
        { nome: 'renuncia', rotulo: 'Renúncia (do que abrimos mão)', tipo: 'textarea', linhas: 2 },
        ...(opcoesFatores.length ? [{
          nome: 'fatores', rotulo: 'Fatores que fundamentam (SWOT/GUT)',
          tipo: 'lista_marcavel', opcoes: opcoesFatores,
          ajuda: 'Marque as evidências do diagnóstico que sustentam esta decisão. '
            + 'A lista vem ordenada pelo score da matriz GUT, do mais crítico ao menos.',
        }] : []),
      ],
      aoSalvar: () => {
        // Salvo, quem manda é o servidor: a intenção local cumpriu o papel e
        // guardá-la esconderia a marcação que outro condutor fizer depois
        if (p) delete this.usoQuiz[String(p.id)];
        App.recarregarSecaoAtiva();
      },
    });
  },
};

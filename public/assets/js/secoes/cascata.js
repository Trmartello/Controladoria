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
  // Aba visível: 'matriz' (a cascata em si) ou 'execucao' (a leitura do que
  // cada escolha virou). A leitura mora AO LADO da decisão, e não numa seção
  // própria: quem discute a escolha é quem pergunta o que ela mede.
  aba: 'matriz',
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
      // Quem chega da sala vem para conduzir, não para ler o consolidado
      this.aba = 'matriz';
    }
    this.plan = await App.planejamento();
    [this.dados, this.quiz] = await Promise.all([
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      QuizSala.estado(this.plan.id, this.perguntaFoco),
    ]);

    const abas = [['matriz', 'Matriz de Escolhas'], ['execucao', 'Matriz de Execução']];
    el.innerHTML = `
      <h1>Cascata de Escolhas — ${Modal.esc(App.rotuloContexto())}</h1>
      <ul class="nav nav-tabs mt-3" id="abas-cascata">
        ${abas.map(([id, rotulo]) =>
          `<li class="nav-item"><a class="nav-link ${id === this.aba ? 'active' : ''}"
            href="#" data-aba-cascata="${id}">${rotulo}</a></li>`).join('')}
      </ul>
      <div id="conteudo-cascata" class="pt-3"></div>`;

    el.querySelectorAll('[data-aba-cascata]').forEach((a) => {
      a.addEventListener('click', (ev) => {
        ev.preventDefault();
        this.aba = a.dataset.abaCascata;
        this.carregar();
      });
    });

    if (this.aba === 'execucao') {
      // Fora da matriz não há painel ao vivo nem célula aberta para repintar:
      // deixar o relógio batendo faria `aoBater` procurar um `#detalhe-celula`
      // que não existe a cada dois segundos
      clearInterval(this.relogioQuiz);
      this.relogioQuiz = null;
      this.renderExecucao();
      return;
    }
    this.renderMatriz();
  },

  /** A cascata em si: a grade driver × horizonte e o detalhe da célula. */
  renderMatriz() {
    const el = document.getElementById('secao-cascata');
    const alvoAba = document.getElementById('conteudo-cascata');
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

    alvoAba.innerHTML = `
      <div class="d-flex justify-content-end align-items-center flex-wrap gap-2">
        <div class="d-flex gap-2">
          <span class="badge text-bg-success fs-6">Aberturas ${feitasAberturas}/${totalAberturas}</span>
          <span class="badge badge-horizonte fs-6">Sínteses ${feitasSinteses}/${totalSinteses}</span>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2"
        id="selo-quiz">${QuizSala.selo(this, 'cascata')}</div>
      <p class="text-muted">Cada célula <em>driver × horizonte</em> tem uma síntese e ${eixos.length}
      aberturas por eixo — cada escolha declara também a sua renúncia. Clique na célula para detalhar.</p>
      <div class="table-responsive">
        <table class="table table-bordered tabela-cascata">
          <thead><tr><th class="celula-driver">LINHAS BASES</th>${cabecalho}</tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div>
      <div id="detalhe-celula"></div>`;

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

  // ---- Matriz de Execução -------------------------------------------------
  /**
   * Meta × real de um indicador, na MESMA regra de `metas.js`: o último real
   * lançado e, na falta dele, a primeira meta futura.
   *
   * Não é "meta do ano corrente": o ciclo semeado é 2027–2035 e as metas
   * começam em 2027 — com o ano corrente, em 2026 a matriz inteira nasceria
   * mostrando "—" e a tela pareceria quebrada.
   */
  metaReal(i) {
    const reais = i.reais || [];
    const metas = i.metas || [];
    const ultimoReal = reais[reais.length - 1] || null;
    const anoRef = ultimoReal ? ultimoReal.ano : (metas[0] || {}).ano;
    const meta = metas.find((m) => m.ano == anoRef);
    if (!ultimoReal && !meta) return '<span class="text-muted">—</span>';
    const atingiu = ultimoReal && meta
      ? (i.sentido === 'MENOR_MELHOR'
          ? Number(ultimoReal.valor) <= Number(meta.valor)
          : Number(ultimoReal.valor) >= Number(meta.valor))
      : null;
    const classe = atingiu === null ? 'text-muted' : atingiu ? 'text-success fw-bold' : 'text-danger fw-bold';
    return `<span class="text-nowrap small ${classe}">
      ${ultimoReal
        ? `${SecaoMetas.fmt(ultimoReal.valor)} <span class="text-muted">(${ultimoReal.ano})</span>`
        : '<span class="text-muted">sem real</span>'}
      ${meta ? ` / meta ${SecaoMetas.fmt(meta.valor)}` : ''}
    </span>`;
  },

  /**
   * A leitura que a direção pede no trimestral: por eixo, o que foi escolhido
   * (com a renúncia), o que MEDE aquilo e o que EXECUTA aquilo.
   *
   * Não é um mapa Kaplan/Norton e não se apresenta como tal: as raias são os
   * eixos que já estão cadastrados, e o valor de controladoria está em ver
   * qual escolha ficou sem medida — não em desenhar setas de causa-e-efeito.
   */
  renderExecucao() {
    const alvoAba = document.getElementById('conteudo-cascata');
    const { horizontes, drivers, eixos, escolhas } = this.dados;
    const nomeH = (id) => horizontes.find((h) => h.id == id)?.nome || '?';
    const nomeD = (id) => drivers.find((d) => d.id == id)?.nome || '?';

    // Grupos na ordem da tela: a síntese da célula primeiro (é o texto que a
    // matriz publica), depois os eixos na ordem em que foram cadastrados
    const grupos = [{ id: null, nome: 'Síntese da célula' }]
      .concat(eixos.map((x) => ({ id: x.id, nome: x.nome })));

    const ordem = (e) => {
      const h = horizontes.findIndex((x) => x.id == e.horizonte_id);
      const d = drivers.findIndex((x) => x.id == e.driver_id);
      return [h < 0 ? 99 : h, d < 0 ? 99 : d];
    };

    let semMedida = 0;
    const corpo = grupos.map((g) => {
      const doGrupo = escolhas
        .filter((e) => (g.id === null ? !e.eixo_id : e.eixo_id == g.id))
        .sort((a, b) => {
          const [ha, da] = ordem(a);
          const [hb, db] = ordem(b);
          return ha - hb || da - db;
        });
      if (!doGrupo.length) return '';

      return doGrupo.map((e, idx) => {
        const inds = e.indicadores || [];
        const projs = e.projetos || [];
        if (!inds.length) semMedida++;

        // Indicadores e Meta/Real são colunas distintas, mas leem-se em par:
        // uma linha por indicador nas DUAS, na mesma ordem
        const colInds = inds.length
          ? inds.map((i) => `<div class="linha-exec small">
              ${Modal.esc(i.nome)}
              ${Number(i.metrica_ancora) ? '<span class="badge badge-ancora ms-1">âncora</span>' : ''}
              <span class="text-muted">(${Modal.esc(i.unidade)})</span>
            </div>`).join('')
          : '<span class="text-muted small">— sem indicador —</span>';
        const colValores = inds.length
          ? inds.map((i) => `<div class="linha-exec">${this.metaReal(i)}</div>`).join('')
          : '<span class="text-muted small">—</span>';
        const colProjs = projs.length
          ? projs.map((p) => {
            const [rotulo, classe] = (typeof STATUS_ROTULOS !== 'undefined'
              && STATUS_ROTULOS[p.status]) || [p.status, 'text-bg-light border'];
            return `<div class="linha-exec small">
              ${Modal.esc(p.titulo)}
              <span class="badge ${classe} ms-1">${Modal.esc(rotulo)}</span>
              ${p.ano ? `<span class="text-muted"> · ${p.ano}</span>` : ''}
            </div>`;
          }).join('')
          : '<span class="text-muted small">— sem projeto —</span>';

        return `<tr>
          ${idx === 0 ? `<th rowspan="${doGrupo.length}" class="align-top celula-eixo">${Modal.esc(g.nome)}</th>` : ''}
          <td>
            <div class="small text-muted">${Modal.esc(nomeH(e.horizonte_id))} · ${Modal.esc(nomeD(e.driver_id))}</div>
            <div class="texto-celula">${Modal.esc(e.escolha)}</div>
            ${e.renuncia
              ? `<div class="small text-muted mt-1 texto-celula"><strong>Renúncia:</strong> ${Modal.esc(e.renuncia)}</div>`
              : ''}
          </td>
          <td>${colInds}</td>
          <td>${colValores}</td>
          <td>${colProjs}</td>
        </tr>`;
      }).join('');
    }).join('');

    const total = escolhas.length;
    alvoAba.innerHTML = `
      <div class="d-flex justify-content-end align-items-center flex-wrap gap-2">
        <span class="badge ${semMedida ? 'text-bg-warning' : 'text-bg-success'} fs-6"
          title="Escolhas sem nenhum indicador vinculado">${semMedida} sem medida</span>
        <span class="badge badge-horizonte fs-6">${total} escolha${total === 1 ? '' : 's'}</span>
      </div>
      <p class="text-muted">O que cada escolha da cascata <strong>mede</strong> (indicadores) e
      <strong>executa</strong> (projetos), agrupado por eixo. O vínculo do indicador se edita em
      <em>Metas · Indicadores</em>; o do projeto, em <em>Projetos</em>. Escolha sem indicador é
      decisão que ninguém acompanha — é isso que o contador acima cobra.</p>
      ${total ? `<div class="table-responsive">
        <table class="table table-sm table-bordered align-middle tabela-execucao">
          <thead><tr>
            <th style="min-width:120px">Eixo</th>
            <th style="min-width:240px">Escolha (e a renúncia)</th>
            <th style="min-width:180px">Indicadores</th>
            <th style="min-width:150px">Meta / Real</th>
            <th style="min-width:200px">Iniciativas (projetos)</th>
          </tr></thead>
          <tbody>${corpo}</tbody>
        </table>
      </div>`
      : `<div class="alert alert-info">Nenhuma escolha definida ainda nesta cascata.
         Preencha a <a href="#" data-aba-cascata="matriz">Matriz de Escolhas</a> primeiro.</div>`}`;

    alvoAba.querySelectorAll('[data-aba-cascata]').forEach((a) => {
      a.addEventListener('click', (ev) => {
        ev.preventDefault();
        this.aba = a.dataset.abaCascata;
        this.carregar();
      });
    });
  },

  renderDetalhe() {
    const { horizontes, drivers, eixos, escolhas } = this.dados;
    const alvo = document.getElementById('detalhe-celula');
    // A aba de execução não desenha o detalhe. `aoBater` chega aqui pelo
    // relógio do quiz, e sem esta linha uma batida no meio da troca de aba
    // estouraria em `alvo.innerHTML` de null, derrubando a seção inteira.
    if (!alvo || !this.celulaAberta) return;
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

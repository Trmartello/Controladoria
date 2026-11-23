/**
 * Projeção de Resultados — a DRE do ano e a proposta do ano seguinte
 * (fatias 1 e 2 do tema 13).
 *
 * Três colunas de leitura e uma de escrita: o REALIZADO dos meses fechados, o
 * PROJETADO dos meses que faltam (o orçamento lançado para eles), a PROJEÇÃO do
 * ano, que é a soma, e a PROPOSTA do ano de planejamento, que o negócio lança.
 *
 * Decisões que não devem ser desfeitas:
 *
 * - **A.V. é sobre a ROL**, não sobre a receita bruta — convenção da
 *   controladoria, confirmada pelo cliente em 2026-11-05, e o que a planilha
 *   faz (`D9/D$15`). Cada coluna usa a ROL dela: a margem do realizado sobre a
 *   receita realizada, a do projetado sobre a projetada.
 * - **As linhas de percentual (Margem Bruta, Desp./ROL, Margem Líquida) são
 *   CALCULADAS aqui.** Na extração elas vêm zeradas — são fórmula de tela no
 *   próprio Qlik. Exibir o valor guardado mostraria 0,00% em três linhas.
 * - **Quem recebe proposta é a SUBCONTA; o bloco é conta** (a soma, feita no
 *   servidor). Sem isso, a linha de despesas poderia valer um total diferente
 *   da soma das despesas logo abaixo dela, as duas na mesma tela.
 * - **A célula aceita `1.500.000` ou `12%`.** O `%` é crescimento sobre a
 *   projeção, e o que fica gravado é o que foi digitado — valor OU percentual,
 *   nunca os dois: com os dois, a recarga do fechamento seguinte os faria
 *   divergir e ninguém saberia qual deles a pessoa quis dizer.
 * - **O percentual tem célula PRÓPRIA, a coluna Reajuste** — a coluna B da
 *   planilha do cliente (`PROPOSTA = Projeção × (1 + B%)`, conferido nas 16
 *   linhas de despesa dele, com resíduo de arredondamento de até R$ 134). É a
 *   mesma `entrada = 'PERCENTUAL'` de sempre; o que muda é que o % e o valor
 *   deixam de disputar um campo só. Continua havendo **um** lançamento por
 *   linha: a última célula tocada decide a `entrada`, e a outra passa a ser a
 *   conta dela, marcada como espelho. Sem a marca não dá para saber se a linha
 *   está ancorada num percentual — e vai se mexer quando o fechamento andar —
 *   ou congelada num valor.
 * - **Célula vazia é "sem proposta", e vale a PROJEÇÃO** (crescimento zero),
 *   não zero — zero seria uma proposta de fechar a linha. A tela marca em
 *   cinza o que está ali por omissão.
 */
const SecaoProjecao = {
  negocioId: null,
  // 0 = a visão do NEGÓCIO; qualquer outro valor, a filial aberta.
  filialId: 0,
  versao: 0,

  moeda(v) {
    return Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  },

  pct(v, casas = 1) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    return `${(100 * v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas })}%`;
  },

  nomeMes(m) {
    return ['jan', 'fev', 'mar', 'abr', 'mai', 'jun',
      'jul', 'ago', 'set', 'out', 'nov', 'dez'][m - 1] || m;
  },

  /**
   * O que vai DENTRO da célula de Proposta: sempre dinheiro.
   *
   * Quando a linha foi lançada por percentual, aqui aparece o valor que o
   * percentual produziu — o `%` tem a coluna dele ao lado. Antes da coluna de
   * Reajuste existir, esta célula mostrava o texto `11,43%` e o valor não
   * aparecia em lugar nenhum da linha.
   */
  textoLancado(l) {
    if (l.proposta_origem === 'PERCENTUAL') return this.moeda(l.proposta);
    if (l.proposta_origem === 'VALOR') return this.moeda(l.proposta_lancado);
    return '';
  },

  /** O que vai DENTRO da célula de Reajuste: sempre percentual. */
  textoReajuste(l) {
    if (l.proposta_origem === 'PERCENTUAL') {
      return `${Number(l.proposta_lancado).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
    }
    // Lançada por valor: o reajuste é a conta de volta. Sem projeção não há
    // base, e aí a célula fica vazia em vez de "—" — "—" dentro de um campo
    // de digitar seria um texto que a pessoa teria de apagar antes de usar.
    if (l.proposta_origem === 'VALOR' && l.projecao) {
      const v = l.proposta / l.projecao - 1;
      return isFinite(v) ? this.pct(v, 2) : '';
    }
    return '';
  },

  async carregar() {
    const el = document.getElementById('secao-projecao');
    // O negócio é UM SÓ no sistema (pedido do cliente, 2026-11-18: "esses campos
    // devem ser o mesmo"): quem manda é o contexto do menu (`App.contexto`), na
    // tela e no dossiê. Antes a Projeção guardava um negócio próprio, e o topo
    // dizia "12 - P. Resf. Leite" sobre uma tela de Cereais. O seletor desta
    // tela continua, por conveniência, mas só ESCREVE no contexto — ver `ligar`.
    const dossie = !!App.modoDossie;
    const corp = !!App.contexto.corporativo;
    const alvo = corp ? 0 : Number(App.contexto.negocioId) || 0;
    // A filial aberta só vale para o negócio em que foi aberta.
    if (alvo !== this.negocioId) this.filialId = 0;
    const q = corp
      ? '?corporativo=1'
      : (alvo
        ? `?negocio_id=${alvo}${!dossie && this.filialId ? `&filial_id=${this.filialId}` : ''}`
        : '');
    const d = await App.api(`/api/projecao${q}`);
    if (corp && d.corporativo) {
      this.dados = d;
      el.innerHTML = this.htmlCorporativo(d);
      this.ligar(el);
      return;
    }
    // Quem não enxerga todos os negócios não recebe o consolidado: diz por quê
    // em vez de mostrar um parcial.
    if (corp) {
      el.innerHTML = `<h1>Projeção de Resultados</h1>
        <p class="text-muted">O consolidado dos negócios é do perfil que enxerga todos eles.</p>`;
      return;
    }
    // A API cai no primeiro negócio com DRE quando o pedido não tem. Mostrar esse
    // outro negócio seria o número de um sob o nome do outro (o do menu) — então
    // a tela diz que este não tem DRE e oferece os que têm.
    if (d.negocios && d.negocios.length && Number(d.negocio_id) !== Number(alvo)) {
      el.innerHTML = `<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <h1 class="mb-0 me-auto">Projeção de Resultados</h1>
          ${dossie ? '' : `<select id="projecao-negocio" class="form-select form-select-sm" style="max-width:18rem"
            aria-label="Negócio"><option value="" selected>— escolha um negócio com DRE —</option>${
  this.opcoesDeNegocio({ ...d, negocio_id: 0 })}</select>`}
        </div>
        <p class="text-muted" id="projecao-sem-dre">Este negócio ainda não tem a DRE carregada — a carga vem do Qlik,
        pela controladoria, e é feita por negócio.</p>`;
      this.negocioId = alvo;
      this.ligar(el);
      return;
    }

    if (d.sem_carga || !d.negocios.length) {
      el.innerHTML = `<h1>Projeção de Resultados</h1>
        <div class="alert alert-info">${d.sem_carga
    ? 'Nenhuma DRE carregada ainda. A carga vem do Qlik pela controladoria, no deploy.'
    : 'Nenhum negócio com DRE carregada no seu escopo.'}</div>`;
      return;
    }
    if (!dossie) this.negocioId = d.negocio_id;
    this.versao = d.versao;
    // A filial pedida pode não existir neste negócio (troquei de negócio com
    // uma filial aberta): quem manda é o que o servidor devolveu.
    this.filialId = d.filial ? d.filial.id : 0;
    this.dados = d;
    this.avisoPiso = '';

    el.innerHTML = this.html(d);
    this.ligar(el);
  },

  html(d) {
    // No dossiê a seção vira PÁGINA: seletor e botão de rádio não se
    // imprimem — viram caixa vazia no papel. O que era escolha vira título.
    const dossie = !!App.modoDossie;
    const nomeNegocio = (d.negocios.find((n) => Number(n.id) === Number(d.negocio_id)) || {}).rotulo || '';
    const opcoes = this.opcoesDeNegocio(d);

    const faltando = d.meses_sem_meta.length
      ? `<div class="alert alert-warning py-2 px-3 small mb-3">
           <strong>Projeção incompleta.</strong> Não há orçamento lançado para
           ${d.meses_sem_meta.map((m) => this.nomeMes(m)).join(', ')} de ${d.ano} —
           esses meses entram como zero na coluna Projetado.
         </div>`
      : '';

    const assinatura = d.atualizado_em
      ? `<span class="text-muted small">Última alteração por ${Modal.esc(d.atualizado_por || '—')}
         em ${String(d.atualizado_em).slice(8, 10)}/${String(d.atualizado_em).slice(5, 7)}</span>`
      : '<span class="text-muted small">Nenhuma proposta lançada ainda.</span>';

    // O modo de planejamento é escolha do NEGÓCIO (decisão do cliente): de cima
    // para baixo, lançando no negócio e distribuindo às filiais depois; ou de
    // baixo para cima, lançando em cada filial e somando. Trocar não apaga
    // lançamento nenhum — só muda qual dos dois forma o total.
    const seletorModo = d.filiais.length && !dossie ? `
      <div class="btn-group btn-group-sm" role="group" aria-label="Como este negócio planeja">
        <input type="radio" class="btn-check" name="projecao-modo" id="modo-negocio"
          value="NEGOCIO" ${d.modo === 'NEGOCIO' ? 'checked' : ''} ${d.pode_editar ? '' : 'disabled'}>
        <label class="btn btn-outline-secondary" for="modo-negocio"
          title="Planejar o negócio e distribuir às filiais depois — distribuir não é requisito para salvar">Planejar o negócio</label>
        <input type="radio" class="btn-check" name="projecao-modo" id="modo-filial"
          value="FILIAL" ${d.modo === 'FILIAL' ? 'checked' : ''} ${d.pode_editar ? '' : 'disabled'}>
        <label class="btn btn-outline-secondary" for="modo-filial"
          title="Planejar cada filial; o negócio passa a ser a soma delas">Planejar por filial</label>
      </div>` : '';

    const seletorFilial = d.filiais.length && !dossie ? `
      <select id="projecao-filial" class="form-select form-select-sm" style="max-width:16rem"
        aria-label="Filial">
        <option value="0"${d.filial ? '' : ' selected'}>Negócio (consolidado)</option>
        ${d.filiais.map((f) => `<option value="${f.id}"${
  d.filial && Number(d.filial.id) === Number(f.id) ? ' selected' : ''
}>${Modal.esc(f.cod_filial)} — ${Modal.esc(f.nome)}</option>`).join('')}
      </select>` : '';

    return `
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="mb-0 me-auto">Projeção de Resultados — ${d.ano}</h1>
        ${seletorModo}
        ${seletorFilial}
        ${dossie
    ? `<span class="fs-5">${Modal.esc(nomeNegocio)}</span>`
    : `<select id="projecao-negocio" class="form-select form-select-sm" style="max-width:18rem"
          aria-label="Negócio">${opcoes}</select>`}
      </div>
      <p class="text-muted mb-1">
        Realizado de <strong>jan a ${this.nomeMes(d.mes_fechado_ate)}</strong> (meses fechados) e
        projetado de <strong>${this.nomeMes(d.mes_fechado_ate + 1)} a dez</strong> pelo orçamento
        lançado. A coluna <strong>Proposta</strong> é a meta de <strong>${d.ano_planejamento}</strong>:
        digite o valor (<code>1.500.000</code>) ali, ou o reajuste sobre a projeção
        (<code>11,43</code>) na coluna <strong>Reajuste</strong>. Uma preenche a outra.
      </p>
      <p class="mb-2">${assinatura}</p>
      ${this.cartaoVisaoAno(d)}
      ${this.cartaoDesafio(d)}
      ${this.cartaoOportunidades(d)}
      ${this.cartaoTributosDre(d)}
      ${this.avisoFiliaisDefasadas(d)}
      ${this.faixaDoModo(d)}
      ${this.painel(d)}
      ${faltando}
      <div id="projecao-meta-negativa-box">${this.alertaMetaNegativa(d)}</div>
      <div id="projecao-aviso" class="alert alert-danger py-2 px-3 small mb-2 d-none"></div>
      ${this.cartaoCenario(d)}
      <div id="projecao-dre-bloco">
      ${this.cabecalhoImpressao(d, d.filial ? `Filial ${(d.filiais.find((f) => Number(f.id) === Number(d.filial.id)) || {}).nome || ''}` : '')}
      ${this.botaoNivel()}
      <div class="small mb-1 nota-formato-2027"><span class="linha-muda px-1">Formato de ${d.ano_planejamento}</span>
        A DRE já está na forma da reforma tributária: a <strong>CBS</strong> (em ${d.ano}, o PIS/COFINS) é linha informativa
        acima da Receita Bruta, e Receita Bruta, Deduções e Imposto s/ Vendas estão <strong>sem ela</strong> — inclusive o
        ${d.ano}, convertido para comparar. A Receita Líquida e o que vem abaixo não mudam. O que se lança na Receita de
        Vendas e no Imposto s/ Vendas é no formato novo (sem a CBS); a CBS da proposta acompanha a receita.</div>
      <div class="table-responsive tabela-projecao${this.estaRecolhido() ? ' recolhida' : ''}${this.nivel3Aberto() ? ' nivel3' : ''}">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Conta</th>
            <th class="text-end">Realizado<br><span class="fw-normal text-muted small">jan–${this.nomeMes(d.mes_fechado_ate)}</span></th>
            <th class="text-end">Projetado<br><span class="fw-normal text-muted small">${this.nomeMes(d.mes_fechado_ate + 1)}–dez</span></th>
            <th class="text-end">Projeção<br><span class="fw-normal text-muted small">do ano</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL</span></th>
            <th class="text-end">Proposta<br><span class="fw-normal text-muted small">${d.ano_planejamento}</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL proposta</span></th>
            <th class="text-end">Reajuste<br><span class="fw-normal text-muted small">sobre a projeção</span></th>
            ${!d.filial && d.filiais.length && d.modo === 'NEGOCIO'
    ? '<th class="text-end">A distribuir<br><span class="fw-normal text-muted small">entre as filiais</span></th>'
    : ''}
          </tr></thead>
          <tbody>${this.corpo(d)}</tbody>
        </table>
      </div>
      <div id="projecao-piso-box">${this.alertaPisoFaturamento(d)}</div>
      <div id="projecao-pessoal-box">${this.alertaPessoalMinimo(d)}</div>
      <p class="text-muted small">
        <span class="legenda-editavel"></span> Célula em amarelo-claro é onde se lança.
        Valores em R$. A.V. = análise vertical sobre a Receita Operacional Líquida.
        A proposta é lançada nas <strong>subcontas</strong>; as linhas em negrito são a soma delas.
        Célula vazia vale o padrão: a projeção, ou o que as premissas e a diretriz dão. Proposta e Reajuste são o
        <strong>mesmo lançamento</strong> visto de dois jeitos: a que você digita manda,
        a outra sai em cinza-itálico porque é a conta dela.
        Fonte: ${Modal.esc(d.fonte || 'Qlik')}${d.extraido_em
  ? `, extraído em ${d.extraido_em.split('-').reverse().join('/')}` : ''}.
        Mês sem rateio é mês aberto e não entra como realizado.
      </p>
      </div>
      ${this.cartaoEstoque(d)}`;
  },

  /**
   * O ano cujas premissas o painel mostra: o que a pessoa escolheu, se ele
   * ainda é oferecido para este negócio; senão o ano da proposta. No dossiê é
   * sempre o da proposta — o documento fala dela, e um seletor não imprime.
   */
  anoDoPainel(d) {
    if (App.modoDossie) return d.ano_planejamento;
    const anos = d.anos_premissa || [d.ano_planejamento];
    return anos.includes(this.anoPremissa) ? this.anoPremissa : d.ano_planejamento;
  },

  /**
   * O painel de indicadores: três premissas DIGITADAS e três leituras.
   *
   *   Digitadas:  IPCA · PIB · Crescimento desejado
   *   Lidas:      Cresc. Total = proposta da receita ÷ projeção − 1   (`B14`)
   *               Cresc. Real  = Cresc. Total − IPCA − PIB            (`B13`)
   *               % Custos     = proposta dos custos ÷ projeção − 1   (`B17`)
   *
   * **As três digitadas são do NEGÓCIO e de cada ANO** (pedido do cliente,
   * 2026-11-16 e 2026-11-17), e valem para todas as filiais dele. Na filial os
   * campos aparecem travados, com o valor herdado, e o aviso diz onde
   * alterá-los; os três valores LIDOS são da filial, calculados das linhas dela
   * com o IPCA e o PIB do negócio.
   *
   * **As três LIDAS só existem no ano da proposta**: saem das linhas dela, e as
   * linhas só existem para ele. Em outro ano o painel mostra as três entradas e
   * diz por que as leituras não estão ali — três "—" no lugar pareceriam um
   * defeito, e não uma ausência que faz sentido.
   *
   * Isto se afasta da planilha, de propósito: ali cada bloco de filial tem o
   * seu IPCA e o seu PIB e os percentuais são digitados (`AD10 = AA10 * Z14%`).
   * O cliente pediu um só por negócio e por ano. Desde 2026-11-18 as premissas
   * movem a proposta: a Receita cresce IPCA + PIB + crescimento desejado, e as
   * Despesas com Pessoal só o percentual de pessoas (por omissão, onde a linha
   * não foi lançada). O Cresc. Total lido é, então, a soma dos três; e o Cresc.
   * Real, o desejado.
   */
  painel(d) {
    const anoProposta = d.ano_planejamento;
    const anos = d.anos_premissa || [anoProposta];
    const ano = this.anoDoPainel(d);
    const noAnoDaProposta = ano === anoProposta;
    // As digitadas são as do ANO escolhido; as lidas, as do ano da proposta.
    const digitadas = (d.premissas_por_ano || {})[ano] || {};
    const lidas = this.visao(d).premissas || {};
    const num = (v, casas = 2) => (v === null || v === undefined
      ? '—'
      : `${Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas })}%`);
    const editavel = this.premissaEditavel(d);
    // O padrão e o efetivo dos valores em reais (vale e abono) deste ano.
    const padrao = (d.pessoas || {}).padrao || {};
    const efetivo = ((d.pessoas || {}).por_ano || {})[ano];
    const valores = efetivo ? efetivo.valores : padrao;
    const plano = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
    const reais = (v) => `R$ ${Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}`;
    const diretriz = ((d.diretriz || {})[ano]) || null;
    const fixa = (chave) => (diretriz && diretriz.premissas && diretriz.premissas[chave] !== undefined
      ? diretriz.premissas[chave] : null);
    // IPCA e PIB: a diretriz SUGERE e cada negócio pode ajustar (pedido do
    // cliente, 2026-11-18). O campo mostra o valor que vale; o selo "diretriz"
    // aparece enquanto o negócio não digitou o seu, e apagar devolve a sugestão.
    const brutas = ((d.premissas_digitadas_por_ano || {})[ano]) || {};
    const sugerida = (chave) => (diretriz && diretriz.sugeridas && diretriz.sugeridas[chave] !== undefined
      ? diretriz.sugeridas[chave] : null);
    // O selo verde diz se o valor do campo É O DA DIRETRIZ (pedido do cliente,
    // 2026-11-19: "se eu digitar 4,5, que é conforme a diretriz, ele marca; caso
    // contrário, o campinho verde sai"). Vale para o sugerido sem digitação e para o
    // digitado igual a ele; digitado diferente fica sem selo.
    const igualDiretriz = (chave) => sugerida(chave) !== null
      && (brutas[chave] === undefined || brutas[chave] === null
        || Math.abs(Number(brutas[chave]) - Number(sugerida(chave))) < 1e-6);
    const seloSugerida = (chave) => (igualDiretriz(chave)
      ? ` <span class="badge selo-diretriz selo-sugerida" data-sugerida="${chave}"
           title="${Modal.esc(brutas[chave] === undefined || brutas[chave] === null
    ? `Sugerido por ${diretriz.fonte}. O negócio pode ajustar; apagar devolve ${num(sugerida(chave), 2)}.`
    : `Igual ao valor das ${diretriz.fonte}: ${num(sugerida(chave), 2)}.`)}">diretriz</span>`
      : '');
    const campo = (chave, rotulo, dica) => (fixa(chave) !== null
      ? `<div class="col-6 col-md-auto">
           <div class="small text-muted mb-0" title="${Modal.esc(dica)}">${rotulo}</div>
           <div class="fw-bold campo-diretriz" data-diretriz="${chave}" title="${Modal.esc(diretriz.fonte)}">
             ${num(fixa(chave), 2)} <span class="badge selo-diretriz">diretriz</span></div>
         </div>`
      : App.modoDossie
      ? `<div class="col-6 col-md-auto">
           <div class="small text-muted" title="${Modal.esc(dica)}">${rotulo}</div>
           <div class="fw-bold">${num(digitadas[chave], 2)}${seloSugerida(chave)}</div>
         </div>`
      : `<div class="col-6 col-md-auto">
        <label class="form-label small text-muted mb-0" for="premissa-${chave}"
          title="${Modal.esc(dica)}">${rotulo}${seloSugerida(chave)}</label>
        <div class="input-group input-group-sm premissa-campo">
          <input type="text" class="form-control celula-premissa" id="premissa-${chave}"
            inputmode="decimal" autocomplete="off" data-chave="${chave}"${chave === 'CRESCIMENTO' ? ' data-sinal' : ''}
            value="${digitadas[chave] === null || digitadas[chave] === undefined ? '' : String(digitadas[chave]).replace('.', ',')}"
            placeholder="—" ${editavel ? '' : 'disabled'}>
          <span class="input-group-text">%</span>
        </div>
      </div>`);
    const leitura = (valor, rotulo, dica) => `
      <div class="col-6 col-md-auto">
        <div class="small text-muted" title="${Modal.esc(dica)}">${rotulo}</div>
        <div class="fw-bold" data-premissa="${Modal.esc(rotulo)}">${num(valor, 2)}</div>
      </div>`;

    // O ano escolhido: um seletor na tela, e só o texto no dossiê (que é papel).
    const seletorAno = App.modoDossie
      ? `<div class="small text-muted mb-2">Premissas de <strong>${ano}</strong></div>`
      : (anos.length > 1
        ? `<div class="d-flex align-items-center gap-2 mb-2">
            <label class="small text-muted mb-0 text-nowrap" for="premissa-ano">Premissas do ano</label>
            <select id="premissa-ano" class="form-select form-select-sm w-auto">
              ${anos.map((a) => `<option value="${a}"${a === ano ? ' selected' : ''}>${a}${a === anoProposta ? ' · ano da proposta' : ''}</option>`).join('')}
            </select>
          </div>`
        : '');

    // O que explica o painel muda com o nível (no negócio se lança, na filial se
    // herda) e com o ano (só o da proposta tem leituras). Sem a frase da filial,
    // três campos cinzentos parecem quebrados.
    const tres = `<strong>IPCA</strong>, <strong>PIB</strong>, <strong>Crescimento desejado</strong>,
      <strong>Cresc. despesas com pessoas</strong> e o <strong>vale e o abono</strong> de ${ano}`;
    const nota = diretriz && !d.filial
      ? `<span class="nota-crescimento">O percentual de crescimento é <strong>sobre o faturamento</strong> (Receita de Vendas):
          ele cresce <strong>IPCA + PIB + crescimento desejado</strong>, e o restante é <strong>proporcional</strong> —
          deduções e custo das vendas acompanham o faturamento na mesma proporção (a margem bruta de ${d.ano} se mantém).</span>
          As <strong>Despesas com Pessoal</strong> partem da conta da diretriz (cartão Pessoas e benefícios), que é também o
          <strong>mínimo</strong> da linha: acima se lança livre, abaixo grava com ⚠ para validar; as demais despesas ficam na projeção. Nas outras linhas, a célula digitada sempre manda.
          ${noAnoDaProposta
    ? `<strong>Cresc. Real</strong>, <strong>Cresc. Total</strong> e <strong>% Custos</strong>
          são lidos da coluna Proposta.` : ''}`
      : d.filial
      ? `${tres} vêm do negócio e valem para todas as filiais — para alterá-los, volte ao negócio.
          ${noAnoDaProposta ? 'Os três valores lidos ao lado são desta filial.' : ''}`
      : `${tres} são lançados aqui e valem para o negócio e todas as filiais.
          Na proposta de ${d.ano_planejamento}, a <strong>Receita de Vendas</strong> cresce
          <strong>IPCA + PIB + crescimento desejado</strong> e as <strong>Despesas com Pessoal</strong>
          crescem só o percentual das despesas com pessoas — o padrão e o mínimo da linha (abaixo dele, grava com ⚠).
          Nas outras linhas, a célula digitada sempre manda; as demais ficam na projeção.
          ${noAnoDaProposta
    ? `<strong>Cresc. Real</strong>, <strong>Cresc. Total</strong> e <strong>% Custos</strong>
          são lidos da coluna Proposta — o reajuste de cada linha se lança na própria linha,
          como <code>5%</code>.`
    : ''}`;

    const notaDiretriz = diretriz
      ? `<div class="small mt-2 nota-diretriz"><span class="badge selo-diretriz">diretriz</span>
          <strong>${Modal.esc(diretriz.fonte)}</strong>: o reajuste de pessoal, o vale e os prêmios de ${ano}
          valem para todos os negócios e não se digitam. <strong>IPCA</strong> e <strong>PIB</strong> vêm sugeridos pela
          diretriz e, como o <strong>crescimento desejado</strong>, cada negócio pode ajustar — as filiais recebem os
          do negócio, sem editar. Custos e deduções acompanham a receita (margem bruta de ${d.ano} mantida), as demais
          despesas não crescem, e o resultado líquido negativo aparece destacado na tabela.</div>`
      : '';
    const leituras = noAnoDaProposta
      ? `<div class="w-100 d-md-none"></div>
          ${leitura(lidas.CRESC_REAL, 'Cresc. Real',
    'Crescimento total menos IPCA e PIB — o que sobra depois da inflação e da economia. '
    + 'Precisa dos dois lançados para existir.')}
          ${leitura(lidas.CRESC_TOTAL, 'Cresc. Total',
    'A proposta da receita contra a projeção do ano. Lido, não lançado: '
    + 'o crescimento se lança na linha da Receita de Vendas.')}
          ${leitura(lidas.PCT_CUSTOS, '% Custos',
    'A proposta dos custos contra a projeção do ano. Lido da linha Custos das Vendas.')}`
      : `<div class="col-12 small text-muted aviso-leituras">A proposta existe só para <strong>${anoProposta}</strong>:
          Cresc. Real, Cresc. Total e % Custos saem das linhas dela e aparecem apenas nesse ano.</div>`;

    // O abono do mês é a soma das duas partes: R$ 200 de assiduidade e R$ 500 se
    // o negócio atingir o resultado — R$ 700 em 2027 (pedido do cliente).
    const abonoTotal = `<div class="col-12 small text-muted" id="abono-total">
        Abono por colaborador/mês em ${ano}: <strong>${reais(Number(valores.ASSIDUIDADE) + Number(valores.RESULTADO))}</strong>
        (${reais(valores.ASSIDUIDADE)} de assiduidade + ${reais(valores.RESULTADO)} se o negócio atingir o resultado).
        Vale alimentação: <strong>${reais(valores.ALIMENTACAO)}</strong> por colaborador/mês.
      </div>`;

    const pctCurto = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}%`);
    const resumo = `${ano} · IPCA ${pctCurto(digitadas.IPCA)} · PIB ${pctCurto(digitadas.PIB)}
      · desejado ${pctCurto(digitadas.CRESCIMENTO)} · pessoas ${pctCurto(digitadas.PESSOAS)}
      · vale ${reais(valores.ALIMENTACAO)} · abono ${reais(Number(valores.ASSIDUIDADE) + Number(valores.RESULTADO))}`;
    // Pessoas e benefícios moram NESTE bloco desde 2026-11-21 (pedido do
    // cliente: "esses dois quadrantes se referem à mesma coisa"): os campos de
    // quadro e de previsão vêm logo abaixo dos valores, e o resultado sai em
    // duas contas separadas — o que vai para o faturamento e o que vai para as
    // Despesas com Pessoal. Na filial não há pessoas (o quadro é do negócio).
    const comPessoas = !d.filial && !!d.pessoas;
    const calc = comPessoas ? (d.pessoas.por_ano || {})[ano] : null;
    const resumoPessoas = calc
      ? ` · ${Number(calc.colaboradores).toLocaleString('pt-BR')} colaboradores · benefícios ${reais(calc.anual.total)} no ano`
      : '';
    return this.expansor('premissas', 'painel-premissas', `Premissas, pessoas e benefícios — ${ano}`, resumo + resumoPessoas, `
        ${seletorAno}
        <div class="row g-3 align-items-end">
          ${campo('IPCA', 'IPCA', `Inflação prevista para ${ano}. Vale para o negócio e todas as filiais.`)}
          ${campo('PIB', 'PIB', `Crescimento da economia previsto para ${ano}. Vale para o negócio e todas as filiais.`)}
          ${campo('CRESCIMENTO', 'Crescimento desejado <span class="text-muted">(sobre o faturamento)</span>',
    `O crescimento REAL que se deseja para o FATURAMENTO (Receita de Vendas) em ${ano}, acima da inflação e da `
    + 'economia. O faturamento cresce IPCA + PIB + este percentual; deduções e custo das vendas acompanham na '
    + 'mesma proporção. Vale para o negócio e todas as filiais.')}
          ${campo('PESSOAS', 'Cresc. despesas com pessoas',
    `O crescimento das despesas com pessoas em ${ano}. Atua SÓ na linha Despesas com Pessoal (8.5): `
    + 'IPCA, PIB e o crescimento desejado não tocam nela. Vale para o negócio e todas as filiais.')}
          ${this.campoBeneficio(d, ano, 'ALIMENTACAO', 'Vale alimentação (por mês)', 'R$',
    'Valor por colaborador, por mês, nos 12 meses — o mesmo padrão para todos os colaboradores.', plano(padrao.ALIMENTACAO))}
          ${this.campoBeneficio(d, ano, 'ASSIDUIDADE', 'Abono assiduidade (por mês)', 'R$',
    'Pago ao colaborador que cumpre os quesitos de assiduidade.', plano(padrao.ASSIDUIDADE))}
          ${this.campoBeneficio(d, ano, 'RESULTADO', 'Abono resultado (por mês)', 'R$',
    'Pago só no mês em que o negócio atinge o resultado planejado.', plano(padrao.RESULTADO))}
          ${this.campoBeneficio(d, ano, 'PPR_SALARIOS', 'PPR (salários no ano)', 'salários',
    'Quantos salários de PPR provisionar no ano, em 1/12 por mês, sobre a folha do mês (despesa + custo) com o '
    + 'reajuste. Entra na linha Despesas com Pessoal (8.5). Vazio: sem PPR.', '—')}
          ${abonoTotal}
          ${leituras}
        </div>
        ${comPessoas ? this.camposPessoas(d, ano) : ''}
        ${this.resultadoPremissas(d, ano, comPessoas)}
        <div class="small text-muted mt-2">${nota}</div>
        ${notaDiretriz}`);
  },

  /**
   * Os campos de PESSOAS do bloco de premissas: o quadro atual da DRE e os três
   * lançados por negócio e por ano (colaboradores planejados, % que cumprem a
   * assiduidade, % dos meses com resultado). O quadro já é de colaboradores
   * EQUIVALENTES — quem divide o tempo entre dois negócios entra em cada um pela
   * sua parte —, e o texto diz isso, porque é a dúvida que um número inteiro daria.
   */
  camposPessoas(d, ano) {
    const base = d.pessoas.base;
    const padrao = d.pessoas.padrao || {};
    const num = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
    const campo = (chave, rotulo, unidade, dica, placeholder) => this.campoBeneficio(d, ano, chave, rotulo, unidade, dica, placeholder);
    const quadro = base
      ? `Quadro atual: <strong>${Number(base.colaboradores).toLocaleString('pt-BR')}</strong> colaboradores
         equivalentes (DRE, ${this.nomeMes(base.mes)}/${base.ano}${base.mes < 12 ? ' — último mês carregado' : ''}):
         quem divide o tempo entre dois negócios (30/70, 50/50) entra em cada um pela sua parte.`
      : 'Não há quadro de colaboradores carregado para este negócio.';
    return `<div class="bloco-pessoas mt-3 pt-2 border-top">
        <div class="small text-muted mb-1" id="pessoas-quadro">${quadro}</div>
        <div class="row g-3 align-items-end">
          ${campo('COLABORADORES', 'Colaboradores planejados', 'pessoas',
    'Deixe vazio para usar o quadro atual da DRE; digite para planejar contratação ou desligamento.',
    base ? String(base.colaboradores) : '—')}
          ${campo('PCT_ASSIDUIDADE', '% que cumprem a assiduidade', '%',
    'Previsão: que parte dos colaboradores cumpre os quesitos. 100% é o orçamento cheio.', num(padrao.PCT_ASSIDUIDADE))}
          ${campo('PCT_RESULTADO', '% dos meses com resultado', '%',
    'Previsão: em que parte dos meses o negócio atinge o resultado. 100% é o orçamento cheio.', num(padrao.PCT_RESULTADO))}
        </div>
      </div>`;
  },

  /**
   * O RESULTADO das premissas, em duas contas separadas e totalizadas (pedido do
   * cliente, 2026-11-21):
   *
   *  - **Faturamento** (Receita de Vendas, 1.1): a projeção do ano, quanto cada
   *    premissa acrescenta (IPCA, PIB, crescimento desejado) e o PISO de
   *    ${ano}, que a linha da DRE não pode ficar abaixo; a proposta da DRE e o
   *    impacto em reais.
   *  - **Despesas com Pessoal** (8.5): os benefícios previstos (vale e abonos) e a
   *    conta da diretriz que dá o padrão (e o mínimo) da linha na DRE.
   *
   * As duas contas usam as linhas da TELA (formato de 2027, sem a CBS na
   * receita): o percentual é o mesmo do motor, e os reais batem com a tabela.
   */
  resultadoPremissas(d, ano, comPessoas) {
    const noAnoDaProposta = ano === d.ano_planejamento;
    const fat = noAnoDaProposta ? this.contaFaturamento(d) : '';
    const pes = comPessoas ? this.contaPessoal(d, ano) : '';
    if (!fat && !pes) return '';
    return `<div class="row g-3 mt-1 resultado-premissas">
        ${fat ? `<div class="col-lg-5">${fat}</div>` : ''}
        ${pes ? `<div class="${fat ? 'col-lg-7' : 'col-12'}">${pes}</div>` : ''}
      </div>`;
  },

  /** O que as premissas fazem com o FATURAMENTO: projeção, acréscimos e o piso. */
  contaFaturamento(d) {
    const l = (this.visao(d).linhas || []).find((x) => x.codigo === '1.1');
    if (!l || !l.projecao) return '';
    const ef = (d.premissas_por_ano || {})[d.ano_planejamento] || {};
    const r = (v) => `R$ ${this.moeda(v)}`;
    const p = (v) => `${Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
    const proj = Number(l.projecao);
    const parte = (chave, rotulo) => (ef[chave] === null || ef[chave] === undefined ? ''
      : `<tr><td>+ ${rotulo} (${p(ef[chave])})</td><td class="text-end">${r(proj * Number(ef[chave]) / 100)}</td></tr>`);
    const pisoPct = l.piso_percentual !== undefined && l.piso_percentual !== null ? Number(l.piso_percentual) : null;
    const piso = pisoPct === null ? null : proj * (1 + pisoPct / 100);
    const prop = Number(l.proposta);
    const acima = piso !== null && prop > piso + 1;
    return `<table class="table table-sm mb-1 tabela-faturamento">
        <thead><tr><th>Faturamento ${d.ano_planejamento} (Receita de Vendas)</th><th class="text-end">No ano</th></tr></thead>
        <tbody>
          <tr><td>Projeção de ${d.ano}</td><td class="text-end">${r(proj)}</td></tr>
          ${parte('IPCA', 'IPCA')}${parte('PIB', 'PIB')}${parte('CRESCIMENTO', 'Crescimento desejado')}
          ${piso === null ? '' : `<tr class="fw-bold"><td>Mínimo de ${d.ano_planejamento} <span class="fw-normal text-muted small">(+${p(pisoPct)}: a DRE não aceita menos)</span></td>
            <td class="text-end" data-faturamento="piso">${r(piso)}</td></tr>`}
          ${acima ? `<tr><td>Proposta lançada na DRE <span class="text-muted small">(acima do mínimo)</span></td>
            <td class="text-end" data-faturamento="proposta">${r(prop)}</td></tr>` : ''}
          <tr class="fw-bold"><td>Impacto no faturamento</td>
            <td class="text-end" data-faturamento="impacto">${prop >= proj ? '+' : ''}${r(prop - proj)}</td></tr>
        </tbody>
      </table>`;
  },

  /** "1,5 salário", "2 salários": o campo PPR_SALARIOS por extenso. */
  salariosPpr(v) {
    const n = Number(v) || 0;
    return `${n.toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ${n < 2 ? 'salário' : 'salários'}`;
  },

  /** O que as premissas fazem com as DESPESAS COM PESSOAL: benefícios e a linha 8.5. */
  contaPessoal(d, ano) {
    const calc = (d.pessoas.por_ano || {})[ano] || null;
    const dinheiro = (v) => `R$ ${Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}`;
    // A provisão do PPR (o campo PPR_SALARIOS; 1,5 salário pela diretriz de 2027) ao lado dos benefícios
    // (pedido do cliente, 2026-11-23: "não encontrei o provisionamento do PPR").
    // Ela já estava na linha 8.5 — e portanto no resultado —, mas só aparecia
    // na composição abaixo; aqui ela fica junto do que o negócio paga às pessoas.
    const pd = ano === d.ano_planejamento ? d.pessoal_diretriz : null;
    const ppr = pd && calc ? `
            <tr data-pessoas-linha="ppr"><td>Provisão do PPR <span class="text-muted small">(${this.salariosPpr(pd.ppr_salarios)} sobre a folha de
              ${this.nomeMes(pd.folha_mes)}/${pd.folha_ano}, ${dinheiro(pd.folha_mensal)}, reajustada)</span></td>
              <td class="text-end">${dinheiro(pd.ppr_mensal)}</td><td class="text-end" data-pessoas="ppr">${dinheiro(pd.ppr)}</td></tr>
            <tr class="fw-bold"><td>Benefícios + PPR <span class="fw-normal text-muted small">(na linha 8.5 e no resultado de ${ano})</span></td>
              <td class="text-end">${dinheiro(calc.mensal.total + pd.ppr_mensal)}</td>
              <td class="text-end" data-pessoas="total_ppr">${dinheiro(calc.anual.total + pd.ppr)}</td></tr>` : '';
    const beneficios = calc ? `
        <table class="table table-sm mb-1 tabela-pessoas">
          <thead><tr><th>Benefícios${ppr ? ' e PPR' : ''} ${ano} (${Number(calc.colaboradores).toLocaleString('pt-BR')} colaboradores)</th><th class="text-end">Por mês</th><th class="text-end">No ano</th></tr></thead>
          <tbody>
            <tr><td>Alimentação (${dinheiro(calc.valores.ALIMENTACAO)} × ${Number(calc.colaboradores).toLocaleString('pt-BR')})</td>
              <td class="text-end">${dinheiro(calc.mensal.alimentacao)}</td><td class="text-end" data-pessoas="alimentacao">${dinheiro(calc.anual.alimentacao)}</td></tr>
            <tr><td>Abono de assiduidade</td>
              <td class="text-end">${dinheiro(calc.mensal.assiduidade)}</td><td class="text-end" data-pessoas="assiduidade">${dinheiro(calc.anual.assiduidade)}</td></tr>
            <tr><td>Abono de resultado <span class="text-muted small">(só nos meses em que o negócio atinge o resultado)</span></td>
              <td class="text-end">${dinheiro(calc.mensal.resultado)}</td><td class="text-end" data-pessoas="resultado">${dinheiro(calc.anual.resultado)}</td></tr>
            <tr class="fw-bold"><td>Despesa prevista com benefícios</td>
              <td class="text-end">${dinheiro(calc.mensal.total)}</td><td class="text-end" data-pessoas="total">${dinheiro(calc.anual.total)}</td></tr>${ppr}
          </tbody>
        </table>
        <div class="small text-muted mb-2">
          Se o negócio <strong>nunca</strong> atingir o resultado: <strong data-pessoas="sem_resultado">${dinheiro(calc.sem_resultado_anual)}</strong> no ano.
          Teto (todos cumprem a assiduidade e o resultado vem todo mês): <strong data-pessoas="maximo">${dinheiro(calc.maximo_anual)}</strong>.
        </div>` : '';
    return `${beneficios}${ano === d.ano_planejamento ? this.composicaoPessoal(d) : ''}`;
  },

  /**
   * Um campo do planejamento de pessoas e benefícios (`negocio_beneficio`): o
   * ano é o do painel, e vazio vale o padrão, que aparece como o placeholder.
   * Mora num método porque os valores em reais ficam no painel das premissas e
   * o resto no cartão de pessoas — dois lugares, uma só maneira de desenhar.
   */
  campoBeneficio(d, ano, chave, rotulo, unidade, dica, placeholder) {
    const dig = ((d.pessoas || {}).digitados || {})[ano] || {};
    const editavel = this.premissaEditavel(d);
    const num = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
    const diretriz = ((d.diretriz || {})[ano]) || null;
    const fixo = diretriz && diretriz.beneficios ? diretriz.beneficios[chave] : undefined;
    if (fixo !== undefined) {
      const n = Number(fixo).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
      const valor = unidade === 'R$' ? `R$ ${n}` : unidade === 'salários' ? this.salariosPpr(fixo) : n;
      return `<div class="col-6 col-md-auto">
          <div class="small text-muted mb-0" title="${Modal.esc(dica)}">${rotulo}</div>
          <div class="fw-bold campo-diretriz" data-diretriz="${chave}" title="${Modal.esc(diretriz.fonte)}">
            ${valor} <span class="badge selo-diretriz">diretriz</span></div>
        </div>`;
    }
    return App.modoDossie
      ? `<div class="col-6 col-md-auto"><div class="small text-muted" title="${Modal.esc(dica)}">${rotulo}</div>
          <div class="fw-bold">${dig[chave] === undefined ? `${placeholder} <span class="text-muted small">(padrão)</span>` : num(dig[chave])}</div></div>`
      : `<div class="col-6 col-md-auto">
          <label class="form-label small text-muted mb-0" for="beneficio-${chave}"
            title="${Modal.esc(dica)}">${rotulo}</label>
          <div class="input-group input-group-sm">
            ${unidade === 'R$' ? '<span class="input-group-text">R$</span>' : ''}
            <input type="text" class="form-control celula-beneficio" id="beneficio-${chave}"
              inputmode="decimal" autocomplete="off" data-chave="${chave}"
              value="${num(dig[chave])}" placeholder="${placeholder}" ${editavel ? '' : 'disabled'}>
            ${unidade === '%' ? '<span class="input-group-text">%</span>' : ''}
            ${unidade === 'salários' ? '<span class="input-group-text">salários</span>' : ''}
          </div>
        </div>`;
  },

  /**
   * Pessoas e benefícios no CORPORATIVO (só soma). No negócio, desde
   * 2026-11-21, os campos e a conta moram no bloco de premissas (`painel`,
   * `camposPessoas`, `contaPessoal`).
   */
  cartaoPessoas(d) {
    if (!d.pessoas) return '';
    return d.corporativo ? this.cartaoPessoasCorporativo(d) : '';
  },

  /**
   * A linha Despesas com Pessoal (8.5) da proposta, pela diretriz: a base de
   * 2026 sem vale, prêmio e PPR, reajustada; o vale e os prêmios de 2027 (a parte
   * da despesa); e o PPR (campo `PPR_SALARIOS`) sobre a folha do mês. É a conta do
   * servidor (`Projecao::pessoalDaDiretriz`), só mostrada — é o PADRÃO da linha
   * 8.5 da DRE e o seu mínimo (desde 2026-11-23 a linha se lança de novo).
   */
  composicaoPessoal(d) {
    const p = d.pessoal_diretriz;
    if (!p) return '';
    const r = (v) => `R$ ${Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}`;
    const pct = (v) => `${(100 * v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
    const reaj = (((d.diretriz || {})[d.ano_planejamento] || {}).premissas || {}).PESSOAS;
    return `<div class="table-responsive mt-2">
        <table class="table table-sm mb-1 tabela-pessoal-diretriz">
          <thead><tr><th>Despesas com Pessoal ${d.ano_planejamento} (linha 8.5), pela diretriz</th><th class="text-end">No ano</th></tr></thead>
          <tbody>
            <tr><td>Projeção de ${d.ano}</td><td class="text-end">${r(-p.projecao)}</td></tr>
            <tr><td class="ps-3 text-muted">− vale, prêmio e PPR de ${d.ano} (${pct(p.parte_2026)} da folha)</td><td class="text-end text-muted">−${r(p.pecas_2026)}</td></tr>
            <tr><td>Base reajustada${reaj !== undefined ? ` em ${String(reaj).replace('.', ',')}%` : ''}${
  Math.abs((p.fator_quadro || 1) - 1) > 1e-9 ? ` <span class="text-muted small" data-pessoal="fator">(× ${pct(p.fator_quadro)}: o quadro planejado sobre o atual)</span>` : ''}</td><td class="text-end" data-pessoal="base">${r(p.base_reajustada)}</td></tr>
            <tr><td>+ vale e prêmios de ${d.ano_planejamento}${p.parte_despesa < 0.999 ? ` <span class="text-muted small">(${pct(p.parte_despesa)}: a parte da folha que é despesa; o resto está no custo)</span>` : ''}</td>
              <td class="text-end" data-pessoal="beneficios">${r(p.beneficios)}</td></tr>
            <tr><td>+ PPR: ${this.salariosPpr(p.ppr_salarios)} sobre a folha de ${this.nomeMes(p.folha_mes)}/${p.folha_ano} (${r(p.folha_mensal)}) reajustada
              <span class="text-muted small">— ${r(p.ppr_mensal)} provisionados por mês</span></td>
              <td class="text-end" data-pessoal="ppr">${r(p.ppr)}</td></tr>
            <tr class="fw-bold"><td>Despesas com Pessoal de ${d.ano_planejamento} (linha 8.5 da DRE) <span class="fw-normal text-muted small">(${(p.percentual >= 0 ? '+' : '') + p.percentual.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}% sobre a projeção)</span></td>
              <td class="text-end" data-pessoal="total">${r(-p.proposta)}</td></tr>
            <tr class="fw-bold"><td>Impacto nas despesas</td>
              <td class="text-end" data-pessoal="impacto">${-p.proposta >= -p.projecao ? '+' : ''}${r(-p.proposta + p.projecao)}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="small text-muted">Folha e peças de ${d.ano}: Qlik DRE Gerencial, contas 3.07.01.21* (despesa) e 4.03.01.01.01 (custo).
        Este total é o PADRÃO da linha 8.5 e também o seu MÍNIMO: lance acima na DRE se houver contratação ou reajuste maior;
        abaixo dele a linha grava com ⚠ para validar. A folha e o PPR andam na proporção do quadro planejado.</div>`;
  },

  /** O Corporativo só totaliza: a soma dos negócios, sem campo nenhum. */
  cartaoPessoasCorporativo(d) {
    const ano = d.ano_planejamento;
    const c = (d.pessoas.por_ano || {})[ano];
    const dinheiro = (v) => `R$ ${Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}`;
    if (!c) {
      return `<div class="card mb-3 painel-pessoas"><div class="card-body py-2 px-3 small text-muted">
        Pessoas e benefícios: nenhum negócio tem quadro de colaboradores carregado.</div></div>`;
    }
    const base = d.pessoas.base;
    return this.expansor('pessoas', 'painel-pessoas', `Pessoas e benefícios — ${ano}`,
      `${Number(c.colaboradores).toLocaleString('pt-BR')} colaboradores · ${dinheiro(c.anual.total)} no ano`, `
        <div class="small text-muted mb-2" id="pessoas-quadro">
          Soma de <strong>${c.negocios} negócios</strong> com quadro:
          <strong>${Number(c.colaboradores).toLocaleString('pt-BR')}</strong> colaboradores equivalentes
          ${base ? `(DRE, ${this.nomeMes(base.mes)}/${base.ano}).` : '.'}
          Não inclui as áreas de apoio (Apoio Operacional e Administração Matriz).
        </div>
        <table class="table table-sm mb-1 tabela-pessoas">
          <thead><tr><th></th><th class="text-end">Por mês</th><th class="text-end">No ano</th></tr></thead>
          <tbody>
            <tr><td>Alimentação</td><td class="text-end">${dinheiro(c.mensal.alimentacao)}</td><td class="text-end" data-pessoas="alimentacao">${dinheiro(c.anual.alimentacao)}</td></tr>
            <tr><td>Abono de assiduidade</td><td class="text-end">${dinheiro(c.mensal.assiduidade)}</td><td class="text-end" data-pessoas="assiduidade">${dinheiro(c.anual.assiduidade)}</td></tr>
            <tr><td>Abono de resultado</td><td class="text-end">${dinheiro(c.mensal.resultado)}</td><td class="text-end" data-pessoas="resultado">${dinheiro(c.anual.resultado)}</td></tr>
            <tr class="fw-bold"><td>Despesa prevista com benefícios</td><td class="text-end">${dinheiro(c.mensal.total)}</td><td class="text-end" data-pessoas="total">${dinheiro(c.anual.total)}</td></tr>
          </tbody>
        </table>
        <div class="small text-muted">
          Sem nenhum abono de resultado: <strong data-pessoas="sem_resultado">${dinheiro(c.sem_resultado_anual)}</strong> no ano.
          Teto: <strong data-pessoas="maximo">${dinheiro(c.maximo_anual)}</strong>. Os valores e as previsões são lançados em cada negócio.
        </div>`);
  },

  /**
   * O resíduo a distribuir, com o ruído do arredondamento fora.
   *
   * Abaixo de R$ 20 não é resíduo: é a diferença entre duas extrações que
   * arredondam ao real em pontos diferentes (a do negócio, mensal; a das
   * filiais, acumulada). Mostrar "—" ali evita uma coluna cheia de "-1" que
   * ninguém sabe o que fazer com — e que faria duvidar do resto.
   */
  celulaDistribuir(l) {
    if (l.proposta === null || l.distribuido === null
        || l.distribuido === undefined || l.percentual) {
      return '<td class="text-end small text-muted"></td>';
    }
    const resto = l.proposta - l.distribuido;
    if (Math.abs(resto) <= 20) return '<td class="text-end small text-muted">—</td>';
    return `<td class="text-end small text-warning-emphasis">${this.moeda(resto)}</td>`;
  },

  /** A visão que está na tela: linhas e premissas do negócio ou da filial. */
  visao(d) {
    return d.filial
      ? { linhas: d.filial.linhas, premissas: d.filial.premissas, filialId: d.filial.id }
      : { linhas: d.linhas, premissas: d.premissas, filialId: 0 };
  },

  /**
   * Quem pode receber lançamento AQUI.
   *
   * Na filial, sempre (no modo negócio é distribuição; no modo filial é o
   * planejamento). No negócio, só no modo negócio — no modo filial a linha
   * dele é a soma, e dois totais discordando na mesma tela é o que esta seção
   * inteira existe para não ter.
   */
  editavelAqui(d) {
    // No dossiê a tela vira papel: campo de digitar sai como caixa vazia na
    // impressão, e o documento parece quebrado.
    if (App.modoDossie) return false;
    if (!d.pode_editar) return false;
    return d.filial ? true : d.modo === 'NEGOCIO';
  },

  /**
   * Quem pode digitar IPCA, PIB e o crescimento desejado: só o NEGÓCIO, em
   * QUALQUER modo (pedido do cliente, 2026-11-17).
   *
   * Não reusa `editavelAqui`, e a diferença é o ponto. Aquela regra existe para
   * os TOTAIS: no modo filial a linha do negócio é a soma das filiais, e dois
   * totais discordando na mesma tela é o que a seção evita. Premissa não é
   * total — não é soma de nada —, e a regra a bloqueava no negócio quando ele
   * planejava por filial: ali só se conseguia lançar IPCA e PIB DENTRO de cada
   * filial, que é exatamente o que o cliente quis acabar. Na filial os campos
   * ficam travados: ela herda do negócio.
   */
  premissaEditavel(d) {
    if (App.modoDossie) return false;
    return !!d.pode_editar && !d.filial;
  },

  /** As opções do seletor: o Corporativo (quando o perfil enxerga tudo) e cada negócio. */
  opcoesDeNegocio(d) {
    const corp = d.pode_corporativo
      ? `<option value="corp"${d.corporativo ? ' selected' : ''}>Corporativo — todos os negócios</option>`
      : '';
    return corp + d.negocios.map((n) => `<option value="${n.id}"${
      !d.corporativo && Number(n.id) === Number(d.negocio_id) ? ' selected' : ''}>${
      Modal.esc(n.rotulo)}</option>`).join('');
  },

  /**
   * O Corporativo: a DRE de todos os negócios somada, só para leitura.
   *
   * Não há campo de digitar, nem modo, nem filial, nem premissa: a proposta é
   * decidida nos negócios e aqui só se soma. O que precisa estar escrito na
   * tela é quantos negócios entram e quantos JÁ lançaram proposta — onde nada
   * foi lançado a proposta vale a projeção, e um consolidado que não diz isso
   * se lê como a meta da cooperativa.
   */
  htmlCorporativo(d) {
    const dossie = !!App.modoDossie;
    const faltando = d.meses_sem_meta.length
      ? `<div class="alert alert-warning py-2 px-3 small mb-3">
           <strong>Projeção incompleta.</strong> Há negócio sem orçamento lançado para
           ${d.meses_sem_meta.map((m) => this.nomeMes(m)).join(', ')} de ${d.ano} —
           esses meses entram como zero na coluna Projetado.
         </div>`
      : '';
    const sem = d.negocios_consolidados - d.negocios_com_proposta;
    return `
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="mb-0 me-auto">Projeção de Resultados — ${d.ano}</h1>
        ${dossie
    ? '<span class="fs-5">Corporativo</span>'
    : `<select id="projecao-negocio" class="form-select form-select-sm" style="max-width:18rem"
          aria-label="Negócio">${this.opcoesDeNegocio(d)}</select>`}
      </div>
      <p class="text-muted mb-1">
        <strong>Corporativo:</strong> a soma dos <strong>${d.negocios_consolidados} negócios</strong>
        com DRE carregada. Realizado de <strong>jan a ${this.nomeMes(d.mes_fechado_ate)}</strong>
        (meses fechados) e projetado de <strong>${this.nomeMes(d.mes_fechado_ate + 1)} a dez</strong>
        pelo orçamento lançado. Nada se lança aqui: a proposta de <strong>${d.ano_planejamento}</strong>
        é decidida em cada negócio.
      </p>
      <div id="projecao-corporativo-cobertura" class="alert ${sem ? 'alert-warning' : 'alert-light border'} py-2 px-3 small mb-3">
        <strong>${d.negocios_com_proposta} de ${d.negocios_consolidados} negócios</strong> já lançaram proposta
        para ${d.ano_planejamento}.${sem
    ? ` Nos outros ${sem} a coluna Proposta vale a própria projeção (crescimento zero) —
        não é meta, é a falta dela.` : ''}
      </div>
      ${this.cartaoVisaoAno(d)}
      ${this.cartaoDesafio(d)}
      ${this.cartaoOportunidades(d)}
      ${this.cartaoTributosDre(d)}
      ${this.cartaoPessoas(d)}
      ${faltando}
      <div id="projecao-dre-bloco">
      ${this.cabecalhoImpressao(d, 'Corporativo — todos os negócios')}
      ${this.botaoNivel()}
      <div class="small mb-1 nota-formato-2027"><span class="linha-muda px-1">Formato de ${d.ano_planejamento}</span>
        A DRE já está na forma da reforma tributária: a <strong>CBS</strong> (em ${d.ano}, o PIS/COFINS) é linha informativa
        acima da Receita Bruta, e Receita Bruta, Deduções e Imposto s/ Vendas estão <strong>sem ela</strong> — inclusive o
        ${d.ano}, convertido para comparar. A Receita Líquida e o que vem abaixo não mudam. O que se lança na Receita de
        Vendas e no Imposto s/ Vendas é no formato novo (sem a CBS); a CBS da proposta acompanha a receita.</div>
      <div class="table-responsive tabela-projecao${this.estaRecolhido() ? ' recolhida' : ''}${this.nivel3Aberto() ? ' nivel3' : ''}">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Conta</th>
            <th class="text-end">Realizado<br><span class="fw-normal text-muted small">jan–${this.nomeMes(d.mes_fechado_ate)}</span></th>
            <th class="text-end">Projetado<br><span class="fw-normal text-muted small">${this.nomeMes(d.mes_fechado_ate + 1)}–dez</span></th>
            <th class="text-end">Projeção<br><span class="fw-normal text-muted small">do ano</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL</span></th>
            <th class="text-end">Proposta<br><span class="fw-normal text-muted small">${d.ano_planejamento}</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL proposta</span></th>
            <th class="text-end">Reajuste<br><span class="fw-normal text-muted small">sobre a projeção</span></th>
          </tr></thead>
          <tbody>${this.corpo(d)}</tbody>
        </table>
      </div>
      <p class="text-muted small">
        Valores em R$. A.V. = análise vertical sobre a Receita Operacional Líquida.
        Cada conta é a soma da mesma conta nos negócios, com a proposta que cada um lançou.
        Fonte: ${Modal.esc(d.fonte || 'Qlik')}${d.extraido_em
  ? `, extraído em ${d.extraido_em.split('-').reverse().join('/')}` : ''}.
        Mês sem rateio é mês aberto e não entra como realizado.
      </p>
      </div>
      ${this.cartaoEstoque(d)}`;
  },

  /**
   * Realizado × planejado do ano corrente e a VISÃO REALISTA (pedido do cliente,
   * 2026-11-18): o mesmo quadro do "Painel Balanço out/26", para a receita bruta e
   * o resultado líquido. Só leitura — é estimativa da Controladoria, e não se
   * lança nada nela. No negócio, duas linhas; no Corporativo, um quadro por linha
   * com todos os negócios e o total.
   */
  colunasVisao(d) {
    const ate = this.nomeMes(d.mes_fechado_ate);
    const prox = this.nomeMes(d.mes_fechado_ate + 1);
    const a = String(d.ano).slice(2);
    return `<tr>
        <th></th>
        <th class="text-end">Realizado<br><span class="fw-normal text-muted small">jan–${ate}/${a}</span></th>
        <th class="text-end">Planejado<br><span class="fw-normal text-muted small">jan–${ate}/${a}</span></th>
        <th class="text-end">Planejado<br><span class="fw-normal text-muted small">${prox}–dez/${a}</span></th>
        <th class="text-end">Real + Plan.<br><span class="fw-normal text-muted small">${d.ano}</span></th>
        <th class="text-end">Planejado<br><span class="fw-normal text-muted small">${d.ano} (ano)</span></th>
        <th class="text-end">Diferença<br><span class="fw-normal text-muted small">R$</span></th>
        <th class="text-end">Diferença<br><span class="fw-normal text-muted small">%</span></th>
        <th class="text-end visao-realista">Visão realista<br><span class="fw-normal small">${prox}–dez/${a}</span></th>
        <th class="text-end visao-realista">Real + Realista<br><span class="fw-normal small">${d.ano}</span></th>
        <th class="text-end visao-realista">Realista × plano<br><span class="fw-normal small">${d.ano} · R$</span></th>
      </tr>`;
  },

  linhaVisao(rotulo, v, classe = '') {
    if (!v) return '';
    const m = (x) => this.moeda(x);
    const cor = (x) => (Math.abs(x) < 5e4 ? '' : (x > 0 ? 'text-success' : 'text-danger'));
    const sinal = (x) => `${Math.round(x) > 0 ? '+' : ''}${m(x)}`;
    const pc = v.dif_pct === null || v.dif_pct === undefined ? '—'
      : `${v.dif_pct > 0 ? '+' : ''}${this.pct(v.dif_pct)}`;
    return `<tr class="${classe}">
        <td>${rotulo}</td>
        <td class="text-end">${m(v.realizado)}</td>
        <td class="text-end">${m(v.plan_ate)}</td>
        <td class="text-end">${m(v.plan_resto)}</td>
        <td class="text-end fw-bold">${m(v.real_plan)}</td>
        <td class="text-end">${m(v.plan_ano)}</td>
        <td class="text-end ${cor(v.dif)}">${sinal(v.dif)}</td>
        <td class="text-end ${cor(v.dif)}">${pc}</td>
        <td class="text-end visao-realista" data-realista="resto">${m(v.realista_resto)}</td>
        <td class="text-end visao-realista fw-bold" data-realista="ano">${m(v.real_realista)}</td>
        <td class="text-end visao-realista ${cor(v.realista_x_plano)}" data-realista="x-plano">${sinal(v.realista_x_plano)}</td>
      </tr>`;
  },

  notaVisao(d) {
    return `<div class="small text-muted mt-1">
        <strong>Visão realista</strong> — estimativa da Controladoria, não é número do Qlik, e só de leitura:
        a <strong>receita bruta</strong> de ${this.nomeMes(d.mes_fechado_ate + 1)}–dez é o planejado desses meses ×
        a aderência da receita até ${this.nomeMes(d.mes_fechado_ate)} (realizado ÷ planejado); o
        <strong>resultado líquido</strong> é o planejado + o desvio médio mensal até ${this.nomeMes(d.mes_fechado_ate)}
        × os meses que faltam — supõe que o negócio continue errando o plano no mesmo ritmo. Sinal positivo é favorável.
      </div>`;
  },

  cartaoVisaoAno(d) {
    if (d.corporativo) return this.cartaoVisaoAnoCorporativo(d);
    if (!d.visao_ano || d.filial) return '';
    const v = d.visao_ano;
    const resumo = `real + realista: receita ${this.mi(v['1'].real_realista)} · resultado ${this.mi(v['18'].real_realista)}
      (${this.mi(v['18'].realista_x_plano, true)} contra o plano)`;
    return this.expansor('visao', 'painel-visao-ano', `${d.ano}: realizado, planejado e visão realista`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 tabela-visao-ano">
            <thead>${this.colunasVisao(d)}</thead>
            <tbody>
              ${this.linhaVisao('Receita operacional bruta', d.visao_ano['1'], '')}
              ${this.linhaVisao('Resultado líquido', d.visao_ano['18'], '')}
            </tbody>
          </table>
        </div>
        ${this.notaVisao(d)}`);
  },

  cartaoVisaoAnoCorporativo(d) {
    const negs = d.visao_ano_negocios || {};
    const nomes = Object.fromEntries((d.negocios || []).map((n) => [String(n.id), n.rotulo]));
    const tabela = (conta, titulo) => {
      const linhas = (d.negocios || [])
        .filter((n) => negs[n.id] && !(negs[n.id][conta].plan_ano === 0 && negs[n.id][conta].realizado === 0))
        .map((n) => this.linhaVisao(Modal.esc(nomes[String(n.id)]), negs[n.id][conta])).join('');
      return `<p class="small fw-bold text-uppercase text-muted mb-1 mt-2">${titulo}</p>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 tabela-visao-ano" data-conta-visao="${conta}">
            <thead>${this.colunasVisao(d)}</thead>
            <tbody>${linhas}${this.linhaVisao('Total dos negócios', (d.visao_ano_total || {})[conta], 'fw-bold table-light linha-total-visao')}</tbody>
          </table>
        </div>`;
    };
    const t = d.visao_ano_total || {};
    const resumo = t['1'] && t['18']
      ? `real + realista: receita ${this.mi(t['1'].real_realista)} · resultado ${this.mi(t['18'].real_realista)}
        (${this.mi(t['18'].realista_x_plano, true)} contra o plano)`
      : '';
    return this.expansor('visao', 'painel-visao-ano', `${d.ano}: realizado, planejado e visão realista, por negócio`, resumo, `
        ${tabela('1', 'Receita operacional bruta')}
        ${tabela('18', 'Resultado líquido')}
        ${this.notaVisao(d)}`);
  },

  /**
   * O DESAFIO da Controladoria para o ano da proposta (pedido do cliente,
   * 2026-11-18: "os 2% são margem líquida, carregue o desafio na projeção").
   * Só leitura: é referência decidida e congelada (`negocio_desafio`), e fica ao
   * lado do que a proposta lançada dá hoje, com a diferença. Na filial não
   * aparece — o desafio é do negócio.
   */
  /**
   * COMO É × COMO FICA (pedido do cliente, 2026-11-18, com o slide da
   * controladoria): a estrutura OFICIAL da DRE — Receita Bruta, Deduções, 2.1,
   * 2.2, 2.3 com ICMS e ISS e PIS/COFINS, Receita Líquida — hoje e no formato da
   * reforma, em que o PIS/COFINS (a CBS, em 2027) sai das deduções e vira uma
   * linha informativa ACIMA da Receita Bruta. Amarelo = linha que muda.
   *
   * É reclassificação em todas as colunas, a proposta inclusive: a CBS entra no
   * lugar do PIS/COFINS PELO MESMO VALOR (decisão do cliente, 2026-11-18: "levando
   * o PIS/COFINS de 7 milhões onde estão os 2 milhões"), e a Receita Líquida não
   * muda. Contas no servidor (`Reforma::comoEComoFica`); aqui só se pinta.
   */
  cartaoTributosDre(d) {
    const t = d.filial ? d.filial.tributos_dre : d.tributos_dre;
    if (!t || !t.colunas || !t.colunas.proposta) return '';
    const ano = d.ano_planejamento;
    const cols = [
      ['realizado', `Realizado<br><span class="fw-normal text-muted small">jan–${this.nomeMes(d.mes_fechado_ate)}/${String(d.ano).slice(2)}</span>`],
      ['projecao', `Projeção<br><span class="fw-normal text-muted small">${d.ano}</span>`],
      ['proposta', `Proposta<br><span class="fw-normal text-muted small">${ano}</span>`],
    ];
    const m = (v) => this.moeda(v);
    const cab = `<thead><tr><th>R$</th>${cols.map(([, r]) => `<th class="text-end">${r}</th>`).join('')}</tr></thead>`;
    // [chave, rótulo, recuo, forte, muda?] — `muda` pode ser função da coluna.
    const tabela = (lado, linhas, id) => `
      <table class="table table-sm align-middle mb-0 tabela-tributos-dre" id="${id}">
        ${cab}
        <tbody>${linhas.map(([k, rotulo, recuo, forte, muda]) => `
          <tr data-linha="${k}">
            <td class="${recuo ? `ps-${recuo}` : ''}${forte ? ' fw-bold' : ''}${muda === true ? ' linha-muda' : ''}">${rotulo}</td>
            ${cols.map(([c]) => {
    const mudaAqui = muda === true || (typeof muda === 'function' && muda(c));
    return `<td class="text-end${forte ? ' fw-bold' : ''}${mudaAqui ? ' linha-muda' : ''}" data-col="${c}">${m(t.colunas[c][lado][k])}</td>`;
  }).join('')}
          </tr>`).join('')}
        </tbody>
      </table>`;
    const comoE = tabela('como_e', [
      ['receita_bruta', 'Receita Bruta', 0, true],
      ['deducoes', '(-) Deduções', 0, true],
      ['devolucoes', '2.1 Devoluções', 3],
      ['avp', '2.2 Ajuste a Valor Presente', 3],
      ['impostos', '2.3 Imposto s/ Vendas', 3],
      ['icms_iss', 'ICMS e ISS', 5],
      ['pis_cofins', 'PIS/COFINS', 5, false, true],
      ['receita_liquida', 'Receita Líquida', 0, true],
    ], 'tributos-como-e');
    const comoFica = tabela('como_fica', [
      ['tributo', `CBS <span class="text-muted small">(PIS/COFINS em ${d.ano})</span> — informativa`, 0, false, true],
      ['receita_bruta', 'Receita Bruta', 0, true, true],
      ['deducoes', '(-) Deduções', 0, true, true],
      ['devolucoes', '2.1 Devoluções', 3],
      ['avp', '2.2 Ajuste a Valor Presente', 3],
      ['impostos', '2.3 Imposto s/ Vendas', 3, false, true],
      ['icms_iss', 'ICMS e ISS', 5],
      ['receita_liquida', 'Receita Líquida', 0, true],
    ], 'tributos-como-fica');

    const avisos = [];
    if (!t.tem_tributos) {
      avisos.push(`Sem a carga de tributos ${d.corporativo ? 'de nenhum negócio' : 'deste negócio'}: o PIS/COFINS não é conhecido e aparece como zero.`);
    }
    if (d.corporativo && (t.sem_tributos || []).length) {
      avisos.push(`${t.sem_tributos.length} negócio(s) sem carga de tributos entram sem PIS/COFINS.`);
    }

    const resumo = `${ano}: CBS ${m(t.colunas.proposta.como_fica.tributo)} acima da Receita Bruta, no lugar do PIS/COFINS
      · Receita Líquida ${m(t.colunas.proposta.como_fica.receita_liquida)} (não muda)`;
    return this.expansor('tributos', 'painel-tributos', 'Tributos na DRE: como é × como fica', resumo, `
      <div class="small mb-2">O PIS/COFINS <strong>muda de linha</strong>: hoje está dentro das deduções (2.3 Imposto s/ Vendas);
        com a reforma a <strong>CBS</strong> é tributo <em>por fora</em> e vira uma linha <strong>informativa acima da Receita
        Bruta</strong>, que passa a ser o faturamento sem ela. Exemplo: hoje Receita Bruta 100, deduções 50 (ICMS 40 +
        PIS/COFINS 10), devoluções 10 e Receita Líquida 40; no novo formato, CBS 10, Receita Bruta 90, deduções 40 (ICMS),
        devoluções 10 e a mesma Receita Líquida 40.
        É <strong>reclassificação</strong> em ${d.ano} e na proposta de <strong>${ano}</strong>: a CBS entra pelo mesmo valor do
        PIS/COFINS, então Receita Bruta e Deduções caem o mesmo tanto e a Receita Líquida — e tudo abaixo dela — não muda.
        <span class="linha-muda px-1">Amarelo</span> = linha que muda.</div>
      <div class="row g-3">
        <div class="col-xl-6"><div class="fw-bold small text-uppercase mb-1">Como é hoje</div>
          <div class="table-responsive">${comoE}</div></div>
        <div class="col-xl-6"><div class="fw-bold small text-uppercase mb-1">Como fica <span class="fw-normal text-muted">(reforma, a partir de ${ano})</span></div>
          <div class="table-responsive">${comoFica}</div></div>
      </div>
      <div class="small text-muted mt-2">CBS pela LC 214/2025, com o regime específico das cooperativas (art. 271), pelo qual a
        Copérdia optou. PIS/COFINS do realizado: carga de tributos de ${d.ano}; o resto do ano pela taxa de ${d.ano} sobre a Receita
        de Vendas; na proposta, o que a linha 2.3 carrega (a projeção na proporção da receita).${d.filial ? ' Na filial, sempre pela taxa do negócio.' : ''}</div>
      ${avisos.map((a) => `<div class="small text-warning-emphasis mt-1 aviso-tributos">${a}</div>`).join('')}
    `);
  },

  cartaoDesafio(d) {
    if (d.corporativo) return this.cartaoDesafioCorporativo(d);
    const x = d.desafio;
    if (!x || d.filial) return '';
    const des = x.desafio;
    const pro = x.proposta;
    const e = x.escada || { desafio: {}, proposta: {}, estimados: [] };
    const est = new Set(e.estimados || []);
    const pp = (v) => (v === null || v === undefined ? null : v / 100);
    // A escada da DRE (pedido do cliente, 2026-11-18). `pct` marca as linhas de
    // percentual: a diferença sai em pontos percentuais, não em reais.
    const degraus = [
      ['receita_bruta', 'Faturamento <span class="text-muted small">(receita operacional bruta)</span>'],
      ['receita_liquida', 'Receita líquida'],
      ['lucro_bruto', 'Margem bruta <span class="text-muted small">(lucro operacional bruto)</span>'],
      ['margem_bruta', '<span class="ps-3">% margem bruta <span class="text-muted small">(sobre a receita líquida)</span></span>', true],
      ['despesas', 'Despesas operacionais'],
      ['resultado_antes_rateios', 'Resultado antes dos rateios <span class="text-muted small">(11 - após impostos)</span>'],
      ['margem_antes_rateios', '<span class="ps-3">% margem antes dos rateios</span>', true],
      ['rateios_negocio', 'Rateios do negócio <span class="text-muted small">(12)</span>'],
      ['resultado_negocio', 'Resultado do negócio <span class="text-muted small">(14)</span>'],
      ['margem_negocio', '<span class="ps-3">% margem do negócio</span>', true],
      ['rateios_adm', 'Rateios administrativos <span class="text-muted small">(15)</span>'],
      ['resultado', 'Resultado líquido do negócio', false, true],
      ['margem', '% margem líquida <span class="text-muted small">(sobre a receita líquida)</span>', true, true],
    ];
    const celula = (v, pct) => (v === null || v === undefined ? '—' : (pct ? this.pct(pp(v), 2) : this.moeda(v)));
    const linha = ([k, rotulo, pct, forte]) => {
      const a = e.desafio[k];
      const b = e.proposta[k];
      const dif = a === null || a === undefined || b === null || b === undefined ? null : b - a;
      const estimado = est.has(k);
      const textoDif = dif === null ? '—' : (pct
        ? `${dif > 0 ? '+' : ''}${dif.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} p.p.`
        : this.comSinal(dif));
      const cor = dif === null ? '' : (pct ? (dif >= 0 ? 'text-success' : 'text-danger') : this.corDif(dif));
      return `<tr data-desafio="${k}" class="${forte ? 'fw-bold' : ''}">
          <td>${rotulo}</td>
          <td class="text-end ${estimado ? 'text-muted fst-italic' : 'fw-bold'}" ${estimado ? 'title="Estimado a partir da projeção de 2026"' : ''}>${estimado && a !== null && a !== undefined ? '≈ ' : ''}${celula(a, pct)}</td>
          <td class="text-end">${celula(b, pct)}</td>
          <td class="text-end ${estimado ? 'text-muted' : cor}">${estimado ? '' : textoDif}</td>
        </tr>`;
    };
    const resumo = `resultado ${this.mi(des.resultado)}${des.margem === null ? '' : ` · margem ${this.pct(des.margem / 100, 2)}`}
      — a proposta dá ${this.mi(pro.resultado)}${pro.margem === null ? '' : ` (${this.pct(pro.margem / 100, 2)})`}`;
    return this.expansor('desafio', 'painel-desafio', `Desafio ${x.ano} para o negócio`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-desafio">
            <thead><tr><th></th>
              <th class="text-end">Desafio<br><span class="fw-normal text-muted small">${x.ano}</span></th>
              <th class="text-end">Proposta<br><span class="fw-normal text-muted small">lançada hoje</span></th>
              <th class="text-end">Diferença<br><span class="fw-normal text-muted small">proposta − desafio</span></th>
            </tr></thead>
            <tbody>
              ${degraus.map(linha).join('')}
              <tr data-desafio="pis_cofins" class="small"><td>PIS + COFINS <span class="text-muted">(taxa de ${x.ano - 1}; em ${x.ano} vira a CBS, mesmo valor)</span></td>
                <td class="text-end text-muted">${des.pis_cofins ? this.moeda(des.pis_cofins.total) : '—'}</td>
                <td class="text-end">${pro.pis_cofins ? this.moeda(pro.pis_cofins.total) : '—'}</td>
                <td class="text-end"></td></tr>
            </tbody>
          </table>
        </div>
        <div class="small text-muted mb-1">O desafio fixa <strong>faturamento, receita líquida e resultado líquido</strong>. Os valores com
          <strong>≈</strong> são estimados a partir da projeção de ${x.ano - 1}: a margem bruta mantém o percentual de ${x.ano - 1}; despesas e
          rateios (do negócio e administrativos) ficam na projeção, sem crescimento; o resultado do negócio é o líquido do desafio
          menos os rateios administrativos, e o resultado antes dos rateios é ele menos os rateios do negócio. Estrutura nova da DRE
          gerencial (a que vale em ${x.ano}).</div>
        ${this.notaTributos(x.tributos, x.ano)}
        <div class="small text-muted" id="desafio-nota">
          <strong>${Modal.esc(des.nota)}</strong> ${this.notaDesafio(x)}
        </div>`);
  },

  /** O que o negócio pagou de PIS/COFINS em 2026 — o valor que a CBS herda em 2027. */
  notaTributos(t, ano) {
    if (!t) return '';
    const p = (v, c = 2) => this.pct(v === null || v === undefined ? null : v, c);
    return `<div class="small mb-1" id="desafio-tributos">
        <strong>Tributos sobre vendas em ${ano - 1}</strong> (jan–${this.nomeMes(t.mes_ate)}): PIS ${this.mi(t.pis)} e COFINS ${this.mi(t.cofins)}
        — <strong>${p(t.taxa_pis_cofins)}</strong> do faturamento; incidem só sobre a venda a não sócio
        (${p(t.parte_nao_socio, 1)} das vendas), onde são ${p(t.taxa_pis_cofins_nao_socio)}. ICMS: ${this.mi(t.icms)}.
        Em ${ano} o PIS e a COFINS dão lugar à CBS, que no planejamento entra <strong>pelo mesmo valor</strong>: muda a linha
        (sai das deduções e vai para cima da Receita Bruta), não o número — ver "Tributos na DRE: como é × como fica".
      </div>`;
  },

  notaDesafio(x) {
    return `Diretriz do Horizonte 1: <strong>preservar margem</strong> — pode crescer, sem perder margem.
      ${Modal.esc(x.metodo)} A meta da direção é <strong>${this.pct(x.meta_margem / 100, 0)} de margem líquida</strong>
      para a cooperativa. Referência decidida em ${String(x.decidido_em).split('-').reverse().join('/')}, só de leitura:
      recarregar a DRE não a move.`;
  },

  cartaoDesafioCorporativo(d) {
    const x = d.desafio;
    if (!x) return '';
    const nomes = Object.fromEntries((d.negocios || []).map((n) => [String(n.id), n.rotulo]));
    const m2 = (v) => this.pct(v === null || v === undefined ? null : v / 100, 2);
    const linha = (rotulo, des, pro, classe = '', attr = '') => {
      const dif = des && pro.resultado !== null ? pro.resultado - des.resultado : null;
      return `<tr class="${classe}" ${attr}>
          <td>${rotulo}</td>
          <td class="text-end">${des ? this.moeda(des.receita_bruta) : '—'}</td>
          <td class="text-end" data-col="rol-desafio">${des ? this.moeda(des.receita_liquida) : '—'}</td>
          <td class="text-end text-muted" data-col="pis-cofins">${des && des.pis_cofins ? this.moeda(des.pis_cofins.total) : '—'}</td>
          <td class="text-end fw-bold">${des ? this.moeda(des.resultado) : '—'}</td>
          <td class="text-end">${des ? m2(des.margem) : '—'}</td>
          <td class="text-end" data-col="rol-proposta">${pro.receita_liquida === null || pro.receita_liquida === undefined ? '—' : this.moeda(pro.receita_liquida)}</td>
          <td class="text-end" data-col="margem-antes-rateios">${m2(pro.margem_antes_rateios)}</td>
          <td class="text-end" data-col="margem-negocio">${m2(pro.margem_negocio)}</td>
          <td class="text-end${pro.resultado !== null && pro.resultado < -1 ? ' text-danger fw-bold meta-negativa' : ''}"
            ${pro.resultado !== null && pro.resultado < -1 ? 'title="Resultado líquido negativo"' : ''}>${pro.resultado === null ? '—' : this.moeda(pro.resultado)}</td>
          <td class="text-end">${m2(pro.margem)}</td>
          <td class="text-end ${this.corDif(dif)}">${dif === null ? '—' : this.comSinal(dif)}</td>
        </tr>`;
    };
    const ordem = Object.keys(x.negocios).sort((a, b) => ((x.negocios[b].desafio || {}).resultado || 0) - ((x.negocios[a].desafio || {}).resultado || 0));
    const linhas = ordem.map((id) => linha(Modal.esc(nomes[id] || id), x.negocios[id].desafio, x.negocios[id].proposta, '', `data-negocio-desafio="${id}"`)).join('');
    const t = x.total;
    const meta = (lado, rotulo) => {
      const falta = lado.falta;
      return `<li data-meta="${rotulo === 'desafio' ? 'desafio' : 'proposta'}">
          Sobre a ROL ${rotulo === 'desafio' ? 'do desafio' : 'da proposta lançada'} (${this.mi(lado.receita_liquida)}),
          ${this.pct(x.meta_margem / 100, 0)} seriam <strong>${this.mi(lado.resultado_meta)}</strong>;
          ${rotulo === 'desafio' ? 'o desafio' : 'a proposta'} chega a <strong>${this.mi(lado.resultado)}</strong> (${m2(lado.margem)}):
          ${falta > 0 ? `<strong class="text-danger">faltam ${this.mi(falta)}</strong>` : `<strong class="text-success">sobram ${this.mi(-falta)}</strong>`}.
        </li>`;
    };
    const resumo = `resultado ${this.mi(t.desafio.resultado)} · margem ${m2(t.desafio.margem)}
      — meta de ${this.pct(x.meta_margem / 100, 0)}: ${t.desafio.falta > 0 ? `faltam ${this.mi(t.desafio.falta)}` : 'atingida'}`;
    return this.expansor('desafio', 'painel-desafio', `Desafio ${x.ano} para os negócios`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-desafio">
            <thead><tr><th></th>
              <th class="text-end">Receita bruta<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Receita líquida<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">PIS + COFINS<br><span class="fw-normal text-muted small">desafio · estimado</span></th>
              <th class="text-end">Resultado<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Margem<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Receita líquida<br><span class="fw-normal text-muted small">proposta</span></th>
              <th class="text-end">Margem antes<br><span class="fw-normal text-muted small">dos rateios · proposta</span></th>
              <th class="text-end">Margem do<br><span class="fw-normal text-muted small">negócio · proposta</span></th>
              <th class="text-end">Resultado<br><span class="fw-normal text-muted small">proposta</span></th>
              <th class="text-end">Margem<br><span class="fw-normal text-muted small">proposta</span></th>
              <th class="text-end">Diferença<br><span class="fw-normal text-muted small">proposta − desafio</span></th>
            </tr></thead>
            <tbody>${linhas}${linha('Total dos negócios', t.desafio, t.proposta, 'fw-bold table-light', 'data-negocio-desafio="total"')}</tbody>
          </table>
        </div>
        ${this.tabelaTributos(x, nomes)}
        <p class="small fw-bold mb-1 mt-2">Meta da direção: ${this.pct(x.meta_margem / 100, 0)} de margem líquida</p>
        <ul class="small mb-1 meta-margem">${meta(t.desafio, 'desafio')}${meta(t.proposta, 'proposta')}</ul>
        <div class="small text-muted">${this.notaDesafio(x)} Cada negócio vê o seu desafio na própria tela.</div>`);
  },

  /**
   * Vendas e PIS/COFINS de 2026 por negócio — a base para refazer de forma macro
   * a conta com a regra da reforma tributária (pedido do cliente, 2026-11-18).
   */
  tabelaTributos(x, nomes) {
    const t = x.total.tributos;
    if (!t || !t.mes_ate) return '';
    const p = (v, c = 2) => this.pct(v === null || v === undefined ? null : v, c);
    const linha = (rot, r, classe = '', attr = '', pcDesafio = null) => `<tr class="${classe}" ${attr}>
        <td>${rot}</td>
        <td class="text-end">${this.moeda(r.venda_socio)}</td>
        <td class="text-end">${this.moeda(r.venda_nao_socio)}</td>
        <td class="text-end">${p(r.parte_nao_socio, 1)}</td>
        <td class="text-end">${this.moeda(r.pis)}</td>
        <td class="text-end">${this.moeda(r.cofins)}</td>
        <td class="text-end fw-bold">${p(r.taxa_pis_cofins)}</td>
        <td class="text-end">${p(r.taxa_pis_cofins_nao_socio)}</td>
        <td class="text-end">${this.moeda(r.icms)}</td>
        <td class="text-end" data-col="cbs-desafio">${pcDesafio === null || pcDesafio === undefined ? '—' : this.moeda(pcDesafio)}</td>
      </tr>`;
    const ordem = Object.keys(x.negocios).filter((id) => x.negocios[id].tributos)
      .sort((a, b) => (x.negocios[b].tributos.pis + x.negocios[b].tributos.cofins) - (x.negocios[a].tributos.pis + x.negocios[a].tributos.cofins));
    return `<div class="small fw-bold mb-1 mt-3">Tributos sobre vendas em ${x.ano - 1} (jan–${this.nomeMes(t.mes_ate)}) — base para a regra da reforma</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-1 tabela-tributos">
          <thead><tr><th></th>
            <th class="text-end">Vendas<br><span class="fw-normal text-muted small">a sócios</span></th>
            <th class="text-end">Vendas<br><span class="fw-normal text-muted small">a não sócios</span></th>
            <th class="text-end">Não sócio<br><span class="fw-normal text-muted small">% das vendas</span></th>
            <th class="text-end">PIS</th>
            <th class="text-end">COFINS</th>
            <th class="text-end">PIS + COFINS<br><span class="fw-normal text-muted small">% do faturamento</span></th>
            <th class="text-end">PIS + COFINS<br><span class="fw-normal text-muted small">% da venda a não sócio</span></th>
            <th class="text-end">ICMS</th>
            <th class="text-end">CBS ${x.ano}<br><span class="fw-normal text-muted small">= PIS + COFINS sobre o desafio</span></th>
          </tr></thead>
          <tbody>${ordem.map((id) => linha(Modal.esc(nomes[id] || id), x.negocios[id].tributos, '', `data-negocio-tributo="${id}"`,
    ((x.negocios[id].desafio || {}).pis_cofins || {}).total)).join('')}
            ${linha('Total dos negócios', t, 'fw-bold table-light', 'data-negocio-tributo="total"', ((x.total.desafio || {}).pis_cofins || {}).total)}</tbody>
        </table>
      </div>
      <div class="small text-muted">PIS e COFINS incidem só sobre a venda a não sócio (o ato cooperativo não é tributado) e dão lugar
        à <strong>CBS</strong> em ${x.ano} (LC 214/2025). A Copérdia <strong>optou pelo regime específico das cooperativas</strong>
        (art. 271): a venda a sócio segue fora da base. No planejamento a CBS entra <strong>pelo mesmo valor</strong> do PIS/COFINS
        — muda a linha da DRE, não o número. Fonte das vendas e tributos: Qlik DRE Gerencial, por conta contábil.</div>`;
  },

  /**
   * OPORTUNIDADES E RISCOS de 2027 — o QUADRO ÚNICO (pedido do cliente,
   * 2026-11-18: "traga todas as oportunidades nesse mesmo formato, detalhando os
   * meses, e totalize"): os itens não recorrentes por conta contábil e os eventos
   * pontuais das linhas, cada um com os meses em que aconteceu, a oportunidade e o
   * risco se não se repetir, e no pé o total de oportunidades, o de riscos e o
   * saldo. Os itens já saíram da série das linhas (`Oportunidades::quadro`), então
   * os totais não contam nada duas vezes. Na filial, só os eventos dela.
   * Só leitura: quem decide se um item se repete é o negócio, lançando na linha.
   */
  cartaoOportunidades(d) {
    if (d.corporativo) return this.cartaoOportunidadesCorporativo(d);
    const naFilial = !!d.filial;
    const q = naFilial ? d.filial.quadro : (d.oportunidades || {}).quadro;
    if (!q) return '';
    const titulo = `Oportunidades e riscos para ${d.ano_planejamento}${naFilial ? ' — esta filial' : ''}`;
    if (!q.linhas.length) {
      return naFilial ? this.expansor('oportunidades', 'painel-oportunidades', titulo, 'nenhum item ou mês fora do padrão',
        `<div class="small text-muted" id="filial-sem-picos">Nenhum mês fora do padrão nesta filial em ${d.ano}
          (despesas operacionais, outros valores e resultado financeiro, acima de R$ 50 mil). Os itens não recorrentes por conta
          contábil estão no negócio.</div>${this.secaoJuros(d.filial.juros, true)}`) : '';
    }
    const m = (v) => this.moeda(v);
    const linhas = q.linhas.map((l) => {
      const item = l.tipo === 'ITEM';
      return `<tr ${item ? `data-item="${Modal.esc(l.codigo)}"` : `data-pico="${Modal.esc(l.codigo)}"`}>
        <td>${item && l.reduzida
    ? `${this.contaQlik(`${l.reduzida} ${l.reduzida_nome}`)}<div class="text-muted small">${Modal.esc(l.nome)} · conta contábil ${Modal.esc(l.codigo)}</div>`
    : Modal.esc(l.nome)}</td>
        <td class="small">${Modal.esc(l.grupo || '')}${item ? '' : ' <span class="text-muted">· mês fora do padrão</span>'}</td>
        <td class="text-end small">${this.linkLinhaDre(d, l.linha_dre, item ? l.reduzida : null)}</td>
        <td class="text-end">${l.mediana === null || l.mediana === undefined ? '<span class="text-muted">—</span>' : m(l.mediana)}</td>
        <td class="small">${l.meses.length ? l.meses.map((x) => `${this.nomeMes(x.mes)}: ${m(x.valor)}`).join(' · ') : '<span class="text-muted">sem o mês</span>'}</td>
        <td class="text-end">${l.oportunidade ? `<span class="text-success">+${m(l.oportunidade)}</span>` : ''}</td>
        <td class="text-end">${l.risco ? `<span class="text-danger">−${m(Math.abs(l.risco))}</span>` : ''}</td></tr>
        ${this.linhaDeLancamentos(l, item, 7)}`;
    }).join('');
    const resumo = `oportunidades ${this.mi(q.oportunidade, true)} · riscos ${this.mi(q.risco)} · saldo ${this.mi(q.saldo, true)}`;
    return this.expansor('oportunidades', 'painel-oportunidades', titulo, resumo, `
      <div class="small fw-bold mb-1">${naFilial ? 'Eventos pontuais desta filial' : 'Itens não recorrentes e eventos pontuais'}
        de ${d.ano} (jan–${this.nomeMes(q.mes_ate)})</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-1 tabela-oportunidades-quadro">
          <thead><tr><th>Item / linha</th><th>Grupo</th><th class="text-end">Linha da DRE</th>
            <th class="text-end">Padrão mensal<br><span class="fw-normal text-muted small">mediana</span></th>
            <th>Meses em que aconteceu</th>
            <th class="text-end">Oportunidade<br><span class="fw-normal text-muted small">se não se repetir</span></th>
            <th class="text-end">Risco<br><span class="fw-normal text-muted small">se não se repetir</span></th></tr></thead>
          <tbody>${linhas}
            <tr class="fw-bold table-light"><td colspan="5">Total de oportunidades</td>
              <td class="text-end text-success" data-oport="oportunidade">+${m(q.oportunidade)}</td><td></td></tr>
            <tr class="fw-bold table-light"><td colspan="5">Total de riscos</td><td></td>
              <td class="text-end text-danger" data-oport="risco">−${m(Math.abs(q.risco))}</td></tr>
            <tr class="fw-bold"><td colspan="5">Saldo (oportunidades − riscos)</td>
              <td class="text-end ${q.saldo >= 0 ? 'text-success' : 'text-danger'}" colspan="2" data-oport="saldo">${q.saldo >= 0 ? '+' : '−'}${m(Math.abs(q.saldo))}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="small text-muted">${naFilial ? '' : `<strong>Itens</strong> (conta contábil): provisões, perdas, indenizações, multas e
        receitas pontuais, com o mês em que foram lançados. `}<strong>Meses fora do padrão</strong>: em despesas operacionais, outros
        valores e resultado financeiro, o mês que se afasta da mediana do ano em mais da metade dela (e de R$ 50 mil) — uma receita de
        juros concentrada num mês, uma despesa que não se repete${naFilial ? '' : '; calculados sem os itens acima, para nada contar duas vezes'}. Se não se repetir em ${d.ano_planejamento}, despesa é <strong>oportunidade</strong> e receita ou reversão é
        <strong>risco</strong>. Meses no sinal da DRE (despesa negativa).</div>
      <div class="small text-muted mt-1">Nada daqui entra sozinho na proposta: se um item não vai se repetir (ou se falta algo
        que ${d.ano} não teve), lance na linha da DRE correspondente. Em cada linha, <strong>Lançamentos</strong> abre os de
        R$ 10 mil ou mais que formam o valor: data, filial, conta, histórico e o complemento (documento e cliente).</div>
      ${this.secaoJuros(naFilial ? d.filial.juros : (d.oportunidades || {}).juros, naFilial)}`);
  },

  /**
   * A linha recolhida, logo abaixo de cada item ou evento do quadro, com os
   * lançamentos que formam o valor (pedido do cliente, 2026-11-18: "um expansor
   * onde eu identifique o lançamento e a filial"). Fechada por padrão.
   */
  /**
   * Uma conta como o Qlik a lista na DRE gerencial (pedido do cliente,
   * 2026-11-21): a reduzida em destaque e o nome — "3153 JUROS ATIVOS SOCIO".
   */
  contaQlik(texto) {
    const t = String(texto || '');
    const m = t.match(/^(\d{3,5})\s+(.*)$/);
    return m ? `<span class="reduzida-cod">${Modal.esc(m[1])}</span> ${Modal.esc(m[2])}` : Modal.esc(t);
  },

  /**
   * A linha da DRE de um item ou evento, com os NOMES do Qlik — o bloco e a linha
   * ("9 - RESULTADO FINANCEIRO › Receitas Financeiras"), e não o código interno
   * do sistema (11.1), que o gestor não acha na DRE gerencial. É um link: leva à
   * linha na DRE abaixo e, com a conta reduzida, abre o nível 3 dela.
   */
  linkLinhaDre(d, codigo, reduzida) {
    if (!codigo) return '';
    const linhas = this.visao(d).linhas || [];
    const i = linhas.findIndex((x) => x.codigo === codigo);
    if (i < 0) return Modal.esc(codigo);
    const linha = linhas[i];
    // O bloco é a linha de nível 1 logo acima, sem as de percentual: o código
    // interno não serve (as 8.x moram em "6 - DESPESAS OPERACIONAIS", código 7;
    // o 8 é o Desp./ROL).
    const bloco = linha.nivel === 1 ? null
      : linhas.slice(0, i).reverse().find((x) => x.nivel === 1 && !x.percentual && !x.informativa);
    return `<a href="#" class="link-linha-dre" data-ir-conta="${Modal.esc(codigo)}"${reduzida ? ` data-ir-reduzida="${Modal.esc(reduzida)}"` : ''}
        title="Ver na DRE da proposta, abaixo">${bloco && bloco !== linha ? `<span class="text-muted">${Modal.esc(bloco.nome)} ›</span> ` : ''}${Modal.esc(linha.nome)}</a>`;
  },

  /** Leva à linha da DRE (e à conta reduzida, abrindo o nível 3 dela) e a destaca. */
  irParaLinhaDre(codigo, reduzida) {
    const tab = document.querySelector('#secao-projecao .tabela-projecao');
    if (!tab) return;
    if (tab.classList.contains('recolhida')) this.alternarNivel();
    let alvo = tab.querySelector(`tr[data-conta="${CSS.escape(codigo)}"]`);
    if (reduzida) {
      const r = tab.querySelector(`tr.linha-reduzida[data-pai="${CSS.escape(codigo)}"][data-reduzida="${CSS.escape(reduzida)}"]`);
      if (r) {
        if (!r.classList.contains('aberta') && !tab.classList.contains('nivel3')) {
          this.alternarReduzidas(codigo, tab.querySelector(`[data-abre-reduzidas="${CSS.escape(codigo)}"]`));
        }
        alvo = r;
      }
    }
    if (!alvo) return;
    alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
    alvo.classList.remove('linha-destacada');
    void alvo.offsetWidth;
    alvo.classList.add('linha-destacada');
  },

  linhaDeLancamentos(l, item, colunas) {
    const x = l.lancamentos;
    if (!x) return '';
    const m = (v) => this.moeda(v);
    const chave = Modal.esc(`${l.tipo}:${l.codigo}`);
    if (!x.quantos) {
      return `<tr class="linha-lancamentos" data-lancamentos="${chave}"><td colspan="${colunas}" class="small text-muted py-1">
        Nenhum lançamento de R$ ${m(x.minimo)} ou mais ${item ? 'nesta conta' : 'nesta linha nesses meses'}: o valor é a soma de
        lançamentos menores.</td></tr>`;
    }
    const data = (s) => s.split('-').reverse().join('/');
    const linhas = x.itens.map((i) => `<tr>
        <td class="text-nowrap">${data(i.data)}</td>
        <td>${Modal.esc(i.cod_filial)} — ${Modal.esc(i.filial)}</td>
        <td title="${Modal.esc(`Conta contábil ${i.conta}`)}">${this.contaQlik(i.reduzida ? `${i.reduzida} ${i.conta_nome}` : i.conta_nome)}</td>
        <td>${Modal.esc(i.historico)}</td>
        <td>${Modal.esc(i.complemento)}</td>
        <td class="text-end text-nowrap">${m(i.valor)}</td>
        <td class="text-end text-nowrap">${i.valor < 0 ? `<span class="text-success">+${m(-i.valor)}</span>` : ''}</td>
        <td class="text-end text-nowrap">${i.valor > 0 ? `<span class="text-danger">−${m(i.valor)}</span>` : ''}</td></tr>`).join('');
    // Os totais da abertura (pedido do cliente, 2026-11-18): de TODOS os lançamentos
    // do piso para cima, não só dos mostrados — mesma régua do quadro.
    const totais = `
          <tr class="fw-bold table-light"><td colspan="6">Total de oportunidades${x.quantos > x.itens.length ? ` (os ${x.quantos} lançamentos)` : ''}</td>
            <td class="text-end text-success text-nowrap" data-abertura="oportunidade">+${m(x.oportunidade)}</td><td></td></tr>
          <tr class="fw-bold table-light"><td colspan="6">Total de riscos</td><td></td>
            <td class="text-end text-danger text-nowrap" data-abertura="risco">−${m(Math.abs(x.risco))}</td></tr>
          <tr class="fw-bold"><td colspan="6">Saldo (oportunidades − riscos)</td>
            <td class="text-end text-nowrap ${x.saldo >= 0 ? 'text-success' : 'text-danger'}" colspan="2" data-abertura="saldo">${x.saldo >= 0 ? '+' : '−'}${m(Math.abs(x.saldo))}</td></tr>`;
    const mais = x.quantos > x.itens.length
      ? `<div class="text-muted">Mostrando os ${x.itens.length} maiores de ${x.quantos}.</div>` : '';
    // Quanto do valor da linha nesses meses os lançamentos grandes explicam: o resto
    // é a soma de lançamentos menores (fretes, comissões, folha por filial).
    const base = (l.meses || []).reduce((a, b) => a + b.valor, 0);
    const parte = Math.abs(base) >= 1 ? Math.round((x.soma / base) * 100) : null;
    const cobre = parte !== null && parte >= 0 && parte <= 200 ? ` — ${parte}% do valor${item ? '' : ' desses meses'}` : '';
    return `<tr class="linha-lancamentos" data-lancamentos="${chave}"><td colspan="${colunas}" class="py-0">
      <details class="small lancamentos-do-quadro">
        <summary>Lançamentos — ${x.quantos} de R$ ${m(x.minimo)} ou mais, somando ${m(x.soma)}${item ? '' : ' nos meses fora do padrão'}${cobre}</summary>
        <div class="table-responsive"><table class="table table-sm table-borderless mb-1 tabela-lancamentos">
          <thead><tr><th>Data</th><th>Filial</th><th>Conta</th><th>Histórico</th><th>Complemento (documento e cliente)</th>
            <th class="text-end">Valor</th><th class="text-end">Oportunidade</th><th class="text-end">Risco</th></tr></thead>
          <tbody>${linhas}${totais}</tbody></table></div>${mais}
        <div class="text-muted">Se o lançamento não se repetir: despesa é oportunidade, receita ou reversão é risco.
          ${item ? '' : 'Os totais são dos lançamentos inteiros dos meses fora do padrão; o quadro conta só o que passou do padrão.'}</div>
      </details></td></tr>`;
  },

  /**
   * JUROS RECEBIDOS, sócio e não sócio (pedido do cliente, 2026-11-18): o total
   * do ano e os lançamentos acima de R$ 30 mil — risco, se não se repetirem —
   * com a filial e o cliente; e os clientes que somam mais de R$ 30 mil.
   */
  secaoJuros(j, naFilial) {
    if (!j) return '';
    const m = (v) => this.moeda(v);
    const data = (s) => s.split('-').reverse().join('/');
    const lim = m(j.limite);
    const acima = j.acima.map((a) => `<tr data-juros-lancamento="${Modal.esc(a.cliente_cod || a.cliente)}">
        <td class="text-nowrap">${data(a.data)}</td><td>${Modal.esc(a.cod_filial)} — ${Modal.esc(a.filial)}</td>
        <td>${a.cliente_cod ? `<span class="text-muted">${Modal.esc(a.cliente_cod)}</span> ` : ''}${Modal.esc(a.cliente)}</td>
        <td>${this.contaQlik(a.conta || a.tipo)}</td><td class="text-end text-danger text-nowrap">${m(a.valor)}</td></tr>`).join('');
    const clientes = j.clientes.map((c) => `<tr data-juros-cliente="${Modal.esc(c.cliente_cod || c.cliente)}">
        <td>${c.cliente_cod ? `<span class="text-muted">${Modal.esc(c.cliente_cod)}</span> ` : ''}${Modal.esc(c.cliente)}</td>
        <td>${this.contaQlik(c.conta || c.tipo)}</td>
        <td class="small">${c.filiais.map((f) => `${Modal.esc(f.cod_filial)} — ${Modal.esc(f.filial)}`).join('; ')}</td>
        <td class="text-end">${c.lancamentos}</td><td class="text-end text-nowrap">${m(c.valor)}</td></tr>`).join('');
    return `<div class="mt-3" id="juros-recebidos">
      <div class="small fw-bold mb-1">Juros recebidos de ${naFilial ? 'clientes desta filial' : 'clientes'} (jan–${this.nomeMes(j.mes_ate)})</div>
      <div class="small mb-1">Sócio <strong>${m(j.socio)}</strong> · não sócio <strong>${m(j.nao_socio)}</strong> · total
        <strong>${m(j.total)}</strong> em ${j.lancamentos.toLocaleString('pt-BR')} lançamentos.
        ${j.acima.length ? `<span class="text-danger">Risco: <strong data-juros="risco">${m(j.risco)}</strong> em ${j.acima.length}
        lançamento(s) acima de R$ ${lim}</span> — receita que depende de um cliente pagar com atraso; se não se repetir,
        o resultado do ano seguinte cai.` : `Nenhum lançamento acima de R$ ${lim}.`}</div>
      ${j.acima.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-2 tabela-juros">
        <thead><tr><th>Data</th><th>Filial</th><th>Cliente</th><th>Conta</th><th class="text-end">Juros</th></tr></thead>
        <tbody>${acima}</tbody></table></div>` : ''}
      ${j.clientes.length ? `<details class="small"><summary>Clientes que somam mais de R$ ${lim} de juros no ano (${j.clientes.length})</summary>
        <div class="table-responsive"><table class="table table-sm align-middle mb-1 tabela-juros-clientes">
          <thead><tr><th>Cliente</th><th>Conta</th><th>Filiais</th><th class="text-end">Lançamentos</th><th class="text-end">Juros</th></tr></thead>
          <tbody>${clientes}</tbody></table></div>
        <div class="text-muted">Somados os lançamentos de R$ 5 mil ou mais de cada cliente.</div></details>` : ''}
    </div>`;
  },

  cartaoOportunidadesCorporativo(d) {
    const por = d.oportunidades_negocios || {};
    const ids = Object.keys(por);
    if (!ids.length) return '';
    const nomes = Object.fromEntries((d.negocios || []).map((n) => [String(n.id), n.rotulo]));
    let op = 0;
    let ri = 0;
    const linhas = ids.sort((a, b) => (por[b].oportunidade - por[b].risco) - (por[a].oportunidade - por[a].risco)).map((id) => {
      const x = por[id];
      op += x.oportunidade;
      ri += x.risco;
      const top = x.itens.slice(0, 2).map((i) => Modal.esc(i.rotulo)).join('; ');
      return `<tr data-negocio-oport="${id}"><td>${Modal.esc(nomes[id] || id)}</td>
        <td class="text-end text-success">+${this.moeda(x.oportunidade)}</td>
        <td class="text-end text-danger">−${this.moeda(Math.abs(x.risco))}</td>
        <td class="text-end fw-bold">${this.moeda(x.oportunidade + x.risco)}</td>
        <td class="small text-muted">${top}</td></tr>`;
    }).join('');
    const mes = por[ids[0]].mes_ate;
    return this.expansor('oportunidades', 'painel-oportunidades', `Oportunidades e riscos para ${d.ano_planejamento}, por negócio`,
      `oportunidade ${this.mi(op, true)} · risco ${this.mi(ri)}`, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-oportunidades">
            <thead><tr><th></th><th class="text-end">Oportunidades<br><span class="fw-normal text-muted small">se não se repetir</span></th>
              <th class="text-end">Riscos<br><span class="fw-normal text-muted small">se não se repetir</span></th>
              <th class="text-end">Líquido</th><th>Maiores itens</th></tr></thead>
            <tbody>${linhas}
              <tr class="fw-bold table-light" data-negocio-oport="total"><td>Total dos negócios</td>
                <td class="text-end text-success">+${this.moeda(op)}</td><td class="text-end text-danger">−${this.moeda(Math.abs(ri))}</td>
                <td class="text-end">${this.moeda(op + ri)}</td><td></td></tr>
            </tbody>
          </table>
        </div>
        <div class="small text-muted">Por negócio, o quadro de oportunidades e riscos de ${d.ano} (jan–${this.nomeMes(mes)}): itens não
          recorrentes por conta contábil e meses fora do padrão, sem contar nada duas vezes. O detalhe, mês a mês, está na tela de cada negócio.</div>
        ${this.jurosCorporativo(d)}`);
  },

  /** No Corporativo, os juros recebidos por negócio e o risco acima de R$ 30 mil. */
  jurosCorporativo(d) {
    const por = d.juros_negocios || {};
    const ids = Object.keys(por).filter((id) => por[id].total >= 0.5);
    if (!ids.length) return '';
    const nomes = Object.fromEntries((d.negocios || []).map((n) => [String(n.id), n.rotulo]));
    const m = (v) => this.moeda(v);
    const t = { socio: 0, nao_socio: 0, total: 0, risco: 0, acima: 0 };
    const linhas = ids.sort((a, b) => por[b].risco - por[a].risco || por[b].total - por[a].total).map((id) => {
      const x = por[id];
      Object.keys(t).forEach((k) => { t[k] += x[k]; });
      return `<tr data-negocio-juros="${id}"><td>${Modal.esc(nomes[id] || id)}</td><td class="text-end">${m(x.socio)}</td>
        <td class="text-end">${m(x.nao_socio)}</td><td class="text-end">${m(x.total)}</td>
        <td class="text-end ${x.risco ? 'text-danger' : ''}">${x.risco ? `${m(x.risco)} <span class="small text-muted">(${x.acima})</span>` : '—'}</td>
        <td class="small text-muted">${x.maiores.map((c) => Modal.esc(c.cliente)).join('; ')}</td></tr>`;
    }).join('');
    return `<div class="mt-3" id="juros-recebidos">
      <div class="small fw-bold mb-1">Juros recebidos de clientes, por negócio</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-1 tabela-juros">
        <thead><tr><th></th><th class="text-end">Sócio</th><th class="text-end">Não sócio</th><th class="text-end">Total</th>
          <th class="text-end">Risco<br><span class="fw-normal text-muted small">lançamentos &gt; R$ 30 mil</span></th>
          <th>Maiores clientes</th></tr></thead>
        <tbody>${linhas}
          <tr class="fw-bold table-light" data-negocio-juros="total"><td>Total dos negócios</td><td class="text-end">${m(t.socio)}</td>
            <td class="text-end">${m(t.nao_socio)}</td><td class="text-end">${m(t.total)}</td>
            <td class="text-end text-danger" data-juros="risco">${m(t.risco)} <span class="small text-muted">(${t.acima})</span></td><td></td></tr>
        </tbody></table></div>
      <div class="small text-muted">O cliente e a filial de cada lançamento acima de R$ 30 mil estão na tela de cada negócio.</div>
    </div>`;
  },

  /**
   * O MONTANTE DE OPORTUNIDADES DO ESTOQUE, logo abaixo da DRE (pedido do
   * cliente, 2026-11-18: "totalizasse as oportunidades e trouxesse logo abaixo do
   * resultado líquido, para evidenciar o montante a ser trabalhado ao longo do
   * ano, que pode ser desafiado no negócio"). Perdas e acerto de estoque mês a
   * mês contra a média, e o estoque parado; o total separa o que é RESULTADO do
   * que é CAIXA. Só leitura, sempre aberto.
   */
  cartaoEstoque(d) {
    if (d.corporativo) return this.cartaoEstoqueCorporativo(d);
    const naFilial = !!d.filial;
    const e = naFilial ? d.filial.estoque : d.estoque;
    if (!e) return '';
    const m = (v) => this.moeda(v);
    const meses = Array.from({ length: e.mes_ate }, (_, i) => i + 1);
    const cab = meses.map((x) => `<th class="text-end">${this.nomeMes(x)}</th>`).join('');
    const linhas = e.linhas.map((l) => `<tr data-estoque="${Modal.esc(l.tipo)}">
        <td>${Modal.esc(l.nome)} <span class="text-muted small">${Modal.esc(l.conta)} · linha ${Modal.esc(l.linha_dre)}</span></td>
        ${l.meses.map((x) => `<td class="text-end${x.excesso > 0 ? ' estoque-acima' : ''}"${x.excesso > 0
    ? ` title="${this.moeda(x.excesso)} acima da média"` : ''}>${m(x.valor)}</td>`).join('')}
        <td class="text-end fw-bold">${m(l.total)}</td>
        <td class="text-end">${m(l.media)}${l.regua === 'FILIAIS'
    ? `<div class="small text-muted text-nowrap">${naFilial ? 'entre' : `${l.acima} de`} ${l.filiais} filia${l.filiais === 1 ? 'l' : 'is'}${naFilial ? '' : ' acima'}</div>` : ''}</td>
        <td class="text-end">${l.oportunidade >= 0.5 ? `<span class="text-success">+${m(l.oportunidade)}</span>`
    : `<span class="text-muted" title="${l.regua === 'FILIAIS'
      ? (naFilial ? 'A filial está na média das filiais ou abaixo dela.' : 'Nenhuma filial passa da média das filiais.')
      : 'No ano, a sobra de inventário superou a falta: não há o que trazer à média.'}">—</span>`}</td></tr>`).join('');
    const entreFiliais = e.linhas.some((l) => l.regua === 'FILIAIS');
    const vazio = `<td colspan="${e.mes_ate + 2}"></td>`;
    const p = e.parado;
    const parado = p ? `<tr data-estoque="PARADO">
        <td>Produtos parados há mais de ${p.dias >= 730 ? `${Math.round(p.dias / 365)} anos` : p.dias >= 365 ? '1 ano' : `${p.dias} dias`}
          <span class="text-muted small">${p.itens.toLocaleString('pt-BR')} itens · posição de ${p.data_base.split('-').reverse().join('/')}</span></td>
        <td colspan="${e.mes_ate + 2}" class="small text-muted">valor do estoque (custo) sem venda no período</td>
        <td class="text-end text-success">+${m(p.valor)}</td></tr>` : '';
    const liq = (this.visao(d).linhas || []).find((x) => x.codigo === '18');
    const peso = liq && Math.abs(liq.projecao) >= 1
      ? `<div class="small mt-1" id="estoque-peso">No resultado, o montante equivale a <strong>${(e.resultado / Math.abs(liq.projecao) * 100)
        .toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%</strong> do resultado líquido projetado de ${d.ano}
        (${liq.projecao < 0 ? '−' : ''}R$ ${m(Math.abs(liq.projecao))}).</div>` : '';
    return `<div class="card mb-3 border-success" id="projecao-estoque">
      <div class="card-body py-2 px-3">
        <h2 class="h6 mb-1">Oportunidades do estoque a trabalhar em ${d.ano_planejamento}${naFilial ? ' — esta filial' : ''}</h2>
        <div class="small text-muted mb-2">${entreFiliais
    ? `Perdas e acerto de estoque de ${d.ano} (jan–${this.nomeMes(e.mes_ate)}), mês a mês, no sinal da DRE. A régua é a
          <strong>média entre as filiais</strong> do negócio (em reais, no ano): ${naFilial
      ? 'o que esta filial passou dela é a oportunidade.'
      : 'cada filial acima dela tem como oportunidade o que passou, e o negócio é a soma das filiais.'}`
    : `Perdas e acerto de estoque de ${d.ano} (jan–${this.nomeMes(e.mes_ate)}), mês a mês, no sinal da DRE: o que cada mês passou da
          <strong>média mensal</strong> é a oportunidade. Em verde-claro, os meses acima dela.`}${p ? ' E o estoque parado, que é caixa a liberar.' : ''}</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-estoque">
            <thead><tr><th>Indicador</th>${cab}<th class="text-end">Total</th><th class="text-end">Média<br><span class="fw-normal text-muted small">${entreFiliais
    ? 'entre as filiais (ano)' : 'mensal'}</span></th>
              <th class="text-end">Oportunidade<br><span class="fw-normal text-muted small">acima da média</span></th></tr></thead>
            <tbody>${linhas}${parado}
              <tr class="fw-bold table-light"><td>No resultado <span class="fw-normal small text-muted">perdas e acerto acima da média</span></td>${vazio}
                <td class="text-end text-success" data-estoque-total="resultado">+${m(e.resultado)}</td></tr>
              ${p ? `<tr class="fw-bold table-light"><td>No caixa <span class="fw-normal small text-muted">estoque parado</span></td>${vazio}
                <td class="text-end text-success" data-estoque-total="caixa">+${m(e.caixa)}</td></tr>` : ''}
              <tr class="fw-bold"><td>Montante de oportunidades a trabalhar em ${d.ano_planejamento}</td>${vazio}
                <td class="text-end text-success fs-6" data-estoque-total="total">+${m(e.total)}</td></tr>
            </tbody>
          </table>
        </div>
        ${peso}
        ${naFilial ? '' : this.estoqueDasFiliais(e)}
        <div class="small text-muted mt-1">Fontes: Qlik DRE - Gerencial (contas 3.07.01.01.06 e 3.11.03.03.01) e Produtos Parados
          (mais de 2 anos nas Agropecuárias, mais de 1 ano nos Supermercados). O estoque parado é <strong>caixa</strong>, não resultado:
          vendê-lo libera capital, e o resultado depende do preço. Perdas e acerto estão no <strong>Custo das Vendas</strong> (linha 4.1),
          que o quadro de oportunidades não varre: nada aqui está contado duas vezes. Nada daqui entra sozinho na proposta — é o tamanho
          do desafio.</div>
      </div>
    </div>`;
  },

  /** No negócio, as filiais com mais oportunidade no estoque (as dez maiores; o resto, recolhido). */
  estoqueDasFiliais(e) {
    const f = e.filiais || [];
    if (!f.length) return '';
    const m = (v) => (v >= 0.5 ? this.moeda(v) : '<span class="text-muted">—</span>');
    const linha = (x) => `<tr data-estoque-filial="${Modal.esc(x.cod_filial)}"><td>${Modal.esc(x.cod_filial)} — ${Modal.esc(x.nome)}</td>
      <td class="text-end">${m(x.perdas)}</td><td class="text-end">${m(x.acerto)}</td><td class="text-end">${m(x.parado)}</td>
      <td class="text-end fw-bold">${this.moeda(x.total)}</td></tr>`;
    const cab = `<thead><tr><th>Filial</th><th class="text-end">Perdas<br><span class="fw-normal text-muted small">acima da média</span></th>
      <th class="text-end">Acerto<br><span class="fw-normal text-muted small">acima da média</span></th>
      <th class="text-end">Parado</th><th class="text-end">Total</th></tr></thead>`;
    const resto = f.slice(10);
    return `<div class="small fw-bold mt-2 mb-1">Onde está, por filial</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-1 tabela-estoque-filiais">${cab}
        <tbody>${f.slice(0, 10).map(linha).join('')}</tbody></table></div>
      ${resto.length ? `<details class="small"><summary>mais ${resto.length} filiais</summary>
        <div class="table-responsive"><table class="table table-sm align-middle mb-1">${cab}<tbody>${resto.map(linha).join('')}</tbody></table></div>
      </details>` : ''}
      <div class="small text-muted">Perdas e acerto: o que cada filial passou da média entre as filiais — a soma é a oportunidade do
        negócio. Abra a filial para ver os meses dela.</div>`;
  },

  cartaoEstoqueCorporativo(d) {
    const por = d.estoque_negocios || {};
    // Negócio sem nada a trabalhar (o acerto de Rações é crédito o ano todo) não ocupa linha.
    const ids = Object.keys(por).filter((id) => por[id].total >= 0.5);
    if (!ids.length) return '';
    const nomes = Object.fromEntries((d.negocios || []).map((n) => [String(n.id), n.rotulo]));
    const t = { perdas: 0, acerto: 0, parado: 0, resultado: 0, total: 0 };
    const m = (v) => (v >= 0.5 ? this.moeda(v) : '<span class="text-muted">—</span>');
    const linhas = ids.sort((a, b) => por[b].total - por[a].total).map((id) => {
      const x = por[id];
      Object.keys(t).forEach((k) => { t[k] += x[k]; });
      return `<tr data-negocio-estoque="${id}"><td>${Modal.esc(nomes[id] || id)}</td>
        <td class="text-end">${m(x.perdas)}</td><td class="text-end">${m(x.acerto)}</td>
        <td class="text-end fw-bold">${m(x.resultado)}</td><td class="text-end">${m(x.parado)}</td>
        <td class="text-end fw-bold">${this.moeda(x.total)}</td></tr>`;
    }).join('');
    return `<div class="card mb-3 border-success" id="projecao-estoque">
      <div class="card-body py-2 px-3">
        <h2 class="h6 mb-1">Oportunidades do estoque a trabalhar em ${d.ano_planejamento}, por negócio</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-estoque">
            <thead><tr><th></th><th class="text-end">Perdas<br><span class="fw-normal text-muted small">acima da média</span></th>
              <th class="text-end">Acerto<br><span class="fw-normal text-muted small">acima da média</span></th>
              <th class="text-end">No resultado</th><th class="text-end">No caixa<br><span class="fw-normal text-muted small">estoque parado</span></th>
              <th class="text-end">Montante</th></tr></thead>
            <tbody>${linhas}
              <tr class="fw-bold table-light" data-negocio-estoque="total"><td>Total dos negócios</td>
                <td class="text-end">${m(t.perdas)}</td><td class="text-end">${m(t.acerto)}</td>
                <td class="text-end text-success" data-estoque-total="resultado">+${this.moeda(t.resultado)}</td>
                <td class="text-end text-success" data-estoque-total="caixa">+${this.moeda(t.parado)}</td>
                <td class="text-end text-success fs-6" data-estoque-total="total">+${this.moeda(t.total)}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="small text-muted">Por negócio: perdas e acerto de estoque de ${d.ano}, o que os meses passaram da média mensal, e o
          estoque parado (mais de 2 anos nas Agropecuárias, mais de 1 ano nos Supermercados), que é caixa, não resultado. Os meses e as
          filiais estão na tela de cada negócio.</div>
      </div>
    </div>`;
  },

  /** O cabeçalho que só aparece no papel: de quem é a DRE e quando foi impressa. */
  cabecalhoImpressao(d, complemento) {
    const neg = (d.negocios || []).find((n) => Number(n.id) === Number(d.negocio_id));
    return `<div id="projecao-dre-cabecalho" class="cabecalho-impressao-dre">
      <div class="fw-bold fs-5">Copérdia — Proposta da DRE ${d.ano_planejamento}</div>
      <div>${Modal.esc(d.corporativo ? '' : (neg ? neg.rotulo : ''))}${complemento ? `${d.corporativo ? '' : ' · '}${Modal.esc(complemento)}` : ''}
        · realizado jan–${this.nomeMes(d.mes_fechado_ate)}/${d.ano}, projetado ${this.nomeMes(d.mes_fechado_ate + 1)}–dez
        · impresso em <span data-impresso-em></span></div>
    </div>`;
  },

  /**
   * O CENÁRIO de planilha que formou a Receita de Vendas da proposta (pedido do
   * cliente, 2026-11-19: Pecuária, cenário realista): o faturamento da planilha
   * está no formato antigo (com o PIS/COFINS), e o lançado é ele sem a CBS — a
   * mesma conversão do cartão "Tributos na DRE: como é × como fica".
   */
  /** O cenário da planilha abaixo do mínimo do ano: a DRE usa o mínimo, e o cartão diz. */
  avisoCenarioAbaixoDoPiso(d) {
    const l = (d.linhas || []).find((x) => x.codigo === '1.1');
    if (!l || !l.piso_aplicado) return '';
    return `<div class="alert alert-warning py-2 px-3 small mb-2" id="cenario-abaixo-do-piso">
        O cenário da planilha (Receita de Vendas R$ ${this.moeda(d.cenario.receita_vendas)}) ficou <strong>abaixo do mínimo de
        ${d.ano_planejamento}</strong> — IPCA + PIB + crescimento desejado, ${this.pct((Number(l.piso_percentual) || 0) / 100, 2)}
        sobre a projeção. A DRE usa o mínimo, <strong>R$ ${this.moeda(l.proposta)}</strong>; o cenário continua guardado.
      </div>`;
  },

  cartaoCenario(d) {
    const c = d.cenario;
    if (!c || d.corporativo || d.filial) return '';
    const m = (v) => this.moeda(v);
    const nomes = { PESSIMISTA: 'pessimista', REALISTA: 'realista', OTIMISTA: 'otimista' };
    const escolhido = (k) => (nomes[c.cenario] === k ? ' class="table-success fw-bold"' : '');
    const itens = c.itens.map((i) => `<tr><td>${Modal.esc(i.nome)}</td>
        <td class="text-end">${m(i.pessimista)}</td><td class="text-end${nomes[c.cenario] === 'realista' ? ' fw-bold' : ''}">${m(i.realista)}</td>
        <td class="text-end">${m(i.otimista)}</td></tr>`).join('');
    return this.expansor('cenario', 'painel-cenario', `Cenário de faturamento ${d.ano_planejamento} — ${nomes[c.cenario] || c.cenario}`,
      `Receita de Vendas ${this.mi(c.receita_vendas)} (sem a CBS)`, `
      ${this.avisoCenarioAbaixoDoPiso(d)}
      <div class="small mb-2">A Receita de Vendas da proposta veio do cenário <strong>${nomes[c.cenario] || c.cenario}</strong> da
        planilha do negócio. A planilha está no <strong>formato antigo</strong>, com o PIS/COFINS dentro do faturamento; o que se
        lança é o formato novo, sem a CBS — como no cartão "Tributos na DRE: como é × como fica".</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-2 tabela-cenario-conversao" style="max-width:40rem">
        <tbody>
          <tr><td>Faturamento da planilha (formato antigo)</td><td class="text-end" data-cenario="faturamento">${m(c.faturamento)}</td></tr>
          <tr class="linha-muda"><td>(−) CBS — o PIS/COFINS, ${(c.taxa * 100).toLocaleString('pt-BR', { maximumFractionDigits: 4 })}% do faturamento</td>
            <td class="text-end" data-cenario="cbs">−${m(c.cbs)}</td></tr>
          <tr class="fw-bold"><td>Receita de Vendas lançada (formato novo)</td><td class="text-end" data-cenario="receita">${m(c.receita_vendas)}</td></tr>
        </tbody></table></div>
      <details class="small"><summary>Os três cenários da planilha, item a item</summary>
        <div class="table-responsive"><table class="table table-sm align-middle mb-1 tabela-cenario">
          <thead><tr><th>Item</th><th class="text-end">Pessimista</th><th class="text-end">Realista</th><th class="text-end">Otimista</th></tr></thead>
          <tbody>${itens}
            <tr class="table-light"><td class="fw-bold">Total (formato antigo)</td>
              <td${escolhido('pessimista')} style="text-align:right">${m(c.totais.pessimista)}</td>
              <td${escolhido('realista')} style="text-align:right">${m(c.totais.realista)}</td>
              <td${escolhido('otimista')} style="text-align:right">${m(c.totais.otimista)}</td></tr>
          </tbody></table></div>
        <div class="text-muted">Fonte: ${Modal.esc(c.fonte)}. Carregado em ${c.aplicado_em.slice(0, 10).split('-').reverse().join('/')};
          depois disso o número é do negócio — relançar na Receita de Vendas substitui este.</div>
      </details>`);
  },

  /** Reais em milhões, para os resumos dos expansores. `sinal` força o + nos positivos. */
  mi(v, sinal = false) {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    const t = `R$ ${(Math.abs(v) / 1e6).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} mi`;
    if (v < 0 && t !== 'R$ 0,00 mi') return `−${t}`;
    return sinal && v > 0 ? `+${t}` : t;
  },

  comSinal(v) {
    return `${Math.round(v) > 0 ? '+' : ''}${this.moeda(v)}`;
  },

  corDif(v) {
    if (v === null || Math.abs(v) < 5e4) return '';
    return v > 0 ? 'text-success' : 'text-danger';
  },

  /**
   * Os cartões de cima da tela — visão do ano, desafio, premissas, pessoas —
   * são EXPANSORES (pedido do cliente, 2026-11-18: "para otimizar a tela").
   * Fechado, cada um mostra no título o número que importa, então a tabela da
   * proposta sobe sem que se perca o que está acima dela.
   *
   * É `<details>`, não JS: abre e fecha sem código, e o que estiver digitado
   * dentro continua no DOM. O estado de cada um é conveniência de quem olha e
   * fica no `localStorage`, como o de recolher subcontas; por omissão só o
   * desafio vem aberto. No dossiê tudo sai aberto — papel não se expande.
   */
  EXPANSORES_PADRAO: { visao: false, desafio: true, oportunidades: true, tributos: true, premissas: false, pessoas: false },

  expansorAberto(chave) {
    if (App.modoDossie) return true;
    if (this.expansores === undefined) {
      try {
        const salvo = JSON.parse(localStorage.getItem('projecao.expansores') || '{}');
        this.expansores = salvo && typeof salvo === 'object' ? salvo : {};
      } catch (e) { this.expansores = {}; }
    }
    const v = this.expansores[chave];
    return typeof v === 'boolean' ? v : !!this.EXPANSORES_PADRAO[chave];
  },

  guardarExpansor(chave, aberto) {
    this.expansorAberto(chave);
    this.expansores[chave] = !!aberto;
    try { localStorage.setItem('projecao.expansores', JSON.stringify(this.expansores)); } catch (e) { /* sem armazenamento: vale só nesta tela */ }
  },

  expansor(chave, classe, titulo, resumo, corpo) {
    return `<details class="card mb-3 expansor ${classe}" data-expansor="${chave}"${this.expansorAberto(chave) ? ' open' : ''}>
        <summary class="px-3 py-2">
          <h2 class="h6 mb-0">${titulo}</h2>
          ${resumo ? `<span class="small text-muted expansor-resumo">${resumo}</span>` : ''}
        </summary>
        <div class="card-body pt-0 pb-2 px-3">${corpo}</div>
      </details>`;
  },

  /**
   * A DRE das filiais é acumulada e só vale para o corte em que foi extraída.
   * Se o negócio já está em outubro e as filiais ainda em setembro, as duas
   * colunas Realizado deixam de ser comparáveis e a coluna A distribuir mede
   * contra uma base de meses diferentes — isso precisa estar escrito na tela,
   * não na memória de quem fez a carga.
   */
  avisoFiliaisDefasadas(d) {
    if (!d.filiais.length || !d.filiais_corte || Number(d.filiais_corte) >= Number(d.mes_fechado_ate)) {
      return '';
    }
    return `<div id="projecao-filiais-defasadas" class="alert alert-warning py-2 px-3 small mb-3">
      <strong>As filiais estão no fechamento de ${this.nomeMes(d.filiais_corte)}.</strong>
      O negócio já está em ${this.nomeMes(d.mes_fechado_ate)}: o realizado e o projetado por filial
      não somam com os do negócio até a DRE das filiais ser extraída de novo.
    </div>`;
  },

  faixaDoModo(d) {
    if (!d.filiais.length) return '';
    const nome = d.filial
      ? (d.filiais.find((f) => Number(f.id) === Number(d.filial.id)) || {}).nome
      : null;
    if (d.filial) {
      return `<div class="alert alert-light border py-2 px-3 small mb-3">
        Você está lançando em <strong>${Modal.esc(nome || '')}</strong>.
        ${d.modo === 'FILIAL'
    ? 'Neste negócio, o total é a <strong>soma das filiais</strong>.'
    : 'Este negócio planeja no total; o que você lançar aqui é a <strong>distribuição</strong>, '
      + 'e não muda o total do negócio.'}
      </div>`;
    }
    return `<div class="alert alert-light border py-2 px-3 small mb-3">
      ${d.modo === 'FILIAL'
    ? 'Este negócio planeja <strong>por filial</strong>: a coluna Proposta é a soma das filiais e '
      + 'não se digita aqui. Escolha uma filial para lançar.'
    : 'Este negócio planeja <strong>no total</strong>. A coluna <em>A distribuir</em> mostra o que '
      + 'falta repartir entre as filiais — distribuir não é obrigatório para salvar.'}
    </div>`;
  },

  corpo(d) {
    const { linhas } = this.visao(d);
    const linhaRol = linhas.find((l) => l.codigo === '3') || {};
    const rol = linhaRol.projecao || 0;
    // Estrutura nova da DRE (2026-11-18): a margem líquida é a 19, sobre o líquido (18).
    const CALCULADAS = { 6: '5', 8: '7', 19: '18' };
    const podeLancar = this.editavelAqui(d);
    const mostrarDistribuir = !d.filial && d.filiais.length && d.modo === 'NEGOCIO';
    // O nível 3 (conta reduzida) da visão aberta: negócio, filial ou Corporativo.
    const n3 = (d.filial ? d.filial.nivel3 : d.nivel3) || {};
    if (!this.reduzidasAbertas) this.reduzidasAbertas = new Set();

    const valor = (linha, campo) => {
      if (!linha.percentual) return this.moeda(linha[campo]);
      const base = linhaRol[campo] || 0;
      if (!base) return '—';
      const alvo = linhas.find((x) => x.codigo === CALCULADAS[linha.codigo]);
      return alvo ? this.pct(alvo[campo] / base) : '—';
    };
    const av = (linha) => (linha.percentual || !rol ? '' : this.pct(linha.projecao / rol));
    // A.V. da PROPOSTA: cada linha sobre a ROL proposta — é ela que diz se a meta
    // preserva a margem (diretriz do horizonte 1), lado a lado com a A.V. da projeção.
    const rolProposta = linhaRol.proposta || 0;
    const avProp = (linha) => (linha.percentual || !rolProposta || linha.proposta === null || linha.proposta === undefined
      ? '' : this.pct(linha.proposta / rolProposta));

    return linhas.map((l) => {
      const bloco = l.nivel === 1;
      const negativo = (v) => (v < 0 ? 'text-danger' : '');
      const editavel = l.nivel === 2 && !l.percentual && podeLancar;
      // Despesas com Pessoal abaixo do mínimo (pedido do cliente, 2026-11-23):
      // grava, e a célula ganha o ⚠ para validar (`marcarMinimoPessoal`).
      const abaixoMinimo = !!l.abaixo_minimo_pessoal;

      // A proposta: célula de digitar quando a linha é subconta; o número
      // derivado quando é bloco; "—" quando não há regra para derivá-la.
      let proposta;
      if (editavel) {
        proposta = `<input type="text" class="celula-valor celula-proposta${
          l.proposta_origem === 'PERCENTUAL' ? ' celula-espelhada' : ''}${
          l.abaixo_piso || abaixoMinimo ? ' celula-pior' : ''}" inputmode="decimal" data-sinal
          autocomplete="off" value="${Modal.esc(this.textoLancado(l))}"
          placeholder="${this.moeda(l.proposta)}"
          data-conta="${Modal.esc(l.codigo)}"
          aria-label="Proposta de ${d.ano_planejamento} para ${Modal.esc(l.nome)}">`;
      } else if (l.proposta === null || l.proposta === undefined) {
        proposta = `<span class="text-muted" title="${Modal.esc(
          'Linha que o Qlik entrega pronta e que o sistema não sabe recompor — '
          + 'enquanto a regra dela não estiver no modelo, ela não recebe proposta.'
        )}">—</span>`;
      } else if (l.percentual) {
        const alvo = linhas.find((x) => x.codigo === CALCULADAS[l.codigo]);
        const baseProp = (linhas.find((x) => x.codigo === '3') || {}).proposta || 0;
        proposta = alvo && baseProp ? this.pct(alvo.proposta / baseProp) : '—';
      } else {
        proposta = this.moeda(l.proposta);
      }

      const cresc = (!l.percentual && l.proposta !== null && l.projecao)
        ? this.pct(l.proposta / l.projecao - 1) : '';
      const pior = cresc && l.proposta < l.projecao;

      // O reajuste: a coluna B da planilha do cliente. Mesmo lançamento da
      // Proposta (`entrada = PERCENTUAL`), só que com célula própria — nas
      // linhas que não recebem lançamento ela volta a ser o que sempre foi,
      // a leitura do crescimento.
      let reajuste;
      if (editavel) {
        reajuste = `<input type="text" class="celula-valor celula-reajuste${
          l.proposta_origem === 'VALOR' ? ' celula-espelhada' : ''}${
          pior || l.abaixo_piso ? ' celula-pior' : ''}" inputmode="decimal" data-sinal
          autocomplete="off" value="${Modal.esc(this.textoReajuste(l))}"
          placeholder="${l.proposta_padrao !== null && l.proposta_padrao !== undefined ? this.pct(l.proposta_padrao / 100, 2) : '0,00%'}"
          data-conta="${Modal.esc(l.codigo)}"
          aria-label="Reajuste sobre a projeção para ${Modal.esc(l.nome)}">`;
      } else {
        reajuste = `<span class="${pior ? 'text-danger' : 'text-muted'}">${cresc}</span>`;
      }

      const negativa = l.codigo === '18' && l.proposta !== null && l.proposta < -1;
      // Formato de 2027 (`Reforma::formatoNovo`): a CBS é linha informativa acima
      // da Receita Bruta, e o nome das linhas que mudaram vai em amarelo.
      const informativa = !!l.informativa;
      // O nível 3 (conta reduzida) desta linha, se houver: um botão ▸ abre as
      // contas dela, e o botão de cima abre todas.
      const reduzidas = n3[l.codigo] || [];
      const abre = reduzidas.length && !l.percentual && (l.nivel === 2 || informativa)
        ? `<button type="button" class="btn btn-link btn-sm p-0 me-1 abre-reduzidas d-print-none" data-abre-reduzidas="${Modal.esc(l.codigo)}"
            aria-expanded="${this.reduzidasAbertas.has(l.codigo) ? 'true' : 'false'}"
            title="Contas reduzidas desta linha, como no Qlik (nível 3)">${this.reduzidasAbertas.has(l.codigo) ? '▾' : '▸'}</button>`
        : '';
      return `<tr class="${informativa ? 'linha-cbs' : (bloco ? 'fw-bold linha-bloco' : 'linha-subconta')}${negativa ? ' resultado-negativo' : ''}"
        data-conta="${Modal.esc(l.codigo)}" data-nivel="${l.nivel}"
        data-origem="${Modal.esc(l.proposta_origem || '')}">
        <td class="${bloco || informativa ? '' : 'ps-4 text-muted'}${l.formato_novo || informativa ? ' linha-muda-nome' : ''}">${abre}${Modal.esc(l.nome)}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.realizado)}">${valor(l, 'realizado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projetado)}">${valor(l, 'projetado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projecao)}">${valor(l, 'projecao')}</td>
        <td class="text-end text-muted small">${av(l)}</td>
        <td class="text-end celula-proposta-td ${
  ['PROJECAO', 'PREMISSA'].includes(l.proposta_origem) ? 'proposta-por-omissao' : ''}">${
  abaixoMinimo ? this.avisoMinimoPessoal(l) : ''}${proposta}</td>
        <td class="text-end text-muted small celula-av-proposta">${avProp(l)}</td>
        <td class="text-end small celula-reajuste-td">${reajuste}</td>
        ${mostrarDistribuir ? this.celulaDistribuir(l) : ''}
      </tr>${abre ? this.linhasReduzidas(l, reduzidas, mostrarDistribuir) : ''}`;
    }).join('');
  },

  /**
   * As contas reduzidas (nível 3) de uma linha do nível 2, logo abaixo dela —
   * como a aba "#2 Planejado X Realizado" do Qlik as lista: reduzida e nome, e o
   * realizado de jan até o corte (pedido do cliente, 2026-11-21). Só leitura:
   * projetado, projeção e proposta ficam em branco, porque o orçamento é da
   * linha e a meta se lança nela.
   *
   * Se o realizado das reduzidas não somar o da linha (o Qlik foi recarregado
   * depois da carga da DRE), a diferença vai numa linha própria, dita — nunca
   * escondida numa conta que não a tem.
   */
  linhasReduzidas(l, reduzidas, mostrarDistribuir) {
    const aberta = this.reduzidasAbertas.has(l.codigo) ? ' aberta' : '';
    // Projetado, projeção, A.V., proposta, A.V. da proposta e reajuste: em branco.
    const vazias = `<td></td><td></td><td></td><td></td><td></td><td></td>${mostrarDistribuir ? '<td></td>' : ''}`;
    const linha = (nome, v, attrs, extra = '') => `<tr class="linha-reduzida${aberta}${extra}" data-pai="${Modal.esc(l.codigo)}" ${attrs}>
        <td class="ps-5 small">${nome}</td>
        <td class="text-end small ${v < 0 ? 'text-danger' : ''}">${this.moeda(v)}</td>
        ${vazias}
      </tr>`;
    const soma = reduzidas.reduce((a, r) => a + r.realizado, 0);
    const resto = (Number(l.realizado) || 0) - soma;
    return reduzidas.map((r) => linha(
      `${r.reduzida ? `<span class="reduzida-cod">${Modal.esc(r.reduzida)}</span> ` : ''}${Modal.esc(r.nome)}`,
      r.realizado, `data-reduzida="${Modal.esc(r.reduzida || r.codigo)}"`
    )).join('') + (Math.abs(resto) >= 100 ? linha(
      '<span class="text-muted fst-italic">Diferença entre a linha e as contas reduzidas (o Qlik foi recarregado depois da carga da DRE)</span>',
      resto, 'data-reduzida-diferenca="1"', ' linha-reduzida-diferenca'
    ) : '');
  },

  /**
   * Liga o que AINDA não está ligado.
   *
   * A marca `data-ligado` não é zelo: esta tela repinta pedaços (o corpo da
   * tabela, o painel) e chama `ligar` de novo: sem a marca, os campos que não
   * foram repintados ganhavam um segundo ouvinte de `blur` e a mesma célula
   * gravava duas vezes — a segunda com a versão já velha, levantando um
   * "alguém gravou enquanto você editava" que não era verdade. O defeito
   * apareceu na sonda, não na bateria: ele precisa de duas gravações seguidas
   * na mesma tela para nascer.
   */
  ligar(el) {
    const virgem = (n) => {
      if (n.dataset.ligado) return false;
      n.dataset.ligado = '1';
      return true;
    };
    // O toggle do <details> não borbulha: escuta-se na captura, uma vez só no
    // contêiner da seção (que sobrevive às trocas de negócio).
    if (!el.dataset.expansores) {
      el.dataset.expansores = '1';
      el.addEventListener('toggle', (ev) => {
        const t = ev.target;
        if (t instanceof HTMLDetailsElement && t.dataset.expansor && !App.modoDossie) {
          this.guardarExpansor(t.dataset.expansor, t.open);
        }
      }, true);
    }
    const caixaNeg = el.querySelector('#projecao-meta-negativa-box');
    if (caixaNeg && this.dados) caixaNeg.innerHTML = this.alertaMetaNegativa(this.dados);
    const caixaPiso = el.querySelector('#projecao-piso-box');
    if (caixaPiso && this.dados) caixaPiso.innerHTML = this.alertaPisoFaturamento(this.dados);
    const caixaPessoal = el.querySelector('#projecao-pessoal-box');
    if (caixaPessoal && this.dados) caixaPessoal.innerHTML = this.alertaPessoalMinimo(this.dados);
    const nivel = el.querySelector('#projecao-nivel');
    if (nivel && virgem(nivel)) nivel.addEventListener('click', () => this.alternarNivel());
    // A ida das oportunidades à linha da DRE: delegada no contêiner da seção, uma vez.
    if (!el.dataset.linkDre) {
      el.dataset.linkDre = '1';
      el.addEventListener('click', (ev) => {
        const a = ev.target.closest('.link-linha-dre');
        if (!a) return;
        ev.preventDefault();
        this.irParaLinhaDre(a.dataset.irConta, a.dataset.irReduzida || null);
      });
    }
    const nivel3 = el.querySelector('#projecao-nivel3');
    if (nivel3 && virgem(nivel3)) nivel3.addEventListener('click', () => this.alternarNivel3());
    // O ▸ de cada linha: delegado na tabela, que tem o corpo repintado a cada
    // gravação (um ouvinte por botão se perderia no repinte).
    const tabela = el.querySelector('.tabela-projecao');
    if (tabela && virgem(tabela)) {
      tabela.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-abre-reduzidas]');
        if (b) this.alternarReduzidas(b.dataset.abreReduzidas, b);
      });
    }
    const imprimir = el.querySelector('#projecao-imprimir-dre');
    if (imprimir && virgem(imprimir)) imprimir.addEventListener('click', () => this.imprimirDre());
    const sel = el.querySelector('#projecao-negocio');
    if (sel && virgem(sel)) {
      sel.addEventListener('change', (ev) => {
        // Escreve no contexto do MENU e deixa ele recarregar a seção: o negócio
        // é um só, e o topo, as outras telas e esta mudam juntos.
        const v = ev.target.value === 'corp' ? 'CORP' : ev.target.value;
        const global = document.getElementById('sel-negocio');
        if (!v || !global || ![...global.options].some((o) => o.value === v)) return;
        global.value = v;
        global.dispatchEvent(new Event('change'));
      });
    }
    // A gravação vem do `blur`, como nas células de Metas: quem percorre a
    // coluna com Tab grava linha a linha sem tirar a mão do teclado. O Enter
    // tira o foco, e o `blur` faz o resto — um caminho só de gravação.
    const selFilial = el.querySelector('#projecao-filial');
    if (selFilial && virgem(selFilial)) {
      selFilial.addEventListener('change', (ev) => {
        this.filialId = Number(ev.target.value);
        this.carregar();
      });
    }
    // O ano das premissas. Trocar de ano só repinta o painel: os valores de TODOS
    // os anos já vieram na mesma resposta (`premissas_por_ano`), e uma ida ao
    // servidor por troca seria espera sem motivo. O ano escolhido fica em
    // `this.anoPremissa` e sobrevive ao repinte que toda gravação faz.
    const selAno = el.querySelector('#premissa-ano');
    if (selAno && virgem(selAno)) {
      selAno.addEventListener('change', (ev) => {
        this.anoPremissa = Number(ev.target.value);
        // Pessoas e benefícios moram no mesmo bloco e acompanham o ano.
        el.querySelector('.painel-premissas').outerHTML = this.painel(this.dados);
        this.ligar(el);
        el.querySelector('#premissa-ano')?.focus();
      });
    }
    el.querySelectorAll('input[name="projecao-modo"]').forEach((r) => {
      if (!virgem(r)) return;
      r.addEventListener('change', async (ev) => {
        if (!ev.target.checked) return;
        try {
          const dados = await App.api('/api/projecao/modo', {
            negocio_id: this.negocioId, modo: ev.target.value, filial_id: this.filialId,
          });
          this.dados = { ...this.dados, ...dados };
          this.versao = dados.versao;
          this.avisar('');
          const secao = document.getElementById('secao-projecao');
          secao.innerHTML = this.html(this.dados);
          this.ligar(secao);
        } catch (e) {
          this.avisar(e.message);
          this.carregar();
        }
      });
    });
    // As DUAS células da linha, a de valor e a de percentual: elas gravam o
    // mesmo lançamento por caminhos diferentes, e por isso usam o mesmo
    // `gravar` — quem decide a `entrada` é a classe do campo.
    el.querySelectorAll('.celula-proposta, .celula-reajuste').forEach((inp) => {
      inp.dataset.original = inp.value;
      if (!virgem(inp)) return;
      inp.addEventListener('blur', () => this.gravar(inp));
      // Valor em reais: os pontos de milhar aparecem enquanto se digita.
      if (inp.classList.contains('celula-proposta')) {
        inp.addEventListener('focus', () => { delete inp.dataset.pontoDigitado; });
        inp.addEventListener('input', (ev) => this.formatarDigitando(inp, ev));
      }
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
        if (ev.key === 'Escape') { inp.value = inp.dataset.original ?? inp.value; inp.blur(); }
      });
      inp.dataset.original = inp.value;
    });
    el.querySelectorAll('.celula-beneficio').forEach((inp) => {
      inp.dataset.original = inp.value;
      if (!virgem(inp)) return;
      inp.addEventListener('blur', () => this.gravarBeneficio(inp));
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
        if (ev.key === 'Escape') { inp.value = inp.dataset.original ?? inp.value; inp.blur(); }
      });
    });
    el.querySelectorAll('.celula-premissa').forEach((inp) => {
      inp.dataset.original = inp.value;
      if (!virgem(inp)) return;
      inp.addEventListener('blur', () => this.gravarPremissa(inp));
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
        if (ev.key === 'Escape') { inp.value = inp.dataset.original ?? inp.value; inp.blur(); }
      });
      inp.dataset.original = inp.value;
    });
  },

  /**
   * Tudo o que lê a proposta, depois de uma gravação que a recalcula no
   * servidor (premissa ou benefício): o painel, o corpo da tabela, os alertas
   * e o como é × como fica. Repintar só um pedaço deixaria dois números
   * discordando na mesma tela até recarregar a página.
   */
  repintarProposta(el) {
    el.querySelector('.painel-premissas').outerHTML = this.painel(this.dados);
    const tbody = el.querySelector('.tabela-projecao tbody');
    if (tbody) tbody.innerHTML = this.corpo(this.dados);
    const negativa = el.querySelector('#projecao-meta-negativa-box');
    if (negativa) negativa.innerHTML = this.alertaMetaNegativa(this.dados);
    const piso = el.querySelector('#projecao-piso-box');
    if (piso) piso.innerHTML = this.alertaPisoFaturamento(this.dados);
    const pessoal = el.querySelector('#projecao-pessoal-box');
    if (pessoal) pessoal.innerHTML = this.alertaPessoalMinimo(this.dados);
    this.repintarTributos(el);
    this.ligar(el);
  },

  /** O como é × como fica lê a proposta: repinta junto com ela. */
  repintarTributos(el) {
    const c = el.querySelector('.painel-tributos');
    if (c) c.outerHTML = this.cartaoTributosDre(this.dados);
  },

  async gravarPremissa(inp) {
    const bruto = inp.value.trim();
    if (bruto === (inp.dataset.original ?? '')) return;
    const limpo = bruto.replace('%', '').replace(',', '.').trim();
    if (limpo !== '' && !Number.isFinite(Number(limpo))) {
      this.avisar('Premissa inválida: informe um número.');
      inp.value = inp.dataset.original ?? '';
      return;
    }
    // IPCA e PIB são positivos: recusa aqui, com a explicação, em vez de mandar
    // ao servidor o que ele vai recusar. O CRESCIMENTO desejado do faturamento
    // aceita negativo (pedido do cliente, 2026-11-19).
    if (limpo !== '' && Number(limpo) < 0 && inp.dataset.chave !== 'CRESCIMENTO') {
      this.avisar('O percentual de crescimento deve ser positivo: informe de 0% a 100%.');
      inp.value = inp.dataset.original ?? '';
      return;
    }
    inp.classList.add('celula-gravando');
    try {
      const r = await App.api('/api/projecao/premissa', {
        negocio_id: this.negocioId,
        filial_id: this.filialId,
        // O ano que o painel está mostrando: sem ele o servidor assumiria o da
        // proposta, e quem estivesse em 2029 gravaria o valor no ano errado.
        ano: this.anoDoPainel(this.dados),
        chave: inp.dataset.chave,
        valor: limpo === '' ? null : Number(limpo),
      });
      this.versao = r.versao;
      this.avisoPiso = '';
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      // O painel E A TABELA são repintados (pedido do cliente, 2026-11-19: "quando
      // eu mudo o IPCA, o PIB ou o crescimento do faturamento, deve impactar
      // direto na DRE abaixo"). IPCA + PIB + crescimento desejado é o reajuste da
      // Receita de Vendas, e deduções e custo a acompanham: o servidor já devolve
      // a proposta recalculada. Repintar só o painel deixava o Cresc. Total
      // dizendo 8% e a DRE com os 6% de antes, até recarregar a página.
      const el = document.getElementById('secao-projecao');
      this.repintarProposta(el);
      el.querySelector(`#premissa-${inp.dataset.chave}`)?.focus();
    } catch (e) {
      this.avisar(e.message);
      inp.value = inp.dataset.original ?? '';
    } finally {
      inp.classList.remove('celula-gravando');
    }
  },

  /** Grava um valor de pessoas e benefícios no ano que o painel mostra. */
  async gravarBeneficio(inp) {
    const bruto = inp.value.trim();
    if (bruto === (inp.dataset.original ?? '')) return;
    const limpo = bruto.replace(/R\$|%/g, '').replace(/\./g, '').replace(',', '.').trim();
    if (limpo !== '' && !Number.isFinite(Number(limpo))) {
      this.avisar('Informe um número.');
      inp.value = inp.dataset.original ?? '';
      return;
    }
    inp.classList.add('celula-gravando');
    try {
      const r = await App.api('/api/projecao/beneficio', {
        negocio_id: this.negocioId,
        ano: this.anoDoPainel(this.dados),
        chave: inp.dataset.chave,
        valor: limpo === '' ? null : Number(limpo),
      });
      if (r.versao !== undefined) this.versao = r.versao;
      this.avisoPiso = '';
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      const el = document.getElementById('secao-projecao');
      // A despesa com benefícios é peça das Despesas com Pessoal (8.5): a DRE
      // é repintada junto (pedido do cliente, 2026-11-21 — antes o cartão
      // mudava e a linha ficava com o número de antes). Pessoas e benefícios
      // moram no painel das premissas, que vem no mesmo pacote.
      this.repintarProposta(el);
      el.querySelector(`#beneficio-${inp.dataset.chave}`)?.focus();
    } catch (e) {
      this.avisar(e.message);
      inp.value = inp.dataset.original ?? '';
    } finally {
      inp.classList.remove('celula-gravando');
    }
  },

  /**
   * Recolher e mostrar as subcontas (pedido do cliente, 2026-11-18): para ver
   * como os números FECHAM, a tabela mostra só os blocos (receita, deduções,
   * custos, despesas, resultado…) e esconde as linhas de lançamento.
   *
   * É só visualização — nenhuma conta muda, e as subcontas continuam no DOM
   * (a classe `recolhida` as esconde por CSS), então o que estiver digitado
   * nelas não se perde. A escolha é conveniência de quem olha, por isso fica no
   * `localStorage` (com try/catch: em janela privada ele pode não existir) e
   * sobrevive à troca de negócio e ao recarregar. No dossiê não há botão: o
   * documento sai sempre completo.
   */
  /** O nível 3 aberto em todas as linhas (o botão de cima), lembrado no navegador. */
  nivel3Aberto() {
    if (App.modoDossie) return false;
    if (this.n3Aberto === undefined) {
      try { this.n3Aberto = localStorage.getItem('projecao.nivel3') === '1'; } catch (e) { this.n3Aberto = false; }
    }
    return !!this.n3Aberto;
  },

  /** Abre ou fecha as contas reduzidas de TODAS as linhas. */
  alternarNivel3() {
    this.n3Aberto = !this.nivel3Aberto();
    try { localStorage.setItem('projecao.nivel3', this.n3Aberto ? '1' : '0'); } catch (e) { /* vale só nesta tela */ }
    const el = document.getElementById('secao-projecao');
    el.querySelector('.tabela-projecao')?.classList.toggle('nivel3', this.n3Aberto);
    const b = el.querySelector('#projecao-nivel3');
    if (b) {
      b.setAttribute('aria-pressed', this.n3Aberto ? 'true' : 'false');
      b.textContent = this.n3Aberto ? '▾ Fechar contas reduzidas' : '▸ Abrir contas reduzidas (nível 3)';
    }
  },

  /** Abre ou fecha as contas reduzidas de UMA linha (o ▸ ao lado do nome). */
  alternarReduzidas(codigo, botao) {
    if (!this.reduzidasAbertas) this.reduzidasAbertas = new Set();
    const abrir = !this.reduzidasAbertas.has(codigo);
    if (abrir) this.reduzidasAbertas.add(codigo); else this.reduzidasAbertas.delete(codigo);
    document.querySelectorAll(`.tabela-projecao tr.linha-reduzida[data-pai="${CSS.escape(codigo)}"]`)
      .forEach((tr) => tr.classList.toggle('aberta', abrir));
    if (botao) {
      botao.textContent = abrir ? '▾' : '▸';
      botao.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    }
  },

  estaRecolhido() {
    if (App.modoDossie) return false;
    if (this.recolhido === undefined) {
      try { this.recolhido = localStorage.getItem('projecao.recolhido') === '1'; } catch (e) { this.recolhido = false; }
    }
    return !!this.recolhido;
  },

  botaoNivel() {
    if (App.modoDossie) return '';
    const r = this.estaRecolhido();
    return `<div class="d-flex justify-content-end gap-2 mb-1 d-print-none">
        <button type="button" id="projecao-imprimir-dre" class="btn btn-sm btn-success"
          title="Imprime (ou salva em PDF) a DRE da proposta com todas as subcontas abertas">
          🖨 Imprimir DRE (PDF)
        </button>
        <button type="button" id="projecao-nivel3" class="btn btn-sm btn-outline-secondary"
          aria-pressed="${this.nivel3Aberto() ? 'true' : 'false'}"
          title="Abre as contas reduzidas de todas as linhas, como o nível 3 do Qlik (só o realizado)">
          ${this.nivel3Aberto() ? '▾ Fechar contas reduzidas' : '▸ Abrir contas reduzidas (nível 3)'}
        </button>
        <button type="button" id="projecao-nivel" class="btn btn-sm btn-outline-secondary"
          aria-pressed="${r ? 'true' : 'false'}"
          title="Mostrar só os blocos da DRE, para ver como os números fecham">
          ${r ? '▸ Mostrar subcontas' : '▾ Recolher subcontas'}
        </button>
      </div>`;
  },

  /**
   * Imprime a DRE da proposta ABERTA (pedido do cliente, 2026-11-19: "a
   * possibilidade de imprimir em PDF esta proposta da DRE aberta"): abre todas as
   * subcontas, deixa na página só o bloco da DRE (`body.imprimir-dre`, ver o
   * `@media print` do app.css), chama a caixa de impressão — que é quem salva o
   * PDF — e devolve a tela como estava.
   */
  imprimirDre() {
    const el = document.getElementById('secao-projecao');
    const tab = el?.querySelector('.tabela-projecao');
    if (!tab) return;
    const estava = tab.classList.contains('recolhida');
    tab.classList.remove('recolhida');
    const cab = el.querySelector('#projecao-dre-cabecalho');
    if (cab) cab.querySelector('[data-impresso-em]').textContent = new Date().toLocaleString('pt-BR');
    document.body.classList.add('imprimir-dre');
    const volta = () => {
      document.body.classList.remove('imprimir-dre');
      if (estava) tab.classList.add('recolhida');
      window.removeEventListener('afterprint', volta);
    };
    window.addEventListener('afterprint', volta);
    window.print();
  },

  alternarNivel() {
    this.recolhido = !this.estaRecolhido();
    try { localStorage.setItem('projecao.recolhido', this.recolhido ? '1' : '0'); } catch (e) { /* sem armazenamento: vale só nesta tela */ }
    const el = document.getElementById('secao-projecao');
    el.querySelector('.tabela-projecao')?.classList.toggle('recolhida', this.recolhido);
    const b = el.querySelector('#projecao-nivel');
    if (b) {
      b.setAttribute('aria-pressed', this.recolhido ? 'true' : 'false');
      b.textContent = this.recolhido ? '▸ Mostrar subcontas' : '▾ Recolher subcontas';
    }
  },

  /**
   * "Nenhum negócio ou filial poderá estabelecer meta negativa de resultado
   * líquido" (diretriz de 2027). O servidor recusa o lançamento que PIORA um
   * resultado negativo; aqui se diz, enquanto ele estiver no vermelho, que a
   * proposta não pode ficar assim.
   */
  alertaMetaNegativa(d) {
    if (!d || d.corporativo) return '';
    const l = (this.visao(d).linhas || []).find((x) => x.codigo === '18');
    if (!l || l.proposta === null || l.proposta >= -1) return '';
    return `<div class="alert alert-danger py-2 px-3 small mb-2" id="projecao-meta-negativa">
        <strong>Resultado líquido negativo.</strong> A proposta ${d.filial ? 'desta filial' : 'deste negócio'}
        para ${d.ano_planejamento} está em <strong>−R$ ${this.moeda(Math.abs(l.proposta))}</strong>. A diretriz de ${d.ano_planejamento}
        não admite meta negativa de resultado líquido: reveja a proposta. A linha está destacada na tabela.
      </div>`;
  },

  /**
   * O piso do faturamento: a Receita de Vendas proposta não fica abaixo de IPCA
   * + PIB + crescimento desejado — no negócio e em cada filial (pedidos do
   * cliente de 2026-11-21: "o sistema deve voltar os dados até totalizar o
   * percentual estimado"; "já faça para as filiais também"). Dois casos, a
   * mesma caixa amarela, embaixo da DRE:
   *
   *  - **acabou de digitar abaixo**: o servidor recusou e apagou o valor
   *    (`gravarValor`, `aviso_piso`); a caixa fica até a próxima gravação;
   *  - **já estava abaixo** (uma carga, como o cenário da Pecuária, ou a premissa
   *    que subiu depois): o lançamento continua guardado mas não vale, e a linha
   *    está no mínimo (`aplicar`, `piso_aplicado`, `lancado_abaixo`).
   */
  /**
   * O ⚠ da célula das Despesas com Pessoal abaixo do mínimo, com a conta no
   * título: no negócio, o quadro planejado × o custo com o reajuste; na filial,
   * o quadro atual dela × o custo por pessoa dela × o reajuste.
   */
  avisoMinimoPessoal(l) {
    const r = (v) => `R$ ${this.moeda(Math.abs(Number(v) || 0))}`;
    const quadro = l.quadro_pessoal
      ? ` A filial tem ${l.quadro_pessoal} pessoa(s), a ${r(l.custo_por_pessoa)} por pessoa na projeção de ${this.dados ? this.dados.ano : ''}.`
      : '';
    const titulo = `Abaixo do mínimo: ${r(l.minimo_pessoal)} (pessoas × custo com o reajuste de `
      + `${this.pct((Number(l.minimo_pessoal_percentual) || 0) / 100, 2)}).${quadro} `
      + 'O valor foi gravado; valide-o (redução de quadro, desligamentos já feitos).';
    return `<span class="aviso-minimo-pessoal text-warning me-1" title="${Modal.esc(titulo)}" aria-label="${Modal.esc(titulo)}">⚠</span>`;
  },

  /**
   * A lista do que está abaixo do mínimo das Despesas com Pessoal (pedido do
   * cliente, 2026-11-23): o negócio e as filiais (no modo filial), ou, no
   * Corporativo, por negócio. Não recusa nada — é a lista do que validar.
   */
  alertaPessoalMinimo(d) {
    if (!d) return '';
    const r = (v) => `R$ ${this.moeda(Math.abs(Number(v) || 0))}`;
    const item = (a, nome) => `<li>${Modal.esc(nome)}: ${r(a.proposta)}, mínimo ${r(a.minimo)}
      <span class="text-muted">(${r(a.minimo - a.proposta)} abaixo)</span></li>`;
    let itens = [];
    if (d.corporativo) {
      (d.pessoal_alertas_negocios || []).forEach((n) => {
        n.itens.forEach((a) => itens.push(item(a, a.filial ? `${n.negocio} · filial ${a.filial}` : n.negocio)));
      });
    } else {
      const nomes = {};
      (d.filiais || []).forEach((f) => { nomes[f.id] = `${f.cod_filial} ${f.nome}`; });
      itens = (d.pessoal_alertas || []).map((a) => item(a, a.filial_id ? `Filial ${nomes[a.filial_id] || a.filial_id}` : 'Negócio'));
      // A filial aberta no modo negócio também se avisa (ela não entra na lista,
      // que é do que forma o total, mas a célula dela tem o ⚠).
      const lf = d.filial && (d.filial.linhas || []).find((x) => x.codigo === '8.5');
      if (lf && lf.abaixo_minimo_pessoal && !(d.pessoal_alertas || []).some((a) => a.filial_id === d.filial.id)) {
        itens.push(item({ proposta: lf.proposta, minimo: lf.minimo_pessoal }, `Filial ${nomes[d.filial.id] || d.filial.id}`));
      }
    }
    if (!itens.length) return '';
    return `<div class="alert alert-warning py-2 px-3 small mb-2" id="projecao-pessoal-minimo">
        <strong>⚠ Despesas com Pessoal abaixo do mínimo — validar.</strong>
        O mínimo é o número de pessoas × o custo de hoje com o reajuste de ${d.ano_planejamento}
        (no negócio, o quadro planejado; na filial, o quadro atual dela). Os valores foram gravados.
        <ul class="mb-0 mt-1">${itens.join('')}</ul>
      </div>`;
  },

  alertaPisoFaturamento(d) {
    if (!d || d.corporativo) return '';
    const v = this.visao(d);
    const l = (v.linhas || []).find((x) => x.codigo === '1.1');
    if (!l || !l.projecao) return '';
    const piso = Number(l.piso_percentual) || 0;
    const minimo = `R$ ${this.moeda(l.proposta)}`;
    let texto = this.avisoPiso;
    if (!texto && l.piso_aplicado) {
      // O valor recusado está no formato do motor (com a CBS); a tela mostra sem ela.
      const t = (v.filialId ? (d.filial.tributos_dre || {}) : (d.tributos_dre || {})).taxa || 0;
      const lancado = Number(l.lancado_abaixo) * (1 - t);
      texto = `O lançamento de R$ ${this.moeda(lancado)} na Receita de Vendas${d.filial ? ' desta filial' : ''} ficou abaixo de IPCA + PIB + crescimento desejado e não vale: a linha está no mínimo. O valor continua guardado e volta a valer se as premissas baixarem; para outro valor, lance de novo, igual ou acima do mínimo.`;
    }
    if (!texto) return '';
    return `<div class="alert alert-warning py-2 px-3 small mb-2" id="projecao-piso-faturamento">
        <strong>Faturamento no mínimo.</strong> ${Modal.esc(texto)}
        Mínimo de ${d.ano_planejamento}: <strong>${minimo}</strong> (${this.pct(piso / 100, 2)} sobre a projeção).
      </div>`;
  },

  /**
   * Pontos de milhar ENQUANTO se digita na Proposta (pedido do cliente,
   * 2026-11-19: "500000" não diz a grandeza; "500.000" diz).
   *
   * Só regroupa os pontos que ela mesma pôs: quem digita um PONTO está usando
   * outra convenção (decimal à inglesa, ou o próprio milhar) e, a partir dali,
   * a célula fica como a pessoa escreveu até sair dela — reagrupar "11.43"
   * viraria "1.143" e mudaria o número. Com `%` (o atalho de percentual) não
   * mexe. A vírgula é o decimal e passa intacta; o sinal de menos também.
   * O cursor fica depois do mesmo algarismo em que estava.
   */
  formatarDigitando(inp, ev) {
    if (ev && ev.data && ev.data.includes('.')) inp.dataset.pontoDigitado = '1';
    const v = inp.value;
    if (inp.dataset.pontoDigitado || v.includes('%')) return;
    const m = v.replace(/\./g, '').match(/^\s*(-?)(\d*)(,\d*)?\s*$/);
    if (!m) return;
    const [, sinal, inteiro, decimal = ''] = m;
    const agrupado = inteiro.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const novo = sinal + agrupado + decimal;
    if (novo === v) return;
    // Posição do cursor medida em caracteres que não são ponto.
    const pos = inp.selectionStart ?? v.length;
    const antes = v.slice(0, pos).replace(/\./g, '').length;
    inp.value = novo;
    let i = 0;
    let conta = 0;
    while (i < novo.length && conta < antes) {
      if (novo[i] !== '.') conta++;
      i++;
    }
    try { inp.setSelectionRange(i, i); } catch (e) { /* campo sem seleção: segue */ }
  },

  avisar(msg) {
    const al = document.getElementById('projecao-aviso');
    if (!al) return;
    al.textContent = msg || '';
    al.classList.toggle('d-none', !msg);
  },

  async gravar(inp) {
    const bruto = inp.value.trim();
    if (bruto === (inp.dataset.original ?? '')) return;      // nada mudou
    // Na coluna de Reajuste o número é SEMPRE percentual: ali o `%` é enfeite,
    // e exigi-lo faria a coluna B da planilha recusar o que o cliente digita.
    // Na Proposta o `%` continua valendo — é o atalho da fatia 2.
    const reajuste = inp.classList.contains('celula-reajuste');
    const ehPct = reajuste || bruto.endsWith('%');
    // O ponto é separador de MILHAR num valor e separador DECIMAL num
    // percentual: percentual não tem milhar (a faixa é ±1000%), e quem digita
    // `11.43` quer dizer 11,43 — tirar o ponto ali daria 1.143%.
    const limpo = ehPct
      ? bruto.replace('%', '').replace(',', '.').trim()
      : bruto.replace(/\./g, '').replace(',', '.').trim();
    if (limpo !== '' && !Number.isFinite(Number(limpo))) {
      this.avisar('Valor inválido: informe um número, ou um percentual terminado em %.');
      inp.classList.add('celula-erro');
      inp.value = inp.dataset.original ?? '';
      return;
    }
    inp.classList.remove('celula-erro');
    inp.classList.add('celula-gravando');
    try {
      const r = await App.api('/api/projecao/valor', {
        negocio_id: this.negocioId,
        filial_id: this.filialId,
        conta: inp.dataset.conta,
        entrada: ehPct ? 'PERCENTUAL' : 'VALOR',
        valor: limpo === '' ? null : Number(limpo),
        versao: this.versao,
      });
      this.versao = r.versao;
      // A Receita abaixo do piso volta ao piso no servidor, e a resposta diz
      // por quê (`aviso_piso`): a caixa embaixo da DRE mostra até a próxima
      // gravação.
      this.avisoPiso = r.aviso_piso || '';
      delete r.aviso_piso;
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      // Repinta o CORPO da tabela, não a tela inteira (que mataria o foco de
      // quem está descendo a coluna com Tab), e tudo o que lê a proposta: o
      // painel (Cresc. Total, % Custos, o faturamento mínimo), os alertas e os
      // tributos. Deixá-los parados mostraria dois números discordando.
      const el = document.getElementById('secao-projecao');
      const tbody = document.querySelector('.tabela-projecao tbody');
      this.repintarProposta(el);
      // O pisca verde volta para a célula que a pessoa digitou, e não sempre
      // para a Proposta: quem lançou na coluna de Reajuste veria o verde na
      // célula ao lado, como se o sistema tivesse gravado outra coisa.
      const volta = tbody.querySelector(`[data-conta="${inp.dataset.conta}"] .${
        reajuste ? 'celula-reajuste' : 'celula-proposta'}`);
      if (volta) {
        volta.classList.add('celula-salva');
        setTimeout(() => volta.classList.remove('celula-salva'), 1200);
      }
    } catch (e) {
      this.avisar(e.message);
      inp.classList.add('celula-erro');
      inp.value = inp.dataset.original ?? '';
    } finally {
      inp.classList.remove('celula-gravando');
    }
  },
};

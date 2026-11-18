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
      ${this.avisoFiliaisDefasadas(d)}
      ${this.faixaDoModo(d)}
      ${this.painel(d)}
      ${d.filial ? '' : this.cartaoPessoas(d)}
      ${faltando}
      <div id="projecao-aviso" class="alert alert-danger py-2 px-3 small mb-2 d-none"></div>
      ${this.botaoNivel()}
      <div class="table-responsive tabela-projecao${this.estaRecolhido() ? ' recolhida' : ''}">
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
      <p class="text-muted small">
        Valores em R$. A.V. = análise vertical sobre a Receita Operacional Líquida.
        A proposta é lançada nas <strong>subcontas</strong>; as linhas em negrito são a soma delas.
        Célula vazia vale a projeção (crescimento zero). Proposta e Reajuste são o
        <strong>mesmo lançamento</strong> visto de dois jeitos: a que você digita manda,
        a outra sai em cinza-itálico porque é a conta dela.
        Fonte: ${Modal.esc(d.fonte || 'Qlik')}${d.extraido_em
  ? `, extraído em ${d.extraido_em.split('-').reverse().join('/')}` : ''}.
        Mês sem rateio é mês aberto e não entra como realizado.
      </p>`;
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
    const campo = (chave, rotulo, dica) => (App.modoDossie
      ? `<div class="col-6 col-md-auto">
           <div class="small text-muted" title="${Modal.esc(dica)}">${rotulo}</div>
           <div class="fw-bold">${num(digitadas[chave], 2)}</div>
         </div>`
      : `<div class="col-6 col-md-auto">
        <label class="form-label small text-muted mb-0" for="premissa-${chave}"
          title="${Modal.esc(dica)}">${rotulo}</label>
        <div class="input-group input-group-sm premissa-campo">
          <input type="text" class="form-control celula-premissa" id="premissa-${chave}"
            inputmode="decimal" autocomplete="off" data-chave="${chave}"
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
    const nota = d.filial
      ? `${tres} vêm do negócio e valem para todas as filiais — para alterá-los, volte ao negócio.
          ${noAnoDaProposta ? 'Os três valores lidos ao lado são desta filial.' : ''}`
      : `${tres} são lançados aqui e valem para o negócio e todas as filiais.
          Na proposta de ${d.ano_planejamento}, a <strong>Receita de Vendas</strong> cresce
          <strong>IPCA + PIB + crescimento desejado</strong> e as <strong>Despesas com Pessoal</strong>
          crescem só o percentual das despesas com pessoas — onde a linha não foi lançada.
          A célula digitada sempre manda; as demais linhas ficam na projeção.
          ${noAnoDaProposta
    ? `<strong>Cresc. Real</strong>, <strong>Cresc. Total</strong> e <strong>% Custos</strong>
          são lidos da coluna Proposta — o reajuste de cada linha se lança na própria linha,
          como <code>5%</code>.`
    : ''}`;

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
    return this.expansor('premissas', 'painel-premissas', 'Premissas e benefícios', resumo, `
        ${seletorAno}
        <div class="row g-3 align-items-end">
          ${campo('IPCA', 'IPCA', `Inflação prevista para ${ano}. Vale para o negócio e todas as filiais.`)}
          ${campo('PIB', 'PIB', `Crescimento da economia previsto para ${ano}. Vale para o negócio e todas as filiais.`)}
          ${campo('CRESCIMENTO', 'Crescimento desejado',
    `O crescimento REAL que se deseja para a receita em ${ano}, acima da inflação e da economia. A Receita de `
    + 'Vendas cresce IPCA + PIB + este percentual. Vale para o negócio e todas as filiais.')}
          ${campo('PESSOAS', 'Cresc. despesas com pessoas',
    `O crescimento das despesas com pessoas em ${ano}. Atua SÓ na linha Despesas com Pessoal (8.5): `
    + 'IPCA, PIB e o crescimento desejado não tocam nela. Vale para o negócio e todas as filiais.')}
          ${this.campoBeneficio(d, ano, 'ALIMENTACAO', 'Vale alimentação (por mês)', 'R$',
    'Valor por colaborador, por mês, nos 12 meses — o mesmo padrão para todos os colaboradores.', plano(padrao.ALIMENTACAO))}
          ${this.campoBeneficio(d, ano, 'ASSIDUIDADE', 'Abono assiduidade (por mês)', 'R$',
    'Pago ao colaborador que cumpre os quesitos de assiduidade.', plano(padrao.ASSIDUIDADE))}
          ${this.campoBeneficio(d, ano, 'RESULTADO', 'Abono resultado (por mês)', 'R$',
    'Pago só no mês em que o negócio atinge o resultado planejado.', plano(padrao.RESULTADO))}
          ${abonoTotal}
          ${leituras}
        </div>
        <div class="small text-muted mt-2">${nota}</div>`);
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
          </div>
        </div>`;
  },

  /**
   * Pessoas e benefícios do negócio (pedido do cliente, 2026-11-18): quantos
   * colaboradores são do negócio e quanto ele paga de alimentação e de abono.
   *
   * O quadro vem da DRE — o ÚLTIMO mês do ano atual — e já é de colaboradores
   * EQUIVALENTES: quem divide o tempo entre dois negócios entra em cada um pela
   * sua parte, então cada negócio paga a sua e o custo sai direto de
   * colaboradores × valor. O texto da tela diz isso, porque é a dúvida que um
   * número "inteiro" daria. Os valores e os percentuais são digitados por negócio
   * e por ano (o ano é o do seletor das premissas); vazio vale o padrão, que
   * aparece como o placeholder.
   *
   * Nada daqui entra na proposta: a DRE não tem linha de pessoal, então este é
   * o cartão onde o valor com pessoas é PREVISTO, e não uma conta que mexa nas
   * linhas da tabela.
   */
  cartaoPessoas(d) {
    if (!d.pessoas) return '';
    if (d.corporativo) return this.cartaoPessoasCorporativo(d);
    const ano = this.anoDoPainel(d);
    const calc = d.pessoas.por_ano[ano] || null;
    const dig = d.pessoas.digitados[ano] || {};
    const base = d.pessoas.base;
    const editavel = this.premissaEditavel(d);
    const dinheiro = (v) => `R$ ${Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 })}`;
    const num = (v) => (v === null || v === undefined ? '' : String(v).replace('.', ','));
    const padrao = d.pessoas.padrao || {};
    const campo = (chave, rotulo, unidade, dica, placeholder) => this.campoBeneficio(d, ano, chave, rotulo, unidade, dica, placeholder);

    const quadro = base
      ? `Quadro atual: <strong>${Number(base.colaboradores).toLocaleString('pt-BR')}</strong> colaboradores
         (DRE, ${this.nomeMes(base.mes)}/${base.ano}${base.mes < 12 ? ' — último mês carregado; dezembro ainda não existe' : ''}).`
      : 'Não há quadro de colaboradores carregado para este negócio.';

    const resultado = calc ? `
      <div class="table-responsive mt-2">
        <table class="table table-sm mb-1 tabela-pessoas">
          <thead><tr><th></th><th class="text-end">Por mês</th><th class="text-end">No ano</th></tr></thead>
          <tbody>
            <tr><td>Alimentação (${dinheiro(calc.valores.ALIMENTACAO)} × ${Number(calc.colaboradores).toLocaleString('pt-BR')})</td>
              <td class="text-end">${dinheiro(calc.mensal.alimentacao)}</td><td class="text-end" data-pessoas="alimentacao">${dinheiro(calc.anual.alimentacao)}</td></tr>
            <tr><td>Abono de assiduidade</td>
              <td class="text-end">${dinheiro(calc.mensal.assiduidade)}</td><td class="text-end" data-pessoas="assiduidade">${dinheiro(calc.anual.assiduidade)}</td></tr>
            <tr><td>Abono de resultado <span class="text-muted small">(só nos meses em que o negócio atinge o resultado)</span></td>
              <td class="text-end">${dinheiro(calc.mensal.resultado)}</td><td class="text-end" data-pessoas="resultado">${dinheiro(calc.anual.resultado)}</td></tr>
            <tr class="fw-bold"><td>Despesa prevista com benefícios</td>
              <td class="text-end">${dinheiro(calc.mensal.total)}</td><td class="text-end" data-pessoas="total">${dinheiro(calc.anual.total)}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="small text-muted">
        Se o negócio <strong>nunca</strong> atingir o resultado: <strong data-pessoas="sem_resultado">${dinheiro(calc.sem_resultado_anual)}</strong> no ano.
        Teto (todos cumprem a assiduidade e o resultado vem todo mês): <strong data-pessoas="maximo">${dinheiro(calc.maximo_anual)}</strong>.
      </div>` : '';

    const resumo = calc
      ? `${Number(calc.colaboradores).toLocaleString('pt-BR')} colaboradores · ${dinheiro(calc.anual.total)} no ano`
      : (base ? `${Number(base.colaboradores).toLocaleString('pt-BR')} colaboradores` : 'sem quadro carregado');
    return this.expansor('pessoas', 'painel-pessoas', `Pessoas e benefícios — ${ano}`, resumo, `
        <div class="small text-muted mb-1" id="pessoas-quadro">${quadro}</div>
        <div class="small text-muted mb-2">
          O número já é de colaboradores <strong>equivalentes</strong>: quem divide o tempo entre dois
          negócios (30/70, 50/50) entra em cada um pela sua parte, e cada negócio paga a sua.
          O planejamento de ${d.ano_planejamento} parte do quadro atual.
        </div>
        <div class="row g-3 align-items-end">
          ${campo('COLABORADORES', 'Colaboradores planejados', 'pessoas',
    'Deixe vazio para usar o quadro atual da DRE; digite para planejar contratação ou desligamento.',
    base ? String(base.colaboradores) : '—')}
          ${campo('PCT_ASSIDUIDADE', '% que cumprem a assiduidade', '%',
    'Previsão: que parte dos colaboradores cumpre os quesitos. 100% é o orçamento cheio.', num(padrao.PCT_ASSIDUIDADE))}
          ${campo('PCT_RESULTADO', '% dos meses com resultado', '%',
    'Previsão: em que parte dos meses o negócio atinge o resultado. 100% é o orçamento cheio.', num(padrao.PCT_RESULTADO))}
        </div>
        ${resultado}`);
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
      ${this.cartaoPessoas(d)}
      ${faltando}
      ${this.botaoNivel()}
      <div class="table-responsive tabela-projecao${this.estaRecolhido() ? ' recolhida' : ''}">
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
      </p>`;
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
    const resumo = `real + realista: receita ${this.mi(v['1'].real_realista)} · resultado ${this.mi(v['16'].real_realista)}
      (${this.mi(v['16'].realista_x_plano, true)} contra o plano)`;
    return this.expansor('visao', 'painel-visao-ano', `${d.ano}: realizado, planejado e visão realista`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 tabela-visao-ano">
            <thead>${this.colunasVisao(d)}</thead>
            <tbody>
              ${this.linhaVisao('Receita operacional bruta', d.visao_ano['1'], '')}
              ${this.linhaVisao('Resultado líquido', d.visao_ano['16'], '')}
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
    const resumo = t['1'] && t['16']
      ? `real + realista: receita ${this.mi(t['1'].real_realista)} · resultado ${this.mi(t['16'].real_realista)}
        (${this.mi(t['16'].realista_x_plano, true)} contra o plano)`
      : '';
    return this.expansor('visao', 'painel-visao-ano', `${d.ano}: realizado, planejado e visão realista, por negócio`, resumo, `
        ${tabela('1', 'Receita operacional bruta')}
        ${tabela('16', 'Resultado líquido')}
        ${this.notaVisao(d)}`);
  },

  /**
   * O DESAFIO da Controladoria para o ano da proposta (pedido do cliente,
   * 2026-11-18: "os 2% são margem líquida, carregue o desafio na projeção").
   * Só leitura: é referência decidida e congelada (`negocio_desafio`), e fica ao
   * lado do que a proposta lançada dá hoje, com a diferença. Na filial não
   * aparece — o desafio é do negócio.
   */
  cartaoDesafio(d) {
    if (d.corporativo) return this.cartaoDesafioCorporativo(d);
    const x = d.desafio;
    if (!x || d.filial) return '';
    const des = x.desafio;
    const pro = x.proposta;
    const linha = (rotulo, k, chave) => {
      const a = des[k];
      const b = pro[k];
      const dif = a === null || b === null ? null : b - a;
      return `<tr data-desafio="${chave}">
          <td>${rotulo}</td>
          <td class="text-end fw-bold">${this.moeda(a)}</td>
          <td class="text-end">${b === null ? '—' : this.moeda(b)}</td>
          <td class="text-end ${this.corDif(dif)}">${dif === null ? '—' : this.comSinal(dif)}</td>
        </tr>`;
    };
    const difMargem = des.margem === null || pro.margem === null ? null : pro.margem - des.margem;
    const resumo = `resultado ${this.mi(des.resultado)}${des.margem === null ? '' : ` · margem ${this.pct(des.margem / 100, 2)}`}
      — a proposta dá ${this.mi(pro.resultado)}${pro.margem === null ? '' : ` (${this.pct(pro.margem / 100, 2)})`}`;
    return this.expansor('desafio', 'painel-desafio', `Desafio ${x.ano} da Controladoria`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-desafio">
            <thead><tr><th></th>
              <th class="text-end">Desafio<br><span class="fw-normal text-muted small">${x.ano}</span></th>
              <th class="text-end">Proposta<br><span class="fw-normal text-muted small">lançada hoje</span></th>
              <th class="text-end">Diferença<br><span class="fw-normal text-muted small">proposta − desafio</span></th>
            </tr></thead>
            <tbody>
              ${linha('Receita operacional bruta', 'receita_bruta', 'receita_bruta')}
              ${linha('Receita operacional líquida', 'receita_liquida', 'receita_liquida')}
              ${linha('Resultado líquido', 'resultado', 'resultado')}
              <tr data-desafio="margem"><td>Margem líquida <span class="text-muted small">(sobre a ROL)</span></td>
                <td class="text-end fw-bold">${this.pct(des.margem === null ? null : des.margem / 100, 2)}</td>
                <td class="text-end">${this.pct(pro.margem === null ? null : pro.margem / 100, 2)}</td>
                <td class="text-end ${difMargem === null ? '' : (difMargem >= 0 ? 'text-success' : 'text-danger')}">${difMargem === null ? '—'
  : `${difMargem > 0 ? '+' : ''}${difMargem.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} p.p.`}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="small text-muted" id="desafio-nota">
          <strong>${Modal.esc(des.nota)}</strong> ${this.notaDesafio(x)}
        </div>`);
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
          <td class="text-end fw-bold">${des ? this.moeda(des.resultado) : '—'}</td>
          <td class="text-end">${des ? m2(des.margem) : '—'}</td>
          <td class="text-end">${pro.resultado === null ? '—' : this.moeda(pro.resultado)}</td>
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
    return this.expansor('desafio', 'painel-desafio', `Desafio ${x.ano} da Controladoria, por negócio`, resumo, `
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1 tabela-desafio">
            <thead><tr><th></th>
              <th class="text-end">Receita bruta<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Resultado<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Margem<br><span class="fw-normal text-muted small">desafio</span></th>
              <th class="text-end">Resultado<br><span class="fw-normal text-muted small">proposta</span></th>
              <th class="text-end">Margem<br><span class="fw-normal text-muted small">proposta</span></th>
              <th class="text-end">Diferença<br><span class="fw-normal text-muted small">proposta − desafio</span></th>
            </tr></thead>
            <tbody>${linhas}${linha('Total dos negócios', t.desafio, t.proposta, 'fw-bold table-light', 'data-negocio-desafio="total"')}</tbody>
          </table>
        </div>
        <p class="small fw-bold mb-1 mt-2">Meta da direção: ${this.pct(x.meta_margem / 100, 0)} de margem líquida</p>
        <ul class="small mb-1 meta-margem">${meta(t.desafio, 'desafio')}${meta(t.proposta, 'proposta')}</ul>
        <div class="small text-muted">${this.notaDesafio(x)} Cada negócio vê o seu desafio na própria tela.</div>`);
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
  EXPANSORES_PADRAO: { visao: false, desafio: true, premissas: false, pessoas: false },

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
    const CALCULADAS = { 6: '5', 8: '7', 17: '16' };
    const podeLancar = this.editavelAqui(d);
    const mostrarDistribuir = !d.filial && d.filiais.length && d.modo === 'NEGOCIO';

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

      // A proposta: célula de digitar quando a linha é subconta; o número
      // derivado quando é bloco; "—" quando não há regra para derivá-la.
      let proposta;
      if (editavel) {
        proposta = `<input type="text" class="celula-valor celula-proposta${
          l.proposta_origem === 'PERCENTUAL' ? ' celula-espelhada' : ''}" inputmode="decimal" data-sinal
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
          pior ? ' celula-pior' : ''}" inputmode="decimal" data-sinal
          autocomplete="off" value="${Modal.esc(this.textoReajuste(l))}"
          placeholder="${l.proposta_padrao !== null && l.proposta_padrao !== undefined ? this.pct(l.proposta_padrao / 100, 2) : '0,00%'}"
          data-conta="${Modal.esc(l.codigo)}"
          aria-label="Reajuste sobre a projeção para ${Modal.esc(l.nome)}">`;
      } else {
        reajuste = `<span class="${pior ? 'text-danger' : 'text-muted'}">${cresc}</span>`;
      }

      return `<tr class="${bloco ? 'fw-bold linha-bloco' : 'linha-subconta'}"
        data-conta="${Modal.esc(l.codigo)}" data-nivel="${l.nivel}"
        data-origem="${Modal.esc(l.proposta_origem || '')}">
        <td class="${bloco ? '' : 'ps-4 text-muted'}">${Modal.esc(l.nome)}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.realizado)}">${valor(l, 'realizado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projetado)}">${valor(l, 'projetado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projecao)}">${valor(l, 'projecao')}</td>
        <td class="text-end text-muted small">${av(l)}</td>
        <td class="text-end celula-proposta-td ${
  ['PROJECAO', 'PREMISSA'].includes(l.proposta_origem) ? 'proposta-por-omissao' : ''}">${proposta}</td>
        <td class="text-end text-muted small celula-av-proposta">${avProp(l)}</td>
        <td class="text-end small celula-reajuste-td">${reajuste}</td>
        ${mostrarDistribuir ? this.celulaDistribuir(l) : ''}
      </tr>`;
    }).join('');
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
    const nivel = el.querySelector('#projecao-nivel');
    if (nivel && virgem(nivel)) nivel.addEventListener('click', () => this.alternarNivel());
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
        el.querySelector('.painel-premissas').outerHTML = this.painel(this.dados);
        // Pessoas e benefícios acompanham o ano das premissas.
        const cartao = el.querySelector('.painel-pessoas');
        if (cartao) cartao.outerHTML = this.cartaoPessoas(this.dados);
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

  async gravarPremissa(inp) {
    const bruto = inp.value.trim();
    if (bruto === (inp.dataset.original ?? '')) return;
    const limpo = bruto.replace('%', '').replace(',', '.').trim();
    if (limpo !== '' && !Number.isFinite(Number(limpo))) {
      this.avisar('Premissa inválida: informe um número.');
      inp.value = inp.dataset.original ?? '';
      return;
    }
    // Percentual de crescimento é positivo: recusa aqui, com a explicação, em vez
    // de mandar ao servidor o que ele vai recusar.
    if (limpo !== '' && Number(limpo) < 0) {
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
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      // O painel inteiro é repintado: mexer no IPCA muda o Cresc. Real, que é
      // conta do servidor. A tabela não precisa, mas o repinte é barato e
      // mantém a tela e o banco dizendo a mesma coisa.
      const el = document.getElementById('secao-projecao');
      const painel = el.querySelector('.painel-premissas');
      painel.outerHTML = this.painel(this.dados);
      this.ligar(el);
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
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      const el = document.getElementById('secao-projecao');
      el.querySelector('.painel-pessoas').outerHTML = this.cartaoPessoas(this.dados);
      // Vale e abono moram no painel das premissas: ele é repintado junto.
      el.querySelector('.painel-premissas').outerHTML = this.painel(this.dados);
      this.ligar(el);
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
    return `<div class="d-flex justify-content-end mb-1">
        <button type="button" id="projecao-nivel" class="btn btn-sm btn-outline-secondary"
          aria-pressed="${r ? 'true' : 'false'}"
          title="Mostrar só os blocos da DRE, para ver como os números fecham">
          ${r ? '▸ Mostrar subcontas' : '▾ Recolher subcontas'}
        </button>
      </div>`;
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
      this.dados = { ...this.dados, ...r };
      this.avisar('');
      // Repinta só o CORPO da tabela: a tela inteira mataria o foco de quem
      // está descendo a coluna com Tab, e o cabeçalho não mudou.
      const el = document.getElementById('secao-projecao');
      const tbody = document.querySelector('.tabela-projecao tbody');
      tbody.innerHTML = this.corpo(this.dados);
      // O painel lê a proposta: mexer numa linha muda o Cresc. Total e o
      // % Custos. Deixá-lo parado mostraria dois números discordando na mesma
      // tela — que é o defeito que esta seção inteira existe para não ter.
      el.querySelector('.painel-premissas').outerHTML = this.painel(this.dados);
      this.ligar(el);
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

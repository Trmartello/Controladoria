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
 * - **Célula vazia é "sem proposta", e vale a PROJEÇÃO** (crescimento zero),
 *   não zero — zero seria uma proposta de fechar a linha. A tela marca em
 *   cinza o que está ali por omissão.
 */
const SecaoProjecao = {
  negocioId: null,
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

  /** O que vai DENTRO da célula editável: o que foi digitado, ou nada. */
  textoLancado(l) {
    if (l.proposta_origem === 'PERCENTUAL') {
      return `${Number(l.proposta_lancado).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
    }
    if (l.proposta_origem === 'VALOR') return this.moeda(l.proposta_lancado);
    return '';
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
    this.versao = d.versao;
    this.dados = d;

    el.innerHTML = this.html(d);
    this.ligar(el);
  },

  html(d) {
    const opcoes = d.negocios.map((n) => `<option value="${n.id}"${
      Number(n.id) === Number(d.negocio_id) ? ' selected' : ''}>${Modal.esc(n.rotulo)}</option>`).join('');

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

    return `
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="mb-0 me-auto">Projeção de Resultados — ${d.ano}</h1>
        <select id="projecao-negocio" class="form-select form-select-sm" style="max-width:18rem"
          aria-label="Negócio">${opcoes}</select>
      </div>
      <p class="text-muted mb-1">
        Realizado de <strong>jan a ${this.nomeMes(d.mes_fechado_ate)}</strong> (meses fechados) e
        projetado de <strong>${this.nomeMes(d.mes_fechado_ate + 1)} a dez</strong> pelo orçamento
        lançado. A coluna <strong>Proposta</strong> é a meta de <strong>${d.ano_planejamento}</strong>:
        digite o valor (<code>1.500.000</code>) ou o crescimento sobre a projeção (<code>12%</code>).
      </p>
      <p class="mb-2">${assinatura}</p>
      ${this.painel(d)}
      ${faltando}
      <div id="projecao-aviso" class="alert alert-danger py-2 px-3 small mb-2 d-none"></div>
      <div class="table-responsive tabela-projecao">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th>Conta</th>
            <th class="text-end">Realizado<br><span class="fw-normal text-muted small">jan–${this.nomeMes(d.mes_fechado_ate)}</span></th>
            <th class="text-end">Projetado<br><span class="fw-normal text-muted small">${this.nomeMes(d.mes_fechado_ate + 1)}–dez</span></th>
            <th class="text-end">Projeção<br><span class="fw-normal text-muted small">do ano</span></th>
            <th class="text-end">A.V.<br><span class="fw-normal text-muted small">sobre a ROL</span></th>
            <th class="text-end">Proposta<br><span class="fw-normal text-muted small">${d.ano_planejamento}</span></th>
            <th class="text-end">Cresc.<br><span class="fw-normal text-muted small">sobre a projeção</span></th>
          </tr></thead>
          <tbody>${this.corpo(d)}</tbody>
        </table>
      </div>
      <p class="text-muted small">
        Valores em R$. A.V. = análise vertical sobre a Receita Operacional Líquida.
        A proposta é lançada nas <strong>subcontas</strong>; as linhas em negrito são a soma delas.
        Célula vazia vale a projeção (crescimento zero).
        Fonte: ${Modal.esc(d.fonte || 'Qlik')}${d.extraido_em
  ? `, extraído em ${d.extraido_em.split('-').reverse().join('/')}` : ''}.
        Mês sem rateio é mês aberto e não entra como realizado.
      </p>`;
  },

  /**
   * O painel de indicadores, como a planilha o monta NO CONSOLIDADO: IPCA e
   * PIB digitados, o resto lido da proposta.
   *
   *   Cresc. Total = proposta da receita ÷ projeção − 1   (`B14`)
   *   Cresc. Real  = Cresc. Total − IPCA − PIB            (`B13`)
   *   % Custos     = proposta dos custos ÷ projeção − 1   (`B17`)
   *
   * No bloco de CADA FILIAL a planilha faz o contrário — ali os percentuais
   * são digitados e a proposta sai deles (`AD10 = AA10 * Z14%`). É o mesmo
   * painel servindo a dois papéis conforme o nível em que se planeja, e o
   * segundo papel chega com o modo filial (fatia 3).
   */
  painel(d) {
    const p = d.premissas || {};
    const num = (v, casas = 2) => (v === null || v === undefined
      ? '—'
      : `${Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas })}%`);
    const campo = (chave, rotulo) => `
      <div class="col-6 col-md-auto">
        <label class="form-label small text-muted mb-0" for="premissa-${chave}">${rotulo}</label>
        <div class="input-group input-group-sm premissa-campo">
          <input type="text" class="form-control celula-premissa" id="premissa-${chave}"
            inputmode="decimal" autocomplete="off" data-chave="${chave}"
            value="${p[chave] === null || p[chave] === undefined ? '' : String(p[chave]).replace('.', ',')}"
            placeholder="—" ${d.pode_editar ? '' : 'disabled'}>
          <span class="input-group-text">%</span>
        </div>
      </div>`;
    const leitura = (valor, rotulo, dica) => `
      <div class="col-6 col-md-auto">
        <div class="small text-muted" title="${Modal.esc(dica)}">${rotulo}</div>
        <div class="fw-bold" data-premissa="${Modal.esc(rotulo)}">${num(valor, 2)}</div>
      </div>`;

    return `
      <div class="card mb-3 painel-premissas"><div class="card-body py-2 px-3">
        <div class="row g-3 align-items-end">
          ${campo('IPCA', 'IPCA')}
          ${campo('PIB', 'PIB')}
          ${leitura(p.CRESC_REAL, 'Cresc. Real',
    'Crescimento total menos IPCA e PIB — o que sobra depois da inflação e da economia. '
    + 'Precisa dos dois lançados para existir.')}
          ${leitura(p.CRESC_TOTAL, 'Cresc. Total',
    'A proposta da receita contra a projeção do ano. Lido, não lançado: '
    + 'o crescimento se lança na linha da Receita de Vendas.')}
          ${leitura(p.PCT_CUSTOS, '% Custos',
    'A proposta dos custos contra a projeção do ano. Lido da linha Custos das Vendas.')}
        </div>
        <div class="small text-muted mt-2">
          IPCA e PIB são premissas lançadas aqui; <strong>Cresc. Real</strong>,
          <strong>Cresc. Total</strong> e <strong>% Custos</strong> são lidos da coluna Proposta —
          o reajuste de cada linha se lança na própria linha, como <code>5%</code>.
        </div>
      </div></div>`;
  },

  corpo(d) {
    const linhaRol = d.linhas.find((l) => l.codigo === '3') || {};
    const rol = linhaRol.projecao || 0;
    const CALCULADAS = { 6: '5', 8: '7', 17: '16' };

    const valor = (linha, campo) => {
      if (!linha.percentual) return this.moeda(linha[campo]);
      const base = linhaRol[campo] || 0;
      if (!base) return '—';
      const alvo = d.linhas.find((x) => x.codigo === CALCULADAS[linha.codigo]);
      return alvo ? this.pct(alvo[campo] / base) : '—';
    };
    const av = (linha) => (linha.percentual || !rol ? '' : this.pct(linha.projecao / rol));

    return d.linhas.map((l) => {
      const bloco = l.nivel === 1;
      const negativo = (v) => (v < 0 ? 'text-danger' : '');
      const editavel = l.nivel === 2 && !l.percentual && d.pode_editar;

      // A proposta: célula de digitar quando a linha é subconta; o número
      // derivado quando é bloco; "—" quando não há regra para derivá-la.
      let proposta;
      if (editavel) {
        proposta = `<input type="text" class="celula-valor celula-proposta" inputmode="decimal"
          autocomplete="off" value="${Modal.esc(this.textoLancado(l))}"
          placeholder="${this.moeda(l.projecao)}"
          data-conta="${Modal.esc(l.codigo)}"
          aria-label="Proposta de ${d.ano_planejamento} para ${Modal.esc(l.nome)}">`;
      } else if (l.proposta === null || l.proposta === undefined) {
        proposta = `<span class="text-muted" title="${Modal.esc(
          'Linha que o Qlik entrega pronta e que o sistema não sabe recompor — '
          + 'enquanto a regra dela não estiver no modelo, ela não recebe proposta.'
        )}">—</span>`;
      } else if (l.percentual) {
        const alvo = d.linhas.find((x) => x.codigo === CALCULADAS[l.codigo]);
        const baseProp = (d.linhas.find((x) => x.codigo === '3') || {}).proposta || 0;
        proposta = alvo && baseProp ? this.pct(alvo.proposta / baseProp) : '—';
      } else {
        proposta = this.moeda(l.proposta);
      }

      const cresc = (!l.percentual && l.proposta !== null && l.projecao)
        ? this.pct(l.proposta / l.projecao - 1) : '';

      return `<tr class="${bloco ? 'fw-bold linha-bloco' : 'linha-subconta'}"
        data-conta="${Modal.esc(l.codigo)}" data-nivel="${l.nivel}"
        data-origem="${Modal.esc(l.proposta_origem || '')}">
        <td class="${bloco ? '' : 'ps-4 text-muted'}">${Modal.esc(l.nome)}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.realizado)}">${valor(l, 'realizado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projetado)}">${valor(l, 'projetado')}</td>
        <td class="text-end ${l.percentual ? '' : negativo(l.projecao)}">${valor(l, 'projecao')}</td>
        <td class="text-end text-muted small">${av(l)}</td>
        <td class="text-end celula-proposta-td ${
  l.proposta_origem === 'PROJECAO' ? 'proposta-por-omissao' : ''}">${proposta}</td>
        <td class="text-end small ${cresc && l.proposta < l.projecao ? 'text-danger' : 'text-muted'}">${cresc}</td>
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
    const sel = el.querySelector('#projecao-negocio');
    if (sel && virgem(sel)) {
      sel.addEventListener('change', (ev) => {
        this.negocioId = Number(ev.target.value);
        this.carregar();
      });
    }
    // A gravação vem do `blur`, como nas células de Metas: quem percorre a
    // coluna com Tab grava linha a linha sem tirar a mão do teclado. O Enter
    // tira o foco, e o `blur` faz o resto — um caminho só de gravação.
    el.querySelectorAll('.celula-proposta').forEach((inp) => {
      inp.dataset.original = inp.value;
      if (!virgem(inp)) return;
      inp.addEventListener('blur', () => this.gravar(inp));
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
        if (ev.key === 'Escape') { inp.value = inp.dataset.original ?? inp.value; inp.blur(); }
      });
      inp.dataset.original = inp.value;
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
    inp.classList.add('celula-gravando');
    try {
      const r = await App.api('/api/projecao/premissa', {
        negocio_id: this.negocioId,
        chave: inp.dataset.chave,
        valor: limpo === '' ? null : Number(limpo),
      });
      this.versao = r.versao;
      Object.assign(this.dados, {
        linhas: r.linhas, premissas: r.premissas, versao: r.versao,
        atualizado_em: r.atualizado_em, atualizado_por: r.atualizado_por,
      });
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

  avisar(msg) {
    const al = document.getElementById('projecao-aviso');
    if (!al) return;
    al.textContent = msg || '';
    al.classList.toggle('d-none', !msg);
  },

  async gravar(inp) {
    const bruto = inp.value.trim();
    if (bruto === (inp.dataset.original ?? '')) return;      // nada mudou
    const ehPct = bruto.endsWith('%');
    const limpo = bruto.replace('%', '').replace(/\./g, '').replace(',', '.').trim();
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
        conta: inp.dataset.conta,
        entrada: ehPct ? 'PERCENTUAL' : 'VALOR',
        valor: limpo === '' ? null : Number(limpo),
        versao: this.versao,
      });
      this.versao = r.versao;
      this.dados.linhas = r.linhas;
      this.dados.versao = r.versao;
      this.dados.atualizado_em = r.atualizado_em;
      this.dados.atualizado_por = r.atualizado_por;
      this.dados.premissas = r.premissas;
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
      const volta = tbody.querySelector(`[data-conta="${inp.dataset.conta}"] .celula-proposta`);
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

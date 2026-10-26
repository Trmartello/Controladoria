// Metas — Fase 6: métricas-âncora por horizonte + tabela plurianual meta × real.

const MESES_CURTOS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun',
  'jul', 'ago', 'set', 'out', 'nov', 'dez'];

const SecaoMetas = {
  plan: null,
  dados: null,

  /**
   * Qual ano está aberto mês a mês.
   *
   * `undefined` = ninguém escolheu ainda, e vale o **ano em execução do ciclo**
   * (que vem do servidor); `null` = alguém fechou de propósito. A diferença
   * existe para o fechamento sobreviver às repinturas da seção — sem ela, o ano
   * reabriria sozinho a cada troca de ano ou gravação, e o controle pareceria
   * quebrado.
   *
   * **Não vai para o `localStorage`**, e isso é decisão, não esquecimento: o ano
   * em execução é do ciclo justamente para ser o mesmo para todo mundo (ver
   * `App\Services\Metas`). Guardar aqui qual ano cada navegador deixou aberto
   * faria cada pessoa voltar a um ano diferente do mesmo plano — que é o que a
   * decisão do cliente recusou.
   */
  anoAberto: undefined,

  /**
   * O ano em execução do plano, como o servidor o resolveu (`ciclo.ano_execucao`
   * ou o corrente). É ele, e só ele, que se lança mês a mês — nos demais anos a
   * tabela tem um campo por ano, e nenhum mês.
   */
  anoExecucao: null,

  /**
   * Como o ano é formado a partir dos meses, escrito para ler na linha.
   * Catálogo único: o rótulo da tabela, o do cadastro e o do aviso saem daqui.
   */
  ROTULO_AGREGACAO: {
    SOMA: 'soma dos meses',
    MEDIA: 'média dos meses',
    ULTIMO: 'último mês',
  },

  /**
   * A agregação que a UNIDADE sugere — e só ela.
   *
   * Percentual, índice, "x" e prazo em dias não somam: doze margens de 14%
   * somadas dão 168%, um número que não existe e que não parece errado na tela.
   * Esses a unidade denuncia, e o cadastro já abre com a média marcada.
   *
   * O que a unidade **não** sabe dizer é saldo de fluxo: estoque, dívida e
   * capital de giro são R$ como o faturamento é R$, e valem pelo ÚLTIMO mês, não
   * pela soma. Por isso a função devolve `null` nesses casos em vez de arriscar:
   * sugerir soma para um saldo seria errar com cara de acerto, que é pior do que
   * não sugerir.
   */
  agregacaoSugerida(unidade) {
    const u = String(unidade || '').toLocaleLowerCase('pt-BR').trim();
    if (!u) return null;
    if (u.includes('%') || u === 'x' || u.includes('índice') || u.includes('indice')
      || u.includes('dias')) return 'MEDIA';
    return null;
  },

  /**
   * O aviso de agregação suspeita, na própria linha.
   *
   * Existe por um motivo datado: os indicadores cadastrados antes de a coluna
   * existir nasceram todos com SOMA, inclusive os percentuais — e a conferência
   * disso, sem este selo, significa abrir o cadastro de um por um. Aqui ela é
   * uma varredura de olho, e o conserto são dois cliques.
   *
   * Ele **não corrige nada sozinho**: a agregação é decisão de quem cadastra, e
   * há caso legítimo de percentual que soma (pontos de participação ganhos no
   * ano, por exemplo). Avisar e deixar decidir é diferente de consertar por
   * conta própria e avisar depois — e, nas metas, o conserto errado só
   * apareceria meses adiante, no fechamento do ano.
   */
  avisoAgregacao(ind) {
    const sugerida = this.agregacaoSugerida(ind.unidade);
    if (!sugerida || sugerida === ind.agregacao) return '';
    return `<span class="badge text-bg-warning ms-1" title="Indicador em «${Modal.esc(ind.unidade)}»
      somando os meses: doze margens somadas dão um número que não existe. O recomendado aqui é
      ${this.ROTULO_AGREGACAO[sugerida]} — confira no cadastro (✎).">revisar o ano</span>`;
  },

  fmt(v) {
    return v === null || v === undefined || v === ''
      ? '—'
      : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  },

  /**
   * Qual par meta × real de um indicador se mostra — e se ele foi atingido.
   *
   * Mora aqui, numa função só, porque a **Matriz de Execução** (na Cascata) lê
   * pela mesma regra: escrita duas vezes, ela divergiria, e as duas telas
   * passariam a dizer números diferentes do mesmo indicador.
   *
   * O ano de referência é o do ÚLTIMO real lançado; sem real nenhum, o da
   * PRIMEIRA meta. Não é "o ano corrente", e a diferença não é teórica: o ciclo
   * semeado vai de 2027 a 2035 e as metas começam em 2027 — pela regra do ano
   * corrente, em 2026 toda linha mostraria "—" e as duas telas nasceriam vazias.
   */
  metaReal(i) {
    const reais = i.reais || [];
    const metas = i.metas || [];
    const real = reais[reais.length - 1] || null;
    const ano = real ? real.ano : (metas[0] || {}).ano ?? null;
    const meta = metas.find((m) => m.ano == ano) || null;
    const atingiu = real && meta
      ? (i.sentido === 'MENOR_MELHOR'
          ? Number(real.valor) <= Number(meta.valor)
          : Number(real.valor) >= Number(meta.valor))
      : null;
    return { real, meta, ano, atingiu };
  },

  /**
   * A célula da tabela — leitura e lançamento no MESMO lugar.
   *
   * Era um par de botões (M e R) abrindo um modal com a série inteira: para
   * corrigir um ano era preciso abrir o formulário, achar o campo no meio de
   * dez e salvar tudo. Agora clica-se no número e digita-se por cima, que é o
   * gesto da planilha de onde estes dados vêm.
   *
   * `type=text` com `inputmode=decimal`, e não `type=number`: o campo numérico
   * do navegador devolve `.value` VAZIO quando o conteúdo não casa com o locale
   * dele — quem digitasse "13,5" em português gravaria nada, sem nada na tela
   * dizendo isso. O filtro de digitação é o mesmo do campo de dinheiro
   * (`Modal.ligarMoedas`), **com negativos permitidos**: meta de resultado
   * financeiro e de dívida é negativa, e recusar o sinal aqui seria recusar o
   * número certo.
   *
   * **Célula derivada não se digita.** No REAL, o ano cujo valor vem dos meses
   * é só leitura: deixá-lo editável aceitaria um número que a leitura ignora na
   * linha seguinte — o pior tipo de campo, o que parece ter funcionado.
   */
  celula(ind, tipo, ano, entrada, mes = 0) {
    const anual = mes === 0;
    const bruto = anual
      ? (entrada ? entrada.valor : null)
      : (entrada && entrada.meses ? entrada.meses[mes] ?? null : null);
    // **Um caminho de lançamento por ano** (decisão do cliente): o ano em
    // execução é lançado MÊS A MÊS e a célula do ano mostra o total, sem campo;
    // os demais anos recebem o valor anual e não têm meses. Dois campos para o
    // mesmo ano — o anual e os doze — é que produziam os dois números do mesmo
    // ano se contradizendo na mesma linha.
    const derivada = anual && ano === this.anoExecucao;
    const classe = `valor${derivada ? ' celula-derivada' : ''}${anual ? '' : ' celula-mes'}`;
    // Os marcadores ficam no `<td>` e não no campo: a célula derivada não tem
    // campo nenhum, e é justamente ela que precisa ser reencontrada e repintada
    // quando um mês muda.
    const marcas = `data-celula-ind="${ind.id}" data-tipo="${tipo}" data-ano="${ano}" data-mes="${mes}"`;
    if (!App.podeEditar() || derivada) {
      const dica = derivada
        ? ' title="Total dos meses deste ano — para mudar, lance no mês"' : '';
      return `<td class="${classe}" ${marcas}${dica}>${this.fmt(bruto)}</td>`;
    }
    const quando = anual ? ano : `${MESES_CURTOS[mes - 1]}/${ano}`;
    return `<td class="${classe}" ${marcas}>
      <input type="text" class="celula-valor" inputmode="decimal" autocomplete="off"
        value="${Modal.esc(this.fmt(bruto))}" data-bruto="${bruto ?? ''}"
        data-ind="${ind.id}" data-tipo="${tipo}" data-ano="${ano}" data-mes="${mes}"
        aria-label="${tipo === 'META' ? 'Meta' : 'Realizado'} de ${quando} — ${Modal.esc(ind.nome)}">
    </td>`;
  },

  /**
   * As células de um ano numa linha.
   *
   * O ano em EXECUÇÃO sai em doze meses e, **no fim**, o total ("abrir mês a
   * mês e totalizar o ano no final"); qualquer outro ano é uma célula só, com o
   * valor anual. Recolher o ano em execução mostra só o total dele — é leitura,
   * e o lançamento continua sendo no mês.
   */
  celulasDoAno(ind, tipo, ano, entrada) {
    if (ano !== this.anoAberto) return this.celula(ind, tipo, ano, entrada);
    return MESES_CURTOS.map((_, i) => this.celula(ind, tipo, ano, entrada, i + 1)).join('')
      + this.celula(ind, tipo, ano, entrada);
  },

  /**
   * O cabeçalho de um ano.
   *
   * **Só o ano em execução abre mês a mês** — os demais são um número e nada
   * mais. Oferecer os doze meses em todo ano convidaria a lançar mensal num ano
   * que o plano trata como anual, e aí o mesmo ano teria duas verdades.
   */
  cabecalhoAno(ano) {
    if (ano !== this.anoExecucao) return `<th class="col-ano">${ano}</th>`;
    if (ano !== this.anoAberto) {
      return `<th class="col-ano"><button type="button" class="btn-ano" data-abrir-ano="${ano}"
        title="Abrir ${ano} mês a mês" aria-expanded="false">${ano} <span aria-hidden="true">▸</span></button></th>`;
    }
    return MESES_CURTOS.map((m) => `<th class="col-mes">${m}</th>`).join('')
      + `<th class="col-ano col-total"><button type="button" class="btn-ano" data-fechar-ano="${ano}"
        title="Recolher os meses de ${ano} (o lançamento continua sendo no mês)"
        aria-expanded="true">${ano} <span aria-hidden="true">▾</span></button></th>`;
  },

  /** Os cartões de métrica-âncora, repintados sozinhos a cada gravação. */
  cartoes(horizontes, indicadores) {
    return horizontes.map((h) => {
      const ancoras = indicadores.filter((i) => Number(i.metrica_ancora) && i.horizonte_id == h.id);
      const itens = ancoras.map((i) => {
        const { real, meta, atingiu } = this.metaReal(i);
        return `<div class="d-flex justify-content-between align-items-baseline gap-2 mt-1">
          <span class="small">${Modal.esc(i.nome)} <span class="text-muted">(${Modal.esc(i.unidade)})</span></span>
          <span class="text-nowrap small ${atingiu === null ? 'text-muted' : atingiu ? 'text-success fw-bold' : 'text-danger fw-bold'}">
            ${real ? `${this.fmt(real.valor)} <span class="text-muted">(${real.ano})</span>` : 'sem real'}
            ${meta ? ` / meta ${this.fmt(meta.valor)}` : ''}
          </span>
        </div>`;
      }).join('');
      return `<div class="col-md-4">
        <div class="card h-100">
          <div class="card-body py-2 px-3">
            <strong>${Modal.esc(h.nome)} · ${Modal.esc(h.tema)}</strong>
            <span class="badge badge-ancora ms-1">${ancoras.length} âncora${ancoras.length === 1 ? '' : 's'}</span>
            ${itens || '<div class="text-muted small mt-1">Nenhuma métrica-âncora definida para este horizonte.</div>'}
          </div>
        </div>
      </div>`;
    }).join('');
  },

  /** O recado de erro da gravação em célula, que não tem modal para mostrá-lo. */
  avisar(texto) {
    const caixa = document.querySelector('[data-aviso-metas]');
    if (!caixa) return;
    caixa.textContent = texto || '';
    caixa.classList.toggle('d-none', !texto);
  },

  /**
   * Grava UMA célula e repinta o que ela muda.
   *
   * O servidor devolve a série inteira do indicador já recalculada, e é dela
   * que a tela se serve: refazer a agregação aqui seria uma segunda cópia da
   * regra, no idioma errado. Recarregar a seção também não serve — mataria o
   * foco de quem está percorrendo a linha com Tab.
   */
  async gravarCelula(inp) {
    const texto = inp.value.trim().replace(',', '.');
    const vazio = texto === '';
    if (!vazio && !Number.isFinite(Number(texto))) {
      this.avisar('Valor inválido: informe um número.');
      inp.value = this.fmt(inp.dataset.bruto || null);
      inp.classList.add('celula-erro');
      return;
    }
    // A gravação vem do `blur`, com o campo já sem foco: quem escreve o valor
    // formatado de volta é ela, nos dois desfechos. Sem isso a célula ficaria
    // mostrando o que foi digitado ("45,5") enquanto a tabela ao lado mostra
    // número formatado — ou, pior, o valor antigo depois de um erro.
    const ind = this.dados.indicadores.find((i) => i.id == inp.dataset.ind);
    const corpo = {
      planejamento_id: this.plan.id,
      tipo: inp.dataset.tipo,
      mes: Number(inp.dataset.mes),
      valores: { [inp.dataset.ano]: vazio ? null : Number(texto) },
    };
    inp.classList.remove('celula-erro');
    inp.classList.add('celula-gravando');
    try {
      const r = await App.api(`/api/indicadores/${ind.id}/valores`, corpo);
      ind.metas = r.metas;
      ind.reais = r.reais;
      inp.dataset.bruto = vazio ? '' : String(Number(texto));
      inp.value = this.fmt(vazio ? null : Number(texto));
      this.avisar('');
      inp.classList.add('celula-salva');
      setTimeout(() => inp.classList.remove('celula-salva'), 1200);
      // O lançamento mexe no cartão do horizonte (meta × real da âncora) e,
      // quando é de mês, no total do ano. Os dois são repintados a partir da
      // série nova; a célula em foco não é tocada.
      const cartoes = document.querySelector('[data-cartoes-metas]');
      if (cartoes) cartoes.innerHTML = this.cartoes(this.dados.horizontes, this.dados.indicadores);
      this.repintarDerivadas(ind, inp);
    } catch (e) {
      this.avisar(e.message);
      inp.classList.add('celula-erro');
      inp.value = this.fmt(inp.dataset.bruto || null);
    } finally {
      inp.classList.remove('celula-gravando');
    }
  },

  /**
   * As células do indicador que o lançamento mudou SEM serem digitadas: o total
   * do ano quando o que mudou foi um mês. A que está em foco fica de fora — ela
   * tem o que a pessoa acabou de escrever.
   */
  repintarDerivadas(ind, foco) {
    [...document.querySelectorAll(`[data-celula-ind="${ind.id}"]`)].forEach((td) => {
      if (td.contains(foco)) return;
      const { tipo, ano, mes } = td.dataset;
      const serie = tipo === 'META' ? ind.metas : ind.reais;
      const entrada = serie.find((x) => x.ano == ano) || null;
      // A célula é REDESENHADA, não só reescrita: lançar o primeiro mês do
      // realizado transforma a célula do ano em derivada (campo vira texto) e
      // apagar o último a traz de volta. Só trocar o texto deixaria um campo
      // editável mostrando um número que a leitura ignora.
      td.outerHTML = this.celula(ind, tipo, Number(ano), entrada, Number(mes));
      // O campo novo chega sem ouvinte nenhum: `outerHTML` descarta o elemento
      // antigo, e com ele os ouvintes. Sem religar, a célula redesenhada parava
      // de gravar — e nada na tela diria isso.
      const novo = document.querySelector(
        `[data-celula-ind="${ind.id}"][data-tipo="${tipo}"][data-ano="${ano}"][data-mes="${mes}"] .celula-valor`);
      if (novo) this.ligarCelula(novo);
    });
  },

  async carregar() {
    const el = document.getElementById('secao-metas');
    if (!App.contextoParams()) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    this.dados = await App.api(`/api/indicadores?planejamento_id=${this.plan.id}`);
    const { ciclo, horizontes, indicadores } = this.dados;
    const anos = [];
    for (let a = Number(ciclo.ano_base); a <= Number(ciclo.ano_fim); a++) anos.push(a);

    // Qual ano nasce aberto: o EM EXECUÇÃO, que vem do ciclo (o servidor já
    // resolve o vazio). A validação contra `anos` não é zelo: trocar de ciclo
    // ou de negócio recarrega esta seção, e um ano aberto que não existe na
    // tabela nova deixaria a tela sem nenhum ano expandido e com um `colspan`
    // contando doze colunas que ninguém desenhou.
    const execucao = Number(this.dados.ano_execucao);
    this.anoExecucao = anos.includes(execucao) ? execucao : null;
    if (this.anoAberto === undefined
      || (this.anoAberto !== null && this.anoAberto !== this.anoExecucao)) {
      this.anoAberto = this.anoExecucao;
    }
    const abertas = this.anoAberto === null ? 0 : MESES_CURTOS.length;

    // ---- Cartões de métrica-âncora por horizonte
    const cartoes = this.cartoes(horizontes, indicadores);

    // ---- Tabela plurianual (linha Meta + linha Real por indicador)
    const linhas = indicadores.map((i) => {
      const metas = Object.fromEntries(i.metas.map((m) => [m.ano, m]));
      const reais = Object.fromEntries(i.reais.map((r) => [r.ano, r]));
      const revisada = i.metas.some((m) => Number(m.versao_meta) > 1);
      // Os botões M e R saíram: lançar e corrigir acontecem na célula. O que
      // sobra aqui é o que a célula NÃO faz — revisar a meta criando versão
      // nova (gesto deliberado, nunca consequência de uma correção de
      // digitação), editar o cadastro e excluir.
      const acoes = App.podeEditar() ? `
        <button class="btn btn-sm btn-outline-success" data-revisar="${i.id}"
          title="Revisar metas — grava uma versão nova e preserva a anterior">⟳ Revisar</button>
        <button class="btn btn-sm btn-outline-secondary" data-editar="${i.id}" title="Editar indicador">✎</button>
        <button class="btn btn-sm btn-outline-danger" data-excluir="${i.id}" title="Excluir">×</button>` : '';
      return `
        <tr>
          <td rowspan="2" class="align-middle celula-nome">
            <strong>${Modal.esc(i.nome)}</strong>
            ${Number(i.metrica_ancora) ? '<span class="badge badge-ancora ms-1">âncora</span>' : ''}
            ${revisada ? '<span class="badge text-bg-warning ms-1" title="Meta revisada — versões anteriores preservadas">rev.</span>' : ''}
            <div class="small text-muted">${Modal.esc(i.unidade)} · ${i.sentido === 'MENOR_MELHOR' ? '↓ menor melhor' : '↑ maior melhor'}
              ${i.horizonte_nome ? ` · ${Modal.esc(i.horizonte_nome)}` : ''}
              · ano: ${this.ROTULO_AGREGACAO[i.agregacao] || this.ROTULO_AGREGACAO.SOMA}
              ${this.avisoAgregacao(i)}</div>
          </td>
          <td class="small text-muted celula-rotulo">Meta</td>
          ${anos.map((a) => this.celulasDoAno(i, 'META', a, metas[a] || null)).join('')}
          <td rowspan="2" class="align-middle text-nowrap">${acoes}</td>
        </tr>
        <tr class="linha-real">
          <td class="small text-muted celula-rotulo">Real</td>
          ${anos.map((a) => this.celulasDoAno(i, 'REAL', a, reais[a] || null)).join('')}
        </tr>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Metas · Indicadores — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-ind">+ Novo indicador</button>' : ''}
      </div>
      <p class="text-muted">Métricas-âncora dão o foco de cada horizonte; a tabela plurianual acompanha
      meta × real de ${ciclo.ano_base} a ${ciclo.ano_fim}.
      ${this.anoExecucao ? `<strong>${this.anoExecucao}</strong> é o ano em execução: lança-se mês a mês e
        o ano é o total dos meses. Nos demais anos, o lançamento é o valor anual.` : ''}${App.podeEditar()
        ? ' Clique no número para lançar ou corrigir; <strong>⟳ Revisar</strong> grava uma versão nova da meta, preservando a anterior.'
        : ' Revisões de meta preservam as versões anteriores.'}</p>
      <div class="alert alert-danger d-none" data-aviso-metas role="alert"></div>
      <div class="row g-3 mb-3" data-cartoes-metas>${cartoes}</div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle tabela-metas">
          <thead><tr>
            <th class="col-indicador">Indicador</th><th class="col-rotulo"></th>
            ${anos.map((a) => this.cabecalhoAno(a)).join('')}
            <th></th>
          </tr></thead>
          <tbody>${linhas || `<tr><td colspan="${anos.length + abertas + 3}" class="text-muted">Nenhum indicador cadastrado.</td></tr>`}</tbody>
        </table>
      </div>`;

    // Abrir e fechar o ano vale para TODO MUNDO, inclusive o perfil de leitura:
    // é jeito de ler, não de escrever. Por isso a fiação vem antes da guarda de
    // edição logo abaixo.
    el.querySelectorAll('[data-abrir-ano]').forEach((b) => b.addEventListener('click', () => {
      this.anoAberto = Number(b.dataset.abrirAno);
      this.carregar();
    }));
    el.querySelectorAll('[data-fechar-ano]').forEach((b) => b.addEventListener('click', () => {
      // `null`, e não `undefined`: fechar é escolha, e tem de sobreviver às
      // repinturas da seção. Com `undefined` o ano em execução reabriria
      // sozinho na gravação seguinte.
      this.anoAberto = null;
      this.carregar();
    }));

    if (!App.podeEditar()) return;
    document.getElementById('btn-novo-ind').addEventListener('click', () => this.modalIndicador(null));
    el.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () =>
      this.modalIndicador(indicadores.find((i) => i.id == b.dataset.editar))));
    el.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', async () => {
      // O vínculo com a cascata é recente (Matriz de Execução) e é justamente o
      // que ninguém espera perder: some a linha da matriz que dizia que aquela
      // escolha era medida. A escolha em si fica — quem sai é a medida.
      const i = indicadores.find((x) => x.id == b.dataset.excluir);
      const anos = (i?.metas || []).length + (i?.reais || []).length;
      if (!confirm(Vinculos.aviso(`Excluir o indicador «${i?.nome || ''}»?`, {
        some: [
          Vinculos.quantos(anos, 'valor lançado (meta ou real)', 'valores lançados (metas e reais)'),
          Vinculos.quantos((i?.cascatas || []).length,
            'vínculo com escolha da cascata', 'vínculos com escolhas da cascata'),
        ],
        nota: 'Não dá para desfazer.',
      }))) return;
      await App.api(`/api/indicadores/${b.dataset.excluir}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));
    el.querySelectorAll('[data-revisar]').forEach((b) => b.addEventListener('click', () =>
      this.modalRevisarMetas(indicadores.find((i) => i.id == b.dataset.revisar), anos)));

    el.querySelectorAll('.celula-valor').forEach((inp) => this.ligarCelula(inp));
  },

  /**
   * Liga UMA célula. Precisa ser por célula, e não uma varredura só no fim do
   * `carregar()`, porque a tabela troca células de forma sem repintar a seção:
   * lançar o primeiro mês de um ano transforma a célula anual do realizado em
   * derivada (de campo para texto), e o caminho inverso a traz de volta. Quem
   * faz essa troca é `repintarDerivadas`, e o campo novo chega sem ouvinte
   * nenhum — uma célula muda e para de gravar, em silêncio.
   */
  ligarCelula(inp) {
    if (inp.dataset.ligado) return;
    inp.dataset.ligado = '1';
    // O mesmo filtro de digitação do campo de dinheiro, com NEGATIVOS
    // permitidos: meta de resultado financeiro e de dívida é negativa. A raiz é
    // a própria célula, que tem este campo e mais nenhum.
    Modal.ligarMoedas(inp.parentElement, { seletor: '.celula-valor', negativos: true });
    {
      // Com foco, o número CRU (sem separador de milhar): editar "1.500,50" é
      // pisar em cima da formatação, e a primeira tecla apagaria um número
      // certo. Sem foco, ele volta formatado, que é como se lê uma tabela.
      inp.addEventListener('focus', () => {
        inp.value = Modal.moedaBr(inp.dataset.bruto || '');
        inp.select();
      });
      // Grava ao SAIR do campo, e só se alguma tecla mexeu nele. Não dá para
      // usar `change` aqui, e a razão é a armadilha desta tela: o filtro de
      // digitação cancela a entrada original (`preventDefault`) e escreve por
      // `setRangeText` — para o navegador o campo foi alterado por PROGRAMA, e
      // valor alterado por programa não dispara `change` ao perder o foco.
      // A gravação simplesmente não acontecia, sem erro em lugar nenhum.
      // Marcar a sujeira no `input` (que o próprio filtro emite) e gravar no
      // `blur` dá o mesmo comportamento que se queria do `change`: uma
      // gravação por campo deixado, e nenhuma a cada tecla.
      inp.addEventListener('input', () => { inp.dataset.sujo = '1'; });
      inp.addEventListener('blur', () => {
        if (inp.dataset.sujo === '1') {
          delete inp.dataset.sujo;
          this.gravarCelula(inp);
          return;
        }
        inp.value = this.fmt(inp.dataset.bruto || null);
      });
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
        // Esc desfaz o que está sendo digitado — sem gravar. Limpar a marca de
        // sujeira ANTES do blur é o que impede a gravação do valor desfeito.
        if (ev.key === 'Escape') {
          delete inp.dataset.sujo;
          inp.value = Modal.moedaBr(inp.dataset.bruto || '');
          inp.blur();
        }
      });
    }
  },

  /**
   * As escolhas da cascata como itens marcáveis: selo com a célula
   * (driver × horizonte, e o eixo quando é abertura) e o texto da escolha
   * inteiro.
   *
   * `lista_marcavel` e não `multiselect`: a escolha da cascata é uma frase, não
   * um rótulo — precisa ser LIDA antes de marcada —, e o `multiselect` do
   * navegador exige Ctrl, que não existe no celular (ver CLAUDE.md).
   */
  opcoesCascata(cascata) {
    const { horizontes, drivers, eixos, escolhas } = cascata;
    const nome = (lista, id) => lista.find((x) => x.id == id)?.nome || '';
    return escolhas
      // A ordem é a da matriz — horizonte, depois driver —, não a do banco:
      // quem vem marcar já leu a cascata nessa ordem na tela ao lado.
      .slice()
      .sort((a, b) => (horizontes.findIndex((h) => h.id == a.horizonte_id)
          - horizontes.findIndex((h) => h.id == b.horizonte_id))
        || (drivers.findIndex((d) => d.id == a.driver_id)
          - drivers.findIndex((d) => d.id == b.driver_id))
        || Number(a.eixo_id || 0) - Number(b.eixo_id || 0))
      .map((e) => ({
        valor: String(e.id),
        texto: e.escolha,
        selo: `${nome(drivers, e.driver_id)} × ${nome(horizontes, e.horizonte_id)}`,
        selo2: e.eixo_id ? `Eixo · ${nome(eixos, e.eixo_id)}` : 'Síntese',
      }));
  },

  /**
   * O modal é `async` porque a cascata é buscada AQUI, no clique, e não junto da
   * seção: quem abre Metas para conferir uma série não precisa da cascata
   * inteira no caminho. É o mesmo desenho de `SecaoCascata.abrirModalCelula`,
   * que busca os fatores da SWOT ao abrir.
   */
  async modalIndicador(ind) {
    const opcoesHorizonte = [{ valor: '', rotulo: '(sem horizonte)' }].concat(
      this.dados.horizontes.map((h) => ({ valor: h.id, rotulo: `${h.nome} · ${h.tema}` })));
    // Cascata vazia não é erro: o indicador continua cadastrável sem vínculo —
    // o campo é que some, em vez de aparecer como uma lista sem nada dentro.
    const cascata = await App.api(`/api/cascata?planejamento_id=${this.plan.id}`).catch(() => null);
    const opcoesCascata = cascata ? this.opcoesCascata(cascata) : [];
    Modal.abrir({
      titulo: ind ? 'Editar indicador' : 'Novo indicador',
      url: ind ? `/api/indicadores/${ind.id}` : '/api/indicadores',
      valores: ind
        ? { ...ind, horizonte_id: ind.horizonte_id ?? '', metrica_ancora: Number(ind.metrica_ancora),
            cascatas: (ind.cascatas || []).map(String), planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, unidade: 'R$ mil', agregacao: 'SOMA', cascatas: [] },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'nome', rotulo: 'Indicador', ajuda: 'Ex.: Margem bruta, Cobertura de juros, Armazenagem própria' },
        { nome: 'unidade', rotulo: 'Unidade', ajuda: 'R$ mil, %, x, ton...' },
        { nome: 'sentido', rotulo: 'Sentido', tipo: 'select', opcoes: [
          { valor: 'MAIOR_MELHOR', rotulo: 'Maior é melhor' },
          { valor: 'MENOR_MELHOR', rotulo: 'Menor é melhor' },
        ]},
        // Como 12 meses viram um ano. A pergunta só aparece quando alguém lança
        // mês a mês, mas a resposta é do INDICADOR: somar margem percentual dá
        // um número que não existe, e ele não parece errado na tela.
        { nome: 'agregacao', rotulo: 'No ano, os meses…', tipo: 'select', opcoes: [
          { valor: 'SOMA', rotulo: 'Somam (R$, toneladas, quantidades)' },
          { valor: 'MEDIA', rotulo: 'Viram média (%, índices, x)' },
          { valor: 'ULTIMO', rotulo: 'Valem o último mês (saldos: estoque, dívida)' },
        ], ajuda: 'Totaliza o ano quando o indicador é lançado mês a mês. A unidade sugere, '
          + 'mas não decide: estoque e dívida são R$ como o faturamento e valem pelo último mês, '
          + 'não pela soma.' },
        { nome: 'horizonte_id', rotulo: 'Horizonte de referência', tipo: 'select', opcoes: opcoesHorizonte },
        { nome: 'metrica_ancora', rotulo: 'Métrica-âncora (destaque no painel do horizonte)', tipo: 'checkbox' },
        ...(opcoesCascata.length ? [{
          nome: 'cascatas', rotulo: 'Escolhas da cascata que este indicador mede',
          tipo: 'lista_marcavel', opcoes: opcoesCascata,
          ajuda: 'É o que liga a MEDIDA à DECISÃO: marcadas aqui, a escolha e o indicador '
            + 'aparecem na mesma linha da Matriz de Execução, na Cascata. Pode ficar em branco.',
        }] : []),
      ],
      // A unidade sugere a agregação enquanto ninguém a escolheu à mão, e só no
      // indicador NOVO: no já cadastrado, a agregação é decisão tomada — mexer
      // nela porque alguém corrigiu a grafia da unidade seria trocar o critério
      // do ano pelas costas de quem abriu o formulário para outra coisa.
      aoMudar: (d, raiz) => {
        const sel = raiz.querySelector('#campo-agregacao');
        if (!sel) return;
        if (!sel.dataset.ligado) {
          sel.dataset.ligado = '1';
          sel.addEventListener('change', () => { sel.dataset.tocado = '1'; });
        }
        if (ind || sel.dataset.tocado) return;
        const sugerida = this.agregacaoSugerida(d.unidade);
        // Atribuição direta não emite evento: não há laço com o `aoMudar`.
        if (sugerida && sel.value !== sugerida) sel.value = sugerida;
      },
      aoSalvar: () => this.carregar(),
    });
  },

  /**
   * Revisar metas — a série inteira de uma vez, criando VERSÃO NOVA.
   *
   * É o que a célula editável de propósito não faz. Na célula, digitar corrige
   * a versão corrente: é o caminho de quem errou um número. Aqui é o outro
   * gesto, o de quem mudou de meta — e ele preserva a anterior, que é o que o
   * selo "rev." passa a anunciar na linha.
   *
   * Por isso `nova_versao` já vem MARCADA: quem abriu este formulário veio
   * revisar. Desmarcá-la corrige a versão corrente, como a célula faria.
   *
   * O realizado não tem equivalente: ele não é versionado (o que aconteceu não
   * se revisa), e lançá-lo é digitar na célula.
   */
  modalRevisarMetas(ind, anos) {
    const serie = Object.fromEntries(ind.metas.map((m) => [m.ano, m.anual ?? m.valor]));
    const valores = { planejamento_id: this.plan.id, tipo: 'META', nova_versao: true };
    const campos = [
      { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
      { nome: 'tipo', rotulo: '', tipo: 'hidden' },
    ];
    for (const ano of anos) {
      valores[`ano_${ano}`] = serie[ano] ?? '';
      campos.push({ nome: `ano_${ano}`, rotulo: String(ano), tipo: 'number' });
    }
    campos.push({ nome: 'nova_versao', rotulo: 'Gravar como revisão (preserva a versão anterior)', tipo: 'checkbox',
      ajuda: 'Desmarcado, corrige a versão atual — o mesmo que digitar na célula da tabela.' });
    Modal.abrir({
      titulo: `Revisar metas — ${ind.nome} (${ind.unidade})`,
      url: `/api/indicadores/${ind.id}/valores`,
      valores,
      campos,
      aoSalvar: () => this.carregar(),
      // Reagrupa os campos de ano no corpo JSON esperado pela API. Sem `mes`:
      // a revisão é do ANO — os meses dele são a distribuição, e quem os lança
      // é a célula.
      transformar: (d) => ({
        planejamento_id: d.planejamento_id,
        tipo: d.tipo,
        nova_versao: !!d.nova_versao,
        valores: Object.fromEntries(anos.map((ano) => [ano, d[`ano_${ano}`]])),
      }),
    });
  },
};

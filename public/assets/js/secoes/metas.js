// Metas — Fase 6: métricas-âncora por horizonte + tabela plurianual meta × real.

const SecaoMetas = {
  plan: null,
  dados: null,

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
  celula(ind, tipo, ano, entrada) {
    const bruto = entrada ? entrada.valor : null;
    const derivada = tipo === 'REAL' && entrada && entrada.meses_total !== null;
    const classe = `valor${derivada ? ' celula-derivada' : ''}`;
    // Os marcadores ficam no `<td>` e não no campo: a célula derivada não tem
    // campo nenhum, e é justamente ela que precisa ser reencontrada e repintada
    // quando um mês muda.
    const marcas = `data-celula-ind="${ind.id}" data-tipo="${tipo}" data-ano="${ano}" data-mes="0"`;
    if (!App.podeEditar() || derivada) {
      const dica = derivada
        ? ` title="Vem dos meses lançados (${this.fmt(entrada.meses_total)}) — abra o ano para lançar mês a mês"` : '';
      return `<td class="${classe}" ${marcas}${dica}>${this.fmt(bruto)}</td>`;
    }
    // A diferença entre a meta do ano e a soma dos meses é DITA, não corrigida:
    // quem decide se o ano ou a distribuição está errada é quem planeja.
    const difere = tipo === 'META' && entrada && entrada.meses_total !== null
      && Number(entrada.meses_total) !== Number(entrada.valor);
    return `<td class="${classe}" ${marcas}>
      <input type="text" class="celula-valor" inputmode="decimal" autocomplete="off"
        value="${Modal.esc(this.fmt(bruto))}" data-bruto="${bruto ?? ''}"
        data-ind="${ind.id}" data-tipo="${tipo}" data-ano="${ano}" data-mes="0"
        aria-label="${tipo === 'META' ? 'Meta' : 'Realizado'} de ${ano} — ${Modal.esc(ind.nome)}">
      ${difere ? `<span class="difere" title="Os meses somam ${this.fmt(entrada.meses_total)}">≠</span>` : ''}
    </td>`;
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
    document.querySelectorAll(`[data-celula-ind="${ind.id}"]`).forEach((td) => {
      const { tipo, ano, mes } = td.dataset;
      const serie = tipo === 'META' ? ind.metas : ind.reais;
      const entrada = serie.find((x) => x.ano == ano) || null;
      const valor = Number(mes) === 0
        ? (entrada ? entrada.valor : null)
        : (entrada && entrada.meses ? entrada.meses[mes] ?? null : null);
      const campo = td.querySelector('input');
      if (campo) {
        if (campo === foco) return;
        campo.dataset.bruto = valor ?? '';
        campo.value = this.fmt(valor);
      } else {
        td.textContent = this.fmt(valor);
      }
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
          <td rowspan="2" class="align-middle">
            <strong>${Modal.esc(i.nome)}</strong>
            ${Number(i.metrica_ancora) ? '<span class="badge badge-ancora ms-1">âncora</span>' : ''}
            ${revisada ? '<span class="badge text-bg-warning ms-1" title="Meta revisada — versões anteriores preservadas">rev.</span>' : ''}
            <div class="small text-muted">${Modal.esc(i.unidade)} · ${i.sentido === 'MENOR_MELHOR' ? '↓ menor melhor' : '↑ maior melhor'}
              ${i.horizonte_nome ? ` · ${Modal.esc(i.horizonte_nome)}` : ''}</div>
          </td>
          <td class="small text-muted">Meta</td>
          ${anos.map((a) => this.celula(i, 'META', a, metas[a] || null)).join('')}
          <td rowspan="2" class="align-middle text-nowrap">${acoes}</td>
        </tr>
        <tr class="linha-real">
          <td class="small text-muted">Real</td>
          ${anos.map((a) => this.celula(i, 'REAL', a, reais[a] || null)).join('')}
        </tr>`;
    }).join('');

    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Metas · Indicadores — ${Modal.esc(App.rotuloContexto())}</h1>
        ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-ind">+ Novo indicador</button>' : ''}
      </div>
      <p class="text-muted">Métricas-âncora dão o foco de cada horizonte; a tabela plurianual acompanha
      meta × real de ${ciclo.ano_base} a ${ciclo.ano_fim}.${App.podeEditar()
        ? ' Clique no número para lançar ou corrigir; <strong>⟳ Revisar</strong> grava uma versão nova da meta, preservando a anterior.'
        : ' Revisões de meta preservam as versões anteriores.'}</p>
      <div class="alert alert-danger d-none" data-aviso-metas role="alert"></div>
      <div class="row g-3 mb-3" data-cartoes-metas>${cartoes}</div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle tabela-metas">
          <thead><tr>
            <th style="min-width:220px">Indicador</th><th></th>
            ${anos.map((a) => `<th class="col-ano">${a}</th>`).join('')}
            <th></th>
          </tr></thead>
          <tbody>${linhas || `<tr><td colspan="${anos.length + 3}" class="text-muted">Nenhum indicador cadastrado.</td></tr>`}</tbody>
        </table>
      </div>`;

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

    // O mesmo filtro de digitação do campo de dinheiro, com NEGATIVOS
    // permitidos: meta de resultado financeiro e de dívida é negativa.
    Modal.ligarMoedas(el, { seletor: '.celula-valor', negativos: true });
    el.querySelectorAll('.celula-valor').forEach((inp) => {
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
    });
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
        ], ajuda: 'Usado para totalizar o ano quando o indicador é lançado mês a mês.' },
        { nome: 'horizonte_id', rotulo: 'Horizonte de referência', tipo: 'select', opcoes: opcoesHorizonte },
        { nome: 'metrica_ancora', rotulo: 'Métrica-âncora (destaque no painel do horizonte)', tipo: 'checkbox' },
        ...(opcoesCascata.length ? [{
          nome: 'cascatas', rotulo: 'Escolhas da cascata que este indicador mede',
          tipo: 'lista_marcavel', opcoes: opcoesCascata,
          ajuda: 'É o que liga a MEDIDA à DECISÃO: marcadas aqui, a escolha e o indicador '
            + 'aparecem na mesma linha da Matriz de Execução, na Cascata. Pode ficar em branco.',
        }] : []),
      ],
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

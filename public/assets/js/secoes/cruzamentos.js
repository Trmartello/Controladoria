// Cruzamentos da SWOT (TOWS) — a quinta análise do diagnóstico.
//
// A SWOT descreve; o cruzamento decide. Cada linha aqui liga um fator INTERNO
// (força ou fraqueza) a um EXTERNO (oportunidade ou ameaça) e diz o que fazer
// com esse encontro. O bloco em que ela cai — atacar, defender, reforçar,
// proteger — é CONSEQUÊNCIA do par, calculada no servidor: aqui a tela apenas
// antecipa o resultado enquanto o usuário escolhe.
//
// Não existe grade clicável de força × oportunidade, e é decisão, não falta:
// com seis fatores por quadrante seriam 36 células por bloco, e o material
// mostra que na prática se escolhem três. A grade convidaria a preencher tudo.

const SecaoCruzamentos = {
  secaoId: 'secao-cruzamentos',

  /**
   * Os quatro blocos, na ordem do material. O `tipo` é o que o servidor grava;
   * `interno`/`externo` são as categorias que o produzem — a mesma tabela que
   * `CruzamentoController::TIPOS`, só que lida ao contrário (do bloco para o
   * par) para a tela filtrar as listas e antecipar o resultado.
   */
  BLOCOS: [
    { tipo: 'ATACAR',   interno: 'FORCA',    externo: 'OPORTUNIDADE',
      rotulo: 'Forças × Oportunidades', verbo: 'atacar',   cor: '#007a45' },
    { tipo: 'DEFENDER', interno: 'FORCA',    externo: 'AMEACA',
      rotulo: 'Forças × Ameaças',       verbo: 'defender', cor: '#2c7fb8' },
    { tipo: 'REFORCAR', interno: 'FRAQUEZA', externo: 'OPORTUNIDADE',
      rotulo: 'Fraquezas × Oportunidades', verbo: 'reforçar', cor: '#b08d4f' },
    { tipo: 'PROTEGER', interno: 'FRAQUEZA', externo: 'AMEACA',
      rotulo: 'Fraquezas × Ameaças',    verbo: 'proteger', cor: '#8f3b3b' },
  ],

  bloco(tipo) {
    return this.BLOCOS.find((b) => b.tipo === tipo) || null;
  },

  /**
   * O caminho do cruzamento para o plano de ação, nos MESMOS três estados e com
   * a mesma aparência do fator da SWOT (`Diag.seloPlanoAcao`): encaminhar,
   * aguardando (que desfaz), e "Virou ação ↗", que navega até a ação.
   *
   * A cópia visual é de propósito — é o mesmo gesto, na mesma etapa do trabalho.
   * O que muda é só a rota, porque a origem é outra.
   *
   * O "Virou ação" NÃO oferece desfazer: dali em diante quem manda é a ação, e
   * desfazer aqui a deixaria no plano sem origem nenhuma. O servidor recusa do
   * mesmo jeito — este é o aviso antes da recusa, não no lugar dela. E ele
   * existe para quem só lê também: o caminho de volta não é privilégio de quem
   * edita.
   */
  seloAcao(c) {
    if (c.desdobramento_id) {
      return `<button type="button" class="badge selo-link text-bg-success"
        data-ir-acao="${c.desdobramento_id}" title="Ver a ação no plano">Virou ação ↗</button>`;
    }
    if (!App.podeEditar()) return '';
    if (c.acao_em) {
      return `<button type="button" class="badge selo-link text-bg-secondary" data-tirar-acao-cruz="${c.id}"
        title="Aguardando alocação em Projetos — clique para tirar da fila">Aguardando ação</button>`;
    }
    return `<button class="btn btn-sm btn-outline-primary" data-plano-acao-cruz="${c.id}"
      title="Encaminhar para o plano de ação">→ Plano de ação</button>`;
  },

  /** O bloco que nasce de um par de categorias (o mesmo cálculo do servidor). */
  blocoDoPar(catInterna, catExterna) {
    return this.BLOCOS.find((b) => b.interno === catInterna && b.externo === catExterna) || null;
  },

  async carregar() {
    const base = await Diag.preparar('secao-cruzamentos');
    if (!base) return;
    const { el, plan, ano } = base;

    const [fatores, cruzamentos] = await Promise.all([
      App.api(`/api/fatores?planejamento_id=${plan.id}&etapa=SWOT&ano=${ano}`),
      App.api(`/api/cruzamentos?planejamento_id=${plan.id}&ano=${ano}`),
    ]);
    const editar = App.podeEditar();
    const internos = fatores.filter((f) => f.categoria === 'FORCA' || f.categoria === 'FRAQUEZA');
    const externos = fatores.filter((f) => f.categoria === 'OPORTUNIDADE' || f.categoria === 'AMEACA');
    // Sem os dois lados não há par possível: a tela precisa dizer isso antes de
    // oferecer um "+ Novo" que abriria um formulário sem nada para escolher.
    const podeCruzar = internos.length > 0 && externos.length > 0;

    const seloFator = (id, categoria, descricao) => {
      const cor = Diag.CORES_QUADRANTE[categoria] || '#007a45';
      const rotulo = Diag.QUADRANTES[categoria] || categoria;
      // O selo leva ao fator na SWOT — o caminho de volta que a Coleta já tem.
      // O texto vai num `<span>` próprio: o corte de uma linha é feito com
      // `line-clamp`, e o Chrome não o aplica no `<button>`, que impõe o
      // próprio contexto de layout.
      return `<button type="button" class="badge selo-link selo-cruz-fator"
        style="color:${cor};background:${cor}1f" data-ir-swot="${id}" data-cat-swot="${categoria}"
        title="${Modal.esc(descricao)}"><span class="selo-cruz-texto">${rotulo}: ${
        Modal.esc(descricao)}</span></button>`;
    };

    const coluna = (b) => {
      const itens = cruzamentos.filter((c) => c.tipo === b.tipo);
      const cartoes = itens.map((c) => `
        <div class="card mb-2 card-cruzamento" data-card-cruzamento="${c.id}">
          <div class="card-body py-2 px-3">
            <div class="fw-bold small mb-1">${Modal.esc(c.rotulo)}</div>
            <div class="selos-cruzamento">
              ${seloFator(c.fator_interno_id, c.interno_categoria, c.interno_descricao)}
              ${seloFator(c.fator_externo_id, c.externo_categoria, c.externo_descricao)}
            </div>
            <div class="small texto-fator mt-1">${Modal.esc(c.estrategia)}</div>
            <div class="botoes-fator d-flex gap-1 mt-1 align-items-center flex-wrap">
              ${this.seloAcao(c)}
              ${editar ? `<span class="ms-auto d-flex gap-1">
                <button class="btn btn-sm btn-outline-secondary" data-editar-cruz="${c.id}"
                  title="Editar" aria-label="Editar">✎</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-cruz="${c.id}"
                  title="Excluir" aria-label="Excluir">×</button>
              </span>` : ''}
            </div>
          </div>
        </div>`).join('');

      const add = editar && podeCruzar
        ? `<button class="btn btn-sm btn-add-cat ms-auto" style="--cor-cat:${b.cor}"
             data-add-bloco="${b.tipo}" title="Adicionar em ${b.rotulo}"
             aria-label="Adicionar em ${b.rotulo}">+</button>` : '';

      return `<div class="col-md-6" data-coluna-categoria="${b.tipo}">
        <div class="p-2 rounded caixa-coluna"
          style="--tinta-coluna:${b.cor}18; border-top: 3px solid ${b.cor}">
          ${RelatorioAnalise.bloco({
            cabecalho: `<div class="cabecalho-coluna d-flex align-items-center mb-2">
              <span class="fw-bold small text-uppercase" style="color:${b.cor}">${b.rotulo}
                <span class="ambiente-quadrante">(${b.verbo})</span>
                ${Diag.contadorCards(itens.length, b.cor)}</span>
              ${add}
            </div>`,
            corpo: `<div class="corpo-coluna">
              ${cartoes || '<div class="text-muted small">Nenhum cruzamento.</div>'}
            </div>`,
          })}
        </div>
      </div>`;
    };

    const contagens = Object.fromEntries(this.BLOCOS
      .map((b) => [b.tipo, cruzamentos.filter((c) => c.tipo === b.tipo).length]));

    // O aviso substitui o "+ Novo" quando não há o que cruzar, e diz onde
    // resolver: sem os dois lados da SWOT, esta tela não tem trabalho nenhum.
    const semMateria = !podeCruzar
      ? `<div class="alert alert-info">
          Para cruzar é preciso ter, no ano de ${ano}, pelo menos um fator interno
          (força ou fraqueza) e um externo (oportunidade ou ameaça).
          <button type="button" class="btn btn-link p-0 align-baseline" data-ir-swot-vazio>Ir para a SWOT</button>.
        </div>` : '';

    el.innerHTML = RelatorioAnalise.canvas({
      cabecalho: `
      <div class="cabecalho-analise" data-cabecalho-analise>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h1 class="mb-0">Cruzamentos — ${Modal.esc(App.rotuloContexto())} · ${ano}</h1>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            ${Diag.seletorAno('cruzamentos')}
            ${editar && podeCruzar
              ? '<button class="btn btn-verde btn-sm" data-novo-cruzamento>+ Novo cruzamento</button>' : ''}
          </div>
        </div>
      </div>`,
      corpo: `
      ${semMateria}
      ${Diag.seletorCategoriaMovel('CRUZAMENTOS',
        this.BLOCOS.map((b) => [b.tipo, b.rotulo]), contagens)}
      <div class="row g-3">${this.BLOCOS.map((b) => coluna(b)).join('')}</div>`,
    });

    Diag.ligarCabecalhoFixo(el);
    Diag.ligarSeletorAno(el);
    Diag.ligarSeletorCategoriaMovel(el, 'CRUZAMENTOS');
    Diag.ligarVerMais(el);

    el.querySelectorAll('[data-ir-swot]').forEach((b) => b.addEventListener('click', () =>
      Diag.irParaFator('swot', b.dataset.irSwot, 'SWOT', b.dataset.catSwot)));
    el.querySelector('[data-ir-swot-vazio]')?.addEventListener('click', () =>
      App.mostrarSecao('swot'));
    // Antes da saída por `editar`: o caminho de volta até a ação vale para quem
    // só acompanha também — é leitura, não edição.
    el.querySelectorAll('[data-ir-acao]').forEach((b) => b.addEventListener('click', () => {
      SecaoProjetos.destacarAcao = b.dataset.irAcao;
      App.mostrarSecao('projetos');
    }));

    if (!editar) return;

    // O caminho para o plano: marcar põe na fila de Projetos, desmarcar tira.
    // O servidor recusa as duas coisas depois que a ação existe — aqui o botão
    // nem aparece, mas a recusa continua sendo dele.
    const marcarAcao = async (id, marcar) => {
      await App.api(`/api/cruzamentos/${id}/plano-acao`, { planejamento_id: plan.id, marcar });
      App.recarregarSecaoAtiva();
    };
    el.querySelectorAll('[data-plano-acao-cruz]').forEach((b) => b.addEventListener('click', () =>
      marcarAcao(b.dataset.planoAcaoCruz, true)));
    el.querySelectorAll('[data-tirar-acao-cruz]').forEach((b) => b.addEventListener('click', () =>
      marcarAcao(b.dataset.tirarAcaoCruz, false)));

    // ── Cadastro e edição ────────────────────────────────────────────────
    // Na ordem dos quadrantes da SWOT, não na do banco: lá as categorias saem
    // em ordem alfabética e a lista de externos começava por "Ameaça", com as
    // oportunidades depois — o contrário da leitura da tela ao lado.
    const ORDEM = ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'];
    const opcoesFator = (lista) => [...lista]
      .sort((a, b) => ORDEM.indexOf(a.categoria) - ORDEM.indexOf(b.categoria))
      .map((f) => ({
        valor: String(f.id),
        texto: f.descricao,
        selo: Diag.QUADRANTES[f.categoria] || f.categoria,
        cor: Diag.CORES_QUADRANTE[f.categoria],
      }));

    // Reescreve o aviso do bloco enquanto o par é escolhido. Sem ele, o usuário
    // só descobria em que quadro a linha caiu depois de salvar — e o bloco não
    // é um campo que ele possa corrigir, é consequência do par.
    const anunciarBloco = (dados) => {
      const caixa = document.getElementById('campo-bloco');
      if (!caixa) return;
      const fi = internos.find((f) => String(f.id) === String(dados.fator_interno_id));
      const fe = externos.find((f) => String(f.id) === String(dados.fator_externo_id));
      const b = fi && fe ? this.blocoDoPar(fi.categoria, fe.categoria) : null;
      const cor = b ? b.cor : '#5d6b64';
      caixa.querySelector('.info-barra').style.color = cor;
      caixa.querySelector('.info-barra').style.background = `${cor}1f`;
      caixa.querySelector('.info-barra span').textContent = b ? b.rotulo : 'Bloco do cruzamento';
      caixa.querySelector('.card-body').textContent = b
        ? `Este cruzamento entra no bloco “${b.rotulo}” — a estratégia é ${b.verbo}.`
        : 'Escolha um fator interno e um externo: o bloco é consequência do par.';
    };

    const modalCruzamento = (c = null, tipoInicial = null) => {
      const b = c ? this.bloco(c.tipo) : (tipoInicial ? this.bloco(tipoInicial) : null);
      // Vindo do "+" de um bloco, as listas já chegam filtradas nas categorias
      // daquele bloco: é o mesmo gesto do "+" por quadrante da SWOT, e evita
      // escolher um par que cairia noutro quadro.
      const listaInternos = b ? internos.filter((f) => f.categoria === b.interno) : internos;
      const listaExternos = b ? externos.filter((f) => f.categoria === b.externo) : externos;

      Modal.abrir({
        titulo: c ? `Editar cruzamento · ${ano}` : `Novo cruzamento · ${ano}`,
        url: c ? `/api/cruzamentos/${c.id}` : '/api/cruzamentos',
        valores: c
          ? { ...c, planejamento_id: plan.id }
          : {
              planejamento_id: plan.id,
              ...(listaInternos.length === 1 ? { fator_interno_id: String(listaInternos[0].id) } : {}),
              ...(listaExternos.length === 1 ? { fator_externo_id: String(listaExternos[0].id) } : {}),
            },
        campos: [
          { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
          // Na edição o PAR não é campo: ele é a identidade do cruzamento, e o
          // servidor o lê da linha. Trocar o par é outro cruzamento — some o
          // formulário, fica o registro do que está sendo editado.
          ...(c ? [
            { nome: 'par', rotulo: 'Cruzamento', tipo: 'info',
              texto: `${Diag.QUADRANTES[c.interno_categoria]}: ${c.interno_descricao}\n\n`
                + `${Diag.QUADRANTES[c.externo_categoria]}: ${c.externo_descricao}`,
              barra: { titulo: this.bloco(c.tipo)?.rotulo || c.tipo,
                cor: this.bloco(c.tipo)?.cor || '#007a45',
                origem: `Para trocar o par, crie outro cruzamento` } },
          ] : [
            { nome: 'bloco', rotulo: '', tipo: 'info', texto: '',
              barra: { titulo: 'Bloco do cruzamento', cor: '#5d6b64' } },
            { nome: 'fator_interno_id', rotulo: 'O que temos ou nos falta (interno)',
              tipo: 'lista_marcavel', unico: true, obrigatorio: true,
              opcoes: opcoesFator(listaInternos) },
            { nome: 'fator_externo_id', rotulo: 'O que o ambiente oferece ou ameaça (externo)',
              tipo: 'lista_marcavel', unico: true, obrigatorio: true,
              opcoes: opcoesFator(listaExternos) },
          ]),
          { nome: 'rotulo', rotulo: 'Nome curto do cruzamento', tipo: 'text', obrigatorio: true,
            exemplo: 'Pecuária + proteína',
            ajuda: 'É o que aparece no topo do cartão e no relatório. Duas ou três palavras.' },
          { nome: 'estrategia', rotulo: 'Estratégia — o que fazer com este cruzamento',
            tipo: 'textarea', obrigatorio: true, linhas: 5,
            ajuda: 'O parágrafo que diz a decisão: o que se faz, com o quê e para quê.' },
        ],
        ...(c ? {} : { aoMudar: anunciarBloco }),
      });
    };

    el.querySelector('[data-novo-cruzamento]')?.addEventListener('click', () => modalCruzamento());
    el.querySelectorAll('[data-add-bloco]').forEach((btn) => btn.addEventListener('click', () =>
      modalCruzamento(null, btn.dataset.addBloco)));
    el.querySelectorAll('[data-editar-cruz]').forEach((btn) => btn.addEventListener('click', () =>
      modalCruzamento(cruzamentos.find((x) => x.id == btn.dataset.editarCruz))));
    el.querySelectorAll('[data-excluir-cruz]').forEach((btn) => btn.addEventListener('click', async () => {
      const c = cruzamentos.find((x) => x.id == btn.dataset.excluirCruz);
      if (!confirm(`Excluir o cruzamento “${c?.rotulo || ''}”?`)) return;
      await App.api(`/api/cruzamentos/${btn.dataset.excluirCruz}/excluir`, { planejamento_id: plan.id });
      App.recarregarSecaoAtiva();
    }));
  },
};

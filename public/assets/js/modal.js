// Fábrica do modal genérico de cadastro: campos declarativos → formulário → POST JSON.

const Modal = {
  bsModal: null,
  config: null,

  // Ícones olho / olho riscado (Bootstrap Icons, MIT)
  iconeOlho: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>'
    + '<path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>',
  // Ditado por voz (Web Speech API) — o microfone só aparece se o navegador suportar
  suporteVoz: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
  reconhecimento: null,
  botaoGravando: null,
  iconeMic: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M5 3a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V3z"/>'
    + '<path d="M3.5 6.5A.5.5 0 0 1 4 7v1a4 4 0 0 0 8 0V7a.5.5 0 0 1 1 0v1a5 5 0 0 1-4.5 4.975V15h3a.5.5 0 0 1 0 1h-7a.5.5 0 0 1 0-1h3v-2.025A5 5 0 0 1 3 8V7a.5.5 0 0 1 .5-.5z"/></svg>',

  // Seta de aumentar/diminuir o campo: o MESMO caractere com que as frentes de
  // trabalho e os projetos abrem e fecham um bloco (`.seta-projeto`,
  // `.seta-iniciativa`). Um ícone próprio aqui ensinaria um segundo vocabulário
  // para um gesto que o sistema inteiro já escreve assim.
  setaExpandir: '▾',
  setaRecolher: '▴',

  iconeOlhoRiscado: '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">'
    + '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/>'
    + '<path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/>'
    + '<path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/></svg>',

  // `enviar` substitui o POST padrão quando salvar exige mais que uma chamada:
  // o caso real é o quiz, cujo 409 de "sala aberta em outra tela" vira uma
  // confirmação e um reenvio. Sem esse gancho a pergunta aparecia como erro
  // dentro do modal, sem nenhum jeito de responder sim.
  // `aoMudar(dados, raiz)` roda ao abrir e a cada mudança de campo, para o
  // formulário cujo TEXTO depende do que já foi escolhido. O caso real é o
  // cruzamento da SWOT: o bloco (atacar/defender/reforçar/proteger) é
  // consequência do par, e sem esse aviso o usuário só descobria em que quadro
  // a linha caiu depois de salvar. `visivelSe` não resolve — ele olha UM campo,
  // e aqui a resposta depende de dois.
  async abrir({ titulo, campos, valores = {}, url, aoSalvar = null, transformar = null, extra = null,
          enviar = null, aoMudar = null, salvar = null, bloqueio = null }) {
    // O cadeado vem ANTES de qualquer pintura: se o item é de outro, o
    // formulário nem chega a existir. Abrir e fechar em seguida piscaria uma
    // tela que a pessoa não pode usar.
    if (bloqueio) {
      const b = await Cadeado.tomar(bloqueio);
      if (!b.pode) {
        alert(`${b.usuario} está editando este item agora.\n\n`
          + 'A edição fica liberada em pouco tempo — a tela avisa sozinha quando '
          + 'ele terminar.');
        return;
      }
      this.bloqueioPendente = { alvo: bloqueio, restam: b.restam };
    } else {
      this.bloqueioPendente = null;
    }
    this.config = { campos, url, aoSalvar, transformar, extra, enviar };
    document.getElementById('modal-titulo').textContent = titulo;
    document.getElementById('modal-erro').classList.add('d-none');

    // O rótulo do botão pertence ao FORMULÁRIO: "Salvar" descreve mal o gesto
    // que exclui uma pessoa do cadastro, e o verde diz "siga em frente" bem na
    // hora de parar para ler. Com `salvar: {rotulo, perigo}` o formulário
    // escolhe as duas coisas.
    //
    // O botão é o MESMO elemento em todos os modais — como o `modal-extra`
    // abaixo — então ele é REPOSTO ao padrão a cada abertura. Sem essa
    // reposição o primeiro formulário destrutivo deixaria "Excluir", em
    // vermelho, no rodapé de todos os formulários seguintes da sessão.
    const btnSalvar = document.getElementById('modal-salvar');
    btnSalvar.textContent = salvar?.rotulo || 'Salvar';
    btnSalvar.classList.toggle('btn-verde', !salvar?.perigo);
    btnSalvar.classList.toggle('btn-danger', !!salvar?.perigo);

    // Botão opcional à esquerda do rodapé (ex.: redefinir a avaliação)
    const btnExtra = document.getElementById('modal-extra');
    btnExtra.classList.toggle('d-none', !extra);
    btnExtra.textContent = extra ? extra.rotulo : '';
    btnExtra.onclick = extra ? () => this.executarExtra() : null;

    const form = document.getElementById('modal-campos');
    this.combos = {};
    form.innerHTML = this.renderCampos(campos, valores);
    this.ligarBotoesSenha(form);
    this.ligarBotoesDitado(form);
    this.ligarFerramentasTexto(form);
    this.ligarMoedas(form);
    this.ligarSelecaoLivre(form);
    this.ligarListasMarcaveis(form);
    this.ligarDatasBr(form);
    this.ligarFaixas(form, campos);
    this.ligarCondicionais(form, campos);
    this.ligarTextareasElasticas(form);
    this.ligarMudanca(form, aoMudar);

    if (!this.bsModal) {
      this.bsModal = new bootstrap.Modal(document.getElementById('modal-form'));
      document.getElementById('modal-salvar').addEventListener('click', () => this.salvar());
      document.getElementById('modal-form').addEventListener('submit', (ev) => {
        ev.preventDefault();
        this.salvar();
      });
      document.getElementById('modal-form').addEventListener('hidden.bs.modal', () => {
        this.pararDitado();
        // Fechar por qualquer caminho — salvar, cancelar, Esc, clique fora —
        // solta o cadeado. Um lugar só: `hidden.bs.modal` é o funil por onde
        // todos eles passam, e amarrar em cada botão deixaria um de fora.
        Cadeado.soltar();
      });
      // Só com o modal na tela dá para medir o que transborda
      document.getElementById('modal-form').addEventListener('shown.bs.modal', () =>
        this.aoAparecer(document.getElementById('modal-campos')));
    }
    this.bsModal.show();
    if (this.bloqueioPendente) {
      Cadeado.iniciar(this.bloqueioPendente.alvo, this.bloqueioPendente.restam);
      this.bloqueioPendente = null;
    } else {
      Cadeado.pintar();
    }
  },

  /**
   * Monta a lista de campos em DUAS camadas de agrupamento, as duas por
   * vizinhança — só campos CONSECUTIVOS com o mesmo nome entram no mesmo
   * grupo. Juntar campos distantes reordenaria o formulário por baixo do pano,
   * e a ordem é decisão de quem escreveu a lista.
   *
   * `caixa` é um PAINEL em volta de uma decisão e de tudo que ela revela: o
   * caso real é a repetição da ação, que mostra a grade de dias da semana, a do
   * mês ou o prazo de execução conforme a escolha. Soltos no formulário, os
   * campos revelados pareciam pertencer ao resto dele, e trocar "todo mês" por
   * "não se repete" trocava blocos aparentemente sem relação entre si — dentro
   * da caixa fica visível que um está no lugar do outro.
   */
  renderCampos(campos, valores) {
    // O que está FORA de caixa nenhuma também vira bloco — um bloco só, com
    // todos os vizinhos soltos juntos. Um bloco por campo solto (o que era)
    // entregava a `renderLinhas` um campo de cada vez, e ela nunca via dois
    // vizinhos para juntar: Prioridade e Status, que dividem uma fileira,
    // caíam em fileiras separadas sem nada na tela denunciando o motivo.
    const blocos = [];
    let atual = null;
    for (const c of campos) {
      const nome = c.caixa || null;
      if (!atual || atual.nome !== nome) {
        atual = { nome, itens: [] };
        blocos.push(atual);
      }
      atual.itens.push(c);
    }
    return blocos.map((b) => {
      const html = this.renderLinhas(b.itens, valores);
      return b.nome ? `<div class="caixa-campos caixa-${this.esc(b.nome)}">${html}</div>` : html;
    }).join('');
  },

  /**
   * A camada de dentro: campos que declaram a mesma `linha` dividem uma fileira
   * (ex.: `linha: 'prioridade-status'`).
   *
   * Campo curto ocupando a largura inteira do modal custa uma faixa de tela
   * cada — o Salvar mora no rodapé fixo, então o que sobra de altura é rolagem.
   */
  renderLinhas(campos, valores) {
    const partes = [];
    let grupo = null;
    for (const c of campos) {
      const html = this.renderCampo(c, valores[c.nome], valores);
      if (!c.linha) {
        grupo = null;
        partes.push(html);
        continue;
      }
      if (!grupo || grupo.linha !== c.linha) {
        grupo = { linha: c.linha, itens: [] };
        partes.push(grupo);
      }
      grupo.itens.push(html);
    }
    return partes
      // O nome da linha vira classe (`grade-linha-…`): é o gancho para o CSS
      // manter um par lado a lado até no celular, onde a grade padrão empilha
      .map((p) => (typeof p === 'string' ? p
        : `<div class="grade-campos grade-linha-${this.esc(p.linha)}">${p.itens.join('')}</div>`))
      .join('');
  },

  renderCampo(c, valor, valores = {}) {
    const v = valor ?? c.padrao ?? '';
    const id = `campo-${c.nome}`;
    const exemplo = c.exemplo ? ` placeholder="${this.esc(c.exemplo)}"` : '';
    let controle;
    // Botões que moram na LINHA DO RÓTULO, encostados à direita (ver
    // `linha-rotulo` no CSS). Hoje só o campo de texto com teto de linhas os
    // usa: o microfone e o ver mais/ver menos do "O quê?" e do "Como?".
    let ferramentas = '';
    switch (c.tipo) {
      case 'periodo': {
        // Duas datas lado a lado (início e fim), cada uma com seu calendário
        const colunas = (c.campos || []).map((s) => `
          <div>
            <span class="sub-rotulo">${this.esc(s.rotulo)}</span>
            <input type="date" class="form-control" id="campo-${s.nome}"
              value="${this.esc(valores[s.nome] ?? '')}">
          </div>`).join('');
        // O id vai no grupo (e não num dos dois campos) para o `visivelSe`
        // alcançar o período inteiro: sem ele, esconder "Quando?" escondia só
        // o rótulo e as duas datas continuavam na tela
        controle = `<div class="grade-datas" id="${id}">${colunas}</div>`;
        break;
      }
      case 'textarea': {
        // `maxLinhas` limita o crescimento automático; depois dele o texto
        // rola por dentro e a alça do canto continua disponível para esticar
        const teto = c.maxLinhas ? ` data-max-linhas="${Number(c.maxLinhas)}"` : '';
        const area = `<textarea class="form-control campo-elastico" id="${id}" rows="${c.linhas || 3}"${teto}${exemplo}>${this.esc(v)}</textarea>`;
        // TODO campo de texto do modal traz o mesmo par de controles: a seta de
        // ver mais/ver menos na linha do rótulo — a MESMA seta com que as
        // frentes de trabalho e os projetos abrem e fecham um bloco — e a alça
        // do canto inferior direito para arrastar a altura. Antes só o campo com
        // `maxLinhas` os tinha, e o campo sem teto (a estratégia do cruzamento,
        // por exemplo) ficava sem nenhum jeito de crescer no celular.
        // O microfone NÃO sobe para a linha do rótulo: ele fica onde está em
        // todo campo de texto do sistema, dentro da caixa e no canto inferior
        // direito, desviando da alça pelo lado.
        ferramentas = `<span class="campo-ferramentas">${this.botaoExpandir(id)}</span>`;
        // A caixa é sempre a mesma (`.campo-voz`), com ou sem microfone: é ela
        // que dá o `position: relative` de que a alça precisa para se ancorar no
        // canto. Sem o embrulho, a alça de um navegador sem ditado ficaria
        // posicionada em relação ao modal inteiro.
        controle = `<div class="campo-voz">${area}${
          this.suporteVoz ? this.botaoDitar(id) : ''}${this.alcaCampo(id)}</div>`;
        break;
      }
      case 'dias': {
        // Fichas marcáveis para escolher os dias da repetição: os 31 do mês, ou
        // os 7 da semana. Fichas, e não multiselect — a lista suspensa não tem
        // Ctrl no celular, e 31 itens em coluna não cabem na tela. A escolha é
        // MÚLTIPLA nas duas: "toda segunda e quinta" e "todo dia 5 e 20" são
        // rotinas comuns, e com um dia só a pessoa cadastrava a mesma tarefa
        // duas vezes para descrever uma.
        const marcados = new Set((Array.isArray(v) ? v : String(v || '').split(','))
          .map((x) => Number(x)).filter(Boolean));
        const fichas = (c.opcoes || []).map((o) => {
          const dia = Number(o.valor);
          return `
          <input type="checkbox" class="btn-check" id="${id}-${dia}" value="${dia}"
            ${marcados.has(dia) ? 'checked' : ''}>
          <label class="btn btn-dia" for="${id}-${dia}">${this.esc(o.rotulo)}</label>`;
        }).join('');
        // A grade do mês é um calendário (sete colunas iguais); a da semana são
        // nomes de largura desigual, que fluem e quebram a linha
        const grade = c.grade === 'semana' ? 'grade-dias grade-dias-semana' : 'grade-dias';
        controle = `<div class="${grade}" id="${id}" role="group"
          aria-label="${this.esc(c.rotulo)}">${fichas}</div>`;
        break;
      }
      case 'moeda': {
        // Campo de dinheiro: entra número e só número.
        //
        // `type=text` e não `type=number`, apesar de o valor ser numérico. O
        // campo numérico do navegador ACEITA `e`, `E`, `+` e `-` (notação
        // científica) e, com qualquer um deles dentro, devolve `.value` VAZIO —
        // o formulário mandava null para um campo que a pessoa preencheu, sem
        // nada na tela dizendo isso. Pior: ele não expõe `selectionStart`, então
        // não há como recusar UM caractere sem apagar o que já estava escrito.
        // Com `type=text` o filtro trabalha no cursor (`ligarMoedas`), e
        // `inputmode=decimal` mantém o teclado numérico do celular.
        controle = `<input type="text" class="form-control campo-moeda" id="${id}"
          value="${this.esc(this.moedaBr(v))}" inputmode="decimal" autocomplete="off"${exemplo}>`;
        break;
      }
      case 'select': {
        const opcoes = (c.opcoes || [])
          .map((o) => `<option value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'selected' : ''}>${this.esc(o.rotulo)}</option>`)
          .join('');
        controle = `<select class="form-select" id="${id}">${opcoes}</select>`;
        break;
      }
      case 'multiselect': {
        const selecionados = Array.isArray(v) ? v.map(String) : [];
        const opcoes = (c.opcoes || [])
          .map((o) => `<option value="${this.esc(o.valor)}" ${selecionados.includes(String(o.valor)) ? 'selected' : ''}>${this.esc(o.rotulo)}</option>`)
          .join('');
        controle = `<select class="form-select" id="${id}" multiple size="${Math.min(8, (c.opcoes || []).length || 3)}">${opcoes}</select>`;
        break;
      }
      case 'lista_marcavel': {
        // Lista de itens marcáveis com o texto inteiro à vista — para escolhas
        // em que saber exatamente o que se está amarrando importa mais que
        // caber em uma linha. Com muitos itens, ganha campo de pesquisa.
        //
        // Com `unico: true` a lista escolhe UM item: os quadrados viram
        // redondos e o campo devolve o valor, não a lista. É o controle certo
        // sempre que o item precisa ser LIDO antes de escolhido — um `select`
        // com um parágrafo dentro obriga a abrir a lista para descobrir o que
        // existe, e no celular fica ilegível.
        const opcoes = c.opcoes || [];
        const marcados = c.unico
          ? (v === null || v === undefined || v === '' ? [] : [String(v)])
          : (Array.isArray(v) ? v : []).map(String);
        const itens = opcoes.map((o) => {
          const cor = /^#[0-9a-f]{6}$/i.test(o.cor || '') ? o.cor : '#007a45';
          const selos = [
            o.selo && `<span class="badge" style="color:${cor};background:${cor}1f">${this.esc(o.selo)}</span>`,
            o.selo2 && `<span class="badge text-bg-light border">${this.esc(o.selo2)}</span>`,
          ].filter(Boolean).join(' ');
          const busca = this.esc(`${o.selo || ''} ${o.selo2 || ''} ${o.texto || o.rotulo || ''}`);
          return `<label class="marcavel-item" data-busca="${busca}">
            <input class="form-check-input" type="${c.unico ? 'radio' : 'checkbox'}"
              ${c.unico ? `name="${id}-opcao"` : ''} value="${this.esc(o.valor)}"
              ${marcados.includes(String(o.valor)) ? 'checked' : ''}>
            <span class="marcavel-corpo">
              ${selos ? `<span class="marcavel-selos">${selos}</span>` : ''}
              <span class="marcavel-texto">${this.esc(o.texto || o.rotulo || '')}</span>
              <span class="marcavel-rodape"></span>
            </span>
          </label>`;
        }).join('');
        const pesquisa = opcoes.length > 5
          ? `<input type="search" class="form-control form-control-sm marcavel-busca"
               placeholder="Pesquisar na lista..." aria-label="Pesquisar na lista">`
          : '';
        controle = `<div class="lista-marcavel" id="${id}">
          ${pesquisa}
          <div class="marcavel-lista">${itens
            || '<div class="text-muted small p-2">Nenhum item disponível.</div>'}</div>
          <div class="marcavel-contador"></div>
        </div>`;
        break;
      }
      case 'selecao_livre': {
        // Combobox pesquisável: o toque abre um painel com busca no topo e a
        // lista de nomes em ordem alfabética; digitar filtra as sugestões e,
        // sem correspondência, o nome digitado é aceito como novo
        const opcoes = [...new Set((c.opcoes || []).map(String).filter((s) => s.trim() !== ''))]
          .sort((a, b) => a.localeCompare(b, 'pt-BR'));
        const valorStr = String(v ?? '');
        this.combos[id] = { opcoes, vazio: c.vazio || '(não definido)', obrigatorio: !!c.obrigatorio };
        controle = `<div class="combo-busca">
          <input type="hidden" id="${id}" value="${this.esc(valorStr)}">
          <button type="button" class="form-select text-start combo-alvo" id="${id}-alvo"
            aria-haspopup="listbox" aria-expanded="false">
            ${valorStr ? this.esc(valorStr) : `<span class="text-muted">${this.esc(c.vazio || '(não definido)')}</span>`}
          </button>
          <div class="combo-painel d-none">
            <input type="text" class="form-control combo-pesquisa" autocomplete="off"
              placeholder="Pesquisar ou digitar o nome...">
            <div class="combo-lista" role="listbox"></div>
          </div>
        </div>`;
        break;
      }
      case 'quadrantes': {
        // Matriz 2×2 clicável: a escolha é feita tocando no próprio quadrante
        const celulas = (c.opcoes || []).map((o) => {
          const cor = /^#[0-9a-f]{6}$/i.test(o.cor || '') ? o.cor : '#007a45';
          return `<input type="radio" class="btn-check" name="${id}" id="${id}-${this.esc(o.valor)}"
              value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'checked' : ''}>
            <label class="quadrante-opcao" for="${id}-${this.esc(o.valor)}" style="--cor-quad:${cor}">
              <span class="quadrante-nome">${this.esc(o.rotulo)}</span>
              ${o.dica ? `<span class="quadrante-dica">${this.esc(o.dica)}</span>` : ''}
            </label>`;
        }).join('');
        // A SWOT é uma MATRIZ: as quatro posições têm significado, então ficam
        // travadas em 2×2. PESTEL e Porter são só listas de categoria — com
        // `layout: 'lista'` os cartões fluem e acomodam 5, 6 ou quantos forem.
        const grade = c.layout === 'lista' ? 'grade-quadrantes grade-lista' : 'grade-quadrantes';
        controle = `<div class="${grade}" id="${id}" role="radiogroup"
          aria-label="${this.esc(c.rotulo)}">${celulas}</div>`;
        break;
      }
      case 'faixa': {
        // Controle deslizante com o valor atual à direita do rótulo
        const min = c.min ?? 0;
        const max = c.max ?? 100;
        const atual = v === '' || v === null || v === undefined ? min : Number(v);
        const pct = max > min ? Math.round(((atual - min) / (max - min)) * 100) : 0;
        controle = `<div class="campo-faixa">
          <input type="range" class="faixa-verde" id="${id}" min="${min}" max="${max}"
            step="${c.passo ?? 1}" value="${atual}" style="--pct:${pct}%">
          <div class="d-flex justify-content-between faixa-limites">
            <span>${min}${this.esc(c.sufixo || '')}</span>
            <span>${max}${this.esc(c.sufixo || '')}</span>
          </div>
        </div>`;
        break;
      }
      case 'botoes': {
        // Grupo de botões exclusivos (option buttons): um clique escolhe o
        // valor. `vertical` empilha as opções — serve para o grupo dividir uma
        // linha com outro campo sem espremer os rótulos.
        const botoes = (c.opcoes || []).map((o) => `
          <input type="radio" class="btn-check" name="${id}" id="${id}-${this.esc(o.valor)}"
            value="${this.esc(o.valor)}" ${String(o.valor) === String(v) ? 'checked' : ''}>
          <label class="btn btn-opcao" for="${id}-${this.esc(o.valor)}">${this.esc(o.rotulo)}</label>`).join('');
        controle = `<div class="btn-group${c.vertical ? '-vertical' : ''} w-100 grupo-botoes"
          role="group" id="${id}" aria-label="${this.esc(c.rotulo)}">${botoes}</div>`;
        break;
      }
      case 'info': {
        // Bloco somente-leitura no topo do modal: mostra o conteúdo em até
        // ~10 linhas, com barra de rolagem para ler o restante. Com `barra`,
        // ganha um cabeçalho colorido identificando a origem (ex.: quadrante).
        const cor = /^#[0-9a-f]{6}$/i.test(c.barra?.cor || '') ? c.barra.cor : '#007a45';
        const barra = c.barra
          ? `<div class="info-barra" style="color:${cor};background:${cor}1f">
               <span>${this.esc(c.barra.titulo)}</span>
               ${c.barra.origem ? `<span class="info-barra-origem">${this.esc(c.barra.origem)}</span>` : ''}
             </div>`
          : '';
        // `itens` mostra PEÇAS distintas, cada uma na sua caixa colorida, em vez
        // de um texto corrido: dois registros emendados num parágrafo só (o par
        // de um cruzamento da SWOT) liam-se como uma frase única, e ninguém
        // achava onde um acabava e o outro começava. É o mesmo desenho dos selos
        // do cartão — cor do quadrante, "Rótulo: descrição" —, só que INTEIRO:
        // aqui não há corte de linha, porque este bloco existe para ser lido.
        const corpo = Array.isArray(c.itens) && c.itens.length
          ? c.itens.map((it) => {
            const ci = /^#[0-9a-f]{6}$/i.test(it.cor || '') ? it.cor : '#5d6b64';
            return `<div class="info-item" style="color:${ci};background:${ci}1f">
                ${it.rotulo ? `<strong>${this.esc(it.rotulo)}:</strong> ` : ''}${this.esc(it.texto)}
              </div>`;
          }).join('')
          : this.esc(c.texto ?? v);
        // O id vai no bloco inteiro: `info` não tem controle, e é por ele que
        // um `aoMudar` alcança o texto para reescrevê-lo (o bloco do
        // cruzamento da SWOT, que só se conhece depois de escolhido o par).
        // Com `itens` o bloco não rola por dentro: as peças aparecem INTEIRAS e
        // quem rola é o corpo do modal (que já é `modal-dialog-scrollable`).
        // Cortar aqui devolvia o problema que o `itens` veio resolver — a
        // segunda caixa aparecia pela metade, e ler o par exigia descobrir que
        // aquela área tinha rolagem própria.
        const classeCorpo = Array.isArray(c.itens) && c.itens.length ? ' info-itens' : '';
        // O bloco de leitura recebe o MESMO par de controles do campo de texto:
        // a seta de ver mais/ver menos na linha do rótulo e a alça de arrastar a
        // altura no canto. Ele é cortado por altura (no celular, sempre; no
        // computador, a partir de ~10 linhas), e até aqui o único jeito de ler o
        // resto era descobrir que aquela área rolava por dentro.
        // A seta nasce escondida e só aparece se o conteúdo REALMENTE não
        // couber — quem mede é `marcarInfoRolavel`, com o modal já na tela.
        // Sem rótulo não há linha de rótulo onde pendurá-la; nesse caso fica
        // só a alça, que mora dentro do próprio cartão.
        const ferramentasInfo = c.rotulo
          ? `<span class="campo-ferramentas d-none">${this.botaoExpandirInfo(id)}</span>` : '';
        const cabecaInfo = c.rotulo
          ? `<div class="linha-rotulo"><label class="form-label rotulo-info">${
            this.esc(c.rotulo)}</label>${ferramentasInfo}</div>`
          : '';
        return `<div class="mb-3" id="${id}">
          ${cabecaInfo}
          <div class="card card-info-modal">${barra}<div class="card-body py-2 px-3 small${
            classeCorpo}">${corpo}</div>${this.alcaInfo(id)}</div>
        </div>`;
      }
      case 'hidden':
        // Sem rótulo nem espaçamento — o campo não aparece na tela
        return `<input type="hidden" id="${id}" value="${this.esc(v)}">`;
      case 'checkbox':
        return `<div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="${id}" ${v ? 'checked' : ''}>
          <label class="form-check-label" for="${id}">${this.esc(c.rotulo)}</label>
        </div>`;
      case 'password':
        controle = `<div class="input-group">
          <input type="password" class="form-control" id="${id}" value="${this.esc(v)}" autocomplete="new-password">
          <button class="btn btn-outline-secondary btn-ver-senha" type="button" data-alvo="${id}"
            aria-label="Mostrar senha" title="Mostrar senha">${this.iconeOlho}</button>
        </div>`;
        break;
      case 'arquivos':
        // Entrada de arquivos para formulário cujo envio é multipart (o
        // comentário com anexos). `coletar()` a pula — arquivo não viaja em
        // JSON; quem envia lê os arquivos direto do campo, por
        // `Modal.arquivosDe(nome)`, dentro do `enviar`.
        controle = `<input type="file" class="form-control" id="${id}"
          ${c.multiplo ? 'multiple' : ''}${c.aceita ? ` accept="${this.esc(c.aceita)}"` : ''}>`;
        break;
      default: {
        // Com `sugestoes`, o campo vira lista de escolha que aceita digitar
        // um nome fora da lista (ex.: responsável que não é usuário do sistema)
        const lista = (c.sugestoes || []).length ? ` list="${id}-lista"` : '';
        const datalist = lista
          ? `<datalist id="${id}-lista">${c.sugestoes.map((s) => `<option value="${this.esc(s)}"></option>`).join('')}</datalist>`
          : '';
        // `min`/`max` valem para number e date: o teclado numérico do celular
        // não impede um sinal de menos — o limite no campo impede
        const limites = `${c.min !== undefined ? ` min="${this.esc(c.min)}"` : ''}${
          c.max !== undefined ? ` max="${this.esc(c.max)}"` : ''}`;
        const input = `<input type="${c.tipo || 'text'}" class="form-control" id="${id}" value="${this.esc(v)}"${exemplo}${lista}${limites}>${datalist}`;
        controle = (c.tipo || 'text') === 'text' && this.suporteVoz
          ? `<div class="campo-voz">${input}${this.botaoDitar(id)}</div>`
          : input;
      }
    }
    const ajuda = c.ajuda ? `<div class="form-text">${this.esc(c.ajuda)}</div>` : '';
    const nota = c.nota ? `<div class="nota-campo">${this.esc(c.nota)}</div>` : '';
    const obrigatorio = c.obrigatorio ? ' <span class="obrigatorio" title="Campo obrigatório">*</span>' : '';
    // Grupos (como o período) não apontam para um único campo
    const alvo = c.tipo === 'periodo' ? '' : ` for="${id}"`;
    // A faixa mostra o valor escolhido ao lado do rótulo
    const valorFaixa = c.tipo === 'faixa'
      ? `<span class="valor-faixa" id="${id}-valor">${v === '' || v === null || v === undefined ? (c.min ?? 0) : this.esc(v)}${this.esc(c.sufixo || '')}</span>`
      : '';
    const rotulo = `<label class="form-label"${alvo}>${this.esc(c.rotulo)}${obrigatorio}${valorFaixa}</label>`;
    // Com ferramentas, o rótulo divide uma linha com elas; sem, fica como sempre
    const cabeca = ferramentas ? `<div class="linha-rotulo">${rotulo}${ferramentas}</div>` : rotulo;
    // `separador` desenha um filete acima do campo: dentro de uma caixa, ele
    // separa a escolha (que dias) do prazo dela (até quando)
    const classe = `mb-3${c.separador ? ' campo-separado' : ''}`;
    return `<div class="${classe}">${cabeca}${controle}${ajuda}${nota}</div>`;
  },

  botaoDitar(id) {
    return `<button class="btn btn-ditar" type="button" data-alvo="${id}"
      title="Ditar por voz" aria-label="Ditar por voz">${this.iconeMic}</button>`;
  },

  botaoExpandir(id) {
    return `<button class="btn btn-expandir" type="button" data-alvo="${id}"
      aria-controls="${id}" aria-expanded="false"
      title="Aumentar o campo" aria-label="Aumentar o campo">${this.setaExpandir}</button>`;
  },

  /**
   * A mesma seta, para o bloco de leitura. O alvo é declarado noutro atributo
   * (`data-alvo-info`) porque o que ela faz é outro: no campo de texto muda o
   * TETO do crescimento; aqui abre o cartão para mostrar o conteúdo inteiro.
   * Um atributo só obrigaria o ouvinte a adivinhar o tipo do alvo.
   */
  botaoExpandirInfo(id) {
    return `<button class="btn btn-expandir" type="button" data-alvo-info="${id}"
      aria-controls="${id}" aria-expanded="false"
      title="Ver o conteúdo inteiro" aria-label="Ver o conteúdo inteiro">${this.setaExpandir}</button>`;
  },

  /**
   * A alça do canto inferior direito — três riscos na diagonal, o desenho que
   * todo mundo já conhece do canto de um campo de texto.
   *
   * Ela existe porque a nativa (`resize: vertical`) **não funciona no celular**:
   * o iOS não a desenha nem responde ao arraste, e era justamente lá que o campo
   * ficava pequeno demais. Esta é feita de eventos de ponteiro (`ligarAlcas`),
   * que valem para o dedo e para o mouse.
   */
  alcaCampo(id) {
    return `<span class="alca-campo" data-alca="${id}" role="separator"
      aria-orientation="horizontal" title="Arraste para mudar a altura"
      aria-label="Arraste para mudar a altura do campo"></span>`;
  },

  /** A mesma alça, ancorada no cartão do bloco de leitura. */
  alcaInfo(id) {
    return `<span class="alca-campo alca-info" data-alca-info="${id}" role="separator"
      aria-orientation="horizontal" title="Arraste para mudar a altura"
      aria-label="Arraste para mudar a altura do bloco"></span>`;
  },

  /**
   * O valor guardado (`1500.00`) escrito como se escreve dinheiro em português.
   * Sem isso, o campo reabria com o ponto que a digitação recusa — e a primeira
   * tecla apagava um número que estava certo.
   */
  moedaBr(v) {
    if (v === null || v === undefined || v === '') return '';
    return String(v).replace('.', ',');
  },

  /** Arquivos escolhidos num campo `arquivos` do formulário aberto. */
  arquivosDe(nome) {
    return [...(document.getElementById(`campo-${nome}`)?.files || [])];
  },

  // Estado dos comboboxes pesquisáveis do formulário aberto (id → opções)
  combos: {},

  /**
   * Medidas que só fazem sentido com o modal já na tela: enquanto ele está
   * escondido toda altura vale zero, e nada transborda.
   */
  aoAparecer(raiz) {
    this.aplicarVerMais(raiz);
    raiz.querySelectorAll('textarea').forEach((t) => this.crescerTextarea(t));
    this.ligarAvisoRolagem(raiz.closest('.modal-body'));
    this.marcarInfoRolavel(raiz);
  },

  /**
   * Bloco de leitura que não coube: acende o esmaecido do rodapé.
   *
   * No celular o bloco encolhe para o formulário caber na tela e rola por
   * dentro — mas o corte batia no meio de uma frase (ou de uma das caixas do
   * par de um cruzamento) e parecia defeito, não rolagem. A marca é medida com
   * o modal JÁ na tela: escondido, toda altura vale zero e nada transborda.
   * Sem transbordo não há esmaecido — um degradê permanente no rodapé de um
   * bloco inteiro à vista mentiria que ainda há texto embaixo.
   */
  marcarInfoRolavel(raiz) {
    raiz.querySelectorAll('.card-info-modal').forEach((card) => {
      const corpo = card.querySelector('.card-body');
      if (!corpo) return;
      const bloco = card.closest('.mb-3');
      const ferramentas = bloco?.querySelector('.campo-ferramentas');
      const medir = () => {
        const sobra = corpo.scrollHeight - corpo.clientHeight - corpo.scrollTop > 8;
        card.classList.toggle('info-tem-mais', sobra);
        // A seta só aparece onde há mesmo conteúdo escondido — ou onde ela já
        // abriu o bloco, senão sumiria justamente o botão que o recolhe. Seta
        // que não faz nada em bloco curto ensina a ignorar a seta.
        const aberto = card.classList.contains('info-aberto');
        const cortado = corpo.scrollHeight - corpo.clientHeight > 8;
        ferramentas?.classList.toggle('d-none', !(cortado || aberto));
      };
      if (!corpo.dataset.rolagemLigada) {
        corpo.addEventListener('scroll', medir);
        corpo.dataset.rolagemLigada = '1';
      }
      medir();
    });
  },

  /**
   * Avisa que há campo abaixo da dobra.
   *
   * O corpo rola desde sempre, mas nada indicava isso — e o Salvar mora no
   * rodapé fixo, sempre visível. Numa janela de notebook, o formulário de
   * quatro perguntas da matriz GUT mostrava três e o botão: quem respondia as
   * três e salvava deixava o esforço "não estimado" sem nunca ter escolhido
   * isso. Vale para todo modal do sistema, não só para o da GUT.
   *
   * O ouvinte de rolagem é ligado UMA vez: o corpo do modal é o mesmo elemento
   * em todos os formulários, e religá-lo a cada abertura empilharia uma cópia
   * por modal aberto na sessão.
   *
   * O aviso não é clicável (`pointer-events: none` no CSS): flutuando sobre o
   * conteúdo, ele engolia o toque do que estivesse embaixo.
   */
  ligarAvisoRolagem(corpo) {
    const aviso = document.getElementById('modal-mais');
    if (!corpo || !aviso) return;
    const medir = () => aviso.classList.toggle(
      'd-none', corpo.scrollHeight - corpo.clientHeight - corpo.scrollTop <= 8);
    if (!corpo.dataset.avisoLigado) {
      corpo.addEventListener('scroll', medir);
      corpo.dataset.avisoLigado = '1';
    }
    medir();
  },

  /**
   * Descrição longa fica cortada em 3 linhas: um item extenso não pode engolir
   * a lista inteira. Só ganha "ver mais" quem realmente transborda.
   */
  aplicarVerMais(raiz) {
    raiz.querySelectorAll('.marcavel-item').forEach((item) => {
      const texto = item.querySelector('.marcavel-texto');
      const rodape = item.querySelector('.marcavel-rodape');
      if (!texto || !rodape || rodape.firstChild) return;
      if (texto.scrollHeight <= texto.clientHeight + 1) return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-link btn-sm p-0 ver-mais';
      btn.textContent = 'ver mais';
      btn.addEventListener('click', (ev) => {
        // O botão vive dentro do <label>: sem isto o clique marcaria o item
        ev.preventDefault();
        ev.stopPropagation();
        const aberto = texto.classList.toggle('expandido');
        btn.textContent = aberto ? 'ver menos' : 'ver mais';
      });
      rodape.appendChild(btn);
    });
  },

  /**
   * O campo de texto acompanha o que está sendo escrito.
   *
   * São DOIS comportamentos, e a diferença importa: o campo com `maxLinhas`
   * (o "O quê?" e o "Como?" do plano de ação) tem a altura mandada por aqui —
   * cresce e ENCOLHE com o texto até o teto de linhas, e ali passa a rolar por
   * dentro. O campo sem teto continua como sempre foi: só `min-height`, subindo
   * até 60% da tela, com a rolagem nativa. Mexer no `height` e no `overflow`
   * dele quebraria o que o flex do modal já resolve — esticado pelo flex, um
   * `overflow: hidden` calculado aqui cortaria o texto sem barra de rolagem.
   *
   * Encolher exige zerar a altura antes de medir: `scrollHeight` nunca diminui
   * enquanto o elemento estiver esticado por uma altura anterior, e apagar
   * texto deixava o campo grande para sempre. Quem arrastou a alça manda: a
   * altura escolhida à mão (`data-ajustado`) não é mais recalculada.
   *
   * O botão de ver mais não muda a altura: ele muda o TETO (`data-expandido`),
   * e o cálculo continua sendo este. Escrever a altura direto no botão deixaria
   * duas fontes para a mesma medida, e a tecla seguinte desfaria a expansão.
   */
  crescerTextarea(t) {
    if (t.dataset.ajustado) return;
    const tetoTela = Math.round(window.innerHeight * 0.6);
    if (!t.dataset.maxLinhas) {
      // Aberto pela seta, o campo sem teto declarado passa a ocupar até 60% da
      // tela mesmo com pouco texto: quem toca em "ver mais" quer ESPAÇO para
      // escrever, e um campo que só cresce com o que já foi digitado não daria
      // nenhum. Fechado, ele volta a acompanhar o texto, como sempre foi.
      if (t.dataset.expandido === '1') {
        t.style.minHeight = `${tetoTela}px`;
        t.closest('.mb-3')?.classList.add('altura-manual');
        return;
      }
      if (t.scrollHeight <= t.clientHeight + 1) return;
      const alvo = Math.min(t.scrollHeight + 2, tetoTela);
      if (alvo > t.clientHeight) t.style.minHeight = `${alvo}px`;
      return;
    }
    const teto = t.dataset.expandido === '1'
      ? tetoTela
      : Math.round(Number(t.dataset.maxLinhas) * this.alturaLinha(t) + this.bordasVerticais(t));
    const anterior = t.style.height;
    t.style.height = 'auto';
    const alvo = Math.min(t.scrollHeight + 2, teto);
    t.style.height = anterior;
    // Piso: a altura das `rows` declaradas, para o campo vazio não encolher
    // abaixo do tamanho com que nasceu
    t.style.height = `${Math.max(alvo, this.alturaMinima(t))}px`;
    t.style.overflowY = t.scrollHeight > alvo + 1 ? 'auto' : 'hidden';
    // A altura que o ajuste automático acabou de aplicar. É ela que o
    // observador compara para saber se a mudança seguinte veio da alça (de
    // quem escreve) ou daqui — sem esse registro, o próprio crescimento era
    // lido como arraste e o campo congelava na primeira tecla.
    t.dataset.hAuto = String(Math.round(t.getBoundingClientRect().height));
  },

  alturaLinha(t) {
    const lh = parseFloat(getComputedStyle(t).lineHeight);
    return Number.isFinite(lh) ? lh : 20;
  },

  bordasVerticais(t) {
    const c = getComputedStyle(t);
    return parseFloat(c.paddingTop) + parseFloat(c.paddingBottom)
      + parseFloat(c.borderTopWidth) + parseFloat(c.borderBottomWidth);
  },

  alturaMinima(t) {
    return Math.round((Number(t.rows) || 1) * this.alturaLinha(t) + this.bordasVerticais(t));
  },

  /**
   * Além de crescer sozinho, o campo pode ser esticado pela alça do canto
   * inferior direito (o `resize` nativo do textarea). Arrastar a alça é uma
   * decisão de quem escreve: a partir dela o campo para de se ajustar sozinho,
   * senão a próxima tecla digitada desfaria o tamanho escolhido.
   */
  ligarTextareasElasticas(raiz) {
    raiz.querySelectorAll('textarea').forEach((t) => {
      t.addEventListener('input', () => this.crescerTextarea(t));
      // O arraste da alça muda a altura sem disparar evento próprio; o
      // observador é o único jeito de saber que ela mexeu. Altura diferente da
      // que `crescerTextarea` registrou em `hAuto` só pode ter vindo da alça.
      if (typeof ResizeObserver === 'function') {
        const obs = new ResizeObserver(() => {
          const esperada = Number(t.dataset.hAuto || 0);
          const h = Math.round(t.getBoundingClientRect().height);
          if (esperada && Math.abs(h - esperada) > 2) t.dataset.ajustado = '1';
        });
        obs.observe(t);
        document.getElementById('modal-form')?.addEventListener('hidden.bs.modal',
          () => obs.disconnect(), { once: true });
      }
    });
  },

  /**
   * O ver mais / ver menos do campo de texto compacto.
   *
   * Ele alterna o TETO do crescimento automático: compacto, o campo para nas
   * `maxLinhas` declaradas e rola por dentro; aberto, ele acompanha o texto até
   * 60% da tela. Quem já tinha esticado o campo pela alça volta a ser servido
   * pelo cálculo (`data-ajustado` sai) — o botão é uma decisão NOVA sobre o
   * tamanho, e mantendo o congelamento ele não teria efeito nenhum justamente
   * em quem mais mexe no campo.
   */
  ligarFerramentasTexto(raiz) {
    raiz.querySelectorAll('.btn-expandir[data-alvo]').forEach((b) => b.addEventListener('click', () => {
      const campo = document.getElementById(b.dataset.alvo);
      if (!campo) return;
      const aberto = campo.dataset.expandido !== '1';
      if (aberto) campo.dataset.expandido = '1';
      else delete campo.dataset.expandido;
      delete campo.dataset.ajustado;
      campo.style.height = '';
      // Ver menos devolve o campo ao PADRÃO: a altura escrita à mão (pela alça
      // ou pela abertura anterior) sai junto, senão "recolher" não recolhia
      // nada em quem já tinha esticado o campo — que é quem mais usa o botão.
      if (!aberto) {
        campo.style.minHeight = '';
        campo.closest('.mb-3')?.classList.remove('altura-manual');
      }
      this.crescerTextarea(campo);
      this.pintarBotaoExpandir(b, aberto);
    }));
    this.ligarFerramentasInfo(raiz);
    this.ligarAlcas(raiz);
  },

  /** Estado visual da seta: o mesmo par em todos os lugares que a usam. */
  pintarBotaoExpandir(b, aberto, rotulos = ['Aumentar o campo', 'Diminuir o campo']) {
    b.textContent = aberto ? this.setaRecolher : this.setaExpandir;
    b.setAttribute('aria-expanded', String(aberto));
    b.title = aberto ? rotulos[1] : rotulos[0];
    b.setAttribute('aria-label', b.title);
  },

  /**
   * Ver mais / ver menos do bloco de leitura: aberto, o cartão mostra o
   * conteúdo inteiro; recolhido, volta ao corte de sempre (e a altura arrastada
   * na alça sai junto — recolher tem de recolher).
   */
  ligarFerramentasInfo(raiz) {
    raiz.querySelectorAll('.btn-expandir[data-alvo-info]').forEach((b) => b.addEventListener('click', () => {
      const bloco = document.getElementById(b.dataset.alvoInfo);
      const cartao = bloco?.querySelector('.card-info-modal');
      if (!cartao) return;
      const aberto = cartao.classList.toggle('info-aberto');
      bloco.classList.toggle('altura-manual', aberto);
      // Nos DOIS sentidos a altura arrastada na alça sai: a seta é uma decisão
      // NOVA sobre o tamanho. Mantendo-a, "ver mais" não abria nada em quem
      // tinha acabado de esticar o bloco à mão — que é justamente quem já
      // estava tentando ler o conteúdo inteiro.
      const corpo = cartao.querySelector('.card-body');
      if (corpo) corpo.style.height = '';
      cartao.classList.remove('info-altura-manual');
      this.pintarBotaoExpandir(b, aberto, ['Ver o conteúdo inteiro', 'Recolher o bloco']);
      this.marcarInfoRolavel(raiz);
    }));
  },

  /**
   * Arrastar a altura pela alça do canto — no dedo e no mouse.
   *
   * Eventos de PONTEIRO, e não de mouse: no celular é onde o campo aperta, e a
   * API de arrastar do HTML não existe no toque. O `setPointerCapture` mantém o
   * gesto na alça mesmo quando o dedo sai dela (arrastar rápido sempre sai), e
   * o `touch-action: none` do CSS é o que impede a página de rolar junto.
   *
   * A altura escolhida à mão MANDA a partir daí: o bloco ganha `altura-manual`
   * (que desliga o esticamento do flex do modal) e o campo de texto para de se
   * ajustar sozinho — senão a próxima tecla desfaria o tamanho escolhido.
   */
  ligarAlcas(raiz) {
    raiz.querySelectorAll('.alca-campo').forEach((alca) => {
      const paraTexto = !!alca.dataset.alca;
      const bloco = paraTexto
        ? document.getElementById(alca.dataset.alca)?.closest('.mb-3')
        : document.getElementById(alca.dataset.alcaInfo);
      const alvo = paraTexto
        ? document.getElementById(alca.dataset.alca)
        : bloco?.querySelector('.card-info-modal .card-body');
      if (!alvo || !bloco) return;

      let inicioY = 0;
      let inicioH = 0;
      const piso = () => (paraTexto ? this.alturaMinima(alvo) : 48);
      const mover = (ev) => {
        const teto = Math.round(window.innerHeight * 0.85);
        const h = Math.max(piso(), Math.min(teto, inicioH + (ev.clientY - inicioY)));
        alvo.style.height = `${h}px`;
        if (paraTexto) {
          alvo.style.minHeight = `${h}px`;
          alvo.dataset.ajustado = '1';
        }
      };
      const soltar = (ev) => {
        alca.removeEventListener('pointermove', mover);
        if (alca.hasPointerCapture?.(ev.pointerId)) alca.releasePointerCapture(ev.pointerId);
      };
      alca.addEventListener('pointerdown', (ev) => {
        // Sem o preventDefault o navegador começa a selecionar texto (mouse) ou
        // a rolar a página (dedo) no mesmo gesto.
        ev.preventDefault();
        inicioY = ev.clientY;
        inicioH = alvo.getBoundingClientRect().height;
        bloco.classList.add('altura-manual');
        if (!paraTexto) bloco.querySelector('.card-info-modal').classList.add('info-altura-manual');
        alca.setPointerCapture?.(ev.pointerId);
        alca.addEventListener('pointermove', mover);
        alca.addEventListener('pointerup', soltar, { once: true });
        alca.addEventListener('pointercancel', soltar, { once: true });
      });
    });
  },

  /**
   * O campo de dinheiro aceita número e só número.
   *
   * O filtro trabalha no `beforeinput` porque ele é o único que alcança TODAS
   * as formas de entrar texto — teclado, colar, arrastar — antes de o valor
   * mudar. Um `input` que limpasse depois já teria mexido no cursor.
   *
   * A vírgula é o separador que se digita em português e o ponto é o que o
   * servidor entende: aqui o ponto vira vírgula na tela, e `coletar()` faz o
   * caminho de volta. Aceitar os dois e mostrar um só evita o campo que recusa
   * exatamente a tecla que a pessoa aprendeu a usar para centavos.
   */
  ligarMoedas(raiz) {
    raiz.querySelectorAll('.campo-moeda').forEach((inp) => {
      inp.addEventListener('beforeinput', (ev) => {
        const bruto = ev.data ?? ev.dataTransfer?.getData('text') ?? '';
        if (bruto === '') {
          // Apagar, desfazer e mover o cursor passam — eles não trazem texto
          // novo. Mas uma INSERÇÃO cuja origem não conseguimos ler (o
          // arrastar-e-soltar não expõe o `dataTransfer` em todo navegador) é
          // recusada: seria o único caminho por onde texto sem filtro entraria
          // no campo, e o buraco só apareceria em quem arrasta.
          if (String(ev.inputType || '').startsWith('insert')) ev.preventDefault();
          return;
        }
        const pedaco = this.pedacoMoeda(bruto);
        const ini = inp.selectionStart ?? inp.value.length;
        const fim = inp.selectionEnd ?? inp.value.length;
        const candidato = inp.value.slice(0, ini) + pedaco + inp.value.slice(fim);
        // Sempre recusa a entrada original: ou ela era inválida, ou o pedaço
        // limpo é diferente dela (o ponto virou vírgula) e precisa ser inserido
        // por aqui. Deixar passar o original escreveria o caractere cru.
        ev.preventDefault();
        // Uma vírgula só, no máximo dois centavos e nada de sinal
        if (pedaco === '' || !/^\d*(,\d{0,2})?$/.test(candidato)) return;
        inp.setRangeText(pedaco, ini, fim, 'end');
        inp.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  },

  /**
   * Um pedaço de texto que entra no campo de dinheiro, reduzido a número
   * escrito em português. Vale tanto para a tecla solta quanto para o que se
   * COLA de uma planilha, que chega inteiro: "R$ 1.234,56", com símbolo,
   * separador de milhar e centavos.
   *
   * Sobrando mais de um separador, o ÚLTIMO é o decimal e os outros são de
   * milhar — é o que distingue "1.234,56" de "1,50". Sem essa leitura, colar um
   * valor formatado deixava o campo vazio, sem dizer por quê.
   *
   * O sinal de menos não é enfeite como o "R$": ele é parte do número. Por isso
   * o texto que o contém é recusado INTEIRO, em vez de entrar sem o sinal —
   * colar "-99" e ver 99 seria o campo mentindo sobre o que recebeu.
   */
  pedacoMoeda(bruto) {
    const texto = String(bruto);
    if (texto.includes('-')) return '';
    const cru = texto.replace(/[^0-9.,]/g, '');
    const partes = cru.split(/[.,]/);
    if (partes.length <= 1) return cru;
    const decimais = partes.pop();
    return `${partes.join('')},${decimais}`;
  },

  /** Pesquisa e contador das listas marcáveis. */
  ligarListasMarcaveis(raiz) {
    const norm = (s) => s.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[̀-ͯ]/g, '');
    raiz.querySelectorAll('.lista-marcavel').forEach((caixa) => {
      const itens = [...caixa.querySelectorAll('.marcavel-item')];
      const contador = caixa.querySelector('.marcavel-contador');
      const busca = caixa.querySelector('.marcavel-busca');

      // Na lista de escolha única não se conta o que está marcado (é sempre um
      // ou nenhum): o contador serve para dizer o que a pesquisa escondeu.
      const unico = !!caixa.querySelector('input[type=radio]');

      const contar = () => {
        const n = itens.filter((i) => i.querySelector('input').checked).length;
        const ocultos = itens.filter((i) => i.classList.contains('d-none')).length;
        contador.textContent = [
          unico ? (n ? '' : 'Nenhum item escolhido')
                : (n === 0 ? 'Nenhum item marcado' : `${n} marcado${n > 1 ? 's' : ''}`),
          ocultos ? `${ocultos} fora da pesquisa` : '',
        ].filter(Boolean).join(' · ');
      };

      caixa.querySelectorAll('input').forEach((ch) =>
        ch.addEventListener('change', contar));
      busca?.addEventListener('input', () => {
        const q = norm(busca.value.trim());
        // Um item marcado nunca some da lista: some sumiria da conta também
        itens.forEach((i) => i.classList.toggle(
          'd-none',
          q !== '' && !i.querySelector('input').checked && !norm(i.dataset.busca || '').includes(q)
        ));
        contar();
      });
      contar();
    });
  },

  ligarSelecaoLivre(raiz) {
    raiz.querySelectorAll('.combo-busca').forEach((caixa) => {
      const oculto = caixa.querySelector('input[type=hidden]');
      const alvo = caixa.querySelector('.combo-alvo');
      const painel = caixa.querySelector('.combo-painel');
      const pesquisa = caixa.querySelector('.combo-pesquisa');
      const lista = caixa.querySelector('.combo-lista');
      const { opcoes, vazio, obrigatorio } = this.combos[oculto.id]
        || { opcoes: [], vazio: '(não definido)', obrigatorio: false };

      const norm = (s) => s.toLocaleLowerCase('pt-BR')
        .normalize('NFD').replace(/[̀-ͯ]/g, '');

      const escolher = (valor) => {
        oculto.value = valor;
        alvo.innerHTML = valor
          ? this.esc(valor)
          : `<span class="text-muted">${this.esc(vazio)}</span>`;
        // O valor mora num `input[type=hidden]`, e campo escrito por código não
        // emite evento sozinho: sem este disparo, `visivelSe` e `aoMudar` nunca
        // ficariam sabendo da escolha feita neste controle.
        oculto.dispatchEvent(new Event('change', { bubbles: true }));
        fechar();
      };

      const montarLista = () => {
        const q = pesquisa.value.trim();
        const achados = q === '' ? opcoes : opcoes.filter((o) => norm(o).includes(norm(q)));
        const itens = [];
        // Campo obrigatório não oferece a opção de deixar em branco
        if (q === '' && !obrigatorio) {
          itens.push(`<button type="button" class="combo-item text-muted" data-valor="">${this.esc(vazio)}</button>`);
        }
        itens.push(...achados.map((o) =>
          `<button type="button" class="combo-item ${o === oculto.value ? 'ativo' : ''}"
             data-valor="${this.esc(o)}">${this.esc(o)}</button>`));
        // Sem correspondência exata: oferece usar o nome digitado
        if (q !== '' && !achados.some((o) => norm(o) === norm(q))) {
          itens.push(`<button type="button" class="combo-item combo-novo" data-valor="${this.esc(q)}">
            + Usar “${this.esc(q)}”</button>`);
        }
        lista.innerHTML = itens.join('')
          || `<div class="text-muted small px-2 py-1">Nenhum nome encontrado.</div>`;
        lista.querySelectorAll('.combo-item').forEach((b) =>
          b.addEventListener('click', () => escolher(b.dataset.valor)));
      };

      const abrir = () => {
        painel.classList.remove('d-none');
        alvo.setAttribute('aria-expanded', 'true');
        pesquisa.value = '';
        montarLista();
        pesquisa.focus();
      };
      const fechar = () => {
        painel.classList.add('d-none');
        alvo.setAttribute('aria-expanded', 'false');
      };

      alvo.addEventListener('click', () =>
        painel.classList.contains('d-none') ? abrir() : fechar());
      pesquisa.addEventListener('input', montarLista);
      pesquisa.addEventListener('keydown', (ev) => {
        // Enter escolhe o primeiro item (ou o nome digitado); Esc fecha só o painel
        if (ev.key === 'Enter') {
          ev.preventDefault();
          lista.querySelector('.combo-item:not(.text-muted)')?.click()
            || escolher(pesquisa.value.trim());
        } else if (ev.key === 'Escape') {
          ev.stopPropagation();
          fechar();
          alvo.focus();
        }
      });
      // Fechar ao clicar fora: o listener é do DOCUMENTO e o modal é reaberto
      // dezenas de vezes numa sessão. Registrado dentro do forEach e nunca
      // removido, cada abertura deixava mais um closure preso ao painel antigo,
      // que virava nó destacado. Sai junto com o modal.
      const foraDoCombo = (ev) => {
        if (!painel.classList.contains('d-none') && !ev.target.closest('.combo-busca')) fechar();
      };
      document.addEventListener('click', foraDoCombo);
      document.getElementById('modal-form')?.addEventListener('hidden.bs.modal', () => {
        document.removeEventListener('click', foraDoCombo);
      }, { once: true });
    });
  },

  /**
   * Valor atual de um campo, seja ele qual for. `botoes` e `quadrantes` não são
   * um controle só: o id fica numa div que agrupa os rádios, e `.value` nela é
   * `undefined` — um `visivelSe` apontado para um grupo de botões escondia o
   * campo dependente para sempre.
   */
  valorAtual(el) {
    if (!el) return '';
    if (el.tagName === 'DIV') return el.querySelector('input:checked')?.value ?? '';
    if (el.type === 'checkbox') return el.checked ? '1' : '';
    return el.value;
  },

  // Campos que só aparecem conforme o valor de outro (visivelSe: {campo, valores})
  ligarCondicionais(raiz, campos) {
    const condicionais = campos.filter((c) => c.visivelSe);
    if (!condicionais.length) return;
    const aplicar = () => {
      for (const c of condicionais) {
        const gatilho = document.getElementById(`campo-${c.visivelSe.campo}`);
        const bloco = document.getElementById(`campo-${c.nome}`)?.closest('.mb-3');
        if (!gatilho || !bloco) continue;
        bloco.classList.toggle('d-none', !c.visivelSe.valores.includes(this.valorAtual(gatilho)));
      }
    };
    new Set(condicionais.map((c) => c.visivelSe.campo)).forEach((nome) => {
      document.getElementById(`campo-${nome}`)?.addEventListener('change', aplicar);
    });
    aplicar();
  },

  /**
   * Avisa a tela dona a cada mudança de campo (ver `aoMudar` em `abrir`).
   *
   * O ouvinte fica no FORMULÁRIO e não em cada campo: o `change` sobe por
   * borbulhamento, e assim vale também para o que é desenhado por dentro
   * (`selecao_livre` guarda o valor num `input[type=hidden]`, que não emite
   * evento sozinho — quem o preenche dispara o `change` à mão).
   * O formulário é reconstruído a cada abertura (`innerHTML`), então não há
   * ouvinte antigo empilhado aqui.
   */
  ligarMudanca(raiz, aoMudar) {
    if (!aoMudar) return;
    const disparar = () => aoMudar(this.coletar(), raiz);
    raiz.addEventListener('change', disparar);
    raiz.addEventListener('input', disparar);
    disparar();
  },

  // O valor da faixa acompanha o arraste do controle
  ligarFaixas(raiz, campos) {
    raiz.querySelectorAll('input[type=range]').forEach((r) => {
      const sufixo = campos.find((c) => `campo-${c.nome}` === r.id)?.sufixo || '';
      const alvo = document.getElementById(`${r.id}-valor`);
      const min = Number(r.min || 0);
      const max = Number(r.max || 100);
      r.addEventListener('input', () => {
        if (alvo) alvo.textContent = `${r.value}${sufixo}`;
        // Pinta o trilho até a posição do pino
        const pct = max > min ? Math.round(((Number(r.value) - min) / (max - min)) * 100) : 0;
        r.style.setProperty('--pct', `${pct}%`);
      });
    });
  },

  // Campos de data sempre exibem dd/mm/aaaa: o texto nativo (que no iPhone
  // sai por extenso) fica transparente e um rótulo formatado é sobreposto;
  // o toque continua abrindo o calendário nativo
  ligarDatasBr(raiz) {
    raiz.querySelectorAll('input[type=date]').forEach((inp) => {
      if (inp.closest('.campo-data')) return;
      const caixa = document.createElement('div');
      caixa.className = 'campo-data';
      inp.parentNode.insertBefore(caixa, inp);
      caixa.appendChild(inp);
      const rotulo = document.createElement('span');
      rotulo.className = 'data-rotulo';
      caixa.appendChild(rotulo);
      const atualizar = () => {
        rotulo.textContent = inp.value ? inp.value.split('-').reverse().join('/') : 'dd/mm/aaaa';
        rotulo.classList.toggle('text-muted', !inp.value);
      };
      inp.addEventListener('input', atualizar);
      inp.addEventListener('change', atualizar);
      atualizar();
    });
  },

  ligarBotoesDitado(raiz) {
    raiz.querySelectorAll('.btn-ditar').forEach((b) =>
      b.addEventListener('click', () => this.alternarDitado(b)));
  },

  // Toque para gravar (botão pulsa em vermelho), fale, toque para parar —
  // o texto reconhecido é acrescentado ao campo, como no ditado do iPhone.
  alternarDitado(botao) {
    if (this.botaoGravando === botao) {
      this.pararDitado();
      return;
    }
    this.pararDitado();
    const campo = document.getElementById(botao.dataset.alvo);
    if (!campo) return;
    const Reconhecedor = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new Reconhecedor();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = false;
    rec.onresult = (ev) => {
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        if (ev.results[i].isFinal) {
          const texto = ev.results[i][0].transcript.trim();
          if (texto) {
            campo.value = campo.value ? `${campo.value.replace(/\s+$/, '')} ${texto}` : texto;
            // Atribuir `.value` por script não dispara 'input': sem este evento
            // o campo não cresce durante o ditado (`ligarTextareasElasticas`) e
            // a frase ditada some para fora da vista no meio do parágrafo.
            campo.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      }
    };
    rec.onend = () => this.pararDitado();
    rec.onerror = (ev) => {
      this.pararDitado();
      if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') {
        alert('Permita o acesso ao microfone no navegador para ditar por voz.');
      }
    };
    this.reconhecimento = rec;
    this.botaoGravando = botao;
    botao.classList.add('gravando');
    botao.title = 'Parar ditado';
    botao.setAttribute('aria-label', 'Parar ditado');
    try {
      rec.start();
    } catch {
      this.pararDitado();
    }
  },

  pararDitado() {
    if (this.reconhecimento) {
      this.reconhecimento.onend = null;
      try { this.reconhecimento.stop(); } catch { /* já parado */ }
    }
    if (this.botaoGravando) {
      this.botaoGravando.classList.remove('gravando');
      this.botaoGravando.title = 'Ditar por voz';
      this.botaoGravando.setAttribute('aria-label', 'Ditar por voz');
    }
    this.reconhecimento = null;
    this.botaoGravando = null;
  },

  // Olho de conferir senha: alterna visível/oculto no campo alvo
  ligarBotoesSenha(raiz) {
    raiz.querySelectorAll('.btn-ver-senha').forEach((b) => b.addEventListener('click', () => {
      const campo = document.getElementById(b.dataset.alvo);
      const mostrar = campo.type === 'password';
      campo.type = mostrar ? 'text' : 'password';
      b.innerHTML = mostrar ? this.iconeOlhoRiscado : this.iconeOlho;
      b.title = mostrar ? 'Ocultar senha' : 'Mostrar senha';
      b.setAttribute('aria-label', b.title);
    }));
  },

  coletar() {
    const dados = {};
    for (const c of this.config.campos) {
      if (c.tipo === 'periodo') {
        for (const s of c.campos || []) {
          dados[s.nome] = document.getElementById(`campo-${s.nome}`)?.value ?? '';
        }
        continue;
      }
      // `info` é bloco de leitura: tem id (para ser reescrito por `aoMudar`)
      // mas não tem valor, e sem esta saída ele entraria no corpo como `undefined`.
      // `arquivos` não viaja em JSON — o `enviar` os lê por `arquivosDe()`.
      if (c.tipo === 'info' || c.tipo === 'arquivos') continue;
      const el = document.getElementById(`campo-${c.nome}`);
      if (!el) continue;
      if (c.tipo === 'checkbox') dados[c.nome] = el.checked;
      else if (c.tipo === 'selecao_livre') dados[c.nome] = el.value.trim();
      else if (c.tipo === 'botoes' || c.tipo === 'quadrantes') {
        const marcado = el.querySelector('input:checked');
        dados[c.nome] = marcado ? (marcado.value === '' || isNaN(marcado.value) ? marcado.value : Number(marcado.value)) : null;
      }
      else if (c.tipo === 'multiselect') dados[c.nome] = Array.from(el.selectedOptions).map((o) => o.value);
      else if (c.tipo === 'dias') {
        dados[c.nome] = [...el.querySelectorAll('input:checked')].map((ch) => Number(ch.value));
      }
      else if (c.tipo === 'moeda') {
        // O caminho de volta de `moedaBr`: a vírgula da tela vira o ponto que o
        // servidor entende. Vazio é null, não zero — "não informado" e "zero"
        // são respostas diferentes.
        const bruto = el.value.trim().replace(',', '.');
        dados[c.nome] = bruto === '' ? null : Number(bruto);
      }
      else if (c.tipo === 'lista_marcavel') {
        const marcados = [...el.querySelectorAll('input:checked')].map((ch) => ch.value);
        // `unico` devolve o valor, não a lista de um: quem consome é campo de
        // id, e um array aqui viraria "Array" no corpo do pedido.
        dados[c.nome] = c.unico ? (marcados[0] ?? null) : marcados;
      }
      else if (c.tipo === 'number' || c.tipo === 'faixa') dados[c.nome] = el.value === '' ? null : Number(el.value);
      else dados[c.nome] = el.value;
    }
    return dados;
  },

  // Ação do botão extra (ex.: apagar a avaliação para refazê-la)
  async executarExtra() {
    const { extra } = this.config;
    if (extra.confirmar && !confirm(extra.confirmar)) return;
    const botao = document.getElementById('modal-extra');
    botao.disabled = true;
    try {
      const resposta = await extra.aoClicar();
      if (extra.manterAberto) {
        // Redefinir sem sair da tela: atualiza a lista por baixo e some com o
        // botão (nada mais a redefinir); o usuário continua editando no modal
        botao.classList.add('d-none');
        App.recarregarSecaoAtiva();
      } else {
        this.bsModal.hide();
        if (this.config.aoSalvar) this.config.aoSalvar(resposta);
        else App.recarregarSecaoAtiva();
      }
    } catch (e) {
      this.mostrarErro(e.message);
    } finally {
      botao.disabled = false;
    }
  },

  async salvar() {
    const botao = document.getElementById('modal-salvar');
    botao.disabled = true; // evita duplo clique criando registros duplicados
    try {
      const dados = this.coletar();
      // A resposta chega ao aoSalvar: alguns formulários precisam dela (uma
      // ação que se repete devolve a data em que vai reabrir, por exemplo)
      const corpo = this.config.transformar ? this.config.transformar(dados) : dados;
      const resposta = this.config.enviar
        ? await this.config.enviar(corpo)
        : await App.api(this.config.url, corpo);
      this.bsModal.hide();
      if (this.config.aoSalvar) this.config.aoSalvar(resposta);
      else App.recarregarSecaoAtiva();
    } catch (e) {
      this.mostrarErro(e.message);
    } finally {
      botao.disabled = false;
    }
  },

  /**
   * A recusa do servidor aparece acima do rodapé — e o rodapé é justamente
   * onde está o Salvar que acabou de ser clicado. Com o corpo rolado até o
   * fim, o aviso nascia fora da área visível e o formulário parecia ter
   * simplesmente ignorado o clique. O `scrollIntoView` traz o aviso à vista; o
   * foco fica onde está, para não tirar o cursor do campo que precisa mudar.
   */
  mostrarErro(mensagem) {
    const erro = document.getElementById('modal-erro');
    if (!erro) return;
    erro.textContent = mensagem;
    erro.classList.remove('d-none');
    erro.scrollIntoView({ block: 'nearest' });
  },

  esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  },
};

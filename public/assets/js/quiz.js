// A sala do PROJETO: um PIN para todas as análises.
//
// A faixa da sessão (PIN, QR, participantes, pergunta ativa, roteiro) é a
// MESMA em toda tela que conduz um quiz — Cenário, PESTEL, Porter, SWOT,
// Cascata. Escrita em cada seção, ela divergiria na primeira mudança de regra,
// como já aconteceu com `panorama()` e `camposAcao` no plano de ação.
//
// O componente não guarda estado: quem guarda é o DONO (a seção), num objeto
// com `plan`, `quiz`, `perguntaFoco` e `quizUi`. Duas seções compartilhando o
// "QR recolhido" recolheriam o QR uma da outra no meio do encontro.

const QuizSala = {
  /**
   * Estado ao vivo da sala. Devolve `{sessao: null}` em qualquer falha: a
   * faixa some, mas a tela continua funcionando sem o quiz.
   */
  async estado(planId, perguntaFoco) {
    return App.api(`/api/quiz?planejamento_id=${planId}`
      + (perguntaFoco ? `&pergunta_id=${perguntaFoco}` : ''))
      .catch(() => ({ sessao: null }));
  },

  /**
   * Abre a sala (ou acrescenta perguntas) tratando a COLISÃO: com a sala
   * aberta em outra análise o servidor responde 409/SALA_ABERTA com o nome da
   * tela, e aqui isso vira uma pergunta — confirmando, o mesmo pedido volta com
   * `confirmar_encerrar` e o servidor encerra a anterior e abre a nova de uma
   * vez só. Dois pedidos deixariam uma janela sem sala nenhuma.
   */
  async pedir(url, corpo) {
    try {
      return await App.api(url, corpo);
    } catch (e) {
      if (e.codigo !== 'SALA_ABERTA' || !confirm(e.message)) throw e;
      return App.api(url, { ...corpo, confirmar_encerrar: 1 });
    }
  },

  /** Rótulo curto da pergunta — o servidor já o calcula (App\Services\Quiz). */
  rotulo(p) {
    return p?.rotulo || '—';
  },

  // ---- Peças ----

  /**
   * O roteiro do encontro, com os botões de condução. Mora aqui porque a aba
   * Sala e (por enquanto) a faixa mostram a MESMA lista — duas cópias
   * divergiriam no primeiro estado novo de pergunta.
   */
  roteiro(dono) {
    const lista = dono.quiz?.roteiro || [];
    if (!lista.length) return '';
    const podeConduzir = App.podeEditar();
    const linha = (x, i) => {
      const selo = x.situacao === 'ATIVA'
        ? '<span class="badge text-bg-success">na sala</span>'
        : x.situacao === 'ENCERRADA'
          ? '<span class="badge text-bg-secondary">encerrada</span>'
          : '<span class="badge text-bg-light border">pendente</span>';
      const foco = dono.perguntaFoco === x.id ? ' em-foco' : '';
      return `<li class="linha-roteiro${foco}" data-pergunta="${x.id}">
        <span class="small num-roteiro">${i + 1}.</span>
        <span class="small flex-grow-1">${Modal.esc(this.rotulo(x))}
          ${Number(x.sugestoes) ? `<span class="text-muted">· ${x.sugestoes} sugestão(ões)</span>` : ''}</span>
        ${selo}
        <button class="btn btn-sm btn-outline-secondary" data-ver-pergunta="${x.id}"
          data-secao-pergunta="${Modal.esc(x.secao || '')}"
          title="Examinar as sugestões na tela de origem">Ver</button>
        ${podeConduzir && x.situacao !== 'ATIVA' ? `<button class="btn btn-sm btn-verde"
          data-ativar-pergunta="${x.id}">${x.situacao === 'ENCERRADA' ? 'Reabrir' : 'Abrir para a sala'}</button>` : ''}
        ${podeConduzir && x.situacao === 'ATIVA' ? `<button class="btn btn-sm btn-outline-secondary"
          data-encerrar-pergunta="${x.id}" title="Fechar sem abrir outra">Encerrar</button>` : ''}
        ${podeConduzir && x.situacao === 'PENDENTE' && !Number(x.sugestoes) ? `<button
          class="btn btn-sm btn-outline-danger" data-remover-pergunta="${x.id}"
          title="Tirar do roteiro" aria-label="Tirar do roteiro">×</button>` : ''}
      </li>`;
    };
    return `<ol class="lista-roteiro">${lista.map(linha).join('')}</ol>`;
  },

  /**
   * O SELO de uma análise: uma linha dizendo onde a sala está. Substitui a
   * faixa inteira nas telas de análise (PIN, QR e roteiro moram na aba Sala).
   *
   * Ele fala mesmo quando a sala está LONGE ("a sala está em Porter ·
   * Rivalidade"), com atalho para lá: saber que a sala está em outro lugar sem
   * poder ir até ela é meia informação — e o silêncio seria lido como "não tem
   * sala aberta", que é justamente quando alguém abre uma segunda.
   */
  selo(dono, secaoDaTela, aqui = null) {
    const q = dono.quiz;
    if (!q?.sessao) {
      return '<span class="selo-sala vazio">sala fechada</span>';
    }
    const p = q.pergunta;
    const gente = `<span class="small text-muted">${q.sessao.participantes} na sala</span>`;
    if (!p) {
      return `<span class="selo-sala aberta">sala aberta · nenhuma pergunta</span> ${gente}`;
    }
    // "Aqui" é a seção E o contexto que a tela mostra. Comparar só a seção fazia
    // o selo dizer "na sala" com a tela em 2027 e a sala em 2026: nenhum 🎤
    // aceso, nenhum painel, e nem o "Ir até lá" — que só aparecia quando a seção
    // diferia. Quem sabe se é o mesmo contexto é a tela, então ela responde.
    const mesmaTela = aqui === null ? p.secao === secaoDaTela : !!aqui;
    if (mesmaTela) {
      return `<span class="selo-sala aqui">🎤 ${Modal.esc(this.rotulo(p))} · na sala</span> ${gente}`;
    }
    return `<span class="selo-sala longe">a sala está em ${Modal.esc(p.tela)} ·
      ${Modal.esc(this.rotulo(p))}</span>
      <button class="btn btn-sm btn-outline-secondary" data-ir-sala="${Modal.esc(p.secao)}"
        data-ano-sala="${Modal.esc(p.ano ?? '')}">Ir até lá</button>
      ${gente}`;
  },

  ligarSelo(el) {
    el.querySelectorAll('[data-ir-sala]').forEach((b) => b.addEventListener('click', () => {
      // O atalho leva ao ANO da pergunta também: as análises de diagnóstico são
      // anuais e o seletor é compartilhado, então chegar na seção certa no ano
      // errado é chegar numa tela vazia.
      const ano = Number(b.dataset.anoSala);
      if (ano && typeof Diag !== 'undefined') Diag.anoSelecionado = ano;
      if (b.dataset.irSala === App.secaoAtiva) App.recarregarSecaoAtiva();
      else App.mostrarSecao(b.dataset.irSala);
    }));
  },

  /**
   * O 🎤 de um alvo. Aceso (`ativo`), ele é o INTERRUPTOR da pergunta: tocar de
   * novo a FECHA e a sala para de receber respostas ali mesmo, sem passar pela
   * aba Sala. Reabrir é tocar mais uma vez — a pergunta volta com as vozes que
   * já tinha.
   *
   * Fechar pede confirmação (em `ligarMicrofones`) e nunca deixa de pedir: o 🎤
   * é tocado duas vezes sem querer o tempo todo, e a segunda tocada não pode
   * calar a sala no meio da oficina. Foi por isso que ele já foi selo sem
   * toque nenhum; o que mudou é que agora existe o que fazer com o segundo
   * toque, não que o toque acidental tenha deixado de acontecer.
   */
  microfone(alvo, rotulo, { ativo = false, cor = null, pergunta = null } = {}) {
    if (!App.podeEditar()) return '';
    if (ativo) {
      if (!pergunta) {
        return '<span class="mic-sala ativo" title="A sala está respondendo isto">🎤</span>';
      }
      const t = 'A sala está respondendo isto — toque para fechar e parar de receber respostas';
      return `<button class="btn btn-sm mic-sala ativo" data-mic-fechar="${Number(pergunta)}"
        title="${t}" aria-label="${t}">🎤</button>`;
    }
    // O escape é feito AQUI, não pelo chamador: o rótulo pode ser texto que o
    // usuário edita (o nome de um eixo, em Cadastros), e um contrato em que
    // "cada chamador escapa o seu" quebra no primeiro que esquecer. A cor passa
    // pela mesma validação do campo `info` do modal — atributo `style` montado
    // com string de fora é a outra metade do mesmo problema.
    const seguro = Modal.esc(rotulo);
    const corOk = /^#[0-9a-f]{6}$/i.test(cor || '') ? cor : null;
    return `<button class="btn btn-sm mic-sala" data-mic='${Modal.esc(JSON.stringify(alvo))}'
      ${corOk ? `style="--cor-cat:${corOk}"` : ''}
      title="Perguntar ${seguro} à sala" aria-label="Perguntar ${seguro} à sala">🎤</button>`;
  },

  /**
   * Liga os 🎤 de `el`. Um toque manda o alvo para a sala; sem sala aberta, o
   * servidor devolve 409/SEM_SALA e aqui vira uma pergunta antes de criar a
   * sessão.
   */
  ligarMicrofones(dono, el) {
    el.querySelectorAll('[data-mic]').forEach((b) => b.addEventListener('click', async () => {
      let alvo;
      try {
        alvo = JSON.parse(b.dataset.mic);
      } catch (e) {
        return;
      }
      b.disabled = true;
      try {
        const r = await this.perguntar(dono, alvo);
        // Desistiu no confirm: não é erro, não alerta e não mexe em nada —
        // mostrar a MESMA pergunta de volta como alerta vermelho é confuso
        if (r === null) return;
        // O 🎤 torna este alvo a ATIVA, então o foco volta ao padrão. Sem isto o
        // painel ficava preso na pergunta que o condutor tinha EXAMINADO: ele
        // abria a categoria nova para a sala, os celulares respondiam, e ele
        // seguia lendo as vozes velhas — sem saída, porque `perguntaFoco` mora
        // na seção e sobrevive à navegação.
        dono.perguntaFoco = null;
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));

    // O toque no 🎤 aceso FECHA a pergunta: a sala para de receber respostas
    // sem que o condutor precise ir até a aba Sala. Encerrar não apaga nada —
    // as vozes ficam, e o mesmo 🎤 reabre a pergunta com elas.
    el.querySelectorAll('[data-mic-fechar]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Fechar esta pergunta? A sala para de receber respostas '
        + '— as sugestões já enviadas ficam, e o 🎤 reabre quando você quiser.')) return;
      b.disabled = true;
      try {
        await App.api(`/api/quiz/pergunta/${b.dataset.micFechar}/encerrar`,
          { planejamento_id: dono.plan.id });
        // A pergunta fechada continua sendo a que o condutor está lendo; o foco
        // é o que mantém o painel de vozes na tela em vez de esvaziá-lo junto
        dono.perguntaFoco = Number(b.dataset.micFechar);
      } catch (e) {
        alert(e.message);
      } finally {
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));
  },

  /**
   * As fichas de um conjunto de sugestões, em GRADE. Mora aqui porque as três
   * telas que mostram sugestões desenhavam o mesmo cartão — e escrever este
   * layout três vezes seria criar a divergência na primeira mudança.
   *
   * A grade (e não uma pilha) é o que faz caber: resposta de oficina costuma
   * ter duas ou três palavras, e uma ficha por linha, na largura da página,
   * gastava meia tela com cinco respostas.
   */
  /**
   * O par, quando a voz traz um — as respostas do alvo CRUZAMENTO.
   *
   * Sai dos DADOS e não de uma opção do chamador: a ficha mostra o que a voz
   * carrega, e uma bandeira `comPar` seria mais uma coisa para cada tela lembrar
   * de passar. Sem par, devolve nada e o cartão fica como sempre foi.
   *
   * O lado que perdeu o fator (a FK é `SET NULL`) aparece como tal, em vez de
   * sumir: a voz continua valendo, e quem conduz precisa saber que aquele
   * fator saiu da SWOT — se ela desaparecesse, o "Usar" falharia depois sem
   * explicação nenhuma.
   */
  parDaVoz(s) {
    if (!s.fator_interno_id && !s.fator_externo_id) return '';
    const lado = (id, descricao, categoria) => {
      if (!id) return '<span class="par-voz-lado vazio">— o fator foi excluído —</span>';
      const cor = Diag.CORES_QUADRANTE[categoria] || '#007a45';
      return `<span class="par-voz-lado" style="color:${cor};background:${cor}1f"
        title="${Modal.esc(descricao || '')}">${Modal.esc(descricao || '')}</span>`;
    };
    return `<div class="par-voz">
      ${lado(s.fator_interno_id, s.interno_descricao, s.interno_categoria)}
      <span class="par-voz-x">×</span>
      ${lado(s.fator_externo_id, s.externo_descricao, s.externo_categoria)}
    </div>`;
  },

  /**
   * `selo(s)` é um trecho de HTML por voz, acima do texto — a etiqueta da
   * categoria na pergunta da análise inteira, onde as vozes de todos os
   * quadrantes dividem a mesma grade em ordem de chegada. Mora no chamador
   * porque só ele sabe o que a etiqueta significa; a ficha só a posiciona.
   */
  fichas(sugestoes, { acao = 'Usar', virou = 'registro', podeUnir = false, marcar = false, selo = null } = {}) {
    // Duas leituras de "usada", conforme o que o rito faz com a voz:
    //
    //  - onde cada voz vira UM REGISTRO PRÓPRIO (um fator, um item de cenário),
    //    ela SAI do painel: o lugar dela agora é o quadrante de destino, e
    //    mantê-la aqui com um ✓ fazia a fila de trabalho crescer com o que já
    //    foi feito. Excluído o destino, ela é apagada junto
    //    (`Quiz::excluirVozes`) — excluir é descartar, não devolver à fila;
    //  - onde MUITAS vozes viram UM TEXTO SÓ (a síntese da célula da cascata),
    //    ela FICA, marcada de verde (`marcar`). Tirá-la da grade escondia
    //    justamente o que compõe a síntese — e desmarcar ficava sem onde ser
    //    clicado, já que o cartão era o único lugar do gesto.
    const abertas = marcar ? sugestoes : sugestoes.filter((s) => !Number(s.vinculada));
    if (!abertas.length) {
      const usadas = sugestoes.length;
      return `<div class="text-muted small">${usadas
        ? `${usadas === 1 ? 'A única sugestão já virou' : `Todas as ${usadas} sugestões já viraram`}
           ${Modal.esc(virou)}.`
        : 'Nenhuma sugestão ainda.'}</div>`;
    }
    const grupos = this.agrupar(abertas);
    // O 👁 nasce em toda ficha e SAI (em `ligarVozes`) da que cabe em três
    // linhas: numa resposta de duas palavras ele seria só ruído — e só depois
    // de pintar dá para saber qual é qual. Ele vem antes do `podeEditar()`
    // porque ler a resposta inteira é direito de quem só acompanha também.
    const cartao = (g) => {
      const s = g.lider;
      const inteiro = this.textoDoGrupo(g);
      // O estado é do CARTÃO inteiro, nunca de uma resposta unida: o que entra
      // na síntese é o bloco, e marcar meia caixa não teria significado nenhum
      const usado = marcar && [g.lider, ...g.unidas].some((x) => Number(x.usado));
      // As unidas viram LINHAS do mesmo cartão, cada uma com o ↩ que a devolve
      // ao lugar: unir é gesto de condução e precisa de desfazer imediato.
      const unidas = g.unidas.map((x) => `
        <div class="voz-unida">
          <span class="texto-voz" title="${Modal.esc(x.texto)}">${Modal.esc(x.texto)}</span>
          ${podeUnir ? `<button class="btn btn-outline-secondary btn-voz" data-separar-voz="${x.id}"
            title="Separar esta resposta de volta" aria-label="Separar esta resposta de volta">↩</button>` : ''}
        </div>`).join('');
      return `
      <div class="ficha-sugestao${g.unidas.length ? ' unificada' : ''}${podeUnir ? ' arrastavel' : ''}${
        usado ? ' usada' : ''}"
        ${podeUnir ? `data-arrastavel-voz="${s.id}"` : ''}>
        ${selo ? selo(s) : ''}
        ${this.parDaVoz(s)}
        <div class="texto-voz" title="${Modal.esc(inteiro)}">${Modal.esc(s.texto)}</div>
        ${unidas}
        <div class="rodape-voz">
          <span class="autor-voz" title="${Modal.esc(s.autor)}">${g.unidas.length
            ? `${g.unidas.length + 1} respostas unidas`
            : Modal.esc(s.autor)}${Number(g.votos) ? ` · ★ ${g.votos}` : ''}</span>
          <button class="btn btn-outline-secondary btn-voz" data-ver-voz aria-expanded="false"
            title="Ver a resposta inteira" aria-label="Ver a resposta inteira">👁</button>
          ${App.podeEditar() ? `
            <button class="btn ${usado ? 'btn-outline-verde' : 'btn-verde'} btn-voz"
              data-usar-sugestao="${s.id}"${marcar ? ` aria-pressed="${usado}"
              title="${usado ? 'Tirar esta resposta da síntese' : 'Levar esta resposta para a síntese'}"` : ''}>${
              usado ? 'Usado ✓' : Modal.esc(acao)}</button>
            <button class="btn btn-outline-danger btn-voz" data-excluir-sugestao="${s.id}"
              title="Excluir sugestão" aria-label="Excluir sugestão">×</button>` : ''}
        </div>
      </div>`;
    };
    return `<div class="grade-sugestoes">${grupos.map(cartao).join('')}</div>`;
  },

  /**
   * As vozes em GRUPOS: cada cartão é um líder (`agrupado_em_id` nulo) mais o
   * que foi arrastado para ele. É o MESMO mecanismo de grupo da tempestade —
   * nada é apagado ao unir, cada linha guarda o próprio texto, autor e votos.
   *
   * Voz cujo líder não está nesta lista (o líder virou registro, por exemplo)
   * volta a ser cartão próprio: some do painel sem ela seria perder a voz.
   */
  agrupar(abertas) {
    const porId = new Map(abertas.map((s) => [Number(s.id), s]));
    const grupos = new Map();
    const lider = (s) => {
      const pai = Number(s.agrupado_em_id || 0);
      return pai && porId.has(pai) ? pai : Number(s.id);
    };
    abertas.forEach((s) => {
      const id = lider(s);
      if (!grupos.has(id)) grupos.set(id, { lider: porId.get(id), unidas: [], votos: 0 });
      const g = grupos.get(id);
      if (Number(s.id) !== id) g.unidas.push(s);
      g.votos += Number(s.votos) || 0;
    });
    return [...grupos.values()].filter((g) => g.lider);
  },

  /** O texto do cartão inteiro: o do líder e o das unidas, um por linha. */
  textoDoGrupo(g) {
    return [g.lider.texto, ...g.unidas.map((x) => x.texto)].join('\n');
  },

  /**
   * O grupo de uma voz, para quem vai USAR o cartão: o texto de todas juntas e
   * os ids de todas. O vínculo é conjunto — aceitar o cartão unificado amarra
   * as vozes que ele reúne, senão as absorvidas ficariam no painel para sempre.
   */
  grupoDe(sugestoes, id, { marcar = false } = {}) {
    const abertas = marcar
      ? (sugestoes || [])
      : (sugestoes || []).filter((s) => !Number(s.vinculada));
    const g = this.agrupar(abertas).find((x) => Number(x.lider.id) === Number(id));
    if (!g) {
      const s = (sugestoes || []).find((x) => Number(x.id) === Number(id));
      return s ? { ...s, ids: [Number(s.id)], usado: !!Number(s.usado) } : null;
    }
    return {
      ...g.lider,
      texto: this.textoDoGrupo(g),
      ids: [Number(g.lider.id), ...g.unidas.map((x) => Number(x.id))],
      // O estado é do BLOCO: marcada uma resposta unida, o cartão está marcado
      usado: [g.lider, ...g.unidas].some((x) => !!Number(x.usado)),
    };
  },

  /**
   * Quantos CARTÕES o painel mostra — o contador conta cartão, não linha.
   *
   * Com `marcar`, a voz usada continua na grade: o número tem de contá-la, do
   * contrário marcar um cartão fazia o contador cair e prometia trabalho a
   * menos do que a coluna mostra.
   */
  contarCartoes(sugestoes, { marcar = false } = {}) {
    return this.agrupar(
      (sugestoes || []).filter((s) => marcar || !Number(s.vinculada))
    ).length;
  },

  /**
   * O que nas fichas depende de LAYOUT, e por isso só pode ser resolvido depois
   * de pintar: qual voz não coube em três linhas (ganha o 👁) e onde a grade
   * deve parar (duas fileiras; o resto rola, ou o condutor arrasta o canto).
   *
   * A altura escolhida no arraste mora no DONO, nunca no DOM: o polling repinta
   * a seção a cada mudança de assinatura e a grade voltaria sozinha ao padrão
   * bem no meio de uma leitura. Já o 👁 aberto é DOM puro, como o "ver mais"
   * dos cartões — o repintar o recolhe, e é isso mesmo: a voz mudou.
   */
  ligarVozes(dono, el) {
    const ui = dono.quizUi || (dono.quizUi = {});
    el.querySelectorAll('.ficha-sugestao').forEach((f) => {
      const texto = f.querySelector('.texto-voz');
      const olho = f.querySelector('[data-ver-voz]');
      if (!texto || !olho) return;
      if (texto.scrollHeight <= texto.clientHeight + 1) {
        olho.remove();
        return;
      }
      olho.addEventListener('click', () => {
        const aberta = f.classList.toggle('voz-aberta');
        olho.setAttribute('aria-expanded', String(aberta));
        olho.title = aberta ? 'Recolher a resposta' : 'Ver a resposta inteira';
      });
    });
    el.querySelectorAll('.grade-sugestoes').forEach((g) => {
      const ficha = g.querySelector('.ficha-sugestao');
      if (!ficha) return;
      const vao = parseFloat(getComputedStyle(g).rowGap) || 0;
      const duasFileiras = Math.round(ficha.offsetHeight * 2 + vao);
      if (ui.alturaGrade) g.style.height = `${ui.alturaGrade}px`;
      else if (g.scrollHeight > duasFileiras + 1) g.style.height = `${duasFileiras}px`;
      // O padrão fica guardado para o `pointerup` saber distinguir o arraste do
      // canto de um clique qualquer dentro da grade — que também solta o ponteiro
      // aqui e, sem essa comparação, congelaria a altura sem ninguém ter pedido.
      g.dataset.alturaPadrao = String(parseFloat(g.style.height) || 0);
      g.addEventListener('pointerup', () => {
        const altura = parseFloat(g.style.height) || 0;
        if (!altura) return;
        if (ui.alturaGrade || Math.abs(altura - Number(g.dataset.alturaPadrao)) > 1) {
          ui.alturaGrade = Math.round(altura);
        }
      });
    });
  },

  /**
   * Arrastar uma ficha sobre a outra UNE as duas — o gesto de consolidar, e só
   * com a pergunta já fechada (quem decide é a seção, que passa `podeUnir`).
   *
   * Mesmo padrão do arraste da Coleta, e pelas mesmas razões: eventos de
   * PONTEIRO (a API de arrastar do HTML não existe no toque), listeners no
   * `document` (a ficha é repintada durante o gesto) e limiar de 8px separando
   * o toque do arraste — sem ele, tocar em "Usar" perto da borda uniria fichas
   * sem ninguém pedir.
   */
  ligarUniao(dono, el) {
    if (!App.podeEditar()) return;
    el.querySelectorAll('[data-arrastavel-voz]').forEach((ficha) => {
      ficha.addEventListener('pointerdown', (ev) => {
        if (ev.button !== undefined && ev.button !== 0) return;
        // Botão nenhum inicia arraste: Usar, ×, 👁 e ↩ são atos próprios
        if (ev.target.closest('button')) return;
        const origem = { x: ev.clientX, y: ev.clientY };
        let arrastando = false;
        let alvoAtual = null;
        let ultimo = { x: ev.clientX, y: ev.clientY };
        let quadroRolagem = null;

        const limpar = () => alvoAtual?.classList.remove('alvo-juntar');
        const atualizarAlvo = (x, y) => {
          const sob = document.elementFromPoint(x, y)?.closest('[data-arrastavel-voz]');
          const novo = sob && sob !== ficha ? sob : null;
          if (novo !== alvoAtual) limpar();
          alvoAtual = novo;
          alvoAtual?.classList.add('alvo-juntar');
        };
        // A grade rola por dentro (teto de duas fileiras) e a página rola por
        // fora: perto das bordas as duas andam sozinhas, com o alvo recalculado
        // a cada quadro — senão o realce congela enquanto a tela desliza.
        const grade = ficha.closest('.grade-sugestoes');
        const rolar = () => {
          const margem = 60;
          const passo = 14;
          const caixa = grade?.getBoundingClientRect();
          if (caixa && grade.scrollHeight > grade.clientHeight + 1) {
            // A grade tem, por construção, a altura de DUAS fichas (~110px): a
            // margem da janela cobriria a caixa inteira, não sobraria ponto
            // neutro nenhum e o arraste saltaria sozinho para o extremo.
            const m = Math.min(margem, caixa.height / 6);
            if (ultimo.y < caixa.top + m) grade.scrollTop -= passo;
            else if (ultimo.y > caixa.bottom - m) grade.scrollTop += passo;
          }
          const alt = window.innerHeight;
          if (ultimo.y < margem) window.scrollBy(0, -passo);
          else if (ultimo.y > alt - margem) window.scrollBy(0, passo);
          atualizarAlvo(ultimo.x, ultimo.y);
          quadroRolagem = requestAnimationFrame(rolar);
        };

        const mover = (e) => {
          const dist = Math.hypot(e.clientX - origem.x, e.clientY - origem.y);
          if (!arrastando && dist < 8) return;
          if (!arrastando) {
            arrastando = true;
            // O polling não repinta no meio do gesto: a ficha sairia da mão
            dono.unindoVoz = true;
            ficha.classList.add('arrastando');
            document.body.classList.add('arrastando-ficha');
            quadroRolagem = requestAnimationFrame(rolar);
          }
          e.preventDefault();
          ultimo = { x: e.clientX, y: e.clientY };
          atualizarAlvo(e.clientX, e.clientY);
        };

        const soltar = async () => {
          document.removeEventListener('pointermove', mover);
          document.removeEventListener('pointerup', soltar);
          document.removeEventListener('pointercancel', soltar);
          if (quadroRolagem) cancelAnimationFrame(quadroRolagem);
          const alvo = alvoAtual;
          limpar();
          ficha.classList.remove('arrastando');
          document.body.classList.remove('arrastando-ficha');
          dono.unindoVoz = false;
          // Solto fora de outra ficha: nada acontece, nem erro nem seleção
          if (!arrastando || !alvo) return;
          try {
            await App.api(`/api/quiz/sugestao/${ficha.dataset.arrastavelVoz}/unir`, {
              planejamento_id: dono.plan.id,
              alvo: Number(alvo.dataset.arrastavelVoz),
            });
          } catch (erro) {
            alert(erro.message);
          }
          App.recarregarSecaoAtiva();
        };

        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar);
        document.addEventListener('pointercancel', soltar);
      });
    });

    // Desfazer: devolve a resposta ao cartão próprio, com o grupo que trouxe
    el.querySelectorAll('[data-separar-voz]').forEach((b) => b.addEventListener('click', async () => {
      b.disabled = true;
      try {
        await App.api(`/api/quiz/sugestao/${b.dataset.separarVoz}/separar`,
          { planejamento_id: dono.plan.id });
      } catch (erro) {
        alert(erro.message);
        b.disabled = false;
      }
      App.recarregarSecaoAtiva();
    }));
  },

  /**
   * O cabeçalho do painel: o que a sala responde, quantas vozes chegaram, e o
   * botão de recolher. Recolher atende o caso real — numa oficina cheia o
   * painel empurra as colunas da análise para fora da tela, e às vezes o
   * condutor quer trabalhar nos cartões.
   */
  cabecalhoPainel(dono, p, sugestoes, { marcar = false } = {}) {
    const quantas = {
      total: sugestoes.length,
      // Cartões, não linhas: unidas duas respostas, o painel mostra uma —
      // e o contador que prometesse duas mandaria procurar o que não existe
      abertas: this.contarCartoes(sugestoes, { marcar }),
    };
    const ui = dono.quizUi || (dono.quizUi = {});
    // Fechada e sendo a ÚLTIMA fechada, a sala não está parada: os celulares
    // estão pondo estrela nas respostas dela. Quem conduz precisa saber disso —
    // é o momento de ler a sala antes de abrir a próxima pergunta.
    const pontuando = p.situacao === 'ENCERRADA' && dono.quiz?.estrelas_em === p.id;
    const situacao = p.situacao === 'ATIVA'
      ? '<span class="badge text-bg-success">na sala agora</span>'
      : pontuando
        ? '<span class="badge text-bg-warning text-dark" title="A sala está marcando com '
          + 'estrela as respostas mais importantes desta pergunta">★ a sala está pontuando</span>'
        : p.situacao === 'ENCERRADA'
          ? '<span class="badge text-bg-secondary">encerrada</span>'
          : '<span class="badge text-bg-light border">não aberta</span>';
    return `<div class="d-flex align-items-center gap-2 flex-wrap cabecalho-quiz">
      <strong class="small text-uppercase">${Modal.esc(p.rotulo)}</strong>
      ${situacao}
      <span class="badge text-bg-light border" title="${marcar
        ? 'Cartões na grade, do total de vozes recebidas — marcar não muda a conta'
        : 'Vozes ainda não usadas, do total recebido'}">${
        quantas.abertas} de ${quantas.total} voz(es)</span>
      ${App.podeEditar() ? (p.situacao === 'ATIVA'
        // O par do "Reabrir": no MESMO lugar, o botão alterna conforme a
        // pergunta está aberta ou fechada. É a segunda forma de fechar a sala
        // (a primeira é o 🎤 aceso) e usa o mesmo `data-mic-fechar` — mesma
        // confirmação, mesmo pedido, mesma volta ao foco.
        ? `<button class="btn btn-sm btn-outline-secondary" data-mic-fechar="${p.id}"
             title="Fechar e parar de receber respostas">Fechar para a sala</button>`
        : `<button class="btn btn-sm btn-verde" data-reabrir-foco="${p.id}">${
            p.situacao === 'ENCERRADA' ? 'Reabrir para a sala' : 'Abrir para a sala'}</button>`) : ''}
      <button class="btn btn-sm btn-outline-secondary ms-auto" data-recolher-painel
        aria-expanded="${ui.painelRecolhido ? 'false' : 'true'}">${
        ui.painelRecolhido ? 'Mostrar' : 'Recolher'}</button>
    </div>`;
  },

  /** Liga o botão de recolher. O estado é do DONO, como o resto. */
  ligarRecolher(dono, el) {
    el.querySelectorAll('[data-recolher-painel]').forEach((b) => b.addEventListener('click', () => {
      const ui = dono.quizUi || (dono.quizUi = {});
      ui.painelRecolhido = !ui.painelRecolhido;
      App.recarregarSecaoAtiva();
    }));
  },

  /**
   * Liga os botões do roteiro. `dono.aoNavegar(pergunta)` é chamado antes de
   * recarregar quando o condutor examina outra pergunta — é onde cada tela se
   * posiciona (a célula da cascata, o ano do cenário) sem que o componente
   * precise saber o que ela mostra. Da aba Sala, "Ver" NAVEGA até a análise de
   * origem: examinar uma pergunta é ler as sugestões, e elas moram lá.
   */
  ligarRoteiro(dono, el) {
    const ui = dono.quizUi || (dono.quizUi = {});
    el.querySelectorAll('[data-ver-pergunta]').forEach((b) => b.addEventListener('click', async () => {
      const id = Number(b.dataset.verPergunta);
      const pergunta = (dono.quiz?.roteiro || []).find((x) => x.id === id);
      if (!pergunta) return;
      const secao = b.dataset.secaoPergunta;
      if (secao && secao !== App.secaoAtiva) {
        // O foco viaja para a seção de destino: ela é que sabe desenhar as
        // sugestões desta pergunta
        this.focoPendente = { secao, perguntaId: id, pergunta };
        App.mostrarSecao(secao);
        return;
      }
      dono.perguntaFoco = id;
      ui.roteiroAberto = true;
      if (dono.aoNavegar) dono.aoNavegar(pergunta);
      await dono.carregar();
    }));

    const conduzir = async (url) => {
      try {
        await App.api(url, { planejamento_id: dono.plan.id });
      } catch (e) {
        alert(e.message);
      }
      ui.roteiroAberto = true;
      App.recarregarSecaoAtiva();
    };
    el.querySelectorAll('[data-ativar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      // Abrir/reabrir MEXE na sala: o celular de todo mundo muda junto
      dono.perguntaFoco = null;
      conduzir(`/api/quiz/pergunta/${b.dataset.ativarPergunta}/ativar`);
    }));
    el.querySelectorAll('[data-encerrar-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Fechar esta pergunta? A sala vê "aguarde a próxima"; as sugestões ficam guardadas.')) return;
      conduzir(`/api/quiz/pergunta/${b.dataset.encerrarPergunta}/encerrar`);
    }));
    el.querySelectorAll('[data-remover-pergunta]').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('Tirar esta pergunta do roteiro?')) return;
      conduzir(`/api/quiz/pergunta/${b.dataset.removerPergunta}/remover`);
    }));
  },

  /**
   * Foco que atravessa a navegação: o "Ver" da aba Sala manda a seção de
   * destino abrir uma pergunta específica. Quem chega consome e limpa — senão
   * o foco voltaria a se aplicar na próxima visita à seção.
   */
  focoPendente: null,

  consumirFoco(secao) {
    if (this.focoPendente?.secao !== secao) return null;
    const f = this.focoPendente;
    this.focoPendente = null;
    return f;
  },

  /** O endereço que o participante abre no celular — o mesmo do QR. */
  linkEntrada(pin) {
    return `${location.origin}/entrar/${pin}`;
  },

  /**
   * Copiar o link e mandar pelo WhatsApp: nem toda sala tem telão, e nem todo
   * participante consegue mirar o QR (reunião híbrida, quem entra pelo
   * celular na mão). O WhatsApp é um link `wa.me` comum — sem SDK, sem
   * dependência externa, e o próprio aparelho escolhe entre o app e a web.
   */
  compartilhar(pin, tema) {
    if (!pin) return '';
    const url = this.linkEntrada(pin);
    const texto = `${tema ? `${tema}\n\n` : ''}Entre na sala pelo link: ${url}\nPIN: ${pin}`;
    return `<div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" type="button"
        data-copiar-link="${Modal.esc(url)}">Copiar link</button>
      <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener"
        href="https://wa.me/?text=${encodeURIComponent(texto)}">Compartilhar no WhatsApp</a>
    </div>`;
  },

  /** O "Copiar link" — com `prompt` de reserva onde a área de transferência
   *  não é liberada (http sem TLS, permissão negada). */
  ligarCompartilhar(el) {
    el.querySelectorAll('[data-copiar-link]').forEach((b) => b.addEventListener('click', async () => {
      const url = b.dataset.copiarLink;
      try {
        await navigator.clipboard.writeText(url);
        b.textContent = 'Link copiado';
        setTimeout(() => { b.textContent = 'Copiar link'; }, 1800);
      } catch (e) {
        prompt('Copie o link da sala:', url);
      }
    }));
  },

  /**
   * O campo da PERGUNTA da tempestade, definido uma vez só: o formulário que
   * abre a rodada e o que reescreve a pergunta usam o MESMO campo — escritos
   * separados, divergiriam no primeiro ajuste de rótulo ou de exemplo.
   * É `textarea` de propósito: é o campo de composição do sistema, o que traz
   * o botão de ditado e cresce com o texto. Numa linha só, a pergunta ditada
   * pela voz saía da vista no meio da frase.
   */
  campoPergunta(extra = {}) {
    return {
      nome: 'tema',
      rotulo: 'A pergunta que abre a tempestade',
      tipo: 'textarea',
      linhas: 2,
      obrigatorio: true,
      exemplo: 'O que pode atrapalhar o nosso resultado nos próximos três anos?',
      ...extra,
    };
  },

  /**
   * Reescrever a PERGUNTA da tempestade. Mora aqui porque quem edita é a tela
   * da CONDUÇÃO (a Coleta, onde as ideias chegam) e não a de projeção: trocar a
   * pergunta não mexe no PIN nem no QR, então mandar o condutor até a aba Sala
   * para isso era uma viagem sem motivo no meio da oficina.
   */
  modalPergunta(planId, rodada, aoSalvar) {
    Modal.abrir({
      titulo: 'Editar a pergunta da tempestade',
      url: `/api/rodadas/${rodada.id}/pergunta`,
      valores: { planejamento_id: planId, tema: rodada.tema },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        this.campoPergunta({
          ajuda: 'A pergunta nova chega ao celular de quem já está na sala; as '
            + 'ideias e os votos já enviados continuam onde estão.',
        }),
      ],
      aoSalvar,
    });
  },

  /** Desenha o QR de uma sessão dentro de `caixa` (some se não der). */
  desenharQr(caixa, pin) {
    if (!caixa) return;
    if (!pin || typeof qrcode !== 'function') { caixa.remove(); return; }
    try {
      const q = qrcode(0, 'M');
      q.addData(`${location.origin}/entrar/${pin}`);
      q.make();
      caixa.innerHTML = q.createSvgTag({ cellSize: 6, margin: 1, scalable: true });
    } catch (e) {
      caixa.remove();
    }
  },

  /**
   * POST /api/quiz/tela, tratando SEM_SALA (abre) e SALA_ABERTA (encerra).
   * Devolve `null` quando o condutor DESISTE — desistir não é erro.
   */
  async perguntar(dono, alvo) {
    const corpo = { planejamento_id: dono.plan.id, ...alvo };
    try {
      return await App.api('/api/quiz/tela', corpo);
    } catch (e) {
      const assumir = e.codigo === 'ASSUMIR_TEMPESTADE';
      if (e.codigo !== 'SEM_SALA' && !assumir) throw e;
      if (!confirm(e.message)) return null;
      // Assumir a tempestade não pede nome: a sala já tem um, e o PIN é o
      // mesmo. Só a sala que nasce do zero precisa ser batizada — sem nome, o
      // padrão do servidor serve e o condutor renomeia na aba Sala.
      const tema = assumir ? '' : (prompt('Nome do encontro (opcional):', '') ?? '');
      return this.pedir('/api/quiz/tela',
        { ...corpo, abrir_sala: 1, tema, ...(assumir ? { confirmar_encerrar: 1 } : {}) });
    }
  },

  /**
   * Consulta periódica da sessão, nos moldes da tempestade: 4s, e NUNCA
   * redesenha com um modal aberto ou um campo em foco — fecharia o modal do
   * condutor no meio da redação. Para sozinha quando a seção sai de cena (as
   * seções não são destruídas, só ganham d-none — o relógio sobreviveria).
   *
   * `dono.secaoId` é o id do elemento da seção; `dono.aoBater(quizNovo)` recebe
   * o estado novo e decide o que repintar.
   */
  armarRelogio(dono) {
    clearInterval(dono.relogioQuiz);
    dono.relogioQuiz = null;
    // Pintura de lado, para o Dossiê: ninguém está olhando esta tela, e o
    // relógio só existiria para descobrir isso na batida seguinte
    if (App.modoDossie) return;
    if (!dono.quiz?.sessao) return;
    dono.relogioQuiz = setInterval(async () => {
      const el = document.getElementById(dono.secaoId);
      if (!el || el.classList.contains('d-none')) {
        clearInterval(dono.relogioQuiz);
        dono.relogioQuiz = null;
        return;
      }
      if (document.querySelector('.modal.show')) return;
      // Arraste em curso: repintar tiraria a ficha da mão de quem a segura
      if (dono.unindoVoz) return;
      const ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT')) return;
      // Captura o foco DO DISPARO: se o condutor navegar pelo roteiro com esta
      // resposta em voo, ela chega falando de outra pergunta — aplicá-la
      // apagaria o painel que ele acabou de abrir.
      const focoPedido = dono.perguntaFoco;
      let quizNovo;
      try {
        quizNovo = await App.api(`/api/quiz?planejamento_id=${dono.plan.id}`
          + (focoPedido ? `&pergunta_id=${focoPedido}` : ''));
      } catch (e) {
        return; // rede piscou; a próxima batida tenta de novo
      }
      if (focoPedido !== dono.perguntaFoco) return;
      dono.quiz = quizNovo;
      dono.aoBater(quizNovo);
    }, 4000);
  },

  /**
   * Assinatura do estado, para o polling só repintar quando algo mudou.
   * Repintar à toa custa o foco e, no celular, o teclado aberto.
   */
  assinatura(q) {
    if (!q?.sessao) return 'sem-sessao';
    return JSON.stringify([
      q.sessao.participantes,
      q.pergunta?.id || 0,
      q.foco?.id || 0,
      // Em que pergunta a sala está pondo estrela: fechar o 🎤 já muda a ativa,
      // mas o selo "★ a sala está pontuando" também precisa aparecer quando
      // ela SAI (a próxima pergunta abre e a fase acaba)
      q.estrelas_em || 0,
      (q.roteiro || []).map((x) => [x.id, x.situacao, x.sugestoes]),
      (q.sugestoes || []).map((s) => [s.id, s.texto, s.votos, s.vinculada, s.tipo_resposta]),
    ]);
  },
};

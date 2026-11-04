/**
 * Reordenar por ARRASTE, em qualquer lista da casa.
 *
 * Nasceu no cadastro de drivers e eixos (linhas de tabela) e foi generalizado
 * em 2026-11-04, quando o cliente pediu o mesmo gesto nos cartões da Análise
 * de Cenário. Uma implementação só: as duas telas divergiriam na primeira
 * correção, e o que se corrige aqui é sempre a parte difícil — o arraste que
 * morre no primeiro reposicionamento, o toque que rola a página em vez de
 * mover o item, o teclado que não tem gesto nenhum.
 *
 * Três decisões que não devem ser desfeitas:
 *
 * 1. **Ponteiro, não HTML5.** `pointerdown/move/up` cobre mouse, caneta e dedo
 *    com um caminho só; o arraste HTML5 não existe no toque, e é por isso que
 *    o quadro do plano de ação não se arrasta no celular. Aqui o dedo funciona.
 * 2. **Ouvintes no `document`**, e não no elemento: mover o item dentro do DOM
 *    cancela a captura de ponteiro da alça, e o arraste morreria no primeiro
 *    reposicionamento.
 * 3. **Alça própria (`⠿`), e não o cartão inteiro.** O cartão tem botões,
 *    texto selecionável e "ver mais"; arrastar por ele faria o gesto competir
 *    com a leitura. A alça é um `<button>` focável, o que dá o caminho de
 *    teclado de graça (Ctrl/Alt + setas).
 */
const Ordenar = {
  /**
   * @param {Element} caixa    o contêiner que guarda os itens
   * @param {object}  opcoes
   *   - item:   seletor dos itens dentro da caixa (default `[data-id]`)
   *   - alca:   seletor da alça dentro de cada item (default `.alca-arrastar`)
   *   - aoSoltar(ids): grava a nova ordem; receber exceção desfaz nada, mas
   *     avisa — a lista fica como ficou na tela e o erro aparece.
   *   - depois(itens): chamado após gravar, para renumerar o que estiver à
   *     vista (o "Nº" do cadastro, o "1." do cartão do cenário).
   */
  ligar(caixa, { item = '[data-id]', alca = '.alca-arrastar', aoSoltar, depois = null } = {}) {
    if (!caixa) return;
    const itens = () => [...caixa.querySelectorAll(item)];
    const gravar = async (lista) => {
      try {
        await aoSoltar(lista.map((e) => Number(e.dataset.id)));
        if (depois) depois(lista);
      } catch (e) {
        alert(e.message);
      }
    };

    caixa.querySelectorAll(alca).forEach((pega) => {
      pega.addEventListener('pointerdown', (ev) => {
        ev.preventDefault();
        const alvo = pega.closest(item);
        if (!alvo) return;
        alvo.classList.add('item-arrastando');
        const mover = (e) => {
          const sob = document.elementFromPoint(e.clientX, e.clientY)?.closest(item);
          if (!sob || sob === alvo || sob.parentElement !== alvo.parentElement) return;
          const r = sob.getBoundingClientRect();
          if (e.clientY < r.top + r.height / 2) sob.parentElement.insertBefore(alvo, sob);
          else sob.parentElement.insertBefore(alvo, sob.nextSibling);
        };
        const soltar = async () => {
          document.removeEventListener('pointermove', mover);
          document.removeEventListener('pointerup', soltar);
          document.removeEventListener('pointercancel', soltar);
          alvo.classList.remove('item-arrastando');
          await gravar(itens());
        };
        document.addEventListener('pointermove', mover);
        document.addEventListener('pointerup', soltar);
        document.addEventListener('pointercancel', soltar);
      });

      // Sem mouse e sem toque também se reordena: Ctrl/Alt + seta move um
      // degrau. As setas sozinhas continuam rolando a página, que é o que
      // quem lê espera delas.
      pega.addEventListener('keydown', async (ev) => {
        const cima = ev.key === 'ArrowUp';
        const baixo = ev.key === 'ArrowDown';
        if ((!cima && !baixo) || !(ev.ctrlKey || ev.altKey)) return;
        ev.preventDefault();
        const alvo = pega.closest(item);
        const vizinho = cima ? alvo.previousElementSibling : alvo.nextElementSibling;
        if (!vizinho || !vizinho.matches(item)) return;
        if (cima) alvo.parentElement.insertBefore(alvo, vizinho);
        else alvo.parentElement.insertBefore(vizinho, alvo);
        // O foco vai junto com o item: sem isto, a segunda seta movia o
        // vizinho que tomou o lugar, e a lista embaralhava sozinha.
        pega.focus();
        await gravar(itens());
      });
    });
  },

  /** A alça, igual em toda lista ordenável. */
  alca(dica = 'Arrastar para reordenar (ou Ctrl + seta para cima/baixo)') {
    return `<button type="button" class="alca-arrastar" title="${dica}"
      aria-label="Reordenar: arraste, ou use Ctrl com as setas para cima e para baixo">⠿</button>`;
  },
};

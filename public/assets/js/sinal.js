/**
 * Tecla de sinal para os campos numéricos no CELULAR.
 *
 * O teclado numérico do iPhone (`inputmode="decimal"`) não tem o "−": quem
 * lançava uma despesa, uma variação ou um resultado negativo pelo celular não
 * tinha como (pedido do cliente, 2026-11-18). Trocar para o teclado de texto
 * resolveria o sinal e estragaria o que é a maioria dos lançamentos, números
 * positivos, que passariam a pedir três toques a mais.
 *
 * Em vez disso, o campo que aceita negativo (`data-sinal`) ganha, enquanto tem
 * o foco e SÓ em tela de toque, uma tecla "−/+" ao lado. Ela não tira o foco do
 * campo — um blur gravaria o que está pela metade, e na Projeção e em Metas a
 * gravação mora no blur — e só inverte o sinal do texto.
 *
 * Fica de fora, de propósito, o campo de dinheiro dos formulários
 * (`.campo-moeda`): o filtro dele recusa o sinal, porque ali valor negativo é
 * erro, e não lançamento.
 */
const Sinal = {
  botao: null,
  alvo: null,

  /** Tela de toque: onde o teclado numérico não tem o sinal. */
  toque() {
    return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
  },

  iniciar() {
    document.addEventListener('focusin', (ev) => this.aoFocar(ev.target));
    document.addEventListener('focusout', () => {
      // O foco passa por `body` no meio de uma troca de campo: só esconde se ele
      // de fato saiu do campo que a tecla serve.
      setTimeout(() => {
        if (this.alvo && document.activeElement !== this.alvo) this.esconder();
      }, 0);
    });
    const reposicionar = () => { if (this.alvo) this.posicionar(); };
    window.addEventListener('scroll', reposicionar, true);
    window.addEventListener('resize', reposicionar);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', reposicionar);
      window.visualViewport.addEventListener('scroll', reposicionar);
    }
  },

  aoFocar(el) {
    if (!el || !el.matches || !el.matches('input[data-sinal]') || !this.toque()) return;
    this.alvo = el;
    this.mostrar();
  },

  mostrar() {
    if (!this.botao) {
      const b = document.createElement('button');
      b.type = 'button';
      b.id = 'sinal-tecla';
      b.className = 'sinal-tecla';
      b.setAttribute('aria-label', 'Trocar o sinal: positivo ou negativo');
      b.textContent = '−/+';
      // `pointerdown` com preventDefault: o toque não rouba o foco do campo.
      // A troca acontece aqui e não no `click`, que chegaria depois do foco
      // perdido em alguns navegadores móveis.
      b.addEventListener('pointerdown', (ev) => {
        ev.preventDefault();
        this.alternar();
      });
      // Teclado e leitor de tela chegam por `click`, sem `pointerdown`.
      b.addEventListener('click', (ev) => {
        if (ev.detail === 0) this.alternar();
      });
      document.body.appendChild(b);
      this.botao = b;
    }
    this.botao.hidden = false;
    this.posicionar();
  },

  esconder() {
    this.alvo = null;
    if (this.botao) this.botao.hidden = true;
  },

  /** Ao lado do campo; sem espaço à esquerda, acima dele. */
  posicionar() {
    if (!this.alvo || !this.botao) return;
    const r = this.alvo.getBoundingClientRect();
    const lado = 40;
    let left = r.left - lado - 4;
    let top = r.top + (r.height - lado) / 2;
    if (left < 4) {
      left = Math.max(4, r.right - lado);
      top = r.top - lado - 4;
    }
    this.botao.style.left = `${Math.round(left)}px`;
    this.botao.style.top = `${Math.round(Math.max(4, top))}px`;
  },

  /** Inverte o sinal do texto do campo, sem mexer no resto. */
  alternar() {
    const el = this.alvo;
    if (!el) return;
    let v = el.value.trim();
    if (v.startsWith('-') || v.startsWith('−')) {
      v = v.slice(1).trim();
    } else {
      v = `-${v}`;
    }
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    try { el.setSelectionRange(v.length, v.length); } catch (e) { /* campo sem seleção */ }
  },
};
Sinal.iniciar();

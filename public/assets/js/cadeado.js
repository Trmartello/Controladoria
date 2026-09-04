/**
 * O cadeado dentro do formulário: contador, aviso e "+1 minuto".
 *
 * Enquanto alguém edita um item, ninguém mais o abre. Quem está editando vê
 * quanto tempo lhe resta e pede mais um minuto quando precisa.
 *
 * ## Por que renovação MANUAL
 *
 * A primeira versão do plano renovava sozinha enquanto o formulário estivesse
 * aberto. O furo: **um batimento prova que o navegador está aberto, não que
 * existe uma pessoa ali** — uma aba esquecida numa máquina ligada renovaria
 * para sempre, exatamente o caso que o batimento deveria cobrir. Por isso o
 * "+1 minuto" é um clique, e por isso ele pode ser ilimitado.
 *
 * ## O que acontece aos 0:00
 *
 * O cadeado cai e qualquer admin pode assumir o item. Mas **o texto continua
 * na tela e o Salvar continua tentando**: o servidor aceita de quem perdeu o
 * cadeado se ninguém o tiver assumido (`Bloqueio::exigirMeu`). Sem isso, quem
 * estivesse escrevendo aos 4:59 perderia o texto — e o recurso que existe para
 * não perder trabalho passaria a perder trabalho.
 *
 * ## Falha ABERTA
 *
 * Qualquer erro de rede aqui deixa editar. Um sistema de cadeados capaz de
 * impedir todo mundo de trabalhar é pior que a sobrescrita que ele previne.
 */
const Cadeado = {
  /** Abaixo disto o aviso aparece e o contador fica vermelho. */
  ALERTA: 60,

  atual: null,     // { recurso, registro_id, planejamento_id }
  restam: 0,
  relogio: null,

  /**
   * Toma o cadeado antes de o formulário abrir.
   *
   * Devolve o estado para quem chamou decidir. **Recusa só quando o servidor
   * disse, com todas as letras, que o item é de outro** — qualquer outro
   * desfecho (rede fora, rota com erro, resposta estranha) libera a edição.
   */
  async tomar(alvo) {
    if (!alvo) return { pode: true };
    try {
      const b = await App.api('/api/bloqueio', {
        recurso: alvo.recurso,
        registro_id: alvo.registro_id,
        planejamento_id: alvo.planejamento_id,
      });
      if (!b.meu && !b.livre) {
        return { pode: false, usuario: b.usuario, restam: b.restam };
      }
      return { pode: true, restam: b.restam };
    } catch (e) {
      return { pode: true }; // falha aberta, de propósito
    }
  },

  /** Começa a contar dentro do formulário já aberto. */
  iniciar(alvo, restam) {
    this.parar();
    if (!alvo) return;
    this.atual = alvo;
    this.restam = Number(restam) || 0;
    this.pintar();
    // 1s porque é um relógio de LEITURA: ele só desenha o que já sabe. O número
    // verdadeiro vem do servidor a cada tomada e a cada renovação — aqui não se
    // decide nada, só se mostra.
    this.relogio = setInterval(() => {
      this.restam = Math.max(0, this.restam - 1);
      this.pintar();
    }, 1000);
  },

  /**
   * Solta ao fechar.
   *
   * No caminho de FECHAR A ABA usa `fetch` com `keepalive`, e não
   * `navigator.sendBeacon`: o beacon não carrega cabeçalho, e o CSRF do sistema
   * só é aceito em `X-CSRF-Token` (`Auth::validarCsrf`) — o pedido morreria com
   * 419 sem soltar nada. O `keepalive` faz o mesmo papel (sobrevive à página
   * fechando) mantendo os cabeçalhos, e assim o contrato de CSRF continua
   * inteiro: abrir uma exceção nele para soltar cadeado seria caro demais pelo
   * que se ganha — a validade já cobre o caso de o pedido não sair.
   */
  soltar(aoSair = false) {
    const alvo = this.atual;
    this.parar();
    if (!alvo) return;
    const corpo = {
      recurso: alvo.recurso,
      registro_id: alvo.registro_id,
      planejamento_id: alvo.planejamento_id,
    };
    if (aoSair) {
      fetch('/api/bloqueio/soltar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': App.csrf },
        body: JSON.stringify(corpo),
        keepalive: true,
      }).catch(() => {});
      return;
    }
    App.api('/api/bloqueio/soltar', corpo).catch(() => {});
  },

  async renovar() {
    if (!this.atual) return;
    try {
      const b = await App.api('/api/bloqueio/renovar', {
        recurso: this.atual.recurso,
        registro_id: this.atual.registro_id,
        planejamento_id: this.atual.planejamento_id,
      });
      // O número vem do SERVIDOR, sempre: somar 60 aqui faria a tela divergir
      // do relógio que de fato decide quem pode salvar.
      this.restam = Number(b.restam) || 0;
      this.pintar();
    } catch (e) {
      // Falha aberta: quem está escrevendo continua escrevendo.
    }
  },

  parar() {
    clearInterval(this.relogio);
    this.relogio = null;
    this.atual = null;
    this.restam = 0;
  },

  /** A faixa dentro do formulário. Só existe quando há cadeado. */
  faixa() {
    return document.getElementById('modal-cadeado');
  },

  pintar() {
    const el = this.faixa();
    if (!el) return;
    if (!this.atual) {
      el.classList.add('d-none');
      el.innerHTML = '';
      delete el.dataset.fase;
      return;
    }
    const s = this.restam;
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    const alerta = s <= this.ALERTA;
    // A faixa muda de FASE só em três momentos (contando / alerta / acabou).
    // Fora deles, o tique de 1 s atualiza apenas o número: reescrever o
    // innerHTML a cada segundo destruía o botão "+1 minuto" com o foco em
    // cima — quem navega por Tab nunca chegava a acioná-lo, e um clique que
    // atravessasse o tique caía no contêiner, não no botão.
    const fase = s === 0 ? 'acabou' : (alerta ? 'alerta' : 'contando');
    el.classList.remove('d-none');
    el.className = `alert py-2 px-3 mb-3 d-flex align-items-center gap-2 flex-wrap ${
      s === 0 ? 'alert-danger' : (alerta ? 'alert-warning' : 'alert-secondary')}`;
    if (el.dataset.fase !== fase) {
      el.dataset.fase = fase;
      el.innerHTML = s === 0
        // Aos 0:00 a mensagem NÃO manda desistir: o texto continua valendo, e na
        // maioria das vezes o salvamento passa (ninguém assumiu o item).
        ? `<strong>O tempo de edição acabou.</strong>
           <span class="small">O item voltou a ficar disponível para os outros. Você ainda pode
           salvar — só não vai passar se alguém já tiver assumido.</span>
           <button type="button" class="btn btn-sm btn-outline-dark ms-auto" data-mais-tempo>
             Pegar de volta</button>`
        : `<span class="small">${alerta ? '<strong>Termina em</strong>' : 'Tempo de edição:'}</span>
           <strong class="tempo-cadeado" aria-live="${alerta ? 'polite' : 'off'}">${mm}:${ss}</strong>
           ${alerta ? '<span class="small">Peça mais tempo para não perder o item.</span>' : ''}
           <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-mais-tempo>
             +1 minuto</button>`;
      el.querySelector('[data-mais-tempo]')?.addEventListener('click', () => {
        // Aos 0:00 o botão vira "pegar de volta": o cadeado não existe mais, e
        // renovar não teria o que renovar — é uma tomada nova.
        if (this.restam === 0) {
          this.tomar(this.atual).then((r) => {
            if (r.pode) this.restam = Number(r.restam) || 0;
            this.pintar();
          });
          return;
        }
        this.renovar();
      });
      return;
    }
    const tempo = el.querySelector('.tempo-cadeado');
    if (tempo) tempo.textContent = `${mm}:${ss}`;
  },
};

// Fechar a aba com o formulário aberto solta o cadeado. É melhor esforço: o
// navegador pode não chegar a enviar, e para isso existe a validade.
window.addEventListener('pagehide', () => Cadeado.soltar(true));

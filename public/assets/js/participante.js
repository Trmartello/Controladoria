// Tela do participante da tempestade de ideias.
// Roda sem sessão: a identidade é o token devolvido ao entrar com o PIN, e
// vive só no sessionStorage desta aba. Nada aqui fala com o resto do sistema.

const Participante = {
  pin: '',
  token: '',
  nome: '',
  rodada: null,
  minhas: [],
  votacao: null,
  relogio: null,
  ultimaAssinatura: null,

  get tela() {
    return document.getElementById('tela');
  },

  esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  async api(url, corpo = null) {
    const r = await fetch(url, corpo
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) }
      : {});
    // Mesmo envelope do resto da API: { ok, dados } ou { ok:false, erro }
    const json = await r.json().catch(() => ({}));
    if (!r.ok || !json.ok) throw new Error(json.erro || 'Falha na comunicação.');
    return json.dados;
  },

  iniciar() {
    this.pin = this.tela.dataset.pin || '';
    // A sessão da aba guarda a entrada: recarregar a página não perde o lugar
    const guardado = sessionStorage.getItem('tempestade');
    if (guardado) {
      const g = JSON.parse(guardado);
      if (g.pin === this.pin) {
        this.token = g.token;
        this.nome = g.nome;
      }
    }
    if (this.pin && this.token) this.entrarNaSala();
    else this.telaEntrada();
  },

  // ---- Entrada ----
  telaEntrada(erro = '') {
    this.tela.innerHTML = `
      <div class="cartao-participante">
        <h1 class="h4 mb-1">Entrar na tempestade</h1>
        <p class="text-muted small mb-3">Peça o PIN a quem está conduzindo, ou escaneie o QR do telão.</p>
        ${erro ? `<div class="alert alert-danger py-2 small">${this.esc(erro)}</div>` : ''}
        <label class="form-label small" for="campo-pin">PIN da rodada</label>
        <input id="campo-pin" class="form-control form-control-lg campo-pin" inputmode="numeric"
          maxlength="6" autocomplete="off" placeholder="000000" value="${this.esc(this.pin)}">
        <label class="form-label small mt-3" for="campo-nome">Seu nome</label>
        <input id="campo-nome" class="form-control form-control-lg" maxlength="60"
          autocomplete="name" placeholder="Como você quer aparecer" value="${this.esc(this.nome)}">
        <button class="btn btn-verde btn-lg w-100 mt-3" id="btn-entrar">Entrar</button>
      </div>`;
    const entrar = async () => {
      const pin = document.getElementById('campo-pin').value.trim();
      const nome = document.getElementById('campo-nome').value.trim();
      try {
        const r = await this.api('/api/publico/entrar', { pin, nome });
        this.pin = pin;
        this.token = r.token;
        this.nome = r.nome;
        sessionStorage.setItem('tempestade', JSON.stringify({ pin, token: r.token, nome: r.nome }));
        this.entrarNaSala();
      } catch (e) {
        this.telaEntrada(e.message);
      }
    };
    document.getElementById('btn-entrar').addEventListener('click', entrar);
    document.getElementById('campo-nome').addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') entrar();
    });
  },

  pararRelogio() {
    clearInterval(this.relogio);
    this.relogio = null;
  },

  async entrarNaSala() {
    await this.atualizar();
    // A rodada pode encerrar ou abrir a votação a qualquer momento
    clearInterval(this.relogio);
    this.relogio = setInterval(() => this.atualizar(true), 4000);
  },

  /**
   * Está escrevendo? Redesenhar destrói o campo e, no celular, isso fecha o
   * teclado no meio da frase — o participante não consegue nem digitar.
   */
  digitando() {
    const ativo = document.activeElement;
    if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT')) return true;
    const campo = document.getElementById('campo-ideia');
    return !!(campo && campo.value.trim() !== '');
  },

  /** Só redesenha quando algo mudou de verdade. */
  assinatura() {
    return JSON.stringify([
      this.rodada?.situacao, this.rodada?.tema, this.votacao?.votacao,
      this.minhas.map((i) => i.id),
      (this.votacao?.itens || []).map((i) => [i.id, i.votei]),
      this.votacao?.meus_votos,
    ]);
  },

  async atualizar(silencioso = false) {
    try {
      this.rodada = await this.api(`/api/publico/rodada/${this.pin}`);
      if (this.rodada.situacao !== 'ABERTA') {
        this.minhas = [];
        this.votacao = null;
        this.pararRelogio();
        this.render();
        return;
      }
      this.minhas = await this.api(`/api/publico/minhas?pin=${this.pin}&token=${this.token}`);
      this.votacao = await this.api(`/api/publico/votar?pin=${this.pin}&token=${this.token}`);
    } catch (e) {
      if (!silencioso) this.telaEntrada(e.message);
      return;
    }
    // A consulta periódica nunca interrompe quem está escrevendo, e só toca na
    // tela quando há novidade
    if (silencioso && (this.digitando() || this.assinatura() === this.ultimaAssinatura)) return;
    this.ultimaAssinatura = this.assinatura();
    this.render();
  },

  // ---- Sala ----
  render() {
    const r = this.rodada;
    const rascunho = document.getElementById('campo-ideia')?.value ?? '';
    const encerrada = r.situacao !== 'ABERTA';
    const votando = this.votacao?.votacao === 'ABERTA';
    const restam = r.max_ideias - this.minhas.length;

    this.tela.innerHTML = `
      <div class="cartao-participante">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge text-bg-light border">PIN ${this.esc(this.pin)}</span>
          <span class="small text-muted flex-grow-1">${this.esc(this.nome)}</span>
        </div>
        <h1 class="h5 tema-rodada">${this.esc(r.tema || 'Tempestade de ideias')}</h1>

        ${encerrada
          ? '<div class="alert alert-secondary py-2 small mt-3">Esta rodada foi encerrada. Obrigado por participar!</div>'
          : votando ? this.blocoVotacao() : this.blocoEnvio(restam)}

        ${this.minhas.length && !votando ? `
          <div class="mt-4">
            <div class="rotulo-secao">Suas ideias</div>
            ${this.minhas.map((i) => `<div class="ideia-minha">${this.esc(i.texto)}</div>`).join('')}
          </div>` : ''}
      </div>`;

    if (!encerrada && !votando) {
      // Nada do que foi digitado se perde num redesenho inevitável
      const campo = document.getElementById('campo-ideia');
      if (campo && rascunho) campo.value = rascunho;
      this.ligarEnvio();
    }
    if (votando) this.ligarVotacao();
  },

  blocoEnvio(restam) {
    if (restam <= 0) {
      return `<div class="alert alert-success py-2 small mt-3">
        Você enviou todas as suas ideias. Aguarde a condução.</div>`;
    }
    return `
      <div class="mt-3">
        <label class="form-label small" for="campo-ideia">Sua ideia</label>
        <textarea id="campo-ideia" class="form-control" rows="4" maxlength="400"
          placeholder="Escreva como você diria em voz alta"></textarea>
        <div class="d-flex align-items-center gap-2 mt-2">
          <span class="small text-muted flex-grow-1">Pode enviar mais ${restam}.</span>
          <button class="btn btn-verde" id="btn-enviar">Enviar</button>
        </div>
        <div id="aviso-envio" class="small mt-2"></div>
      </div>`;
  },

  ligarEnvio() {
    const btn = document.getElementById('btn-enviar');
    const campo = document.getElementById('campo-ideia');
    const aviso = document.getElementById('aviso-envio');
    btn.addEventListener('click', async () => {
      const texto = campo.value.trim();
      if (!texto) return;
      btn.disabled = true;
      try {
        // O nome vem do registro no servidor, não daqui: enviá-lo permitiria
        // assinar a ideia com o nome de outra pessoa
        await this.api('/api/publico/ideia', { pin: this.pin, token: this.token, texto });
        campo.value = '';
        // A confirmação vai depois do redesenho, senão ele a apaga na hora
        await this.atualizar(true);
        const novo = document.getElementById('aviso-envio');
        if (novo) {
          novo.className = 'small mt-2 text-success';
          novo.textContent = 'Ideia enviada.';
        }
      } catch (e) {
        aviso.className = 'small mt-2 text-danger';
        aviso.textContent = e.message;
      } finally {
        btn.disabled = false;
      }
    });
  },

  // ---- Votação (fase opcional) ----
  blocoVotacao() {
    const v = this.votacao;
    const restam = v.max_votos - v.meus_votos;
    return `
      <div class="mt-3">
        <div class="alert alert-warning py-2 small">Escolha as ideias mais importantes.
          Restam <strong>${restam}</strong> voto(s). Toque de novo para desmarcar.</div>
        ${v.itens.map((i) => `
          <button type="button" class="ideia-votavel ${Number(i.votei) ? 'votada' : ''}"
            data-votar="${i.id}">
            <span class="voto-marca">${Number(i.votei) ? '★' : '☆'}</span>
            <span>${this.esc(i.texto)}</span>
          </button>`).join('') || '<p class="text-muted small">Nenhuma ideia para votar ainda.</p>'}
        <div id="aviso-voto" class="small mt-2"></div>
      </div>`;
  },

  ligarVotacao() {
    const aviso = document.getElementById('aviso-voto');
    this.tela.querySelectorAll('[data-votar]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await this.api(`/api/publico/votar/${b.dataset.votar}`, { pin: this.pin, token: this.token });
        aviso.textContent = '';
        await this.atualizar(true);
      } catch (e) {
        aviso.className = 'small mt-2 text-danger';
        aviso.textContent = e.message;
      }
    }));
  },
};

document.addEventListener('DOMContentLoaded', () => Participante.iniciar());

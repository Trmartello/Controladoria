/**
 * Duas telas abertas ao mesmo tempo, acompanhando-se sozinhas.
 *
 * O caso que isto resolve: numa reunião com a direção, mais de uma pessoa
 * preenche o plano ao mesmo tempo. Sem isto, quem não digitou só vê o que o
 * outro fez depois de apertar F5 — e numa sala onde o tempo é curto, "atualiza
 * aí" é atrito repetido dezenas de vezes.
 *
 * ## Como funciona
 *
 * Um relógio pergunta ao servidor a VERSÃO do planejamento (`/api/pulso`, uma
 * tabela de duas colunas) e, só quando ela muda, manda a seção se recarregar.
 * O caminho caro — reler o conteúdo — acontece apenas quando houve mudança de
 * verdade; o caminho frequente custa um inteiro.
 *
 * Foi por isso que o pulso é um contador no servidor, e não a comparação do
 * próprio conteúdo aqui: comparar payload exigiria baixar Projetos inteiro a
 * cada batida, de cada admin conectado, para quase sempre concluir que nada
 * mudou.
 *
 * ## As guardas, que são o coração disto
 *
 * Repintar na hora errada é pior que não repintar: perde-se o que a pessoa
 * estava escrevendo. Todas as guardas abaixo vieram do relógio da Sala
 * (`QuizSala.armarRelogio`), que já rodou em oficina — este arquivo existe para
 * que elas valham em TODA seção, e não só lá:
 *
 * - **seção escondida** → o relógio se desarma sozinho. As seções não são
 *   destruídas ao navegar, só ganham `d-none`; sem isto ficariam relógios de
 *   telas que ninguém está vendo, todos batendo.
 * - **modal aberto** → não repinta. A repintura descartaria o formulário que a
 *   pessoa está preenchendo.
 * - **foco num campo** → não repinta. Vale para a busca da análise e, no
 *   celular, para o teclado aberto.
 * - **modo Dossiê** → nem arma. Ali a seção é pintada de lado, para tirar foto
 *   do papel; ninguém está olhando.
 * - **rede piscou** → ignora a batida. A próxima tenta de novo, e um erro de
 *   consulta periódica não pode virar alerta na cara de quem está numa reunião.
 */
const Vivo = {
  /**
   * 4 segundos, o mesmo da Sala. É o intervalo em que a atualização parece
   * imediata para quem está ao lado, sem que a rota do pulso vire tráfego
   * constante: com cinco admins, são 75 leituras de uma linha por minuto.
   */
  INTERVALO: 4000,

  relogio: null,
  secaoId: null,
  versoes: null,

  /**
   * Arma o relógio para a seção que acabou de pintar.
   *
   * Um relógio SÓ em todo o sistema, e não um por seção: só existe uma seção
   * visível por vez, e cada `carregar()` chama isto de novo. Um por seção
   * exigiria que cada uma lembrasse de desarmar o seu — e a que esquecesse
   * ficaria batendo para sempre, invisível.
   *
   * `planIds` são os planejamentos que a seção lê. Quase sempre um (o do
   * contexto); a Matriz de Impacto passa dois, porque é lida no contexto de um
   * negócio e o conteúdo dela vive no plano corporativo.
   */
  armar(secaoId, planIds) {
    this.parar();
    if (App.modoDossie) return;
    const ciclo = App.contexto?.cicloId;
    const alvos = (planIds || []).map(Number).filter(Boolean);
    if (!ciclo || !alvos.length) return;

    this.secaoId = secaoId;
    // A referência é o estado de AGORA: a seção acabou de ler o conteúdo, então
    // o que o servidor disser a partir daqui é novidade. Sem esta primeira
    // leitura, a batida seguinte veria "mudou" para tudo e repintaria à toa.
    this.versoes = null;
    this.ler(ciclo).then((v) => { this.versoes = v; });

    this.relogio = setInterval(async () => {
      const el = document.getElementById(secaoId);
      if (!el || el.classList.contains('d-none')) {
        this.parar();
        return;
      }
      if (document.querySelector('.modal.show')) return;
      const ativo = document.activeElement;
      if (ativo && (ativo.tagName === 'TEXTAREA' || ativo.tagName === 'INPUT'
        || ativo.isContentEditable)) return;

      const novas = await this.ler(ciclo);
      if (!novas || !this.versoes) {
        if (novas) this.versoes = novas;
        return;
      }
      const mudou = alvos.some((id) => (novas[id] || 0) !== (this.versoes[id] || 0));
      if (!mudou) return;
      // Guarda a versão ANTES de repintar: `recarregarSecaoAtiva` chama
      // `carregar()`, que chama `armar()` de novo e zera este estado. Sem isto,
      // uma escrita durante a repintura seria perdida entre as duas leituras.
      this.versoes = novas;
      App.recarregarSecaoAtiva();
    }, this.INTERVALO);
  },

  async ler(ciclo) {
    try {
      return await App.api(`/api/pulso?ciclo_id=${ciclo}`);
    } catch (e) {
      return null; // rede piscou; a próxima batida tenta de novo
    }
  },

  parar() {
    clearInterval(this.relogio);
    this.relogio = null;
    this.secaoId = null;
    this.versoes = null;
  },
};

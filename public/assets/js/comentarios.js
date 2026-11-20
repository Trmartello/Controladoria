// Comentários com anexos — o bloco que lista, cria e apaga registros com
// arquivo (foto, PDF, planilha) pendurados num item do planejamento.
//
// Nasceu no plano de ação (projeto e ação) e saiu de lá em 2026-11-20, quando
// os itens da Análise de Cenário também passaram a receber anexos e
// apontamentos (pedido do cliente: "colocar uma imagem que represente um
// gráfico, ou até um PDF, um Excel"). Um código só para os dois lugares:
// escritos separados, divergiriam na primeira mudança.
//
// O servidor é o mesmo (`ComentarioController`): `ref_tipo` diz a que o
// registro pertence (PROJETO, DESDOBRAMENTO, ... CENARIO) e `ref_id` qual.

const Comentarios = {
  /** dd/mm/aaaa hh:mm a partir do DATETIME que o banco devolve. */
  quando(iso) {
    if (!iso) return '';
    const [data, hora] = String(iso).split(' ');
    return `${data.split('-').reverse().join('/')}${hora ? ` ${hora.slice(0, 5)}` : ''}`;
  },

  /** 162,9 KB / 2,1 MB — o mesmo jeito de escrever tamanho do resto do sistema. */
  tamanho(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} KB`;
    return `${(n / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
  },

  /**
   * A miniatura do anexo. Imagem mostra a própria imagem; documento mostra o
   * selo da extensão, com a cor do tipo — o ícone genérico de "arquivo" obriga
   * a ler o nome para saber se é a proposta ou a planilha.
   * A imagem é a original, encolhida por CSS: não há GD na imagem do PHP para
   * gerar miniatura no servidor, e o teto de 5 MB por arquivo segura o peso.
   */
  miniaturaAnexo(a, planId, podeApagar = false, ultimoSemTexto = false) {
    const url = `/api/anexos/${a.id}?planejamento_id=${planId}`;
    const ext = (a.nome.split('.').pop() || '').toUpperCase().slice(0, 4);
    const corpo = a.imagem
      ? `<img src="${url}" alt="${Modal.esc(a.nome)}" loading="lazy">`
      : `<span class="selo-ext selo-ext-${ext.toLowerCase()}">${Modal.esc(ext)}</span>`;
    // O × é IRMÃO do link, nunca filho: botão dentro de <a> é HTML inválido, e
    // o clique acabaria abrindo o anexo em vez de removê-lo.
    const excluir = podeApagar
      ? `<button type="button" class="anexo-excluir" data-excluir-anexo="${a.id}"
          data-anexo-nome="${Modal.esc(a.nome)}"${ultimoSemTexto ? ' data-ultimo="1"' : ''}
          title="Remover este anexo" aria-label="Remover o anexo ${Modal.esc(a.nome)}">×</button>`
      : '';
    // `download` no link do documento: ele já desce como anexo pelo servidor, e
    // o atributo evita a aba em branco que o navegador abre antes de baixar.
    return `<span class="anexo-item">
      <a class="anexo-mini" href="${url}" target="_blank" rel="noopener"
          ${a.imagem ? '' : 'download'} title="${Modal.esc(a.nome)}">
        <span class="anexo-face">${corpo}</span>
        <span class="anexo-nome">${Modal.esc(a.nome)}</span>
        <span class="anexo-tamanho">(${this.tamanho(a.tamanho)})</span>
      </a>
      ${excluir}
    </span>`;
  },

  /**
   * A imagem em tamanho de leitura: ocupa a largura do bloco, com altura
   * limitada, e o clique abre o original numa aba. O nome, o tamanho e o ×
   * ficam na legenda.
   */
  figuraAnexo(a, planId, podeApagar = false, ultimoSemTexto = false) {
    const url = `/api/anexos/${a.id}?planejamento_id=${planId}`;
    const excluir = podeApagar
      ? `<button type="button" class="anexo-excluir-legenda btn btn-sm btn-link p-0 text-danger"
          data-excluir-anexo="${a.id}" data-anexo-nome="${Modal.esc(a.nome)}"${ultimoSemTexto ? ' data-ultimo="1"' : ''}
          title="Remover esta imagem" aria-label="Remover a imagem ${Modal.esc(a.nome)}">×</button>`
      : '';
    return `<figure class="anexo-figura">
      <a href="${url}" target="_blank" rel="noopener" title="Abrir ${Modal.esc(a.nome)} em tamanho original">
        <img src="${url}" alt="${Modal.esc(a.nome)}" loading="lazy">
      </a>
      <figcaption class="small text-muted d-flex gap-2 align-items-center">
        <span class="text-truncate">${Modal.esc(a.nome)} (${this.tamanho(a.tamanho)})</span>${excluir}
      </figcaption>
    </figure>`;
  },

  /**
   * Lista os registros de um item dentro de `alvo`, com o "+" de novo registro
   * e os × de apagar. `aoMudar` roda depois de criar ou apagar — quem chama
   * decide o que repintar (o bloco, ou também as contagens do item).
   *
   * `rotulos` troca as palavras sem trocar o comportamento: no plano de ação o
   * bloco é "Comentários"; no cenário, "Anexos e apontamentos".
   */
  async render(alvo, { planId, refTipo, refId, aoMudar, rotulos = {}, imagensAbertas = false }) {
    if (!alvo) return;
    const r = {
      titulo: 'Comentários', novo: 'Novo comentário', vazio: 'Nenhum comentário ainda.',
      ...rotulos,
    };
    const lista = await App.api(
      `/api/comentarios?planejamento_id=${planId}&ref_tipo=${refTipo}&ref_id=${refId}`);
    const eu = App.sessao.usuario;
    const depois = aoMudar || (() => this.render(alvo, { planId, refTipo, refId, aoMudar, rotulos, imagensAbertas }));

    const itens = lista.map((c) => {
      const inicial = (c.autor || '?').trim().charAt(0).toUpperCase();
      const podeApagar = Number(c.autor_id) === Number(eu.id) || eu.perfil === 'ADMIN';
      // Remover o único anexo de um comentário sem texto apaga o comentário —
      // é a regra do servidor. A tela precisa saber disso ANTES de perguntar,
      // para avisar do que vai acontecer em vez de só relatar depois.
      const ultimoSemTexto = (c.anexos || []).length === 1 && !(c.texto || '').trim();
      // Com `imagensAbertas` (o cenário), a FOTO aparece inteira, na largura
      // do bloco: é um gráfico para ler ali mesmo, sem abrir o anexo (pedido
      // do cliente, 2026-11-20). Documento continua na grade de miniaturas.
      const todos = c.anexos || [];
      const fotos = imagensAbertas ? todos.filter((a) => a.imagem) : [];
      const resto = imagensAbertas ? todos.filter((a) => !a.imagem) : todos;
      const figuras = fotos.map((a) => this.figuraAnexo(a, planId, podeApagar, ultimoSemTexto)).join('');
      const anexos = (figuras ? `<div class="figuras-anexos">${figuras}</div>` : '')
        + (resto.length ? `<div class="grade-anexos">${resto
          .map((a) => this.miniaturaAnexo(a, planId, podeApagar, ultimoSemTexto)).join('')}</div>` : '');
      return `<div class="comentario d-flex gap-2">
        <span class="avatar-inicial" aria-hidden="true">${Modal.esc(inicial)}</span>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-items-baseline gap-2 flex-wrap">
            <strong class="small">${Modal.esc(c.autor)}</strong>
            <span class="small text-muted flex-grow-1">${this.quando(c.criado_em)}</span>
            ${podeApagar ? `<button class="btn btn-sm btn-link p-0 text-danger"
              data-excluir-comentario="${c.id}" title="Excluir registro"
              aria-label="Excluir registro">×</button>` : ''}
          </div>
          ${c.texto ? `<div class="small texto-comentario">${Modal.esc(c.texto)}</div>` : ''}
          ${anexos}
        </div>
      </div>`;
    }).join('');

    // Abrir mostra o que JÁ EXISTE (pedido do cliente): escrever é o gesto do
    // "+" ao lado do título, que abre o formulário em modal.
    const btnNovo = App.podeEditar()
      ? `<button class="btn btn-sm btn-verde px-2 py-0" data-novo-comentario
          title="${Modal.esc(r.novo)}" aria-label="${Modal.esc(r.novo)}">+</button>` : '';

    alvo.innerHTML = `<div class="card bg-light"><div class="card-body py-2">
      <div class="d-flex align-items-center gap-2 mb-2">
        <strong class="small text-uppercase">${Modal.esc(r.titulo)}</strong>
        ${btnNovo}
        <span class="small text-muted ms-auto">${lista.length} registro(s)</span>
      </div>
      ${itens || `<div class="text-muted small">${Modal.esc(r.vazio)}</div>`}
    </div></div>`;

    alvo.querySelector('[data-novo-comentario]')
      ?.addEventListener('click', () => this.modal({ planId, refTipo, refId, aoSalvar: depois, titulo: r.novo }));

    alvo.querySelectorAll('[data-excluir-comentario]').forEach((b) =>
      b.addEventListener('click', async () => {
        if (!confirm('Excluir este registro? Os anexos dele saem junto.')) return;
        try {
          await App.api(`/api/comentarios/${b.dataset.excluirComentario}/excluir`, { planejamento_id: planId });
        } catch (e) {
          alert(e.message);
        }
        depois();
      }));

    // Remover UM anexo. Antes disso, tirar o arquivo errado custava o
    // comentário inteiro — apagar tudo e reescrever, com o texto junto.
    alvo.querySelectorAll('[data-excluir-anexo]').forEach((b) =>
      b.addEventListener('click', async () => {
        const nome = b.dataset.anexoNome || 'este anexo';
        const pergunta = b.dataset.ultimo
          ? `Remover “${nome}”? É o único anexo e o registro não tem texto — o registro sai junto.`
          : `Remover o anexo “${nome}”? O texto e os demais anexos ficam.`;
        if (!confirm(pergunta)) return;
        try {
          await App.api(`/api/anexos/${b.dataset.excluirAnexo}/excluir`, { planejamento_id: planId });
        } catch (e) {
          alert(e.message);
        }
        depois();
      }));
  },

  /**
   * O formulário do novo registro, em modal — texto e anexos.
   *
   * É a fábrica de sempre (`Modal.abrir`), com `enviar` próprio: o corpo é
   * multipart porque leva arquivo, e `App.api` só fala JSON — este é o único
   * `fetch` na mão do sistema, com o mesmo header de CSRF, que é o que a rota
   * confere. Os arquivos não passam por `coletar()` (arquivo não viaja em
   * JSON): o `enviar` os lê direto do campo, por `Modal.arquivosDe`.
   */
  modal({ planId, refTipo, refId, aoSalvar, titulo = 'Novo comentário' }) {
    Modal.abrir({
      titulo,
      campos: [
        { nome: 'texto', rotulo: refTipo === 'CENARIO' ? 'Apontamento' : 'Comentário', tipo: 'textarea', linhas: 4,
          exemplo: refTipo === 'CENARIO'
            ? 'O que este anexo mostra, ou uma observação sobre o item...'
            : 'Descreva o andamento, bloqueios ou próximos passos...' },
        { nome: 'arquivos', rotulo: 'Anexos', tipo: 'arquivos', multiplo: true,
          aceita: '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt',
          ajuda: 'Até 5 arquivos por registro, 5 MB cada — imagem, PDF, Word, Excel, PowerPoint, CSV ou TXT.' },
      ],
      enviar: async (corpo) => {
        const texto = (corpo.texto || '').trim();
        const arquivos = Modal.arquivosDe('arquivos');
        // A recusa acontece AQUI, não no clique: lançada, ela aparece no aviso
        // do modal (`mostrarErro`), que é onde quem acabou de salvar está olhando.
        if (!texto && !arquivos.length) throw new Error('Escreva o texto ou anexe um arquivo.');
        const dados = new FormData();
        dados.append('planejamento_id', planId);
        dados.append('ref_tipo', refTipo);
        dados.append('ref_id', refId);
        dados.append('texto', texto);
        for (const arquivo of arquivos) dados.append('arquivos[]', arquivo);
        const resposta = await fetch('/api/comentarios', {
          method: 'POST',
          headers: { 'X-CSRF-Token': App.csrf },
          body: dados,
        });
        const json = await resposta.json().catch(() => ({}));
        if (!resposta.ok || json.ok === false) {
          throw new Error(json.erro || 'Não foi possível enviar.');
        }
        return json;
      },
      aoSalvar: () => aoSalvar && aoSalvar(),
    });
  },
};

// Projetos estratégicos e operacionais: desdobramentos 5W2H + diário de bordo.

const STATUS_ROTULOS = {
  NAO_INICIADO: ['Não iniciado', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluído', 'text-bg-success'],
  ATRASADO: ['Atrasado', 'text-bg-danger'],
  CANCELADO: ['Cancelado', 'text-bg-secondary'],
};
const OPCOES_STATUS = Object.entries(STATUS_ROTULOS)
  .map(([valor, [rotulo]]) => ({ valor, rotulo }));

// Ações: "No prazo" e "Atrasada" saem da data-limite; os demais são manuais
const STATUS_ACAO = {
  NAO_INICIADO: ['No prazo', 'text-bg-info'],
  ATRASADO: ['Atrasada', 'text-bg-danger'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDO: ['Concluída', 'text-bg-success'],
  CANCELADO: ['Cancelada', 'text-bg-secondary'],
  PAUSADO: ['Pausada', 'text-bg-warning'],
  AGUARDANDO_VALIDACAO: ['Aguardando validação', 'text-bg-light border'],
};
const STATUS_MANUAIS = ['EM_ANDAMENTO', 'CONCLUIDO', 'CANCELADO', 'PAUSADO', 'AGUARDANDO_VALIDACAO'];
const PRIORIDADES = {
  ALTA: ['Alta', '#b3261e'], MEDIA: ['Média', '#b08d4f'], BAIXA: ['Baixa', '#2c7fb8'],
};
// 1 = segunda … 7 = domingo (mesma numeração do PHP: date('N'))
const DIAS_SEMANA = [
  [1, 'Segunda-feira'], [2, 'Terça-feira'], [3, 'Quarta-feira'], [4, 'Quinta-feira'],
  [5, 'Sexta-feira'], [6, 'Sábado'], [7, 'Domingo'],
];
const STATUS_INICIATIVA = {
  ABERTA: ['Aberta', 'text-bg-light border'],
  EM_ANDAMENTO: ['Em andamento', 'text-bg-primary'],
  CONCLUIDA: ['Concluída', 'text-bg-success'],
};

const SecaoProjetos = {
  plan: null,
  cascata: null,
  responsaveis: [],
  diarioAberto: null, // { refTipo, refId }
  // Recolhidos guardam quem está fechado; projeto e iniciativa começam
  // abertos e a escolha do usuário sobrevive aos recarregamentos da seção
  iniciativasFechadas: new Set(),
  projetosFechados: new Set(),
  detalhesAbertos: new Set(), // quem está com o "mostrar mais" aberto

  /** Alternador de detalhe de um item (projeto, iniciativa ou ação). */
  botaoMais(chave, aberto) {
    return `<button type="button" class="btn-mais" data-mais="${chave}">
      ${aberto ? 'mostrar menos' : 'mostrar mais'}</button>`;
  },

  ligarBotoesMais(el) {
    el.querySelectorAll('[data-mais]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const chave = b.dataset.mais;
      const alvo = el.querySelector(`[data-detalhe="${chave}"]`);
      const abrir = this.detalhesAbertos.has(chave);
      if (abrir) this.detalhesAbertos.delete(chave);
      else this.detalhesAbertos.add(chave);
      alvo.classList.toggle('d-none', abrir);
      b.textContent = abrir ? 'mostrar mais' : 'mostrar menos';
    }));
  },

  // Período escolhido no calendário; textos antigos (prazo/quando_) seguem valendo
  periodo(inicio, fim, legado) {
    const br = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : null);
    const de = br(inicio);
    const ate = br(fim);
    if (de && ate) return `${de} a ${ate}`;
    if (de) return `a partir de ${de}`;
    if (ate) return `até ${ate}`;
    return legado || null;
  },

  // Bloco da iniciativa (frente de trabalho) com as ações dentro
  blocoIniciativa(p, ini) {
    const acoes = ini.acoes || [];
    const feitas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
    const [rotIni, classeIni] = STATUS_INICIATIVA[ini.status] || [ini.status, 'text-bg-light'];
    const aberta = !this.iniciativasFechadas.has(ini.id);
    const cartoes = acoes.map((a) => this.cartaoAcao(p, ini, a)).join('');
    // Recolhida mostra só título e situação; o resto vai no "mostrar mais"
    const chave = `ini-${ini.id}`;
    const detalhado = this.detalhesAbertos.has(chave);
    return `<div class="iniciativa mb-2" data-iniciativa="${ini.id}">
      <div class="d-flex align-items-center gap-2 flex-wrap iniciativa-cabeca" data-abrir-ini="${ini.id}">
        <span class="seta-iniciativa">${aberta ? '▾' : '▸'}</span>
        <strong class="small flex-grow-1">${Modal.esc(ini.titulo)}</strong>
        <span class="badge ${classeIni}">${rotIni}</span>
        ${this.botaoMais(chave, detalhado)}
      </div>
      <div class="detalhe-item ${detalhado ? '' : 'd-none'}" data-detalhe="${chave}">
        ${ini.descricao ? `<div class="small text-muted mt-1">${Modal.esc(ini.descricao)}</div>` : ''}
        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
          <span class="badge text-bg-light border" title="Ações concluídas">${feitas}/${acoes.length} ações</span>
          ${App.podeEditar() ? `
            <button class="btn btn-sm btn-verde" data-nova-acao="${ini.id}" data-proj="${p.id}">+ Ação</button>
            <button class="btn btn-sm btn-outline-secondary" data-editar-ini="${ini.id}" data-proj="${p.id}"
              title="Editar iniciativa" aria-label="Editar iniciativa">✎</button>
            <button class="btn btn-sm btn-outline-danger" data-excluir-ini="${ini.id}"
              title="Excluir iniciativa" aria-label="Excluir iniciativa">×</button>` : ''}
        </div>
      </div>
      <div class="acoes-iniciativa ${aberta ? '' : 'd-none'}">
        ${cartoes || '<div class="text-muted small">Nenhuma ação nesta iniciativa.</div>'}
      </div>
    </div>`;
  },

  cartaoAcao(p, ini, a) {
    const [rotulo, classe] = STATUS_ACAO[a.status] || [a.status, 'text-bg-light'];
    const [rotPrio, corPrio] = PRIORIDADES[a.prioridade] || PRIORIDADES.MEDIA;
    const prazo = this.periodo(a.data_inicio, a.data_fim, a.quando_);
    const detalhes = [
      a.quem && `Quem: ${a.quem}`,
      a.onde && `Onde: ${a.onde}`,
      a.como && `Como: ${a.como}`,
      a.por_que && `Por quê: ${a.por_que}`,
      a.quanto !== null && a.quanto !== undefined && `Quanto: R$ ${Number(a.quanto).toLocaleString('pt-BR')}`,
    ].filter(Boolean).map(Modal.esc).join(' · ');
    const timeline = this.diarioAberto?.refTipo === 'DESDOBRAMENTO' && this.diarioAberto?.refId === a.id
      ? `<div id="diario-DESDOBRAMENTO-${a.id}" class="mt-2"></div>` : '';
    const repeticao = a.recorrencia === 'SEMANAL'
      ? `toda ${(DIAS_SEMANA.find(([v]) => v == a.recorrencia_dia) || [, ''])[1].toLowerCase()}`
      : a.recorrencia === 'MENSAL' ? `todo dia ${a.recorrencia_dia}` : '';
    // Visível: o que é, como está e o progresso. O resto fica no "mostrar mais".
    const chave = `acao-${a.id}`;
    const detalhado = this.detalhesAbertos.has(chave);
    const extras = [
      prazo && `<strong>Prazo:</strong> ${Modal.esc(prazo)}`,
      `<strong>Prioridade:</strong> ${rotPrio}`,
      repeticao && `<strong>Repete:</strong> ${repeticao}`,
      detalhes,
    ].filter(Boolean).join(' · ');
    return `<div class="card acao-card mb-2" style="--cor-prio:${corPrio}" data-card-acao="${a.id}">
      <div class="card-body py-2 px-2">
        <div class="d-flex align-items-center gap-1 flex-wrap">
          <span class="badge ${classe}">${rotulo}</span>
          <span class="small flex-grow-1">${Modal.esc(a.o_que)}</span>
        </div>
        <div class="d-flex align-items-center gap-2 mt-2">
          ${App.podeEditar() ? `
          <input type="range" class="faixa-verde flex-grow-1" min="0" max="100" step="1"
            style="--pct:${a.progresso}%" value="${a.progresso}"
            data-progresso="${a.id}" data-proj="${p.id}"
            title="Arraste para ajustar o progresso" aria-label="Progresso da ação">
          <span class="valor-progresso" data-rotulo="${a.id}">${a.progresso}%</span>` : `
          <div class="faixa-progresso flex-grow-1" title="${a.progresso}%">
            <span style="width:${a.progresso}%"></span>
          </div>
          <span class="valor-progresso">${a.progresso}%</span>`}
          ${this.botaoMais(chave, detalhado)}
        </div>
        <div class="detalhe-item ${detalhado ? '' : 'd-none'}" data-detalhe="${chave}">
          ${extras ? `<div class="small text-muted mt-2">${extras}</div>` : ''}
          <span class="d-flex gap-1 flex-wrap mt-2">
            <button class="btn btn-sm btn-outline-success" data-diario="DESDOBRAMENTO:${a.id}">Diário</button>
            ${App.podeEditar() ? `
              <button class="btn btn-sm btn-outline-secondary" data-editar-desd="${a.id}" data-proj="${p.id}"
                title="Editar ação" aria-label="Editar ação">✎</button>
              <button class="btn btn-sm btn-outline-danger" data-excluir-desd="${a.id}"
                title="Excluir ação" aria-label="Excluir ação">×</button>` : ''}
          </span>
        </div>
        ${timeline}
      </div>
    </div>`;
  },

  async carregar() {
    const el = document.getElementById('secao-projetos');
    const params = App.contextoParams();
    if (!params) {
      el.innerHTML = '<div class="alert alert-info">Selecione o ciclo e o negócio no menu ☰.</div>';
      return;
    }
    this.plan = await App.planejamento();
    const [projetos, cascata, responsaveis] = await Promise.all([
      App.api(`/api/projetos?planejamento_id=${this.plan.id}`),
      App.api(`/api/cascata?planejamento_id=${this.plan.id}`),
      App.api(`/api/responsaveis?planejamento_id=${this.plan.id}`),
    ]);
    this.cascata = cascata;
    this.responsaveis = responsaveis;

    const badge = (status) => {
      const [rotulo, classe] = STATUS_ROTULOS[status] || [status, 'text-bg-light'];
      return `<span class="badge ${classe}">${rotulo}</span>`;
    };

    const cartoes = projetos.map((p) => {
      // O prazo é consequência das ações: menor início e maior fim entre elas
      const detalhes = [
        p.ano && `<strong>Ano:</strong> ${p.ano}`,
        p.responsavel && `<strong>Responsável:</strong> ${Modal.esc(p.responsavel)}`,
        this.periodo(p.data_inicio, p.data_fim, p.prazo)
          && `<strong>Prazo (das ações):</strong> ${Modal.esc(this.periodo(p.data_inicio, p.data_fim, p.prazo))}`,
      ].filter(Boolean).join(' · ');
      const descricao = p.descricao
        ? `<div class="small text-muted texto-fator mt-1">${Modal.esc(p.descricao)}</div>` : '';
      const origem = p.escolha_origem
        ? `<div class="small text-muted mt-1">↳ Escolha da cascata: “${Modal.esc(p.escolha_origem.slice(0, 90))}”</div>` : '';
      const media = p.desdobramentos.length
        ? Math.round(p.desdobramentos.reduce((s, d) => s + Number(d.progresso), 0) / p.desdobramentos.length)
        : 0;

      const iniciativas = (p.iniciativas || []).map((ini) => this.blocoIniciativa(p, ini)).join('');

      const timelineProjeto = this.diarioAberto?.refTipo === 'PROJETO' && this.diarioAberto?.refId === p.id
        ? `<div id="diario-PROJETO-${p.id}" class="mt-2"></div>` : '';

      // Panorama do projeto: some no cabeçalho o que está dentro dele
      const acoes = p.desdobramentos || [];
      const concluidas = acoes.filter((a) => a.status === 'CONCLUIDO').length;
      const atrasadas = acoes.filter((a) => a.status === 'ATRASADO').length;
      const aberto = !this.projetosFechados.has(p.id);

      // Recolhido mostra só título, situação e a barra; o resto no "mostrar mais"
      const chave = `proj-${p.id}`;
      const detalhado = this.detalhesAbertos.has(chave);
      return `<div class="card mb-3" data-projeto="${p.id}">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="projeto-cabeca flex-grow-1" data-abrir-proj="${p.id}" role="button" tabindex="0">
              <span class="seta-projeto">${aberto ? '▾' : '▸'}</span>
              <strong>${Modal.esc(p.titulo)}</strong>
              ${p.classificacao === 'PRIORITARIO' ? '<span class="badge text-bg-warning ms-1">Prioritário</span>' : ''}
              ${badge(p.status)}
            </div>
            ${this.botaoMais(chave, detalhado)}
          </div>
          <div class="d-flex align-items-center gap-2 mt-1 panorama-projeto">
            <div class="faixa-progresso flex-grow-1" style="max-width:180px"
              title="Progresso médio das ações">
              <span data-barra-projeto style="width:${media}%"></span>
            </div>
            <span class="valor-progresso" data-media-projeto>${media}%</span>
            ${atrasadas ? `<span class="badge text-bg-danger">${atrasadas} atrasada(s)</span>` : ''}
          </div>
          <div class="detalhe-item ${detalhado ? '' : 'd-none'}" data-detalhe="${chave}">
            ${descricao}
            ${detalhes ? `<div class="small text-muted mt-1">${detalhes}</div>` : ''}
            <div class="small text-muted mt-1">${(p.iniciativas || []).length} iniciativa(s) ·
              ${concluidas}/${acoes.length} ações concluídas</div>
            ${origem}
            <div class="d-flex gap-1 flex-wrap mt-2">
              <button class="btn btn-sm btn-outline-success" data-diario="PROJETO:${p.id}">Diário</button>
              ${App.podeEditar() ? `
                <button class="btn btn-sm btn-verde" data-nova-ini="${p.id}">+ Iniciativa</button>
                <button class="btn btn-sm btn-outline-secondary" data-editar-proj="${p.id}">Editar</button>
                <button class="btn btn-sm btn-outline-danger" data-excluir-proj="${p.id}">×</button>` : ''}
            </div>
          </div>
          <div class="mt-3 iniciativas-projeto ${aberto ? '' : 'd-none'}">
            ${iniciativas || '<div class="text-muted small">Nenhuma iniciativa cadastrada. Crie uma frente de trabalho para organizar as ações.</div>'}
          </div>
          ${timelineProjeto}
        </div>
      </div>`;
    }).join('');

    // Tudo recolhido = panorama; expandido = trabalho no detalhe
    const tudoFechado = projetos.length > 0 && projetos.every((p) => this.projetosFechados.has(p.id));
    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1>Projetos — ${Modal.esc(App.rotuloContexto())}</h1>
        <div class="d-flex gap-2">
          ${projetos.length ? `<button class="btn btn-outline-secondary btn-sm" id="btn-alternar-tudo">
            ${tudoFechado ? 'Expandir tudo' : 'Recolher tudo'}</button>` : ''}
          ${App.podeEditar() ? '<button class="btn btn-verde btn-sm" id="btn-novo-proj">+ Novo projeto</button>' : ''}
        </div>
      </div>
      <p class="text-muted">Toque no título para recolher e expandir; use “mostrar mais” para ver o detalhe.</p>
      <div class="pt-2">${cartoes || '<div class="text-muted">Nenhum projeto cadastrado.</div>'}</div>`;

    document.getElementById('btn-alternar-tudo')?.addEventListener('click', () => {
      if (tudoFechado) {
        this.projetosFechados.clear();
        this.iniciativasFechadas.clear();
      } else {
        projetos.forEach((p) => {
          this.projetosFechados.add(p.id);
          (p.iniciativas || []).forEach((i) => this.iniciativasFechadas.add(i.id));
        });
      }
      this.carregar();
    });

    // Acordeão do projeto (clicar no cabeçalho abre/fecha as iniciativas)
    el.querySelectorAll('[data-abrir-proj]').forEach((c) => {
      const alternar = () => {
        const id = parseInt(c.dataset.abrirProj, 10);
        if (this.projetosFechados.has(id)) this.projetosFechados.delete(id);
        else this.projetosFechados.add(id);
        const cartao = el.querySelector(`[data-projeto="${id}"]`);
        const fechado = this.projetosFechados.has(id);
        cartao.querySelector('.iniciativas-projeto').classList.toggle('d-none', fechado);
        cartao.querySelector('.seta-projeto').textContent = fechado ? '▸' : '▾';
      };
      c.addEventListener('click', (ev) => {
        if (ev.target.closest('button, a, input')) return;
        alternar();
      });
      c.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); alternar(); }
      });
    });

    this.ligarBotoesMais(el);

    el.querySelectorAll('[data-diario]').forEach((b) => b.addEventListener('click', () => {
      const [refTipo, refId] = b.dataset.diario.split(':');
      const mesmo = this.diarioAberto?.refTipo === refTipo && this.diarioAberto?.refId === Number(refId);
      this.diarioAberto = mesmo ? null : { refTipo, refId: Number(refId) };
      this.carregar();
    }));
    if (this.diarioAberto) this.renderDiario();

    if (!App.podeEditar()) return;

    document.getElementById('btn-novo-proj').addEventListener('click', () => this.modalProjeto(null, projetos));
    el.querySelectorAll('[data-editar-proj]').forEach((b) => b.addEventListener('click', () =>
      this.modalProjeto(projetos.find((p) => p.id == b.dataset.editarProj), projetos)));
    el.querySelectorAll('[data-excluir-proj]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir o projeto e todos os seus desdobramentos?')) return;
      try {
        await App.api(`/api/projetos/${b.dataset.excluirProj}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-nova-ini]').forEach((b) => b.addEventListener('click', () =>
      this.modalIniciativa(parseInt(b.dataset.novaIni, 10), null)));
    el.querySelectorAll('[data-editar-ini]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      this.modalIniciativa(proj.id, proj.iniciativas.find((i) => i.id == b.dataset.editarIni));
    }));
    el.querySelectorAll('[data-excluir-ini]').forEach((b) => b.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      if (!confirm('Excluir a iniciativa e todas as ações dentro dela?')) return;
      try {
        await App.api(`/api/iniciativas/${b.dataset.excluirIni}/excluir`, { planejamento_id: this.plan.id });
      } catch (e) {
        alert(e.message);
      }
      this.carregar();
    }));
    el.querySelectorAll('[data-nova-acao]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      this.modalDesdobramento(parseInt(b.dataset.proj, 10), null, parseInt(b.dataset.novaAcao, 10));
    }));
    el.querySelectorAll('[data-editar-desd]').forEach((b) => b.addEventListener('click', () => {
      const proj = projetos.find((p) => p.id == b.dataset.proj);
      const acao = proj.desdobramentos.find((dd) => dd.id == b.dataset.editarDesd);
      // A barra do cartão pode ter mudado o progresso depois da carga da lista;
      // vale o que está na tela, senão o modal regravaria o valor antigo
      const barra = el.querySelector(`[data-progresso="${acao.id}"]`);
      const atual = barra ? { ...acao, progresso: Number(barra.value) } : acao;
      this.modalDesdobramento(proj.id, atual, acao.iniciativa_id);
    }));
    // Acordeão das iniciativas (clicar no cabeçalho abre/fecha)
    el.querySelectorAll('[data-abrir-ini]').forEach((c) => c.addEventListener('click', (ev) => {
      if (ev.target.closest('button')) return;
      const id = parseInt(c.dataset.abrirIni, 10);
      if (this.iniciativasFechadas.has(id)) this.iniciativasFechadas.delete(id);
      else this.iniciativasFechadas.add(id);
      const bloco = el.querySelector(`[data-iniciativa="${id}"]`);
      bloco.querySelector('.acoes-iniciativa').classList.toggle('d-none', this.iniciativasFechadas.has(id));
      bloco.querySelector('.seta-iniciativa').textContent = this.iniciativasFechadas.has(id) ? '▸' : '▾';
    }));
    el.querySelectorAll('[data-excluir-desd]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Excluir este desdobramento?')) return;
      await App.api(`/api/desdobramentos/${b.dataset.excluirDesd}/excluir`, { planejamento_id: this.plan.id });
      this.carregar();
    }));

    // Progresso ajustado arrastando a própria barra do cartão: a barra e o
    // rótulo acompanham o dedo e a gravação sai só ao soltar
    el.querySelectorAll('[data-progresso]').forEach((r) => {
      const id = r.dataset.progresso;
      const rotulo = el.querySelector(`[data-rotulo="${id}"]`);
      const pintar = (v) => {
        r.style.setProperty('--pct', `${v}%`);
        rotulo.textContent = `${v}%`;
      };
      r.addEventListener('input', () => pintar(r.value));
      r.addEventListener('change', async () => {
        const anterior = r.dataset.salvo ?? r.defaultValue;
        try {
          await App.api(`/api/desdobramentos/${id}/progresso`, {
            planejamento_id: this.plan.id, progresso: Number(r.value),
          });
          r.dataset.salvo = r.value;
          this.atualizarMediaProjeto(el, r.dataset.proj);
        } catch (e) {
          // Falhou: devolve a barra ao valor que está no servidor
          r.value = anterior;
          pintar(anterior);
          alert(e.message);
        }
      });
    });
  },

  /** Recalcula na tela o percentual médio do projeto após ajustar uma ação. */
  atualizarMediaProjeto(el, projetoId) {
    const cartao = el.querySelector(`[data-projeto="${projetoId}"]`);
    const medias = [...(cartao?.querySelectorAll('[data-progresso]') || [])].map((x) => Number(x.value));
    const alvo = cartao?.querySelector('[data-media-projeto]');
    if (!alvo || !medias.length) return;
    const media = Math.round(medias.reduce((s, v) => s + v, 0) / medias.length);
    alvo.textContent = `${media}%`;
    const barra = cartao.querySelector('[data-barra-projeto]');
    if (barra) barra.style.width = `${media}%`;
  },

  // Anos de execução do ciclo vigente para o seletor "Ano do planejamento"
  anosDoCiclo() {
    const c = App.sessao.ciclos.find((x) => x.id === App.contexto.cicloId);
    if (!c) return [];
    const anos = [];
    const inicio = Number(c.ano_inicio || c.ano_base);
    for (let a = inicio; a <= Number(c.ano_fim); a++) anos.push(a);
    return anos;
  },

  modalProjeto(p, projetos) {
    const anos = this.anosDoCiclo();
    const anoPadrao = Math.min(Math.max(new Date().getFullYear(), anos[0] || 0), anos[anos.length - 1] || 9999);
    const mapaHorizontes = this.cascata.horizontes
      .map((h) => `${h.nome} ${h.ano_inicio}–${h.ano_fim}`).join(' · ');
    const opcoesAno = anos.map((a) => ({ valor: a, rotulo: String(a) }));
    // Projeto legado com ano fora da lista: mantém o valor à mostra em vez de
    // pular calado para o primeiro ano — o servidor pedirá um ano válido
    if (p?.ano && !anos.includes(Number(p.ano))) {
      opcoesAno.unshift({ valor: p.ano, rotulo: `${p.ano} (fora dos horizontes — escolha outro)` });
    }
    Modal.abrir({
      titulo: p ? 'Editar projeto' : 'Novo projeto',
      url: p ? `/api/projetos/${p.id}` : '/api/projetos',
      valores: p
        ? { ...p, cascata_id: p.cascata_id ?? '', impacto: p.impacto ?? '', planejamento_id: this.plan.id }
        : { planejamento_id: this.plan.id, ano: anoPadrao },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        // Tipo é legado — mantém o valor dos projetos antigos sem exibi-lo
        { nome: 'tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'ano', rotulo: 'Ano do planejamento', obrigatorio: true, tipo: 'select',
          opcoes: opcoesAno,
          nota: mapaHorizontes
            ? `O horizonte é definido pelo ano: ${mapaHorizontes}.`
            : 'Cadastre os horizontes do ciclo em Cadastros para o ano ser aceito.' },
        { nome: 'titulo', rotulo: 'Nome do projeto', obrigatorio: true,
          exemplo: 'Ex.: 1ª onda de silos — unidade Capinzal' },
        { nome: 'descricao', rotulo: 'Descrição — para que serve o projeto', tipo: 'textarea', linhas: 3,
          exemplo: 'O que o projeto entrega e por quê.' },
        { nome: 'responsavel', rotulo: 'Responsável', tipo: 'selecao_livre', opcoes: this.responsaveis,
          obrigatorio: true, vazio: '(selecione o responsável)',
          ajuda: 'Pesquise um usuário cadastrado ou digite um nome de fora do sistema.' },
      ],
    });
  },

  modalIniciativa(projetoId, ini) {
    Modal.abrir({
      titulo: ini ? 'Editar iniciativa' : 'Nova iniciativa',
      url: ini ? `/api/iniciativas/${ini.id}` : '/api/iniciativas',
      valores: ini
        ? { ...ini, planejamento_id: this.plan.id, projeto_id: projetoId }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, status: 'ABERTA' },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'titulo', rotulo: 'Iniciativa (frente de trabalho)', obrigatorio: true,
          exemplo: 'Ex.: Licenciamento e obra civil',
          ajuda: 'Agrupa as ações de uma mesma frente dentro do projeto.' },
        { nome: 'descricao', rotulo: 'Descrição', tipo: 'textarea', linhas: 2 },
        { nome: 'status', rotulo: 'Status', tipo: 'select', opcoes:
          Object.entries(STATUS_INICIATIVA).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
      ],
    });
  },

  // Status da ação: os automáticos aparecem, mas não podem ser escolhidos
  opcoesStatusAcao(atual) {
    const automatico = atual === 'ATRASADO' ? 'ATRASADO' : 'NAO_INICIADO';
    const opcoes = [{
      valor: automatico,
      rotulo: `${STATUS_ACAO[automatico][0]} (automático)`,
    }];
    for (const v of STATUS_MANUAIS) opcoes.push({ valor: v, rotulo: STATUS_ACAO[v][0] });
    return opcoes;
  },

  modalDesdobramento(projetoId, dd, iniciativaId = null) {
    Modal.abrir({
      titulo: dd ? 'Editar ação' : 'Nova ação',
      url: dd ? `/api/desdobramentos/${dd.id}` : '/api/desdobramentos',
      valores: dd
        ? { ...dd, quanto: dd.quanto ?? '', planejamento_id: this.plan.id, projeto_id: projetoId,
            iniciativa_id: dd.iniciativa_id ?? iniciativaId,
            recorrencia: dd.recorrencia || 'NENHUMA',
            recorrencia_ate: dd.recorrencia_ate ?? '',
            recorrencia_dia_semana: dd.recorrencia === 'SEMANAL' ? dd.recorrencia_dia : 1,
            recorrencia_dia_mes: dd.recorrencia === 'MENSAL' ? dd.recorrencia_dia : 1 }
        : { planejamento_id: this.plan.id, projeto_id: projetoId, iniciativa_id: iniciativaId,
            progresso: 0, prioridade: 'MEDIA', status: 'NAO_INICIADO', recorrencia: 'NENHUMA',
            recorrencia_dia_semana: 1, recorrencia_dia_mes: 1 },
      // O dia enviado depende do tipo de repetição escolhido
      transformar: (dados) => {
        const { recorrencia_dia_semana: sem, recorrencia_dia_mes: mes, ...resto } = dados;
        return {
          ...resto,
          recorrencia_dia: resto.recorrencia === 'SEMANAL' ? Number(sem)
            : resto.recorrencia === 'MENSAL' ? Number(mes) : null,
        };
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'projeto_id', rotulo: '', tipo: 'hidden' },
        { nome: 'iniciativa_id', rotulo: '', tipo: 'hidden' },
        // "Por quê?" e "Onde?" saíram do formulário; os valores dos registros
        // antigos seguem preservados nos campos ocultos
        { nome: 'por_que', rotulo: '', tipo: 'hidden' },
        { nome: 'onde', rotulo: '', tipo: 'hidden' },
        // Ordem pedida: o quê, quem, como, prioridade, quando, repetição,
        // quanto custa, status e progresso
        { nome: 'o_que', rotulo: 'O quê?', obrigatorio: true, tipo: 'textarea', linhas: 2,
          exemplo: 'Ex.: Contratar projeto executivo dos silos' },
        { nome: 'quem', rotulo: 'Quem?', tipo: 'selecao_livre', opcoes: this.responsaveis,
          obrigatorio: true, vazio: '(selecione o responsável)',
          ajuda: 'Pesquise um usuário cadastrado ou digite um nome de fora do sistema.' },
        { nome: 'como', rotulo: 'Como?' },
        { nome: 'prioridade', rotulo: 'Prioridade', tipo: 'botoes', opcoes:
          Object.entries(PRIORIDADES).map(([valor, [rotulo]]) => ({ valor, rotulo })) },
        { nome: 'quando_', rotulo: '', tipo: 'hidden' },
        { nome: 'quando_periodo', rotulo: 'Quando?', tipo: 'periodo',
          campos: [
            { nome: 'data_inicio', rotulo: 'Início' },
            { nome: 'data_fim', rotulo: 'Fim previsto' },
          ],
          ajuda: 'Toque no campo para abrir o calendário.' },
        { nome: 'recorrencia', rotulo: 'Repetição', tipo: 'select', opcoes: [
          { valor: 'NENHUMA', rotulo: 'Não se repete' },
          { valor: 'SEMANAL', rotulo: 'Toda semana' },
          { valor: 'MENSAL', rotulo: 'Todo mês' },
        ], ajuda: 'Ao concluir uma ação que se repete, ela reabre na próxima data prevista.' },
        { nome: 'recorrencia_dia_semana', rotulo: 'Repete toda', tipo: 'select',
          visivelSe: { campo: 'recorrencia', valores: ['SEMANAL'] },
          opcoes: DIAS_SEMANA.map(([valor, rotulo]) => ({ valor, rotulo })) },
        { nome: 'recorrencia_dia_mes', rotulo: 'Repete todo dia', tipo: 'select',
          visivelSe: { campo: 'recorrencia', valores: ['MENSAL'] },
          opcoes: Array.from({ length: 31 }, (_, i) => ({ valor: i + 1, rotulo: String(i + 1) })),
          ajuda: 'Em meses mais curtos, cai no último dia do mês.' },
        { nome: 'recorrencia_ate', rotulo: 'Repetir até', tipo: 'date',
          visivelSe: { campo: 'recorrencia', valores: ['SEMANAL', 'MENSAL'] },
          ajuda: 'Opcional — depois dessa data a ação encerra de vez.' },
        { nome: 'quanto', rotulo: 'Quanto custa? (R$)', tipo: 'number' },
        { nome: 'status', rotulo: 'Status', tipo: 'select',
          opcoes: this.opcoesStatusAcao(dd?.status),
          ajuda: '“No prazo” e “Atrasada” são definidos pela data de fim — escolha um status manual só quando quiser fixá-lo.' },
        // Passo de 1 para o modal nunca arredondar o que a barra do cartão gravou
        { nome: 'progresso', rotulo: 'Progresso', tipo: 'faixa', min: 0, max: 100, passo: 1, sufixo: '%' },
      ],
      aoSalvar: (r) => {
        this.avisarReagendamento(r);
        App.recarregarSecaoAtiva();
      },
    });
  },

  /**
   * Concluir uma ação que se repete não a encerra: ela volta na próxima data.
   * Sem este aviso o usuário marca "Concluída" e a vê reaparecer em aberto,
   * com outra data e 0%, sem entender o porquê.
   */
  avisarReagendamento(resposta) {
    const data = resposta?.reagendada_para;
    if (!data) return;
    alert(`Ocorrência concluída. Como esta ação se repete, ela volta em `
      + `${String(data).split('-').reverse().join('/')}.`);
  },

  async renderDiario() {
    const { refTipo, refId } = this.diarioAberto;
    const alvo = document.getElementById(`diario-${refTipo}-${refId}`);
    if (!alvo) return;
    const registros = await App.api(
      `/api/diario?planejamento_id=${this.plan.id}&ref_tipo=${refTipo}&ref_id=${refId}`);

    const itens = registros.map((r) => {
      const [rotulo, classe] = r.status_atual ? STATUS_ROTULOS[r.status_atual] : [null, null];
      return `<div class="border-start border-success border-3 ps-2 mb-2">
        <div class="small text-muted">${r.data_reg.split('-').reverse().join('/')} · ${Modal.esc(r.autor)}
          ${rotulo ? `<span class="badge ${classe}">${rotulo}</span>` : ''}
          ${r.progresso !== null && r.progresso !== undefined ? `<span class="badge text-bg-light border">${r.progresso}%</span>` : ''}
        </div>
        <div class="small">${Modal.esc(r.texto)}</div>
      </div>`;
    }).join('');

    alvo.innerHTML = `<div class="card bg-light"><div class="card-body py-2">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small text-uppercase">Diário de bordo</strong>
        ${App.podeEditar() ? `<button class="btn btn-sm btn-verde" id="btn-novo-registro">+ Registro</button>` : ''}
      </div>
      ${itens || '<div class="text-muted small">Nenhum registro ainda.</div>'}
    </div></div>`;

    if (!App.podeEditar()) return;
    document.getElementById('btn-novo-registro').addEventListener('click', () => Modal.abrir({
      titulo: 'Novo registro no diário de bordo',
      url: '/api/diario',
      valores: {
        planejamento_id: this.plan.id, ref_tipo: refTipo, ref_id: refId,
        data_reg: App.hoje(),
      },
      campos: [
        { nome: 'planejamento_id', rotulo: '', tipo: 'hidden' },
        { nome: 'ref_tipo', rotulo: '', tipo: 'hidden' },
        { nome: 'ref_id', rotulo: '', tipo: 'hidden' },
        { nome: 'data_reg', rotulo: 'Data', tipo: 'date' },
        { nome: 'texto', rotulo: 'Situação atual / acompanhamento', tipo: 'textarea', linhas: 4 },
        ...(refTipo !== 'CASCATA' ? [
          { nome: 'status_atual', rotulo: 'Atualizar status do item (opcional)', tipo: 'select',
            opcoes: [{ valor: '', rotulo: '(manter status atual)' }, ...OPCOES_STATUS] },
        ] : []),
        ...(refTipo === 'DESDOBRAMENTO' ? [
          { nome: 'progresso', rotulo: 'Atualizar progresso % (opcional)', tipo: 'number' },
        ] : []),
      ],
      aoSalvar: (r) => {
        this.avisarReagendamento(r);
        App.recarregarSecaoAtiva();
      },
    }));
  },
};

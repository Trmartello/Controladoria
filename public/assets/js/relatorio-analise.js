// Relatório de uma análise do diagnóstico (PESTEL, Porter, SWOT, Cenário), em
// dois formatos e sem dependência nenhuma:
//
//   • Word — um `.doc` de HTML, o MESMO caminho do `.xls` do Relatório de
//     Status (`RelatorioController::exportar`): o Word abre com títulos, cores
//     e listas, e não há Composer neste projeto para gerar `.docx` de verdade.
//   • PDF — a impressão da própria tela. Quem gera o arquivo é o navegador, em
//     "Salvar como PDF"; o que este arquivo faz é chamar a caixa de impressão, e
//     a folha de estilo `@media print` abre as colunas, tira menu, botões e o
//     corte de três linhas dos cartões.
//
// O documento se monta AQUI, no front, porque é aqui que moram os rótulos e as
// cores das categorias (`Diag.CATEGORIAS_ETAPA`, `Diag.QUADRANTES`). No
// servidor, o relatório precisaria de uma segunda cópia desse catálogo — e ela
// divergiria na primeira revisão, como já aconteceu com os campos da ação.
//
// O componente não guarda estado: quem sabe o que está na tela é a seção, que
// entrega os dados prontos em `ligar(el, montar)`.

const RelatorioAnalise = {
  /**
   * O canvas do relatório: uma TABELA de verdade cujo `<thead>` o navegador
   * repete no topo de TODA página impressa. Foi medido: um `<div>` com
   * `display: table-header-group` sai só na primeira folha, e um
   * `position: fixed` deslocado para a margem some justamente na última.
   * Na tela a tabela é neutralizada por CSS (`.canvas-analise`) e o layout é o
   * de sempre.
   */
  canvas({ cabecalho, corpo }) {
    return `<table class="canvas-analise">
      <thead><tr><td>${cabecalho}</td></tr></thead>
      <tbody><tr><td>${corpo}</td></tr></tbody>
      </table>`;
  },

  /**
   * Um BLOCO do relatório — a categoria da análise, a seção do documento — com
   * a mesma mecânica um nível abaixo: atravessando a quebra de página, o
   * navegador repete o `<thead>` do bloco no topo da folha seguinte. Sem isso a
   * página seguinte trazia só os cartões restantes, sem dizer de que quadrante
   * eles eram.
   * Na tela a tabela é `display: contents` — ela não existe: o cabeçalho e o
   * corpo continuam sendo filhos diretos da caixa da coluna, que é quem tem o
   * flex, a rolagem interna e o fundo.
   * O cabeçalho órfão no pé da folha não precisa de regra: o navegador não
   * fragmenta entre o `<thead>` repetido e a primeira linha do corpo, então o
   * título desce junto com o primeiro cartão (medido em varredura de 1 mm).
   */
  bloco({ cabecalho, corpo, classe = '', estilo = '' }) {
    return `<table class="canvas-bloco${classe ? ` ${classe}` : ''}"${
      estilo ? ` style="${estilo}"` : ''}>
      <thead><tr><td>${cabecalho}</td></tr></thead>
      <tbody><tr><td>${corpo}</td></tr></tbody>
      </table>`;
  },

  /** O botão da barra da análise. `dropdown` do Bootstrap, que já é vendorado. */
  botao() {
    return `<div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
        data-bs-toggle="dropdown" aria-expanded="false"
        title="Gerar relatório desta análise">⤓ Relatório</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button class="dropdown-item" type="button" data-relatorio="word">
          Word <span class="text-muted small">(.doc)</span></button></li>
        <li><button class="dropdown-item" type="button" data-relatorio="pdf">
          PDF <span class="text-muted small">(imprimir · salvar como PDF)</span></button></li>
      </ul>
    </div>`;
  },

  /**
   * `montar()` devolve o documento pronto — a seção é quem sabe o que está na
   * tela. Só é chamada no clique: montar a cada pintura seria refazer o
   * relatório a cada batida do polling, de graça.
   */
  ligar(el, montar) {
    el.querySelectorAll('[data-relatorio]').forEach((b) => b.addEventListener('click', () => {
      if (b.dataset.relatorio === 'pdf') {
        // Impressão do que está na tela: sem janela nova, sem popup bloqueado
        window.print();
        return;
      }
      try {
        this.baixarWord(montar());
      } catch (e) {
        alert('Não foi possível gerar o relatório.');
      }
    }));
  },

  /** dd/mm/aaaa hh:mm — o mesmo formato de data do resto do sistema. */
  agora() {
    const d = new Date();
    const dois = (n) => String(n).padStart(2, '0');
    return `${dois(d.getDate())}/${dois(d.getMonth() + 1)}/${d.getFullYear()} `
      + `${dois(d.getHours())}:${dois(d.getMinutes())}`;
  },

  /** Nome de arquivo sem acento, espaço nem barra — vai para o disco de alguém. */
  nomeArquivo(titulo) {
    const base = String(titulo)
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase();
    return `${base || 'relatorio'}.doc`;
  },

  /**
   * Monta o `.doc` e entrega ao navegador. O BOM é o mesmo do `.xls`: sem ele o
   * Word lê o arquivo em Latin-1 e a acentuação chega quebrada.
   */
  baixarWord({ titulo, contexto, secoes }) {
    const esc = Modal.esc;
    const notas = (i) => ((i.notas || []).length
      ? `<div class="notas">${esc(i.notas.join(' · '))}</div>` : '');

    // Lista numerada — o formato de toda análise cujo item é UM texto (o fator
    // da SWOT, a linha do PESTEL, a escolha da célula).
    const lista = (s) => `<ol>${(s.itens || []).map((i) =>
      `<li>${esc(i.texto)}${notas(i)}</li>`).join('')}</ol>`;

    // Tabela de duas colunas, quando a seção pede (`colunas`). É o formato do
    // material dos Cruzamentos — *cruzamento | estratégia* —, e ali a lista
    // numerada não serve: o item tem DOIS lados de peso igual, e emendá-los num
    // parágrafo só é justamente o que a tabela do cliente separa.
    const tabela = (s) => `<table class="grade">
      <thead><tr>${s.colunas.map((c) => `<th>${esc(c)}</th>`).join('')}</tr></thead>
      <tbody>${(s.itens || []).map((i) => `<tr>
        <td class="col-a"><strong>${esc(i.texto)}</strong>${notas(i)}</td>
        <td>${esc(i.detalhe || '')}</td>
      </tr>`).join('')}</tbody></table>`;

    const corpo = secoes.map((s) => {
      const qtd = (s.itens || []).length;
      return `<h2 style="color:${/^#[0-9a-f]{6}$/i.test(s.cor || '') ? s.cor : '#06432a'}">
          ${esc(s.rotulo)} <span class="dica">(${qtd})${
            s.dica ? ` · ${esc(s.dica)}` : ''}</span></h2>
        ${qtd ? (s.colunas ? tabela(s) : lista(s))
          : '<p class="vazio">Nenhum registro nesta categoria.</p>'}`;
    }).join('');

    const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office"
  xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="utf-8"><title>${esc(titulo)}</title>
<style>
@page { size: A4 portrait; margin: 2cm; }
body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #212529; }
h1 { font-size: 18pt; color: #06432a; margin: 0 0 4pt; }
h2 { font-size: 13pt; margin: 18pt 0 6pt; border-bottom: 1pt solid #d9dee2; padding-bottom: 3pt; }
.contexto { color: #6c757d; font-size: 10pt; margin: 0 0 6pt; }
.dica { font-weight: normal; font-size: 9pt; color: #6c757d; }
.notas { color: #6c757d; font-size: 9pt; }
.vazio { color: #6c757d; font-style: italic; }
ol { margin: 0 0 0 18pt; padding: 0; }
li { margin-bottom: 6pt; }
.grade { width: 100%; border-collapse: collapse; }
.grade th, .grade td { border: 1pt solid #d9dee2; padding: 4pt 6pt; text-align: left;
  vertical-align: top; }
.grade th { background: #eef2f0; font-size: 9pt; text-transform: uppercase; }
.grade .col-a { width: 38%; }
.rodape { margin-top: 24pt; border-top: 1pt solid #d9dee2; padding-top: 6pt;
  color: #6c757d; font-size: 9pt; }
</style></head><body>
<h1>${esc(titulo)}</h1>
<p class="contexto">${esc(contexto)}</p>
${corpo}
<p class="rodape">Gerado em ${this.agora()} · Planejamento Estratégico Copérdia</p>
</body></html>`;

    const url = URL.createObjectURL(new Blob([`\ufeff${html}`], { type: 'application/msword' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = this.nomeArquivo(titulo);
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Revogar na hora cancelaria o download que acabou de começar
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  },
};

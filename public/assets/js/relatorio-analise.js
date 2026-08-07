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
    const corpo = secoes.map((s) => {
      const itens = (s.itens || []).map((i) => `<li>${esc(i.texto)}${
        (i.notas || []).length ? `<div class="notas">${esc(i.notas.join(' · '))}</div>` : ''}</li>`).join('');
      return `<h2 style="color:${/^#[0-9a-f]{6}$/i.test(s.cor || '') ? s.cor : '#06432a'}">
          ${esc(s.rotulo)} <span class="dica">(${(s.itens || []).length})${
            s.dica ? ` · ${esc(s.dica)}` : ''}</span></h2>
        ${itens ? `<ol>${itens}</ol>` : '<p class="vazio">Nenhum registro nesta categoria.</p>'}`;
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

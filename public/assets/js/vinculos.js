// O que a exclusão leva junto — dito ANTES do clique.
//
// O sistema é feito de vínculos: o fator vira cruzamento, o cruzamento vira
// ação, a escolha da cascata vira projeto, a ideia da coleta vira fator. Apagar
// uma ponta mexe na outra, e o servidor responde a isso de TRÊS jeitos
// diferentes, todos certos para a relação que tratam:
//
//   • RECUSAR — a ação órfã no plano é grave, então o fator/cruzamento/ideia
//     que já virou ação não pode ser apagado por aqui.
//   • CASCATEAR — o que só existe por causa do registro sai com ele (a
//     avaliação GUT do fator, os comentários, as ações do projeto).
//   • SOLTAR O VÍNCULO — o que tem vida própria fica, sem o vínculo (o
//     investimento sem projeto continua sendo um investimento).
//
// O que era uniforme, e não devia ser, é o AVISO: o × tinha a mesma cara nos
// sete casos e o `confirm()` dizia sempre a mesma frase. Este arquivo uniformiza
// o aviso — não a regra.
//
// Duas coisas que ele NÃO é. Não é a guarda: quem recusa continua sendo o
// servidor, e a contagem aqui vale no instante da pintura — entre ela e o
// clique, alguém pode ter criado o vínculo. E não é uma consulta nova: os
// números saem das listagens que a tela já busca.

const Vinculos = {
  /**
   * A frase do `confirm()`, montada a partir do que se sabe da listagem.
   *
   * `some` é o que desaparece com o registro; `solta` é o que sobrevive sem o
   * vínculo. São listas separadas porque a diferença importa para quem decide:
   * perder a discussão de uma célula não é o mesmo que um investimento ficar
   * sem projeto.
   *
   * Item vazio (`0 comentário(s)`) não entra — quem monta a lista já filtra —,
   * e sem nenhum item a frase é só a pergunta, como antes.
   */
  aviso(pergunta, { some = [], solta = [], nota = '' } = {}) {
    // Filtrar ANTES de medir: quem chama despeja `quantos(0, …)` na lista sem
    // contar antes, e ele devolve string vazia. Medindo `length` cru, um
    // registro sem vínculo nenhum ganhava a frase "Sai junto: ." — pior do que
    // não dizer nada, porque parecia informação.
    const vao = some.filter(Boolean);
    const ficam = solta.filter(Boolean);
    const partes = [pergunta];
    if (vao.length) partes.push(`Sai junto: ${this.lista(vao)}.`);
    if (ficam.length) partes.push(`Continua existindo, sem o vínculo: ${this.lista(ficam)}.`);
    if (nota) partes.push(nota);
    return partes.join('\n\n');
  },

  /** "a, b e c" — a vírgula serial não é do português. */
  lista(itens) {
    const l = itens.filter(Boolean);
    if (l.length <= 1) return l[0] || '';
    return `${l.slice(0, -1).join(', ')} e ${l[l.length - 1]}`;
  },

  /**
   * "2 comentário(s)" — o plural entre parênteses é o mesmo do resto do
   * sistema, e devolve vazio no zero para o chamador poder despejar tudo na
   * lista sem contar antes.
   */
  quantos(n, singular, plural = null) {
    const q = Number(n) || 0;
    if (!q) return '';
    return `${q} ${q === 1 ? singular : (plural || `${singular}s`)}`;
  },

  /**
   * Os atributos do × quando o servidor VAI recusar.
   *
   * Desabilitado e com o motivo no `title`: o gesto para antes, e a recusa do
   * servidor volta a ser rede de segurança em vez de ser o canal por onde se
   * descobre a regra. `aria-disabled` junto do `disabled` porque o leitor de
   * tela anuncia o primeiro e o navegador obedece ao segundo.
   *
   * O motivo é sempre uma frase inteira — "Já virou ação no plano (…): exclua a
   * ação em Projetos antes." —, e não um "bloqueado" seco: quem lê o `title`
   * precisa saber o que fazer, não só que não pode.
   */
  travado(motivo) {
    return `disabled aria-disabled="true" title="${Modal.esc(motivo)}"`;
  },
};

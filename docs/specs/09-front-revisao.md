# Spec 09 — Front de revisão (triagem do especialista)

## Objetivo
Tela onde o especialista da Controladoria avalia a fila de exceções e decide,
item a item, o que **procede** (vai para o e-mail à contabilidade) e o que
**não procede** (retroalimenta a calibragem do motor).

## Fluxo (definido pela Controladoria)
1. O motor lista as inconsistências (AMARELO e VERMELHO) na fila de exceções.
2. O especialista avalia cada item com o contexto completo: lançamento, IAL,
   motivo textual, histórico do par e componentes do score.
3. Para cada item, uma decisão obrigatória:
   - **PROCEDE** — a alocação está errada; o item entra na minuta de e-mail
     para a contabilidade (ver Spec 13). Pode indicar o CC correto.
   - **NÃO PROCEDE** — a alocação está certa; exige justificativa. Alimenta a
     Taxa de Falso Positivo (Spec 10) e a retroalimentação da matriz (Spec 08).
   - **PROMOVER REGRA** — o caso revela regra ausente na matriz; cria proposta
     PENDENTE (nunca altera a matriz diretamente — CLAUDE.md §7).
4. **Apenas itens marcados PROCEDE compõem o e-mail.** Nada sai para a
   contabilidade sem passar pela triagem humana.

## Interface
- React + Vite (stack do CLAUDE.md §4), consumindo a API do Spec 08.
- Lista com filtros: semáforo, CC, conta, período, valor mínimo.
- Detalhe do item: motivo em linguagem contábil, sparkline do histórico do par,
  faixa de valor esperada (mediana ± k·MAD), sugestão da Camada 3 quando existir.
- Ação em lote permitida somente para NÃO PROCEDE de mesmo motivo (com uma
  justificativa única) — PROCEDE é sempre decisão individual.

## Trilha de auditoria
Toda decisão grava em `log_auditoria`: usuário, timestamp, decisão, justificativa,
CC indicado. Decisões não são editáveis — correção gera novo registro versionado.

## Critério de conclusão
Especialista consegue triar uma fila de 50 itens em menos de 30 minutos e gerar
a lista de aprovados que alimenta o Spec 13, com trilha completa.

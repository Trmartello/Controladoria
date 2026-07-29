# Spec 12 — Modo preventivo (validação no ato do lançamento)

> **Spec a detalhar.** Só será escrito após a calibragem homologada do shadow mode
> (Spec 07: 60 dias de execução detectiva) e com os pré-requisitos abaixo atendidos.

## Pré-requisitos para sequer iniciar este spec (CLAUDE.md §2.3 e Spec 10)
- Taxa de Falso Positivo estável abaixo de 30%.
- Cobertura da Matriz ≥ 90% do valor lançado.
- Aprovação formal da Controladoria para ativar `modo: PREVENTIVO` em
  `config/parametros.yaml` — bloqueio nunca é comportamento default.

## Escopo previsto (a refinar)
- Integração com o GDI/ERP para validação no ato do lançamento.
- Bloqueio apenas para veredicto BLOQUEADO da Camada 1; estatística nunca bloqueia sozinha.
- Caminho de exceção documentado: quem pode liberar um lançamento bloqueado e como
  isso fica na trilha de auditoria.

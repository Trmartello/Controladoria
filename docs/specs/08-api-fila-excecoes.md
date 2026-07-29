# Spec 08 — API da fila de exceções

## Endpoints
| Método | Rota | Função |
|---|---|---|
| GET | `/excecoes` | listar com filtro por semáforo, CC, conta, período |
| GET | `/excecoes/:id` | detalhe + histórico do par + componentes do IAL |
| POST | `/excecoes/:id/aprovar` | aceitar o lançamento como está (exige justificativa) |
| POST | `/excecoes/:id/reclassificar` | indicar CC correto (exige justificativa) |
| POST | `/excecoes/:id/promover-regra` | propor alteração na matriz de elegibilidade |
| GET | `/indicadores` | painel de saúde do sistema |

## Retroalimentação
`promover-regra` **não altera a matriz diretamente**. Cria registro em
`propostas_regra` com status PENDENTE. Alteração efetiva exige aprovação de
usuário com papel `CONTROLADORIA`. Ver CLAUDE.md §7.

## Trilha
Toda ação grava em `log_auditoria`: usuário, timestamp, ação, payload, IP.

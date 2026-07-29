# Spec 01 — Extração da base histórica

## Objetivo
Materializar 36 meses de lançamentos contábeis em `data/lancamentos.parquet`, com
as regras de negócio da Copérdia já aplicadas.

## Entrada
Qlik, via conector MCP, app **DRE - Centro de Custos** (nome citado pela
Controladoria). O CLAUDE.md referencia o app DRE-Gerencial
(appId `0a23b6b3-59c8-4b8e-aa48-10b24410bcba`) — **confirmar na sessão de
extração qual appId corresponde ao DRE - Centro de Custos** antes de extrair.
Alternativa: export do ERP H&S.

> O conector MCP do Qlik precisa ser ligado/autorizado manualmente a cada
> sessão. Ausência de resultado = conector desligado, não "sem dados".

## Colunas obrigatórias
| Coluna | Tipo | Observação |
|---|---|---|
| `id_lancamento` | str | chave única |
| `data_lancamento` | date | |
| `ano_mes` | str | `AAAA-MM` |
| `conta_contabil` | str | código |
| `descricao_conta` | str | |
| `grupo_conta` | str | nível superior da árvore |
| `centro_custo` | str | código |
| `descricao_cc` | str | |
| `negocio` | str | usado nas exclusões |
| `filial` | str | |
| `valor_lancamento` | decimal(18,2) | |
| `natureza` | str | D / C |
| `origem_lancamento` | str | **MANUAL / RATEIO / NF / FOLHA / INTEGRACAO** |
| `documento` | str | |
| `fornecedor` | str | nullable |
| `historico` | str | texto livre — insumo da Camada 3 |
| `usuario_lancamento` | str | |

## Regras aplicadas na extração
- `%ParticipacaoSocietaria = 'Sem Part. Societária'`
- Excluir negócio 18 e negócio 20
- Marcar jan/2025 com flag `dado_suspeito = True` (falha de carga conhecida)

## Critério de conclusão
- Parquet gerado com contagem de linhas e soma de valor conferindo com o total do Qlik
- Relatório de completude por coluna (% de nulos), especialmente `origem_lancamento`
- Se `origem_lancamento` não existir na fonte: **PARAR e reportar.** É bloqueante.

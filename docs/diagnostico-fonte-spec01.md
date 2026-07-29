# Diagnóstico da fonte — Spec 01 (Extração da base histórica)

> Resultado da sondagem do app Qlik **DRE Centro de Custo** realizada em 29/07/2026,
> antes de iniciar a extração. A spec 01 determina: *"Se `origem_lancamento` não
> existir na fonte: PARAR e reportar. É bloqueante."* — condição atingida.
> Este documento é o reporte.

## Apps verificados

| App | Espaço | appId | Situação |
|---|---|---|---|
| DRE Centro de Custo | Filiais (managed) | `052e19f8-892d-48a3-a408-da3ebb1ea53a` | **Fonte recomendada** |
| DRE Centro de Custo | Desenvolvimento (shared) | `274dca10-ae77-4b06-9c04-3e157ca0b063` | Cobertura idêntica à do managed |

O DRE-Gerencial (`0a23b6b3-…`, citado no CLAUDE.md) é outro app, de estrutura
de indicadores — não é a fonte de lançamentos por centro de custo.

## Bloqueante 1 — `origem_lancamento` não existe na fonte

Nenhum campo do app carrega a classificação MANUAL / RATEIO / NF / FOLHA / INTEGRACAO:

- `Tipo Histórico` contém apenas {Crédito, Débito} — é natureza, não origem.
- Não há campo de origem no fato nem nas dimensões.

**Proxies parciais encontrados** (cobrem apenas rateio, não as demais origens):

- Hierarquia de contas: `N1 = '9 RESULTADO RATEIO GERAL'`
- Histórico padrão: `'VLR RATEIO SOCIOS/NAO SOCIOS'`
- Flags do fato: `FlagHistoricoZeramento`, `FlagLancamentoParaIgnorar`

Qualquer uso desses proxies como substituto de `origem_lancamento` exige
validação contábil explícita. A alternativa prevista na spec é o export do
ERP H&S, onde o campo de origem deve existir nativamente.

## Bloqueante 2 — não existem 36 meses de histórico

Cobertura real do fato (idêntica nos dois apps):

- **Início: jan/2025. Fim (mês fechado): jun/2026. Total: 18 meses.**
- jul/2026 existe parcial (mês corrente).
- O calendário do app vai de 2020 a 2026, mas 2020–2024 não têm nenhuma
  linha de fato associada.

Consequências: "par inédito = sem ocorrência em 36 meses" e a base de
sazonalidade da Camada 2 precisam ser recalibrados ou o histórico anterior
precisa ser carregado de outra fonte (QVD antigo / ERP H&S).

## Campos obrigatórios sem correspondente na fonte

| Coluna da spec | Situação no app |
|---|---|
| `id_lancamento` | Não existe chave única de lançamento |
| `documento` | Não existe |
| `usuario_lancamento` | Não existe |
| `origem_lancamento` | Não existe (bloqueante 1) |
| `grupo_conta` | Atendível pela hierarquia `N1`…`N8` (definir nível) |
| Demais colunas | Atendíveis (`Data`, `Cód. Plano Contas`, `Conta Contábil`, `Cód. Centro Custo`, `Centro Custo`, `Cód. Negócio`, `Cód. Filial`, `Valor Lançamento Centro Custo`, `Natureza Lançamento`, `Cliente/Fornecedor`, `Histórico` + `Complemento Lançamento`) |

## Volume — extração linha a linha via MCP é inviável

- ~3,0 a 4,0 milhões de lançamentos/mês; ~61,6 milhões de linhas em 2025–2026.
- O conector MCP pagina hypercubes em blocos pequenos — ordem de centenas de
  milhares de chamadas para materializar a base. Não é caminho viável.
- Caminhos viáveis: (a) export QVD/CSV direto da recarga Qlik ou do ERP H&S;
  (b) extração **agregada por par × mês** via MCP (contas × CCs × 18 meses —
  ordem de dezenas de milhares de linhas), suficiente para a Camada 2, mas
  não para a Camada 3 (que precisa do texto do histórico por lançamento).

## Campo de valor — fato multi-grão (descoberto no piloto via MCP, 29/07/2026)

O modelo do app tem mais de um grão na tabela de fatos:

- **`Valor Lançamento`** acompanha os flags `FlagLancamentoDebito`/`FlagLancamentoCredito`
  e se associa ao plano de contas (`N1`…`N8`). Débitos e créditos fecham espelhados
  (±R$ 4,15 bi em jan/2025). **É o campo correto para a base par × mês.**
- **`Valor Lançamento Centro Custo`** vive em linhas que NÃO se associam a `N1`
  nem aos flags D/C — qualquer seleção nesses campos zera a soma. É visão de
  rateio/gerencial, não serve para o fato da validação.
- `Valor Lançamento DRE Centro Custo` é a visão líquida gerencial (não usar como fato).
- Em set analysis, `Mês` só casa por texto (`Mês={"jan"}`); `Mês={1}` retorna 0
  silenciosamente.
- Zeramento de exercício: em dez/2025, débitos de zeramento (R$ 1,59 bi, 750
  lançamentos no escopo) são ~6x o movimento normal (R$ 260 mi) — mantidos em
  colunas separadas no parquet.

## Árvore 9 (RESULTADO RATEIO GERAL) = rateio automático → FORA do motor

Constatado no piloto (jan/2025, sem zeramento, sem part. societária, sem
negócios 18/20):

| N1 | Lançamentos com CC | Débitos com CC | Contas |
|---|---|---|---|
| 3 CONTAS DE RESULTADO | ~156 mil | R$ 23,1 mi | 188 |
| 4 CUSTOS DE PRODUCAO | ~14 mil | R$ 75,7 mi | 40 |
| 9 RESULTADO RATEIO GERAL | **~1,65 milhão** | R$ 35,5 mi | 207 |

A árvore 9 espelha/reestrutura a DRE pós-rateio e concentra os micro-lançamentos
de rateio (ex.: "VLR RATEIO SOCIOS/NAO SOCIOS") — o CC ali é consequência de
regra, não decisão humana. Pelo CLAUDE.md §2.6, rateio automático não é
pontuado: **o escopo do fato é N1 ∈ {3, 4} com CC ≠ 0** (~1.516 pares/mês,
~171 mil lançamentos/mês). Somar 3+4+9 duplicaria valores (contas 3.x e 9.x
espelhadas com totais idênticos).

Limitação registrada: dentro de 3.x/4.x ainda podem existir rateios internos
não identificáveis sem o campo de origem do ERP — validar com a contabilidade.

## Observações de qualidade

- `Count` de lançamentos = `FlagLancamentoDebito` + `FlagLancamentoCredito` em
  todos os meses → todo registro do fato é D ou C; contagem não inflada.
- **dez/2025 inverte o sinal do total mensal** — padrão típico de zeramento /
  encerramento de exercício (`FlagHistoricoZeramento`). Tratar à parte na
  estatística.
- jan/2025 **tem** lançamentos populados neste app (a falha de carga conhecida
  era em `Valor Realizado` do DRE-Gerencial). Ainda assim será marcado
  `dado_suspeito = True`, conforme a spec.
- `FlagParticipacaoSocietaria` ∈ {0, 1} — assumir `0` = "Sem Part. Societária"
  exige confirmação (no DRE-Gerencial o filtro é textual).

## Decisões necessárias para destravar

1. **Origem do lançamento**: obter export do ERP H&S (com `origem_lancamento`,
   `documento`, `usuario_lancamento` e chave única) **ou** aprovar formalmente
   o uso dos proxies de rateio acima, registrando a limitação.
2. **Janela histórica**: aceitar 18 meses (recalibrando "par inédito" e
   sazonalidade) **ou** providenciar carga do histórico 2023–2024.
3. **Granularidade da extração via Qlik**: se a fonte permanecer o Qlik via
   MCP, a materialização será **agregada por par × mês** (não linha a linha).

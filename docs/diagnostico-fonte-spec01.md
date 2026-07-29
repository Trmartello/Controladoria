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

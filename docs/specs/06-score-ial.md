# Spec 06 — Índice de Aderência do Lançamento (IAL)

## Objetivo
Consolidar Camadas 1 e 2 em um score 0–100 com semáforo e **motivo textual**.

## Regra de precedência
A Camada 1 tem poder de veto:
- `veredicto = BLOQUEADO` → IAL = 0, semáforo VERMELHO, independente da estatística.
- `veredicto = JUSTIFICAR` → teto de IAL = 60 (nunca fica verde).
- `veredicto = PERMITIDO` → score livre, definido pela Camada 2.

## Composição (pesos em `config/parametros.yaml`)
| Componente | Peso default |
|---|---|
| Elegibilidade (Camada 1) | 40 |
| Frequência do par | 25 |
| Aderência de valor (mediana/MAD) | 20 |
| Aderência sazonal | 15 |

Componente sem dados suficientes **não zera** — é redistribuído proporcionalmente
entre os demais. Ausência de histórico não é evidência de erro.

## Regra de carência (CC novo)
CC com menos de 6 meses de movimento: usa a estatística agregada do **perfil**,
não a do próprio CC, e o IAL recebe teto de 80 com motivo "CC em carência".

## Semáforo (limiares configuráveis)
- **VERDE** ≥ 75 → passa direto
- **AMARELO** 40–74 → fila de revisão
- **VERMELHO** < 40 → fila prioritária (bloqueio apenas se modo preventivo ativo)

## Motivo textual — obrigatório
Toda saída não-verde carrega `motivo` legível. Exemplos:
- "Conta de frete em centro de custo de perfil Pessoal Administrativo — bloqueado por regra."
- "Par inédito: esta conta nunca foi lançada neste centro de custo em 36 meses."
- "Valor 4,2x acima da faixa habitual deste par (mediana R$ 3.100)."
- "Lançamento em mês atípico: este par concentra 82% do valor entre outubro e novembro."

## Critério de conclusão
Rodar sobre os últimos 3 meses fechados e comparar com os erros já conhecidos
manualmente pela Controladoria. Reportar acerto e falso positivo.

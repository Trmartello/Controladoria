# Spec 10 — Painel da Controladoria — indicadores de saúde do SVL

Indicadores do próprio sistema, expostos em `/indicadores` e no painel React.

| Indicador | Fórmula | O que mede |
|---|---|---|
| **Taxa de Aderência** | lançamentos verdes ÷ total | Qualidade da alocação na origem — o quanto o razão nasce certo |
| **Taxa de Reclassificação** | estornos/reclassificações ÷ total | Retrabalho contábil; é o custo da má qualidade da informação |
| **Taxa de Falso Positivo** | alertas aprovados sem alteração ÷ alertas | Calibragem do motor |
| **Cobertura da Matriz** | valor com regra explícita ÷ valor total | Quanto do razão está sob governança |
| **Lead time de exceção** | mediana de horas entre abertura e tratamento | Risco de a fila travar o fechamento |
| **Valor em exceção aberta** | soma de R$ em exceções pendentes | Exposição do fechamento em curso |

## Alarmes
- Taxa de Falso Positivo > 30% → motor descalibrado. Acima disso o revisor passa a
  aprovar no automático e o controle morre silenciosamente. Revisar limiares.
- Lead time > 48h → fila virou gargalo do fechamento.
- Cobertura da Matriz < 90% → não ativar modo preventivo.

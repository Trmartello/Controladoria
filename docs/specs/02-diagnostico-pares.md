# Spec 02 — Diagnóstico dos pares conta × centro de custo

## Objetivo
Entender o tamanho real da bagunça antes de construir qualquer regra.

## Saídas
1. `data/pares_observados.parquet` com, por par (conta, CC):
   - `n_ocorrencias`, `n_meses_distintos`, `primeira_ocorrencia`, `ultima_ocorrencia`
   - `valor_total`, `valor_mediana`, `valor_mad`, `valor_maximo`
   - `pct_origem_manual`
2. Relatório executivo (`outputs/diagnostico_pares.html`) com:
   - Curva de Pareto: % de pares que respondem por 80% do valor
   - **Cauda longa**: pares com `n_ocorrencias = 1` — contagem e valor total
   - Distribuição de pares por origem do lançamento
   - Top 30 CCs com maior dispersão de contas (candidatos a CC "lixeira")

## Indicador-chave a reportar
**Concentração de pares** = % dos pares que concentram 80% do valor. Mede o quanto
o razão é estruturado. Se poucos pares concentram quase tudo, a matriz de
elegibilidade cobre muito com pouco esforço.

## Critério de conclusão
Relatório entregue e revisado pela Controladoria antes de iniciar o Spec 03.

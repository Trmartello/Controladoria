# Spec 05 — Motor estatístico (Camada 2)

## Objetivo
Avaliar aderência do lançamento ao comportamento histórico do seu par.

## Componentes

### 5.1 Frequência do par
- `par_inedito` = True se `n_ocorrencias = 0` nos últimos 36 meses.
- `frequencia_relativa` = meses com ocorrência ÷ meses ativos do CC.

### 5.2 Faixa de valor esperada — mediana + MAD
```
mad = mediana(|x - mediana(x)|)
limite_superior = mediana + k * 1.4826 * mad     # k default = 3
```
`1.4826` converte MAD em estimador consistente do desvio-padrão sob normalidade.
**Proibido usar média + desvio-padrão** (ver CLAUDE.md §7).
Se `mad = 0` (valores idênticos), usar fallback: desvio = 10% da mediana.
Exigir `n_ocorrencias >= 6` para calcular faixa; abaixo disso, faixa indefinida
e o componente não pontua.

### 5.3 Índice sazonal
Para cada par, participação % de cada mês no valor anual, média dos 3 últimos anos.
```
indice_sazonal[m] = (part_media[m]) / (1/12)
```
Índice 1,0 = mês neutro. Alerta se o lançamento cai em mês com índice < 0,3
e valor acima da mediana do par.
Exigir ≥ 2 ciclos anuais completos; caso contrário, componente não pontua.

### 5.4 Tendência de origem
`pct_origem_manual` do par. Peso agravante: par majoritariamente manual tem
histórico menos confiável como referência.

## Tratamento de lacunas
- jan/2025 (`dado_suspeito = True`) é excluído dos cálculos de frequência e sazonalidade.
- `origem_lancamento = 'RATEIO'` não entra nem como entrada nem como base.

## Critério de conclusão
Testes unitários cobrindo: MAD com mad=0, sazonalidade com menos de 2 ciclos,
par inédito, par com histórico apenas em janeiro/2025.

# Spec 11 — Sugestão de CC via LLM (Camada 3)

> Só implementar após 60 dias de shadow mode e Taxa de Falso Positivo estável abaixo de 30%.

## Escopo estrito
- Atua **apenas** em lançamentos AMARELOS.
- **Sugere** o CC provável. Não decide, não bloqueia, não altera a matriz.
- Saída sempre acompanhada de grau de confiança e justificativa em uma frase.

## Entrada do prompt
`historico`, `fornecedor`, `documento`, `conta_contabil`, `descricao_conta`,
`valor`, CC atual e os 5 CCs mais frequentes daquela conta com suas descrições.

## Saída esperada (JSON estrito)
```json
{
  "cc_sugerido": "string",
  "confianca": 0.0,
  "justificativa": "uma frase em português"
}
```

## Guarda-corpos
- Se `confianca < 0.7`, não exibir sugestão — exibir apenas os candidatos frequentes.
- Nenhum dado pessoal de associado (CPF, nome) vai no prompt.
- Registrar taxa de aceitação da sugestão. Abaixo de 50%, desligar a camada.

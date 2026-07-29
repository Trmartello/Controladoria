# Spec 07 — Execução detectiva D+1

## Objetivo
Rodar o motor todo dia útil de manhã sobre os lançamentos do dia anterior.

## Fluxo
1. Extrair lançamentos de D-1
2. Filtrar `origem_lancamento != 'RATEIO'`
3. Calcular IAL
4. Gravar em `fato_avaliacao_lancamento`
5. Abrir exceção para AMARELO e VERMELHO em `fila_excecoes`
6. Enviar resumo por e-mail à Controladoria

## Tabela `fato_avaliacao_lancamento`
`id_lancamento`, `data_avaliacao`, `ial`, `semaforo`, `motivo`, `veredicto_camada1`,
`componentes_json`, `versao_matriz`, `versao_parametros`.

**`versao_matriz` e `versao_parametros` são obrigatórios** — sem eles a avaliação
não é reproduzível seis meses depois, e uma avaliação não reproduzível não é auditável.

## Modo
`config.modo = 'SHADOW'` por padrão. Nunca bloqueia. Ver CLAUDE.md §2.3.

## Critério de conclusão
60 dias de execução em shadow mode com relatório semanal de calibragem.

# ROADMAP — SVL

Um PR por item. Marcar o checkbox ao concluir.

**Fluxo-alvo do sistema:** ler o razão no Qlik (app DRE - Centro de Custos) →
rodar as análises (Camadas 1 e 2) → listar as inconsistências para triagem do
especialista → apenas os itens que **procedem** compõem a minuta de e-mail para
a contabilidade ajustar.

## Fase 0 — Diagnóstico (semana 1)
- [ ] `01-extracao-base-historica` — extrair 36 meses do razão e materializar em parquet
- [ ] `02-diagnostico-pares` — matriz observada conta × CC, frequência, mediana, cauda longa

## Fase 1 — Estrutura (semanas 2–4)
- [ ] `03-perfilagem-cc` — clusterização dos centros de custo e curadoria dos perfis
- [ ] `04-matriz-elegibilidade` — modelo de dados + carga inicial + versionamento

## Fase 2 — Motor (semanas 5–8)
- [ ] `05-motor-estatistico` — frequência, mediana/MAD, índice sazonal, par inédito
- [ ] `06-score-ial` — composição do score, semáforo, motivo textual
- [ ] `07-batch-d1` — execução noturna, geração da fila de exceções

## Fase 3 — Operação (semanas 9–12)
- [ ] `08-api-fila-excecoes` — endpoints de fila, aprovação, retroalimentação
- [ ] `09-front-revisao` — tela de triagem do especialista (procede / não procede)
- [ ] `10-painel-controladoria` — indicadores de saúde do próprio sistema
- [ ] `13-email-contabilidade` — minuta de e-mail com os itens aprovados na triagem

## Fase 4 — Inteligência e prevenção (após calibragem)
- [ ] `11-llm-sugestao-cc` — sugestão de CC correto para casos amarelos
- [ ] `12-modo-preventivo` — validação no ato do lançamento / integração GDI

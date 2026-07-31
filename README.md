# Controladoria — Sistema de Planejamento Estratégico

Sistema de apoio ao planejamento estratégico construído em Streamlit.

## Módulos

- **Painel Geral** — visão consolidada: objetivos, ações por status, progresso e atingimento dos indicadores.
- **Diretrizes** — missão, visão e valores.
- **Diagnóstico SWOT** — forças, fraquezas, oportunidades e ameaças.
- **Objetivos Estratégicos** — organizados pelas 4 perspectivas do Balanced Scorecard.
- **Planos de Ação (5W2H)** — desdobramento dos objetivos em ações com status e progresso.
- **Indicadores** — KPIs por objetivo, com meta, realizado e % de atingimento.

## Como executar

```bash
pip install -r requirements.txt
streamlit run app.py
```

Os dados são armazenados localmente em `planejamento.db` (SQLite), criado automaticamente na primeira execução.

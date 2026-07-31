"""Sistema de apoio ao Planejamento Estratégico — Controladoria."""

import streamlit as st

import database

st.set_page_config(
    page_title="Planejamento Estratégico",
    page_icon="🎯",
    layout="wide",
    initial_sidebar_state="expanded",
)

database.init_db()

paginas = st.navigation(
    [
        st.Page("views/inicio.py", title="Painel Geral", icon="📊", default=True),
        st.Page("views/diretrizes.py", title="Diretrizes", icon="🧭"),
        st.Page("views/swot.py", title="Diagnóstico SWOT", icon="🔍"),
        st.Page("views/objetivos.py", title="Objetivos Estratégicos", icon="🎯"),
        st.Page("views/acoes.py", title="Planos de Ação (5W2H)", icon="✅"),
        st.Page("views/indicadores.py", title="Indicadores", icon="📈"),
    ]
)
paginas.run()

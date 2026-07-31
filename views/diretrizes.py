import streamlit as st

import database as db

st.header("🧭 Diretrizes Estratégicas")
st.caption("Missão, visão e valores que orientam todo o planejamento.")

rotulos = {
    "missao": ("Missão", "Por que a organização existe?"),
    "visao": ("Visão", "Onde queremos chegar?"),
    "valores": ("Valores", "Quais princípios guiam nossas decisões?"),
}

with st.form("diretrizes"):
    textos = {}
    for tipo, (titulo, ajuda) in rotulos.items():
        textos[tipo] = st.text_area(
            titulo, value=db.get_diretriz(tipo), help=ajuda, height=120
        )
    if st.form_submit_button("Salvar diretrizes", type="primary"):
        for tipo, texto in textos.items():
            db.set_diretriz(tipo, texto)
        st.success("Diretrizes salvas.")

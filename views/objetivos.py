import streamlit as st

import database as db

st.header("🎯 Objetivos Estratégicos")
st.caption("Organizados pelas quatro perspectivas do Balanced Scorecard.")

with st.expander("➕ Novo objetivo", expanded=False):
    with st.form("novo_objetivo", clear_on_submit=True):
        titulo = st.text_input("Objetivo")
        perspectiva = st.selectbox("Perspectiva", db.PERSPECTIVAS)
        descricao = st.text_area("Descrição / resultado esperado", height=80)
        col1, col2 = st.columns(2)
        responsavel = col1.text_input("Responsável")
        prazo = col2.text_input("Prazo (ex.: dez/2026)")
        if st.form_submit_button("Cadastrar", type="primary") and titulo.strip():
            db.execute(
                "INSERT INTO objetivos (perspectiva, titulo, descricao, responsavel, prazo)"
                " VALUES (?, ?, ?, ?, ?)",
                (perspectiva, titulo.strip(), descricao, responsavel, prazo),
            )
            st.rerun()

objetivos = db.listar_objetivos()

if objetivos.empty:
    st.info("Nenhum objetivo cadastrado.")

for perspectiva in db.PERSPECTIVAS:
    grupo = objetivos[objetivos["perspectiva"] == perspectiva]
    if grupo.empty:
        continue
    st.subheader(perspectiva)
    for linha in grupo.itertuples():
        with st.container(border=True):
            c1, c2 = st.columns([8, 1])
            c1.markdown(f"**{linha.titulo}**")
            if linha.descricao:
                c1.write(linha.descricao)
            detalhes = " · ".join(
                p for p in (
                    f"Responsável: {linha.responsavel}" if linha.responsavel else "",
                    f"Prazo: {linha.prazo}" if linha.prazo else "",
                ) if p
            )
            if detalhes:
                c1.caption(detalhes)
            if c2.button("🗑️", key=f"del_obj_{linha.id}", help="Excluir objetivo"):
                db.execute("DELETE FROM objetivos WHERE id = ?", (int(linha.id),))
                st.rerun()

import streamlit as st

import database as db

st.header("🔍 Diagnóstico SWOT")
st.caption("Forças e fraquezas (ambiente interno); oportunidades e ameaças (externo).")

with st.form("nova_swot", clear_on_submit=True):
    col1, col2 = st.columns([1, 3])
    quadrante = col1.selectbox("Quadrante", db.QUADRANTES_SWOT)
    descricao = col2.text_input("Descrição")
    if st.form_submit_button("Adicionar") and descricao.strip():
        db.execute(
            "INSERT INTO swot (quadrante, descricao) VALUES (?, ?)",
            (quadrante, descricao.strip()),
        )
        st.rerun()

swot = db.query_df("SELECT * FROM swot ORDER BY id")

cores = {
    "Força": "green",
    "Fraqueza": "orange",
    "Oportunidade": "blue",
    "Ameaça": "red",
}
col_int, col_ext = st.columns(2)
colunas = {
    "Força": col_int,
    "Fraqueza": col_int,
    "Oportunidade": col_ext,
    "Ameaça": col_ext,
}

for quadrante in db.QUADRANTES_SWOT:
    coluna = colunas[quadrante]
    itens = swot[swot["quadrante"] == quadrante]
    coluna.subheader(f":{cores[quadrante]}[{quadrante}s]")
    if itens.empty:
        coluna.caption("Nenhum item.")
    for linha in itens.itertuples():
        c1, c2 = coluna.columns([6, 1])
        c1.write(f"- {linha.descricao}")
        if c2.button("🗑️", key=f"del_swot_{linha.id}", help="Excluir item"):
            db.execute("DELETE FROM swot WHERE id = ?", (int(linha.id),))
            st.rerun()

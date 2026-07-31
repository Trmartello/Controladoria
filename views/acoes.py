import streamlit as st

import database as db

st.header("✅ Planos de Ação — 5W2H")

opcoes = db.opcoes_objetivos()
if not opcoes:
    st.warning("Cadastre ao menos um **objetivo estratégico** antes de criar ações.")
    st.stop()

with st.expander("➕ Nova ação", expanded=False):
    with st.form("nova_acao", clear_on_submit=True):
        objetivo = st.selectbox("Objetivo vinculado", list(opcoes))
        o_que = st.text_input("O quê? (What)")
        por_que = st.text_input("Por quê? (Why)")
        col1, col2, col3 = st.columns(3)
        quem = col1.text_input("Quem? (Who)")
        quando = col2.text_input("Quando? (When)")
        onde = col3.text_input("Onde? (Where)")
        como = st.text_input("Como? (How)")
        quanto = st.text_input("Quanto custa? (How much)")
        if st.form_submit_button("Cadastrar", type="primary") and o_que.strip():
            db.execute(
                "INSERT INTO acoes (objetivo_id, o_que, por_que, quem, quando,"
                " onde, como, quanto) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (opcoes[objetivo], o_que.strip(), por_que, quem, quando, onde, como, quanto),
            )
            st.rerun()

acoes = db.query_df(
    "SELECT a.*, o.titulo AS objetivo FROM acoes a"
    " LEFT JOIN objetivos o ON o.id = a.objetivo_id ORDER BY a.id"
)

if acoes.empty:
    st.info("Nenhuma ação cadastrada.")
    st.stop()

filtro = st.multiselect("Filtrar por status", db.STATUS_ACAO, default=db.STATUS_ACAO)
acoes = acoes[acoes["status"].isin(filtro)]

for linha in acoes.itertuples():
    with st.container(border=True):
        cabecalho, excluir = st.columns([8, 1])
        cabecalho.markdown(f"**{linha.o_que}**  \n🎯 {linha.objetivo or '—'}")
        if excluir.button("🗑️", key=f"del_acao_{linha.id}", help="Excluir ação"):
            db.execute("DELETE FROM acoes WHERE id = ?", (int(linha.id),))
            st.rerun()

        detalhes = [
            ("Por quê", linha.por_que), ("Quem", linha.quem),
            ("Quando", linha.quando), ("Onde", linha.onde),
            ("Como", linha.como), ("Quanto", linha.quanto),
        ]
        texto = " · ".join(f"**{rotulo}:** {valor}" for rotulo, valor in detalhes if valor)
        if texto:
            st.caption(texto)

        c1, c2 = st.columns(2)
        status = c1.selectbox(
            "Status", db.STATUS_ACAO,
            index=db.STATUS_ACAO.index(linha.status),
            key=f"status_{linha.id}",
        )
        progresso = c2.slider(
            "Progresso (%)", 0, 100, int(linha.progresso), key=f"prog_{linha.id}"
        )
        if status != linha.status or progresso != linha.progresso:
            db.execute(
                "UPDATE acoes SET status = ?, progresso = ? WHERE id = ?",
                (status, progresso, int(linha.id)),
            )
            st.rerun()

import streamlit as st

import database as db

st.header("📈 Indicadores Estratégicos")
st.caption("KPIs vinculados aos objetivos, com meta e realizado.")

opcoes = db.opcoes_objetivos()
if not opcoes:
    st.warning("Cadastre ao menos um **objetivo estratégico** antes de criar indicadores.")
    st.stop()

with st.expander("➕ Novo indicador", expanded=False):
    with st.form("novo_indicador", clear_on_submit=True):
        objetivo = st.selectbox("Objetivo vinculado", list(opcoes))
        nome = st.text_input("Indicador (ex.: Margem EBITDA)")
        col1, col2, col3 = st.columns(3)
        unidade = col1.text_input("Unidade (%, R$, dias...)")
        meta = col2.number_input("Meta", value=0.0, format="%.2f")
        sentido = col3.selectbox("Sentido", ["Maior é melhor", "Menor é melhor"])
        if st.form_submit_button("Cadastrar", type="primary") and nome.strip():
            db.execute(
                "INSERT INTO indicadores (objetivo_id, nome, unidade, meta, realizado, sentido)"
                " VALUES (?, ?, ?, ?, 0, ?)",
                (opcoes[objetivo], nome.strip(), unidade, meta, sentido),
            )
            st.rerun()

indicadores = db.query_df(
    "SELECT i.*, o.titulo AS objetivo FROM indicadores i"
    " LEFT JOIN objetivos o ON o.id = i.objetivo_id ORDER BY i.id"
)

if indicadores.empty:
    st.info("Nenhum indicador cadastrado.")
    st.stop()

for linha in indicadores.itertuples():
    with st.container(border=True):
        cabecalho, excluir = st.columns([8, 1])
        unidade = f" ({linha.unidade})" if linha.unidade else ""
        cabecalho.markdown(f"**{linha.nome}**{unidade}  \n🎯 {linha.objetivo or '—'}")
        if excluir.button("🗑️", key=f"del_ind_{linha.id}", help="Excluir indicador"):
            db.execute("DELETE FROM indicadores WHERE id = ?", (int(linha.id),))
            st.rerun()

        c1, c2, c3 = st.columns(3)
        c1.metric("Meta", f"{linha.meta:g}")
        realizado = c2.number_input(
            "Realizado", value=float(linha.realizado or 0),
            format="%.2f", key=f"real_{linha.id}",
        )
        if linha.meta:
            if linha.sentido == "Menor é melhor":
                pct = 100 * linha.meta / realizado if realizado else 0.0
            else:
                pct = 100 * realizado / linha.meta
            c3.metric("Atingimento", f"{pct:.1f}%")
        if realizado != (linha.realizado or 0):
            db.execute(
                "UPDATE indicadores SET realizado = ? WHERE id = ?",
                (realizado, int(linha.id)),
            )
            st.rerun()

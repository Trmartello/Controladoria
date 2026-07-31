import streamlit as st

import database as db

st.header("📊 Painel Geral do Planejamento")

objetivos = db.listar_objetivos()
acoes = db.query_df("SELECT * FROM acoes")
indicadores = db.query_df("SELECT * FROM indicadores")

col1, col2, col3, col4 = st.columns(4)
col1.metric("Objetivos estratégicos", len(objetivos))
col2.metric("Ações planejadas", len(acoes))
concluidas = int((acoes["status"] == "Concluída").sum()) if not acoes.empty else 0
col3.metric("Ações concluídas", concluidas)
col4.metric("Indicadores", len(indicadores))

st.divider()

if acoes.empty:
    st.info(
        "Nenhuma ação cadastrada ainda. Comece pelas **Diretrizes**, "
        "faça o **Diagnóstico SWOT**, defina os **Objetivos** e desdobre em "
        "**Planos de Ação**."
    )
else:
    esq, dir = st.columns(2)

    with esq:
        st.subheader("Ações por status")
        st.bar_chart(acoes["status"].value_counts())

    with dir:
        st.subheader("Progresso médio por objetivo")
        if not objetivos.empty:
            juncao = acoes.merge(
                objetivos[["id", "titulo"]],
                left_on="objetivo_id",
                right_on="id",
                suffixes=("", "_obj"),
            )
            if not juncao.empty:
                st.bar_chart(juncao.groupby("titulo")["progresso"].mean())

    atrasadas = acoes[acoes["status"] == "Atrasada"]
    if not atrasadas.empty:
        st.subheader("⚠️ Ações atrasadas")
        st.dataframe(
            atrasadas[["o_que", "quem", "quando", "progresso"]],
            width='stretch',
            hide_index=True,
        )

if not indicadores.empty:
    st.subheader("Atingimento dos indicadores")
    ind = indicadores.dropna(subset=["meta", "realizado"]).copy()
    if not ind.empty:
        def atingimento(linha):
            if linha["meta"] == 0:
                return None
            if linha["sentido"] == "Menor é melhor":
                return round(100 * linha["meta"] / linha["realizado"], 1) if linha["realizado"] else None
            return round(100 * linha["realizado"] / linha["meta"], 1)

        ind["atingimento_%"] = ind.apply(atingimento, axis=1)
        st.dataframe(
            ind[["nome", "unidade", "meta", "realizado", "atingimento_%"]],
            width='stretch',
            hide_index=True,
        )

"""Spec 01 — Extração da base histórica (agregado par × mês).

A fonte (app Qlik "DRE Centro de Custo") não tem `origem_lancamento` nem chave
única de lançamento, e o fato só existe a partir de jan/2025 — ver
docs/diagnostico-fonte-spec01.md. Por decisão registrada, a base é materializada
no grão **par × mês** (conta_contabil × centro_custo × ano_mes), suficiente para
as Camadas 1 e 2.

O escopo é N1 ∈ {3 CONTAS DE RESULTADO, 4 CUSTOS DE PRODUCAO} com centro de
custo atribuído. A árvore 9 (RESULTADO RATEIO GERAL) é o rateio automático —
~1,6 milhão de micro-lançamentos/mês cujo CC é consequência de regra, não de
decisão humana — e fica FORA do motor (CLAUDE.md §2.6).

Saídas (em `data/`, fora do git):
- lancamentos_par_mes.parquet   — fato agregado
- dim_conta.parquet             — plano de contas do escopo
- dim_centro_custo.parquet      — centros de custo
- dim_negocio.parquet           — negócios
- relatorio_completude_spec01.md

Execução: QLIK_API_KEY=... python -m svl.extracao_base_historica
"""

import os
import sys
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

import pandas as pd
import pyarrow as pa
import pyarrow.parquet as pq

from svl.config import Parametros, carregar_parametros
from svl.qlik_engine import ClienteEngineQlik

PASTA_DADOS = Path(__file__).resolve().parents[2] / "data"

# Nomes dos campos na fonte (estrutura do app, não são thresholds).
CAMPO_MES_ANO = "=Date([Mês/Ano], 'YYYY-MM')"
CAMPO_CONTA = "Cód. Plano Contas"
CAMPO_DESCRICAO_CONTA = "Conta Contábil"
CAMPO_GRUPO_CONTA = "N1"
CAMPO_CC = "Cód. Centro Custo"
CAMPO_DESCRICAO_CC = "Centro Custo"
CAMPO_TIPO_CC = "desc tipo"
CAMPO_RESPONSAVEL_CC = "Nome Responsavel"
CAMPO_NEGOCIO = "Cód. Negócio"
CAMPO_DESCRICAO_NEGOCIO = "Negócio"
CAMPO_FLAG_PARTICIPACAO = "FlagParticipacaoSocietaria"
SEM_PARTICIPACAO_SOCIETARIA = 0

# Dimensão calculada: suprime centro de custo 0 (lançamento sem CC — nada a validar).
DIMENSAO_CC_VALIDO = f"=If([{CAMPO_CC}]>0, [{CAMPO_CC}])"

# Medidas do fato agregado. Zeramento (encerramento de exercício) é separado do
# movimento normal para não contaminar a estatística das specs 02/05 — em
# dez/2025 o zeramento é ~6x o movimento normal.
# O campo de valor é [Valor Lançamento]: no modelo do app, as linhas de
# [Valor Lançamento Centro Custo] NÃO se associam a N1 nem aos flags D/C
# (fato multi-grão) — ver docs/diagnostico-fonte-spec01.md.
# Valores saem crus (sem Round): arredondar no nível do par acumularia deriva
# contra o total mensal; a quantização em 2 casas acontece só na materialização.
MEDIDAS_FATO = [
    {"rotulo": "qtd_debito", "expressao": "Sum({<FlagHistoricoZeramento={0}>} FlagLancamentoDebito)"},
    {"rotulo": "qtd_credito", "expressao": "Sum({<FlagHistoricoZeramento={0}>} FlagLancamentoCredito)"},
    {
        "rotulo": "valor_debito",
        "expressao": "Sum({<FlagHistoricoZeramento={0}, FlagLancamentoDebito={1}>}"
        " [Valor Lançamento])",
    },
    {
        "rotulo": "valor_credito",
        "expressao": "Sum({<FlagHistoricoZeramento={0}, FlagLancamentoCredito={1}>}"
        " [Valor Lançamento])",
    },
    {"rotulo": "qtd_zeramento", "expressao": "Sum(FlagHistoricoZeramento)"},
    {
        "rotulo": "valor_zeramento",
        "expressao": "Sum({<FlagHistoricoZeramento={1}>} [Valor Lançamento])",
    },
]

DOIS_DECIMAIS = Decimal("0.01")


class ErroConferencia(RuntimeError):
    """Divergência entre o parquet materializado e os totais do Qlik."""


# ------------------------------------------------------------------- extração


def _cubo_para_dataframe(cliente: ClienteEngineQlik, dimensoes, medidas, celulas_por_pagina) -> pd.DataFrame:
    cubo = cliente.criar_cubo(dimensoes=dimensoes, medidas=medidas)
    n_dimensoes = len(dimensoes)
    linhas = []
    for pagina in cliente.paginar_cubo(cubo, celulas_por_pagina):
        for linha in pagina:
            # Dimensões chegam como texto; medidas como número.
            linhas.append(
                [celula[0] for celula in linha[:n_dimensoes]]
                + [celula[1] for celula in linha[n_dimensoes:]]
            )
    return pd.DataFrame(linhas, columns=cubo.colunas)


def aplicar_escopo(cliente: ClienteEngineQlik, parametros: Parametros) -> None:
    """Seleções obrigatórias da Copérdia (CLAUDE.md §3) + escopo de contas da validação."""
    cliente.selecionar_valores(CAMPO_FLAG_PARTICIPACAO, [SEM_PARTICIPACAO_SOCIETARIA])
    cliente.selecionar_valores(CAMPO_GRUPO_CONTA, parametros.extracao.grupos_conta)
    negocios = _cubo_para_dataframe(
        cliente,
        dimensoes=[{"campo": CAMPO_NEGOCIO, "rotulo": "negocio"}],
        medidas=[{"expressao": "Count([Valor Lançamento])", "rotulo": "qtd"}],
        celulas_por_pagina=parametros.extracao.pagina_celulas,
    )
    validos = [
        int(codigo)
        for codigo in negocios["negocio"]
        if int(codigo) not in parametros.exclusoes.negocios
    ]
    cliente.selecionar_valores(CAMPO_NEGOCIO, validos)


def extrair_fato(cliente: ClienteEngineQlik, parametros: Parametros) -> pd.DataFrame:
    return _cubo_para_dataframe(
        cliente,
        dimensoes=[
            {"campo": CAMPO_MES_ANO, "rotulo": "ano_mes"},
            {"campo": CAMPO_CONTA, "rotulo": "conta_contabil"},
            {"campo": DIMENSAO_CC_VALIDO, "rotulo": "centro_custo"},
            {"campo": CAMPO_NEGOCIO, "rotulo": "negocio"},
        ],
        medidas=MEDIDAS_FATO,
        celulas_por_pagina=parametros.extracao.pagina_celulas,
    )


def extrair_dimensoes(cliente: ClienteEngineQlik, parametros: Parametros) -> dict[str, pd.DataFrame]:
    celulas = parametros.extracao.pagina_celulas
    contagem = [{"expressao": "Count([Valor Lançamento])", "rotulo": "qtd_lancamentos"}]
    return {
        "dim_conta": _cubo_para_dataframe(
            cliente,
            dimensoes=[
                {"campo": CAMPO_CONTA, "rotulo": "conta_contabil"},
                {"campo": CAMPO_DESCRICAO_CONTA, "rotulo": "descricao_conta"},
                {"campo": CAMPO_GRUPO_CONTA, "rotulo": "grupo_conta"},
            ],
            medidas=contagem,
            celulas_por_pagina=celulas,
        ).drop(columns=["qtd_lancamentos"]),
        "dim_centro_custo": _cubo_para_dataframe(
            cliente,
            dimensoes=[
                {"campo": DIMENSAO_CC_VALIDO, "rotulo": "centro_custo"},
                {"campo": CAMPO_DESCRICAO_CC, "rotulo": "descricao_cc"},
                {"campo": CAMPO_TIPO_CC, "rotulo": "tipo_cc"},
                {"campo": CAMPO_RESPONSAVEL_CC, "rotulo": "responsavel_cc"},
            ],
            medidas=contagem,
            celulas_por_pagina=celulas,
        ).drop(columns=["qtd_lancamentos"]),
        "dim_negocio": _cubo_para_dataframe(
            cliente,
            dimensoes=[
                {"campo": CAMPO_NEGOCIO, "rotulo": "negocio"},
                {"campo": CAMPO_DESCRICAO_NEGOCIO, "rotulo": "descricao_negocio"},
            ],
            medidas=contagem,
            celulas_por_pagina=celulas,
        ).drop(columns=["qtd_lancamentos"]),
    }


def extrair_totais_conferencia(cliente: ClienteEngineQlik, parametros: Parametros) -> pd.DataFrame:
    """Totais mensais no Qlik, no MESMO escopo do fato, para o critério de conclusão."""
    medidas = [
        {
            "rotulo": "qtd_total",
            "expressao": f"Sum({{<[{CAMPO_CC}]-={{0}}>}} FlagLancamentoDebito)"
            f"+Sum({{<[{CAMPO_CC}]-={{0}}>}} FlagLancamentoCredito)",
        },
        {
            "rotulo": "valor_total",
            "expressao": f"Sum({{<[{CAMPO_CC}]-={{0}}>}} [Valor Lançamento])",
        },
    ]
    return _cubo_para_dataframe(
        cliente,
        dimensoes=[{"campo": CAMPO_MES_ANO, "rotulo": "ano_mes"}],
        medidas=medidas,
        celulas_por_pagina=parametros.extracao.pagina_celulas,
    )


# --------------------------------------------------------------- transformação


def transformar(bruto: pd.DataFrame, parametros: Parametros) -> pd.DataFrame:
    """Aplica janela, exclusões-salvaguarda, proxy de origem e tipos finais."""
    df = bruto.copy()
    df = df[df["centro_custo"].notna()]
    df = df[
        (df["ano_mes"] >= parametros.extracao.mes_inicio)
        & (df["ano_mes"] <= parametros.extracao.mes_fim)
    ]
    df["negocio"] = df["negocio"].astype(int)
    # Salvaguarda: as exclusões já entram por seleção no Qlik, mas o filtro é
    # regra de negócio obrigatória — reaplicar aqui torna o parquet correto
    # mesmo se a seleção falhar silenciosamente.
    df = df[~df["negocio"].isin(parametros.exclusoes.negocios)]

    df["conta_contabil"] = df["conta_contabil"].astype(str)
    df["centro_custo"] = df["centro_custo"].astype(str)
    # Salvaguarda contra rateio automático: nenhuma conta da árvore 9 pode
    # vazar para o fato (CLAUDE.md §2.6) — o escopo por N1 já as exclui.
    df = df[~df["conta_contabil"].str.startswith(parametros.extracao.prefixo_conta_rateio)]
    df["dado_suspeito"] = df["ano_mes"].isin(parametros.exclusoes.meses_suspeitos)

    for coluna in ("qtd_debito", "qtd_credito", "qtd_zeramento"):
        df[coluna] = df[coluna].fillna(0).astype("int64")
    for coluna in ("valor_debito", "valor_credito", "valor_zeramento"):
        # Ainda em float aqui — a conferência com o Qlik compara somas cruas;
        # a conversão para Decimal acontece em materializar().
        df[coluna] = df[coluna].fillna(0.0).astype("float64")

    colunas = [
        "ano_mes",
        "conta_contabil",
        "centro_custo",
        "negocio",
        "qtd_debito",
        "qtd_credito",
        "valor_debito",
        "valor_credito",
        "qtd_zeramento",
        "valor_zeramento",
        "dado_suspeito",
    ]
    return df[colunas].sort_values(["ano_mes", "conta_contabil", "centro_custo"]).reset_index(drop=True)


def _para_decimal(valor) -> Decimal:
    """Dinheiro nunca fica em float (CLAUDE.md §4) — quantiza em 2 casas."""
    if valor is None or pd.isna(valor):
        return Decimal("0.00")
    return Decimal(str(valor)).quantize(DOIS_DECIMAIS, rounding=ROUND_HALF_UP)


def materializar(fato: pd.DataFrame) -> pa.Table:
    """Converte o fato validado para o esquema final (dinheiro em decimal 18,2)."""
    df = fato.copy()
    for coluna in ("valor_debito", "valor_credito", "valor_zeramento"):
        df[coluna] = df[coluna].map(_para_decimal)
    return pa.Table.from_pandas(df, schema=ESQUEMA_FATO, preserve_index=False)


ESQUEMA_FATO = pa.schema(
    [
        ("ano_mes", pa.string()),
        ("conta_contabil", pa.string()),
        ("centro_custo", pa.string()),
        ("negocio", pa.int32()),
        ("qtd_debito", pa.int64()),
        ("qtd_credito", pa.int64()),
        ("valor_debito", pa.decimal128(18, 2)),
        ("valor_credito", pa.decimal128(18, 2)),
        ("qtd_zeramento", pa.int64()),
        ("valor_zeramento", pa.decimal128(18, 2)),
        ("dado_suspeito", pa.bool_()),
    ]
)


# ------------------------------------------------------------------ validação


def conferir(fato: pd.DataFrame, totais_qlik: pd.DataFrame, parametros: Parametros) -> pd.DataFrame:
    """Critério de conclusão da spec: contagem e soma batendo com o Qlik, mês a mês.

    O total do Qlik inclui zeramento; no parquet o zeramento está em colunas
    separadas — a conferência soma tudo de volta.
    """
    tolerancia = Decimal(str(parametros.extracao.tolerancia_conferencia))
    janela = totais_qlik[
        (totais_qlik["ano_mes"] >= parametros.extracao.mes_inicio)
        & (totais_qlik["ano_mes"] <= parametros.extracao.mes_fim)
    ]
    parquet_por_mes = fato.groupby("ano_mes").agg(
        qtd=("qtd_debito", "sum"),
        qtd_cred=("qtd_credito", "sum"),
        qtd_zer=("qtd_zeramento", "sum"),
        val_deb=("valor_debito", "sum"),
        val_cred=("valor_credito", "sum"),
        val_zer=("valor_zeramento", "sum"),
    )

    divergencias = []
    for _, linha in janela.iterrows():
        mes = linha["ano_mes"]
        if mes not in parquet_por_mes.index:
            divergencias.append({"ano_mes": mes, "problema": "mês ausente no parquet"})
            continue
        materializado = parquet_por_mes.loc[mes]
        qtd_parquet = int(materializado["qtd"] + materializado["qtd_cred"] + materializado["qtd_zer"])
        qtd_qlik = int(linha["qtd_total"] or 0)
        valor_parquet = float(materializado["val_deb"] + materializado["val_cred"] + materializado["val_zer"])
        valor_qlik = float(linha["valor_total"] or 0.0)
        if qtd_parquet != qtd_qlik:
            divergencias.append(
                {"ano_mes": mes, "problema": f"contagem: parquet={qtd_parquet} qlik={qtd_qlik}"}
            )
        if abs(valor_parquet - valor_qlik) > float(tolerancia):
            divergencias.append(
                {"ano_mes": mes, "problema": f"valor: parquet={valor_parquet} qlik={valor_qlik}"}
            )

    if divergencias:
        raise ErroConferencia(f"conferência com o Qlik falhou: {divergencias}")
    return janela


def relatorio_completude(fato: pd.DataFrame, dimensoes: dict[str, pd.DataFrame]) -> str:
    """Relatório exigido pelo critério de conclusão da spec 01."""
    linhas = [
        "# Relatório de completude — Spec 01 (base histórica par × mês)",
        "",
        f"- Linhas do fato: **{len(fato)}**",
        f"- Janela: **{fato['ano_mes'].min()} a {fato['ano_mes'].max()}** "
        f"({fato['ano_mes'].nunique()} meses)",
        f"- Pares (conta × CC) distintos: **{fato.groupby(['conta_contabil', 'centro_custo']).ngroups}**",
        f"- Centros de custo distintos: **{fato['centro_custo'].nunique()}**",
        f"- Contas distintas: **{fato['conta_contabil'].nunique()}**",
        "",
        f"- Linhas com dado_suspeito=True (meses com falha de carga conhecida): "
        f"{int(fato['dado_suspeito'].sum())}",
        "",
        "## Percentual de nulos por coluna",
    ]
    for coluna in fato.columns:
        pct = 100.0 * fato[coluna].isna().mean()
        linhas.append(f"- {coluna}: {pct:.2f}%")
    linhas += ["", "## Dimensões"]
    for nome, df in dimensoes.items():
        linhas.append(f"- {nome}: {len(df)} linhas")
    linhas += [
        "",
        "> Limitações herdadas da fonte (ver docs/diagnostico-fonte-spec01.md):",
        "> sem origem_lancamento nativa, sem id de lançamento, sem documento/usuário;",
        "> grão materializado é par × mês, não lançamento a lançamento.",
        "> Rateio automático (árvore 9) excluído estruturalmente do fato (CLAUDE.md §2.6).",
        "",
    ]
    return "\n".join(linhas)


# ------------------------------------------------------------------ orquestra


def executar(parametros: Parametros | None = None) -> Path:
    parametros = parametros or carregar_parametros()
    chave = os.environ.get(parametros.extracao.variavel_api_key)
    if not chave:
        raise SystemExit(
            f"variável {parametros.extracao.variavel_api_key} não definida — "
            "gere uma API key no Qlik Cloud e exporte-a antes de executar."
        )

    PASTA_DADOS.mkdir(exist_ok=True)
    with ClienteEngineQlik(
        parametros.extracao.tenant, parametros.extracao.app_id, chave
    ) as cliente:
        aplicar_escopo(cliente, parametros)
        fato = transformar(extrair_fato(cliente, parametros), parametros)
        dimensoes = extrair_dimensoes(cliente, parametros)
        totais = extrair_totais_conferencia(cliente, parametros)

    conferir(fato, totais, parametros)

    caminho_fato = PASTA_DADOS / "lancamentos_par_mes.parquet"
    pq.write_table(materializar(fato), caminho_fato)
    for nome, df in dimensoes.items():
        pq.write_table(
            pa.Table.from_pandas(df, preserve_index=False), PASTA_DADOS / f"{nome}.parquet"
        )
    (PASTA_DADOS / "relatorio_completude_spec01.md").write_text(
        relatorio_completude(fato, dimensoes), encoding="utf-8"
    )
    return caminho_fato


if __name__ == "__main__":
    caminho = executar()
    print(f"base histórica materializada em {caminho}", file=sys.stderr)

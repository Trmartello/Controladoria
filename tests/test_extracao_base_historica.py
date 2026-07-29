"""Testes da transformação e conferência da spec 01 (sem rede — só DataFrames)."""

from decimal import Decimal

import pandas as pd
import pytest

from svl.config import carregar_parametros
from svl.extracao_base_historica import (
    ErroConferencia,
    ORIGEM_NAO_CLASSIFICADA,
    ORIGEM_RATEIO,
    _para_decimal,
    conferir,
    materializar,
    relatorio_completude,
    transformar,
)


@pytest.fixture(scope="module")
def parametros():
    return carregar_parametros()


def _bruto_minimo() -> pd.DataFrame:
    """Duas linhas válidas + uma de CC nulo + uma de negócio excluído + uma fora da janela."""
    return pd.DataFrame(
        {
            "ano_mes": ["2025-01", "2025-02", "2025-02", "2025-02", "2024-12"],
            "conta_contabil": ["3.01.01", "9.07.01", "3.01.01", "3.01.01", "3.01.01"],
            "centro_custo": ["5100", "5171", None, "5100", "5100"],
            "negocio": ["1", "9", "1", "18", "1"],
            "qtd_debito": [2, 1, 3, 4, 5],
            "qtd_credito": [0, 0, 0, 0, 0],
            "valor_debito": [100.505, 33.334, 9.9, 7.7, 5.5],
            "valor_credito": [0.0, None, 0.0, 0.0, 0.0],
            "qtd_zeramento": [0, 0, 0, 0, 0],
            "valor_zeramento": [None, None, None, None, None],
        }
    )


def test_transformar_aplica_regras(parametros):
    fato = transformar(_bruto_minimo(), parametros)

    # CC nulo, negócio 18 e mês fora da janela caem fora.
    assert len(fato) == 2
    assert set(fato["centro_custo"]) == {"5100", "5171"}
    assert 18 not in set(fato["negocio"])

    # Proxy de origem: conta sob N1=9 é RATEIO; o resto fica não classificado.
    por_conta = fato.set_index("conta_contabil")["origem_proxy"]
    assert por_conta["9.07.01"] == ORIGEM_RATEIO
    assert por_conta["3.01.01"] == ORIGEM_NAO_CLASSIFICADA

    # jan/2025 é mês suspeito conhecido (falha de carga) — flag obrigatória.
    suspeito = fato.set_index("ano_mes")["dado_suspeito"]
    assert bool(suspeito["2025-01"]) is True
    assert bool(suspeito["2025-02"]) is False


def test_para_decimal_quantiza_meio_para_cima():
    assert _para_decimal(100.505) == Decimal("100.51")
    assert _para_decimal(None) == Decimal("0.00")


def test_materializar_produz_decimal_18_2(parametros):
    tabela = materializar(transformar(_bruto_minimo(), parametros))
    tipo = tabela.schema.field("valor_debito").type
    assert str(tipo) == "decimal128(18, 2)"


def _totais(qtd: int, valor: float) -> pd.DataFrame:
    return pd.DataFrame({"ano_mes": ["2025-01"], "qtd_total": [qtd], "valor_total": [valor]})


def test_conferir_aceita_totais_iguais(parametros):
    fato = transformar(_bruto_minimo(), parametros)
    fato = fato[fato["ano_mes"] == "2025-01"]
    conferir(fato, _totais(qtd=2, valor=100.505), parametros)


def test_conferir_rejeita_contagem_divergente(parametros):
    fato = transformar(_bruto_minimo(), parametros)
    fato = fato[fato["ano_mes"] == "2025-01"]
    with pytest.raises(ErroConferencia, match="contagem"):
        conferir(fato, _totais(qtd=99, valor=100.505), parametros)


def test_conferir_rejeita_valor_divergente(parametros):
    fato = transformar(_bruto_minimo(), parametros)
    fato = fato[fato["ano_mes"] == "2025-01"]
    with pytest.raises(ErroConferencia, match="valor"):
        conferir(fato, _totais(qtd=2, valor=200.0), parametros)


def test_relatorio_cita_limitacoes_da_fonte(parametros):
    fato = transformar(_bruto_minimo(), parametros)
    relatorio = relatorio_completude(fato, {"dim_conta": pd.DataFrame({"c": [1]})})
    assert "origem_lancamento" in relatorio
    assert "par × mês" in relatorio

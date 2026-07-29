"""Testes da carga de config/parametros.yaml."""

import pytest

from svl.config import (
    CAMINHO_PARAMETROS_PADRAO,
    Parametros,
    carregar_parametros,
)


@pytest.fixture(scope="module")
def parametros() -> Parametros:
    return carregar_parametros()


def test_arquivo_de_parametros_existe():
    assert CAMINHO_PARAMETROS_PADRAO.is_file()


def test_modo_default_e_shadow(parametros):
    # Princípio 3 do CLAUDE.md: o sistema nasce em shadow mode, nunca bloqueando.
    assert parametros.modo == "SHADOW"
    assert parametros.modo_preventivo_ativo is False


def test_pesos_ial_somam_100(parametros):
    assert parametros.pesos_ial.total() == 100


def test_semaforo_coerente(parametros):
    assert 0 < parametros.semaforo.amarelo_min < parametros.semaforo.verde_min <= 100


def test_exclusoes_da_coperdia(parametros):
    # Regras de negócio obrigatórias do CLAUDE.md §3.
    assert 18 in parametros.exclusoes.negocios
    assert 20 in parametros.exclusoes.negocios
    assert set(parametros.exclusoes.filiais_apenas_silo) == {80, 99, 116, 122}
    assert "2025-01" in parametros.exclusoes.meses_suspeitos


def test_parametros_estatisticos_minimos(parametros):
    # 18 meses: a fonte só tem fato desde jan/2025 (docs/diagnostico-fonte-spec01.md).
    assert parametros.janela_historica_meses == 18
    assert parametros.min_ocorrencias_faixa_valor >= 1
    assert parametros.min_ciclos_sazonalidade >= 2
    assert parametros.mad.fator_consistencia == pytest.approx(1.4826)


def test_extracao_configurada(parametros):
    extracao = parametros.extracao
    assert extracao.app_id
    assert extracao.mes_inicio <= extracao.mes_fim
    assert extracao.prefixo_conta_rateio == "9."
    assert extracao.tolerancia_conferencia > 0
    # A chave de API nunca vive no YAML — apenas o nome da variável de ambiente.
    assert extracao.variavel_api_key == "QLIK_API_KEY"


def test_modo_invalido_e_rejeitado(tmp_path):
    invalido = tmp_path / "parametros.yaml"
    conteudo = CAMINHO_PARAMETROS_PADRAO.read_text(encoding="utf-8")
    invalido.write_text(conteudo.replace("modo: SHADOW", "modo: LIVRE"), encoding="utf-8")
    with pytest.raises(ValueError, match="modo inválido"):
        carregar_parametros(invalido)

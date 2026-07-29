"""Carga tipada de `config/parametros.yaml`.

Todo threshold do SVL vive no YAML versionado — nenhum número mágico no código
(CLAUDE.md §4). Este módulo é o único caminho de leitura dos parâmetros.
"""

from dataclasses import dataclass, field
from pathlib import Path

import yaml

RAIZ_PROJETO = Path(__file__).resolve().parents[2]
CAMINHO_PARAMETROS_PADRAO = RAIZ_PROJETO / "config" / "parametros.yaml"

MODOS_VALIDOS = ("SHADOW", "PREVENTIVO")


@dataclass(frozen=True)
class ParametrosMad:
    k: float
    fator_consistencia: float
    fallback_pct_mediana: float


@dataclass(frozen=True)
class ParametrosCargaMatriz:
    meses_para_permitido: int
    min_ocorrencias_justificar: int


@dataclass(frozen=True)
class PesosIal:
    elegibilidade: int
    frequencia: int
    valor: int
    sazonalidade: int

    def total(self) -> int:
        return self.elegibilidade + self.frequencia + self.valor + self.sazonalidade


@dataclass(frozen=True)
class TetosIal:
    justificar: int
    cc_em_carencia: int


@dataclass(frozen=True)
class Semaforo:
    verde_min: int
    amarelo_min: int


@dataclass(frozen=True)
class Alarmes:
    falso_positivo_max: float
    lead_time_horas_max: int
    cobertura_minima_preventivo: float


@dataclass(frozen=True)
class Exclusoes:
    negocios: list[int] = field(default_factory=list)
    filiais_apenas_silo: list[int] = field(default_factory=list)
    meses_suspeitos: list[str] = field(default_factory=list)


@dataclass(frozen=True)
class Parametros:
    modo: str
    janela_historica_meses: int
    min_ocorrencias_faixa_valor: int
    min_ciclos_sazonalidade: int
    meses_carencia_cc_novo: int
    mad: ParametrosMad
    carga_matriz: ParametrosCargaMatriz
    pesos_ial: PesosIal
    tetos_ial: TetosIal
    semaforo: Semaforo
    alarmes: Alarmes
    exclusoes: Exclusoes

    @property
    def modo_preventivo_ativo(self) -> bool:
        """Bloqueio só existe com flag explícita — shadow mode é o padrão (CLAUDE.md §2.3)."""
        return self.modo == "PREVENTIVO"


def carregar_parametros(caminho: Path | str = CAMINHO_PARAMETROS_PADRAO) -> Parametros:
    """Lê o YAML e devolve os parâmetros tipados, validando as invariantes básicas."""
    with open(caminho, encoding="utf-8") as arquivo:
        bruto = yaml.safe_load(arquivo)

    parametros = Parametros(
        modo=bruto["modo"],
        janela_historica_meses=bruto["janela_historica_meses"],
        min_ocorrencias_faixa_valor=bruto["min_ocorrencias_faixa_valor"],
        min_ciclos_sazonalidade=bruto["min_ciclos_sazonalidade"],
        meses_carencia_cc_novo=bruto["meses_carencia_cc_novo"],
        mad=ParametrosMad(**bruto["mad"]),
        carga_matriz=ParametrosCargaMatriz(**bruto["carga_matriz"]),
        pesos_ial=PesosIal(**bruto["pesos_ial"]),
        tetos_ial=TetosIal(**bruto["tetos_ial"]),
        semaforo=Semaforo(**bruto["semaforo"]),
        alarmes=Alarmes(**bruto["alarmes"]),
        exclusoes=Exclusoes(**bruto["exclusoes"]),
    )

    if parametros.modo not in MODOS_VALIDOS:
        raise ValueError(f"modo inválido: {parametros.modo!r} (esperado um de {MODOS_VALIDOS})")
    if parametros.pesos_ial.total() != 100:
        raise ValueError(f"pesos_ial devem somar 100, somam {parametros.pesos_ial.total()}")
    if parametros.semaforo.verde_min <= parametros.semaforo.amarelo_min:
        raise ValueError("semaforo.verde_min deve ser maior que semaforo.amarelo_min")

    return parametros

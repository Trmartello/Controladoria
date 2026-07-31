"""Camada de dados do sistema de planejamento estratégico (SQLite)."""

import sqlite3
from pathlib import Path

import pandas as pd

DB_PATH = Path(__file__).parent / "planejamento.db"

STATUS_ACAO = ["Não iniciada", "Em andamento", "Concluída", "Atrasada"]
PERSPECTIVAS = [
    "Financeira",
    "Clientes / Cooperados",
    "Processos Internos",
    "Aprendizado e Crescimento",
]
QUADRANTES_SWOT = ["Força", "Fraqueza", "Oportunidade", "Ameaça"]

SCHEMA = """
CREATE TABLE IF NOT EXISTS diretrizes (
    tipo TEXT PRIMARY KEY,          -- missao | visao | valores
    texto TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS swot (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quadrante TEXT NOT NULL,
    descricao TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS objetivos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    perspectiva TEXT NOT NULL,
    titulo TEXT NOT NULL,
    descricao TEXT DEFAULT '',
    responsavel TEXT DEFAULT '',
    prazo TEXT DEFAULT ''
);

CREATE TABLE IF NOT EXISTS acoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    objetivo_id INTEGER REFERENCES objetivos(id) ON DELETE CASCADE,
    o_que TEXT NOT NULL,
    por_que TEXT DEFAULT '',
    quem TEXT DEFAULT '',
    quando TEXT DEFAULT '',
    onde TEXT DEFAULT '',
    como TEXT DEFAULT '',
    quanto TEXT DEFAULT '',
    status TEXT DEFAULT 'Não iniciada',
    progresso INTEGER DEFAULT 0    -- 0 a 100
);

CREATE TABLE IF NOT EXISTS indicadores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    objetivo_id INTEGER REFERENCES objetivos(id) ON DELETE CASCADE,
    nome TEXT NOT NULL,
    unidade TEXT DEFAULT '',
    meta REAL,
    realizado REAL,
    sentido TEXT DEFAULT 'Maior é melhor'  -- ou 'Menor é melhor'
);
"""


def get_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def init_db() -> None:
    with get_conn() as conn:
        conn.executescript(SCHEMA)
        for tipo in ("missao", "visao", "valores"):
            conn.execute(
                "INSERT OR IGNORE INTO diretrizes (tipo, texto) VALUES (?, '')",
                (tipo,),
            )


def query_df(sql: str, params: tuple = ()) -> pd.DataFrame:
    with get_conn() as conn:
        return pd.read_sql_query(sql, conn, params=params)


def execute(sql: str, params: tuple = ()) -> None:
    with get_conn() as conn:
        conn.execute(sql, params)


# ---- Diretrizes ----

def get_diretriz(tipo: str) -> str:
    df = query_df("SELECT texto FROM diretrizes WHERE tipo = ?", (tipo,))
    return df["texto"].iloc[0] if not df.empty else ""


def set_diretriz(tipo: str, texto: str) -> None:
    execute("UPDATE diretrizes SET texto = ? WHERE tipo = ?", (texto, tipo))


# ---- Objetivos ----

def listar_objetivos() -> pd.DataFrame:
    return query_df("SELECT * FROM objetivos ORDER BY perspectiva, id")


def opcoes_objetivos() -> dict:
    """Mapa 'titulo (perspectiva)' -> id, para caixas de seleção."""
    df = listar_objetivos()
    return {
        f"{r.titulo} ({r.perspectiva})": int(r.id) for r in df.itertuples()
    }

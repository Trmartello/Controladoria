"""Cliente mínimo do Qlik Engine (JSON-RPC sobre WebSocket) para o Qlik Cloud.

Cobre apenas o que a extração da spec 01 precisa: abrir o app, aplicar seleções,
criar hypercubes de sessão e paginar os dados. Autenticação por API key
(Authorization: Bearer), lida via variável de ambiente — nunca versionada.

Uso:
    with ClienteEngineQlik(tenant, app_id, api_key) as cliente:
        cliente.selecionar_valores("N1", ["3 CONTAS DE RESULTADO"])
        cubo = cliente.criar_cubo(dimensoes=[...], medidas=[...])
        for pagina in cliente.paginar_cubo(cubo, celulas_por_pagina=9000):
            ...
"""

import json
import os
import ssl
from dataclasses import dataclass
from urllib.parse import urlparse

from websocket import WebSocket, create_connection


@dataclass(frozen=True)
class CuboSessao:
    """Referência a um hypercube de sessão criado no engine."""

    handle: int
    colunas: list[str]
    total_linhas: int


class ErroEngineQlik(RuntimeError):
    """Erro devolvido pelo Qlik Engine ou falha de protocolo."""


def _contexto_ssl() -> ssl.SSLContext:
    """Contexto TLS honrando o CA bundle do ambiente (proxy corporativo), se houver."""
    cafile = (
        os.environ.get("QLIK_CA_BUNDLE")
        or os.environ.get("SSL_CERT_FILE")
        or os.environ.get("REQUESTS_CA_BUNDLE")
    )
    return ssl.create_default_context(cafile=cafile)


def _proxy_https() -> dict:
    """Extrai host/porta/credenciais do HTTPS_PROXY para o handshake do WebSocket."""
    bruto = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")
    if not bruto:
        return {}
    proxy = urlparse(bruto)
    opcoes: dict = {
        "http_proxy_host": proxy.hostname,
        "http_proxy_port": proxy.port,
        "proxy_type": "http",
    }
    if proxy.username:
        opcoes["http_proxy_auth"] = (proxy.username, proxy.password or "")
    return opcoes


class ClienteEngineQlik:
    """Sessão JSON-RPC com um app específico do Qlik Cloud."""

    def __init__(self, tenant: str, app_id: str, api_key: str):
        self._tenant = tenant
        self._app_id = app_id
        self._api_key = api_key
        self._ws: WebSocket | None = None
        self._proximo_id = 0
        self._handle_app: int | None = None

    def __enter__(self) -> "ClienteEngineQlik":
        url = f"wss://{self._tenant}/app/{self._app_id}"
        self._ws = create_connection(
            url,
            header=[f"Authorization: Bearer {self._api_key}"],
            sslopt={"context": _contexto_ssl()},
            **_proxy_https(),
        )
        self._aguardar_conexao()
        self._handle_app = self._abrir_app()
        return self

    def __exit__(self, *args) -> None:
        if self._ws is not None:
            self._ws.close()
            self._ws = None

    # ---------------------------------------------------------------- protocolo

    def _aguardar_conexao(self) -> None:
        """Consome a notificação inicial do engine (OnConnected) antes do primeiro pedido."""
        assert self._ws is not None
        mensagem = json.loads(self._ws.recv())
        severidade = mensagem.get("params", {}).get("qSessionState")
        if mensagem.get("method") != "OnConnected":
            raise ErroEngineQlik(f"handshake inesperado do engine: {mensagem}")
        if severidade not in ("SESSION_CREATED", "SESSION_ATTACHED"):
            raise ErroEngineQlik(f"sessão não estabelecida: {severidade}")

    def _chamar(self, metodo: str, handle: int, params: dict | list) -> dict:
        assert self._ws is not None, "cliente não conectado — use como context manager"
        self._proximo_id += 1
        pedido_id = self._proximo_id
        self._ws.send(
            json.dumps(
                {
                    "jsonrpc": "2.0",
                    "id": pedido_id,
                    "method": metodo,
                    "handle": handle,
                    "params": params,
                }
            )
        )
        # O engine intercala notificações (OnAuthenticationInformation etc.);
        # descartamos tudo que não for a resposta ao nosso id.
        while True:
            mensagem = json.loads(self._ws.recv())
            if mensagem.get("id") != pedido_id:
                continue
            if "error" in mensagem:
                raise ErroEngineQlik(f"{metodo}: {mensagem['error']}")
            return mensagem["result"]

    def _abrir_app(self) -> int:
        resultado = self._chamar("OpenDoc", handle=-1, params=[self._app_id])
        return resultado["qReturn"]["qHandle"]

    # ----------------------------------------------------------------- seleções

    def selecionar_valores(self, campo: str, valores: list) -> None:
        """Seleciona valores exatos em um campo (texto ou numérico)."""
        assert self._handle_app is not None
        campo_handle = self._chamar(
            "GetField", handle=self._handle_app, params={"qFieldName": campo}
        )["qReturn"]["qHandle"]
        valores_qlik = [
            {"qNumber": v, "qIsNumeric": True}
            if isinstance(v, (int, float)) and not isinstance(v, bool)
            else {"qText": str(v)}
            for v in valores
        ]
        aplicado = self._chamar(
            "SelectValues",
            handle=campo_handle,
            params={"qFieldValues": valores_qlik, "qToggleMode": False},
        )["qReturn"]
        if not aplicado:
            raise ErroEngineQlik(f"seleção recusada pelo engine no campo {campo!r}")

    def limpar_selecoes(self) -> None:
        assert self._handle_app is not None
        self._chamar("ClearAll", handle=self._handle_app, params={})

    # ---------------------------------------------------------------- hypercube

    def criar_cubo(self, dimensoes: list[dict], medidas: list[dict]) -> CuboSessao:
        """Cria um hypercube de sessão.

        dimensoes: [{"campo": "Cód. Plano Contas", "rotulo": "conta_contabil"}, ...]
                   ("campo" aceita expressão iniciada por "=" para dimensão calculada;
                   valores nulos de dimensão calculada são suprimidos)
        medidas:   [{"expressao": "Sum(...)", "rotulo": "qtd_debito"}, ...]
        """
        assert self._handle_app is not None
        definicao = {
            "qInfo": {"qType": "svl-cubo-extracao"},
            "qHyperCubeDef": {
                "qDimensions": [
                    {
                        "qDef": {"qFieldDefs": [d["campo"]]},
                        "qNullSuppression": True,
                    }
                    for d in dimensoes
                ],
                "qMeasures": [{"qDef": {"qDef": m["expressao"]}} for m in medidas],
                "qSuppressZero": True,
                "qSuppressMissing": True,
                "qInitialDataFetch": [],
            },
        }
        handle = self._chamar(
            "CreateSessionObject", handle=self._handle_app, params={"qProp": definicao}
        )["qReturn"]["qHandle"]
        layout = self._chamar("GetLayout", handle=handle, params={})["qLayout"]
        total_linhas = layout["qHyperCube"]["qSize"]["qcy"]
        colunas = [d["rotulo"] for d in dimensoes] + [m["rotulo"] for m in medidas]
        return CuboSessao(handle=handle, colunas=colunas, total_linhas=total_linhas)

    def paginar_cubo(self, cubo: CuboSessao, celulas_por_pagina: int):
        """Itera as páginas do cubo; cada página é uma lista de linhas.

        Cada célula vem como (texto, numero) — o consumidor decide qual usar.
        """
        largura = len(cubo.colunas)
        altura_pagina = max(1, celulas_por_pagina // largura)
        topo = 0
        while topo < cubo.total_linhas:
            resultado = self._chamar(
                "GetHyperCubeData",
                handle=cubo.handle,
                params={
                    "qPath": "/qHyperCubeDef",
                    "qPages": [
                        {
                            "qLeft": 0,
                            "qTop": topo,
                            "qWidth": largura,
                            "qHeight": min(altura_pagina, cubo.total_linhas - topo),
                        }
                    ],
                },
            )
            matriz = resultado["qDataPages"][0]["qMatrix"]
            if not matriz:
                break
            yield [
                [(celula.get("qText"), celula.get("qNum")) for celula in linha]
                for linha in matriz
            ]
            topo += len(matriz)

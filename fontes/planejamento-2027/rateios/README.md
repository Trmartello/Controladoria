# Rateios do planejamento 2027 — planilhas de origem

Planilhas enviadas pela controladoria em 24/11/2026, guardadas aqui por pedido do
cliente (2026-11-25: "pode salvar as planilhas no repositório"). A cópia de
trabalho fica em `V:\Controladoria\003 - FP&A\5 - PLANEJAMENTO ORCAMENTARIO\PLANEJAMENTO - 2027\1 - CENTRO DE CUSTOS\RATEIOS IA`.

| Planilha | Linha da DRE | Carga gerada | Gerador |
|---|---|---|---|
| `Rateio Corporativo 2027.xlsm` (coluna K, Saldo, por mês) | 16.1 Rateio Corporativo Administrativo | `database/rateio_adm_2027.php` | `scripts/gerar_rateio.py` |
| `critério de rateios Corp Administrativo.xlsx` | 16.1: as contas do critério | `Projecao::CRITERIO_RATEIO_ADM` (no código) | — |
| `Rateio Corporativo Negocios 2027.xlsm` + `Critérios de rateios.xlsx` | 14.3 Rateio Corporativo Negócio | `database/rateio_negocios_2027.php` | `scripts/gerar_rateio_neg.py` |
| `Rateio_Financeiro - site planejamento.xlsx` | 14.2 Rateio Financeiro | `database/rateio_financeiro_2027.php` | `scripts/gerar_rateio_fin.py` |

Para regerar uma carga depois de uma planilha nova (precisa de `pip install openpyxl`):

```bash
cd fontes/planejamento-2027/rateios
python3 scripts/gerar_rateio.py "Rateio Corporativo 2027.xlsm" ../../../database/rateio_adm_2027.php
python3 scripts/gerar_rateio_neg.py "Rateio Corporativo Negocios 2027.xlsm" "Critérios de rateios.xlsx" ../../../database/rateio_negocios_2027.php
python3 scripts/gerar_rateio_fin.py "Rateio_Financeiro - site planejamento.xlsx" ../../../database/rateio_financeiro_2027.php
```

Em 25/11/2026 os três geradores, rodados sobre estas planilhas, reproduziram as
cargas do repositório byte a byte. Depois de regerar, rode
`testes/rateio_adm.php`, `testes/rateio_negocios.php` e
`testes/rateio_financeiro.php`, que conferem os totais contra a planilha.

Atualizações: em 25/11/2026 o cliente mandou o `Rateio Corporativo ADM 2027.xlsm`, que
substituiu o `Rateio Corporativo 2027.xlsm` (mesmo nome aqui, para o gerador e as provas):
total de −R$ 59.011.744,41 para −R$ 37.735.231,41 (créditos na 5205 Contabilidade, mais
Marketing na 5162). No mesmo dia o `Rateio Corporativo Negocios 2027.xlsm` chegou de novo,
idêntico ao guardado; o 5163 CENTRAL DE COMPRAS continua fora (decisão do cliente).

A linha 14.1 (Rateio Matriz/Central) não tem planilha: vale zero no planejado
(ver o CLAUDE.md).

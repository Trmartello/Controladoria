# Rateios do planejamento 2027 — planilhas de origem

Planilhas enviadas pela controladoria em 24/11/2026, guardadas aqui por pedido do
cliente (2026-11-25: "pode salvar as planilhas no repositório"). A cópia de
trabalho fica em `V:\Controladoria\003 - FP&A\5 - PLANEJAMENTO ORCAMENTARIO\PLANEJAMENTO - 2027\1 - CENTRO DE CUSTOS\RATEIOS IA`.

| Planilha | Linha da DRE | Carga gerada | Gerador |
|---|---|---|---|
| `Rateio Corporativo 2027.xlsm` (coluna Saldo, por mês) | 16.1 Rateio Corporativo Administrativo | `database/rateio_adm_2027.php` | `scripts/gerar_rateio.py` |
| `critério de rateios Corp Administrativo.xlsx` | 16.1: as contas do critério | `Projecao::CRITERIO_RATEIO_ADM` (no código) | — |
| `Rateio Corporativo Negocios 2027.xlsm` (coluna Saldo) + `Critérios de rateios.xlsx` | 14.3 Rateio Corporativo Negócio | `database/rateio_negocios_2027.php` | `scripts/gerar_rateio_neg.py` |
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
Marketing na 5162). Ainda em 25/11 veio uma 2ª versão, já pronta pela controladoria, sem as
colunas de débito e crédito (o Saldo passou da coluna K para a I; o gerador lê pelo nome do
cabeçalho): total −R$ 37.014.037,33, guardada no mesmo nome. No mesmo dia o `Rateio Corporativo Negocios 2027.xlsm` chegou de novo,
idêntico ao guardado; o 5163 CENTRAL DE COMPRAS continua fora (decisão do cliente).
Depois veio a versão de 25/11 do `Rateio Corporativo Negocios 2027.xlsm` (−R$ 37.678.709,77):
sem o 5163 e sem o 5112 GERÊNCIA SUINOCULTURA, com pequenos acréscimos em 15 centros. Ela
substituiu a guardada, no mesmo nome.
Em 27/11/2026 veio a versão seguinte (−R$ 36.944.068,77; R$ 734.641,00 a menos), com os
mesmos 26 centros e as mesmas regras; mudaram seis: 5151 (−1.897.205,21 → −1.051.745,21),
5152 (−9.097.257,26 → −9.027.257,26), 5192 (−11.631.238,71 → −11.431.135,71), 5196
(−1.448.522,82 → −1.443.118,82), 5224 Tecnoeste (−730.675,46 → −470.001,46) e 5232
Tecnorte (−206.255,00 → −853.255,00). Também substituiu a guardada, no mesmo nome.

A linha 14.1 (Rateio Matriz/Central) não tem planilha: vale zero no planejado
(ver o CLAUDE.md).

import sys, collections, openpyxl
from decimal import Decimal
f, saida = sys.argv[1:3]
ws = openpyxl.load_workbook(f, read_only=True, data_only=True).worksheets[0]
q4 = lambda d: (lambda t: t.rstrip('0').rstrip('.') if '.' in t else t)(format(d.quantize(Decimal('0.0001')), 'f'))
neg = collections.defaultdict(lambda: {}); nomes = {}; saldo_mes = {}; periodos = set(); n = 0
for i, r in enumerate(ws.iter_rows(values_only=True)):
    if i == 0 or r[0] is None: continue
    cod, nome, ano, mes, pct, saldo, val = r
    cod = str(int(cod)); mes = int(mes); periodos.add((int(ano), mes))
    assert mes not in neg[cod], (cod, mes)  # um valor por negócio e mês do calendário
    neg[cod][mes] = Decimal(str(val)); nomes[cod] = nome; n += 1
    saldo_mes.setdefault((int(ano), mes), Decimal(str(saldo)))
assert len(periodos) == 12 and all(len(m) == 12 for m in neg.values()), (len(periodos), {k: len(v) for k, v in neg.items()})
tot = sum(sum(m.values()) for m in neg.values())
tot_saldo = sum(saldo_mes.values())
ini, fim = min(periodos), max(periodos)
out = [f"""<?php
/**
 * RATEIO FINANCEIRO de 2027 por negócio e mês — linha 14.2 ("Rateios Financeiros"),
 * travada (pedido do cliente, 2026-11-24: "atualize o rateio financeiro por negócio no
 * projetado / orçado de 2027, totalizando por ano, ou seja na coluna proposta. E
 * bloqueie a célula").
 *
 * Origem: planilha `Rateio_Financeiro - site planejamento.xlsx`, coluna
 * "Rateio_fin_negocio", {n} linhas: os 12 meses de {ini[1]:02d}/{ini[0]} a {fim[1]:02d}/{fim[0]}, por negócio
 * (percentual × saldo do mês). Cada mês do calendário vai para o MESMO mês de 2027
 * (nov e dez de {ini[0]}, jan a out de {fim[0]}); o ano é a soma dos 12. Total R$ {q4(tot)}
 * (o saldo dos 12 meses é R$ {q4(tot_saldo)}; a diferença é arredondamento da planilha).
 * Gerado por script a partir da planilha, sem redigitação.
 *
 * Nas filiais, o negócio se divide pela projeção de 2026 da 14.2 de cada filial
 * (`Projecao::fixosDoRateioFin`).
 */
return [
    'ano' => 2027,
    'fonte' => 'Rateio_Financeiro - site planejamento.xlsx, coluna Rateio_fin_negocio ({ini[1]:02d}/{ini[0]} a {fim[1]:02d}/{fim[0]})',
    // Conferência: a soma de tudo abaixo (a carga recusa se não bater).
    'total' => {q4(tot)},
    // 'cód. negócio' => [mês => valor] (sinal da DRE)
    'negocios' => ["""]
for cod in sorted(neg, key=int):
    m = neg[cod]
    out.append(f"        '{cod}' => [" + ', '.join(f"{k} => {q4(m[k])}" for k in range(1, 13)) + f"],  // {nomes[cod]}: {q4(sum(m.values()))}")
out.append("    ],\n];\n")
open(saida, 'w', encoding='utf-8').write('\n'.join(out))
print(n, 'linhas', len(neg), 'negocios', 'total', q4(tot), 'saldo', q4(tot_saldo))
for cod in sorted(neg, key=int): print(cod, nomes[cod], q4(sum(neg[cod].values())))

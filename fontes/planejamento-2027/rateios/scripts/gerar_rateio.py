import sys, collections, openpyxl
from decimal import Decimal
f, saida = sys.argv[1:3]
ws = openpyxl.load_workbook(f, read_only=True, data_only=True).worksheets[0]
mes = collections.defaultdict(Decimal); cc = collections.defaultdict(Decimal); n = 0; total_planilha = None
for i, r in enumerate(ws.iter_rows(values_only=True)):
    if i == 0: continue
    if r[0] == 'Total': total_planilha = Decimal(str(r[10])); continue
    if r[2] is None: continue
    assert r[6] == 'CORPORATIVO' and r[9] == '2027 - 2026', r
    v = Decimal(str(r[10])); mes[int(r[2])] += v; cc[(r[4], r[5])] += v; n += 1
tot = sum(mes.values())
assert total_planilha is not None and abs(tot - total_planilha) < Decimal('0.01'), (tot, total_planilha)
assert sorted(mes) == list(range(1, 13))
q = lambda d: format(d.quantize(Decimal('0.01')), 'f')
out = [f"""<?php
/**
 * RATEIO CORPORATIVO ADMINISTRATIVO de 2027 — o total a ratear, mês a mês (linha
 * 16.1, "Rateios Corporativos Administrativo"). Pedido do cliente, 2026-11-24: "gere
 * um critério de rateio por negócio, olhando para a coluna K 'saldo' e por período /
 * mês […] numa proporção de valor orçado. Bloqueia o campo, a célula vai em rateio
 * corporativo administrativo".
 *
 * Origem: planilha `Rateio Corporativo 2027.xlsm` (aba "Rateio Corp ADM"), coluna K
 * "Saldo" (planejado débito + crédito) somada por `periodo`: {n} linhas, {len(cc)}
 * centros de custo da MATRIZ, tipo CORPORATIVO, total R$ {q(tot)} (o mesmo da linha
 * "Total" da planilha). Sinal da DRE: negativo é despesa. Gerado por script a partir
 * da planilha, sem redigitação.
 *
 * O CRITÉRIO não está aqui: é calculado na hora (`Projecao::rateioAdm`) — a parte de
 * cada negócio é a proporção da proposta de 2027 dele nas linhas de
 * `Projecao::CRITERIO_RATEIO_ADM` (vendas, promoções, pessoal, serviços
 * profissionais, serviços em geral, manutenção, materiais e viagens), como pediu o
 * cliente na planilha "critério de rateios Corp Administrativo.xlsx" — as contas sem
 * valor de lá já estão fora dessas linhas na DRE gerencial (são custo).
 *
 * Decisão do cliente (2026-11-24): o total vai INTEIRO para a 16.1; a 14.3
 * ("Rateios Corporativos Negócio") continua como está, lançável.
 */
return [
    'ano' => 2027,
    'fonte' => 'Rateio Corporativo 2027.xlsm, aba Rateio Corp ADM, coluna K (Saldo) por período',
    // Conferência: a soma dos meses (o carregamento recusa se não bater).
    'total' => {q(tot)},
    // mês => saldo a ratear (sinal da DRE)
    'meses' => ["""]
q4 = lambda d: (lambda t: t.rstrip('0').rstrip('.') if '.' in t else t)(format(d.quantize(Decimal('0.0001')), 'f'))
for m in range(1, 13):
    out.append(f"        {m} => {q4(mes[m])},")
out.append("    ],\n];\n")
open(saida, 'w', encoding='utf-8').write('\n'.join(out))
print(n, len(cc), q(tot))

import sys, re, collections, openpyxl
from decimal import Decimal
f_rat, f_crit, saida = sys.argv[1:4]
q4 = lambda d: (lambda t: t.rstrip('0').rstrip('.') if '.' in t else t)(format(d.quantize(Decimal('0.0001')), 'f'))
# critérios por centro de custo
ws = openpyxl.load_workbook(f_crit, read_only=True, data_only=True)['Centros de Custo']
regra = {}
for i, r in enumerate(ws.iter_rows(values_only=True)):
    if i == 0 or r[2] is None: continue
    cc, crit, fil, neg = int(r[2]), r[5], r[6], str(r[7]).strip()
    if str(neg).startswith('Todos'):
        exc = sorted(set(re.findall(r'\d+', neg)), key=int); negs = ('TODOS', exc)
    else:
        negs = ('LISTA', sorted(set(re.findall(r'(\d+)\s*-', neg)), key=int))
    d = regra.setdefault(cc, {'criterio': crit, 'negocios': negs, 'filiais': set(), 'todas': False, 'nome': r[4]})
    assert d['criterio'] == crit and d['negocios'] == negs, (cc, crit, negs, d)
    if isinstance(fil, str) and fil.strip().lower() == 'todas': d['todas'] = True
    else: d['filiais'].add(int(fil))
for cc, d in regra.items(): assert not (d['todas'] and d['filiais']), cc
# saldo por centro de custo e mês
ws = openpyxl.load_workbook(f_rat, read_only=True, data_only=True).worksheets[0]
mes = collections.defaultdict(lambda: collections.defaultdict(Decimal)); nomes = {}; tipos = {}; total_planilha = None; n = 0
for i, r in enumerate(ws.iter_rows(values_only=True)):
    if i == 0: continue
    if r[0] == 'Total': total_planilha = Decimal(str(r[10])); continue
    if r[2] is None: continue
    assert r[9] == '2027 - 2026', r
    cc = int(r[4]); mes[cc][int(r[2])] += Decimal(str(r[10])); nomes[cc] = r[5]; tipos[cc] = r[6]; n += 1
tot = sum(sum(m.values()) for m in mes.values())
assert abs(tot - total_planilha) < Decimal('0.01'), (tot, total_planilha)
fora = sorted(cc for cc in mes if cc not in regra)
sem_saldo = sorted(cc for cc in regra if cc not in mes)
tot_fora = sum(sum(mes[cc].values()) for cc in fora)
out = [f"""<?php
/**
 * RATEIO CORPORATIVO NEGÓCIO de 2027 — linha 14.3 ("Rateios Corporativos Negócio"),
 * travada (pedido do cliente, 2026-11-24: "distribua o valor mensalmente por negócios
 * […] olhando para essa planilha […] lançar na célula rateio corporativo negócio,
 * bloquear célula").
 *
 * Origem: `Rateio Corporativo Negocios 2027.xlsm` (coluna K "Saldo" por período e
 * centro de custo: {n} linhas, total da planilha R$ {q4(tot)}) e `Critérios de
 * rateios.xlsx` (aba "Centros de Custo": critério na coluna F, filiais na G, negócio
 * na H; aba "Critérios": as contas de cada critério). Gerado por script, sem
 * redigitação.
 *
 * Por centro de custo: os 12 meses (sinal da DRE), o critério, os negócios e as
 * filiais. A distribuição é calculada NA HORA (`Projecao::rateioNegocios`): a parte
 * de cada negócio (e de cada filial) é a proporção da proposta de 2027 no critério —
 * DESPESAS (vendas, promoções, pessoal, serviços profissionais, serviços em geral,
 * manutenção, materiais, viagens) ou FATURAMENTO (receita bruta) —, só nos negócios da
 * coluna H e, quando a coluna G lista filiais, só nelas.
 *
 * Decisões (2026-11-24): contas de CUSTO dos critérios 1 e 2 ficam fora (a proposta
 * não separa a conta dentro do Custo das Vendas); centro de custo sem regra na
 * planilha de critérios fica FORA do rateio (5163 CENTRAL DE COMPRAS, "APENAS
 * PLANEJAR"); centro sem critério (5191) vai inteiro ao negócio dele.
 */
return [
    'ano' => 2027,
    'fonte' => 'Rateio Corporativo Negocios 2027.xlsm (coluna K) + Critérios de rateios.xlsx',
    // Conferência: a soma dos centros abaixo (sem os de fora).
    'total' => {q4(tot - tot_fora)},
    'total_planilha' => {q4(tot)},
    'fora' => ["""]
for cc in fora:
    out.append(f"        ['centro' => {cc}, 'nome' => '{nomes[cc]}', 'tipo' => '{tipos[cc]}', 'valor' => {q4(sum(mes[cc].values()))}],")
out.append("    ],\n    'centros' => [")
for cc in sorted(regra):
    if cc not in mes: continue
    d = regra[cc]
    tipo, lst = d['negocios']
    negs = f"['TODOS', 'exceto' => [{', '.join(repr(x) for x in lst)}]]" if tipo == 'TODOS' else '[' + ', '.join(repr(x) for x in lst) + ']'
    fil = "'TODAS'" if d['todas'] else '[' + ', '.join(str(x) for x in sorted(d['filiais'])) + ']'
    crit = 'null' if d['criterio'] is None else str(int(d['criterio']))
    meses = ', '.join(f"{m} => {q4(mes[cc][m])}" for m in range(1, 13))
    out.append(f"        {cc} => ['nome' => '{nomes[cc]}', 'criterio' => {crit}, 'negocios' => {negs}, 'filiais' => {fil},\n            'meses' => [{meses}]],")
out.append("    ],\n];\n")
open(saida, 'w', encoding='utf-8').write('\n'.join(out))
print('linhas', n, 'centros', len(mes), 'com regra', len([c for c in mes if c in regra]), 'fora', fora, q4(tot_fora), 'regra sem saldo', sem_saldo, 'total', q4(tot), 'rateado', q4(tot - tot_fora))

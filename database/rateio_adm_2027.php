<?php
/**
 * RATEIO CORPORATIVO ADMINISTRATIVO de 2027 — o total a ratear, mês a mês (linha
 * 16.1, "Rateios Corporativos Administrativo"). Pedido do cliente, 2026-11-24: "gere
 * um critério de rateio por negócio, olhando para a coluna K 'saldo' e por período /
 * mês […] numa proporção de valor orçado. Bloqueia o campo, a célula vai em rateio
 * corporativo administrativo".
 *
 * Origem: planilha `Rateio Corporativo 2027.xlsm` (aba "Rateio Corp ADM"), coluna K
 * "Saldo" (planejado débito + crédito) somada por `periodo`: 514 linhas, 43
 * centros de custo da MATRIZ, tipo CORPORATIVO, total R$ -59011744.41 (o mesmo da linha
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
    'total' => -59011744.41,
    // mês => saldo a ratear (sinal da DRE)
    'meses' => [
        1 => -1454868.455,
        2 => -3508321.395,
        3 => -3975842.515,
        4 => -5843845.555,
        5 => -5352269.535,
        6 => -5392507.105,
        7 => -5549462.585,
        8 => -5393636.215,
        9 => -5571027.935,
        10 => -5634999.715,
        11 => -5880835.005,
        12 => -5454128.395,
    ],
];

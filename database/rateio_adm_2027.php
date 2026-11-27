<?php
/**
 * RATEIO CORPORATIVO ADMINISTRATIVO de 2027 — o total a ratear, mês a mês (linha
 * 16.1, "Rateios Corporativos Administrativo"). Pedido do cliente, 2026-11-24: "gere
 * um critério de rateio por negócio, olhando para a coluna K 'saldo' e por período /
 * mês […] numa proporção de valor orçado. Bloqueia o campo, a célula vai em rateio
 * corporativo administrativo".
 *
 * Origem: planilha `Rateio Corporativo 2027.xlsm` (aba "Rateio Corp ADM"), coluna
 * "Saldo" somada por `periodo`: 514 linhas, 43
 * centros de custo da MATRIZ, tipo CORPORATIVO, total R$ -36619882.83 (o mesmo da linha
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
    'fonte' => 'Rateio Corporativo 2027.xlsm (Rateio Corporativo ADM 2027 de 27/11), aba Rateio Corp ADM, coluna Saldo por período',
    // Conferência: a soma dos meses (o carregamento recusa se não bater).
    'total' => -36619882.83,
    // mês => saldo a ratear (sinal da DRE)
    'meses' => [
        1 => -1400244.955,
        2 => -3408811.715,
        3 => -1715103.555,
        4 => -1704121.595,
        5 => -1328732.725,
        6 => -5377155.295,
        7 => -1253904.775,
        8 => -3089106.405,
        9 => -5319090.125,
        10 => -2085696.905,
        11 => -4454258.195,
        12 => -5483656.585,
    ],
];

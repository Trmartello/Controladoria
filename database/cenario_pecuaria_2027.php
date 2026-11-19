<?php

/**
 * CENÁRIO de faturamento da Pecuária para 2027 — a proposta da Receita de Vendas
 * (pedido do cliente, 2026-11-19: "carregue o cenário realista para o planejamento;
 * os dados da planilha (faturamento) estão no formato da DRE antiga, precisa converter").
 *
 * Origem: planilha `PECUÁRIA VOLUMES e FATURAMENTO Planejamento 2027.xlsx`, aba
 * "Projetado 2027", linhas 24 a 40 (volume × preço base com pontuações), recalculada
 * a partir das fórmulas dela: preço base do kg 4,83 / 5,13 / 5,43 nos cenários
 * pessimista / realista / otimista. Total realista (célula D41): R$ 732.218.693,78.
 *
 * O faturamento da planilha está no FORMATO ANTIGO: a receita bruta com o PIS/COFINS
 * dentro. O que se lança na Receita de Vendas (1.1) é no FORMATO NOVO, sem a CBS
 * (`Projecao::aplicar` divide o lançado por 1 − taxa para voltar ao antigo). Então:
 *   receita de vendas = faturamento × (1 − taxa), CBS = faturamento × taxa,
 * com a taxa de PIS/COFINS da Pecuária que o sistema usa (`Reforma::pisCofinsDoNegocio`,
 * projeção de 2026: R$ 476.428 sobre R$ 830.654.939 = 0,05736%).
 */
return [
    'chave' => 'cenario_pecuaria_2027_realista',
    'negocio' => '2',
    'ano' => 2027,
    'conta' => '1.1',
    'cenario' => 'REALISTA',
    'fonte' => 'Planilha PECUÁRIA VOLUMES e FATURAMENTO Planejamento 2027.xlsx, aba Projetado 2027, linhas 24 a 40',
    'taxa_pis_cofins' => 0.000573556978785541,
    'faturamento_antigo' => 732218693.78,
    'receita_vendas' => 731798724.64,
    // item => [pessimista, realista, otimista], formato antigo
    'itens' => [
        'Aurora — Sui III, remuneração produtor' => [99494868.00, 102985916.00, 104731440.00],
        'Bonificação Sui III — ração ideal' => [3683055.64, 4555817.64, 5428579.64],
        'Bonificação Sui III — assistência técnica' => [3388585.74, 3388585.74, 3388585.74],
        'Bonificação Sui III — 1,5% sobre a carcaça' => [15892632.08, 16879752.08, 17866872.09],
        'Bonificação Sui III — medicamentos' => [5236572.00, 5236572.00, 5236572.00],
        'Aurora — crechário, remuneração produtor' => [11785500.00, 12964050.00, 13356900.00],
        'Parceria Copérdia' => [104158814.76, 110628306.36, 117097797.96],
        'Aurora — compras de terceiros' => [22812965.58, 24229919.97, 25646874.35],
        'Venda de leitões de 7,7 kg (creche Aurora)' => [126212968.92, 133893271.29, 141573573.65],
        'Venda de leitões de 23 kg (Aurora)' => [158801561.10, 168464942.10, 178128323.10],
        'Venda de leitoas direto para UPD' => [9212742.00, 9784962.00, 10357182.00],
        'Venda de descarte da genética' => [8073089.98, 8574524.14, 9075958.30],
        'Venda de fêmeas de 4º sítio' => [29127653.10, 30936824.10, 32745995.10],
        'Venda de doses de sêmen' => [6095929.19, 6095929.19, 6095929.19],
        'Embrapa — cooperação técnica' => [2000000.00, 2300000.00, 2500000.00],
        'Aurora — aves, remuneração produtor' => [63439625.70, 65746521.18, 67419020.40],
        'Gobby' => [17035200.00, 25552800.00, 34070400.00],
    ],
];

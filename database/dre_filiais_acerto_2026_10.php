<?php

/**
 * ACERTO da DRE por filial, realizado jan–out/2026 (recarga de 25/11/2026,
 * pedido do cliente: "o realizado traga conforme estiver no Qlik").
 *
 * Depois da carga das filiais (18/11), o Qlik reprocessou outubro e passou
 * alguns lançamentos de um negócio para outro DENTRO da mesma filial. A carga
 * dos negócios foi refeita (`dre_2026_fechamento_10_r2`); aqui vão as mesmas
 * trocas nas filiais, para a soma delas continuar sendo o negócio.
 *
 * Origem: Qlik, app `DRE - Gerencial Nova` (`3aa6bf76-3186-4f9d-96b9-46891cf41d04`),
 * extraído em 25/11/2026: o acumulado jan–out por negócio × filial nas linhas
 * 4.1, 14.1 e 14.3 dos negócios que mudaram (1, 4, 6, 8, 9), comparado com o
 * banco filial a filial. Diferenças de até R$ 5 são arredondamento e ficaram.
 *
 * Cada acerto é [negócio, filial, conta folha, diferença]; a carga leva os
 * subtotais junto (`CargaDre::ACERTO_PROPAGA`) e recusa tudo se a soma das
 * filiais não bater com o negócio nas contas tocadas.
 *
 *  - Filial 106 (Fábrica de Rações Joaçaba): o custo de R$ 5.918 sai das
 *    Agropecuárias e vai para Rações. Os R$ 87 de rateio que vinham com ele
 *    estão na 16.1 das filiais (a carga do nível 3 dividiu o rateio da filial
 *    assim) e vão juntos. A filial 106 das Agropecuárias fica zerada.
 *  - Rateio Matriz/Central (14.1): R$ 400 da filial 123 e R$ 3 da 132 vão das
 *    Agropecuárias para Cereais; R$ 22 da filial 1, R$ 71 da 50 e R$ 4 da 133
 *    trocam entre Agropecuárias e Supermercados.
 */
return [
    'chave' => 'dre_filiais_acerto_2026_10',
    'ano' => 2026,
    'corte' => 10,
    'extraido_em' => '2026-11-25',
    'fonte' => 'Qlik DRE - Gerencial Nova 3aa6bf76-3186-4f9d-96b9-46891cf41d04',

    // [cód. negócio, cód. filial, conta, diferença (Qlik − carga de 18/11)]
    'acertos' => [
        ['6', '106', '4.1', -5918],
        ['8', '106', '4.1', 5918],
        ['6', '106', '16.1', -87],
        ['8', '106', '16.1', 87],
        ['1', '123', '14.1', 400],
        ['8', '123', '14.1', -400],
        ['1', '132', '14.1', 3],
        ['8', '132', '14.1', -3],
        ['8', '1', '14.1', 22],
        ['9', '1', '14.1', -22],
        ['8', '50', '14.1', 71],
        ['9', '50', '14.1', -71],
        ['8', '133', '14.1', 4],
        ['9', '133', '14.1', -4],
    ],
];

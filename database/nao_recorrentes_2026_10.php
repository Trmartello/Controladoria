<?php
/**
 * Itens NÃO RECORRENTES de 2026 por negócio — provisões, perdas, indenizações,
 * multas e receitas pontuais —, para evidenciar as OPORTUNIDADES (e os riscos)
 * de 2027 (pedido do cliente, 2026-11-18: "os provisionamentos que houveram, que
 * podem não acontecer igual para o ano que vem").
 *
 * Origem: Qlik Cloud, app `DRE - Gerencial` do espaço **Filiais**
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026, `Valor
 * Lançamento` por `Cód. Plano Contas`, jan–out/2026, com o bloco de seleção da
 * controladoria. Sinal do Qlik: POSITIVO é despesa (débito), NEGATIVO é
 * receita ou reversão (crédito). O que cada conta é, e o grupo em que entra,
 * mora em `Oportunidades::CONTAS`.
 *
 * As contas foram escolhidas pelo nome (PROVIS, PERDA, BAIXA, ALIENA, INDENIZ,
 * MULTA) entre as 3.* com movimento no ano; ficam de fora a provisão do PPR (a
 * diretriz de 2027 já a refaz na despesa de pessoal), a provisão de IRPJ/CSLL
 * sobre depreciação societária (tributo, não operação) e as provisões que
 * somam zero no ano. Valores arredondados ao real.
 */
return [
    'chave' => 'nao_recorrentes_2026_10_18',
    'ano' => 2026,
    'mes_ate' => 10,
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba, Valor Lançamento por conta contábil',

    // 'cód. negócio' => ['conta contábil' => valor jan–out (+ despesa, − receita/reversão)]
    'valores' => [
        '1' => ['3.05.03.01' => -892666, '3.05.03.02' => 881048, '3.07.01.01.14' => 4500, '3.07.01.01.19' => 141253,
            '3.07.01.21.01.14' => 42650, '3.07.01.21.01.23' => 1336, '3.07.03.01.09' => 675, '3.07.03.05.05' => 1579,
            '3.11.01.01.11' => -318677, '3.11.01.01.14' => -155013, '3.11.01.01.15' => -1200, '3.11.03.01.01' => 137869,
            '3.11.03.03.02' => 15930, '3.11.21.11.01' => 15053],
        '2' => ['3.05.03.01' => -8499355, '3.05.03.02' => -30562, '3.07.01.11.11' => 11127515, '3.07.03.03.07' => 77346,
            '3.11.03.01.01' => -213252, '3.11.21.11.01' => 1406],
        '4' => ['3.05.03.01' => 136686, '3.07.01.01.19' => 12000, '3.11.01.01.14' => -43256, '3.11.03.01.01' => 7182466,
            '3.11.21.11.01' => 3272],
        '6' => ['3.07.01.01.14' => 102518, '3.07.01.21.01.14' => 228350],
        '7' => ['3.07.01.21.01.14' => 31147, '3.11.03.01.01' => -1034, '3.11.21.11.01' => -31911],
        '8' => ['3.07.01.01.14' => 104778, '3.07.01.01.19' => 205838, '3.07.01.11.11' => -45576, '3.07.01.21.01.14' => 171828,
            '3.07.01.21.01.23' => 8787, '3.07.03.03.07' => 30057, '3.11.01.01.03' => -87558, '3.11.01.01.11' => -323377,
            '3.11.01.01.14' => -13000, '3.11.01.01.15' => -205597, '3.11.03.01.01' => 7336588, '3.11.03.03.02' => 414309,
            '3.11.21.11.01' => 23725],
        '9' => ['3.07.01.01.14' => 4000, '3.07.03.03.07' => 80418, '3.11.01.01.11' => -4050, '3.11.01.01.15' => -99723,
            '3.11.03.01.01' => 51626, '3.11.03.03.02' => 121276, '3.11.21.11.01' => 45563],
        '11' => ['3.11.01.01.03' => -14469, '3.11.01.01.15' => -9777, '3.11.03.01.01' => -12405, '3.11.03.03.02' => 24931,
            '3.11.21.11.01' => 2508],
        '12' => ['3.07.01.01.19' => 30228, '3.11.21.11.01' => 1053],
        '13' => ['3.07.01.01.19' => 24025, '3.07.03.01.09' => 8044, '3.07.03.03.07' => 61236, '3.11.03.01.01' => 100,
            '3.11.21.11.01' => 839],
    ],
];

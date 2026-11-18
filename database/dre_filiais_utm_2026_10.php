<?php

/**
 * DRE de 2026 por FILIAL do negócio UTM (7) — corte 10.
 *
 * Origem: Qlik, app `DRE - Gerencial` do espaço Filiais
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026, com
 * `[Cód. Filial]` somado ao bloco de seleção da controladoria
 * (`%ParticipacaoSocietaria={'Sem Part. Societária'}, FlagLancamentoParaIgnorar={0}`).
 * Realizado acumulado jan–out/2026 e orçado nov–dez/2026, arredondados ao real,
 * nas 50 contas de nível 1 e 2 da DRE gerencial (`Cód Indicador`).
 *
 * As 2 filiais com movimento em 2026: Indústria de Madeira, que concentra o
 * negócio, e Cultivo Erva Mate, só com despesas (R$ 21.173 no realizado, sem
 * receita nem orçado). A Matriz aparece associada, com peso zero.
 * Atenção: a conta 4.1 (custo) da Indústria de Madeira é POSITIVA no Qlik
 * (R$ 304.116 realizado, R$ 113.219 orçado) — mantida como está na fonte.
 *
 * Prova do resto: o negócio inteiro SEM as filiais da lista dá zero em todas
 * as contas. Conferido: a soma das filiais bate com a DRE do negócio em todas
 * as contas não percentuais, realizado e orçado, com desvio máximo de
 * R$ 1 (conta 8.12, arredondamento ao real). Nenhuma filial zerada
 * foi deixada de fora.
 */
return [
    'chave' => 'dre_filiais_utm_2026_10',
    'ano' => 2026,
    'corte' => 10,
    'negocio' => '7',
    'extraido_em' => '2026-11-18',
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba',

    // código no ERP => nome
    'filiais' => [
        '64' => 'Industria de Madeira',
        '121' => 'Cultivo Erva Mate',
    ],

    // 'filial|conta' => 'realizado jan–out/orçado nov–dez' (ausente = zero)
    'valores' => [
        '64|1.1' => '1158793/142000',
        '64|2.1' => '-842/-2000',
        '64|2.2' => '-456/-100',
        '64|2.3' => '-272243/-34946',
        '64|4.1' => '304116/113219',
        '64|8.1' => '-13903/-2760',
        '64|8.3' => '-577/-180',
        '64|8.4' => '-108/0',
        '64|8.5' => '-113721/-71626',
        '64|8.7' => '-11891/-2400',
        '64|8.8' => '-9575/-1600',
        '121|8.8' => '-4/0',
        '64|8.9' => '-37675/-9716',
        '64|8.10' => '-8584/-872',
        '64|8.11' => '-15764/-800',
        '121|8.11' => '-249/0',
        '64|8.12' => '-13937/-1064',
        '121|8.12' => '-16772/0',
        '64|8.13' => '-110/-30',
        '64|8.14' => '-2322/0',
        '64|8.15' => '-18868/-3200',
        '121|8.15' => '-4148/0',
        '64|8.16' => '89126/0',
        '64|9.1' => '0/374',
        '64|9.2' => '1034/0',
        '64|9.4' => '31911/0',
        '64|11.1' => '1861/0',
        '64|11.2' => '-2283/0',
        '64|12.1' => '-13144/-382',
        '64|14.1' => '-1/0',
        '64|14.2' => '-100523/-20113',
        '64|14.3' => '-111956/-30980',
        '64|1' => '1158793/142000',
        '64|2' => '-273542/-37046',
        '64|3' => '885251/104954',
        '64|4' => '304116/113219',
        '64|5' => '1189367/218173',
        '64|6' => '0/416',
        '64|7' => '-157908/-94248',
        '121|7' => '-21173/0',
        '64|8' => '0/-165',
        '64|9' => '32945/374',
        '64|10' => '1064405/124299',
        '121|10' => '-21173/0',
        '64|11' => '-422/0',
        '64|12' => '1063982/124299',
        '121|12' => '-21173/0',
        '64|13' => '1050838/123917',
        '121|13' => '-21173/0',
        '64|14' => '-212479/-51093',
        '64|15' => '-351009/-145348',
        '121|15' => '-21173/0',
        '64|16' => '838358/72825',
        '121|16' => '-21173/0',
        '64|17' => '0/139',
    ],
];

<?php

/**
 * DRE de 2026 por FILIAL do negócio 13 — UBS — acumulada no corte do fechamento
 * (realizado jan–out/2026, orçado nov–dez/2026).
 *
 * Origem: Qlik, app `DRE - Gerencial` do espaço Filiais
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026: uma coluna por
 * filial, `Nível<=2`, sem participação societária e sem lançamento a ignorar.
 *
 * UMA filial com movimento em 2026: a 53, Capinzal UBS — o negócio inteiro.
 * As outras 25 filiais que o Qlik associa ao negócio têm peso zero.
 *
 * Prova do resto: o negócio sem a 53 dá zero em todas as contas.
 * Conferido: a filial bate com a DRE do negócio nas 47 contas não percentuais,
 * realizado e orçado, com desvio máximo de R$ 0. Nenhuma filial zerada
 * entre as candidatas.
 */
return [
    'chave' => 'dre_filiais_ubs_2026_10',
    'ano' => 2026,
    'corte' => 10,
    'negocio' => '13',
    'extraido_em' => '2026-11-18',
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba',

    // código no ERP => nome (do maior peso para o menor)
    'filiais' => [
        '53' => 'Capinzal UBS',
    ],

    // 'filial|conta' => 'realizado jan–out/orçado nov–dez' (ausente = zero)
    'valores' => [
        '53|1.1' => '13321075/1940000',
        '53|2.1' => '-131961/-141977',
        '53|2.2' => '-49879/-9600',
        '53|2.3' => '-181459/-6850',
        '53|4.1' => '-8153301/-1250000',
        '53|8.1' => '-212657/-326000',
        '53|8.2' => '-11965/-1100',
        '53|8.3' => '-5312/0',
        '53|8.4' => '-3018/0',
        '53|8.5' => '-1269090/-272605',
        '53|8.7' => '-27624/-4700',
        '53|8.8' => '-37462/-10675',
        '53|8.9' => '-98297/-20805',
        '53|8.10' => '-47200/-8015',
        '53|8.11' => '-17192/-11316',
        '53|8.12' => '-33404/-6775',
        '53|8.13' => '-10557/-2000',
        '53|8.14' => '-11847/-4100',
        '53|8.15' => '-122689/-24200',
        '53|8.16' => '17724/2950',
        '53|9.1' => '42359/0',
        '53|9.2' => '-100/0',
        '53|9.4' => '-839/0',
        '53|11.1' => '64888/450',
        '53|11.2' => '-127568/-26100',
        '53|12.1' => '-51467/0',
        '53|14.1' => '121/0',
        '53|14.2' => '-1618727/-181668',
        '53|14.3' => '-181693/-91532',
        '53|1' => '13321075/1940000',
        '53|2' => '-363299/-158427',
        '53|3' => '12957776/1781573',
        '53|4' => '-8153301/-1250000',
        '53|5' => '4804474/531573',
        '53|6' => '0/64',
        '53|7' => '-1890591/-689341',
        '53|8' => '0/-75',
        '53|9' => '41420/0',
        '53|10' => '2955304/-157768',
        '53|11' => '-62680/-25650',
        '53|12' => '2892623/-183418',
        '53|13' => '2841157/-183418',
        '53|14' => '-1800299/-273200',
        '53|15' => '-3763617/-988191',
        '53|16' => '1040858/-456618',
        '53|17' => '0/-46',
    ],
];

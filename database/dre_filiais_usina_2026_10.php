<?php

/**
 * DRE de 2026 por FILIAL do negócio Usina Fotovoltaica (17) — corte 10.
 *
 * Origem: Qlik, app `DRE - Gerencial` do espaço Filiais
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026, com
 * `[Cód. Filial]` somado ao bloco de seleção da controladoria
 * (`%ParticipacaoSocietaria={'Sem Part. Societária'}, FlagLancamentoParaIgnorar={0}`).
 * Realizado acumulado jan–out/2026 e orçado nov–dez/2026, arredondados ao real,
 * nas 50 contas de nível 1 e 2 da DRE gerencial (`Cód Indicador`).
 *
 * As 3 filiais com movimento em 2026: as duas usinas e a Matriz, que entra
 * só com R$ 156 de despesa na conta 8.8 do realizado. O negócio não tem
 * receita (conta 1.1 zerada): o resultado vem da conta 8.8, POSITIVA nas
 * usinas (R$ 882.333 realizado, R$ 237.000 orçado no negócio) — mantida como
 * está na fonte.
 *
 * Prova do resto: o negócio inteiro SEM as filiais da lista dá zero em todas
 * as contas. Conferido: a soma das filiais bate com a DRE do negócio em todas
 * as contas não percentuais, realizado e orçado, com desvio máximo de
 * R$ 1 (conta 8.15, arredondamento ao real). Nenhuma filial zerada
 * foi deixada de fora.
 */
return [
    'chave' => 'dre_filiais_usina_2026_10',
    'ano' => 2026,
    'corte' => 10,
    'negocio' => '17',
    'extraido_em' => '2026-11-18',
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba',

    // código no ERP => nome
    'filiais' => [
        '119' => 'Usina Fotovoltaica 01',
        '120' => 'Usina Fotovoltaica 02',
        '1' => 'Matriz',
    ],

    // 'filial|conta' => 'realizado jan–out/orçado nov–dez' (ausente = zero)
    'valores' => [
        '119|8.7' => '-21850/0',
        '119|8.8' => '554514/127000',
        '120|8.8' => '327975/110000',
        '1|8.8' => '-156/0',
        '119|8.9' => '-26018/-6000',
        '120|8.9' => '-25770/-6000',
        '119|8.10' => '-222646/-44530',
        '120|8.10' => '-191885/-38377',
        '119|8.11' => '-23640/-6500',
        '120|8.11' => '-11024/-6500',
        '119|8.12' => '-17025/0',
        '120|8.12' => '-17025/0',
        '119|8.15' => '-2238/0',
        '120|8.15' => '-2168/0',
        '119|11.1' => '1812/0',
        '119|12.1' => '-8496/0',
        '120|12.1' => '-8651/0',
        '119|7' => '241097/69970',
        '120|7' => '80102/59123',
        '1|7' => '-156/0',
        '119|10' => '241097/69970',
        '120|10' => '80102/59123',
        '1|10' => '-156/0',
        '119|11' => '1812/0',
        '119|12' => '242908/69970',
        '120|12' => '80102/59123',
        '1|12' => '-156/0',
        '119|13' => '234412/69970',
        '120|13' => '71451/59123',
        '1|13' => '-156/0',
        '119|15' => '234412/69970',
        '120|15' => '71451/59123',
        '1|15' => '-156/0',
        '119|16' => '234412/69970',
        '120|16' => '71451/59123',
        '1|16' => '-156/0',
    ],
];

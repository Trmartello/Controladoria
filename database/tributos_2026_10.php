<?php
/**
 * Vendas e TRIBUTOS SOBRE VENDAS de 2026 por negócio, para fazer a conta da
 * reforma tributária de forma macro no painel do desafio (pedido do cliente,
 * 2026-11-18: "trazer o PIS e COFINS destacado nas colunas desse painel").
 *
 * Origem: Qlik Cloud, app `DRE - Gerencial` do espaço **Filiais**
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026, `Valor
 * Lançamento` por `Cód. Plano Contas`, jan–out/2026 (outubro é o último mês
 * fechado), com `%ParticipacaoSocietaria='Sem Part. Societária'`,
 * `FlagLancamentoParaIgnorar=0`, `[Cód. Negócio]` 1–17:
 *
 *  - vendas a SÓCIOS (3.01.01.01*, ato cooperativo) e a NÃO SÓCIOS (3.01.01.02*);
 *    as duas somam a receita bruta do negócio (conferido: Cereais R$ 472,96 mi);
 *  - PIS (3.03.01.0x.03.04), COFINS (3.03.01.0x.03.03) e ICMS (3.03.01.0x.03.02)
 *    sobre vendas. Somados, fecham com a linha 2.3 (Imposto s/ Vendas) da DRE —
 *    ao real na Pecuária e em Supermercados, R$ 13 mil de diferença na
 *    Agropecuária e R$ 22 mil em Cereais (outros impostos sobre venda).
 *
 * PIS e COFINS só incidem sobre a venda a NÃO sócio: a conta "SÓCIO" é zero em
 * todos os negócios (o ato cooperativo não é tributado). As contas 6.03.0x
 * espelham as 3.03 e não entram. Valores positivos, arredondados ao real.
 */
return [
    'chave' => 'tributos_2026_10_18',
    'ano' => 2026,
    'mes_ate' => 10,
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba, Valor Lançamento por conta contábil',

    // 'cód. negócio' => [venda_socio, venda_nao_socio, pis, cofins, icms]
    'valores' => [
        '1' => [396075923, 76882301, 577702, 2660607, 934036],
        '2' => [662722719, 13288583, 69163, 318568, 6541326],
        '4' => [269925759, 11563, 6, 26, 556842],
        '6' => [275938308, 607080, 8150, 37538, 5768],
        '7' => [243337, 915456, 13716, 63179, 195348],
        '8' => [793626408, 207740079, 918693, 4234328, 9469572],
        '9' => [38612334, 177450404, 1129573, 5207545, 25532008],
        '11' => [9344210, 50345484, 47714, 219790, 593241],
        '12' => [5578505, 1630, 21, 96, 19484],
        '13' => [12074090, 1246984, 9050, 41685, 130725],
    ],
];

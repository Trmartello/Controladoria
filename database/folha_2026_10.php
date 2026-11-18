<?php
/**
 * A FOLHA de 2026 por negócio, para a despesa de pessoal de 2027 pela diretriz
 * (pedido do cliente, 2026-11-18). Ver `Projecao::pessoalDaDiretriz`.
 *
 * Origem: Qlik Cloud, app `DRE - Gerencial` do espaço **Filiais**
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraído em 18/11/2026, `Valor
 * Lançamento` por `Cód. Plano Contas`, com `%ParticipacaoSocietaria='Sem Part.
 * Societária'`, `FlagLancamentoParaIgnorar=0`, `[Cód. Negócio]` 1–17, Ano 2026:
 *
 *  - `pessoal_ate`: as 3.07.01.21* de jan a out — a linha 8.5 (Despesas com
 *    Pessoal). Bate com a linha 8.5 do banco, ao real, em Pecuária, Rações, UTM,
 *    Supermercados, Postos, Resfriamento e UBS; Cereais (R$ 8,5 mil) e Leite
 *    (R$ 47 mil) ficam perto, e a Agropecuária tem R$ 4,6 mi a mais nas contas
 *    do que na linha — por isso as peças de 2026 entram como PARTE, não em reais.
 *  - `vale_ate` (3.07.01.21.07.17, vale alimentação PAT), `premio_ate`
 *    (3.07.01.21.07.19, prêmio por desempenho) e `ppr_ate` (3.07.01.21.01.89,
 *    provisão da participação nos resultados, + 3.07.01.21.01.12, o acerto dela),
 *    de jan a out.
 *  - `salario_despesa` (3.07.01.21.01.01) e `salario_custo` (4.03.01.01.01):
 *    SALÁRIOS E REMUNERAÇÕES de OUTUBRO — "a folha do mês atual": novembro está
 *    aberto (sem rateio), e outubro é o último mês fechado. A conta inclui o que
 *    a folha paga além do salário contratual; é a referência que o plano de
 *    contas tem do salário base.
 *
 * As contas 6.03.0x.07.01.21* (dispêndios/despesas do ato cooperativo) espelham
 * as 3.07.01.21* e NÃO entram: somá-las contaria a folha duas vezes.
 * A Usina Fotovoltaica (17) não tem folha. Valores arredondados ao real.
 */
return [
    'chave' => 'folha_2026_10_18',
    'ano' => 2026,
    'mes_ate' => 10,
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba, Valor Lançamento por conta contábil',

    // 'cód. negócio' => [pessoal_ate, vale_ate, premio_ate, ppr_ate, salario_despesa, salario_custo]
    'valores' => [
        '1' => [3058285, 130220, 69449, 492205, 140457, 285187],
        '2' => [8328796, 323839, 148600, 701329, 375803, 0],
        '4' => [3065057, 117677, 84400, 242880, 151698, 0],
        '6' => [2373054, 97961, 64300, 461125, 113136, 200172],
        '7' => [113721, 16450, 5700, 64306, 15035, 21167],
        '8' => [40134334, 2391217, 1133297, 1661178, 1765828, 0],
        '9' => [19465869, 1746182, 709514, 1191713, 978185, 0],
        '11' => [3872942, 280575, 181300, 240752, 189339, 0],
        '12' => [1950541, 116181, 56400, 99590, 96330, 0],
        '13' => [1269090, 54967, 41800, 119450, 69185, 30706],
    ],
];

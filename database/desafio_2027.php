<?php
/**
 * O DESAFIO de 2027 por negócio, proposto pela Controladoria e carregado na
 * Projeção de Resultados (pedido do cliente, 2026-11-18: "os 2% são margem
 * líquida, carregue o desafio na projeção").
 *
 * Diretriz do Horizonte 1: **preservar margem** — pode crescer, mas sem perder
 * margem. A direção da Copérdia pretendia 2% de margem líquida para a
 * cooperativa; com a Pecuária começando 2027 perto do ritmo dos últimos dois
 * meses, o cliente já antecipou que os 2% provavelmente não fecham. O desafio é
 * o máximo que cada negócio consegue sustentar, e a meta dos 2% fica ao lado,
 * como referência, com a distância até ela.
 *
 * Método (publicado para aprovação em https://claude.ai/artifact/KPmsxFFV9Au84nexBjvtAD):
 *  - Receita bruta 2027 = 2026 na VISÃO REALISTA (jan–out realizado + nov–dez
 *    realista, fechamento de outubro) × 1,06 — IPCA 4,5% + PIB agropecuário 1,5%,
 *    os das "Diretrizes para o Planejamento 2027" (16/11/2026). A página aprovada
 *    usava IPCA 4% + PIB 2%: a soma é a mesma, e só a Usina mudou.
 *  - Receita líquida = a receita bruta × a relação ROL/ROB de 2026 do negócio.
 *  - Margem líquida = a MAIOR entre a de 2025 e a de 2026 realista, sobre a ROL —
 *    "preservar margem" lido como não aceitar a pior das duas.
 *  - Pecuária: receita bruta do cenário OTIMISTA da planilha do negócio
 *    ("PECUÁRIA VOLUMES e FATURAMENTO Planejamento 2027", R$ 774.720.003,52),
 *    com a margem de 2026 realista.
 *  - Usina Fotovoltaica: sem receita; resultado de 2026 realista + IPCA (4,5%).
 *
 * Total (soma das linhas abaixo): ROB R$ 3.694.590.776, ROL R$ 3.563.582.221,
 * resultado R$ 63.687.733 — margem líquida de 1,79%. Os 2% sobre essa ROL
 * (sobras mínimas da diretriz) seriam R$ 71,27 mi: faltam R$ 7,58 mi.
 *
 * O desafio parte da visão realista de 2026, que já traz a folha de 2026 (com a
 * provisão do PPR). Ele NÃO refaz a despesa de pessoal pela diretriz — isso é a
 * proposta de cada negócio, ao lado dele na tela.
 *
 * Três perguntas da página de aprovação ficaram sem resposta e valem como lá
 * (macro igual para todos; Cereais pela margem de 2025, 1,72%, e não pela do
 * plano de 2026, 0,82%; Pecuária com a margem de 2026 no otimista). Mudou uma
 * delas: refaz-se a conta, troca-se a `chave` e o deploy recarrega.
 *
 * Valores em R$, arredondados ao real. A margem não vem: é resultado ÷ ROL.
 */
return [
    'chave' => 'desafio_2027_2026_11_18_diretriz',
    'ano' => 2027,
    'meta_margem' => 2.0,
    'decidido_em' => '2026-11-18',
    'fonte' => 'Desafio 2027 da Controladoria (visão realista de 2026, fechamento de outubro)',
    'metodo' => 'Receita bruta = 2026 realista × 1,06 (IPCA 4,5% + PIB agropecuário 1,5%, Diretrizes 2027); '
        . 'receita líquida pela relação ROL/ROB de 2026; margem líquida = a maior entre 2025 e 2026 realista. '
        . 'Pecuária no cenário otimista do negócio; Usina sem receita, resultado de 2026 realista + IPCA (4,5%).',

    // 'cód. negócio' => desafio
    'valores' => [
        '8' => ['receita_bruta' => 1301048956, 'receita_liquida' => 1252168441, 'resultado' => 20931543, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '2' => ['receita_bruta' => 774720004, 'receita_liquida' => 763904008, 'resultado' => 11169868, 'nota' => 'Cenário otimista do negócio (R$ 774,7 mi de faturamento, planilha Planejamento 2027) com a margem de 2026 realista preservada.'],
        '1' => ['receita_bruta' => 547416689, 'receita_liquida' => 527974426, 'resultado' => 9101824, 'nota' => 'Volta à margem de 2025.'],
        '4' => ['receita_bruta' => 344918613, 'receita_liquida' => 337776567, 'resultado' => 1488042, 'nota' => 'Margem de 2025 (melhor que a de 2026 realista).'],
        '6' => ['receita_bruta' => 342098419, 'receita_liquida' => 341404817, 'resultado' => 4974067, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '9' => ['receita_bruta' => 282829944, 'receita_liquida' => 240882148, 'resultado' => 8260740, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '11' => ['receita_bruta' => 76844614, 'receita_liquida' => 75693351, 'resultado' => 2817521, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '13' => ['receita_bruta' => 16119133, 'receita_liquida' => 15568071, 'resultado' => 1818448, 'nota' => 'Margem de 2025 (melhor que a de 2026 realista).'],
        '12' => ['receita_bruta' => 7139678, 'receita_liquida' => 7103011, 'resultado' => 1690782, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '7' => ['receita_bruta' => 1454726, 'receita_liquida' => 1107381, 'resultado' => 1013714, 'nota' => 'Margem de 2026 realista preservada (melhor que a de 2025).'],
        '17' => ['receita_bruta' => 0, 'receita_liquida' => 0, 'resultado' => 421184, 'nota' => 'Sem receita: resultado de 2026 realista corrigido pelo IPCA.'],
    ],
];

<?php
/**
 * Quadro de colaboradores por NEGÓCIO e por mês, de 2026 — a base do
 * planejamento de pessoas e benefícios de 2027 (pedido do cliente, 2026-11-18).
 *
 * Origem: Qlik Cloud, app `DRE - Gerencial` do espaço **Filiais**
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), campo `Número Funcionarios` da
 * tabela de fatos, extraído em 18/11/2026 com o bloco de seleção da
 * controladoria (`Ano=2026`, `%ParticipacaoSocietaria='Sem Part. Societária'`,
 * `FlagLancamentoParaIgnorar=0`, `[Cód. Negócio]` ≤ 17), SOMADO por negócio e
 * mês. É uma contagem por filial e centro de custo, e a soma de novembro
 * (1.564 na cooperativa) bate com "mais de 1.500 colaboradores".
 *
 * **A divisão entre negócios já está no número** (confirmado pelo cliente em
 * 2026-11-18): quem trabalha 30% num negócio e 70% noutro entra, em cada um,
 * pela sua parte, arredondada — então o valor daqui já é o de colaboradores
 * EQUIVALENTES, e o custo de cada negócio sai dele direto, sem rateio por cima.
 *
 * **Dezembro ainda não existe** (o mês não aconteceu): a base do planejamento é
 * "o último mês do ano atual", e enquanto dezembro não vier ela é novembro. Quando
 * vier, acrescenta-se o 12 à série e a base anda sozinha — o planejamento usa
 * sempre o último mês carregado do ano-base.
 *
 * Ficam de fora: os negócios sem quadro (Fruticultura, Juros s/ cota capital e
 * Usina Fotovoltaica) e as áreas de apoio (18 Apoio Operacional, 87 pessoas, e
 * 20 Administração Matriz, 127), que não são negócios — fora do escopo por ora,
 * por decisão do cliente.
 *
 * Os valores vêm como lista de meses (jan…nov) por código de negócio.
 */
return [
    'chave' => 'pessoas_2026_11_18',
    'ano' => 2026,
    'extraido_em' => '2026-11-18',
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba, campo Número Funcionarios',
    'meses' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],

    // 'cód. negócio' => colaboradores de cada mês de `meses`
    'valores' => [
        '1' => [109, 107, 106, 107, 105, 102, 107, 106, 112, 113, 113],
        '2' => [66, 66, 66, 66, 67, 67, 68, 68, 68, 68, 67],
        '4' => [22, 23, 23, 24, 24, 24, 24, 24, 24, 24, 24],
        '6' => [72, 72, 72, 73, 72, 74, 74, 73, 73, 73, 73],
        '7' => [14, 14, 15, 15, 15, 11, 11, 11, 11, 11, 11],
        '8' => [546, 542, 543, 545, 540, 546, 546, 547, 543, 555, 548],
        '9' => [388, 389, 391, 390, 397, 403, 405, 403, 405, 415, 406],
        '11' => [61, 61, 60, 59, 58, 60, 60, 60, 60, 60, 60],
        '12' => [22, 23, 23, 24, 25, 25, 26, 26, 26, 27, 27],
        '13' => [23, 23, 20, 19, 20, 20, 20, 20, 20, 21, 21],
    ],
];

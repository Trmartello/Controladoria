<?php
/**
 * Quadro de colaboradores de 2026 por NEGÓCIO (série mensal) e por FILIAL (o
 * último mês), atualizado em 23/11/2026 — a base do mínimo das Despesas com
 * Pessoal de 2027 (pedido do cliente: "traga os dados mais recentes de cada
 * negócio e filial").
 *
 * Origem: Qlik Cloud, app `DRE - Gerencial` do espaço **Filiais**
 * (`0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), campo `Número Funcionarios`, com o
 * bloco da controladoria (`Ano=2026`, `%ParticipacaoSocietaria='Sem Part.
 * Societária'`, `FlagLancamentoParaIgnorar=0`, `[Cód. Negócio]` 1–17). É a
 * mesma origem da carga de 18/11 (`pessoas_2026_11.php`); o Qlik foi recarregado
 * desde então e novembro mudou em sete negócios (ex.: Agropecuárias 548 → 542).
 *
 * Conferido na geração (script, sem redigitação): a série de cada negócio soma
 * o total do ano que o Qlik calculou; as filiais de novembro somam o novembro do
 * negócio e são tantas quantas o Qlik contou (136 filiais com quadro).
 *
 * O quadro da filial é o de NOVEMBRO, o último mês do ano-base — o mesmo que o
 * negócio usa. É ele que diz quantas pessoas a filial tem para o mínimo da 8.5.
 */
return [
    'chave' => 'pessoas_2026_11_23',
    'ano' => 2026,
    'extraido_em' => '2026-11-23',
    'fonte' => 'Qlik DRE - Gerencial (Filiais) 0a23b6b3-59c8-4b8e-aa48-10b24410bcba, campo Número Funcionarios',
    'meses' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],

    // 'cód. negócio' => colaboradores de cada mês de `meses`
    'valores' => [
        '1' => [109, 107, 106, 107, 105, 102, 107, 106, 112, 113, 111],
        '2' => [66, 66, 66, 66, 67, 67, 68, 68, 68, 68, 67],
        '4' => [22, 23, 23, 24, 24, 24, 24, 24, 24, 24, 23],
        '6' => [72, 72, 72, 73, 72, 74, 74, 73, 73, 73, 73],
        '7' => [14, 14, 15, 15, 15, 11, 11, 11, 11, 11, 10],
        '8' => [546, 542, 543, 545, 540, 546, 546, 547, 543, 555, 542],
        '9' => [388, 389, 391, 390, 397, 403, 405, 403, 405, 415, 402],
        '11' => [61, 61, 60, 59, 58, 60, 60, 60, 60, 60, 59],
        '12' => [22, 23, 23, 24, 25, 25, 26, 26, 26, 27, 27],
        '13' => [23, 23, 20, 19, 20, 20, 20, 20, 20, 21, 20],
    ],

    // O quadro da filial no último mês de `meses`: 'cód. negócio' => ['cód. filial' => colaboradores]
    'filiais_mes' => 11,
    'filiais' => [
        '1' => ['1' => 4, '10' => 7, '13' => 4, '43' => 1, '54' => 7, '68' => 1, '69' => 4, '80' => 6, '86' => 1, '92' => 3, '94' => 3, '98' => 3, '99' => 3, '103' => 2, '104' => 1, '105' => 17, '108' => 2, '113' => 6, '118' => 5, '122' => 14, '123' => 4, '126' => 2, '128' => 2, '132' => 4, '134' => 4, '135' => 1],
        '2' => ['1' => 62, '46' => 5],
        '4' => ['16' => 22, '97' => 1],
        '6' => ['47' => 33, '96' => 9, '106' => 30, '131' => 1],
        '7' => ['64' => 10],
        '8' => ['1' => 10, '2' => 8, '3' => 7, '4' => 13, '5' => 8, '6' => 6, '7' => 5, '9' => 6, '11' => 23, '13' => 11, '14' => 4, '15' => 9, '19' => 6, '23' => 5, '31' => 5, '34' => 33, '43' => 1, '44' => 7, '49' => 18, '50' => 6, '51' => 7, '54' => 5, '59' => 6, '60' => 6, '61' => 5, '62' => 3, '68' => 6, '69' => 13, '70' => 12, '71' => 7, '72' => 9, '73' => 9, '74' => 5, '75' => 4, '77' => 6, '80' => 2, '81' => 6, '83' => 6, '84' => 6, '85' => 7, '86' => 10, '87' => 6, '88' => 4, '91' => 7, '92' => 11, '94' => 3, '95' => 6, '98' => 14, '99' => 1, '100' => 9, '101' => 2, '102' => 8, '103' => 6, '104' => 8, '105' => 11, '107' => 12, '108' => 7, '109' => 5, '110' => 2, '113' => 8, '115' => 7, '116' => 5, '117' => 9, '118' => 12, '122' => 1, '125' => 4, '126' => 10, '128' => 4, '129' => 5, '130' => 2, '133' => 10, '134' => 7, '136' => 1, '138' => 4],
        '9' => ['1' => 9, '2' => 26, '3' => 41, '4' => 58, '5' => 15, '6' => 14, '7' => 21, '9' => 43, '11' => 37, '14' => 9, '15' => 10, '19' => 7, '23' => 11, '31' => 3, '33' => 64, '44' => 4, '51' => 5, '88' => 25],
        '11' => ['1' => 1, '66' => 25, '111' => 11, '112' => 13, '114' => 9],
        '12' => ['16' => 13, '76' => 7, '97' => 7],
        '13' => ['53' => 20],
    ],
];

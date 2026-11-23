<?php
/**
 * O DESAFIO de 2027 — CALCULADO (pedido do cliente, 2026-11-23: "atualizar essa
 * tela de Desafio para os negócios… considerando tudo que conversamos… gere um
 * novo desafio. E a partir dali, ele continua fazendo a projeção").
 *
 * Esta carga grava só o cabeçalho (meta de margem, método, fonte); os números
 * saem da Projeção, negócio a negócio, porque o desafio é a PROPOSTA PADRÃO:
 *
 *  - **Base**: os 12 meses fechados mais recentes, nov/2025–out/2026 (decisão do
 *    cliente: "12 meses em tudo").
 *  - **Receita de Vendas**: base × (1 + IPCA + PIB + % crescimento), as premissas
 *    do painel do negócio (IPCA 4,5% e PIB agropecuário 1,5% sugeridos pela
 *    diretriz) — ou o DRIVER de volume nos negócios que derivam de outro
 *    (`App\Services\DesafioDrivers`): Pecuária no cenário realista da planilha
 *    (R$ 732.218.694), Leite pela cota Aurora × preço médio por litro dos 12
 *    meses, Postos de resfriamento pela cota × R$ 0,055/L, Fábrica de Rações com
 *    a ração de bovinos pela meta das Agropecuárias × R$ 100/t. UTM, Cereais e UBS
 *    vão por base × premissas, com a nota "driver a definir".
 *  - **Margem bruta preservada**: deduções e custo acompanham a receita (pedido
 *    explícito para Agropecuárias, Supermercados e Postos de Combustíveis; o motor
 *    faz o mesmo em todos).
 *  - **Pessoal pela diretriz**: reajuste de 5,5%, vale R$ 500, prêmios R$ 200 e
 *    R$ 500 e PPR de até 1,5 salário (`Projecao::pessoalDaDiretriz`).
 *  - Demais despesas e rateios na base, sem crescimento. Usina: só resultado.
 *
 * O desafio de 18/11 (2026 realista × 1,06, margem = a maior de 2025 e 2026,
 * Pecuária no otimista) fica no histórico do git; esta carga apaga os valores
 * dele de `negocio_desafio`.
 */
return [
    'chave' => 'desafio_2027_2026_11_23_calculado',
    'ano' => 2027,
    'meta_margem' => 2.0,
    'decidido_em' => '2026-11-23',
    'calculado' => true,
    'fonte' => 'Desafio 2027 da Controladoria — proposta padrão sobre a base de 12 meses (nov/2025–out/2026)',
    'metodo' => 'O desafio é a proposta padrão: base de 12 meses (nov/25–out/26) × premissas do painel (IPCA + PIB + '
        . 'crescimento), ou o driver de volume (Pecuária: realista da planilha; Leite: cota Aurora × preço médio/L; '
        . 'Postos de leite: cota × R$ 0,055/L; Rações: bovinos × R$ 100/t + o resto × premissas); custo e deduções '
        . 'acompanham a receita (margem bruta preservada); pessoal pela diretriz (5,5%, vale, prêmios e PPR); demais '
        . 'despesas e rateios na base.',
];

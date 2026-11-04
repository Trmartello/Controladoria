<?php

/**
 * Conteúdo da carga do Porter — 5 Forças do negócio PECUÁRIA, ano 2027.
 *
 * FONTE: a mesma do cenário da Pecuária — extração da DRE Gerencial do Qlik em
 * 04/11/2026 (meses fechados jan–set/2026, `Cód. Negócio = 2`, sem participação
 * societária) e imprensa setorial de ago–set/2026. Ver o cabeçalho de
 * `conteudo_cenario_pecuaria_2027.php`.
 *
 * NÃO é conteúdo de oficina: análise montada a pedido do cliente em
 * 2026-11-04, e entra como item editável.
 *
 * Porter é sobre a ESTRUTURA DO SETOR — quem captura a margem e por quê. Cada
 * força abaixo tenta responder isso com o número que a DRE dá, em vez de
 * descrever o setor em geral.
 *
 * Como toda carga, aplica uma vez por `chave`, marcada em `carga_conteudo`, e
 * só INSERE o que ainda não está na tela.
 */

return [
    'chave' => 'porter_pecuaria_2027_dre_2026_11_04',
    'destino' => 'FATOR',
    'etapa' => 'PORTER',
    'negocio' => '2',
    'ano' => 2027,

    'itens' => [
        'RIVALIDADE' => [
            'Commodity sem diferenciação: o suíno vivo é vendido por cotação de praça, '
                . 'e nenhuma vantagem da Copérdia no manejo se converte em preço melhor no '
                . 'momento da venda. A disputa se dá por custo por quilo produzido, não por '
                . 'produto — e quem tem o menor custo define o preço para todos.',

            'A concorrência é por INTEGRADO, não por cliente. Integradoras da região '
                . 'disputam o mesmo produtor com bônus, condição de financiamento de '
                . 'instalação e garantia de alojamento; perder um integrado é perder volume '
                . 'por anos, porque a instalação dele passa a ocupar a escala do concorrente.',

            'Rivalidade agravada pela superoferta de 2026: com abates recordes e preço '
                . 'no menor nível real da série do Cepea, o setor inteiro opera perto do '
                . 'custo, e a disputa migra para quem aguenta mais tempo com margem '
                . 'comprimida — o que favorece quem tem capital de giro barato.',
        ],

        'NOVOS_ENTRANTES' => [
            'Barreira de entrada ALTA na produção: instalação, licença ambiental, área '
                . 'para dejeto e status sanitário não se improvisam, e o ciclo biológico '
                . 'impede ganho rápido de escala. O risco de entrante não vem de fora do '
                . 'setor.',

            'O entrante relevante é a integradora vizinha que amplia alojamento na '
                . 'nossa região, não uma empresa nova. Ela entra sem custo de aprendizado e '
                . 'disputando exatamente o nosso integrado.',

            'Do nosso lado, a entrada em praça nova já aconteceu e ainda não se pagou: '
                . 'Enéas Marques (PR) representa 7,4% da receita da Pecuária em jan–set/2026 '
                . 'e −15,9% de margem líquida sobre a própria receita, enquanto São Gabriel '
                . 'do Oeste (MS) levou dois anos para virar positivo (de −R$ 2,63 mi em 2025 '
                . 'para +R$ 1,52 mi em 2026). Expansão geográfica neste negócio custa caro '
                . 'antes de render.',
        ],

        'SUBSTITUTOS' => [
            'Substituição no prato, não na granja: frango compete com suíno pelo mesmo '
                . 'real do consumidor, e a relação de preço entre as duas proteínas desloca '
                . 'demanda em semanas. Quando a carne bovina perde mercado externo — como na '
                . 'sobretaxa chinesa de 2026 — o excedente volta ao mercado interno e '
                . 'pressiona as outras duas.',

            'Proteína alternativa (vegetal, cultivada) ainda não é substituto de preço '
                . 'no Brasil e não explica nada do resultado de 2026. Registrar como '
                . 'vigilância de longo prazo, sem atribuir efeito presente.',
        ],

        'PODER_FORNECEDORES' => [
            'O fornecedor que define a margem é o do grão. Com a alimentação em cerca '
                . 'de 70% do desembolso operacional, milho e farelo de soja decidem o '
                . 'resultado: em jan–set/2026 o custo das vendas subiu 3,0% (R$ 16,9 mi) '
                . 'com a receita líquida parada em +0,5%, e foi isso que levou a margem '
                . 'bruta de 8,11% para 5,80%.',

            'A cooperativa tem uma defesa que a maioria dos concorrentes não tem: '
                . 'origina grão (negócio Cereais) e produz ração (Fábrica de Rações) dentro '
                . 'de casa. Em jan–set/2026 a Fábrica de Rações foi de −R$ 0,8 mi para '
                . '+R$ 3,0 mi enquanto a Pecuária perdia R$ 16,0 mi — o que levanta a '
                . 'pergunta de se o preço de transferência entre os dois está medindo '
                . 'eficiência ou apenas movendo resultado de um negócio para o outro.',

            'Genética e sanidade são fornecimento concentrado em poucas empresas '
                . 'globais, com preço em moeda forte e pouca margem de negociação para um '
                . 'comprador do nosso porte.',
        ],

        'PODER_CLIENTES' => [
            'Cliente concentrado e, ao mesmo tempo, sócio: a produção vai '
                . 'majoritariamente ao Sistema Aurora, no qual a Copérdia tem participação '
                . 'societária. Isso dá escoamento garantido — vantagem real num ano de '
                . 'superoferta — e tira da mesa a alternativa de preço, porque a cooperativa '
                . 'senta dos dois lados dela.',

            'O preço praticado nessa relação não é uma negociação comercial comum, e '
                . 'por isso precisa de política explícita no ciclo 2027–2035: enquanto ela '
                . 'não existir, não há como separar, no resultado da Pecuária, o que é '
                . 'desempenho do negócio e o que é decisão de repartição dentro do sistema.',

            'No mercado spot, o poder do comprador é total: preço de cotação pública, '
                . 'produto sem diferenciação e oferta abundante. A margem do negócio não se '
                . 'defende na venda — se defende no custo do quilo produzido e no capital '
                . 'empatado para produzi-lo.',
        ],
    ],
];

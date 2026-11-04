<?php

/**
 * Conteúdo da carga do PESTEL do negócio PECUÁRIA, ano 2027.
 *
 * FONTE: a mesma do cenário da Pecuária — extração da DRE Gerencial do Qlik em
 * 04/11/2026 (meses fechados jan–set/2026, `Cód. Negócio = 2`, sem participação
 * societária) e imprensa setorial de ago–set/2026 para preço e relação de
 * troca. Ver o cabeçalho de `conteudo_cenario_pecuaria_2027.php`, que registra
 * as âncoras de sanidade e o descarte dos meses abertos.
 *
 * NÃO é conteúdo de oficina: é análise montada a pedido do cliente em
 * 2026-11-04, e entra como item editável.
 *
 * PESTEL é o ambiente EXTERNO. Nada aqui é escolha da cooperativa — o que ela
 * pode fazer a respeito vira força, fraqueza ou ação, não fator PESTEL. Os
 * números da nossa DRE aparecem só quando dimensionam o efeito do fator sobre
 * o negócio; o fator em si é de fora.
 *
 * Como toda carga, aplica uma vez por `chave`, marcada em `carga_conteudo`, e
 * só INSERE o que ainda não está na tela.
 */

return [
    'chave' => 'pestel_pecuaria_2027_dre_2026_11_04',
    'destino' => 'FATOR',
    'etapa' => 'PESTEL',
    'negocio' => '2',
    'ano' => 2027,

    'itens' => [
        'POLITICO' => [
            'Acesso a mercado externo decidido por acordo entre governos, não por '
                . 'competitividade. A China manteve em 2026 a renegociação de cotas e '
                . 'sobretaxas sobre a carne brasileira, e a cota da carne bovina esgotou em '
                . '29/09/2026, passando a pagar tarifa de 67%. Mesmo quando a medida não '
                . 'atinge a proteína que produzimos, ela realoca oferta entre mercados e '
                . 'chega ao nosso preço pela porta de trás.',

            'Redução do custeio do Plano Safra empurra o integrado para o crédito de '
                . 'mercado, a juro de mercado. Produtor com custo financeiro mais alto '
                . 'negocia pior, atrasa investimento em instalação e aumenta o risco de '
                . 'saída da atividade — e a saída de integrado é perda de escala que não se '
                . 'repõe no mesmo ciclo.',
        ],

        'ECONOMICO' => [
            'Preço do suíno vivo no menor patamar real da série do Cepea (iniciada em '
                . '2002): R$ 4,97/kg na praça SP-5 em agosto de 2026, após oito quedas '
                . 'mensais seguidas, com recuo de cerca de 38% contra agosto de 2025 '
                . '(Safras & Mercado). É a causa externa direta da perda de R$ 16,0 mi de '
                . 'resultado da Pecuária em jan–set/2026.',

            'Relação de troca contra o produtor: em setembro de 2026 o preço do suíno '
                . 'subiu 1,9%, mas milho e farelo de soja subiram mais, e o poder de compra '
                . 'do suinocultor caiu pelo terceiro mês seguido. Com a alimentação '
                . 'respondendo por cerca de 70% do desembolso operacional, a margem do '
                . 'negócio é definida por essa razão, e não pelo preço de venda isolado.',

            'Superoferta estrutural com abates recordes no Brasil em 2026: o custo de '
                . 'produção baixo do início do ano estimulou alojamento, e o excedente '
                . 'interno pressiona o preço para baixo. Oferta responde devagar — o ciclo '
                . 'biológico do suíno impede ajuste rápido, e o excesso tende a atravessar '
                . 'mais de um exercício.',

            'Selic em patamar ainda restritivo (14% a.a. em agosto de 2026, com '
                . 'projeção de 12% para 2027 no Focus) encarece o capital de giro de um '
                . 'negócio que carrega animal, ração e prazo de recebimento. A 1,3% ao mês, '
                . 'a referência da controladoria, cada R$ 10 mi parados custam R$ 1,56 mi '
                . 'por ano — mais de 12% do resultado atual da Pecuária.',
        ],

        'SOCIAL' => [
            'Sucessão familiar no campo: o integrado que encerra a atividade raramente '
                . 'é substituído por outro na mesma propriedade. Dois anos seguidos de '
                . 'margem apertada aceleram a desistência, e o efeito sobre a escala da '
                . 'cooperativa aparece com atraso de um ciclo — quando já não há como '
                . 'reverter dentro do exercício.',

            'Mão de obra escassa e com baixa qualificação para granja tecnificada, num '
                . 'mercado em que a jornada e a atratividade do emprego rural competem com a '
                . 'indústria e o serviço urbano das mesmas cidades.',

            'Pressão do consumidor e do varejo por bem-estar animal e rastreabilidade: '
                . 'ainda não é exigência de preço no mercado interno, mas já é condição de '
                . 'acesso em parte do mercado externo, e o custo da adequação recai sobre '
                . 'quem produz.',
        ],

        'TECNOLOGICO' => [
            'Genética, nutrição de precisão e ambiência deslocaram a fronteira de '
                . 'conversão alimentar: quem não acompanha perde margem com o mesmo preço de '
                . 'venda. Numa estrutura em que a ração é ~70% do desembolso, cada ponto de '
                . 'conversão alimentar vale mais do que qualquer negociação comercial.',

            'Monitoramento e dado em tempo real na granja (sensor de ambiência, consumo '
                . 'e peso) permitem gerir por lote e por produtor, e não pela média do '
                . 'negócio — exatamente o corte que a nossa DRE ainda não dá, já que o '
                . 'resultado só se abre até filial.',
        ],

        'ECOLOGICO' => [
            'Licenciamento ambiental e manejo de dejetos são o limite físico da '
                . 'expansão em Santa Catarina: a bacia do Rio do Peixe e o Alto Uruguai já '
                . 'operam com alta densidade de suínos por hectare, e ampliar alojamento na '
                . 'região esbarra em área de disposição, não em capital.',

            'Risco sanitário concentrado: foco de doença de notificação obrigatória '
                . '(peste suína clássica, influenza aviária) fecha mercado inteiro de uma '
                . 'vez, independentemente do desempenho da cooperativa. Santa Catarina é '
                . 'zona livre e esse status é ativo comercial — perdê-lo é perder o prêmio '
                . 'de preço do estado.',

            'Clima altera o custo da ração antes de alterar qualquer outra coisa: a '
                . 'quebra de safra de milho no Sul transfere direto para o custo do '
                . 'alojamento, e a cooperativa compra grão num mercado em que também é '
                . 'originadora (negócio Cereais).',
        ],

        'LEGAL' => [
            'Exigência de rastreabilidade e certificação sanitária para exportação muda '
                . 'por mercado e por ano, e cada mudança vira investimento em processo na '
                . 'granja e na logística — custo que entra antes da receita correspondente.',

            'Responsabilidade ambiental solidária na cadeia: a cooperativa responde, '
                . 'perante o licenciador e perante o comprador, pelo manejo de dejetos de '
                . 'propriedades que não são dela. O controle é contratual e documental, e '
                . 'falha de um integrado é risco jurídico do conjunto.',
        ],
    ],
];

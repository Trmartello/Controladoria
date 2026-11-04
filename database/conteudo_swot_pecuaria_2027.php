<?php

/**
 * Conteúdo da carga da SWOT do negócio PECUÁRIA, ano 2027.
 *
 * FONTE: a mesma do cenário da Pecuária — extração da DRE Gerencial do Qlik em
 * 04/11/2026 (meses fechados jan–set/2026, `Cód. Negócio = 2`, sem participação
 * societária) e imprensa setorial de ago–set/2026. Ver o cabeçalho de
 * `conteudo_cenario_pecuaria_2027.php`.
 *
 * NÃO é conteúdo de oficina: análise montada a pedido do cliente em
 * 2026-11-04, e entra como item editável.
 *
 * A disciplina da SWOT, que esta carga respeita: FORÇA e FRAQUEZA são de
 * dentro — algo que a cooperativa faz ou deixa de fazer; OPORTUNIDADE e AMEAÇA
 * são de fora — acontecem com ela. Preço de mercado é ameaça, nunca fraqueza;
 * margem pior que a do vizinho no mesmo preço é fraqueza, nunca ameaça.
 *
 * Entram SOLTOS, sem `promovido_de_id`: promover um fator do PESTEL ou do
 * Porter para a SWOT é gesto de quem conduz a análise, e a carga não o toma.
 *
 * Como toda carga, aplica uma vez por `chave`, marcada em `carga_conteudo`, e
 * só INSERE o que ainda não está na tela.
 */

return [
    'chave' => 'swot_pecuaria_2027_dre_2026_11_04',
    'destino' => 'FATOR',
    'etapa' => 'SWOT',
    'negocio' => '2',
    'ano' => 2027,

    'itens' => [
        'FORCA' => [
            'É o negócio que mais sustenta o resultado da cooperativa: 57,9% do '
                . 'resultado líquido consolidado em jan–set/2026 (R$ 12,5 mi de R$ 21,6 mi), '
                . 'com 23,6% da receita. Mesmo no pior ano da série, gera mais resultado do '
                . 'que qualquer outro negócio.',

            'Escoamento garantido pela relação com o Sistema Aurora: num ano de '
                . 'superoferta e abates recordes, ter comprador certo para toda a produção é '
                . 'vantagem competitiva concreta — vários concorrentes passaram 2026 '
                . 'disputando lugar no abate.',

            'Cadeia integrada dentro de casa: origina grão (Cereais), produz ração '
                . '(Fábrica de Rações) e faz assistência técnica ao integrado. Numa '
                . 'atividade em que a alimentação é cerca de 70% do desembolso, controlar o '
                . 'elo do custo é a defesa estrutural que o concorrente independente não tem.',

            'Operação multiestadual já instalada e com caso de recuperação provado: '
                . 'quatro filiais em SC, RS, MS e PR, com São Gabriel do Oeste saindo de '
                . '−R$ 2,63 mi (jan–set/2025) para +R$ 1,52 mi (jan–set/2026). A cooperativa '
                . 'sabe virar uma unidade deficitária — já fez.',

            'Severiano de Almeida (RS) opera com a melhor margem do negócio: '
                . 'R$ 7,86 mi de resultado sobre R$ 133,0 mi de receita em jan–set/2026 '
                . '(5,9%), contra 3,0% da Matriz. Existe um padrão interno melhor que a '
                . 'média, e ele é da própria casa.',
        ],

        'FRAQUEZA' => [
            'Resultado concentrado numa estrutura que não acompanha a receita: as '
                . 'despesas operacionais subiram 19,8% em jan–set/2026 (R$ 13,2 mi → '
                . 'R$ 15,8 mi) com a receita líquida parada em +0,5%. A despesa passou de '
                . '2,18% para 2,60% da ROL — é a parte da queda que não tem a ver com o '
                . 'preço do suíno.',

            'Enéas Marques (PR) virou de +R$ 4,39 mi para −R$ 7,27 mi em doze meses, '
                . '73% de toda a queda do negócio, com margem de −15,9% sobre a própria '
                . 'receita. O preço de mercado atinge as quatro filiais; só esta desabou — '
                . 'e a causa ainda não está diagnosticada.',

            'Não sabemos o resultado ECONÔMICO do negócio. A DRE Gerencial não aloca '
                . 'saldo patrimonial por negócio, então a NCG da Pecuária — animal, ração, '
                . 'adiantamento a integrado, prazo de recebimento — nunca foi apurada. A '
                . '1,3% ao mês, cada R$ 10 mi empatados custam R$ 1,56 mi por ano, mais de '
                . '12% do resultado atual. Decidimos sobre o maior negócio da casa com '
                . 'metade da informação.',

            'A gestão enxerga até filial, e o problema mora abaixo dela. Não há corte '
                . 'de resultado por lote, por linha (suíno/ave) nem por integrado, e sem '
                . 'esse corte a única resposta possível a uma margem que cai é cortar '
                . 'despesa geral.',

            'O rateio da estrutura central consome 46% do resultado antes do rateio em '
                . 'jan–set/2026 (R$ 10,7 mi de R$ 23,2 mi), contra 28% em 2025. O valor '
                . 'quase não mudou; o que mudou foi a capacidade do negócio de absorvê-lo — '
                . 'e o critério do rateio não é discutido na mesma mesa em que se cobra o '
                . 'resultado da unidade.',
        ],

        'OPORTUNIDADE' => [
            'O ciclo de preço do suíno é cíclico, não terminal: setembro de 2026 '
                . 'registrou a primeira alta do ano (+1,9%, R$ 5,06/kg) depois de oito '
                . 'quedas seguidas. Quem atravessar a baixa com estrutura de custo ajustada '
                . 'captura a recuperação inteira — e parte dos concorrentes está encerrando '
                . 'atividade agora.',

            'Saída de produtores independentes da atividade abre espaço para ganhar '
                . 'escala com integrado já instalado, sem investir em licença e obra nova — '
                . 'exatamente onde a barreira de entrada do setor é mais alta.',

            'Ajuste da oferta brasileira depois de 2026: a superoferta que derrubou o '
                . 'preço nasceu do custo baixo do início do ano, e o ciclo biológico faz a '
                . 'correção chegar com atraso. O planejamento 2027 pode se posicionar para o '
                . 'momento em que a oferta cede e o preço reage.',

            'Queda da Selic (14% em agosto de 2026, projeção de 12% para 2027) barateia '
                . 'o capital de giro de um negócio que vive de carregar animal e ração — e é '
                . 'o negócio da casa que mais tem a ganhar com isso, se a NCG for medida.',

            'Status sanitário de Santa Catarina como zona livre segue valendo prêmio no '
                . 'mercado externo: é ativo que a cooperativa não criou, mas pode explorar '
                . 'melhor na composição de mix e de destino da produção.',
        ],

        'AMEACA' => [
            'Preço do suíno vivo no menor patamar real da série do Cepea desde 2002 '
                . '(R$ 4,97/kg em agosto de 2026, −38% contra agosto de 2025 pela Safras & '
                . 'Mercado), com superoferta e abates recordes. É a causa externa direta da '
                . 'perda de R$ 16,0 mi de resultado em jan–set/2026.',

            'Relação de troca desfavorável: milho e farelo de soja subindo mais rápido '
                . 'que o preço do animal, com o poder de compra do suinocultor caindo pelo '
                . 'terceiro mês seguido em setembro de 2026. Com alimentação em cerca de 70% '
                . 'do desembolso, é essa razão que define a margem — e ela não está sob '
                . 'controle da cooperativa.',

            'Risco sanitário de notificação obrigatória: um único foco fecha mercado '
                . 'inteiro, independentemente do desempenho da cooperativa, e derruba o '
                . 'prêmio de preço do estado livre.',

            'Barreira comercial decidida entre governos: cotas e sobretaxas chinesas em '
                . 'renegociação ao longo de 2026, com a cota da carne bovina esgotada em '
                . '29/09/2026 e tarifa de 67%. Mesmo quando não atinge a nossa proteína, '
                . 'realoca oferta entre mercados e chega ao nosso preço.',

            'Desistência de integrados depois de dois anos de margem apertada: a perda '
                . 'de escala aparece com um ciclo de atraso e não se repõe dentro do '
                . 'exercício, porque instalação e licença levam mais tempo que a decisão de '
                . 'sair.',

            'Limite físico de expansão em Santa Catarina por área de disposição de '
                . 'dejeto e densidade de suínos nas bacias do Rio do Peixe e do Alto '
                . 'Uruguai: crescer na região esbarra em licenciamento, não em capital.',
        ],
    ],
];

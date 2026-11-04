<?php

/**
 * Conteúdo da carga da Análise de Cenário do negócio PECUÁRIA, ano 2027.
 *
 * FONTE — duas, e elas não se misturam:
 *
 * 1. **DRE Gerencial do Qlik** (espaço Filiais, app
 *    `0a23b6b3-59c8-4b8e-aa48-10b24410bcba`), extraída em 04/11/2026 com
 *    `Cód. Negócio = 2`, `%ParticipacaoSocietaria = 'Sem Part. Societária'` e
 *    `FlagLancamentoParaIgnorar = 0`. **Meses fechados: janeiro a setembro de
 *    2026** — outubro e novembro apareceram com Rateios = 0 (o rateio é a
 *    última etapa do fechamento) e foram descartados; outubro exibia Resultado
 *    Líquido de R$ 53,9 mi, artefato clássico de mês aberto. Duas âncoras de
 *    sanidade bateram exatamente com a referência da controladoria: receita
 *    bruta jan–jul de R$ 496.459.807,28 e resultado líquido de
 *    R$ 12.893.422,82. A identidade Ativo = Passivo fechou em set/2026
 *    (R$ 2.876.658.653,52).
 * 2. **Imprensa setorial** (Cepea/Safras & Mercado noticiados em ago–set/2026)
 *    para preço do suíno vivo e relação de troca. Está marcado item a item;
 *    onde o texto diz "mercado", é esta fonte, e não o Qlik.
 *
 * NÃO é conteúdo de oficina: é análise montada a pedido do cliente em
 * 2026-11-04. Entra como item editável, como qualquer outro — quem conduz o
 * planejamento corrige, completa ou apaga o que não servir.
 *
 * Como toda carga de conteúdo, é aplicada uma vez por `chave` e marcada em
 * `carga_conteudo` — ver `conteudo_cenario_macro.php` para o porquê da marca e
 * da chave nova a cada revisão. Só INSERE: nenhum item usa a forma
 * `['de' => ..., 'para' => ...]`, então nada do que já está na tela é tocado.
 *
 * `negocio` é o `cod_negocio` do cadastro — ver `CargaConteudo::planosDaCarga`.
 * É o que faz esta carga cair no planejamento da Pecuária e não no
 * corporativo.
 */

return [
    'chave' => 'cenario_pecuaria_2027_dre_2026_11_04',
    'destino' => 'CENARIO',
    'negocio' => '2',
    'ano' => 2027,

    'itens' => [
        'SITUACAO_ATUAL' => [
            'A margem caiu sem a receita cair. Janeiro a setembro de 2026 (meses '
                . 'fechados na DRE Gerencial): receita operacional líquida de R$ 607,7 mi, '
                . '+0,5% sobre o mesmo período de 2025, contra custo das vendas de '
                . 'R$ 572,4 mi, +3,0% (R$ 16,9 mi a mais). O lucro bruto caiu de R$ 49,0 mi '
                . 'para R$ 35,2 mi (−28,1%) e a margem bruta passou de 8,11% para 5,80% da '
                . 'ROL — 2,3 pontos percentuais. O problema de 2026 não é vender menos: é '
                . 'o custo subir seis vezes mais rápido que a receita.',

            'Resultado líquido de R$ 12,5 mi em jan–set/2026 contra R$ 28,5 mi em '
                . 'jan–set/2025: queda de 56,2%, ou R$ 16,0 mi a menos. A margem líquida '
                . 'saiu de 4,72% para 2,06% da receita líquida. Para comparação, o mesmo '
                . 'período de 2024 fechou em R$ 26,2 mi (4,89%) — 2026 é ruptura, não '
                . 'oscilação.',

            'A queda está concentrada em UMA filial. Resultado líquido jan–set, por '
                . 'filial: Enéas Marques (PR) foi de +R$ 4,39 mi para −R$ 7,27 mi (uma '
                . 'virada de R$ 11,7 mi, 73% de toda a queda do negócio, sobre receita de '
                . 'R$ 45,6 mi); a Matriz caiu de R$ 19,22 mi para R$ 11,11 mi; Severiano de '
                . 'Almeida (RS) ficou estável (R$ 7,54 mi → R$ 7,86 mi); e São Gabriel do '
                . 'Oeste (MS) MELHOROU, de −R$ 2,63 mi para +R$ 1,52 mi. Tratar a Pecuária '
                . 'como um bloco esconde que três das quatro unidades estão de pé.',

            'Dentro do ano a curva é de deterioração contínua, e não de sazonalidade. '
                . 'Receita mensal de 2026: R$ 77,4 mi em março, R$ 65,0 mi em junho, '
                . 'R$ 59,8 mi em setembro. Resultado líquido mensal: R$ 3,18 mi em maio, '
                . 'R$ 0,95 mi em junho e julho, R$ 0,32 mi em agosto e −R$ 0,71 mi em '
                . 'setembro — o primeiro mês negativo da série. Em 2025 a curva era o '
                . 'inverso: a receita SUBIA no segundo semestre (R$ 74,1 mi em setembro, '
                . 'R$ 93,1 mi em dezembro) e nenhum mês ficou negativo.',

            'Setembro contra setembro mede o tamanho do estrago: receita de R$ 59,8 mi '
                . 'contra R$ 74,1 mi (−19,2%), lucro bruto de R$ 2,06 mi contra R$ 5,85 mi '
                . '(−64,9%). No terceiro trimestre inteiro a receita ficou 13,9% abaixo de '
                . '2025. O acumulado positivo (+1,3%) é herança do primeiro quadrimestre e '
                . 'vai sumir no fechamento do ano.',

            'A Pecuária é 23,6% da receita da cooperativa e 57,9% do resultado '
                . '(jan–set/2026: R$ 617,0 mi de R$ 2.619,4 mi, e R$ 12,5 mi de R$ 21,6 mi). '
                . 'O negócio que mais sustenta o resultado da Copérdia é o que está perdendo '
                . 'margem mais rápido — e no mesmo período o resultado da cooperativa caiu '
                . 'de R$ 52,2 mi para R$ 21,6 mi, com Cereais virando prejuízo de R$ 13,3 mi.',

            'Despesas operacionais de R$ 15,8 mi em jan–set/2026, 19,8% acima de 2025 '
                . '(R$ 13,2 mi), com receita praticamente parada. A despesa passou de 2,18% '
                . 'para 2,60% da receita líquida. Em reais são R$ 2,6 mi — menos de um sexto '
                . 'da queda do resultado, mas é a parte que a gestão do negócio controla '
                . 'sozinha.',

            'O rateio da estrutura central cobra R$ 10,7 mi por ano do negócio '
                . '(jan–set/2026), praticamente o mesmo de 2025 (R$ 11,4 mi). Com o '
                . 'resultado antes do rateio caindo de R$ 39,9 mi para R$ 23,2 mi, a mesma '
                . 'conta passou a consumir 46% do que o negócio gera, contra 28% no ano '
                . 'passado.',

            'MERCADO (Cepea e Safras & Mercado, noticiados em ago–set/2026, não é dado '
                . 'do nosso Qlik): o suíno vivo atingiu em agosto a menor média real da '
                . 'série do Cepea, iniciada em 2002 — R$ 4,97/kg na praça SP-5, oitava queda '
                . 'mensal seguida; a Safras aponta recuo de ~38% contra agosto de 2025. '
                . 'Setembro teve a primeira alta do ano (+1,9%, R$ 5,06/kg), mas milho e '
                . 'farelo de soja subiram mais e o poder de compra do produtor caiu pelo '
                . 'terceiro mês seguido. A causa apontada é superoferta, com abates '
                . 'recordes. É a explicação externa exata da compressão que a nossa DRE '
                . 'mostra.',

            'A operação já é multiestadual, e isso não aparece no nome do negócio. '
                . 'Receita jan–set/2026 por filial: Matriz (SC) R$ 373,8 mi (60,6%), '
                . 'Severiano de Almeida (RS) R$ 133,0 mi (21,6%), São Gabriel do Oeste (MS) '
                . 'R$ 63,1 mi (10,2%), Enéas Marques (PR) R$ 45,6 mi (7,4%) e Embrapa '
                . 'R$ 1,5 mi. Quase 40% do faturamento da Pecuária é fora de Santa Catarina.',

            'LACUNA DECLARADA: o custo do capital de giro da Pecuária não foi apurado. '
                . 'A DRE Gerencial não aloca saldo patrimonial por negócio (o Ativo da '
                . 'Pecuária volta em R$ −0,4 mil em set/2026, o que prova que a alocação não '
                . 'existe, não que o capital seja zero). A 1,3% ao mês — a referência da '
                . 'controladoria —, cada R$ 10 mi de NCG custa R$ 1,56 mi ao ano, mais de '
                . '12% do resultado atual do negócio. Enquanto esse número não for apurado, '
                . 'o resultado econômico da Pecuária é desconhecido, e o contábil é o teto '
                . 'dele, nunca o piso.',
        ],

        'TENDENCIA' => [
            'O ponto de partida de 2027 é mais baixo do que o acumulado sugere. Se os '
                . 'últimos três meses fechados (jul–set/2026) forem a base, o negócio roda a '
                . 'cerca de R$ 62 mi de receita e R$ 0,2 mi de resultado por mês — contra '
                . 'R$ 3,2 mi/mês de média em 2025. Planejar 2027 a partir do acumulado de '
                . '2026 superestima a largada.',

            'A relação de troca, e não o preço do suíno isolado, é a variável que '
                . 'decide o ano. O preço do animal dá sinal de fundo (primeira alta em '
                . 'setembro), mas o custo da ração voltou a subir mais rápido. Com '
                . 'alimentação respondendo por cerca de 70% do desembolso operacional da '
                . 'atividade, a cooperativa precisa acompanhar a razão kg de suíno / kg de '
                . 'milho como indicador de primeira linha do negócio, não como nota de '
                . 'rodapé do boletim de mercado.',

            'A integração vertical com a Fábrica de Rações é a alavanca mais direta '
                . 'sobre o custo que está comendo a margem. O negócio Fábrica de Rações '
                . 'virou resultado em 2026 (de −R$ 0,8 mi para +R$ 3,0 mi em jan–set) '
                . 'enquanto a Pecuária perdia R$ 16,0 mi: vale decidir, no ciclo, se o preço '
                . 'de transferência entre os dois negócios está medindo eficiência ou apenas '
                . 'movendo resultado de um para o outro.',

            'Enéas Marques exige diagnóstico próprio antes de qualquer meta de 2027. '
                . 'Uma unidade que sai de +R$ 4,4 mi para −R$ 7,3 mi em doze meses, sobre '
                . 'R$ 45,6 mi de receita, não cabe na explicação do preço de mercado — este '
                . 'atinge as quatro filiais, e São Gabriel do Oeste melhorou no mesmo '
                . 'período. Separar preço, volume, mix, custo de aquisição e estrutura fixa '
                . 'naquela unidade é o trabalho de maior retorno financeiro do negócio hoje.',

            'Concentração de cliente é risco estrutural não precificado: a produção vai '
                . 'majoritariamente para o Sistema Aurora, no qual a cooperativa é sócia. A '
                . 'mesma relação que garante escoamento garantido faz o preço de '
                . 'transferência ser decidido numa mesa onde a Copérdia senta dos dois '
                . 'lados. O ciclo 2027–2035 precisa dizer explicitamente qual é a política '
                . 'de preço nessa relação.',

            'Expansão geográfica sem padronização de resultado. Quatro filiais em '
                . 'quatro estados, com margens que vão de +5,9% (Severiano de Almeida) a '
                . '−15,9% (Enéas Marques) sobre a receita de cada uma, em jan–set/2026. '
                . 'Antes de abrir a próxima praça, vale fechar a diferença entre as que já '
                . 'existem — o custo de aprender é menor em unidade que já opera.',

            'O resultado econômico precisa entrar no ciclo como métrica, não como '
                . 'exercício. Apurar a NCG por negócio (estoque de animais e de ração, '
                . 'adiantamento a integrado, prazo de recebimento da agroindústria) e '
                . 'descontar o custo de 1,3% ao mês é o que separa "a Pecuária deu lucro" '
                . 'de "a Pecuária criou valor". Enquanto a conta não existir, o Conselho '
                . 'decide sobre o negócio com metade da informação.',

            'Cenário de 2027 em três faixas, para as metas não nascerem de um número '
                . 'só. Base: preço do suíno se estabiliza no patamar atual e a relação de '
                . 'troca não piora — resultado anual entre R$ 8 mi e R$ 12 mi. Otimista: a '
                . 'oferta se ajusta, o preço recupera parte da queda de 38% e a margem bruta '
                . 'volta a 7% — resultado acima de R$ 25 mi. Pessimista: superoferta '
                . 'persiste e o milho sobe — o negócio opera no zero a zero, e o rateio de '
                . 'R$ 10,7 mi passa a ser a diferença entre lucro e prejuízo. As três '
                . 'premissas são explícitas de propósito: o que não pode acontecer é a meta '
                . 'de 2027 herdar a média de 2025.',
        ],
    ],
];

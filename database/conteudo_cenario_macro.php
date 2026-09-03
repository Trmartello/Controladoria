<?php

/**
 * Conteúdo da carga de cenário macroeconômico da Análise de Cenário.
 *
 * FONTE ÚNICA: lida pelo passo do `database/migrate.php` (que aplica a carga no
 * deploy) e por `cli/carga_diagnostico.php` (que aplica a um planejamento
 * escolhido à mão). Escritas separadas, as duas divergiriam na primeira revisão
 * dos números — como já aconteceu com os códigos de negócio antes de o migrate
 * passar a ler QlikSync::NEGOCIOS_FONTE por reflexão.
 *
 * `chave` marca a carga como aplicada em `carga_conteudo`: o migrate roda a
 * cada deploy e sem a marca ele recriaria, todo deploy, o item que alguém
 * apagou na tela. Revisar os textos exige chave NOVA — a antiga já está
 * marcada e nunca mais é reaplicada.
 *
 * REVISÃO NO LUGAR: um item pode ser `['de' => texto anterior, 'para' =>
 * texto novo]`. Se o texto anterior está na tela, ele é ATUALIZADO — mesmo id,
 * mesma ordem, mesmas vozes da sala e mesmo encaminhamento ao plano de ação.
 * Se foi apagado ou reescrito à mão, o novo entra como item, a menos que já
 * exista (App\Services\CargaConteudo::aplicarCenario). Item só texto entra se
 * ainda não está na tela. Por isso os textos de agosto abaixo têm de ser
 * cópia EXATA do que foi gravado: qualquer letra diferente e a revisão vira
 * duplicata.
 *
 * O conteúdo é uma FOTOGRAFIA de 2 de setembro de 2026 (fontes no fim do
 * arquivo), revisando a de agosto/2026 e acrescentando os assuntos que
 * apareceram no intervalo. Cenário envelhece: confira os números antes de
 * aplicar em outro momento.
 */

// ---- A fotografia de agosto/2026, letra por letra, para a revisão casar ----
$agosto = [
    'juro' => 'Juro em patamar recorde começando a ceder: o Copom sustentou a Selic em 15% a.a. '
        . 'durante todo o primeiro semestre e iniciou o ciclo de corte em 17/06/2026, para '
        . '14,25%. O Focus de 03/08/2026 projeta 13,75% no fim de 2026 e 12% em 2027, com '
        . 'IPCA de 5,03% (ainda acima do teto da meta), dólar a R$ 5,20 e PIB de 1,99%. '
        . 'Capital de giro e investimento seguem caros, e o associado chega à cooperativa '
        . 'já endividado a juro de mercado.',

    'suino' => 'Suíno vivo abaixo do custo de produção: em Santa Catarina o quilo recuou de R$ 5,05 '
        . 'para R$ 4,95 na integração e de R$ 5,10 para R$ 4,90 no interior (24/07/2026), '
        . 'contra custo de R$ 6,21/kg vivo apurado em junho (ICPSuíno/Embrapa). É o menor '
        . 'patamar real em duas décadas, com superoferta no mercado interno e margem '
        . 'negativa na ponta da produção.',

    'exportacao' => 'A exportação é o que sustenta o abate: o Brasil embarcou 392,2 mil toneladas de '
        . 'carne suína no 1º trimestre de 2026, alta de 16,5% sobre igual período de 2025 e '
        . 'volume histórico. O paradoxo do ano é recorde de embarque convivendo com preço '
        . 'interno abaixo do custo.',

    'racao' => 'A ração responde por 72,6% do custo do suíno e é de onde veio o alívio: o milho é '
        . 'negociado cerca de R$ 40/t abaixo do pico da última crise e o farelo de soja '
        . 'também recua, amparados por safra brasileira recorde. A alta dos grãos em julho, '
        . 'porém, já devolveu parte do ganho.',

    'leite' => 'Leite reage depois de oito meses de queda: o preço ao produtor subiu 17,6% no 1º '
        . 'trimestre de 2026 e a média Brasil chegou a R$ 2,3924/litro em março. As praças '
        . 'catarinenses acompanharam a recuperação, mas seguem abaixo do mesmo período de '
        . '2025, e a importação em alta mantém o déficit da balança em cerca de 2 bilhões '
        . 'de litros equivalentes.',

    'aves' => 'Avicultura entre a retomada e o risco sanitário: a China reabriu para o frango '
        . 'brasileiro, mas compra cerca de 30% menos em volume do que em 2024 e 2025; a '
        . 'projeção para 2026 é de 5,5 milhões de toneladas exportadas (+3,4%). A influenza '
        . 'aviária de alta patogenicidade avança no hemisfério norte, e o episódio do Rio '
        . 'Grande do Sul mostrou que um único foco embarga o país inteiro.',

    'regional' => 'Mercado regional aquecido: Santa Catarina opera com desemprego de 2,3% (mínima da '
        . 'série histórica), varejo em alta de 4,4% no 1º trimestre de 2026 contra 2,4% da '
        . 'média nacional, e o extremo Oeste com o maior avanço do emprego formal do estado '
        . '(+6,79% em 12 meses). A agropecuária catarinense cresceu 3,1% e a pecuária 4,4%. '
        . 'Sustenta supermercados, postos e agropecuárias — e pressiona salário e retenção '
        . 'de pessoas.',

    'safra' => 'Plano Safra 2026/27 melhora o funding: R$ 525,1 bilhões em crédito rural (+1,7%), '
        . 'com o custeio de cooperativas caindo de 14% para 12,5% a.a. e Prodecoop e '
        . 'Procap-Agro de 13,5% para 12% a.a., além de limites ampliados para singulares, '
        . 'centrais e federações. O setor alerta que o custeio encolheu em termos reais e '
        . 'que o seguro rural ficou sem solução.',

    't_juro' => 'O alívio do juro é de 2027, não de 2026: com o Focus projetando 13,75% ao fim deste '
        . 'ano e 12% em 2027, o custo de capital só melhora de verdade no próximo ciclo. O '
        . 'ano corrente pede alongamento de dívida e escalonamento de investimento, não '
        . 'ampliação de exposição.',

    't_suino' => 'Ciclo suíno em ajuste de oferta: com o vivo abaixo do custo, a tendência é redução '
        . 'de alojamento e saída de produtores independentes, reequilibrando a oferta ao '
        . 'longo de 2027. Quem atravessar o vale ganha participação, e a margem virá de '
        . 'conversão alimentar, sanidade e escala — não de preço.',

    't_racao' => 'Custo de ração comportado é a principal defesa da margem: a safra 2026/27 é '
        . 'projetada em novo recorde (185,6 milhões de toneladas de soja e 147,5 milhões de '
        . 'milho). O clima e a migração do produtor para o barter, por falta de crédito, são '
        . 'os riscos que podem virar essa mesa.',

    't_lacteos' => 'Lácteos com recuperação moderada e teto à vista: oferta interna em nível recorde, '
        . 'importações crescendo e as cotas de leite em pó e queijos previstas no acordo '
        . 'Mercosul–UE limitam a alta. A disputa por matéria-prima entre indústrias tende a '
        . 'se acirrar na entressafra.',

    't_sanidade' => 'Sanidade deixa de ser custo e vira condição de acesso a mercado: com HPAI ativa no '
        . 'hemisfério norte e embargos aplicados por região, biosseguridade, rastreabilidade '
        . 'e velocidade de resposta a foco passam a decidir quais unidades continuam '
        . 'exportando.',

    't_protecionismo' => 'Protecionismo redesenha o destino das proteínas: cotas e sobretaxas chinesas, '
        . 'tarifas norte-americanas e o acordo Mercosul–UE deslocam preço e volume de um '
        . 'mercado para outro dentro do mesmo ano. Diversificar destino e mix passa a pesar '
        . 'mais do que crescer volume.',

    't_financiadora' => 'A cooperativa assume o papel de financiadora do associado: crédito rural menor em '
        . 'termos reais e juro de mercado alto empurram o produtor para o barter e para o '
        . 'prazo da cooperativa. A consequência é pressão sobre o capital de giro e '
        . 'elevação do risco de crédito da carteira de associados.',

    't_consumo' => 'Consumo regional resiliente, com o mix migrando: emprego e renda em Santa Catarina '
        . 'sustentam o varejo, mas inflação acima do teto e juro alto favorecem marca '
        . 'própria, promoção e atacarejo. A disputa passa a ser por frequência de compra, e '
        . 'não por ticket médio.',

    't_energia' => 'Energia e mão de obra como custos estruturais: energia elétrica e combustível '
        . 'seguem pressionando o custo industrial e logístico, o que encurta o payback da '
        . 'geração própria; e o pleno emprego no Oeste mantém a pressão salarial, '
        . 'transformando retenção de pessoas e sucessão rural em gargalo de execução do '
        . 'planejamento.',
];

return [
    'chave' => 'cenario_macro_2026_09',
    // Grava em `cenario_item`; as chaves de `itens` são o `tipo` da coluna
    'destino' => 'CENARIO',
    // Ano da análise. A tela limita o seletor a [ano_base, ano_fim] do ciclo:
    // item gravado fora da faixa existiria no banco sem jamais aparecer. É o
    // ano-base do ciclo 2027–2035: a fotografia do ambiente de onde o plano
    // parte, e o mesmo ano da carga de agosto que esta revisa.
    'ano' => 2026,

    'itens' => [
        'SITUACAO_ATUAL' => [
            ['de' => $agosto['juro'], 'para' =>
                'Ciclo de corte em curso, ainda em terreno restritivo: o Copom cortou a Selic para '
                . '14% em 05/08/2026 — quarto corte seguido desde março, quando ela saiu dos 15% '
                . 'mantidos desde junho de 2025 — e o mercado espera mais 0,25 p.p. em 16/09. O Focus '
                . 'de 31/08/2026 projeta 13,75% no fim de 2026 e 12% em 2027, IPCA de 5,01% (ainda '
                . 'acima do teto da meta), dólar a R$ 5,20 e PIB de 1,92%. Capital de giro e '
                . 'investimento seguem caros, e o associado chega à cooperativa endividado a juro '
                . 'de mercado.'],

            ['de' => $agosto['suino'], 'para' =>
                'Suíno vivo cada vez mais longe do custo: em Santa Catarina o quilo caiu em agosto '
                . 'de R$ 4,95 para R$ 4,80 na integração e de R$ 4,75 para R$ 4,60 no interior — a '
                . 'maior queda entre as praças —, enquanto o custo de produção subiu a R$ 6,24/kg '
                . 'vivo (ICPSuíno/Embrapa, agosto, +1,82% no mês), encerrando três meses de queda. '
                . 'Oferta interna elevada, dificuldade de formação de preço no atacado e margem '
                . 'negativa na ponta da produção.'],

            ['de' => $agosto['exportacao'], 'para' =>
                'A exportação continua sendo o que sustenta o abate: 925,6 mil toneladas de carne '
                . 'suína de janeiro a julho de 2026 (+9,1%), com receita de US$ 2,16 bilhões (+5,8%); '
                . 'em julho, 131,5 mil t (+3,7%) com receita 5,6% menor — volume recorde a preço '
                . 'médio mais baixo. Filipinas, Japão e Chile lideram; a China caiu para 9,1 mil t no '
                . 'mês. O paradoxo do ano continua: recorde de embarque com preço interno abaixo do '
                . 'custo.'],

            ['de' => $agosto['racao'], 'para' =>
                'O alívio dos grãos acabou: o milho (Indicador Cepea/Esalq) subiu cerca de 6% em '
                . 'agosto, a R$ 67,90/saca em 21/08, e a soja renovou a máxima nominal de 2026 em '
                . 'R$ 149/saca em Paranaguá, maior nível desde abril de 2023, com produtor retendo '
                . 'oferta e demanda firme. A ração, 70,8% do custo do suíno, subiu 2,2% no mês e '
                . 'pressiona também frango, leite e a Fábrica de Rações.'],

            ['de' => $agosto['leite'], 'para' =>
                'Leite recupera devagar e ainda muito abaixo de 2025: na apuração mais recente do '
                . 'Cepea a Média Brasil subiu 5,43% e chegou a R$ 2,1464/litro, mas segue 25,5% '
                . 'abaixo do mesmo período do ano passado em termos reais; Santa Catarina paga '
                . 'R$ 2,0727/litro, abaixo da média nacional. A importação de leite em pó e queijos '
                . 'do Mercosul mantém a pressão, e a balança catarinense de lácteos segue '
                . 'deficitária (672,6 t em julho).'],

            ['de' => $agosto['aves'], 'para' =>
                'Avicultura livre de embargo, mas sob vigilância: o foco de influenza aviária de '
                . 'julho de 2026 em criação de subsistência em Coqueiros do Sul (RS) não alterou o '
                . 'status sanitário nem gerou restrição, ao contrário do foco de Montenegro em 2025. '
                . 'A China segue comprando menos que em 2024/2025 e a projeção de 5,5 milhões de '
                . 'toneladas exportadas em 2026 (+3,4%) se mantém. O ponto de atenção mudou de '
                . 'lugar: a lista europeia de antimicrobianos.'],

            ['de' => $agosto['regional'], 'para' =>
                'Mercado regional ainda aquecido, com sinais de desaceleração: Santa Catarina teve '
                . 'desemprego de 2,1% no 2º trimestre de 2026 (menor do país, contra 5,4% da média '
                . 'nacional) e criou 62,7 mil empregos formais de janeiro a julho, mas julho fechou '
                . 'com saldo negativo (−248 vagas), puxado pela indústria — vestuário, têxtil, '
                . 'madeira e móveis. Supermercados, postos e agropecuárias seguem sustentados pela '
                . 'renda; a pressão salarial e a retenção de pessoas continuam.'],

            ['de' => $agosto['safra'], 'para' =>
                'Plano Safra 2026/27 em vigor: R$ 525,1 bilhões em crédito rural (+1,7%), custeio '
                . 'de cooperativas de 14% para 12,5% a.a. e Prodecoop e Procap-Agro de 13,5% para '
                . '12% a.a., com limites ampliados para singulares, centrais e federações. Na '
                . 'prática o custeio encolheu em termos reais, o seguro rural ficou sem solução e, '
                . 'com a Selic a 14%, o crédito subsidiado continua disputado.'],

            // ---- Assuntos novos desde agosto ----
            'União Europeia fecha a porta por antimicrobianos: em 12/05/2026 a Comissão retirou o '
            . 'Brasil da lista de países autorizados a exportar produtos de origem animal ao bloco '
            . '(bovinos, aves, ovos, aquicultura, mel) por falta de garantias sobre o não uso de '
            . 'antimicrobianos críticos, com efeito a partir de 03/09/2026. Estão em jogo '
            . 'US$ 1,8 bilhão (368 mil t) de carnes vendidas ao bloco em 2025 — três meses depois '
            . 'de o acordo Mercosul–UE entrar em vigor provisório (maio/2026). Reversão ou '
            . 'prorrogação mudam o cenário das proteínas: acompanhar semana a semana.',

            'Reforma tributária entrou na fase de teste: a LC 227/2026 (janeiro) completou a '
            . 'regulamentação do IBS/CBS com regime específico e OPTATIVO para o ato cooperativo '
            . '(alíquota zero nas operações entre associado e cooperativa), opção formalizada no '
            . 'ano anterior ao de vigência, e transição de 2026 a 2032. As resoluções do Comitê '
            . 'Gestor previstas para o 2º semestre de 2026 ainda detalham o regime. Decidir pela '
            . 'opção e adequar os sistemas é tarefa de 2026, não de 2027.',

            'Energia mais cara em Santa Catarina: a Aneel aprovou a revisão tarifária da Celesc '
            . 'com efeito médio de 10,82% a partir de 22/08/2026 — 9,3% nas residências e 14,16% '
            . 'nos grandes consumidores em alta tensão. Encurta o payback da geração própria '
            . '(usina fotovoltaica e Mauê) e pesa no resfriamento de leite, na fábrica de ração '
            . 'e no frio dos supermercados.',

            'El Niño confirmado para a safra 2026/27: a probabilidade passa de 90% de agosto em '
            . 'diante, com persistência até 2027. No Sul o padrão é chuva acima da média e alta '
            . 'umidade na safra de verão — bom para pastagem e reservatórios, risco de excesso na '
            . 'colheita, doenças e qualidade de grãos. A Conab divulga o 1º levantamento da safra '
            . '2026/27 em 15/10.',
        ],

        'TENDENCIA' => [
            ['de' => $agosto['t_juro'], 'para' =>
                'O alívio do juro é gradual e de 2027: Selic a 13,75% no fim de 2026, 12% em 2027 e '
                . '10,5% só em 2028 pelo Focus, com inflação ainda acima do teto. O custo de capital '
                . 'só melhora de verdade no próximo ciclo; 2026 pede alongamento de dívida e '
                . 'escalonamento de investimento, não ampliação de exposição.'],

            ['de' => $agosto['t_suino'], 'para' =>
                'Ciclo suíno em ajuste de oferta prolongado: com o vivo a R$ 4,60–4,80/kg contra '
                . 'custo de R$ 6,24, a redução de alojamento e a saída de independentes se aceleram '
                . 'e o reequilíbrio fica para 2027. Quem atravessar o vale ganha participação; a '
                . 'margem virá de conversão alimentar, sanidade e escala — e do preço de exportação, '
                . 'não do mercado interno.'],

            ['de' => $agosto['t_racao'], 'para' =>
                'Custo de ração deixa de ser defesa e vira risco: milho e soja na máxima do ano, '
                . 'produtor retendo grãos e demanda externa firme; a safra 2026/27, projetada em '
                . 'novo recorde, só alivia a partir da colheita (fevereiro a abril de 2027), e o El '
                . 'Niño pode comprometer a qualidade. Travar parte do milho e da soja e usar o '
                . 'barter com o associado passam a ser decisões de 2026.'],

            ['de' => $agosto['t_lacteos'], 'para' =>
                'Lácteos com recuperação lenta e teto baixo: oferta interna alta, preço 25% abaixo '
                . 'de 2025 em termos reais, importação do Mercosul e as cotas do acordo com a UE '
                . 'limitam a alta; a disputa por matéria-prima na entressafra se acirra e o produtor '
                . 'menor sai da atividade. A cooperativa tende a captar mais leite por produtor, de '
                . 'menos produtores.'],

            ['de' => $agosto['t_sanidade'], 'para' =>
                'Sanidade vira condição de acesso a mercado, agora também por antimicrobianos: além '
                . 'da influenza aviária e do risco de peste suína africana (Brasil livre desde 1984, '
                . 'doença presente em mais de 70 países), a exigência europeia sobre antimicrobianos '
                . 'críticos passa a decidir quais cadeias exportam. Rastreabilidade do uso de '
                . 'medicamentos, biosseguridade e velocidade de resposta a foco entram no custo '
                . 'fixo.'],

            ['de' => $agosto['t_protecionismo'], 'para' =>
                'Protecionismo e regulação redesenham o destino das proteínas: o acordo Mercosul–UE '
                . 'em vigor provisório abre cota, mas a lista de antimicrobianos fecha a porta; a '
                . 'China compra menos e de forma mais volátil; os Estados Unidos mantêm tarifas '
                . 'sobre parte da pauta brasileira, com as carnes preservadas até aqui. Diversificar '
                . 'destino (Filipinas, Japão, Chile, Oriente Médio) e mix pesa mais do que crescer '
                . 'volume.'],

            ['de' => $agosto['t_financiadora'], 'para' =>
                'A cooperativa assume o papel de financiadora do associado: custeio menor em termos '
                . 'reais e juro de mercado a 14% empurram o produtor para o barter e para o prazo da '
                . 'cooperativa — com o suíno abaixo do custo, a inadimplência na integração tende a '
                . 'subir. A consequência é pressão sobre o capital de giro e elevação do risco de '
                . 'crédito da carteira de associados.'],

            ['de' => $agosto['t_consumo'], 'para' =>
                'Consumo regional resiliente, com o mix migrando e o emprego desacelerando: o pleno '
                . 'emprego sustenta o varejo, mas o saldo negativo de julho na indústria e a inflação '
                . 'acima do teto favorecem marca própria, promoção e atacarejo. A disputa é por '
                . 'frequência de compra, não por ticket médio.'],

            ['de' => $agosto['t_energia'], 'para' =>
                'Energia e mão de obra como custos estruturais: o reajuste de 14% na alta tensão da '
                . 'Celesc encurta o payback da geração própria e favorece contratos no mercado livre; '
                . 'o pleno emprego no Oeste mantém a pressão salarial e transforma retenção de '
                . 'pessoas e sucessão rural em gargalo de execução do planejamento.'],

            // ---- Assuntos novos desde agosto ----
            'Reforma tributária muda o preço relativo do ato cooperativo: com alíquota zero no '
            . 'regime específico e crédito na ponta do associado contribuinte, cresce o incentivo a '
            . 'concentrar compras e vendas via cooperativa — desde que a opção seja feita a tempo e '
            . 'os sistemas separem ato cooperativo de ato com terceiros. Supermercados e postos, '
            . 'que vendem a não associados, ficam na alíquota cheia.',

            'Câmbio estável e preço de exportação em queda: com o dólar projetado em R$ 5,20 e o '
            . 'preço médio da carne suína exportada caindo (receita −5,6% em julho com volume '
            . '+3,7%), a receita em reais da proteína tende a crescer menos que o volume. A margem '
            . 'dependerá de custo em reais — ração, energia e mão de obra —, não do câmbio.',

            'Clima com El Niño até 2027: excesso de chuva no Sul favorece pastagem e leite, mas '
            . 'aumenta perda na colheita, micotoxinas no milho e custo de secagem. Cereais e '
            . 'Fábrica de Rações precisam planejar recepção, secagem e armazenagem para uma safra '
            . 'volumosa e úmida.',

            'Sucessão e digitalização do associado: a base de mais de 20 mil cooperados envelhece '
            . 'e o sucessor decide por aplicativo, assistência técnica e crédito. Drones, '
            . 'agricultura de precisão e canal digital deixam de ser diferenciação e viram condição '
            . 'para manter a fidelidade de entrega do produtor.',
        ],
    ],
];

/*
 * Fontes da fotografia de 2 de setembro de 2026:
 * - Copom de 05/08/2026 (Selic 14%, quarto corte desde março) e Boletim Focus de
 *   31/08/2026 (IPCA 5,01% em 2026 e 4,28% em 2027; Selic 13,75% em 2026, 12% em
 *   2027 e 10,5% em 2028; dólar R$ 5,20; PIB 1,92% em 2026 e 1,50% em 2027).
 * - ICPSuíno/Embrapa (CIAS) de agosto/2026: custo de R$ 6,24/kg vivo em SC (+1,82%
 *   no mês; ração 70,76% do custo, +2,22%); cotações de suíno vivo em SC em agosto
 *   (integração R$ 4,95 -> 4,80; interior R$ 4,75 -> 4,60).
 * - ABPA: carne suína, 925,6 mil t de janeiro a julho/2026 (+9,1%), US$ 2,158 bi
 *   (+5,8%); julho 131,5 mil t (+3,7%) e US$ 298,3 mi (-5,6%); destinos Filipinas
 *   20,3 mil t, Japão 18,4, Chile 13,9, China 9,1. Projeção de frango 2026:
 *   5,5 mi t (+3,4%).
 * - Cepea: Média Brasil do leite R$ 2,1464/l (+5,43% no mês; -25,45% real em 12
 *   meses); SC R$ 2,0727/l. Epagri/Cepa: déficit de 672,6 t na balança de lácteos
 *   de SC em julho. Cepea/Esalq: milho R$ 67,90/sc em 21/08 (+5,8% na parcial de
 *   agosto); soja R$ 149/sc em Paranaguá na semana de 24/08 (máxima de 2026).
 * - Mapa/Agência Brasil: foco de HPAI em subsistência em Coqueiros do Sul (RS),
 *   julho/2026, sem alteração de status; Brasil livre de PSA desde 1984 (OMSA).
 * - Comissão Europeia, 12/05/2026 (Regulamento Delegado (UE) 2023/905): Brasil
 *   fora da lista de exportadores de produtos de origem animal a partir de
 *   03/09/2026; UE importou US$ 1,8 bi / 368,1 mil t de carnes brasileiras em 2025.
 *   Acordo Mercosul–UE em aplicação provisória desde maio/2026.
 * - Leis Complementares 214/2025 e 227/2026 (regime específico do ato cooperativo
 *   no IBS/CBS; transição 2026–2032); resoluções do CGIBS previstas para o 2º
 *   semestre de 2026.
 * - Aneel: revisão tarifária da Celesc, efeito médio 10,82% desde 22/08/2026
 *   (B1 9,29%; alta tensão 14,16%).
 * - Inmet/Defesa Civil SC (junho/2026): El Niño confirmado, probabilidade >= 90% de
 *   agosto-setembro-outubro em diante. Conab: 1º levantamento da safra 2026/27 em
 *   15/10/2026; safra 2025/26 em 360,8 mi t (soja 180,5; milho 143).
 * - IBGE/PNAD (2º tri/2026: SC 2,1% contra 5,4% nacional) e Caged via Seplan-SC
 *   (julho -248 vagas; +62.658 de janeiro a julho). Plano Safra 2026/27 como na
 *   fotografia de agosto.
 */

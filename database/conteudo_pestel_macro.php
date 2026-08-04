<?php

/**
 * Conteúdo da carga da análise PESTEL (fatores externos do diagnóstico).
 *
 * FONTE ÚNICA: lida pelo passo do `database/migrate.php` (que aplica no deploy)
 * e por `cli/carga_diagnostico.php` (que aplica a um planejamento escolhido à
 * mão). Duas redações separadas divergiriam na primeira revisão dos números.
 *
 * `chave` marca a carga como aplicada em `carga_conteudo`: o migrate roda a
 * cada deploy e sem a marca ele recriaria, todo deploy, o fator que alguém
 * apagou na tela. Revisar os textos exige chave NOVA.
 *
 * As categorias são as de FatorController::CATEGORIAS['PESTEL'] — categoria
 * fora dessa lista entra no banco mas não aparece em coluna nenhuma.
 *
 * O conteúdo é uma FOTOGRAFIA de agosto/2026 (fontes no fim do arquivo).
 */

return [
    'chave' => 'pestel_macro_2026_08',
    // Grava em `fator`; as chaves de `itens` são a `categoria` da coluna
    'destino' => 'FATOR',
    'etapa' => 'PESTEL',
    'ano' => 2026,

    'itens' => [
        'POLITICO' => [
            'Plano Safra 2026/27 com R$ 525,1 bilhões e juro de custeio para cooperativas caindo '
            . 'de 14% para 12,5% a.a., com Prodecoop e Procap-Agro a 12%: a política de crédito '
            . 'rural é variável de decisão da cooperativa, não pano de fundo.',

            'Redução do custeio em termos reais e seguro rural sem solução no Plano Safra: o '
            . 'risco climático que o seguro cobriria migra para a cooperativa, via barter e '
            . 'prazo concedido ao associado.',

            'Cotas e sobretaxas chinesas sobre a carne brasileira em renegociação diplomática '
            . '(cota anual de 1,1 milhão de toneladas e sobretaxa de 55% acima dela na bovina): '
            . 'acesso a mercado depende de acordo entre governos, não de competitividade.',

            'Tarifas norte-americanas e a disputa comercial em curso deslocam destino e preço '
            . 'das proteínas dentro do mesmo ano, sem aviso e sem relação com o custo de '
            . 'produção daqui.',

            'Eleições gerais de 2026: segundo semestre com pauta fiscal, câmbio e crédito mais '
            . 'voláteis, e definições de política agrícola de médio prazo adiadas para o governo '
            . 'seguinte.',
        ],

        'ECONOMICO' => [
            'Selic em 14,25% a.a. desde 17/06/2026, com projeção de 13,75% no fim do ano e 12% '
            . 'em 2027 (Focus de 03/08): custo de capital ainda no topo do ciclo, encarecendo '
            . 'capital de giro e adiando investimento.',

            'IPCA projetado em 5,03% para 2026, acima do teto da meta: pouco espaço para repasse '
            . 'de preço no varejo e consumidor trocando marca a cada reajuste.',

            'Câmbio em R$ 5,20: sustenta a receita de exportação das proteínas e, ao mesmo '
            . 'tempo, encarece fertilizante, aditivo, medicamento e peça de reposição '
            . 'importados.',

            'Suíno vivo em Santa Catarina entre R$ 4,90 e R$ 4,95/kg (24/07/2026) contra custo '
            . 'de produção de R$ 6,21/kg vivo (ICPSuíno/Embrapa, junho): margem negativa na '
            . 'ponta da produção, com a ração respondendo por 72,6% do custo.',

            'Safra 2026/27 projetada em novo recorde (185,6 milhões de toneladas de soja e 147,5 '
            . 'milhões de milho): alívio no custo da ração e, ao mesmo tempo, pressão de baixa '
            . 'no preço do grão recebido do associado.',

            'PIB nacional de 1,99% em 2026 convivendo com desemprego catarinense de 2,3% e '
            . 'varejo estadual em alta de 4,4%: economia do país em marcha lenta e mercado '
            . 'regional aquecido ao mesmo tempo.',
        ],

        'SOCIAL' => [
            'Pleno emprego no Oeste catarinense (emprego formal +6,79% em 12 meses, desemprego '
            . 'estadual de 2,3%): disputa por mão de obra, pressão salarial e dificuldade de '
            . 'retenção nas unidades industriais.',

            'Sucessão rural: envelhecimento do quadro social e saída dos jovens do campo ameaçam '
            . 'a base de associados dentro do horizonte do ciclo, não depois dele.',

            'Consumidor migrando para marca própria, promoção e atacarejo mesmo com a renda '
            . 'catarinense acima da média nacional: no varejo, a disputa passa a ser por '
            . 'frequência de compra, não por ticket médio.',

            'Exigência crescente de bem-estar animal, origem e rotulagem, vinda do comprador '
            . 'externo e do consumidor urbano, alcançando a prática dentro da propriedade do '
            . 'associado.',
        ],

        'TECNOLOGICO' => [
            'Nutrição de precisão, ambiência e sensoriamento na granja: com o preço abaixo do '
            . 'custo, a margem passa a vir de conversão alimentar e mortalidade, e não do '
            . 'mercado.',

            'Rastreabilidade digital do lote como exigência prática de habilitação para '
            . 'exportar — deixa de ser diferencial e vira porta de entrada.',

            'Geração fotovoltaica própria com payback encurtado pelo custo de energia, tornando '
            . 'a ampliação da usina existente uma decisão econômica e não ambiental.',

            'Dados e BI na assistência técnica e na gestão do quadro social: decisão por '
            . 'indicador comparável entre unidades, no lugar da percepção de campo.',

            'Inteligência artificial aplicada a previsão de demanda, formulação de ração e '
            . 'manutenção preditiva na indústria, com ganho concentrado em quem já tem o dado '
            . 'organizado.',
        ],

        'ECOLOGICO' => [
            'Eventos climáticos extremos no Sul (estiagem e enchente) atingem safra, logística e '
            . 'renda do associado dentro do mesmo ciclo, e a frequência deixou de permitir '
            . 'tratá-los como excepcionais.',

            'Gestão de dejetos e licenciamento ambiental como restrição concreta à ampliação de '
            . 'alojamento: o limite da expansão é ambiental antes de ser financeiro.',

            'Disponibilidade e qualidade da água nas bacias do Oeste como limite físico à '
            . 'agroindústria, disputada entre produção animal, indústria e abastecimento '
            . 'urbano.',

            'Pressão de descarbonização na cadeia: comprador externo pedindo inventário de '
            . 'emissões e prática de baixo carbono como condição de compra, não como '
            . 'diferencial de preço.',
        ],

        'LEGAL' => [
            'Reforma tributária em implantação: 2026 é o ano de teste da CBS e do IBS, com o ato '
            . 'cooperativo reconhecido a alíquota zero no art. 271 da LC nº 214/2025.',

            'Janela de 1º de setembro a 31 de outubro de 2026 para optar formalmente pelo regime '
            . 'específico das cooperativas: prazo legal com efeito direto na carga tributária do '
            . 'ato cooperativo, e a opção não é automática.',

            'Fornecimento a associado que não seja contribuinte regular do IBS/CBS só tem '
            . 'alíquota zero com anulação dos créditos apropriados: complexidade contábil nova '
            . 'na operação corrente com o quadro social.',

            'Habilitação sanitária de plantas por país de destino: é a norma do comprador, e não '
            . 'a brasileira, que define o que cada unidade pode exportar.',

            'Legislação ambiental e trabalhista mais exigente — licenciamento, normas '
            . 'regulamentadoras e LGPD no tratamento de dados de associados e colaboradores.',
        ],
    ],
];

/*
 * Fontes da fotografia de agosto/2026:
 * - Boletim Focus de 03/08/2026 e decisão do Copom de 17/06/2026 (Selic 14,25%).
 * - ICPSuíno/Embrapa (custo de R$ 6,21/kg vivo em junho; alimentação 72,6%) e
 *   cotações de suíno vivo em SC de 24/07/2026.
 * - Plano Safra 2026/27: R$ 525,1 bi; custeio de cooperativas 14% -> 12,5% a.a.;
 *   Prodecoop e Procap-Agro 13,5% -> 12% a.a.; alerta do setor sobre custeio real
 *   e seguro rural.
 * - Conab e projeções de mercado para a safra 2026/27 (soja 185,6 mi t;
 *   milho 147,5 mi t).
 * - IBGE/PNAD, PMC e Caged via Seplan-SC e Facisc: desemprego de 2,3%, varejo
 *   catarinense +4,4% no 1º tri/2026, emprego formal do extremo Oeste +6,79%.
 * - LC nº 214/2025 (art. 271) e Decreto nº 12.955/2026: ato cooperativo a
 *   alíquota zero de IBS/CBS, regime específico optativo e a janela de opção
 *   entre 01/09 e 31/10/2026.
 * - Cotas e sobretaxas da China sobre a carne brasileira e tarifas dos EUA,
 *   conforme noticiado ao longo do 1º semestre de 2026.
 */

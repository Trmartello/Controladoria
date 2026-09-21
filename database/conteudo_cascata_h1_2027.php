<?php

/**
 * Conteúdo da carga da Cascata de Escolhas do H1, como estava em 2027.
 *
 * FONTE: o dossiê do planejamento corporativo gerado pelo próprio sistema em
 * 04/09/2026 (Ciclo 2027–2035, diagnóstico de 2027) — o que a cooperativa
 * escreveu nas oficinas, não análise nova. Existe para REPOR o diagnóstico de
 * 2027 num banco que o perdeu: instalação nova, ou restauração de um backup
 * anterior ao trabalho.
 *
 * Como toda carga de conteúdo, é aplicada uma vez por `chave` e marcada em
 * `carga_conteudo` — ver `conteudo_cenario_macro.php` para o porquê da marca e
 * da chave nova a cada revisão. Entra na lista do migrate (aplica no deploy) e
 * em `CARGAS` da CLI. Nasceu só na CLI, por ser reposição de conteúdo do
 * cliente e não conteúdo curado; passou ao deploy em 2026-09-21, quando o
 * Console do provedor mostrou-se inalcançável na rede da cooperativa e a
 * alternativa oferecida por lá era expor os comandos numa URL pública.
 *
 * ATENÇÃO ao sexto driver: no cadastro ele nasce 'Métrica-Âncora' e no dossiê
 * aparece como "Indicadores (KPI's)", renomeado pela interface. A carga resolve
 * driver e eixo por NOME e recusa o que não achar, com mensagem. Num banco onde
 * o nome foi trocado, ajuste a chave aqui ou o cadastro — não insira às cegas.
 *
 * A guarda desta carga é a CÉLULA (plano × horizonte × driver × eixo): num
 * banco que já tem a cascata do H1 preenchida, nada é sobrescrito.
 */

return [
    'chave' => 'cascata_h1_2027_dossie_2026_09_04',
    'destino' => 'CASCATA',
    'horizonte' => 'H1',

    'itens' => [
        'Aonde Jogar' => [
            'sintese' => [
                'escolha' =>
                    'Preservar a imagem não disputar preço e ganhar chefes somente se '
                        . 'preservar margem Tober',
                'renuncia' =>
                    'Disputa por preço Foco em participação do mercado',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Atacar os dois maiores custos, ração e energia, onde cada ponto '
                        . 'ganho vale mais do que qualquer receita nova em H1.',
                'renuncia' =>
                    'Não dispersar esforço em ganhos pequenos espalhados por muitas '
                        . 'frentes.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Jogar na desalavancagem e no alongamento da dívida cara, usando o '
                        . 'funding subsidiado enquanto ele existe.',
                'renuncia' =>
                    'Não usar capital próprio para crescer em H1.',
            ],
            'Marca' => [
                'escolha' =>
                    'c\\zxc\\zxc\\zxc\\zcvfbwfsdcs',
                'renuncia' =>
                    'sdfsdfafvasdvafv',
            ],
            'Mercado' => [
                'escolha' =>
                    'participação de share',
                'renuncia' =>
                    'renunciamos disputa por preço',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Jogar na retenção do time técnico e das lideranças de unidade, que '
                        . 'são quem entrega a eficiência prometida.',
                'renuncia' =>
                    'Não expandir quadro administrativo; contratação nova só em função '
                        . 'que toca margem.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'escolha testes',
                'renuncia' =>
                    'sem renúncias',
            ],
        ],
        'Como Vencer' => [
            'sintese' => [
                'escolha' =>
                    'Vencer por eficiência e por vínculo com o associado — melhor '
                        . 'conversão alimentar, melhor ocupação de planta e um atendimento que '
                        . 'a trading não presta —, e não por preço nem por volume.',
                'renuncia' =>
                    'Não disputar preço com grupo consolidado e não perseguir '
                        . 'participação de mercado que exija operar com margem negativa.',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Vencer com conversão alimentar, sanidade e ocupação de planta acima '
                        . 'da média do Oeste.',
                'renuncia' =>
                    'Não buscar eficiência cortando assistência técnica ao associado — é '
                        . 'o custo que devolve margem.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Vencer com custo de capital menor: Plano Safra, regime tributário '
                        . 'correto e capital próprio bem alocado.',
                'renuncia' =>
                    'Não financiar operação corrente com dívida de mercado a juro cheio.',
            ],
            'Marca' => [
                'escolha' =>
                    'Vencer pela confiança local: origem, procedência e uma relação de '
                        . 'décadas que o entrante não consegue comprar.',
                'renuncia' =>
                    'Não competir em comunicação de massa com as marcas nacionais.',
            ],
            'Mercado' => [
                'escolha' =>
                    'Vencer pelo nível de serviço ao associado e pela regularidade de '
                        . 'entrega ao varejo, que é o que sustenta espaço de gôndola.',
                'renuncia' =>
                    'Não vencer por preço na gôndola.',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Vencer com gente que fica: trilha técnica e sucessão de liderança '
                        . 'nas unidades.',
                'renuncia' =>
                    'Não competir apenas por salário com a indústria vizinha, disputa que '
                        . 'a escala menor não ganha.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'Vencer pelo mix que carrega margem — industrializado e lácteos —, e '
                        . 'não pelo volume de commodity.',
                'renuncia' =>
                    'Não crescer em item de margem baixa apenas para ocupar planta.',
            ],
        ],
        'Envelope' => [
            'sintese' => [
                'escolha' =>
                    'Envelope curto e seletivo, com prioridade para armazenagem própria, '
                        . 'eficiência industrial e geração de energia. Investimento que não se '
                        . 'paga dentro do próprio horizonte fica para H2.',
                'renuncia' =>
                    'Nada de ampliação de capacidade de abate, aquisição de terceiros nem '
                        . 'bandeira nova de varejo em H1.',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Prioridade máxima do envelope: eficiência energética, automação de '
                        . 'granja e ampliação da geração fotovoltaica.',
                'renuncia' =>
                    'Projeto de eficiência com payback além de 2029 vai para H2.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Parte do envelope reservada para reduzir dívida cara e recompor '
                        . 'capital de giro.',
                'renuncia' =>
                    'Não ampliar distribuição de sobras antes de a alavancagem chegar ao '
                        . 'alvo do horizonte.',
            ],
            'Marca' => [
                'escolha' =>
                    'Envelope mínimo de marca: manutenção de identidade e ponto de venda.',
                'renuncia' =>
                    'Sem verba de campanha institucional.',
            ],
            'Mercado' => [
                'escolha' =>
                    'Envelope para manter e qualificar os pontos que já existem.',
                'renuncia' =>
                    'Zero capex de abertura de ponto novo em H1.',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Envelope para formação técnica e retenção, não para ampliar quadro.',
                'renuncia' =>
                    'Sem estrutura corporativa nova em H1.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'Envelope concentrado em armazenagem e em fábrica de rações, que são '
                        . 'os dois ativos que mudam a margem da cadeia inteira.',
                'renuncia' =>
                    'Sem capex de ampliação de abate.',
            ],
        ],
        'Capacidades e Recursos' => [
            'sintese' => [
                'escolha' =>
                    'Construir três capacidades em H1: custo medido por unidade, retenção '
                        . 'de gente técnica e gestão do crédito ao associado como carteira com '
                        . 'risco conhecido.',
                'renuncia' =>
                    'Não construir estrutura corporativa nova e não internalizar o que o '
                        . 'mercado já entrega barato.',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Capacidade de medir conversão alimentar e consumo de energia por '
                        . 'unidade, com o mesmo critério em todas.',
                'renuncia' =>
                    'Não aceitar indicador que só existe numa unidade ou que cada uma '
                        . 'calcula à sua maneira.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Capacidade de tratar o crédito ao associado como carteira: '
                        . 'concentração, prazo e risco medidos.',
                'renuncia' =>
                    'Não ampliar prazo concedido sem análise de risco.',
            ],
            'Marca' => [
                'escolha' =>
                    'Capacidade de garantir e comprovar procedência e rastreabilidade do '
                        . 'lote, que já é exigência de comprador.',
                'renuncia' =>
                    'Não construir estrutura de marketing própria em H1.',
            ],
            'Mercado' => [
                'escolha' =>
                    'Capacidade de ler preço e margem por canal a tempo de mudar a '
                        . 'decisão, e não no fechamento do mês.',
                'renuncia' =>
                    'Não montar equipe comercial nova.',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Capacidade de formar técnico de campo e de reter liderança de '
                        . 'unidade, incluindo os filhos de associados.',
                'renuncia' =>
                    'Não depender de contratação pronta num mercado local em pleno '
                        . 'emprego.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'Capacidade de custeio por produto e por unidade, comparável entre '
                        . 'elas.',
                'renuncia' =>
                    'Não terceirizar a inteligência de custo: é ela que decide o mix.',
            ],
        ],
        'Iniciativas Estruturantes' => [
            'sintese' => [
                'escolha' =>
                    'Poucas iniciativas, todas com efeito de caixa dentro de H1: '
                        . 'armazenagem, eficiência de ração e energia, e reperfilamento da '
                        . 'dívida.',
                'renuncia' =>
                    'Nenhuma iniciativa de inovação sem caso de uso operacional, e nenhum '
                        . 'projeto plurianual sem entrega parcial até 2028.',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Programa de eficiência de ração e energia, com ampliação da geração '
                        . 'fotovoltaica própria.',
                'renuncia' =>
                    'Não iniciar automação que dependa de dado que ainda não é coletado.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Reperfilamento da dívida com funding do Plano Safra e decisão formal '
                        . 'sobre o regime específico do ato cooperativo.',
                'renuncia' =>
                    'Não recorrer a captação de mercado a juro cheio para alongar prazo.',
            ],
            'Marca' => [
                'escolha' =>
                    'Selo de origem e rastreabilidade do lote, ligado à exigência do '
                        . 'comprador externo.',
                'renuncia' =>
                    'Não reposicionar marca nem redesenhar identidade em H1.',
            ],
            'Mercado' => [
                'escolha' =>
                    'Programa de regularidade de entrega ao varejo e de atendimento ao '
                        . 'associado, com indicador de nível de serviço.',
                'renuncia' =>
                    'Não lançar programa de expansão de clientes em H1.',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Programa de sucessão rural e de formação técnica com os filhos de '
                        . 'associados, que é o que repõe a base do quadro social.',
                'renuncia' =>
                    'Não criar programa corporativo de trainee desconectado do campo.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'Projeto de armazenagem própria: a meta de 70% do ciclo começa a ser '
                        . 'construída aqui, não em H3.',
                'renuncia' =>
                    'Não iniciar projeto de linha de produto nova enquanto a armazenagem '
                        . 'não avançar.',
            ],
        ],
        'Métrica-Âncora' => [
            'sintese' => [
                'escolha' =>
                    'A âncora de H1 é a margem bruta antes de investimentos: é ela que '
                        . 'decide a prioridade quando duas frentes disputarem o mesmo recurso.',
                'renuncia' =>
                    'Não perseguir faturamento, número de associados nem participação de '
                        . 'mercado como métrica de sucesso do horizonte.',
            ],
            'Eficiência' => [
                'escolha' =>
                    'Conversão alimentar e custo de energia por tonelada produzida.',
                'renuncia' =>
                    'Não medir por produção total.',
            ],
            'Financeiro' => [
                'escolha' =>
                    'Dívida líquida sobre geração de caixa, ao lado da margem bruta antes '
                        . 'de investimentos.',
                'renuncia' =>
                    'Não medir o horizonte por sobra distribuída.',
            ],
            'Marca' => [
                'escolha' =>
                    'Recompra e frequência de compra no varejo próprio.',
                'renuncia' =>
                    'Não medir por lembrança de marca nem por alcance de campanha.',
            ],
            'Mercado' => [
                'escolha' =>
                    'Participação da cooperativa na produção de cada associado — quanto '
                        . 'do que ele produz passa por aqui.',
                'renuncia' =>
                    'Não medir por número de associados.',
            ],
            'Pessoas' => [
                'escolha' =>
                    'Retenção do time técnico e das lideranças de unidade em doze meses.',
                'renuncia' =>
                    'Não medir por número de contratações nem por horas de treinamento.',
            ],
            'Portfólio' => [
                'escolha' =>
                    'Margem bruta por linha de negócio, comparável entre as linhas.',
                'renuncia' =>
                    'Não medir a linha por faturamento.',
            ],
        ],
    ],
];

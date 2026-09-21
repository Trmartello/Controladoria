<?php

/**
 * Conteúdo da carga da Análise de Cenário de 2027.
 *
 * FONTE: o dossiê do planejamento corporativo gerado pelo próprio sistema em
 * 04/09/2026 (Ciclo 2027–2035, diagnóstico de 2027) — o que a cooperativa
 * escreveu nas oficinas, não análise nova. Existe para REPOR o diagnóstico de
 * 2027 num banco que o perdeu: instalação nova, ou restauração de um backup
 * anterior ao trabalho.
 *
 * Como toda carga de conteúdo, é aplicada uma vez por `chave` e marcada em
 * `carga_conteudo` — ver `conteudo_cenario_macro.php` para o porquê da marca e
 * da chave nova a cada revisão. Diferente das cargas de análise macro, esta
 * **não entra na lista automática do migrate**: ela repõe conteúdo do cliente,
 * e um deploy não deve repovoar diagnóstico sem alguém pedir. O caminho é a
 * CLI (`cli/carga_diagnostico.php`).
 */

return [
    'chave' => 'cenario_2027_dossie_2026_09_04',
    'destino' => 'CENARIO',
    'ano' => 2027,

    'itens' => [
        'SITUACAO_ATUAL' => [
            'Ciclo de corte em curso, ainda em terreno restritivo: o Copom cortou a Selic '
                . 'para 14% em 05/08/2026 — quarto corte seguido desde março, quando ela saiu '
                . 'dos 15% mantidos desde junho de 2025 — e o mercado espera mais 0,25 p.p. em '
                . '16/09. O Focus de 31/08/2026 projeta 13,75% no fim de 2026 e 12% em 2027, '
                . 'IPCA de 5,01% (ainda acima do teto da meta), dólar a R$ 5,20 e PIB de 1,92%. '
                . 'Capital de giro e investimento seguem caros, e o associado chega à '
                . 'cooperativa endividado a juro de mercado.',

            'Endividamento dos cooperados e clientes em geral. Dificuldades de acesso ao '
                . 'crédito',

            'Exportação e consumo, com incertezas do mercado consumidor. Exige análise e '
                . 'cautela, considerando mudanças governamentais e econômicas',

            'Grande número de famílias endividadas com comprometimento de grande parte da '
                . 'renda para pagamento da própria dívida e do custo (juros) dessa dívida. '
                . 'Tendência de aumento da inadimplência decorrente da limitação da própria '
                . 'capacidade de pagamento.',

            'Impacto da alteração das escalas de trabalho nas empresas (6x1). O que fazer '
                . 'para as pessoas produzirem / entregarem mais? Impacto da tecnologia X baixa '
                . 'qualificação da mão de obra. DESAFIO MAIOR: AUMENTAR PRODUTIVIDADE NA '
                . 'COOPERATIVA.',

            'Reforma tributária entrará em janeiro. a regulamentação do IBS/CBS com '
                . 'regime específico e OPTATIVO para o ato cooperativo (alíquota zero nas '
                . 'operações entre associado e cooperativa), opção formalizada no ano anterior '
                . 'ao de vigência, e transição de 2026 a 2032.',

            'No curto prazo, avaliar condições de melhoria de produtividade, sem investir '
                . 'em novas estruturas, dados os indicadores inflacionarios e crescimento do '
                . 'país. DESAFIO DE MELHORAR A EFICIENCIA OPERACIONAL NAS UNIDADES EXISTENTES E '
                . 'PRODUTIVIDADE DA MAO DE OBRA.',

            'Como esta a nossa estrutura de apoio , é necessário estar e ser deste '
                . 'tamanho ou temos que reavaliar? Exemplo: serviços de malote, digitalização, '
                . 'melhoria de processos. Otimizar TI e IA para proporcionar maior segurança '
                . 'nos processos operacionais.',

            'Burocracia interna travando a eficiência nos processos. Buscar automatização '
                . 'dos processos, visando agilidade e otimização das equipes de trabalho. Focar '
                . 'melhorias nas filiais, não apenas na Matriz. ESTAMOS ATRASADOS NESTES '
                . 'PROCESSOS? Necessidade de melhorar.',

        ],
        'TENDENCIA' => [
            'Crescimento do faturamento da Cooperativa, porem, com menores margens em '
                . 'alguns segmentos (Supermercado).',

            'Entrada de novos concorrentes, com maior agressividade, afetando faturamento '
                . 'e resultados da Copérdia.',

            'Comprometimento dos investimentos realizados pela Copérdia, frente a '
                . 'perspectiva de estabilidade de faturamento nos próximos anos.',

            'Impacto da reforma tributária nos resultados da Copérdia. Pagamento '
                . 'antecipado de impostos fora do caixa da Cooperativa. Energia mais cara e '
                . 'Plano safra com juros mais elevados.',

            'Expectativa de crescimento da economia, fortalecendo o capitalismo, em caso '
                . 'de vitória da oposição.',

            'Desafiar a equipe em redução de custos',

            'Janeiro e fevereiro de 2027 com expectativas de aumento nos custo na '
                . 'produção e redução de faturamento e resultados na atividade de suinocultura '
                . 'e aves.',

        ],
    ],
];

<?php

/**
 * Conteúdo da carga do Porter de 2027.
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
    'chave' => 'porter_2027_dossie_2026_09_04',
    'destino' => 'FATOR',
    'etapa' => 'PORTER',
    'ano' => 2027,

    'itens' => [
        'RIVALIDADE' => [
            'Encontrar alternativas para que o produtor perceba que a Copérdia realmente '
                . 'atenda as suas necessidades. Copérdia entrega produto, servicos, segurança e '
                . 'confiabilidade. COMPROMISSO DE ENTREGAR O QUE O PRODUTOR PRECISA. Buscar '
                . 'estratégias para Supermercado, Agropecuaria e Postos. Ter política comercial '
                . 'diferenciada para Cliente comercial X Cliente Produtor Integrado',

            'Disputa pelo mercado de grãos pela concorrencia. Copérdia não tem capacidade '
                . 'de industrialização, o que as vezes pode dificultar as operações, '
                . 'eventualmente.',

            'Copérdia com limitação nas estruturas de recebimento de grãos. Necessidade '
                . 'de maior e melhor organização interna.',

            'Melhorar estruturas de recebimento de cereais e vincular venda de insumos ao '
                . 'recebimento de cereais.',

            'Encontrar alternativas de valorizar mais o fomento. Classificar bem para '
                . 'quem dar crédito e trabalhar com estratégias de fidelização com o associado.',

        ],
        'NOVOS_ENTRANTES' => [
            'Disputa de grãos e comercialização com outras cooperativas. Criar mecanismos '
                . 'para melhor atuação frente Coopercampos e Alfa.',

            'Região de atuação. Alfa, Copercampos atuando em regiões da Copérdia. Como '
                . 'blindar as nossas Regiões?',

        ],
        'SUBSTITUTOS' => [
            'Revisão do mix de produtos, de acordo com o perfil de publico.',

            'Marketplace e e-commerce agropecuário "substituem" complementam a loja '
                . 'física na compra de insumo, principalmente entre os produtores mais jovens. '
                . 'Para Lojas Agropecuárias, Mercado. SEPARAR INTEGRADOS DOS OUTROS CLIENTES. '
                . 'Oportunidade de fazer trabalho diferenciado com o cliente. Pode até não '
                . 'precisar estrutura física.',

            'Fertilizante e defensivo importados, com oferta concentrada e exposta à '
                . 'geopolítica',

        ],
        'PODER_FORNECEDORES' => [
            'Grandes fornecedores definem as regras e preços para a Copérdia. De que '
                . 'forma melhorar esta relação?',

            'Qual a representatividade de vendas por fornecedor e de que forma isso pode '
                . 'fortalecer a venda a prazo safra? (Mapeamento pela Controladoria). TER '
                . 'TRATAMENTO DIFERENCIADO PARA CLIENTES, conforme seu perfil de relacionamento '
                . 'com a Copérdia.',

            'Custo logístico é imposto pela oferta de transporte, não negociado.',

        ],
        'PODER_CLIENTES' => [
            'Como fidelizar nossos clientes? (por negócio?) Como entregar um produto '
                . 'melhor? Como melhor nossos servicos? Como protegemos nossos clientes dos '
                . 'concorrentes?',

            'BLINDAR os nossos integrados e fidelizá-los na Cooperativa.',

            'Rever estratégias de atendimento e comissionamento com vendedores. Criar '
                . 'nova mentalidade para o produtor dele ser cliente da Copérdia e valorizar o '
                . 'que ela faz ao produtor. FIDELIZAR.',

            'Implantar CONSULTORIA PERSONALIZADA ao produtor.',

            'Sempre focar em buscar formas de proteção ao Crédito seguras, via Comitê, '
                . 'com atualização e adequação de critérios.',

        ],
    ],
];

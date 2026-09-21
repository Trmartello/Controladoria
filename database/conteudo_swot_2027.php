<?php

/**
 * Conteúdo da carga da SWOT de 2027.
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
 * Só FORÇAS e FRAQUEZAS: o dossiê mostra Oportunidades e Ameaças zeradas — e,
 * ainda assim, a cascata cita uma oportunidade ao fundamentar uma escolha.
 * O lado externo não é recuperável de um relatório que o imprime vazio, e
 * inventá-lo seria pior do que deixar a tela pedindo o preenchimento.
 *
 * Entram SOLTOS, sem `promovido_de_id`, como a carga macro: promover é o gesto
 * de quem conduz a análise.
 */

return [
    'chave' => 'swot_2027_dossie_2026_09_04',
    'destino' => 'FATOR',
    'etapa' => 'SWOT',
    'ano' => 2027,

    'itens' => [
        'FORCA' => [
            'Capacitação / qualificação das equipes de campo.',

            'Segregação da equipe de vendas / assistencia técnica. Proporcionou maior '
                . 'foco nos negócios.',

            'Contrato firmado entre produtor e Cooperativa nas atividades de fomento. '
                . '(Leite, Suinos e Aves)',

        ],
        'FRAQUEZA' => [
            'Processos burocráticos afetando a eficiencia dos processos.',

            'Relacionamento com o produtor de cereais da Copérdia não é formalizado entre '
                . 'as partes.',

            'Baixa capacidade de geração de caixa.',

            'Necessidade de ter a cultura de resultados para a Copérdia, não apenas do '
                . 'Negócio / Setor. Maior sinergia e integração entre os negócios (Cultura de '
                . 'pessoas)',

            'Exposição concentrada na suinocultura justamente no momento em que o preço '
                . 'está abaixo do custo: a margem do negócio principal depende de uma variável '
                . 'que a cooperativa não controla.',

            'Necessidade de conhecer, na Suinocultura, o resultado de cada ciclo de '
                . 'produção para definição se algo pode ser direcionado a Aurora ou não.',

            'Instalações de Supermercados "defasados" necessitando de revitalização. '
                . '(Padrão de Loja, adequado a realidade de cada região)',

        ],
    ],
];

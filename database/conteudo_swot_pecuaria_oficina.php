<?php

/**
 * SWOT do negócio PECUÁRIA escrita pelos GERENTES DE NEGÓCIO, 2027.
 *
 * FONTE: a aba `SWOT` da planilha "SUINOS - REUNIÃO PRÉVIA COM GERENTES DE
 * NEGÓCIO - PLAN 2026", enviada pelo cliente em 2026-11-12. É conteúdo de
 * OFICINA — o que a equipe do negócio escreveu —, e não análise montada sobre
 * a DRE. Convive de propósito com `conteudo_swot_pecuaria_2027.php`, que é a
 * leitura financeira do mesmo negócio: as duas se completam, e a sobreposição
 * em sanidade e em juro é sinal de convergência, não de erro.
 *
 * ANO 2027, e não 2026 como diz o cabeçalho da planilha. Não é descuido: o
 * ciclo cadastrado é 2027–2035, e `planosDoNegocio` procura ciclo cujo
 * intervalo cubra o ano da carga. Com 2026 nenhum ciclo casaria, a carga
 * gravaria zero registro e seria marcada como aplicada — o desfecho que o
 * próprio serviço avisa ser o pior, porque não há erro em lugar nenhum e o
 * texto simplesmente nunca aparece. 2027 é também onde está a SWOT que o
 * cliente tem na tela.
 *
 * DUAS EDIÇÕES no texto da planilha, e nenhuma além destas:
 *
 * 1. **A numeração do início de cada item saiu** ("1 - ", "2- "). A coluna já
 *    tem cinco itens da carga anterior, e a carga acrescenta ao FIM da ordem —
 *    um item numerado "1 -" apareceria na sexta posição, brigando com a ordem
 *    real da tela.
 * 2. **Erros de digitação evidentes corrigidos** (Politica, previsibildiade,
 *    orgãos, suino, Upls). A redação é a da equipe; só a grafia mudou.
 *
 * Nada mais foi reescrito: item que parece repetir o que já existe entra assim
 * mesmo. Quem conduz o planejamento funde, corta ou reescreve na tela — a
 * carga não tem o contexto da oficina para decidir isso por ela.
 *
 * Como toda carga de conteúdo, é aplicada uma vez por `chave` e marcada em
 * `carga_conteudo` — ver `conteudo_cenario_macro.php` para o porquê da marca e
 * da chave nova a cada revisão. Só INSERE, e compara pelo texto normalizado
 * dentro da própria etapa: o que já está na SWOT não entra de novo, e o que
 * alguém apagar depois não volta no deploy seguinte.
 */

return [
    'chave' => 'swot_pecuaria_oficina_suinos_2026_11_12',
    'destino' => 'FATOR',
    'etapa' => 'SWOT',
    'negocio' => '2',
    'ano' => 2027,

    'itens' => [
        'FORCA' => [
            'Gestão da atividade no conceito do programa + 1 (P+1).',

            'Investimento de produtores em ampliações de plantel nas UPDs.',

            'Melhor sanidade do plantel apesar de desafios sanitários diários.',

            'Mais de R$ 3,0 milhões pagos com os prêmios de eficiência técnica '
                . 'nas terminações.',

            'Equipe engajada na busca de melhores resultados.',
        ],

        'FRAQUEZA' => [
            'Inexistência de produção própria de leitões UPD e/ou falta de incentivo '
                . 'para novos investimentos.',

            'Falta de leitões e, consequentemente, falta de 153.000 suínos gordos '
                . '(intervalos).',

            'Em 10 anos pelo menos 10.000 matrizes irão parar de produzir.',

            'Política de preços de insumos (medicamentos, equipamentos, etc.).',

            'Para 2026 teremos uma falta de 153.000 cb/abate. Desembolso previsto de '
                . 'aquisição de suínos gordos de R$ 17,3 milhões.',
        ],

        'OPORTUNIDADE' => [
            'Ampliação da atividade de 4º sítio; hoje responsável por atender 70% das '
                . 'reposições de plantel; meta: chegar a 90%.',

            'Implantação de programa de remuneração variável para as creches e UPLs '
                . 'por estrutura e resultados.',

            'Buscar novas regiões para implantação da atividade de suinocultura (UPD).',

            'Desenvolver projeto de incentivo para implantação de novas granjas de '
                . 'UPLs conforme padrão Aurora, com garantia mínima de rentabilidade ao '
                . 'investidor, dando segurança e previsibilidade no negócio.',

            'Migração do ciclo de creche Aurora para Copérdia.',
        ],

        'AMEACA' => [
            'Problemas sanitários de notificação obrigatória (PRRS, peste suína '
                . 'africana, entre outras); PRRSV (vírus da síndrome respiratória e '
                . 'reprodutiva suína); novos genótipos de circovírus suíno; novas cepas '
                . 'de influenza.',

            'Insegurança econômica do País; perda de algum mercado comprador.',

            'Uso de antimicrobianos; proibição e redução de alguns aditivos.',

            'Auditorias de validação da produção, com critérios diferentes entre os '
                . 'órgãos oficiais e a Aurora.',

            'Saída de produtores do ciclo de UPD com melhores propostas financeiras '
                . 'no leitão.',

            'Financiamentos com juros altos, inviabilizando investimentos.',
        ],
    ],
];

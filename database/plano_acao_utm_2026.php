<?php

/**
 * Plano de ação da UTM (negócio 7), pedido do cliente de 2026-11-19: os quatro
 * projetos da planilha do negócio viram ações de um projeto, e um projeto novo
 * de automação do parque fabril.
 *
 * Mesmas decisões da carga da Fábrica de Rações: projeto no ano 2027 (H1), o
 * primeiro ano que o plano de ação aceita; as ações mantêm os prazos de 2026
 * (mês da planilha → último dia do mês); o acompanhamento da planilha vai como
 * comentário. Status pela planilha:
 *  - ergonomia: "ainda não avaliamos" → em aberto, prazo vencido → ATRASADO;
 *  - maravalha e serragem: análise feita (inviável) → CONCLUIDO no prazo;
 *  - postes tratados: demanda avaliada (mercado restrito) → CONCLUIDO no prazo;
 *  - reajuste de 25%: "realizado no mês de mar/26" → CONCLUIDO em 31/03/2026.
 * Automação: responsável Ricardo, prazo dez/2027 (escolha do cliente).
 */

return [
    'chave' => 'plano_acao_utm_2026',
    'rotulo' => 'UTM, plano de ação 2026 e automação',
    'negocio' => '7',
    'ano' => 2027,
    'projetos' => [
        [
            'titulo' => 'Plano Operacional 2026',
            'descricao' => 'Ações do plano operacional de 2026 da UTM (planilha do negócio).',
            'responsavel' => 'Ricardo',
            'iniciativa' => 'Estudos e ajustes do negócio',
            'acoes' => [
                [
                    'o_que' => 'Estudar formas de adequações da ergonomia de trabalho',
                    'quem' => 'Ricardo',
                    'prazo' => '2026-06-30',
                    'status' => 'ATRASADO',
                    'comentario' => 'Esse projeto ainda não avaliamos.',
                ],
                [
                    'o_que' => 'Analisar viabilidade técnica e econômica de instalação de produção de maravalha e serragem',
                    'quem' => 'Ricardo',
                    'prazo' => '2026-06-30',
                    'status' => 'CONCLUIDO',
                    'comentario' => 'Somente há viabilidade em produzir serragem e maravalha de Pinus americano. '
                        . 'De eucalipto é inviável economicamente e tecnicamente. Não há na região disponibilidade '
                        . 'de MP, ou florestas de pinus. Também os resíduos gerados na UTM, do processo de interno '
                        . 'de tratamento, é baixo volume, não viabilizando a instalação de maquinas para produção '
                        . 'de maravalha.',
                ],
                [
                    'o_que' => 'Avaliar demanda de postes tratados para rede elétrica',
                    'quem' => 'Ricardo',
                    'prazo' => '2026-07-31',
                    'status' => 'CONCLUIDO',
                    'comentario' => 'Conversamos com uma empresa que é homologada na Celesc, para instalação de '
                        . 'redes de energia elétrica. Segundo essa empresa, não chega a 1% o uso de postes de '
                        . 'madeira de tratamento. Somente é usado ainda postes de madeira em alguns locais de '
                        . 'difícil acesso. Portanto esse mercado e muito restrito.',
                ],
                [
                    'o_que' => 'Reajustar tabela de preços em 25% para todos os produtos',
                    'quem' => 'Ricardo',
                    'prazo' => '2026-01-31',
                    'status' => 'CONCLUIDO',
                    'concluido_em' => '2026-03-31',
                    'comentario' => 'Foi realizado esse reajuste no mês de mar/26',
                ],
            ],
        ],
        [
            'titulo' => 'Automação do Parque Fabril',
            'descricao' => 'Automatizar o parque fabril da UTM com equipamentos de descascamento e de '
                . 'classificação de matéria-prima.',
            'responsavel' => 'Ricardo',
            'iniciativa' => 'Automatizar o parque fabril com equipamentos de descascamento e classificação '
                . 'de matéria-prima para a UTM',
            'acoes' => [
                [
                    'o_que' => 'Implantar equipamentos de descascamento e de classificação de matéria-prima '
                        . 'no parque fabril da UTM',
                    'quem' => 'Ricardo',
                    'prazo' => '2027-12-31',
                    'status' => 'NAO_INICIADO',
                ],
            ],
        ],
    ],
];

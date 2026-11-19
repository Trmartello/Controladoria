<?php

/**
 * Plano operacional de 2026 da Fábrica de Rações (negócio 6), levado ao plano
 * de ação a pedido do cliente (2026-11-19), a partir da planilha "PLANO
 * OPERACIONAL PARA 2026" do negócio: três instalações de equipamento, todas com
 * o Ricardo.
 *
 * Decisões do cliente: projeto "Plano Operacional 2026" gravado no ANO 2027
 * (H1). O plano de ação só aceita anos dos horizontes, e o migrate move para o
 * primeiro ano de execução todo projeto anterior a ele; o cliente preferiu
 * seguir a regra a mudá-la. As ações mantêm as datas de 2026. Uma frente só,
 * "Modernização da fábrica".
 *
 * Cada ação é uma lista de datas com uma linha (o padrão da tela): o prazo da
 * planilha é o fim do período. A coluna de acompanhamento da planilha vai como
 * comentário da ação, sem autor (é registro da planilha, não de um usuário).
 * Status: a peneira está instalada (concluída na data do prazo, a única data que
 * a planilha dá); as outras duas passaram do prazo sem conclusão e ficam
 * ATRASADO — a mesma regra que a tela aplica (`resolverStatus`).
 */

return [
    'chave' => 'plano_operacional_racoes_2026',
    'negocio' => '6',
    'ano' => 2027,
    'projeto' => [
        'titulo' => 'Plano Operacional 2026',
        'descricao' => 'Ações do plano operacional de 2026 da Fábrica de Rações (planilha do negócio).',
        'responsavel' => 'Ricardo',
    ],
    'iniciativa' => 'Modernização da fábrica',
    'acoes' => [
        [
            'o_que' => 'Instalação de ensacadeira com braço mecânico',
            'quem' => 'Ricardo',
            'prazo' => '2026-02-01',
            'status' => 'ATRASADO',
            'comentario' => 'O equipamento está comprado, obra contratada, porém a empresa atrasou a entrega. '
                . 'Está previsto o início da instalação para jun/26. Vamos atrasar o pagamento para a '
                . 'empresa, o mesmo tempo que estão atrasando a entrega.',
        ],
        [
            'o_que' => 'Instalação da peneira rotativa para finos',
            'quem' => 'Ricardo',
            'prazo' => '2026-03-01',
            'status' => 'CONCLUIDO',
            'comentario' => 'Equipamento instalado, conforme relatado no proj. Estrat.',
        ],
        [
            'o_que' => 'Instalação do sistema de injeção de emulsificante líquido',
            'quem' => 'Ricardo',
            'prazo' => '2026-06-01',
            'status' => 'ATRASADO',
            'comentario' => 'Equipamento sendo instalado essa semana, conforme relatado no proj. Estrat.',
        ],
    ],
];

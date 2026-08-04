<?php

/**
 * Conteúdo da carga da análise de Porter (as cinco forças do diagnóstico).
 *
 * FONTE ÚNICA: lida pelo passo do `database/migrate.php` (que aplica no deploy)
 * e por `cli/carga_diagnostico.php`. A regra de aplicar é de
 * App\Services\CargaConteudo — ver `conteudo_cenario_macro.php` para o porquê
 * da marca em `carga_conteudo` e da chave nova a cada revisão.
 *
 * As categorias são as de FatorController::CATEGORIAS['PORTER'] — categoria
 * fora dessa lista entra no banco mas não aparece em coluna nenhuma.
 *
 * O conteúdo é uma FOTOGRAFIA de agosto/2026 (fontes no fim do arquivo).
 */

return [
    'chave' => 'porter_macro_2026_08',
    'destino' => 'FATOR',
    'etapa' => 'PORTER',
    'ano' => 2026,

    'itens' => [
        'RIVALIDADE' => [
            'O Oeste catarinense concentra a maior densidade de agroindústria de proteína do '
            . 'país: a disputa por associado, por grão e por mão de obra acontece dentro do '
            . 'mesmo raio de cem quilômetros, com integradoras de porte nacional na mesma '
            . 'praça.',

            'A consolidação eleva a escala mínima do setor: a fusão que criou a MBRF '
            . '(Marfrig e BRF, setembro de 2025) juntou marcas líderes em aves, suínos e '
            . 'processados, e o poder de compra e de marca do concorrente cresceu sem que a '
            . 'cooperativa tenha crescido junto.',

            'Com o suíno vivo abaixo do custo, a rivalidade deixa de ser por preço e passa a '
            . 'ser por eficiência: quem tem melhor conversão alimentar, sanidade e ocupação de '
            . 'planta atravessa o vale do ciclo; quem não tem, deixa a atividade.',

            'Disputa por matéria-prima no leite: na entressafra as indústrias vizinhas elevam o '
            . 'preço pago ao produtor sem conseguir repassar ao consumidor, e a margem se perde '
            . 'no meio da cadeia.',

            'No varejo, supermercados e lojas agropecuárias competem com redes regionais e com '
            . 'o atacarejo pelo mesmo consumidor, que hoje decide por preço e promoção, e não '
            . 'por vínculo com a cooperativa.',
        ],

        'NOVOS_ENTRANTES' => [
            'Barreira alta na indústria de proteína: capital, licenciamento ambiental e '
            . 'habilitação sanitária por país de destino tornam improvável um entrante novo em '
            . 'abate e processamento.',

            'Barreira baixa no varejo e na loja agropecuária: atacarejo e redes de insumo entram '
            . 'na região com capital próprio, sem precisar de ativo industrial nem de quadro '
            . 'social.',

            'Tradings e revendas compram grão direto do produtor e vendem insumo via barter, '
            . 'entrando na relação com o associado sem fábrica, sem armazém e sem a obrigação de '
            . 'atendê-lo nos anos ruins.',

            'Cooperativas de crédito e fintechs ampliam o financiamento ao produtor — o crédito '
            . 'era um dos vínculos que seguravam o associado, e deixou de ser exclusivo.',

            'Geração distribuída de terceiros oferece desconto de energia direto ao produtor, '
            . 'concorrendo com um serviço que a cooperativa passou a prestar com a usina '
            . 'fotovoltaica.',
        ],

        'SUBSTITUTOS' => [
            'Entre proteínas a substituição é imediata: com IPCA acima do teto e renda apertada, '
            . 'o consumidor troca suíno por frango e por ovo dentro do mesmo mês, sem qualquer '
            . 'fidelidade de marca.',

            'Bebidas vegetais e lácteos reconstituídos concorrem com o leite fluido, e a '
            . 'importação de leite em pó cumpre o mesmo papel no produto industrializado.',

            'Marketplace e e-commerce agropecuário substituem a loja física na compra de insumo, '
            . 'principalmente entre os produtores mais jovens.',

            'O barter com trading substitui, numa operação só, o crédito e o insumo da '
            . 'cooperativa — e leva junto a comercialização do grão que sustentaria a receita '
            . 'de cereais.',

            'Energia própria na propriedade substitui a compra de energia e reduz mais um ponto '
            . 'de contato entre o associado e a cooperativa.',
        ],

        'PODER_FORNECEDORES' => [
            'Genética, sanidade animal e aditivos concentrados em poucas multinacionais e com '
            . 'preço em dólar: a R$ 5,20 o câmbio define o custo antes de qualquer negociação '
            . 'comercial.',

            'Fertilizante e defensivo importados, com oferta concentrada e exposta à '
            . 'geopolítica — em ano de tensão comercial, o prazo de entrega pesa tanto quanto o '
            . 'preço.',

            'Em milho e soja o fornecedor é o próprio associado: o poder de barganha se dilui, '
            . 'mas a cooperativa fica presa ao preço de mercado nas duas pontas da mesma '
            . 'operação.',

            'Energia elétrica e combustível têm preço indexado e não há fornecedor alternativo '
            . 'real: o custo entra na indústria e na frota sem espaço de negociação.',

            'Frete rodoviário escasso e caro no Sul, com falta de motorista: o custo logístico é '
            . 'imposto pela oferta de transporte, não negociado.',
        ],

        'PODER_CLIENTES' => [
            'Grandes redes varejistas concentram a compra de proteína e ditam prazo, preço e '
            . 'nível de serviço; perder espaço de gôndola custa mais caro do que aceitar a '
            . 'margem menor.',

            'Importadores e a política comercial do país de destino mudam a regra '
            . 'unilateralmente — cota, sobretaxa e habilitação de planta são decisões do '
            . 'cliente, não do fornecedor.',

            'O associado é cliente e dono ao mesmo tempo: pode levar a produção para outra '
            . 'integradora, o que limita o repasse de custo e transforma retenção do quadro '
            . 'social em pauta permanente de conselho.',

            'No supermercado o custo de troca do consumidor é zero: com inflação acima do teto, '
            . 'ele decide por preço, promoção e marca própria, e volta ao concorrente na semana '
            . 'seguinte.',

            'Compradores externos exigem certificação, bem-estar animal e inventário de emissões '
            . 'como condição de compra: é requisito de cliente que vira investimento '
            . 'obrigatório, sem prêmio de preço.',
        ],
    ],
];

/*
 * Fontes da fotografia de agosto/2026:
 * - Fusão Marfrig + BRF na MBRF Global Foods, concluída em setembro de 2025.
 * - Cotações de suíno vivo em SC de 24/07/2026 e custo do ICPSuíno/Embrapa
 *   (R$ 6,21/kg vivo em junho), que sustentam a leitura de rivalidade por
 *   eficiência.
 * - Cepea e Epagri/Cepa sobre a disputa por matéria-prima no leite e a
 *   importação de lácteos.
 * - Boletim Focus de 03/08/2026 (IPCA 5,03%; dólar R$ 5,20) para a substituição
 *   entre proteínas e o poder do consumidor.
 * - Cotas e sobretaxas da China e tarifas dos EUA sobre a proteína brasileira,
 *   noticiadas ao longo do 1º semestre de 2026.
 */

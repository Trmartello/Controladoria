<?php

/**
 * Metas MENSAIS de 2027 do negócio AGROPECUÁRIA.
 *
 * FONTE: a planilha "Metas Agropecuária 2027", enviada pelo cliente em
 * 2026-11-13. Cada bloco dela traz duas linhas de doze meses — uma base e a
 * meta — e um fator meta÷base. **Só a linha de META entra aqui**: a base não
 * diz a que ano pertence nem se é realizado ou projeção, e lançar projeção como
 * realizado estragaria o percentual de atingimento da tela.
 *
 * Conferência antes de montar o arquivo: nos 15 blocos com dados, a soma dos
 * doze meses bate com o total da planilha e o fator declarado bate com
 * meta÷base. O 16º bloco, "Ferragens Marca Copérdia", está VAZIO na planilha —
 * e na tela ele já tem meta de mai a dez somando 720.000 Unidades. Por isso ele
 * não está aqui: não há o que gravar, e a carga não apagaria aquilo de todo
 * jeito.
 *
 * OS VALORES ENTRAM COMO ESTÃO NA PLANILHA, sem conversão de unidade (decisão
 * do cliente, 2026-11-13: "preencha como está na planilha"). Isso importa
 * porque os indicadores NÃO compartilham unidade: há "Reais", "R$ mil", "Ton" e
 * "Unidades" no cadastro. Num indicador de "R$ mil", o número cheio da planilha
 * aparece mil vezes maior — foi dito ao cliente e é a escolha dele. Quem quiser
 * desfazer mexe na unidade do indicador, no cadastro, não aqui.
 *
 * OS NOMES SÃO OS DA TELA, não os da planilha, e três divergem (marcados item a
 * item). A resolução é por nome normalizado — acento, caixa e espaço não
 * contam —, mas "Rações Suinos" e "Ração Suinos" diferem no radical, e nenhuma
 * normalização salvaria isso. Indicador que não for encontrado é REPORTADO pelo
 * migrate e não é criado: um segundo indicador quase igual ao que já existe é
 * pior que uma lacuna, porque só se descobre olhando a lista inteira.
 *
 * ESTA CARGA SOBRESCREVE o que já estiver lançado, ao contrário de todas as
 * outras (pedido do cliente: "atualize caso já tenha algo preenchido"). A marca
 * em `carga_conteudo` continua valendo e é o que impede o deploy seguinte de
 * desfazer uma correção feita à mão depois dela. Revisar as metas exige chave
 * NOVA; a antiga fica marcada e nunca mais é reaplicada.
 *
 * `negocio` é o `cod_negocio` do cadastro — ver `CargaConteudo::planosDaCarga`.
 * É o que faz esta carga cair no planejamento da Agropecuária.
 */

return [
    'chave' => 'metas_agropecuaria_2027_planilha_2026_11_13',
    'destino' => 'INDICADOR',
    'negocio' => '8',
    'ano' => 2027,

    // nome do indicador => os doze meses, de janeiro a dezembro
    'itens' => [
        'Faturamento Nutrição animal' => [
            6438674.01, 6658678.33, 7698038.71, 6386907.04, 6913717.36, 7462421.28,
            7600791.76, 6255290.44, 7570273.59, 8435248.01, 5870764.11, 6592031.47,
        ],
        'Faturamento Veterinária' => [  // planilha: "Faturamento Veterinaria"
            6425920.13, 6404385.59, 7192226.78, 7088094.00, 7094569.72, 6395789.66,
            6646654.54, 5825599.49, 6548337.11, 6800696.49, 6022294.99, 6471000.38,
        ],
        'Fertilizantes Foliar' => [
            2273609.20, 1024959.67, 353919.35, 156809.09, 65055.42, 117089.80,
            240370.82, 487476.57, 731160.98, 1377235.50, 1841269.17, 2841996.54,
        ],
        'Ração Suinos' => [  // planilha: "Rações Suinos"
            1388688.00, 1338141.00, 1545216.75, 1515900.75, 1424010.00, 1649951.85,
            1646388.05, 1429531.85, 1702265.55, 1672138.05, 1484211.75, 1484211.75,
        ],
        'Venda de Calcareo' => [
            310401.32, 284056.50, 1653487.50, 6502398.00, 5874702.75, 7594408.84,
            5312627.25, 4400760.00, 1798912.50, 674322.60, 619756.15, 265467.05,
        ],
        'Venda de Defensivos Agrícola' => [  // planilha: "Venda de Defensivos Agrícolas"
            22316896.11, 12596457.90, 7321698.61, 5198790.17, 3885936.86,
            3804180.47, 7469424.85, 16348530.49, 23240819.06, 23617160.42,
            19244272.22, 27026110.15,
        ],
        'Venda de Ferragens' => [
            7833650.36, 7125770.83, 7063835.38, 7423480.54, 8319295.35, 8045066.03,
            7186604.89, 8017499.94, 9399315.81, 10583686.73, 6732062.27, 6595111.49,
        ],
        'Venda de Fertilizantes (Exceto Foliar)' => [
            3062403.75, 2652431.25, 2794758.75, 1919007.30, 2369928.75, 4101363.50,
            8085749.25, 13881511.25, 16708665.25, 18123918.75, 12401371.50,
            5116807.50,
        ],
        'Venda de Semente de Soja' => [
            18322.50, 8022.00, 84.00, 0.00, 0.00, 0.00, 42000.00, 231281.40,
            276008.10, 1026154.20, 315848.20, 57303.60,
        ],
        'Venda de Semente de Forrageiras' => [
            50094.05, 225394.90, 906783.28, 779978.85, 367700.73, 118705.44,
            17439.96, 3790.40, 58179.55, 131021.15, 111852.85, 64385.30,
        ],
        'Venda Distribuidora' => [
            650000.00, 550000.00, 400000.00, 450000.00, 400000.00, 500000.00,
            650000.00, 950000.00, 1050000.00, 1050000.00, 850000.00, 650000.00,
        ],
        'Venda Semente de Trigo' => [
            0.00, 0.00, 71736.00, 141498.00, 335286.00, 367269.00, 121527.00, 0.00,
            0.00, 0.00, 0.00, 0.00,
        ],
        'Venda Sementes de Milho' => [
            66880.00, 36340.00, 4960.00, 80.00, 4820.00, 33800.00, 181111.60,
            529978.80, 401591.60, 153784.80, 63960.40, 32711.60,
        ],
        'Volume de Farelo Soja' => [
            6113319.50, 5913892.25, 6345403.30, 5470106.09, 6597438.10, 6443753.80,
            7006419.92, 7026764.90, 7367692.50, 7248548.70, 6345403.30, 6345403.30,
        ],
        'Volume de Rações Coperdia Bovinos' => [
            4457175.75, 4287272.00, 4333287.38, 4373336.50, 4654733.88, 5025582.00,
            5110668.25, 4911992.12, 5312375.88, 5242355.75, 4827223.50, 5287920.00,
        ],    ],
];

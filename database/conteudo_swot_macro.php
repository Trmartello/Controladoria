<?php

/**
 * Conteúdo da carga da análise SWOT.
 *
 * FONTE ÚNICA: lida pelo passo do `database/migrate.php` (que aplica no deploy)
 * e por `cli/carga_diagnostico.php`. A regra de aplicar é de
 * App\Services\CargaConteudo — ver `conteudo_cenario_macro.php` para o porquê
 * da marca em `carga_conteudo` e da chave nova a cada revisão.
 *
 * Estes fatores entram SOLTOS, sem `promovido_de_id`. Promover é o gesto de
 * quem conduz a análise: escolher qual fator do PESTEL ou do Porter merece o
 * quadrante, e em qual. Uma carga que promovesse sozinha decidiria isso pelo
 * usuário e ainda apagaria a possibilidade — o botão "→ SWOT" some quando o
 * fator já foi promovido. Por isso Oportunidades e Ameaças aqui estão no nível
 * da DECISÃO da cooperativa ("o que fazemos a respeito"), e não repetem o
 * fator externo do PESTEL/Porter, que descreve o ambiente.
 *
 * Forças e Fraquezas são leitura do que é visível de fora — portfólio de
 * negócios, estrutura da cadeia, exposição de mercado. São HIPÓTESES para o
 * grupo validar ou derrubar com o dado interno, não medição.
 *
 * O conteúdo é uma FOTOGRAFIA de agosto/2026 (fontes no fim do arquivo).
 */

return [
    'chave' => 'swot_macro_2026_08',
    'destino' => 'FATOR',
    'etapa' => 'SWOT',
    'ano' => 2026,

    'itens' => [
        'FORCA' => [
            'Verticalização do grão à proteína dentro de casa: recebimento de cereais, UBS, '
            . 'fábrica de rações e unidades de resfriamento na mesma estrutura — a ração, que é '
            . '72,6% do custo do suíno, é decidida internamente e não comprada no mercado.',

            'Portfólio diversificado entre agro e varejo (cereais, pecuária, leite, rações, '
            . 'agropecuárias, supermercados, postos de combustíveis e fruticultura): quando a '
            . 'proteína entra no vale do ciclo, as outras frentes seguem gerando caixa.',

            'O associado é fornecedor, cliente e dono ao mesmo tempo: isso dá previsibilidade de '
            . 'oferta e fidelidade que nenhuma integradora de capital consegue contratar em '
            . 'mercado.',

            'Presença física consolidada em Concórdia e região — lojas, supermercados, postos e '
            . 'unidades de recebimento —, um ponto de contato que a concorrência digital e as '
            . 'tradings não replicam.',

            'Geração fotovoltaica própria já em operação: parte do custo de energia fica travada '
            . 'enquanto a tarifa pressiona a indústria e a frota dos concorrentes.',

            'Planejamento estratégico estruturado em ciclo plurianual com diagnóstico anual, '
            . 'indicadores e plano de ação acompanhados: a decisão passa a ter base comparável '
            . 'entre as unidades, e não percepção de cada gestor.',
        ],

        'FRAQUEZA' => [
            'Exposição concentrada na suinocultura justamente no momento em que o preço está '
            . 'abaixo do custo: a margem do negócio principal depende de uma variável que a '
            . 'cooperativa não controla.',

            'Escala menor do que a dos grupos nacionais consolidados: desvantagem estrutural no '
            . 'poder de compra de insumo e na negociação com as grandes redes varejistas.',

            'Dependência de insumo importado precificado em dólar (genética, sanidade animal, '
            . 'aditivo e fertilizante) sem hedge natural na parte da receita que vem do mercado '
            . 'interno.',

            'Capital de giro pressionado pelo papel de financiadora do associado, com o juro '
            . 'ainda no topo do ciclo e o barter crescendo — risco de crédito da carteira '
            . 'concentrado na mesma safra que já é o risco do negócio.',

            'Disputa por mão de obra no pleno emprego do Oeste catarinense, com dificuldade de '
            . 'atrair e reter perfil técnico e industrial.',

            'Sucessão rural não equacionada: a base de associados envelhece dentro do horizonte '
            . 'do ciclo e a reposição não está contratada.',
        ],

        'OPORTUNIDADE' => [
            'Optar pelo regime específico do ato cooperativo (IBS e CBS a alíquota zero) na '
            . 'janela de 1º de setembro a 31 de outubro de 2026: decisão com prazo fechado e '
            . 'efeito direto na carga tributária, que não é automática e não se repete.',

            'Tomar funding barato do Plano Safra 2026/27 enquanto o juro de mercado ainda é '
            . 'alto: custeio de cooperativas a 12,5% a.a. e Prodecoop e Procap-Agro a 12%, com '
            . 'limites ampliados para armazenagem e industrialização.',

            'Recompor margem na proteína aproveitando a safra recorde 2026/27, que segura o '
            . 'custo da ração: a janela existe antes de o ciclo do suíno virar e o grão '
            . 'reagir.',

            'Ganhar participação no ajuste do ciclo suíno: com produtores independentes deixando '
            . 'a atividade e integradoras reduzindo alojamento, há espaço para captar produção '
            . 'de quem quer continuar.',

            'Ampliar a geração fotovoltaica própria com o payback encurtado pelo custo de '
            . 'energia — investimento que se paga sem depender do preço da proteína.',

            'Diversificar destino e mix de exportação aproveitando o volume recorde e a '
            . 'reabertura da China para o frango, em vez de disputar volume no mercado interno '
            . 'deprimido.',
        ],

        'AMEACA' => [
            'Suíno vivo abaixo do custo de produção (R$ 4,90 a R$ 4,95/kg contra R$ 6,21/kg): se '
            . 'o preço não reagir, a saída de associados da atividade é o risco imediato, e '
            . 'quem sai não volta no ciclo seguinte.',

            'Um único foco de influenza aviária ou de peste suína no Sul embarga o país inteiro '
            . 'e para a exportação que hoje sustenta o abate — risco de baixa probabilidade e '
            . 'consequência total.',

            'Consolidação dos grandes grupos elevando a escala mínima do setor e o poder de '
            . 'barganha deles com o varejo e com o fornecedor de insumo.',

            'Mudança unilateral de regra pelos importadores (cotas e sobretaxas chinesas, '
            . 'tarifas norte-americanas): preço e destino se deslocam sem aviso e sem relação '
            . 'com a eficiência da cooperativa.',

            'Desintermediação da relação com o associado e com o consumidor: tradings e revendas '
            . 'numa ponta, fintechs e cooperativas de crédito no financiamento, atacarejo na '
            . 'outra — cada uma atacando um vínculo diferente.',

            'Clima extremo no Sul atingindo safra, logística e renda do associado no mesmo '
            . 'ciclo, sem que o seguro rural tenha sido equacionado no Plano Safra 2026/27.',
        ],
    ],
];

/*
 * Fontes da fotografia de agosto/2026:
 * - Forças e Fraquezas: leitura das linhas de negócio da cooperativa (a lista de
 *   QlikSync::NEGOCIOS_FONTE) e da exposição de mercado descrita nas cargas de
 *   cenário, PESTEL e Porter. São hipóteses para validação interna.
 * - LC nº 214/2025 (art. 271) e a janela de opção pelo regime específico entre
 *   01/09 e 31/10/2026.
 * - Plano Safra 2026/27: custeio de cooperativas 14% -> 12,5% a.a.; Prodecoop e
 *   Procap-Agro 13,5% -> 12% a.a.; seguro rural sem solução.
 * - Cotações de suíno vivo em SC de 24/07/2026 e custo do ICPSuíno/Embrapa
 *   (R$ 6,21/kg vivo em junho).
 * - Conab e projeções de mercado para a safra 2026/27; ABPA/Secex para o volume
 *   recorde de exportação e a reabertura da China ao frango brasileiro.
 */

<?php

/**
 * Conteúdo da carga do PESTEL de 2027.
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
    'chave' => 'pestel_2027_dossie_2026_09_04',
    'destino' => 'FATOR',
    'etapa' => 'PESTEL',
    'ano' => 2027,

    'itens' => [
        'POLITICO' => [
            'Falta de confiança para liberação de crédito ao produtor.',

            'Necessidades de se adaptar diante dos futuros cenários políticos e '
                . 'econômicos.',

        ],
        'ECONOMICO' => [
            'Impacto das tarifas de importação (americanas, européias) nos insumos '
                . 'brasileiros. Cotas sobre taxas sobre a China Cotas e sobretaxas chinesas '
                . 'sobre a carne brasileira em renegociação diplomática. acesso a mercado '
                . 'depende de acordo entre governos, não de competitividade.',

            'Redução do custeio Plano Safra, pelo governo federal. (produtor terá que '
                . 'buscar recursos em outras instituições). (obs: fator não determinante)',

            'Selic em 14,25% a.a. desde 17/06/2026, com projeção de 13,75% no fim do ano '
                . 'e 12% em 2027.',

            'Instituições com receios de conceder empréstimos (falta de confiança, diante '
                . 'do cenário politico).',

            'Necessidade de discutir quais os investimentos necessários que PRECISAM ser '
                . 'feitos para viabilizar resultados e como MELHORAR A EFICIENCIA nos '
                . 'processos.',

            'FOCAR melhorias operacionais. Buscar EFICIÊNCIA EXTREMA, independente das '
                . 'novidades de mercado. Precisamos ser melhor do que os outros para '
                . 'sobreviver.',

        ],
        'SOCIAL' => [
            'Buscar se adaptar ao modelo de consumo do cliente.',

            'Sucessão rural: envelhecimento do quadro social e saída dos jovens do campo '
                . 'ameaçam a base de associados dentro do horizonte do ciclo, não depois dele.',

            'Acompanhamento da Copérdia junto aos seus cooperados proporciona uma '
                . 'estabilidade quanto a sucessão de produtores fomentados. De forma geral, a '
                . 'sucessão precisa ser trabalhada. Que comportamento a Copérdia poderá '
                . 'utilizar? Como se comunicar com a nova geração?',

        ],
        'TECNOLOGICO' => [
            'Copérdia está em ramo de produção de alimentos. Precisamos nos moldar a nova '
                . 'realidade, buscando alternativas de resultados para os próximos anos. Ano de '
                . '2027 ja está estruturado, com as produções encaminhadas.',

        ],
        'ECOLOGICO' => [
            'Cumprir as normas vigentes. Copérdia está de acordo com as legislações.',

            'ESG precisa avançar e Copérdia se adaptas às leis vigentes. (Exemplo: '
                . 'Dejetos suínos tem potencial para se transformar em um negócio?)',

            'Gestão de dejetos e licenciamento ambiental como restrição concreta à '
                . 'ampliação de alojamento: o limite da expansão é ambiental antes de ser '
                . 'financeiro. Aonde Vamos crescer (Geografia)?',

        ],
        'LEGAL' => [
            'Mudança constante nas normativas X dificuldade dos produtores estarem '
                . 'acompanhando e se adaptando. Isso gera custos ao produtor.',

            'LGPD no tratamento de dados de associados e colaboradores.',

            'Legislação ambiental e trabalhista mais exigente',

            'Necessidade de cumprimento de LGPD, NR1, Compliance, Relacionamento com '
                . 'Entidades.',

            'Fornecimento a associado que não seja contribuinte regular do IBS/CBS só tem '
                . 'alíquota zero com anulação dos créditos apropriados: complexidade contábil '
                . 'nova na operação corrente com o quadro social.',

        ],
    ],
];

<?php

namespace App\Services;

use App\Core\Database;

/**
 * As regras da Projeção de Resultados que não são de tela nem de banco.
 *
 * **Quem recebe proposta é a SUBCONTA; o bloco é conta.** É o que a planilha
 * do cliente faz (`O11 = SUM(O12:O14)`, `O15 = SUM(O10:O11)`) e é o que
 * impede a contradição mais fácil deste tipo de tela: alguém digitar na linha
 * de despesas um total que não é a soma das despesas que ele mesmo digitou.
 *
 * A derivação mora AQUI, e não no JavaScript, pelo mesmo motivo que a
 * agregação das metas mora no servidor: refazer a conta na tela seria uma
 * segunda cópia da regra, no idioma errado, e a primeira divergência entre as
 * duas apareceria como número na reunião.
 *
 * **A linha 15 (Despesas Totais) fica de fora.** Ela vem do Qlik com valor
 * próprio e não é soma de nenhuma combinação das linhas que carregamos — foram
 * testadas sete, todas divergentes nos 11 negócios × 9 meses. Enquanto a regra
 * dela não for conhecida, a proposta dessa linha é `null` e a tela mostra
 * "—": inventar uma soma plausível ali seria o erro mais caro deste sistema.
 */
class Projecao
{
    /**
     * Bloco => partes que o compõem, na ORDEM em que precisam ser calculados.
     * A sequência respeita as dependências (3 depois de 1 e 2; 10 depois de 5,
     * 7 e 9), então uma passada só basta.
     */
    public const DERIVADAS = [
        '1'  => ['1.1'],
        '2'  => ['2.1', '2.2', '2.3'],
        '3'  => ['1', '2'],
        '4'  => ['4.1'],
        '5'  => ['3', '4'],
        '7'  => ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6', '8.7', '8.8', '8.9',
                 '8.10', '8.11', '8.12', '8.13', '8.14', '8.15', '8.16', '8.17'],
        '9'  => ['9.1', '9.2', '9.3', '9.4', '9.5'],
        '10' => ['5', '7', '9'],
        '11' => ['11.1', '11.2'],
        '12' => ['10', '11'],
        '13' => ['12', '12.1'],
        '14' => ['14.1', '14.2', '14.3'],
        // Estrutura NOVA da DRE gerencial (2026-11-18): o rateio corporativo se
        // divide em negócio (14.3) e administrativo (16.1); "14 - Resultado do
        // negócio" (15) = após impostos + rateios do negócio; o líquido (18) =
        // resultado do negócio + rateios administrativos.
        '15' => ['13', '14'],
        '16' => ['16.1'],
        '18' => ['15', '16'],
    ];

    /** Linhas-chave da DRE (estrutura nova). */
    public const LINHA_APOS_IMPOSTOS = '13';
    public const LINHA_RATEIOS_NEGOCIO = '14';
    public const LINHA_RESULTADO_NEGOCIO = '15';
    public const LINHA_RATEIOS_ADM = '16';
    public const LINHA_RESULTADO = '18';
    public const LINHA_MARGEM = '19';

    /** Linha que o Qlik entrega pronta e que não sabemos recompor (Despesas Totais). */
    public const SEM_PROPOSTA = ['17'];

    /**
     * As premissas que a pessoa DIGITA no painel. São TRÊS, e valem para o
     * NEGÓCIO INTEIRO e todas as filiais dele, com UM valor para cada ANO
     * (pedido do cliente, 2026-11-16: "quando lançar o PIB e o IPCA, deve ficar
     * fixo, gravado, registrado, ou seja, vai servir para o negócio e todas as
     * filiais do negócio. Além desses dois campos, precisa ter um percentual de
     * crescimento também"; e 2026-11-17: "IPCA, PIB e percentual de crescimento
     * desejado devem ser por negócio e por ano").
     *
     * IPCA e PIB são as duas únicas células digitadas do painel da planilha no
     * nível consolidado. `CRESCIMENTO` é o crescimento DESEJADO da receita (real,
     * acima da inflação e da economia).
     *
     * **As premissas movem a proposta** (pedido do cliente, 2026-11-18: "o
     * crescimento atual deve considerar o PIB, o IPCA, mais o percentual de
     * crescimento desejado"; "o percentual de crescimento com despesas com
     * pessoas deve impactar somente na linha despesas com pessoa"). Até 17/11 o
     * desejado era REGISTRO; agora, por omissão, a Receita de Vendas cresce IPCA +
     * PIB + desejado e as Despesas com Pessoal (8.5) crescem `PESSOAS` — e SÓ
     * elas: ver `crescimentosPadrao`. O que foi digitado numa linha manda.
     *
     * `PESSOAS` é o crescimento das despesas com pessoas, por negócio e por ano.
     * Vale para todas as filiais, e atua apenas na linha Despesas com Pessoal.
     */
    public const PREMISSAS = ['IPCA', 'PIB', 'CRESCIMENTO', 'PESSOAS'];

    /**
     * Planejamento de PESSOAS E BENEFÍCIOS por negócio (pedido do cliente,
     * 2026-11-18): quanto o negócio paga de alimentação e de abono aos
     * colaboradores que são dele.
     *
     * As regras, como o cliente as deu:
     *  - **Alimentação**: R$ 500 por colaborador, por mês, os 12 meses.
     *  - **Abono mensal**, em DUAS partes: R$ 200 de **assiduidade** (o
     *    colaborador que cumpre os quesitos) e R$ 500 de **resultado**, que só se
     *    paga no mês em que o negócio atinge o resultado planejado — "quando não
     *    atinge, não ganha".
     *  - **Quem divide o tempo entre negócios** (30/70, 50/50) é pago pelos dois,
     *    cada um pela sua parte. Isso já está no número de colaboradores: a DRE
     *    traz cada negócio com as pessoas EQUIVALENTES dele (confirmado pelo
     *    cliente), então o custo do negócio sai direto de `colaboradores × valor`,
     *    sem rateio por cima — ratear de novo dividiria duas vezes.
     *  - **A base é o último mês do ano atual**: o quadro de dezembro de 2026
     *    planeja 2027. Enquanto dezembro não existe, é o último mês carregado.
     *
     * O padrão dos valores mora AQUI, e só o que o cliente digita vai para
     * `negocio_beneficio`: guardar o padrão também criaria duas fontes para o
     * mesmo número, e a mudança do padrão não alcançaria quem o gravou.
     * `PCT_ASSIDUIDADE` e `PCT_RESULTADO` são a PREVISÃO: que parte dos
     * colaboradores cumpre a assiduidade, e em que parte dos meses o negócio
     * atinge o resultado. O padrão é 100%, que é o orçamento cheio (o abono pago
     * todo mês a todos) — e a tela mostra ao lado o que seria SEM o abono de
     * resultado, porque é esse o risco que o negócio carrega.
     *
     * `COLABORADORES` é o quadro planejado quando difere do atual (contratação,
     * desligamento); vazio, vale o quadro da DRE.
     */
    public const BENEFICIOS = ['COLABORADORES', 'ALIMENTACAO', 'ASSIDUIDADE', 'RESULTADO',
        'PCT_ASSIDUIDADE', 'PCT_RESULTADO', 'PPR_SALARIOS'];
    /** O teto do campo `PPR_SALARIOS` (salários no ano). */
    public const PPR_SALARIOS_MAXIMO = 12.0;
    public const BENEFICIOS_PADRAO = [
        'ALIMENTACAO' => 500.0,
        'ASSIDUIDADE' => 200.0,
        'RESULTADO' => 500.0,
        'PCT_ASSIDUIDADE' => 100.0,
        'PCT_RESULTADO' => 100.0,
    ];
    /** Meses do ano em que se pagam alimentação e abono. */
    public const BENEFICIOS_MESES = 12;

    /**
     * A previsão de benefícios de UM negócio em UM ano.
     *
     * @param array|null $base      ['ano','mes','colaboradores'] do quadro da DRE, ou null se não há
     * @param array      $digitados chave => valor lançado pelo cliente para o ano
     * @return array|null null quando não há com o que calcular (sem quadro e sem número digitado)
     */
    public static function pessoas(?array $base, array $digitados): ?array
    {
        $colabBase = $base ? (float)$base['colaboradores'] : null;
        $colab = isset($digitados['COLABORADORES']) ? (float)$digitados['COLABORADORES'] : $colabBase;
        if ($colab === null) {
            return null;
        }
        $v = [];
        foreach (self::BENEFICIOS_PADRAO as $chave => $padrao) {
            $v[$chave] = isset($digitados[$chave]) ? (float)$digitados[$chave] : $padrao;
        }
        $m = self::BENEFICIOS_MESES;
        $alim = $colab * $v['ALIMENTACAO'];
        $assid = $colab * ($v['PCT_ASSIDUIDADE'] / 100) * $v['ASSIDUIDADE'];
        $result = $colab * ($v['PCT_RESULTADO'] / 100) * $v['RESULTADO'];
        $mensal = ['alimentacao' => $alim, 'assiduidade' => $assid, 'resultado' => $result,
            'total' => $alim + $assid + $result];
        $anual = array_map(static fn ($x) => round($x * $m, 2), $mensal);
        return [
            'colaboradores' => $colab,
            'colaboradores_base' => $colabBase,
            'base' => $base,
            'valores' => $v,
            'mensal' => array_map(static fn ($x) => round($x, 2), $mensal),
            'anual' => $anual,
            // O piso: o que o negócio paga se NUNCA atingir o resultado.
            'sem_resultado_anual' => round(($alim + $assid) * $m, 2),
            // O teto: todos cumprem a assiduidade e o negócio atinge o resultado todo mês.
            'maximo_anual' => round($colab * ($v['ALIMENTACAO'] + $v['ASSIDUIDADE'] + $v['RESULTADO']) * $m, 2),
        ];
    }

    /**
     * A VISÃO REALISTA do ano corrente para UMA linha (pedido do cliente,
     * 2026-11-18): a mesma regra do "Painel Balanço out/26", que a direção já viu.
     * É estimativa da Controladoria, e não número do Qlik — por isso só leitura.
     *
     *  - **Receita bruta** (`1`): o planejado dos meses que faltam × a ADERÊNCIA da
     *    receita no que já fechou (realizado ÷ planejado até o corte). Receita
     *    escala: quem vendeu 92% do plano até outubro vende 92% em nov–dez.
     *  - **Resultado líquido** (`16`): o planejado dos meses que faltam + o DESVIO
     *    MÉDIO MENSAL até o corte × os meses que faltam. Supõe que o negócio
     *    continue errando o plano no mesmo ritmo do ano — em reais, porque
     *    resultado não escala com nada (pode trocar de sinal).
     *
     * Desde 2026-11-25 (pedido do cliente: escolher a visão por receita líquida,
     * lucro bruto, despesas, resultado antes dos rateios), as outras linhas usam
     * a regra que já existia, por natureza: a **receita líquida** (`3`) escala
     * como a bruta (aderência); **lucro bruto, despesas operacionais e resultado
     * antes dos rateios** (`5`, `7`, `13`) seguem o desvio médio mensal, como o
     * resultado. É a extensão mais curta das duas regras — e por isso o realista
     * de cada linha é calculado sozinho: o lucro bruto realista NÃO é a receita
     * líquida realista menos o custo realista (não há linha de custo no quadro).
     * Regra confirmada pelo cliente em 2026-11-25 ("sim, pode manter assim").
     *
     * @param array{realizado:float, plan_ate:float, plan_resto:float} $v
     * @return array todas as colunas do quadro, em reais
     */
    /**
     * As linhas do quadro "realizado, planejado e visão realista" (pedido do
     * cliente, 2026-11-25: o seletor de visão por negócio): receita bruta e
     * líquida, lucro bruto, despesas operacionais, "11 - resultado após
     * impostos" (antes dos rateios) e resultado líquido.
     */
    public const LINHAS_VISAO_ANO = ['1', '3', '5', '7', '13', '18'];

    /** As linhas de RECEITA, que no realista seguem a aderência; as demais, o desvio médio. */
    private const VISAO_POR_ADERENCIA = ['1', '3'];

    public static function visaoRealista(array $v, string $conta, int $corte): array
    {
        $real = (float)$v['realizado'];
        $ate = (float)$v['plan_ate'];
        $resto = (float)$v['plan_resto'];
        $faltam = 12 - $corte;
        if (in_array($conta, self::VISAO_POR_ADERENCIA, true)) {
            // Sem plano até o corte não há aderência: vale o planejado.
            $realista = $ate != 0.0 ? $resto * ($real / $ate) : $resto;
        } else {
            $realista = $corte > 0 ? $resto + ($real - $ate) / $corte * $faltam : $resto;
        }
        $planAno = $ate + $resto;
        $realPlan = $real + $resto;
        $realReal = $real + $realista;
        return [
            'realizado' => $real,
            'plan_ate' => $ate,
            'plan_resto' => $resto,
            'real_plan' => $realPlan,
            'plan_ano' => $planAno,
            'dif' => $realPlan - $planAno,
            'dif_pct' => $planAno != 0.0 ? ($realPlan - $planAno) / abs($planAno) : null,
            'realista_resto' => $realista,
            'real_realista' => $realReal,
            'realista_x_plano' => $realReal - $planAno,
        ];
    }

    /**
     * As DIRETRIZES DO PLANEJAMENTO, por ano (documento "Diretrizes para o
     * Planejamento 2027", Concórdia, 16/11/2026, assinado pela presidência).
     *
     * Dois tipos de valor:
     *  - `premissas` e `beneficios` FIXOS: valem para TODOS os negócios naquele
     *    ano e não se digitam — a tela mostra o valor com o selo da diretriz e a
     *    gravação o recusa (decisão do cliente, 2026-11-18: "fixos em 2027").
     *  - `sugeridas`: IPCA e PIB. Vêm preenchidos com o valor da diretriz, mas
     *    CADA NEGÓCIO pode ajustar (decisão do cliente, 2026-11-18, revendo a
     *    anterior: "deixe os percentuais de IPCA e PIB e % de crescimento do
     *    faturamento para que cada negócio possa editar; porém as filiais devem
     *    receber isso sem poder editar"). Apagar o digitado devolve a diretriz.
     * O crescimento REAL (`CRESCIMENTO`) fica fora de propósito — o documento o
     * deixa "a ser definido com as gerências de cada negócio".
     *
     *  - Pessoal: reajuste de 5,50% (`PESSOAS`).
     *  - Vale alimentação R$ 500 por colaborador/mês, DENTRO da despesa de pessoal.
     *  - Prêmio assiduidade R$ 200 e prêmio metas R$ 500 por colaborador que
     *    cumprir os requisitos.
     *  - PPR: 1,5 salário, provisionado mensalmente em 1/12 (`PPR_SALARIOS`, um
     *    campo do bloco de premissas). É um TETO, não um valor fixo (pedido do
     *    cliente, 2026-11-23: "precisa ser editável, caso o negócio resolva pagar
     *    menos PPR; a mais não deve permitir… já deve vir preenchido"): vem
     *    preenchido com a diretriz, o negócio pode baixar, e acima é recusado.
     *    Nos anos sem diretriz o negócio digita (0 a 12), e vazio é sem PPR.
     *
     * Um terceiro tipo de valor, então: `maximos` — o padrão é a diretriz, o
     * negócio digita MENOS, e mais é recusado (`maximoDaDiretriz`).
     *  - IPCA 4,50% (Focus de 06/11/2026) e PIB agropecuário 1,50%.
     *  - Demais despesas operacionais: sem crescimento sobre 2026 — que é o que a
     *    proposta já faz por omissão (vale a projeção). O DESAFIO, não: nele elas
     *    acompanham a receita (`acompanhamReceitaNoDesafio`, 2026-11-23).
     *  - Nenhum negócio ou filial com meta negativa de resultado líquido — a
     *    tela DESTACA o resultado negativo, sem travar o lançamento (decisão do
     *    cliente, 2026-11-18).
     *  - Sobras líquidas mínimas de 2,0% — a meta do desafio (`desafio_ano`).
     */
    public const DIRETRIZES = [
        2027 => [
            'fonte' => 'Diretrizes para o Planejamento 2027 (16/11/2026)',
            'premissas' => ['PESSOAS' => 5.5],
            'sugeridas' => ['IPCA' => 4.5, 'PIB' => 1.5],
            'beneficios' => ['ALIMENTACAO' => 500.0, 'ASSIDUIDADE' => 200.0, 'RESULTADO' => 500.0],
            'maximos' => ['PPR_SALARIOS' => 1.5],
        ],
    ];

    /** A diretriz de um ano, ou null. */
    public static function diretriz(int $ano): ?array
    {
        return self::DIRETRIZES[$ano] ?? null;
    }

    /**
     * As premissas que VALEM num ano: as sugeridas pela diretriz (IPCA e PIB),
     * com as digitadas do negócio por cima, e as FIXAS da diretriz por cima de
     * tudo.
     */
    public static function premissasEfetivas(int $ano, array $digitadas): array
    {
        $d = self::diretriz($ano) ?? [];
        return array_replace($d['sugeridas'] ?? [], $digitadas, $d['premissas'] ?? []);
    }

    /**
     * Os benefícios que VALEM num ano: os máximos da diretriz como padrão, os
     * digitados por cima — sem passar do máximo (se a diretriz baixar depois, o
     * digitado acima dela passa a valer o teto) — e os FIXOS da diretriz por
     * cima de tudo.
     */
    public static function beneficiosEfetivos(int $ano, array $digitados): array
    {
        $d = self::diretriz($ano) ?? [];
        $maximos = $d['maximos'] ?? [];
        $v = array_replace($maximos, $digitados);
        foreach ($maximos as $k => $max) {
            $v[$k] = min((float)$v[$k], (float)$max);
        }
        return array_replace($v, $d['beneficios'] ?? []);
    }

    /** O teto da diretriz para a chave naquele ano (o negócio digita menos), ou null. */
    public static function maximoDaDiretriz(int $ano, string $chave): ?float
    {
        $m = self::diretriz($ano)['maximos'][$chave] ?? null;
        return $m === null ? null : (float)$m;
    }

    /** A chave é fixada pela diretriz naquele ano? (premissa ou benefício) */
    public static function fixadaPelaDiretriz(int $ano, string $chave): ?float
    {
        $d = self::diretriz($ano);
        if (!$d) {
            return null;
        }
        return $d['premissas'][$chave] ?? $d['beneficios'][$chave] ?? null;
    }

    /**
     * DESPESAS COM PESSOAL do ano da proposta, pela diretriz (pedido do cliente,
     * 2026-11-18): "Despesa com pessoal 5,5%", vale de R$ 500 DENTRO dela, os
     * prêmios de R$ 200 + R$ 500, e o PPR de 1,5 salário "calculado
     * automaticamente pelo sistema", a partir da folha do mês atual.
     *
     * A linha 8.5 da DRE é a soma das contas 3.07.01.21* — e já traz, em 2026, o
     * vale PAT, o prêmio por desempenho e a provisão do PPR. Somar o PPR de 2027
     * por cima contaria o PPR duas vezes, e reajustar a linha inteira em 5,5%
     * reajustaria um vale que a diretriz manda MANTER. Então a conta troca as
     * peças:
     *
     *   8.5 de 2027 = (projeção de 2026 − vale, prêmio e PPR de 2026) × (1 + 5,5%)
     *               + (vale + prêmios de 2027) × parte da folha que está na despesa
     *               + PPR de 2027
     *
     *  - As peças de 2026 entram pela PARTE que têm nas 3.07.01.21* de jan até o
     *    corte (`pessoal_ate`), aplicada à projeção da linha: na Agropecuária, as
     *    3.07.01.21* somam R$ 4,6 mi a mais que a linha 8.5, e subtrair o valor
     *    absoluto tiraria da linha o que nunca esteve nela.
     *  - Vale e prêmios de 2027 são os do cartão de pessoas (`pessoas()`), com os
     *    valores da diretriz, × a parte da folha que é DESPESA: quem é custo (as
     *    contas 4.03, na produção de Cereais, Rações, UTM e UBS) tem vale e prêmio
     *    no custo, que acompanha a receita.
     *  - O PPR de 2027 é a folha do mês (salários e remunerações, despesa + custo)
     *    × (1 + 5,5%) × 1,5 — inteiro na 8.5, onde a provisão de 2026 de todos os
     *    colaboradores já está.
     *
     * Devolve o PERCENTUAL que isso dá sobre a projeção — é ele que cresce a linha
     * no negócio e nas filiais (a filial não tem folha própria: herda o % do
     * negócio, e a soma das filiais continua fechando com ele). Null quando não há
     * com o que calcular (sem folha carregada, sem diretriz de PPR ou sem
     * despesa de pessoal), e aí vale o reajuste puro.
     *
     * **O quadro planejado** (pedido do cliente, 2026-11-23): quando o cartão
     * traz um número de colaboradores diferente do atual, a folha reajustada e o
     * PPR andam na PROPORÇÃO do quadro (`$fatorQuadro` = planejado ÷ atual) — os
     * benefícios já andam, porque `pessoas()` os conta pelo quadro planejado. Com
     * o quadro atual o fator é 1 e nada muda.
     *
     * **A base sai SEM a conta reduzida 3227** (pedido do cliente, 2026-11-23:
     * "estornando esses 1 milhão de PPR da conta reduzida 3227. A base tem que
     * ir para 44 milhões e pouco. Faça isso para todos os negócios e filiais").
     * O PPR de 2026 mora em duas reduzidas da 8.5: a 3419 (PROVISÃO PARTICIPAÇÃO
     * NOS RESULTADOS, a provisão do ano) e a 3227 (PARTICIPAÇÃO NOS RESULTADOS, o
     * acerto contra a provisão — nas Agropecuárias um ESTORNO de +R$ 1.049.311 em
     * jan–out/2026). `ppr_ate` da folha é a soma das duas. Agora a projeção perde
     * a 3227 realizada (`$ppr26['realizado']`, no sinal da DRE: estorno é +) e o
     * "PPR de 2026" que sai da base é só a provisão (`ppr_ate` + 3227), na parte
     * que ela tem nas contas sem a 3227. Nas Agropecuárias: 43.464.705 + 1.049.311
     * = 44.514.016 de base. Como a conta anterior já tirava o PPR LÍQUIDO (com o
     * estorno dentro), o total do negócio quase não se move; o que muda é a base
     * mostrada e a DISTRIBUIÇÃO entre as filiais, que crescem sobre a projeção
     * DELAS sem a 3227 delas (`percentual_sem_ppr`, `crescimentoPessoalDaFilial`).
     * Sem o nível 3 carregado (`$ppr26` null) vale a conta anterior.
     *
     * @param array|null $folha  linha de `negocio_folha`
     * @param array|null $calc   saída de `pessoas()` para o ano
     * @param array|null $ppr26  ['realizado' => a 3227 do negócio de jan até o corte] (`pprRealizado`)
     */
    public static function pessoalDaDiretriz(float $projecao85, ?array $folha, ?array $calc, float $reajuste, ?float $pprSalarios, float $fatorQuadro = 1.0, ?array $ppr26 = null): ?array
    {
        if (!$folha || $pprSalarios === null || $projecao85 >= 0 || (float)$folha['pessoal_ate'] <= 0) {
            return null;
        }
        $g = $reajuste / 100;
        // A 3227 sai da projeção e das contas; a provisão (3419) fica como o PPR de 2026.
        $acerto = $ppr26 !== null ? (float)$ppr26['realizado'] : 0.0;
        $semPpr = -$projecao85 + $acerto;
        $pessoalAte = (float)$folha['pessoal_ate'] + $acerto;
        if ($semPpr <= 0 || $pessoalAte <= 0) {
            $acerto = 0.0;
            $semPpr = -$projecao85;
            $pessoalAte = (float)$folha['pessoal_ate'];
        }
        $provisao = (float)$folha['ppr_ate'] + $acerto;
        $pecas26 = (float)$folha['vale_ate'] + (float)$folha['premio_ate'] + $provisao;
        $parte26 = min(1.0, max(0.0, $pecas26 / $pessoalAte));
        $base = $semPpr * (1 - $parte26);
        // O ESTORNO DIRETO das contas reduzidas (pedido do cliente, 2026-11-25:
        // "estornar a provisão atual de PPR conta 3419, projetar esse estorno de
        // novembro e dezembro também; fazer a mesma questão para as contas 3398 e
        // 3339 — dividir por 10 e multiplicar por 12"): sai da base o realizado de
        // jan até o corte de cada uma, projetado para o ano (÷ corte × 12), em vez
        // da parte proporcional da folha.
        $estornos = [];
        $corteEst = (int)($ppr26['corte'] ?? 0);
        if ($ppr26 !== null && !empty($ppr26['estornos']) && $corteEst > 0) {
            $soma = 0.0;
            foreach (self::CONTAS_ESTORNO_PESSOAL as $conta => $nome) {
                $real = (float)($ppr26['estornos'][$conta] ?? 0);
                $anual = $real / $corteEst * 12;
                $estornos[] = ['conta' => (string)$conta, 'nome' => $nome, 'realizado' => $real, 'anual' => $anual];
                $soma += $anual;
            }
            // Sinal da DRE: despesa é negativa, e tirá-la encolhe a base.
            if ($semPpr + $soma > 0) {
                $base = $semPpr + $soma;
                $pecas26 = -$soma;
                $parte26 = $semPpr > 0 ? -$soma / $semPpr : 0.0;
            } else {
                $estornos = [];
            }
        }
        $salDesp = (float)$folha['salario_despesa'];
        $salCusto = (float)$folha['salario_custo'];
        $parteDespesa = $salDesp + $salCusto > 0 ? $salDesp / ($salDesp + $salCusto) : 1.0;
        $beneficios = $calc ? (float)$calc['anual']['total'] * $parteDespesa : 0.0;
        $f = $fatorQuadro > 0 ? $fatorQuadro : 1.0;
        $ppr = ($salDesp + $salCusto) * (1 + $g) * $pprSalarios * $f;
        $total = $base * (1 + $g) * $f + $beneficios + $ppr;
        return [
            'percentual' => ($total / -$projecao85 - 1) * 100,
            // O mesmo percentual com o quadro ATUAL — o reajuste que o mínimo da
            // filial usa (ela não tem quadro planejado).
            'percentual_atual' => ($total / $f / -$projecao85 - 1) * 100,
            // Os dois sobre a projeção SEM a 3227: é o que cada filial aplica à
            // projeção dela sem a 3227 dela (`crescimentoPessoalDaFilial`).
            'percentual_sem_ppr' => ($total / $semPpr - 1) * 100,
            'percentual_atual_sem_ppr' => ($total / $f / $semPpr - 1) * 100,
            'fator_quadro' => $f,
            'ppr_salarios' => $pprSalarios,
            'projecao' => $projecao85,
            // A 3227 realizada (sinal da DRE: estorno é +) e a projeção sem ela.
            'ppr_acerto' => $acerto,
            'ppr_acerto_conta' => self::CONTA_ACERTO_PPR,
            'projecao_sem_ppr' => -$semPpr,
            'ppr_provisao_2026' => $provisao,
            'parte_2026' => $parte26,
            'pecas_2026' => $estornos ? $pecas26 : $semPpr * $parte26,
            // As contas estornadas uma a uma (realizado até o corte e o ano); vazio
            // = a conta anterior, pela parte da folha.
            'estornos' => $estornos,
            'estornos_corte' => $estornos ? $corteEst : null,
            'base' => $base,
            'base_reajustada' => $base * (1 + $g) * $f,
            'parte_despesa' => $parteDespesa,
            'beneficios' => $beneficios,
            'ppr' => $ppr,
            'ppr_mensal' => $ppr / 12,
            'folha_mensal' => $salDesp + $salCusto,
            'folha_mes' => (int)$folha['mes_ate'],
            'folha_ano' => (int)$folha['ano'],
            'proposta' => -$total,
        ];
    }

    /**
     * O que a despesa de pessoal precisa saber de VÁRIOS negócios: a folha do
     * ano-base e a previsão de benefícios do ano da proposta (quadro do último
     * mês carregado, com os valores da diretriz por cima do digitado). Três
     * consultas para todos — o Painel chama com doze negócios de uma vez.
     *
     * @return array<int,array{folha:?array, calc:?array}>
     */
    public static function dadosDePessoal(array $negocioIds, int $anoBase, int $anoPlan): array
    {
        $saida = [];
        foreach ($negocioIds as $id) {
            $saida[(int)$id] = ['folha' => null, 'calc' => null];
        }
        if (!$saida) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($saida), '?'));
        $ids = array_keys($saida);
        foreach (Database::todos(
            'SELECT * FROM negocio_folha WHERE ano = ? AND negocio_id IN (' . $marcas . ')',
            array_merge([$anoBase], $ids)
        ) as $f) {
            $saida[(int)$f['negocio_id']]['folha'] = $f;
        }
        $bases = [];
        foreach (Database::todos(
            'SELECT p.negocio_id, p.ano, p.mes, p.colaboradores FROM negocio_pessoas_mes p
              WHERE p.ano = ? AND p.negocio_id IN (' . $marcas . ')
                AND p.mes = (SELECT MAX(q.mes) FROM negocio_pessoas_mes q
                              WHERE q.negocio_id = p.negocio_id AND q.ano = p.ano)',
            array_merge([$anoBase], $ids)
        ) as $b) {
            $bases[(int)$b['negocio_id']] = ['ano' => (int)$b['ano'], 'mes' => (int)$b['mes'],
                'colaboradores' => (int)$b['colaboradores']];
        }
        $dig = [];
        foreach (Database::todos(
            'SELECT negocio_id, chave, valor FROM negocio_beneficio WHERE ano = ? AND negocio_id IN (' . $marcas . ')',
            array_merge([$anoPlan], $ids)
        ) as $d) {
            $dig[(int)$d['negocio_id']][$d['chave']] = (float)$d['valor'];
        }
        // A 3227 realizada do negócio (filial 0) até o corte: sai da base do pessoal.
        $acertos = self::pprRealizado($ids, $anoBase);
        $estornos = self::estornosPessoal($ids, $anoBase);
        foreach ($ids as $id) {
            $saida[$id]['ppr26'] = isset($acertos[$id]) || isset($estornos[$id])
                ? ['realizado' => $acertos[$id]['negocio'] ?? 0.0,
                    'estornos' => $estornos[$id]['contas'] ?? [], 'corte' => $estornos[$id]['corte'] ?? null]
                : null;
            $efetivos = self::beneficiosEfetivos($anoPlan, $dig[$id] ?? []);
            $saida[$id]['calc'] = self::pessoas($bases[$id] ?? null, $efetivos);
            $saida[$id]['ppr_salarios'] = isset($efetivos['PPR_SALARIOS']) ? (float)$efetivos['PPR_SALARIOS'] : null;
        }
        return $saida;
    }

    /**
     * Quanto cada linha cresce por omissão num negócio, com TUDO o que vale no
     * ano: as premissas digitadas com a diretriz por cima (`crescimentosPadrao`)
     * e, para as Despesas com Pessoal, o percentual da conta da diretriz
     * (`pessoalDaDiretriz`) no lugar do reajuste puro. É a mesma chamada na tela
     * da Projeção e no Painel — se as duas contassem diferente, ninguém saberia
     * em qual acreditar.
     *
     * **Quadro planejado diferente do atual** (pedido do cliente, 2026-11-23): o
     * padrão da 8.5 anda na proporção do quadro — contratar sobe, desligar desce
     * —, e é esse padrão que vira o MÍNIMO da linha (`marcarMinimoPessoal`): o
     * número de pessoas planejado × o custo de hoje com o reajuste. O terceiro
     * item da resposta diz o mínimo e de onde ele veio.
     *
     * @return array{0: array<string,float>, 1: ?array, 2: ?array} [crescimentos, detalhe do pessoal, mínimo]
     */
    public static function crescimentosDoNegocio(int $anoPlan, array $digitadas, float $projecao85, ?array $pessoal): array
    {
        $efetivas = self::premissasEfetivas($anoPlan, $digitadas);
        $cresc = self::crescimentosPadrao($efetivas);
        $detalhe = null;
        $minimo = null;
        if (isset($efetivas['PESSOAS'])) {
            $calc = $pessoal['calc'] ?? null;
            $q0 = $calc ? (float)($calc['colaboradores_base'] ?? 0) : 0.0;
            $qp = $calc ? (float)($calc['colaboradores'] ?? 0) : 0.0;
            $fator = $q0 > 0 && $qp > 0 ? $qp / $q0 : 1.0;
            $detalhe = self::pessoalDaDiretriz(
                $projecao85,
                $pessoal['folha'] ?? null,
                $calc,
                (float)$efetivas['PESSOAS'],
                // O número de salários do PPR: o campo `PPR_SALARIOS` (diretriz
                // por cima do digitado, `dadosDePessoal`); sem ele, sem PPR.
                array_key_exists('ppr_salarios', (array)$pessoal)
                    ? $pessoal['ppr_salarios']
                    : self::maximoDaDiretriz($anoPlan, 'PPR_SALARIOS'),
                $fator,
                $pessoal['ppr26'] ?? null
            );
            if ($detalhe) {
                $cresc[self::LINHA_PESSOAL] = $detalhe['percentual'];
                $atual = $detalhe['percentual_atual'];
            } else {
                $atual = (float)$efetivas['PESSOAS'];
                $cresc[self::LINHA_PESSOAL] = ((1 + $atual / 100) * $fator - 1) * 100;
            }
            $minimo = [
                'percentual' => $cresc[self::LINHA_PESSOAL],
                'percentual_atual' => $atual,
                // Sobre a projeção sem a 3227 — o que as filiais aplicam à delas
                // (`crescimentoPessoalDaFilial`). Null: a filial herda o % acima.
                'percentual_sem_ppr' => $detalhe['percentual_sem_ppr'] ?? null,
                'percentual_atual_sem_ppr' => $detalhe['percentual_atual_sem_ppr'] ?? null,
                'fator_quadro' => $fator,
                'colaboradores' => $calc ? $qp : null,
                'colaboradores_base' => $calc ? $q0 : null,
            ];
        }
        return [$cresc, $detalhe, $minimo];
    }

    /**
     * A conta reduzida 3227 (acerto do PPR) realizada de jan até o corte, por
     * negócio e por filial (`dre_reduzida_valor`, o nível 3). No sinal da DRE: o
     * estorno é positivo. Negócio sem a conta no ano não aparece.
     *
     * @param int[] $negocioIds
     * @return array<int, array{negocio: float, filiais: array<int,float>}>
     */
    public static function pprRealizado(array $negocioIds, int $ano): array
    {
        $negocioIds = array_values(array_unique(array_map('intval', $negocioIds)));
        if (!$negocioIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        $saida = [];
        foreach (Database::todos(
            "SELECT v.negocio_id, v.filial_id, v.realizado
               FROM dre_reduzida_valor v JOIN dre_reduzida r ON r.codigo = v.codigo
              WHERE r.reduzida = ? AND r.linha = ? AND v.ano = ? AND v.negocio_id IN ({$marcas})",
            array_merge([self::CONTA_ACERTO_PPR, self::LINHA_PESSOAL, $ano], $negocioIds)
        ) as $r) {
            $n = (int)$r['negocio_id'];
            $saida[$n] ??= ['negocio' => 0.0, 'filiais' => []];
            if ((int)$r['filial_id'] === 0) {
                $saida[$n]['negocio'] += (float)$r['realizado'];
            } else {
                $saida[$n]['filiais'][(int)$r['filial_id']] = (float)$r['realizado'];
            }
        }
        return $saida;
    }

    /**
     * O realizado de jan até o corte das contas de `CONTAS_ESTORNO_PESSOAL`, por
     * negócio (`filial_id = 0`, o nível 3), no sinal da DRE, com o corte da carga.
     *
     * @param int[] $negocioIds
     * @return array<int, array{corte: int, contas: array<string,float>}>
     */
    public static function estornosPessoal(array $negocioIds, int $ano): array
    {
        $negocioIds = array_values(array_unique(array_map('intval', $negocioIds)));
        if (!$negocioIds) {
            return [];
        }
        $contas = array_map('strval', array_keys(self::CONTAS_ESTORNO_PESSOAL));
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        $mc = implode(',', array_fill(0, count($contas), '?'));
        $saida = [];
        foreach (Database::todos(
            "SELECT v.negocio_id, r.reduzida, v.corte, SUM(v.realizado) AS realizado
               FROM dre_reduzida_valor v JOIN dre_reduzida r ON r.codigo = v.codigo
              WHERE r.reduzida IN ({$mc}) AND r.linha = ? AND v.ano = ? AND v.filial_id = 0
                AND v.negocio_id IN ({$marcas})
              GROUP BY v.negocio_id, r.reduzida, v.corte",
            array_merge($contas, [self::LINHA_PESSOAL, $ano], $negocioIds)
        ) as $r) {
            $n = (int)$r['negocio_id'];
            $saida[$n] ??= ['corte' => (int)$r['corte'], 'contas' => []];
            $saida[$n]['corte'] = max($saida[$n]['corte'], (int)$r['corte']);
            $saida[$n]['contas'][(string)$r['reduzida']] = (float)$r['realizado'];
        }
        return $saida;
    }

    /**
     * O crescimento da 8.5 NUMA FILIAL: a projeção dela sem a 3227 dela × (1 +
     * o % do negócio sem a 3227), escrito como % sobre a projeção da filial — o
     * motor (`aplicar`) só sabe crescer a projeção. Somadas, as filiais dão o
     * negócio (as 3227 das filiais somam a do negócio). Sem a conta do negócio
     * (`percentual_sem_ppr` null) ou sem despesa de pessoal, a filial herda o %
     * do negócio como antes.
     *
     * @param array      $cresc   crescimentos do negócio
     * @param array|null $minimo  terceiro item de `crescimentosDoNegocio`
     */
    public static function crescimentoPessoalDaFilial(array $cresc, array $linhasFilial, float $acertoFilial, ?array $minimo, string $chave = 'percentual_sem_ppr'): ?float
    {
        $pct = $minimo[$chave] ?? null;
        $proj = 0.0;
        foreach ($linhasFilial as $l) {
            if ($l['codigo'] === self::LINHA_PESSOAL) {
                $proj = (float)$l['projecao'];
            }
        }
        $herdado = $chave === 'percentual_sem_ppr'
            ? ($cresc[self::LINHA_PESSOAL] ?? null)
            : ($minimo['percentual_atual'] ?? null);
        // Filial sem a 3227 também usa o % sem ela (a dela é zero): o % com a
        // 3227 do negócio dentro faria a soma das filiais escapar do negócio.
        if ($pct === null || $proj >= 0 || $proj - $acertoFilial >= 0) {
            return $herdado;
        }
        return (($proj - $acertoFilial) * (1 + $pct / 100) / $proj - 1) * 100;
    }

    /** `$cresc` com a 8.5 da filial (`crescimentoPessoalDaFilial`). */
    public static function crescimentosDaFilial(array $cresc, array $linhasFilial, float $acertoFilial, ?array $minimo): array
    {
        if (isset($cresc[self::LINHA_PESSOAL])) {
            $cresc[self::LINHA_PESSOAL] = self::crescimentoPessoalDaFilial($cresc, $linhasFilial, $acertoFilial, $minimo);
        }
        return $cresc;
    }

    /**
     * O MÍNIMO das Despesas com Pessoal (pedido do cliente, 2026-11-23): a linha
     * é lançável — contratação e reajuste acima da diretriz a levam para cima —,
     * mas não deveria ficar abaixo do número de pessoas × o custo com o
     * reajuste. Abaixo, ela GRAVA e sai marcada (`abaixo_minimo_pessoal`) para
     * a tela pôr o ⚠ e alguém validar: decisão do cliente, "só o ⚠, grava".
     *
     *  - **Negócio**: o mínimo é o padrão da linha, já na proporção do quadro
     *    planejado (`crescimentosDoNegocio`).
     *  - **Filial**: o quadro ATUAL da filial (não há quadro planejado por filial)
     *    × o custo por pessoa DELA (projeção ÷ quadro) × o reajuste com o quadro
     *    atual — o que dá a projeção da filial × (1 + `percentual_atual`). Filial
     *    sem quadro não tem mínimo: não há pessoa para multiplicar.
     *
     * Despesa é negativa: "abaixo do mínimo" é menos despesa, um número MAIOR que
     * o mínimo (R$ 1 de folga para o arredondamento). `$soLancado` restringe o ⚠
     * ao que foi lançado na linha — o padrão da filial herda o % do negócio (com
     * o quadro planejado), e marcar o padrão seria acusar quem não digitou nada;
     * o total do negócio no modo filial é comparado sempre.
     *
     * @param array|null $quadro ['colaboradores' => int] da filial, para a tela dizer o custo por pessoa
     */
    public static function marcarMinimoPessoal(array $linhas, ?float $percentual, bool $soLancado = true, ?array $quadro = null): array
    {
        foreach ($linhas as $i => $l) {
            if ($l['codigo'] !== self::LINHA_PESSOAL) {
                continue;
            }
            $proj = (float)$l['projecao'];
            $linhas[$i]['minimo_pessoal'] = null;
            $linhas[$i]['abaixo_minimo_pessoal'] = false;
            if ($quadro) {
                $q = (int)$quadro['colaboradores'];
                $linhas[$i]['quadro_pessoal'] = $q;
                $linhas[$i]['custo_por_pessoa'] = $q > 0 ? $proj / $q : null;
            }
            if ($percentual === null || $proj >= 0 || ($l['proposta'] ?? null) === null) {
                continue;
            }
            $min = $proj * (1 + $percentual / 100);
            $linhas[$i]['minimo_pessoal'] = $min;
            $linhas[$i]['minimo_pessoal_percentual'] = $percentual;
            $lancado = in_array($l['proposta_origem'] ?? null, ['VALOR', 'PERCENTUAL'], true);
            // R$ 1 de folga (ou um milionésimo do mínimo, em linhas pequenas).
            $folga = min(1.0, abs($min) * 1e-6);
            $linhas[$i]['abaixo_minimo_pessoal'] = (!$soLancado || $lancado) && (float)$l['proposta'] > $min + $folga;
        }
        return $linhas;
    }

    /**
     * O quadro de cada filial no último mês carregado do ano (`filial_pessoas`).
     *
     * @param int[] $filialIds
     * @return array<int,int> filial_id => colaboradores
     */
    public static function quadroDasFiliais(array $filialIds, int $ano): array
    {
        if (!$filialIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($filialIds), '?'));
        $saida = [];
        foreach (Database::todos(
            'SELECT filial_id, colaboradores FROM filial_pessoas WHERE ano = ? AND filial_id IN (' . $marcas . ')',
            array_merge([$ano], array_map('intval', $filialIds))
        ) as $r) {
            $saida[(int)$r['filial_id']] = (int)$r['colaboradores'];
        }
        return $saida;
    }

    /**
     * O mínimo da 8.5 no NEGÓCIO e nas FILIAIS dele, de uma vez — a mesma conta
     * na tela da Projeção, no Painel e no Corporativo.
     *
     * @param array      $linhas    do negócio (já com a proposta: lançada ou somada das filiais)
     * @param array      $porFilial filial_id => linhas da filial (já com a proposta)
     * @param array|null $minimo    terceiro item de `crescimentosDoNegocio`
     * @param array<int,int> $quadros filial_id => colaboradores
     * @return array{0: array, 1: array} [linhas do negócio, linhas por filial], marcadas
     */
    public static function minimoPessoalDoNegocio(array $linhas, array $porFilial, ?array $minimo, array $quadros, bool $modoFilial, array $acertosFilial = []): array
    {
        $pct = $minimo['percentual'] ?? null;
        foreach ($porFilial as $fid => $lf) {
            $q = $quadros[(int)$fid] ?? 0;
            // A projeção da filial sem a 3227 dela × (1 + o % atual sem a 3227).
            $pctAtual = self::crescimentoPessoalDaFilial([], $lf, (float)($acertosFilial[(int)$fid] ?? 0.0), $minimo, 'percentual_atual_sem_ppr');
            $porFilial[$fid] = self::marcarMinimoPessoal($lf, $q > 0 ? $pctAtual : null, true, ['colaboradores' => $q]);
        }
        return [self::marcarMinimoPessoal($linhas, $pct, !$modoFilial), $porFilial];
    }

    /**
     * Onde a 8.5 ficou abaixo do mínimo, para listar (Painel, Corporativo).
     *
     * @return list<array{filial_id:int, proposta:float, minimo:float}> filial_id 0 é o negócio
     */
    public static function alertasPessoal(array $linhas, array $porFilial): array
    {
        $saida = [];
        $acha = static function (array $ls): ?array {
            foreach ($ls as $l) {
                if ($l['codigo'] === self::LINHA_PESSOAL) {
                    return $l;
                }
            }
            return null;
        };
        $l = $acha($linhas);
        if ($l && !empty($l['abaixo_minimo_pessoal'])) {
            $saida[] = ['filial_id' => 0, 'proposta' => (float)$l['proposta'], 'minimo' => (float)$l['minimo_pessoal']];
        }
        foreach ($porFilial as $fid => $lf) {
            $l = $acha($lf);
            if ($l && !empty($l['abaixo_minimo_pessoal'])) {
                $saida[] = ['filial_id' => (int)$fid, 'proposta' => (float)$l['proposta'], 'minimo' => (float)$l['minimo_pessoal']];
            }
        }
        return $saida;
    }

    /**
     * As linhas que ACOMPANHAM A RECEITA na proposta, por omissão (decisão do
     * cliente, 2026-11-18): deduções e custo das vendas crescem na mesma
     * proporção da Receita de Vendas, o que mantém a margem bruta de 2026. Antes
     * elas ficavam na projeção enquanto a receita crescia 6% — e o resultado
     * proposto saía inflado (Pecuária com 7,5% de margem). Ganho de margem
     * passa a ser decisão LANÇADA na linha, não efeito colateral.
     */
    public const ACOMPANHAM_RECEITA = ['2.1', '2.2', '2.3', '4.1'];

    /**
     * No DESAFIO, as despesas operacionais também acompanham a receita (pedido do
     * cliente, 2026-11-23: "o Desafio para o negócio deve levar em consideração um
     * crescimento proporcional das despesas"): cada subconta da linha 7 cresce na
     * proporção da Receita de Vendas, o que mantém a despesa sobre a receita de
     * 2026. Ficam fora as Despesas com Pessoal (crescem pela diretriz) e a
     * Depreciação (vale a carga). Os rateios (14.x, 16.1) seguem a projeção.
     *
     * As despesas só ACOMPANHAM PARA CIMA: onde a receita cai, ficam na projeção
     * (pedido do cliente, 2026-11-23, sobre a Pecuária, cujo faturamento cai com o
     * preço base do suíno: "as despesas não vão diminuir… nem as despesas da
     * operação vão encolher proporcional à receita"). Custo e deduções
     * (`ACOMPANHAM_RECEITA`) seguem a receita nos dois sentidos.
     *
     * Só no desafio: na PROPOSTA as demais despesas continuam na projeção por
     * omissão — é a diretriz de 2027 ("sem crescimento sobre 2026") —, e o que o
     * negócio lança manda. A Diferença da tela passa a mostrar também o quanto a
     * proposta segura as despesas abaixo do crescimento da receita.
     *
     * @return string[]
     */
    public static function acompanhamReceitaNoDesafio(): array
    {
        $despesas = array_values(array_diff(self::DERIVADAS['7'], [self::LINHA_PESSOAL, self::LINHA_DEPRECIACAO]));
        return array_merge(self::ACOMPANHAM_RECEITA, $despesas);
    }

    /** A subconta da Receita de Vendas, que cresce com IPCA + PIB + crescimento desejado. */
    public const LINHA_RECEITA = '1.1';
    /** A subconta de Despesas com Pessoal, que cresce com o percentual das despesas com pessoas. */
    public const LINHA_PESSOAL = '8.5';
    /**
     * A conta reduzida do ACERTO do PPR (3227, PARTICIPACAO NOS RESULTADOS, na
     * 8.5): sai da base da despesa de pessoal do ano da proposta, no negócio e em
     * cada filial (pedido do cliente, 2026-11-23). A provisão é a 3419.
     */
    public const CONTA_ACERTO_PPR = '3227';

    /**
     * As contas reduzidas da 8.5 ESTORNADAS da base do pessoal, projetadas para o
     * ano (÷ meses até o corte × 12): a provisão do PPR, o prêmio por desempenho e
     * o vale-alimentação (pedido do cliente, 2026-11-25). No lugar delas entram o
     * PPR, os prêmios e o vale de 2027.
     */
    public const CONTAS_ESTORNO_PESSOAL = [
        '3419' => 'Provisão do PPR (Participação nos Resultados)',
        '3398' => 'Prêmio por desempenho',
        '3339' => 'Vale-alimentação (PAT)',
    ];

    /**
     * A DEPRECIAÇÃO (8.10) do ano da proposta vem da carga por negócio, filial e
     * mês (`negocio_depreciacao`, conta reduzida 3128) e NÃO se lança (pedido do
     * cliente, 2026-11-23: "este campo não deve permitir edição, pois esse já é
     * o total da depreciação"). Negócio sem carga no ano: a linha volta a ser
     * uma despesa lançável como as outras.
     */
    public const LINHA_DEPRECIACAO = '8.10';
    public const CONTA_DEPRECIACAO = '3128';

    /**
     * A depreciação carregada para o ano, por negócio: o total do negócio
     * (`filial_id` 0, que pode ter resto de filial fora do cadastro) e o de cada
     * filial, mês a mês (1–12), no sinal da DRE. Negócio sem carga não aparece.
     *
     * @param int[] $negocioIds
     * @return array<int, array{negocio: array<int,float>, filiais: array<int, array<int,float>>}>
     */
    public static function depreciacao(array $negocioIds, int $ano): array
    {
        $negocioIds = array_values(array_unique(array_map('intval', $negocioIds)));
        if (!$negocioIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        $saida = [];
        foreach (Database::todos(
            "SELECT negocio_id, filial_id, mes, valor FROM negocio_depreciacao
              WHERE ano = ? AND negocio_id IN ({$marcas}) ORDER BY mes",
            array_merge([$ano], $negocioIds)
        ) as $r) {
            $n = (int)$r['negocio_id'];
            $saida[$n] ??= ['negocio' => array_fill(1, 12, 0.0), 'filiais' => []];
            if ((int)$r['filial_id'] === 0) {
                $saida[$n]['negocio'][(int)$r['mes']] = (float)$r['valor'];
            } else {
                $saida[$n]['filiais'][(int)$r['filial_id']] ??= array_fill(1, 12, 0.0);
                $saida[$n]['filiais'][(int)$r['filial_id']][(int)$r['mes']] = (float)$r['valor'];
            }
        }
        return $saida;
    }

    /**
     * As linhas de valor FIXO (travadas) de um negócio ou filial, no formato que
     * `aplicar` e `somarFiliais` recebem. Filial sem depreciação carregada, num
     * negócio que tem carga, vale ZERO: a carga é o total da depreciação.
     *
     * @param array|null $dep um item de `depreciacao()`
     * @return array<string, array{valor: float, meses: array<int,float>, conta: string}>
     */
    public static function fixosDaDepreciacao(?array $dep, int $filialId = 0, string $codNegocio = ''): array
    {
        if (!$dep) {
            return [];
        }
        $meses = $filialId ? ($dep['filiais'][$filialId] ?? array_fill(1, 12, 0.0)) : $dep['negocio'];
        return [self::LINHA_DEPRECIACAO => ['valor' => array_sum($meses), 'meses' => $meses,
            'conta' => self::CONTA_DEPRECIACAO, 'livre' => self::depreciacaoEditavel($codNegocio)]];
    }

    /**
     * Os negócios que EDITAM a depreciação (pedido do cliente, 2026-11-24: "somente o
     * negócio Pecuária deve poder editar a depreciação conforme desejar; os demais
     * negócios não"). Neles a 8.10 vem com a carga como padrão e aceita lançamento;
     * nos outros, a carga é travada.
     */
    public const NEGOCIOS_DEPRECIACAO_EDITAVEL = ['2'];

    public static function depreciacaoEditavel(string $codNegocio): bool
    {
        return in_array($codNegocio, self::NEGOCIOS_DEPRECIACAO_EDITAVEL, true);
    }

    /**
     * O RATEIO CORPORATIVO ADMINISTRATIVO (linha 16.1) do ano da proposta, por
     * negócio e mês (pedido do cliente, 2026-11-24). O total a ratear vem da carga
     * (`database/rateio_adm_<ano>.php`: o saldo planejado dos centros de custo
     * corporativos da Matriz, mês a mês); a parte de cada negócio é a proporção da
     * proposta dele nas linhas de `CRITERIO_RATEIO_ADM` — calculada NA HORA, então
     * muda quando um negócio lança despesa (decisão do cliente: "recalcular ao
     * vivo"). Travada na proposta, como a depreciação. A 14.3 não muda.
     */
    public const LINHA_RATEIO_ADM = '16.1';
    /**
     * As linhas do critério (pedido do cliente, 2026-11-24): despesas com vendas,
     * com promoções, com pessoal, serviços profissionais, serviços em geral,
     * manutenção, materiais e viagens. As contas sem valor da planilha de critério
     * (carga e descarga, royalties, mercadorias deterioradas, frete de
     * transferência) são custo na DRE gerencial e já não estão nessas linhas.
     */
    public const CRITERIO_RATEIO_ADM = ['8.1', '8.2', '8.5', '8.7', '8.8', '8.11', '8.12', '8.14'];

    /** A carga do total a ratear de um ano, conferida, ou null se não houver. */
    public static function cargaRateioAdm(int $anoPlan): ?array
    {
        $arquivo = __DIR__ . '/../../database/rateio_adm_' . $anoPlan . '.php';
        if (!is_file($arquivo)) {
            return null;
        }
        $c = require $arquivo;
        $meses = $c['meses'] ?? [];
        if ((int)($c['ano'] ?? 0) !== $anoPlan || array_keys($meses) !== range(1, 12)
            || abs(array_sum($meses) - (float)($c['total'] ?? 0)) > 0.005) {
            throw new \RuntimeException("Carga do rateio administrativo de {$anoPlan} inválida: 12 meses que somem o total.");
        }
        return $c;
    }

    /** O critério de UMA DRE já resolvida: a despesa proposta nas linhas do critério, positiva. */
    public static function criterioRateioAdm(array $linhas): float
    {
        $soma = 0.0;
        foreach ($linhas as $l) {
            if (in_array($l['codigo'], self::CRITERIO_RATEIO_ADM, true)) {
                $soma += (float)($l['proposta'] ?? 0.0);
            }
        }
        return max(0.0, -$soma);
    }

    /**
     * O rateio administrativo de TODOS os negócios ativos com DRE no ano: por
     * negócio, o critério, a parte e os 12 meses (sinal da DRE). Null sem carga.
     *
     * @return array{negocios: array<int, array{criterio: float, parte: float, meses: array<int,float>, valor: float}>, criterio_total: float, total: float, fonte: string}|null
     */
    public static function rateioAdm(int $ano, int $corte, int $anoPlan): ?array
    {
        return self::rateios($ano, $corte, $anoPlan)['adm'];
    }

    /**
     * Os dois rateios travados de uma vez — 16.1 administrativo e 14.3 negócio —,
     * da MESMA base: a proposta de 2027 de todos os negócios (e das filiais, para
     * a 14.3) nas linhas dos critérios, calculada sem os próprios rateios.
     *
     * @return array{adm: ?array, neg: ?array}
     */
    public static function rateios(int $ano, int $corte, int $anoPlan): array
    {
        $cAdm = self::cargaRateioAdm($anoPlan);
        $cNeg = self::cargaRateioNegocios($anoPlan);
        if (!$cAdm && !$cNeg) {
            return ['adm' => null, 'neg' => null];
        }
        $ids = array_map('intval', array_column(Database::todos(
            'SELECT n.id FROM negocio n WHERE n.ativo = 1
               AND EXISTS (SELECT 1 FROM dre_valor v WHERE v.negocio_id = n.id AND v.ano = ?)',
            [$ano]
        ), 'id'));
        $base = self::resumoDosNegocios($ids, $ano, $corte, $anoPlan,
            array_merge(['1'], self::CRITERIO_RATEIO_ADM), false, (bool)$cNeg);
        return [
            'adm' => $cAdm ? self::rateioAdmDe($cAdm, $base) : null,
            'neg' => $cNeg ? self::rateioNegociosDe($cNeg, $base) : null,
        ];
    }

    /** O valor de um critério numa proposta (do negócio ou da filial): despesa ou faturamento, positivo. */
    private static function valorDoCriterio(array $proposta, string $tipo): float
    {
        if ($tipo === 'FATURAMENTO') {
            return max(0.0, (float)($proposta['1'] ?? 0.0));
        }
        $s = 0.0;
        foreach (self::CRITERIO_RATEIO_ADM as $c) {
            $s += (float)($proposta[$c] ?? 0.0);
        }
        return max(0.0, -$s);
    }

    /** O rateio administrativo a partir da base dos rateios. */
    private static function rateioAdmDe(array $carga, array $base): array
    {
        $criterios = [];
        foreach ($base as $id => $r) {
            $criterios[(int)$id] = self::valorDoCriterio($r['proposta'], 'DESPESAS');
        }
        $total = array_sum($criterios);
        $saida = [];
        foreach ($criterios as $id => $c) {
            $parte = $total > 0 ? $c / $total : 0.0;
            $meses = [];
            foreach ($carga['meses'] as $m => $v) {
                $meses[(int)$m] = (float)$v * $parte;
            }
            $saida[$id] = ['criterio' => $c, 'parte' => $parte, 'meses' => $meses, 'valor' => array_sum($meses)];
        }
        return ['negocios' => $saida, 'criterio_total' => $total, 'total' => (float)$carga['total'], 'fonte' => (string)$carga['fonte']];
    }

    /**
     * RATEIO CORPORATIVO NEGÓCIO (linha 14.3), travado (pedido do cliente,
     * 2026-11-24). O valor de cada centro de custo, mês a mês, vem da carga
     * (`database/rateio_negocios_<ano>.php`); a distribuição segue a planilha de
     * critérios: só os negócios da regra (coluna H), só as filiais listadas (coluna
     * G; "Todas" = todas as do negócio), na proporção da proposta de 2027 no critério
     * — despesas (as linhas de `CRITERIO_RATEIO_ADM`) ou faturamento (receita bruta).
     * Ao vivo, como a 16.1.
     */
    public const LINHA_RATEIO_NEG = '14.3';
    /** Os critérios da planilha que são FATURAMENTO (receita bruta); os demais são despesas. */
    public const CRITERIOS_FATURAMENTO = [16, 22, 33, 37];

    /** A carga do rateio negócio de um ano, conferida, ou null se não houver. */
    public static function cargaRateioNegocios(int $anoPlan): ?array
    {
        $arquivo = __DIR__ . '/../../database/rateio_negocios_' . $anoPlan . '.php';
        if (!is_file($arquivo)) {
            return null;
        }
        $c = require $arquivo;
        $soma = 0.0;
        foreach ($c['centros'] ?? [] as $cc => $x) {
            if (array_keys($x['meses'] ?? []) !== range(1, 12)) {
                throw new \RuntimeException("Rateio negócio {$anoPlan}: o centro {$cc} não tem os 12 meses.");
            }
            $soma += array_sum($x['meses']);
        }
        if ((int)($c['ano'] ?? 0) !== $anoPlan || !$c['centros'] || abs($soma - (float)($c['total'] ?? 0)) > 0.005) {
            throw new \RuntimeException("Carga do rateio negócio de {$anoPlan} inválida: os centros têm de somar o total.");
        }
        return $c;
    }

    /**
     * O rateio negócio a partir da base: por negócio, os 12 meses, o valor, os 12
     * meses de cada filial e quanto veio de cada centro de custo.
     */
    private static function rateioNegociosDe(array $carga, array $base): array
    {
        $cods = [];
        foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
            $cods[(int)$n['id']] = (string)$n['cod_negocio'];
        }
        $porCod = array_flip($cods);
        $filialDe = [];   // [negocio_id][cod_filial] => filial_id
        foreach (Database::todos('SELECT id, negocio_id, cod_filial FROM filial') as $f) {
            $filialDe[(int)$f['negocio_id']][(string)$f['cod_filial']] = (int)$f['id'];
        }
        $zeros = array_fill(1, 12, 0.0);
        $saida = [];
        foreach ($carga['centros'] as $cc => $c) {
            $tipo = in_array((int)($c['criterio'] ?? 0), self::CRITERIOS_FATURAMENTO, true) ? 'FATURAMENTO' : 'DESPESAS';
            if (($c['negocios'][0] ?? null) === 'TODOS') {
                $exceto = array_map('strval', $c['negocios']['exceto'] ?? []);
                $negs = array_values(array_filter(array_keys($base), static fn ($id) => !in_array($cods[(int)$id] ?? '', $exceto, true)));
            } else {
                $negs = array_values(array_filter(array_map(static fn ($cod) => $porCod[(string)$cod] ?? null, $c['negocios']),
                    static fn ($id) => $id !== null && isset($base[$id])));
            }
            $pesoNeg = [];
            $pesoFil = [];
            foreach ($negs as $n) {
                $props = $base[$n]['filiais'] ?? [];
                $fids = $c['filiais'] === 'TODAS'
                    ? array_keys($props)
                    : array_values(array_filter(array_map(static fn ($cf) => $filialDe[$n][(string)$cf] ?? null, $c['filiais'])));
                foreach ($fids as $fid) {
                    $pesoFil[$n][$fid] = self::valorDoCriterio($props[$fid] ?? [], $tipo);
                }
                // Todas as filiais: o negócio pesa pela proposta DELE; filiais listadas:
                // pela soma delas (o centro só atende essas).
                $pesoNeg[$n] = $c['filiais'] === 'TODAS'
                    ? self::valorDoCriterio($base[$n]['proposta'], $tipo)
                    : array_sum($pesoFil[$n] ?? []);
            }
            $total = array_sum($pesoNeg);
            foreach ($negs as $n) {
                // Um negócio só leva o centro inteiro; sem critério com valor, divide igual.
                $parte = count($negs) === 1 ? 1.0 : ($total > 0 ? $pesoNeg[$n] / $total : 1.0 / count($negs));
                if ($parte <= 0) {
                    continue;
                }
                $s = &$saida[$n];
                $s ??= ['meses' => $zeros, 'valor' => 0.0, 'filiais' => [], 'centros' => []];
                $somaFil = array_sum($pesoFil[$n] ?? []);
                foreach ($c['meses'] as $m => $v) {
                    $val = (float)$v * $parte;
                    $s['meses'][$m] += $val;
                    if ($somaFil > 0) {
                        foreach ($pesoFil[$n] as $fid => $w) {
                            $s['filiais'][$fid] ??= $zeros;
                            $s['filiais'][$fid][$m] += $val * $w / $somaFil;
                        }
                    }
                }
                $s['centros'][(int)$cc] = array_sum($c['meses']) * $parte;
                unset($s);
            }
        }
        foreach ($saida as &$s) {
            $s['valor'] = array_sum($s['meses']);
        }
        unset($s);
        return ['negocios' => $saida, 'total' => (float)$carga['total'], 'fora' => $carga['fora'] ?? [],
            'fonte' => (string)$carga['fonte']];
    }

    /**
     * RATEIO FINANCEIRO (linha 14.2) de 2027, por negócio e mês, travado (pedido do
     * cliente, 2026-11-24). Carga fixa (`database/rateio_financeiro_<ano>.php`): a
     * planilha já traz o valor de cada negócio; não há critério a calcular.
     */
    public const LINHA_RATEIO_FIN = '14.2';

    /**
     * RATEIO MATRIZ/CENTRAL (linha 14.1) ZERADO na proposta (pedido do cliente,
     * 2026-11-25: "zere o valor para os negócios"). A conta 9999 fecha em zero na
     * cooperativa todo mês, mas o crédito na origem não fecha dentro de cada
     * negócio: sobra um resíduo (R$ 770 nas Agropecuárias, −R$ 643 nos
     * Supermercados…) que a projeção levaria como meta. O realizado de 2026 fica
     * como no Qlik; a proposta e o desafio de todo negócio e filial valem zero,
     * travados.
     */
    public const LINHA_RATEIO_MATRIZ = '14.1';

    /** A 14.1 travada em zero, todos os meses. */
    public static function fixosDoRateioMatriz(): array
    {
        return [self::LINHA_RATEIO_MATRIZ => ['valor' => 0.0, 'meses' => array_fill(1, 12, 0.0), 'conta' => null,
            'origem' => 'RATEIO_MATRIZ']];
    }

    /** A carga do rateio financeiro de um ano, conferida, ou null se não houver. */
    public static function cargaRateioFin(int $anoPlan): ?array
    {
        $arquivo = __DIR__ . '/../../database/rateio_financeiro_' . $anoPlan . '.php';
        if (!is_file($arquivo)) {
            return null;
        }
        $c = require $arquivo;
        $soma = 0.0;
        foreach ($c['negocios'] ?? [] as $cod => $meses) {
            if (array_keys($meses) !== range(1, 12)) {
                throw new \RuntimeException("Rateio financeiro {$anoPlan}: o negócio {$cod} não tem os 12 meses.");
            }
            $soma += array_sum($meses);
        }
        if ((int)($c['ano'] ?? 0) !== $anoPlan || abs($soma - (float)($c['total'] ?? 0)) > 0.005) {
            throw new \RuntimeException("Carga do rateio financeiro de {$anoPlan} inválida: os negócios têm de somar o total.");
        }
        return $c;
    }

    /** Os 12 meses do rateio financeiro de UM negócio (pelo id), ou null. */
    public static function rateioFinDoNegocio(int $negocioId, int $anoPlan): ?array
    {
        $c = self::cargaRateioFin($anoPlan);
        if (!$c) {
            return null;
        }
        $cod = (string)(Database::um('SELECT cod_negocio FROM negocio WHERE id = ?', [$negocioId])['cod_negocio'] ?? '');
        $meses = $c['negocios'][$cod] ?? null;
        return $meses === null ? null : array_map('floatval', $meses);
    }

    /**
     * A 14.2 travada de um negócio, ou de uma filial: `$parteFilial` é a parte dela
     * (a projeção de 2026 da 14.2 da filial sobre a das filiais do negócio).
     */
    public static function fixosDoRateioFin(?array $meses, ?float $parteFilial = null): array
    {
        if ($meses === null) {
            return [];
        }
        $f = $parteFilial ?? 1.0;
        $m = array_map(static fn ($v) => (float)$v * $f, $meses);
        return [self::LINHA_RATEIO_FIN => ['valor' => array_sum($m), 'meses' => $m, 'conta' => null, 'origem' => 'RATEIO_FIN']];
    }

    /** A 14.3 travada de um negócio, ou de uma filial (os meses dela; sem parte, zero). */
    public static function fixosDoRateioNeg(?array $rateio, ?int $filialId = null): array
    {
        if (!$rateio) {
            return [];
        }
        $meses = $filialId === null ? $rateio['meses'] : ($rateio['filiais'][$filialId] ?? array_fill(1, 12, 0.0));
        return [self::LINHA_RATEIO_NEG => ['valor' => array_sum($meses), 'meses' => $meses, 'conta' => null,
            'origem' => 'RATEIO_NEG']];
    }

    /**
     * A 16.1 travada de um negócio (`$rateio` de `rateioAdm`, a entrada dele), ou
     * de uma filial: `$parteFilial` é a parte dela no critério do negócio.
     */
    public static function fixosDoRateioAdm(?array $rateio, ?float $parteFilial = null): array
    {
        if (!$rateio) {
            return [];
        }
        $f = $parteFilial ?? 1.0;
        $meses = array_map(static fn ($v) => (float)$v * $f, $rateio['meses']);
        return [self::LINHA_RATEIO_ADM => ['valor' => array_sum($meses), 'meses' => $meses, 'conta' => null,
            'origem' => 'RATEIO_ADM', 'parte' => $rateio['parte'] * $f, 'criterio' => $rateio['criterio']]];
    }

    /**
     * Trava linhas já resolvidas (o valor fixo por cima do que houver) e refaz os
     * blocos — para o rateio da FILIAL, que só se conhece depois das propostas
     * das filiais (o critério sai delas).
     */
    public static function fixar(array $linhas, array $fixos): array
    {
        foreach ($linhas as $i => $l) {
            if (isset($fixos[$l['codigo']])) {
                $linhas[$i] = self::travar($l, $fixos[$l['codigo']], $l['proposta_lancado'] ?? null);
            }
        }
        return self::derivar($linhas);
    }

    /** Põe numa linha o valor fixo (travado), ignorando o que estiver lançado nela. */
    private static function travar(array $linha, array $fixo, ?array $lancado): array
    {
        $linha['proposta'] = (float)$fixo['valor'];
        $linha['proposta_origem'] = $fixo['origem'] ?? 'DEPRECIACAO';
        if (isset($fixo['parte'])) {
            $linha['proposta_parte'] = $fixo['parte'];
        }
        $linha['proposta_lancado'] = null;
        $linha['proposta_padrao'] = null;
        $linha['proposta_regra'] = null;
        $linha['proposta_travada'] = true;
        $linha['proposta_meses'] = $fixo['meses'];
        $linha['proposta_conta'] = $fixo['conta'];
        // Um lançamento antigo continua guardado, mas não vale: a tela diz.
        $linha['lancado_ignorado'] = $lancado;
        return $linha;
    }

    /**
     * O NÍVEL 3 da DRE — a conta reduzida, como a aba "#2 Planejado X Realizado"
     * do Qlik (pedido do cliente, 2026-11-21): por linha do nível 2, as contas
     * reduzidas com o realizado acumulado até o corte, na ordem do Qlik. Só
     * leitura e só realizado: a proposta é lançada no nível 2, e o Qlik não tem
     * orçado por conta reduzida — projetado, projeção e proposta ficam em branco.
     *
     * Vários negócios (o Corporativo) somam; `$filialId` 0 é o negócio inteiro.
     * No formato da carga (PIS/COFINS dentro da 2.3): `nivel3FormatoNovo` leva à
     * tela.
     *
     * @param int[] $negocioIds
     * @return array<string, list<array{codigo:string, reduzida:string, nome:string, realizado:float}>>
     */
    public static function nivel3(array $negocioIds, int $ano, int $filialId = 0): array
    {
        $negocioIds = array_values(array_unique(array_map('intval', $negocioIds)));
        if (!$negocioIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        $saida = [];
        foreach (Database::todos(
            "SELECT r.linha, r.codigo, r.reduzida, r.nome, SUM(v.realizado) AS realizado
               FROM dre_reduzida_valor v JOIN dre_reduzida r ON r.codigo = v.codigo
              WHERE v.ano = ? AND v.filial_id = ? AND v.negocio_id IN ({$marcas})
              GROUP BY r.linha, r.codigo, r.reduzida, r.nome, r.ordem
             HAVING ABS(SUM(v.realizado)) >= 0.5
              ORDER BY r.ordem",
            array_merge([$ano, $filialId], $negocioIds)
        ) as $r) {
            $saida[$r['linha']][] = ['codigo' => $r['codigo'], 'reduzida' => $r['reduzida'],
                'nome' => $r['nome'], 'realizado' => (float)$r['realizado']];
        }
        return $saida;
    }

    /**
     * O nível 3 no FORMATO DE 2027, o mesmo de `Reforma::formatoNovo`: o
     * PIS/COFINS sobre as vendas sai da Receita de Vendas e do Imposto s/ Vendas
     * e vira a linha informativa da CBS. As contas reduzidas de PIS e COFINS da
     * 2.3 passam para baixo da CBS (no sinal dela, positivo), e a Receita de
     * Vendas ganha a linha que tira o PIS/COFINS — para que, nas três, o nível 3
     * continue somando o nível 2 que a tela mostra.
     *
     * @param array $tributosDre o `comoEComoFica` da mesma visão
     */
    public static function nivel3FormatoNovo(array $nivel3, array $tributosDre): array
    {
        $col = $tributosDre['colunas'] ?? [];
        if (!$col || !$nivel3) {
            return $nivel3;
        }
        $pc = (float)$col['realizado']['como_e']['pis_cofins'];
        $ficam = [];
        foreach ($nivel3['2.3'] ?? [] as $r) {
            if (preg_match('/^(PIS|COFINS)\b/u', $r['nome'])) {
                $nivel3['CBS'][] = ['realizado' => -$r['realizado']] + $r;
            } else {
                $ficam[] = $r;
            }
        }
        if (isset($nivel3['2.3'])) {
            $nivel3['2.3'] = $ficam;
        }
        if (isset($nivel3['1.1']) && abs($pc) >= 0.5) {
            $nivel3['1.1'][] = ['codigo' => 'CBS', 'reduzida' => '', 'realizado' => -$pc,
                'nome' => '(−) PIS/COFINS das vendas, que em 2027 é a CBS (linha informativa acima)'];
        }
        return $nivel3;
    }


    /**
     * Quanto cada linha CRESCE por omissão, a partir das premissas do negócio
     * no ano da proposta (pedido do cliente, 2026-11-18).
     *
     *  - **Receita de Vendas**: IPCA + PIB + crescimento desejado, SOMADOS. É a
     *    mesma conta que o painel desfaz ao ler "Cresc. Real" (total − IPCA −
     *    PIB), então com a proposta nascida daqui o Cresc. Real é o crescimento
     *    desejado, e o Cresc. Total é a soma dos três.
     *  - **Despesas com Pessoal**: SÓ o percentual das despesas com pessoas. Ele
     *    não toca em nenhuma outra linha, e IPCA e PIB também não tocam nela.
     *  - Deduções e custo das vendas ACOMPANHAM a receita (`ACOMPANHAM_RECEITA`,
     *    em `aplicar`, decisão de 2026-11-18); as demais despesas seguem a
     *    projeção ("sem previsão de crescimento", diretriz de 2027).
     *  - No ano com diretriz, quem chama é `crescimentosDoNegocio`, que põe a
     *    diretriz por cima do digitado e troca o % das Despesas com Pessoal pelo
     *    da conta de `pessoalDaDiretriz` (PPR, vale e prêmios).
     *
     * O que foi DIGITADO numa linha sempre manda (nas Despesas com Pessoal, com
     * o ⚠ de `marcarMinimoPessoal` quando fica abaixo do mínimo): o
     * crescimento por omissão só preenche as linhas em branco. Sem nenhuma premissa lançada não há
     * crescimento nenhum (e a linha vale a projeção, como sempre valeu): uma
     * premissa ausente não é "0%", é "não decidida".
     *
     * @param array<string,float|string|null> $digitadas chave => percentual digitado, do ano da proposta
     * @return array<string,float> código da linha => percentual de crescimento
     */
    public static function crescimentosPadrao(array $digitadas): array
    {
        $saida = [];
        $geral = null;
        foreach (['IPCA', 'PIB', 'CRESCIMENTO'] as $k) {
            if (isset($digitadas[$k])) {
                $geral = ($geral ?? 0.0) + (float)$digitadas[$k];
            }
        }
        if ($geral !== null) {
            $saida[self::LINHA_RECEITA] = $geral;
        }
        if (isset($digitadas['PESSOAS'])) {
            $saida[self::LINHA_PESSOAL] = (float)$digitadas['PESSOAS'];
        }
        return $saida;
    }

    /**
     * Os anos em que se lançam premissas: do ANO DA PROPOSTA ao último do ciclo.
     *
     * Começa no ano da proposta porque é o primeiro que ainda se planeja — o
     * que já fechou não recebe premissa. Termina no fim do ciclo porque é até
     * onde o planejamento enxerga. Sem ciclo que cubra o ano (`$anoFimCiclo`
     * nulo ou anterior), devolve só o ano da proposta, e nunca uma lista vazia:
     * o painel precisa de ao menos um ano para existir.
     *
     * @return int[]
     */
    public static function anosDasPremissas(int $anoProposta, ?int $anoFimCiclo): array
    {
        return range($anoProposta, max($anoProposta, (int)$anoFimCiclo));
    }

    /**
     * Copia para `negocio_premissa` as premissas que estavam penduradas na
     * projeção — o que o cliente JÁ DIGITOU antes de elas terem ano próprio.
     *
     * Só a linha do NEGÓCIO (`filial_id = 0`), e o ano vem da projeção: era o
     * ano da proposta, que é onde a tela as mostrava. A linha por FILIAL fica de
     * fora, de propósito — a decisão de 2026-11-16 foi que a premissa é do
     * negócio, e promover um IPCA de filial ao negócio escolheria, entre as
     * filiais, qual delas manda.
     *
     * `INSERT IGNORE`: o que já está na tabela nova NÃO é sobrescrito. Mas isto
     * NÃO basta para rodar a cada deploy — uma premissa que alguém apagou na
     * tabela nova seria RESSUSCITADA daqui no deploy seguinte. Quem chama roda
     * uma vez só, sob marca em `carga_conteudo` (ver o migrate).
     *
     * `$negocioId` restringe a cópia a UM negócio. O migrate chama sem ele — a
     * migração é de todos —, e existe para o teste não varrer os negócios reais
     * do banco em que roda.
     *
     * @return int quantas premissas entraram
     */
    public static function migrarPremissasParaNegocio(\PDO $pdo, ?int $negocioId = null): int
    {
        $filtro = $negocioId === null ? '' : ' AND p.negocio_id = ' . (int)$negocioId;
        return (int)$pdo->exec(
            'INSERT IGNORE INTO negocio_premissa (negocio_id, ano, chave, percentual)
             SELECT p.negocio_id, p.ano, pp.chave, pp.percentual
               FROM projecao_premissa pp
               JOIN projecao p ON p.id = pp.projecao_id
              WHERE pp.filial_id = 0' . $filtro
        );
    }

    /**
     * O painel de indicadores, montado como a planilha monta o consolidado:
     *
     *   Cresc. Total = proposta da receita ÷ projeção da receita − 1   (B14)
     *   Cresc. Real  = Cresc. Total − IPCA − PIB                       (B13)
     *   % Custos     = proposta dos custos ÷ projeção dos custos − 1   (B17)
     *
     * IPCA, PIB e o crescimento desejado entram por digitação; os três acima são
     * LEITURA. Deixar "Cresc. Total" também ser digitado criaria duas fontes
     * para o mesmo número — o painel e a célula da receita —, e a primeira
     * divergência entre elas apareceria como meta errada na reunião. Quem quer
     * lançar o crescimento da proposta lança na linha, que é onde ele vale; o
     * painel mostra no que deu, e decompõe. A meta digitada convive com o
     * "Cresc. Total" lido exatamente por isso: um é o que se QUER, o outro é o
     * que as linhas SOMAM, e a distância entre os dois é a informação.
     *
     * `$digitadas` é SEMPRE o conjunto do negócio, também quando `$linhas` é as
     * de uma filial: a filial herda as três premissas, e os três derivados são
     * dela. "Cresc. Real" da filial é o crescimento dela menos o IPCA e o PIB
     * do negócio.
     */
    public static function premissas(array $linhas, array $digitadas): array
    {
        $acha = static function (string $cod) use ($linhas) {
            foreach ($linhas as $l) {
                if ($l['codigo'] === $cod) {
                    return $l;
                }
            }
            return null;
        };
        $variacao = static function (?array $l): ?float {
            if (!$l || empty($l['projecao']) || !isset($l['proposta'])) {
                return null;
            }
            return 100 * ((float)$l['proposta'] / (float)$l['projecao'] - 1);
        };

        $ipca = isset($digitadas['IPCA']) ? (float)$digitadas['IPCA'] : null;
        $pib  = isset($digitadas['PIB']) ? (float)$digitadas['PIB'] : null;
        $meta = isset($digitadas['CRESCIMENTO']) ? (float)$digitadas['CRESCIMENTO'] : null;
        $pessoas = isset($digitadas['PESSOAS']) ? (float)$digitadas['PESSOAS'] : null;
        $total = $variacao($acha('1'));
        $custos = $variacao($acha('4'));

        return [
            'IPCA' => $ipca,
            'PIB' => $pib,
            // Devolvidas como digitadas; quem as usa em conta é `crescimentosPadrao`.
            'CRESCIMENTO' => $meta,
            'PESSOAS' => $pessoas,
            'CRESC_TOTAL' => $total,
            // Sem IPCA e PIB lançados não há o que decompor: devolver o próprio
            // total como "real" diria que a inflação é zero, que é uma
            // afirmação — e não uma ausência.
            'CRESC_REAL' => ($total === null || $ipca === null || $pib === null)
                ? null : $total - $ipca - $pib,
            'PCT_CUSTOS' => $custos,
        ];
    }

    /** Recebe proposta quem é subconta (nível 2) e não é linha de percentual. */
    public static function editavel(array $conta): bool
    {
        return (int)$conta['nivel'] === 2 && !(int)$conta['percentual'];
    }

    /**
     * O valor proposto de UMA linha editável.
     *
     * Sem proposta lançada, a linha vale a PROJEÇÃO — crescimento zero. É o que
     * deixa a DRE inteira de pé desde a primeira abertura, com a pessoa mexendo
     * só no que muda; e é por isso que a tela marca a diferença entre o que foi
     * digitado e o que está ali por omissão.
     *
     * @param array|null $lancado ['entrada'=>'VALOR'|'PERCENTUAL','valor'=>?,'percentual'=>?]
     */
    public static function valorProposto(float $projecao, ?array $lancado, ?float $padrao = null): array
    {
        if (!$lancado) {
            // Sem lançamento a linha vale a projeção — a menos que as premissas
            // do negócio digam quanto ela cresce (`crescimentosPadrao`). Essa
            // origem é PREMISSA, e não PROJECAO: a tela precisa dizer que o
            // número nasceu de IPCA + PIB + crescimento desejado (ou do
            // crescimento das despesas com pessoas), e não de omissão.
            if ($padrao !== null) {
                return ['valor' => $projecao * (1 + $padrao / 100), 'origem' => 'PREMISSA',
                        'percentual' => $padrao, 'lancado' => null];
            }
            return ['valor' => $projecao, 'origem' => 'PROJECAO',
                    'percentual' => null, 'lancado' => null];
        }
        if ($lancado['entrada'] === 'PERCENTUAL') {
            $pct = (float)$lancado['percentual'];
            return ['valor' => $projecao * (1 + $pct / 100), 'origem' => 'PERCENTUAL',
                    'percentual' => $pct, 'lancado' => $pct];
        }
        return ['valor' => (float)$lancado['valor'], 'origem' => 'VALOR',
                'percentual' => null, 'lancado' => (float)$lancado['valor']];
    }

    /**
     * Completa as linhas com a proposta: resolve as editáveis, deriva os
     * blocos e deixa `null` onde não há regra.
     *
     * @param array $linhas  cada uma com `codigo`, `nivel`, `percentual`, `projecao`
     * @param array $lancados codigo => ['entrada'=>…, 'valor'=>…, 'percentual'=>…]
     * @param float|null $taxaPisCofins a taxa de PIS/COFINS sobre a Receita de Vendas
     *        (`Reforma::pisCofinsDoNegocio`): com ela, o que se LANÇA na 1.1 e na 2.3 é
     *        no formato de 2027 — sem o PIS/COFINS (a CBS) — e o motor o devolve ao
     *        formato da carga para calcular.
     */
    public static function aplicar(array $linhas, array $lancados, array $crescimentos = [], ?float $taxaPisCofins = null, array $fixos = [], ?array $acompanham = null): array
    {
        $acompanham ??= self::ACOMPANHAM_RECEITA;
        $t = $taxaPisCofins !== null && $taxaPisCofins > 0 && $taxaPisCofins < 1 ? $taxaPisCofins : null;
        // As editáveis, uma a uma; os blocos saem de `derivar`.
        foreach ($linhas as $i => $l) {
            if (!self::editavel($l)) {
                continue;
            }
            // As Despesas com Pessoal se LANÇAM de novo desde 2026-11-23 (pedido
            // do cliente): o padrão vem do cartão Pessoas e benefícios, e o
            // lançado manda — acima (contratação, reajuste) livre, abaixo do
            // mínimo com o ⚠ de `marcarMinimoPessoal`. Entre 21 e 23/11 a linha
            // ficou travada no padrão; não a trave de volta sem o cliente.
            $r = self::valorProposto(
                (float)$l['projecao'],
                $lancados[$l['codigo']] ?? null,
                $crescimentos[$l['codigo']] ?? null
            );
            // Formato de 2027: a Receita de Vendas LANÇADA em valor é sem a CBS; o
            // motor calcula com ela dentro (a CBS acompanha a receita).
            if ($t !== null && $l['codigo'] === '1.1' && $r['origem'] === 'VALOR') {
                $r['valor'] /= (1 - $t);
            }
            $linhas[$i]['proposta'] = $r['valor'];
            $linhas[$i]['proposta_origem'] = $r['origem'];
            $linhas[$i]['proposta_lancado'] = $r['lancado'];
            // O crescimento por omissão que gerou o número, para a tela mostrar
            // de onde ele veio (null quando a linha foi lançada ou não cresce).
            $linhas[$i]['proposta_padrao'] = $r['origem'] === 'PREMISSA' ? $r['percentual'] : null;
            $linhas[$i]['proposta_regra'] = $r['origem'] === 'PREMISSA'
                ? ($l['codigo'] === self::LINHA_PESSOAL ? 'PESSOAL' : 'PREMISSA') : null;
            // A DEPRECIAÇÃO carregada (`fixosDaDepreciacao`): o valor é o da carga
            // e o lançado não vale (pedido do cliente, 2026-11-23). Na Pecuária
            // (`depreciacaoEditavel`, 2026-11-24) a carga é só o padrão: o lançado
            // manda, e sem lançamento a linha vale a carga, sem trava.
            if (isset($fixos[$l['codigo']])) {
                $fixo = $fixos[$l['codigo']];
                if (!empty($fixo['livre']) && isset($lancados[$l['codigo']])) {
                    continue;
                }
                $linhas[$i] = self::travar($linhas[$i], $fixo, $lancados[$l['codigo']] ?? null);
                if (!empty($fixo['livre'])) {
                    $linhas[$i]['proposta_travada'] = false;
                    $linhas[$i]['lancado_ignorado'] = null;
                }
                continue;
            }
            // O PISO do faturamento (pedido do cliente, 2026-11-19): a Receita de
            // Vendas não pode ficar abaixo do crescimento real total do negócio
            // — IPCA + PIB + crescimento desejado. Acima pode (o negócio se
            // desafia mais); abaixo, a tela aponta a inconsistência. Não recusa
            // o lançamento: o número é do negócio, e a diretriz é cobrada à
            // vista de todos em vez de travada no campo. Comparado no formato
            // do motor (com a CBS dentro dos dois lados), por isso aqui e não na
            // tela, que mostra a receita sem a CBS.
            //
            // Desde 2026-11-21 o piso TRAVA, inclusive o que já estava lançado e
            // nas filiais (pedido do cliente: "não deve permitir o lançamento
            // menor… o sistema deve voltar os dados até totalizar o percentual
            // estimado"; "já faça para as filiais também"). Lançamento abaixo do
            // piso NÃO VALE: a linha fica no piso (a premissa) e sai marcada
            // (`piso_aplicado`, com o valor recusado em `lancado_abaixo`, no
            // formato do motor) para a tela explicar. O lançamento continua
            // guardado: se a premissa baixar, ele volta a valer.
            if ($l['codigo'] === self::LINHA_RECEITA && isset($crescimentos[$l['codigo']])) {
                $piso = (float)$l['projecao'] * (1 + (float)$crescimentos[$l['codigo']] / 100);
                $linhas[$i]['piso_percentual'] = (float)$crescimentos[$l['codigo']];
                $linhas[$i]['piso_aplicado'] = false;
                $linhas[$i]['abaixo_piso'] = false;
                if ((float)$l['projecao'] > 0 && $r['origem'] !== 'PREMISSA' && $r['origem'] !== 'PROJECAO' && $r['valor'] < $piso - 1) {
                    $linhas[$i]['lancado_abaixo'] = $r['valor'];
                    // O lançamento como foi guardado (entrada, valor, percentual): é o
                    // que a tela devolve se alguém quiser relançá-lo, e o que a
                    // bateria guarda e põe de volta.
                    $linhas[$i]['lancado_recusado'] = $lancados[$l['codigo']] ?? null;
                    $r = self::valorProposto((float)$l['projecao'], null, (float)$crescimentos[$l['codigo']]);
                    $linhas[$i]['proposta'] = $r['valor'];
                    $linhas[$i]['proposta_origem'] = $r['origem'];
                    $linhas[$i]['proposta_lancado'] = null;
                    $linhas[$i]['proposta_padrao'] = $r['percentual'];
                    $linhas[$i]['proposta_regra'] = 'PREMISSA';
                    $linhas[$i]['piso_aplicado'] = true;
                }
            }
        }

        // Deduções e custo acompanham a receita (ACOMPANHAM_RECEITA), onde não
        // foram lançados: a proporção é a da Receita de Vendas — lançada ou por
        // premissa, tanto faz.
        $idx = array_flip(array_column($linhas, 'codigo'));
        $rec = isset($idx[self::LINHA_RECEITA]) ? $linhas[$idx[self::LINHA_RECEITA]] : null;
        if ($rec && (float)$rec['projecao'] != 0.0 && isset($rec['proposta'])) {
            $fator = (float)$rec['proposta'] / (float)$rec['projecao'];
            if (abs($fator - 1) > 1e-12) {
                foreach ($acompanham as $c) {
                    // Despesa (fora de ACOMPANHAM_RECEITA) só acompanha para cima.
                    $f = in_array($c, self::ACOMPANHAM_RECEITA, true) ? $fator : max($fator, 1.0);
                    if (!isset($idx[$c]) || abs($f - 1) <= 1e-12) {
                        continue;
                    }
                    $l = &$linhas[$idx[$c]];
                    if (($l['proposta_origem'] ?? null) === 'PROJECAO') {
                        $l['proposta'] = (float)$l['projecao'] * $f;
                        $l['proposta_origem'] = 'PREMISSA';
                        $l['proposta_padrao'] = ($f - 1) * 100;
                        $l['proposta_regra'] = 'RECEITA';
                    }
                    unset($l);
                }
            }
        }

        // Formato de 2027 na 2.3: o lançado (valor ou reajuste) é SÓ ICMS e ISS; o
        // PIS/COFINS (a CBS) entra por cima, na proporção da Receita de Vendas
        // proposta — o mesmo que `Reforma::formatoNovo` tira para mostrar.
        if ($t !== null && isset($idx['2.3'], $idx['1.1'])) {
            $l23 = &$linhas[$idx['2.3']];
            $pcProp = $t * (float)$linhas[$idx['1.1']]['proposta'];
            if (($l23['proposta_origem'] ?? null) === 'VALOR') {
                $l23['proposta'] = (float)$l23['proposta_lancado'] - $pcProp;
            } elseif (($l23['proposta_origem'] ?? null) === 'PERCENTUAL') {
                $semPc = (float)$l23['projecao'] + $t * (float)$linhas[$idx['1.1']]['projecao'];
                $l23['proposta'] = $semPc * (1 + (float)$l23['proposta_lancado'] / 100) - $pcProp;
            }
            unset($l23);
        }

        // A REFORMA (LC 214): em 2027 a CBS entra no lugar do PIS/COFINS PELO MESMO
        // VALOR — a linha 2.3 segue a proporção da receita, que carrega o PIS/COFINS
        // de 2026 (decisão do cliente, 2026-11-18: "levando o PIS/COFINS de 7 milhões
        // onde estão os 2 milhões"). Uma versão anterior trocava o PIS/COFINS pela
        // CBS estimada LÍQUIDA de créditos; mas o PIS/COFINS da DRE é o débito BRUTO
        // (os créditos dele já estão no custo), e a troca contava o crédito duas
        // vezes. Não a reponha sem o crédito de PIS/COFINS de 2026 por negócio.
        // A apresentação no formato novo é `Reforma::comoEComoFica`.

        return self::derivar($linhas);
    }

    /**
     * A proposta do negócio no modo FILIAL: a soma do que as filiais lançaram.
     *
     * As linhas editáveis do negócio deixam de ser lançáveis e passam a valer a
     * soma; os blocos derivam dela como sempre. É o que a planilha faz no
     * consolidado — `O10 = SUM(AF10, AR10, BD10, BP10, CB10)`.
     *
     * @param array $porFilial [filialId => [codigo => proposta]]
     */
    public static function somarFiliais(array $linhas, array $porFilial, array $fixos = []): array
    {
        foreach ($linhas as $i => $l) {
            if (!self::editavel($l)) {
                continue;
            }
            // A depreciação do negócio é a da carga, também no modo filial: o
            // resto de filial fora do cadastro aparece como resíduo a distribuir.
            // Linha livre (a depreciação da Pecuária): no modo filial é a soma delas.
            if (isset($fixos[$l['codigo']]) && empty($fixos[$l['codigo']]['livre'])) {
                $linhas[$i] = self::travar($l, $fixos[$l['codigo']], null);
                continue;
            }
            $soma = 0.0;
            foreach ($porFilial as $propostas) {
                $soma += $propostas[$l['codigo']] ?? 0.0;
            }
            $linhas[$i]['proposta'] = $soma;
            $linhas[$i]['proposta_origem'] = 'FILIAIS';
            $linhas[$i]['proposta_lancado'] = null;
        }
        return self::derivar($linhas);
    }

    /**
     * A projeção e a proposta de VÁRIOS negócios de uma vez (fatia 4).
     *
     * O Painel precisa disso para doze planejamentos ao mesmo tempo, e fazer
     * por negócio seria dezenas de consultas por carregamento. Aqui são quatro,
     * e as regras continuam sendo as mesmas de uma tela só: `aplicar` quando o
     * negócio planeja no total, `somarFiliais` quando planeja por filial. Se a
     * conta divergisse entre o Painel e a tela da Projeção, ninguém saberia
     * qual das duas acreditar.
     *
     * @param int[] $negocioIds
     * @return array<int,array{projecao:array,proposta:array,modo:string}>
     *         por negócio, os valores das contas pedidas em `$contasAlvo`
     */
    public static function resumoDosNegocios(
        array $negocioIds,
        int $ano,
        int $corte,
        int $anoPlan,
        array $contasAlvo = ['3', '18'],
        bool $comRateios = true,
        bool $comFiliais = false
    ): array {
        if (!$negocioIds) {
            return [];
        }
        // Os RATEIOS travados (16.1 administrativo e 14.3 negócio) dependem do
        // critério de TODOS os negócios: calculados uma vez, sem eles mesmos (o
        // critério não passa pela 14.3 nem pela 16.1).
        $rateios = $comRateios ? self::rateios($ano, $corte, $anoPlan) : ['adm' => null, 'neg' => null];
        $rateio = $rateios['adm'];
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));

        $contas = Database::todos(
            'SELECT id, codigo, nome, nivel, pai_codigo, percentual FROM dre_conta ORDER BY ordem'
        );
        if (!$contas) {
            return [];
        }
        $porId = [];
        foreach ($contas as $c) {
            $porId[(int)$c['id']] = $c['codigo'];
        }

        $valores = Database::todos(
            'SELECT negocio_id, conta_id,
                    COALESCE(SUM(CASE WHEN tipo = \'REAL\' AND mes <= ? THEN valor END), 0) AS realizado,
                    COALESCE(SUM(CASE WHEN tipo = \'META\' AND mes >  ? THEN valor END), 0) AS projetado
               FROM dre_valor
              WHERE ano = ? AND negocio_id IN (' . $marcas . ')
              GROUP BY negocio_id, conta_id',
            array_merge([$corte, $corte, $ano], $negocioIds)
        );
        $dre = [];
        foreach ($valores as $v) {
            $dre[(int)$v['negocio_id']][$porId[(int)$v['conta_id']]] =
                [(float)$v['realizado'], (float)$v['projetado']];
        }

        $projecoes = Database::todos(
            'SELECT id, negocio_id, modo FROM projecao WHERE ano = ? AND negocio_id IN (' . $marcas . ')',
            array_merge([$anoPlan], $negocioIds)
        );
        $projPorNegocio = [];
        $idsProj = [];
        foreach ($projecoes as $p) {
            $projPorNegocio[(int)$p['negocio_id']] = $p;
            $idsProj[] = (int)$p['id'];
        }

        $lancPorProj = [];
        if ($idsProj) {
            $m2 = implode(',', array_fill(0, count($idsProj), '?'));
            foreach (Database::todos(
                'SELECT projecao_id, filial_id, conta_id, entrada, valor, percentual
                   FROM projecao_valor WHERE projecao_id IN (' . $m2 . ')',
                $idsProj
            ) as $l) {
                $lancPorProj[(int)$l['projecao_id']][(int)$l['filial_id']][$porId[(int)$l['conta_id']]] = $l;
            }
        }

        // As premissas do ano da proposta, por negócio: elas decidem quanto a
        // Receita e as Despesas com Pessoal crescem por omissão, e o Painel tem
        // de fazer a MESMA conta da tela — se divergisse, ninguém saberia em
        // qual das duas acreditar.
        $premissasPorNegocio = [];
        foreach (Database::todos(
            'SELECT negocio_id, chave, percentual FROM negocio_premissa
              WHERE ano = ? AND negocio_id IN (' . $marcas . ')',
            array_merge([$anoPlan], $negocioIds)
        ) as $p) {
            $premissasPorNegocio[(int)$p['negocio_id']][$p['chave']] = (float)$p['percentual'];
        }

        $pessoal = self::dadosDePessoal($negocioIds, $ano, $anoPlan);
        $acertos = self::pprRealizado($negocioIds, $ano);
        $deps = self::depreciacao($negocioIds, $anoPlan);
        $cods = [];
        foreach (Database::todos('SELECT id, cod_negocio FROM negocio WHERE id IN (' . $marcas . ')', $negocioIds) as $n) {
            $cods[(int)$n['id']] = (string)$n['cod_negocio'];
        }
        // Os tributos de 2026: a taxa de PIS/COFINS que converte o que se lança na
        // 1.1 e na 2.3 (formato de 2027) — a mesma conta da tela.
        $tributos = Oportunidades::tributos($negocioIds, $ano);

        $filiais = Database::todos(
            'SELECT f.negocio_id, v.filial_id, v.conta_id, v.realizado, v.projetado
               FROM filial f JOIN dre_valor_filial v ON v.filial_id = f.id
              WHERE v.ano = ? AND f.negocio_id IN (' . $marcas . ')',
            array_merge([$ano], $negocioIds)
        );
        $dreFilial = [];
        foreach ($filiais as $f) {
            $dreFilial[(int)$f['negocio_id']][(int)$f['filial_id']][$porId[(int)$f['conta_id']]] =
                [(float)$f['realizado'], (float)$f['projetado']];
        }

        $monta = static function (array $valoresPorConta) use ($contas): array {
            $linhas = [];
            foreach ($contas as $c) {
                [$r, $p] = $valoresPorConta[$c['codigo']] ?? [0.0, 0.0];
                $linhas[] = [
                    'codigo' => $c['codigo'], 'nome' => $c['nome'],
                    'nivel' => (int)$c['nivel'], 'percentual' => (int)$c['percentual'],
                    'realizado' => $r, 'projetado' => $p, 'projecao' => $r + $p,
                ];
            }
            return $linhas;
        };

        $saida = [];
        foreach ($negocioIds as $id) {
            $id = (int)$id;
            if (!isset($dre[$id])) {
                continue;
            }
            $linhas = $monta($dre[$id]);
            $proj = $projPorNegocio[$id] ?? null;
            $modo = $proj['modo'] ?? 'NEGOCIO';
            $lanc = $proj ? ($lancPorProj[(int)$proj['id']] ?? []) : [];
            $p85 = 0.0;
            foreach ($linhas as $l) {
                if ($l['codigo'] === self::LINHA_PESSOAL) {
                    $p85 = (float)$l['projecao'];
                }
            }
            [$cresc, , $minimo] = self::crescimentosDoNegocio($anoPlan, $premissasPorNegocio[$id] ?? [], $p85, $pessoal[$id] ?? null);
            // O driver do faturamento — a mesma conta da tela (`DesafioDrivers`).
            [$cresc] = DesafioDrivers::aplicar($cresc, $cods[$id] ?? '', $anoPlan, $linhas);
            $taxaPc = Reforma::pisCofinsDoNegocio($linhas, $tributos[$id] ?? null, $corte)['taxa'];

            $porFilial = [];
            $modoFilial = $modo === 'FILIAL' && !empty($dreFilial[$id]);
            $acertosFilial = $acertos[$id]['filiais'] ?? [];
            $propostas = [];
            if ($modoFilial || ($comFiliais && !empty($dreFilial[$id]))) {
                foreach ($dreFilial[$id] as $filialId => $porConta) {
                    // A 8.5 da filial sobre a projeção dela sem a 3227 dela — a mesma conta da tela.
                    $base = $monta($porConta);
                    $cf = self::crescimentosDaFilial($cresc, $base, (float)($acertosFilial[$filialId] ?? 0.0), $minimo);
                    $lf = self::aplicar($base, $lanc[$filialId] ?? [], $cf, $taxaPc,
                        self::fixosDaDepreciacao($deps[$id] ?? null, (int)$filialId, $cods[$id] ?? ''));
                    $porFilial[$filialId] = $lf;
                    $propostas[$filialId] = array_column($lf, 'proposta', 'codigo');
                }
            }
            $fixosNeg = self::fixosDaDepreciacao($deps[$id] ?? null, 0, $cods[$id] ?? '')
                + self::fixosDoRateioMatriz()
                + self::fixosDoRateioAdm($rateio['negocios'][$id] ?? null)
                + self::fixosDoRateioNeg($rateios['neg']['negocios'][$id] ?? null)
                + ($comRateios ? self::fixosDoRateioFin(self::rateioFinDoNegocio($id, $anoPlan)) : []);
            if ($modoFilial) {
                $linhas = self::somarFiliais($linhas, $propostas, $fixosNeg);
            } else {
                $linhas = self::aplicar($linhas, $lanc[0] ?? [], $cresc, $taxaPc, $fixosNeg);
                // Fora do modo filial, as filiais só serviram ao critério: não entram no mínimo.
                $porFilial = [];
            }
            // O mínimo das Despesas com Pessoal — a mesma conta da tela. As
            // filiais só entram no modo filial, quando são elas que formam o total.
            [$linhas, $porFilial] = self::minimoPessoalDoNegocio(
                $linhas, $porFilial, $minimo,
                $modoFilial ? self::quadroDasFiliais(array_keys($porFilial), $ano) : [],
                $modoFilial,
                $acertosFilial
            );

            $proje = [];
            $prop = [];
            foreach ($linhas as $l) {
                if (in_array($l['codigo'], $contasAlvo, true)) {
                    $proje[$l['codigo']] = (float)$l['projecao'];
                    $prop[$l['codigo']] = $l['proposta'] === null ? null : (float)$l['proposta'];
                }
            }
            $saida[$id] = ['projecao' => $proje, 'proposta' => $prop, 'modo' => $modo,
                'pessoal_alertas' => self::alertasPessoal($linhas, $porFilial)];
            if ($comFiliais) {
                // A proposta de cada filial nas linhas pedidas (para os critérios de rateio).
                $saida[$id]['filiais'] = array_map(
                    static fn ($p) => array_intersect_key($p, array_flip($contasAlvo)),
                    $propostas
                );
            }
        }
        return $saida;
    }

    /** Os blocos a partir das linhas já resolvidas, e o que não tem regra. */
    private static function derivar(array $linhas): array
    {
        $porCodigo = [];
        foreach ($linhas as $i => $l) {
            $porCodigo[$l['codigo']] = $i;
        }

        // 2) Os blocos, na ordem das dependências.
        foreach (self::DERIVADAS as $bloco => $partes) {
            if (!isset($porCodigo[$bloco])) {
                continue;
            }
            $soma = 0.0;
            foreach ($partes as $p) {
                $soma += $linhas[$porCodigo[$p]]['proposta'] ?? 0.0;
            }
            $linhas[$porCodigo[$bloco]]['proposta'] = $soma;
            $linhas[$porCodigo[$bloco]]['proposta_origem'] = 'DERIVADA';
        }

        // 3) O que sobrou sem regra fica explicitamente vazio.
        foreach ($linhas as $i => $l) {
            if (!array_key_exists('proposta', $linhas[$i])) {
                $linhas[$i]['proposta'] = null;
                $linhas[$i]['proposta_origem'] = (int)$l['percentual'] ? 'PERCENTUAL_TELA' : 'SEM_REGRA';
            }
            $linhas[$i]['proposta_lancado'] = $linhas[$i]['proposta_lancado'] ?? null;
        }
        return $linhas;
    }
}

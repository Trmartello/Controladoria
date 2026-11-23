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
     * Só essas duas linhas: estender a estimativa às demais exigiria uma regra
     * para custos e despesas que ninguém definiu, e inventá-la seria o erro que
     * este sistema evita.
     *
     * @param array{realizado:float, plan_ate:float, plan_resto:float} $v
     * @return array todas as colunas do quadro, em reais
     */
    public static function visaoRealista(array $v, string $conta, int $corte): array
    {
        $real = (float)$v['realizado'];
        $ate = (float)$v['plan_ate'];
        $resto = (float)$v['plan_resto'];
        $faltam = 12 - $corte;
        if ($conta === '1') {
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
     *    proposta já faz por omissão (vale a projeção).
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
     * @param array|null $folha  linha de `negocio_folha`
     * @param array|null $calc   saída de `pessoas()` para o ano
     */
    public static function pessoalDaDiretriz(float $projecao85, ?array $folha, ?array $calc, float $reajuste, ?float $pprSalarios, float $fatorQuadro = 1.0): ?array
    {
        if (!$folha || $pprSalarios === null || $projecao85 >= 0 || (float)$folha['pessoal_ate'] <= 0) {
            return null;
        }
        $g = $reajuste / 100;
        $pecas26 = (float)$folha['vale_ate'] + (float)$folha['premio_ate'] + (float)$folha['ppr_ate'];
        $parte26 = min(1.0, max(0.0, $pecas26 / (float)$folha['pessoal_ate']));
        $base = -$projecao85 * (1 - $parte26);
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
            'fator_quadro' => $f,
            'ppr_salarios' => $pprSalarios,
            'projecao' => $projecao85,
            'parte_2026' => $parte26,
            'pecas_2026' => -$projecao85 * $parte26,
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
        foreach ($ids as $id) {
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
                $fator
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
                'fator_quadro' => $fator,
                'colaboradores' => $calc ? $qp : null,
                'colaboradores_base' => $calc ? $q0 : null,
            ];
        }
        return [$cresc, $detalhe, $minimo];
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
    public static function minimoPessoalDoNegocio(array $linhas, array $porFilial, ?array $minimo, array $quadros, bool $modoFilial): array
    {
        $pct = $minimo['percentual'] ?? null;
        $pctAtual = $minimo['percentual_atual'] ?? null;
        foreach ($porFilial as $fid => $lf) {
            $q = $quadros[(int)$fid] ?? 0;
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

    /** A subconta da Receita de Vendas, que cresce com IPCA + PIB + crescimento desejado. */
    public const LINHA_RECEITA = '1.1';
    /** A subconta de Despesas com Pessoal, que cresce com o percentual das despesas com pessoas. */
    public const LINHA_PESSOAL = '8.5';

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
    public static function aplicar(array $linhas, array $lancados, array $crescimentos = [], ?float $taxaPisCofins = null): array
    {
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
                foreach (self::ACOMPANHAM_RECEITA as $c) {
                    if (!isset($idx[$c])) {
                        continue;
                    }
                    $l = &$linhas[$idx[$c]];
                    if (($l['proposta_origem'] ?? null) === 'PROJECAO') {
                        $l['proposta'] = (float)$l['projecao'] * $fator;
                        $l['proposta_origem'] = 'PREMISSA';
                        $l['proposta_padrao'] = ($fator - 1) * 100;
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
    public static function somarFiliais(array $linhas, array $porFilial): array
    {
        foreach ($linhas as $i => $l) {
            if (!self::editavel($l)) {
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
        array $contasAlvo = ['3', '18']
    ): array {
        if (!$negocioIds) {
            return [];
        }
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
            if ($modoFilial) {
                $propostas = [];
                foreach ($dreFilial[$id] as $filialId => $porConta) {
                    $lf = self::aplicar($monta($porConta), $lanc[$filialId] ?? [], $cresc, $taxaPc);
                    $porFilial[$filialId] = $lf;
                    $propostas[$filialId] = array_column($lf, 'proposta', 'codigo');
                }
                $linhas = self::somarFiliais($linhas, $propostas);
            } else {
                $linhas = self::aplicar($linhas, $lanc[0] ?? [], $cresc, $taxaPc);
            }
            // O mínimo das Despesas com Pessoal — a mesma conta da tela. As
            // filiais só entram no modo filial, quando são elas que formam o total.
            [$linhas, $porFilial] = self::minimoPessoalDoNegocio(
                $linhas, $porFilial, $minimo,
                $modoFilial ? self::quadroDasFiliais(array_keys($porFilial), $ano) : [],
                $modoFilial
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

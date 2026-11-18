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
        '16' => ['13', '14'],
    ];

    /** Linha que o Qlik entrega pronta e que não sabemos recompor. */
    public const SEM_PROPOSTA = ['15'];

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
        'PCT_ASSIDUIDADE', 'PCT_RESULTADO'];
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

    /** A subconta da Receita de Vendas, que cresce com IPCA + PIB + crescimento desejado. */
    public const LINHA_RECEITA = '1.1';
    /** A subconta de Despesas com Pessoal, que cresce com o percentual das despesas com pessoas. */
    public const LINHA_PESSOAL = '8.5';

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
     *  - Nenhuma outra linha cresce por omissão: seguem valendo a projeção até
     *    que alguém lance (custos e demais despesas não foram pedidos, e inventar
     *    uma regra de crescimento para elas mudaria a meta sem que ninguém tenha
     *    decidido).
     *
     * O que foi DIGITADO numa linha sempre manda: o crescimento por omissão só
     * preenche as linhas em branco. Sem nenhuma premissa lançada não há
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
     */
    public static function aplicar(array $linhas, array $lancados, array $crescimentos = []): array
    {
        // As editáveis, uma a uma; os blocos saem de `derivar`.
        foreach ($linhas as $i => $l) {
            if (!self::editavel($l)) {
                continue;
            }
            $r = self::valorProposto(
                (float)$l['projecao'],
                $lancados[$l['codigo']] ?? null,
                $crescimentos[$l['codigo']] ?? null
            );
            $linhas[$i]['proposta'] = $r['valor'];
            $linhas[$i]['proposta_origem'] = $r['origem'];
            $linhas[$i]['proposta_lancado'] = $r['lancado'];
            // O crescimento por omissão que gerou o número, para a tela mostrar
            // de onde ele veio (null quando a linha foi lançada ou não cresce).
            $linhas[$i]['proposta_padrao'] = $r['origem'] === 'PREMISSA' ? $r['percentual'] : null;
        }

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
        array $contasAlvo = ['3', '16']
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
            $cresc = self::crescimentosPadrao($premissasPorNegocio[$id] ?? []);

            if ($modo === 'FILIAL' && !empty($dreFilial[$id])) {
                $propostas = [];
                foreach ($dreFilial[$id] as $filialId => $porConta) {
                    $lf = self::aplicar($monta($porConta), $lanc[$filialId] ?? [], $cresc);
                    $propostas[$filialId] = array_column($lf, 'proposta', 'codigo');
                }
                $linhas = self::somarFiliais($linhas, $propostas);
            } else {
                $linhas = self::aplicar($linhas, $lanc[0] ?? [], $cresc);
            }

            $proje = [];
            $prop = [];
            foreach ($linhas as $l) {
                if (in_array($l['codigo'], $contasAlvo, true)) {
                    $proje[$l['codigo']] = (float)$l['projecao'];
                    $prop[$l['codigo']] = $l['proposta'] === null ? null : (float)$l['proposta'];
                }
            }
            $saida[$id] = ['projecao' => $proje, 'proposta' => $prop, 'modo' => $modo];
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

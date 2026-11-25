<?php

namespace App\Services;

use App\Core\Database;

/**
 * OPORTUNIDADES E RISCOS de 2027 por negócio (pedido do cliente, 2026-11-18:
 * "evidenciar as oportunidades — os provisionamentos que houveram, que podem não
 * acontecer igual para o ano que vem; mapear outras compras que podem não se
 * repetir, ou que talvez tenhamos que contemplar e não estão contempladas").
 *
 * Duas fontes, mostradas separadas e NÃO somadas uma à outra (podem se sobrepor):
 *
 *  1. **Itens não recorrentes** de jan até o corte, por conta contábil
 *     (`negocio_nao_recorrente`): provisão para devedores duvidosos, baixas,
 *     indenizações, multas, provisões e reversões de custo, e as receitas
 *     pontuais (seguro, quebra de contrato). Despesa que não se repetir é
 *     OPORTUNIDADE (o resultado de 2027 melhora); receita ou reversão que não se
 *     repetir é RISCO (2026 está melhor do que a operação).
 *  2. **Meses fora do padrão** nas despesas operacionais da DRE (subcontas 8.x):
 *     o mês que se afasta da mediana do ano em mais de metade dela (e de R$ 50
 *     mil). Gasto acima do padrão é o sinal de uma compra pontual; abaixo, de um
 *     gasto que pode faltar no plano. É SINAL para investigar, não número para o
 *     plano: pode ser sazonalidade legítima.
 *
 * Só leitura: nada daqui entra na proposta sozinho — quem decide se um item
 * se repete é o negócio, lançando na linha.
 */
class Oportunidades
{
    /** conta contábil => [grupo, rótulo, linha da DRE onde cai] */
    public const CONTAS = [
        '3.11.03.01.01' => ['PROVISAO', 'Provisão para devedores duvidosos (PDD)', '9.2'],
        '3.05.03.01' => ['PROVISAO', 'Provisões de custo indedutíveis (sócio)', '4.1'],
        '3.05.03.02' => ['PROVISAO', 'Provisões de custo indedutíveis (não sócio)', '4.1'],
        '3.05.03.03' => ['PROVISAO', 'Provisões de custo indedutíveis (sócio/não sócio)', '4.1'],
        '3.11.03.03.02' => ['PERDA', 'Baixa de créditos incobráveis', '9.2'],
        '3.11.21.11.01' => ['PERDA', 'Valor residual de imobilizado alienado', '9.4'],
        '3.07.01.11.11' => ['PERDA', 'Baixa de consumo em parcerias', '8.4'],
        '3.07.01.21.01.14' => ['INDENIZACAO', 'Indenizações trabalhistas', '8.5'],
        '3.07.01.01.14' => ['INDENIZACAO', 'Indenização judicial (parceiro)', '8.1'],
        '3.07.01.21.01.23' => ['INDENIZACAO', 'Indenizações judiciais pecuniárias', '8.5'],
        '3.07.01.01.19' => ['INDENIZACAO', 'Indenização, multa ou reposição comercial', '8.1'],
        '3.07.03.01.09' => ['MULTA', 'Multas fiscais federais', '8.15'],
        '3.07.03.03.07' => ['MULTA', 'Multas fiscais estaduais', '8.15'],
        '3.07.03.05.05' => ['MULTA', 'Multas fiscais municipais', '8.15'],
        '3.11.01.01.11' => ['RECEITA', 'Receita de prêmio de seguro (indenização)', '9.1'],
        '3.11.01.01.14' => ['RECEITA', 'Receita de indenização por quebra contratual', '9.1'],
        '3.11.01.01.15' => ['RECEITA', 'Recuperação de títulos baixados como incobráveis', '9.1'],
        '3.11.01.01.03' => ['RECEITA', 'Receita de indenizações e ressarcimentos', '9.1'],
    ];

    /**
     * ESTOQUE como oportunidade (pedido do cliente, 2026-11-18): as duas contas da
     * DRE Gerencial em que o estoque vira resultado. conta => [tipo, rótulo, linha].
     * As duas caem no CUSTO DAS VENDAS (estrutura da DRE Gerencial: 3081 em
     * 4.1.14, 3148 em 4.1.40) — não em despesas nem em Provisões e Perdas.
     * A oportunidade de cada uma é o que passou da MÉDIA mensal (`acimaDaMedia`).
     */
    public const ESTOQUE_CONTAS = [
        '3.07.01.01.06' => ['PERDA', 'Perdas — mercadorias deterioradas', '4.1'],
        '3.11.03.03.01' => ['ACERTO', 'Acerto de estoque (inventário)', '4.1'],
    ];

    public const GRUPOS = [
        'PROVISAO' => 'Provisões', 'PERDA' => 'Perdas e baixas', 'INDENIZACAO' => 'Indenizações',
        'MULTA' => 'Multas', 'RECEITA' => 'Receitas pontuais',
    ];

    /** Lançamentos que explicam o quadro: só os deste tamanho para cima (R$). */
    public const LANCAMENTO_MINIMO = 10000.0;
    /** Juros recebido acima disto num lançamento é RISCO (pedido do cliente, 2026-11-18). */
    public const JUROS_RISCO = 30000.0;
    /** Juros ativos: reduzida => quem paga. */
    public const JUROS_CONTAS = ['3153' => 'sócio', '3155' => 'não sócio'];

    /** Meses fora do padrão: afastamento mínimo, relativo à mediana e em reais. */
    public const PICO_RELATIVO = 0.5;
    public const PICO_MINIMO = 50000.0;

    /**
     * Os itens não recorrentes de vários negócios, por negócio.
     *
     * @return array<int, array{mes_ate:int, itens:array, oportunidade:float, risco:float}>
     */
    public static function itens(array $negocioIds, int $ano): array
    {
        $saida = [];
        if (!$negocioIds) {
            return $saida;
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        foreach (Database::todos(
            'SELECT negocio_id, mes_ate, conta, valor FROM negocio_nao_recorrente
              WHERE ano = ? AND negocio_id IN (' . $marcas . ') ORDER BY ABS(valor) DESC',
            array_merge([$ano], array_map('intval', $negocioIds))
        ) as $r) {
            $id = (int)$r['negocio_id'];
            $cat = self::CONTAS[$r['conta']] ?? ['OUTRO', $r['conta'], null];
            $v = (float)$r['valor'];
            if (abs($v) < 0.5) {
                continue;
            }
            $saida[$id] ??= ['mes_ate' => (int)$r['mes_ate'], 'itens' => [], 'oportunidade' => 0.0, 'risco' => 0.0];
            // Efeito em 2027 se NÃO se repetir: despesa (+) que some melhora o
            // resultado; receita/reversão (−) que some o piora.
            $efeito = $v;
            $saida[$id]['itens'][] = [
                'conta' => $r['conta'], 'grupo' => $cat[0], 'rotulo' => $cat[1], 'linha_dre' => $cat[2],
                'valor' => $v, 'efeito' => $efeito,
            ];
            if ($efeito > 0) {
                $saida[$id]['oportunidade'] += $efeito;
            } else {
                $saida[$id]['risco'] += $efeito;
            }
        }
        return $saida;
    }

    /**
     * As linhas varridas atrás de EVENTOS PONTUAIS (pedido do cliente, 2026-11-18:
     * "valores eventuais que aconteceram no decorrer do ano — por exemplo, uma
     * receita de juros muito alta concentrada num único mês; algo que não vem sendo
     * linear, que não se repete"): despesas operacionais, outros valores e
     * resultado financeiro. Receita, deduções e custo ficam fora — seguem a venda
     * e a sazonalidade da safra, e encheriam a lista de falso alarme.
     */
    public const PICO_GRUPOS = ['8' => 'Despesas operacionais', '9' => 'Outros valores', '11' => 'Resultado financeiro'];

    /**
     * Meses fora do padrão de UM negócio — ou de uma FILIAL dele — de jan até o
     * corte, nas linhas de `PICO_GRUPOS`. Valores da DRE: despesa é negativa,
     * receita positiva.
     *
     * Um mês está fora do padrão quando se afasta da mediana do ano em mais da
     * metade dela e de R$ 50 mil. O efeito em 2027, SE NÃO SE REPETIR, é o desvio
     * com o sinal trocado: despesa acima do padrão que não volta é OPORTUNIDADE
     * (+); receita acima do padrão — os juros concentrados num mês — que não volta
     * é RISCO (−), e despesa abaixo do padrão também (pode faltar no plano).
     *
     * Na filial a fonte é `dre_valor_filial_mes`, que só tem as combinações com
     * algum mês acima do piso — o resto não teria o que mostrar.
     *
     * @return array<int, array{codigo:string, nome:string, grupo:string, meses:array, oportunidade:float, risco:float, mediana:float}>
     */
    public static function picos(int $negocioId, int $ano, int $corte, int $filialId = 0, array $ajuste = []): array
    {
        $filtro = "(c.codigo LIKE '8.%' OR c.codigo LIKE '9.%' OR c.codigo LIKE '11.%')";
        // O ANO CORRENTE, de jan até o corte (pedido do cliente, 2026-11-25: "considere
        // 2025 de janeiro a dezembro e 2026 de janeiro até o mês que tem rateio";
        // entre 23/11 e 25/11 era a janela de 12 meses, nov a out).
        $periodo = '(v.ano = ? AND v.mes <= ?)';
        $pp = [$ano, $corte];
        $linhas = $filialId
            ? Database::todos(
                "SELECT c.codigo, c.nome, v.ano, v.mes, v.valor FROM dre_valor_filial_mes v JOIN dre_conta c ON c.id = v.conta_id
                  WHERE v.filial_id = ? AND {$periodo} AND {$filtro}
                  ORDER BY c.ordem, v.ano, v.mes",
                array_merge([$filialId], $pp)
            )
            : Database::todos(
                "SELECT c.codigo, c.nome, v.ano, v.mes, v.valor FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
                  WHERE v.negocio_id = ? AND v.tipo = 'REAL' AND {$periodo} AND {$filtro}
                  ORDER BY c.ordem, v.ano, v.mes",
                array_merge([$negocioId], $pp)
            );
        $porConta = [];
        foreach ($linhas as $l) {
            $porConta[$l['codigo']]['nome'] = $l['nome'];
            // `$ajuste` (do `quadro`): o que os itens não recorrentes já explicam
            // na linha, no sinal da carga (+ despesa), por 'ano-mês'. Tirado da
            // série, o mesmo valor não aparece duas vezes — como item e como pico.
            $k = self::chaveMes((int)$l['ano'], (int)$l['mes']);
            $porConta[$l['codigo']]['meses'][$k] = (float)$l['valor'] + ($ajuste[$l['codigo']][$k] ?? 0.0);
        }
        $saida = [];
        foreach ($porConta as $codigo => $c) {
            $vals = array_values($c['meses']);
            if (count($vals) < 6) {
                continue;
            }
            $med = self::mediana($vals);
            $limite = max(self::PICO_RELATIVO * abs($med), self::PICO_MINIMO);
            $fora = [];
            $oportunidade = 0.0;
            $risco = 0.0;
            foreach ($c['meses'] as $k => $v) {
                $d = $v - $med;
                if (abs($d) <= $limite) {
                    continue;
                }
                [$a, $mes] = array_map('intval', explode('-', $k));
                // Se o desvio não se repetir em 2027, o resultado muda em −d.
                $fora[] = ['ano' => $a, 'mes' => $mes, 'valor' => $v, 'desvio' => $d, 'efeito' => -$d];
                if ($d < 0) {
                    $oportunidade += -$d;
                } else {
                    $risco += -$d;
                }
            }
            if ($fora) {
                $saida[] = ['codigo' => $codigo, 'nome' => $c['nome'],
                    'grupo' => self::PICO_GRUPOS[strstr($codigo, '.', true)] ?? '',
                    'mediana' => $med, 'meses' => $fora, 'oportunidade' => $oportunidade, 'risco' => $risco];
            }
        }
        usort($saida, static fn ($a, $b) => ($b['oportunidade'] - $b['risco']) <=> ($a['oportunidade'] - $a['risco']));
        return $saida;
    }

    /**
     * O QUADRO ÚNICO de oportunidades e riscos de 2027 (pedido do cliente,
     * 2026-11-18: "traga todas as oportunidades nesse mesmo formato — os meses em
     * que aconteceram, as oportunidades, os riscos — e totalize"): os itens não
     * recorrentes por conta contábil e os eventos pontuais das linhas, no mesmo
     * formato, com o total de oportunidades e o total de riscos.
     *
     * Os itens saem da série da linha antes da busca por meses fora do padrão
     * (`picos(…, $ajuste)`): a PDD de outubro do Leite é item, e o que sobra na
     * linha 9.2 naquele mês é outro evento — somar os dois não conta nada duas
     * vezes. Na FILIAL só há os eventos (os itens por conta são do negócio).
     *
     * Sinais: os meses vêm no sinal da DRE (despesa negativa); o efeito em 2027, se
     * não se repetir, é + oportunidade / − risco.
     *
     * @return array{mes_ate:int, linhas:array, oportunidade:float, risco:float, saldo:float}
     */
    public static function quadro(int $negocioId, int $ano, int $corte, int $filialId = 0): array
    {
        $linhas = [];
        $ajuste = [];
        // Considerar / Desconsiderar vale para TODAS as linhas do negócio (pedido do
        // cliente, 2026-11-24): itens pela conta contábil, meses fora do padrão pela
        // linha da DRE (`chaveAvaliacao`). Na filial não se avalia: é do negócio.
        $avaliacoes = $filialId ? [] : self::avaliacoes($negocioId, $ano + 1);
        if (!$filialId) {
            $porConta = [];
            $anterior = [];
            // O ano corrente de jan até o corte, e o ANO ANTERIOR INTEIRO para
            // comparar (pedido do cliente, 2026-11-25; antes, janelas de 12 meses).
            foreach (Database::todos(
                'SELECT conta, ano, mes, valor FROM negocio_nao_recorrente_mes
                  WHERE negocio_id = ? AND ano = ? AND mes <= ? ORDER BY conta, ano, mes',
                [$negocioId, $ano, $corte]
            ) as $r) {
                $porConta[$r['conta']][self::chaveMes((int)$r['ano'], (int)$r['mes'])] = (float)$r['valor'];
            }
            foreach (Database::todos(
                'SELECT conta, SUM(valor) s, COUNT(*) n FROM negocio_nao_recorrente_mes
                  WHERE negocio_id = ? AND ano = ? GROUP BY conta',
                [$negocioId, $ano - 1]
            ) as $r) {
                $anterior[$r['conta']] = (float)$r['s'];
            }
            // Sem a carga mensal, os totais (sem mês e sem tirar da série).
            if (!$porConta) {
                foreach ((self::itens([$negocioId], $ano)[$negocioId]['itens'] ?? []) as $i) {
                    $porConta[$i['conta']] = ['total' => (float)$i['valor']];
                }
            }
            foreach ($porConta as $conta => $meses) {
                $cat = self::CONTAS[$conta] ?? ['OUTRO', $conta, null];
                $total = array_sum($meses);
                if (abs($total) < 0.5) {
                    continue;
                }
                $comMes = !isset($meses['total']);
                if ($comMes && $cat[2]) {
                    foreach ($meses as $k => $v) {
                        $ajuste[$cat[2]][$k] = ($ajuste[$cat[2]][$k] ?? 0.0) + $v;
                    }
                }
                $red = self::reduzidaDaConta((string)$conta);
                $linhas[] = [
                    'tipo' => 'ITEM', 'codigo' => (string)$conta, 'nome' => $cat[1],
                    'chave_avaliacao' => self::chaveAvaliacao('ITEM', (string)$conta),
                    // A conta reduzida e o nome como o Qlik mostram (pedido do
                    // cliente, 2026-11-21): é por ela que o gestor acha o número
                    // na DRE gerencial; a conta contábil fica de apoio.
                    'reduzida' => $red['reduzida'] ?? null, 'reduzida_nome' => $red['nome'] ?? null,
                    'grupo' => self::GRUPOS[$cat[0]] ?? $cat[0], 'linha_dre' => $cat[2], 'mediana' => null,
                    'meses' => $comMes ? array_values(array_map(
                        static function ($k, $v) {
                            [$a, $m] = array_map('intval', explode('-', (string)$k));
                            return ['ano' => $a, 'mes' => $m, 'valor' => -$v];
                        },
                        array_keys(array_filter($meses)), array_values(array_filter($meses))
                    )) : [],
                    // No sinal da DRE (despesa negativa): o total do ano corrente até o
                    // corte e o do ano anterior inteiro, para comparar.
                    'total' => -$total,
                    'anterior' => isset($anterior[$conta]) ? -$anterior[$conta] : 0.0,
                    'avaliacao' => $avaliacoes[(string)$conta] ?? null,
                    'oportunidade' => $total > 0 ? $total : 0.0,
                    'risco' => $total < 0 ? $total : 0.0,
                ];
            }
        }
        foreach (self::picos($negocioId, $ano, $corte, $filialId, $ajuste) as $p) {
            $chave = self::chaveAvaliacao('EVENTO', (string)$p['codigo']);
            $linhas[] = [
                'tipo' => 'EVENTO', 'codigo' => $p['codigo'], 'nome' => $p['nome'], 'grupo' => $p['grupo'],
                'chave_avaliacao' => $chave, 'avaliacao' => $avaliacoes[$chave] ?? null,
                'linha_dre' => $p['codigo'], 'mediana' => $p['mediana'],
                'meses' => array_map(static fn ($m) => ['ano' => $m['ano'], 'mes' => $m['mes'], 'valor' => $m['valor']], $p['meses']),
                'oportunidade' => $p['oportunidade'], 'risco' => $p['risco'],
            ];
        }
        usort($linhas, static fn ($a, $b) => ($b['oportunidade'] - $b['risco']) <=> ($a['oportunidade'] - $a['risco']));
        // Os lançamentos que formam cada valor (pedido do cliente, 2026-11-18): o
        // item, pela conta; o evento, pela linha nos meses fora do padrão — sem as
        // contas que já são item, que saíram da série da linha.
        $codFilial = $filialId
            ? (string)(Database::um('SELECT cod_filial FROM filial WHERE id = ?', [$filialId])['cod_filial'] ?? '')
            : null;
        $contasItem = array_values(array_unique(array_map(static fn ($l) => $l['codigo'],
            array_filter($linhas, static fn ($l) => $l['tipo'] === 'ITEM'))));
        foreach ($linhas as &$l) {
            $l['lancamentos'] = $l['tipo'] === 'ITEM'
                ? self::lancamentos($negocioId, $ano, $corte, ['conta' => $l['codigo']], $codFilial)
                : self::lancamentos($negocioId, $ano, $corte, ['linha' => $l['codigo'],
                    // Os lançamentos carregados são do ano corrente (jan até o corte).
                    'meses' => array_column(array_filter($l['meses'], static fn ($m) => (int)$m['ano'] === $ano), 'mes'),
                    'sem' => $contasItem], $codFilial);
        }
        unset($l);
        $op = array_sum(array_column($linhas, 'oportunidade'));
        $ri = array_sum(array_column($linhas, 'risco'));
        // A avaliação (Considerar / Desconsiderar) de TODAS as linhas, totalizada no
        // sinal da DRE: o item pelo total dos 12 meses; o mês fora do padrão pelo
        // quanto ele se afasta do padrão (−(oportunidade + risco)). Na filial, nada.
        $aval = ['considerar' => 0.0, 'desconsiderar' => 0.0, 'avaliados' => 0, 'pendentes' => 0];
        foreach ($filialId ? [] : $linhas as $l) {
            $valor = $l['tipo'] === 'ITEM' ? (float)$l['total'] : -((float)$l['oportunidade'] + (float)$l['risco']);
            $dec = $l['avaliacao']['decisao'] ?? null;
            if ($dec === 'CONSIDERAR') {
                $aval['considerar'] += $valor;
                $aval['avaliados']++;
            } elseif ($dec === 'DESCONSIDERAR') {
                $aval['desconsiderar'] += $valor;
                $aval['avaliados']++;
            } else {
                $aval['pendentes']++;
            }
        }
        return ['mes_ate' => $corte, 'ano' => $ano,
            'periodo' => self::periodoAtual($ano, $corte), 'periodo_anterior' => self::periodoAnterior($ano),
            'linhas' => $linhas, 'oportunidade' => $op, 'risco' => $ri, 'saldo' => $op + $ri, 'avaliacao' => $aval];
    }

    /** Prefixo da chave de avaliação de um mês fora do padrão: a linha da DRE ('LINHA:8.15'). */
    public const PREFIXO_LINHA = 'LINHA:';

    /**
     * A chave com que a avaliação de uma linha do quadro se grava em
     * `negocio_item_avaliacao.conta`: o item, pela conta contábil (como sempre foi,
     * então o que já foi avaliado continua valendo); o mês fora do padrão, pela
     * linha da DRE com o prefixo `LINHA:`, que não colide com conta contábil.
     */
    public static function chaveAvaliacao(string $tipo, string $codigo): string
    {
        return $tipo === 'ITEM' ? $codigo : self::PREFIXO_LINHA . $codigo;
    }

    /** 'ano-mês' de uma série que atravessa anos ('2025-11'). */
    public static function chaveMes(int $ano, int $mes): string
    {
        return sprintf('%04d-%02d', $ano, $mes);
    }

    /** O período das oportunidades: o ano corrente, de jan até o corte (2026-11-25). */
    public static function periodoAtual(int $ano, int $corte): array
    {
        return ['de' => [$ano, 1], 'ate' => [$ano, $corte]];
    }

    /** E o de comparação: o ano anterior inteiro, jan a dez. */
    public static function periodoAnterior(int $ano): array
    {
        return ['de' => [$ano - 1, 1], 'ate' => [$ano - 1, 12]];
    }

    /**
     * Os 12 meses que terminam em (ano, corte) — nov do ano anterior a out do
     * atual, com corte 10 —, em ordem: [[ano, mês], …].
     *
     * @return list<array{0:int, 1:int}>
     */
    public static function janela(int $ano, int $corte): array
    {
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $corte - $i;
            $a = $ano;
            while ($m < 1) {
                $m += 12;
                $a--;
            }
            $out[] = [$a, $m];
        }
        return $out;
    }

    /**
     * As avaliações dos itens não recorrentes de um negócio para o ano do
     * planejamento: conta => ['decisao', 'por', 'em'].
     *
     * @return array<string, array{decisao:string, por:?string, em:string}>
     */
    public static function avaliacoes(int $negocioId, int $anoPlan): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT a.conta, a.decisao, a.avaliado_em, u.nome
               FROM negocio_item_avaliacao a LEFT JOIN usuario u ON u.id = a.avaliado_por
              WHERE a.negocio_id = ? AND a.ano = ?',
            [$negocioId, $anoPlan]
        ) as $r) {
            $out[(string)$r['conta']] = ['decisao' => $r['decisao'], 'por' => $r['nome'], 'em' => $r['avaliado_em']];
        }
        return $out;
    }

    /** Valor mínimo (no ano em que aconteceu) para virar questionamento. */
    public const QUESTIONAMENTO_MINIMO = 10000.0;
    /** "Não aconteceu" no outro ano: abaixo desta fração do valor do ano em que aconteceu. */
    public const QUESTIONAMENTO_FRACAO = 0.10;

    /**
     * QUESTIONAMENTOS (pedido do cliente, 2026-11-23: "além das variações, traga
     * o apontamento do que está sendo realizado num ano e não foi no outro, ou
     * vice-versa, para questionar se isso vai continuar acontecendo ou deixou de
     * acontecer e deveria continuar"; comparação escolhida: 2025 × 2026
     * realizado).
     *
     * Conta reduzida a conta reduzida (nível 3), em JANELAS DE 12 MESES desde
     * 2026-11-23 (nov do ano anterior a out do atual, contra os 12 meses antes;
     * `dre_reduzida_12m`), do negócio ou da filial:
     *  - **Parou**: R$ 10 mil ou mais nos 12 meses anteriores e, nos últimos 12,
     *    menos de 10% disso (inclui zero) — "deixou de acontecer: deveria
     *    continuar em 2027?";
     *  - **Começou**: o inverso — "vai continuar em 2027?".
     * Ficam de fora os rateios corporativos (blocos 14 e 16): são divisão do custo
     * da administração, não um fato do negócio para continuar ou parar. Ordem:
     * maior valor primeiro. Só leitura — é pergunta, não número para o plano.
     *
     * @return array{ano:int, ano_anterior:int, corte:int, minimo:float, parou:list<array>, comecou:list<array>}|null
     */
    public static function questionamentos(int $negocioId, int $ano, int $corte, int $filialId = 0): ?array
    {
        // O ano corrente até o corte × o ANO ANTERIOR INTEIRO (pedido do cliente,
        // 2026-11-25; antes, janelas de 12 meses). Não há carga por conta reduzida do
        // ano anterior inteiro, e ela não precisa existir: os meses dele depois do
        // corte são a janela de 12 meses que termina no corte deste ano MENOS o ano
        // corrente até o corte. Então ano anterior = (jan–corte dele) + janela −
        // (jan–corte deste). Conferido em 25/11 contra a DRE de nov–dez/2025: as 32
        // linhas batem. As chaves vêm das TRÊS fontes: conta que soma zero na janela
        // (um estorno, como a 9.2.5 das Agropecuárias) não está nela.
        $anterior = $ano - 1;
        $linhas = Database::todos(
            "SELECT k.codigo, r.reduzida, r.nome, r.linha, r.ordem,
                    COALESCE(p.v, 0) + COALESCE(w.v, 0) - COALESCE(a.v, 0) AS antes,
                    COALESCE(a.v, 0) AS agora
               FROM (SELECT codigo FROM dre_reduzida_valor WHERE negocio_id = ? AND filial_id = ? AND ano IN (?, ?) AND corte = ?
                     UNION SELECT codigo FROM dre_reduzida_12m WHERE negocio_id = ? AND filial_id = ? AND ano_fim = ? AND mes_fim = ?) k
               JOIN dre_reduzida r ON r.codigo = k.codigo
               LEFT JOIN (SELECT codigo, SUM(realizado) v FROM dre_reduzida_valor
                           WHERE negocio_id = ? AND filial_id = ? AND ano = ? AND corte = ? GROUP BY codigo) p ON p.codigo = k.codigo
               LEFT JOIN (SELECT codigo, SUM(realizado) v FROM dre_reduzida_valor
                           WHERE negocio_id = ? AND filial_id = ? AND ano = ? AND corte = ? GROUP BY codigo) a ON a.codigo = k.codigo
               LEFT JOIN (SELECT codigo, SUM(realizado) v FROM dre_reduzida_12m
                           WHERE negocio_id = ? AND filial_id = ? AND ano_fim = ? AND mes_fim = ? GROUP BY codigo) w ON w.codigo = k.codigo",
            [$negocioId, $filialId, $anterior, $ano, $corte, $negocioId, $filialId, $ano, $corte,
                $negocioId, $filialId, $anterior, $corte,
                $negocioId, $filialId, $ano, $corte,
                $negocioId, $filialId, $ano, $corte]
        );
        // Sem as três fontes carregadas não há comparação honesta.
        $tem = static fn (string $sql, array $p) => (bool)Database::um($sql, $p);
        if (!$linhas
            || !$tem('SELECT 1 AS x FROM dre_reduzida_valor WHERE negocio_id = ? AND ano = ? AND corte = ? LIMIT 1', [$negocioId, $anterior, $corte])
            || !$tem('SELECT 1 AS x FROM dre_reduzida_valor WHERE negocio_id = ? AND ano = ? AND corte = ? LIMIT 1', [$negocioId, $ano, $corte])
            || !$tem('SELECT 1 AS x FROM dre_reduzida_12m WHERE negocio_id = ? AND ano_fim = ? AND mes_fim = ? LIMIT 1', [$negocioId, $ano, $corte])) {
            return null;
        }
        $parou = [];
        $comecou = [];
        foreach ($linhas as $l) {
            if (preg_match('/^1[46]\./', (string)$l['linha'])) {
                continue;
            }
            $a = (float)$l['antes'];
            $b = (float)$l['agora'];
            $item = [
                'codigo' => $l['codigo'], 'reduzida' => $l['reduzida'], 'nome' => $l['nome'],
                'linha' => $l['linha'], 'anterior' => round($a, 2), 'atual' => round($b, 2),
            ];
            if (abs($a) >= self::QUESTIONAMENTO_MINIMO && abs($b) < self::QUESTIONAMENTO_FRACAO * abs($a)) {
                $parou[] = $item + ['pergunta' => "Aconteceu em {$anterior} e não em {$ano}: deixou de acontecer — deveria continuar em " . ($ano + 1) . '?'];
            } elseif (abs($b) >= self::QUESTIONAMENTO_MINIMO && abs($a) < self::QUESTIONAMENTO_FRACAO * abs($b)) {
                $comecou[] = $item + ['pergunta' => "Começou em {$ano} sem ter acontecido em {$anterior}: vai continuar em " . ($ano + 1) . '?'];
            }
        }
        usort($parou, static fn ($x, $y) => abs($y['anterior']) <=> abs($x['anterior']));
        usort($comecou, static fn ($x, $y) => abs($y['atual']) <=> abs($x['atual']));
        return ['ano' => $ano, 'ano_anterior' => $anterior, 'corte' => $corte,
            // Os períodos, para a tela dizer "jan–dez/25 × jan–out/26".
            'periodo' => self::periodoAtual($ano, $corte), 'periodo_anterior' => self::periodoAnterior($ano),
            'minimo' => self::QUESTIONAMENTO_MINIMO, 'parou' => $parou, 'comecou' => $comecou];
    }

    /**
     * Os lançamentos de R$ 10 mil ou mais por trás de um valor do quadro: pela
     * conta (`['conta' => …]`) ou pela linha da DRE em alguns meses (`['linha' =>
     * …, 'meses' => […], 'sem' => [contas a deixar de fora]]`); na filial, só os
     * dela. Valores no sinal da DRE. Os 40 maiores, com quantos e quanto somam
     * todos os do piso para cima.
     *
     * @return array{itens:array, quantos:int, soma:float, minimo:float, oportunidade:float, risco:float, saldo:float}
     */
    public static function lancamentos(int $negocioId, int $ano, int $corte, array $filtro, ?string $codFilial = null): array
    {
        $onde = 'negocio_id = ? AND ano = ? AND mes <= ? AND ABS(valor) >= ?';
        $p = [$negocioId, $ano, $corte, self::LANCAMENTO_MINIMO];
        if (isset($filtro['conta'])) {
            $onde .= ' AND conta = ?';
            $p[] = $filtro['conta'];
        } else {
            $meses = array_map('intval', $filtro['meses'] ?: [0]);
            $onde .= ' AND linha_dre = ? AND mes IN (' . implode(',', array_fill(0, count($meses), '?')) . ')';
            $p = array_merge($p, [$filtro['linha']], $meses);
            if (!empty($filtro['sem'])) {
                $onde .= ' AND conta NOT IN (' . implode(',', array_fill(0, count($filtro['sem']), '?')) . ')';
                $p = array_merge($p, $filtro['sem']);
            }
        }
        if ($codFilial !== null) {
            $onde .= ' AND cod_filial = ?';
            $p[] = $codFilial;
        }
        // Totais de TODOS os lançamentos do piso para cima (não só dos 40 mostrados),
        // com a régua do quadro: despesa (+ no Qlik) que não se repetir é
        // oportunidade; receita ou reversão (−) é risco.
        $tot = Database::um("SELECT COUNT(*) n, COALESCE(SUM(valor), 0) s,
            COALESCE(SUM(CASE WHEN valor > 0 THEN valor ELSE 0 END), 0) op,
            COALESCE(SUM(CASE WHEN valor < 0 THEN valor ELSE 0 END), 0) ri
            FROM dre_lancamento WHERE {$onde}", $p);
        $itens = array_map(static fn ($r) => [
            'data' => $r['data'], 'mes' => (int)$r['mes'], 'cod_filial' => $r['cod_filial'], 'filial' => $r['filial'],
            'conta' => $r['conta'], 'conta_nome' => trim(preg_replace('/\s+/u', ' ', $r['conta_nome'])),
            'reduzida' => $r['reduzida'], 'historico' => trim($r['historico']),
            'complemento' => trim($r['complemento']), 'valor' => -(float)$r['valor'],
        ], Database::todos(
            "SELECT * FROM dre_lancamento WHERE {$onde} ORDER BY ABS(valor) DESC, data LIMIT 40", $p
        ));
        return ['itens' => $itens, 'quantos' => (int)$tot['n'], 'soma' => -(float)$tot['s'], 'minimo' => self::LANCAMENTO_MINIMO,
            'oportunidade' => (float)$tot['op'], 'risco' => (float)$tot['ri'], 'saldo' => (float)$tot['op'] + (float)$tot['ri']];
    }

    /**
     * A conta reduzida de uma conta contábil, e o nome com que o Qlik a lista na
     * DRE gerencial: a reduzida sai dos lançamentos (`dre_lancamento`, que trazem
     * as duas), o nome do catálogo do nível 3 (`dre_reduzida`) — ou, sem ele, o
     * do lançamento. Null quando a conta não tem lançamento carregado.
     *
     * @return array{reduzida:string, nome:string}|null
     */
    public static function reduzidaDaConta(string $conta): ?array
    {
        static $cache = [];
        if (array_key_exists($conta, $cache)) {
            return $cache[$conta];
        }
        $r = Database::um('SELECT reduzida, conta_nome FROM dre_lancamento WHERE conta = ? AND reduzida <> \'\' LIMIT 1', [$conta]);
        if (!$r) {
            return $cache[$conta] = null;
        }
        $cat = Database::um('SELECT nome FROM dre_reduzida WHERE reduzida = ? LIMIT 1', [$r['reduzida']]);
        return $cache[$conta] = [
            'reduzida' => (string)$r['reduzida'],
            'nome' => $cat ? (string)$cat['nome'] : trim(preg_replace('/\s+/u', ' ', (string)$r['conta_nome'])),
        ];
    }

    /** "000902500 0350688-ALTAIR LORENZETT" → ['0350688', 'ALTAIR LORENZETT']; o resto, inteiro. */
    public static function cliente(string $complemento): array
    {
        if (preg_match('/^\d+\s+(\d+)-(.+)$/u', trim($complemento), $m)) {
            return [$m[1], trim($m[2])];
        }
        return ['', trim($complemento)];
    }

    /**
     * JUROS RECEBIDOS — sócio (3153) e não sócio (3155) — e onde eles se
     * concentram (pedido do cliente, 2026-11-18: "valores acima de R$ 30 mil são
     * risco; quero saber onde tem e de que cliente se trata"). O total do ano é de
     * todos os lançamentos; os lançamentos e os clientes vêm da carga dos de R$ 5
     * mil ou mais. Receita no sinal da DRE (positiva). Null sem a carga.
     */
    public static function juros(int $negocioId, int $ano, ?string $codFilial = null): ?array
    {
        $f = $codFilial !== null ? ' AND cod_filial = ?' : '';
        $p = $codFilial !== null ? [$negocioId, $ano, $codFilial] : [$negocioId, $ano];
        $tot = Database::todos(
            "SELECT reduzida, MAX(mes_ate) mes_ate, SUM(total) total, SUM(lancamentos) n FROM juros_recebido
              WHERE negocio_id = ? AND ano = ?{$f} GROUP BY reduzida", $p
        );
        if (!$tot) {
            return null;
        }
        $por = array_column($tot, null, 'reduzida');
        $lanc = Database::todos(
            "SELECT * FROM dre_lancamento WHERE negocio_id = ? AND ano = ? AND reduzida IN ('3153','3155') AND valor < 0{$f}
              ORDER BY valor, data", $p
        );
        $acima = [];
        $clientes = [];
        foreach ($lanc as $r) {
            $v = -(float)$r['valor'];
            [$cod, $nome] = self::cliente($r['complemento']);
            $quem = self::JUROS_CONTAS[$r['reduzida']] ?? $r['reduzida'];
            // A conta como o Qlik a lista: "3153 JUROS ATIVOS SOCIO".
            $conta = $r['reduzida'] . ' ' . trim(preg_replace('/\s+/u', ' ', (string)$r['conta_nome']));
            if ($v >= self::JUROS_RISCO) {
                $acima[] = ['data' => $r['data'], 'mes' => (int)$r['mes'], 'cod_filial' => $r['cod_filial'], 'filial' => $r['filial'],
                    'tipo' => $quem, 'conta' => $conta, 'cliente_cod' => $cod, 'cliente' => $nome, 'historico' => trim($r['historico']), 'valor' => $v];
            }
            $k = $cod !== '' ? $cod : $nome;
            $c = &$clientes[$k];
            $c ??= ['cliente_cod' => $cod, 'cliente' => $nome, 'tipo' => $quem, 'conta' => $conta, 'valor' => 0.0, 'lancamentos' => 0, 'filiais' => []];
            $c['valor'] += $v;
            $c['lancamentos']++;
            $c['filiais'][$r['cod_filial']] = $r['filial'];
            unset($c);
        }
        $clientes = array_values(array_filter($clientes, static fn ($c) => $c['valor'] >= self::JUROS_RISCO));
        foreach ($clientes as &$c) {
            $c['filiais'] = array_values(array_map(static fn ($k, $n) => ['cod_filial' => (string)$k, 'filial' => $n],
                array_keys($c['filiais']), $c['filiais']));
        }
        unset($c);
        usort($clientes, static fn ($a, $b) => $b['valor'] <=> $a['valor']);
        $socio = -(float)($por['3153']['total'] ?? 0);
        $naoSocio = -(float)($por['3155']['total'] ?? 0);
        return [
            'mes_ate' => (int)max(array_column($tot, 'mes_ate')),
            'socio' => $socio, 'nao_socio' => $naoSocio, 'total' => $socio + $naoSocio,
            'lancamentos' => (int)array_sum(array_column($tot, 'n')),
            'limite' => self::JUROS_RISCO,
            'acima' => $acima, 'risco' => array_sum(array_column($acima, 'valor')),
            'clientes' => $clientes,
        ];
    }

    /**
     * O MONTANTE DE OPORTUNIDADES DO ESTOQUE a trabalhar no ano do planejamento
     * (pedido do cliente, 2026-11-18), totalizado logo abaixo da DRE:
     *
     *  - **Perdas** e **acerto de estoque**: a régua é a MÉDIA ENTRE AS FILIAIS do
     *    negócio, em reais no ano (pedido do cliente, 2026-11-19: "a média deve ser
     *    a média dentre as filiais, não a do negócio nem a da própria filial").
     *    Cada filial acima dela tem como oportunidade o que passou; o negócio é a
     *    soma das filiais. Entram na média as filiais com movimento na conta.
     *    Média que é crédito (sobra de inventário) vale zero na régua: a filial
     *    com falta líquida tem a falta inteira como oportunidade.
     *  - **Produtos parados**: o valor do estoque sem venda há mais de 2 anos
     *    (Agropecuárias) ou 1 ano (Supermercados). É CAIXA, não resultado — o
     *    total mostra os dois separados, e a soma dos dois.
     *
     * Na filial, as séries, a oportunidade e o parado dela contra a média das
     * filiais; no negócio, a soma e o ranking das filiais. Negócio sem série por
     * filial cai na régua antiga (média mensal do ano), marcada em `regua`.
     * Null quando o negócio não tem nada carregado.
     */
    public static function estoque(int $negocioId, int $ano, int $corte, int $filialId = 0): ?array
    {
        $entre = self::mediaEntreFiliais($negocioId, $ano, $corte);
        $linhas = [];
        foreach (self::seriesDeEstoque($negocioId, $ano, $corte, $filialId) as $conta => $meses) {
            $cat = self::ESTOQUE_CONTAS[$conta];
            $l = ['tipo' => $cat[0], 'conta' => $conta, 'nome' => $cat[1], 'linha_dre' => $cat[2]]
                + self::acimaDaMedia($meses, $corte) + ['regua' => 'MES'];
            if (isset($entre[$conta])) {
                $m = $entre[$conta];
                $l['regua'] = 'FILIAIS';
                $l['media'] = $m['media'] ? -$m['media'] : 0.0;
                $l['filiais'] = $m['filiais'];
                $l['acima'] = $m['acima'];
                $l['oportunidade'] = $filialId ? ($m['excesso'][$filialId] ?? 0.0) : $m['oportunidade'];
                // Os meses ficam como informação: a régua é o ano da filial, não o mês.
                foreach ($l['meses'] as &$x) {
                    $x['excesso'] = 0.0;
                }
                unset($x);
            }
            $linhas[] = $l;
        }
        $parado = self::parado($negocioId, $filialId);
        if (!$linhas && !$parado) {
            return null;
        }
        $resultado = array_sum(array_column($linhas, 'oportunidade'));
        $caixa = $parado ? $parado['valor'] : 0.0;
        $saida = [
            'mes_ate' => $corte, 'linhas' => $linhas, 'parado' => $parado,
            'resultado' => $resultado, 'caixa' => $caixa, 'total' => $resultado + $caixa,
        ];
        if (!$filialId) {
            $saida['filiais'] = self::estoquePorFilial($negocioId, $ano, $corte, $entre);
        }
        return $saida;
    }

    /**
     * A régua de perdas e acerto: por conta, o total do ano de cada filial com
     * movimento (sinal do Qlik, + perda), a MÉDIA simples entre elas e o que cada
     * uma passou da média — `max(0, total − max(média, 0))`.
     *
     * @return array<string, array{media:float, filiais:int, acima:int, oportunidade:float, totais:array, excesso:array}>
     */
    public static function mediaEntreFiliais(int $negocioId, int $ano, int $corte): array
    {
        $totais = [];
        foreach (Database::todos(
            'SELECT filial_id, conta, SUM(valor) t FROM estoque_perda_mes
              WHERE negocio_id = ? AND ano = ? AND mes <= ? AND filial_id > 0 GROUP BY filial_id, conta',
            [$negocioId, $ano, $corte]
        ) as $r) {
            if (isset(self::ESTOQUE_CONTAS[$r['conta']])) {
                $totais[$r['conta']][(int)$r['filial_id']] = (float)$r['t'];
            }
        }
        $saida = [];
        foreach ($totais as $conta => $porFilial) {
            $media = array_sum($porFilial) / count($porFilial);
            $regua = max($media, 0.0);
            $excesso = array_map(static fn ($t) => max(0.0, $t - $regua), $porFilial);
            $saida[$conta] = [
                'media' => $media, 'filiais' => count($porFilial),
                'acima' => count(array_filter($excesso, static fn ($x) => $x > 0.005)),
                'oportunidade' => array_sum($excesso), 'totais' => $porFilial, 'excesso' => $excesso,
            ];
        }
        return $saida;
    }

    /**
     * Média mensal e o que passou dela, de uma série no sinal do Qlik (+ despesa).
     * Mês sem lançamento vale zero — é um mês sem perda, e conta na média. Os
     * valores de saída estão no sinal da DRE (despesa negativa).
     *
     * @return array{media:float, total:float, meses:array, oportunidade:float}
     */
    public static function acimaDaMedia(array $meses, int $corte): array
    {
        $serie = [];
        for ($m = 1; $m <= $corte; $m++) {
            $serie[$m] = (float)($meses[$m] ?? 0.0);
        }
        $total = array_sum($serie);
        $media = $corte ? $total / $corte : 0.0;
        $saida = [];
        $op = 0.0;
        foreach ($serie as $m => $v) {
            $excesso = $media > 0 && $v > $media ? $v - $media : 0.0;
            $op += $excesso;
            $saida[] = ['mes' => $m, 'valor' => $v ? -$v : 0.0, 'excesso' => $excesso];
        }
        return ['media' => -$media, 'total' => -$total, 'meses' => $saida, 'oportunidade' => $op];
    }

    /** conta => [mês => valor no sinal do Qlik], do negócio (filial 0) ou da filial. */
    private static function seriesDeEstoque(int $negocioId, int $ano, int $corte, int $filialId): array
    {
        $series = [];
        foreach (Database::todos(
            'SELECT conta, mes, valor FROM estoque_perda_mes
              WHERE negocio_id = ? AND filial_id = ? AND ano = ? AND mes <= ? ORDER BY conta, mes',
            [$negocioId, $filialId, $ano, $corte]
        ) as $r) {
            if (isset(self::ESTOQUE_CONTAS[$r['conta']])) {
                $series[$r['conta']][(int)$r['mes']] = (float)$r['valor'];
            }
        }
        // Na ordem do catálogo: perdas antes do acerto, sempre.
        return array_intersect_key(array_replace(array_fill_keys(array_keys(self::ESTOQUE_CONTAS), null), $series), $series);
    }

    /** O estoque parado da última posição carregada, do negócio ou da filial. */
    private static function parado(int $negocioId, int $filialId): ?array
    {
        $base = Database::um(
            'SELECT MAX(data_base) AS data_base FROM estoque_parado WHERE negocio_id = ?', [$negocioId]
        )['data_base'] ?? null;
        if (!$base) {
            return null;
        }
        $r = Database::um(
            'SELECT MAX(dias_minimo) AS dias, COUNT(*) AS n, COALESCE(SUM(valor), 0) AS valor, COALESCE(SUM(itens), 0) AS itens
               FROM estoque_parado WHERE negocio_id = ? AND data_base = ?' . ($filialId ? ' AND filial_id = ?' : ''),
            $filialId ? [$negocioId, $base, $filialId] : [$negocioId, $base]
        );
        if (!$r || !(int)$r['n']) {
            return null;
        }
        return ['data_base' => $base, 'dias' => (int)$r['dias'], 'valor' => (float)$r['valor'], 'itens' => (int)$r['itens']];
    }

    /** As filiais do negócio com o que cada uma tem de oportunidade, da maior para a menor. */
    private static function estoquePorFilial(int $negocioId, int $ano, int $corte, array $entre): array
    {
        $por = [];
        $nova = static fn ($r) => ['filial_id' => (int)$r['id'], 'cod_filial' => $r['cod_filial'], 'nome' => $r['nome'],
            'perdas' => 0.0, 'acerto' => 0.0, 'parado' => 0.0, 'itens' => 0];
        $nomes = [];
        foreach (Database::todos('SELECT id, cod_filial, nome FROM filial WHERE negocio_id = ?', [$negocioId]) as $r) {
            $nomes[(int)$r['id']] = $r;
        }
        foreach ($entre as $conta => $m) {
            $chave = self::ESTOQUE_CONTAS[$conta][0] === 'PERDA' ? 'perdas' : 'acerto';
            foreach ($m['excesso'] as $id => $x) {
                if (!isset($nomes[$id])) {
                    continue;
                }
                $por[$id] ??= $nova($nomes[$id]);
                $por[$id][$chave] += $x;
            }
        }
        foreach (Database::todos(
            'SELECT f.id, f.cod_filial, f.nome, p.valor, p.itens FROM estoque_parado p
               JOIN filial f ON f.id = p.filial_id
              WHERE p.negocio_id = ? AND p.data_base = (SELECT MAX(data_base) FROM estoque_parado WHERE negocio_id = ?)',
            [$negocioId, $negocioId]
        ) as $r) {
            $por[(int)$r['id']] ??= $nova($r);
            $por[(int)$r['id']]['parado'] += (float)$r['valor'];
            $por[(int)$r['id']]['itens'] += (int)$r['itens'];
        }
        foreach ($por as &$f) {
            $f['resultado'] = $f['perdas'] + $f['acerto'];
            $f['total'] = $f['resultado'] + $f['parado'];
        }
        unset($f);
        $por = array_values(array_filter($por, static fn ($f) => $f['total'] >= 0.5));
        usort($por, static fn ($a, $b) => $b['total'] <=> $a['total']);
        return $por;
    }

    private static function mediana(array $v): float
    {
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /**
     * Vendas e tributos sobre vendas de jan até o corte, por negócio, com a taxa
     * de PIS + COFINS sobre o faturamento — a base da conta macro da reforma.
     *
     * @return array<int, array>
     */
    public static function tributos(array $negocioIds, int $ano): array
    {
        $saida = [];
        if (!$negocioIds) {
            return $saida;
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        foreach (Database::todos(
            'SELECT * FROM negocio_tributo WHERE ano = ? AND negocio_id IN (' . $marcas . ')',
            array_merge([$ano], array_map('intval', $negocioIds))
        ) as $t) {
            $vendas = (float)$t['venda_socio'] + (float)$t['venda_nao_socio'];
            $pis = (float)$t['pis'];
            $cofins = (float)$t['cofins'];
            $saida[(int)$t['negocio_id']] = [
                'mes_ate' => (int)$t['mes_ate'],
                'venda_socio' => (float)$t['venda_socio'],
                'venda_nao_socio' => (float)$t['venda_nao_socio'],
                'parte_nao_socio' => $vendas > 0 ? (float)$t['venda_nao_socio'] / $vendas : null,
                'pis' => $pis, 'cofins' => $cofins, 'icms' => (float)$t['icms'],
                'taxa_pis' => $vendas > 0 ? $pis / $vendas : null,
                'taxa_cofins' => $vendas > 0 ? $cofins / $vendas : null,
                'taxa_pis_cofins' => $vendas > 0 ? ($pis + $cofins) / $vendas : null,
                'taxa_pis_cofins_nao_socio' => (float)$t['venda_nao_socio'] > 0 ? ($pis + $cofins) / (float)$t['venda_nao_socio'] : null,
            ];
        }
        return $saida;
    }
}

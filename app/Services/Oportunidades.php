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

    public const GRUPOS = [
        'PROVISAO' => 'Provisões', 'PERDA' => 'Perdas e baixas', 'INDENIZACAO' => 'Indenizações',
        'MULTA' => 'Multas', 'RECEITA' => 'Receitas pontuais',
    ];

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
    public static function picos(int $negocioId, int $ano, int $corte, int $filialId = 0): array
    {
        $filtro = "(c.codigo LIKE '8.%' OR c.codigo LIKE '9.%' OR c.codigo LIKE '11.%')";
        $linhas = $filialId
            ? Database::todos(
                "SELECT c.codigo, c.nome, v.mes, v.valor FROM dre_valor_filial_mes v JOIN dre_conta c ON c.id = v.conta_id
                  WHERE v.filial_id = ? AND v.ano = ? AND v.mes <= ? AND {$filtro}
                  ORDER BY c.ordem, v.mes",
                [$filialId, $ano, $corte]
            )
            : Database::todos(
                "SELECT c.codigo, c.nome, v.mes, v.valor FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
                  WHERE v.negocio_id = ? AND v.ano = ? AND v.tipo = 'REAL' AND v.mes <= ? AND {$filtro}
                  ORDER BY c.ordem, v.mes",
                [$negocioId, $ano, $corte]
            );
        $porConta = [];
        foreach ($linhas as $l) {
            $porConta[$l['codigo']]['nome'] = $l['nome'];
            $porConta[$l['codigo']]['meses'][(int)$l['mes']] = (float)$l['valor'];
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
            foreach ($c['meses'] as $mes => $v) {
                $d = $v - $med;
                if (abs($d) <= $limite) {
                    continue;
                }
                // Se o desvio não se repetir em 2027, o resultado muda em −d.
                $fora[] = ['mes' => $mes, 'valor' => $v, 'desvio' => $d, 'efeito' => -$d];
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

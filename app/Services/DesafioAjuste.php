<?php

namespace App\Services;

use App\Core\Database;

/**
 * O DESAFIO EXTRA dos administradores (pedido do cliente, 2026-11-25): no cartão
 * "Desafio 2027 para o negócio", quem tem perfil ADMIN digita na coluna Desafio
 * e desafia o negócio além da proposta padrão. Vale para o ano todo e só nas
 * linhas de VALOR — os resultados e as margens saem em cascata, como na DRE.
 *
 * O ajuste é de UM negócio ("os ajustes do desafio servem apenas para negócio a
 * negócio"); o Corporativo e os grupos somam os desafios já ajustados de cada
 * um, para ver como a corporação fecha. Apagar o número devolve o calculado.
 *
 * A cascata parte da escada CALCULADA e soma as diferenças, em vez de refazer a
 * DRE: entre a margem bruta e o resultado antes dos rateios há linhas que o
 * ajuste não toca (outras receitas, impostos sobre o resultado), e elas ficam
 * como estão.
 */
final class DesafioAjuste
{
    /** As linhas que o administrador digita, na ordem do cartão. */
    public const CHAVES = ['receita_bruta', 'lucro_bruto', 'despesas', 'rateios_negocio', 'rateios_adm'];

    /** Linhas que são despesa (negativas na DRE). */
    public const DESPESAS = ['despesas', 'rateios_negocio', 'rateios_adm'];

    /** @return array<string,float> chave => valor ajustado */
    public static function doNegocio(int $negocioId, int $ano): array
    {
        $r = [];
        foreach (Database::todos(
            'SELECT chave, valor FROM negocio_desafio_ajuste WHERE negocio_id = ? AND ano = ?',
            [$negocioId, $ano]
        ) as $l) {
            if (in_array($l['chave'], self::CHAVES, true)) {
                $r[$l['chave']] = (float)$l['valor'];
            }
        }
        return $r;
    }

    /**
     * Despesa digitada positiva vira negativa quando a calculada é negativa: no
     * celular o teclado numérico não tem o sinal de menos, e "despesa de 5
     * milhões" é −5 milhões na DRE. Despesa que já é positiva (um crédito) fica.
     */
    public static function normalizar(string $chave, float $valor, ?float $calculado): float
    {
        if (in_array($chave, self::DESPESAS, true) && $valor > 0 && ($calculado ?? -1) < 0) {
            return -$valor;
        }
        return $valor;
    }

    /**
     * A escada ajustada. `$c` é a escada calculada (`escadaDoDesafio`), com
     * receita_bruta, receita_liquida, lucro_bruto, despesas,
     * resultado_antes_rateios, rateios_negocio, resultado_negocio, rateios_adm e
     * resultado; as margens se refazem sobre a receita líquida ajustada.
     *
     *  - Faturamento: a receita líquida acompanha na mesma proporção (as deduções
     *    são proporcionais à receita) e, se a margem bruta não foi digitada, ela
     *    também — o % de margem bruta se mantém.
     *  - Margem bruta e despesas: o resultado antes dos rateios muda pela
     *    diferença.
     *  - Rateios: o resultado do negócio e o líquido mudam pela diferença.
     */
    public static function aplicar(array $c, array $aj): array
    {
        if (!$aj) {
            return $c;
        }
        $v = static fn ($k) => isset($c[$k]) && $c[$k] !== null ? (float)$c[$k] : null;
        $e = $c;
        $rb = $v('receita_bruta');
        $fator = 1.0;
        if (array_key_exists('receita_bruta', $aj)) {
            $e['receita_bruta'] = $aj['receita_bruta'];
            $fator = $rb ? $aj['receita_bruta'] / $rb : 1.0;
        }
        if ($v('receita_liquida') !== null) {
            $e['receita_liquida'] = $v('receita_liquida') * $fator;
        }
        if (array_key_exists('lucro_bruto', $aj)) {
            $e['lucro_bruto'] = $aj['lucro_bruto'];
        } elseif ($v('lucro_bruto') !== null) {
            $e['lucro_bruto'] = $v('lucro_bruto') * $fator;
        }
        if (array_key_exists('despesas', $aj)) {
            $e['despesas'] = $aj['despesas'];
        }
        if (array_key_exists('rateios_negocio', $aj)) {
            $e['rateios_negocio'] = $aj['rateios_negocio'];
        }
        if (array_key_exists('rateios_adm', $aj)) {
            $e['rateios_adm'] = $aj['rateios_adm'];
        }
        $dif = static fn ($k) => (float)($e[$k] ?? 0) - (float)($c[$k] ?? 0);
        $d13 = $dif('lucro_bruto') + $dif('despesas');
        $d15 = $d13 + $dif('rateios_negocio');
        $d18 = $d15 + $dif('rateios_adm');
        foreach (['resultado_antes_rateios' => $d13, 'resultado_negocio' => $d15, 'resultado' => $d18] as $k => $d) {
            if ($v($k) !== null) {
                $e[$k] = $v($k) + $d;
            }
        }
        $rol = $e['receita_liquida'] ?? null;
        $pct = static fn ($a) => $a !== null && $rol ? $a / $rol * 100 : null;
        $e['margem_bruta'] = $pct($e['lucro_bruto'] ?? null);
        $e['margem_antes_rateios'] = $pct($e['resultado_antes_rateios'] ?? null);
        $e['margem_negocio'] = $pct($e['resultado_negocio'] ?? null);
        $e['margem'] = $pct($e['resultado'] ?? null);
        return $e;
    }

    /** Grava (ou, com null, apaga) o ajuste de uma linha. */
    public static function gravar(int $negocioId, int $ano, string $chave, ?float $valor, int $usuarioId): void
    {
        if ($valor === null) {
            Database::executar('DELETE FROM negocio_desafio_ajuste WHERE negocio_id = ? AND ano = ? AND chave = ?',
                [$negocioId, $ano, $chave]);
            return;
        }
        Database::executar(
            'INSERT INTO negocio_desafio_ajuste (negocio_id, ano, chave, valor, ajustado_por, ajustado_em)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), ajustado_por = VALUES(ajustado_por), ajustado_em = NOW()',
            [$negocioId, $ano, $chave, round($valor, 2), $usuarioId]
        );
    }
}

<?php

namespace App\Services;

/**
 * A REFORMA TRIBUTÁRIA na DRE do planejamento (LC 214/2025): em 2027 a CBS
 * substitui o PIS e a COFINS; o ICMS segue até 2029. A Copérdia optou pelo regime
 * específico das cooperativas (art. 271) — confirmado pelo cliente em 2026-11-18.
 *
 * No planejamento de 2027 a CBS entra no lugar do PIS/COFINS PELO MESMO VALOR
 * (decisão do cliente, 2026-11-18): o que muda é a LINHA, não o número. A
 * proposta não troca nada na 2.3 (`Projecao::aplicar`), e a CBS aparece só no
 * formato novo (`comoEComoFica`).
 *
 * Por que não se estima a CBS pela alíquota: uma versão anterior (PR #95) fazia
 * débito sobre a venda a não sócio menos crédito sobre o custo e trocava o
 * PIS/COFINS da 2.3 por esse líquido. Só que o PIS/COFINS da DRE é o débito
 * BRUTO — os créditos de PIS/COFINS já estão dentro do custo (4.1) —, então a
 * troca comparava bruto com líquido, contava o crédito duas vezes e inflava a
 * receita líquida (Agropecuárias: CBS de R$ 2,2 mi no lugar de PIS/COFINS de R$ 7,1
 * mi). Estimar de verdade exige o crédito de PIS/COFINS de 2026 por negócio (para
 * tirar do custo) e a CBS bruta na linha: fica para quando houver esse dado.
 */
class Reforma
{
    /**
     * COMO É × COMO FICA: o PIS/COFINS muda de linha (pedido do cliente,
     * 2026-11-18, com o slide da controladoria de exemplo: "trazer a estrutura
     * oficial, como é e como fica").
     *
     * Hoje o PIS/COFINS está DENTRO das deduções (2.3 Imposto s/ Vendas = ICMS e
     * ISS + PIS/COFINS). Com a reforma a CBS é tributo "por fora": sai das
     * deduções e vira uma linha INFORMATIVA acima da Receita Bruta, que passa a
     * ser o faturamento sem ela. O exemplo do cliente:
     *
     *   hoje:  RB 100 · deduções 50 (ICMS 40 + PIS/COFINS 10) · devoluções 10 · RL 40
     *   fica:  CBS 10 · RB 90 · deduções 40 (ICMS 40) · devoluções 10 · RL 40
     *
     * É RECLASSIFICAÇÃO em todas as colunas: RB e deduções caem o mesmo valor e a
     * RL não muda. Na proposta a CBS é o PIS/COFINS da própria proposta (decisão do
     * cliente, 2026-11-18 — ver o cabeçalho da classe).
     *
     * As colunas são as da tabela: realizado (jan até o corte), projeção de 2026
     * e proposta do ano seguinte. O PIS/COFINS do realizado é o da carga de
     * tributos quando ela está no mesmo corte; o resto do ano, pela taxa de 2026
     * sobre a Receita de Vendas projetada.
     *
     * @param array      $linhas as linhas da tela (codigo, realizado, projecao, proposta)
     * @param array|null $trib   `Oportunidades::tributos` do negócio
     */
    public static function comoEComoFica(array $linhas, ?array $trib, int $corte, ?float $taxaFixa = null): array
    {
        $l = array_column($linhas, null, 'codigo');
        $v = static fn (string $c, string $k): float => (float)($l[$c][$k] ?? 0);

        // O PIS/COFINS de cada coluna (ver `pisCofinsDoNegocio`). Na proposta, a
        // taxa da projeção sobre a Receita de Vendas proposta — o que a 2.3 carrega.
        $base = self::pisCofinsDoNegocio($linhas, $trib, $corte, $taxaFixa);
        $pcReal = $base['realizado'];
        $pcProj = $base['projecao'];
        $pcProp = $base['taxa'] * $v('1.1', 'proposta');

        $colunas = [];
        foreach ([
            'realizado' => [$pcReal, 'PIS/COFINS'],
            'projecao' => [$pcProj, 'PIS/COFINS'],
            'proposta' => [$pcProp, 'CBS'],
        ] as $k => [$pc, $nomeTributo]) {
            // Deduções em valor POSITIVO, como no slide; a DRE as guarda negativas.
            $rb = $v('1', $k);
            $ded = 0.0 - $v('2', $k);
            $d23 = 0.0 - $v('2.3', $k);
            $rl = $v('3', $k);
            $icms = $d23 - $pc;
            $colunas[$k] = [
                'tributo_nome' => $nomeTributo,
                'como_e' => ['receita_bruta' => $rb, 'deducoes' => $ded, 'devolucoes' => 0.0 - $v('2.1', $k),
                    'avp' => 0.0 - $v('2.2', $k), 'impostos' => $d23, 'icms_iss' => $icms, 'pis_cofins' => $pc,
                    'receita_liquida' => $rl],
                // A CBS é o mesmo valor: sai das deduções e vai para cima da RB.
                'como_fica' => ['tributo' => $pc, 'receita_bruta' => $rb - $pc, 'deducoes' => $ded - $pc,
                    'devolucoes' => 0.0 - $v('2.1', $k), 'avp' => 0.0 - $v('2.2', $k),
                    'impostos' => $icms, 'icms_iss' => $icms, 'receita_liquida' => $rl],
            ];
        }
        return ['tem_tributos' => $trib !== null || $taxaFixa !== null, 'taxa' => $base['taxa'], 'colunas' => $colunas];
    }

    /**
     * O PIS/COFINS de 2026 do negócio por coluna, e a TAXA que vale na proposta
     * (PIS/COFINS da projeção ÷ Receita de Vendas da projeção).
     *
     * Realizado: o da carga de tributos quando ela está no mesmo corte; senão a
     * taxa de 2026 sobre a Receita de Vendas. O resto do ano, pela taxa. Com
     * `$taxaFixa` (a filial, que não tem carga de tributos própria), toda coluna
     * é essa taxa sobre a Receita de Vendas dela — a do negócio.
     *
     * @return array{realizado: float, projecao: float, taxa: float}
     */
    public static function pisCofinsDoNegocio(array $linhas, ?array $trib, int $corte, ?float $taxaFixa = null): array
    {
        $l = array_column($linhas, null, 'codigo');
        $real = (float)($l['1.1']['realizado'] ?? 0);
        $proj = (float)($l['1.1']['projecao'] ?? 0);
        if ($taxaFixa !== null) {
            return ['realizado' => $taxaFixa * $real, 'projecao' => $taxaFixa * $proj, 'taxa' => $taxaFixa];
        }
        $taxa = (float)($trib['taxa_pis_cofins'] ?? 0);
        $pcReal = $trib && (int)($trib['mes_ate'] ?? 0) === $corte
            ? (float)$trib['pis'] + (float)$trib['cofins']
            : $taxa * $real;
        $pcProj = $pcReal + $taxa * ($proj - $real);
        return ['realizado' => $pcReal, 'projecao' => $pcProj, 'taxa' => $proj != 0.0 ? $pcProj / $proj : $taxa];
    }

    /**
     * A DRE da tela no FORMATO DE 2027 (pedido do cliente, 2026-11-18: "converta o
     * resultado de 2026 para como vai ficar em 2027 ... fazer toda a exibição igual"):
     * a linha informativa da CBS ACIMA da Receita Bruta, Receita Bruta e Receita de
     * Vendas sem o PIS/COFINS, Deduções e Imposto s/ Vendas só com ICMS e ISS. A
     * Receita Líquida e tudo abaixo dela não mudam.
     *
     * É a ÚLTIMA coisa antes da resposta: o motor, o banco, o Painel e o desafio
     * seguem no formato da carga (a DRE de 2026, com o PIS/COFINS dentro da 2.3).
     * Os LANÇAMENTOS da 1.1 e da 2.3 já são no formato novo — quem converte é
     * `Projecao::aplicar`, com a mesma taxa.
     *
     * @param array $tributosDre o `comoEComoFica` da mesma visão (negócio, filial ou Corporativo)
     */
    public static function formatoNovo(array $linhas, array $tributosDre): array
    {
        $col = $tributosDre['colunas'] ?? [];
        if (!$col) {
            return $linhas;
        }
        $t = (float)($tributosDre['taxa'] ?? 0);
        $pc = [
            'realizado' => (float)$col['realizado']['como_e']['pis_cofins'],
            'projecao' => (float)$col['projecao']['como_e']['pis_cofins'],
            'proposta' => (float)$col['proposta']['como_e']['pis_cofins'],
        ];
        $pc['projetado'] = $pc['projecao'] - $pc['realizado'];
        $idx = array_flip(array_column($linhas, 'codigo'));
        $dist11 = isset($idx['1.1']) ? ($linhas[$idx['1.1']]['distribuido'] ?? null) : null;

        foreach ($linhas as $i => $l) {
            $sinal = in_array($l['codigo'], ['1', '1.1'], true) ? -1.0
                : (in_array($l['codigo'], ['2', '2.3'], true) ? 1.0 : 0.0);
            if ($sinal === 0.0) {
                continue;
            }
            foreach ($pc as $k => $x) {
                if (isset($l[$k]) && $l[$k] !== null) {
                    $linhas[$i][$k] = (float)$l[$k] + $sinal * $x;
                }
            }
            if (($l['distribuido'] ?? null) !== null && $dist11 !== null) {
                $linhas[$i]['distribuido'] = (float)$l['distribuido'] + $sinal * $t * (float)$dist11;
            }
            if ($l['codigo'] === '2.3') {
                $linhas[$i]['nome'] = $l['nome'] . ' (ICMS e ISS)';
            }
            $linhas[$i]['formato_novo'] = true;
        }
        $cbs = [
            'codigo' => 'CBS', 'nome' => 'CBS (PIS/COFINS em 2026) — informativa', 'nivel' => 1,
            'pai_codigo' => null, 'percentual' => 0, 'informativa' => true,
            'realizado' => $pc['realizado'], 'projetado' => $pc['projetado'], 'projecao' => $pc['projecao'],
            'proposta' => $pc['proposta'], 'proposta_origem' => 'INFORMATIVA', 'proposta_lancado' => null,
            'distribuido' => $dist11 !== null ? $t * (float)$dist11 : null,
        ];
        array_splice($linhas, $idx['1'] ?? 0, 0, [$cbs]);
        return $linhas;
    }

    /** A soma de vários `comoEComoFica` (o Corporativo). */
    public static function somarComoEComoFica(array $porNegocio): array
    {
        $tot = null;
        $semTributos = [];
        foreach ($porNegocio as $id => $x) {
            if (!$x['tem_tributos']) {
                $semTributos[] = $id;
            }
            if ($tot === null) {
                $tot = $x;
                continue;
            }
            foreach ($x['colunas'] as $k => $col) {
                foreach (['como_e', 'como_fica'] as $lado) {
                    foreach ($col[$lado] as $c => $val) {
                        $tot['colunas'][$k][$lado][$c] += $val;
                    }
                }
            }
        }
        if ($tot === null) {
            return ['tem_tributos' => false, 'colunas' => [], 'sem_tributos' => []];
        }
        $tot['tem_tributos'] = count($semTributos) < count($porNegocio);
        $tot['sem_tributos'] = $semTributos;
        return $tot;
    }
}

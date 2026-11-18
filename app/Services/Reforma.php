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
    public static function comoEComoFica(array $linhas, ?array $trib, int $corte): array
    {
        $l = array_column($linhas, null, 'codigo');
        $v = static fn (string $c, string $k): float => (float)($l[$c][$k] ?? 0);
        $taxa = (float)($trib['taxa_pis_cofins'] ?? 0);

        // O PIS/COFINS de cada coluna.
        $pcReal = $trib && (int)($trib['mes_ate'] ?? 0) === $corte
            ? (float)$trib['pis'] + (float)$trib['cofins']
            : $taxa * $v('1.1', 'realizado');
        $pcProj = $pcReal + $taxa * ($v('1.1', 'projecao') - $v('1.1', 'realizado'));

        // Na proposta, o PIS/COFINS que a 2.3 carrega: ela acompanha a receita, então
        // é o da projeção na mesma proporção da Receita de Vendas.
        $pcProp = $v('1.1', 'projecao') != 0.0
            ? $pcProj * $v('1.1', 'proposta') / $v('1.1', 'projecao')
            : $taxa * $v('1.1', 'proposta');

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
        return ['tem_tributos' => $trib !== null, 'colunas' => $colunas];
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

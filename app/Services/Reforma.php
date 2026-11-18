<?php

namespace App\Services;

/**
 * A CBS de 2027 pela LEI COMPLEMENTAR 214/2025 (decisão do cliente, 2026-11-18:
 * "aplique a CBS conforme a LC 214"), em conta MACRO por negócio. Em 2027 a CBS
 * substitui o PIS e a COFINS; o ICMS segue até 2029.
 *
 * O que a lei e os números oficiais dizem, e como entram aqui:
 *  - ALÍQUOTA: o TCU calculou a alíquota de referência da CBS para 2027 em 11,3%
 *    (Acórdão 2933/2026-Plenário, 28/10/2026), sujeita a resolução do Senado (se
 *    ela não sair até 22/12, vale a do TCU — art. 349, § 2º). Em 2027 e 2028 a CBS
 *    cobrada fica 0,1 p.p. ABAIXO da referência (art. 347), e o IBS de teste é
 *    0,1%: somados, 11,3% (`ALIQUOTA`). Combustíveis ficam fora — regime
 *    monofásico (arts. 172 a 180): a CBS é recolhida pelo produtor/importador e o
 *    posto não a destaca na revenda.
 *  - ATO COOPERATIVO: no regime específico das cooperativas (art. 271), opcional,
 *    a alíquota é ZERO no fornecimento do associado à cooperativa e da cooperativa
 *    ao associado — então a venda a SÓCIO não é base. A opção para valer em 2027
 *    se fazia de 01/09 a 31/10/2026; se a Copérdia NÃO optou, a venda a sócio
 *    também é tributada: a tela mostra esse cenário ao lado (`cbs_sem_regime`).
 *  - REDUÇÕES: 60% para insumos agropecuários do Anexo IX (art. 138) — rações,
 *    sementes, fertilizantes, defensivos — e para produtos agropecuários in natura;
 *    zero para a cesta básica nacional. Por negócio, em `NEGOCIOS`, como HIPÓTESE
 *    declarada do mix de cada um (a classificação fina é por NCM e não está aqui).
 *  - NÃO CUMULATIVIDADE: a CBS é débito sobre a venda MENOS crédito sobre o que
 *    se comprou para vendê-la. Na conta macro, o crédito é o custo das vendas que
 *    corresponde à venda a não sócio × a parte desse custo que gera crédito: quase
 *    toda na revenda (lojas, supermercados), nenhuma no que a cooperativa compra do
 *    associado com alíquota zero (grãos, animais, leite). Crédito presumido e
 *    transferência de crédito do associado (art. 272) ficam de fora — a favor da
 *    cooperativa, então a conta tende a PESAR A MAIS.
 *
 * É ordem de grandeza para o planejamento, não apuração: a resolução do Senado, a
 * opção pelo regime específico e o mix por NCM mudam o número.
 */
class Reforma
{
    /** CBS 2027 (11,3% − 0,1 p.p., art. 347) + IBS de teste (0,1%). */
    public const ALIQUOTA = 11.3;
    public const FONTE = 'LC 214/2025; alíquota de referência da CBS 2027 de 11,3% (TCU, Acórdão 2933/2026, pendente de resolução do Senado)';

    /**
     * cód. negócio => [redução de alíquota %, parte do custo com crédito %, monofásico, por quê]
     */
    public const NEGOCIOS = [
        '1' => [60, 0, false, 'Grãos in natura: redução de 60%. Compra do associado com alíquota zero, sem crédito.'],
        '2' => [60, 0, false, 'Animais vivos in natura: redução de 60%. Compra do associado sem crédito.'],
        '4' => [60, 0, false, 'Leite in natura: redução de 60%. Compra do associado sem crédito.'],
        '6' => [60, 50, false, 'Ração é insumo do Anexo IX: redução de 60%. Metade do custo com crédito (insumos de fornecedores do regime regular; o milho do associado não dá crédito).'],
        '7' => [0, 100, false, 'Madeira tratada: alíquota cheia, custo com crédito.'],
        '8' => [60, 100, false, 'Lojas: maior parte é insumo do Anexo IX (redução de 60%); revenda, custo com crédito.'],
        '9' => [40, 100, false, 'Supermercado: mix de cesta básica (zero), alimentos com redução de 60% e itens cheios — redução média de 40% como hipótese; revenda, custo com crédito.'],
        '11' => [0, 0, true, 'Combustíveis: regime monofásico, a CBS é do produtor/importador; o posto não a destaca.'],
        '12' => [60, 0, false, 'Leite in natura (resfriamento): redução de 60%; compra do associado sem crédito.'],
        '13' => [60, 50, false, 'Semente é insumo do Anexo IX: redução de 60%. Metade do custo com crédito (a semente multiplicada pelo associado não dá).'],
        '17' => [0, 0, false, 'Sem venda.'],
    ];

    /**
     * A CBS de 2027 de UM negócio, sobre uma receita bruta e um custo das vendas.
     *
     * @param array|null $trib  `Oportunidades::tributos` do negócio (mistura sócio × não sócio e taxa de PIS/COFINS de 2026)
     * @param float      $custo custo das vendas (positivo)
     * @return array|null
     */
    public static function cbs(string $codNegocio, ?array $trib, ?float $receitaBruta, float $custo): ?array
    {
        $p = self::NEGOCIOS[$codNegocio] ?? null;
        if (!$p || !$trib || $receitaBruta === null || $receitaBruta <= 0) {
            return null;
        }
        [$reducao, $parteCredito, $monofasico, $porque] = $p;
        $aliq = self::ALIQUOTA / 100 * (1 - $reducao / 100);
        $parteNaoSocio = (float)($trib['parte_nao_socio'] ?? 0);
        $calc = static function (float $parteTributada) use ($receitaBruta, $custo, $aliq, $parteCredito, $monofasico): array {
            if ($monofasico) {
                return ['base' => 0.0, 'debito' => 0.0, 'credito' => 0.0, 'cbs' => 0.0];
            }
            $base = $receitaBruta * $parteTributada;
            $debito = $base * $aliq;
            $credito = $custo * $parteTributada * ($parteCredito / 100) * $aliq;
            return ['base' => $base, 'debito' => $debito, 'credito' => $credito, 'cbs' => max(0.0, $debito - $credito)];
        };
        $com = $calc($parteNaoSocio);
        $sem = $calc(1.0);
        $pisCofins = $receitaBruta * (float)($trib['taxa_pis_cofins'] ?? 0);
        return $com + [
            'aliquota' => self::ALIQUOTA, 'aliquota_efetiva' => $aliq * 100, 'reducao' => $reducao,
            'parte_credito' => $parteCredito, 'monofasico' => $monofasico, 'porque' => $porque,
            'parte_nao_socio' => $parteNaoSocio,
            'pis_cofins' => $pisCofins,
            // + = a CBS pesa MENOS que o PIS/COFINS que sai; − = pesa mais.
            'impacto' => $pisCofins - $com['cbs'],
            'cbs_sem_regime' => $sem['cbs'],
            'impacto_sem_regime' => $pisCofins - $sem['cbs'],
        ];
    }

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
     * Em 2026 é só RECLASSIFICAÇÃO: RB e deduções caem o mesmo valor e a RL não
     * muda. Em 2027 a CBS SUBSTITUI o PIS/COFINS com outro valor — a proposta já
     * troca um pelo outro na linha 2.3 (`Projecao::aplicar`) —, e a diferença muda
     * a RL e desce por tudo o que está abaixo dela até o resultado líquido.
     *
     * As colunas são as da tabela: realizado (jan até o corte), projeção de 2026
     * e proposta do ano seguinte. O PIS/COFINS do realizado é o da carga de
     * tributos quando ela está no mesmo corte; o resto do ano, pela taxa de 2026
     * sobre a Receita de Vendas projetada.
     *
     * @param array      $linhas      as linhas da tela (codigo, realizado, projecao, proposta; a 2.3 com `cbs` quando a reforma foi aplicada)
     * @param array|null $tributacao  ['cod' => cód. do negócio, 'trib' => Oportunidades::tributos do negócio]
     */
    public static function comoEComoFica(array $linhas, ?array $tributacao, int $corte): array
    {
        $l = array_column($linhas, null, 'codigo');
        $v = static fn (string $c, string $k): float => (float)($l[$c][$k] ?? 0);
        $trib = $tributacao['trib'] ?? null;
        $taxa = (float)($trib['taxa_pis_cofins'] ?? 0);

        // O PIS/COFINS de cada coluna.
        $pcReal = $trib && (int)($trib['mes_ate'] ?? 0) === $corte
            ? (float)$trib['pis'] + (float)$trib['cofins']
            : $taxa * $v('1.1', 'realizado');
        $pcProj = $pcReal + $taxa * ($v('1.1', 'projecao') - $v('1.1', 'realizado'));

        // A proposta: a CBS que a 2.3 recebeu ou, se a 2.3 foi lançada à mão, a
        // que ela daria (a tabela, aí, segue na lei atual).
        $c23 = $l['2.3']['cbs'] ?? null;
        $aplicada = $c23 !== null;
        if (!$c23 && $tributacao) {
            $c23 = self::cbs(
                (string)$tributacao['cod'], $trib,
                $v('1.1', 'proposta'), max(0.0, -$v('4.1', 'proposta'))
            );
        }
        $pcProp = $c23 ? (float)$c23['pis_cofins'] : $taxa * $v('1.1', 'proposta');
        $cbsProp = $c23 ? (float)$c23['cbs'] : null;

        $colunas = [];
        foreach ([
            'realizado' => [$pcReal, $pcReal, 'PIS/COFINS', false],
            'projecao' => [$pcProj, $pcProj, 'PIS/COFINS', false],
            'proposta' => [$pcProp, $cbsProp, 'CBS', $aplicada],
        ] as $k => [$pc, $tributoFica, $nomeTributo, $jaNaTabela]) {
            // Deduções em valor POSITIVO, como no slide; a DRE as guarda negativas.
            $rb = $v('1', $k);
            $ded = 0.0 - $v('2', $k);
            $d23 = 0.0 - $v('2.3', $k);
            $rl = $v('3', $k);
            $abaixo = ['lucro_bruto' => $v('5', $k), 'resultado_antes_rateios' => $v('13', $k),
                'resultado_negocio' => $v('15', $k), 'resultado' => $v('18', $k)];
            $tributoFica ??= $pc;
            // Na proposta com a reforma aplicada, a tabela JÁ está no "como fica"
            // (a 2.3 traz ICMS + CBS): o "como é" desfaz a troca.
            $efeito = $pc - $tributoFica;           // o que a troca faz na RL
            if ($jaNaTabela) {
                $d23 = $d23 - $tributoFica + $pc;
                $ded = $ded - $tributoFica + $pc;
                $rl -= $efeito;
                foreach ($abaixo as $a => $x) {
                    $abaixo[$a] = $x - $efeito;
                }
            }
            $icms = $d23 - $pc;
            $comoE = ['receita_bruta' => $rb, 'deducoes' => $ded, 'devolucoes' => 0.0 - $v('2.1', $k),
                'avp' => 0.0 - $v('2.2', $k), 'impostos' => $d23, 'icms_iss' => $icms, 'pis_cofins' => $pc,
                'receita_liquida' => $rl];
            $comoFica = ['tributo' => $tributoFica, 'receita_bruta' => $rb - $tributoFica,
                'deducoes' => $ded - $pc, 'devolucoes' => $comoE['devolucoes'], 'avp' => $comoE['avp'],
                'impostos' => $icms, 'icms_iss' => $icms, 'receita_liquida' => $rl + $efeito];
            $abaixoFica = array_map(static fn ($x) => $x + $efeito, $abaixo);
            $colunas[$k] = [
                'tributo_nome' => $nomeTributo,
                'como_e' => $comoE,
                'como_fica' => $comoFica,
                'abaixo' => ['como_e' => $abaixo + ['receita_liquida' => $rl],
                    'como_fica' => $abaixoFica + ['receita_liquida' => $rl + $efeito]],
                'efeito' => $efeito,
            ];
        }
        return [
            'tem_tributos' => $trib !== null,
            'cbs_aplicada' => $aplicada,
            'colunas' => $colunas,
        ];
    }

    /**
     * A soma de vários `comoEComoFica` (o Corporativo). Margens não se somam:
     * a tela as recalcula dos valores.
     */
    public static function somarComoEComoFica(array $porNegocio): array
    {
        $tot = null;
        $semTributos = [];
        $aplicadas = [];
        foreach ($porNegocio as $id => $x) {
            if (!$x['tem_tributos']) {
                $semTributos[] = $id;
            }
            if ($tot === null) {
                $tot = $x;
                $aplicadas = $x['tem_tributos'] ? [$x['cbs_aplicada']] : [];
                continue;
            }
            // "Aplicada" no consolidado: em todos os negócios que TÊM tributos —
            // o que não tem não tem PIS/COFINS a trocar.
            if ($x['tem_tributos']) {
                $aplicadas[] = $x['cbs_aplicada'];
            }
            foreach ($x['colunas'] as $k => $col) {
                foreach (['como_e', 'como_fica'] as $lado) {
                    foreach ($col[$lado] as $c => $val) {
                        $tot['colunas'][$k][$lado][$c] += $val;
                    }
                    foreach ($col['abaixo'][$lado] as $c => $val) {
                        $tot['colunas'][$k]['abaixo'][$lado][$c] += $val;
                    }
                }
                $tot['colunas'][$k]['efeito'] += $col['efeito'];
            }
        }
        if ($tot === null) {
            return ['tem_tributos' => false, 'cbs_aplicada' => false, 'colunas' => [], 'sem_tributos' => []];
        }
        $tot['tem_tributos'] = count($semTributos) < count($porNegocio);
        $tot['cbs_aplicada'] = $aplicadas !== [] && !in_array(false, $aplicadas, true);
        $tot['sem_tributos'] = $semTributos;
        return $tot;
    }
}

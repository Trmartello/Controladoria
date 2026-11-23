<?php

namespace App\Services;

use App\Core\Database;

/**
 * Os DRIVERS do faturamento no desafio de 2027 (pedido do cliente, 2026-11-23:
 * "gere um novo desafio… e a partir dali ele continua fazendo a projeção").
 *
 * O desafio é a PROPOSTA PADRÃO do negócio: a projeção do ano (jan–out realizado
 * + nov–dez planejado)
 * com as premissas do painel (IPCA, PIB, % crescimento, pessoal 5,5%, vale,
 * prêmios e PPR da diretriz), custo e deduções acompanhando a receita (margem
 * bruta preservada) e as demais despesas na base — sem nenhum lançamento. Para
 * a maioria dos negócios a Receita de Vendas cresce IPCA + PIB + crescimento.
 * Os negócios que DERIVAM de outro têm o faturamento por um driver de volume,
 * e é ele que vira o crescimento padrão da Receita de Vendas — e, portanto, o
 * PISO dela (`Projecao::aplicar`):
 *
 *  - **Pecuária**: o cenário REALISTA da planilha do negócio ("PECUÁRIA VOLUMES
 *    e FATURAMENTO Planejamento 2027", aba "Projetado 2027", D41).
 *  - **Leite**: litros da cota Aurora do ano (meta do site, `DesafioCota`) × o
 *    preço médio por litro dos 12 meses realizados, reajustado pelas premissas
 *    (Receita de Vendas de nov/25–out/26 ÷ litros
 *    captados no App Leite, naturezas 1101 e 1102).
 *  - **Postos de resfriamento**: a mesma cota × R$ 0,055 por litro
 *    (`DesafioCota::RS_POR_LITRO`), sem reajuste.
 *  - **Fábrica de Rações**: a ração de BOVINOS pela meta das Agropecuárias (quem
 *    a vende) × R$ 100/t de prestação de serviço, sem reajuste; o resto do
 *    faturamento pela projeção × premissas. A parte de suínos (sistema Aurora) é
 *    driver a definir — vai pelo resto.
 *  - **UTM, Cereais e UBS**: projeção × premissas, com a nota "driver a definir".
 *
 * Agropecuárias, Supermercados e Postos de Combustíveis vão pela base ×
 * premissas — o cliente pediu a margem bruta preservada neles, que é o que o
 * motor faz em todos (custo acompanha a receita).
 *
 * Os volumes da base (litros, toneladas) vêm do Qlik e ficam aqui, por ano,
 * com a data da extração; as metas (cota, bovinos) são lidas NA HORA do site.
 * Sem a meta, o negócio cai em projeção × premissas e a nota diz por quê.
 */
class DesafioDrivers
{
    public const PECUARIA = '2';
    public const LEITE = '4';
    public const RACOES = '6';
    public const POSTOS = '12';
    public const AGROPECUARIAS = '8';

    /** Negócios sem driver definido: projeção × premissas, ditos. */
    public const A_DEFINIR = [
        '1' => 'Cereais',
        '7' => 'UTM (deriva das vendas de produtos UTM pelas Agropecuárias)',
        '13' => 'UBS',
    ];

    /** O começo do nome do indicador de ração de bovinos na meta das Agropecuárias. */
    public const INDICADOR_BOVINOS = 'bovino';
    /** Ano abaixo disto está em toneladas; acima, em kg. */
    public const LIMITE_TONELADAS = 1000000.0;

    /**
     * Os volumes dos 12 meses realizados que os drivers usam, por ano da proposta.
     * Extraídos do Qlik em 23/11/2026, nov/2025–out/2026.
     */
    public const BASE = [
        2027 => [
            // Planilha do negócio, cenário realista (D41), no formato da carga
            // (com o PIS/COFINS dentro): é o que o motor compara.
            'pecuaria_faturamento' => 732218693.78,
            // App Leite (e5ed18f6-0e4a-4c29-94e6-18b951d67fe9), [Qtde Líquida Item],
            // naturezas 1101 e 1102: 18.115.965 (nov–dez/25) + 89.788.740 (jan–out/26).
            'leite_litros' => 107904705.0,
            // Comercial Global (4aed35d9-bc8c-42dd-a5d7-ea13925a53b9), Linha 'RACOES
            // BOVINOS IND.', FlagParticipaFaturamento=1, Qtde × Peso Padrão ÷ 1.000:
            // 9.195,59 t (nov–dez/25) + 44.380,26 t (jan–out/26).
            'bovinos_toneladas' => 53575.85,
            'racoes_rs_por_t' => 100.0,
        ],
    ];

    /**
     * O faturamento (Receita de Vendas, formato da carga) do driver do negócio
     * no ano, ou só a nota quando o negócio vai por projeção × premissas.
     *
     * @param float $base11  a Receita de Vendas da projeção do ano (a base da proposta)
     * @param float $crescPremissa o crescimento padrão da receita pelas premissas (%)
     * @return array{valor: ?float, nota: string, driver: string}|null null = projeção × premissas, sem nota
     */
    public static function faturamento(string $cod, int $anoPlan, float $base11, float $crescPremissa): ?array
    {
        $b = self::BASE[$anoPlan] ?? null;
        if (isset(self::A_DEFINIR[$cod])) {
            return ['valor' => null, 'driver' => 'A_DEFINIR',
                'nota' => 'Projeção do ano × premissas (IPCA + PIB + crescimento) — driver de volume a definir.'];
        }
        if (!$b) {
            return null;
        }
        $mi = static fn (float $v) => number_format($v, 0, ',', '.');
        switch ($cod) {
            case self::PECUARIA:
                return ['valor' => $b['pecuaria_faturamento'], 'driver' => 'PLANILHA', 'premissas' => false,
                    'nota' => 'Faturamento do cenário REALISTA da planilha do negócio (Planejamento 2027): R$ '
                        . $mi($b['pecuaria_faturamento']) . ' — já em valores de 2027: IPCA, PIB e crescimento não '
                        . 'mexem nele.'];
            case self::POSTOS:
                $cota = DesafioCota::cotaDoLeite($anoPlan);
                if (!$cota) {
                    return ['valor' => null, 'driver' => 'SEM_META',
                        'nota' => 'Sem a meta "Cota Aurora" do Leite no ano: projeção × premissas até a meta ser lançada.'];
                }
                return ['valor' => $cota['litros'] * DesafioCota::RS_POR_LITRO, 'driver' => 'COTA', 'premissas' => false,
                    'nota' => sprintf('Cota Aurora do Leite (meta "%s"): %s litros × R$ %s por litro resfriado%s, '
                        . 'sem reajuste: IPCA, PIB e crescimento não mexem nele (o valor só cresce com o volume).',
                        $cota['indicador'], $mi($cota['litros']),
                        number_format(DesafioCota::RS_POR_LITRO, 3, ',', '.'),
                        $cota['em_mil_litros'] ? ' (meta lançada em mil litros)' : '')];
            case self::LEITE:
                $cota = DesafioCota::cotaDoLeite($anoPlan);
                $rec12 = self::receitaDozeMeses(self::LEITE);
                if (!$cota || $b['leite_litros'] <= 0 || $rec12 <= 0) {
                    return ['valor' => null, 'driver' => 'SEM_META',
                        'nota' => 'Sem a meta "Cota Aurora" no ano: projeção × premissas até a meta ser lançada.'];
                }
                // O PREÇO vem dos 12 meses realizados (decisão do cliente, 2026-11-23) e
                // as premissas o reajustam ("atualizar… conforme alterações das
                // premissas", mesmo dia); o VOLUME é a cota.
                $preco = $rec12 / $b['leite_litros'];
                $bruto = $cota['litros'] * $preco;
                return ['valor' => $bruto * (1 + $crescPremissa / 100), 'driver' => 'COTA', 'premissas' => true,
                    'sem_premissas' => $bruto,
                    'nota' => sprintf('Cota Aurora (meta "%s"): %s litros × R$ %s por litro — o preço médio dos 12 '
                        . 'meses (Receita de Vendas de R$ %s ÷ %s litros captados, nov/25–out/26) = R$ %s, '
                        . 'reajustado por IPCA + PIB + crescimento (%s%%).',
                        $cota['indicador'], $mi($cota['litros']), number_format($preco, 4, ',', '.'),
                        $mi($rec12), $mi($b['leite_litros']), $mi($bruto),
                        number_format($crescPremissa, 2, ',', '.'))];
            case self::RACOES:
                $meta = self::bovinosDoAno($anoPlan);
                if (!$meta) {
                    return ['valor' => null, 'driver' => 'SEM_META',
                        'nota' => 'Sem a meta de ração de bovinos das Agropecuárias no ano: projeção × premissas. '
                            . 'Suínos: driver a definir.'];
                }
                $rs = $b['racoes_rs_por_t'];
                $bovBase = $b['bovinos_toneladas'] * $rs;
                $resto = $base11 - $bovBase;
                $valor = $meta['toneladas'] * $rs + $resto * (1 + $crescPremissa / 100);
                return ['valor' => $valor, 'driver' => 'BOVINOS', 'premissas' => true,
                    'nota' => sprintf('Ração de bovinos pela meta das Agropecuárias ("%s"): %s t × R$ %s/t, sem reajuste; '
                        . 'o resto (R$ %s, a projeção sem os %s t de bovinos dos 12 meses) × premissas. Suínos: driver a definir.',
                        $meta['indicador'], number_format($meta['toneladas'], 0, ',', '.'),
                        number_format($rs, 2, ',', '.'), $mi($resto), number_format($b['bovinos_toneladas'], 0, ',', '.'))];
        }
        return null;
    }

    /**
     * A Receita de Vendas REALIZADA nos 12 meses fechados mais recentes (do mês
     * depois do corte no ano anterior ao corte do ano), no formato da carga — a
     * base do preço médio por litro do Leite.
     */
    public static function receitaDozeMeses(string $cod): float
    {
        $f = Database::um('SELECT ano, mes_fechado_ate FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$f) {
            return 0.0;
        }
        $ano = (int)$f['ano'];
        $corte = (int)$f['mes_fechado_ate'];
        return (float)(Database::um(
            "SELECT SUM(v.valor) s FROM dre_valor v
               JOIN dre_conta c ON c.id = v.conta_id JOIN negocio n ON n.id = v.negocio_id
              WHERE n.cod_negocio = ? AND c.codigo = ? AND v.tipo = 'REAL'
                AND ((v.ano = ? AND v.mes <= ?) OR (v.ano = ? AND v.mes > ?))",
            [$cod, Projecao::LINHA_RECEITA, $ano, $corte, $ano - 1, $corte]
        )['s'] ?? 0.0);
    }

    /**
     * A meta de ração de bovinos das Agropecuárias no ano, em toneladas.
     *
     * @return array{toneladas: float, meta: float, indicador: string}|null
     */
    public static function bovinosDoAno(int $ano): ?array
    {
        $ind = Database::um(
            "SELECT i.* FROM indicador i
               JOIN planejamento p ON p.id = i.planejamento_id
               JOIN negocio n ON n.id = p.negocio_id
               JOIN ciclo c ON c.id = p.ciclo_id
              WHERE n.cod_negocio = ? AND ? BETWEEN c.ano_base AND c.ano_fim
                AND LOWER(i.nome) LIKE ?
              ORDER BY c.id DESC, i.id LIMIT 1",
            [self::AGROPECUARIAS, $ano, '%' . self::INDICADOR_BOVINOS . '%']
        );
        if (!$ind) {
            return null;
        }
        $serie = Metas::anexarSeries([$ind])[0]['metas'] ?? [];
        $doAno = array_values(array_filter($serie, static fn ($m) => (int)$m['ano'] === $ano))[0] ?? null;
        if (!$doAno || (float)$doAno['valor'] <= 0) {
            return null;
        }
        $meta = (float)$doAno['valor'];
        return [
            'toneladas' => $meta < self::LIMITE_TONELADAS ? $meta : $meta / 1000,
            'meta' => $meta,
            'indicador' => (string)$ind['nome'],
        ];
    }

    /**
     * Põe o driver no crescimento padrão da Receita de Vendas. Devolve os
     * crescimentos (com a 1.1 trocada quando o driver tem valor) e o driver,
     * para a tela dizer de onde veio o número.
     *
     * @return array{0: array<string,float>, 1: ?array}
     */
    public static function aplicar(array $cresc, string $cod, int $anoPlan, array $linhas): array
    {
        $base11 = 0.0;
        foreach ($linhas as $l) {
            if ($l['codigo'] === Projecao::LINHA_RECEITA) {
                $base11 = (float)$l['projecao'];
            }
        }
        $premissa = (float)($cresc[Projecao::LINHA_RECEITA] ?? 0.0);
        $d = self::faturamento($cod, $anoPlan, $base11, $premissa);
        if ($d && $d['valor'] !== null && $base11 > 0) {
            $cresc[Projecao::LINHA_RECEITA] = ($d['valor'] / $base11 - 1) * 100;
            $d['crescimento'] = $cresc[Projecao::LINHA_RECEITA];
            $d['base'] = $base11;
        }
        return [$cresc, $d];
    }
}

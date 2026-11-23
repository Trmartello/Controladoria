<?php

namespace App\Services;

use App\Core\Database;

/**
 * O desafio dos POSTOS DE RESFRIAMENTO DE LEITE pela cota da Aurora (pedido do
 * cliente, 2026-11-23): "o posto de leite depende do volume de leite coletado
 * pelo negócio leite… recebe um valor aproximado de 6 centavos ao litro para
 * cada litro resfriado, e esse valor só aumenta se o volume for maior, não pelo
 * aumento do valor por litro"; "pegue o indicador Cota Aurora… e projete 0,06"
 * (revisto em 2026-11-23: "decidimos alterar para R$ 0,055")
 * para projetar o faturamento do negócio posto de resfriamento de leite".
 *
 * O faturamento do desafio dos Postos deixa de ser o de 2026 × (1 + IPCA + PIB)
 * e passa a ser **a cota Aurora do ano, da meta de indicadores do Leite, ×
 * R$ 0,055 por litro**. É lido na hora, do próprio banco: mudou a cota no site,
 * mudou o desafio dos Postos — sem carga nova, e a meta do Leite e o desafio do
 * Posto ficam sempre no mesmo volume.
 *
 *  - O indicador é o do planejamento do Leite (negócio 4) no ciclo que cobre o
 *    ano, com nome começando por "Cota Aurora". O ano é a soma dos meses
 *    (`Metas::anexarSeries`, a regra única de leitura das metas).
 *  - **Unidade**: a meta está em MIL litros na tela ("8.950" em janeiro são 8,95
 *    milhões de litros no mês — o volume do Leite). Ano abaixo de 10 milhões é
 *    lido como mil litros (×1.000); acima, como litros. O Leite capta ~110
 *    milhões de litros por ano: em mil litros isso é ~110 mil, em litros ~110
 *    milhões — não há como confundir as duas faixas, e a tela diz qual foi lida.
 *  - A receita líquida segue a relação ROL/ROB do desafio carregado, e o
 *    resultado líquido a MARGEM dele (a diretriz "preservar margem", a mesma
 *    dos demais negócios). Sem a cota (indicador ausente ou sem meta no ano),
 *    vale o desafio carregado, inalterado.
 */
class DesafioCota
{
    /** Postos de resfriamento de leite e Leite (`negocio.cod_negocio`). */
    public const NEGOCIO_POSTOS = '12';
    public const NEGOCIO_LEITE = '4';
    /** O começo do nome do indicador na meta do Leite. */
    public const INDICADOR = 'cota aurora';
    /** O que o Posto recebe por litro resfriado, sem reajuste (o cliente). */
    public const RS_POR_LITRO = 0.055;
    /** Ano abaixo disto está em mil litros (ver "Unidade"). */
    public const LIMITE_MIL_LITROS = 10000000.0;

    /**
     * A cota Aurora do Leite no ano, em litros, com o que foi lido.
     *
     * @return array{litros:float, meta:float, em_mil_litros:bool, meses:int, indicador:string}|null
     */
    public static function cotaDoLeite(int $ano): ?array
    {
        $ind = Database::um(
            "SELECT i.* FROM indicador i
               JOIN planejamento p ON p.id = i.planejamento_id
               JOIN negocio n ON n.id = p.negocio_id
               JOIN ciclo c ON c.id = p.ciclo_id
              WHERE n.cod_negocio = ? AND ? BETWEEN c.ano_base AND c.ano_fim
                AND LOWER(TRIM(i.nome)) LIKE ?
              ORDER BY c.id DESC, i.id LIMIT 1",
            [self::NEGOCIO_LEITE, $ano, self::INDICADOR . '%']
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
        $emMil = $meta < self::LIMITE_MIL_LITROS;
        return [
            'litros' => $emMil ? $meta * 1000 : $meta,
            'meta' => $meta,
            'em_mil_litros' => $emMil,
            'meses' => count((array)$doAno['meses']),
            'indicador' => (string)$ind['nome'],
        ];
    }

    /**
     * Troca o desafio dos Postos pelo da cota, mantendo a relação ROL/ROB e a
     * margem do desafio carregado.
     *
     * @param array<int,array> $porNegocio negocio_id => ['receita_bruta', 'receita_liquida', 'resultado', 'nota']
     * @return array<int,array> o mesmo mapa, com os Postos refeitos (quando há cota)
     */
    public static function aplicar(array $porNegocio, int $ano): array
    {
        $postos = Database::um('SELECT id FROM negocio WHERE cod_negocio = ?', [self::NEGOCIO_POSTOS]);
        $id = $postos ? (int)$postos['id'] : 0;
        if (!$id || !isset($porNegocio[$id])) {
            return $porNegocio;
        }
        $cota = self::cotaDoLeite($ano);
        if (!$cota) {
            return $porNegocio;
        }
        $d = $porNegocio[$id];
        $rob = (float)$d['receita_bruta'];
        $rol = (float)$d['receita_liquida'];
        $razao = $rob > 0 ? $rol / $rob : 1.0;
        $margem = $rol != 0.0 ? (float)$d['resultado'] / $rol : 0.0;
        $novaRob = $cota['litros'] * self::RS_POR_LITRO;
        $novaRol = $novaRob * $razao;
        $porNegocio[$id] = [
            'receita_bruta' => round($novaRob, 2),
            'receita_liquida' => round($novaRol, 2),
            'resultado' => round($novaRol * $margem, 2),
            'nota' => sprintf(
                'Faturamento pela cota Aurora do Leite (meta "%s" de %d): %s litros × R$ %s por litro resfriado%s; '
                . 'margem do desafio preservada.',
                $cota['indicador'],
                $ano,
                number_format($cota['litros'], 0, ',', '.'),
                number_format(self::RS_POR_LITRO, 3, ',', '.'),
                $cota['em_mil_litros'] ? ' (meta lançada em mil litros)' : ''
            ),
            'cota' => $cota,
        ] + $d;
        return $porNegocio;
    }
}

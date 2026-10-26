<?php

namespace App\Services;

use App\Core\Database;

/**
 * As séries de meta e real de um indicador — a regra ÚNICA de leitura.
 *
 * Três telas leem os mesmos números: a tabela de Metas, a **Matriz de Execução**
 * (na Cascata) e o **Relatório/Dossiê**. Até aqui cada uma repetia a sua própria
 * consulta, com a mesma subconsulta de `MAX(versao_meta)` escrita três vezes.
 * Enquanto o grão era o ANO isso era redundância tolerável; com o MÊS deixou de
 * ser: quem não souber da coluna nova lê a linha de dezembro como se fosse o
 * ano inteiro e mostra um número plausível e errado, que é o pior defeito
 * possível numa tela de meta × real.
 *
 * Por isso a regra está aqui, e as três passaram a chamar `anexarSeries()`.
 *
 * ## O grão: `mes = 0` é o ano inteiro
 *
 * `indicador_valor.mes` vale 0 para o valor ANUAL e 1–12 para o mês. **Zero e
 * não `NULL`**: a chave única `(indicador_id, ano, mes, tipo, versao_meta)` com
 * `NULL` não impediria duplicata nenhuma — o MySQL trata cada `NULL` como
 * distinto —, e dois valores anuais do mesmo ano passariam a conviver, com a
 * leitura escolhendo um deles por sorte.
 *
 * ## Um caminho por ano (decisão do cliente, 2026-10-26, revista no mesmo dia)
 *
 * **Havendo mês lançado, o ano é a agregação dos meses** — na meta e no
 * realizado, sem exceção. O valor anual digitado só vale enquanto não houver
 * mês nenhum.
 *
 * A primeira versão desta regra era outra: a meta anual mandava e os meses
 * eram a distribuição dela, com a tela apontando a diferença ("os meses somam
 * 43 de 45"). O cliente reviu no mesmo dia — *"para o ano atual deve ser
 * mensal e somar o total do ano; para os demais anos lançamento do valor
 * anual, um único campo"* — e a revisão é mais simples de usar e de explicar:
 * cada ano tem UM caminho de lançamento, o mensal ou o anual, e não dois
 * números do mesmo ano se contradizendo na mesma linha. A tela faz a outra
 * metade, oferecendo os meses apenas no ano em execução.
 *
 * `anual` e `meses_total` continuam no payload: é com eles que a tela sabe se
 * a célula do ano é digitável ou derivada.
 *
 * ## Agregar não é somar
 *
 * `indicador.agregacao` diz como 12 meses viram um ano, porque a resposta
 * depende do que se mede: R$ e tonelada **somam**; margem e índice tiram
 * **média**; estoque, dívida e cobertura valem pelo **último mês** com valor —
 * são saldos, e somar saldo mensal produz um número que não existe. Somar tudo
 * seria a implementação mais simples e a mais perigosa: o erro não aparece na
 * tela, aparece na decisão.
 */
class Metas
{
    /** O grão anual. Ver o bloco "O grão" acima. */
    public const ANO_INTEIRO = 0;

    public const AGREGACOES = ['SOMA', 'MEDIA', 'ULTIMO'];

    /**
     * Como 12 meses viram um ano. `$porMes` é `[mes => valor]`, só com os meses
     * que têm valor — mês em branco não entra em conta nenhuma (numerador nem
     * denominador), senão a média de um trimestre lançado sairia dividida por
     * doze.
     */
    public static function agregar(array $porMes, string $agregacao): ?float
    {
        $valores = array_map('floatval', array_filter(
            $porMes,
            static fn ($v) => $v !== null && $v !== ''
        ));
        if (!$valores) {
            return null;
        }
        if ($agregacao === 'MEDIA') {
            return array_sum($valores) / count($valores);
        }
        if ($agregacao === 'ULTIMO') {
            // O último mês COM VALOR, não o mês 12: em outubro, o ano de um
            // saldo vale o que ele era em outubro.
            $meses = array_keys($valores);
            return $valores[max($meses)];
        }
        return array_sum($valores);
    }

    /**
     * Preenche `metas` e `reais` de cada indicador da lista.
     *
     * Cada entrada é `{ano, valor, meses, meses_total}` (e `versao_meta` nas
     * metas): `valor` é o número DO ANO pela regra acima, `meses` é
     * `[mes => valor]` e `meses_total` é a agregação deles — é ela que a tela
     * compara com a meta anual para apontar a diferença.
     *
     * Uma consulta só para a lista inteira. Era uma por indicador, dentro do
     * laço, e com os meses passaria a ser até treze vezes mais linhas por
     * indicador.
     */
    public static function anexarSeries(array $indicadores): array
    {
        if (!$indicadores) {
            return $indicadores;
        }
        $ids = array_map('intval', array_column($indicadores, 'id'));
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        // Meta na versão mais recente de cada ano; real não tem versão. O MAX é
        // por (indicador, ano) e ignora o mês de propósito: revisar a meta de um
        // ano revisa o ano inteiro, meses inclusive — uma versão nova que
        // mantivesse os meses da anterior seria meia revisão.
        $linhas = Database::todos(
            "SELECT v.indicador_id, v.ano, v.mes, v.tipo, v.valor, v.versao_meta
               FROM indicador_valor v
              WHERE v.indicador_id IN ({$marcas})
                AND (v.tipo = 'REAL' OR v.versao_meta = (
                      SELECT MAX(v2.versao_meta) FROM indicador_valor v2
                       WHERE v2.indicador_id = v.indicador_id
                         AND v2.ano = v.ano AND v2.tipo = 'META'))
              ORDER BY v.ano, v.mes",
            $ids
        );

        $bruto = [];
        foreach ($linhas as $l) {
            $bruto[(int)$l['indicador_id']][$l['tipo']][(int)$l['ano']][(int)$l['mes']] = $l;
        }

        foreach ($indicadores as &$ind) {
            $agregacao = in_array($ind['agregacao'] ?? '', self::AGREGACOES, true)
                ? $ind['agregacao'] : 'SOMA';
            $meu = $bruto[(int)$ind['id']] ?? [];
            $ind['metas'] = self::serie($meu['META'] ?? [], $agregacao, 'META');
            $ind['reais'] = self::serie($meu['REAL'] ?? [], $agregacao, 'REAL');
        }
        unset($ind);
        return $indicadores;
    }

    /** Monta a série de um tipo: `[ano => [mes => linha]]` → lista por ano. */
    private static function serie(array $porAno, string $agregacao, string $tipo): array
    {
        ksort($porAno);
        $serie = [];
        foreach ($porAno as $ano => $porMes) {
            // Tudo sai como NÚMERO, não como a string do DECIMAL. Metade da
            // série vem do banco e metade é calculada aqui: devolver "100.00"
            // num ano e 60 no outro faria cada leitor decidir sozinho quando
            // converter — e a tela compara os dois valores entre si.
            $anual = isset($porMes[self::ANO_INTEIRO])
                ? (float)$porMes[self::ANO_INTEIRO]['valor'] : null;
            $meses = [];
            foreach ($porMes as $mes => $linha) {
                if ($mes !== self::ANO_INTEIRO) {
                    $meses[$mes] = (float)$linha['valor'];
                }
            }
            $total = self::agregar($meses, $agregacao);
            // Mandam os MESES, nos dois tipos. O anual só vale enquanto não
            // houver mês — ver "Um caminho por ano", acima.
            $valor = $total ?? $anual;
            if ($valor === null) {
                continue;
            }
            $entrada = [
                'ano'         => (int)$ano,
                'valor'       => $valor,
                'anual'       => $anual,
                'meses'       => (object)$meses,
                'meses_total' => $total,
            ];
            if ($tipo === 'META') {
                $entrada['versao_meta'] = (int)(reset($porMes)['versao_meta'] ?? 1);
            }
            $serie[] = $entrada;
        }
        return $serie;
    }

    /**
     * O ano em execução do plano — o que a tela de Metas abre mês a mês.
     *
     * É do CICLO, e não do navegador de quem olha (decisão do cliente,
     * 2026-10-26): o ano em execução é o mesmo para todos os negócios e para o
     * Relatório. Guardá-lo como preferência de tela faria cada pessoa ver um ano
     * diferente do mesmo plano.
     *
     * Em branco, vale o ano corrente quando ele cabe no ciclo; fora dele, o
     * primeiro ano. Sem essa segunda regra, um ciclo que ainda não começou (ou
     * já terminou) abriria expandido num ano que não existe na tabela.
     */
    public static function anoEmExecucao(array $ciclo): int
    {
        $base = (int)($ciclo['ano_base'] ?? 0);
        $fim  = (int)($ciclo['ano_fim'] ?? 0);
        $escolhido = (int)($ciclo['ano_execucao'] ?? 0);
        if ($escolhido >= $base && $escolhido <= $fim) {
            return $escolhido;
        }
        $hoje = (int)date('Y');
        return ($hoje >= $base && $hoje <= $fim) ? $hoje : $base;
    }
}

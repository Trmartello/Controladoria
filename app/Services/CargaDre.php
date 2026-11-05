<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga da DRE gerencial: estrutura de contas + valor por negócio, conta e mês.
 *
 * Diferente de `CargaConteudo` em uma coisa que decide todo o resto: isto NÃO é
 * conteúdo editável. É número que veio de fora, e número de fora se SOBRESCREVE
 * — a carga do fechamento seguinte reescreve o mês que mudou em vez de empilhar
 * uma segunda verdade. Por isso `INSERT ... ON DUPLICATE KEY UPDATE`, e por
 * isso rodar a mesma carga duas vezes é inofensivo.
 *
 * A marca em `carga_conteudo` continua existindo, mas com outro papel: ela
 * evita o trabalho repetido a cada deploy, não protege edição de ninguém.
 *
 * **A regra do fechamento mora aqui, e não na cabeça de quem gera o arquivo.**
 * Rateio é a última etapa do fechamento contábil: mês sem rateio é mês ABERTO,
 * e o realizado dele está incompleto — costuma exibir resultado líquido igual
 * ao resultado do negócio e valor fora de escala. A carga recusa um mês
 * declarado como fechado cujo rateio consolidado seja zero, e diz qual mês
 * recusou. Em 2026 isso é outubro: R$ 373,2 mi de receita lançada e Rateios = 0.
 */
class CargaDre
{
    /** A linha 12 da DRE (Rateios) é `Cód Indicador` 14 — não 12. */
    private const CONTA_RATEIOS = '14';

    /**
     * @param array $dados o `require` de um `database/dre_*.php`
     * @return array{contas:int, valores:int, negocios:int, ignorados:array}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mesesReal = $dados['meses_real'] ?? [];
        if ($ano < 2000 || !$mesesReal) {
            throw new RuntimeException('Carga de DRE sem ano ou sem meses realizados.');
        }

        // Confere TUDO antes de escrever QUALQUER coisa. Carga recusada no meio
        // deixaria a estrutura nova com os valores velhos — e a DRE fecharia
        // errado sem ninguém ver. Validar primeiro custa uma passada a mais.
        $estrutura = $dados['contas'] ?? [];
        if (!$estrutura) {
            throw new RuntimeException('Carga de DRE sem estrutura de contas.');
        }
        $codigos = array_column($estrutura, 1);
        $porNegocio = self::agrupar($dados['valores'] ?? [], $codigos, count($mesesReal));
        self::exigirMesesFechados($porNegocio, $mesesReal);

        $contas = self::gravarContas($pdo, $estrutura);

        $ins = $pdo->prepare(
            'INSERT INTO dre_valor (negocio_id, conta_id, ano, mes, tipo, valor)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');

        $gravados = 0;
        $negocios = 0;
        $ignorados = [];
        foreach ($porNegocio as $cod => $linhas) {
            $busca->execute([(string)$cod]);
            $negocioId = (int)$busca->fetchColumn();
            if (!$negocioId) {
                // Negócio que não está no cadastro (saiu da lista oficial, por
                // exemplo) não vira linha órfã e não derruba a carga dos
                // outros: fica anotado e o migrate o imprime.
                $ignorados[] = $cod;
                continue;
            }
            $negocios++;
            foreach ($linhas as $codConta => $serie) {
                $contaId = $contas[$codConta];
                foreach ($mesesReal as $i => $mes) {
                    $ins->execute([$negocioId, $contaId, $ano, $mes, 'REAL', $serie['real'][$i]]);
                    $gravados++;
                }
                foreach ($serie['meta'] as $i => $v) {
                    $ins->execute([$negocioId, $contaId, $ano, $i + 1, 'META', $v]);
                    $gravados++;
                }
            }
        }

        $pdo->prepare(
            'INSERT INTO dre_fechamento (ano, mes_fechado_ate, carga, extraido_em, fonte)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mes_fechado_ate = VALUES(mes_fechado_ate),
                                     carga = VALUES(carga),
                                     extraido_em = VALUES(extraido_em),
                                     fonte = VALUES(fonte),
                                     aplicado_em = CURRENT_TIMESTAMP'
        )->execute([
            $ano,
            max($mesesReal),
            (string)($dados['chave'] ?? ''),
            $dados['extraido_em'] ?? null,
            $dados['fonte'] ?? null,
        ]);

        return ['contas' => count($contas), 'valores' => $gravados,
                'negocios' => $negocios, 'ignorados' => $ignorados];
    }

    /**
     * Estrutura de contas, vinda na mesma extração dos valores.
     *
     * @return array<string,int> código => id
     */
    private static function gravarContas(PDO $pdo, array $contas): array
    {
        $ins = $pdo->prepare(
            'INSERT INTO dre_conta (codigo, nome, nivel, pai_codigo, ordem, percentual)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), nivel = VALUES(nivel),
                                     pai_codigo = VALUES(pai_codigo), ordem = VALUES(ordem),
                                     percentual = VALUES(percentual)'
        );
        foreach ($contas as $c) {
            [$ordem, $codigo, $pai, $nivel, $nome, $percentual] = $c;
            $ins->execute([$codigo, $nome, $nivel, $pai, $ordem, $percentual]);
        }
        $mapa = [];
        foreach ($pdo->query('SELECT id, codigo FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $mapa[$l['codigo']] = (int)$l['id'];
        }
        return $mapa;
    }

    /**
     * Abre as séries serializadas e agrupa por negócio.
     *
     * Confere o que o formato compacto esconde: conta fora da estrutura, série
     * com número de meses errado e valor não numérico. Um ';' a mais numa linha
     * desalinharia o ano inteiro daquele negócio, calado.
     */
    private static function agrupar(array $valores, array $codigos, int $qtdReal): array
    {
        $porNegocio = [];
        foreach ($valores as $chave => $par) {
            [$cod, $codConta] = array_pad(explode('|', (string)$chave, 2), 2, '');
            if (!in_array($codConta, $codigos, true)) {
                throw new RuntimeException("Carga de DRE com conta fora da estrutura: {$chave}.");
            }
            $real = self::numeros($par[0], $chave);
            $meta = self::numeros($par[1], $chave);
            if (count($real) !== $qtdReal || count($meta) !== 12) {
                throw new RuntimeException(
                    "Carga de DRE com série de tamanho errado em {$chave}: "
                    . count($real) . " realizado(s) e " . count($meta) . ' meta(s).'
                );
            }
            $porNegocio[$cod][$codConta] = ['real' => $real, 'meta' => $meta];
        }
        return $porNegocio;
    }

    private static function numeros(string $serie, string $chave): array
    {
        $out = [];
        foreach (explode(';', $serie) as $n) {
            $n = trim($n);
            if ($n === '' || !preg_match('/^-?\d+(\.\d+)?$/', $n)) {
                throw new RuntimeException("Carga de DRE com valor não numérico em {$chave}: '{$n}'.");
            }
            $out[] = (float)$n;
        }
        return $out;
    }

    /**
     * Mês declarado como fechado precisa ter RATEIO consolidado.
     *
     * Consolidado, e não por negócio: o fechamento é da cooperativa, e a Usina
     * Fotovoltaica não tem rateio em mês nenhum — julgada sozinha, ela reprovaria
     * todo mês do ano.
     */
    private static function exigirMesesFechados(array $porNegocio, array $mesesReal): void
    {
        foreach ($mesesReal as $i => $mes) {
            $soma = 0.0;
            foreach ($porNegocio as $linhas) {
                $soma += $linhas[self::CONTA_RATEIOS]['real'][$i] ?? 0.0;
            }
            if (abs($soma) < 0.005) {
                throw new RuntimeException(
                    "Carga de DRE: o mês {$mes} está declarado como fechado, mas o rateio "
                    . 'consolidado dele é zero. Rateio é a última etapa do fechamento — '
                    . 'mês sem rateio é mês aberto, e o realizado dele muda sozinho até fechar.'
                );
            }
        }
    }
}

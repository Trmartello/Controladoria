<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga do quadro de colaboradores por negócio e mês (`negocio_pessoas_mes`) e,
 * quando o arquivo traz `filiais`, por filial no último mês (`filial_pessoas`,
 * desde 2026-11-23: é o quadro que dá o mínimo da 8.5 de cada filial).
 *
 * Como a da DRE, esta carga SOBRESCREVE: é número de fora, não texto que
 * alguém edita — a de dezembro reescreve o que mudou em vez de empilhar uma
 * segunda verdade — e confere TUDO antes de escrever qualquer coisa: carga
 * recusada no meio deixaria metade dos negócios no mês novo e metade no velho,
 * e o "último mês" de cada um discordaria do vizinho.
 */
class CargaPessoas
{
    /**
     * @param array $dados o `require` de um `database/pessoas_*.php`
     * @return array{negocios:int, valores:int, ignorados:array, filiais:int, filiais_ignoradas:array}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $meses = array_map('intval', $dados['meses'] ?? []);
        if ($ano < 2000 || !$meses) {
            throw new RuntimeException('Carga de pessoas sem ano ou sem meses.');
        }
        foreach ($meses as $m) {
            if ($m < 1 || $m > 12) {
                throw new RuntimeException("Carga de pessoas com mês inválido: {$m}.");
            }
        }
        $valores = $dados['valores'] ?? [];
        if (!$valores) {
            throw new RuntimeException('Carga de pessoas sem valores.');
        }
        foreach ($valores as $cod => $serie) {
            if (count($serie) !== count($meses)) {
                throw new RuntimeException(
                    "Carga de pessoas com série de tamanho errado no negócio {$cod}: "
                    . count($serie) . ' valor(es) para ' . count($meses) . ' mês(es).'
                );
            }
            foreach ($serie as $v) {
                if (!is_int($v) || $v < 0) {
                    throw new RuntimeException("Carga de pessoas com valor inválido no negócio {$cod}: " . json_encode($v) . '.');
                }
            }
        }

        // O quadro por filial é o do mês `filiais_mes` e precisa FECHAR com o do
        // negócio nesse mês: filial a mais ou a menos daria um mínimo da 8.5 que
        // a soma das filiais não explica.
        $filiais = $dados['filiais'] ?? [];
        $mesFilial = (int)($dados['filiais_mes'] ?? 0);
        if ($filiais) {
            $posMes = array_search($mesFilial, $meses, true);
            if ($posMes === false) {
                throw new RuntimeException("Carga de pessoas com quadro de filial num mês fora da série: {$mesFilial}.");
            }
            foreach ($filiais as $cod => $porFilial) {
                foreach ($porFilial as $f => $v) {
                    if (!is_int($v) || $v < 0) {
                        throw new RuntimeException("Carga de pessoas com valor inválido na filial {$cod}/{$f}: " . json_encode($v) . '.');
                    }
                }
                $doNegocio = $valores[$cod][$posMes] ?? null;
                if ($doNegocio === null || array_sum($porFilial) !== $doNegocio) {
                    throw new RuntimeException(
                        "Carga de pessoas: as filiais do negócio {$cod} somam " . array_sum($porFilial)
                        . " no mês {$mesFilial}, e o negócio tem " . json_encode($doNegocio) . '.'
                    );
                }
            }
        }

        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $ins = $pdo->prepare(
            'INSERT INTO negocio_pessoas_mes (negocio_id, ano, mes, colaboradores)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE colaboradores = VALUES(colaboradores)'
        );
        $gravados = 0;
        $negocios = 0;
        $ignorados = [];
        foreach ($valores as $cod => $serie) {
            $busca->execute([(string)$cod]);
            $negocioId = (int)$busca->fetchColumn();
            if (!$negocioId) {
                // Negócio fora do cadastro não derruba a carga dos outros: fica
                // anotado e o migrate o imprime.
                $ignorados[] = (string)$cod;
                continue;
            }
            $negocios++;
            foreach ($meses as $i => $mes) {
                $ins->execute([$negocioId, $ano, $mes, $serie[$i]]);
                $gravados++;
            }
        }

        $nFiliais = 0;
        $filiaisIgnoradas = [];
        if ($filiais) {
            $pdo->prepare('DELETE FROM filial_pessoas WHERE ano = ?')->execute([$ano]);
            $buscaF = $pdo->prepare(
                'SELECT f.id FROM filial f JOIN negocio n ON n.id = f.negocio_id
                  WHERE n.cod_negocio = ? AND f.cod_filial = ?'
            );
            $insF = $pdo->prepare('INSERT INTO filial_pessoas (filial_id, ano, mes, colaboradores) VALUES (?, ?, ?, ?)');
            foreach ($filiais as $cod => $porFilial) {
                foreach ($porFilial as $f => $v) {
                    $buscaF->execute([(string)$cod, (string)$f]);
                    $filialId = (int)$buscaF->fetchColumn();
                    if (!$filialId) {
                        // Filial sem DRE de filial não tem linha para planejar:
                        // fica anotada (o migrate imprime) e o resto entra.
                        $filiaisIgnoradas[] = "{$cod}/{$f}";
                        continue;
                    }
                    $insF->execute([$filialId, $ano, $mesFilial, $v]);
                    $nFiliais++;
                }
            }
        }
        return ['negocios' => $negocios, 'valores' => $gravados, 'ignorados' => $ignorados,
            'filiais' => $nFiliais, 'filiais_ignoradas' => $filiaisIgnoradas];
    }
}

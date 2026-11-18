<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga do quadro de colaboradores por negócio e mês (`negocio_pessoas_mes`).
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
     * @return array{negocios:int, valores:int, ignorados:array}
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
        return ['negocios' => $negocios, 'valores' => $gravados, 'ignorados' => $ignorados];
    }
}

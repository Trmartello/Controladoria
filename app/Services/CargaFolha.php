<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga da folha por negócio (`negocio_folha`). Como as outras cargas de número
 * de fora, SOBRESCREVE o ano e confere tudo antes de escrever: uma folha velha
 * ao lado de uma nova daria PPR de meses diferentes na mesma tela.
 */
class CargaFolha
{
    public const CAMPOS = ['pessoal_ate', 'vale_ate', 'premio_ate', 'ppr_ate', 'salario_despesa', 'salario_custo'];

    /** @return array{negocios:int, ignorados:array} */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mes = (int)($dados['mes_ate'] ?? 0);
        if ($ano < 2000 || $mes < 1 || $mes > 12) {
            throw new RuntimeException('Carga de folha sem ano ou com mês inválido.');
        }
        $valores = $dados['valores'] ?? [];
        if (!$valores) {
            throw new RuntimeException('Carga de folha sem valores.');
        }
        foreach ($valores as $cod => $v) {
            if (!is_array($v) || count($v) !== count(self::CAMPOS)) {
                throw new RuntimeException("Carga de folha com linha de tamanho errado no negócio {$cod}.");
            }
            foreach ($v as $x) {
                if (!is_int($x) || $x < 0) {
                    throw new RuntimeException("Carga de folha com valor inválido no negócio {$cod}: " . json_encode($x) . '.');
                }
            }
            [$pessoal, $vale, $premio, $ppr] = $v;
            if ($vale + $premio + $ppr > $pessoal) {
                throw new RuntimeException("Carga de folha no negócio {$cod}: vale, prêmio e PPR somam mais que a despesa de pessoal.");
            }
        }
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $ids = [];
        $ignorados = [];
        foreach ($valores as $cod => $v) {
            $busca->execute([(string)$cod]);
            $id = (int)$busca->fetchColumn();
            if ($id) {
                $ids[(string)$cod] = $id;
            } else {
                $ignorados[] = (string)$cod;
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM negocio_folha WHERE ano = ?')->execute([$ano]);
            $ins = $pdo->prepare(
                'INSERT INTO negocio_folha (negocio_id, ano, mes_ate, pessoal_ate, vale_ate, premio_ate,
                    ppr_ate, salario_despesa, salario_custo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($ids as $cod => $id) {
                $ins->execute(array_merge([$id, $ano, $mes], $valores[$cod]));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['negocios' => count($ids), 'ignorados' => $ignorados];
    }
}

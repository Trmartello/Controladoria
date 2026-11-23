<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga da DEPRECIAÇÃO do ano da proposta (`negocio_depreciacao`), por negócio,
 * filial e mês — a linha 8.10 (conta reduzida 3128), travada na proposta
 * (pedido do cliente, 2026-11-23). Como as outras cargas de número de fora,
 * confere tudo antes de escrever e REESCREVE o ano inteiro.
 *
 * A filial é o par (negócio, cód. filial): o mesmo número existe em mais de um
 * negócio. O que não casa com o cadastro não some calado:
 *  - negócio fora do cadastro: fica fora, e volta em `negocios_ignorados`;
 *  - filial fora do cadastro DAQUELE negócio: entra só no total do negócio
 *    (`filial_id` 0), e volta em `filiais_so_no_negocio`.
 */
class CargaDepreciacao
{
    /**
     * @return array{negocios:int, filiais:int, negocios_ignorados:array<string,float>, filiais_so_no_negocio:array<string,float>}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        if ($ano < 2000) {
            throw new RuntimeException('Carga de depreciação sem ano.');
        }
        $valores = $dados['valores'] ?? [];
        if (!$valores || !is_array($valores)) {
            throw new RuntimeException('Carga de depreciação sem valores.');
        }
        $soma = 0.0;
        foreach ($valores as $cod => $filiais) {
            if (!is_array($filiais) || !$filiais) {
                throw new RuntimeException("Carga de depreciação sem filiais no negócio {$cod}.");
            }
            foreach ($filiais as $fil => $meses) {
                if (!is_array($meses) || count($meses) !== 12 || array_keys($meses) !== range(0, 11)) {
                    throw new RuntimeException("Carga de depreciação com linha de tamanho errado (negócio {$cod}, filial {$fil}): são 12 meses.");
                }
                foreach ($meses as $x) {
                    if (!is_int($x) && !is_float($x) || $x < 0) {
                        throw new RuntimeException("Carga de depreciação com valor inválido (negócio {$cod}, filial {$fil}): " . json_encode($x) . '.');
                    }
                    $soma += $x;
                }
            }
        }
        // A conferência do arquivo: o total declarado é a soma do que está nele.
        if (!isset($dados['total']) || abs($soma - (float)$dados['total']) > 0.005) {
            throw new RuntimeException(sprintf(
                'Carga de depreciação não fecha: as linhas somam %.2f e o total declarado é %s.',
                $soma, json_encode($dados['total'] ?? null)
            ));
        }

        $negocios = [];
        foreach ($pdo->query('SELECT id, cod_negocio FROM negocio') as $n) {
            $negocios[(string)$n['cod_negocio']] = (int)$n['id'];
        }
        $filiais = [];
        foreach ($pdo->query('SELECT id, negocio_id, cod_filial FROM filial') as $f) {
            $filiais[(int)$f['negocio_id']][(string)$f['cod_filial']] = (int)$f['id'];
        }

        $linhas = [];   // [negocio_id][filial_id][mes] => valor (positivo)
        $ignorados = [];
        $soNoNegocio = [];
        foreach ($valores as $cod => $porFilial) {
            $id = $negocios[(string)$cod] ?? null;
            if (!$id) {
                $ignorados[(string)$cod] = array_sum(array_map('array_sum', $porFilial));
                continue;
            }
            foreach ($porFilial as $fil => $meses) {
                $fid = $filiais[$id][(string)$fil] ?? null;
                if ($fid === null) {
                    $soNoNegocio["{$cod}/{$fil}"] = array_sum($meses);
                }
                foreach ($meses as $m => $v) {
                    $linhas[$id][0][$m + 1] = ($linhas[$id][0][$m + 1] ?? 0.0) + $v;
                    if ($fid !== null) {
                        $linhas[$id][$fid][$m + 1] = ($linhas[$id][$fid][$m + 1] ?? 0.0) + $v;
                    }
                }
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM negocio_depreciacao WHERE ano = ?')->execute([$ano]);
            $ins = $pdo->prepare('INSERT INTO negocio_depreciacao (negocio_id, filial_id, ano, mes, valor) VALUES (?, ?, ?, ?, ?)');
            $nFiliais = 0;
            foreach ($linhas as $id => $porFilial) {
                foreach ($porFilial as $fid => $meses) {
                    $nFiliais += $fid ? 1 : 0;
                    for ($m = 1; $m <= 12; $m++) {
                        // Sinal da DRE: depreciação é despesa.
                        $ins->execute([$id, $fid, $ano, $m, -round($meses[$m] ?? 0.0, 4)]);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['negocios' => count($linhas), 'filiais' => $nFiliais,
            'negocios_ignorados' => $ignorados, 'filiais_so_no_negocio' => $soNoNegocio];
    }
}

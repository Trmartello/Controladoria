<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga do desafio do ano por negócio (`desafio_ano` + `negocio_desafio`).
 *
 * SOBRESCREVE o ano inteiro: o desafio é uma decisão só, e uma carga nova dele
 * (outra margem, outro cenário da Pecuária) substitui a anterior em todos os
 * negócios — sobrar a linha de um negócio que saiu da carga somaria no total
 * um desafio que ninguém decidiu. Confere tudo antes de escrever, e escreve
 * numa transação.
 */
class CargaDesafio
{
    private const CAMPOS = ['receita_bruta', 'receita_liquida', 'resultado'];

    /**
     * @param array $dados o `require` de um `database/desafio_*.php`
     * @return array{negocios:int, ignorados:array}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        if ($ano < 2000) {
            throw new RuntimeException('Carga de desafio sem ano.');
        }
        $meta = $dados['meta_margem'] ?? null;
        if (!is_int($meta) && !is_float($meta) || $meta <= 0 || $meta >= 100) {
            throw new RuntimeException('Carga de desafio com meta de margem inválida: ' . json_encode($meta) . '.');
        }
        foreach (['fonte', 'metodo', 'decidido_em'] as $k) {
            if (trim((string)($dados[$k] ?? '')) === '') {
                throw new RuntimeException("Carga de desafio sem {$k}.");
            }
        }
        // Desafio CALCULADO (desde 2026-11-23): a carga grava só o cabeçalho (meta,
        // método, fonte) e apaga os valores carregados do ano — quem calcula é a
        // Projeção (`ProjecaoController::montar`, `DesafioDrivers`).
        $calculado = !empty($dados['calculado']);
        $valores = $calculado ? [] : ($dados['valores'] ?? []);
        if (!$valores && !$calculado) {
            throw new RuntimeException('Carga de desafio sem valores.');
        }
        if ($calculado && !empty($dados['valores'])) {
            throw new RuntimeException('Carga de desafio calculado com valores: ou um, ou outro.');
        }
        foreach ($valores as $cod => $v) {
            foreach (self::CAMPOS as $c) {
                if (!isset($v[$c]) || !is_int($v[$c])) {
                    throw new RuntimeException("Carga de desafio com {$c} inválido no negócio {$cod}: " . json_encode($v[$c] ?? null) . '.');
                }
            }
            if ($v['receita_bruta'] < 0 || $v['receita_liquida'] < 0) {
                throw new RuntimeException("Carga de desafio com receita negativa no negócio {$cod}.");
            }
            if ($v['receita_liquida'] > $v['receita_bruta']) {
                throw new RuntimeException("Carga de desafio com receita líquida maior que a bruta no negócio {$cod}.");
            }
            if (trim((string)($v['nota'] ?? '')) === '') {
                throw new RuntimeException("Carga de desafio sem nota no negócio {$cod}: o desafio diz de onde saiu.");
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
            $pdo->prepare(
                'INSERT INTO desafio_ano (ano, meta_margem, metodo, fonte, decidido_em) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE meta_margem = VALUES(meta_margem), metodo = VALUES(metodo),
                   fonte = VALUES(fonte), decidido_em = VALUES(decidido_em)'
            )->execute([$ano, $meta, $dados['metodo'], $dados['fonte'], $dados['decidido_em']]);
            $pdo->prepare('DELETE FROM negocio_desafio WHERE ano = ?')->execute([$ano]);
            $ins = $pdo->prepare(
                'INSERT INTO negocio_desafio (negocio_id, ano, receita_bruta, receita_liquida, resultado, nota)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($ids as $cod => $id) {
                $v = $valores[$cod];
                $ins->execute([$id, $ano, $v['receita_bruta'], $v['receita_liquida'], $v['resultado'], $v['nota']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['negocios' => count($ids), 'ignorados' => $ignorados];
    }
}

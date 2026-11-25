<?php

namespace App\Services;

use App\Core\Database;

/**
 * O DESAFIO dos administradores, CONTA A CONTA (pedido do cliente, 2026-11-25:
 * "retire a coluna de a distribuir e adicione uma coluna de desafio, onde apenas
 * os administradores do sistema poderão editar, e só estará visível para eles
 * […] esse desafio irá se resumir na tela" do cartão do desafio).
 *
 * O desafio continua sendo a PROPOSTA PADRÃO do negócio (a projeção com as
 * premissas e a diretriz, custos e despesas acompanhando a receita, rateios
 * travados); o que o ADMIN lança na coluna Desafio entra no MESMO motor da
 * proposta (`Projecao::aplicar`), como lançamento — valor ou % sobre a projeção.
 * Subtotais, resultados e margens saem dali, e o cartão só resume.
 *
 * Até 25/11 o ajuste era agregado, nas cinco linhas de valor do cartão
 * (`negocio_desafio_ajuste`); aquela tabela ficou no banco e não é mais lida.
 */
final class DesafioAjuste
{
    /**
     * Os lançamentos do desafio de um negócio no ano, no formato de
     * `ProjecaoController::lancados` (código da conta => linha).
     *
     * @return array<string,array{codigo:string, entrada:string, valor:?string, percentual:?string}>
     */
    public static function lancados(int $negocioId, int $ano): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT c.codigo, v.entrada, v.valor, v.percentual
               FROM negocio_desafio_valor v JOIN dre_conta c ON c.id = v.conta_id
              WHERE v.negocio_id = ? AND v.ano = ?',
            [$negocioId, $ano]
        ) as $l) {
            $out[$l['codigo']] = $l;
        }
        return $out;
    }

    /** Grava (ou, com null, apaga) o desafio de uma conta. */
    public static function gravar(int $negocioId, int $ano, int $contaId, string $entrada, ?float $valor, int $usuarioId): void
    {
        if ($valor === null) {
            Database::executar('DELETE FROM negocio_desafio_valor WHERE negocio_id = ? AND ano = ? AND conta_id = ?',
                [$negocioId, $ano, $contaId]);
            return;
        }
        $pct = $entrada === 'PERCENTUAL';
        Database::executar(
            'INSERT INTO negocio_desafio_valor (negocio_id, ano, conta_id, entrada, valor, percentual, ajustado_por, ajustado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE entrada = VALUES(entrada), valor = VALUES(valor), percentual = VALUES(percentual),
                                     ajustado_por = VALUES(ajustado_por), ajustado_em = NOW()',
            [$negocioId, $ano, $contaId, $pct ? 'PERCENTUAL' : 'VALOR', $pct ? null : round($valor, 2), $pct ? $valor : null, $usuarioId]
        );
    }
}

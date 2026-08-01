<?php

namespace App\Services;

/**
 * Repetição das ações do plano (semanal ou mensal).
 *
 * Concluir uma ocorrência não encerra a ação: ela reabre na próxima data
 * prevista. A regra é compartilhada pelo cadastro da ação e pelo diário de
 * bordo — os dois caminhos que marcam uma ação como concluída.
 */
class Recorrencia
{
    public const TIPOS = ['NENHUMA', 'SEMANAL', 'MENSAL'];

    /** Próxima data depois de $base, seguindo o dia da semana/mês escolhido. */
    public static function proxima(string $base, string $recorrencia, int $dia): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $base);
        if (!$d) {
            return null;
        }
        if ($recorrencia === 'SEMANAL') {
            $alvo = max(1, min(7, $dia));
            $atual = (int)$d->format('N');
            $somar = ($alvo - $atual + 7) % 7;
            return $d->modify('+' . ($somar === 0 ? 7 : $somar) . ' days')->format('Y-m-d');
        }
        if ($recorrencia === 'MENSAL') {
            $alvo = max(1, min(31, $dia));
            // O dia escolhido ainda pode estar à frente no próprio mês da base;
            // só quando já passou é que a ocorrência cai no mês seguinte
            $noMes = $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($alvo, (int)$d->format('t')));
            if ($noMes > $d) {
                return $noMes->format('Y-m-d');
            }
            $mes = $d->modify('first day of next month');
            $ultimo = (int)$mes->format('t');
            return $mes->setDate((int)$mes->format('Y'), (int)$mes->format('n'), min($alvo, $ultimo))
                ->format('Y-m-d');
        }
        return null;
    }

    /**
     * Calcula a próxima janela (início e fim) de uma ação recorrente concluída.
     * Devolve null quando não há recorrência ou o limite (`recorrencia_ate`)
     * foi atingido — nesses casos a ação encerra de vez.
     *
     * A ação pode estar atrasada há vários ciclos; por isso avança ocorrência
     * a ocorrência até passar de hoje, senão reabriria já vencida.
     */
    public static function reagendar(
        ?string $dataInicio,
        string $recorrencia,
        ?int $dia,
        ?string $ate,
        ?string $fim
    ): ?array {
        if ($recorrencia === 'NENHUMA' || !$dia) {
            return null;
        }
        $hoje = date('Y-m-d');
        $base = $fim ?: $hoje;
        // As ocorrências formam uma grade fixa (dia da semana ou dia do mês),
        // então a primeira que ainda não passou sai de uma conta só: basta
        // ancorar na data mais recente entre o fim da ocorrência atual e hoje.
        // Uma ação parada há anos reabre já na próxima data válida.
        $proxima = self::proxima(max($base, $hoje), $recorrencia, $dia);
        if ($proxima === null || ($ate !== null && $proxima > $ate)) {
            return null;
        }
        // Mantém a mesma janela entre início e fim na próxima ocorrência
        $novoInicio = null;
        if ($dataInicio && $fim) {
            $dias = (int)((new \DateTimeImmutable($fim))->diff(new \DateTimeImmutable($dataInicio))->days);
            $novoInicio = (new \DateTimeImmutable($proxima))->modify("-{$dias} days")->format('Y-m-d');
        }
        return ['data_inicio' => $novoInicio, 'data_fim' => $proxima];
    }
}

<?php

namespace App\Services;

/**
 * Repetição das ações do plano (semanal ou mensal).
 *
 * Concluir uma ocorrência não encerra a ação: ela reabre na próxima data
 * prevista. A regra é compartilhada pelo cadastro da ação e pelo diário de
 * bordo — os dois caminhos que marcam uma ação como concluída.
 *
 * A ação que se repete NÃO tem período digitado: quem diz quando ela vence é a
 * grade (um ou mais dias da semana, ou um ou mais dias do mês), e as datas saem
 * daqui.
 * Gravar `data_fim` mesmo assim é o que mantém a recorrente dentro do atraso
 * automático, dos avisos por e-mail e do prazo consolidado do projeto — os três
 * leem a coluna, não a regra.
 */
class Recorrencia
{
    public const TIPOS = ['NENHUMA', 'SEMANAL', 'MENSAL'];

    /**
     * Os dias da grade de uma ação, em ordem. Os dois tipos aceitam vários dias
     * (CSV em `recorrencia_dias`): "toda segunda e quinta" e "todo dia 5 e 20"
     * são uma rotina só, e com um dia por ação a mesma rotina virava duas.
     * `recorrencia_dia` continua gravado com o primeiro dia e é o fallback das
     * ações anteriores à coluna.
     *
     * @param array $acao linha de `desdobramento`
     * @return int[]
     */
    public static function dias(array $acao): array
    {
        $csv = trim((string)($acao['recorrencia_dias'] ?? ''));
        $recorrencia = (string)($acao['recorrencia'] ?? 'NENHUMA');
        if ($csv !== '') {
            return self::normalizarDias(array_map('intval', explode(',', $csv)), $recorrencia);
        }
        return ($acao['recorrencia_dia'] ?? null) !== null
            ? self::normalizarDias([(int)$acao['recorrencia_dia']], $recorrencia)
            : [];
    }

    /**
     * Limpa a lista de dias: dentro da faixa do tipo, sem repetidos e em ordem.
     * Ordenar importa — `proxima()` compara as datas candidatas e a lista ordenada
     * mantém previsível o que é gravado no CSV.
     *
     * @param int[] $dias
     * @return int[]
     */
    public static function normalizarDias(array $dias, string $recorrencia): array
    {
        $limite = $recorrencia === 'SEMANAL' ? 7 : 31;
        $limpos = [];
        foreach ($dias as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= $limite) {
                $limpos[$d] = $d;
            }
        }
        ksort($limpos);
        return array_values($limpos);
    }

    /**
     * Primeira ocorrência DEPOIS de $base, entre os dias da grade.
     *
     * @param int[] $dias
     */
    public static function proxima(string $base, string $recorrencia, array $dias): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $base);
        $dias = self::normalizarDias($dias, $recorrencia);
        if (!$d || !$dias) {
            return null;
        }
        $candidatas = [];
        foreach ($dias as $dia) {
            if ($recorrencia === 'SEMANAL') {
                $somar = ($dia - (int)$d->format('N') + 7) % 7;
                $candidatas[] = $d->modify('+' . ($somar === 0 ? 7 : $somar) . ' days')->format('Y-m-d');
                continue;
            }
            if ($recorrencia === 'MENSAL') {
                // O dia escolhido ainda pode estar à frente no próprio mês da
                // base; só quando já passou é que a ocorrência cai no mês
                // seguinte. Em mês curto, encosta no último dia.
                $noMes = $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($dia, (int)$d->format('t')));
                if ($noMes > $d) {
                    $candidatas[] = $noMes->format('Y-m-d');
                    continue;
                }
                $mes = $d->modify('first day of next month');
                $candidatas[] = $mes
                    ->setDate((int)$mes->format('Y'), (int)$mes->format('n'), min($dia, (int)$mes->format('t')))
                    ->format('Y-m-d');
            }
        }
        if (!$candidatas) {
            return null;
        }
        sort($candidatas);
        return $candidatas[0]; // a mais próxima entre os dias marcados
    }

    /**
     * A data de vencimento de uma ação que se repete: a primeira ocorrência que
     * ainda não passou — contada a partir de ontem, para o dia de hoje valer.
     * Devolve null quando a grade já ultrapassou o limite de `recorrencia_ate`.
     *
     * @param int[] $dias
     */
    public static function primeiraOcorrencia(array $dias, string $recorrencia, ?string $ate): ?string
    {
        $proxima = self::proxima(date('Y-m-d', strtotime('-1 day')), $recorrencia, $dias);
        if ($proxima === null || ($ate !== null && $proxima > $ate)) {
            return null;
        }
        return $proxima;
    }

    /**
     * Calcula a próxima janela (início e fim) de uma ação recorrente concluída.
     * Devolve null quando não há recorrência ou o limite (`recorrencia_ate`)
     * foi atingido — nesses casos a ação encerra de vez.
     *
     * A ação pode estar atrasada há vários ciclos; por isso ancora na data mais
     * recente entre o fim da ocorrência atual e hoje, senão reabriria vencida.
     *
     * @param int[] $dias
     */
    public static function reagendar(
        ?string $dataInicio,
        string $recorrencia,
        array $dias,
        ?string $ate,
        ?string $fim
    ): ?array {
        if ($recorrencia === 'NENHUMA' || !self::normalizarDias($dias, $recorrencia)) {
            return null;
        }
        $hoje = date('Y-m-d');
        $base = $fim ?: $hoje;
        $proxima = self::proxima(max($base, $hoje), $recorrencia, $dias);
        if ($proxima === null || ($ate !== null && $proxima > $ate)) {
            return null;
        }
        // Mantém a mesma janela entre início e fim na próxima ocorrência
        $novoInicio = null;
        if ($dataInicio && $fim) {
            $janela = (int)((new \DateTimeImmutable($fim))->diff(new \DateTimeImmutable($dataInicio))->days);
            $novoInicio = (new \DateTimeImmutable($proxima))->modify("-{$janela} days")->format('Y-m-d');
        }
        return ['data_inicio' => $novoInicio, 'data_fim' => $proxima];
    }
}

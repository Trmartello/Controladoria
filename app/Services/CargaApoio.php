<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Cargas de APOIO à análise do desafio, vindas do Qlik por conta contábil:
 * tributos sobre vendas (`negocio_tributo`) e itens não recorrentes
 * (`negocio_nao_recorrente`). Como as outras cargas de número de fora,
 * sobrescrevem o ano inteiro numa transação e conferem tudo antes de escrever.
 */
class CargaApoio
{
    /** @return array{negocios:int, ignorados:array} */
    public static function tributos(PDO $pdo, array $dados): array
    {
        [$ano, $mes, $valores] = self::cabecalho($dados, 'tributos');
        foreach ($valores as $cod => $v) {
            if (!is_array($v) || count($v) !== 5) {
                throw new RuntimeException("Carga de tributos com linha de tamanho errado no negócio {$cod}.");
            }
            foreach ($v as $x) {
                if (!is_int($x) || $x < 0) {
                    throw new RuntimeException("Carga de tributos com valor inválido no negócio {$cod}: " . json_encode($x) . '.');
                }
            }
            if ($v[2] + $v[3] + $v[4] > $v[0] + $v[1]) {
                throw new RuntimeException("Carga de tributos no negócio {$cod}: os tributos passam das vendas.");
            }
        }
        return self::gravar($pdo, $valores, 'DELETE FROM negocio_tributo WHERE ano = ?', $ano,
            'INSERT INTO negocio_tributo (negocio_id, ano, mes_ate, venda_socio, venda_nao_socio, pis, cofins, icms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            static fn (int $id, array $v) => [array_merge([$id, $ano, $mes], $v)]);
    }

    /** @return array{negocios:int, ignorados:array} */
    public static function naoRecorrentes(PDO $pdo, array $dados): array
    {
        [$ano, $mes, $valores] = self::cabecalho($dados, 'itens não recorrentes');
        foreach ($valores as $cod => $contas) {
            if (!is_array($contas) || !$contas) {
                throw new RuntimeException("Carga de itens não recorrentes sem contas no negócio {$cod}.");
            }
            foreach ($contas as $conta => $x) {
                if (!isset(Oportunidades::CONTAS[(string)$conta])) {
                    throw new RuntimeException("Carga de itens não recorrentes com conta fora do catálogo no negócio {$cod}: {$conta}.");
                }
                if (!is_int($x)) {
                    throw new RuntimeException("Carga de itens não recorrentes com valor inválido no negócio {$cod}, conta {$conta}.");
                }
            }
        }
        return self::gravar($pdo, $valores, 'DELETE FROM negocio_nao_recorrente WHERE ano = ?', $ano,
            'INSERT INTO negocio_nao_recorrente (negocio_id, ano, mes_ate, conta, valor) VALUES (?, ?, ?, ?, ?)',
            static function (int $id, array $contas) use ($ano, $mes): array {
                $linhas = [];
                foreach ($contas as $conta => $x) {
                    $linhas[] = [$id, $ano, $mes, (string)$conta, $x];
                }
                return $linhas;
            });
    }

    /**
     * Os itens não recorrentes MÊS A MÊS (`database/nao_recorrentes_mensal_*.php`):
     * mesmas contas do catálogo, um valor por mês de jan a `mes_ate`.
     */
    public static function naoRecorrentesMensal(PDO $pdo, array $dados): array
    {
        [$ano, $mes, $valores] = self::cabecalho($dados, 'itens não recorrentes mês a mês');
        foreach ($valores as $cod => $contas) {
            foreach ($contas as $conta => $meses) {
                if (!isset(Oportunidades::CONTAS[(string)$conta])) {
                    throw new RuntimeException("Carga mensal de não recorrentes com conta fora do catálogo no negócio {$cod}: {$conta}.");
                }
                if (!is_array($meses) || count($meses) !== $mes) {
                    throw new RuntimeException("Carga mensal de não recorrentes sem os {$mes} meses no negócio {$cod}, conta {$conta}.");
                }
            }
        }
        return self::gravar($pdo, $valores, 'DELETE FROM negocio_nao_recorrente_mes WHERE ano = ?', $ano,
            'INSERT INTO negocio_nao_recorrente_mes (negocio_id, ano, conta, mes, valor) VALUES (?, ?, ?, ?, ?)',
            static function (int $id, array $contas) use ($ano): array {
                $linhas = [];
                foreach ($contas as $conta => $meses) {
                    foreach (array_values($meses) as $i => $v) {
                        if ((int)$v !== 0) {
                            $linhas[] = [$id, $ano, (string)$conta, $i + 1, (int)$v];
                        }
                    }
                }
                return $linhas;
            });
    }

    /**
     * A DRE da filial mês a mês nas linhas dos eventos pontuais
     * (`database/eventos_filiais_mensal_*.php`). Recusa filial ou conta que não
     * existam e mês fora de jan..mes_ate; regrava o ano inteiro.
     *
     * @return array{negocios:int, linhas:int, ignorados:array}
     */
    public static function filiaisMensal(PDO $pdo, array $dados): array
    {
        [$ano, $mes, $valores] = self::cabecalho($dados, 'DRE mensal das filiais');
        $contas = [];
        foreach ($pdo->query('SELECT id, codigo FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $contas[$c['codigo']] = (int)$c['id'];
        }
        $ins = $pdo->prepare('INSERT INTO dre_valor_filial_mes (filial_id, conta_id, ano, mes, valor) VALUES (?, ?, ?, ?, ?)');
        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM dre_valor_filial_mes WHERE ano = ?')->execute([$ano]);
            $negocios = 0;
            $linhas = 0;
            $ignorados = [];
            foreach ($valores as $cod => $filas) {
                $neg = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
                $neg->execute([(string)$cod]);
                $negId = $neg->fetchColumn();
                if (!$negId) {
                    $ignorados[] = (string)$cod;
                    continue;
                }
                $negocios++;
                $fil = $pdo->prepare('SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = ?');
                foreach ($filas as [$codFilial, $conta, $meses]) {
                    if (!isset($contas[$conta])) {
                        throw new RuntimeException("DRE mensal das filiais com conta desconhecida: {$conta}.");
                    }
                    if (count($meses) !== $mes) {
                        throw new RuntimeException("DRE mensal das filiais: {$cod}/{$codFilial}/{$conta} sem os {$mes} meses.");
                    }
                    $fil->execute([(int)$negId, (string)$codFilial]);
                    $filialId = $fil->fetchColumn();
                    if (!$filialId) {
                        throw new RuntimeException("DRE mensal das filiais: filial {$codFilial} não existe no negócio {$cod}.");
                    }
                    foreach (array_values($meses) as $i => $v) {
                        $ins->execute([(int)$filialId, $contas[$conta], $ano, $i + 1, (float)$v]);
                    }
                    $linhas++;
                }
            }
            if ($dono) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($dono) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['negocios' => $negocios, 'linhas' => $linhas, 'ignorados' => $ignorados];
    }

    /**
     * Estoque como oportunidade (`database/estoque_oportunidade_*.php`): perdas e
     * acerto de estoque mês a mês, do negócio e das filiais, e o estoque parado
     * por filial. Recusa conta fora de `Oportunidades::ESTOQUE_CONTAS`, série sem
     * os meses do corte e filial de perda fora do cadastro; regrava o ano (e a
     * data-base dos parados) inteiro numa transação.
     *
     * @return array{negocios:int, linhas:int, parados:int}
     */
    public static function estoque(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mes = (int)($dados['mes_ate'] ?? 0);
        $base = (string)($dados['parados']['data_base'] ?? '');
        if ($ano < 2000 || $mes < 1 || $mes > 12 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) {
            throw new RuntimeException('Carga de estoque sem ano, com mês inválido ou sem a data-base dos parados.');
        }
        $negocio = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $idDe = static function (string $cod) use ($negocio): int {
            $negocio->execute([$cod]);
            $id = (int)$negocio->fetchColumn();
            if (!$id) {
                throw new RuntimeException("Carga de estoque com negócio fora do cadastro: {$cod}.");
            }
            return $id;
        };
        $filial = $pdo->prepare('SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = ?');
        $filialDe = static function (int $negId, string $cod, string $neg) use ($filial): int {
            $filial->execute([$negId, $cod]);
            $id = (int)$filial->fetchColumn();
            if (!$id) {
                throw new RuntimeException("Carga de estoque: filial {$cod} não existe no negócio {$neg}.");
            }
            return $id;
        };
        $serie = static function (string $neg, string $conta, $meses) use ($mes): array {
            if (!isset(Oportunidades::ESTOQUE_CONTAS[$conta])) {
                throw new RuntimeException("Carga de estoque com conta fora do catálogo no negócio {$neg}: {$conta}.");
            }
            if (!is_array($meses) || count($meses) !== $mes) {
                throw new RuntimeException("Carga de estoque sem os {$mes} meses no negócio {$neg}, conta {$conta}.");
            }
            return array_values($meses);
        };

        // Confere tudo antes de escrever: uma carga pela metade é pior que nenhuma.
        $series = [];
        $negocios = [];
        foreach ($dados['perdas'] ?? [] as $cod => $contas) {
            $id = $idDe((string)$cod);
            $negocios[$id] = true;
            foreach ($contas as $conta => $meses) {
                $series[] = [$id, 0, (string)$conta, $serie((string)$cod, (string)$conta, $meses)];
            }
        }
        foreach ($dados['perdas_filiais'] ?? [] as $cod => $filas) {
            $id = $idDe((string)$cod);
            foreach ($filas as [$codFilial, $conta, $meses]) {
                $series[] = [$id, $filialDe($id, (string)$codFilial, (string)$cod), (string)$conta,
                    $serie((string)$cod, (string)$conta, $meses)];
            }
        }
        $parados = [];
        foreach ($dados['parados'] as $cod => $p) {
            if ($cod === 'data_base') {
                continue;
            }
            $id = $idDe((string)$cod);
            $dias = (int)($p['dias'] ?? 0);
            if ($dias < 1) {
                throw new RuntimeException("Carga de estoque parado sem o mínimo de dias no negócio {$cod}.");
            }
            foreach ($p['filiais'] as [$codFilial, $nome, $valor, $itens]) {
                $parados[] = [$id, $codFilial === null ? null : $filialDe($id, (string)$codFilial, (string)$cod),
                    $base, $dias, (string)$nome, (float)$valor, (int)$itens];
            }
        }
        if (!$series && !$parados) {
            throw new RuntimeException('Carga de estoque sem valores.');
        }

        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM estoque_perda_mes WHERE ano = ?')->execute([$ano]);
            $pdo->prepare('DELETE FROM estoque_parado WHERE data_base = ?')->execute([$base]);
            $ins = $pdo->prepare('INSERT INTO estoque_perda_mes (negocio_id, filial_id, ano, conta, mes, valor) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($series as [$id, $filialId, $conta, $meses]) {
                foreach ($meses as $i => $v) {
                    if ((float)$v != 0.0) {
                        $ins->execute([$id, $filialId, $ano, $conta, $i + 1, (float)$v]);
                    }
                }
            }
            $insP = $pdo->prepare('INSERT INTO estoque_parado (negocio_id, filial_id, data_base, dias_minimo, nome_origem, valor, itens) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($parados as $p) {
                $insP->execute($p);
                $negocios[$p[0]] = true;
            }
            if ($dono) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($dono) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['negocios' => count($negocios), 'linhas' => count($series), 'parados' => count($parados)];
    }

    private static function cabecalho(array $dados, string $nome): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mes = (int)($dados['mes_ate'] ?? 0);
        if ($ano < 2000 || $mes < 1 || $mes > 12) {
            throw new RuntimeException("Carga de {$nome} sem ano ou com mês inválido.");
        }
        $valores = $dados['valores'] ?? [];
        if (!$valores) {
            throw new RuntimeException("Carga de {$nome} sem valores.");
        }
        return [$ano, $mes, $valores];
    }

    private static function gravar(PDO $pdo, array $valores, string $apagar, int $ano, string $inserir, callable $linhas): array
    {
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
            $pdo->prepare($apagar)->execute([$ano]);
            $ins = $pdo->prepare($inserir);
            foreach ($ids as $cod => $id) {
                foreach ($linhas($id, $valores[$cod]) as $l) {
                    $ins->execute($l);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['negocios' => count($ids), 'ignorados' => $ignorados];
    }
}

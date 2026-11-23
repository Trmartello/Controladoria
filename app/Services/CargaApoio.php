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

    /**
     * Lançamentos do quadro de oportunidades e juros recebidos
     * (`database/lancamentos_*.php`). A linha da DRE de cada lançamento sai do
     * mapa reduzida → linha da estrutura da DRE Gerencial que vem na carga (e,
     * para as contas de itens, de `Oportunidades::CONTAS`). Negócio fora do
     * cadastro (o 20, administrativo) é contado e ignorado. Regrava o ano.
     *
     * @return array{lancamentos:int, juros:int, ignorados:int}
     */
    public static function lancamentos(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mes = (int)($dados['mes_ate'] ?? 0);
        if ($ano < 2000 || $mes < 1 || $mes > 12 || empty($dados['lancamentos'])) {
            throw new RuntimeException('Carga de lançamentos sem ano, com mês inválido ou sem lançamentos.');
        }
        $linhaDe = [];
        foreach ($dados['linhas'] ?? [] as $linha => $reduzidas) {
            foreach ($reduzidas as $r) {
                $linhaDe[(string)$r] = (string)$linha;
            }
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, cod_negocio FROM negocio')->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $ids[(string)$n['cod_negocio']] = (int)$n['id'];
        }
        $linhas = [];
        $ignorados = 0;
        foreach ($dados['lancamentos'] as $i => $l) {
            if (count($l) !== 11) {
                throw new RuntimeException("Carga de lançamentos: a linha {$i} não tem as 11 colunas.");
            }
            [$neg, $fil, $filial, $data, $m, $red, $conta, $nome, $hist, $compl, $valor] = $l;
            if (!isset($ids[(string)$neg])) {
                $ignorados++;
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data) || (int)$m < 1 || (int)$m > $mes
                || (int)substr((string)$data, 0, 4) !== $ano || (int)substr((string)$data, 5, 2) !== (int)$m) {
                throw new RuntimeException("Carga de lançamentos: data ou mês inválido na linha {$i} ({$data}, mês {$m}).");
            }
            $linhas[] = [$ids[(string)$neg], $ano, (int)$m, $data, (string)$fil, mb_substr((string)$filial, 0, 120),
                (string)$conta, (string)$red, mb_substr((string)$nome, 0, 120),
                $linhaDe[(string)$red] ?? (Oportunidades::CONTAS[(string)$conta][2] ?? null),
                mb_substr((string)$hist, 0, 120), mb_substr((string)$compl, 0, 255), (float)$valor];
        }
        $juros = [];
        foreach ($dados['juros_totais'] ?? [] as $j) {
            [$neg, $fil, $filial, $red, $total, $n] = $j;
            if (isset($ids[(string)$neg])) {
                $juros[] = [$ids[(string)$neg], $ano, $mes, (string)$fil, mb_substr((string)$filial, 0, 120), (string)$red, (float)$total, (int)$n];
            }
        }
        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM dre_lancamento WHERE ano = ?')->execute([$ano]);
            $pdo->prepare('DELETE FROM juros_recebido WHERE ano = ?')->execute([$ano]);
            $ins = $pdo->prepare('INSERT INTO dre_lancamento (negocio_id, ano, mes, data, cod_filial, filial, conta, reduzida,
                conta_nome, linha_dre, historico, complemento, valor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($linhas as $l) {
                $ins->execute($l);
            }
            $insJ = $pdo->prepare('INSERT INTO juros_recebido (negocio_id, ano, mes_ate, cod_filial, filial, reduzida, total, lancamentos)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($juros as $j) {
                $insJ->execute($j);
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
        return ['lancamentos' => count($linhas), 'juros' => count($juros), 'ignorados' => $ignorados];
    }

    /**
     * Um CENÁRIO de faturamento de planilha do negócio vira a proposta da Receita
     * de Vendas (`database/cenario_*.php`): lança o valor no formato novo (sem a
     * CBS) na linha do negócio, sobe a versão da projeção (quem estiver com a tela
     * aberta recebe 409 em vez de sobrescrever calado) e guarda o cenário.
     *
     * @return array{negocio:string, valor:float}
     */
    public static function cenario(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $valor = (float)($dados['receita_vendas'] ?? 0);
        $fat = (float)($dados['faturamento_antigo'] ?? 0);
        $t = (float)($dados['taxa_pis_cofins'] ?? -1);
        if ($ano < 2000 || $valor <= 0 || $t < 0 || $t >= 1 || abs($fat * (1 - $t) - $valor) > 1) {
            throw new RuntimeException('Cenário sem ano, sem valor, ou com receita ≠ faturamento × (1 − taxa).');
        }
        $st = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $st->execute([(string)$dados['negocio']]);
        $negId = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT id FROM dre_conta WHERE codigo = ?');
        $st->execute([(string)$dados['conta']]);
        $contaId = (int)$st->fetchColumn();
        if (!$negId || !$contaId) {
            throw new RuntimeException('Cenário para negócio ou conta fora do cadastro.');
        }
        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare("INSERT INTO projecao (negocio_id, ano, modo, versao) VALUES (?, ?, 'NEGOCIO', 1)
                ON DUPLICATE KEY UPDATE id = id")->execute([$negId, $ano]);
            $st = $pdo->prepare('SELECT id FROM projecao WHERE negocio_id = ? AND ano = ?');
            $st->execute([$negId, $ano]);
            $projId = (int)$st->fetchColumn();
            $pdo->prepare("INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor, percentual)
                VALUES (?, 0, ?, 'VALOR', ?, NULL)
                ON DUPLICATE KEY UPDATE entrada = 'VALOR', valor = VALUES(valor), percentual = NULL")
                ->execute([$projId, $contaId, round($valor, 2)]);
            $pdo->prepare('UPDATE projecao SET versao = versao + 1, atualizado_em = NOW() WHERE id = ?')->execute([$projId]);
            $pdo->prepare('REPLACE INTO projecao_cenario (negocio_id, ano, conta, cenario, fonte, taxa_pis_cofins,
                faturamento_antigo, receita_vendas, itens, aplicado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$negId, $ano, (string)$dados['conta'], (string)$dados['cenario'], (string)$dados['fonte'],
                    $t, $fat, $valor, json_encode($dados['itens'], JSON_UNESCAPED_UNICODE)]);
            if ($dono) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($dono) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['negocio' => (string)$dados['negocio'], 'valor' => $valor];
    }

    /**
     * Itens não recorrentes MÊS A MÊS num período que atravessa anos
     * (`database/nao_recorrentes_mensal_AAAA_MM_AAAA_MM.php`, pedido do cliente de
     * 2026-11-23: as oportunidades olham os 12 meses de nov do ano anterior a out do
     * atual, e os 12 antes). `meses` diz quais ('2024-11', …); regrava SÓ esses meses,
     * de todos os negócios — os demais (a carga de jan–out do ano corrente) ficam.
     *
     * @return array{negocios:int, ignorados:array}
     */
    public static function naoRecorrentesPeriodo(PDO $pdo, array $dados): array
    {
        $meses = [];
        foreach ($dados['meses'] ?? [] as $m) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$m, $x) || (int)$x[2] < 1 || (int)$x[2] > 12) {
                throw new RuntimeException("Carga de não recorrentes com mês inválido: {$m}.");
            }
            $meses[] = [(int)$x[1], (int)$x[2]];
        }
        $valores = $dados['valores'] ?? [];
        if (!$meses || !$valores) {
            throw new RuntimeException('Carga de não recorrentes por período sem meses ou sem valores.');
        }
        foreach ($valores as $cod => $contas) {
            foreach ($contas as $conta => $serie) {
                if (!isset(Oportunidades::CONTAS[(string)$conta])) {
                    throw new RuntimeException("Carga de não recorrentes com conta fora do catálogo no negócio {$cod}: {$conta}.");
                }
                if (!is_array($serie) || count($serie) !== count($meses)) {
                    throw new RuntimeException("Carga de não recorrentes sem os " . count($meses) . " meses no negócio {$cod}, conta {$conta}.");
                }
                foreach ($serie as $v) {
                    if (!is_int($v)) {
                        throw new RuntimeException("Carga de não recorrentes com valor inválido no negócio {$cod}, conta {$conta}.");
                    }
                }
            }
        }
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $ids = [];
        $ignorados = [];
        foreach (array_keys($valores) as $cod) {
            $busca->execute([(string)$cod]);
            $id = (int)$busca->fetchColumn();
            if ($id) {
                $ids[(string)$cod] = $id;
            } else {
                $ignorados[] = (string)$cod;
            }
        }
        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $apaga = $pdo->prepare('DELETE FROM negocio_nao_recorrente_mes WHERE ano = ? AND mes = ?');
            foreach ($meses as [$a, $m]) {
                $apaga->execute([$a, $m]);
            }
            $ins = $pdo->prepare('INSERT INTO negocio_nao_recorrente_mes (negocio_id, ano, conta, mes, valor) VALUES (?, ?, ?, ?, ?)');
            foreach ($ids as $cod => $id) {
                foreach ($valores[$cod] as $conta => $serie) {
                    foreach (array_values($serie) as $i => $v) {
                        if ($v !== 0) {
                            $ins->execute([$id, $meses[$i][0], (string)$conta, $meses[$i][1], $v]);
                        }
                    }
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
        return ['negocios' => count($ids), 'ignorados' => $ignorados];
    }

    /**
     * A DRE de meses de OUTRO ano nas linhas dos eventos pontuais (8.x, 9.x, 11.x),
     * do negócio (`dre_valor`, REAL) e das filiais (`dre_valor_filial_mes`):
     * `database/dre_eventos_AAAA_MM_MM.php`, os meses que completam a janela de 12
     * meses das oportunidades. Só essas linhas e esses meses são regravados. Filial
     * fora do cadastro de hoje é ignorada (dita).
     *
     * @return array{negocios:int, filiais:int, linhas:int, ignorados:array, filiais_ignoradas:array}
     */
    public static function eventosPeriodo(PDO $pdo, array $dados): array
    {
        return self::periodoDaDre($pdo, $dados, '/^(8|9|11)\./', 'DRE de eventos', 'fora das 8.x, 9.x e 11.x');
    }

    /**
     * A DRE INTEIRA de meses de outro ano, do negócio (`dre_valor`, REAL) e das
     * filiais (`dre_valor_filial_mes`): `database/dre_AAAA_MM_MM.php`. São os
     * meses que fecham a BASE DE 12 MESES da Projeção (nov/ano−1 a out/ano;
     * pedido do cliente, 2026-11-23: "12 meses em tudo"). Todas as linhas da
     * DRE, menos as percentuais; os meses dados são regravados por inteiro.
     * Filial fora do cadastro de hoje é ignorada (dita) — fica só no total do
     * negócio, que vem do Qlik e não da soma das filiais.
     *
     * @return array{negocios:int, filiais:int, linhas:int, ignorados:array, filiais_ignoradas:array}
     */
    public static function drePeriodo(PDO $pdo, array $dados): array
    {
        return self::periodoDaDre($pdo, $dados, null, 'DRE do período', 'desconhecida ou percentual');
    }

    private static function periodoDaDre(PDO $pdo, array $dados, ?string $filtroLinha, string $nome, string $motivo): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $meses = array_map('intval', $dados['meses'] ?? []);
        if ($ano < 2000 || !$meses) {
            throw new RuntimeException("Carga da {$nome} sem ano ou sem meses.");
        }
        // As linhas que a carga pode tocar: as do filtro (eventos) ou todas as
        // não percentuais (a DRE inteira) — percentual é derivado na tela.
        $contas = [];
        foreach ($pdo->query('SELECT id, codigo, percentual FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $vale = $filtroLinha !== null ? (bool)preg_match($filtroLinha, (string)$c['codigo']) : !(int)$c['percentual'];
            if ($vale) {
                $contas[$c['codigo']] = (int)$c['id'];
            }
        }
        $confere = static function (array $porLinha, string $onde) use ($contas, $meses, $nome, $motivo): void {
            foreach ($porLinha as $linha => $serie) {
                if (!isset($contas[$linha])) {
                    throw new RuntimeException("Carga da {$nome} com linha {$motivo} em {$onde}: {$linha}.");
                }
                if (!is_array($serie) || count($serie) !== count($meses)) {
                    throw new RuntimeException("Carga da {$nome} sem os meses em {$onde}, linha {$linha}.");
                }
            }
        };
        foreach ($dados['negocios'] ?? [] as $cod => $l) {
            $confere($l, "negócio {$cod}");
        }
        foreach ($dados['filiais'] ?? [] as $cod => $fs) {
            foreach ($fs as $f => $l) {
                $confere($l, "filial {$cod}/{$f}");
            }
        }
        $idsLinhas = array_values($contas);
        $marcasL = implode(',', array_fill(0, count($idsLinhas), '?'));
        $marcasM = implode(',', array_fill(0, count($meses), '?'));
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $buscaF = $pdo->prepare('SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = ?');
        $negocios = 0;
        $filiais = 0;
        $linhas = 0;
        $ignorados = [];
        $filiaisIgnoradas = [];
        $dono = !$pdo->inTransaction();
        if ($dono) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare("DELETE FROM dre_valor WHERE ano = ? AND tipo = 'REAL' AND mes IN ({$marcasM}) AND conta_id IN ({$marcasL})")
                ->execute(array_merge([$ano], $meses, $idsLinhas));
            $pdo->prepare("DELETE FROM dre_valor_filial_mes WHERE ano = ? AND mes IN ({$marcasM}) AND conta_id IN ({$marcasL})")
                ->execute(array_merge([$ano], $meses, $idsLinhas));
            $insN = $pdo->prepare("INSERT INTO dre_valor (negocio_id, conta_id, ano, mes, tipo, valor) VALUES (?, ?, ?, ?, 'REAL', ?)");
            $insF = $pdo->prepare('INSERT INTO dre_valor_filial_mes (filial_id, conta_id, ano, mes, valor) VALUES (?, ?, ?, ?, ?)');
            $idNeg = [];
            foreach (array_unique(array_merge(array_keys($dados['negocios'] ?? []), array_keys($dados['filiais'] ?? []))) as $cod) {
                $busca->execute([(string)$cod]);
                $id = (int)$busca->fetchColumn();
                if ($id) {
                    $idNeg[(string)$cod] = $id;
                } else {
                    $ignorados[] = (string)$cod;
                }
            }
            foreach ($dados['negocios'] ?? [] as $cod => $porLinha) {
                if (!isset($idNeg[(string)$cod])) {
                    continue;
                }
                $negocios++;
                foreach ($porLinha as $linha => $serie) {
                    foreach (array_values($serie) as $i => $v) {
                        $insN->execute([$idNeg[(string)$cod], $contas[$linha], $ano, $meses[$i], (float)$v]);
                        $linhas++;
                    }
                }
            }
            foreach ($dados['filiais'] ?? [] as $cod => $porFilial) {
                if (!isset($idNeg[(string)$cod])) {
                    continue;
                }
                foreach ($porFilial as $f => $porLinha) {
                    $buscaF->execute([$idNeg[(string)$cod], (string)$f]);
                    $fid = (int)$buscaF->fetchColumn();
                    if (!$fid) {
                        $filiaisIgnoradas[] = "{$cod}/{$f}";
                        continue;
                    }
                    $filiais++;
                    foreach ($porLinha as $linha => $serie) {
                        foreach (array_values($serie) as $i => $v) {
                            if ((float)$v != 0.0) {
                                $insF->execute([$fid, $contas[$linha], $ano, $meses[$i], (float)$v]);
                                $linhas++;
                            }
                        }
                    }
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
        return ['negocios' => $negocios, 'filiais' => $filiais, 'linhas' => $linhas,
            'ignorados' => $ignorados, 'filiais_ignoradas' => $filiaisIgnoradas];
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

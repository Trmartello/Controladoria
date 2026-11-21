<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga do NÍVEL 3 da DRE gerencial — a conta reduzida (pedido do cliente,
 * 2026-11-21): o catálogo (`dre_reduzida`) e o realizado acumulado até o corte
 * por negócio e por filial (`dre_reduzida_valor`).
 *
 * Como a da DRE, SOBRESCREVE (é número de fora) e confere antes de escrever:
 *  - todo código usado está no catálogo, e todo negócio e filial existem;
 *  - a soma das filiais é o negócio, conta reduzida a conta reduzida (R$ 50 de
 *    tolerância: os valores vêm arredondados ao real). Filial faltando passaria
 *    calada — a tela mostraria cada filial certa e o negócio diferente delas.
 *
 * E CORRIGE o rateio corporativo das filiais. Na carga de 18/11/2026 a DRE da
 * filial veio do app antigo, sem a divisão do rateio corporativo em negócio
 * (14.3) e administrativo (16.1), e `CargaDre::migrarFiliaisParaEstruturaNova`
 * a dividiu na proporção do negócio — aproximação declarada. O nível 3 vem do
 * app NOVO, com a divisão real: onde a soma das duas linhas fecha com a carga
 * (R$ 100), o REALIZADO de cada uma passa a ser o do nível 3. O projetado
 * (orçado de nov–dez) continua aproximado: o Qlik não o tem por filial.
 */
class CargaNivel3
{
    /** Tolerância da soma das filiais contra o negócio (arredondamento ao real). */
    public const TOLERANCIA_FILIAIS = 50.0;
    /** Tolerância da soma 14.3 + 16.1 para corrigir a divisão do rateio. */
    public const TOLERANCIA_RATEIO = 100.0;

    /**
     * @param array $dados o `require` de um `database/dre_nivel3_*.php`
     * @return array{contas:int, negocios:int, filiais:int, valores:int, rateios_corrigidos:int, ignorados:array}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $corte = (int)($dados['corte'] ?? 0);
        $contas = $dados['contas'] ?? [];
        if ($ano < 2000 || $corte < 1 || $corte > 12 || !$contas) {
            throw new RuntimeException('Carga do nível 3 sem ano, corte ou catálogo.');
        }

        $ler = static function (string $lista, string $onde) use ($contas): array {
            $out = [];
            foreach (explode(';', $lista) as $item) {
                if ($item === '') {
                    continue;
                }
                [$cod, $v] = array_pad(explode('=', $item, 2), 2, null);
                if (!isset($contas[$cod]) || !is_numeric($v)) {
                    throw new RuntimeException("Carga do nível 3 com item inválido em {$onde}: {$item}.");
                }
                $out[$cod] = (float)$v;
            }
            return $out;
        };

        // Lê e confere TUDO antes de escrever.
        $negocios = [];
        foreach ($dados['negocios'] ?? [] as $cod => $lista) {
            $negocios[(string)$cod] = $ler($lista, "negócio {$cod}");
        }
        $filiais = [];
        foreach ($dados['filiais'] ?? [] as $cod => $porFilial) {
            foreach ($porFilial as $fil => $lista) {
                $filiais[(string)$cod][(string)$fil] = $ler($lista, "negócio {$cod}, filial {$fil}");
            }
        }
        foreach ($filiais as $cod => $porFilial) {
            $soma = [];
            foreach ($porFilial as $valores) {
                foreach ($valores as $c => $v) {
                    $soma[$c] = ($soma[$c] ?? 0.0) + $v;
                }
            }
            $neg = $negocios[$cod] ?? [];
            foreach (array_unique(array_merge(array_keys($soma), array_keys($neg))) as $c) {
                if (abs(($soma[$c] ?? 0.0) - ($neg[$c] ?? 0.0)) > self::TOLERANCIA_FILIAIS) {
                    throw new RuntimeException(sprintf(
                        'Carga do nível 3: no negócio %s, a conta %s soma %s nas filiais e %s no negócio. Falta filial na carga.',
                        $cod, $c, number_format($soma[$c] ?? 0.0, 0, ',', '.'), number_format($neg[$c] ?? 0.0, 0, ',', '.')
                    ));
                }
            }
        }

        $idNegocio = [];
        $ignorados = [];
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        foreach (array_keys($negocios + $filiais) as $cod) {
            $busca->execute([(string)$cod]);
            $id = $busca->fetchColumn();
            if ($id === false) {
                $ignorados[] = (string)$cod;
                continue;
            }
            $idNegocio[(string)$cod] = (int)$id;
        }
        $idFilial = [];
        $buscaF = $pdo->prepare('SELECT id FROM filial WHERE negocio_id = ? AND cod_filial = ?');
        foreach ($filiais as $cod => $porFilial) {
            if (!isset($idNegocio[$cod])) {
                continue;
            }
            foreach (array_keys($porFilial) as $fil) {
                $buscaF->execute([$idNegocio[$cod], (string)$fil]);
                $id = $buscaF->fetchColumn();
                if ($id === false) {
                    throw new RuntimeException(
                        "Carga do nível 3 com filial {$fil} do negócio {$cod} fora do cadastro: carregue a DRE da filial antes."
                    );
                }
                $idFilial[$cod][(string)$fil] = (int)$id;
            }
        }

        // Escreve.
        $insCat = $pdo->prepare(
            'INSERT INTO dre_reduzida (codigo, reduzida, nome, linha, ordem) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reduzida = VALUES(reduzida), nome = VALUES(nome),
                                     linha = VALUES(linha), ordem = VALUES(ordem)'
        );
        foreach ($contas as $cod => [$red, $nome, $linha, $ordem]) {
            $insCat->execute([(string)$cod, (string)$red, (string)$nome, (string)$linha, (int)$ordem]);
        }
        $apaga = $pdo->prepare('DELETE FROM dre_reduzida_valor WHERE negocio_id = ? AND ano = ?');
        $ins = $pdo->prepare(
            'INSERT INTO dre_reduzida_valor (negocio_id, filial_id, ano, corte, codigo, realizado) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $valores = 0;
        $nFiliais = 0;
        foreach ($idNegocio as $cod => $negId) {
            $apaga->execute([$negId, $ano]);
            foreach ($negocios[$cod] ?? [] as $c => $v) {
                $ins->execute([$negId, 0, $ano, $corte, $c, $v]);
                $valores++;
            }
            foreach ($filiais[$cod] ?? [] as $fil => $porConta) {
                $nFiliais++;
                foreach ($porConta as $c => $v) {
                    $ins->execute([$negId, $idFilial[$cod][$fil], $ano, $corte, $c, $v]);
                    $valores++;
                }
            }
        }

        return [
            'contas' => count($contas), 'negocios' => count($idNegocio), 'filiais' => $nFiliais,
            'valores' => $valores, 'rateios_corrigidos' => self::corrigirRateioDasFiliais($pdo, $ano),
            'ignorados' => $ignorados,
        ];
    }

    /**
     * O realizado de 14.3 e 16.1 de cada filial passa a ser o do nível 3, onde a
     * soma das duas fecha com a carga (ver o cabeçalho da classe). Idempotente.
     */
    public static function corrigirRateioDasFiliais(PDO $pdo, int $ano): int
    {
        $ids = [];
        foreach ($pdo->query("SELECT id, codigo FROM dre_conta WHERE codigo IN ('14', '14.3', '15', '16', '16.1')") as $r) {
            $ids[$r['codigo']] = (int)$r['id'];
        }
        if (count($ids) < 5) {
            return 0; // estrutura nova ainda não carregada
        }
        $n3 = [];
        $st = $pdo->prepare(
            "SELECT v.filial_id, r.linha, SUM(v.realizado) s FROM dre_reduzida_valor v
               JOIN dre_reduzida r ON r.codigo = v.codigo
              WHERE v.ano = ? AND v.filial_id <> 0 AND r.linha IN ('14.3', '16.1')
              GROUP BY v.filial_id, r.linha"
        );
        $st->execute([$ano]);
        foreach ($st as $r) {
            $n3[(int)$r['filial_id']][$r['linha']] = (float)$r['s'];
        }
        $carga = [];
        $st = $pdo->prepare('SELECT filial_id, conta_id, realizado FROM dre_valor_filial WHERE ano = ? AND conta_id IN (?, ?)');
        $st->execute([$ano, $ids['14.3'], $ids['16.1']]);
        foreach ($st as $r) {
            $carga[(int)$r['filial_id']][(int)$r['conta_id'] === $ids['14.3'] ? '14.3' : '16.1'] = (float)$r['realizado'];
        }
        $upd = $pdo->prepare('UPDATE dre_valor_filial SET realizado = ? WHERE filial_id = ? AND conta_id = ? AND ano = ?');
        // Os blocos que somam o rateio andam junto: 14 (rateios do negócio) e 15
        // (resultado do negócio) com o 14.3; 16 (rateios administrativo) com o 16.1.
        $somar = $pdo->prepare('UPDATE dre_valor_filial SET realizado = realizado + ? WHERE filial_id = ? AND conta_id = ? AND ano = ?');
        $corrigidas = 0;
        foreach ($carga as $filial => $c) {
            if (!isset($c['14.3'], $c['16.1'])) {
                continue;
            }
            $novo143 = $n3[$filial]['14.3'] ?? 0.0;
            $novo161 = $n3[$filial]['16.1'] ?? 0.0;
            if (abs(($novo143 + $novo161) - ($c['14.3'] + $c['16.1'])) > self::TOLERANCIA_RATEIO) {
                continue;
            }
            // Já corrigida (o 16.1 fica com o resto do arredondamento, então só o
            // 14.3 diz se a filial já está no nível 3).
            if (abs($novo143 - $c['14.3']) < 1) {
                continue;
            }
            // O que sobrar do arredondamento fica no 16.1, para a soma das duas
            // continuar exatamente a da carga (e o resultado líquido não mexer).
            $delta = round($novo143 - $c['14.3'], 2);
            $upd->execute([round($c['14.3'] + $delta, 2), $filial, $ids['14.3'], $ano]);
            $upd->execute([round($c['16.1'] - $delta, 2), $filial, $ids['16.1'], $ano]);
            $somar->execute([$delta, $filial, $ids['14'], $ano]);
            $somar->execute([$delta, $filial, $ids['15'], $ano]);
            $somar->execute([-$delta, $filial, $ids['16'], $ano]);
            $corrigidas++;
        }
        return $corrigidas;
    }
}

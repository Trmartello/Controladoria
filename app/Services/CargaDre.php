<?php

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Carga da DRE gerencial: estrutura de contas + valor por negócio, conta e mês.
 *
 * Diferente de `CargaConteudo` em uma coisa que decide todo o resto: isto NÃO é
 * conteúdo editável. É número que veio de fora, e número de fora se SOBRESCREVE
 * — a carga do fechamento seguinte reescreve o mês que mudou em vez de empilhar
 * uma segunda verdade. Por isso `INSERT ... ON DUPLICATE KEY UPDATE`, e por
 * isso rodar a mesma carga duas vezes é inofensivo.
 *
 * A marca em `carga_conteudo` continua existindo, mas com outro papel: ela
 * evita o trabalho repetido a cada deploy, não protege edição de ninguém.
 *
 * **A regra do fechamento mora aqui, e não na cabeça de quem gera o arquivo.**
 * Rateio é a última etapa do fechamento contábil: mês sem rateio é mês ABERTO,
 * e o realizado dele está incompleto — costuma exibir resultado líquido igual
 * ao resultado do negócio e valor fora de escala. A carga recusa um mês
 * declarado como fechado cujo rateio consolidado seja zero, e diz qual mês
 * recusou. Em 2026 isso é outubro: R$ 373,2 mi de receita lançada e Rateios = 0.
 */
class CargaDre
{
    /** A linha 12 da DRE (Rateios) é `Cód Indicador` 14 — não 12. */
    private const CONTA_RATEIOS = '14';

    /**
     * @param array $dados o `require` de um `database/dre_*.php`
     * @return array{contas:int, valores:int, negocios:int, ignorados:array}
     */
    public static function aplicar(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $mesesReal = $dados['meses_real'] ?? [];
        if ($ano < 2000 || !$mesesReal) {
            throw new RuntimeException('Carga de DRE sem ano ou sem meses realizados.');
        }

        // Confere TUDO antes de escrever QUALQUER coisa. Carga recusada no meio
        // deixaria a estrutura nova com os valores velhos — e a DRE fecharia
        // errado sem ninguém ver. Validar primeiro custa uma passada a mais.
        $estrutura = $dados['contas'] ?? [];
        if (!$estrutura) {
            throw new RuntimeException('Carga de DRE sem estrutura de contas.');
        }
        $codigos = array_column($estrutura, 1);
        // Estrutura VELHA da DRE (sem 16.1) sobre um banco já na NOVA: os códigos
        // 15, 16 e 17 mudaram de sentido, e regravar a velha deixaria o resultado
        // líquido no código do rateio administrativo — sem erro nenhum. Recusa.
        if (!in_array('16.1', $codigos, true)
            && $pdo->query("SELECT 1 FROM dre_conta WHERE codigo = '16.1'")->fetchColumn()) {
            throw new RuntimeException(
                'Carga de DRE na estrutura VELHA (sem "Rateios Corporativos Administrativo", 16.1) sobre um banco '
                . 'já na estrutura nova da DRE gerencial: extraia do app "DRE - Gerencial Nova".'
            );
        }
        $porNegocio = self::agrupar($dados['valores'] ?? [], $codigos, count($mesesReal));
        self::exigirMesesFechados($porNegocio, $mesesReal);

        $contas = self::gravarContas($pdo, $estrutura);

        $ins = $pdo->prepare(
            'INSERT INTO dre_valor (negocio_id, conta_id, ano, mes, tipo, valor)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $busca = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');

        $gravados = 0;
        $negocios = 0;
        $ignorados = [];
        foreach ($porNegocio as $cod => $linhas) {
            $busca->execute([(string)$cod]);
            $negocioId = (int)$busca->fetchColumn();
            if (!$negocioId) {
                // Negócio que não está no cadastro (saiu da lista oficial, por
                // exemplo) não vira linha órfã e não derruba a carga dos
                // outros: fica anotado e o migrate o imprime.
                $ignorados[] = $cod;
                continue;
            }
            $negocios++;
            foreach ($linhas as $codConta => $serie) {
                $contaId = $contas[$codConta];
                foreach ($mesesReal as $i => $mes) {
                    $ins->execute([$negocioId, $contaId, $ano, $mes, 'REAL', $serie['real'][$i]]);
                    $gravados++;
                }
                foreach ($serie['meta'] as $i => $v) {
                    $ins->execute([$negocioId, $contaId, $ano, $i + 1, 'META', $v]);
                    $gravados++;
                }
            }
        }

        $pdo->prepare(
            'INSERT INTO dre_fechamento (ano, mes_fechado_ate, carga, extraido_em, fonte)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mes_fechado_ate = VALUES(mes_fechado_ate),
                                     carga = VALUES(carga),
                                     extraido_em = VALUES(extraido_em),
                                     fonte = VALUES(fonte),
                                     aplicado_em = CURRENT_TIMESTAMP'
        )->execute([
            $ano,
            max($mesesReal),
            (string)($dados['chave'] ?? ''),
            $dados['extraido_em'] ?? null,
            $dados['fonte'] ?? null,
        ]);

        return ['contas' => count($contas), 'valores' => $gravados,
                'negocios' => $negocios, 'ignorados' => $ignorados];
    }

    /**
     * Carga da DRE por FILIAL de UM negócio (fatia 3).
     *
     * Acumulada, não mensal — o porquê está no `schema.sql` e no cabeçalho do
     * arquivo de carga. A guarda que importa é a última: **a soma das filiais
     * tem de bater com a DRE do negócio**, conta a conta. Uma filial faltando
     * passaria despercebida (a tela mostraria cada filial certa e o total
     * errado), e é exatamente o modo de falha que este sistema não pode ter.
     *
     * @return array{filiais:int, valores:int}
     */
    public static function aplicarFiliais(PDO $pdo, array $dados): array
    {
        $ano = (int)($dados['ano'] ?? 0);
        $corte = (int)($dados['corte'] ?? 0);
        $codNegocio = (string)($dados['negocio'] ?? '');
        if ($ano < 2000 || $corte < 1 || $corte > 12 || $codNegocio === '') {
            throw new RuntimeException('Carga de filiais sem ano, corte ou negócio.');
        }

        $st = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
        $st->execute([$codNegocio]);
        $negocioId = (int)$st->fetchColumn();
        if (!$negocioId) {
            throw new RuntimeException("Carga de filiais para negócio fora do cadastro: {$codNegocio}.");
        }

        // O corte da carga precisa ser o do fechamento que está no banco: estes
        // números são "realizado ATÉ o corte", e casá-los com outro corte
        // somaria realizado de um período com orçamento de outro.
        $st = $pdo->prepare('SELECT mes_fechado_ate FROM dre_fechamento WHERE ano = ?');
        $st->execute([$ano]);
        $corteBanco = (int)$st->fetchColumn();
        if ($corteBanco !== $corte) {
            throw new RuntimeException(
                "Carga de filiais com corte {$corte}, mas o fechamento de {$ano} no banco está em "
                . "{$corteBanco}. Re-extraia as filiais no corte do fechamento."
            );
        }

        $contas = [];
        foreach ($pdo->query('SELECT id, codigo FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $contas[$l['codigo']] = (int)$l['id'];
        }
        if (!$contas) {
            throw new RuntimeException('Carga de filiais antes da carga do negócio: não há contas.');
        }

        // Valida tudo antes de escrever qualquer coisa.
        $porFilial = [];
        foreach ($dados['valores'] ?? [] as $chave => $par) {
            [$cod, $codConta] = array_pad(explode('|', (string)$chave, 2), 2, '');
            if (!isset($dados['filiais'][$cod])) {
                throw new RuntimeException("Carga de filiais com filial não declarada: {$chave}.");
            }
            if (!isset($contas[$codConta])) {
                throw new RuntimeException("Carga de filiais com conta fora da estrutura: {$chave}.");
            }
            [$r, $p] = array_pad(explode('/', (string)$par, 2), 2, '');
            foreach ([$r, $p] as $n) {
                if (!preg_match('/^-?\d+(\.\d+)?$/', trim($n))) {
                    throw new RuntimeException("Carga de filiais com valor não numérico em {$chave}: '{$par}'.");
                }
            }
            $porFilial[$cod][$codConta] = [(float)$r, (float)$p];
        }
        // Carga de filial na ESTRUTURA VELHA da DRE (sem 16.1/18/19) com o negócio
        // já na NOVA (2026-11-18): confere contra o negócio traduzido para os
        // sentidos velhos e, depois de gravar, converte (`migrarFiliaisParaEstruturaNova`).
        $velha = isset($contas['16.1']) && !self::cargaTemContas($porFilial, ['16.1', '18', '19'])
            && self::negocioTemConta($pdo, $negocioId, $ano, $contas['16.1']);
        self::exigirSomaDasFiliais($pdo, $negocioId, $ano, $corte, $porFilial, $contas, $velha);

        $insF = $pdo->prepare(
            'INSERT INTO filial (negocio_id, cod_filial, nome) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome)'
        );
        foreach ($dados['filiais'] as $cod => $nome) {
            $insF->execute([$negocioId, (string)$cod, $nome]);
        }
        $ids = [];
        $st = $pdo->prepare('SELECT id, cod_filial FROM filial WHERE negocio_id = ?');
        $st->execute([$negocioId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $ids[$l['cod_filial']] = (int)$l['id'];
        }

        $ins = $pdo->prepare(
            'INSERT INTO dre_valor_filial (filial_id, conta_id, ano, corte, realizado, projetado)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE corte = VALUES(corte), realizado = VALUES(realizado),
                                     projetado = VALUES(projetado)'
        );
        // A carga omite o que é zero ("ausente = zero"), e `ON DUPLICATE KEY
        // UPDATE` só sobrescreve o que ESTÁ no arquivo. Sem apagar antes, uma
        // linha que virou zero no corte novo continuaria no banco com o valor e
        // o corte do anterior — fantasma que a soma contra o negócio não pega,
        // porque essa conferência olha o arquivo, não o banco. Apagar e gravar
        // formam uma unidade: sem a transação, uma falha no meio deixaria o
        // negócio sem filial nenhuma.
        $gravados = 0;
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare(
                'DELETE v FROM dre_valor_filial v
                   JOIN filial f ON f.id = v.filial_id
                  WHERE f.negocio_id = ? AND v.ano = ?'
            )->execute([$negocioId, $ano]);
            foreach ($porFilial as $cod => $linhas) {
                foreach ($linhas as $codConta => [$r, $p]) {
                    $ins->execute([$ids[$cod], $contas[$codConta], $ano, $corte, $r, $p]);
                    $gravados++;
                }
            }
            if ($velha) {
                self::migrarFiliaisParaEstruturaNova($pdo, $ano);
            }
            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['filiais' => count($dados['filiais']), 'valores' => $gravados, 'convertida' => $velha];
    }

    private static function cargaTemContas(array $porFilial, array $codigos): bool
    {
        foreach ($porFilial as $linhas) {
            foreach ($codigos as $c) {
                if (isset($linhas[$c])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function negocioTemConta(PDO $pdo, int $negocioId, int $ano, int $contaId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM dre_valor WHERE negocio_id = ? AND ano = ? AND conta_id = ? LIMIT 1');
        $st->execute([$negocioId, $ano, $contaId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * A soma das filiais, conta a conta, contra a DRE do negócio.
     *
     * Tolerância de R$ 20: os dois lados vêm de extrações diferentes, cada uma
     * arredondando ao real — o maior desvio medido foi de R$ 6. Acima disso não
     * é arredondamento: é filial faltando.
     */
    private static function exigirSomaDasFiliais(
        PDO $pdo,
        int $negocioId,
        int $ano,
        int $corte,
        array $porFilial,
        array $contas,
        bool $velha = false
    ): void {
        $st = $pdo->prepare(
            'SELECT c.codigo,
                    COALESCE(SUM(CASE WHEN v.tipo = \'REAL\' AND v.mes <= ? THEN v.valor END), 0) AS realizado,
                    COALESCE(SUM(CASE WHEN v.tipo = \'META\' AND v.mes >  ? THEN v.valor END), 0) AS projetado
               FROM dre_conta c
               LEFT JOIN dre_valor v ON v.conta_id = c.id AND v.negocio_id = ? AND v.ano = ?
              GROUP BY c.id, c.codigo'
        );
        $st->execute([$corte, $corte, $negocioId, $ano]);
        $linhasNegocio = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($velha) {
            // Os sentidos VELHOS dos códigos, a partir do negócio na estrutura nova:
            // 14 velho = 14 + 16 novos; 14.3 velho = 14.3 + 16.1; 15/16/17 velhos =
            // 17/18/19 novos. 16.1, 18 e 19 não existem na carga velha.
            $n = [];
            foreach ($linhasNegocio as $l) {
                $n[$l['codigo']] = [(float)$l['realizado'], (float)$l['projetado']];
            }
            $soma = static fn ($a, $b) => [($n[$a][0] ?? 0) + ($n[$b][0] ?? 0), ($n[$a][1] ?? 0) + ($n[$b][1] ?? 0)];
            $mapa = ['14' => $soma('14', '16'), '14.3' => $soma('14.3', '16.1'),
                '15' => $n['17'] ?? [0, 0], '16' => $n['18'] ?? [0, 0], '17' => $n['19'] ?? [0, 0]];
            $traduzidas = [];
            foreach ($linhasNegocio as $l) {
                if (in_array($l['codigo'], ['16.1', '18', '19'], true)) {
                    continue;
                }
                if (isset($mapa[$l['codigo']])) {
                    [$l['realizado'], $l['projetado']] = $mapa[$l['codigo']];
                }
                $traduzidas[] = $l;
            }
            $linhasNegocio = $traduzidas;
        }
        foreach ($linhasNegocio as $l) {
            $somaR = 0.0;
            $somaP = 0.0;
            foreach ($porFilial as $linhas) {
                $somaR += $linhas[$l['codigo']][0] ?? 0.0;
                $somaP += $linhas[$l['codigo']][1] ?? 0.0;
            }
            if (abs($somaR - (float)$l['realizado']) > 20 || abs($somaP - (float)$l['projetado']) > 20) {
                throw new RuntimeException(sprintf(
                    'Carga de filiais não fecha com o negócio na conta %s: filiais somam %s realizado '
                    . 'e %s projetado, o negócio tem %s e %s. Falta filial na carga.',
                    $l['codigo'],
                    number_format($somaR, 0, ',', '.'),
                    number_format($somaP, 0, ',', '.'),
                    number_format((float)$l['realizado'], 0, ',', '.'),
                    number_format((float)$l['projetado'], 0, ',', '.')
                ));
            }
        }
    }

    /**
     * Leva a DRE das FILIAIS para a estrutura nova da DRE gerencial ("DRE -
     * Gerencial Nova", 2026-11-18), em que o rateio corporativo se divide em
     * NEGÓCIO (14.3) e ADMINISTRATIVO (16.1), nasce o "14 - Resultado do negócio"
     * (15) e Despesas Totais, Resultado Líquido e Margem descem para 17, 18 e 19.
     *
     * A carga das filiais é acumulada e cara de re-extrair (uma coluna por
     * filial), então ela não volta do Qlik agora: o 14.3 de cada filial se divide
     * na MESMA proporção que o negócio dela teve no mesmo período — realizado até
     * o corte da filial, orçado depois dele. É aproximação declarada, até a
     * re-extração das filiais no app novo; as demais linhas são só remanejadas
     * (o resultado líquido da filial não muda).
     *
     * Idempotente e independente da ordem: só converte a filial que ainda NÃO
     * tem linha 16.1 no ano (estrutura velha), e só se o negócio dela já estiver
     * na estrutura nova (tem 16.1). Carga de filial extraída do app novo já vem
     * com 16.1 e passa intacta.
     *
     * @return int filiais convertidas
     */
    public static function migrarFiliaisParaEstruturaNova(PDO $pdo, int $ano): int
    {
        $id = [];
        foreach ($pdo->query('SELECT id, codigo FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $id[$l['codigo']] = (int)$l['id'];
        }
        foreach (['13', '14', '14.1', '14.2', '14.3', '15', '16', '16.1', '17', '18', '19'] as $c) {
            if (!isset($id[$c])) {
                return 0; // estrutura nova ainda não carregada
            }
        }
        $filiais = $pdo->prepare(
            'SELECT DISTINCT v.filial_id, f.negocio_id, v.corte FROM dre_valor_filial v
               JOIN filial f ON f.id = v.filial_id
              WHERE v.ano = ? AND NOT EXISTS (SELECT 1 FROM dre_valor_filial w
                     WHERE w.filial_id = v.filial_id AND w.ano = v.ano AND w.conta_id = ?)'
        );
        $filiais->execute([$ano, $id['16.1']]);
        $lista = $filiais->fetchAll(PDO::FETCH_ASSOC);
        if (!$lista) {
            return 0;
        }
        $proporcao = $pdo->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN tipo = 'REAL' AND mes <= ? AND conta_id = ? THEN valor END), 0) AS r_neg,
               COALESCE(SUM(CASE WHEN tipo = 'REAL' AND mes <= ? AND conta_id = ? THEN valor END), 0) AS r_adm,
               COALESCE(SUM(CASE WHEN tipo = 'META' AND mes >  ? AND conta_id = ? THEN valor END), 0) AS p_neg,
               COALESCE(SUM(CASE WHEN tipo = 'META' AND mes >  ? AND conta_id = ? THEN valor END), 0) AS p_adm,
               COUNT(CASE WHEN conta_id = ? THEN 1 END) AS tem_adm
               FROM dre_valor WHERE negocio_id = ? AND ano = ?"
        );
        $ler = $pdo->prepare('SELECT conta_id, realizado, projetado FROM dre_valor_filial WHERE filial_id = ? AND ano = ?');
        $gravar = $pdo->prepare(
            'INSERT INTO dre_valor_filial (filial_id, conta_id, ano, corte, realizado, projetado) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE corte = VALUES(corte), realizado = VALUES(realizado), projetado = VALUES(projetado)'
        );
        $porId = array_flip($id);
        $convertidas = 0;
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($lista as $f) {
                $corte = (int)$f['corte'];
                $proporcao->execute([$corte, $id['14.3'], $corte, $id['16.1'], $corte, $id['14.3'], $corte, $id['16.1'],
                    $id['16.1'], (int)$f['negocio_id'], $ano]);
                $p = $proporcao->fetch(PDO::FETCH_ASSOC);
                if (!(int)$p['tem_adm']) {
                    continue; // o negócio ainda está na estrutura velha
                }
                $parte = static function (float $neg, float $adm): float {
                    $tot = $neg + $adm;
                    return abs($tot) < 0.005 ? 1.0 : $neg / $tot;
                };
                $pr = $parte((float)$p['r_neg'], (float)$p['r_adm']);
                $pp = $parte((float)$p['p_neg'], (float)$p['p_adm']);
                $ler->execute([(int)$f['filial_id'], $ano]);
                $velho = [];
                foreach ($ler->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $c = $porId[(int)$l['conta_id']] ?? null;
                    if ($c !== null) {
                        $velho[$c] = [(float)$l['realizado'], (float)$l['projetado']];
                    }
                }
                $v = static fn ($c) => $velho[$c] ?? [0.0, 0.0];
                $novo = [];
                $neg143 = [$v('14.3')[0] * $pr, $v('14.3')[1] * $pp];
                $novo['14.3'] = $neg143;
                $novo['16.1'] = [$v('14.3')[0] - $neg143[0], $v('14.3')[1] - $neg143[1]];
                $novo['16'] = $novo['16.1'];
                $novo['14'] = [$v('14.1')[0] + $v('14.2')[0] + $neg143[0], $v('14.1')[1] + $v('14.2')[1] + $neg143[1]];
                $novo['15'] = [$v('13')[0] + $novo['14'][0], $v('13')[1] + $novo['14'][1]];
                // Os códigos velhos 15, 16 e 17 mudaram de sentido: o valor deles
                // desce para 17, 18 e 19 ANTES de serem regravados acima.
                $novo['17'] = $v('15');
                $novo['18'] = $v('16');
                $novo['19'] = $v('17');
                foreach ($novo as $c => [$r, $pj]) {
                    $gravar->execute([(int)$f['filial_id'], $id[$c], $ano, $corte, round($r, 2), round($pj, 2)]);
                }
                $convertidas++;
            }
            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $convertidas;
    }

    /**
     * Estrutura de contas, vinda na mesma extração dos valores.
     *
     * @return array<string,int> código => id
     */
    private static function gravarContas(PDO $pdo, array $contas): array
    {
        $ins = $pdo->prepare(
            'INSERT INTO dre_conta (codigo, nome, nivel, pai_codigo, ordem, percentual)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), nivel = VALUES(nivel),
                                     pai_codigo = VALUES(pai_codigo), ordem = VALUES(ordem),
                                     percentual = VALUES(percentual)'
        );
        foreach ($contas as $c) {
            [$ordem, $codigo, $pai, $nivel, $nome, $percentual] = $c;
            $ins->execute([$codigo, $nome, $nivel, $pai, $ordem, $percentual]);
        }
        $mapa = [];
        foreach ($pdo->query('SELECT id, codigo FROM dre_conta')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $mapa[$l['codigo']] = (int)$l['id'];
        }
        return $mapa;
    }

    /**
     * Abre as séries serializadas e agrupa por negócio.
     *
     * Confere o que o formato compacto esconde: conta fora da estrutura, série
     * com número de meses errado e valor não numérico. Um ';' a mais numa linha
     * desalinharia o ano inteiro daquele negócio, calado.
     */
    private static function agrupar(array $valores, array $codigos, int $qtdReal): array
    {
        $porNegocio = [];
        foreach ($valores as $chave => $par) {
            [$cod, $codConta] = array_pad(explode('|', (string)$chave, 2), 2, '');
            if (!in_array($codConta, $codigos, true)) {
                throw new RuntimeException("Carga de DRE com conta fora da estrutura: {$chave}.");
            }
            $real = self::numeros($par[0], $chave);
            $meta = self::numeros($par[1], $chave);
            if (count($real) !== $qtdReal || count($meta) !== 12) {
                throw new RuntimeException(
                    "Carga de DRE com série de tamanho errado em {$chave}: "
                    . count($real) . " realizado(s) e " . count($meta) . ' meta(s).'
                );
            }
            $porNegocio[$cod][$codConta] = ['real' => $real, 'meta' => $meta];
        }
        return $porNegocio;
    }

    private static function numeros(string $serie, string $chave): array
    {
        $out = [];
        foreach (explode(';', $serie) as $n) {
            $n = trim($n);
            if ($n === '' || !preg_match('/^-?\d+(\.\d+)?$/', $n)) {
                throw new RuntimeException("Carga de DRE com valor não numérico em {$chave}: '{$n}'.");
            }
            $out[] = (float)$n;
        }
        return $out;
    }

    /**
     * Mês declarado como fechado precisa ter RATEIO consolidado.
     *
     * Consolidado, e não por negócio: o fechamento é da cooperativa, e a Usina
     * Fotovoltaica não tem rateio em mês nenhum — julgada sozinha, ela reprovaria
     * todo mês do ano.
     */
    private static function exigirMesesFechados(array $porNegocio, array $mesesReal): void
    {
        foreach ($mesesReal as $i => $mes) {
            $soma = 0.0;
            foreach ($porNegocio as $linhas) {
                $soma += $linhas[self::CONTA_RATEIOS]['real'][$i] ?? 0.0;
            }
            if (abs($soma) < 0.005) {
                throw new RuntimeException(
                    "Carga de DRE: o mês {$mes} está declarado como fechado, mas o rateio "
                    . 'consolidado dele é zero. Rateio é a última etapa do fechamento — '
                    . 'mês sem rateio é mês aberto, e o realizado dele muda sozinho até fechar.'
                );
            }
        }
    }
}

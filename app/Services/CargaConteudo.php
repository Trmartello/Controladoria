<?php

namespace App\Services;

use PDO;

/**
 * Cargas de conteúdo: texto redigido fora do sistema (o cenário macroeconômico,
 * a análise PESTEL) que precisa chegar a uma instalação EM USO.
 *
 * O `seeds.sql` não serve: ele só age com o contexto vazio, e essas telas já
 * têm itens escritos à mão. Quem aplica é o migrate, no deploy — e por isso a
 * marca em `carga_conteudo` é obrigatória: o migrate roda a cada deploy e, sem
 * ela, todo deploy recriaria o item que alguém apagou e reporia a redação que
 * alguém ajustou. Revisar os textos exige chave NOVA; a antiga fica marcada e
 * nunca mais é reaplicada.
 *
 * Esta classe é o ponto único das duas regras que toda carga repete — o que já
 * está na tela não entra de novo, e o alvo é o planejamento corporativo do ano.
 * O migrate a inclui direto (como faz com QlikSync) e a CLI a alcança pelo
 * autoload; sem isso, a terceira carga nasceria como terceira cópia da mesma
 * lógica, e a primeira divergência apareceria só em produção.
 */
class CargaConteudo
{
    /**
     * Normaliza para comparar: minúsculas, sem acento, espaços colapsados.
     * A tabela substitui o Normalizer porque a extensão intl não está na
     * imagem — mesma razão de PublicoController::normalizar().
     */
    public static function chaveTexto(string $t): string
    {
        $t = strtr(mb_strtolower(trim($t), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return preg_replace('/\s+/u', ' ', $t);
    }

    public static function jaAplicada(PDO $pdo, string $chave): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM carga_conteudo WHERE chave = ?');
        $st->execute([$chave]);
        return (bool)$st->fetchColumn();
    }

    public static function marcar(PDO $pdo, string $chave, string $detalhe): void
    {
        $pdo->prepare('INSERT INTO carga_conteudo (chave, detalhe) VALUES (?, ?)')
            ->execute([$chave, $detalhe]);
    }

    /**
     * Planejamentos corporativos cujo ciclo cobre o ano da carga.
     *
     * Só o CORPORATIVO: análise macro é leitura da cooperativa inteira, e
     * replicá-la nos doze negócios encheria cada tela com o mesmo texto,
     * empurrando para baixo a análise que é própria do negócio. O ano precisa
     * caber no ciclo porque o seletor da tela é limitado a
     * [ano_base, ano_fim] — fora dela o registro existiria sem nunca aparecer.
     */
    public static function planosCorporativos(PDO $pdo, int $ano): array
    {
        $st = $pdo->prepare(
            "SELECT p.id FROM planejamento p
               JOIN ciclo c ON c.id = p.ciclo_id
              WHERE p.escopo = 'CORPORATIVO' AND ? BETWEEN c.ano_base AND c.ano_fim"
        );
        $st->execute([$ano]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Aplica uma carga a um planejamento e devolve quantos registros entraram.
     *
     * `$conteudo['destino']` decide a tabela: CENARIO grava em `cenario_item`
     * (com `tipo` e `ordem`), FATOR grava em `fator` (com `etapa` e
     * `categoria`). Em ambos, `itens` é um mapa chave => lista de textos.
     */
    public static function aplicar(PDO $pdo, array $conteudo, int $planoId): int
    {
        return $conteudo['destino'] === 'CENARIO'
            ? self::aplicarCenario($pdo, $conteudo, $planoId)
            : self::aplicarFatores($pdo, $conteudo, $planoId);
    }

    private static function aplicarCenario(PDO $pdo, array $conteudo, int $planoId): int
    {
        $ano = (int)$conteudo['ano'];
        $existentes = self::existentes(
            $pdo,
            'SELECT descricao FROM cenario_item WHERE planejamento_id = ? AND ano = ?',
            [$planoId, $ano]
        );

        $gravados = 0;
        foreach ($conteudo['itens'] as $tipo => $textos) {
            // Continua a numeração do que já está na tela, em vez de disputar a
            // ordem com os itens que o usuário escreveu antes
            $maior = $pdo->prepare(
                'SELECT COALESCE(MAX(ordem), 0) FROM cenario_item
                  WHERE planejamento_id = ? AND ano = ? AND tipo = ?'
            );
            $maior->execute([$planoId, $ano, $tipo]);
            $ordem = (int)$maior->fetchColumn();

            $insere = $pdo->prepare(
                'INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($textos as $texto) {
                if (isset($existentes[self::chaveTexto($texto)])) {
                    continue;
                }
                $existentes[self::chaveTexto($texto)] = true;
                $insere->execute([$planoId, $ano, $tipo, ++$ordem, $texto]);
                $gravados++;
            }
        }
        return $gravados;
    }

    private static function aplicarFatores(PDO $pdo, array $conteudo, int $planoId): int
    {
        $ano = (int)$conteudo['ano'];
        $etapa = $conteudo['etapa'];
        // A comparação é dentro da ETAPA: o mesmo texto pode existir de
        // propósito no PESTEL e na SWOT (é assim que a promoção funciona), e
        // olhar o planejamento inteiro bloquearia a carga por causa dele.
        $existentes = self::existentes(
            $pdo,
            'SELECT descricao FROM fator WHERE planejamento_id = ? AND ano = ? AND etapa = ?',
            [$planoId, $ano, $etapa]
        );

        // `fator` não tem coluna de ordem: a tela ordena por categoria e id, e
        // a sequência do INSERT já é a ordem em que os itens aparecem.
        $insere = $pdo->prepare(
            'INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao)
             VALUES (?, ?, ?, ?, ?)'
        );
        $gravados = 0;
        foreach ($conteudo['itens'] as $categoria => $textos) {
            foreach ($textos as $texto) {
                if (isset($existentes[self::chaveTexto($texto)])) {
                    continue;
                }
                $existentes[self::chaveTexto($texto)] = true;
                $insere->execute([$planoId, $ano, $etapa, $categoria, $texto]);
                $gravados++;
            }
        }
        return $gravados;
    }

    /** Textos já presentes, indexados pela forma normalizada. */
    private static function existentes(PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $mapa = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $mapa[self::chaveTexto((string)$d)] = true;
        }
        return $mapa;
    }
}

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
    public static function planosCorporativos(PDO $pdo, ?int $ano): array
    {
        // Carga sem ano (a cascata é do horizonte, que já pertence a um ciclo)
        // não filtra por faixa: quem decide se o horizonte existe é a própria
        // carga, e filtrar por um ano que ela não tem excluiria todo mundo.
        if ($ano === null) {
            $st = $pdo->query("SELECT id FROM planejamento WHERE escopo = 'CORPORATIVO'");
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $st = $pdo->prepare(
            "SELECT p.id FROM planejamento p
               JOIN ciclo c ON c.id = p.ciclo_id
              WHERE p.escopo = 'CORPORATIVO' AND ? BETWEEN c.ano_base AND c.ano_fim"
        );
        $st->execute([$ano]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Os planejamentos que uma carga alcança: o corporativo, ou o de UM
     * NEGÓCIO quando ela declara `negocio` (o `cod_negocio` do cadastro).
     *
     * A carga de negócio nasceu com o diagnóstico da Pecuária (2026-11-04):
     * análise macro é leitura da cooperativa inteira e vai no corporativo,
     * mas a leitura de um negócio é dele — no corporativo ela empurraria para
     * baixo o que vale para todos, e nos outros onze negócios seria texto que
     * não é sobre eles.
     */
    public static function planosDaCarga(PDO $pdo, array $conteudo): array
    {
        $ano = isset($conteudo['ano']) ? (int)$conteudo['ano'] : null;
        return isset($conteudo['negocio'])
            ? self::planosDoNegocio($pdo, (string)$conteudo['negocio'], $ano)
            : self::planosCorporativos($pdo, $ano);
    }

    /**
     * Planejamentos de um negócio cujo ciclo cobre o ano da carga — CRIANDO o
     * que faltar.
     *
     * Criar não é liberdade que a carga toma: o planejamento de um negócio
     * nasce quando alguém escolhe aquele contexto na tela
     * (`PlanejamentoController::resolver`), e é a MESMA linha, com as mesmas
     * três colunas. Sem isso a carga de um negócio que ninguém abriu ainda
     * gravaria zero registro e seria marcada como aplicada — o pior desfecho
     * possível, porque não há erro em lugar nenhum e o texto simplesmente
     * nunca aparece.
     *
     * Negócio inexistente no cadastro levanta exceção em vez de devolver
     * lista vazia: o migrate trata `RuntimeException` adiando a carga para o
     * próximo deploy, que é o que se quer quando alguém renomeou ou desativou
     * o cadastro — e não marcar a chave como se a carga tivesse rodado.
     */
    public static function planosDoNegocio(PDO $pdo, string $cod, ?int $ano): array
    {
        $st = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ? AND ativo = 1');
        $st->execute([$cod]);
        $negocioId = (int)$st->fetchColumn();
        if (!$negocioId) {
            throw new \RuntimeException("negócio de código \"{$cod}\" não existe (ou está inativo) no cadastro");
        }

        if ($ano === null) {
            $ciclos = $pdo->query('SELECT id FROM ciclo')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $st = $pdo->prepare('SELECT id FROM ciclo WHERE ? BETWEEN ano_base AND ano_fim');
            $st->execute([$ano]);
            $ciclos = $st->fetchAll(PDO::FETCH_COLUMN);
        }

        $busca = $pdo->prepare(
            "SELECT id FROM planejamento WHERE ciclo_id = ? AND escopo = 'NEGOCIO' AND negocio_id = ?"
        );
        $cria = $pdo->prepare(
            "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'NEGOCIO', ?)"
        );
        $planos = [];
        foreach ($ciclos as $cicloId) {
            $busca->execute([(int)$cicloId, $negocioId]);
            $planoId = (int)$busca->fetchColumn();
            if (!$planoId) {
                $cria->execute([(int)$cicloId, $negocioId]);
                $planoId = (int)$pdo->lastInsertId();
            }
            $planos[] = $planoId;
        }
        return $planos;
    }

    /**
     * Aplica uma carga a um planejamento e devolve quantos registros entraram.
     *
     * `$conteudo['destino']` decide a tabela: CENARIO grava em `cenario_item`
     * (com `tipo` e `ordem`), FATOR grava em `fator` (com `etapa` e
     * `categoria`), CASCATA grava em `cascata_escolha` (célula
     * driver × eixo × horizonte, com escolha e renúncia) e INDICADOR grava os
     * doze meses de `indicador_valor`.
     */
    public static function aplicar(PDO $pdo, array $conteudo, int $planoId): int
    {
        return match ($conteudo['destino']) {
            'CENARIO'   => self::aplicarCenario($pdo, $conteudo, $planoId),
            'CASCATA'   => self::aplicarCascata($pdo, $conteudo, $planoId),
            'INDICADOR' => self::aplicarIndicadores($pdo, $conteudo, $planoId),
            default     => self::aplicarFatores($pdo, $conteudo, $planoId),
        };
    }

    /**
     * Indicadores que a carga procurou e NÃO achou, pelo nome da carga.
     *
     * Existe porque esta carga não cria indicador, e quem a chama precisa
     * dizer quais ficaram de fora — ver `aplicarIndicadores`.
     *
     * @var string[]
     */
    public static array $indicadoresNaoAchados = [];

    /**
     * Metas mensais de indicadores que JÁ EXISTEM no planejamento.
     *
     * Nasceu com as metas de 2027 da Agropecuária (planilha do cliente,
     * 2026-11-13). Quatro decisões, e nenhuma é simetria com as outras cargas:
     *
     * 1. **SOBRESCREVE o que já está lançado** — e as outras cargas nunca
     *    sobrescrevem. É pedido explícito do cliente ("atualize caso já tenha
     *    algo preenchido"): a planilha é a fonte, e o que estava na tela antes
     *    dela é rascunho. A marca em `carga_conteudo` continua valendo, e é ela
     *    que impede o deploy seguinte de desfazer uma correção feita à mão
     *    DEPOIS da carga — sem a marca, toda subida devolveria a planilha por
     *    cima do trabalho de quem ajustou.
     *
     * 2. **NÃO cria indicador que não existe.** As outras cargas criam o que
     *    falta; aqui, não. O indicador tem unidade, sentido, agregação e
     *    horizonte escolhidos a dedo na tela, e um nome que diverge por uma
     *    letra ("Rações Suinos" na planilha, "Ração Suinos" na tela) criaria um
     *    SEGUNDO indicador parecido em vez de preencher o primeiro — duplicata
     *    que ninguém pediu e que só se descobre olhando a lista inteira. O que
     *    não for encontrado é reportado em `$indicadoresNaoAchados`.
     *
     * 3. **Só os doze MESES, nunca o ano.** `mes = 0` é lançamento próprio, e a
     *    tela deriva o ano da soma dos meses quando a agregação é SOMA. Gravar
     *    os dois faria o anual digitado disputar com o calculado, e o que
     *    aparece dependeria de qual dos dois a leitura prefere.
     *
     * 4. **Escreve na versão CORRENTE da meta**, como faz
     *    `IndicadorController::salvar` sem `nova_versao`. A carga não é uma
     *    revisão de meta: é o preenchimento da revisão que está aberta. Abrir
     *    versão nova empurraria para o histórico uma meta que ninguém usou.
     *
     * `itens` é `nome do indicador => [doze valores, de janeiro a dezembro]`, e
     * os valores entram COMO ESTÃO: converter para a unidade cadastrada é
     * decisão de quem monta o arquivo, não daqui.
     */
    private static function aplicarIndicadores(PDO $pdo, array $conteudo, int $planoId): int
    {
        $ano = (int)$conteudo['ano'];

        // Índice por nome normalizado: a comparação tolera acento, caixa e
        // espaço repetido, que é onde planilha e tela divergem de verdade.
        $porNome = [];
        $st = $pdo->prepare('SELECT id, nome FROM indicador WHERE planejamento_id = ?');
        $st->execute([$planoId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $porNome[self::chaveTexto($i['nome'])] = (int)$i['id'];
        }

        $versaoDe = $pdo->prepare(
            "SELECT COALESCE(MAX(versao_meta), 0) FROM indicador_valor
              WHERE indicador_id = ? AND tipo = 'META'"
        );
        $grava = $pdo->prepare(
            "INSERT INTO indicador_valor (indicador_id, ano, mes, tipo, versao_meta, valor)
             VALUES (?, ?, ?, 'META', ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );

        $gravados = 0;
        foreach ($conteudo['itens'] as $nome => $meses) {
            $id = $porNome[self::chaveTexto((string)$nome)] ?? 0;
            if (!$id) {
                self::$indicadoresNaoAchados[] = (string)$nome;
                continue;
            }
            $versaoDe->execute([$id]);
            $versao = max(1, (int)$versaoDe->fetchColumn());
            foreach (array_values($meses) as $i => $valor) {
                $grava->execute([$id, $ano, $i + 1, $versao, (float)$valor]);
                $gravados++;
            }
        }
        return $gravados;
    }

    /**
     * Células da cascata que a carga preencheria: as que estão VAZIAS.
     *
     * A guarda aqui não é o texto, como nas outras cargas, e sim a CÉLULA:
     * a chave única de `cascata_escolha` é (plano, horizonte, driver, eixo), e
     * cada célula guarda a decisão que alguém tomou. Comparar texto deixaria a
     * carga sobrescrever uma escolha reescrita à mão — que é justamente o que
     * ninguém pode perder aqui.
     *
     * Devolve linhas já com os ids resolvidos, prontas para inserir.
     */
    public static function celulasVazias(PDO $pdo, array $conteudo, int $planoId): array
    {
        // O horizonte é do ciclo DO PLANEJAMENTO: o mesmo "H1" existe em cada
        // ciclo, e pegar o primeiro que casasse pelo nome gravaria a cascata no
        // ciclo errado.
        $st = $pdo->prepare(
            'SELECT h.id FROM horizonte h
               JOIN planejamento p ON p.ciclo_id = h.ciclo_id
              WHERE p.id = ? AND h.nome = ?'
        );
        $st->execute([$planoId, $conteudo['horizonte']]);
        $horizonteId = (int)$st->fetchColumn();
        if (!$horizonteId) {
            throw new \RuntimeException(
                "horizonte {$conteudo['horizonte']} não existe no ciclo do planejamento {$planoId}"
            );
        }

        $drivers = self::porNome($pdo, 'driver');
        $eixos = self::porNome($pdo, 'eixo');

        // Células já preenchidas, para não tocar em nenhuma delas
        $st = $pdo->prepare(
            'SELECT driver_id, COALESCE(eixo_id, 0) AS eixo
               FROM cascata_escolha WHERE planejamento_id = ? AND horizonte_id = ?'
        );
        $st->execute([$planoId, $horizonteId]);
        $ocupadas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $ocupadas["{$c['driver_id']}:{$c['eixo']}"] = true;
        }

        $linhas = [];
        foreach ($conteudo['itens'] as $driver => $celulas) {
            if (!isset($drivers[self::chaveTexto($driver)])) {
                // Driver e eixo são editáveis no Cadastro: uma renomeação em
                // produção não pode derrubar o deploy nem perder a carga em
                // silêncio. Quem chama decide — o migrate não marca a carga, e
                // o deploy seguinte tenta de novo.
                throw new \RuntimeException("driver \"{$driver}\" não existe no cadastro");
            }
            $driverId = $drivers[self::chaveTexto($driver)];

            foreach ($celulas as $eixo => $decisao) {
                $eixoId = null;
                if ($eixo !== 'sintese') {
                    if (!isset($eixos[self::chaveTexto($eixo)])) {
                        throw new \RuntimeException("eixo \"{$eixo}\" não existe no cadastro");
                    }
                    $eixoId = $eixos[self::chaveTexto($eixo)];
                }
                if (isset($ocupadas[$driverId . ':' . ($eixoId ?? 0)])) {
                    continue;
                }
                $linhas[] = [
                    'horizonte_id' => $horizonteId,
                    'driver_id' => $driverId,
                    'eixo_id' => $eixoId,
                    'driver' => $driver,
                    'eixo' => $eixo === 'sintese' ? 'síntese' : $eixo,
                    'escolha' => $decisao['escolha'],
                    'renuncia' => $decisao['renuncia'] ?? '',
                ];
            }
        }
        return $linhas;
    }

    private static function aplicarCascata(PDO $pdo, array $conteudo, int $planoId): int
    {
        $insere = $pdo->prepare(
            'INSERT INTO cascata_escolha
               (planejamento_id, horizonte_id, driver_id, eixo_id, escolha, renuncia)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $gravados = 0;
        foreach (self::celulasVazias($pdo, $conteudo, $planoId) as $l) {
            $insere->execute([
                $planoId, $l['horizonte_id'], $l['driver_id'], $l['eixo_id'],
                $l['escolha'], $l['renuncia'],
            ]);
            $gravados++;
        }
        return $gravados;
    }

    /** Cadastro ativo indexado pelo nome normalizado (driver ou eixo). */
    private static function porNome(PDO $pdo, string $tabela): array
    {
        // $tabela vem de chamada interna com literal, nunca de entrada
        $mapa = [];
        foreach ($pdo->query("SELECT id, nome FROM {$tabela} WHERE ativo = 1") as $l) {
            $mapa[self::chaveTexto($l['nome'])] = (int)$l['id'];
        }
        return $mapa;
    }

    /**
     * Um item da carga de cenário pode ser um TEXTO (entra se ainda não está
     * na tela) ou uma REVISÃO `['de' => texto anterior, 'para' => texto novo]`:
     * se o anterior está na tela, ele é atualizado no lugar — mesmo id, mesma
     * ordem, mesmas vozes da sala e mesmo encaminhamento ao plano de ação; se
     * foi apagado ou reescrito à mão, o novo entra como item, a menos que já
     * exista. Nasceu com a fotografia de setembro/2026: revisar a de agosto só
     * inserindo deixaria as duas versões do mesmo assunto lado a lado, e
     * apagar a antiga levaria junto o que alguém já tinha pendurado nela.
     */
    public static function textoDoItem(string|array $item): string
    {
        return is_array($item) ? (string)$item['para'] : $item;
    }

    public static function textoAnterior(string|array $item): ?string
    {
        return is_array($item) && isset($item['de']) ? (string)$item['de'] : null;
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
        $atualiza = $pdo->prepare(
            'UPDATE cenario_item SET descricao = ?
              WHERE planejamento_id = ? AND ano = ? AND descricao = ?'
        );
        foreach ($conteudo['itens'] as $tipo => $itens) {
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
            foreach ($itens as $item) {
                $texto = self::textoDoItem($item);
                $anterior = self::textoAnterior($item);
                if ($anterior !== null && isset($existentes[self::chaveTexto($anterior)])) {
                    // O UPDATE compara o texto exato; o mapa comparou o
                    // normalizado. Se a tela guardou o texto com outra caixa ou
                    // acento, a atualização não acha a linha e o novo entra abaixo.
                    $atualiza->execute([$texto, $planoId, $ano, $anterior]);
                    if ($atualiza->rowCount() > 0) {
                        unset($existentes[self::chaveTexto($anterior)]);
                        $existentes[self::chaveTexto($texto)] = true;
                        $gravados++;
                        continue;
                    }
                }
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

        // A carga entra DEPOIS do que já está na coluna: desde 2026-11-05 o
        // fator tem `ordem`, e item novo com zero saltaria para o topo de uma
        // coluna que alguém arrastou. A conta é feita aqui, e não por
        // `Fatores::proximaOrdem`, porque o migrate carrega este arquivo
        // sozinho (`require_once`), sem o autoload da aplicação — chamar o
        // serviço derrubaria o start do contêiner.
        $ultima = $pdo->prepare(
            'SELECT COALESCE(MAX(ordem), 0) FROM fator
              WHERE planejamento_id = ? AND ano <=> ? AND etapa = ? AND categoria = ?'
        );
        $insere = $pdo->prepare(
            'INSERT INTO fator (planejamento_id, ano, etapa, categoria, ordem, descricao)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $gravados = 0;
        foreach ($conteudo['itens'] as $categoria => $textos) {
            $ultima->execute([$planoId, $ano, $etapa, $categoria]);
            $ordem = (int)$ultima->fetchColumn();
            foreach ($textos as $texto) {
                if (isset($existentes[self::chaveTexto($texto)])) {
                    continue;
                }
                $existentes[self::chaveTexto($texto)] = true;
                $insere->execute([$planoId, $ano, $etapa, $categoria, ++$ordem, $texto]);
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

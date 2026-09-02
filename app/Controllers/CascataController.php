<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Bloqueio;
use App\Services\Quiz;

/** Cascata de escolhas: células driver × horizonte com síntese e aberturas por eixo. */
class CascataController
{
    /** Estrutura completa da cascata do planejamento (matriz + escolhas + fatores). */
    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);

        $horizontes = Database::todos(
            'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
            [(int)$plan['ciclo_id']]
        );
        $drivers = Database::todos('SELECT * FROM driver WHERE ativo = 1 ORDER BY ordem, nome');
        $eixos   = Database::todos('SELECT * FROM eixo WHERE ativo = 1 ORDER BY ordem, nome');

        $escolhas = Database::todos(
            'SELECT * FROM cascata_escolha WHERE planejamento_id = ?',
            [$planId]
        );
        // Comentários por escolha, numa consulta só: a tela precisa deles para
        // dizer, ANTES do clique, o que a exclusão leva junto. O comentário é
        // polimórfico (`ref_tipo`/`ref_id`) e não tem FK — quem o apaga é o
        // `excluir` daqui, em silêncio até agora.
        $comentarios = [];
        foreach (Database::todos(
            "SELECT c.ref_id, COUNT(*) AS n
             FROM comentario c
             JOIN cascata_escolha ce ON ce.id = c.ref_id
             WHERE c.ref_tipo = 'CASCATA' AND ce.planejamento_id = ?
             GROUP BY c.ref_id",
            [$planId]
        ) as $c) {
            $comentarios[(int)$c['ref_id']] = (int)$c['n'];
        }
        foreach ($escolhas as &$e) {
            $e['comentarios'] = $comentarios[(int)$e['id']] ?? 0;
            $e['fatores'] = Database::todos(
                'SELECT f.id, f.categoria, f.descricao, g.score
                 FROM cascata_fator cf
                 JOIN fator f ON f.id = cf.fator_id
                 LEFT JOIN gut g ON g.fator_id = f.id
                 WHERE cf.cascata_id = ?
                 ORDER BY g.score DESC, f.id',
                [$e['id']]
            );
            // Vozes do quiz que sustentam a decisão — muitas por célula, cada
            // uma com autor e lado; o texto da célula continua sendo um só
            $e['sugestoes'] = Database::todos(
                "SELECT ci.id, ci.texto, ci.tipo_resposta, ci.votos,
                        COALESCE(u.nome, ci.autor_nome, 'Participante') AS autor
                 FROM coleta_item ci
                 LEFT JOIN usuario u ON u.id = ci.autor_id
                 WHERE ci.destino_tipo = 'CASCATA' AND ci.destino_id = ?
                 ORDER BY ci.tipo_resposta, ci.votos DESC, ci.id",
                [$e['id']]
            );
        }

        Json::ok([
            'horizontes'  => $horizontes,
            'drivers'     => $drivers,
            'eixos'       => $eixos,
            'escolhas'    => $escolhas,
            // A Matriz de Execução: o que MEDE e o que EXECUTA cada escolha.
            'indicadores' => $this->indicadoresDaMatriz($planId),
            'projetos'    => $this->projetosDaMatriz($planId),
        ]);
    }

    /**
     * Indicadores do planejamento com as escolhas que eles medem e as séries
     * meta/real — a coluna do meio da Matriz de Execução.
     *
     * Vêm numa lista PLANA, com `cascatas` em cada um, e não repetidos dentro
     * de cada escolha: um indicador pode medir várias, e duplicá-lo por escolha
     * inflaria o corpo com as séries plurianuais repetidas. Quem agrupa é a
     * tela, num passe.
     *
     * Quatro consultas para o conjunto todo, nenhuma dentro de laço. O laço que
     * já existe acima (fatores e sugestões por escolha) é anterior a este tema e
     * ficou como estava; o que este acrescenta não repete o problema.
     *
     * As séries vêm CRUAS (todos os anos, meta na versão mais recente) porque
     * quem decide qual ano mostrar é a regra da tela de Metas — e ela mora lá,
     * numa função só. Resolver "o ano de referência" aqui criaria uma segunda
     * cópia da regra, que divergiria da primeira revisão em diante.
     */
    private function indicadoresDaMatriz(int $planId): array
    {
        $indicadores = Database::todos(
            'SELECT id, nome, unidade, sentido, metrica_ancora, horizonte_id
             FROM indicador WHERE planejamento_id = ?
             ORDER BY metrica_ancora DESC, nome',
            [$planId]
        );
        if (!$indicadores) {
            return [];
        }
        $ids = array_column($indicadores, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $vinculos = [];
        foreach (Database::todos(
            "SELECT indicador_id, cascata_id FROM indicador_cascata
             WHERE indicador_id IN ({$marcas})",
            $ids
        ) as $v) {
            $vinculos[(int)$v['indicador_id']][] = (int)$v['cascata_id'];
        }

        // Meta na versão mais recente de cada ano — a mesma subconsulta de
        // `IndicadorController::listar`, que é quem define o que é "a meta".
        $series = [];
        foreach (Database::todos(
            "SELECT v.indicador_id, v.ano, v.tipo, v.valor
             FROM indicador_valor v
             WHERE v.indicador_id IN ({$marcas})
               AND (v.tipo = 'REAL' OR v.versao_meta = (
                     SELECT MAX(v2.versao_meta) FROM indicador_valor v2
                      WHERE v2.indicador_id = v.indicador_id AND v2.ano = v.ano AND v2.tipo = 'META'))
             ORDER BY v.ano",
            $ids
        ) as $v) {
            $chave = $v['tipo'] === 'REAL' ? 'reais' : 'metas';
            $series[(int)$v['indicador_id']][$chave][] = ['ano' => (int)$v['ano'], 'valor' => $v['valor']];
        }

        foreach ($indicadores as &$i) {
            $i['cascatas'] = $vinculos[(int)$i['id']] ?? [];
            $i['metas'] = $series[(int)$i['id']]['metas'] ?? [];
            $i['reais'] = $series[(int)$i['id']]['reais'] ?? [];
        }
        return $indicadores;
    }

    /**
     * Projetos que executam alguma escolha — a coluna das iniciativas.
     *
     * Só os que têm `cascata_id`: a matriz é a leitura da cascata, e projeto
     * sem escolha não tem linha onde aparecer. O `progresso` é a média das ações
     * vivas, a MESMA expressão do Relatório de Status — ação cancelada fora da
     * conta, senão trabalho que ninguém vai fazer puxaria o percentual do
     * projeto para baixo.
     */
    private function projetosDaMatriz(int $planId): array
    {
        return Database::todos(
            "SELECT p.id, p.cascata_id, p.titulo, p.status, p.classificacao,
                    COALESCE(ROUND(AVG(CASE WHEN d.status <> 'CANCELADO' THEN d.progresso END)), 0) AS progresso
             FROM projeto p
             LEFT JOIN desdobramento d ON d.projeto_id = p.id
             WHERE p.planejamento_id = ? AND p.cascata_id IS NOT NULL
             GROUP BY p.id
             ORDER BY p.classificacao, p.ano, p.id",
            [$planId]
        );
    }

    /** Cria/atualiza a célula (síntese quando eixo_id é nulo; abertura quando informado). */
    public function salvar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);
        // exigirEdicaoPlanejamento devolve o PLANEJAMENTO; o vínculo das
        // sugestões grava triado_por, que precisa do usuário — usar o retorno
        // errado aqui estouraria a FK para usuario (defeito já catalogado)
        $u = Auth::exigirLogin();

        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        $driverId    = (int)($d['driver_id'] ?? 0);
        $eixoId      = !empty($d['eixo_id']) ? (int)$d['eixo_id'] : null;
        $escolha     = trim($d['escolha'] ?? '');
        $renuncia    = trim($d['renuncia'] ?? '');
        $fatores     = array_map('intval', $d['fatores'] ?? []);

        $horizonte = Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        );
        if (!$horizonte) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        if (!Database::um('SELECT id FROM driver WHERE id = ? AND ativo = 1', [$driverId])) {
            Json::erro('Driver inválido.');
        }
        if ($eixoId !== null && !Database::um('SELECT id FROM eixo WHERE id = ? AND ativo = 1', [$eixoId])) {
            Json::erro('Eixo inválido.');
        }
        if ($escolha === '') {
            Json::erro('Descreva a escolha.');
        }

        $existente = Database::um(
            'SELECT id FROM cascata_escolha
             WHERE planejamento_id = ? AND horizonte_id = ? AND driver_id = ?
               AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
            [$planId, $horizonteId, $driverId, $eixoId]
        );

        if ($existente) {
            $id = (int)$existente['id'];
            // Cadeado só na EDIÇÃO: a célula que ainda não existe não é disputada.
            Bloqueio::exigirMeu('cascata_escolha', $id, (int)$u['id'], 'esta célula');
            Database::executar(
                'UPDATE cascata_escolha SET escolha = ?, renuncia = ? WHERE id = ?',
                [$escolha, $renuncia, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO cascata_escolha
                   (planejamento_id, horizonte_id, driver_id, eixo_id, escolha, renuncia)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$planId, $horizonteId, $driverId, $eixoId, $escolha, $renuncia]
            );
        }

        // Vínculo com os fatores priorizados (SWOT/GUT) — substitui o conjunto
        Database::executar('DELETE FROM cascata_fator WHERE cascata_id = ?', [$id]);
        foreach (array_unique($fatores) as $fatorId) {
            $fator = Database::um(
                "SELECT id FROM fator WHERE id = ? AND planejamento_id = ? AND etapa = 'SWOT'",
                [$fatorId, $planId]
            );
            if ($fator) {
                Database::executar(
                    'INSERT INTO cascata_fator (cascata_id, fator_id) VALUES (?, ?)',
                    [$id, $fatorId]
                );
            }
        }

        // Vozes do quiz da cascata amarradas a esta célula. O front manda o
        // CONJUNTO (como `fatores`): quem saiu é solto, quem entrou é amarrado.
        // Muitas vozes, um texto por lado — o vínculo registra a origem; o
        // texto da célula é o que o condutor redigiu acima.
        if (array_key_exists('sugestoes', $d)) {
            $sugestoes = array_values(array_unique(array_map('intval', (array)$d['sugestoes'])));
            // A lista é medida ANTES de tocar o banco: `php -S` é
            // single-threaded, e um laço de milhares de UPDATEs segura o
            // servidor inteiro. Mesma lição de `Quiz::alvosCrus`.
            if (count($sugestoes) > 500) {
                Json::erro('Sugestões demais num pedido só.');
            }
            $marcas = $sugestoes ? implode(',', array_fill(0, count($sugestoes), '?')) : '';
            // Solta quem saiu do conjunto: volta a NOVO, editável de novo pelo
            // autor — o mesmo papel do "Desmarcar" da tempestade
            Database::executar(
                "UPDATE coleta_item SET destino_tipo = NULL, destino_id = NULL,
                   situacao = 'NOVO', triado_por = NULL, triado_em = NULL
                 WHERE destino_tipo = 'CASCATA' AND destino_id = ?"
                . ($marcas ? " AND id NOT IN ({$marcas})" : ''),
                array_merge([$id], $sugestoes)
            );
            foreach ($sugestoes as $sugestaoId) {
                // A guarda é a CÉLULA, não a rodada: encontros diferentes podem
                // ter perguntado a mesma célula, e todas essas vozes valem. O
                // JOIN recusa sugestão de outra célula, de outro plano, de
                // outro alvo (o roteiro agora tem cenário e fator no meio) ou
                // que não seja do quiz.
                Database::executar(
                    "UPDATE coleta_item ci
                     JOIN quiz_pergunta cp ON cp.id = ci.pergunta_id
                     SET ci.destino_tipo = 'CASCATA', ci.destino_id = ?,
                         ci.situacao = 'ACEITO', ci.triado_por = ?, ci.triado_em = NOW()
                     WHERE ci.id = ? AND ci.planejamento_id = ? AND ci.origem = 'QUIZ'
                       AND cp.alvo_tipo = 'CASCATA'
                       AND cp.horizonte_id = ? AND cp.driver_id = ?
                       AND COALESCE(cp.eixo_id, 0) = COALESCE(?, 0)",
                    [$id, (int)$u['id'], $sugestaoId, $planId, $horizonteId, $driverId, $eixoId]
                );
            }
        }
        // Sempre, e por LADO: a célula tem dois textos, e devolver a renúncia
        // com o texto da escolha seria pior que devolver o original
        Quiz::guardarRedacao('CASCATA', $id, $escolha, 'ESCOLHA');
        Quiz::guardarRedacao('CASCATA', $id, $renuncia, 'RENUNCIA');
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $celula = Database::um(
            'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$celula) {
            Json::erro('Escolha não encontrada neste planejamento.', 404);
        }
        // Projetos originados desta escolha perdem o vínculo (a FK não tem ON DELETE)
        Database::executar('UPDATE projeto SET cascata_id = NULL WHERE cascata_id = ?', [$id]);
        // Vozes do quiz voltam a soltas (e editáveis), como cenário/fator fazem
        // com a ideia da Coleta: sem isso ficariam apontando para um id morto
        Database::executar(
            "UPDATE coleta_item SET destino_tipo = NULL, destino_id = NULL,
               situacao = 'NOVO', triado_por = NULL, triado_em = NULL
             WHERE destino_tipo = 'CASCATA' AND destino_id = ?",
            [$id]
        );
        // O comentário é polimórfico (ref_tipo/ref_id) e não tem FK: sem apagar
        // junto, os registros ficariam órfãos para sempre no banco
        Database::executar(
            "DELETE FROM comentario WHERE ref_tipo = 'CASCATA' AND ref_id = ?", [$id]
        );
        Database::executar('DELETE FROM cascata_escolha WHERE id = ?', [$id]);
        Json::ok();
    }
}

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Quiz;

/**
 * Condução do quiz: a sala do PROJETO, que responde às análises do
 * planejamento uma pergunta por vez.
 *
 * A sala é a MESMA da tempestade — PIN, token, tetos, trava de força bruta —
 * e o PIN vale para o encontro inteiro: o participante escaneia uma vez e o
 * celular acompanha a tela que o condutor abriu. Aqui ficam apenas as rotas
 * AUTENTICADAS do condutor; a escrita do participante continua toda em
 * PublicoController.
 *
 * O ROTEIRO do encontro é a lista de perguntas da rodada (`quiz_pergunta`,
 * ordenada por `ordem`). A pergunta ativa é a única fonte da verdade
 * (`situacao = 'ATIVA'`): ativar uma encerra a anterior, e "reabrir" é
 * simplesmente ativar de novo — as sugestões continuam presas ao pergunta_id
 * delas, então navegar não perde nada.
 *
 * Navegar ≠ ativar: o condutor examina qualquer pergunta do roteiro (o
 * `estado()` aceita `pergunta_id` para pôr uma delas em FOCO) sem mexer no
 * celular de ninguém; a sala só muda em ativar/reabrir/encerrar.
 *
 * O que cada alvo significa mora em App\Services\Quiz — cinco telas
 * reescrevendo essa regra divergiriam na primeira análise nova.
 */
class QuizController
{
    private const MAX_IDEIAS = 20;
    private const MAX_VOTOS = 10;

    /**
     * Abre a sala do encontro já com a primeira pergunta ativa; os demais
     * alvos entram no roteiro como pendentes.
     *
     * Uma sala aberta por planejamento, de QUALQUER rito. Com outra aberta a
     * resposta é 409 com o código SALA_ABERTA e o nome da tela em que ela
     * ficou; confirmando (`confirmar_encerrar`), o encerra-e-abre acontece
     * aqui, num pedido só.
     */
    public function abrir(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();

        // Validar ANTES de encerrar a sala anterior: alvo inválido não pode
        // custar a discussão que estava rolando na outra tela.
        $alvos = Quiz::validarAlvos($d, $plan);
        Quiz::liberarSala($planId, $d, Quiz::tela((string)$alvos[0]['alvo_tipo']));

        $tema = mb_substr(trim(is_string($d['tema'] ?? null) ? $d['tema'] : ''), 0, 180)
            ?: 'Planejamento estratégico — preenchimento colaborativo';
        $maxIdeias = max(1, min(self::MAX_IDEIAS, (int)($d['max_ideias'] ?? 5)));
        $maxVotos = max(1, min(self::MAX_VOTOS, (int)($d['max_votos'] ?? 3)));

        $pin = Quiz::pinLivre();
        // O ano da rodada é obrigatório no schema, mas aqui é só registro: a
        // análise que a resposta alimenta tem o ano NA PERGUNTA (as sugestões
        // do quiz nunca entram nas telas anuais, isoladas por `origem`).
        $rodadaId = (int)Database::executar(
            "INSERT INTO coleta_rodada (planejamento_id, ano, tema, pin, max_ideias, max_votos,
               modo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, 'QUIZ', ?)",
            [$planId, (int)date('Y'), $tema, $pin, $maxIdeias, $maxVotos, (int)$u['id']]
        );
        $ids = $this->enfileirar($rodadaId, $alvos, true);
        Json::ok(['id' => $rodadaId, 'pin' => $pin, 'pergunta_id' => $ids[0] ?? null]);
    }

    /**
     * Acrescenta alvos ao roteiro e, se `ativar` (padrão), abre o primeiro
     * para a sala — os demais ficam pendentes. Alvo que já está no roteiro não
     * duplica (o UNIQUE garante) e, sendo o primeiro com `ativar`, é REABERTO
     * com as sugestões que já tinha.
     */
    public function perguntar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $alvos = Quiz::validarAlvos($d, $plan);
        $r = $this->sessaoAberta($planId);
        $ativar = !array_key_exists('ativar', $d) || (bool)$d['ativar'];
        $ids = $this->enfileirar((int)$r['id'], $alvos, $ativar);
        Json::ok(['pergunta_id' => $ativar ? ($ids[0] ?? null) : null, 'no_roteiro' => count($ids)]);
    }

    /** Abre (ou REABRE) uma pergunta do roteiro para a sala, pelo id. */
    public function ativar(int $perguntaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        $this->exigirPergunta($perguntaId, (int)$r['id']);
        $this->ativarPergunta((int)$r['id'], $perguntaId);
        Json::ok(['pergunta_id' => $perguntaId]);
    }

    /**
     * Encerra a pergunta ativa sem abrir outra: a sala vê "aguarde a próxima".
     * Serve para fechar a coleta de um alvo e discutir antes de avançar.
     */
    public function encerrarPergunta(int $perguntaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        $this->exigirPergunta($perguntaId, (int)$r['id']);
        Database::executar(
            "UPDATE quiz_pergunta SET situacao = 'ENCERRADA' WHERE id = ? AND situacao = 'ATIVA'",
            [$perguntaId]
        );
        Json::ok();
    }

    /**
     * Tira do roteiro uma pergunta que ainda não aconteceu. Só PENDENTE e sem
     * sugestão nenhuma: apagar uma pergunta respondida levaria as vozes junto
     * (FK SET NULL as soltaria no limbo) — essa fica, no máximo, encerrada.
     */
    public function removerPergunta(int $perguntaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        $p = $this->exigirPergunta($perguntaId, (int)$r['id']);
        if ($p['situacao'] !== 'PENDENTE') {
            Json::erro('Só uma pergunta ainda não aberta sai do roteiro.');
        }
        if (Database::um('SELECT id FROM coleta_item WHERE pergunta_id = ?', [$perguntaId])) {
            Json::erro('Esta pergunta já tem sugestões e não pode sair do roteiro.');
        }
        Database::executar('DELETE FROM quiz_pergunta WHERE id = ?', [$perguntaId]);
        Json::ok();
    }

    /**
     * Estado ao vivo para o condutor (consulta periódica): a sessão, o ROTEIRO
     * completo, a pergunta ativa, a pergunta em FOCO (`?pergunta_id` — navegar
     * sem mexer na sala) com as sugestões dela, e o progresso.
     *
     * É o MESMO estado para todas as telas: a faixa da sessão é um componente
     * só (`quiz.js`), e cada seção decide o que fazer com o foco.
     */
    public function estado(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);

        $r = Database::um(
            "SELECT r.*, (SELECT COUNT(DISTINCT p.token) FROM coleta_participante p
                          WHERE p.rodada_id = r.id) AS participantes
             FROM coleta_rodada r
             WHERE r.planejamento_id = ? AND r.situacao = 'ABERTA' AND r.modo = 'QUIZ'",
            [$planId]
        );
        if (!$r) {
            Json::ok(['sessao' => null]);
        }

        $roteiro = Quiz::roteiro((int)$r['id']);
        // A ativa sai de Quiz::ativa(), a MESMA fonte que o celular lê. Varrer
        // o roteiro aqui (ordenado por `ordem`) e ordenar por `aberta_em` lá
        // dava respostas DIFERENTES quando duas linhas ficavam ATIVA: o painel
        // do condutor mostrava as sugestões de uma pergunta e a sala respondia
        // outra, sem erro nenhum na tela.
        $ativaCrua = Quiz::ativa((int)$r['id']);
        $ativa = null;
        foreach ($roteiro as $p) {
            if ($ativaCrua && (int)$p['id'] === (int)$ativaCrua['id']) {
                $ativa = $p;
            }
        }
        // O foco é o que o condutor está EXAMINANDO — por padrão, a ativa.
        // Foco que não existe mais (pergunta removida) cai para a ativa.
        $focoId = (int)($_GET['pergunta_id'] ?? 0);
        $foco = $ativa;
        foreach ($roteiro as $p) {
            if ($focoId && (int)$p['id'] === $focoId) {
                $foco = $p;
            }
        }

        $sugestoes = $foco ? Database::todos(
            "SELECT ci.id, ci.texto, ci.tipo_resposta, ci.votos, ci.situacao,
                    (ci.destino_id IS NOT NULL) AS vinculada,
                    COALESCE(u.nome, ci.autor_nome, 'Participante') AS autor
             FROM coleta_item ci
             LEFT JOIN usuario u ON u.id = ci.autor_id
             WHERE ci.pergunta_id = ?
             ORDER BY ci.votos DESC, ci.criado_em, ci.id",
            [(int)$foco['id']]
        ) : [];

        // O PIN é a credencial de escrita da sala: quem não pode editar o
        // planejamento não o recebe — a MESMA regra de RodadaController::listar.
        // Sem isto, um perfil LEITURA lia o PIN aqui e gravava sugestões pela
        // porta pública (/api/publico/resposta), driblando o "somente leitura".
        $podeEditar = (Auth::usuario()['perfil'] ?? '') !== 'LEITURA';
        Json::ok([
            'sessao' => [
                'id' => (int)$r['id'],
                'pin' => $podeEditar ? $r['pin'] : null,
                'tema' => $r['tema'],
                'participantes' => (int)$r['participantes'],
                'max_ideias' => (int)$r['max_ideias'],
            ],
            'roteiro' => $roteiro,
            'progresso' => Quiz::progresso($roteiro),
            'pergunta' => $ativa,
            'foco' => $foco,
            'sugestoes' => $sugestoes,
        ]);
    }

    /** Encerra o encontro: a rodada e o que estiver ativo nela. */
    public function encerrar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        Quiz::encerrarSala((int)$r['id']);
        Json::ok();
    }

    /**
     * Exclui uma sugestão — ato de condução: teste, duplicata, coisa fora de
     * contexto. Só alcança item do QUIZ; a exclusão de ideia da tempestade
     * continua no ColetaController, com as regras dela.
     */
    public function excluirSugestao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = Database::um(
            "SELECT id FROM coleta_item
             WHERE id = ? AND planejamento_id = ? AND origem = 'QUIZ'",
            [$id, $planId]
        );
        if (!$item) {
            Json::erro('Sugestão não encontrada neste planejamento.', 404);
        }
        // O vínculo com o registro final mora NO item (destino_id): apagar o
        // item já apaga a voz da lista — nada fica apontando para registro
        // morto. Os votos caem pela FK (ON DELETE CASCADE). Grupos chegam na
        // fase de unificação; o solta abaixo é cinto de segurança, não fluxo.
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = NULL WHERE agrupado_em_id = ?', [$id]
        );
        Database::executar('DELETE FROM coleta_item WHERE id = ?', [$id]);
        Json::ok();
    }

    // ---- Miolo ----

    /**
     * Encerra a que estava na sala e põe esta no lugar — num UPDATE só.
     *
     * Em dois comandos (encerra as outras, depois ativa esta) há uma janela em
     * que NENHUMA está ativa, e dois condutores clicando junto podiam deixar
     * DUAS. `php -S` serializa os pedidos e escondia isso; php-fpm, que é o
     * recomendado para produção, não serializa.
     */
    private function ativarPergunta(int $rodadaId, int $perguntaId): void
    {
        Database::executar(
            "UPDATE quiz_pergunta
                SET situacao  = CASE WHEN id = ? THEN 'ATIVA' ELSE 'ENCERRADA' END,
                    aberta_em = CASE WHEN id = ? THEN NOW() ELSE aberta_em END
              WHERE rodada_id = ? AND (id = ? OR situacao = 'ATIVA')",
            [$perguntaId, $perguntaId, $rodadaId, $perguntaId]
        );
    }

    /**
     * Põe os alvos no roteiro (criando o que falta) e, se pedido, ativa o
     * primeiro. Alvo já existente não é recriado — e, sendo o primeiro com
     * `$ativarPrimeira`, é reaberto com as sugestões que já tinha.
     */
    private function enfileirar(int $rodadaId, array $alvos, bool $ativarPrimeira): array
    {
        $total = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM quiz_pergunta WHERE rodada_id = ?', [$rodadaId]
        )['n'] ?? 0);
        if ($total + count($alvos) > Quiz::MAX_PERGUNTAS) {
            Json::erro('O roteiro chegou ao limite de perguntas deste encontro.');
        }
        $ids = [];
        foreach ($alvos as $a) {
            // INSERT IGNORE + re-select, o padrão da casa para chave única sob
            // corrida (ver PublicoController::votar): dois condutores — telão e
            // notebook — enfileirando o mesmo alvo não podem virar 500. O
            // MAX(ordem) concorrente pode empatar; ORDER BY ordem, id desempata.
            $ordem = (int)(Database::um(
                'SELECT COALESCE(MAX(ordem), 0) AS o FROM quiz_pergunta WHERE rodada_id = ?',
                [$rodadaId]
            )['o'] ?? 0);
            $nova = Database::afetadas(
                'INSERT IGNORE INTO quiz_pergunta (rodada_id, alvo_tipo, enunciado, horizonte_id,
                   driver_id, eixo_id, ano, etapa, categoria, ordem, situacao)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'PENDENTE\')',
                [$rodadaId, $a['alvo_tipo'], $a['enunciado'], $a['horizonte_id'], $a['driver_id'],
                 $a['eixo_id'], $a['ano'], $a['etapa'], $a['categoria'], $ordem + 1]
            );
            // A re-seleção casa pela MESMA chave do UNIQUE (o alvo inteiro),
            // senão o IGNORE devolveria a pergunta errada quando o roteiro já
            // tem outros alvos do mesmo tipo.
            $linha = Database::um(
                "SELECT id FROM quiz_pergunta
                 WHERE rodada_id = ? AND alvo_tipo = ?
                   AND COALESCE(horizonte_id, 0) = COALESCE(?, 0)
                   AND COALESCE(driver_id, 0) = COALESCE(?, 0)
                   AND COALESCE(eixo_id, 0) = COALESCE(?, 0)
                   AND COALESCE(ano, 0) = COALESCE(?, 0)
                   AND COALESCE(etapa, '') = COALESCE(?, '')
                   AND COALESCE(categoria, '') = COALESCE(?, '')
                   AND (? <> 'LIVRE' OR COALESCE(enunciado, '') = COALESCE(?, ''))",
                [$rodadaId, $a['alvo_tipo'], $a['horizonte_id'], $a['driver_id'], $a['eixo_id'],
                 $a['ano'], $a['etapa'], $a['categoria'], $a['alvo_tipo'], $a['enunciado']]
            );
            if ($linha) {
                $ids[] = (int)$linha['id'];
                // O alvo já estava no roteiro e o condutor reescreveu a pergunta:
                // o IGNORE come o INSERT e a redação nova sumiria em silêncio —
                // a sala seguiria lendo a antiga. Fora de LIVRE o enunciado não
                // entra na chave do alvo justamente para permitir esta troca.
                if (!$nova && $a['enunciado'] !== null && $a['alvo_tipo'] !== 'LIVRE') {
                    Database::executar(
                        'UPDATE quiz_pergunta SET enunciado = ? WHERE id = ?',
                        [$a['enunciado'], (int)$linha['id']]
                    );
                }
            }
        }
        if ($ativarPrimeira && $ids) {
            $this->ativarPergunta($rodadaId, $ids[0]);
        }
        return $ids;
    }

    private function exigirPergunta(int $perguntaId, int $rodadaId): array
    {
        $p = Database::um(
            'SELECT * FROM quiz_pergunta WHERE id = ? AND rodada_id = ?',
            [$perguntaId, $rodadaId]
        );
        if (!$p) {
            Json::erro('Pergunta não encontrada nesta sessão.', 404);
        }
        return $p;
    }

    private function sessaoAberta(int $planId): array
    {
        $r = Database::um(
            "SELECT * FROM coleta_rodada
             WHERE planejamento_id = ? AND situacao = 'ABERTA' AND modo = 'QUIZ'",
            [$planId]
        );
        if (!$r) {
            Json::erro('Nenhuma sessão de quiz aberta neste planejamento.', 404);
        }
        return $r;
    }
}

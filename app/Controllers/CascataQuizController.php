<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Condução do quiz da cascata: a sala (rodada modo CASCATA) que responde
 * células da Cascata de Escolhas.
 *
 * A sala é a MESMA da tempestade — PIN, token, tetos, trava de força bruta —
 * só que a rodada declara o modo e cada sugestão pertence a uma pergunta
 * (célula). Aqui ficam apenas as rotas AUTENTICADAS do condutor; a escrita do
 * participante continua toda em PublicoController.
 *
 * A pergunta ativa é a única fonte da verdade (`cascata_pergunta.situacao`):
 * ativar uma encerra a anterior, e "reabrir" é simplesmente ativar de novo —
 * as sugestões continuam presas ao pergunta_id delas, então navegar não perde
 * nada.
 */
class CascataQuizController
{
    private const MAX_IDEIAS = 20;
    private const MAX_VOTOS = 10;

    /**
     * Abre a sessão do encontro (rodada modo CASCATA) já com a primeira
     * pergunta ativa. Uma rodada aberta por planejamento, de QUALQUER modo: o
     * PublicoController resolve a rodada pelo PIN e a ideia manual da Coleta
     * herda "a rodada aberta" — duas abertas deixariam as duas regras cegas.
     */
    public function abrir(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();

        $celula = $this->validarCelula($d, $plan);

        $jaAberta = Database::um(
            "SELECT modo FROM coleta_rodada WHERE planejamento_id = ? AND situacao = 'ABERTA'",
            [$planId]
        );
        if ($jaAberta) {
            Json::erro($jaAberta['modo'] === 'CASCATA'
                ? 'Já existe uma sessão da cascata aberta neste planejamento.'
                : 'Há uma tempestade de ideias aberta neste planejamento. Encerre-a antes.');
        }

        $tema = mb_substr(trim(is_string($d['tema'] ?? null) ? $d['tema'] : ''), 0, 180)
            ?: 'Cascata de Escolhas — preenchimento colaborativo';
        $maxIdeias = max(1, min(self::MAX_IDEIAS, (int)($d['max_ideias'] ?? 5)));
        $maxVotos = max(1, min(self::MAX_VOTOS, (int)($d['max_votos'] ?? 3)));

        $pin = $this->pinLivre();
        // O ano da rodada é obrigatório no schema, mas aqui é só registro: as
        // sugestões do quiz nunca entram nas telas anuais (isoladas por
        // tipo_resposta), então o ano corrente basta.
        $rodadaId = (int)Database::executar(
            "INSERT INTO coleta_rodada (planejamento_id, ano, tema, pin, max_ideias, max_votos,
               modo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, 'CASCATA', ?)",
            [$planId, (int)date('Y'), $tema, $pin, $maxIdeias, $maxVotos, (int)$u['id']]
        );
        $perguntaId = $this->ativarPergunta($rodadaId, $celula);
        Json::ok(['id' => $rodadaId, 'pin' => $pin, 'pergunta_id' => $perguntaId]);
    }

    /**
     * Pergunta a célula à sala: cria a pergunta (ou REABRE a já existente — as
     * sugestões dela voltam à tela como estavam) e a torna a ativa, encerrando
     * a anterior.
     */
    public function perguntar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $celula = $this->validarCelula($d, $plan);
        $r = $this->sessaoAberta($planId);
        $perguntaId = $this->ativarPergunta((int)$r['id'], $celula);
        Json::ok(['pergunta_id' => $perguntaId]);
    }

    /**
     * Estado ao vivo para o condutor (consulta periódica): a sessão, a pergunta
     * ativa com contexto, as sugestões dela nas duas colunas e a célula real.
     */
    public function estado(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);

        $r = Database::um(
            "SELECT r.*, (SELECT COUNT(DISTINCT p.token) FROM coleta_participante p
                          WHERE p.rodada_id = r.id) AS participantes
             FROM coleta_rodada r
             WHERE r.planejamento_id = ? AND r.situacao = 'ABERTA' AND r.modo = 'CASCATA'",
            [$planId]
        );
        if (!$r) {
            Json::ok(['sessao' => null]);
        }

        $ativa = Database::um(
            "SELECT p.id, p.horizonte_id, p.driver_id, p.eixo_id,
                    d.nome AS driver, e.nome AS eixo, h.nome AS horizonte,
                    h.ano_inicio, h.ano_fim, h.tema AS horizonte_tema
             FROM cascata_pergunta p
             JOIN driver d ON d.id = p.driver_id
             JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN eixo e ON e.id = p.eixo_id
             WHERE p.rodada_id = ? AND p.situacao = 'ATIVA'
             ORDER BY p.aberta_em DESC, p.id DESC",
            [(int)$r['id']]
        );

        $sugestoes = [];
        $escolha = null;
        if ($ativa) {
            $sugestoes = Database::todos(
                "SELECT ci.id, ci.texto, ci.tipo_resposta, ci.votos, ci.situacao,
                        (ci.destino_id IS NOT NULL) AS vinculada,
                        COALESCE(u.nome, ci.autor_nome, 'Participante') AS autor
                 FROM coleta_item ci
                 LEFT JOIN usuario u ON u.id = ci.autor_id
                 WHERE ci.pergunta_id = ?
                 ORDER BY ci.votos DESC, ci.criado_em, ci.id",
                [(int)$ativa['id']]
            );
            // A célula real (pode nem existir ainda), para o quadrante final
            $escolha = Database::um(
                'SELECT id, escolha, renuncia FROM cascata_escolha
                 WHERE planejamento_id = ? AND horizonte_id = ? AND driver_id = ?
                   AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
                [$planId, (int)$ativa['horizonte_id'], (int)$ativa['driver_id'],
                 $ativa['eixo_id'] !== null ? (int)$ativa['eixo_id'] : null]
            );
        }

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
            'pergunta' => $ativa,
            'sugestoes' => $sugestoes,
            'celula' => $escolha,
        ]);
    }

    /** Encerra o encontro: a rodada e o que estiver ativo nela. */
    public function encerrar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        Database::executar(
            "UPDATE cascata_pergunta SET situacao = 'ENCERRADA' WHERE rodada_id = ? AND situacao = 'ATIVA'",
            [(int)$r['id']]
        );
        Database::executar(
            "UPDATE coleta_rodada SET situacao = 'ENCERRADA', votacao = 'FECHADA', encerrada_em = NOW()
             WHERE id = ? AND situacao = 'ABERTA'",
            [(int)$r['id']]
        );
        Json::ok();
    }

    /**
     * Exclui uma sugestão (resposta ou renúncia) — ato de condução: teste,
     * duplicata, coisa fora de contexto. Só alcança item do QUIZ; a exclusão
     * de ideia da tempestade continua no ColetaController, com as regras dela.
     */
    public function excluirSugestao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = Database::um(
            'SELECT id FROM coleta_item
             WHERE id = ? AND planejamento_id = ? AND tipo_resposta IS NOT NULL',
            [$id, $planId]
        );
        if (!$item) {
            Json::erro('Sugestão não encontrada neste planejamento.', 404);
        }
        // O vínculo com a célula mora NO item (destino_id): apagar o item já
        // apaga a voz da lista — nada fica apontando para registro morto. Os
        // votos caem pela FK (ON DELETE CASCADE). Grupos chegam na fase de
        // unificação; o solta abaixo é cinto de segurança, não fluxo.
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = NULL WHERE agrupado_em_id = ?', [$id]
        );
        Database::executar('DELETE FROM coleta_item WHERE id = ?', [$id]);
        Json::ok();
    }

    // ---- Miolo ----

    /**
     * Torna a célula a pergunta ativa da rodada: encerra a anterior e cria (ou
     * reabre) a desta célula. Reabrir NÃO apaga nada — as sugestões continuam
     * presas ao pergunta_id.
     */
    private function ativarPergunta(int $rodadaId, array $c): int
    {
        Database::executar(
            "UPDATE cascata_pergunta SET situacao = 'ENCERRADA'
             WHERE rodada_id = ? AND situacao = 'ATIVA'",
            [$rodadaId]
        );
        $existente = Database::um(
            'SELECT id FROM cascata_pergunta
             WHERE rodada_id = ? AND horizonte_id = ? AND driver_id = ?
               AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
            [$rodadaId, $c['horizonte_id'], $c['driver_id'], $c['eixo_id']]
        );
        if ($existente) {
            Database::executar(
                "UPDATE cascata_pergunta SET situacao = 'ATIVA', aberta_em = NOW() WHERE id = ?",
                [(int)$existente['id']]
            );
            return (int)$existente['id'];
        }
        $ordem = (int)(Database::um(
            'SELECT COALESCE(MAX(ordem), 0) AS o FROM cascata_pergunta WHERE rodada_id = ?',
            [$rodadaId]
        )['o'] ?? 0);
        return (int)Database::executar(
            "INSERT INTO cascata_pergunta (rodada_id, horizonte_id, driver_id, eixo_id,
               ordem, situacao, aberta_em)
             VALUES (?, ?, ?, ?, ?, 'ATIVA', NOW())",
            [$rodadaId, $c['horizonte_id'], $c['driver_id'], $c['eixo_id'], $ordem + 1]
        );
    }

    /**
     * A célula pedida existe e pertence ao contexto: horizonte do ciclo DESTE
     * planejamento (o mesmo "H1" existe em cada ciclo), driver e eixo ativos.
     * A mesma validação do CascataController::salvar — a pergunta não pode
     * apontar para onde a escolha não poderia ser gravada.
     */
    private function validarCelula(array $d, array $plan): array
    {
        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        $driverId = (int)($d['driver_id'] ?? 0);
        $eixoId = !empty($d['eixo_id']) ? (int)$d['eixo_id'] : null;

        if (!Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        if (!Database::um('SELECT id FROM driver WHERE id = ? AND ativo = 1', [$driverId])) {
            Json::erro('Driver inválido.');
        }
        if ($eixoId !== null && !Database::um('SELECT id FROM eixo WHERE id = ? AND ativo = 1', [$eixoId])) {
            Json::erro('Eixo inválido.');
        }
        return ['horizonte_id' => $horizonteId, 'driver_id' => $driverId, 'eixo_id' => $eixoId];
    }

    private function sessaoAberta(int $planId): array
    {
        $r = Database::um(
            "SELECT * FROM coleta_rodada
             WHERE planejamento_id = ? AND situacao = 'ABERTA' AND modo = 'CASCATA'",
            [$planId]
        );
        if (!$r) {
            Json::erro('Nenhuma sessão da cascata aberta neste planejamento.', 404);
        }
        return $r;
    }

    /** Igual ao da tempestade: o UNIQUE do banco é a garantia final. */
    private function pinLivre(): string
    {
        for ($i = 0; $i < 30; $i++) {
            $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!Database::um('SELECT id FROM coleta_rodada WHERE pin = ?', [$pin])) {
                return $pin;
            }
        }
        Json::erro('Não foi possível gerar um PIN livre. Encerre rodadas antigas e tente de novo.');
    }
}

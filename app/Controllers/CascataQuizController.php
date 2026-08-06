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
 * O ROTEIRO do encontro é a lista de perguntas da rodada (`cascata_pergunta`,
 * ordenada por `ordem`). A pergunta ativa é a única fonte da verdade
 * (`situacao = 'ATIVA'`): ativar uma encerra a anterior, e "reabrir" é
 * simplesmente ativar de novo — as sugestões continuam presas ao pergunta_id
 * delas, então navegar não perde nada.
 *
 * Navegar ≠ ativar: o condutor examina qualquer pergunta do roteiro (o
 * `estado()` aceita `pergunta_id` para pôr uma delas em FOCO) sem mexer no
 * celular de ninguém; a sala só muda em ativar/reabrir/encerrar.
 */
class CascataQuizController
{
    private const MAX_IDEIAS = 20;
    private const MAX_VOTOS = 10;
    /** Teto de perguntas por encontro (126 células é o planejamento inteiro). */
    private const MAX_PERGUNTAS = 126;

    /**
     * Abre a sessão do encontro (rodada modo CASCATA) já com a primeira
     * pergunta ativa; os demais alvos entram no roteiro como pendentes. Uma
     * rodada aberta por planejamento, de QUALQUER modo: o PublicoController
     * resolve a rodada pelo PIN e a ideia manual da Coleta herda "a rodada
     * aberta" — duas abertas deixariam as duas regras cegas.
     */
    public function abrir(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();

        $base = $this->validarCelulaBase($d, $plan);
        $alvos = $this->validarAlvos($d);

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
        $ids = $this->enfileirar($rodadaId, $base, $alvos, true);
        Json::ok(['id' => $rodadaId, 'pin' => $pin, 'pergunta_id' => $ids[0] ?? null]);
    }

    /**
     * Acrescenta os alvos da célula ao roteiro e, se `ativar` (padrão), abre o
     * primeiro para a sala — os demais ficam pendentes. Alvo que já está no
     * roteiro não duplica (o UNIQUE garante; aqui só não é recriado) e, sendo o
     * primeiro com `ativar`, é REABERTO com as sugestões que já tinha.
     */
    public function perguntar(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $base = $this->validarCelulaBase($d, $plan);
        $alvos = $this->validarAlvos($d);
        $r = $this->sessaoAberta($planId);
        $ativar = !array_key_exists('ativar', $d) || (bool)$d['ativar'];
        $ids = $this->enfileirar((int)$r['id'], $base, $alvos, $ativar);
        Json::ok(['pergunta_id' => $ativar ? ($ids[0] ?? null) : null, 'no_roteiro' => count($ids)]);
    }

    /** Abre (ou REABRE) uma pergunta do roteiro para a sala, pelo id. */
    public function ativar(int $perguntaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        $p = $this->exigirPergunta($perguntaId, (int)$r['id']);
        Database::executar(
            "UPDATE cascata_pergunta SET situacao = 'ENCERRADA'
             WHERE rodada_id = ? AND situacao = 'ATIVA' AND id <> ?",
            [(int)$r['id'], $perguntaId]
        );
        Database::executar(
            "UPDATE cascata_pergunta SET situacao = 'ATIVA', aberta_em = NOW() WHERE id = ?",
            [$perguntaId]
        );
        Json::ok(['pergunta_id' => $perguntaId]);
    }

    /**
     * Encerra a pergunta ativa sem abrir outra: a sala vê "aguarde a próxima".
     * Serve para fechar a coleta de uma célula e discutir antes de avançar.
     */
    public function encerrarPergunta(int $perguntaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $r = $this->sessaoAberta($planId);
        $this->exigirPergunta($perguntaId, (int)$r['id']);
        Database::executar(
            "UPDATE cascata_pergunta SET situacao = 'ENCERRADA' WHERE id = ? AND situacao = 'ATIVA'",
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
        Database::executar('DELETE FROM cascata_pergunta WHERE id = ?', [$perguntaId]);
        Json::ok();
    }

    /**
     * Estado ao vivo para o condutor (consulta periódica): a sessão, o ROTEIRO
     * completo, a pergunta ativa, a pergunta em FOCO (`?pergunta_id` — navegar
     * sem mexer na sala) com as sugestões e a célula real dela, e o progresso.
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

        $roteiro = $this->roteiro((int)$r['id']);
        $ativa = null;
        foreach ($roteiro as $p) {
            if ($p['situacao'] === 'ATIVA') {
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

        $sugestoes = [];
        $escolha = null;
        if ($foco) {
            $sugestoes = Database::todos(
                "SELECT ci.id, ci.texto, ci.tipo_resposta, ci.votos, ci.situacao,
                        (ci.destino_id IS NOT NULL) AS vinculada,
                        COALESCE(u.nome, ci.autor_nome, 'Participante') AS autor
                 FROM coleta_item ci
                 LEFT JOIN usuario u ON u.id = ci.autor_id
                 WHERE ci.pergunta_id = ?
                 ORDER BY ci.votos DESC, ci.criado_em, ci.id",
                [(int)$foco['id']]
            );
            // A célula real (pode nem existir ainda), para o quadrante final
            $escolha = Database::um(
                'SELECT id, escolha, renuncia FROM cascata_escolha
                 WHERE planejamento_id = ? AND horizonte_id = ? AND driver_id = ?
                   AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
                [$planId, (int)$foco['horizonte_id'], (int)$foco['driver_id'],
                 $foco['eixo_id'] !== null ? (int)$foco['eixo_id'] : null]
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
            'roteiro' => $roteiro,
            'progresso' => self::progresso($roteiro),
            'pergunta' => $ativa,
            'foco' => $foco,
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

    /** O roteiro inteiro, na ordem, com a contagem de sugestões por pergunta. */
    private function roteiro(int $rodadaId): array
    {
        return Database::todos(
            "SELECT p.id, p.horizonte_id, p.driver_id, p.eixo_id, p.ordem, p.situacao,
                    d.nome AS driver, e.nome AS eixo, h.nome AS horizonte,
                    h.ano_inicio, h.ano_fim, h.tema AS horizonte_tema, h.objetivo,
                    (SELECT COUNT(*) FROM coleta_item ci WHERE ci.pergunta_id = p.id) AS sugestoes
             FROM cascata_pergunta p
             JOIN driver d ON d.id = p.driver_id
             JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN eixo e ON e.id = p.eixo_id
             WHERE p.rodada_id = ?
             ORDER BY p.ordem, p.id",
            [$rodadaId]
        );
    }

    /** "Pergunta N de M": N é a posição da ATIVA no roteiro; null sem ativa. */
    public static function progresso(array $roteiro): array
    {
        $atual = null;
        foreach (array_values($roteiro) as $i => $p) {
            if ($p['situacao'] === 'ATIVA') {
                $atual = $i + 1;
            }
        }
        return ['atual' => $atual, 'total' => count($roteiro)];
    }

    /**
     * Põe os alvos no roteiro (criando o que falta) e, se pedido, ativa o
     * primeiro. Alvo já existente não é recriado — e, sendo o primeiro com
     * `$ativarPrimeira`, é reaberto com as sugestões que já tinha.
     */
    private function enfileirar(int $rodadaId, array $base, array $alvos, bool $ativarPrimeira): array
    {
        $total = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM cascata_pergunta WHERE rodada_id = ?', [$rodadaId]
        )['n'] ?? 0);
        if ($total + count($alvos) > self::MAX_PERGUNTAS) {
            Json::erro('O roteiro chegou ao limite de perguntas deste encontro.');
        }
        $ids = [];
        foreach ($alvos as $eixoId) {
            // INSERT IGNORE + re-select, o padrão da casa para chave única sob
            // corrida (ver PublicoController::votar): dois condutores — telão e
            // notebook — enfileirando o mesmo alvo não podem virar 500. O
            // MAX(ordem) concorrente pode empatar; ORDER BY ordem, id desempata.
            $ordem = (int)(Database::um(
                'SELECT COALESCE(MAX(ordem), 0) AS o FROM cascata_pergunta WHERE rodada_id = ?',
                [$rodadaId]
            )['o'] ?? 0);
            Database::afetadas(
                "INSERT IGNORE INTO cascata_pergunta (rodada_id, horizonte_id, driver_id, eixo_id,
                   ordem, situacao)
                 VALUES (?, ?, ?, ?, ?, 'PENDENTE')",
                [$rodadaId, $base['horizonte_id'], $base['driver_id'], $eixoId, $ordem + 1]
            );
            $linha = Database::um(
                'SELECT id FROM cascata_pergunta
                 WHERE rodada_id = ? AND horizonte_id = ? AND driver_id = ?
                   AND COALESCE(eixo_id, 0) = COALESCE(?, 0)',
                [$rodadaId, $base['horizonte_id'], $base['driver_id'], $eixoId]
            );
            if ($linha) {
                $ids[] = (int)$linha['id'];
            }
        }
        if ($ativarPrimeira && $ids) {
            Database::executar(
                "UPDATE cascata_pergunta SET situacao = 'ENCERRADA'
                 WHERE rodada_id = ? AND situacao = 'ATIVA' AND id <> ?",
                [$rodadaId, $ids[0]]
            );
            Database::executar(
                "UPDATE cascata_pergunta SET situacao = 'ATIVA', aberta_em = NOW() WHERE id = ?",
                [$ids[0]]
            );
        }
        return $ids;
    }

    /**
     * A célula pedida existe e pertence ao contexto: horizonte do ciclo DESTE
     * planejamento (o mesmo "H1" existe em cada ciclo) e driver ativo. A mesma
     * validação do CascataController::salvar — a pergunta não pode apontar
     * para onde a escolha não poderia ser gravada.
     */
    private function validarCelulaBase(array $d, array $plan): array
    {
        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        $driverId = (int)($d['driver_id'] ?? 0);
        if (!Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        if (!Database::um('SELECT id FROM driver WHERE id = ? AND ativo = 1', [$driverId])) {
            Json::erro('Driver inválido.');
        }
        return ['horizonte_id' => $horizonteId, 'driver_id' => $driverId];
    }

    /**
     * Os alvos da pergunta: null = síntese, número = eixo (ativo). Aceita a
     * lista `alvos` ou o `eixo_id` avulso; sem nenhum, o alvo é a síntese.
     * Duplicatas caem; eixo inválido é recusado, nunca ignorado em silêncio.
     */
    private function validarAlvos(array $d): array
    {
        $declarouLista = array_key_exists('alvos', $d) && is_array($d['alvos']);
        $brutos = $declarouLista ? $d['alvos'] : [$d['eixo_id'] ?? null];
        $alvos = [];
        foreach ($brutos as $a) {
            $eixoId = ($a === null || $a === '' ) ? null : (int)$a;
            if ($eixoId !== null && !Database::um(
                'SELECT id FROM eixo WHERE id = ? AND ativo = 1', [$eixoId]
            )) {
                Json::erro('Eixo inválido.');
            }
            if (!in_array($eixoId, $alvos, true)) {
                $alvos[] = $eixoId;
            }
        }
        // Lista DECLARADA e vazia é recusada, nunca "corrigida": o condutor
        // desmarcou tudo, e assumir a síntese aqui abriria para a sala uma
        // pergunta que ele acabou de desmarcar. O padrão [null] vale só para o
        // caminho legado, sem a chave `alvos` no corpo.
        if (!$alvos) {
            if ($declarouLista) {
                Json::erro('Marque pelo menos uma parte da célula para perguntar.');
            }
            $alvos = [null];
        }
        return $alvos;
    }

    private function exigirPergunta(int $perguntaId, int $rodadaId): array
    {
        $p = Database::um(
            'SELECT * FROM cascata_pergunta WHERE id = ? AND rodada_id = ?',
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

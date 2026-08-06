<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Rodadas da tempestade de ideias — a sessão ao vivo da oficina.
 *
 * Uma rodada = um tema. Quem conduz abre a rodada, projeta o PIN e o QR, e os
 * participantes entram pelo celular sem cadastro. Precisando de mais de uma
 * pergunta, abrem-se rodadas em sequência (não existe roteiro de perguntas,
 * por decisão registrada no backlog).
 */
class RodadaController
{
    /** Teto de segurança da tela ao vivo, independente do que o front pedir. */
    private const MAX_IDEIAS = 20;
    private const MAX_VOTOS = 10;

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $ano = (int)($_GET['ano'] ?? 0);
        $filtro = $ano ? ' AND r.ano = ?' : '';
        $params = $ano ? [$planId, $ano] : [$planId];
        // O PIN é a credencial de escrita da rodada: quem não pode editar o
        // planejamento não o recebe. Sem isso, um perfil LEITURA — barrado em
        // POST /api/coleta — lia o PIN aqui e gravava ideias pela porta pública.
        $podeEditar = (Auth::usuario()['perfil'] ?? '') !== 'LEITURA';
        $colunas = $podeEditar ? 'r.*' : 'r.id, r.planejamento_id, r.ano, r.tema, r.situacao, r.modo,
                    r.votacao, r.max_ideias, r.max_votos, r.criado_por, r.criado_em, r.encerrada_em';
        Json::ok(Database::todos(
            "SELECT {$colunas}, u.nome AS autor,
                    (SELECT COUNT(*) FROM coleta_item i WHERE i.rodada_id = r.id) AS ideias,
                    (SELECT COUNT(DISTINCT i.participante_token) FROM coleta_item i
                      WHERE i.rodada_id = r.id AND i.participante_token IS NOT NULL) AS participantes
             FROM coleta_rodada r JOIN usuario u ON u.id = r.criado_por
             WHERE r.planejamento_id = ?{$filtro}
             ORDER BY r.situacao = 'ABERTA' DESC, r.criado_em DESC",
            $params
        ));
    }

    public function abrir(): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        // exigirEdicaoPlanejamento devolve o PLANEJAMENTO; criado_por precisa do
        // usuário, senão gravaria o id do plano e estouraria a FK fk_rod_autor
        Auth::exigirEdicaoPlanejamento($planId);
        $u = Auth::exigirLogin();

        $tema = mb_substr(trim(is_string($d['tema'] ?? null) ? $d['tema'] : ''), 0, 180);
        if ($tema === '') {
            Json::erro('Escreva a pergunta que abre a tempestade.');
        }
        $ano = (int)($d['ano'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            Json::erro('Informe o ano da coleta.');
        }
        // Duas rodadas abertas no mesmo plano deixariam uma delas invisível no
        // painel — e ela seguiria aceitando ideias pelo PIN antigo
        $jaAberta = Database::um(
            "SELECT id, ano, modo FROM coleta_rodada WHERE planejamento_id = ? AND situacao = 'ABERTA'",
            [$planId]
        );
        if ($jaAberta) {
            // A sala aberta pode ser o quiz da cascata: a mensagem diz qual é,
            // senão o condutor procura uma tempestade que não existe
            Json::erro($jaAberta['modo'] === 'CASCATA'
                ? 'Há uma sessão da cascata aberta neste planejamento. Encerre-a na seção Cascata antes.'
                : "Já existe uma rodada aberta neste planejamento (ano {$jaAberta['ano']}). "
                    . 'Encerre-a antes de abrir outra.');
        }
        $maxIdeias = max(1, min(self::MAX_IDEIAS, (int)($d['max_ideias'] ?? 5)));
        $maxVotos = max(1, min(self::MAX_VOTOS, (int)($d['max_votos'] ?? 3)));

        $pin = $this->pinLivre();
        $id = (int)Database::executar(
            'INSERT INTO coleta_rodada (planejamento_id, ano, tema, pin, max_ideias, max_votos, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$planId, $ano, $tema, $pin, $maxIdeias, $maxVotos, (int)$u['id']]
        );
        Json::ok(['id' => $id, 'pin' => $pin]);
    }

    public function encerrar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirRodada($id, $planId);
        Database::executar(
            "UPDATE coleta_rodada SET situacao = 'ENCERRADA', votacao = 'FECHADA', encerrada_em = NOW()
             WHERE id = ? AND situacao = 'ABERTA'",
            [$id]
        );
        Json::ok();
    }

    /** Liga ou desliga a votação dos participantes (convergência opcional). */
    public function votacao(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $rodada = $this->exigirRodada($id, $planId);
        if ($rodada['situacao'] !== 'ABERTA') {
            Json::erro('A rodada já foi encerrada.');
        }
        $abrir = !empty($d['abrir']);
        Database::executar(
            'UPDATE coleta_rodada SET votacao = ? WHERE id = ?',
            [$abrir ? 'ABERTA' : 'FECHADA', $id]
        );
        Json::ok(['votacao' => $abrir ? 'ABERTA' : 'FECHADA']);
    }

    /**
     * PIN de 6 dígitos que não colide com outra rodada. O UNIQUE do banco é a
     * garantia final; o laço só evita o erro na maioria dos casos.
     */
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

    private function exigirRodada(int $id, int $planId): array
    {
        $r = Database::um(
            'SELECT * FROM coleta_rodada WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$r) {
            Json::erro('Rodada não encontrada neste planejamento.', 404);
        }
        return $r;
    }
}

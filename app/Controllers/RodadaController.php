<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Quiz;

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
    /** Perguntas por questionário: ninguém responde trinta antes de um encontro. */
    private const MAX_PERGUNTAS = 30;

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // O prazo do questionário fecha a rodada na primeira leitura depois
        // dele — inclusive esta, para o condutor ver "encerrada" sem depender
        // de um celular ter batido antes.
        Quiz::fecharVencidas();
        $ano = (int)($_GET['ano'] ?? 0);
        $filtro = $ano ? ' AND r.ano = ?' : '';
        // O rótulo do autor removido é o PRIMEIRO parâmetro: o `?` dele está
        // no SELECT, antes do WHERE — e a lista muda de tamanho com o filtro
        // de ano, então ele entra aqui e não no fim.
        $params = $ano ? [UsuarioController::SEM_USUARIO, $planId, $ano]
                       : [UsuarioController::SEM_USUARIO, $planId];
        // O PIN é a credencial de escrita da rodada: quem não pode editar o
        // planejamento não o recebe. Sem isso, um perfil LEITURA — barrado em
        // POST /api/coleta — lia o PIN aqui e gravava ideias pela porta pública.
        $podeEditar = (Auth::usuario()['perfil'] ?? '') !== 'LEITURA';
        $colunas = $podeEditar ? 'r.*' : 'r.id, r.planejamento_id, r.ano, r.tema, r.situacao, r.modo,
                    r.votacao, r.max_ideias, r.max_votos, r.prazo, r.criado_por, r.criado_em, r.encerrada_em';
        $rodadas = Database::todos(
            "SELECT {$colunas}, COALESCE(u.nome, ?) AS autor,
                    (SELECT COUNT(*) FROM coleta_item i WHERE i.rodada_id = r.id) AS ideias,
                    -- Participante é quem ENTROU (escaneou o QR e se identificou),
                    -- não quem já enviou ideia: contar por `coleta_item` deixava
                    -- a sala cheia marcando zero enquanto ninguém tivesse
                    -- escrito, que é exatamente o momento em que quem conduz
                    -- precisa saber se pode começar.
                    (SELECT COUNT(*) FROM coleta_participante cp
                      WHERE cp.rodada_id = r.id) AS participantes
             FROM coleta_rodada r LEFT JOIN usuario u ON u.id = r.criado_por
             WHERE r.planejamento_id = ?{$filtro}
             ORDER BY r.situacao = 'ABERTA' DESC, r.criado_em DESC",
            $params
        );
        // As perguntas do questionário, com quantas ideias e quantas pessoas
        // responderam cada uma: é o acompanhamento do condutor antes do
        // encontro. Só a tempestade as tem; no quiz o roteiro é outro.
        foreach ($rodadas as &$r) {
            $r['perguntas'] = $r['modo'] === 'TEMPESTADE'
                ? Quiz::perguntasDaTempestade((int)$r['id'], true) : [];
        }
        Json::ok($rodadas);
    }

    /**
     * O prazo do questionário, vindo do formulário como data (`AAAA-MM-DD`,
     * vale até o fim do dia) ou como data e hora. Nulo = sem prazo. No
     * passado é recusado: abriria uma rodada que a primeira leitura fecha.
     */
    private function prazo(array $d): ?string
    {
        $bruto = trim(is_string($d['prazo'] ?? null) ? $d['prazo'] : '');
        if ($bruto === '') {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $bruto)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $bruto)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $bruto);
        if (!$data) {
            Json::erro('Prazo inválido — use o calendário para escolher a data.');
        }
        if (strlen($bruto) === 10) {
            $data = $data->setTime(23, 59, 59);
        }
        if ($data <= new \DateTimeImmutable()) {
            Json::erro('O prazo do questionário precisa estar no futuro.');
        }
        return $data->format('Y-m-d H:i:s');
    }

    /**
     * As perguntas do questionário vindas do corpo: uma lista, ou um texto com
     * uma pergunta por linha (é assim que o formulário as manda). Vazio é a
     * tempestade de tema único, como sempre foi.
     */
    private function perguntasDoCorpo(array $d): array
    {
        $bruto = $d['perguntas'] ?? [];
        if (is_string($bruto)) {
            $bruto = preg_split('/\r\n|\r|\n/', $bruto) ?: [];
        }
        if (!is_array($bruto)) {
            Json::erro('Perguntas inválidas.');
        }
        if (count($bruto) > self::MAX_PERGUNTAS) {
            Json::erro('Questionário longo demais: no máximo ' . self::MAX_PERGUNTAS . ' perguntas.');
        }
        return array_values(array_filter(array_map(
            static fn($p) => is_string($p) ? trim($p) : '', $bruto
        ), static fn($p) => $p !== ''));
    }

    /**
     * Acrescenta perguntas ao questionário de uma rodada ABERTA — sempre ao
     * fim, para não mudar a numeração de quem já respondeu. Pedido do cliente
     * (2026-09-03): as perguntas são pré-cadastradas e o participante as
     * percorre em ordem; reordenar depois da primeira resposta trocaria o
     * "pergunta 2" que alguém já respondeu por outra.
     */
    public function perguntas(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $rodada = $this->exigirRodada($id, $planId);
        if ($rodada['situacao'] !== 'ABERTA') {
            Json::erro('A rodada já foi encerrada.');
        }
        if ($rodada['modo'] !== 'TEMPESTADE') {
            Json::erro('O questionário é da tempestade de ideias; o roteiro do encontro se monta pelo 🎤 das análises.');
        }
        $perguntas = $this->perguntasDoCorpo($d);
        if (!$perguntas) {
            Json::erro('Escreva ao menos uma pergunta.');
        }
        $gravadas = Quiz::gravarPerguntasLivres($id, $perguntas);
        Json::ok(['gravadas' => $gravadas, 'perguntas' => Quiz::perguntasDaTempestade($id, true)]);
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
        $maxIdeias = max(1, min(self::MAX_IDEIAS, (int)($d['max_ideias'] ?? 5)));
        $maxVotos = max(1, min(self::MAX_VOTOS, (int)($d['max_votos'] ?? 3)));
        // Tudo validado ANTES de mexer em qualquer sala: perguntas e prazo
        // tortos não podem encerrar a sessão de outra análise (o `liberarSala`
        // abaixo, com a confirmação) e então recusar — nem deixar uma rodada
        // aberta pela metade, com PIN e sem questionário.
        $perguntas = $this->perguntasDoCorpo($d);
        $prazo = $this->prazo($d);

        // Uma sala aberta por planejamento, de qualquer rito: duas deixariam uma
        // delas invisível no painel, seguindo a aceitar ideias pelo PIN antigo.
        // A colisão é PERGUNTA, não recusa — quem esqueceu de fechar a sala de
        // outra análise confirma o encerramento e segue daqui mesmo.
        Quiz::liberarSala($planId, $d, Quiz::tela('LIVRE'));

        $pin = Quiz::pinLivre();
        $id = (int)Database::executar(
            'INSERT INTO coleta_rodada (planejamento_id, ano, tema, pin, max_ideias, max_votos, prazo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$planId, $ano, $tema, $pin, $maxIdeias, $maxVotos, $prazo, (int)$u['id']]
        );
        // O QUESTIONÁRIO: as perguntas viram linhas LIVRE do roteiro, todas
        // abertas ao mesmo tempo. O teto `max_ideias` passa a valer POR
        // PERGUNTA (`PublicoController::ideia`).
        $gravadas = $perguntas ? Quiz::gravarPerguntasLivres($id, $perguntas) : 0;
        Json::ok(['id' => $id, 'pin' => $pin, 'perguntas' => $gravadas, 'prazo' => $prazo]);
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

    /**
     * Reescreve a PERGUNTA da tempestade, com a rodada aberta.
     *
     * A pergunta é da condução, não do cadastro: o rumo do encontro muda no
     * meio dele, e quem conduz precisa poder reformular sem encerrar a rodada
     * (o que jogaria fora PIN, participantes e ideias já coletadas). Ela chega
     * ao celular de todo mundo na batida seguinte — a tela do participante já
     * lê o `tema` e o tem na assinatura do polling.
     *
     * Só com a rodada ABERTA: mexer no tema de uma rodada encerrada reescreveria
     * a pergunta sob as ideias que já foram respondidas e arquivadas.
     * A guarda é a mesma de encerrar e de abrir a votação — quem conduz o
     * planejamento; perfil LEITURA não passa de `exigirEdicaoPlanejamento`.
     */
    public function pergunta(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $rodada = $this->exigirRodada($id, $planId);
        if ($rodada['situacao'] !== 'ABERTA') {
            Json::erro('A rodada já foi encerrada.');
        }
        // Mesmo corte do `abrir()`: a coluna é VARCHAR(180) e o texto vem da
        // sala, ditado por voz — sem o corte, o INSERT falharia em modo estrito
        $tema = mb_substr(trim(is_string($d['tema'] ?? null) ? $d['tema'] : ''), 0, 180);
        if ($tema === '') {
            Json::erro('Escreva a pergunta que abre a tempestade.');
        }
        Database::executar('UPDATE coleta_rodada SET tema = ? WHERE id = ?', [$tema, $id]);
        Json::ok(['tema' => $tema]);
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

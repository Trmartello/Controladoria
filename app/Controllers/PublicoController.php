<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Json;

/**
 * Entrada do participante na tempestade de ideias — as ÚNICAS rotas de escrita
 * sem autenticação do sistema.
 *
 * Não há sessão, logo não há CSRF a validar: um token só faz sentido contra
 * autoridade ambiente, e aqui não existe nenhuma. A guarda é outra e precisa
 * ser levada a sério:
 *
 *  - só grava em `coleta_item`, e só de uma rodada com situacao = 'ABERTA';
 *  - o participante recebe um token aleatório amarrado à rodada; sem ele, ou
 *    com ele de outra rodada, nada é aceito;
 *  - teto de ideias por participante (definido na rodada) e tamanho máximo de
 *    texto, para uma aba aberta não virar canal de despejo;
 *  - encerrada a rodada, o token deixa de valer para qualquer coisa.
 */
class PublicoController
{
    private const MAX_TEXTO = 400;
    private const MAX_NOME = 60;

    /** Dados públicos da rodada — o mínimo para a tela do participante. */
    public function rodada(string $pin): void
    {
        $r = $this->rodadaPorPin($pin);
        Json::ok([
            'tema' => $r['tema'],
            'situacao' => $r['situacao'],
            'votacao' => $r['votacao'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
        ]);
    }

    /** Entra na rodada com um nome; devolve o token que identifica a pessoa. */
    public function entrar(): void
    {
        $d = Json::corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $nome = mb_substr(trim($d['nome'] ?? ''), 0, self::MAX_NOME);
        if ($nome === '') {
            Json::erro('Digite seu nome para entrar.');
        }
        Json::ok([
            'token' => bin2hex(random_bytes(16)),
            'nome' => $nome,
            'tema' => $r['tema'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
        ]);
    }

    public function ideia(): void
    {
        $d = Json::corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $token = $this->token($d);
        $nome = mb_substr(trim($d['nome'] ?? ''), 0, self::MAX_NOME) ?: 'Participante';

        $texto = mb_substr(trim($d['texto'] ?? ''), 0, self::MAX_TEXTO);
        if ($texto === '') {
            Json::erro('Escreva a ideia antes de enviar.');
        }
        $jaEnviou = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_item WHERE rodada_id = ? AND participante_token = ?',
            [(int)$r['id'], $token]
        )['n'] ?? 0);
        if ($jaEnviou >= (int)$r['max_ideias']) {
            Json::erro("Você já enviou {$r['max_ideias']} ideia(s) nesta rodada.");
        }

        $id = (int)Database::executar(
            'INSERT INTO coleta_item (planejamento_id, rodada_id, ano, autor_id, autor_nome,
               participante_token, texto, destino_sugerido)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)',
            [
                (int)$r['planejamento_id'], (int)$r['id'], (int)$r['ano'],
                $nome, $token, $texto,
                in_array($d['destino_sugerido'] ?? '', ['CENARIO', 'PESTEL', 'PORTER', 'SWOT'], true)
                    ? $d['destino_sugerido'] : 'NAO_SEI',
            ]
        );
        Json::ok(['id' => $id, 'enviadas' => $jaEnviou + 1]);
    }

    /** As próprias ideias, para o participante conferir e corrigir. */
    public function minhas(): void
    {
        $pin = (string)($_GET['pin'] ?? '');
        $r = $this->rodadaPorPin($pin);
        $token = $this->token($_GET);
        Json::ok(Database::todos(
            'SELECT id, texto, votos FROM coleta_item
             WHERE rodada_id = ? AND participante_token = ? ORDER BY id',
            [(int)$r['id'], $token]
        ));
    }

    /** Ideias abertas para votação, quando quem conduz liberar essa fase. */
    public function paraVotar(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $token = $this->token($_GET);
        if ($r['votacao'] !== 'ABERTA') {
            Json::ok(['votacao' => 'FECHADA', 'itens' => [], 'meus_votos' => 0]);
        }
        $itens = Database::todos(
            "SELECT i.id, i.texto, (v.id IS NOT NULL) AS votei
             FROM coleta_item i
             LEFT JOIN coleta_voto v ON v.item_id = i.id AND v.participante_token = ?
             WHERE i.rodada_id = ? AND i.situacao = 'NOVO'
             ORDER BY i.id",
            [$token, (int)$r['id']]
        );
        $meus = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_voto WHERE rodada_id = ? AND participante_token = ?',
            [(int)$r['id'], $token]
        )['n'] ?? 0);
        Json::ok(['votacao' => 'ABERTA', 'itens' => $itens, 'meus_votos' => $meus,
                  'max_votos' => (int)$r['max_votos']]);
    }

    /** Alterna o voto do participante numa ideia, respeitando o teto. */
    public function votar(int $id): void
    {
        $d = Json::corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $token = $this->token($d);
        if ($r['votacao'] !== 'ABERTA') {
            Json::erro('A votação não está aberta.');
        }
        $item = Database::um(
            'SELECT id FROM coleta_item WHERE id = ? AND rodada_id = ?',
            [$id, (int)$r['id']]
        );
        if (!$item) {
            Json::erro('Ideia não encontrada nesta rodada.', 404);
        }

        $removidos = Database::afetadas(
            'DELETE FROM coleta_voto WHERE item_id = ? AND participante_token = ?',
            [$id, $token]
        );
        if ($removidos) {
            Database::executar('UPDATE coleta_item SET votos = GREATEST(votos - 1, 0) WHERE id = ?', [$id]);
            Json::ok(['votou' => false]);
        }

        $meus = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_voto WHERE rodada_id = ? AND participante_token = ?',
            [(int)$r['id'], $token]
        )['n'] ?? 0);
        if ($meus >= (int)$r['max_votos']) {
            Json::erro("Você já usou seus {$r['max_votos']} voto(s). Toque de novo num que já votou para trocar.");
        }
        Database::executar(
            'INSERT INTO coleta_voto (item_id, rodada_id, participante_token) VALUES (?, ?, ?)',
            [$id, (int)$r['id'], $token]
        );
        Database::executar('UPDATE coleta_item SET votos = votos + 1 WHERE id = ?', [$id]);
        Json::ok(['votou' => true]);
    }

    private function token(array $origem): string
    {
        $token = (string)($origem['token'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            Json::erro('Entre na rodada antes de participar.', 403);
        }
        return $token;
    }

    private function rodadaPorPin(string $pin): array
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            Json::erro('PIN inválido.', 404);
        }
        $r = Database::um('SELECT * FROM coleta_rodada WHERE pin = ?', [$pin]);
        if (!$r) {
            Json::erro('Rodada não encontrada. Confira o PIN.', 404);
        }
        return $r;
    }

    private function rodadaAberta(string $pin): array
    {
        $r = $this->rodadaPorPin($pin);
        if ($r['situacao'] !== 'ABERTA') {
            Json::erro('Esta rodada já foi encerrada.');
        }
        return $r;
    }
}

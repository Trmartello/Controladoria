<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Json;
use App\Core\Versao;

/**
 * Um item por vez: o cadeado de edição.
 *
 * Enquanto alguém tem o formulário de um item aberto, ninguém mais o abre, e a
 * tela dos outros mostra o nome de quem está lá. Nasceu de uma medição: dois
 * admins no mesmo item, o segundo a salvar apagava o primeiro, e o servidor
 * respondia `ok:true` — quem foi sobrescrito não recebia sinal nenhum.
 *
 * ## Por que cadeado, e não comparação de versões
 *
 * Decisão do cliente (2026-09-01), e o argumento é o melhor da discussão: **se
 * ninguém mais pode abrir o item, não existe base de comparação a manter.** A
 * alternativa exigiria versionar cada registro, carregar a versão no formulário
 * e reconciliar dois textos na recusa. Aqui não há nada disso.
 *
 * ## Renovação MANUAL, e é isso que o torna seguro
 *
 * A primeira versão do plano renovava sozinha enquanto o formulário estivesse
 * aberto. O furo: **um batimento prova que o navegador está aberto, não que
 * existe uma pessoa ali** — uma aba esquecida numa máquina ligada renovaria
 * para sempre, que é exatamente o caso que o batimento deveria cobrir.
 *
 * Trocado por: 5 minutos, contador à vista, e um "+1 minuto" que a pessoa
 * clica. Sem teto, e sem teto é seguro justamente por ser manual — um clique é
 * prova de vida que um batimento não é.
 *
 * ## O que acontece aos 0:00
 *
 * O cadeado cai e **qualquer admin pode assumir** (decisão do cliente). Mas
 * `exigirMeu` aceita o salvamento de quem perdeu o cadeado **se ninguém o tiver
 * assumido** — ver o método. Sem isso, quem estivesse escrevendo aos 4:59
 * perderia o texto, e o recurso que existe para não perder trabalho passaria a
 * perder trabalho.
 *
 * ## Falha ABERTA
 *
 * Nada aqui pode impedir alguém de trabalhar. Um sistema de cadeados capaz de
 * travar a cooperativa inteira é pior que a sobrescrita que ele previne. Por
 * isso o `tomar()` devolve o estado em vez de lançar, e a tela decide — e
 * decide a favor de editar quando a rota falha.
 */
class Bloqueio
{
    /** Recursos que aceitam cadeado: os itens que duas pessoas disputam. */
    public const RECURSOS = ['fator', 'cascata_escolha', 'cenario_item', 'desdobramento', 'projeto'];

    /** Quanto dura ao tomar. Cinco minutos, decisão do cliente. */
    private const SEGUNDOS = 300;

    /** Quanto o "+1 minuto" acrescenta. */
    private const RENOVA = 60;

    public static function recursoValido(string $recurso): bool
    {
        return in_array($recurso, self::RECURSOS, true);
    }

    /**
     * Toma o cadeado, ou diz de quem ele é.
     *
     * A instrução é UMA só, e é isso que a torna atômica: entre "conferir se
     * está livre" e "tomar" não existe janela por onde dois cliques simultâneos
     * passem os dois. O `IF()` concede quando o cadeado expirou (qualquer admin
     * assume — decisão do cliente) ou quando já é meu (reabrir o mesmo item, ou
     * uma segunda aba da mesma pessoa, não se autobloqueia).
     *
     * @return array o estado do cadeado depois da tentativa, com `meu`
     */
    public static function tomar(string $recurso, int $id, int $planId, int $usuarioId, int $segundos = self::SEGUNDOS): array
    {
        // O cadeado não é conteúdo do plano: sem isto, cada tomada e cada
        // renovação subiriam a versão e TODAS as telas repintariam a cada 4s.
        Versao::ignorar();
        Database::executar(
            'INSERT INTO edicao_bloqueio (recurso, registro_id, planejamento_id, usuario_id, expira_em)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
               planejamento_id = IF(expira_em < NOW() OR usuario_id = VALUES(usuario_id),
                                    VALUES(planejamento_id), planejamento_id),
               usuario_id      = IF(expira_em < NOW() OR usuario_id = VALUES(usuario_id),
                                    VALUES(usuario_id), usuario_id),
               expira_em       = IF(expira_em < NOW() OR usuario_id = VALUES(usuario_id),
                                    VALUES(expira_em), expira_em)',
            [$recurso, $id, $planId, $usuarioId, $segundos]
        );
        return self::estado($recurso, $id, $usuarioId);
    }

    /**
     * O "+1 minuto".
     *
     * `GREATEST(expira_em, NOW()) + 60s` e não `NOW() + 60s`: a segunda forma
     * faria quem clicasse com 4:00 restantes **cair para 1:00** — o botão de
     * ganhar tempo tirando tempo. Assim, acrescenta sobre o que resta quando
     * ainda há tempo, e dá um minuto cheio quando já não há. Nunca reduz.
     *
     * Só renova o que é meu e ainda está de pé: renovar cadeado alheio seria
     * roubá-lo, e renovar um já expirado e assumido por outro devolveria o item
     * a quem já o perdeu.
     */
    public static function renovar(string $recurso, int $id, int $usuarioId): array
    {
        Versao::ignorar();
        Database::executar(
            'UPDATE edicao_bloqueio
             SET expira_em = GREATEST(expira_em, NOW()) + INTERVAL ? SECOND
             WHERE recurso = ? AND registro_id = ? AND usuario_id = ? AND expira_em >= NOW()',
            [self::RENOVA, $recurso, $id, $usuarioId]
        );
        return self::estado($recurso, $id, $usuarioId);
    }

    /** Solta o que é meu. Cadeado de outro é ignorado em silêncio. */
    public static function soltar(string $recurso, int $id, int $usuarioId): void
    {
        Versao::ignorar();
        Database::executar(
            'DELETE FROM edicao_bloqueio WHERE recurso = ? AND registro_id = ? AND usuario_id = ?',
            [$recurso, $id, $usuarioId]
        );
    }

    /**
     * O cadeado como está agora, do ponto de vista de quem perguntou.
     *
     * `restam` vem do SERVIDOR (`TIMESTAMPDIFF` contra `NOW()`), e não da
     * subtração de datas no navegador: duas máquinas com hora dessincronizada
     * mostrariam contagens diferentes para o mesmo cadeado, e o número que
     * decide quem pode salvar não pode depender do relógio de ninguém.
     */
    public static function estado(string $recurso, int $id, int $usuarioId): array
    {
        $l = Database::um(
            'SELECT b.usuario_id, COALESCE(u.nome, \'outro usuário\') AS usuario,
                    TIMESTAMPDIFF(SECOND, NOW(), b.expira_em) AS restam
             FROM edicao_bloqueio b
             LEFT JOIN usuario u ON u.id = b.usuario_id
             WHERE b.recurso = ? AND b.registro_id = ? AND b.expira_em >= NOW()',
            [$recurso, $id]
        );
        if (!$l) {
            return ['livre' => true, 'meu' => false, 'usuario' => null, 'restam' => 0];
        }
        return [
            'livre' => false,
            'meu' => (int)$l['usuario_id'] === $usuarioId,
            'usuario' => $l['usuario'],
            'restam' => max(0, (int)$l['restam']),
        ];
    }

    /**
     * Recusa a gravação quando o item é de outro — a metade da trava que mora
     * no servidor.
     *
     * Sem ela o cadeado seria teatro: uma tela aberta ANTES de o cadeado
     * existir não sabe dele e salvaria assim mesmo.
     *
     * **Aceita quem perdeu o cadeado, desde que ninguém o tenha assumido.** É a
     * ressalva que impede o recurso de destruir trabalho: aos 0:00 o item volta
     * a ficar disponível (regra do cliente), mas o texto continua na tela de
     * quem escrevia, e o salvamento dele passa se o item seguiu livre. Só é
     * recusado quando outra pessoa realmente tomou o lugar — o único caso em
     * que perder faz sentido. De quebra some a corrida do "cliquei em Salvar
     * aos 0:02 e a requisição levou um segundo".
     */
    public static function exigirMeu(string $recurso, int $id, int $usuarioId, string $oQue = 'este item'): void
    {
        $b = self::estado($recurso, $id, $usuarioId);
        if ($b['livre'] || $b['meu']) {
            return;
        }
        Json::erro(
            "{$b['usuario']} está editando {$oQue} agora. Sua alteração não foi gravada — "
            . 'copie o que você escreveu antes de fechar e tente de novo quando a edição terminar.',
            409
        );
    }

    /**
     * Os cadeados vivos de um ciclo, para o pulso.
     *
     * Vai junto com as versões porque é a MESMA pergunta ("o que está
     * acontecendo agora?") e o mesmo relógio de 4s. Uma rota própria dobraria o
     * tráfego da tela mais movimentada do sistema para responder metade do que
     * ela precisa saber.
     *
     * Expirado não entra: cadeado vencido é cadeado que não existe, e deixá-lo
     * na lista faria a tela mostrar "Maria está editando" sobre um item que
     * qualquer um já pode abrir.
     */
    public static function doCiclo(int $cicloId, int $usuarioId): array
    {
        $linhas = Database::todos(
            'SELECT b.recurso, b.registro_id, b.usuario_id,
                    COALESCE(u.nome, \'outro usuário\') AS usuario,
                    TIMESTAMPDIFF(SECOND, NOW(), b.expira_em) AS restam
             FROM edicao_bloqueio b
             JOIN planejamento p ON p.id = b.planejamento_id
             LEFT JOIN usuario u ON u.id = b.usuario_id
             WHERE p.ciclo_id = ? AND b.expira_em >= NOW()
             ORDER BY b.recurso, b.registro_id',
            [$cicloId]
        );
        return array_map(static fn($l) => [
            'recurso' => $l['recurso'],
            'registro_id' => (int)$l['registro_id'],
            'usuario' => $l['usuario'],
            'meu' => (int)$l['usuario_id'] === $usuarioId,
            'restam' => max(0, (int)$l['restam']),
        ], $linhas);
    }

    /**
     * Faxina dos cadeados vencidos. Chamada no `migrate`, junto com a das
     * sessões: a tabela só cresce se ninguém varrer, e um cadeado vencido nunca
     * mais é lido — as consultas todas filtram por `expira_em >= NOW()`.
     */
    public static function faxina(): int
    {
        return (int)Database::afetadas(
            'DELETE FROM edicao_bloqueio WHERE expira_em < (NOW() - INTERVAL 1 DAY)'
        );
    }
}

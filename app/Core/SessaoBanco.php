<?php

namespace App\Core;

/**
 * Sessões persistidas no MySQL: o container do Railway é efêmero e as sessões
 * em arquivo morrem a cada deploy — no banco, o login sobrevive a deploys e
 * reinícios. A expiração usa session.gc_maxlifetime (30 dias no index.php).
 */
class SessaoBanco implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /**
     * `session.use_strict_mode` só recusa id inventado pelo cliente quando o
     * handler diz se o id EXISTE. Sem este método, o PHP aceitava qualquer
     * `PHPSESSID` do cookie e o `write()` o materializava na tabela — um
     * visitante anônimo em /login enchia `sessao` com ids à escolha dele.
     */
    public function validateId(string $id): bool
    {
        $vida = (int)ini_get('session.gc_maxlifetime') ?: 1440;
        return (bool)Database::um(
            'SELECT 1 FROM sessao WHERE id = ? AND atualizado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$id, $vida]
        );
    }

    public function updateTimestamp(string $id, string $dados): bool
    {
        return $this->write($id, $dados);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * A validade é conferida AQUI, não só no gc(). O coletor do PHP roda por
     * probabilidade (session.gc_probability) e há ambientes em que ela é zero —
     * lá o gc nunca é chamado e a sessão não expirava nunca, por mais antiga
     * que fosse. Com a checagem na leitura, a expiração não depende de sorte.
     */
    public function read(string $id): string
    {
        $vida = (int)ini_get('session.gc_maxlifetime') ?: 1440;
        $linha = Database::um(
            'SELECT dados FROM sessao WHERE id = ? AND atualizado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$id, $vida]
        );
        return $linha ? (string)$linha['dados'] : '';
    }

    public function write(string $id, string $dados): bool
    {
        Database::executar(
            'INSERT INTO sessao (id, dados) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE dados = VALUES(dados), atualizado_em = NOW()',
            [$id, $dados]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::executar('DELETE FROM sessao WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        Database::executar(
            'DELETE FROM sessao WHERE atualizado_em < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$max_lifetime]
        );
        return 0;
    }
}

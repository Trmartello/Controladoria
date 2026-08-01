<?php

namespace App\Core;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $db = $GLOBALS['config']['db'];
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** SELECT retornando todas as linhas. */
    public static function todos(string $sql, array $params = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** SELECT retornando uma linha (ou null). */
    public static function um(string $sql, array $params = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $linha = $stmt->fetch();
        return $linha === false ? null : $linha;
    }

    /** INSERT/UPDATE/DELETE; retorna o último id inserido. */
    public static function executar(string $sql, array $params = []): string
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return self::conn()->lastInsertId();
    }

    /**
     * UPDATE/DELETE devolvendo quantas linhas mudaram. Serve para reserva
     * atômica: um UPDATE com a condição no WHERE só afeta uma linha na
     * primeira vez, então o segundo clique não repete o efeito.
     */
    public static function afetadas(string $sql, array $params = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

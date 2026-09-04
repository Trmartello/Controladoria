<?php

namespace App\Core;

use PDO;

// `executar`/`afetadas` marcam o pulso em `Versao`. Fora do front controller
// (as CLIs: notificar, senha, backup remoto) não há autoloader, e a primeira
// escrita morria com "Class App\Core\Versao not found" — o cron de avisos
// mandava o primeiro e-mail e caía antes de registrar o envio, repetindo-o
// todo dia. Quem usa Database carrega Versao junto, sem depender de lembrar.
require_once __DIR__ . '/Versao.php';

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
                // CURDATE()/NOW() do banco precisam concordar com o date() do
                // PHP: os dois decidem atraso e recorrência. Vai o deslocamento
                // (-03:00), não o nome da zona — nome exige as tabelas de fuso
                // carregadas no MySQL, o que nem sempre acontece.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'",
                // Banco mudo (sem recusar a conexão) prendia um trabalhador do
                // php -S pelo timeout de TCP do sistema, travando a aplicação
                PDO::ATTR_TIMEOUT            => 5,
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
        // Metade da marcação do pulso (`App\Core\Versao`): este é o único
        // caminho de escrita do sistema, e marcar aqui é o que dispensa lembrar
        // de marcar em cada endpoint. A outra metade — QUAL planejamento — vem
        // do portão de autorização.
        Versao::marcarEscrita();
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
        // Também é escrita: a conclusão pela barra de progresso e o
        // reagendamento da recorrente passam SÓ por aqui, e sem a marca a
        // outra tela não acompanhava até um F5.
        if ($stmt->rowCount() > 0) {
            Versao::marcarEscrita();
        }
        return $stmt->rowCount();
    }
}

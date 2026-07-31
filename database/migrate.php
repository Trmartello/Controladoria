<?php
// Migração idempotente: aplica schema + seeds e garante o usuário admin.
// Roda no start do container (entrypoint) e pode ser executada manualmente.

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];
$dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

$pdo = null;
for ($tentativa = 1; $tentativa <= 30; $tentativa++) {
    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        fwrite(STDERR, "migrate: aguardando banco ({$tentativa}/30): {$e->getMessage()}\n");
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "migrate: banco indisponível, abortando.\n");
    exit(1);
}

function executarArquivoSql(PDO $pdo, string $caminho): void
{
    $sql = file_get_contents($caminho);
    // Remove linhas de comentário e divide por ';' no fim de linha
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $comando) {
        $comando = trim($comando);
        if ($comando !== '') {
            $pdo->exec($comando);
        }
    }
}

executarArquivoSql($pdo, __DIR__ . '/schema.sql');
executarArquivoSql($pdo, __DIR__ . '/seeds.sql');

// Usuário admin inicial (senha via env ADMIN_SENHA; trocar após o 1º login)
$existe = $pdo->query("SELECT COUNT(*) FROM usuario WHERE perfil = 'ADMIN'")->fetchColumn();
if ((int)$existe === 0) {
    $stmt = $pdo->prepare(
        "INSERT INTO usuario (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'ADMIN')"
    );
    $stmt->execute([
        'Administrador',
        $config['app']['admin_email'],
        password_hash($config['app']['admin_senha'], PASSWORD_DEFAULT),
    ]);
    echo "migrate: usuário admin criado ({$config['app']['admin_email']}).\n";
}

echo "migrate: ok.\n";

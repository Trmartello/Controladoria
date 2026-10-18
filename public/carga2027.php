<?php
/**
 * Script único para recarregar os dados de diagnóstico de 2027
 * (Planejamento ID 1) que foram perdidos após o reset do banco.
 *
 * Uso: /carga2027.php?token=load2027nowplease
 */

$expectedToken = 'load2027nowplease';
$token = isset($_GET['token']) ? $_GET['token'] : null;

if ($token === null || $token !== $expectedToken) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Unauthorized";
    exit;
}

$baseDir = dirname(__DIR__);

$commands = [
    'Cenario 2027' => 'php cli/carga_diagnostico.php cenario2027 1 2027 --aplicar',
    'PESTEL 2027'  => 'php cli/carga_diagnostico.php pestel2027 1 2027 --aplicar',
    'Porter 2027'  => 'php cli/carga_diagnostico.php porter2027 1 2027 --aplicar',
    'SWOT 2027'    => 'php cli/carga_diagnostico.php swot2027 1 2027 --aplicar',
    'Cascata 2027' => 'php cli/carga_diagnostico.php cascata2027 1 --aplicar',
];

$results = [];

foreach ($commands as $label => $command) {
    $fullCommand = 'cd ' . escapeshellarg($baseDir) . ' && ' . $command . ' 2>&1';
    $output = shell_exec($fullCommand);

    $success = $output !== null && stripos($output, 'error') === false && stripos($output, 'exception') === false;

    $results[] = [
        'label'   => $label,
        'command' => $command,
        'output'  => $output === null ? '(sem saída)' : $output,
        'success' => $success,
    ];
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Carga Diagnóstico 2027</title>
</head>
<body>
    <h1>Carga de Diagnóstico 2027 - Planejamento ID 1</h1>
    <?php foreach ($results as $result): ?>
        <h2>
            <?php echo $result['success'] ? '✓' : '✗'; ?>
            <?php echo htmlspecialchars($result['label']); ?>
        </h2>
        <p><strong>Comando:</strong> <code><?php echo htmlspecialchars($result['command']); ?></code></p>
        <pre><?php echo htmlspecialchars($result['output']); ?></pre>
        <hr>
    <?php endforeach; ?>
</body>
</html>

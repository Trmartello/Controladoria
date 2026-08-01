<?php

/**
 * Disparo dos avisos por e-mail do plano de ação.
 *
 *   php cli/notificar.php            # decide pelo dia (semanal na segunda + diário)
 *   php cli/notificar.php semanal    # força o relatório da semana
 *   php cli/notificar.php diario     # força só as pendências do dia
 *   php cli/notificar.php auto 2027-03-01   # simula a execução de outra data
 *
 * Agende uma execução diária (ver docs/DEPLOY-RAILWAY.md). Reexecutar no mesmo
 * dia é seguro: envio_email guarda o que já saiu e nada é repetido.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';
require __DIR__ . '/../app/Core/Email.php';
require __DIR__ . '/../app/Services/Avisos.php';

use App\Core\Email;
use App\Services\Avisos;

$tipo = $argv[1] ?? 'auto';
$data = $argv[2] ?? null;

if (!in_array($tipo, ['auto', 'semanal', 'diario'], true)) {
    fwrite(STDERR, "Uso: php cli/notificar.php [auto|semanal|diario] [AAAA-MM-DD]\n");
    exit(2);
}

if (!Email::configurado()) {
    fwrite(STDERR, "notificar: SMTP não configurado (defina SMTP_HOST e SMTP_REMETENTE). Nada enviado.\n");
    exit(1);
}

try {
    $resultado = Avisos::despachar($tipo, $data);
} catch (\Throwable $e) {
    fwrite(STDERR, 'notificar: falhou — ' . $e->getMessage() . "\n");
    exit(1);
}

$falhas = 0;
foreach ($resultado as $qual => $r) {
    $falhas += $r['falhas'];
    echo "notificar[{$qual}]: {$r['enviados']} enviado(s), {$r['falhas']} falha(s), "
        . "{$r['ja_enviados']} já enviado(s) hoje, {$r['sem_itens']} sem pendência.\n";
    foreach ($r['detalhes'] as $d) {
        if ($d['erro']) {
            echo "  - {$d['usuario']}: ERRO {$d['erro']}\n";
        }
    }
}
if (!$resultado) {
    echo "notificar: nada previsto para hoje.\n";
}
exit($falhas > 0 ? 1 : 0);

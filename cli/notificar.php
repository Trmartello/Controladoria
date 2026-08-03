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

/**
 * Faxina das tabelas que só crescem.
 *
 * O coletor de sessão do PHP depende de session.gc_probability, que em alguns
 * ambientes é zero — ali ele nunca roda e a tabela `sessao` cresce para sempre,
 * alimentada até por visita anônima ao /login (o formulário precisa do token
 * CSRF). O migrate faz o mesmo a cada deploy; aqui a limpeza acontece mesmo
 * sem deploy nenhum. Roda ANTES da checagem de SMTP: é higiene do banco, não
 * parte do envio, e a instalação sem e-mail configurado também acumula lixo.
 */
function faxina(): void
{
    try {
        $limpas = App\Core\Database::afetadas(
            'DELETE FROM sessao WHERE atualizado_em < (NOW() - INTERVAL 30 DAY)'
        );
        $limpas += App\Core\Database::afetadas(
            'DELETE FROM coleta_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)'
        );
        $limpas += App\Core\Database::afetadas(
            'DELETE FROM login_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)'
        );
        if ($limpas) {
            echo "notificar: faxina — {$limpas} linha(s) expirada(s) removida(s).\n";
        }
    } catch (\Throwable $e) {
        // Higiene não pode derrubar a tarefa principal
        fwrite(STDERR, 'notificar: faxina falhou — ' . $e->getMessage() . "\n");
    }
}

faxina();

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

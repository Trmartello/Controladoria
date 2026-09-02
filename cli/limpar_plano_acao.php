<?php

/**
 * Zera o plano de ação: apaga projetos, iniciativas e ações cadastrados,
 * mantendo o banco, as telas e todo o resto do planejamento.
 *
 *   php cli/limpar_plano_acao.php                 # só CONTA o que sairia e o que fica
 *   php cli/limpar_plano_acao.php apagar          # faz backup e apaga, pedindo confirmação
 *   php cli/limpar_plano_acao.php apagar --confirmo=APAGAR-PLANO-DE-ACAO
 *                                                 # sem pergunta (one-off command, sem terminal)
 *
 * Opções:
 *   --planejamento=ID   só o plano de ação de um planejamento (padrão: todos)
 *   --sem-backup        não gera o backup antes (SÓ para a bateria de testes)
 *
 * Pedido do cliente (2026-09-02): recomeçar o cadastro de ações, projetos e
 * iniciativas do zero. É o único comando destrutivo em massa do sistema, e
 * por isso ele (1) conta e mostra antes, (2) gera um backup pelo
 * cli/backup.sh e aborta se o backup falhar, (3) exige a confirmação por
 * extenso, e (4) roda numa transação: ou apaga tudo, ou nada.
 *
 * O que sai: projeto, iniciativa, desdobramento (a ação), os comentários e
 * anexos deles, e os cadeados de edição abertos sobre eles.
 * O que fica, e como fica: o fator, o item de cenário e o cruzamento que
 * tinham virado ação voltam à fila de "aguardando plano de ação" (a FK de
 * `desdobramento_id` é SET NULL e a marca `acao_em` continua); a ideia da
 * Coleta encaminhada ao plano fica ACEITA, "aguardando plano de ação"; o
 * investimento vinculado a um projeto perde o vínculo e continua cadastrado.
 * É a MESMA contabilidade que ProjetoController::excluir faz um a um.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';
// Database::executar marca o pulso em App\Core\Versao; sem a classe carregada,
// a primeira escrita fora do front controller morre com "Class not found".
require __DIR__ . '/../app/Core/Versao.php';

use App\Core\Database;

const CONFIRMACAO = 'APAGAR-PLANO-DE-ACAO';

$acao = 'contar';
$planId = null;
$semBackup = false;
$confirmo = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === 'contar' || $arg === 'apagar') {
        $acao = $arg;
    } elseif (preg_match('/^--planejamento=(\d+)$/', $arg, $m)) {
        $planId = (int)$m[1];
    } elseif ($arg === '--sem-backup') {
        $semBackup = true;
    } elseif (preg_match('/^--confirmo=(.*)$/', $arg, $m)) {
        $confirmo = $m[1];
    } else {
        fwrite(STDERR, "Argumento desconhecido: {$arg}\n"
            . "Uso: php cli/limpar_plano_acao.php [contar|apagar] [--planejamento=ID] [--confirmo=" . CONFIRMACAO . "] [--sem-backup]\n");
        exit(2);
    }
}

// Todo filtro deriva dos PROJETOS: iniciativa e ação pertencem a um projeto,
// e o projeto pertence a um planejamento.
$ondeProj = $planId ? 'WHERE planejamento_id = ?' : '';
$pp = $planId ? [$planId] : [];
$projetos = "(SELECT id FROM projeto {$ondeProj})";
$acoes = "(SELECT id FROM desdobramento WHERE projeto_id IN {$projetos})";

if ($planId) {
    if (!Database::um('SELECT id FROM planejamento WHERE id = ?', [$planId])) {
        fwrite(STDERR, "Planejamento {$planId} não existe.\n");
        exit(2);
    }
    echo "Planejamento #{$planId}.\n";
} else {
    echo "Todos os planejamentos.\n";
}

$n = static fn(string $sql, array $params = []) => (int)(Database::um($sql, $params)['n'] ?? 0);
$contagem = [
    'projetos'      => $n("SELECT COUNT(*) AS n FROM projeto {$ondeProj}", $pp),
    'iniciativas'   => $n("SELECT COUNT(*) AS n FROM iniciativa WHERE projeto_id IN {$projetos}", $pp),
    'acoes'         => $n("SELECT COUNT(*) AS n FROM desdobramento WHERE projeto_id IN {$projetos}", $pp),
    'comentarios'   => $n("SELECT COUNT(*) AS n FROM comentario
                            WHERE (ref_tipo = 'PROJETO' AND ref_id IN {$projetos})
                               OR (ref_tipo = 'DESDOBRAMENTO' AND ref_id IN {$acoes})", array_merge($pp, $pp)),
    'cadeados'      => $n("SELECT COUNT(*) AS n FROM edicao_bloqueio
                            WHERE (recurso = 'projeto' AND registro_id IN {$projetos})
                               OR (recurso = 'desdobramento' AND registro_id IN {$acoes})", array_merge($pp, $pp)),
    // O que FICA, e muda de estado
    'fatores_fila'  => $n("SELECT COUNT(*) AS n FROM fator WHERE desdobramento_id IN {$acoes}", $pp),
    'cenario_fila'  => $n("SELECT COUNT(*) AS n FROM cenario_item WHERE desdobramento_id IN {$acoes}", $pp),
    'cruz_fila'     => $n("SELECT COUNT(*) AS n FROM swot_cruzamento WHERE desdobramento_id IN {$acoes}", $pp),
    'ideias'        => $n("SELECT COUNT(*) AS n FROM coleta_item WHERE destino_tipo = 'ACAO' AND destino_id IN {$acoes}", $pp),
    'investimentos' => $n("SELECT COUNT(*) AS n FROM investimento WHERE projeto_id IN {$projetos}", $pp),
];

echo "\nSAI (apagado de vez):\n";
printf("  %6d projeto(s)\n  %6d iniciativa(s)\n  %6d ação(ões)\n  %6d comentário(s) deles, com anexos\n  %6d cadeado(s) de edição\n",
    $contagem['projetos'], $contagem['iniciativas'], $contagem['acoes'], $contagem['comentarios'], $contagem['cadeados']);
echo "\nFICA (só muda de estado):\n";
printf("  %6d fator(es), %d item(ns) de cenário e %d cruzamento(s) voltam à fila de aguardando plano de ação\n",
    $contagem['fatores_fila'], $contagem['cenario_fila'], $contagem['cruz_fila']);
printf("  %6d ideia(s) da Coleta ficam aceitas, aguardando plano de ação\n", $contagem['ideias']);
printf("  %6d investimento(s) perdem o vínculo com projeto e continuam cadastrados\n", $contagem['investimentos']);

if ($acao === 'contar') {
    echo "\nNada foi apagado. Para apagar: php cli/limpar_plano_acao.php apagar\n";
    exit(0);
}

if ($contagem['projetos'] === 0 && $contagem['acoes'] === 0) {
    echo "\nNão há plano de ação para apagar.\n";
    exit(0);
}

// Confirmação por extenso: com terminal, digitada; sem terminal (one-off
// command do Railway), pela opção --confirmo — nunca por padrão.
if ($confirmo !== CONFIRMACAO) {
    if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
        fwrite(STDERR, "\nSem terminal para confirmar. Repita com --confirmo=" . CONFIRMACAO . "\n");
        exit(3);
    }
    echo "\nIsto NÃO tem desfazer (a não ser pelo backup). Digite " . CONFIRMACAO . " para continuar: ";
    $digitado = trim((string)fgets(STDIN));
    if ($digitado !== CONFIRMACAO) {
        echo "Cancelado: nada foi apagado.\n";
        exit(3);
    }
}

if (!$semBackup) {
    echo "\nGerando backup antes de apagar (cli/backup.sh)...\n";
    passthru('bash ' . escapeshellarg(__DIR__ . '/backup.sh'), $codigo);
    if ($codigo !== 0) {
        fwrite(STDERR, "\nBackup falhou (código {$codigo}): nada foi apagado. Resolva o backup antes.\n");
        exit(4);
    }
}

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    // Par polimórfico não tem FK: comentários (anexos caem por CASCADE) e a
    // ideia da Coleta são soltos à mão, como em ProjetoController::soltarAcoes.
    // A derivada `(SELECT ... FROM (...) x)` é o contorno para o MySQL aceitar
    // subconsulta na tabela que está sendo alterada.
    $acoesX = "(SELECT id FROM (SELECT id FROM desdobramento WHERE projeto_id IN {$projetos}) x)";
    $projetosX = "(SELECT id FROM (SELECT id FROM projeto {$ondeProj}) y)";
    Database::executar("DELETE FROM comentario WHERE ref_tipo = 'DESDOBRAMENTO' AND ref_id IN {$acoesX}", $pp);
    Database::executar("DELETE FROM comentario WHERE ref_tipo = 'PROJETO' AND ref_id IN {$projetosX}", $pp);
    Database::executar("UPDATE coleta_item SET destino_id = NULL WHERE destino_tipo = 'ACAO' AND destino_id IN {$acoesX}", $pp);
    // Investimento → projeto: FK sem ON DELETE, então o vínculo sai antes.
    Database::executar("UPDATE investimento SET projeto_id = NULL WHERE projeto_id IN {$projetosX}", $pp);
    // Cadeados de edição abertos sobre o que vai sumir.
    Database::executar("DELETE FROM edicao_bloqueio WHERE recurso = 'desdobramento' AND registro_id IN {$acoesX}", $pp);
    Database::executar("DELETE FROM edicao_bloqueio WHERE recurso = 'projeto' AND registro_id IN {$projetosX}", $pp);
    // fator, cenario_item e swot_cruzamento: FK ON DELETE SET NULL cuida.
    $apagadas = Database::afetadas("DELETE FROM desdobramento WHERE projeto_id IN {$projetosX}", $pp);
    $apagadasIni = Database::afetadas("DELETE FROM iniciativa WHERE projeto_id IN {$projetosX}", $pp);
    $apagadosProj = Database::afetadas("DELETE FROM projeto {$ondeProj}", $pp);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nFalhou no meio e NADA foi apagado (transação desfeita): " . $e->getMessage() . "\n");
    exit(1);
}

printf("\nApagado: %d projeto(s), %d iniciativa(s), %d ação(ões).\n", $apagadosProj, $apagadasIni, $apagadas);
$restam = $n("SELECT COUNT(*) AS n FROM desdobramento WHERE projeto_id IN {$projetos}", $pp)
    + $n("SELECT COUNT(*) AS n FROM projeto {$ondeProj}", $pp);
echo $restam === 0 ? "Plano de ação zerado.\n" : "ATENÇÃO: ainda restam {$restam} registro(s).\n";
exit($restam === 0 ? 0 : 1);

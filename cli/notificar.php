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

if (!in_array($tipo, ['auto', 'semanal', 'diario', 'diagnostico'], true)) {
    fwrite(STDERR, "Uso: php cli/notificar.php [auto|semanal|diario|diagnostico] [AAAA-MM-DD]\n");
    exit(2);
}

/**
 * Por que o e-mail não sai — respondido de dentro do contêiner que envia.
 *
 * Existe porque "Connection timed out" tem três causas que exigem providências
 * opostas e a mensagem não as separa: par porta×segurança trocado (arruma-se
 * numa variável), senha colada com os espaços que o Google mostra (idem), e
 * saída SMTP bloqueada pelo provedor de hospedagem (nenhuma variável resolve —
 * troca-se de caminho). Sem isto, a depuração vira tentativa e erro a cada
 * deploy, e cada tentativa custa um reinício do sistema.
 *
 * A senha NUNCA é impressa: sai só se está definida e quantos caracteres tem,
 * que é o suficiente para flagrar o erro mais comum sem vazar credencial em
 * log de provedor.
 */
function diagnostico(): int
{
    $c = Email::config();
    $porta = (int)$c['porta'];
    $seg = $c['seguranca'];
    $senha = (string)($c['senha'] ?? '');
    $cfg = $GLOBALS['config'] ?? [];

    $porApi = Email::porApi();

    echo "notificar: diagnóstico do envio de e-mail\n\n";
    echo 'Caminho de envio: ', $porApi ? "API sobre HTTPS (o SMTP é ignorado)\n" : "SMTP\n";
    echo "\nConfiguração\n";
    foreach ([
        'SMTP_HOST' => $c['host'], 'SMTP_PORTA' => $c['porta'], 'SMTP_SEGURANCA' => $seg,
        'SMTP_USUARIO' => $c['usuario'], 'SMTP_REMETENTE' => $c['remetente'],
        'SMTP_NOME_REMETENTE' => $c['nome_remetente'], 'APP_URL' => $cfg['app_url'] ?? '',
        'EMAIL_API_URL' => $c['api_url'] ?? '',
    ] as $chave => $valor) {
        printf("  %-20s %s\n", $chave, ($valor ?? '') === '' ? '(vazia)' : $valor);
    }
    printf("  %-20s %s\n", 'SMTP_SENHA', $senha === ''
        ? '(vazia)'
        : sprintf('definida, %d caractere(s)', strlen($senha)));
    $chaveApi = (string)($c['api_chave'] ?? '');
    printf("  %-20s %s\n", 'EMAIL_API_CHAVE', $chaveApi === ''
        ? '(vazia)'
        : sprintf('definida, %d caractere(s)', strlen($chaveApi)));

    $avisos = [];
    if (!Email::configurado()) {
        $avisos[] = 'falta SMTP_REMETENTE, ou falta SMTP_HOST/EMAIL_API_CHAVE — sem isso nada é enviado.';
    }
    if ($porApi && $chaveApi !== '' && trim($chaveApi) !== $chaveApi) {
        $avisos[] = 'EMAIL_API_CHAVE tem espaço no começo ou no fim.';
    }

    // Com a API no comando, testar porta de e-mail seria diagnosticar o caminho
    // que o sistema não usa — e o veredito "SMTP bloqueado" mandaria consertar
    // o que já foi contornado.
    if ($porApi) {
        $alvo = parse_url((string)$c['api_url'], PHP_URL_HOST) ?: '';
        echo "\nSaída de rede (8s de espera cada)\n";
        $t0 = microtime(true);
        $s = $alvo ? @stream_socket_client("ssl://{$alvo}:443", $n, $m, 8) : false;
        printf("  %-4s %-5d %-34s (%.1fs)\n", 'ssl', 443, $s ? "ABERTO ({$alvo})" : "FECHADO: $m",
            microtime(true) - $t0);
        if ($s) {
            fclose($s);
        }
        echo "\nVeredito\n";
        if ($s) {
            echo "  O serviço de e-mail está alcançável. Se ainda falha, o motivo vem\n"
               . "  dele (chave recusada, remetente não verificado): dispare pelo botão\n"
               . "  do Relatório de Status, que mostra a resposta do serviço.\n";
        } else {
            echo "  Não há saída para {$alvo} na porta 443. Confira EMAIL_API_URL.\n";
        }
        if ($avisos) {
            echo "\nAvisos\n";
            foreach ($avisos as $a3) {
                echo "  ! {$a3}\n";
            }
        }
        return $avisos || !$s ? 1 : 0;
    }
    // O par porta×segurança é a troca mais fácil de fazer e a mais difícil de
    // ver: as duas combinações erradas dão o MESMO "timed out" da porta fechada.
    if ($porta === 587 && $seg !== 'tls') {
        $avisos[] = "a porta 587 começa em claro e sobe para cifrado: use SMTP_SEGURANCA=tls (está '{$seg}').";
    }
    if ($porta === 465 && $seg !== 'ssl') {
        $avisos[] = "a porta 465 é cifrada desde o primeiro byte: use SMTP_SEGURANCA=ssl (está '{$seg}').";
    }
    // A senha de aplicativo do Google é mostrada em quatro blocos de quatro; o
    // que vai na variável são os 16 caracteres emendados.
    if ($senha !== '' && preg_match('/\s/', $senha)) {
        $avisos[] = 'SMTP_SENHA tem espaço em branco — a senha de aplicativo vai emendada, sem os espaços.';
    }
    if ($senha !== '' && trim($senha) !== $senha) {
        $avisos[] = 'SMTP_SENHA tem espaço no começo ou no fim.';
    }

    echo "\nDNS de {$c['host']}\n";
    $a = $c['host'] ? (@gethostbynamel($c['host']) ?: []) : [];
    printf("  %-20s %s\n", 'IPv4', $a ? implode(', ', $a) : '(nenhum — o nome não resolve)');
    if (!$a && $c['host']) {
        $avisos[] = "o nome {$c['host']} não resolve: confira SMTP_HOST.";
    }

    echo "\nSaída de rede (8s de espera cada)\n";
    $testar = static function (string $proto, int $p, string $host): bool {
        $t0 = microtime(true);
        $s = @stream_socket_client("$proto://$host:$p", $n, $m, 8);
        printf("  %-4s %-5d %-34s (%.1fs)\n", $proto, $p, $s ? 'ABERTO' : "FECHADO: $m", microtime(true) - $t0);
        if ($s) {
            fclose($s);
        }
        return (bool)$s;
    };
    $aberta = false;
    if ($c['host']) {
        $protoAtual = $seg === 'ssl' ? 'ssl' : 'tcp';
        $aberta = $testar($protoAtual, $porta ?: 587, $c['host']);
        // As alternativas, para separar "esta porta" de "toda porta de e-mail".
        // O par testado é (protocolo, porta), não a porta sozinha: com 587 em
        // `ssl` — o engano mais comum —, pular "a porta já testada" deixava o
        // 587 em `tcp`, que é o par certo, sem teste nenhum, e o veredito
        // acusava bloqueio do provedor onde havia só variável trocada.
        foreach ([['tcp', 587], ['ssl', 465], ['tcp', 2525]] as [$pr, $pt]) {
            if ($pr !== $protoAtual || $pt !== $porta) {
                $aberta = $testar($pr, $pt, $c['host']) || $aberta;
            }
        }
    }
    // A referência: sem ela, "tudo fechado" poderia ser o contêiner sem rede.
    $web = $testar('tcp', 443, 'www.google.com');

    echo "\nVeredito\n";
    if (!$aberta && $web) {
        echo "  A saída para a internet funciona (443 abriu), mas NENHUMA porta de\n"
           . "  e-mail abriu. Isso é bloqueio de SMTP do provedor de hospedagem, e\n"
           . "  nenhuma variável resolve: o caminho é enviar por API (HTTPS) em vez\n"
           . "  de SMTP. Ver docs/DEPLOY-RAILWAY.md, seção 6.\n";
    } elseif (!$web) {
        echo "  Nem a porta 443 abriu: o contêiner está sem saída para a internet.\n"
           . "  Não é assunto de configuração de e-mail.\n";
    } elseif ($avisos) {
        echo "  A rede permite sair. O que impede o envio está nos avisos abaixo.\n";
    } else {
        echo "  Rede e configuração parecem em ordem — se ainda falha, o motivo vem\n"
           . "  do servidor de e-mail (senha recusada, remetente não autorizado).\n"
           . "  Dispare pelo botão do Relatório de Status: ele mostra a resposta.\n";
    }
    if ($avisos) {
        echo "\nAvisos\n";
        foreach ($avisos as $a2) {
            echo "  ! {$a2}\n";
        }
    }
    return $avisos || !$aberta ? 1 : 0;
}

if ($tipo === 'diagnostico') {
    exit(diagnostico());
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

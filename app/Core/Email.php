<?php

namespace App\Core;

/**
 * Cliente SMTP mínimo (sem dependências externas — o projeto não usa Composer).
 * Fala o suficiente do protocolo para enviar um e-mail HTML em UTF-8:
 * EHLO, STARTTLS opcional, AUTH LOGIN, MAIL FROM/RCPT TO/DATA.
 */
class Email
{
    private const TEMPO_LIMITE = 20;

    /** Configuração ausente = envio desligado (o sistema segue funcionando). */
    public static function configurado(): bool
    {
        $c = self::config();
        return $c['host'] !== null && $c['remetente'] !== null;
    }

    public static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $app = $GLOBALS['config'] ?? (require dirname(__DIR__, 2) . '/config/config.php');
            $cfg = $app['smtp'];
        }
        return $cfg;
    }

    /**
     * Envia um e-mail HTML. Lança RuntimeException com a resposta do servidor
     * quando o envio falha — quem chama decide se registra ou interrompe.
     */
    public static function enviar(string $para, string $assunto, string $html): void
    {
        $c = self::config();
        if (!self::configurado()) {
            throw new \RuntimeException('SMTP não configurado (defina SMTP_HOST e SMTP_REMETENTE).');
        }

        $porta = (int)$c['porta'];
        $endereco = $c['seguranca'] === 'ssl'
            ? "ssl://{$c['host']}:{$porta}"
            : "tcp://{$c['host']}:{$porta}";

        $conexao = @stream_socket_client(
            $endereco,
            $erroNum,
            $erroMsg,
            self::TEMPO_LIMITE,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['SNI_enabled' => true]])
        );
        if (!$conexao) {
            throw new \RuntimeException("Não foi possível conectar em {$c['host']}:{$porta} — {$erroMsg}");
        }
        stream_set_timeout($conexao, self::TEMPO_LIMITE);

        try {
            self::ler($conexao, 220);
            $dominio = $c['dominio'] ?: 'localhost';
            self::comando($conexao, "EHLO {$dominio}", 250);

            if ($c['seguranca'] === 'tls') {
                self::comando($conexao, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Falha ao iniciar TLS com o servidor SMTP.');
                }
                self::comando($conexao, "EHLO {$dominio}", 250);
            }

            if ($c['usuario'] !== null) {
                self::comando($conexao, 'AUTH LOGIN', 334);
                self::comando($conexao, base64_encode($c['usuario']), 334);
                self::comando($conexao, base64_encode((string)$c['senha']), 235);
            }

            self::comando($conexao, 'MAIL FROM:<' . $c['remetente'] . '>', 250);
            self::comando($conexao, "RCPT TO:<{$para}>", 250);
            self::comando($conexao, 'DATA', 354);
            self::escrever($conexao, self::mensagem($para, $assunto, $html) . "\r\n.");
            self::ler($conexao, 250);
            self::comando($conexao, 'QUIT', 221);
        } finally {
            fclose($conexao);
        }
    }

    /** Cabeçalhos + corpo, com assunto codificado e linhas normalizadas. */
    private static function mensagem(string $para, string $assunto, string $html): string
    {
        $c = self::config();
        $de = $c['nome_remetente']
            ? '=?UTF-8?B?' . base64_encode($c['nome_remetente']) . "?= <{$c['remetente']}>"
            : $c['remetente'];

        $cabecalhos = [
            'Date: ' . date('r'),
            'From: ' . $de,
            'To: ' . $para,
            'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // base64 evita qualquer problema com acentos e com linhas longas
        $corpo = chunk_split(base64_encode($html), 76, "\r\n");
        return implode("\r\n", $cabecalhos) . "\r\n\r\n" . rtrim($corpo, "\r\n");
    }

    private static function comando($conexao, string $linha, int $esperado): string
    {
        self::escrever($conexao, $linha);
        return self::ler($conexao, $esperado);
    }

    private static function escrever($conexao, string $linha): void
    {
        fwrite($conexao, $linha . "\r\n");
    }

    /** Lê a resposta (inclusive multilinha) e confere o código esperado. */
    private static function ler($conexao, int $esperado): string
    {
        $resposta = '';
        while (($linha = fgets($conexao, 1024)) !== false) {
            $resposta .= $linha;
            // Multilinha traz '-' na quarta posição; a última traz espaço
            if (strlen($linha) < 4 || $linha[3] !== '-') {
                break;
            }
        }
        $codigo = (int)substr($resposta, 0, 3);
        if ($codigo !== $esperado) {
            throw new \RuntimeException("SMTP respondeu {$codigo} (esperado {$esperado}): " . trim($resposta));
        }
        return $resposta;
    }
}

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
        return $c['remetente'] !== null && ($c['host'] !== null || self::porApi());
    }

    /**
     * Manda por API (HTTPS) em vez de SMTP?
     *
     * A chave tem precedência sobre o SMTP de propósito: quem a preencheu já
     * descobriu que a porta 587 não abre daquele contêiner. Tentar o SMTP antes
     * "por via das dúvidas" custaria 20 segundos de espera por destinatário
     * para terminar no mesmo tempo esgotado.
     */
    public static function porApi(): bool
    {
        $c = self::config();
        return ($c['api_chave'] ?? null) !== null && ($c['api_url'] ?? '') !== '';
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
            throw new \RuntimeException(
                'Envio não configurado (defina SMTP_HOST e SMTP_REMETENTE, ou EMAIL_API_CHAVE e SMTP_REMETENTE).'
            );
        }
        if (self::porApi()) {
            self::enviarPorApi($para, $assunto, $html);
            return;
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
            // A mensagem já saiu; o que vem daqui em diante decide se ela foi
            // aceita. Uma recusa explícita (4xx/5xx) é falha de verdade e pode
            // ser tentada de novo. Já ficar sem resposta legível — servidor que
            // desliga ou demora além do tempo limite — é ambíguo: a entrega
            // provavelmente aconteceu, e insistir mandaria o aviso duplicado.
            try {
                self::ler($conexao, 250);
            } catch (\Throwable $e) {
                if ($e->getCode() !== 0) {
                    throw $e;
                }
            }
            try {
                self::comando($conexao, 'QUIT', 221);
            } catch (\Throwable $e) {
                // servidor encerrou a conversa do seu jeito; a entrega está feita
            }
        } finally {
            fclose($conexao);
        }
    }

    /**
     * O mesmo e-mail, entregue por HTTPS (porta 443) em vez de SMTP.
     *
     * O corpo segue o formato da API transacional do Brevo, que é o serviço
     * documentado na seção 6 do guia de deploy — escolhido por ser o que aceita
     * verificar um remetente AVULSO, sem exigir domínio próprio, que é a
     * situação de quem está validando o sistema. `EMAIL_API_URL` existe para
     * apontar a outro serviço de formato compatível sem mexer no código.
     *
     * Erro vira RuntimeException com o corpo da resposta, como no SMTP: é o que
     * o botão do Relatório de Status mostra e o que vai para `envio_email.erro`.
     * A CHAVE nunca entra na mensagem — ela viaja em cabeçalho e a exceção
     * termina em log de provedor.
     */
    private static function enviarPorApi(string $para, string $assunto, string $html): void
    {
        $c = self::config();
        $corpo = json_encode([
            'sender' => array_filter([
                'email' => $c['remetente'],
                'name'  => $c['nome_remetente'] ?: null,
            ]),
            'to'          => [['email' => $para]],
            'subject'     => $assunto,
            'htmlContent' => $html,
        ], JSON_UNESCAPED_UNICODE);
        if ($corpo === false) {
            throw new \RuntimeException('Falha ao montar o corpo do e-mail (JSON).');
        }
        $cabecalhos = [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $c['api_chave'],
        ];

        [$status, $resposta, $erroRede] = \function_exists('curl_init')
            ? self::postarCurl($c['api_url'], $cabecalhos, $corpo)
            : self::postarStream($c['api_url'], $cabecalhos, $corpo);

        if ($erroRede !== '') {
            throw new \RuntimeException("Não foi possível falar com {$c['api_url']} — {$erroRede}");
        }
        if ($status < 200 || $status >= 300) {
            // A resposta é cortada: mensagem de erro vai para o banco e para um
            // alerta na tela, e serviço nenhum garante que ela seja curta.
            throw new \RuntimeException(
                "O serviço de e-mail recusou (HTTP {$status}): " . mb_substr(trim($resposta), 0, 300)
            );
        }
    }

    /** @return array{0:int,1:string,2:string} status, corpo, erro de rede */
    private static function postarCurl(string $url, array $cabecalhos, string $corpo): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $cabecalhos,
            CURLOPT_POSTFIELDS     => $corpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TEMPO_LIMITE,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resposta = curl_exec($ch);
        $erro = $resposta === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string)$resposta, $erro];
    }

    /**
     * O mesmo POST sem a extensão curl. A imagem publicada a tem, mas este
     * arquivo também roda na máquina de quem desenvolve, e um envio que morre
     * por extensão faltando manda procurar o defeito no lugar errado.
     */
    private static function postarStream(string $url, array $cabecalhos, string $corpo): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $cabecalhos),
            'content'       => $corpo,
            'timeout'       => self::TEMPO_LIMITE,
            // Sem isto, resposta 4xx faz o fopen devolver false e a mensagem do
            // serviço — que é justamente o diagnóstico — se perde.
            'ignore_errors' => true,
        ]]);
        $resposta = @file_get_contents($url, false, $ctx);
        if ($resposta === false) {
            return [0, '', 'sem resposta (rede ou allow_url_fopen desligado)'];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $linha) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
                $status = (int)$m[1];
            }
        }
        return [$status, $resposta, ''];
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
            // O código vai junto na exceção: 0 significa "o servidor não
            // respondeu nada", o que quem chama precisa saber distinguir
            throw new \RuntimeException(
                "SMTP respondeu {$codigo} (esperado {$esperado}): " . trim($resposta),
                $codigo
            );
        }
        return $resposta;
    }
}

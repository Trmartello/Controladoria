<?php

/**
 * Cópia do backup para fora do provedor (Backblaze B2).
 *
 *   php cli/backup_remoto.php enviar <arquivo>
 *   php cli/backup_remoto.php listar
 *   php cli/backup_remoto.php diagnostico
 *
 * POR QUE ISTO EXISTE. O backup do `cli/backup.sh` protege contra erro DENTRO
 * do Railway — alguém apagar dado, uma migração ruim. Ele não protege contra
 * perder o Railway: conta suspensa, projeto excluído, região fora do ar. Uma
 * cópia que mora no mesmo lugar do original é meia cópia.
 *
 * POR QUE EM PHP, e não no `backup.sh`. A imagem publicada tem PHP por
 * definição — é o que serve o sistema. Do resto não dá para depender: ela
 * instala só o cliente do MySQL, e `curl` de linha de comando pode não estar
 * lá. O envio é HTTP; escrevê-lo aqui usa o que já existe.
 *
 * POR QUE B2, e não S3. A API nativa do B2 é autenticação por token: três
 * chamadas HTTP comuns. A da Amazon (e as compatíveis, como R2) exige assinar
 * cada pedido com SigV4 — HMAC encadeado sobre uma string canônica —, que é
 * código de criptografia escrito à mão para resolver um problema de upload.
 * `B2_API_URL` existe para a bateria apontar a um serviço de mentira.
 *
 * O que NÃO está aqui, de propósito: cifrar o arquivo antes de subir. O B2
 * cifra em repouso, e uma chave gerenciada por nós que se perca transforma
 * todo o histórico em lixo — a decisão precisa vir com um lugar para guardar a
 * chave, e esse lugar ainda não existe.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';

const B2_TEMPO_LIMITE = 120;

function envB2(string $chave): ?string
{
    $v = getenv($chave);
    return ($v === false || $v === '') ? null : $v;
}

function configuradoB2(): bool
{
    return envB2('B2_KEY_ID') !== null && envB2('B2_KEY') !== null
        && (envB2('B2_BUCKET') !== null || envB2('B2_BUCKET_ID') !== null);
}

/**
 * Um pedido HTTP. Devolve [status, corpo, erroDeRede].
 *
 * Com a extensão curl quando ela existe, por stream quando não — o mesmo par
 * de caminhos de `App\Core\Email`, pelo mesmo motivo: envio que morre por
 * extensão faltando manda procurar o defeito no lugar errado.
 *
 * @param array<int,string> $cabecalhos
 * @return array{0:int,1:string,2:string}
 */
function pedirB2(string $url, array $cabecalhos, ?string $corpo = null, ?string $arquivo = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => B2_TEMPO_LIMITE,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($arquivo !== null) {
            // `UPLOAD` (que lê do arquivo, sem carregá-lo na memória) com o
            // método trocado para POST, que é o que o B2 espera. As duas
            // alternativas não servem: `POSTFIELDS` com o arquivo inteiro
            // estoura o memory_limit num dump grande, e um POST com
            // READFUNCTION não tem como anunciar o tamanho (o PHP não expõe
            // `CURLOPT_POSTFIELDSIZE`) — vira `Transfer-Encoding: chunked` e
            // fica pendurado contra quem não aceita corpo em pedaços.
            $fh = fopen($arquivo, 'rb');
            $opcoes[CURLOPT_UPLOAD] = true;
            $opcoes[CURLOPT_CUSTOMREQUEST] = 'POST';
            $opcoes[CURLOPT_INFILE] = $fh;
            $opcoes[CURLOPT_INFILESIZE] = filesize($arquivo);
            // Sem isto o curl manda `Expect: 100-continue` em corpo grande e
            // espera um segundo por uma resposta que nem todo servidor dá.
            $cabecalhos[] = 'Expect:';
        } elseif ($corpo !== null) {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        }
        // Os cabeçalhos entram DEPOIS: o ramo do arquivo acrescenta um à lista,
        // e fixá-los antes deixaria esse acréscimo de fora sem erro nenhum.
        $opcoes[CURLOPT_HTTPHEADER] = $cabecalhos;
        curl_setopt_array($ch, $opcoes);
        $resposta = curl_exec($ch);
        $erro = $resposta === false ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (isset($fh) && is_resource($fh)) {
            fclose($fh);
        }
        return [$status, (string)$resposta, $erro];
    }

    $conteudo = $arquivo !== null ? file_get_contents($arquivo) : $corpo;
    $ctx = stream_context_create(['http' => [
        'method'        => $conteudo === null ? 'GET' : 'POST',
        'header'        => implode("\r\n", $cabecalhos),
        'content'       => $conteudo ?? '',
        'timeout'       => B2_TEMPO_LIMITE,
        'ignore_errors' => true,
    ]]);
    $resposta = @file_get_contents($url, false, $ctx);
    if ($resposta === false) {
        return [0, '', 'falha de rede ao falar com ' . $url];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $linha) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
            $status = (int)$m[1];
        }
    }
    return [$status, $resposta, ''];
}

/** Resposta JSON ou exceção com o motivo que o serviço deu. */
function jsonB2(string $passo, array $r): array
{
    [$status, $corpo, $erroRede] = $r;
    if ($erroRede !== '') {
        throw new RuntimeException("{$passo}: {$erroRede}");
    }
    if ($status < 200 || $status >= 300) {
        // A CHAVE nunca entra na mensagem: ela viaja em cabeçalho e este texto
        // termina em log de provedor.
        throw new RuntimeException("{$passo}: o serviço recusou (HTTP {$status}) "
            . mb_substr(trim($corpo), 0, 300));
    }
    $d = json_decode($corpo, true);
    if (!is_array($d)) {
        throw new RuntimeException("{$passo}: resposta não é JSON.");
    }
    return $d;
}

/** Autoriza e devolve [apiUrl, token, accountId]. */
function autorizarB2(): array
{
    $base = envB2('B2_API_URL') ?: 'https://api.backblazeb2.com';
    $cred = base64_encode(envB2('B2_KEY_ID') . ':' . envB2('B2_KEY'));
    $d = jsonB2('autorização', pedirB2(
        rtrim($base, '/') . '/b2api/v3/b2_authorize_account',
        ['Authorization: Basic ' . $cred]
    ));
    // A v3 aninha o endereço da API; a v2 devolvia na raiz. Ler os dois evita
    // que uma virada de versão do serviço derrube o backup sem aviso.
    $apiUrl = $d['apiInfo']['storageApi']['apiUrl'] ?? ($d['apiUrl'] ?? null);
    if (!$apiUrl) {
        throw new RuntimeException('autorização: a resposta não trouxe o endereço da API.');
    }
    return [rtrim($apiUrl, '/'), $d['authorizationToken'], $d['accountId'] ?? ''];
}

/** O id do balde, resolvido pelo nome quando só o nome foi configurado. */
function baldeB2(string $apiUrl, string $token, string $contaId): string
{
    $id = envB2('B2_BUCKET_ID');
    if ($id !== null) {
        return $id;
    }
    $d = jsonB2('lista de baldes', pedirB2(
        $apiUrl . '/b2api/v3/b2_list_buckets',
        ['Authorization: ' . $token, 'Content-Type: application/json'],
        json_encode(['accountId' => $contaId, 'bucketName' => envB2('B2_BUCKET')])
    ));
    foreach ($d['buckets'] ?? [] as $b) {
        if (($b['bucketName'] ?? '') === envB2('B2_BUCKET')) {
            return $b['bucketId'];
        }
    }
    throw new RuntimeException('balde "' . envB2('B2_BUCKET') . '" não encontrado nesta conta.');
}

function enviarB2(string $caminho): int
{
    if (!is_file($caminho)) {
        fwrite(STDERR, "backup-remoto: arquivo não encontrado: {$caminho}\n");
        return 2;
    }
    [$apiUrl, $token, $contaId] = autorizarB2();
    $balde = baldeB2($apiUrl, $token, $contaId);

    $d = jsonB2('endereço de envio', pedirB2(
        $apiUrl . '/b2api/v3/b2_get_upload_url',
        ['Authorization: ' . $token, 'Content-Type: application/json'],
        json_encode(['bucketId' => $balde])
    ));

    $prefixo = envB2('B2_PREFIXO') ?: 'controladoria';
    $nome = trim($prefixo, '/') . '/' . basename($caminho);
    // O SHA-1 é exigência do B2 e é também a verificação de ponta a ponta: o
    // serviço recusa o arquivo se o que chegou não bate com o que foi anunciado.
    $sha = sha1_file($caminho);
    $tamanho = filesize($caminho);

    $r = pedirB2($d['uploadUrl'], [
        'Authorization: ' . $d['authorizationToken'],
        'X-Bz-File-Name: ' . rawurlencode($nome),
        'Content-Type: application/octet-stream',
        'Content-Length: ' . $tamanho,
        'X-Bz-Content-Sha1: ' . $sha,
    ], null, $caminho);
    $envio = jsonB2('envio', $r);

    printf(
        "backup-remoto: %s enviado (%s, sha1 %s…) como %s\n",
        basename($caminho),
        formatarTamanho($tamanho),
        substr($sha, 0, 12),
        $envio['fileName'] ?? $nome
    );
    return 0;
}

function listarB2(): int
{
    [$apiUrl, $token, $contaId] = autorizarB2();
    $balde = baldeB2($apiUrl, $token, $contaId);
    $d = jsonB2('listagem', pedirB2(
        $apiUrl . '/b2api/v3/b2_list_file_names',
        ['Authorization: ' . $token, 'Content-Type: application/json'],
        json_encode([
            'bucketId'     => $balde,
            'prefix'       => trim(envB2('B2_PREFIXO') ?: 'controladoria', '/') . '/',
            'maxFileCount' => 100,
        ])
    ));
    $arquivos = $d['files'] ?? [];
    if (!$arquivos) {
        echo "backup-remoto: nenhuma cópia remota ainda.\n";
        return 0;
    }
    foreach ($arquivos as $f) {
        printf(
            "%s  %10s  %s\n",
            date('d/m/Y H:i', (int)round(($f['uploadTimestamp'] ?? 0) / 1000)),
            formatarTamanho((int)($f['contentLength'] ?? 0)),
            $f['fileName'] ?? '?'
        );
    }
    printf("backup-remoto: %d cópia(s) remota(s).\n", count($arquivos));
    return 0;
}

function formatarTamanho(int $b): string
{
    foreach ([['GB', 1073741824], ['MB', 1048576], ['kB', 1024]] as [$u, $d]) {
        if ($b >= $d) {
            return round($b / $d, 1) . ' ' . $u;
        }
    }
    return $b . ' B';
}

/**
 * Por que a cópia remota não sai — respondido sem enviar nada.
 *
 * A senha e a chave NUNCA são impressas: sai só se estão definidas e o
 * tamanho, que é o suficiente para flagrar o erro mais comum (variável vazia
 * ou colada com espaço) sem vazar credencial em log de provedor.
 */
function diagnosticoB2(): int
{
    echo "backup-remoto: diagnóstico\n\n";
    foreach (['B2_KEY_ID', 'B2_KEY', 'B2_BUCKET', 'B2_BUCKET_ID', 'B2_PREFIXO', 'B2_API_URL'] as $k) {
        $v = envB2($k);
        $mostra = in_array($k, ['B2_KEY_ID', 'B2_KEY'], true)
            ? ($v === null ? '(não definida)' : 'definida, ' . strlen($v) . ' caracteres'
                . (trim($v) !== $v ? ' — TEM ESPAÇO nas pontas' : ''))
            : ($v ?? '(não definida)');
        printf("  %-12s %s\n", $k, $mostra);
    }
    if (!configuradoB2()) {
        echo "\nCópia remota DESLIGADA: faltam B2_KEY_ID, B2_KEY e B2_BUCKET.\n";
        echo "O backup local continua sendo gerado normalmente.\n";
        return 1;
    }
    try {
        [$apiUrl, $token, $contaId] = autorizarB2();
        echo "\n  autorização    ok\n";
        $balde = baldeB2($apiUrl, $token, $contaId);
        echo "  balde          ok ({$balde})\n";
        echo "\nCópia remota configurada e alcançável.\n";
        return 0;
    } catch (Throwable $e) {
        echo "\nFALHOU: ", $e->getMessage(), "\n";
        return 1;
    }
}

$acao = $argv[1] ?? 'diagnostico';
if ($acao === 'diagnostico') {
    exit(diagnosticoB2());
}
if (!configuradoB2()) {
    // Silêncio de propósito no `enviar`: quem não configurou a cópia remota não
    // pode ver o backup diário falhar por causa dela.
    if ($acao === 'enviar') {
        exit(0);
    }
    fwrite(STDERR, "backup-remoto: não configurado (B2_KEY_ID, B2_KEY, B2_BUCKET).\n");
    exit(1);
}

try {
    exit(match ($acao) {
        'enviar' => enviarB2($argv[2] ?? ''),
        'listar' => listarB2(),
        default  => (function () {
            fwrite(STDERR, "Uso: php cli/backup_remoto.php [enviar <arquivo>|listar|diagnostico]\n");
            return 2;
        })(),
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'backup-remoto: ' . $e->getMessage() . "\n");
    exit(1);
}

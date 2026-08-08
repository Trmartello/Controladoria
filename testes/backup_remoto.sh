#!/usr/bin/env bash
# Bateria da cópia do backup para fora do provedor (Backblaze B2).
#
#   ./testes/backup_remoto.sh
#
# Não sobe nada para lugar nenhum e não precisa de banco nem de conta: levanta
# um serviço de MENTIRA que responde como o B2 (401 com chave errada, resolve o
# balde pelo nome, confere o SHA-1 do que recebeu) e exercita
# `cli/backup_remoto.php` contra ele. Um teste que dependesse do serviço de
# verdade exigiria credencial no repositório, gastaria cota e ficaria vermelho
# quando o B2 saísse do ar — três motivos para ele ser ignorado.
set -uo pipefail
cd "$(dirname "$0")/.."

OK=0; FALHA=0
declare -a FALHAS
ok()    { OK=$((OK + 1)); }
falha() { FALHA=$((FALHA + 1)); FALHAS+=("$1 | esperado: $2 | veio: $3"); }
afirma()     { if echo "$3" | grep -qF "$2"; then ok; else falha "$1" "$2" "$(echo "$3" | head -c 200)"; fi; }
afirma_nao() { if echo "$3" | grep -qF "$2"; then falha "$1" "SEM '$2'" "$(echo "$3" | head -c 200)"; else ok; fi; }

command -v php >/dev/null 2>&1 || { echo "  ⏭  pulada: php não está no PATH."; exit 0; }

TMP=$(mktemp -d) || exit 1
PORTA=8126
KEY_ID='id-do-teste'
CHAVE='chave-do-teste'
BALDE='copia-controladoria'
RECEBIDOS="$TMP/recebidos.log"
: > "$RECEBIDOS"

cat > "$TMP/b2.php" <<'PHP'
<?php
// Imita o essencial do B2: as três chamadas do envio, com os mesmos erros.
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = 'http://127.0.0.1:' . getenv('PORTA_TESTE');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: application/json');

if (str_ends_with($uri, 'b2_authorize_account')) {
    $esperado = 'Basic ' . base64_encode(getenv('KEY_ID_ESPERADO') . ':' . getenv('CHAVE_ESPERADA'));
    if ($auth !== $esperado) {
        http_response_code(401);
        exit(json_encode(['code' => 'unauthorized', 'message' => 'Invalid application key']));
    }
    exit(json_encode(['authorizationToken' => 'tok', 'accountId' => 'conta',
        'apiInfo' => ['storageApi' => ['apiUrl' => $base]]]));
}
if (str_ends_with($uri, 'b2_list_buckets')) {
    if ($auth !== 'tok') { http_response_code(401); exit(json_encode(['message' => 'sem token'])); }
    exit(json_encode(['buckets' => [['bucketName' => getenv('BALDE_ESPERADO'), 'bucketId' => 'balde-1']]]));
}
if (str_ends_with($uri, 'b2_get_upload_url')) {
    if ($auth !== 'tok') { http_response_code(401); exit(json_encode(['message' => 'sem token'])); }
    exit(json_encode(['uploadUrl' => $base . '/upload', 'authorizationToken' => 'tok-envio']));
}
if (str_ends_with($uri, '/upload')) {
    $corpo = file_get_contents('php://input');
    // O SHA-1 é a verificação de ponta a ponta: o serviço real recusa quando o
    // que chegou não bate com o que foi anunciado, e aqui é igual.
    if (sha1($corpo) !== ($_SERVER['HTTP_X_BZ_CONTENT_SHA1'] ?? '')) {
        http_response_code(400);
        exit(json_encode(['code' => 'bad_request', 'message' => 'sha1 did not match data received']));
    }
    $nome = rawurldecode($_SERVER['HTTP_X_BZ_FILE_NAME'] ?? '');
    file_put_contents(getenv('RECEBIDOS'), $nome . ' | ' . strlen($corpo) . "\n", FILE_APPEND);
    exit(json_encode(['fileName' => $nome, 'contentLength' => strlen($corpo)]));
}
if (str_ends_with($uri, 'b2_list_file_names')) {
    exit(json_encode(['files' => [[
        'fileName' => 'controladoria/planejamento-2026-08-08-030000.sql.gz',
        'contentLength' => 4096, 'uploadTimestamp' => 1770000000000]]]));
}
http_response_code(404);
echo json_encode(['message' => 'rota desconhecida: ' . $uri]);
PHP

PORTA_TESTE="$PORTA" KEY_ID_ESPERADO="$KEY_ID" CHAVE_ESPERADA="$CHAVE" \
    BALDE_ESPERADO="$BALDE" RECEBIDOS="$RECEBIDOS" \
    php -S "127.0.0.1:$PORTA" -t "$TMP" "$TMP/b2.php" > "$TMP/b2.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null; rm -rf "$TMP"' EXIT HUP INT TERM
for _ in $(seq 1 30); do
    curl -s -o /dev/null "http://127.0.0.1:$PORTA/" && break
    sleep 0.2
done
if ! curl -s -o /dev/null "http://127.0.0.1:$PORTA/"; then
    echo "  ⏭  pulada: não subiu o servidor de teste na porta $PORTA."
    exit 0
fi

ARQ="$TMP/planejamento-2026-08-08-030000.sql.gz"
printf 'conteudo de backup de mentira\n' | gzip > "$ARQ"
BOM=(B2_API_URL="http://127.0.0.1:$PORTA" B2_KEY_ID="$KEY_ID" B2_KEY="$CHAVE" B2_BUCKET="$BALDE")

echo "### 1. Sem configuração, o backup local não pode falhar"
# Quem ainda não tem cópia remota não pode ver o backup diário virar vermelho
# por causa dela: `enviar` sai calado e com zero.
R=$(env -u B2_KEY_ID -u B2_KEY -u B2_BUCKET php cli/backup_remoto.php enviar "$ARQ" 2>&1; echo "saida=$?")
afirma "envio sem configuração sai com zero" 'saida=0' "$R"
afirma_nao "e sem barulho" 'backup-remoto:' "$R"
R=$(env -u B2_KEY_ID -u B2_KEY -u B2_BUCKET php cli/backup_remoto.php diagnostico 2>&1)
afirma "o diagnóstico diz que está desligada" 'Cópia remota DESLIGADA' "$R"

echo "### 2. Configurada e alcançável"
R=$(env "${BOM[@]}" php cli/backup_remoto.php diagnostico 2>&1)
afirma "o diagnóstico autoriza e resolve o balde" 'Cópia remota configurada e alcançável' "$R"
# A chave sai só como tamanho: este texto termina em log de provedor.
afirma "e nunca imprime a chave" 'B2_KEY       definida, ' "$R"
afirma_nao "nem por acidente" "$CHAVE" "$R"

echo "### 3. O envio"
: > "$RECEBIDOS"
R=$(env "${BOM[@]}" php cli/backup_remoto.php enviar "$ARQ" 2>&1)
afirma "o arquivo sobe" 'enviado' "$R"
afirma "com o prefixo no nome remoto" 'controladoria/planejamento-2026-08-08-030000.sql.gz' "$R"
# Se o SHA-1 anunciado não batesse com o corpo, o serviço teria recusado — esta
# linha no log do serviço é a prova de que os bytes chegaram inteiros.
afirma "e o serviço confirma o que recebeu" 'controladoria/planejamento-2026-08-08-030000.sql.gz | ' "$(cat "$RECEBIDOS")"
# A imagem publicada tem a extensão curl, mas a máquina de quem desenvolve pode
# não ter — e um envio que morre por extensão faltando manda procurar o defeito
# no lugar errado.
: > "$RECEBIDOS"
R=$(env "${BOM[@]}" php -d disable_functions=curl_init,curl_setopt_array,curl_exec \
    cli/backup_remoto.php enviar "$ARQ" 2>&1)
afirma "sobe também sem a extensão curl" 'enviado' "$R"
afirma "e chega igual por esse caminho" 'controladoria/planejamento-2026-08-08-030000.sql.gz | ' "$(cat "$RECEBIDOS")"

echo "### 4. Prefixo próprio e listagem"
R=$(env "${BOM[@]}" B2_PREFIXO='copia-homologacao' php cli/backup_remoto.php enviar "$ARQ" 2>&1)
afirma "o prefixo configurado é respeitado" 'copia-homologacao/planejamento-' "$R"
R=$(env "${BOM[@]}" php cli/backup_remoto.php listar 2>&1)
afirma "a listagem mostra as cópias remotas" '1 cópia(s) remota(s)' "$R"

echo "### 5. O que dá errado chega a quem chamou"
R=$(env "${BOM[@]}" B2_KEY='chave-errada' php cli/backup_remoto.php enviar "$ARQ" 2>&1; echo "saida=$?")
afirma "chave recusada vira erro" 'autorização: o serviço recusou (HTTP 401)' "$R"
afirma "com o motivo do serviço" 'Invalid application key' "$R"
afirma "e saída diferente de zero" 'saida=1' "$R"
afirma_nao "sem vazar a chave" 'chave-errada' "$R"
R=$(env "${BOM[@]}" B2_BUCKET='balde-que-nao-existe' php cli/backup_remoto.php enviar "$ARQ" 2>&1)
afirma "balde inexistente diz qual" 'balde-que-nao-existe' "$R"
R=$(env "${BOM[@]}" php cli/backup_remoto.php enviar "$TMP/nao-existe.gz" 2>&1; echo "saida=$?")
afirma "arquivo inexistente não vira envio vazio" 'arquivo não encontrado' "$R"
R=$(env B2_API_URL='http://127.0.0.1:9' B2_KEY_ID="$KEY_ID" B2_KEY="$CHAVE" B2_BUCKET="$BALDE" \
    php cli/backup_remoto.php enviar "$ARQ" 2>&1; echo "saida=$?")
afirma "serviço inalcançável vira erro, não silêncio" 'saida=1' "$R"

echo
if [ $FALHA -eq 0 ]; then
    echo "✓ backup remoto: $OK verificação(ões), nenhuma falha."
else
    echo "✗ backup remoto: $FALHA falha(s) em $((OK + FALHA)):"
    for f in "${FALHAS[@]}"; do echo "  - $f"; done
fi
exit $((FALHA > 0 ? 1 : 0))

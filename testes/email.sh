#!/usr/bin/env bash
# Bateria do envio de e-mail: o caminho por API (HTTPS), que é o que roda em
# produção — a hospedagem bloqueia as portas de SMTP, medido, e ali nenhum
# servidor de e-mail é alcançável.
#
#   ./testes/email.sh
#
# Não manda e-mail nenhum e não precisa de banco: sobe um servidor de MENTIRA
# que responde como o serviço real (401 sem chave, 400 com remetente não
# verificado, 201 no sucesso) e exercita App\Core\Email contra ele. Um teste que
# dependesse do serviço de verdade exigiria credencial no repositório, gastaria
# cota e ficaria vermelho quando o serviço saísse do ar — três motivos para ele
# ser ignorado.
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
PORTA=8124
CHAVE='chave-boa-do-teste'
REMETENTE='remetente@exemplo.com'

cat > "$TMP/api.php" <<'PHP'
<?php
// Imita a API transacional: erra igual ao serviço real, que é o que interessa.
$chave = $_SERVER['HTTP_API_KEY'] ?? '';
$d = json_decode(file_get_contents('php://input'), true) ?: [];
if ($chave !== getenv('CHAVE_ESPERADA')) {
    http_response_code(401);
    exit(json_encode(['code' => 'unauthorized', 'message' => 'Key not found']));
}
if (($d['sender']['email'] ?? '') !== getenv('REMETENTE_ESPERADO')) {
    http_response_code(400);
    exit(json_encode(['code' => 'invalid_parameter', 'message' => 'sender not verified']));
}
http_response_code(201);
echo json_encode(['messageId' => '<x@teste>', 'para' => $d['to'][0]['email'] ?? '',
    'assunto' => $d['subject'] ?? '', 'corpo' => $d['htmlContent'] ?? '']);
PHP

CHAVE_ESPERADA="$CHAVE" REMETENTE_ESPERADO="$REMETENTE" \
    php -S "127.0.0.1:$PORTA" -t "$TMP" "$TMP/api.php" > "$TMP/api.log" 2>&1 &
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

# Os dois trechos vão para ARQUIVO, não para `php -r`: as provas precisam
# limpar variáveis de ambiente (`env -u`), e `env` não executa função de shell.
cat > "$TMP/estado.php" <<'PHP'
<?php
$GLOBALS['config'] = require 'config/config.php';
require 'app/Core/Email.php';
printf('configurado=%d porApi=%d', App\Core\Email::configurado(), App\Core\Email::porApi());
PHP
cat > "$TMP/enviar.php" <<'PHP'
<?php
$GLOBALS['config'] = require 'config/config.php';
require 'app/Core/Email.php';
try {
    App\Core\Email::enviar('destino@exemplo.com', 'Pendências', '<p>oi</p>');
    echo 'ENVIADO';
} catch (Throwable $e) {
    echo 'RECUSA: ', $e->getMessage();
}
PHP

export EMAIL_API_URL="http://127.0.0.1:$PORTA/"

echo "### 1. Qual caminho o sistema escolhe"
R=$(env -u SMTP_HOST -u EMAIL_API_CHAVE -u SMTP_REMETENTE php "$TMP/estado.php" 2>&1)
afirma "sem configuração nenhuma, envio desligado" 'configurado=0 porApi=0' "$R"
R=$(env -u EMAIL_API_CHAVE SMTP_HOST=smtp.exemplo.com SMTP_REMETENTE="$REMETENTE" php "$TMP/estado.php" 2>&1)
afirma "só com SMTP, vai de SMTP" 'configurado=1 porApi=0' "$R"
# A chave tem precedência: quem a definiu já descobriu que a porta não abre, e
# tentar o SMTP antes custaria 20s de espera por destinatário para falhar igual.
R=$(env SMTP_HOST=smtp.exemplo.com EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/estado.php" 2>&1)
afirma "com a chave, a API tem precedência sobre o SMTP" 'configurado=1 porApi=1' "$R"
R=$(env -u SMTP_HOST EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/estado.php" 2>&1)
afirma "a API sozinha basta (sem SMTP_HOST)" 'configurado=1 porApi=1' "$R"

echo "### 2. O que o serviço recusa chega a quem clicou"
R=$(env EMAIL_API_CHAVE='chave-errada' SMTP_REMETENTE="$REMETENTE" php "$TMP/enviar.php" 2>&1)
afirma "chave recusada diz o motivo do serviço" 'Key not found' "$R"
afirma "e informa o código HTTP" 'HTTP 401' "$R"
# A chave viaja em cabeçalho; a exceção vai para envio_email.erro, para um
# alerta na tela e para o log do provedor — nenhum lugar para credencial.
afirma_nao "a chave NUNCA aparece na mensagem de erro" 'chave-errada' "$R"
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE='naoverificado@exemplo.com' php "$TMP/enviar.php" 2>&1)
afirma "remetente não verificado diz o motivo" 'sender not verified' "$R"
afirma_nao "e também não vaza a chave" "$CHAVE" "$R"

echo "### 3. O envio que dá certo"
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/enviar.php" 2>&1)
afirma "envia pela API" 'ENVIADO' "$R"
# Sem a extensão curl o envio cai no caminho de stream. A imagem publicada tem
# curl, mas a máquina de quem desenvolve pode não ter, e um envio que morre por
# extensão faltando manda procurar o defeito no lugar errado.
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" \
    php -d disable_functions=curl_init,curl_setopt_array,curl_exec "$TMP/enviar.php" 2>&1)
afirma "envia também sem a extensão curl" 'ENVIADO' "$R"

echo "### 4. Rede fora do ar não passa por sucesso"
R=$(env EMAIL_API_URL='http://127.0.0.1:9/' EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/enviar.php" 2>&1)
afirma "serviço inalcançável vira erro, não silêncio" 'RECUSA' "$R"

echo
if [ $FALHA -eq 0 ]; then
    echo "✓ e-mail: $OK verificação(ões), nenhuma falha."
else
    echo "✗ e-mail: $FALHA falha(s) em $((OK + FALHA)):"
    for f in "${FALHAS[@]}"; do echo "  - $f"; done
fi
exit $((FALHA > 0 ? 1 : 0))

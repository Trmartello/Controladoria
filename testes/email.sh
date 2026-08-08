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
// O que saiu fica em disco: a prova do relatório do disparo precisa afirmar
// QUEM recebeu o quê, e não só o que a função devolveu.
file_put_contents(getenv('DIARIO_ENVIOS'),
    ($d['to'][0]['email'] ?? '') . ' | ' . ($d['subject'] ?? '') . ' | '
    . str_replace("\n", ' ', $d['htmlContent'] ?? '') . "\n", FILE_APPEND);
echo json_encode(['messageId' => '<x@teste>', 'para' => $d['to'][0]['email'] ?? '',
    'assunto' => $d['subject'] ?? '', 'corpo' => $d['htmlContent'] ?? '']);
PHP

ENVIOS="$TMP/enviados.log"
: > "$ENVIOS"
CHAVE_ESPERADA="$CHAVE" REMETENTE_ESPERADO="$REMETENTE" DIARIO_ENVIOS="$ENVIOS" \
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

echo "### 5. A trava de duplicidade e o reenvio manual"
# `Avisos` fala com o banco só por métodos ESTÁTICOS de Database, então um
# dublê declarado ANTES do require substitui a tabela inteira — sem MySQL, sem
# massa e sem depender de instância no ar. O que se prova aqui é a assimetria
# que o botão do Relatório de Status introduziu: ele FORÇA (quem clica quer o
# e-mail agora) e o agendamento NÃO (roda sozinho, e sem a trava um cron a cada
# cinco minutos viraria doze e-mails por hora para gente de verdade).
cat > "$TMP/avisos.php" <<'PHP'
<?php
namespace App\Core;

/**
 * Dublê do banco: uma responsável com uma ação vencendo hoje, uma
 * administradora, e a carteira que o relatório do disparo resume.
 *
 * O modo (argv) diz se o aviso do dia JÁ saiu e se há pendência nenhuma —
 * é o que separa os quatro cenários provados abaixo.
 */
class Database
{
    public static array $gravou = [];
    public static function todos(string $sql, array $p = []): array
    {
        if (str_contains($sql, "perfil = 'ADMIN'")) {
            return [['id' => 1, 'nome' => 'Chefia', 'email' => 'chefia@exemplo.com']];
        }
        if (str_contains($sql, 'GROUP BY nome')) { // a carteira do relatório
            return [[
                'nome' => 'Fulana', 'total' => 10, 'concluidas' => 3, 'canceladas' => 0,
                'abertas' => 7, 'atrasadas' => 2, 'vencem_hoje' => 1, 'nao_iniciadas' => 5,
                'em_andamento' => 0, 'marcadas_atraso' => 1, 'pausadas' => 0, 'aguardando' => 1,
            ]];
        }
        if (str_contains($sql, 'FROM usuario u')) {
            return [['id' => 7, 'nome' => 'Fulana', 'email' => 'fulana@exemplo.com']];
        }
        if ($GLOBALS['modo'] === 'vazio') {
            return []; // ninguém com prazo vencendo: nada a disparar
        }
        return [['id' => 1, 'o_que' => 'Ação de teste', 'data_fim' => date('Y-m-d'),
                 'status' => 'NAO_INICIADO', 'prioridade' => 'ALTA', 'progresso' => 0,
                 'projeto' => 'Projeto', 'iniciativa' => 'Frente']];
    }
    public static function um(string $sql, array $p = []): ?array
    {
        return in_array($GLOBALS['modo'], ['cron', 'botao'], true) ? ['id' => 99] : null;
    }
    public static function executar(string $sql, array $p = []): void { self::$gravou[] = $p; }
}

namespace Prova;

$GLOBALS['config'] = require 'config/config.php';
require 'app/Core/Email.php';
require 'app/Services/Avisos.php';

$GLOBALS['modo'] = $modo = $argv[1] ?? 'cron';
$saida = \App\Services\Avisos::despachar('diario', null, $modo === 'botao');
$r = $saida['diario'];
printf(
    "%s: enviados=%d reenviados=%d ja_enviados=%d falhas=%d gravou=%d resumo=%s",
    $modo, $r['enviados'], $r['reenviados'], $r['ja_enviados'], $r['falhas'],
    count(\App\Core\Database::$gravou),
    isset($saida['resumo']) ? (int)$saida['resumo']['enviados'] : 'ausente'
);
PHP

R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/avisos.php" cron 2>&1)
afirma "o agendamento não repete o aviso do dia" 'cron: enviados=0 reenviados=0 ja_enviados=1' "$R"
# Sem esta, a trava poderia estar "funcionando" por não conseguir enviar nada.
afirma "e nem sequer grava linha nova" 'gravou=0' "$R"
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/avisos.php" botao 2>&1)
afirma "o botão reenvia mesmo já tendo saído hoje" 'botao: enviados=1 reenviados=1 ja_enviados=0' "$R"
afirma "e o reenvio não vira falha" 'falhas=0' "$R"

echo "### 6. O relatório do disparo, para quem administra"
: > "$ENVIOS"
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/avisos.php" novo 2>&1)
afirma "sai junto com os avisos do dia" 'novo: enviados=1 reenviados=0 ja_enviados=0 falhas=0 gravou=2 resumo=1' "$R"
L=$(cat "$ENVIOS")
afirma "vai para quem administra" 'chefia@exemplo.com | Planejamento — relatório do disparo' "$L"
afirma "traz a carteira por responsável" 'Por responsável' "$L"
afirma "com o percentual ao lado do número" '2</strong> (29%)' "$L"
afirma "e a quebra por situação" 'Aguardando validação' "$L"
# O rótulo é escrito, não derivado da chave: `ucfirst('diario')` dava "Diario".
afirma "com o rótulo acentuado do que saiu" 'Pendências do dia' "$L"
# Um relatório diário de "nada aconteceu" ensina a ignorar o remetente, e aí o
# dia em que algo falha passa junto.
: > "$ENVIOS"
R=$(env EMAIL_API_CHAVE="$CHAVE" SMTP_REMETENTE="$REMETENTE" php "$TMP/avisos.php" vazio 2>&1)
afirma "não sai quando nada foi disparado" 'vazio: enviados=0 reenviados=0 ja_enviados=0 falhas=0 gravou=0 resumo=ausente' "$R"
afirma_nao "e nenhum e-mail é gerado nesse caso" 'relatório do disparo' "$(cat "$ENVIOS")"

echo
if [ $FALHA -eq 0 ]; then
    echo "✓ e-mail: $OK verificação(ões), nenhuma falha."
else
    echo "✗ e-mail: $FALHA falha(s) em $((OK + FALHA)):"
    for f in "${FALHAS[@]}"; do echo "  - $f"; done
fi
exit $((FALHA > 0 ? 1 : 0))

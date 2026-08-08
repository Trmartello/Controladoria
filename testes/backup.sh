#!/usr/bin/env bash
# Bateria do backup: exercita o ciclo inteiro de cli/backup.sh — gerar,
# verificar, restaurar — e afirma o que ele DEVE recusar, que é onde mora o
# risco (arquivo pela metade aceito como bom, restauração sem confirmação).
#
#   ./testes/backup.sh
#
# O banco de produção/desenvolvimento é lido, NUNCA escrito: a bateria cria dois
# bancos descartáveis (`<banco>_bkp1` e `<banco>_bkp2`), faz o vaivém entre eles
# e os derruba no fim. Precisa de um usuário com CREATE DATABASE — sem isso a
# bateria é PULADA, não reprovada (o usuário que o Railway cria não tem esse
# direito, e um vermelho por falta de privilégio ensina a ignorar o vermelho).
set -uo pipefail
cd "$(dirname "$0")/.."

OK=0; FALHA=0
declare -a FALHAS
ok()    { OK=$((OK + 1)); }
falha() { FALHA=$((FALHA + 1)); FALHAS+=("$1 | esperado: $2 | veio: $3"); }
# afirma <nome> <padrão-esperado> <texto>
afirma() { if echo "$3" | grep -qE "$2"; then ok; else falha "$1" "$2" "$(echo "$3" | head -c 200)"; fi; }
# afirma_igual <nome> <esperado> <veio>
afirma_igual() { if [ "$2" = "$3" ]; then ok; else falha "$1" "$2" "$3"; fi; }

command -v php >/dev/null 2>&1 || { echo "  ⏭  pulada: php não está no PATH."; exit 0; }

# Mesma conexão da aplicação, lida de config/config.php (uma verdade só).
mapfile -t -d '' CFG < <(php -r '
    $d = (require "config/config.php")["db"];
    foreach (["host","port","name","user","pass"] as $k) { echo (string) $d[$k], "\0"; }
' 2>/dev/null)
[ "${#CFG[@]}" -eq 5 ] || { echo "  ⏭  pulada: não li config/config.php."; exit 0; }
H=${CFG[0]}; P=${CFG[1]}; N=${CFG[2]}; U=${CFG[3]}; S=${CFG[4]}

CLIENTE=$(command -v mysql || command -v mariadb) || true
DUMP=$(command -v mysqldump || command -v mariadb-dump) || true
[ -n "$CLIENTE" ] && [ -n "$DUMP" ] || { echo "  ⏭  pulada: cliente MySQL/MariaDB não encontrado."; exit 0; }

TMP=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP"' EXIT HUP INT TERM
CNF="$TMP/c.cnf"; umask 077
printf '[client]\nhost="%s"\nport=%s\nuser="%s"\npassword="%s"\ndefault-character-set=utf8mb4\n' \
    "$H" "${P:-3306}" "$U" "$S" > "$CNF"
sql() { "$CLIENTE" --defaults-file="$CNF" -N -B ${2:+-D "$2"} -e "$1" 2>/dev/null; }
# Mesma coisa, com o SQL pela entrada padrão: um único argumento de linha de
# comando para em 128 KB no Linux, e o INSERT do anexo é maior que isso.
sql_stdin() { "$CLIENTE" --defaults-file="$CNF" -N -B ${1:+-D "$1"} 2>/dev/null; }

A="${N}_bkp1"; B="${N}_bkp2"
if ! sql "CREATE DATABASE IF NOT EXISTS \`$A\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
          CREATE DATABASE IF NOT EXISTS \`$B\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; then
    echo "  ⏭  pulada: o usuário '$U' não pode criar banco (CREATE DATABASE)."
    exit 0
fi
limpar() { sql "DROP DATABASE IF EXISTS \`$A\`; DROP DATABASE IF EXISTS \`$B\`;" >/dev/null 2>&1; rm -rf "$TMP"; }
trap limpar EXIT HUP INT TERM

DIR="$TMP/backups"
# MYSQLDATABASE e DB_NAME juntos: config.php lê a primeira e cai na segunda, e
# num ambiente que já define a do Railway só DB_NAME não trocaria o alvo.
bkp() { local banco=$1; shift; MYSQLDATABASE="$banco" DB_NAME="$banco" BACKUP_DIR="$DIR" ./cli/backup.sh "$@"; }

echo "### 1. Gerar"
R=$(bkp "$N" 2>&1); afirma "gera o backup do banco atual" '✓ .*\.sql\.gz' "$R"
ARQ=$(find "$DIR" -name '*.sql.gz' | head -1)
afirma_igual "arquivo existe" "sim" "$([ -f "$ARQ" ] && echo sim || echo nao)"
afirma_igual "nasce só para o dono (600)" "600" "$(stat -c '%a' "$ARQ" 2>/dev/null)"
afirma_igual "gzip íntegro" "0" "$(gzip -t "$ARQ" 2>/dev/null; echo $?)"
afirma "termina na marca de fim" '^-- FIM DO BACKUP' "$(gzip -dc "$ARQ" | tail -1)"
ESPERADO=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$N' AND table_type='BASE TABLE'")
afirma_igual "traz todas as tabelas" "$ESPERADO" "$(gzip -dc "$ARQ" | grep -c '^CREATE TABLE')"
afirma "não interrompido deixa .parcial" '^0$' "$(find "$DIR" -name '*.parcial' | wc -l)"

echo "### 2. Restaurar"
R=$(MYSQLDATABASE="$A" DB_NAME="$A" CONFIRMAR="$A" ./cli/backup.sh restaurar "$ARQ" 2>&1)
afirma "restaura no banco descartável" '✓ restaurado' "$R"
for T in fator cenario_item negocio usuario cascata_escolha; do
    afirma_igual "$T volta com o mesmo total" "$(sql "SELECT COUNT(*) FROM \`$N\`.\`$T\`")" "$(sql "SELECT COUNT(*) FROM \`$A\`.\`$T\`")"
done
# Estrutura sim, linhas não: restaurar sessões devolveria acesso a quem estava
# logado no dia do dump, com o cookie de 30 dias ainda de pé.
afirma_igual "sessao veio com estrutura" "1" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$A' AND table_name='sessao'")"
afirma_igual "sessao veio VAZIA" "0" "$(sql "SELECT COUNT(*) FROM \`$A\`.sessao")"

echo "### 3. Anexo binário (o LONGBLOB dos comentários)"
# O binário nasce em HEX e entra por UNHEX: LOAD_FILE exigiria o privilégio
# FILE e o arquivo dentro do secure_file_priv do servidor — a prova do anexo
# ficaria pulada justamente onde ela importa. Os quatro primeiros bytes são os
# que quebram um dump sem --hex-blob (NUL, EOF do DOS e um par UTF-8 inválido).
HEX="001afffe$(head -c 65536 /dev/urandom | od -An -tx1 -v | tr -d ' \n')"
{
    printf "INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto)
              VALUES ('PROJETO', 987654, (SELECT MIN(id) FROM usuario), 'Acentuação: ação, coração, ñ, ©');\n"
    printf "INSERT INTO comentario_anexo (comentario_id, nome, tipo, tamanho, conteudo)
              VALUES (LAST_INSERT_ID(), 'teste.bin', 'application/octet-stream', %s, UNHEX('%s'));\n" \
        "$((${#HEX} / 2))" "$HEX"
} | sql_stdin "$A" >/dev/null
MD5_A=$(sql "SELECT MD5(conteudo) FROM comentario_anexo ORDER BY id DESC LIMIT 1" "$A")
afirma_igual "anexo entrou inteiro" "$((${#HEX} / 2))" "$(sql "SELECT LENGTH(conteudo) FROM comentario_anexo ORDER BY id DESC LIMIT 1" "$A")"
R=$(bkp "$A" 2>&1); afirma "gera backup com anexo" '✓ .*\.sql\.gz' "$R"
# Pelo nome do BANCO, não pelo mais recente: os dois backups desta bateria
# podem cair no mesmo segundo, e "o último" seria o do banco errado.
ARQ2=$(find "$DIR" -name "$A-*.sql.gz" | LC_ALL=C sort | tail -1)
MYSQLDATABASE="$B" DB_NAME="$B" CONFIRMAR="$B" ./cli/backup.sh restaurar "$ARQ2" >/dev/null 2>&1
afirma_igual "anexo volta byte a byte" "$MD5_A" "$(sql "SELECT MD5(conteudo) FROM comentario_anexo ORDER BY id DESC LIMIT 1" "$B")"
afirma_igual "acentuação atravessa" "$(sql "SELECT texto FROM comentario ORDER BY id DESC LIMIT 1" "$A")" \
                                    "$(sql "SELECT texto FROM comentario ORDER BY id DESC LIMIT 1" "$B")"

echo "### 4. O que tem de ser recusado"
# A prova do defeito: o arquivo são DOIS dumps concatenados, e o rodapé padrão
# do mysqldump ("-- Dump completed") fecha o primeiro, no meio do arquivo. Um
# corte durante os dados — como um disco cheio corta — terminava nessa marca e
# passava por íntegro. Recortar aqui em 60 KB cai depois da estrutura.
gzip -dc "$ARQ" | head -c 60000 | gzip > "$TMP/truncado.sql.gz"
R=$(./cli/backup.sh verificar "$TMP/truncado.sql.gz" 2>&1); afirma "recusa dump truncado nos dados" 'truncado' "$R"
R=$(MYSQLDATABASE="$B" DB_NAME="$B" CONFIRMAR="$B" ./cli/backup.sh restaurar "$TMP/truncado.sql.gz" 2>&1)
afirma "não restaura dump truncado" 'truncado' "$R"
head -c 2000 /dev/urandom > "$TMP/lixo.sql.gz"
R=$(./cli/backup.sh verificar "$TMP/lixo.sql.gz" 2>&1); afirma "recusa arquivo corrompido" 'corrompido' "$R"
R=$(MYSQLDATABASE="$B" DB_NAME="$B" ./cli/backup.sh restaurar "$ARQ" < /dev/null 2>&1)
afirma "sem confirmação não restaura" 'sem terminal para confirmar' "$R"
R=$(./cli/backup.sh restaurar "$TMP/nao-existe.sql.gz" 2>&1); afirma "recusa arquivo inexistente" 'não encontrado' "$R"
R=$(MYSQLDATABASE="$N" DB_NAME="$N" DB_PASS=errada MYSQLPASSWORD=errada BACKUP_DIR="$TMP/falha" ./cli/backup.sh 2>&1)
afirma "senha errada falha sem gravar" 'o dump falhou' "$R"
afirma_igual "e não deixa arquivo para trás" "0" "$(find "$TMP/falha" -type f 2>/dev/null | wc -l)"

echo "### 5. Faxina (retenção)"
for _ in 1 2 3; do MYSQLDATABASE="$A" DB_NAME="$A" BACKUP_DIR="$TMP/ret" BACKUP_MANTER=2 ./cli/backup.sh >/dev/null 2>&1; sleep 1; done
afirma_igual "mantém só os N mais novos" "2" "$(find "$TMP/ret" -name '*.sql.gz' | wc -l)"

echo "### 6. Certificado assinado pelo próprio banco (o do Railway)"
# O defeito: contra banco gerenciado, o cliente do MariaDB derrubava a conexão
# com "TLS/SSL error: self-signed certificate in certificate chain" (erro 2026)
# e NENHUM backup era gravado — todo dia, em silêncio, com o serviço reiniciando
# em laço. A cura é uma linha no arquivo de opções.
# O defeito ficou invisível no desenvolvimento por um motivo que vale registrar:
# o padrão MUDOU entre versões do cliente. O MariaDB 10.11 (o do contêiner de
# desenvolvimento) não verifica; o 11.4+ (o da imagem publicada) verifica. Por
# isso o script escreve o valor nos dois sentidos em vez de omitir a linha — e
# por isso a prova abaixo força a verificação em vez de confiar no padrão.
afirma "escreve a diretiva do certificado" 'loose-ssl-verify-server-cert=%s' "$(cat cli/backup.sh)"
afirma "e o BACKUP_SSL_VERIFICAR a inverte" 'BACKUP_SSL_VERIFICAR' "$(cat cli/backup.sh)"
# A prova de fogo só existe onde o servidor fala TLS. No banco local comum ela é
# pulada (não reprovada), pelo mesmo motivo da bateria do participante: vermelho
# por falta de ambiente ensina a ignorar vermelho. Para exercitá-la, suba um
# servidor com par autoassinado (`openssl req -x509` + `mariadbd --ssl-cert
# --ssl-key`) e aponte as DB_* para ele.
if [ "$(sql "SHOW VARIABLES LIKE 'have_ssl'" | awk '{print $2}')" = "YES" ]; then
    R=$(MYSQLDATABASE="$A" DB_NAME="$A" BACKUP_DIR="$TMP/tls1" BACKUP_SSL_VERIFICAR=1 ./cli/backup.sh 2>&1)
    afirma "com verificação, o certificado próprio derruba o dump" 'o dump falhou' "$R"
    afirma_igual "e nada é gravado" "0" "$(find "$TMP/tls1" -type f 2>/dev/null | wc -l)"
    R=$(MYSQLDATABASE="$A" DB_NAME="$A" BACKUP_DIR="$TMP/tls0" ./cli/backup.sh 2>&1)
    afirma "sem ela, o backup atravessa o TLS" '✓ ' "$R"
else
    echo "  ⏭  servidor sem TLS: a prova do certificado próprio foi pulada."
fi
# O prefixo `loose-` é o que faz a linha servir aos dois clientes: no da Oracle,
# que não conhece a opção, ela precisa virar AVISO — sem ele o arquivo de opções
# derruba o comando inteiro e a correção de um cliente vira defeito no outro.
CNF_TLS="$TMP/tls.cnf"
{ cat "$CNF"; printf 'loose-ssl-verify-server-cert=0\nloose-opcao-que-nao-existe=1\n'; } > "$CNF_TLS"
"$DUMP" --defaults-file="$CNF_TLS" --single-transaction --quick "$A" >/dev/null 2>"$TMP/tls.err"
afirma_igual "o cliente de dump aceita a diretiva" "0" "$?"
afirma_igual "e opção desconhecida não é erro" "0" \
    "$("$CLIENTE" --defaults-file="$CNF_TLS" -N -B -e "SELECT 1" >/dev/null 2>&1; echo $?)"

echo
if [ $FALHA -eq 0 ]; then
    echo "✓ backup: $OK verificação(ões), nenhuma falha."
else
    echo "✗ backup: $FALHA falha(s) em $((OK + FALHA)):"
    for f in "${FALHAS[@]}"; do echo "  - $f"; done
fi
exit $((FALHA > 0 ? 1 : 0))

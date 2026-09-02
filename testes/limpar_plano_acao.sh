#!/usr/bin/env bash
# Bateria do comando que zera o plano de ação (cli/limpar_plano_acao.php).
#
#   ./testes/limpar_plano_acao.sh
#
# Semeia, DIRETO NO BANCO, um projeto com iniciativa e duas ações, e tudo o que
# aponta para eles (comentário, cadeado, fator que virou ação, ideia da Coleta
# encaminhada, investimento vinculado); prova que `contar` não apaga, que
# `apagar` sem confirmação recusa, e que `apagar` confirmado apaga só o que
# deve e solta o que fica. Roda contra o banco da aplicação (local, nunca
# produção: ela apaga TODO o plano de ação desse banco). Sem cliente MySQL ou
# php ela se pula sozinha, como a do backup.
set -uo pipefail
cd "$(dirname "$0")/.."
OK=0; FALHA=0
declare -a FALHAS
ok()    { OK=$((OK + 1)); }
falha() { FALHA=$((FALHA + 1)); FALHAS+=("$1 | esperado: $2 | veio: $3"); }
afirma() { if echo "$3" | grep -qE "$2"; then ok; else falha "$1" "$2" "$(echo "$3" | head -c 200)"; fi; }
afirma_igual() { if [ "$2" = "$3" ]; then ok; else falha "$1" "$2" "$3"; fi; }

command -v php >/dev/null 2>&1 || { echo "  ⏭  pulada: php não está no PATH."; exit 0; }
mapfile -t -d '' CFG < <(php -r '
    $d = (require "config/config.php")["db"];
    foreach (["host","port","name","user","pass"] as $k) { echo (string) $d[$k], "\0"; }
' 2>/dev/null)
[ "${#CFG[@]}" -eq 5 ] || { echo "  ⏭  pulada: não li config/config.php."; exit 0; }
H=${CFG[0]}; P=${CFG[1]}; N=${CFG[2]}; U=${CFG[3]}; S=${CFG[4]}
CLIENTE=$(command -v mysql || command -v mariadb) || true
[ -n "$CLIENTE" ] || { echo "  ⏭  pulada: cliente MySQL/MariaDB não encontrado."; exit 0; }
TMP=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP"' EXIT HUP INT TERM
CNF="$TMP/c.cnf"; umask 077
printf '[client]\nhost="%s"\nport=%s\nuser="%s"\npassword="%s"\ndefault-character-set=utf8mb4\n' \
    "$H" "${P:-3306}" "$U" "$S" > "$CNF"
sql() { "$CLIENTE" --defaults-file="$CNF" -N -B -D "$N" -e "$1" 2>/dev/null; }

PLAN=$(sql "SELECT MIN(id) FROM planejamento")
USU=$(sql "SELECT MIN(id) FROM usuario")
[ -n "$PLAN" ] && [ -n "$USU" ] || { echo "  ⏭  pulada: banco sem planejamento ou usuário (rode o migrate)."; exit 0; }

# --- semente -------------------------------------------------------------
sql "INSERT INTO projeto (planejamento_id, tipo, ano, titulo, responsavel) VALUES ($PLAN,'ESTRATEGICO',2027,'Projeto de prova (limpar)','Prova')"
PRJ=$(sql "SELECT MAX(id) FROM projeto WHERE titulo='Projeto de prova (limpar)'")
sql "INSERT INTO iniciativa (projeto_id, titulo) VALUES ($PRJ,'Iniciativa de prova (limpar)')"
INI=$(sql "SELECT MAX(id) FROM iniciativa WHERE projeto_id=$PRJ")
sql "INSERT INTO desdobramento (projeto_id, iniciativa_id, o_que, quem, como) VALUES ($PRJ,$INI,'Ação de prova 1 (limpar)','Prova','x'),($PRJ,$INI,'Ação de prova 2 (limpar)','Prova','x')"
A1=$(sql "SELECT MIN(id) FROM desdobramento WHERE projeto_id=$PRJ")
A2=$(sql "SELECT MAX(id) FROM desdobramento WHERE projeto_id=$PRJ")
sql "INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto) VALUES ('PROJETO',$PRJ,$USU,'c (limpar)'),('DESDOBRAMENTO',$A1,$USU,'c (limpar)')"
sql "INSERT INTO edicao_bloqueio (recurso, registro_id, planejamento_id, usuario_id, expira_em) VALUES ('projeto',$PRJ,$PLAN,$USU,NOW()+INTERVAL 1 HOUR),('desdobramento',$A2,$PLAN,$USU,NOW()+INTERVAL 1 HOUR)"
sql "INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao, acao_em, acao_por, desdobramento_id) VALUES ($PLAN,2027,'SWOT','FORCA','Fator de prova (limpar)',NOW(),$USU,$A1)"
FAT=$(sql "SELECT MAX(id) FROM fator WHERE descricao='Fator de prova (limpar)'")
sql "INSERT INTO coleta_item (planejamento_id, ano, autor_id, texto, situacao, destino_tipo, destino_id) VALUES ($PLAN,2027,$USU,'Ideia de prova (limpar)','ACEITO','ACAO',$A2)"
IDE=$(sql "SELECT MAX(id) FROM coleta_item WHERE texto='Ideia de prova (limpar)'")
sql "INSERT INTO investimento (planejamento_id, projeto_id, descricao, ano, valor) VALUES ($PLAN,$PRJ,'Investimento de prova (limpar)',2027,1000)"
INV=$(sql "SELECT MAX(id) FROM investimento WHERE descricao='Investimento de prova (limpar)'")
afirma_igual "semente: duas ações no projeto" "2" "$(sql "SELECT COUNT(*) FROM desdobramento WHERE projeto_id=$PRJ")"

limpar_sementes() {
  sql "DELETE FROM investimento WHERE id=${INV:-0}"
  sql "DELETE FROM coleta_item WHERE id=${IDE:-0}"
  sql "DELETE FROM fator WHERE id=${FAT:-0}"
  sql "DELETE FROM desdobramento WHERE projeto_id=${PRJ:-0}"
  sql "DELETE FROM projeto WHERE id=${PRJ:-0}"
  sql "DELETE FROM comentario WHERE texto='c (limpar)'"
}
trap 'limpar_sementes; rm -rf "$TMP"' EXIT HUP INT TERM

# --- 1. contar não apaga ----------------------------------------------
R=$(php cli/limpar_plano_acao.php --planejamento=$PLAN 2>&1)
afirma "contar: lista o projeto e as ações" "1 projeto\(s\)" "$R"
afirma "contar: conta as duas ações" " 2 ação\(ões\)" "$R"
afirma "contar: diz que nada foi apagado" "Nada foi apagado" "$R"
afirma_igual "contar: as ações continuam lá" "2" "$(sql "SELECT COUNT(*) FROM desdobramento WHERE projeto_id=$PRJ")"

# --- 2. apagar sem confirmação recusa ---------------------------------
R=$(php cli/limpar_plano_acao.php apagar --planejamento=$PLAN --sem-backup </dev/null 2>&1); CODIGO=$?
afirma "sem confirmação: recusa dizendo como confirmar" "confirmo=APAGAR-PLANO-DE-ACAO" "$R"
afirma_igual "sem confirmação: sai com código 3" "3" "$CODIGO"
afirma_igual "sem confirmação: nada apagado" "2" "$(sql "SELECT COUNT(*) FROM desdobramento WHERE projeto_id=$PRJ")"
R=$(php cli/limpar_plano_acao.php apagar --planejamento=$PLAN --sem-backup --confirmo=errado </dev/null 2>&1)
afirma_igual "confirmação errada: nada apagado" "2" "$(sql "SELECT COUNT(*) FROM desdobramento WHERE projeto_id=$PRJ")"

# --- 3. apagar confirmado ---------------------------------------------
R=$(php cli/limpar_plano_acao.php apagar --planejamento=$PLAN --sem-backup --confirmo=APAGAR-PLANO-DE-ACAO 2>&1); CODIGO=$?
afirma_igual "apagar: sai com 0" "0" "$CODIGO"
afirma "apagar: relata o que apagou" "Plano de ação zerado" "$R"
afirma_igual "apagar: sem projetos no planejamento" "0" "$(sql "SELECT COUNT(*) FROM projeto WHERE planejamento_id=$PLAN")"
afirma_igual "apagar: sem iniciativas" "0" "$(sql "SELECT COUNT(*) FROM iniciativa WHERE id=$INI")"
afirma_igual "apagar: sem ações" "0" "$(sql "SELECT COUNT(*) FROM desdobramento WHERE id IN ($A1,$A2)")"
afirma_igual "apagar: comentários deles saíram" "0" "$(sql "SELECT COUNT(*) FROM comentario WHERE texto='c (limpar)'")"
afirma_igual "apagar: cadeados deles saíram" "0" "$(sql "SELECT COUNT(*) FROM edicao_bloqueio WHERE (recurso='projeto' AND registro_id=$PRJ) OR (recurso='desdobramento' AND registro_id=$A2)")"
afirma_igual "fica: o fator volta à fila (acao_em segue, desdobramento_id nulo)" "1|NULL" "$(sql "SELECT CONCAT(acao_em IS NOT NULL,'|',IFNULL(desdobramento_id,'NULL')) FROM fator WHERE id=$FAT")"
afirma_igual "fica: a ideia fica aceita, sem destino" "ACEITO|ACAO|NULL" "$(sql "SELECT CONCAT(situacao,'|',destino_tipo,'|',IFNULL(destino_id,'NULL')) FROM coleta_item WHERE id=$IDE")"
afirma_igual "fica: o investimento continua, sem projeto" "1|NULL" "$(sql "SELECT CONCAT(COUNT(*),'|',IFNULL(MAX(projeto_id),'NULL')) FROM investimento WHERE id=$INV")"

# --- 4. sem nada para apagar --------------------------------------------
R=$(php cli/limpar_plano_acao.php apagar --planejamento=$PLAN --sem-backup --confirmo=APAGAR-PLANO-DE-ACAO 2>&1)
afirma "vazio: diz que não há o que apagar" "Não há plano de ação" "$R"

echo
echo "✓ $OK passaram    ✗ $FALHA falharam"
if [ $FALHA -gt 0 ]; then printf '%s\n' "${FALHAS[@]}" | sed 's/^/  ✗ /'; fi
exit $([ $FALHA -eq 0 ] && echo 0 || echo 1)

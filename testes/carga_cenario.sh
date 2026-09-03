#!/usr/bin/env bash
# Bateria da carga de cenário com REVISÃO NO LUGAR (cli/carga_diagnostico.php
# e App\Services\CargaConteudo::aplicarCenario).
#
#   ./testes/carga_cenario.sh
#
# Semeia, num ano do ciclo que ninguém usa (o último), os textos ANTERIORES
# que a carga atual revisa (lidos do próprio arquivo de conteúdo, campo `de`),
# roda a prévia e a aplicação, e confere: o texto antigo foi atualizado no
# mesmo id (vínculos preservados), os assuntos novos entraram, nada duplicou, e
# a segunda passagem não grava nada. Apaga a semente no fim. Sem cliente MySQL
# ou php ela se pula sozinha, como a do backup.
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
CNF="$TMP/c.cnf"; umask 077
printf '[client]\nhost="%s"\nport=%s\nuser="%s"\npassword="%s"\ndefault-character-set=utf8mb4\n' \
    "$H" "${P:-3306}" "$U" "$S" > "$CNF"
sql() { "$CLIENTE" --defaults-file="$CNF" -N -B -D "$N" -e "$1" 2>/dev/null; }

# Planejamento corporativo e o ÚLTIMO ano do ciclo dele: dentro da faixa que a
# tela exibe (a CLI recusa ano fora do ciclo), e longe do que está em uso.
read -r PLAN ANO < <(sql "SELECT p.id, c.ano_fim FROM planejamento p JOIN ciclo c ON c.id = p.ciclo_id WHERE p.escopo='CORPORATIVO' ORDER BY p.id LIMIT 1")
[ -n "${PLAN:-}" ] && [ -n "${ANO:-}" ] || { echo "  ⏭  pulada: sem planejamento corporativo."; exit 0; }
[ "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")" = "0" ] \
    || { echo "  ⏭  pulada: o ano $ANO do planejamento $PLAN já tem itens de cenário."; exit 0; }
limpar() { sql "DELETE FROM cenario_item WHERE planejamento_id=${PLAN:-0} AND ano=${ANO:-0}"; rm -rf "$TMP"; }
trap limpar EXIT HUP INT TERM

# --- semente: os textos anteriores, lidos do próprio arquivo de conteúdo ----
php -r '
    $c = require "database/conteudo_cenario_macro.php";
    foreach ($c["itens"] as $tipo => $itens) {
        $ordem = 0;
        foreach ($itens as $i) {
            if (is_array($i)) echo $tipo, "\t", ++$ordem, "\t", str_replace(["\t","\n"], " ", $i["de"]), "\n";
        }
    }
' > "$TMP/antigos.tsv"
REVISOES=$(wc -l < "$TMP/antigos.tsv")
NOVOS=$(php -r '$c = require "database/conteudo_cenario_macro.php"; $n=0; foreach ($c["itens"] as $itens) foreach ($itens as $i) if (!is_array($i)) $n++; echo $n;')
while IFS=$'\t' read -r TIPO ORDEM TEXTO; do
  ESC=${TEXTO//\\/\\\\}; ESC=${ESC//\'/\'\'}
  sql "INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao) VALUES ($PLAN, $ANO, '$TIPO', $ORDEM, '$ESC')"
done < "$TMP/antigos.tsv"
afirma_igual "semente: os textos anteriores estão na tela" "$REVISOES" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")"
PRIMEIRO=$(sql "SELECT MIN(id) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")
ANTES=$(sql "SELECT LEFT(descricao, 40) FROM cenario_item WHERE id=$PRIMEIRO")

# --- 1. prévia ---------------------------------------------------------------
R=$(php cli/carga_diagnostico.php cenario "$PLAN" "$ANO" 2>&1)
afirma "prévia: diz que é prévia" "PRÉVIA — nada será gravado" "$R"
afirma "prévia: conta as revisões no lugar" "$NOVOS registro\(s\) a gravar, $REVISOES a atualizar no lugar, 0 já presente" "$R"
afirma_igual "prévia: não gravou nada" "$REVISOES" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")"

# --- 2. aplicar --------------------------------------------------------------
R=$(php cli/carga_diagnostico.php cenario "$PLAN" "$ANO" --aplicar 2>&1)
afirma "aplicar: relata o total gravado" "$((REVISOES + NOVOS)) registro" "$R"
afirma_igual "aplicar: revisões + novos, sem duplicar" "$((REVISOES + NOVOS))" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")"
DEPOIS=$(sql "SELECT LEFT(descricao, 40) FROM cenario_item WHERE id=$PRIMEIRO")
afirma_igual "aplicar: o primeiro item continua com o MESMO id" "1" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE id=$PRIMEIRO")"
[ "$ANTES" != "$DEPOIS" ] && ok || falha "aplicar: e o texto dele mudou" "texto novo" "$DEPOIS"
afirma_igual "aplicar: nenhum texto anterior sobrou" "0" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO AND descricao LIKE 'Juro em patamar recorde%'")"
afirma_igual "aplicar: um item por texto novo (sem duplicata)" "0" "$(sql "SELECT COUNT(*) FROM (SELECT descricao FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO GROUP BY descricao HAVING COUNT(*) > 1) d")"

# --- 3. segunda passagem não grava -------------------------------------------
R=$(php cli/carga_diagnostico.php cenario "$PLAN" "$ANO" 2>&1)
afirma "segunda prévia: tudo já presente" "0 registro\(s\) a gravar, 0 a atualizar no lugar, $((REVISOES + NOVOS)) já presente" "$R"
R=$(php cli/carga_diagnostico.php cenario "$PLAN" "$ANO" --aplicar 2>&1)
afirma_igual "segunda aplicação: contagem inalterada" "$((REVISOES + NOVOS))" "$(sql "SELECT COUNT(*) FROM cenario_item WHERE planejamento_id=$PLAN AND ano=$ANO")"

echo
echo "✓ $OK passaram    ✗ $FALHA falharam"
if [ $FALHA -gt 0 ]; then printf '%s\n' "${FALHAS[@]}" | sed 's/^/  ✗ /'; fi
exit $([ $FALHA -eq 0 ] && echo 0 || echo 1)

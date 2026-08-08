#!/usr/bin/env bash
# Bateria funcional: exercita os caminhos de ESCRITA de cada módulo pela própria
# API, do jeito que a tela os chama. Cada bloco cria, lê de volta, altera e
# apaga — e afirma o que o servidor DEVE recusar, que é onde moram as regras.
#
#   ./testes/funcional.sh
#
# Roda contra a instância LOCAL (ver testes/README.md). Cria e apaga a própria
# massa; assume o planejamento CORPORATIVO do ciclo 1 já semeado pelo migrate,
# com os horizontes começando em 2027 — por isso os anos de projeto são 2027.
set -uo pipefail
BASE=${APP_URL:-http://127.0.0.1:8099}
EMAIL=${APP_EMAIL:-admin@coperdia.com.br}
SENHA=${APP_SENHA:-trocar123}
J=/tmp/fj.txt; rm -f $J
OK=0; FALHA=0
declare -a FALHAS

ok()   { OK=$((OK+1)); }
falha(){ FALHA=$((FALHA+1)); FALHAS+=("$1 | esperado: $2 | veio: $3"); }

# afirma <nome> <padrão-esperado> <resposta>
afirma() {
  if echo "$3" | grep -qE "$2"; then ok; else falha "$1" "$2" "$(echo "$3" | head -c 200)"; fi
}

# nega <nome> <padrão-que-NÃO-pode-aparecer> <resposta>
# Para o que some: registro apagado em cascata não tem resposta própria para
# afirmar — o que se prova é a ausência dele na listagem.
nega() {
  if echo "$3" | grep -qE "$2"; then falha "$1" "sem $2" "$(echo "$3" | head -c 200)"; else ok; fi
}

login() {
  curl -s -c $J -o /dev/null $BASE/login
  curl -s -b $J -c $J -X POST $BASE/api/login -H 'Content-Type: application/json' \
    -d "{\"email\":\"$EMAIL\",\"senha\":\"$SENHA\"}" -o /dev/null
  CSRF=$(curl -s -b $J $BASE/ | grep -o 'name="csrf" content="[^"]*"' | sed 's/.*content="//;s/"//')
}
get()  { curl -s -b $J -H "X-CSRF-Token: $CSRF" "$BASE$1"; }
post() { curl -s -b $J -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -X POST "$BASE$1" -d "$2"; }
id_de(){ python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('id',''))" 2>/dev/null; }

login
P=1  # planejamento corporativo

echo "### 1. Diagnóstico — fator, promoção, GUT"
R=$(post /api/fatores '{"planejamento_id":1,"etapa":"PESTEL","categoria":"ECONOMICO","descricao":"Fator de teste automatizado","ano":2026}')
afirma "cria fator PESTEL" '"ok":true' "$R"
FAT=$(echo "$R" | id_de)
R=$(post /api/fatores "{\"planejamento_id\":1,\"etapa\":\"PESTEL\",\"categoria\":\"ECONOMICO\",\"descricao\":\"editado\"}" )
R=$(post /api/fatores/$FAT/promover '{"planejamento_id":1,"quadrante":"AMEACA"}')
afirma "promove PESTEL para a SWOT" '"ok":true' "$R"
PROM=$(echo "$R" | id_de)
R=$(post /api/fatores/$FAT/promover '{"planejamento_id":1,"quadrante":"AMEACA"}')
afirma "recusa promover duas vezes" 'já foi promovido' "$R"
R=$(post /api/fatores/$PROM/gut '{"planejamento_id":1,"gravidade":5,"urgencia":5,"tendencia":5,"esforco":"PEQUENO"}')
afirma "avalia GUT com esforço" '"score":125' "$R"
R=$(post /api/fatores/$PROM/gut '{"planejamento_id":1,"gravidade":9,"urgencia":5,"tendencia":5}')
afirma "recusa nota fora de 1..5" 'entre 1 e 5' "$R"
R=$(post /api/fatores/$PROM/gut '{"planejamento_id":1,"gravidade":5,"urgencia":5,"tendencia":5,"esforco":"ENORME"}')
afirma "recusa esforço inválido" 'Esforço inválido' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026")
afirma "esforço volta na listagem" '"esforco":"PEQUENO"' "$R"

echo "### 2. Cenário"
R=$(post /api/cenario '{"planejamento_id":1,"tipo":"TENDENCIA","descricao":"Item de teste","ano":2026}')
afirma "cria item de cenário" '"ok":true' "$R"
CEN=$(echo "$R" | id_de)

echo "### 3. Projetos — três níveis + recorrência"
R=$(post /api/projetos '{"planejamento_id":1,"titulo":"Projeto de teste","ano":2027,"responsavel":"QA","descricao":"x"}')
afirma "cria projeto" '"ok":true' "$R"
PRJ=$(echo "$R" | id_de)
R=$(post /api/iniciativas "{\"planejamento_id\":1,\"projeto_id\":$PRJ,\"titulo\":\"Frente de teste\"}")
afirma "cria iniciativa" '"ok":true' "$R"
INI=$(echo "$R" | id_de)
R=$(post /api/desdobramentos "{\"planejamento_id\":1,\"projeto_id\":$PRJ,\"iniciativa_id\":$INI,\"o_que\":\"Ação de teste\",\"quem\":\"QA\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0,\"recorrencia\":\"NENHUMA\"}")
afirma "cria ação 5W2H" '"ok":true' "$R"
ACAO=$(echo "$R" | id_de)
R=$(get "/api/projetos?planejamento_id=1")
afirma "projeto aparece com período consolidado das ações" '2027-01-01|01/01/2027' "$R"

echo "### 4. Investimentos — a máquina de estados"
R=$(post /api/investimentos '{"planejamento_id":1,"descricao":"Investimento de teste","valor":1000,"ano":2027,"situacao":"PROPOSTO"}')
afirma "cria investimento PROPOSTO" '"ok":true' "$R"
INV=$(echo "$R" | id_de)
R=$(post /api/investimentos/$INV/decidir '{"planejamento_id":1,"situacao":"APROVADO","decisao_criterio":"teste","decisao_data":"2026-08-08"}')
afirma "aprova investimento" '"ok":true' "$R"
post /api/investimentos/$INV "{\"planejamento_id\":1,\"descricao\":\"x\",\"valor\":1000,\"ano\":2027,\"situacao\":\"PROPOSTO\"}" >/dev/null
R=$(get "/api/investimentos?planejamento_id=1")
afirma "IGNORA voltar de APROVADO para PROPOSTO (segue APROVADO)" '"situacao":"APROVADO"' "$R"

echo "### 5. Metas e indicadores — versão de meta"
R=$(post /api/indicadores '{"planejamento_id":1,"nome":"Indicador de teste","unidade":"R$ mil","metrica_ancora":0}')
afirma "cria indicador" '"ok":true' "$R"
IND=$(echo "$R" | id_de)
R=$(post /api/indicadores/$IND/valores "{\"planejamento_id\":1,\"tipo\":\"META\",\"valores\":{\"2026\":100}}")
afirma "grava meta" '"ok":true' "$R"
R=$(post /api/indicadores/$IND/valores "{\"planejamento_id\":1,\"tipo\":\"META\",\"valores\":{\"2026\":200}}")
afirma "regrava meta (nova versão)" '"ok":true' "$R"
R=$(get "/api/indicadores?planejamento_id=1")
afirma "leitura usa a MAIOR versão (200, não 100)" '200' "$R"

echo "### 6. Coleta — ideia, triagem, encaminhamento"
R=$(post /api/coleta '{"planejamento_id":1,"texto":"Ideia de teste automatizado","ano":2026}')
afirma "cria ideia" '"ok":true' "$R"
IDEIA=$(echo "$R" | id_de)
R=$(post /api/coleta/$IDEIA/encaminhar '{"planejamento_id":1,"destino":"SWOT","categoria":"OPORTUNIDADE"}')
afirma "encaminha ideia para a SWOT" '"ok":true' "$R"
R=$(get "/api/coleta?planejamento_id=1&ano=2026")
afirma "ideia encaminhada continua na lista" 'Ideia de teste automatizado' "$R"
R=$(post /api/coleta/$IDEIA/reabrir '{"planejamento_id":1}')
afirma "desmarca o destino (reabrir)" '"ok":true' "$R"

echo "### 7. Relatório e reuniões"
R=$(get "/api/relatorio?planejamento_id=1&de=2026-01-01&ate=2026-12-31")
afirma "relatório responde" '"indicadores"' "$R"
afirma "relatório traz projetos" '"projetos"' "$R"
R=$(post /api/reunioes '{"planejamento_id":1,"data_reuniao":"2026-08-08","periodo_de":"2026-07-01","periodo_ate":"2026-08-08","participantes":"QA","decisoes":"teste","proximos_passos":"x"}')
afirma "registra reunião" '"ok":true' "$R"
REU=$(echo "$R" | id_de)

echo "### 8. Tempestade — rodada, PIN e as regras públicas"
R=$(post /api/rodadas '{"planejamento_id":1,"ano":2026,"tema":"O que trava o nosso crescimento?","max_ideias":3,"max_votos":2}')
afirma "abre rodada" '"ok":true|SALA_ABERTA' "$R"
PIN=$(echo "$R" | python3 -c "import sys,json
try: print(json.load(sys.stdin)['dados'].get('pin',''))
except: print('')" 2>/dev/null)
if [ -n "$PIN" ]; then
  R=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' -d "{\"pin\":\"$PIN\",\"nome\":\"Participante QA\"}")
  afirma "participante entra com o PIN" '"token"' "$R"
  TOK=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  R=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' -d '{"pin":"000000","nome":"x"}')
  afirma "PIN errado é recusado" '"ok":false' "$R"
  R=$(curl -s -X POST $BASE/api/publico/ideia -d "{\"token\":\"$TOK\",\"texto\":\"sem content-type\"}")
  afirma "RECUSA escrita sem Content-Type JSON" '"ok":false|^$' "$R"
  for i in 1 2 3 4; do
    R=$(curl -s -X POST $BASE/api/publico/ideia -H 'Content-Type: application/json' -d "{\"token\":\"$TOK\",\"texto\":\"ideia $i do teste\"}")
  done
  afirma "teto de 3 ideias é aplicado no 4º envio" '"ok":false' "$R"
fi

echo "### 9. Cruzamentos da SWOT (TOWS)"
# O bloco é DERIVADO do par, nunca escolhido: é a regra que este trecho guarda.
# A massa é própria — quatro fatores da SWOT criados e apagados aqui — porque a
# do diagnóstico carregado muda com a revisão do conteúdo.
cria_swot() { echo $(post /api/fatores "{\"planejamento_id\":1,\"etapa\":\"SWOT\",\"categoria\":\"$1\",\"descricao\":\"$2\",\"ano\":2026}" | id_de); }
SW_F=$(cria_swot FORCA        "Força de teste do cruzamento")
SW_FR=$(cria_swot FRAQUEZA    "Fraqueza de teste do cruzamento")
SW_O=$(cria_swot OPORTUNIDADE "Oportunidade de teste do cruzamento")
SW_A=$(cria_swot AMEACA       "Ameaça de teste do cruzamento")

R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_F,\"fator_externo_id\":$SW_O,\"rotulo\":\"Par de teste\",\"estrategia\":\"Estratégia de teste.\"}")
afirma "força × oportunidade nasce ATACAR" '"tipo":"ATACAR"' "$R"
CRUZ=$(echo "$R" | id_de)
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_FR,\"fator_externo_id\":$SW_A,\"rotulo\":\"Par de teste 2\",\"estrategia\":\"Outra.\"}")
afirma "fraqueza × ameaça nasce PROTEGER" '"tipo":"PROTEGER"' "$R"
CRUZ2=$(echo "$R" | id_de)
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_O,\"fator_externo_id\":$SW_F,\"rotulo\":\"x\",\"estrategia\":\"y\"}")
afirma "recusa par invertido (externo no lugar do interno)" 'INTERNO' "$R"
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_F,\"fator_externo_id\":$SW_FR,\"rotulo\":\"x\",\"estrategia\":\"y\"}")
afirma "recusa dois fatores internos" 'INTERNO' "$R"
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_F,\"fator_externo_id\":$SW_O,\"rotulo\":\"repetido\",\"estrategia\":\"z\"}")
afirma "recusa o mesmo par duas vezes no ano" 'já foi cruzado' "$R"
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_FR,\"fator_externo_id\":$SW_O,\"rotulo\":\"\",\"estrategia\":\"y\"}")
afirma "recusa cruzamento sem rótulo" '"ok":false' "$R"
R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$SW_FR,\"fator_externo_id\":$SW_O,\"rotulo\":\"sem estratégia\",\"estrategia\":\"\"}")
afirma "recusa cruzamento sem estratégia" '"ok":false' "$R"
# O par é a IDENTIDADE do cruzamento: na edição ele sai da linha, e um corpo
# forjado com outro par não pode mover a linha de bloco.
R=$(post /api/cruzamentos/$CRUZ "{\"planejamento_id\":1,\"fator_interno_id\":$SW_FR,\"fator_externo_id\":$SW_A,\"rotulo\":\"Renomeado\",\"estrategia\":\"Reescrita.\"}")
afirma "edição não troca o par pelo corpo" '"tipo":"ATACAR"' "$R"
R=$(get "/api/cruzamentos?planejamento_id=1&ano=2026")
afirma "listagem traz o par e o rótulo novo" "\"fator_interno_id\":$SW_F" "$R"
afirma "listagem traz a categoria do fator externo" '"externo_categoria":"OPORTUNIDADE"' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026")
afirma "fator conta os cruzamentos que o citam" '"cruzamentos":1' "$R"
# Apagado o fator, o cruzamento que o cita perde o sentido e vai junto (FK
# ON DELETE CASCADE) — a tela avisa antes, e este é o teste de que vai mesmo.
post /api/fatores/$SW_F/excluir '{"planejamento_id":1}' >/dev/null
R=$(get "/api/cruzamentos?planejamento_id=1&ano=2026")
nega "excluir o fator leva o cruzamento junto" "\"id\":$CRUZ," "$R"
afirma "e não leva os outros cruzamentos" "\"id\":$CRUZ2," "$R"

echo "### 10. Limpeza"
[ -n "${REU:-}" ]  && post /api/reunioes/$REU/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${ACAO:-}" ] && post /api/desdobramentos/$ACAO/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${INI:-}" ]  && post /api/iniciativas/$INI/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${PRJ:-}" ]  && post /api/projetos/$PRJ/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${INV:-}" ]  && post /api/investimentos/$INV/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${IND:-}" ]  && post /api/indicadores/$IND/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${IDEIA:-}" ]&& post /api/coleta/$IDEIA/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${CEN:-}" ]  && post /api/cenario/$CEN/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${FAT:-}" ]  && post /api/fatores/$FAT/excluir '{"planejamento_id":1}' >/dev/null
# Os fatores da SWOT levam consigo os cruzamentos que sobraram (FK em cascata),
# então basta apagá-los — não há ordem a respeitar aqui.
for F in "${SW_FR:-}" "${SW_O:-}" "${SW_A:-}"; do
  [ -n "$F" ] && post /api/fatores/$F/excluir '{"planejamento_id":1}' >/dev/null
done

echo
echo "=========================================="
echo "✓ $OK passaram    ✗ $FALHA falharam"
if [ $FALHA -gt 0 ]; then
  printf '%s\n' "${FALHAS[@]}" | sed 's/^/  ✗ /'
fi
exit $([ $FALHA -gt 0 ] && echo 1 || echo 0)

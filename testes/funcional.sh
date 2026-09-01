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
# campo_de <id> <campo> — de uma LISTAGEM na entrada, o valor de um campo de UM
# registro, em JSON. Afirmar contra a resposta inteira não serve para valor que
# se repete: `"esforco":null` casa com qualquer outro fator sem estimativa (e
# são quase todos), e a prova passaria verde sem provar nada.
campo_de(){ python3 -c "
import sys, json
alvo, campo = sys.argv[1], sys.argv[2]
linhas = json.load(sys.stdin)['dados']
print(json.dumps(next((l.get(campo) for l in linhas if str(l.get('id')) == alvo), '__ausente__')))
" "$1" "$2" 2>/dev/null; }

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
# A tela não pergunta mais o esforço: ela manda G, U e T e MAIS NADA. Salvar
# assim não pode apagar a estimativa que já estava gravada — sem a guarda do
# array_key_exists, o `?? ''` vira NULL e o UPDATE limpa a coluna calado.
R=$(post /api/fatores/$PROM/gut '{"planejamento_id":1,"gravidade":4,"urgencia":4,"tendencia":4}')
afirma "reavalia sem mandar esforço" '"score":64' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026" | campo_de $PROM esforco)
afirma "esforço antigo sobrevive à reavaliação" '^"PEQUENO"$' "$R"
# A outra metade da mesma guarda, e é ela que impede a correção virar uma coluna
# congelada: DECLARADO vazio, o esforço apaga. É o "não estimado" de quem chama
# a rota com o campo na mão — sem esta prova, trocar o array_key_exists por um
# `if ($esforco !== '')` passaria verde e deixaria a estimativa presa para
# sempre, sem nenhuma tela por onde limpá-la.
R=$(post /api/fatores/$PROM/gut '{"planejamento_id":1,"gravidade":4,"urgencia":4,"tendencia":4,"esforco":""}')
afirma "esforço declarado vazio é aceito" '"score":64' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026" | campo_de $PROM esforco)
afirma "esforço declarado vazio limpa a estimativa" '^null$' "$R"

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
BASE_ACAO="\"planejamento_id\":1,\"projeto_id\":$PRJ,\"iniciativa_id\":$INI,\"quem\":\"QA\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0,\"recorrencia\":\"NENHUMA\""
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Ação de teste\",\"como\":\"Passo a passo do teste\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\"}")
afirma "cria ação 5W2H" '"ok":true' "$R"
ACAO=$(echo "$R" | id_de)
# O asterisco do modal DESENHA a obrigatoriedade, não a impõe: quem recusa é o
# servidor, e por aqui passa também o direcionamento de uma ideia da Coleta.
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Sem caminho\",\"como\":\"\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\"}")
afirma "recusa ação sem o Como" 'Como' "$R"
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Sem prazo\",\"como\":\"x\",\"data_inicio\":\"\",\"data_fim\":\"\"}")
afirma "recusa ação sem período" 'Quando' "$R"
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Só começo\",\"como\":\"x\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"\"}")
afirma "recusa ação sem fim previsto" 'Quando' "$R"
R=$(get "/api/projetos?planejamento_id=1")
afirma "projeto aparece com período consolidado das ações" '2027-01-01|01/01/2027' "$R"

# --- Repetição: a grade decide o prazo, e ela aceita MAIS DE UM dia ---
# "Toda segunda e quinta" é UMA rotina. Enquanto o semanal aceitava um dia só,
# quem precisava de dois cadastrava a mesma tarefa duas vezes — e as duas
# cobravam a mesma pessoa, em relatórios separados.
BASE_REP="\"planejamento_id\":1,\"projeto_id\":$PRJ,\"iniciativa_id\":$INI,\"quem\":\"QA\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0"
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Rotina semanal\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[1,4],\"recorrencia_ate\":\"2027-12-31\"}")
afirma "cria ação semanal com DOIS dias" '"ok":true' "$R"
REP=$(echo "$R" | id_de)
R=$(get "/api/projetos?planejamento_id=1")
afirma "os dois dias da semana voltam na listagem" '"recorrencia_dias":"1,4"' "$R"
# A data de vencimento é DERIVADA da grade: sem ela gravada, o atraso
# automático, os avisos por e-mail e o prazo do projeto não teriam o que ler.
afirma "a semanal ganha data_fim derivada da grade" '"data_fim":"20[0-9][0-9]-[0-9]{2}-[0-9]{2}"' "$R"
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Rotina mensal\",\"como\":\"x\",\"recorrencia\":\"MENSAL\",\"recorrencia_dias\":[5,20],\"recorrencia_ate\":\"2027-12-31\"}")
afirma "cria ação mensal com dois dias" '"ok":true' "$R"
# Concluir uma ocorrência reabre na PRÓXIMA data da grade — e com dois dias
# marcados a próxima é a mais perto deles, não "daqui a uma semana". Era isso
# que o corte para um dia só impedia de acontecer, e é o que faz "toda segunda
# e quinta" ser uma rotina em vez de duas ações.
VENCE=$(python3 -c "
import json,sys,datetime
p=json.load(sys.stdin)['dados']
a=[x for pr in p for i in pr['iniciativas'] for x in i['acoes'] if x['id']==$REP][0]
print(a['data_fim'])" <<< "$(get "/api/projetos?planejamento_id=1")")
R=$(post /api/desdobramentos/$REP "{$BASE_REP,\"o_que\":\"Rotina semanal\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[1,4],\"recorrencia_ate\":\"2027-12-31\",\"status\":\"CONCLUIDO\"}")
PROX=$(python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('reagendada_para') or '')" <<< "$R")
afirma "concluir a semanal reabre na próxima data" '20[0-9][0-9]-' "$PROX"
# Segunda (1) e quinta (4) nunca distam sete dias uma da outra: o salto de uma
# semana é a assinatura do bug de guardar um dia só.
AVANCO=$(python3 -c "
import datetime
a=datetime.date.fromisoformat('$VENCE'); b=datetime.date.fromisoformat('$PROX')
print((b-a).days)" 2>/dev/null)
afirma "a próxima ocorrência cai no OUTRO dia da grade, não sete dias depois" '^(3|4)$' "$AVANCO"
# O fim da repetição é OPCIONAL (decisão do cliente): em branco, a rotina é por
# tempo INDETERMINADO. A prova é o par — ela entra, e entra com `recorrencia_ate`
# NULO. Gravar uma data qualquer no lugar do vazio (hoje, ou o fim do ciclo)
# encerraria sozinha uma rotina que ninguém mandou encerrar.
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Rotina sem prazo\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[2],\"recorrencia_ate\":\"\"}")
afirma "aceita repetição sem data fim" '"ok":true' "$R"
SEMFIM=$(echo "$R" | id_de)
R=$(get "/api/projetos?planejamento_id=1")
afirma "a rotina sem prazo fica com recorrencia_ate nulo" "\"id\":$SEMFIM,.*\"recorrencia_ate\":null" "$R"
# Mas data ESCRITA tem de ser data: sem esta recusa, um texto que não parseia
# viraria um null silencioso — e a rotina que alguém quis limitar passaria a
# não acabar nunca, que é justamente o significado do campo em branco.
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Fim torto\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[2],\"recorrencia_ate\":\"amanhã\"}")
afirma "recusa data fim que não é data" 'Data inválida' "$R"
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Sem dia\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[],\"recorrencia_ate\":\"2027-12-31\"}")
afirma "recusa semanal sem nenhum dia marcado" 'ao menos um dia da semana' "$R"
R=$(post /api/desdobramentos "{$BASE_REP,\"o_que\":\"Dia inválido\",\"como\":\"x\",\"recorrencia\":\"SEMANAL\",\"recorrencia_dias\":[9],\"recorrencia_ate\":\"2027-12-31\"}")
afirma "recusa dia da semana fora de 1..7" 'ao menos um dia da semana' "$R"

# --- Ganhos previstos: número e só número ---
# `(float)` cego transformava qualquer texto num silencioso R$ 0,00 — o campo
# ficava preenchido com um valor que ninguém digitou.
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Com ganhos\",\"como\":\"x\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\",\"quanto\":1500.5}")
afirma "aceita ganhos previstos com centavos" '"ok":true' "$R"
GAN=$(echo "$R" | id_de)
R=$(get "/api/projetos?planejamento_id=1")
afirma "os ganhos voltam com os centavos" '"quanto":"1500.50"' "$R"
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Ganho torto\",\"como\":\"x\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\",\"quanto\":\"mil reais\"}")
afirma "recusa ganhos que não são número" 'apenas números' "$R"
R=$(post /api/desdobramentos "{$BASE_ACAO,\"o_que\":\"Ganho negativo\",\"como\":\"x\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\",\"quanto\":-50}")
afirma "recusa ganhos negativos" 'negativo' "$R"

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

echo "### 8b. Reentrada do participante — o aparelho, o nome e o dono ativo"
# Cunhar um token novo a cada entrada fazia de quem voltava OUTRA pessoa: as
# estrelas apareciam apagadas, o teto zerava e os votos antigos seguiam
# contando. O desenho aqui é o do Quiz Copérdia (`POST /api/rooms/:pin/join`).
#
# A janela de ausência é encurtada por ambiente para a prova do "dono calado"
# não custar os 45 s do padrão. Ela vale no SERVIDOR — rodá-la contra uma
# instância subida sem SALA_AUSENTE_SEG deixa essas duas provas em vermelho.
if [ -n "${PIN:-}" ]; then
  pub(){ curl -s -X POST $BASE/api/publico/$1 -H 'Content-Type: application/json' -d "$2"; }
  token_de(){ python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('token',''))" 2>/dev/null; }
  APAR="aparelho-qa-$$"

  R=$(pub entrar "{\"pin\":\"$PIN\",\"nome\":\"Fulano QA\",\"dispositivo\":\"$APAR\"}")
  afirma "entra com nome e aparelho" '"token"' "$R"
  T1=$(echo "$R" | token_de)

  # 1. O aparelho volta como a MESMA pessoa, sem digitar nada.
  R=$(pub entrar "{\"pin\":\"$PIN\",\"dispositivo\":\"$APAR\"}")
  afirma "o mesmo aparelho volta sem informar nome" '"voltou":true' "$R"
  afirma "e volta com o MESMO token" "^$T1\$" "$(echo "$R" | token_de)"

  # 2. Com o dono ATIVO (as chamadas acima acabaram de marcar presença), o nome
  #    não devolve a identidade: seria entregar a credencial de quem está lá.
  R=$(pub entrar "{\"pin\":\"$PIN\",\"nome\":\"Fulano QA\",\"dispositivo\":\"outro-$$\"}")
  afirma "nome de quem está na sala agora é recusado" 'Já há alguém com esse nome' "$R"

  # 3. Calado o dono, o mesmo nome o traz de volta — é ele trocando de aparelho.
  #    A espera é a janela REAL do servidor, e o padrão são 5 minutos: parar a
  #    bateria por isso não paga. Estas duas só rodam quando a janela foi
  #    encurtada de propósito — no servidor E aqui, com a mesma variável.
  if [ -n "${SALA_AUSENTE_SEG:-}" ]; then
    sleep "$SALA_AUSENTE_SEG"
    R=$(pub entrar "{\"pin\":\"$PIN\",\"nome\":\"Fulano QA\",\"dispositivo\":\"outro-$$\"}")
    afirma "dono calado: o nome devolve a identidade" '"voltou":true' "$R"
    afirma "e é o mesmo token de antes" "^$T1\$" "$(echo "$R" | token_de)"
  else
    echo "  … 'dono calado' fora desta rodada: suba o servidor e rode a bateria"
    echo "    com SALA_AUSENTE_SEG=6 para provar a reentrada pelo nome."
  fi

  # 4. "Não é você?" solta o aparelho: a próxima pessoa nesta máquina entra como
  #    ela mesma, em vez de herdar a anterior.
  R=$(pub esquecer "{\"pin\":\"$PIN\",\"dispositivo\":\"$APAR\"}")
  afirma "esquecer o aparelho responde ok" '"ok":true' "$R"
  # Pedido só com aparelho é a PERGUNTA "me conhece?" — aparelho solto responde
  # "não" sem erro, para não encher o console de quem chega pela primeira vez.
  R=$(pub entrar "{\"pin\":\"$PIN\",\"dispositivo\":\"$APAR\"}")
  afirma "e o aparelho solto não devolve mais ninguém" '"conhecido":false' "$R"
  nega "sem devolver token de ninguém" '"token"' "$R"
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
echo "### 9b. Cruzamento → plano de ação"
# A terceira origem da MESMA fila (as outras duas são a ideia da coleta e o
# fator da SWOT). O cruzamento vai DIRETO ao plano, sem passar pela cascata:
# ele já é a estratégia que nasce do par.
R=$(get "/api/cruzamentos/aguardando-acao?planejamento_id=1")
nega "cruzamento não encaminhado fica fora da fila" "\"id\":$CRUZ2," "$R"
R=$(post /api/cruzamentos/$CRUZ2/plano-acao '{"planejamento_id":1}')
afirma "encaminha o cruzamento ao plano" '"acao_em":true' "$R"
R=$(get "/api/cruzamentos/aguardando-acao?planejamento_id=1")
afirma "e ele aparece na fila com a estratégia" '"origem":"TOWS"' "$R"
afirma "com o bloco como categoria" '"categoria":"PROTEGER"' "$R"
# Desmarcar só vale enquanto a ação não existe.
R=$(post /api/cruzamentos/$CRUZ2/plano-acao '{"planejamento_id":1,"marcar":false}')
afirma "tirar da fila desfaz o encaminhamento" '"acao_em":false' "$R"
R=$(get "/api/cruzamentos/aguardando-acao?planejamento_id=1")
nega "e ele some da fila" "\"id\":$CRUZ2," "$R"

post /api/cruzamentos/$CRUZ2/plano-acao '{"planejamento_id":1}' >/dev/null
ACAO_CRUZ=$(post /api/desdobramentos "{$BASE_ACAO,\"cruzamento_id\":$CRUZ2,\"o_que\":\"Ação do cruzamento\",\"como\":\"passo\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\"}" | id_de)
R=$(get "/api/cruzamentos/aguardando-acao?planejamento_id=1")
nega "virada a ação, sai da fila" "\"id\":$CRUZ2," "$R"
# As três recusas que evitam ação órfã no plano.
R=$(post /api/cruzamentos/$CRUZ2/excluir '{"planejamento_id":1}')
afirma "recusa excluir cruzamento que virou ação" 'já virou ação' "$R"
R=$(post /api/cruzamentos/$CRUZ2/plano-acao '{"planejamento_id":1,"marcar":false}')
afirma "recusa tirar da fila depois da ação criada" 'já virou ação' "$R"
# Apagar o fator do par derrubaria o cruzamento por CASCATA e deixaria a ação
# sem origem — a guarda compartilhada precisa enxergar esse caminho também.
R=$(post /api/fatores/$SW_FR/excluir '{"planejamento_id":1}')
afirma "recusa excluir o fator do par que virou ação" 'já virou' "$R"
# Apagada a AÇÃO, o cruzamento volta sozinho para a fila (FK ON DELETE SET NULL).
post /api/desdobramentos/$ACAO_CRUZ/excluir '{"planejamento_id":1}' >/dev/null
R=$(get "/api/cruzamentos/aguardando-acao?planejamento_id=1")
afirma "apagada a ação, o cruzamento volta para a fila" "\"id\":$CRUZ2," "$R"
post /api/cruzamentos/$CRUZ2/plano-acao '{"planejamento_id":1,"marcar":false}' >/dev/null

# Apagado o fator, o cruzamento que o cita perde o sentido e vai junto (FK
# ON DELETE CASCADE) — a tela avisa antes, e este é o teste de que vai mesmo.
post /api/fatores/$SW_F/excluir '{"planejamento_id":1}' >/dev/null
R=$(get "/api/cruzamentos?planejamento_id=1&ano=2026")
nega "excluir o fator leva o cruzamento junto" "\"id\":$CRUZ," "$R"
afirma "e não leva os outros cruzamentos" "\"id\":$CRUZ2," "$R"

echo "### 9c. Excluir usuário — validação, transferência e o que fica sem dono"
# Excluir alguém é o gesto que mexe em treze colunas de uma vez. O que estas
# provas seguram é justamente o que a tela NÃO mostra: se a carteira some em
# silêncio, o cartão da ação continua plausível, com um nome antigo escrito nele
# e cobrança nenhuma saindo — e ninguém descobre até a pessoa cobrada reclamar.
U_SAI=$(post /api/usuarios '{"nome":"Zeca da Prova","email":"zeca.prova@teste.local","senha":"trocar123","perfil":"CONTROLADORIA","negocios":[]}' | id_de)
U_FICA=$(post /api/usuarios '{"nome":"Ana da Prova","email":"ana.prova@teste.local","senha":"trocar123","perfil":"CONTROLADORIA","negocios":[]}' | id_de)
U_OFF=$(post /api/usuarios '{"nome":"Inativo da Prova","email":"off.prova@teste.local","senha":"trocar123","perfil":"CONTROLADORIA","ativo":false,"negocios":[]}' | id_de)
UPRJ=$(post /api/projetos '{"planejamento_id":1,"titulo":"Projeto da exclusão","ano":2027,"responsavel":"QA","descricao":"x"}' | id_de)
UINI=$(post /api/iniciativas "{\"planejamento_id\":1,\"projeto_id\":$UPRJ,\"titulo\":\"Frente da exclusão\"}" | id_de)
ACAO_BASE="\"planejamento_id\":1,\"projeto_id\":$UPRJ,\"iniciativa_id\":$UINI,\"como\":\"x\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0,\"recorrencia\":\"NENHUMA\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\""
UACAO=$(post /api/desdobramentos "{$ACAO_BASE,\"o_que\":\"Ação do Zeca\",\"quem\":\"Zeca da Prova\"}" | id_de)

R=$(get "/api/usuarios/$U_SAI/vinculos")
afirma "vínculos contam a ação do plano" '"chave":"desdobramento.quem_usuario_id","rotulo":"ação do plano","total":1' "$R"
afirma "vínculos separam a carteira do resto" '"carteira":1' "$R"
nega "quem sai não aparece como destino de si mesmo" "\"valor\":$U_SAI," "$R"
nega "inativo não entra na lista de quem pode receber" "\"valor\":$U_OFF," "$R"
# Sem destino declarado a rota RECUSA. É a guarda central: o caminho mais curto
# (só o id na URL) seria justamente o que apaga o dono de toda a carteira sem
# ninguém ter escolhido isso, e "não respondi" viraria uma resposta.
R=$(post /api/usuarios/$U_SAI/excluir '{}')
afirma "recusa excluir sem dizer para quem vai" 'DESTINO_OBRIGATORIO' "$R"
R=$(get /api/usuarios | campo_de $U_SAI nome)
afirma "e a recusa não excluiu ninguém" '^"Zeca da Prova"$' "$R"
R=$(post /api/usuarios/$U_SAI/excluir "{\"transferir_para\":$U_OFF}")
afirma "recusa transferir para quem está inativo" 'inativo' "$R"
R=$(post /api/usuarios/$U_SAI/excluir "{\"transferir_para\":$U_SAI}")
afirma "recusa transferir para quem está saindo" 'não dá para transferir' "$R"

R=$(post /api/usuarios/$U_SAI/excluir "{\"transferir_para\":$U_FICA}")
afirma "exclui transferindo a carteira" '"transferido":"Ana da Prova"' "$R"
R=$(get /api/usuarios | campo_de $U_SAI nome)
afirma "o usuário saiu do cadastro" '^"__ausente__"$' "$R"
# O NOME escrito na ação anda junto com o id. Atualizar só o id deixaria o
# cartão exibindo quem já saiu enquanto a cobrança ia para outra pessoa — duas
# verdades na mesma linha, e a errada é a que se lê.
R=$(get "/api/projetos?planejamento_id=1" | python3 -c "
import sys, json
a = [a for p in json.load(sys.stdin)['dados'] for i in p['iniciativas'] for a in i['acoes'] if a['id'] == $UACAO]
print(json.dumps({'quem': a[0]['quem'], 'id': a[0]['quem_usuario_id']} if a else {}))")
afirma "a ação passou para quem recebeu — nome e id juntos" "\"quem\": \"Ana da Prova\", \"id\": $U_FICA" "$R"

# A outra saída: ninguém assume. A ação FICA (não se apaga trabalho porque uma
# pessoa saiu), mas fica sem dono e sem nome — é o vazio que a tela lê como
# «Sem usuário». Gravar ali o nome de quem saiu seria a mentira mais fácil.
R=$(post /api/usuarios/$U_FICA/excluir '{"sem_responsavel":true}')
afirma "exclui deixando sem responsável" '"transferido":null' "$R"
R=$(get "/api/projetos?planejamento_id=1" | python3 -c "
import sys, json
a = [a for p in json.load(sys.stdin)['dados'] for i in p['iniciativas'] for a in i['acoes'] if a['id'] == $UACAO]
print(json.dumps({'quem': a[0]['quem'], 'id': a[0]['quem_usuario_id']} if a else {}))")
afirma "a ação continua no plano, agora sem dono" '"quem": "", "id": null' "$R"

# Os dois impedimentos que transferência nenhuma resolve. Sem eles o sistema
# fica sem saída: ninguém para criar usuário, nem para chegar de novo a esta
# tela — o conserto seria no banco, à mão.
EU=$(get /api/usuarios | python3 -c "import sys,json;print([u['id'] for u in json.load(sys.stdin)['dados'] if u['email']=='$EMAIL'][0])")
R=$(post /api/usuarios/$EU/excluir '{"sem_responsavel":true}')
afirma "recusa excluir a própria conta" 'própria conta' "$R"
R=$(get /api/usuarios | campo_de $EU excluivel)
afirma "e o ✕ dele nem aparece na tela" '^0$' "$R"
R=$(post /api/usuarios/$U_OFF/excluir '{"sem_responsavel":true}')
afirma "exclui usuário sem vínculo nenhum" '"ok":true' "$R"

echo "### 9d. Comentários — remover UM anexo sem perder o comentário"
# O comentário é o único envio multipart da API (o anexo não viaja em JSON),
# então este bloco tem o `post` próprio.
postm(){ curl -s -b $J -H "X-CSRF-Token: $CSRF" -X POST "$BASE$1" "${@:2}"; }
# Os ids dos anexos de UM comentário, na ordem — a listagem não os traz soltos.
anexos_de(){ python3 -c "
import sys, json
alvo = sys.argv[1]
c = next((c for c in json.load(sys.stdin)['dados'] if str(c['id']) == alvo), None)
print(' '.join(str(a['id']) for a in (c or {}).get('anexos', [])))
" "$1" 2>/dev/null; }
# Os ids dos COMENTÁRIOS da listagem. Afirmar com regex sobre o corpo cru não
# serve aqui: comentário e anexo dividem o espaço de ids, e `"id":7` casa com o
# anexo 7 dentro do comentário 6 — a prova passava (ou falhava) por sorteio.
ids_com(){ python3 -c "
import sys, json
print(' '.join(str(c['id']) for c in json.load(sys.stdin)['dados']))" 2>/dev/null; }
LISTA_COM="/api/comentarios?planejamento_id=1&ref_tipo=PROJETO&ref_id=${PRJ:-0}"
# PNG de 1×1 de verdade: o servidor confere a imagem com `getimagesize`, e um
# arquivo qualquer renomeado para .png é recusado — como deve ser.
PNG=/tmp/fa1.png
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' \
  | base64 -d > $PNG

R=$(postm /api/comentarios -F "planejamento_id=1" -F "ref_tipo=PROJETO" -F "ref_id=${PRJ:-0}" \
  -F "texto=Comentário com dois anexos" -F "arquivos[]=@$PNG" -F "arquivos[]=@$PNG;filename=segundo.png")
afirma "cria comentário com 2 anexos" '"anexos":2' "$R"
COM=$(echo "$R" | id_de)
IDS=$(get "$LISTA_COM" | anexos_de "$COM")
A1=$(echo $IDS | cut -d' ' -f1); A2=$(echo $IDS | cut -d' ' -f2)

R=$(post /api/anexos/$A1/excluir '{"planejamento_id":1}')
afirma "remove um anexo" '"comentario_excluido":false' "$R"
L=$(get "$LISTA_COM")
afirma "o comentário continua lá" "(^| )$COM( |\$)" "$(echo "$L" | ids_com)"
RESTA=$(echo "$L" | anexos_de "$COM")
afirma "sobrou exatamente o outro anexo" "^$A2\$" "$RESTA"
# O anexo removido não desce mais: a rota de download é a prova, não a listagem.
R=$(get "/api/anexos/$A1?planejamento_id=1")
afirma "o anexo removido não baixa mais" 'não encontrado' "$R"

# Comentário sem texto é só o arquivo: tirar o único anexo o esvazia, e
# comentário vazio não existe — a regra é a mesma que `criar` aplica na entrada.
R=$(postm /api/comentarios -F "planejamento_id=1" -F "ref_tipo=PROJETO" -F "ref_id=${PRJ:-0}" \
  -F "texto=" -F "arquivos[]=@$PNG")
afirma "cria comentário só com anexo" '"anexos":1' "$R"
SOANEXO=$(echo "$R" | id_de)
A3=$(get "$LISTA_COM" | anexos_de "$SOANEXO")
R=$(post /api/anexos/$A3/excluir '{"planejamento_id":1}')
afirma "último anexo sem texto leva o comentário" '"comentario_excluido":true' "$R"
nega "e o comentário sumiu da lista" "(^| )$SOANEXO( |\$)" "$(get "$LISTA_COM" | ids_com)"

R=$(post /api/anexos/99999999/excluir '{"planejamento_id":1}')
afirma "recusa anexo inexistente" 'não encontrado' "$R"
rm -f $PNG

# ─────────────────────────────────────────────────────────────────────────────
echo "### 9e. Matriz de Impacto — a autorização, que é o tema inteiro"
#
# Aqui mora a única exceção ao modelo de acesso do sistema (PLANEJAMENTO-SISTEMA
# §5): o GESTOR passa a LER descrição de fator do plano corporativo e a GRAVAR a
# célula do negócio dele. Toda a prova abaixo é sobre o LIMITE dessa exceção —
# não sobre a tela, que é a parte fácil.
#
# A sessão do gestor é OUTRA: `login` sobrescreve $J e $CSRF, então o admin é
# reautenticado antes da limpeza. Sem isso, tudo o que viesse depois rodaria
# como gestor e falharia por um motivo que nada tem a ver com o que se mede.
#
# E as credenciais do admin são GUARDADAS antes de trocar, não relidas de $EMAIL:
# em bash, `VAR=valor funcao` deixa a atribuição valendo DEPOIS que a função
# retorna (ao contrário do que acontece com um comando externo). Um `login` seco
# no fim reentraria como gestor — e a limpeza falharia em silêncio, deixando
# usuário de prova no banco para a próxima execução tropeçar.
IMP_ADM_EMAIL=$EMAIL
IMP_ADM_SENHA=$SENHA
admin_de_volta() { EMAIL=$IMP_ADM_EMAIL; SENHA=$IMP_ADM_SENHA; login; }

IMP_N1=$(get "/api/negocios" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'][0]['id'])" 2>/dev/null)
IMP_N2=$(get "/api/negocios" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'][1]['id'])" 2>/dev/null)
IMP_F=$(get "/api/impacto?ciclo_id=1&ano=2026" | python3 -c "
import sys, json
f = json.load(sys.stdin)['dados']['fatores']
print(f[0]['id'] if f else '')" 2>/dev/null)

if [ -z "${IMP_F:-}" ] || [ -z "${IMP_N1:-}" ]; then
  ok
  echo "  (pulada: sem SWOT corporativa de 2026 ou sem negócio ativo)"
else
  R=$(get "/api/impacto?ciclo_id=1&ano=2026")
  afirma "admin recebe a grade com o score da GUT" '"score"' "$R"

  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N1,\"sinal\":\"NEGATIVO\",\"texto\":\"Aperta a margem (prova)\"}")
  afirma "admin grava uma célula" '"ok":true' "$R"
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N2,\"sinal\":\"POSITIVO\"}")
  afirma "admin grava a célula de outro negócio" '"ok":true' "$R"
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N1,\"sinal\":\"TALVEZ\"}")
  afirma "recusa sinal fora da lista" 'Sinal inválido' "$R"

  post /api/usuarios "{\"nome\":\"Gestor Prova Impacto\",\"email\":\"gestor.impacto@teste.local\",\"senha\":\"trocar123\",\"perfil\":\"GESTOR\",\"negocios\":[$IMP_N1]}" >/dev/null
  post /api/usuarios "{\"nome\":\"Leitor Prova Impacto\",\"email\":\"leitor.impacto@teste.local\",\"senha\":\"trocar123\",\"perfil\":\"LEITURA\",\"negocios\":[$IMP_N1]}" >/dev/null

  EMAIL=gestor.impacto@teste.local SENHA=trocar123 login
  R=$(get "/api/impacto?ciclo_id=1&ano=2026")
  afirma "gestor LÊ a descrição do fator corporativo" '"descricao"' "$R"
  # O score é o que a decisão de acesso manteve fora do alcance dele: é a
  # priorização, não o fato. Sai do PAYLOAD, não só da tela.
  nega "e o score da GUT NÃO vaza para o gestor" '"score"' "$R"
  VISTOS=$(echo "$R" | python3 -c "
import sys, json
d = json.load(sys.stdin)['dados']
print(','.join(str(n['id']) for n in d['negocios']))" 2>/dev/null)
  afirma "gestor vê só o negócio dele" "^$IMP_N1\$" "$VISTOS"
  CELS=$(echo "$R" | python3 -c "
import sys, json
d = json.load(sys.stdin)['dados']
print(','.join(str(c['negocio_id']) for c in d['celulas']))" 2>/dev/null)
  nega "e não recebe a célula do negócio alheio" "(^|,)$IMP_N2(,|\$)" "$CELS"

  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N1,\"sinal\":\"NEGATIVO\",\"texto\":\"Escrito pelo gestor\"}")
  afirma "gestor GRAVA a célula do negócio dele" '"ok":true' "$R"
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N2,\"sinal\":\"POSITIVO\"}")
  afirma "gestor é recusado na célula alheia" 'Sem acesso a este negócio' "$R"
  # A linha é conferida contra o plano corporativo: sem isso um id qualquer de
  # fator viraria linha nova pela borda, invisível na grade e eterna na tabela.
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":${FAT:-0},\"negocio_id\":$IMP_N1,\"sinal\":\"POSITIVO\"}")
  afirma "gestor não inventa linha com fator de fora da matriz" 'não é uma linha da matriz' "$R"

  EMAIL=leitor.impacto@teste.local SENHA=trocar123 login
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N1,\"sinal\":\"POSITIVO\"}")
  afirma "LEITURA nunca grava" 'somente leitura' "$R"
  R=$(get "/api/impacto?ciclo_id=1&ano=2026")
  afirma "LEITURA lê, com pode_editar falso" '"pode_editar":false' "$R"

  admin_de_volta   # para a limpeza e para o resto do arquivo
  R=$(post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N1,\"sinal\":\"\"}")
  afirma "sinal vazio apaga a célula" '"apagada":true' "$R"
  R=$(get "/api/impacto?ciclo_id=1&ano=2026")
  nega "e ela não volta na listagem" 'Escrito pelo gestor' "$R"
  post /api/impacto "{\"ciclo_id\":1,\"fator_id\":$IMP_F,\"negocio_id\":$IMP_N2,\"sinal\":\"\"}" >/dev/null

  # `IMP_UID`, e não `UID`: em bash `UID` é SOMENTE LEITURA (o id do usuário do
  # sistema), e a atribuição morre com "readonly variable" — a limpeza não
  # rodava e os usuários de prova ficavam no banco, fazendo a execução seguinte
  # falhar ao criar o mesmo e-mail.
  for E in gestor.impacto@teste.local leitor.impacto@teste.local; do
    IMP_UID=$(get "/api/usuarios" | python3 -c "
import sys, json
print(next((u['id'] for u in json.load(sys.stdin)['dados'] if u['email'] == '$E'), ''))" 2>/dev/null)
    [ -n "$IMP_UID" ] && post /api/usuarios/$IMP_UID/excluir '{"sem_responsavel":true}' >/dev/null
  done
fi

echo "### 10. Limpeza"
[ -n "${COM:-}" ]  && post /api/comentarios/$COM/excluir '{"planejamento_id":1}' >/dev/null
[ -n "${UPRJ:-}" ] && post /api/projetos/$UPRJ/excluir '{"planejamento_id":1}' >/dev/null
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

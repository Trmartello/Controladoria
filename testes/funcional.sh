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

echo "### 8c. Questionário prévio — perguntas em ordem, teto por pergunta, prazo"
#
# Pedido do cliente (2026-09-03): a tempestade nasce com perguntas, respondidas
# pelo celular antes do encontro. O que a rota pública RECUSA é o que importa
# aqui: ideia sem pergunta num questionário, pergunta de outra sala, e o teto —
# que passa a contar POR PERGUNTA, senão quem gastasse tudo na primeira ficaria
# calado nas outras. `confirmar_encerrar` fecha a sala que a seção 8 deixou.
R=$(post /api/rodadas '{"planejamento_id":1,"ano":2026,"tema":"Preparação do encontro","max_ideias":2,"max_votos":2,"prazo":"2000-01-01","perguntas":"Uma","confirmar_encerrar":true}')
afirma "prazo no passado é recusado antes de mexer em sala nenhuma" 'no futuro' "$R"
R=$(post /api/rodadas '{"planejamento_id":1,"ano":2026,"tema":"Preparação do encontro","max_ideias":2,"max_votos":2,"prazo":"2099-12-31","perguntas":"O que trava o crescimento?\n\nQue oportunidade estamos perdendo?\nO que trava o crescimento?\nOnde perdemos dinheiro?","confirmar_encerrar":true}')
afirma "abre a tempestade com questionário" '"pin"' "$R"
afirma "linha vazia e pergunta repetida não entram: três perguntas" '"perguntas":3' "$R"
QP_ID=$(echo "$R" | id_de)
QP_PIN=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pin',''))" 2>/dev/null)
if [ -n "$QP_PIN" ]; then
  R=$(curl -s "$BASE/api/publico/rodada/$QP_PIN" | python3 -c "
import sys, json
p = json.load(sys.stdin)['dados']
print(json.dumps({'ordens': [q['ordem'] for q in p['perguntas']], 'prazo': p.get('prazo'),
  'ids': [q['id'] for q in p['perguntas']]}))" 2>/dev/null)
  afirma "o celular recebe as três perguntas em ordem" '"ordens": \[1, 2, 3\]' "$R"
  afirma "e o prazo, até o fim do dia" '2099-12-31 23:59:59' "$R"
  QP_P1=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['ids'][0])")
  QP_P2=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['ids'][1])")
  QP_TOK=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$QP_PIN\",\"nome\":\"Cooperado que responde antes\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  qp_ideia(){ curl -s -X POST $BASE/api/publico/ideia -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$QP_PIN\",\"token\":\"$QP_TOK\",$1\"texto\":\"$2\"}"; }
  R=$(qp_ideia '' 'Sem pergunta')
  afirma "ideia sem pergunta é recusada num questionário" 'Escolha a pergunta' "$R"
  R=$(qp_ideia '"pergunta_id":999999,' 'Pergunta de outra sala')
  afirma "pergunta que não é desta rodada é recusada" 'Escolha a pergunta' "$R"
  R=$(qp_ideia "\"pergunta_id\":$QP_P1," 'Sucessao nas propriedades')
  afirma "ideia na pergunta 1 entra" '"ok":true' "$R"
  R=$(qp_ideia "\"pergunta_id\":$QP_P1," 'Credito caro')
  afirma "a segunda da pergunta 1 também" '"ok":true' "$R"
  R=$(qp_ideia "\"pergunta_id\":$QP_P1," 'Terceira estoura')
  afirma "o teto de 2 é POR PERGUNTA, e a recusa diz isso" 'nesta pergunta' "$R"
  R=$(qp_ideia "\"pergunta_id\":$QP_P2," 'Marca propria no varejo')
  afirma "e a pergunta 2 continua aberta" '"ok":true' "$R"
  R=$(curl -s "$BASE/api/publico/minhas?pin=$QP_PIN&token=$QP_TOK")
  afirma "as ideias voltam ao celular com a pergunta" "\"pergunta_id\":$QP_P2" "$R"
  QP_I2=$(echo "$R" | python3 -c "
import sys, json
print(next((i['id'] for i in json.load(sys.stdin)['dados'] if i['texto'] == 'Marca propria no varejo'), ''))" 2>/dev/null)
  R=$(get "/api/coleta?planejamento_id=1&ano=2026" | campo_de $QP_I2 pergunta_ordem)
  afirma "na Coleta a ideia sabe a que pergunta respondeu" '^2$' "$R"
  R=$(post /api/rodadas/$QP_ID/perguntas '{"planejamento_id":1,"perguntas":"Quarta pergunta, no fim"}')
  afirma "acrescenta uma pergunta ao questionário aberto" '"gravadas":1' "$R"
  R=$(curl -s "$BASE/api/publico/rodada/$QP_PIN" | python3 -c "
import sys, json; print([q['ordem'] for q in json.load(sys.stdin)['dados']['perguntas']])" 2>/dev/null)
  afirma "e ela entra no FIM, sem mexer na numeração" '^\[1, 2, 3, 4\]$' "$R"
  R=$(get "/api/rodadas?planejamento_id=1" | python3 -c "
import sys, json
r = next((x for x in json.load(sys.stdin)['dados'] if x['id'] == $QP_ID), {})
print(json.dumps({'n': len(r.get('perguntas', [])), 'ideias1': r['perguntas'][0]['ideias'], 'gente1': r['perguntas'][0]['respondentes']}))" 2>/dev/null)
  afirma "a lista do condutor conta ideias e pessoas por pergunta" '"ideias1": 2, "gente1": 1' "$R"
  # As ESTRELAS do questionário (pedido de 2026-09-04): liberadas para quem
  # concluiu, SEM o condutor fechar a sala — e o teto (2) conta POR PERGUNTA.
  # Um segundo participante enche a pergunta 2 para o teto ter onde estourar.
  QP_TOK2=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$QP_PIN\",\"nome\":\"Segundo cooperado\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  for t in 'Loja online' 'Entrega na propriedade'; do
    curl -s -X POST $BASE/api/publico/ideia -H 'Content-Type: application/json' \
      -d "{\"pin\":\"$QP_PIN\",\"token\":\"$QP_TOK2\",\"pergunta_id\":$QP_P2,\"texto\":\"$t\"}" >/dev/null
  done
  R=$(curl -s "$BASE/api/publico/votar?pin=$QP_PIN&token=$QP_TOK")
  afirma "no questionário as ★ vêm liberadas com a sala ainda recolhendo" '"estrelas":"ABERTA"' "$R"
  afirma "e a chave da sala segue FECHADA: o campo de escrever continua lá" '"votacao":"FECHADA"' "$R"
  QP_I1A=$(echo "$R" | python3 -c "import sys,json;print([i['id'] for i in json.load(sys.stdin)['dados']['itens'] if i['pergunta_id']==$QP_P1][0])" 2>/dev/null)
  QP_X1=$(echo "$R" | python3 -c "import sys,json;print([i['id'] for i in json.load(sys.stdin)['dados']['itens'] if i['texto']=='Loja online'][0])" 2>/dev/null)
  QP_X2=$(echo "$R" | python3 -c "import sys,json;print([i['id'] for i in json.load(sys.stdin)['dados']['itens'] if i['texto']=='Entrega na propriedade'][0])" 2>/dev/null)
  qp_voto(){ curl -s -X POST $BASE/api/publico/votar/$1 -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$QP_PIN\",\"token\":\"$QP_TOK\"}"; }
  R=$(qp_voto $QP_I2); afirma "primeira estrela na pergunta 2" '"votou":true' "$R"
  R=$(qp_voto $QP_X1); afirma "segunda estrela na pergunta 2" '"votou":true' "$R"
  R=$(qp_voto $QP_X2); afirma "a terceira estoura o teto DA PERGUNTA, e a recusa diz isso" 'nesta pergunta' "$R"
  R=$(qp_voto $QP_I1A); afirma "a pergunta 1 tem estrelas próprias" '"votou":true' "$R"
  R=$(curl -s "$BASE/api/publico/votar?pin=$QP_PIN&token=$QP_TOK")
  afirma "o celular recebe as estrelas usadas em cada pergunta" "\"$QP_P2\":2" "$R"
  R=$(qp_voto $QP_I2); afirma "tocar de novo tira a estrela" '"votou":false' "$R"
  post /api/rodadas/$QP_ID/encerrar '{"planejamento_id":1}' >/dev/null
  # Sem questionário nada muda: as ★ esperam o condutor fechar a sala
  R=$(post /api/rodadas '{"planejamento_id":1,"ano":2026,"tema":"Tema único","max_ideias":2,"max_votos":2,"confirmar_encerrar":true}')
  TU_ID=$(echo "$R" | id_de)
  TU_PIN=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pin',''))" 2>/dev/null)
  TU_TOK=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$TU_PIN\",\"nome\":\"Alguém\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  curl -s -X POST $BASE/api/publico/ideia -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$TU_PIN\",\"token\":\"$TU_TOK\",\"texto\":\"Uma ideia\"}" >/dev/null
  R=$(curl -s "$BASE/api/publico/votar?pin=$TU_PIN&token=$TU_TOK")
  afirma "sem questionário, as ★ seguem esperando o condutor fechar a sala" '"estrelas":"FECHADA"' "$R"
  post /api/rodadas/$TU_ID/encerrar '{"planejamento_id":1}' >/dev/null
  # O prazo fecha a rodada SOZINHO — na primeira leitura depois dele, porque
  # não há relógio no servidor. Quatro segundos de prazo, e a leitura seguinte
  # já a vê encerrada. O prazo é escrito no FUSO DO APLICATIVO (`TZ_APP` em
  # config.php, América/São Paulo por padrão), que é o do PHP e o da conexão
  # com o banco (`SET time_zone` em Database): no relógio da shell, em UTC,
  # ele cairia três horas à frente e a prova esperaria para sempre.
  QP_PRAZO=$(TZ="${TZ_APP:-America/Sao_Paulo}" date -d '+4 seconds' '+%Y-%m-%d %H:%M:%S')
  R=$(post /api/rodadas "{\"planejamento_id\":1,\"ano\":2026,\"tema\":\"Prazo curto\",\"prazo\":\"$QP_PRAZO\",\"perguntas\":\"Só uma\",\"confirmar_encerrar\":true}")
  afirma "abre a rodada com prazo de segundos" '"pin"' "$R"
  QP_PIN2=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pin',''))" 2>/dev/null)
  sleep 5
  R=$(curl -s "$BASE/api/publico/rodada/$QP_PIN2")
  afirma "passado o prazo, o celular já a encontra encerrada" '"situacao":"ENCERRADA"' "$R"
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

# ─────────────────────────────────────────────────────────────────────────────
echo "### 9f. Pulso — o contador que faz duas telas se acompanharem"
#
# A marcação é montada em DUAS metades, cada uma num ponto de passagem
# obrigatório (`Auth::exigirEdicaoPlanejamento` diz o alvo, `Database::executar`
# diz que houve escrita). O que estas provas medem é justamente que nenhuma das
# duas depende de alguém ter lembrado: leitura não sobe, escrita sobe, e escrita
# recusada antes de tocar o banco não sobe.

pulso() { get "/api/pulso?ciclo_id=1" | python3 -c "
import sys, json
v = json.load(sys.stdin)['dados'].get('versoes', {})
print(v.get('1', 0) if isinstance(v, dict) else 0)" 2>/dev/null; }

P0=$(pulso)
get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" >/dev/null
get "/api/projetos?planejamento_id=1" >/dev/null
get "/api/cascata?planejamento_id=1" >/dev/null
P1=$(pulso)
afirma "três LEITURAS não mexem no pulso" "^$P0\$" "$P1"

R=$(post /api/fatores '{"planejamento_id":1,"etapa":"PESTEL","categoria":"SOCIAL","descricao":"Fator do pulso","ano":2026}')
PULSO_F=$(echo "$R" | id_de)
P2=$(pulso)
afirma "INSERT sobe o pulso" "^$((P1 + 1))\$" "$P2"

post /api/fatores/$PULSO_F '{"planejamento_id":1,"categoria":"LEGAL","descricao":"Fator do pulso (editado)"}' >/dev/null
P3=$(pulso)
afirma "UPDATE sobe o pulso" "^$((P2 + 1))\$" "$P3"

# O DELETE é o que nenhum "MAX(atualizado_em)" pegaria: apagar não deixa carimbo
# em lugar nenhum, e a outra tela seguiria mostrando o que já não existe.
post /api/fatores/$PULSO_F/excluir '{"planejamento_id":1}' >/dev/null
P4=$(pulso)
afirma "DELETE sobe o pulso" "^$((P3 + 1))\$" "$P4"

R=$(post /api/fatores '{"planejamento_id":1,"etapa":"PESTEL","categoria":"NAO_EXISTE","descricao":"x","ano":2026}')
afirma "a escrita inválida é recusada" '"ok":false' "$R"
P5=$(pulso)
afirma "e recusada antes do banco, não sobe o pulso" "^$P4\$" "$P5"

# A rota é chamada a cada poucos segundos por admin: um ciclo sem escrita
# nenhuma tem de devolver OBJETO vazio, não lista — a tela indexa por id.
R=$(get "/api/pulso?ciclo_id=99999")
afirma "ciclo sem escrita devolve objeto vazio, não lista" '"versoes":\{\}' "$R"
R=$(get "/api/pulso")
afirma "pulso sem ciclo é recusado" 'Informe o ciclo' "$R"

echo "### 9g. Cadeado de edição — um item aberto por vez"
#
# A regra em uma frase: enquanto um admin tem o item aberto, ninguém mais
# GRAVA nele. Quem prova isso é o servidor, não a tela — a tela só evita o
# atrito de abrir um formulário que já ia ser recusado.
#
# Duas sessões de verdade, cada uma com seu cookie e seu CSRF. Sem isso não há
# o que provar: com uma sessão só, todo cadeado é "meu" e todas as guardas
# passam por engano.
JB=/tmp/fj-b.txt; rm -f $JB
post /api/usuarios '{"nome":"Bruna do Cadeado","email":"bruna.cadeado@teste.local","senha":"trocar123","perfil":"CONTROLADORIA","negocios":[]}' >/dev/null
curl -s -c $JB -o /dev/null $BASE/login
curl -s -b $JB -c $JB -X POST $BASE/api/login -H 'Content-Type: application/json' \
  -d '{"email":"bruna.cadeado@teste.local","senha":"trocar123"}' -o /dev/null
CSRF_B=$(curl -s -b $JB $BASE/ | grep -o 'name="csrf" content="[^"]*"' | sed 's/.*content="//;s/"//')
postb() { curl -s -b $JB -H "X-CSRF-Token: $CSRF_B" -H 'Content-Type: application/json' -X POST "$BASE$1" -d "$2"; }
getb()  { curl -s -b $JB -H "X-CSRF-Token: $CSRF_B" "$BASE$1"; }

CAD_F=$(post /api/fatores '{"planejamento_id":1,"etapa":"PESTEL","categoria":"SOCIAL","descricao":"Item disputado","ano":2026}' | id_de)
CAD_B="{\"recurso\":\"fator\",\"registro_id\":$CAD_F,\"planejamento_id\":1}"

R=$(post /api/bloqueio "$CAD_B")
afirma "quem chega primeiro fica com o item" '"meu":true' "$R"
afirma "e recebe os 5 minutos combinados" '"restam":(29[0-9]|300)' "$R"
R=$(postb /api/bloqueio "$CAD_B")
afirma "o segundo vê que o item é de outro" '"meu":false' "$R"
afirma "com o NOME de quem está editando" '"usuario":"Administrador"' "$R"

# O coração da coisa. A tela do segundo admin nem abre o formulário, mas a
# prova tem de ser feita por baixo dela: é o servidor que não pode deixar a
# gravação passar, porque quem chama a API não é obrigado a ser a tela.
R=$(postb /api/fatores/$CAD_F '{"planejamento_id":1,"categoria":"LEGAL","descricao":"passei por cima"}')
afirma "o segundo NÃO grava por cima" 'está editando' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" | campo_de $CAD_F descricao)
afirma "e o texto do primeiro continua inteiro" '^"Item disputado"$' "$R"
R=$(postb /api/fatores/$CAD_F/excluir '{"planejamento_id":1}')
afirma "nem exclui o item que o outro está editando" 'está editando' "$R"
R=$(post /api/fatores/$CAD_F '{"planejamento_id":1,"categoria":"SOCIAL","descricao":"Item disputado (dono salvou)"}')
afirma "quem tem o cadeado grava normalmente" '"ok":true' "$R"

# O "+1 minuto" ESTENDE. Escrito como `expira_em = NOW() + 60` ele ENCURTARIA
# um cadeado recém-tomado (de 300 para 60) — o botão de ganhar tempo tirando
# tempo de quem clicou. Por isso o `GREATEST(expira_em, NOW()) + 60`.
restam_de(){ python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['restam'])" 2>/dev/null; }
ANTES=$(post /api/bloqueio/renovar "$CAD_B" | restam_de)
R=$(post /api/bloqueio/renovar "$CAD_B" | restam_de)
afirma "o +1 minuto estende em vez de encurtar" "^(3[5-9][0-9]|4[0-2][0-9])$" "$R"
[ "$R" -gt "$ANTES" ] && ok || falha "cada +1 minuto soma sobre o anterior" ">$ANTES" "$R"

R=$(get "/api/pulso?ciclo_id=1")
afirma "o pulso conta o cadeado para as outras telas" "\"recurso\":\"fator\",\"registro_id\":$CAD_F" "$R"
afirma "e leva o nome, que é o que aparece no cartão" '"usuario":"Administrador"' "$R"
R=$(getb "/api/pulso?ciclo_id=1" | python3 -c "
import sys, json
b = [x for x in json.load(sys.stdin)['dados']['bloqueios'] if x['registro_id'] == $CAD_F]
print(json.dumps(b[0]['meu'] if b else None))")
afirma "o 'meu' é de quem pergunta, não do dono do cadeado" '^false$' "$R"

# O que acontece aos 0:00, que foi a decisão mais delicada do desenho: soltar o
# cadeado NÃO invalida o texto de quem estava escrevendo. Enquanto ninguém
# assumir o item, a gravação dele ainda passa — sem isso, quem estivesse
# redigindo aos 4:59 perderia o parágrafo, e o recurso feito para não perder
# trabalho passaria a perder trabalho. O `soltar` aqui faz o papel do relógio
# chegando a zero: nos dois casos o item fica LIVRE.
post /api/bloqueio/soltar "$CAD_B" >/dev/null
R=$(post /api/fatores/$CAD_F '{"planejamento_id":1,"categoria":"SOCIAL","descricao":"O texto sobreviveu ao 0:00"}')
afirma "sem cadeado e sem ninguém no lugar, a gravação passa" '"ok":true' "$R"
R=$(get "/api/pulso?ciclo_id=1")
nega "cadeado solto some do pulso na hora" "\"registro_id\":$CAD_F" "$R"

# E a outra metade: se ALGUÉM assumiu no intervalo, aí sim a gravação é
# recusada — com a mensagem que manda copiar o texto antes de fechar.
postb /api/bloqueio "$CAD_B" >/dev/null
R=$(post /api/fatores/$CAD_F '{"planejamento_id":1,"categoria":"SOCIAL","descricao":"tarde demais"}')
afirma "mas se alguém assumiu, a gravação é recusada" 'Bruna do Cadeado está editando' "$R"
afirma "e a recusa diz o que fazer com o texto" 'copie o que você escreveu' "$R"
postb /api/bloqueio/soltar "$CAD_B" >/dev/null

# Recurso fora do catálogo é recusado na entrada: a rota recebe o nome da
# tabela vindo do navegador, e sem a lista fechada `RECURSOS` ela viraria uma
# chave livre para encher a tabela de cadeados que nada solta.
R=$(post /api/bloqueio '{"recurso":"usuario","registro_id":1,"planejamento_id":1}')
afirma "recusa recurso fora do catálogo" '"ok":false' "$R"

# As mesmas guardas nos outros recursos — o valor de ter uma regra só é ela
# valer igual em todos, e é isso que estas duas medem.
if [ -n "${ACAO:-}" ]; then
  postb /api/bloqueio "{\"recurso\":\"desdobramento\",\"registro_id\":$ACAO,\"planejamento_id\":1}" >/dev/null
  R=$(post /api/desdobramentos/$ACAO "{\"planejamento_id\":1,\"projeto_id\":$PRJ,\"iniciativa_id\":$INI,\"o_que\":\"por cima\",\"quem\":\"QA\",\"como\":\"x\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0,\"recorrencia\":\"NENHUMA\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\"}")
  afirma "a mesma guarda vale para a ação do plano" 'está editando' "$R"
  postb /api/bloqueio/soltar "{\"recurso\":\"desdobramento\",\"registro_id\":$ACAO,\"planejamento_id\":1}" >/dev/null
fi
if [ -n "${PRJ:-}" ]; then
  postb /api/bloqueio "{\"recurso\":\"projeto\",\"registro_id\":$PRJ,\"planejamento_id\":1}" >/dev/null
  R=$(post /api/projetos/$PRJ '{"planejamento_id":1,"titulo":"por cima","ano":2027,"responsavel":"x","descricao":"x"}')
  afirma "e para o projeto" 'está editando' "$R"
  postb /api/bloqueio/soltar "{\"recurso\":\"projeto\",\"registro_id\":$PRJ,\"planejamento_id\":1}" >/dev/null
fi

# Tomar e renovar ESCREVEM no banco, mas não podem contar como mudança do
# plano: a cada renovação as outras telas se repintariam inteiras, que é o
# oposto exato do que o pulso existe para fazer. Daí o `Versao::ignorar()`.
PC0=$(pulso)
post /api/bloqueio "$CAD_B" >/dev/null
post /api/bloqueio/renovar "$CAD_B" >/dev/null
post /api/bloqueio/soltar "$CAD_B" >/dev/null
afirma "cadeado não conta como mudança do plano" "^$PC0\$" "$(pulso)"

post /api/fatores/$CAD_F/excluir '{"planejamento_id":1}' >/dev/null
CAD_U=$(get "/api/usuarios" | python3 -c "
import sys, json
print(next((u['id'] for u in json.load(sys.stdin)['dados'] if u['email'] == 'bruna.cadeado@teste.local'), ''))" 2>/dev/null)
[ -n "$CAD_U" ] && post /api/usuarios/$CAD_U/excluir "{\"transferir_para\":1}" >/dev/null
rm -f $JB

echo "### 9h. Mudar de análise ATRAVESSANDO a tabela (Cenário ⇄ fator)"
#
# Entre análises, mover é `UPDATE fator SET etapa`: o id não muda e nada mais
# precisa mudar. Para a Análise de Cenário o id MORRE — é outra tabela —, e o
# que estas provas medem é justamente o que o id sustentava: o texto, o ano, a
# marca do plano de ação e, sobretudo, as VOZES DA SALA.
MV_F=$(post /api/fatores '{"planejamento_id":1,"etapa":"PESTEL","categoria":"SOCIAL","descricao":"Item que atravessa","ano":2026}' | id_de)
R=$(post /api/fatores/$MV_F/mover '{"planejamento_id":1,"etapa":"CENARIO"}')
afirma "sem dizer o tipo, o destino Cenário é recusado" 'situação atual ou como tendência' "$R"
R=$(post /api/fatores/$MV_F/mover '{"planejamento_id":1,"etapa":"CENARIO","tipo":"NAO_EXISTE"}')
afirma "tipo inventado é recusado" 'situação atual ou como tendência' "$R"
R=$(post /api/fatores/$MV_F/mover '{"planejamento_id":1,"etapa":"CENARIO","tipo":"TENDENCIA"}')
afirma "move o fator para a Análise de Cenário" '"destino":"CENARIO"' "$R"
MV_C=$(echo "$R" | id_de)
R=$(get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" | campo_de $MV_F descricao)
afirma "e ele sai do PESTEL" '^"__ausente__"$' "$R"
R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_C descricao)
afirma "com o texto inteiro do outro lado" '^"Item que atravessa"$' "$R"
R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_C tipo)
afirma "e no tipo pedido" '^"TENDENCIA"$' "$R"

R=$(post /api/cenario/$MV_C/mover '{"planejamento_id":1,"etapa":"SWOT","categoria":"SITUACAO_ATUAL"}')
afirma "recusa categoria de outro catálogo" 'não se correspondem' "$R"
R=$(post /api/cenario/$MV_C/mover '{"planejamento_id":1,"etapa":"NAO_EXISTE","categoria":"AMEACA"}')
afirma "recusa análise que não existe" 'análise de destino' "$R"
R=$(post /api/cenario/$MV_C/mover '{"planejamento_id":1,"etapa":"SWOT","categoria":"AMEACA"}')
afirma "e volta, virando fator da SWOT" '"destino":"SWOT"' "$R"
MV_F2=$(echo "$R" | id_de)
R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_C descricao)
afirma "o item sai da Análise de Cenário" '^"__ausente__"$' "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026" | campo_de $MV_F2 categoria)
afirma "e chega na categoria escolhida" '^"AMEACA"$' "$R"
post /api/fatores/$MV_F2/excluir '{"planejamento_id":1}' >/dev/null

# A ideia da COLETA acompanha o item. Sem isso ela ficaria apontando para um id
# morto — o rastreio da tela exibiria vínculo quebrado e a ideia ficaria presa
# em ACEITO sobre um registro que não existe mais.
MV_ID=$(post /api/coleta '{"planejamento_id":1,"texto":"Ideia que atravessa","ano":2026}' | id_de)
post /api/coleta/$MV_ID/encaminhar '{"planejamento_id":1,"destino":"SWOT","categoria":"AMEACA"}' >/dev/null
MV_FC=$(get "/api/coleta?planejamento_id=1&ano=2026" | campo_de $MV_ID destino_id | tr -d '"')
R=$(post /api/fatores/$MV_FC/mover '{"planejamento_id":1,"etapa":"CENARIO","tipo":"SITUACAO_ATUAL"}')
MV_CC=$(echo "$R" | id_de)
R=$(get "/api/coleta?planejamento_id=1&ano=2026" | campo_de $MV_ID destino_tipo)
afirma "a ideia da Coleta segue o item para a outra tabela" '^"CENARIO"$' "$R"
R=$(get "/api/coleta?planejamento_id=1&ano=2026" | campo_de $MV_ID destino_id)
afirma "apontando para o registro NOVO" "^$MV_CC\$" "$R"
R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_CC coleta_vozes)
afirma "e o item novo já nasce mostrando a voz que o originou" '^1$' "$R"

# A voz do QUIZ é o caso difícil, e o que ela mede é uma guarda que não existia:
# ela vem de uma pergunta cujo ALVO era o Cenário, e depois da travessia mora
# num fator. O "solta quem saiu do conjunto" do `vincularSugestoes` alcançava
# QUALQUER voz do quiz amarrada ao registro — então a primeira edição do fator
# soltava, calada, exatamente a voz que a travessia acabou de preservar.
#
# `confirmar_encerrar` porque a seção 8 deixou uma tempestade aberta e a sala é
# UMA por projeto: sem ele a rota devolve SALA_ABERTA/409 pedindo confirmação, e
# este bloco inteiro era pulado — verde por não ter rodado, que é o pior jeito
# de uma prova passar.
R=$(post /api/quiz/abrir '{"planejamento_id":1,"alvo_tipo":"CENARIO","ano":2026,"tema":"Prova da travessia","max_ideias":3,"confirmar_encerrar":true}')
# Afirmado de propósito: o bloco abaixo é condicional, e uma sala que não
# abrisse o pularia inteiro em silêncio — verde sem ter rodado.
afirma "abre a sala no Cenário para a prova da travessia" '"pergunta_id"' "$R"
MV_PIN=$(echo "$R" | python3 -c "import sys,json
try: print(json.load(sys.stdin)['dados'].get('pin',''))
except: print('')" 2>/dev/null)
MV_PERG=$(echo "$R" | python3 -c "import sys,json
try: print(json.load(sys.stdin)['dados'].get('pergunta_id') or '')
except: print('')" 2>/dev/null)
if [ -n "$MV_PIN" ] && [ -n "$MV_PERG" ]; then
  MV_TOK=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$MV_PIN\",\"nome\":\"Voz da travessia\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  curl -s -X POST $BASE/api/publico/resposta -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$MV_PIN\",\"token\":\"$MV_TOK\",\"pergunta_id\":$MV_PERG,\"tipo\":\"TENDENCIA\",\"texto\":\"Voz que atravessa\"}" >/dev/null
  MV_SUG=$(get "/api/quiz?planejamento_id=1&pergunta_id=$MV_PERG" | python3 -c "
import sys, json
d = json.load(sys.stdin)['dados']
print(next((s['id'] for s in d.get('sugestoes', []) if s['texto'] == 'Voz que atravessa'), ''))" 2>/dev/null)
  R=$(post /api/cenario "{\"planejamento_id\":1,\"ano\":2026,\"tipo\":\"TENDENCIA\",\"descricao\":\"Item com voz do quiz\",\"sugestoes\":[$MV_SUG]}")
  MV_CQ=$(echo "$R" | id_de)
  R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_CQ quiz_vozes)
  afirma "a voz do quiz fica amarrada ao item do cenário" '^1$' "$R"

  R=$(post /api/cenario/$MV_CQ/mover '{"planejamento_id":1,"etapa":"PORTER","categoria":"RIVALIDADE"}')
  MV_FQ=$(echo "$R" | id_de)
  R=$(get "/api/fatores?planejamento_id=1&etapa=PORTER&ano=2026" | campo_de $MV_FQ quiz_vozes)
  afirma "e atravessa junto com ele" '^1$' "$R"
  # O golpe: editar o fator MANDANDO o conjunto de vozes que o painel dele
  # conhece — que é vazio, porque a pergunta era de outro alvo.
  post /api/fatores/$MV_FQ "{\"planejamento_id\":1,\"etapa\":\"PORTER\",\"categoria\":\"RIVALIDADE\",\"ano\":2026,\"descricao\":\"Item com voz do quiz (editado)\",\"sugestoes\":[]}" >/dev/null
  R=$(get "/api/fatores?planejamento_id=1&etapa=PORTER&ano=2026" | campo_de $MV_FQ quiz_vozes)
  afirma "e a primeira edição NÃO solta a voz que atravessou" '^1$' "$R"
  # Excluir o registro apaga a voz DE VEZ. Até 2026-09-02 ela voltava a NOVO
  # e reaparecia no painel do Cenário como sugestão nova — o cliente viu isso
  # numa voz que tinha atravessado do Cenário para a SWOT e pediu a exclusão
  # definitiva: apagar o registro é o mesmo gesto do ✕ da ficha. A prova mede
  # os dois lados — o painel do condutor e o celular do autor.
  R=$(post /api/fatores/$MV_FQ/excluir '{"planejamento_id":1}')
  afirma "exclui o fator que nasceu da voz" '"ok":true' "$R"
  # As duas negações vêm depois de um `afirma` na MESMA resposta: uma rota que
  # falhasse também não conteria o texto, e a prova ficaria verde sem provar.
  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$MV_PERG")
  afirma "o painel da pergunta continua respondendo" '"ok":true' "$R"
  nega "e excluir o fator NÃO devolve a voz que atravessou ao painel" 'Voz que atravessa' "$R"
  R=$(curl -s "$BASE/api/publico/minhas?pin=$MV_PIN&token=$MV_TOK")
  afirma "o celular do autor continua respondendo" '"ok":true' "$R"
  nega "e a voz não volta para ele" 'Voz que atravessa' "$R"
  # O mesmo vale sem travessia: voz usada no Cenário e item excluído ali.
  curl -s -X POST $BASE/api/publico/resposta -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$MV_PIN\",\"token\":\"$MV_TOK\",\"pergunta_id\":$MV_PERG,\"tipo\":\"SITUACAO_ATUAL\",\"texto\":\"Voz que some com o item\"}" >/dev/null
  MV_SUG2=$(get "/api/quiz?planejamento_id=1&pergunta_id=$MV_PERG" | python3 -c "
import sys, json
d = json.load(sys.stdin)['dados']
print(next((s['id'] for s in d.get('sugestoes', []) if s['texto'] == 'Voz que some com o item'), ''))" 2>/dev/null)
  MV_CI=$(post /api/cenario "{\"planejamento_id\":1,\"ano\":2026,\"tipo\":\"SITUACAO_ATUAL\",\"descricao\":\"Item que vai ser excluído\",\"sugestoes\":[$MV_SUG2]}" | id_de)
  R=$(get "/api/cenario?planejamento_id=1&ano=2026" | campo_de $MV_CI quiz_vozes)
  afirma "a segunda voz fica amarrada ao item do cenário" '^1$' "$R"
  R=$(post /api/cenario/$MV_CI/excluir '{"planejamento_id":1}')
  afirma "exclui o item do Cenário" '"ok":true' "$R"
  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$MV_PERG")
  afirma "o painel responde depois da exclusão" '"ok":true' "$R"
  nega "e a voz que originou o item foi apagada com ele" 'Voz que some com o item' "$R"
  post /api/quiz/encerrar '{"planejamento_id":1}' >/dev/null
fi
post /api/coleta/$MV_ID/excluir '{"planejamento_id":1}' >/dev/null

echo "### 9i. Cruzamentos na sala — a única resposta pública que NÃO é só texto"
#
# O alvo CRUZAMENTO é o primeiro em que o celular ESCOLHE registros: a pessoa
# marca dois fatores da SWOT e escreve a estratégia. É a rota sem login
# recebendo ids, e por isso estas provas medem sobretudo o que ela RECUSA.
#
# A regra do par mora em `Services\Cruzamentos` e vale igual dos dois lados —
# com login e sem. As provas abaixo batem no lado de FORA, que é o que importa:
# a de dentro já é exercitada pelo cadastro comum, na seção 9.
CZ_F=$(post /api/fatores '{"planejamento_id":1,"etapa":"SWOT","categoria":"FORCA","descricao":"Forca da sala","ano":2026}' | id_de)
CZ_O=$(post /api/fatores '{"planejamento_id":1,"etapa":"SWOT","categoria":"OPORTUNIDADE","descricao":"Oportunidade da sala","ano":2026}' | id_de)
CZ_A=$(post /api/fatores '{"planejamento_id":1,"etapa":"SWOT","categoria":"AMEACA","descricao":"Ameaca da sala","ano":2026}' | id_de)
R=$(post /api/quiz/abrir '{"planejamento_id":1,"alvo_tipo":"CRUZAMENTO","ano":2026,"alvos":["NAO_EXISTE"],"tema":"TOWS","confirmar_encerrar":true}')
afirma "bloco inventado é recusado ao abrir a sala" 'Bloco de cruzamento inválido' "$R"
R=$(post /api/quiz/abrir '{"planejamento_id":1,"alvo_tipo":"CRUZAMENTO","ano":2026,"alvos":["ATACAR"],"tema":"TOWS na sala","max_ideias":3,"confirmar_encerrar":true}')
afirma "abre a sala num bloco do cruzamento" '"pergunta_id"' "$R"
CZ_PIN=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pin',''))" 2>/dev/null)
CZ_PERG=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pergunta_id') or '')" 2>/dev/null)
if [ -n "$CZ_PIN" ] && [ -n "$CZ_PERG" ]; then
  # O que DESCE para o celular. As duas listas são a novidade: é conteúdo do
  # diagnóstico numa tela sem login, e por isso a prova mede também o que NÃO
  # desce — o score da GUT é priorização interna e não tem por que viajar.
  R=$(curl -s "$BASE/api/publico/rodada/$CZ_PIN" | python3 -c "
import sys, json
p = json.load(sys.stdin)['dados']['pergunta']
pares = p.get('pares') or {}
print(json.dumps({
  'interno': pares.get('interno', {}).get('rotulo'),
  'externo': pares.get('externo', {}).get('rotulo'),
  'campos': sorted((pares.get('interno', {}).get('itens') or [{}])[0].keys()),
}, ensure_ascii=False))" 2>/dev/null)
  afirma "o celular recebe as duas listas do bloco" '"interno": "Forças", "externo": "Oportunidades"' "$R"
  afirma "e de cada fator só o id e a descrição" '"campos": \["descricao", "id"\]' "$R"

  CZ_TOK=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$CZ_PIN\",\"nome\":\"Voz do cruzamento\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  cz_resp(){ curl -s -X POST $BASE/api/publico/resposta -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$CZ_PIN\",\"token\":\"$CZ_TOK\",\"pergunta_id\":$CZ_PERG,$1\"texto\":\"Estrategia da sala\"}"; }

  R=$(cz_resp '')
  afirma "sem o par, a resposta é recusada" 'um fator interno e um externo' "$R"
  R=$(cz_resp "\"fator_interno_id\":$CZ_F,\"fator_externo_id\":$CZ_F,")
  afirma "o mesmo fator dos dois lados é recusado" 'DOIS fatores diferentes' "$R"
  R=$(cz_resp "\"fator_interno_id\":$CZ_O,\"fator_externo_id\":$CZ_F,")
  afirma "o par invertido é recusado" 'fator INTERNO .* a um fator EXTERNO' "$R"
  # A guarda que dá sentido à pergunta: sem ela, "Forças × Oportunidades"
  # aceitaria força com ameaça, e o painel encheria de resposta fora do assunto.
  R=$(cz_resp "\"fator_interno_id\":$CZ_F,\"fator_externo_id\":$CZ_A,")
  afirma "par de OUTRO bloco é recusado, dizendo qual é o bloco" 'Esta pergunta é do bloco Forças × Oportunidades' "$R"
  # Id que não é da SWOT deste plano: o caso do corpo forjado, que é a razão
  # de a rota nunca ler o planejamento do que lhe mandam.
  R=$(cz_resp "\"fator_interno_id\":$CZ_F,\"fator_externo_id\":99999999,")
  afirma "id de fora do planejamento é recusado" 'dois fatores da SWOT deste planejamento' "$R"
  R=$(cz_resp "\"fator_interno_id\":$FAT,\"fator_externo_id\":$CZ_O,")
  afirma "fator que não é da SWOT é recusado" 'dois fatores da SWOT deste planejamento' "$R"
  R=$(cz_resp "\"fator_interno_id\":$CZ_F,\"fator_externo_id\":$CZ_O,")
  afirma "o par certo é aceito" '"ok":true' "$R"

  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$CZ_PERG" | python3 -c "
import sys, json
s = json.load(sys.stdin)['dados']['sugestoes']
print(json.dumps(s[0] if s else {}, ensure_ascii=False))" 2>/dev/null)
  afirma "o painel do condutor recebe o par com as descrições" '"interno_descricao": "Forca da sala"' "$R"
  CZ_SUG=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin).get('id',''))" 2>/dev/null)

  # O "Usar": o cruzamento nasce com o par que a sala propôs, e a voz fica
  # amarrada a ele — é o registro de QUEM propôs aquele encontro.
  R=$(post /api/cruzamentos "{\"planejamento_id\":1,\"fator_interno_id\":$CZ_F,\"fator_externo_id\":$CZ_O,\"rotulo\":\"Par da sala\",\"estrategia\":\"Estrategia redigida pelo condutor\",\"sugestoes\":[$CZ_SUG]}")
  afirma "o condutor aceita a proposta e ela vira cruzamento" '"tipo":"ATACAR"' "$R"
  CZ_ID=$(echo "$R" | id_de)
  R=$(get "/api/cruzamentos?planejamento_id=1&ano=2026" | campo_de $CZ_ID quiz_vozes)
  afirma "e o cruzamento mostra a voz que o sustenta" '^1$' "$R"
  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$CZ_PERG" | python3 -c "
import sys, json
s = [x for x in json.load(sys.stdin)['dados']['sugestoes'] if x['id'] == $CZ_SUG]
print(json.dumps(s[0]['vinculada'] if s else None))" 2>/dev/null)
  afirma "a voz sai do painel, porque virou registro" '^1$' "$R"

  # Excluído o cruzamento, a voz SAI com ele. Até 2026-09-02 ela voltava ao
  # painel como sugestão nova — o cliente pediu a exclusão definitiva: quem
  # exclui o registro está descartando a voz, como no ✕ da ficha. Ficar ACEITA
  # sobre um id morto continua proibido; a resposta agora é apagar, não soltar.
  R=$(post /api/cruzamentos/$CZ_ID/excluir '{"planejamento_id":1}')
  afirma "exclui o cruzamento que nasceu da voz" '"ok":true' "$R"
  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$CZ_PERG" | python3 -c "
import sys, json
d = json.load(sys.stdin)
s = [x for x in d['dados']['sugestoes'] if x['id'] == $CZ_SUG]
print('painel-ok', json.dumps(s[0]['vinculada'] if s else None))" 2>/dev/null)
  afirma "apagado o cruzamento, a voz é apagada junto" '^painel-ok null$' "$R"

  # A voz sai antes dos fatores: apagar o fator só ANULA o lado do par (a FK é
  # SET NULL, para não perder o que alguém escreveu na oficina), e a resposta
  # ficaria para trás a cada rodada da bateria.
  [ -n "$CZ_SUG" ] && post /api/quiz/sugestao/$CZ_SUG/excluir '{"planejamento_id":1}' >/dev/null
  post /api/quiz/encerrar '{"planejamento_id":1}' >/dev/null
fi
for F in $CZ_F $CZ_O $CZ_A; do
  [ -n "$F" ] && post /api/fatores/$F/excluir '{"planejamento_id":1}' >/dev/null
done

echo "### 9j. A etapa inteira na sala — o celular escolhe a categoria"
#
# Pedido do cliente (2026-09-03): o 🎤 do cabeçalho do PESTEL/Porter/SWOT abre
# a análise INTEIRA, e é o participante quem diz em qual categoria a resposta
# entra — lendo ali a orientação do ⓘ. O alvo VAZIO é "toda a etapa"
# (`Quiz::validarAlvos`), e os lados da pergunta passam a ser as categorias
# (`Quiz::ladosDe`). As provas medem sobretudo o que a rota pública RECUSA:
# resposta sem categoria e categoria de outra etapa — "cair na primeira", que é
# a regra dos alvos de dois lados, aqui mandaria a voz para um quadrante que
# ninguém escolheu.
R=$(post /api/quiz/abrir '{"planejamento_id":1,"alvo_tipo":"FATOR","etapa":"PESTEL","ano":2026,"alvos":[""],"tema":"PESTEL inteiro","max_ideias":2,"confirmar_encerrar":true}')
afirma "abre a sala na etapa inteira (alvo vazio)" '"pergunta_id"' "$R"
EI_PIN=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pin',''))" 2>/dev/null)
EI_PERG=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin)['dados'].get('pergunta_id') or '')" 2>/dev/null)
if [ -n "$EI_PIN" ] && [ -n "$EI_PERG" ]; then
  R=$(curl -s "$BASE/api/publico/rodada/$EI_PIN" | python3 -c "
import sys, json
p = json.load(sys.stdin)['dados']['pergunta']
lados = p.get('lados') or []
print(json.dumps({'n': len(lados), 'escolhe': p.get('escolhe_categoria'),
  'campos': sorted(lados[0].keys()) if lados else [],
  'tem_orientacao': all(l.get('orientacao') for l in lados),
  'rotulo': p.get('rotulo'), 'titulo': p.get('titulo')}, ensure_ascii=False))" 2>/dev/null)
  afirma "o celular recebe as seis categorias do PESTEL como lados" '"n": 6' "$R"
  afirma "e sabe que a escolha é obrigatória" '"escolhe": true' "$R"
  afirma "cada lado leva cor, dica, rótulo e a orientação do ⓘ" '"campos": \["cor", "dica", "orientacao", "rotulo", "valor"\]' "$R"
  afirma "com orientação em todos" '"tem_orientacao": true' "$R"
  afirma "o rótulo diz que é a análise inteira" 'a análise inteira' "$R"
  afirma "e o enunciado pede a escolha" 'Escolha a categoria' "$R"

  EI_TOK=$(curl -s -X POST $BASE/api/publico/entrar -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$EI_PIN\",\"nome\":\"Voz da etapa\"}" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['dados']['token'])" 2>/dev/null)
  ei_resp(){ curl -s -X POST $BASE/api/publico/resposta -H 'Content-Type: application/json' \
    -d "{\"pin\":\"$EI_PIN\",\"token\":\"$EI_TOK\",\"pergunta_id\":$EI_PERG,$1\"texto\":\"$2\"}"; }
  R=$(ei_resp '' 'Sem categoria')
  afirma "sem categoria, a resposta é recusada — nunca cai na primeira" 'Escolha a categoria' "$R"
  R=$(ei_resp '"tipo":"FORCA",' 'Categoria de outra etapa')
  afirma "categoria de OUTRA etapa é recusada" 'Escolha a categoria' "$R"
  R=$(ei_resp '"tipo":"TECNOLOGICO",' 'Automacao da granja')
  afirma "com a categoria, a resposta entra" '"ok":true' "$R"
  R=$(ei_resp '"tipo":"TECNOLOGICO",' 'Sensores no rebanho')
  afirma "a segunda da mesma categoria também" '"ok":true' "$R"
  R=$(ei_resp '"tipo":"TECNOLOGICO",' 'Terceira estoura o teto')
  afirma "o teto conta POR CATEGORIA, e a recusa diz qual" 'sugestão\(ões\) em Tecnológico' "$R"
  R=$(ei_resp '"tipo":"SOCIAL",' 'Sucessao no campo')
  afirma "e outra categoria segue aberta" '"ok":true' "$R"
  R=$(curl -s "$BASE/api/publico/minhas?pin=$EI_PIN&token=$EI_TOK")
  afirma "a categoria volta ao celular em tipo_resposta" '"tipo_resposta":"SOCIAL"' "$R"

  # O "Usar" do condutor: a voz amarra ao fator da categoria escolhida — ou de
  # OUTRA da mesma etapa, porque a escolha do celular é sugestão e o quadrante
  # final é decisão de quem conduz (`FatorController::vincularSugestoes` aceita
  # `qp.categoria IS NULL` para qualquer categoria da etapa).
  ei_sug(){ get "/api/quiz?planejamento_id=1&pergunta_id=$EI_PERG" | python3 -c "
import sys, json
d = json.load(sys.stdin)['dados']
print(next((s['id'] for s in d.get('sugestoes', []) if s['texto'] == sys.argv[1]), ''))" "$1" 2>/dev/null; }
  EI_SUG=$(ei_sug 'Automacao da granja')
  R=$(post /api/fatores "{\"planejamento_id\":1,\"etapa\":\"PESTEL\",\"categoria\":\"TECNOLOGICO\",\"ano\":2026,\"descricao\":\"Automacao da granja (fator)\",\"sugestoes\":[$EI_SUG]}")
  EI_F=$(echo "$R" | id_de)
  R=$(get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" | campo_de $EI_F quiz_vozes)
  afirma "a voz da etapa inteira amarra ao fator da categoria escolhida" '^1$' "$R"
  EI_SUG2=$(ei_sug 'Sucessao no campo')
  R=$(post /api/fatores "{\"planejamento_id\":1,\"etapa\":\"PESTEL\",\"categoria\":\"ECONOMICO\",\"ano\":2026,\"descricao\":\"Sucessao reclassificada (fator)\",\"sugestoes\":[$EI_SUG2]}")
  EI_F2=$(echo "$R" | id_de)
  R=$(get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" | campo_de $EI_F2 quiz_vozes)
  afirma "e o condutor pode amarrá-la a OUTRA categoria da mesma etapa" '^1$' "$R"
  post /api/fatores/$EI_F "{\"planejamento_id\":1,\"etapa\":\"PESTEL\",\"categoria\":\"TECNOLOGICO\",\"ano\":2026,\"descricao\":\"Automacao da granja (editado)\",\"sugestoes\":[$EI_SUG]}" >/dev/null
  R=$(get "/api/fatores?planejamento_id=1&etapa=PESTEL&ano=2026" | campo_de $EI_F quiz_vozes)
  afirma "editar o fator mantendo o conjunto não solta a voz" '^1$' "$R"
  # O estado devolve TODAS as vozes; quem tira a usada da grade é a tela, pela
  # marca `vinculada` — é ela que a prova mede.
  R=$(get "/api/quiz?planejamento_id=1&pergunta_id=$EI_PERG" | python3 -c "
import sys, json
s = {x['texto']: x for x in json.load(sys.stdin)['dados'].get('sugestoes', [])}
print(json.dumps({'usada': int(s.get('Automacao da granja', {}).get('vinculada') or 0),
  'sobra': s.get('Sensores no rebanho', {}).get('tipo_resposta')}))" 2>/dev/null)
  afirma "a voz usada fica marcada como vinculada no painel do condutor" '"usada": 1' "$R"
  afirma "e a que sobrou continua lá, com a categoria" '"sobra": "TECNOLOGICO"' "$R"
  post /api/fatores/$EI_F/excluir '{"planejamento_id":1}' >/dev/null
  post /api/fatores/$EI_F2/excluir '{"planejamento_id":1}' >/dev/null
  post /api/quiz/encerrar '{"planejamento_id":1}' >/dev/null
fi

echo "### 9k. Excluir ação, frente ou projeto: as origens voltam à fila ou saem de vez"
#
# Pedido do cliente (2026-09-03): apagar uma ação devolvia a origem (o fator, a
# ideia…) para "aguardando plano de ação" sem perguntar, e a fila enchia do
# que ninguém queria mais. A chave `origens` do corpo decide: `devolver` (o
# padrão, e o de sempre) ou `tirar`. As provas medem os dois caminhos e as
# duas origens que se comportam diferente — o fator (FK SET NULL) e a ideia da
# Coleta (par polimórfico, sem FK) —, mais a recusa de valor desconhecido.
OK_F=$(post /api/fatores '{"planejamento_id":1,"etapa":"SWOT","categoria":"FRAQUEZA","descricao":"Fraqueza da prova de origens","ano":2026}' | id_de)
post /api/fatores/$OK_F/plano-acao '{"planejamento_id":1}' >/dev/null
OK_I=$(post /api/coleta '{"planejamento_id":1,"texto":"Ideia da prova de origens","ano":2026}' | id_de)
post /api/coleta/$OK_I/encaminhar '{"planejamento_id":1,"destino":"ACAO"}' >/dev/null
OK_P=$(post /api/projetos '{"planejamento_id":1,"titulo":"Projeto da prova de origens","ano":2027,"responsavel":"QA"}' | id_de)
OK_BASE="\"planejamento_id\":1,\"projeto_id\":$OK_P,\"iniciativa_nova\":\"Frente das origens\",\"como\":\"x\",\"quem\":\"QA\",\"prioridade\":\"MEDIA\",\"status\":\"NAO_INICIADO\",\"progresso\":0,\"recorrencia\":\"NENHUMA\",\"data_inicio\":\"2027-01-01\",\"data_fim\":\"2027-12-31\""
OK_A1=$(post /api/desdobramentos "{$OK_BASE,\"o_que\":\"Acao da fraqueza\",\"fator_id\":$OK_F}" | id_de)
OK_A2=$(post /api/desdobramentos "{$OK_BASE,\"o_que\":\"Acao da ideia\",\"coleta_item_id\":$OK_I}" | id_de)
R=$(get "/api/projetos?planejamento_id=1" | python3 -c "
import sys, json
p = json.load(sys.stdin)['dados']
a = [x for pr in p for x in pr['desdobramentos'] if x['id'] == $OK_A1]
print(a[0]['origens'] if a else '')" 2>/dev/null)
afirma "a listagem conta a origem de cada ação (é ela que decide se a tela pergunta)" '^1$' "$R"
R=$(post /api/desdobramentos/$OK_A1/excluir '{"planejamento_id":1,"origens":"jogar fora"}')
afirma "valor desconhecido para as origens é recusado, nunca corrigido" 'devolver à fila ou tirar' "$R"
R=$(post /api/desdobramentos/$OK_A1/excluir '{"planejamento_id":1,"origens":"tirar"}')
afirma "exclui a ação tirando a origem de vez" '"ok":true' "$R"
R=$(get "/api/fatores/aguardando-acao?planejamento_id=1")
afirma "a fila de fatores responde" '"ok":true' "$R"
nega "e o fator NÃO volta para ela" "\"id\":$OK_F," "$R"
R=$(get "/api/fatores?planejamento_id=1&etapa=SWOT&ano=2026" | campo_de $OK_F acao_em)
afirma "o fator continua na SWOT, sem a marca de plano de ação" '^null$' "$R"
R=$(post /api/desdobramentos/$OK_A2/excluir '{"planejamento_id":1,"origens":"tirar"}')
afirma "exclui a ação da ideia tirando-a de vez" '"ok":true' "$R"
R=$(get "/api/coleta/aguardando-acao?planejamento_id=1")
afirma "a fila de ideias responde" '"ok":true' "$R"
nega "e a ideia NÃO volta para ela" "\"id\":$OK_I," "$R"
R=$(get "/api/coleta?planejamento_id=1&ano=2026" | campo_de $OK_I situacao)
afirma "a ideia volta à matriz da Coleta como selecionada, sem destino" '^"SELECIONADO"$' "$R"
# O padrão continua sendo o de sempre: sem a chave, a origem volta à fila.
post /api/fatores/$OK_F/plano-acao '{"planejamento_id":1}' >/dev/null
OK_A3=$(post /api/desdobramentos "{$OK_BASE,\"o_que\":\"Acao devolvida\",\"fator_id\":$OK_F}" | id_de)
R=$(post /api/desdobramentos/$OK_A3/excluir '{"planejamento_id":1}')
afirma "sem a chave, exclui como sempre" '"ok":true' "$R"
R=$(get "/api/fatores/aguardando-acao?planejamento_id=1")
afirma "e o fator volta para a fila" "\"id\":$OK_F," "$R"
# Pelo PROJETO inteiro: as ações caem por CASCADE, e a origem tem de ser
# tratada ANTES — depois do DELETE a subconsulta não as encontra mais.
OK_A4=$(post /api/desdobramentos "{$OK_BASE,\"o_que\":\"Acao do projeto que sai\",\"fator_id\":$OK_F}" | id_de)
R=$(post /api/projetos/$OK_P/excluir '{"planejamento_id":1,"origens":"tirar"}')
afirma "exclui o projeto inteiro tirando as origens" '"ok":true' "$R"
R=$(get "/api/fatores/aguardando-acao?planejamento_id=1")
afirma "a fila responde depois do projeto" '"ok":true' "$R"
nega "e o fator das ações do projeto não volta para ela" "\"id\":$OK_F," "$R"
post /api/fatores/$OK_F/excluir '{"planejamento_id":1}' >/dev/null
post /api/coleta/$OK_I/excluir '{"planejamento_id":1}' >/dev/null

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

#!/usr/bin/env bash
# Roda as oito baterias em sequência e devolve 0 só se todas passarem.
# Ver testes/README.md para o que precisa estar de pé antes.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE=${APP_URL:-http://127.0.0.1:8099}
FALHOU=0

if ! curl -sf -o /dev/null "$BASE/login"; then
  echo "✗ A aplicação não responde em $BASE — suba o banco e o servidor (testes/README.md)."
  exit 2
fi

echo "═══ 1/8  funcional (escrita de cada módulo pela API)"
./testes/funcional.sh || FALHOU=1

echo
echo "═══ 2/8  sistema (16 seções, desktop e celular)"
node testes/sistema.js || FALHOU=1

echo
echo "═══ 3/8  participante (tela pública, celular)"
# Precisa de uma rodada ABERTA. Sem PIN à mão a bateria é pulada em vez de
# falhar: ela depende de estado que nem toda instância tem, e um vermelho por
# ausência de massa ensinaria a ignorar o vermelho.
PIN=${PIN_TEMPESTADE:-}
if [ -z "$PIN" ]; then
  echo "  ⏭  pulada: defina PIN_TEMPESTADE=<pin de uma rodada aberta> para rodá-la."
else
  node testes/participante.js "$PIN" || FALHOU=1
fi

echo
echo "═══ 4/8  backup (gerar, verificar, restaurar)"
# Fala com o banco direto, não com a aplicação: é a única que precisa de um
# usuário com CREATE DATABASE, e sem ele ela se pula sozinha.
./testes/backup.sh || FALHOU=1

echo
echo "═══ 5/8  e-mail (envio por API, com serviço de mentira)"
# Não manda e-mail nem toca no banco: sobe um servidor local que responde como
# o serviço real. É o caminho que roda em produção — a hospedagem bloqueia as
# portas de SMTP, e ali nenhum servidor de e-mail é alcançável.
./testes/email.sh || FALHOU=1

echo
echo "═══ 6/8  backup remoto (cópia fora do provedor, com serviço de mentira)"
# Também não toca no banco nem sobe nada de verdade: fala com um B2 de mentira.
# Ela roda mesmo sem conta configurada — o primeiro caso que ela prova é
# justamente o de quem ainda não configurou a cópia remota.
./testes/backup_remoto.sh || FALHOU=1

echo
echo "═══ 7/8  limpar plano de ação (cli/limpar_plano_acao.php)"
# Fala com o banco direto, como a do backup: semeia um plano de ação de prova,
# conta, apaga e confere o que sobrou. Sem cliente MySQL ela se pula sozinha.
./testes/limpar_plano_acao.sh || FALHOU=1

echo
echo "═══ 8/8  carga de cenário com revisão no lugar (cli/carga_diagnostico.php)"
# Semeia os textos anteriores num ano vazio do ciclo, aplica a carga e confere
# que a revisão atualizou no mesmo id. Sem cliente MySQL ela se pula sozinha.
./testes/carga_cenario.sh || FALHOU=1

echo
if [ $FALHOU -eq 0 ]; then echo "✓ Todas as baterias passaram."; else echo "✗ Há bateria com falha."; fi
exit $FALHOU

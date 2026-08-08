#!/usr/bin/env bash
# Roda as três baterias em sequência e devolve 0 só se todas passarem.
# Ver testes/README.md para o que precisa estar de pé antes.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE=${APP_URL:-http://127.0.0.1:8099}
FALHOU=0

if ! curl -sf -o /dev/null "$BASE/login"; then
  echo "✗ A aplicação não responde em $BASE — suba o banco e o servidor (testes/README.md)."
  exit 2
fi

echo "═══ 1/3  funcional (escrita de cada módulo pela API)"
./testes/funcional.sh || FALHOU=1

echo
echo "═══ 2/3  sistema (15 seções, desktop e celular)"
node testes/sistema.js || FALHOU=1

echo
echo "═══ 3/3  participante (tela pública, celular)"
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
if [ $FALHOU -eq 0 ]; then echo "✓ Todas as baterias passaram."; else echo "✗ Há bateria com falha."; fi
exit $FALHOU

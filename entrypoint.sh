#!/bin/sh
set -e

PORT="${PORT:-80}"
echo "entrypoint: iniciando na porta ${PORT}"

echo "Aplicando migração do banco..."
php /app/database/migrate.php || echo "AVISO: migração falhou — verifique as variáveis do MySQL."

exec php -S "0.0.0.0:${PORT}" -t /app/public /app/public/index.php

#!/bin/sh
set -e

PORT="${PORT:-80}"
echo "entrypoint: iniciando na porta ${PORT}"

# Migração obrigatória: se falhar (banco fora, credenciais erradas), o start
# aborta e o Railway mantém/reinicia o deploy — melhor que servir 500 sem schema.
echo "Aplicando migração do banco..."
php /app/database/migrate.php

exec php -S "0.0.0.0:${PORT}" -t /app/public /app/public/index.php

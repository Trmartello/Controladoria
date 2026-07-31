#!/bin/sh
set -e

PORT="${PORT:-80}"
echo "entrypoint: configurando Apache para escutar na porta ${PORT}"
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
grep -h "^Listen" /etc/apache2/ports.conf

echo "Aplicando migração do banco..."
php /var/www/html/database/migrate.php || echo "AVISO: migração falhou — verifique as variáveis do MySQL."

exec apache2-foreground

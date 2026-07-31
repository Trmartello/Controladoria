FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . .

RUN chmod +x entrypoint.sh

# Concorrência do servidor embutido (processos trabalhadores)
ENV PHP_CLI_SERVER_WORKERS=8

# O Railway define $PORT em tempo de execução; o entrypoint roda a migração
# e sobe o servidor embutido do PHP servindo public/.
CMD ["./entrypoint.sh"]

FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql

# A imagem oficial não instala php.ini nenhum (só traz os modelos), e os padrões
# compilados são display_errors=On e log_errors=Off — ou seja, erro do PHP era
# impresso no corpo da resposta (com caminho do servidor e stack trace, e
# quebrando o JSON que o front espera) enquanto os error_log() da aplicação não
# gravavam em lugar nenhum. Aqui os dois papéis trocam de lado: o usuário recebe
# resposta limpa e o operador vê o erro nos logs do Railway.
# Os anexos dos comentários sobem por multipart e são guardados no banco. O
# php.ini-production limita o upload a 2M e o POST a 8M — abaixo do teto de 5 MB
# por arquivo (e de 5 arquivos por comentário) que o ComentarioController aplica.
# Sem estas duas linhas, o PHP descarta o arquivo ANTES do controller e o
# usuário recebe "falha ao receber o arquivo" sem saber por quê.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'display_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\nexpose_php=Off\nupload_max_filesize=5M\npost_max_size=30M\n' \
      > "$PHP_INI_DIR/conf.d/zz-app.ini"

# O opcache vem na imagem, mas desligado — e no SAPI do servidor embutido ele
# ainda exige enable_cli. Sem isso, todo pedido recompila o PHP inteiro.
# O código não muda dentro do container, então a revalidação fica desligada.
RUN docker-php-ext-enable opcache \
 && printf 'opcache.enable=1\nopcache.enable_cli=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=64\n' \
      > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# Mesmo fuso da cooperativa (o PHP também o fixa em config/config.php)
ENV TZ=America/Sao_Paulo

WORKDIR /app
COPY . .

RUN chmod +x entrypoint.sh

# Concorrência do servidor embutido (processos trabalhadores)
ENV PHP_CLI_SERVER_WORKERS=8

# O Railway define $PORT em tempo de execução; o entrypoint roda a migração
# e sobe o servidor embutido do PHP servindo public/.
CMD ["./entrypoint.sh"]

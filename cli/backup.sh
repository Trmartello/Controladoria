#!/usr/bin/env bash
#
# Cópia de segurança do sistema — que é, inteira, o banco MySQL.
#
# O padrão do projeto desde o começo é MySQL 8 (README; o migrate também
# atravessa MariaDB), e essa escolha é o que faz o backup ser simples: TUDO o
# que o usuário produz mora no banco — diagnóstico, cascata, plano de ação,
# sessões, e até os ANEXOS dos comentários, que são LONGBLOB em
# `comentario_anexo` justamente porque o contêiner do Railway é efêmero e pasta
# de upload some no deploy seguinte. Não há diretório de arquivos para copiar e
# o código vem do git: backup deste sistema é exatamente um dump do banco
# apontado por `config/config.php`.
#
#   ./cli/backup.sh                       # gera backups/<banco>-<data>.sql.gz
#   ./cli/backup.sh listar                # o que já existe, do mais novo ao mais velho
#   ./cli/backup.sh verificar <arquivo>   # confere um arquivo antigo
#   ./cli/backup.sh restaurar <arquivo>   # DESTRUTIVO — pede confirmação
#
# Conexão: as MESMAS variáveis da aplicação (MYSQL* → DB_* → padrão), lidas de
# config/config.php. Ajustes deste script:
#
#   BACKUP_DIR      onde gravar (padrão: ./backups)
#   BACKUP_MANTER   quantos arquivos manter (padrão: 14; 0 = não apaga nenhum)
#   MYSQLDUMP_BIN   força o cliente de dump (padrão: mysqldump, senão mariadb-dump)
#   MYSQL_BIN       força o cliente de restauração (padrão: mysql, senão mariadb)
#   CONFIRMAR       nome do banco, para restaurar sem terminal (cron/CI)
#   BACKUP_SSL_VERIFICAR=1  exige certificado de autoridade conhecida (fora disso
#                   a conexão continua CIFRADA, só não confere quem assinou — é
#                   o que permite falar com banco gerenciado, que assina o
#                   próprio certificado)
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

# O dump carrega hash de senha, o e-mail de todo mundo e o conteúdo dos anexos:
# nasce legível só para quem o gerou.
umask 077

BACKUP_DIR=${BACKUP_DIR:-backups}
MANTER=${BACKUP_MANTER:-14}
# Nome do arquivo = nome do BANCO + carimbo. Com um prefixo fixo, o backup de
# homologação e o de produção caíam no mesmo padrão: ninguém distinguia um do
# outro na pasta, e a faxina de um contava os arquivos do outro para decidir o
# que apagar. Definido em conectar(), quando o banco é conhecido.
PREFIXO=""

msg()  { printf '%s\n' "$*"; }
erro() { printf '✗ %s\n' "$*" >&2; }

# O próprio cabeçalho deste arquivo é a ajuda: para na primeira linha que não é
# comentário, para não sair de sincronia quando o bloco crescer.
uso() {
    awk 'NR > 1 { if ($0 !~ /^#/) exit; sub(/^# ?/, ""); print }' "$0"
}

# ─────────────────────────────────────────────────────────────────────────────
# Conexão
# ─────────────────────────────────────────────────────────────────────────────

TMP=""
CNF=""
DUMP=""
CLIENTE=""
DB_H=""; DB_P=""; DB_N=""; DB_U=""; DB_S=""

achar_bin() {
    local nome
    for nome in "$@"; do
        if command -v "$nome" >/dev/null 2>&1; then printf '%s' "$nome"; return 0; fi
    done
    return 1
}

# Valor de arquivo de opções do MySQL: entre aspas (senão um `#` na senha vira
# comentário e o resto dela some) e com a barra invertida escapada.
escapar_cnf() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

conectar() {
    # A configuração sai de config/config.php — a mesma cadeia MYSQL* → DB_* →
    # padrão que a aplicação usa. Reescrever essa cadeia aqui em shell criaria
    # uma segunda verdade, e o backup apontando para outro banco é o pior lugar
    # possível para uma divergência dessas.
    if ! command -v php >/dev/null 2>&1; then
        erro "php não encontrado no PATH — a conexão é lida de config/config.php."
        exit 1
    fi

    local cfg=()
    mapfile -t -d '' cfg < <(php -r '
        $c = require "config/config.php";
        $d = $c["db"];
        foreach (["host", "port", "name", "user", "pass"] as $k) {
            echo (string) $d[$k], "\0";
        }
        echo date_default_timezone_get(), "\0";
    ' 2>/dev/null)

    if [ "${#cfg[@]}" -ne 6 ]; then
        erro "não consegui ler a configuração do banco em config/config.php."
        exit 1
    fi
    DB_H=${cfg[0]}; DB_P=${cfg[1]}; DB_N=${cfg[2]}; DB_U=${cfg[3]}; DB_S=${cfg[4]}

    # O mesmo fuso da aplicação (config/config.php), não o do contêiner — que
    # roda em UTC. Sem isto o backup das 21h de segunda nasce carimbado de
    # terça, e é justamente pelo carimbo que se escolhe qual restaurar.
    export TZ=${cfg[5]}

    # Nome de banco é nome de arquivo aqui: o que não serve num, vira `_`.
    PREFIXO=$(printf '%s' "$DB_N" | tr -c 'A-Za-z0-9_-' '_')

    DUMP=${MYSQLDUMP_BIN:-$(achar_bin mysqldump mariadb-dump || true)}
    CLIENTE=${MYSQL_BIN:-$(achar_bin mysql mariadb || true)}

    TMP=$(mktemp -d) || exit 1
    trap 'rm -rf "$TMP"' EXIT HUP INT TERM

    # A senha vai num arquivo de opções temporário (0600, apagado no trap), e
    # nunca em `-p` na linha de comando: ali qualquer usuário da máquina a lê
    # num `ps`. Este arquivo carrega TODA a conexão, então as chamadas abaixo
    # não repetem host/porta/usuário.
    CNF="$TMP/cliente.cnf"
    {
        printf '[client]\n'
        printf 'host="%s"\n'     "$(escapar_cnf "$DB_H")"
        printf 'port=%s\n'       "${DB_P:-3306}"
        printf 'user="%s"\n'     "$(escapar_cnf "$DB_U")"
        printf 'password="%s"\n' "$(escapar_cnf "$DB_S")"
        # CLAUDE.md: cliente sem utf8mb4 explícito devolve acentuação quebrada.
        printf 'default-character-set=utf8mb4\n'
        # Banco gerenciado (Railway, e todo provedor que se parece com ele) serve
        # certificado assinado por ele mesmo, e o cliente do MariaDB derruba a
        # conexão antes de autenticar: erro 2026, "self-signed certificate in
        # certificate chain" — nenhum backup era gravado, todo dia.
        # Isto NÃO desliga a criptografia: o `--ssl` do cliente continua
        # negociando TLS (medido: TLS_AES_256_GCM_SHA384 com esta linha posta).
        # O que sai é só a exigência de que o certificado venha de uma autoridade
        # conhecida — a mesma postura que o PDO da aplicação já toma para falar
        # com o mesmo banco, pela mesma rede privada. Backup mais estrito que a
        # aplicação que ESCREVE o dado não protege nada e não roda.
        # Quem tiver certificado de autoridade de verdade recupera a checagem com
        # BACKUP_SSL_VERIFICAR=1.
        # O valor é escrito nos DOIS sentidos, nunca omitido: o padrão do cliente
        # não é o mesmo em toda máquina — o MariaDB 10.11 (o do desenvolvimento)
        # vem com verificação desligada e o 11.4+ (o da imagem publicada) vem com
        # ela ligada. Foi essa diferença que deixou o defeito invisível aqui e
        # diário lá; omitir a linha devolveria exatamente essa armadilha.
        # O prefixo `loose-` é o que faz uma linha só servir aos dois clientes:
        # no do MySQL da Oracle, que não conhece esta opção, ela vira aviso em
        # vez de erro (sem ele, o arquivo de opções derruba o comando inteiro).
        printf 'loose-ssl-verify-server-cert=%s\n' \
            "$([ "${BACKUP_SSL_VERIFICAR:-0}" = "1" ] && echo 1 || echo 0)"
    } > "$CNF"
}

# ─────────────────────────────────────────────────────────────────────────────
# Backup
# ─────────────────────────────────────────────────────────────────────────────

# Estrutura sim, dados não: as três tabelas que só crescem e não guardam nada
# do planejamento. `sessao` é a pior de restaurar — traz de volta sessões de
# quem estava logado no dia do dump, com o cookie de 30 dias ainda válido.
TABELAS_SEM_DADOS=(sessao login_tentativa coleta_tentativa)

opcoes_dump() {
    local suporta=""
    suporta=$("$DUMP" --help 2>/dev/null)

    OPCOES=(
        # Snapshot consistente sem travar a oficina: tudo é InnoDB, e assim o
        # dump não exige LOCK TABLES/RELOAD — privilégios que o usuário criado
        # pelo Railway costuma não ter.
        --single-transaction
        # Linha a linha; comentario_anexo tem LONGBLOB e não cabe na memória.
        --quick
        # Anexo binário sai como 0x… — imune a conversão de charset na volta.
        --hex-blob
    )
    # Cliente do MySQL 8 contra servidor MariaDB/5.7 morre pedindo
    # information_schema.COLUMN_STATISTICS, que não existe lá.
    grep -q -- '--column-statistics' <<< "$suporta" && OPCOES+=(--column-statistics=0)
    # Desde o MySQL 8.0.21 a informação de tablespace exige o privilégio PROCESS.
    grep -q -- '--no-tablespaces'    <<< "$suporta" && OPCOES+=(--no-tablespaces)
    # Sem isto o dump embute SET @@GLOBAL.GTID_PURGED, que exige SUPER para
    # restaurar — e a restauração é justamente a hora em que ninguém quer
    # descobrir que falta privilégio.
    grep -q -- '--set-gtid-purged'   <<< "$suporta" && OPCOES+=(--set-gtid-purged=OFF)

    # Nada de --routines/--events: o schema não tem nenhum dos dois e ambos
    # pedem privilégio extra (um dump que falha por isso é pior que um dump sem
    # objetos que não existem).
    # E nada de --databases: sem CREATE DATABASE/USE no arquivo, quem escolhe o
    # destino é o config do ambiente onde a restauração roda — um dump de
    # produção não "pula" de volta para produção ao ser restaurado num teste.
}

cabecalho() {
    local commit
    # Qual versão do código gerou este dump — é o que diz, na hora de restaurar,
    # se o schema do arquivo é mais velho que o da aplicação. Na imagem do
    # Railway NÃO existe `.git` (o .dockerignore o exclui), e é justamente lá
    # que a informação importa: o Railway publica o commit do deploy em
    # RAILWAY_GIT_COMMIT_SHA, que serve de segunda fonte.
    commit=$(git rev-parse --short HEAD 2>/dev/null) \
        || commit=$(printf '%.7s' "${RAILWAY_GIT_COMMIT_SHA:-}")
    [ -n "$commit" ] || commit='?'
    printf -- '-- Backup — Planejamento Estratégico Copérdia\n'
    printf -- '-- banco:     %s em %s:%s\n' "$DB_N" "$DB_H" "${DB_P:-3306}"
    printf -- '-- gerado em: %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')"
    printf -- '-- código:    %s\n' "$commit"
    printf -- '-- sem dados: %s\n' "${TABELAS_SEM_DADOS[*]}"
    printf -- '-- restaurar: ./cli/backup.sh restaurar <este arquivo>\n'
    printf -- '--\n'
}

# A ÚLTIMA linha do arquivo, escrita só depois de os dois passos terem voltado
# zero. Não dá para confiar no "-- Dump completed" do mysqldump aqui: são dois
# dumps concatenados, e o rodapé do primeiro (a estrutura) cai no MEIO do
# arquivo — um dump cortado durante os dados, que é como um disco cheio o
# corta, terminava nessa marca e passava por íntegro.
FIM_MARCA='-- FIM DO BACKUP'
rodape() { printf -- '%s (%s)\n' "$FIM_MARCA" "$DB_N"; }

# Um passo só de leitura: conta as tabelas e confere que o arquivo termina onde
# deveria. Descomprimir duas vezes um dump grande é desperdício, e esse fim é o
# que separa "backup" de "arquivo interrompido".
# Arquivo gerado aqui exige a marca própria; dump avulso (mysqldump na mão,
# sem os dois passos) ainda vale pelo rodapé padrão — recusá-lo seria recusar
# justamente o arquivo que alguém tem à mão numa emergência.
resumo_dump() {
    gzip -dc "$1" | awk -v marca="$FIM_MARCA" '
        NR == 1 && /^-- Backup /  { nosso = 1 }
        /^CREATE TABLE/           { t++ }
        NF                        { ultima = $0 }
        END {
            fim = nosso ? (index(ultima, marca) == 1) : (ultima ~ /^-- Dump completed/)
            print t + 0, fim + 0
        }
    '
}

# O destino sobrevive ao fim do contêiner?
#
# Sem um Volume montado, o disco do serviço é apagado quando ele termina — e um
# serviço de cron termina todo dia. O backup rodava, dizia "✓", e o arquivo
# sumia junto com o contêiner: sucesso diário até o dia de restaurar.
#
# A checagem só vale NO PROVEDOR (as variáveis RAILWAY_* dizem que é lá): na
# máquina de quem desenvolve, "/" é disco de verdade e o aviso seria ruído em
# toda execução — e aviso que aparece sempre é aviso que ninguém lê.
avisar_efemero() {
    [ -n "${RAILWAY_ENVIRONMENT:-}${RAILWAY_PROJECT_ID:-}${RAILWAY_SERVICE_ID:-}" ] || return 0
    command -v stat >/dev/null 2>&1 || return 0
    local ponto
    ponto=$(stat -c '%m' "$BACKUP_DIR" 2>/dev/null) || return 0
    [ "$ponto" = "/" ] || return 0
    erro "ATENÇÃO: $BACKUP_DIR está no disco do contêiner (ponto de montagem \"/\")."
    erro "  Esse disco é apagado quando o contêiner termina — o arquivo NÃO sobrevive."
    erro "  Monte um Volume e aponte BACKUP_DIR para o Mount Path dele."
}

gerar() {
    conectar
    [ -n "$DUMP" ] || { erro "nem mysqldump nem mariadb-dump no PATH (instale o cliente MySQL/MariaDB)."; exit 1; }
    opcoes_dump

    mkdir -p "$BACKUP_DIR" || exit 1
    avisar_efemero

    local carimbo destino parcial ignorar=() t
    carimbo=$(date +%Y-%m-%d-%H%M%S)
    destino="$BACKUP_DIR/$PREFIXO-$carimbo.sql.gz"
    parcial="$destino.parcial"
    [ -e "$destino" ] && { erro "já existe $destino — não sobrescrevo backup."; exit 1; }

    for t in "${TABELAS_SEM_DADOS[@]}"; do ignorar+=(--ignore-table="$DB_N.$t"); done

    msg "Banco:   $DB_N em $DB_H:${DB_P:-3306} (cliente: $DUMP)"
    msg "Gravando $destino …"

    # Dois passos no MESMO fluxo: primeiro a estrutura de todas as tabelas,
    # depois os dados de todas menos as descartáveis. O `&&` faz o grupo
    # devolver erro se qualquer um falhar, e o `pipefail` leva esse erro através
    # do gzip — sem ele, `dump | gzip` sempre "dá certo" e o que sobra é um
    # arquivo pela metade com cara de backup.
    {
        cabecalho \
            && "$DUMP" --defaults-file="$CNF" "${OPCOES[@]}" --no-data "$DB_N" \
            && "$DUMP" --defaults-file="$CNF" "${OPCOES[@]}" --no-create-info "${ignorar[@]}" "$DB_N" \
            && rodape
    } 2> "$TMP/erro.log" | gzip -9 > "$parcial"
    local estado=$?

    if [ -s "$TMP/erro.log" ]; then cat "$TMP/erro.log" >&2; fi

    if [ "$estado" -ne 0 ]; then
        rm -f "$parcial"
        erro "o dump falhou — nada foi gravado."
        if grep -qi 'caching_sha2_password' "$TMP/erro.log" 2>/dev/null; then
            erro "o servidor usa caching_sha2_password: use o cliente do MySQL (MYSQLDUMP_BIN=mysqldump do mysql-client) ou habilite TLS."
        fi
        exit 1
    fi

    if ! gzip -t "$parcial" 2>/dev/null; then
        rm -f "$parcial"; erro "o arquivo gerado não passa no teste do gzip."; exit 1
    fi

    local tabelas fim
    read -r tabelas fim < <(resumo_dump "$parcial")
    if [ "$fim" -ne 1 ]; then
        rm -f "$parcial"; erro "dump truncado (sem a marca de conclusão) — nada foi gravado."; exit 1
    fi

    # Confere contra o banco: dump que perdeu tabela no caminho ainda termina
    # com a marca de conclusão.
    if [ -n "$CLIENTE" ]; then
        local esperado
        esperado=$("$CLIENTE" --defaults-file="$CNF" -N -B -D "$DB_N" \
            -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'" 2>/dev/null)
        if [ -n "$esperado" ] && [ "$esperado" != "$tabelas" ]; then
            rm -f "$parcial"
            erro "o dump tem $tabelas tabela(s) e o banco tem $esperado — nada foi gravado."
            exit 1
        fi
    fi

    # Só agora vira backup: até aqui o nome era .parcial, e um Ctrl-C no meio
    # deixaria para trás um arquivo com cara de cópia boa.
    mv "$parcial" "$destino" || exit 1
    chmod 600 "$destino"

    msg "✓ $destino ($(du -h "$destino" | cut -f1), $tabelas tabelas)"
    remoto "$destino"
    faxina
}

# ─────────────────────────────────────────────────────────────────────────────
# Cópia fora do provedor
#
# O backup local protege contra erro DENTRO do provedor — alguém apagar dado,
# uma migração ruim. Não protege contra perder o provedor: conta suspensa,
# projeto excluído, região fora do ar. Por isso a cópia sai daqui, logo depois
# de o arquivo ter passado por íntegro (nunca antes: subir um dump truncado
# gasta banda para guardar lixo com cara de backup).
#
# Sem as variáveis do B2 isto não faz nada e não reclama: quem ainda não
# configurou a cópia remota não pode ver o backup diário falhar por causa dela.
# Falha no envio também NÃO derruba o backup — o arquivo local já existe, e
# devolver erro aqui faria o cron marcar como perdido um backup que está feito.
remoto() {
    local arquivo=$1
    [ -n "${B2_KEY_ID:-}" ] && [ -n "${B2_KEY:-}" ] || return 0
    command -v php >/dev/null 2>&1 || { erro "cópia remota: php não está no PATH."; return 0; }
    php "$(dirname "$0")/backup_remoto.php" enviar "$arquivo" \
        || erro "a cópia remota falhou — o backup LOCAL está feito em $arquivo."
}

faxina() {
    # Um `.parcial` com mais de duas horas é resto de um dump morto no meio
    # (kill -9 não passa pelo trap): ficava no volume para sempre.
    find "$BACKUP_DIR" -maxdepth 1 -type f -name "$PREFIXO-*.parcial" -mmin +120 -delete 2>/dev/null

    case "$MANTER" in ''|*[!0-9]*) return 0 ;; esac
    [ "$MANTER" -gt 0 ] || return 0

    local antigos=() f
    # Ordena por NOME, não por data do arquivo: o carimbo está no nome e é
    # crescente, enquanto um arquivo copiado de outra máquina chega com mtime
    # novo — e a faxina apagaria o backup errado.
    mapfile -t antigos < <(
        find "$BACKUP_DIR" -maxdepth 1 -type f -name "$PREFIXO-*.sql.gz" 2>/dev/null \
            | LC_ALL=C sort -r | tail -n +$((MANTER + 1))
    )
    for f in "${antigos[@]}"; do
        rm -f "$f" && msg "  faxina: removido $(basename "$f")"
    done
}

# ─────────────────────────────────────────────────────────────────────────────
# Leitura e restauração
# ─────────────────────────────────────────────────────────────────────────────

listar() {
    # Só para saber de que banco é a pasta — não abre conexão nenhuma.
    conectar
    local achou=0 f
    while IFS= read -r f; do
        achou=1
        printf '%s  %s\n' "$(du -h "$f" | cut -f1)" "$f"
    done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name "$PREFIXO-*.sql.gz" 2>/dev/null | LC_ALL=C sort -r)
    [ "$achou" -eq 1 ] || msg "Nenhum backup de $DB_N em $BACKUP_DIR."
}

verificar() {
    local arq=${1:-}
    [ -n "$arq" ] || { erro "uso: ./cli/backup.sh verificar <arquivo.sql.gz>"; exit 2; }
    [ -f "$arq" ] || { erro "arquivo não encontrado: $arq"; exit 1; }

    if ! gzip -t "$arq" 2>/dev/null; then erro "arquivo corrompido (gzip)."; exit 1; fi

    gzip -dc "$arq" 2>/dev/null | head -7
    local tabelas fim
    read -r tabelas fim < <(resumo_dump "$arq")
    if [ "$fim" -ne 1 ]; then erro "dump truncado — não serve para restaurar."; exit 1; fi
    msg "✓ íntegro: $tabelas tabela(s)"
}

restaurar() {
    local arq=${1:-}
    [ -n "$arq" ] || { erro "uso: ./cli/backup.sh restaurar <arquivo.sql.gz>"; exit 2; }
    [ -f "$arq" ] || { erro "arquivo não encontrado: $arq"; exit 1; }

    conectar
    [ -n "$CLIENTE" ] || { erro "nem mysql nem mariadb no PATH (instale o cliente MySQL/MariaDB)."; exit 1; }

    if ! gzip -t "$arq" 2>/dev/null; then erro "arquivo corrompido (gzip) — não restauro."; exit 1; fi
    local tabelas fim
    read -r tabelas fim < <(resumo_dump "$arq")
    if [ "$fim" -ne 1 ]; then erro "dump truncado — não restauro."; exit 1; fi

    msg "Arquivo: $arq ($tabelas tabelas)"
    gzip -dc "$arq" 2>/dev/null | head -4 | sed 's/^/  /'
    msg "Destino: $DB_N em $DB_H:${DB_P:-3306} (cliente: $CLIENTE)"
    msg "As tabelas presentes no arquivo serão APAGADAS e recriadas com o conteúdo dele."

    # Confirmação explícita, digitando o nome do banco: restaurar é o gesto que
    # apaga o trabalho de todo mundo desde o dump, e um "s" distraído é barato
    # demais para o tamanho do estrago.
    if [ "${CONFIRMAR:-}" != "$DB_N" ]; then
        if [ ! -t 0 ]; then
            erro "sem terminal para confirmar — rode com CONFIRMAR=$DB_N ./cli/backup.sh restaurar $arq"
            exit 1
        fi
        printf 'Digite o nome do banco para confirmar (%s): ' "$DB_N"
        local resposta=""
        read -r resposta
        [ "$resposta" = "$DB_N" ] || { erro "cancelado."; exit 1; }
    fi

    gzip -dc "$arq" | "$CLIENTE" --defaults-file="$CNF" "$DB_N" 2> "$TMP/erro.log"
    local estado=$?
    if [ -s "$TMP/erro.log" ]; then cat "$TMP/erro.log" >&2; fi
    if [ "$estado" -ne 0 ]; then erro "a restauração falhou — o banco pode ter ficado pela metade."; exit 1; fi

    msg "✓ restaurado em $DB_N."
    msg "  Tabela que exista no banco e não no arquivo continua como estava."
    msg "  Se o arquivo for mais antigo que o código, rode: php database/migrate.php"
}

# ─────────────────────────────────────────────────────────────────────────────

case "${1:-gerar}" in
    gerar|backup)      gerar ;;
    listar)            listar ;;
    verificar)         verificar "${2:-}" ;;
    restaurar)         restaurar "${2:-}" ;;
    -h|--help|ajuda)   uso ;;
    *)                 erro "comando desconhecido: $1"; uso >&2; exit 2 ;;
esac

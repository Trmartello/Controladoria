<?php
// Migração idempotente: aplica schema + seeds e garante o usuário admin.
// Roda no start do container (entrypoint) e pode ser executada manualmente.

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];
$dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
echo "migrate: conectando em {$db['host']}:{$db['port']}/{$db['name']} (usuário {$db['user']}).\n";

$pdo = null;
for ($tentativa = 1; $tentativa <= 30; $tentativa++) {
    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        break;
    } catch (PDOException $e) {
        // Erro PERMANENTE não melhora esperando: insistir 30 vezes escondia a
        // causa atrás de "aguardando banco" e mandava o operador procurar um
        // MySQL fora do ar quando o problema era a senha ou o nome da base.
        // 1045 = acesso negado; 1049 = base inexistente.
        $codigo = (int)($e->errorInfo[1] ?? 0);
        if (in_array($codigo, [1045, 1049], true)) {
            fwrite(STDERR, "migrate: credenciais ou base inválidas ({$codigo}): {$e->getMessage()}\n");
            exit(1);
        }
        fwrite(STDERR, "migrate: aguardando banco ({$tentativa}/30): {$e->getMessage()}\n");
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "migrate: banco indisponível, abortando.\n");
    exit(1);
}

// Uma migração por vez: garantirColuna/garantirIndice conferem e depois agem,
// e duas réplicas subindo juntas poderiam passar as duas na conferência — a
// segunda morreria com "Duplicate column name" e o container não subiria.
$travou = $pdo->query("SELECT GET_LOCK('migrate_controladoria', 60)")->fetchColumn();
if ((int)$travou !== 1) {
    // Sem o lock, seguir em frente é exatamente o cenário que ele existe para
    // evitar (duas réplicas no check-then-act de garantirColuna) — só que 60s
    // mais tarde. Melhor abortar e deixar o Railway tentar de novo.
    fwrite(STDERR, "migrate: outra migração está em andamento (lock não obtido). Abortando.\n");
    exit(1);
}

function executarArquivoSql(PDO $pdo, string $caminho): void
{
    $sql = file_get_contents($caminho);
    // Remove linhas de comentário e divide por ';' no fim de linha
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $comando) {
        $comando = trim($comando);
        if ($comando !== '') {
            $pdo->exec($comando);
        }
    }
}

/** Alterações em tabelas já existentes (CREATE IF NOT EXISTS não as cobre). */
function garantirColuna(PDO $pdo, string $tabela, string $coluna, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabela, $coluna]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($ddl);
        echo "migrate: coluna {$tabela}.{$coluna} criada.\n";
    }
}

/**
 * Cria um índice só se ele ainda não existe. Serve para índice que nasceu no
 * CREATE TABLE depois que a tabela já estava em produção — ali o
 * `CREATE TABLE IF NOT EXISTS` não faz nada e o índice nunca chegaria.
 */
function garantirIndice(PDO $pdo, string $tabela, string $indice, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$tabela, $indice]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($ddl);
        echo "migrate: índice {$tabela}.{$indice} criado.\n";
    }
}

/**
 * Normaliza texto para comparar: minúsculas, sem acento, espaços colapsados.
 * Usada pela carga de conteúdo do cenário, para não regravar o que já está na
 * tela. A tabela substitui o Normalizer porque a extensão intl não está na
 * imagem — mesma razão de PublicoController::normalizar().
 */
function chaveTextoCenario(string $t): string
{
    $t = strtr(mb_strtolower(trim($t), 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
        'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return preg_replace('/\s+/u', ' ', $t);
}

executarArquivoSql($pdo, __DIR__ . '/schema.sql');

// Análises do diagnóstico são anuais (horizontes seguem plurianuais)
garantirColuna($pdo, 'cenario_item', 'ano',
    'ALTER TABLE cenario_item ADD COLUMN ano SMALLINT NULL AFTER planejamento_id');
garantirColuna($pdo, 'fator', 'ano',
    'ALTER TABLE fator ADD COLUMN ano SMALLINT NULL AFTER planejamento_id');

// Prazos por calendário: início e fim em projetos e ações planejadas
// (os campos de texto prazo/quando_ permanecem para os registros antigos)
garantirColuna($pdo, 'projeto', 'data_inicio',
    'ALTER TABLE projeto ADD COLUMN data_inicio DATE NULL AFTER prazo');
garantirColuna($pdo, 'projeto', 'data_fim',
    'ALTER TABLE projeto ADD COLUMN data_fim DATE NULL AFTER data_inicio');
garantirColuna($pdo, 'desdobramento', 'data_inicio',
    'ALTER TABLE desdobramento ADD COLUMN data_inicio DATE NULL AFTER quando_');
garantirColuna($pdo, 'desdobramento', 'data_fim',
    'ALTER TABLE desdobramento ADD COLUMN data_fim DATE NULL AFTER data_inicio');

// Plano de ação em três níveis: projeto → iniciativa → ação
garantirColuna($pdo, 'desdobramento', 'iniciativa_id',
    'ALTER TABLE desdobramento ADD COLUMN iniciativa_id INT NULL AFTER projeto_id,
     ADD CONSTRAINT fk_desd_iniciativa FOREIGN KEY (iniciativa_id)
         REFERENCES iniciativa(id) ON DELETE CASCADE');
garantirColuna($pdo, 'desdobramento', 'prioridade',
    "ALTER TABLE desdobramento ADD COLUMN prioridade
     ENUM('ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA' AFTER status");
garantirColuna($pdo, 'desdobramento', 'concluido_em',
    'ALTER TABLE desdobramento ADD COLUMN concluido_em DATETIME NULL AFTER progresso');

// Status manuais novos (pausada e aguardando validação) nas ações já existentes
$tipoStatus = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'desdobramento' AND COLUMN_NAME = 'status'"
)->fetchColumn();
if ($tipoStatus && !str_contains((string)$tipoStatus, 'PAUSADO')) {
    $pdo->exec(
        "ALTER TABLE desdobramento MODIFY COLUMN status
         ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO','PAUSADO','AGUARDANDO_VALIDACAO')
         NOT NULL DEFAULT 'NAO_INICIADO'"
    );
    echo "migrate: status da ação ampliado (PAUSADO, AGUARDANDO_VALIDACAO).\n";
}

// A ideia da coleta pode ir para um plano de ação (vira desdobramento depois),
// além de Cenário/fator do diagnóstico
$tipoDestinoCi = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'destino_tipo'"
)->fetchColumn();
if ($tipoDestinoCi && !str_contains((string)$tipoDestinoCi, 'ACAO')) {
    $pdo->exec("ALTER TABLE coleta_item MODIFY COLUMN destino_tipo ENUM('CENARIO','FATOR','ACAO') NULL");
    echo "migrate: coleta_item.destino_tipo agora aceita ACAO (plano de ação).\n";
}

// O projeto pertence a um ano do planejamento; o horizonte deriva do ano.
// Backfill: o horizonte escolhido explicitamente vence a data de início, e o
// último recurso é o primeiro ano de execução (ano_base é o ano de elaboração,
// que nenhum horizonte contempla)
garantirColuna($pdo, 'projeto', 'ano',
    'ALTER TABLE projeto ADD COLUMN ano SMALLINT NULL AFTER tipo');
$pdo->exec(
    'UPDATE projeto p
     JOIN planejamento pl ON pl.id = p.planejamento_id
     JOIN ciclo c ON c.id = pl.ciclo_id
     LEFT JOIN horizonte h ON h.id = p.horizonte_id
     SET p.ano = COALESCE(h.ano_inicio, YEAR(p.data_inicio), c.ano_inicio)
     WHERE p.ano IS NULL'
);
garantirColuna($pdo, 'projeto', 'descricao',
    'ALTER TABLE projeto ADD COLUMN descricao TEXT NULL AFTER titulo');
// Reparo dos backfills antigos (idempotente): ano desalinhado do horizonte
// escolhido volta para o primeiro ano dele; projeto sem horizonte com ano
// anterior à execução vai para o primeiro ano de execução do ciclo
$pdo->exec(
    'UPDATE projeto p
     JOIN horizonte h ON h.id = p.horizonte_id
     SET p.ano = h.ano_inicio
     WHERE p.ano NOT BETWEEN h.ano_inicio AND h.ano_fim'
);
$pdo->exec(
    'UPDATE projeto p
     JOIN planejamento pl ON pl.id = p.planejamento_id
     JOIN ciclo c ON c.id = pl.ciclo_id
     SET p.ano = c.ano_inicio
     WHERE p.horizonte_id IS NULL AND (p.ano IS NULL OR p.ano < c.ano_inicio)'
);

// Ações recorrentes (semanal/mensal) e vínculo do responsável com o usuário,
// para o disparo dos avisos por e-mail
garantirColuna($pdo, 'desdobramento', 'quem_usuario_id',
    'ALTER TABLE desdobramento ADD COLUMN quem_usuario_id INT NULL AFTER quem');
garantirColuna($pdo, 'desdobramento', 'recorrencia',
    "ALTER TABLE desdobramento ADD COLUMN recorrencia
     ENUM('NENHUMA','SEMANAL','MENSAL') NOT NULL DEFAULT 'NENHUMA' AFTER quem_usuario_id");
garantirColuna($pdo, 'desdobramento', 'recorrencia_dia',
    'ALTER TABLE desdobramento ADD COLUMN recorrencia_dia TINYINT NULL AFTER recorrencia');
garantirColuna($pdo, 'desdobramento', 'recorrencia_ate',
    'ALTER TABLE desdobramento ADD COLUMN recorrencia_ate DATE NULL AFTER recorrencia_dia');
// Casa o nome digitado em "Quem?" com o usuário cadastrado de mesmo nome
$pdo->exec(
    'UPDATE desdobramento d
     JOIN usuario u ON u.ativo = 1 AND u.nome = d.quem
     SET d.quem_usuario_id = u.id
     WHERE d.quem_usuario_id IS NULL AND d.quem IS NOT NULL AND d.quem <> \'\''
);

// Ações criadas antes das iniciativas são agrupadas numa frente padrão.
// O NOT EXISTS evita criar uma SEGUNDA "Ações do projeto" num deploy posterior,
// caso apareça outra ação sem iniciativa (o UPDATE seguinte apontaria para a
// primeira e a segunda ficaria vazia na tela).
$pdo->exec(
    "INSERT INTO iniciativa (projeto_id, titulo, ordem)
     SELECT DISTINCT d.projeto_id, 'Ações do projeto', 0
     FROM desdobramento d
     WHERE d.iniciativa_id IS NULL
       AND NOT EXISTS (SELECT 1 FROM (SELECT projeto_id, titulo FROM iniciativa) i2
                       WHERE i2.projeto_id = d.projeto_id AND i2.titulo = 'Ações do projeto')"
);
$pdo->exec(
    "UPDATE desdobramento d
     JOIN iniciativa i ON i.projeto_id = d.projeto_id AND i.titulo = 'Ações do projeto'
     SET d.iniciativa_id = i.id
     WHERE d.iniciativa_id IS NULL"
);
// Análises antigas sem ano pertencem ao ano-base do ciclo
$pdo->exec(
    'UPDATE cenario_item ci
     JOIN planejamento p ON p.id = ci.planejamento_id
     JOIN ciclo c ON c.id = p.ciclo_id
     SET ci.ano = c.ano_base WHERE ci.ano IS NULL'
);
$pdo->exec(
    'UPDATE fator f
     JOIN planejamento p ON p.id = f.planejamento_id
     JOIN ciclo c ON c.id = p.ciclo_id
     SET f.ano = c.ano_base WHERE f.ano IS NULL'
);
// Ideias travadas: ACEITO sem destino nenhum. Sobra de quando excluir o fator
// (ou o item de cenário) soltava o vínculo mas mantinha a ideia como tratada —
// ela ficava sem análise e recusava novo encaminhamento com "já foi tratada
// por outra pessoa". Volta para a fila, como faz o "Desmarcar".
$pdo->exec(
    "UPDATE coleta_item
        SET situacao = 'SELECIONADO', triado_por = NULL, triado_em = NULL
      WHERE situacao = 'ACEITO' AND destino_tipo IS NULL AND destino_id IS NULL"
);

executarArquivoSql($pdo, __DIR__ . '/seeds.sql');

// ---- Negócios oficiais: aplica a lista da fonte a quem já tem cadastro ----
// O seeds.sql só age com a tabela vazia, então uma revisão da fonte (a de
// 03/08/2026 acrescentou 3, 5 e 17 e trocou os rótulos longos pelos curtos)
// nunca chegaria a uma instalação em uso — dependeria de alguém lembrar de
// clicar em "Sincronizar" no Cadastro de Negócios.
//
// A lista vem por reflexão de QlikSync::NEGOCIOS_FONTE (a fonte da verdade),
// para o migrate não virar uma terceira cópia dos códigos que envelhece à
// parte. O arquivo só declara a classe: incluí-lo não executa nada nem exige
// o autoload da aplicação.
require_once __DIR__ . '/../app/Services/QlikSync.php';
$oficiais = (new ReflectionClass(App\Services\QlikSync::class))->getConstant('NEGOCIOS_FONTE');
$renomeados = 0;
$novos = 0;
foreach ($oficiais as $cod => $nome) {
    $cod = (string)$cod;
    // Renomeia pelo CÓDIGO, que é a identidade do negócio no ERP. Só linha da
    // sincronização: cadastro manual nunca é sobrescrito.
    $atual = $pdo->prepare("SELECT id, nome FROM negocio WHERE cod_negocio = ? AND origem = 'QLIK'");
    $atual->execute([$cod]);
    $linha = $atual->fetch(PDO::FETCH_ASSOC);

    // O nome oficial já pertencer a OUTRA linha impediria distinguir as duas no
    // seletor — nesse caso o rename fica para a sincronização resolver, que
    // sabe contar o conflito.
    $dono = $pdo->prepare('SELECT id FROM negocio WHERE nome = ? AND id <> ?');
    $dono->execute([$nome, (int)($linha['id'] ?? 0)]);
    $nomeOcupado = (bool)$dono->fetchColumn();

    if ($linha) {
        if ($linha['nome'] !== $nome && !$nomeOcupado) {
            $pdo->prepare('UPDATE negocio SET nome = ? WHERE id = ?')
                ->execute([$nome, (int)$linha['id']]);
            $renomeados++;
        }
        continue;
    }
    // Código que ainda não existe entra como QLIK. Sem código nem nome livres
    // não há inserção: duplicar qualquer um dos dois é pior que faltar a linha,
    // e a tela de sincronização mostra o conflito.
    $ocupa = $pdo->prepare('SELECT id FROM negocio WHERE cod_negocio = ?');
    $ocupa->execute([$cod]);
    if ($ocupa->fetchColumn() || $nomeOcupado) {
        continue;
    }
    $pdo->prepare("INSERT INTO negocio (cod_negocio, nome, origem) VALUES (?, ?, 'QLIK')")
        ->execute([$cod, $nome]);
    $novos++;
}
if ($renomeados || $novos) {
    echo "migrate: negócios oficiais — {$renomeados} renomeado(s), {$novos} novo(s).\n";
}

// ---- Faxina dos negócios inativos que não representam nada ----
// Sobra de carga antiga: linha com o nome longo de antes da revisão (ex.: "99 —
// NEGOCIO UTM" ao lado do "7 — UTM" oficial) que ficou desativada ocupando
// espaço no Cadastro. Some no deploy, sem ninguém precisar clicar.
//
// Três guardas, e nenhuma delas é decorativa:
// - fora da lista oficial: apagar quem está nela seria trabalho perdido, porque
//   o passo acima recria a linha na mesma execução;
// - sem planejamento: a FK é RESTRICT e o DELETE morreria — e junto iria todo o
//   diagnóstico daquele negócio, que é exatamente o que ninguém quer perder;
// - sem vínculo de usuário: `usuario_negocio` cai por CASCADE, então uma linha
//   que ainda é escopo de alguém sai só pela tela, com confirmação.
$sobras = $pdo->query(
    "SELECT n.id, n.cod_negocio, n.nome FROM negocio n
      WHERE n.ativo = 0
        AND NOT EXISTS (SELECT 1 FROM planejamento p WHERE p.negocio_id = n.id)
        AND NOT EXISTS (SELECT 1 FROM usuario_negocio un WHERE un.negocio_id = n.id)"
)->fetchAll(PDO::FETCH_ASSOC);
$apagados = 0;
foreach ($sobras as $s) {
    if (array_key_exists((string)$s['cod_negocio'], $oficiais)) {
        continue;
    }
    $pdo->prepare('DELETE FROM negocio WHERE id = ?')->execute([(int)$s['id']]);
    echo "migrate: negócio inativo removido — {$s['cod_negocio']} — {$s['nome']}.\n";
    $apagados++;
}
if ($apagados) {
    echo "migrate: {$apagados} negócio(s) inativo(s) removido(s) do cadastro.\n";
}

// ---- Cenário macroeconômico na Análise de Cenário ----
// O seeds.sql só age com o contexto vazio e a tela já tem itens escritos à mão,
// então a carga nunca chegaria por lá. Aqui ela chega no deploy — UMA vez.
//
// A marca em `carga_conteudo` é o que torna isso seguro: cenário é conteúdo
// EDITÁVEL, e sem a marca todo deploy recriaria o item que alguém apagou e
// reporia o texto que alguém reescreveu. Revisar os números pede chave nova no
// arquivo de conteúdo; a chave antiga fica marcada e nunca mais é reaplicada.
//
// Os textos vêm de database/conteudo_cenario_macro.php, o mesmo arquivo que
// cli/cenario_macro.php lê — como os negócios oficiais vêm de
// QlikSync::NEGOCIOS_FONTE, para não existir uma segunda cópia envelhecendo à
// parte.
$conteudoCenario = require __DIR__ . '/conteudo_cenario_macro.php';
$chaveCarga = $conteudoCenario['chave'];
$jaAplicada = $pdo->prepare('SELECT 1 FROM carga_conteudo WHERE chave = ?');
$jaAplicada->execute([$chaveCarga]);
if (!$jaAplicada->fetchColumn()) {
    $anoCarga = (int)$conteudoCenario['ano'];
    // Só o planejamento CORPORATIVO: o cenário macro é leitura da cooperativa
    // inteira, e replicá-lo nos doze negócios encheria cada tela com o mesmo
    // texto, empurrando para baixo a análise que é própria do negócio.
    // O ano precisa caber no ciclo: o seletor da tela é limitado a
    // [ano_base, ano_fim] e o item existiria sem nunca aparecer para ninguém.
    $alvos = $pdo->prepare(
        "SELECT p.id FROM planejamento p
           JOIN ciclo c ON c.id = p.ciclo_id
          WHERE p.escopo = 'CORPORATIVO' AND ? BETWEEN c.ano_base AND c.ano_fim"
    );
    $alvos->execute([$anoCarga]);
    $planos = $alvos->fetchAll(PDO::FETCH_COLUMN);

    $gravados = 0;
    foreach ($planos as $planoId) {
        $planoId = (int)$planoId;
        // Texto que já está na tela não entra de novo: a carga pode ter sido
        // aplicada à mão pela CLI antes deste deploy, e o operador veria tudo
        // duplicado. A comparação normaliza acento, caixa e espaço, como faz
        // o agrupamento da Coleta.
        $existentes = [];
        $atuais = $pdo->prepare(
            'SELECT descricao FROM cenario_item WHERE planejamento_id = ? AND ano = ?'
        );
        $atuais->execute([$planoId, $anoCarga]);
        foreach ($atuais->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $existentes[chaveTextoCenario((string)$d)] = true;
        }

        foreach ($conteudoCenario['itens'] as $tipo => $textos) {
            // Continua a numeração do que já está na tela, em vez de disputar a
            // ordem com os itens que o usuário escreveu antes
            $maior = $pdo->prepare(
                'SELECT COALESCE(MAX(ordem), 0) FROM cenario_item
                  WHERE planejamento_id = ? AND ano = ? AND tipo = ?'
            );
            $maior->execute([$planoId, $anoCarga, $tipo]);
            $ordem = (int)$maior->fetchColumn();

            $insere = $pdo->prepare(
                'INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($textos as $texto) {
                if (isset($existentes[chaveTextoCenario($texto)])) {
                    continue;
                }
                $existentes[chaveTextoCenario($texto)] = true;
                $insere->execute([$planoId, $anoCarga, $tipo, ++$ordem, $texto]);
                $gravados++;
            }
        }
    }
    // A marca é gravada mesmo sem plano corporativo elegível: a carga é uma
    // fotografia datada, e reavaliá-la a cada deploy futuro faria o texto de
    // agosto/2026 aparecer meses depois, num ciclo criado muito mais tarde.
    $pdo->prepare('INSERT INTO carga_conteudo (chave, detalhe) VALUES (?, ?)')
        ->execute([$chaveCarga, "{$gravados} item(ns) em " . count($planos) . ' planejamento(s)']);
    echo "migrate: cenário macro ({$chaveCarga}) — {$gravados} item(ns) "
        . 'em ' . count($planos) . " planejamento(s) corporativo(s).\n";
}

// Usuário admin inicial (senha via env ADMIN_SENHA; sem a variável, gera uma
// senha aleatória e a mostra no log uma única vez — trocar após o 1º login)
$existe = $pdo->query("SELECT COUNT(*) FROM usuario WHERE perfil = 'ADMIN'")->fetchColumn();
if ((int)$existe === 0) {
    $senha = $config['app']['admin_senha'];
    $gerada = false;
    if (!$senha) {
        $senha = bin2hex(random_bytes(9));
        $gerada = true;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO usuario (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'ADMIN')"
    );
    $stmt->execute([
        'Administrador',
        $config['app']['admin_email'],
        password_hash($senha, PASSWORD_DEFAULT),
    ]);
    echo "migrate: usuário admin criado ({$config['app']['admin_email']}).\n";
    if ($gerada) {
        echo "migrate: ADMIN_SENHA não definida — senha inicial gerada: {$senha} (troque no primeiro acesso).\n";
    }
}

// ---- Tempestade de ideias: rodada ao vivo com entrada por PIN ----
garantirColuna($pdo, 'coleta_item', 'rodada_id',
    'ALTER TABLE coleta_item ADD COLUMN rodada_id INT NULL AFTER planejamento_id');
garantirColuna($pdo, 'coleta_item', 'autor_nome',
    'ALTER TABLE coleta_item ADD COLUMN autor_nome VARCHAR(120) NULL AFTER autor_id');
garantirColuna($pdo, 'coleta_item', 'participante_token',
    'ALTER TABLE coleta_item ADD COLUMN participante_token CHAR(32) NULL AFTER autor_nome');
garantirColuna($pdo, 'coleta_item', 'dividido_de_id',
    'ALTER TABLE coleta_item ADD COLUMN dividido_de_id INT NULL AFTER participante_token');
// Agrupamento manual: o condutor arrasta uma ideia sobre a outra quando têm
// o mesmo sentido, e a ideia arrastada aponta para a que ficou como líder
garantirColuna($pdo, 'coleta_item', 'agrupado_em_id',
    'ALTER TABLE coleta_item ADD COLUMN agrupado_em_id INT NULL AFTER dividido_de_id');
garantirColuna($pdo, 'coleta_item', 'adiado',
    'ALTER TABLE coleta_item ADD COLUMN adiado TINYINT(1) NOT NULL DEFAULT 0 AFTER agrupado_em_id');
garantirColuna($pdo, 'coleta_item', 'impacto',
    "ALTER TABLE coleta_item ADD COLUMN impacto ENUM('ALTO','BAIXO') NULL AFTER situacao");
garantirColuna($pdo, 'coleta_item', 'esforco',
    "ALTER TABLE coleta_item ADD COLUMN esforco ENUM('BAIXO','ALTO') NULL AFTER impacto");
garantirColuna($pdo, 'coleta_item', 'votos',
    'ALTER TABLE coleta_item ADD COLUMN votos SMALLINT NOT NULL DEFAULT 0 AFTER esforco');

// As colunas acima nasceram por ALTER e vieram sem índice nenhum. A tela ao
// vivo da tempestade consulta por (rodada_id, participante_token) de 4 em 4
// segundos POR PARTICIPANTE — numa oficina de 30 pessoas são centenas de
// varreduras por minuto justamente na tabela que mais cresce.
garantirIndice($pdo, 'coleta_item', 'idx_ci_rodada',
    'ALTER TABLE coleta_item ADD KEY idx_ci_rodada (rodada_id, situacao)');
garantirIndice($pdo, 'coleta_item', 'idx_ci_part',
    'ALTER TABLE coleta_item ADD KEY idx_ci_part (rodada_id, participante_token)');
garantirIndice($pdo, 'coleta_item', 'idx_ci_grupo',
    'ALTER TABLE coleta_item ADD KEY idx_ci_grupo (agrupado_em_id)');

/** Cria uma chave estrangeira só se ela ainda não existe. */
function garantirFk(PDO $pdo, string $tabela, string $nome, string $ddl): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([$tabela, $nome]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($ddl);
        echo "migrate: chave estrangeira {$tabela}.{$nome} criada.\n";
    }
}

// rodada_id e triado_por nasceram por ALTER e ficaram sem FK — ao contrário de
// dividido_de_id/agrupado_em_id, que são deliberadamente sem chave (o código as
// solta à mão, porque apontam para linhas que podem sumir a qualquer momento).
// Limpa referências mortas antes, senão o ALTER falha e o container não sobe.
$pdo->exec('UPDATE coleta_item SET rodada_id = NULL WHERE rodada_id IS NOT NULL
            AND rodada_id NOT IN (SELECT id FROM (SELECT id FROM coleta_rodada) r)');
$pdo->exec('UPDATE coleta_item SET triado_por = NULL WHERE triado_por IS NOT NULL
            AND triado_por NOT IN (SELECT id FROM (SELECT id FROM usuario) u)');
garantirFk($pdo, 'coleta_item', 'fk_ci_rodada',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_rodada
     FOREIGN KEY (rodada_id) REFERENCES coleta_rodada(id) ON DELETE SET NULL');
garantirFk($pdo, 'coleta_item', 'fk_ci_triador',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_triador
     FOREIGN KEY (triado_por) REFERENCES usuario(id) ON DELETE SET NULL');

// Quem entra pela tempestade não tem conta: o autor passa a ser opcional
$autorNulo = $pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'autor_id'"
)->fetchColumn();
if ($autorNulo === 'NO') {
    $pdo->exec('ALTER TABLE coleta_item MODIFY COLUMN autor_id INT NULL');
    echo "migrate: coleta_item.autor_id passou a aceitar nulo (entrada sem cadastro).\n";
}

// Estado intermediário: escolhida para tratar, ainda sem destino
$tipoSituacaoColeta = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'situacao'"
)->fetchColumn();
if ($tipoSituacaoColeta && !str_contains((string)$tipoSituacaoColeta, 'DIVIDIDO')) {
    $pdo->exec(
        "ALTER TABLE coleta_item MODIFY COLUMN situacao
         ENUM('NOVO','SELECIONADO','ACEITO','DESCARTADO','DIVIDIDO') NOT NULL DEFAULT 'NOVO'"
    );
    // Quem já foi dividido estava marcado como descartado: o rótulo dizia
    // "não entrou" para uma ideia que entrou em pedaços
    $pdo->exec(
        "UPDATE coleta_item SET situacao = 'DIVIDIDO'
         WHERE situacao = 'DESCARTADO' AND motivo LIKE 'Dividida em %'"
    );
    echo "migrate: situacao da ideia ampliada (SELECIONADO, DIVIDIDO).\n";
}

// Collation uniforme em todas as tabelas. Sem COLLATE explícito, cada motor
// escolhe o seu — MariaDB cai em utf8mb4_general_ci e o MySQL 8 em
// utf8mb4_0900_ai_ci —, então homologação e produção discordam em ordenação e
// na comparação de acentos (e um "nome já existe" pode valer num e não no
// outro). A conversão roda uma vez só: depois dela a consulta não acha nada.
$foraDoPadrao = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
       AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'"
)->fetchAll(PDO::FETCH_COLUMN);
if ($foraDoPadrao) {
    // As chaves estrangeiras exigem charset/collation iguais entre pai e filho;
    // durante a conversão as tabelas ficam momentaneamente diferentes
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($foraDoPadrao as $tabela) {
        $pdo->exec("ALTER TABLE `{$tabela}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo 'migrate: collation uniformizada em ' . count($foraDoPadrao) . " tabela(s).\n";
}

// Faxina das tabelas que só crescem. O coletor de sessão do PHP roda por
// probabilidade e há ambiente com session.gc_probability = 0, onde ele nunca é
// chamado — e cada visita anônima a /login já cria uma linha em `sessao`, porque
// o formulário precisa do token CSRF. Aqui a limpeza é determinística: acontece
// em todo deploy (e de novo no cron diário, em cli/notificar.php).
$sessoes = $pdo->exec("DELETE FROM sessao WHERE atualizado_em < (NOW() - INTERVAL 30 DAY)");
$tentativas = $pdo->exec("DELETE FROM coleta_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)")
    + $pdo->exec("DELETE FROM login_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)");
if ($sessoes || $tentativas) {
    echo "migrate: faxina — {$sessoes} sessão(ões) e {$tentativas} tentativa(s) expiradas removidas.\n";
}

$pdo->query("SELECT RELEASE_LOCK('migrate_controladoria')")->fetchColumn();

echo "migrate: ok.\n";

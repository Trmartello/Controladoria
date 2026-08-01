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
        ]);
        break;
    } catch (PDOException $e) {
        fwrite(STDERR, "migrate: aguardando banco ({$tentativa}/30): {$e->getMessage()}\n");
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "migrate: banco indisponível, abortando.\n");
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

// Ações criadas antes das iniciativas são agrupadas numa frente padrão
$pdo->exec(
    "INSERT INTO iniciativa (projeto_id, titulo, ordem)
     SELECT DISTINCT d.projeto_id, 'Ações do projeto', 0
     FROM desdobramento d
     WHERE d.iniciativa_id IS NULL"
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

executarArquivoSql($pdo, __DIR__ . '/seeds.sql');

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

echo "migrate: ok.\n";

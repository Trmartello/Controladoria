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

/** A tabela existe neste banco? */
function tabelaExiste(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabela]);
    return (int)$stmt->fetchColumn() > 0;
}

// A sala deixou de ser só da cascata: a pergunta passou a apontar para qualquer
// análise, e cascata_pergunta virou quiz_pergunta. O RENAME vem ANTES do
// schema.sql porque ele já declara quiz_pergunta — rodando primeiro, criaria
// uma tabela nova e VAZIA ao lado da que guarda as perguntas do encontro, e o
// roteiro sumiria sem erro nenhum. O RENAME leva junto as chaves estrangeiras
// que apontam para ela (fk_ci_pergunta), nos dois motores.
if (!tabelaExiste($pdo, 'quiz_pergunta') && tabelaExiste($pdo, 'cascata_pergunta')) {
    $pdo->exec('RENAME TABLE cascata_pergunta TO quiz_pergunta');
    echo "migrate: cascata_pergunta renomeada para quiz_pergunta.\n";
}

executarArquivoSql($pdo, __DIR__ . '/schema.sql');

// Análises do diagnóstico são anuais (horizontes seguem plurianuais)
garantirColuna($pdo, 'cenario_item', 'ano',
    'ALTER TABLE cenario_item ADD COLUMN ano SMALLINT NULL AFTER planejamento_id');
garantirColuna($pdo, 'fator', 'ano',
    'ALTER TABLE fator ADD COLUMN ano SMALLINT NULL AFTER planejamento_id');

// Fator da SWOT encaminhado ao plano de ação (mesma regra da ideia da Coleta:
// marcado e sem ação = aguardando alocação em Projetos). A FK do
// desdobramento é ON DELETE SET NULL: apagada a ação, o fator volta sozinho
// para a fila de espera, em vez de ficar apontando para um id morto — que é
// exatamente o que acontecia com a ideia da Coleta antes deste passo.
garantirColuna($pdo, 'fator', 'acao_em',
    'ALTER TABLE fator ADD COLUMN acao_em DATETIME NULL AFTER promovido_de_id');
garantirColuna($pdo, 'fator', 'acao_por',
    'ALTER TABLE fator ADD COLUMN acao_por INT NULL AFTER acao_em');
garantirColuna($pdo, 'fator', 'desdobramento_id',
    'ALTER TABLE fator ADD COLUMN desdobramento_id INT NULL AFTER acao_por');
// Referência morta impediria o ALTER e derrubaria o start do container
$pdo->exec('UPDATE fator SET acao_por = NULL WHERE acao_por IS NOT NULL
            AND acao_por NOT IN (SELECT id FROM usuario)');
$pdo->exec('UPDATE fator SET desdobramento_id = NULL WHERE desdobramento_id IS NOT NULL
            AND desdobramento_id NOT IN (SELECT id FROM desdobramento)');
garantirFk($pdo, 'fator', 'fk_fator_acao_por',
    'ALTER TABLE fator ADD CONSTRAINT fk_fator_acao_por
       FOREIGN KEY (acao_por) REFERENCES usuario(id) ON DELETE SET NULL');
garantirFk($pdo, 'fator', 'fk_fator_desdobramento',
    'ALTER TABLE fator ADD CONSTRAINT fk_fator_desdobramento
       FOREIGN KEY (desdobramento_id) REFERENCES desdobramento(id) ON DELETE SET NULL');

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
// A barra em 100% conclui a ação e guarda aqui a posição de onde saiu;
// cancelar depois devolve a barra a essa posição, sem perguntar nada
garantirColuna($pdo, 'desdobramento', 'progresso_anterior',
    'ALTER TABLE desdobramento ADD COLUMN progresso_anterior TINYINT NULL AFTER progresso');

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

// O cruzamento da SWOT vai direto ao plano de ação, como o fator da SWOT: as
// três colunas são as mesmas de lá, e por isso a fila de "aguardando plano de
// ação" continua sendo UMA — a origem muda o selo, não a pergunta.
garantirColuna($pdo, 'swot_cruzamento', 'acao_em',
    'ALTER TABLE swot_cruzamento ADD COLUMN acao_em DATETIME NULL AFTER criado_em');
garantirColuna($pdo, 'swot_cruzamento', 'acao_por',
    'ALTER TABLE swot_cruzamento ADD COLUMN acao_por INT NULL AFTER acao_em');
garantirColuna($pdo, 'swot_cruzamento', 'desdobramento_id',
    'ALTER TABLE swot_cruzamento ADD COLUMN desdobramento_id INT NULL AFTER acao_por');
garantirFk($pdo, 'swot_cruzamento', 'fk_cruz_acao_por',
    'ALTER TABLE swot_cruzamento ADD CONSTRAINT fk_cruz_acao_por
     FOREIGN KEY (acao_por) REFERENCES usuario(id)');
// SET NULL: apagada a ação, o cruzamento volta sozinho para a fila em vez de
// apontar para um desdobramento que não existe mais.
garantirFk($pdo, 'swot_cruzamento', 'fk_cruz_desdobramento',
    'ALTER TABLE swot_cruzamento ADD CONSTRAINT fk_cruz_desdobramento
     FOREIGN KEY (desdobramento_id) REFERENCES desdobramento(id) ON DELETE SET NULL');

// O item da Análise de Cenário também vai ao plano de ação (decisão do cliente
// 2026-08-31, junto com PESTEL e Porter). Mesmas três colunas do fator e do
// cruzamento, de novo: é a QUARTA origem da mesma fila, e o que a torna uma
// fila só é justamente os quatro terem o mesmo par "marcado / já virou ação".
garantirColuna($pdo, 'cenario_item', 'acao_em',
    'ALTER TABLE cenario_item ADD COLUMN acao_em DATETIME NULL AFTER descricao');
garantirColuna($pdo, 'cenario_item', 'acao_por',
    'ALTER TABLE cenario_item ADD COLUMN acao_por INT NULL AFTER acao_em');
garantirColuna($pdo, 'cenario_item', 'desdobramento_id',
    'ALTER TABLE cenario_item ADD COLUMN desdobramento_id INT NULL AFTER acao_por');
// Referência morta impediria o ALTER e derrubaria o start do container — a
// mesma faxina que o fator faz antes das dele.
$pdo->exec('UPDATE cenario_item SET acao_por = NULL WHERE acao_por IS NOT NULL
            AND acao_por NOT IN (SELECT id FROM usuario)');
$pdo->exec('UPDATE cenario_item SET desdobramento_id = NULL WHERE desdobramento_id IS NOT NULL
            AND desdobramento_id NOT IN (SELECT id FROM desdobramento)');
garantirFk($pdo, 'cenario_item', 'fk_cenario_acao_por',
    'ALTER TABLE cenario_item ADD CONSTRAINT fk_cenario_acao_por
     FOREIGN KEY (acao_por) REFERENCES usuario(id) ON DELETE SET NULL');
// SET NULL: apagada a ação, o item volta sozinho para a fila.
garantirFk($pdo, 'cenario_item', 'fk_cenario_desdobramento',
    'ALTER TABLE cenario_item ADD CONSTRAINT fk_cenario_desdobramento
     FOREIGN KEY (desdobramento_id) REFERENCES desdobramento(id) ON DELETE SET NULL');

// O relatório do disparo, que vai para quem administra depois de cada rodada de
// avisos. Ele entra em `envio_email` como os outros para herdar a trava de
// duplicidade — sem um tipo próprio, ele colidiria com o aviso do próprio
// admin na chave (tipo, referência, usuário).
$tipoEnvio = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'envio_email' AND COLUMN_NAME = 'tipo'"
)->fetchColumn();
if ($tipoEnvio && !str_contains((string)$tipoEnvio, 'RESUMO')) {
    $pdo->exec("ALTER TABLE envio_email MODIFY COLUMN tipo ENUM('SEMANAL','DIARIO','RESUMO') NOT NULL");
    echo "migrate: envio_email.tipo agora aceita RESUMO (relatório do disparo).\n";
}

// Ação cancelada não tem avanço: o percentual dela é zero. A gravação já
// garante isso (formulário e barra do cartão), mas as ações canceladas ANTES
// da regra ficaram com o percentual antigo — e o relatório, que tira a média
// no servidor, leria esse número. Idempotente por natureza: na segunda
// execução não há mais linha a corrigir.
$zeradas = $pdo->exec("UPDATE desdobramento SET progresso = 0, progresso_anterior = NULL
                        WHERE status = 'CANCELADO' AND (progresso <> 0 OR progresso_anterior IS NOT NULL)");
if ($zeradas) {
    echo "migrate: progresso zerado em {$zeradas} ação(ões) cancelada(s).\n";
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

// ---- Quiz da cascata: a sala responde células da Cascata de Escolhas ----
// A rodada ganha um modo (a MESMA sala serve os dois ritos) e a sugestão passa
// a pertencer a uma pergunta, declarando de que lado fala (escolha/renúncia).
garantirColuna($pdo, 'coleta_rodada', 'modo',
    "ALTER TABLE coleta_rodada ADD COLUMN modo ENUM('TEMPESTADE','QUIZ')
     NOT NULL DEFAULT 'TEMPESTADE' AFTER max_votos");
garantirColuna($pdo, 'coleta_item', 'pergunta_id',
    'ALTER TABLE coleta_item ADD COLUMN pergunta_id INT NULL AFTER rodada_id');
garantirColuna($pdo, 'coleta_item', 'tipo_resposta',
    "ALTER TABLE coleta_item ADD COLUMN tipo_resposta ENUM('ESCOLHA','RENUNCIA') NULL
     AFTER pergunta_id");

// Unificação de respostas do quiz: o condutor arrasta uma ficha sobre a outra e
// as duas passam a ser um cartão só. O vínculo em si é o `agrupado_em_id` que a
// tempestade já usava; estas três colunas são a RASTREABILIDADE que a operação
// exige — quem uniu, quando, e de que grupo a ficha veio (é o que permite
// DESFAZER exatamente, devolvendo cada uma ao líder de antes).
garantirColuna($pdo, 'coleta_item', 'unido_de_id',
    'ALTER TABLE coleta_item ADD COLUMN unido_de_id INT NULL AFTER agrupado_em_id');
garantirColuna($pdo, 'coleta_item', 'unido_por',
    'ALTER TABLE coleta_item ADD COLUMN unido_por INT NULL AFTER unido_de_id');
garantirColuna($pdo, 'coleta_item', 'unido_em',
    'ALTER TABLE coleta_item ADD COLUMN unido_em DATETIME NULL AFTER unido_por');
$tipoDestinoCi2 = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'destino_tipo'"
)->fetchColumn();
if ($tipoDestinoCi2 && !str_contains((string)$tipoDestinoCi2, 'CASCATA')) {
    $pdo->exec("ALTER TABLE coleta_item MODIFY COLUMN destino_tipo
                ENUM('CENARIO','FATOR','ACAO','CASCATA') NULL");
    echo "migrate: coleta_item.destino_tipo agora aceita CASCATA (célula da cascata).\n";
}

// ---- A sala propõe CRUZAMENTOS (TOWS) ----
// A resposta deste alvo não é só texto: a pessoa escolhe dois fatores da SWOT e
// escreve a estratégia do encontro deles. Daí o par na própria linha da voz.
garantirColuna($pdo, 'coleta_item', 'fator_interno_id',
    'ALTER TABLE coleta_item ADD COLUMN fator_interno_id INT NULL AFTER tipo_resposta');
garantirColuna($pdo, 'coleta_item', 'fator_externo_id',
    'ALTER TABLE coleta_item ADD COLUMN fator_externo_id INT NULL AFTER fator_interno_id');
// Limpa antes de amarrar, pelo mesmo motivo das FKs do encaminhamento: base
// antiga pode ter id de fator já apagado, e a FK morreria no ALTER.
$pdo->exec('UPDATE coleta_item ci SET fator_interno_id = NULL
            WHERE fator_interno_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM fator) f WHERE f.id = ci.fator_interno_id)');
$pdo->exec('UPDATE coleta_item ci SET fator_externo_id = NULL
            WHERE fator_externo_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM fator) f WHERE f.id = ci.fator_externo_id)');
garantirFk($pdo, 'coleta_item', 'fk_ci_fator_interno',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_fator_interno
     FOREIGN KEY (fator_interno_id) REFERENCES fator(id) ON DELETE SET NULL');
garantirFk($pdo, 'coleta_item', 'fk_ci_fator_externo',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_fator_externo
     FOREIGN KEY (fator_externo_id) REFERENCES fator(id) ON DELETE SET NULL');
$tipoDestinoCi3 = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'destino_tipo'"
)->fetchColumn();
if ($tipoDestinoCi3 && !str_contains((string)$tipoDestinoCi3, 'CRUZAMENTO')) {
    $pdo->exec("ALTER TABLE coleta_item MODIFY COLUMN destino_tipo
                ENUM('CENARIO','FATOR','ACAO','CASCATA','CRUZAMENTO') NULL");
    echo "migrate: coleta_item.destino_tipo agora aceita CRUZAMENTO (TOWS).\n";
}

// ---- A sala é do PROJETO: a pergunta ganha um alvo polimórfico ----
// O roteiro deixa de saber só de células da cascata e passa a apontar para
// qualquer análise. As colunas do alvo entram todas nulas: linha antiga é
// CASCATA (o DEFAULT), e horizonte/driver deixam de ser obrigatórios porque
// pergunta de cenário e de PESTEL não tem célula nenhuma.
garantirColuna($pdo, 'quiz_pergunta', 'alvo_tipo',
    "ALTER TABLE quiz_pergunta ADD COLUMN alvo_tipo ENUM('CASCATA','CENARIO','FATOR','LIVRE')
     NOT NULL DEFAULT 'CASCATA' AFTER rodada_id");
garantirColuna($pdo, 'quiz_pergunta', 'enunciado',
    'ALTER TABLE quiz_pergunta ADD COLUMN enunciado VARCHAR(255) NULL AFTER alvo_tipo');
garantirColuna($pdo, 'quiz_pergunta', 'ano',
    'ALTER TABLE quiz_pergunta ADD COLUMN ano SMALLINT NULL AFTER eixo_id');
garantirColuna($pdo, 'quiz_pergunta', 'etapa',
    "ALTER TABLE quiz_pergunta ADD COLUMN etapa ENUM('PESTEL','PORTER','SWOT') NULL AFTER ano");
garantirColuna($pdo, 'quiz_pergunta', 'categoria',
    'ALTER TABLE quiz_pergunta ADD COLUMN categoria VARCHAR(40) NULL AFTER etapa');
// O alvo CRUZAMENTO (TOWS) entrou depois. A coluna `categoria` guarda o BLOCO
// (ATACAR, DEFENDER, REFORCAR, PROTEGER) — não precisa de coluna nova, e a
// `alvo_chave` já separa os usos porque carrega o `alvo_tipo`.
$tipoAlvoQp = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_pergunta' AND COLUMN_NAME = 'alvo_tipo'"
)->fetchColumn();
if ($tipoAlvoQp && !str_contains((string)$tipoAlvoQp, 'CRUZAMENTO')) {
    $pdo->exec("ALTER TABLE quiz_pergunta MODIFY COLUMN alvo_tipo
                ENUM('CASCATA','CENARIO','FATOR','CRUZAMENTO','LIVRE') NOT NULL DEFAULT 'CASCATA'");
    echo "migrate: quiz_pergunta.alvo_tipo agora aceita CRUZAMENTO (TOWS).\n";
}

// Reentrada do participante (padrão trazido do Quiz Copérdia): o aparelho volta
// como a MESMA pessoa, e o nome só devolve a identidade de quem está calado.
garantirColuna($pdo, 'coleta_participante', 'dispositivo',
    'ALTER TABLE coleta_participante ADD COLUMN dispositivo VARCHAR(80) NULL AFTER nome');
garantirColuna($pdo, 'coleta_participante', 'visto_em',
    'ALTER TABLE coleta_participante ADD COLUMN visto_em DATETIME NULL AFTER dispositivo');
garantirIndice($pdo, 'coleta_participante', 'idx_part_disp',
    'ALTER TABLE coleta_participante ADD INDEX idx_part_disp (rodada_id, dispositivo)');
foreach (['horizonte_id', 'driver_id'] as $colunaCelula) {
    $obrigatoria = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_pergunta'
           AND COLUMN_NAME = '{$colunaCelula}'"
    )->fetchColumn();
    if ($obrigatoria === 'NO') {
        $pdo->exec("ALTER TABLE quiz_pergunta MODIFY COLUMN {$colunaCelula} INT NULL");
        echo "migrate: quiz_pergunta.{$colunaCelula} agora é opcional (alvo fora da cascata).\n";
    }
}
// A unicidade passa a ser do ALVO INTEIRO: colunas nulas por tipo não formam
// UNIQUE (NULL nunca colide com NULL), então a chave é uma coluna gerada que
// junta todas elas. A antiga (uk_pergunta_celula, sobre eixo_chave) sai junto
// com a coluna que só ela usava.
garantirColuna($pdo, 'quiz_pergunta', 'alvo_chave',
    "ALTER TABLE quiz_pergunta ADD COLUMN alvo_chave VARCHAR(160)
     AS (CONCAT_WS('|', alvo_tipo, COALESCE(horizonte_id, 0), COALESCE(driver_id, 0),
         COALESCE(eixo_id, 0), COALESCE(ano, 0), COALESCE(etapa, ''), COALESCE(categoria, ''),
         IF(alvo_tipo = 'LIVRE', MD5(COALESCE(enunciado, '')), ''))) STORED");
garantirIndice($pdo, 'quiz_pergunta', 'uk_pergunta_alvo',
    'ALTER TABLE quiz_pergunta ADD UNIQUE KEY uk_pergunta_alvo (rodada_id, alvo_chave)');
garantirIndice($pdo, 'quiz_pergunta', 'idx_qp_ativa',
    'ALTER TABLE quiz_pergunta ADD KEY idx_qp_ativa (rodada_id, situacao)');
$temChaveVelha = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_pergunta'
       AND INDEX_NAME = 'uk_pergunta_celula'"
)->fetchColumn();
if ((int)$temChaveVelha > 0) {
    // O índice sai ANTES da coluna gerada: eixo_chave só existe para ele.
    $pdo->exec('ALTER TABLE quiz_pergunta DROP INDEX uk_pergunta_celula');
    echo "migrate: uk_pergunta_celula substituída por uk_pergunta_alvo.\n";
}
// idx_cp_ativa e idx_qp_ativa são o MESMO índice com nomes de épocas
// diferentes: sem soltar o velho, a instalação existente carrega os dois e
// paga escrita dobrada — e diverge da nova, que só tem um.
$temIndiceVelho = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_pergunta'
       AND INDEX_NAME = 'idx_cp_ativa'"
)->fetchColumn();
if ((int)$temIndiceVelho > 0) {
    $pdo->exec('ALTER TABLE quiz_pergunta DROP INDEX idx_cp_ativa');
    echo "migrate: idx_cp_ativa removido (idx_qp_ativa é o mesmo índice).\n";
}
$temEixoChave = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_pergunta'
       AND COLUMN_NAME = 'eixo_chave'"
)->fetchColumn();
if ((int)$temEixoChave > 0) {
    $pdo->exec('ALTER TABLE quiz_pergunta DROP COLUMN eixo_chave');
    echo "migrate: quiz_pergunta.eixo_chave removida (a chave agora é do alvo inteiro).\n";
}

// A MARCA de isolamento entre os ritos passa a ser explícita. Ela era
// `tipo_resposta IS NULL`, o que só funcionava enquanto TODA resposta de quiz
// tinha lado: alvo sem lado (PESTEL, Porter, SWOT) responde com tipo_resposta
// nulo e a sugestão vazaria para a fila de triagem da Coleta.
garantirColuna($pdo, 'coleta_item', 'origem',
    "ALTER TABLE coleta_item ADD COLUMN origem ENUM('TEMPESTADE','QUIZ')
     NOT NULL DEFAULT 'TEMPESTADE' AFTER rodada_id");
// Backfill idempotente: item com lado declarado é, por definição, do quiz.
$pdo->exec("UPDATE coleta_item SET origem = 'QUIZ'
            WHERE tipo_resposta IS NOT NULL AND origem = 'TEMPESTADE'");
// O lado ganha os valores do cenário (o alvo CENARIO tem dois, como a cascata)
$tipoResposta = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_item' AND COLUMN_NAME = 'tipo_resposta'"
)->fetchColumn();
// O passo do ENUM ficou pelo histórico, mas só roda sobre um ENUM: no esquema
// novo a coluna já nasce VARCHAR (abaixo), e `str_contains` sozinho a mandaria
// DE VOLTA para o ENUM a cada deploy.
if ($tipoResposta && stripos((string)$tipoResposta, 'enum') === 0
    && !str_contains((string)$tipoResposta, 'SITUACAO_ATUAL')) {
    $pdo->exec("ALTER TABLE coleta_item MODIFY COLUMN tipo_resposta
                ENUM('ESCOLHA','RENUNCIA','SITUACAO_ATUAL','TENDENCIA') NULL");
    echo "migrate: coleta_item.tipo_resposta agora aceita os lados do cenário.\n";
    $tipoResposta = 'enum(atualizado)';
}
// O lado deixa de ser um ENUM: com o 🎤 da ETAPA INTEIRA (2026-09-03) o
// celular escolhe a CATEGORIA do PESTEL/Porter/SWOT, e ela viaja na mesma
// coluna — a lista branca é derivada da pergunta (`Quiz::ladosDe`), como o
// CLAUDE.md sempre prometeu, e um ENUM que precisasse crescer a cada análise
// nova era justamente o "ENUM fixo" que a regra proíbe. VARCHAR(40) é a
// largura de `quiz_pergunta.categoria`, de onde os valores vêm.
if ($tipoResposta && stripos((string)$tipoResposta, 'enum') === 0) {
    $pdo->exec('ALTER TABLE coleta_item MODIFY COLUMN tipo_resposta VARCHAR(40) NULL');
    echo "migrate: coleta_item.tipo_resposta vira VARCHAR(40) (lado ou categoria, derivados da pergunta).\n";
}

// O modo CASCATA vira QUIZ: a sessão não é mais de uma análise só. Três passos
// porque um ENUM não troca de valor em uso — o novo entra, as linhas migram, o
// velho sai.
$modoRodada = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coleta_rodada' AND COLUMN_NAME = 'modo'"
)->fetchColumn();
if ($modoRodada && str_contains((string)$modoRodada, "'CASCATA'")) {
    if (!str_contains((string)$modoRodada, "'QUIZ'")) {
        $pdo->exec("ALTER TABLE coleta_rodada MODIFY COLUMN modo
                    ENUM('TEMPESTADE','CASCATA','QUIZ') NOT NULL DEFAULT 'TEMPESTADE'");
    }
    $pdo->exec("UPDATE coleta_rodada SET modo = 'QUIZ' WHERE modo = 'CASCATA'");
    $pdo->exec("ALTER TABLE coleta_rodada MODIFY COLUMN modo
                ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE'");
    echo "migrate: coleta_rodada.modo CASCATA virou QUIZ (a sala é do projeto).\n";
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

// Esforço para tratar a ameaça, ao lado das notas G/U/T. Nulo nas avaliações
// anteriores à coluna: a matriz não inventa um esforço que ninguém estimou —
// o cartão mostra "—" e continua ordenado pelo score.
garantirColuna($pdo, 'gut', 'esforco',
    "ALTER TABLE gut ADD COLUMN esforco ENUM('PEQUENO','MEDIO','GRANDE') NULL AFTER score");
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
// Repetição mensal em vários dias do mês (CSV, ex.: "5,20"). `recorrencia_dia`
// segue gravado com o primeiro dia: é o fallback das ações criadas antes daqui.
// Fica AQUI, e não lá em cima, porque o `AFTER recorrencia_dia` exige a coluna
// que as duas linhas acima acabam de garantir — numa base anterior à
// recorrência, o ALTER morria com "Unknown column" e derrubava o deploy.
garantirColuna($pdo, 'desdobramento', 'recorrencia_dias',
    'ALTER TABLE desdobramento ADD COLUMN recorrencia_dias VARCHAR(100) NULL AFTER recorrencia_dia');
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

// ---- Cargas de conteúdo: cenário macroeconômico e PESTEL ----
// O seeds.sql só age com o contexto vazio e essas telas já têm itens escritos
// à mão, então a carga nunca chegaria por lá. Aqui ela chega no deploy — UMA
// vez por chave, e a marca em `carga_conteudo` é o que torna isso seguro:
// cenário e fatores são conteúdo EDITÁVEL, e sem a marca todo deploy recriaria
// o que alguém apagou e reporia a redação que alguém ajustou.
//
// A lógica mora em App\Services\CargaConteudo, a mesma que a CLI usa. O
// arquivo só declara a classe: incluí-lo não executa nada nem exige o autoload
// da aplicação — igual ao que já é feito com QlikSync.
require_once __DIR__ . '/../app/Services/CargaConteudo.php';
foreach ([
    'conteudo_cenario_macro.php',
    'conteudo_pestel_macro.php',
    'conteudo_porter_macro.php',
    'conteudo_swot_macro.php',
    'conteudo_cascata_h1.php',
] as $arquivo) {
    $conteudo = require __DIR__ . '/' . $arquivo;
    $chaveCarga = $conteudo['chave'];
    if (App\Services\CargaConteudo::jaAplicada($pdo, $chaveCarga)) {
        continue;
    }
    $planos = App\Services\CargaConteudo::planosCorporativos($pdo, $conteudo['ano'] ?? null);
    $gravados = 0;
    try {
        foreach ($planos as $planoId) {
            $gravados += App\Services\CargaConteudo::aplicar($pdo, $conteudo, $planoId);
        }
    } catch (\RuntimeException $e) {
        // A cascata casa driver, eixo e horizonte pelo NOME do cadastro, e os
        // três são editáveis na tela. Uma renomeação em produção não pode
        // derrubar o start do container nem enterrar a carga em silêncio: o
        // deploy segue, a carga NÃO é marcada, e a tentativa se repete no
        // próximo deploy — quando o nome voltar, ela entra sozinha.
        fwrite(STDERR, "migrate: carga {$chaveCarga} adiada — {$e->getMessage()}\n");
        continue;
    }
    // A marca é gravada mesmo sem plano corporativo elegível: a carga é uma
    // fotografia datada, e reavaliá-la a cada deploy futuro faria o texto de
    // agosto/2026 aparecer meses depois, num ciclo criado muito mais tarde.
    App\Services\CargaConteudo::marcar(
        $pdo, $chaveCarga, "{$gravados} registro(s) em " . count($planos) . ' planejamento(s)'
    );
    echo "migrate: carga {$chaveCarga} — {$gravados} registro(s) em "
        . count($planos) . " planejamento(s) corporativo(s).\n";
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
// SET NULL, não CASCADE: sumindo a pergunta, a sugestão sobrevive como voz
// registrada — e é por isso que o isolamento das telas da tempestade usa
// `origem`, que nunca é solta, e não pergunta_id
$pdo->exec('UPDATE coleta_item SET pergunta_id = NULL WHERE pergunta_id IS NOT NULL
            AND pergunta_id NOT IN (SELECT id FROM (SELECT id FROM quiz_pergunta) q)');
garantirFk($pdo, 'coleta_item', 'fk_ci_pergunta',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_pergunta
     FOREIGN KEY (pergunta_id) REFERENCES quiz_pergunta(id) ON DELETE SET NULL');

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

// ---- Diário de bordo → comentários ----
// O diário virou "Comentários", que é a mesma coisa com anexo junto. Os
// registros antigos precisam atravessar: eles são o histórico de acompanhamento
// dos projetos, e o Relatório de Status lê deles a seção do período.
//
// A marca em `carga_conteudo` é o que impede o deploy seguinte de duplicar tudo
// (um `NOT EXISTS` por linha não serve: o mesmo autor pode comentar duas vezes
// o mesmo texto no mesmo dia, e a segunda vez é um comentário de verdade).
// A tabela `diario_bordo` NÃO é apagada: ela fica como arquivo da migração,
// sem código nenhum lendo dela. Descartá-la aqui tornaria o passo irreversível
// no primeiro deploy, e o custo de mantê-la é uma tabela parada.
$temDiario = (bool)$pdo->query(
    "SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diario_bordo'"
)->fetchColumn();
if ($temDiario && !App\Services\CargaConteudo::jaAplicada($pdo, 'migracao_diario_comentario')) {
    // `data_reg` é a data que o autor escolheu no diário; `criado_em` do
    // comentário herda ela (ao meio-dia, para não escorregar de dia em
    // conversão de fuso), senão a linha do tempo nova nasceria toda com a data
    // do deploy e a ordem do histórico se perderia.
    // Status e progresso viravam texto: eram a informação do registro, e sem
    // eles o comentário migrado diria menos do que dizia o diário.
    $migrados = $pdo->exec(
        "INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto, criado_em)
         SELECT db.ref_tipo, db.ref_id, db.autor_id,
                CONCAT(db.texto,
                  CASE WHEN db.status_atual IS NULL THEN ''
                       ELSE CONCAT('\n\nStatus registrado: ', db.status_atual) END,
                  CASE WHEN db.progresso IS NULL THEN ''
                       ELSE CONCAT('\nProgresso registrado: ', db.progresso, '%') END),
                TIMESTAMP(db.data_reg, '12:00:00')
           FROM diario_bordo db"
    );
    App\Services\CargaConteudo::marcar(
        $pdo,
        'migracao_diario_comentario',
        "{$migrados} registro(s) do diário de bordo migrados para comentários"
    );
    echo "migrate: {$migrados} registro(s) do diário migrados para comentários.\n";
}

// ---------------------------------------------------------------------------
// Excluir usuário: as colunas que apontam para uma PESSOA passam a aceitar nulo
// ---------------------------------------------------------------------------
// Excluir alguém do cadastro esbarrava em treze colunas apontando para
// `usuario.id`. O que a pessoa segura tem duas naturezas, e as duas precisam de
// saída:
//
// - **carteira** (`desdobramento.quem_usuario_id`, `fator.acao_por`,
//   `swot_cruzamento.acao_por`, `negocio.gestor_id`) — trabalho que alguém tem
//   de assumir; é daqui que saem as cobranças por e-mail;
// - **autoria** (`comentario`, `reuniao`, `coleta_item`, `coleta_rodada`,
//   `swot_cruzamento.criado_por`) — quem escreveu o quê.
//
// Na exclusão, as duas vão para a pessoa INDICADA — ou ficam pendentes, sem
// responsável, que é a outra saída oferecida na tela. A pendência é o motivo
// desta migração: cinco dessas colunas eram NOT NULL, e sem nulo a única
// alternativa a transferir seria apagar o registro junto com a pessoa —
// perder a ata da reunião porque quem a escreveu saiu da empresa.
//
// Anular NÃO é apagar: o texto, a data e os anexos continuam lá. O que some é o
// vínculo com a pessoa, e a tela passa a mostrar «Sem usuário».
function tornarAnulavel(PDO $pdo, string $tabela, string $coluna, string $tipo, string $porque): void
{
    $nulo = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $nulo->execute([$tabela, $coluna]);
    if ($nulo->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE {$tabela} MODIFY COLUMN {$coluna} {$tipo} NULL");
        echo "migrate: {$tabela}.{$coluna} passou a aceitar nulo ({$porque}).\n";
    }
}
tornarAnulavel($pdo, 'comentario', 'autor_id', 'INT', 'o comentário sobrevive a quem o escreveu');
tornarAnulavel($pdo, 'reuniao', 'autor_id', 'INT', 'a ata sobrevive a quem a registrou');
tornarAnulavel($pdo, 'coleta_rodada', 'criado_por', 'INT', 'a rodada sobrevive a quem a abriu');
tornarAnulavel($pdo, 'swot_cruzamento', 'criado_por', 'INT', 'o cruzamento sobrevive a quem o redigiu');
tornarAnulavel($pdo, 'diario_bordo', 'autor_id', 'INT', 'arquivo da migração, mas a FK ainda prende');

// As duas colunas de pessoa que nunca tiveram chave estrangeira. Sem ela, o
// DELETE do usuário passava e deixava as duas apontando para um id que não
// existe mais — em silêncio, que é o pior jeito de perder um dado: a ação
// continuava listada, com responsável nenhum e sem nada na tela dizendo isso.
// RESTRICT de propósito, não SET NULL: quem decide o destino da carteira é o
// UsuarioController, e a chave aqui é a rede de segurança que faz o DELETE
// FALHAR se algum dia ele esquecer uma delas. Nulo silencioso a rede não pega.
$pdo->exec('UPDATE desdobramento SET quem_usuario_id = NULL WHERE quem_usuario_id IS NOT NULL
            AND quem_usuario_id NOT IN (SELECT id FROM (SELECT id FROM usuario) u)');
garantirFk($pdo, 'desdobramento', 'fk_desd_quem',
    'ALTER TABLE desdobramento ADD CONSTRAINT fk_desd_quem
     FOREIGN KEY (quem_usuario_id) REFERENCES usuario(id)');
$pdo->exec('UPDATE coleta_item SET unido_por = NULL WHERE unido_por IS NOT NULL
            AND unido_por NOT IN (SELECT id FROM (SELECT id FROM usuario) u)');
garantirFk($pdo, 'coleta_item', 'fk_ci_unido_por',
    'ALTER TABLE coleta_item ADD CONSTRAINT fk_ci_unido_por
     FOREIGN KEY (unido_por) REFERENCES usuario(id)');

// O progresso das ações anda de 5 em 5 (barra, modal e servidor). Valores
// legados fora da grade seriam "encaixados" pelo range do navegador e cada
// salvamento gravaria outro número — normaliza uma vez; o WHERE torna o passo
// inócuo nos deploys seguintes.
$fora = $pdo->exec('UPDATE desdobramento SET progresso = 5 * ROUND(progresso / 5) WHERE progresso % 5 <> 0');
if ($fora) {
    echo "migrate: {$fora} progresso(s) de ação arredondado(s) para múltiplo de 5.\n";
}

// Faxina das tabelas que só crescem. O coletor de sessão do PHP roda por
// probabilidade e há ambiente com session.gc_probability = 0, onde ele nunca é
// chamado — e cada visita anônima a /login já cria uma linha em `sessao`, porque
// o formulário precisa do token CSRF. Aqui a limpeza é determinística: acontece
// em todo deploy (e de novo no cron diário, em cli/notificar.php).
$sessoes = $pdo->exec("DELETE FROM sessao WHERE atualizado_em < (NOW() - INTERVAL 30 DAY)");
// Cadeados de edição vencidos: a tabela só cresce se ninguém varrer, e um
// cadeado vencido nunca mais é lido — todas as consultas filtram por validade.
$pdo->exec("DELETE FROM edicao_bloqueio WHERE expira_em < (NOW() - INTERVAL 1 DAY)");
$tentativas = $pdo->exec("DELETE FROM coleta_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)")
    + $pdo->exec("DELETE FROM login_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY)");
if ($sessoes || $tentativas) {
    echo "migrate: faxina — {$sessoes} sessão(ões) e {$tentativas} tentativa(s) expiradas removidas.\n";
}

$pdo->query("SELECT RELEASE_LOCK('migrate_controladoria')")->fetchColumn();

echo "migrate: ok.\n";

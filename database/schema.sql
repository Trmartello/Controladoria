-- Sistema de Planejamento Estratégico Copérdia — schema MySQL 8

-- Sessões no banco: o login sobrevive a deploys (container efêmero no Railway)
CREATE TABLE IF NOT EXISTS sessao (
  id            VARCHAR(128) PRIMARY KEY,
  dados         MEDIUMBLOB,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sessao_atualizado (atualizado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  email         VARCHAR(120) NOT NULL UNIQUE,
  senha_hash    VARCHAR(255) NOT NULL,
  perfil        ENUM('ADMIN','CONTROLADORIA','DIRECAO','GESTOR','LEITURA') NOT NULL DEFAULT 'LEITURA',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS negocio (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  cod_negocio     VARCHAR(10) NOT NULL UNIQUE,
  nome            VARCHAR(120) NOT NULL,
  gestor_id       INT NULL,
  origem          ENUM('QLIK','MANUAL') NOT NULL DEFAULT 'MANUAL',
  sincronizado_em DATETIME NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_negocio_gestor FOREIGN KEY (gestor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_negocio (
  usuario_id INT NOT NULL,
  negocio_id INT NOT NULL,
  PRIMARY KEY (usuario_id, negocio_id),
  CONSTRAINT fk_un_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  CONSTRAINT fk_un_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ciclo (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(60) NOT NULL,
  ano_base    SMALLINT NOT NULL,
  ano_inicio  SMALLINT NOT NULL,
  ano_fim     SMALLINT NOT NULL,
  -- O ano que o plano está EXECUTANDO — é ele que a tela de Metas abre mês a
  -- mês. Fica no CICLO porque é o mesmo para todos os negócios e para o
  -- relatório; em branco, vale o ano corrente (`App\Services\Metas`).
  ano_execucao SMALLINT NULL,
  status      ENUM('EM_ELABORACAO','VIGENTE','ENCERRADO') NOT NULL DEFAULT 'EM_ELABORACAO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS horizonte (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ciclo_id    INT NOT NULL,
  nome        VARCHAR(30) NOT NULL,
  ano_inicio  SMALLINT NOT NULL,
  ano_fim     SMALLINT NOT NULL,
  tema        VARCHAR(120) NOT NULL,
  objetivo    TEXT NOT NULL,
  ordem       TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_horizonte_ciclo FOREIGN KEY (ciclo_id) REFERENCES ciclo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(60) NOT NULL,
  ordem  TINYINT NOT NULL DEFAULT 0,
  ativo  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eixo (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(60) NOT NULL,
  ordem  TINYINT NOT NULL DEFAULT 0,
  ativo  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planejamento (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ciclo_id    INT NOT NULL,
  escopo      ENUM('NEGOCIO','CORPORATIVO') NOT NULL DEFAULT 'NEGOCIO',
  negocio_id  INT NULL,
  -- coluna gerada para unicidade com NULL (corporativo) — compatível MySQL 8 e MariaDB
  negocio_chave INT AS (COALESCE(negocio_id, 0)) STORED,
  UNIQUE KEY uk_ciclo_neg (ciclo_id, escopo, negocio_chave),
  CONSTRAINT fk_plan_ciclo FOREIGN KEY (ciclo_id) REFERENCES ciclo(id),
  CONSTRAINT fk_plan_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cenario_item (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  -- análise anual; horizontes seguem plurianuais
  ano              SMALLINT NULL,
  tipo             ENUM('SITUACAO_ATUAL','TENDENCIA') NOT NULL,
  ordem            SMALLINT NOT NULL DEFAULT 0,
  descricao        TEXT NOT NULL,
  -- Encaminhamento ao plano de ação, na MESMA regra do fator e do cruzamento:
  -- `acao_em` marca o envio e `desdobramento_id` guarda a ação que nasceu dele;
  -- os dois juntos definem "aguardando alocação". Três tabelas com os mesmos
  -- três campos é repetição de propósito — o que elas compartilham é a REGRA,
  -- não a linha, e uma tabela de encaminhamentos polimórfica trocaria três
  -- colunas por uma junção a mais em toda leitura das três telas.
  acao_em          DATETIME NULL,
  acao_por         INT NULL,
  desdobramento_id INT NULL,
  CONSTRAINT fk_cenario_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  INDEX idx_cenario_plan_ano (planejamento_id, ano, tipo)
  -- As FKs de `acao_por` e `desdobramento_id` moram no migrate (`garantirFk`),
  -- como as do fator: aqui a segunda quebraria a instalação NOVA, porque
  -- `desdobramento` só é criada mais abaixo neste mesmo arquivo.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fator (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  -- análise anual; horizontes seguem plurianuais
  ano              SMALLINT NULL,
  etapa            ENUM('PESTEL','PORTER','SWOT') NOT NULL,
  categoria        VARCHAR(40) NOT NULL,
  -- Posição dentro da categoria (arraste na tela). Zero = coluna nunca
  -- ordenada: a leitura é `ORDER BY categoria, ordem, id`, e com todo mundo em
  -- zero a ordem é a de criação, que é o que sempre foi.
  ordem            SMALLINT NOT NULL DEFAULT 0,
  descricao        TEXT NOT NULL,
  promovido_de_id  INT NULL,
  -- Encaminhamento do fator da SWOT para o plano de ação, na mesma regra da
  -- ideia da Coleta: `acao_em` marca o envio (o destino_tipo='ACAO' de lá) e
  -- `desdobramento_id` guarda a ação que nasceu dele (o destino_id). Os dois
  -- juntos definem "aguardando alocação": marcado e ainda sem ação.
  acao_em          DATETIME NULL,
  acao_por         INT NULL,
  desdobramento_id INT NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fator_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_fator_origem FOREIGN KEY (promovido_de_id) REFERENCES fator(id),
  -- Toda tela de análise filtra por (planejamento, ano, etapa); só a FK não basta
  INDEX idx_fator_plan_ano (planejamento_id, ano, etapa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gut (
  fator_id   INT PRIMARY KEY,
  gravidade  TINYINT NOT NULL CHECK (gravidade  BETWEEN 1 AND 5),
  urgencia   TINYINT NOT NULL CHECK (urgencia   BETWEEN 1 AND 5),
  tendencia  TINYINT NOT NULL CHECK (tendencia  BETWEEN 1 AND 5),
  score      SMALLINT GENERATED ALWAYS AS (gravidade * urgencia * tendencia) STORED,
  -- Esforço para tratar a ameaça. Fica NULO nas avaliações feitas antes de a
  -- coluna existir: prioridade continua sendo o score, e o esforço só diz por
  -- onde começar entre fatores de prioridade parecida.
  esforco    ENUM('PEQUENO','MEDIO','GRANDE') NULL,
  CONSTRAINT fk_gut_fator FOREIGN KEY (fator_id) REFERENCES fator(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cruzamentos da SWOT (TOWS): o par de um fator INTERNO com um EXTERNO e a
-- estratégia que nasce dele. É o elo que faltava entre descrever o ambiente e
-- decidir o que fazer — "uma boa SWOT não descreve a empresa, descreve o que
-- ela precisa decidir".
--
-- Três coisas que o modelo carrega de propósito:
--   • `tipo` é DERIVADO do par (força+oportunidade só pode ser ATACAR) e
--     calculado no servidor. Deixá-lo escolher abriria a porta para a linha
--     gravada no bloco errado — o mesmo defeito que a etapa/ano do fator já
--     custou.
--   • O par é ÚNICO por ano. Sem a chave, o mesmo cruzamento entraria duas
--     vezes com redações diferentes e o bloco viraria discussão em vez de
--     decisão.
--   • `ano`, como toda análise do diagnóstico: os horizontes são do ciclo, mas
--     a SWOT é anual e o cruzamento é leitura da SWOT daquele ano.
-- As FKs dos dois fatores são ON DELETE CASCADE: apagado o fator, o cruzamento
-- que o cita perde o sentido — não existe cruzamento de um lado só.
CREATE TABLE IF NOT EXISTS swot_cruzamento (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  ano              SMALLINT NOT NULL,
  fator_interno_id INT NOT NULL,
  fator_externo_id INT NOT NULL,
  tipo             ENUM('ATACAR','DEFENDER','REFORCAR','PROTEGER') NOT NULL,
  rotulo           VARCHAR(120) NOT NULL,
  estrategia       TEXT NOT NULL,
  -- Anulável de propósito: excluir um usuário não pode levar junto o que ele
  -- escreveu. Na exclusão o registro vai para a pessoa indicada, ou fica sem
  -- vínculo e a tela mostra «Sem usuário» (ver UsuarioController::excluir).
  criado_por       INT NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  acao_em          DATETIME NULL,
  acao_por         INT NULL,
  desdobramento_id INT NULL,
  UNIQUE KEY uk_par (planejamento_id, ano, fator_interno_id, fator_externo_id),
  KEY idx_cruz_plan (planejamento_id, ano, tipo),
  KEY idx_cruz_externo (fator_externo_id),
  CONSTRAINT fk_cruz_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_cruz_interno FOREIGN KEY (fator_interno_id) REFERENCES fator(id) ON DELETE CASCADE,
  CONSTRAINT fk_cruz_externo FOREIGN KEY (fator_externo_id) REFERENCES fator(id) ON DELETE CASCADE,
  CONSTRAINT fk_cruz_autor FOREIGN KEY (criado_por) REFERENCES usuario(id),
  CONSTRAINT fk_cruz_acao_por FOREIGN KEY (acao_por) REFERENCES usuario(id)
  -- A FK de `desdobramento_id` (SET NULL, como no fator: apagada a ação, o
  -- cruzamento volta sozinho para a fila de "aguardando plano de ação") mora no
  -- migrate, em `garantirFk`. Aqui ela quebrava a instalação NOVA: `desdobramento`
  -- só é criada mais abaixo neste arquivo, e o CREATE TABLE morria com
  -- "Foreign key constraint is incorrectly formed" antes de chegar lá.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cascata_escolha (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  horizonte_id     INT NOT NULL,
  driver_id        INT NOT NULL,
  eixo_id          INT NULL,
  escolha          TEXT NOT NULL,
  renuncia         TEXT,
  eixo_chave       INT AS (COALESCE(eixo_id, 0)) STORED,
  UNIQUE KEY uk_celula (planejamento_id, horizonte_id, driver_id, eixo_chave),
  CONSTRAINT fk_casc_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_casc_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id),
  CONSTRAINT fk_casc_driver FOREIGN KEY (driver_id) REFERENCES driver(id),
  CONSTRAINT fk_casc_eixo FOREIGN KEY (eixo_id) REFERENCES eixo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cascata_fator (
  cascata_id INT NOT NULL,
  fator_id   INT NOT NULL,
  PRIMARY KEY (cascata_id, fator_id),
  CONSTRAINT fk_cf_cascata FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id) ON DELETE CASCADE,
  CONSTRAINT fk_cf_fator FOREIGN KEY (fator_id) REFERENCES fator(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicador (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  nome             VARCHAR(120) NOT NULL,
  unidade          VARCHAR(20)  NOT NULL DEFAULT 'R$ mil',
  sentido          ENUM('MAIOR_MELHOR','MENOR_MELHOR') NOT NULL DEFAULT 'MAIOR_MELHOR',
  -- Como 12 meses viram um ano. R$ e tonelada SOMAM; margem e índice tiram
  -- MÉDIA; estoque, dívida e cobertura valem pelo ÚLTIMO mês com valor (são
  -- saldos). Somar tudo seria o mais simples e o mais perigoso: o erro não
  -- aparece na tela, aparece na decisão.
  agregacao        ENUM('SOMA','MEDIA','ULTIMO') NOT NULL DEFAULT 'SOMA',
  metrica_ancora   TINYINT(1) NOT NULL DEFAULT 0,
  horizonte_id     INT NULL,
  -- Ordem escolhida a dedo na tela de Metas (arraste pelo ⠿). Zero quer dizer
  -- "nunca arrastado", e a leitura é `ORDER BY ordem, metrica_ancora DESC,
  -- nome`: com a coluna toda em zero a tela continua como sempre foi — âncora
  -- em cima, depois alfabético. A primeira arrastada numera o planejamento
  -- inteiro (1..N) e a ordem passa a ser a de quem planeja, âncora inclusive.
  ordem            SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ind_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_ind_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicador_valor (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  indicador_id  INT NOT NULL,
  ano           SMALLINT NOT NULL,
  -- 0 = o ANO INTEIRO; 1–12 = o mês. Zero e não NULL: com NULL a chave única
  -- abaixo não impediria duplicata nenhuma (o MySQL trata cada NULL como
  -- distinto) e dois valores anuais do mesmo ano passariam a conviver.
  mes           TINYINT NOT NULL DEFAULT 0,
  tipo          ENUM('META','REAL') NOT NULL,
  versao_meta   TINYINT NOT NULL DEFAULT 1,
  -- Três casas: indicador em % lança até três após a vírgula (2026-11-19).
  valor         DECIMAL(18,3) NOT NULL,
  UNIQUE KEY uk_ind_ano_mes (indicador_id, ano, mes, tipo, versao_meta),
  CONSTRAINT fk_iv_indicador FOREIGN KEY (indicador_id) REFERENCES indicador(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que escolha da cascata este indicador MEDE. É o vínculo que faltava entre a
-- decisão e a medida; o outro lado do vão — decisão × execução — já é
-- `projeto.cascata_id`. Com os dois, a Matriz de Execução se lê da cascata sem
-- entidade nova: escolha, o que a mede, e o que a executa.
-- Clone literal de `cascata_fator`: N:N, chave composta e `ON DELETE CASCADE`
-- nos dois lados, porque o vínculo não sobrevive a nenhuma das pontas. O índice
-- avulso em `cascata_id` existe porque a leitura da matriz entra POR ELE (a
-- chave composta só serve a quem começa pelo indicador).
CREATE TABLE IF NOT EXISTS indicador_cascata (
  indicador_id INT NOT NULL,
  cascata_id   INT NOT NULL,
  PRIMARY KEY (indicador_id, cascata_id),
  KEY idx_ic_cascata (cascata_id),
  CONSTRAINT fk_ic_ind FOREIGN KEY (indicador_id) REFERENCES indicador(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_cas FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Matriz de Impacto por Negócio: o que o diagnóstico CORPORATIVO faz com cada
 * negócio. Linha = ameaça ou oportunidade da SWOT corporativa do ano; coluna =
 * negócio; célula = o sinal e o como.
 *
 * **Sem `planejamento_id` e sem `ano`**, de propósito: os dois vêm do `fator`
 * apontado, e guardá-los aqui criaria uma segunda verdade — a célula podia
 * dizer 2027 com o fator dela em 2026, e nenhuma tela mostraria a divergência.
 *
 * **Não reusa `fator.promovido_de_id`** para o vínculo. `FatorController::listar`
 * faz `LEFT JOIN fator pr ON pr.promovido_de_id = f.id`: reusar o campo
 * multiplicaria a linha do fator corporativo por negócio impactado, duplicando
 * cards na tela do PESTEL — longe daqui, e sem ninguém ligar as duas coisas.
 *
 * A FK do fator é ON DELETE CASCADE (some o fator, some a linha da matriz); a
 * do negócio NÃO é: negócio não se apaga, se desativa (`NegocioController`
 * recusa com contagem), e um CASCADE ali só esconderia a recusa.
 */
/*
 * Versão do conteúdo de um planejamento — o "pulso" que faz duas telas abertas
 * ao mesmo tempo se acompanharem.
 *
 * Uma linha por planejamento, um inteiro que só cresce. É o MENOR estado
 * possível que responde "mudou alguma coisa desde a última vez que olhei?", e
 * essa pequenez é o requisito: a rota que a lê roda a cada poucos segundos por
 * admin conectado, e uma consulta cara ali viraria carga permanente.
 *
 * Por que um contador e não `MAX(atualizado_em)` das tabelas: a maioria delas
 * não tem carimbo de tempo, e as que têm não registram DELETE — apagar um fator
 * não mexeria em carimbo nenhum, e a outra tela seguiria mostrando o que já não
 * existe. O contador não tem esse buraco porque quem o incrementa é a ESCRITA,
 * qualquer que seja ela.
 *
 * Sem FK para `planejamento`: a linha é cache de sincronização, não dado do
 * plano. Um plano apagado deixa aqui uma linha órfã de 16 bytes que ninguém
 * lê — e uma FK CASCADE só acrescentaria trabalho ao DELETE para economizar
 * isso.
 */
CREATE TABLE IF NOT EXISTS planejamento_versao (
  planejamento_id INT PRIMARY KEY,
  versao          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  em              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Um item por vez: o cadeado de edição.
 *
 * Enquanto alguém tem o formulário de um item aberto, ninguém mais o abre — e a
 * tela dos outros mostra o NOME de quem está editando. É o que evita a
 * sobrescrita silenciosa (medida em 2026-09-01: o segundo a salvar apagava o
 * primeiro, e o servidor respondia `ok:true`).
 *
 * **A chave primária composta é o que torna a tomada atômica.** Um
 * `INSERT … ON DUPLICATE KEY UPDATE` com a condição de expiração dentro do
 * `IF()` pega ou não pega numa instrução só — sem transação, e sem a janela
 * entre "conferir se está livre" e "tomar" por onde dois cliques simultâneos
 * passariam os dois.
 *
 * `planejamento_id` é redundante com o registro apontado (dá para chegar nele
 * por cinco caminhos diferentes, um por recurso) e está aqui de propósito: o
 * pulso precisa listar os cadeados de um ciclo, e sem esta coluna isso seria
 * uma união de cinco JOINs numa rota que roda a cada 4s por admin.
 *
 * Linha efêmera: morre por validade, sem FK para `usuario` nem para o registro
 * apontado. Um cadeado de um item apagado é lixo de 30 bytes que nunca mais é
 * lido — e uma FK por recurso exigiria uma tabela de cadeado por recurso.
 */
CREATE TABLE IF NOT EXISTS edicao_bloqueio (
  recurso         VARCHAR(40) NOT NULL,
  registro_id     INT NOT NULL,
  planejamento_id INT NOT NULL,
  usuario_id      INT NOT NULL,
  expira_em       DATETIME NOT NULL,
  PRIMARY KEY (recurso, registro_id),
  KEY idx_bloqueio_plan (planejamento_id, expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS impacto_negocio (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  fator_id      INT NOT NULL,
  negocio_id    INT NOT NULL,
  sinal         ENUM('POSITIVO','NEGATIVO') NOT NULL,
  texto         TEXT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Uma célula por par: é o que faz o `salvar` ser um upsert e não uma pilha
  -- de opiniões sobre o mesmo cruzamento.
  UNIQUE KEY uk_impacto (fator_id, negocio_id),
  KEY idx_imp_negocio (negocio_id),
  CONSTRAINT fk_imp_fator   FOREIGN KEY (fator_id)   REFERENCES fator(id) ON DELETE CASCADE,
  CONSTRAINT fk_imp_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projeto (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  tipo             ENUM('ESTRATEGICO','OPERACIONAL') NOT NULL,
  ano              SMALLINT NULL,
  titulo           TEXT NOT NULL,
  descricao        TEXT NULL,
  responsavel      VARCHAR(255),
  prazo            VARCHAR(60),
  data_inicio      DATE NULL,
  data_fim         DATE NULL,
  horizonte_id     INT NULL,
  -- `cascata_id` saiu daqui em 2026-10-26: a escolha da cascata passou a ser da
  -- FRENTE (ver `iniciativa`). A coluna fica na tabela, sem uso, porque a
  -- migração copia o valor dela para as frentes e apagá-la jogaria fora o
  -- rastro de onde o vínculo estava antes.
  cascata_id       INT NULL,
  impacto          ENUM('RENTABILIDADE','FATURAMENTO','SUSTENTABILIDADE','PESSOAS') NULL,
  classificacao    ENUM('PRIORITARIO','NORMAL') NOT NULL DEFAULT 'NORMAL',
  status           ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO') NOT NULL DEFAULT 'NAO_INICIADO',
  ordem            SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_proj_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_proj_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id),
  CONSTRAINT fk_proj_cascata FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Frentes de trabalho dentro de um projeto (projeto → iniciativa → ação)
CREATE TABLE IF NOT EXISTS iniciativa (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  projeto_id  INT NOT NULL,
  titulo      TEXT NOT NULL,
  descricao   TEXT,
  -- Que escolha da cascata esta FRENTE executa (decisão do cliente,
  -- 2026-10-26: era do projeto). Um projeto costuma nascer de uma decisão só,
  -- mas quem executa pedaços dela são as frentes — e é por elas que a Matriz
  -- de Execução lê o que cada escolha virou.
  cascata_id  INT NULL,
  status      ENUM('ABERTA','EM_ANDAMENTO','CONCLUIDA') NOT NULL DEFAULT 'ABERTA',
  ordem       SMALLINT NOT NULL DEFAULT 0,
  KEY idx_ini_cascata (cascata_id),
  CONSTRAINT fk_ini_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE,
  CONSTRAINT fk_ini_cascata FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desdobramento (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  projeto_id   INT NOT NULL,
  iniciativa_id INT NULL,
  o_que        TEXT NOT NULL,
  por_que      TEXT,
  quem         VARCHAR(255),
  quando_      VARCHAR(60),
  data_inicio  DATE NULL,
  data_fim     DATE NULL,
  onde         VARCHAR(120),
  como         TEXT,
  quanto       DECIMAL(15,2) NULL,
  quem_usuario_id INT NULL,
  -- DATAS: a ação acontece em períodos AVULSOS, lançados um a um em
  -- `desdobramento_periodo` (um treinamento em abril e outro em agosto, por
  -- exemplo). Não é grade: nada se calcula, e as únicas datas que valem são as
  -- digitadas. Ver a tabela mais abaixo. É o PADRÃO da tela desde 2026-11-10,
  -- e a ação que acontece UMA vez é esta lista com uma linha só.
  --
  -- NENHUMA continua no ENUM, mas a tela não o oferece mais e nada o produz: a
  -- migração converteu em DATAS toda ação que estava nele COM fim previsto, e o
  -- formulário abre como DATAS o que sobrou (ação legada sem fim). O valor fica
  -- como default da coluna e como o que o controller assume quando a API é
  -- chamada sem `recorrencia` — tirá-lo do ENUM quebraria os dois.
  recorrencia     ENUM('NENHUMA','SEMANAL','MENSAL','DATAS') NOT NULL DEFAULT 'NENHUMA',
  recorrencia_dia TINYINT NULL,
  recorrencia_dias VARCHAR(100) NULL,
  recorrencia_ate DATE NULL,
  status       ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO','PAUSADO','AGUARDANDO_VALIDACAO') NOT NULL DEFAULT 'NAO_INICIADO',
  prioridade   ENUM('ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA',
  progresso    TINYINT NOT NULL DEFAULT 0,
  progresso_anterior TINYINT NULL,
  concluido_em DATETIME NULL,
  ordem        SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_desd_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE,
  CONSTRAINT fk_desd_iniciativa FOREIGN KEY (iniciativa_id) REFERENCES iniciativa(id) ON DELETE CASCADE,
  -- O dono da ação: é daqui que saem as cobranças por e-mail. Sem chave, excluir
  -- o usuário deixava a ação com um responsável que não existe — listada, sem
  -- dono e sem nada na tela dizendo isso. Ver a nota de fk_ci_unido_por.
  CONSTRAINT fk_desd_quem FOREIGN KEY (quem_usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Os períodos de uma ação de recorrência DATAS (pedido do cliente,
-- 2026-11-09): "a ação de treinamento seria realizada duas vezes ao ano, uma
-- em abril e outra em agosto, e se houvessem mais datas pudessem ser lançadas.
-- Essas ações não se repetem mensalmente, e sim só nas datas lançadas."
--
-- A ação continua tendo UM `data_inicio`/`data_fim`, e eles espelham o período
-- CORRENTE — o primeiro ainda não concluído. É isso que mantém o atraso
-- automático, os avisos por e-mail e o prazo consolidado do projeto
-- funcionando sem saber desta tabela: os três leem as colunas da ação, como já
-- acontece com a recorrência semanal e mensal.
--
-- `concluido_em` por período, e não só na ação: sem ele, concluir abril e
-- reabrir em agosto apagaria o registro de que abril foi feito.
CREATE TABLE IF NOT EXISTS desdobramento_periodo (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  desdobramento_id INT NOT NULL,
  -- Início opcional, fim obrigatório: é o fim que vence, e é ele que o atraso
  -- e a cobrança leem. "Entregar até 30/04" é um período legítimo sem início.
  data_inicio      DATE NULL,
  data_fim         DATE NOT NULL,
  concluido_em     DATETIME NULL,
  CONSTRAINT fk_dperiodo_desd FOREIGN KEY (desdobramento_id)
    REFERENCES desdobramento(id) ON DELETE CASCADE,
  -- A ordem de leitura é sempre por `data_fim`: o período corrente é o
  -- primeiro em aberto, e "primeiro" quer dizer o que vence antes.
  KEY idx_dperiodo_acao (desdobramento_id, data_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS envelope_capital (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  horizonte_id     INT NOT NULL,
  valor_limite     DECIMAL(15,2) NOT NULL,
  flex_percentual  DECIMAL(5,2) NOT NULL DEFAULT 0,
  regras           TEXT,
  CONSTRAINT fk_env_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_env_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investimento (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  projeto_id       INT NULL,
  horizonte_id     INT NULL,
  descricao        TEXT NOT NULL,
  papel            ENUM('OBRIGATORIO','MANUTENCAO','EFICIENCIA','CRESCIMENTO','ESTRATEGICO') NULL,
  ano              SMALLINT NOT NULL,
  valor            DECIMAL(15,2) NOT NULL,
  taxa_retorno     DECIMAL(6,2) NULL,
  situacao         ENUM('PROPOSTO','RANQUEADO','APROVADO','REPROVADO','EXECUTADO','AUDITADO') NOT NULL DEFAULT 'PROPOSTO',
  decisao_criterio TEXT,
  decisao_data     DATE NULL,
  valor_realizado  DECIMAL(15,2) NULL,
  auditoria_nota   TEXT,
  CONSTRAINT fk_inv_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id),
  CONSTRAINT fk_inv_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro dos avisos por e-mail já enviados (evita repetir no mesmo dia)
CREATE TABLE IF NOT EXISTS envio_email (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tipo        ENUM('SEMANAL','DIARIO','RESUMO') NOT NULL,
  referencia  DATE NOT NULL,
  usuario_id  INT NOT NULL,
  destinatario VARCHAR(255) NOT NULL,
  itens       SMALLINT NOT NULL DEFAULT 0,
  erro        TEXT NULL,
  enviado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_envio (tipo, referencia, usuario_id),
  CONSTRAINT fk_envio_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diario_bordo (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ref_tipo     ENUM('PROJETO','DESDOBRAMENTO','INVESTIMENTO','CASCATA') NOT NULL,
  ref_id       INT NOT NULL,
  data_reg     DATE NOT NULL,
  -- Anulável de propósito: excluir um usuário não pode levar junto o que ele
  -- escreveu. Na exclusão o registro vai para a pessoa indicada, ou fica sem
  -- vínculo e a tela mostra «Sem usuário» (ver UsuarioController::excluir).
  autor_id     INT NULL,
  texto        TEXT NOT NULL,
  status_atual ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO') NULL,
  progresso    TINYINT NULL,
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ref (ref_tipo, ref_id, data_reg),
  CONSTRAINT fk_db_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comentários de acompanhamento, com anexos. Sucedem o diário de bordo: o
-- registro continua sendo datado e nunca sobrescrito, mas agora carrega
-- arquivo junto (a foto da obra, a proposta em PDF, a planilha do orçamento).
CREATE TABLE IF NOT EXISTS comentario (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  -- CENARIO: anexos e apontamentos de cada item da Análise de Cenário
  -- (pedido do cliente, 2026-11-20).
  ref_tipo     ENUM('PROJETO','DESDOBRAMENTO','INVESTIMENTO','CASCATA','CENARIO') NOT NULL,
  ref_id       INT NOT NULL,
  -- Anulável de propósito: excluir um usuário não pode levar junto o que ele
  -- escreveu. Na exclusão o registro vai para a pessoa indicada, ou fica sem
  -- vínculo e a tela mostra «Sem usuário» (ver UsuarioController::excluir).
  autor_id     INT NULL,
  texto        TEXT NOT NULL,
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ref (ref_tipo, ref_id, criado_em),
  CONSTRAINT fk_com_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O arquivo mora no BANCO, não em disco: o contêiner do Railway é efêmero e
-- pasta de upload some no deploy seguinte, levando junto o anexo de todo mundo.
-- O conteúdo fica numa tabela à parte para o SELECT da lista não arrastar
-- megabytes de BLOB a cada abertura do cartão.
CREATE TABLE IF NOT EXISTS comentario_anexo (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  comentario_id INT NOT NULL,
  nome          VARCHAR(200) NOT NULL,
  tipo          VARCHAR(100) NOT NULL,
  tamanho       INT NOT NULL,
  conteudo      LONGBLOB NOT NULL,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_com (comentario_id),
  CONSTRAINT fk_anexo_com FOREIGN KEY (comentario_id) REFERENCES comentario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ata leve das reuniões de acompanhamento do planejamento
CREATE TABLE IF NOT EXISTS reuniao (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id INT NOT NULL,
  data_reuniao    DATE NOT NULL,
  periodo_de      DATE NOT NULL,
  periodo_ate     DATE NOT NULL,
  participantes   TEXT NULL,
  decisoes        TEXT NULL,
  proximos_passos TEXT NULL,
  -- Anulável de propósito: excluir um usuário não pode levar junto o que ele
  -- escreveu. Na exclusão o registro vai para a pessoa indicada, ou fica sem
  -- vínculo e a tela mostra «Sem usuário» (ver UsuarioController::excluir).
  autor_id        INT NULL,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reu_plan (planejamento_id, data_reuniao),
  CONSTRAINT fk_reu_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_reu_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coleta de ideias (brainstorm): item cru até virar cenário ou fator
CREATE TABLE IF NOT EXISTS coleta_item (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  rodada_id        INT NULL,
  ano              SMALLINT NOT NULL,
  -- nulo quando a ideia veio da tempestade (participante sem cadastro)
  autor_id         INT NULL,
  autor_nome       VARCHAR(120) NULL,
  participante_token CHAR(32) NULL,
  dividido_de_id   INT NULL,
  agrupado_em_id   INT NULL,
  -- Unificação de respostas do quiz (o condutor arrasta uma ficha sobre a
  -- outra): o vínculo é o `agrupado_em_id` acima; estas guardam a
  -- rastreabilidade — de que grupo a ficha veio, quem uniu e quando. É
  -- `unido_de_id` que permite DESFAZER exatamente, devolvendo cada linha ao
  -- líder que ela tinha antes.
  unido_de_id      INT NULL,
  unido_por        INT NULL,
  unido_em         DATETIME NULL,
  adiado           TINYINT(1) NOT NULL DEFAULT 0,
  -- Quiz: a sugestão pertence a uma pergunta do roteiro e, quando o alvo tem
  -- lados, declara de qual fala. `origem` é a MARCA de isolamento entre
  -- os ritos — é por ela que as listagens da tempestade deixam a resposta de
  -- quiz de fora. Nunca por pergunta_id (FK SET NULL, que soltaria o item para
  -- dentro da tela errada) nem por tipo_resposta: alvo sem lado (o 🎤 de uma
  -- coluna do PESTEL/SWOT) responde com tipo_resposta NULL e vazaria para a
  -- fila da Coleta.
  -- `tipo_resposta` é o LADO da resposta (ESCOLHA/RENUNCIA na cascata,
  -- SITUACAO_ATUAL/TENDENCIA no cenário) ou, na pergunta da ETAPA INTEIRA, a
  -- CATEGORIA que o participante escolheu no celular (POLITICO, FORCA…). Não é
  -- ENUM de propósito: a lista branca é derivada da pergunta
  -- (App\Services\Quiz::ladosDe), e um ENUM cresceria a cada análise nova.
  origem           ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE',
  pergunta_id      INT NULL,
  tipo_resposta    VARCHAR(40) NULL,
  -- O par do CRUZAMENTO (TOWS), quando a pergunta é desse alvo: é a única
  -- resposta da sala que não é só texto — a pessoa ESCOLHE dois fatores da SWOT
  -- e escreve a estratégia do encontro deles. Nulos em todos os outros alvos.
  --
  -- `SET NULL` e não `CASCADE`: apagar um fator da SWOT não pode apagar o que
  -- alguém escreveu na oficina. O par se desfaz, a voz fica — e a condução vê
  -- que o fator saiu, em vez de ver a resposta sumir sem explicação.
  fator_interno_id INT NULL,
  fator_externo_id INT NULL,
  texto            TEXT NOT NULL,
  texto_tratado    TEXT NULL,
  destino_sugerido ENUM('CENARIO','PESTEL','PORTER','SWOT','NAO_SEI') NOT NULL DEFAULT 'NAO_SEI',
  situacao         ENUM('NOVO','SELECIONADO','ACEITO','DESCARTADO','DIVIDIDO') NOT NULL DEFAULT 'NOVO',
  impacto          ENUM('ALTO','BAIXO') NULL,
  esforco          ENUM('BAIXO','ALTO') NULL,
  votos            SMALLINT NOT NULL DEFAULT 0,
  destino_tipo     ENUM('CENARIO','FATOR','ACAO','CASCATA','CRUZAMENTO') NULL,
  destino_id       INT NULL,
  motivo           TEXT NULL,
  triado_por       INT NULL,
  triado_em        DATETIME NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ci_plan (planejamento_id, ano, situacao),
  KEY idx_ci_destino (destino_tipo, destino_id),
  -- a tela ao vivo consulta de 4 em 4 segundos por participante: sem estes
  -- índices cada consulta varria a tabela que mais cresce na oficina
  KEY idx_ci_rodada (rodada_id, situacao),
  KEY idx_ci_part (rodada_id, participante_token),
  KEY idx_ci_grupo (agrupado_em_id),
  CONSTRAINT fk_ci_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_autor FOREIGN KEY (autor_id) REFERENCES usuario(id),
  -- Sem esta chave, excluir o usuário deixava `unido_por` apontando para um id
  -- que não existe mais, em silêncio. RESTRICT de propósito: quem decide o
  -- destino é o UsuarioController, e a chave é a rede que faz o DELETE falhar
  -- se ele esquecer a coluna — nulo silencioso a rede não pega.
  CONSTRAINT fk_ci_unido_por FOREIGN KEY (unido_por) REFERENCES usuario(id)
  -- fk_ci_rodada e fk_ci_triador ficam no migrate (garantirFk): coleta_rodada
  -- é criada DEPOIS desta tabela, e a FK aqui quebraria a instalação nova
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rodada de tempestade de ideias: sessão ao vivo com PIN para entrar
-- A rodada tem um MODO: a tempestade clássica (tema aberto, matriz de
-- prioridade) e a sessão de QUIZ, com roteiro de perguntas dirigidas às
-- análises. É a MESMA sala — PIN, token, tetos, trava de força bruta — para os
-- dois ritos; uma segunda sala seria a segunda cópia das regras de segurança
-- das únicas rotas de escrita sem autenticação do sistema. Dentro do quiz o
-- rito é da PERGUNTA ATIVA, não da rodada: é isso que dá um PIN só para o
-- projeto inteiro, valendo em todas as telas de análise.
CREATE TABLE IF NOT EXISTS coleta_rodada (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id INT NOT NULL,
  ano             SMALLINT NOT NULL,
  tema            VARCHAR(180) NOT NULL,
  pin             CHAR(6) NOT NULL,
  situacao        ENUM('ABERTA','ENCERRADA') NOT NULL DEFAULT 'ABERTA',
  votacao         ENUM('FECHADA','ABERTA') NOT NULL DEFAULT 'FECHADA',
  max_ideias      TINYINT NOT NULL DEFAULT 5,
  max_votos       TINYINT NOT NULL DEFAULT 3,
  -- Questionário prévio (tempestade com perguntas em `quiz_pergunta`, alvo
  -- LIVRE): a rodada fecha sozinha ao passar do prazo, ou quando o condutor
  -- encerra — o que vier primeiro. Nulo = só o condutor encerra.
  prazo           DATETIME NULL,
  modo            ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE',
  -- Anulável de propósito: excluir um usuário não pode levar junto o que ele
  -- escreveu. Na exclusão o registro vai para a pessoa indicada, ou fica sem
  -- vínculo e a tela mostra «Sem usuário» (ver UsuarioController::excluir).
  criado_por      INT NULL,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  encerrada_em    DATETIME NULL,
  UNIQUE KEY uk_rodada_pin (pin),
  KEY idx_rodada_plan (planejamento_id, ano, situacao),
  CONSTRAINT fk_rod_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_rod_autor FOREIGN KEY (criado_por) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roteiro do encontro: cada linha é uma pergunta que a sala responde, e a
-- pergunta aponta para QUALQUER análise do planejamento (ALVO polimórfico) —
-- uma célula da Cascata, o cenário de um ano, um quadrante do PESTEL/Porter/
-- SWOT, ou nada (LIVRE, a tempestade de ideias). É o que permite UM PIN para o
-- projeto todo: o participante escaneia uma vez e o celular acompanha o que o
-- condutor abre em cada tela.
--
-- A pergunta ATIVA é a que a sala está respondendo agora — a fonte da verdade é
-- esta situação, e não uma coluna na rodada: dois lugares dizendo "qual é a
-- ativa" dessincronizariam na primeira corrida. Reabrir uma pergunta é
-- devolvê-la a ATIVA; ENCERRADA ela para de aceitar envio, mas as sugestões
-- continuam inteiras (navegar não apaga nada).
CREATE TABLE IF NOT EXISTS quiz_pergunta (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  rodada_id    INT NOT NULL,
  alvo_tipo    ENUM('CASCATA','CENARIO','FATOR','CRUZAMENTO','LIVRE') NOT NULL DEFAULT 'CASCATA',
  -- a pergunta nas palavras do condutor (o padrão vem do alvo, em App\Services\Quiz)
  enunciado    VARCHAR(255) NULL,
  -- CASCATA: a célula (driver x horizonte x eixo). Nulos nos demais alvos.
  horizonte_id INT NULL,
  driver_id    INT NULL,
  eixo_id      INT NULL,
  -- CENARIO, FATOR e CRUZAMENTO: a análise de diagnóstico é anual
  ano          SMALLINT NULL,
  -- FATOR: qual coluna do PESTEL/Porter/SWOT
  etapa        ENUM('PESTEL','PORTER','SWOT') NULL,
  -- FATOR: o quadrante. CRUZAMENTO: o BLOCO do TOWS (ATACAR, DEFENDER,
  -- REFORCAR, PROTEGER) — a mesma coluna porque a pergunta é a mesma coisa nos
  -- dois casos, "qual recorte desta análise estamos perguntando". `alvo_chave`
  -- já carrega o `alvo_tipo`, então os dois usos não colidem no UNIQUE.
  categoria    VARCHAR(40) NULL,
  ordem        SMALLINT NOT NULL DEFAULT 0,
  situacao     ENUM('PENDENTE','ATIVA','ENCERRADA') NOT NULL DEFAULT 'PENDENTE',
  aberta_em    DATETIME NULL,
  -- Uma chave só para todos os alvos: colunas nulas por tipo não formam UNIQUE
  -- (NULL nunca colide com NULL), e a mesma célula/categoria não pode entrar
  -- duas vezes no mesmo roteiro. LIVRE entra pelo enunciado, que é o alvo dela.
  alvo_chave   VARCHAR(160) AS (CONCAT_WS('|', alvo_tipo,
                 COALESCE(horizonte_id, 0), COALESCE(driver_id, 0), COALESCE(eixo_id, 0),
                 COALESCE(ano, 0), COALESCE(etapa, ''), COALESCE(categoria, ''),
                 IF(alvo_tipo = 'LIVRE', MD5(COALESCE(enunciado, '')), ''))) STORED,
  UNIQUE KEY uk_pergunta_alvo (rodada_id, alvo_chave),
  KEY idx_qp_ativa (rodada_id, situacao),
  CONSTRAINT fk_qp_rodada FOREIGN KEY (rodada_id) REFERENCES coleta_rodada(id) ON DELETE CASCADE,
  CONSTRAINT fk_qp_horizonte FOREIGN KEY (horizonte_id) REFERENCES horizonte(id),
  CONSTRAINT fk_qp_driver FOREIGN KEY (driver_id) REFERENCES driver(id),
  CONSTRAINT fk_qp_eixo FOREIGN KEY (eixo_id) REFERENCES eixo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voto de participante numa ideia da rodada (convergência opcional)
CREATE TABLE IF NOT EXISTS coleta_voto (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  item_id             INT NOT NULL,
  rodada_id           INT NOT NULL,
  participante_token  CHAR(32) NOT NULL,
  criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_voto (item_id, participante_token),
  KEY idx_voto_rodada (rodada_id, participante_token),
  CONSTRAINT fk_voto_item FOREIGN KEY (item_id) REFERENCES coleta_item(id) ON DELETE CASCADE,
  CONSTRAINT fk_voto_rodada FOREIGN KEY (rodada_id) REFERENCES coleta_rodada(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem entrou na rodada pelo PIN. Sem esta tabela o token do participante
-- seria auto-emitido: qualquer string hex passaria na validação de formato.
CREATE TABLE IF NOT EXISTS coleta_participante (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  rodada_id  INT NOT NULL,
  token      CHAR(32) NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  -- Identificador do APARELHO, gerado e guardado pelo navegador — o `qc_device`
  -- do Quiz Copérdia. O token é a credencial da rodada; o dispositivo é o que
  -- permite DEVOLVER essa credencial a quem já entrou, sem pedir nada e sem
  -- ter de confiar no nome digitado.
  dispositivo VARCHAR(80) NULL,
  -- Última vez que este participante falou com o servidor. Faz o papel da
  -- conexão viva do Quiz (lá o SSE diz quem está on-line; aqui a tela consulta
  -- de 4 em 4 segundos) e é o que autoriza devolver a identidade pelo NOME:
  -- dono calado há tempo é a mesma pessoa voltando; dono ativo, não é.
  visto_em   DATETIME NULL,
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_part (rodada_id, token),
  KEY idx_part_disp (rodada_id, dispositivo),
  CONSTRAINT fk_part_rodada FOREIGN KEY (rodada_id) REFERENCES coleta_rodada(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de resolver um PIN, para travar enumeração por força bruta
CREATE TABLE IF NOT EXISTS coleta_tentativa (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  origem    VARCHAR(45) NOT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tentativa (origem, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cargas de conteúdo já aplicadas pelo migrate (ex.: o cenário macroeconômico
-- da Análise de Cenário). O migrate roda a cada deploy e o conteúdo aqui é
-- EDITÁVEL na tela: sem a marca, todo deploy recriaria o item que alguém
-- apagou e desfaria a redação que alguém ajustou. Marcada uma vez, a carga
-- nunca mais é reaplicada — revisão de texto pede chave nova.
CREATE TABLE IF NOT EXISTS carga_conteudo (
  chave       VARCHAR(80) PRIMARY KEY,
  detalhe     VARCHAR(255) NULL,
  aplicado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login falhas: trava de força bruta por e-mail e por origem
CREATE TABLE IF NOT EXISTS login_tentativa (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  origem    VARCHAR(45) NOT NULL,
  email     VARCHAR(190) NOT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_origem (origem, criado_em),
  KEY idx_login_email (email, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Projeção de Resultados ──────────────────────────────────────────────────
-- A DRE gerencial do Qlik dentro do sistema: realizado dos meses fechados,
-- orçamento dos doze, e (nas fatias seguintes) a proposta do ano de
-- planejamento. Pedido do cliente em 2026-11-05, com a planilha
-- "PECUARIA - SIMULADOR Oficial" como maquete; o plano está no tema 13 de
-- `docs/BACKLOG-EVOLUCAO.md`.

-- A estrutura da DRE, espelhando `Cód Indicador` do app DRE - Gerencial. Não é
-- cadastro de tela: vem na carga, junto com os valores, porque estrutura e
-- número precisam ser da MESMA extração — conta nova no Qlik com estrutura
-- velha aqui faria a DRE fechar errado em silêncio.
CREATE TABLE IF NOT EXISTS dre_conta (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  codigo      VARCHAR(10) NOT NULL,
  nome        VARCHAR(120) NOT NULL,
  -- 1 = linha do bloco (as doze da DRE mais as calculadas); 2 = subconta.
  nivel       TINYINT NOT NULL DEFAULT 1,
  pai_codigo  VARCHAR(10) NULL,
  -- `SeqEstrutura` do Qlik: é ela que põe "Desp./ROL" entre a linha de
  -- despesas e as subcontas dela, como na tela do app e na planilha.
  ordem       SMALLINT NOT NULL DEFAULT 0,
  -- Linha de PERCENTUAL (Margem Bruta, Desp./ROL, Margem Líquida). Extração
  -- devolve ZERO nelas — são calculadas na tela, e somá-las seria somar nada.
  percentual  TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_dre_conta (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valor por negócio, conta e MÊS. Mês a mês, e não o acumulado, por dois
-- motivos: o corte do fechamento se recalcula sem nova extração, e a carga do
-- mês seguinte é um arquivo pequeno em vez da reescrita do ano.
CREATE TABLE IF NOT EXISTS dre_valor (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id  INT NOT NULL,
  conta_id    INT NOT NULL,
  ano         SMALLINT NOT NULL,
  mes         TINYINT NOT NULL,
  -- REAL = mês fechado (tem rateio); META = o orçamento lançado para o mês.
  tipo        ENUM('REAL','META') NOT NULL,
  valor       DECIMAL(18,2) NOT NULL,
  UNIQUE KEY uk_dre_valor (negocio_id, conta_id, ano, mes, tipo),
  KEY idx_dre_valor_leitura (negocio_id, ano, tipo),
  CONSTRAINT fk_dre_valor_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE,
  CONSTRAINT fk_dre_valor_conta FOREIGN KEY (conta_id) REFERENCES dre_conta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Até que mês o ano está FECHADO. É propriedade do ano, não do negócio: o
-- fechamento é da cooperativa, e há negócio sem rateio nenhum (a Usina
-- Fotovoltaica) que, julgado sozinho, pareceria nunca ter fechado mês.
CREATE TABLE IF NOT EXISTS dre_fechamento (
  ano             SMALLINT PRIMARY KEY,
  mes_fechado_ate TINYINT NOT NULL,
  carga           VARCHAR(80) NOT NULL,
  extraido_em     DATE NULL,
  fonte           VARCHAR(255) NULL,
  aplicado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A proposta do ano de planejamento (fatia 2 do tema 13). Uma por negócio e
-- ano; o `modo` existe desde já porque a decisão do cliente é que cada negócio
-- escolhe planejar de cima para baixo (negócio, distribuindo às filiais depois)
-- ou de baixo para cima (filial a filial, somando) — a fatia 3 liga o segundo.
CREATE TABLE IF NOT EXISTS projecao (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id     INT NOT NULL,
  ano            SMALLINT NOT NULL,
  modo           ENUM('NEGOCIO','FILIAL') NOT NULL DEFAULT 'NEGOCIO',
  -- Trava otimista: sobe a cada gravação. Quem carregou a tela antes da
  -- gravação de outra pessoa é recusado com 409 em vez de sobrescrever calado.
  versao         INT NOT NULL DEFAULT 1,
  atualizado_em  DATETIME NULL,
  atualizado_por INT NULL,
  UNIQUE KEY uk_projecao (negocio_id, ano),
  CONSTRAINT fk_projecao_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE,
  CONSTRAINT fk_projecao_usuario FOREIGN KEY (atualizado_por) REFERENCES usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O que foi LANÇADO, linha a linha. Guarda o que a pessoa digitou — valor OU
-- percentual, nunca os dois: com os dois gravados eles divergem no primeiro
-- fechamento novo e ninguém sabe qual dos dois ela quis dizer.
CREATE TABLE IF NOT EXISTS projecao_valor (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  projecao_id INT NOT NULL,
  -- 0 = a linha do NEGÓCIO; qualquer outro valor é a filial. Zero e não NULL
  -- pela mesma razão de `indicador_valor.mes`: com NULL, a chave única abaixo
  -- não impediria duplicata nenhuma — o MySQL trata cada NULL como distinto.
  filial_id   INT NOT NULL DEFAULT 0,
  conta_id    INT NOT NULL,
  entrada     ENUM('VALOR','PERCENTUAL') NOT NULL,
  valor       DECIMAL(18,2) NULL,
  percentual  DECIMAL(9,4) NULL,
  UNIQUE KEY uk_projecao_valor_filial (projecao_id, filial_id, conta_id),
  CONSTRAINT fk_pv_projecao FOREIGN KEY (projecao_id) REFERENCES projecao(id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_conta FOREIGN KEY (conta_id) REFERENCES dre_conta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LEGADO: as premissas penduradas na PROJEÇÃO (que é a proposta de UM ano), por
-- projeção e filial. Desde 2026-11-17 (pedido do cliente: "IPCA, PIB e
-- percentual de crescimento desejado devem ser por negócio e por ano") quem as
-- guarda é `negocio_premissa`, logo abaixo, e esta tabela NÃO É MAIS LIDA nem
-- escrita. Fica por dois motivos: a migração copia daqui o que o cliente já
-- tinha digitado (`Projecao::migrarPremissasParaNegocio`, uma vez só), e uma
-- linha por FILIAL gravada antes da decisão de 2026-11-16 (as premissas são do
-- negócio e valem para todas as filiais) continua aqui, intacta e inerte — não
-- foi promovida ao negócio, e se alguém quiser recuperar um valor antigo, ele
-- está aqui. Não reintroduza a leitura desta tabela.
--
-- IPCA e PIB são as únicas células digitadas do painel da planilha no nível
-- consolidado: lá, "Cresc. Total" é lido da proposta da receita
-- (`B14 = (O9/J9)-1`), "Cresc. Real" é o resto (`B13 = B14-B11-B12`) e
-- "% Custos" é lido dos custos. Guardar só o que é digitado evita a segunda
-- fonte do mesmo número.
CREATE TABLE IF NOT EXISTS projecao_premissa (
  projecao_id INT NOT NULL,
  filial_id   INT NOT NULL DEFAULT 0,
  chave       VARCHAR(20) NOT NULL,
  percentual  DECIMAL(9,4) NOT NULL,
  PRIMARY KEY (projecao_id, filial_id, chave),
  KEY idx_pp_projecao (projecao_id),
  CONSTRAINT fk_pp_projecao FOREIGN KEY (projecao_id) REFERENCES projecao(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Desde 2026-11-17 também `PESSOAS`: o crescimento das despesas com pessoas. A
-- coluna `chave` é texto livre de até 20 caracteres, então uma premissa nova não pede
-- migração.)
-- As premissas do painel, por NEGÓCIO e por ANO: IPCA, PIB e o CRESCIMENTO
-- DESEJADO (`CRESCIMENTO`). Valem para o negócio inteiro e para todas as filiais
-- dele (pedido do cliente, 2026-11-16) e há UM valor para cada ano do ciclo, a
-- partir do ano da proposta (2026-11-17).
--
-- Mora aqui e não em `projecao_premissa` porque a projeção é a PROPOSTA de um
-- ano só: para guardar o IPCA de 2028 seria preciso criar uma projeção de 2028,
-- com modo, versão e trava de edição de uma proposta que não existe — e o que
-- lista projeções passaria a ver anos fantasma. Premissa de ano sem proposta é
-- registro, e registro não precisa de proposta.
--
-- Desde 2026-11-18 as premissas MOVEM a proposta: a Receita de Vendas cresce
-- IPCA + PIB + CRESCIMENTO e as Despesas com Pessoal, só PESSOAS (por omissão, onde a
-- linha não foi lançada — `Projecao::crescimentosPadrao`). O digitado numa linha manda.
CREATE TABLE IF NOT EXISTS negocio_premissa (
  negocio_id INT NOT NULL,
  ano        SMALLINT NOT NULL,
  chave      VARCHAR(20) NOT NULL,
  percentual DECIMAL(9,4) NOT NULL,
  PRIMARY KEY (negocio_id, ano, chave),
  CONSTRAINT fk_np_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Filiais (fatia 3 do tema 13) ────────────────────────────────────────────
-- As unidades dentro do negócio. Só entram as que têm movimento: a Pecuária
-- tem 39 filiais no cadastro do ERP e SEIS com lançamento em 2026 — são as
-- cinco da planilha do cliente mais Morrinhos, que aparece com R$ 1.448.
CREATE TABLE IF NOT EXISTS filial (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id INT NOT NULL,
  cod_filial VARCHAR(10) NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  UNIQUE KEY uk_filial (negocio_id, cod_filial),
  CONSTRAINT fk_filial_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A DRE da filial, ACUMULADA — e não mês a mês como a do negócio.
-- O motivo é tamanho: são 177 filiais com movimento nos onze negócios, e mês a
-- mês isso é meio milhão de valores. Acumulado, a carga de um negócio cabe numa
-- chamada do Qlik. A contrapartida está declarada em `corte`: estes números
-- valem para UM corte de fechamento, e quando o corte anda a filial inteira é
-- re-extraída (o negócio, não — lá só o mês novo chega).
CREATE TABLE IF NOT EXISTS dre_valor_filial (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  filial_id  INT NOT NULL,
  conta_id   INT NOT NULL,
  ano        SMALLINT NOT NULL,
  corte      TINYINT NOT NULL,
  realizado  DECIMAL(18,2) NOT NULL,
  projetado  DECIMAL(18,2) NOT NULL,
  UNIQUE KEY uk_dre_valor_filial (filial_id, conta_id, ano),
  CONSTRAINT fk_dvf_filial FOREIGN KEY (filial_id) REFERENCES filial(id) ON DELETE CASCADE,
  CONSTRAINT fk_dvf_conta FOREIGN KEY (conta_id) REFERENCES dre_conta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O NÍVEL 3 da DRE gerencial: a conta reduzida (pedido do cliente, 2026-11-21:
-- "na DRE gerencial temos 3 níveis de abertura; o terceiro totaliza o segundo").
-- O catálogo é o do Qlik (`Cód Indicador` do nível 3, como "8.5.12"): reduzida,
-- nome, a linha do nível 2 que ela soma e a ordem em que o Qlik a lista. Só
-- leitura e só realizado — o lançamento é no nível 2, e o Qlik não tem orçado
-- por conta reduzida.
CREATE TABLE IF NOT EXISTS dre_reduzida (
  codigo     VARCHAR(12) NOT NULL PRIMARY KEY,
  reduzida   VARCHAR(8) NOT NULL,
  nome       VARCHAR(80) NOT NULL,
  linha      VARCHAR(10) NOT NULL,
  ordem      INT NOT NULL,
  KEY idx_dre_reduzida_linha (linha, ordem),
  KEY idx_dre_reduzida_red (reduzida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O realizado ACUMULADO até o corte por conta reduzida, do negócio inteiro
-- (`filial_id = 0`) e de cada filial. Sem chave estrangeira na filial porque o
-- zero é o negócio; a carga confere que a filial existe.
CREATE TABLE IF NOT EXISTS dre_reduzida_valor (
  negocio_id INT NOT NULL,
  filial_id  INT NOT NULL DEFAULT 0,
  ano        SMALLINT NOT NULL,
  corte      TINYINT NOT NULL,
  codigo     VARCHAR(12) NOT NULL,
  realizado  DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, filial_id, ano, codigo),
  CONSTRAINT fk_drv_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O NÍVEL 3 em JANELAS DE 12 MESES, terminando em (ano_fim, mes_fim): nov/2025 a
-- out/2026 é (2026, 10). Base dos questionamentos das oportunidades desde o pedido
-- do cliente de 2026-11-23 ("pegue desde novembro do ano anterior até outubro do
-- ano atual, e compare com o ano anterior"). Somado no Qlik; filial_id 0 é o negócio.
CREATE TABLE IF NOT EXISTS dre_reduzida_12m (
  negocio_id INT NOT NULL,
  filial_id  INT NOT NULL DEFAULT 0,
  ano_fim    SMALLINT NOT NULL,
  mes_fim    TINYINT NOT NULL,
  codigo     VARCHAR(12) NOT NULL,
  realizado  DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, filial_id, ano_fim, mes_fim, codigo),
  CONSTRAINT fk_dr12_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A AVALIAÇÃO de cada item não recorrente (multas, provisões, indenizações,
-- perdas, receitas pontuais) pelo negócio, para o ano do planejamento (pedido do
-- cliente, 2026-11-23): CONSIDERAR (vai se repetir: risco a planejar) ou
-- DESCONSIDERAR (não se repete: oportunidade). A linha existir é o item estar
-- AVALIADO. Não muda a DRE sozinha.
CREATE TABLE IF NOT EXISTS negocio_item_avaliacao (
  negocio_id   INT NOT NULL,
  ano          SMALLINT NOT NULL,
  conta        VARCHAR(32) NOT NULL,
  decisao      ENUM('CONSIDERAR','DESCONSIDERAR') NOT NULL,
  avaliado_por INT NULL,
  avaliado_em  DATETIME NOT NULL,
  PRIMARY KEY (negocio_id, ano, conta),
  CONSTRAINT fk_nia_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Desafio extra dos administradores (pedido do cliente, 2026-11-25): o valor que
-- o ADMIN digita na coluna Desafio do cartão do negócio, para o ano todo, numa
-- das linhas de valor (App\Services\DesafioAjuste::CHAVES). Sem linha aqui, vale
-- o desafio calculado (a proposta padrão).
CREATE TABLE IF NOT EXISTS negocio_desafio_ajuste (
  negocio_id   INT NOT NULL,
  ano          SMALLINT NOT NULL,
  chave        VARCHAR(32) NOT NULL,
  valor        DECIMAL(18,2) NOT NULL,
  ajustado_por INT NULL,
  ajustado_em  DATETIME NOT NULL,
  PRIMARY KEY (negocio_id, ano, chave),
  CONSTRAINT fk_nda_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A DRE da filial MÊS A MÊS, só onde moram os eventos pontuais (8.x, 9.x, 11.x)
-- e só onde algum mês passa do piso da detecção — ver
-- database/eventos_filiais_mensal_2026_10.php. Serve às oportunidades e riscos da
-- filial (receita de juros concentrada num mês e afins); a DRE da filial para a
-- proposta continua sendo a acumulada (dre_valor_filial).
CREATE TABLE IF NOT EXISTS dre_valor_filial_mes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  filial_id  INT NOT NULL,
  conta_id   INT NOT NULL,
  ano        SMALLINT NOT NULL,
  mes        TINYINT NOT NULL,
  valor      DECIMAL(18,2) NOT NULL,
  UNIQUE KEY uk_dre_valor_filial_mes (filial_id, conta_id, ano, mes),
  CONSTRAINT fk_dvfm_filial FOREIGN KEY (filial_id) REFERENCES filial(id) ON DELETE CASCADE,
  CONSTRAINT fk_dvfm_conta FOREIGN KEY (conta_id) REFERENCES dre_conta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O quadro de colaboradores por NEGÓCIO e por mês, vindo da DRE gerencial (campo
-- `Número Funcionarios`). Já é "equivalente": quem divide o tempo entre dois
-- negócios entra em cada um pela sua parte (confirmado pelo cliente em
-- 2026-11-18). A base do planejamento de pessoas é o ÚLTIMO mês carregado do
-- ano-base, então dezembro, quando vier, é só mais uma linha — a base anda sozinha.
CREATE TABLE IF NOT EXISTS negocio_pessoas_mes (
  negocio_id    INT NOT NULL,
  ano           SMALLINT NOT NULL,
  mes           TINYINT NOT NULL,
  colaboradores INT NOT NULL,
  PRIMARY KEY (negocio_id, ano, mes),
  CONSTRAINT fk_npm_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O quadro de colaboradores por FILIAL, no último mês carregado do ano (pedido do
-- cliente, 2026-11-23): é ele que dá o MÍNIMO das Despesas com Pessoal de cada
-- filial — "o número de pessoas × o custo com 5,5%" —, e a filial não tem quadro
-- planejado (decisão do cliente: "só o quadro atual da filial"). Mesma origem do
-- quadro do negócio (`Número Funcionarios` da DRE gerencial); a soma das filiais
-- de um negócio é o quadro dele no mesmo mês. A carga do ano o REESCREVE inteiro:
-- filial que ficou sem ninguém some, em vez de ficar com o quadro de antes.
CREATE TABLE IF NOT EXISTS filial_pessoas (
  filial_id     INT NOT NULL,
  ano           SMALLINT NOT NULL,
  mes           TINYINT NOT NULL,
  colaboradores INT NOT NULL,
  PRIMARY KEY (filial_id, ano),
  CONSTRAINT fk_fp_filial FOREIGN KEY (filial_id) REFERENCES filial(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O que o cliente DIGITA no planejamento de pessoas e benefícios, por NEGÓCIO e por
-- ANO: o número de colaboradores planejado (quando difere do quadro atual), os
-- valores por colaborador/mês e os percentuais de previsão. Só o digitado é
-- guardado: o padrão (alimentação R$ 500, abono de assiduidade R$ 200, abono de
-- resultado R$ 500) mora em `Projecao::BENEFICIOS_PADRAO`, e guardar uma cópia dele
-- aqui criaria duas fontes para o mesmo número. `valor` serve a reais, a
-- percentuais e a pessoas — o que cada `chave` significa está em `Projecao`.
CREATE TABLE IF NOT EXISTS negocio_beneficio (
  negocio_id INT NOT NULL,
  ano        SMALLINT NOT NULL,
  chave      VARCHAR(24) NOT NULL,
  valor      DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (negocio_id, ano, chave),
  CONSTRAINT fk_nb_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A FOLHA de cada negócio, para a despesa de pessoal da diretriz (pedido do
-- cliente, 2026-11-18: PPR de 1,5 salário "considerando a folha de pagamento do
-- mês atual, com o salário base como referência"). Do Qlik DRE Gerencial, por
-- conta contábil: o total das 3.07.01.21* (a linha 8.5) de jan até o corte e, nele,
-- o vale PAT, o prêmio por desempenho e a provisão do PPR; e os SALÁRIOS E
-- REMUNERAÇÕES do mês do corte, na despesa (3.07.01.21.01.01) e no custo
-- (4.03.01.01.01). Ver `Projecao::pessoalDaDiretriz` e database/folha_*.php.
CREATE TABLE IF NOT EXISTS negocio_folha (
  negocio_id      INT NOT NULL,
  ano             SMALLINT NOT NULL,
  mes_ate         TINYINT NOT NULL,
  pessoal_ate     DECIMAL(18,2) NOT NULL,
  vale_ate        DECIMAL(18,2) NOT NULL,
  premio_ate      DECIMAL(18,2) NOT NULL,
  ppr_ate         DECIMAL(18,2) NOT NULL,
  salario_despesa DECIMAL(18,2) NOT NULL,
  salario_custo   DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, ano),
  CONSTRAINT fk_nf_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEPRECIAÇÃO do ano da proposta por negócio, filial e mês (pedido do cliente,
-- 2026-11-23): a linha 8.10 (conta reduzida 3128) da proposta, TRAVADA — é o
-- total da depreciação, não se lança. `filial_id` 0 é o negócio (que pode ter um
-- resto em filial fora do cadastro); `valor` no sinal da DRE (despesa negativa).
-- Carga: `database/depreciacao_2027.php` (`CargaDepreciacao`, reescreve o ano).
CREATE TABLE IF NOT EXISTS negocio_depreciacao (
  negocio_id INT NOT NULL,
  filial_id  INT NOT NULL DEFAULT 0,
  ano        SMALLINT NOT NULL,
  mes        TINYINT NOT NULL,
  -- 4 casas, como a fonte (41,625): em centavos o total escapava R$ 1,45.
  valor      DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (negocio_id, filial_id, ano, mes),
  CONSTRAINT fk_ndep_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VENDAS E TRIBUTOS SOBRE VENDAS de jan até o corte, por negócio (pedido do
-- cliente, 2026-11-18): PIS e COFINS destacados no painel do desafio, para a
-- conta da reforma tributária ser feita de forma macro. Venda a sócio (ato
-- cooperativo, sem PIS/COFINS) e a não sócio. Ver database/tributos_*.php.
CREATE TABLE IF NOT EXISTS negocio_tributo (
  negocio_id      INT NOT NULL,
  ano             SMALLINT NOT NULL,
  mes_ate         TINYINT NOT NULL,
  venda_socio     DECIMAL(18,2) NOT NULL,
  venda_nao_socio DECIMAL(18,2) NOT NULL,
  pis             DECIMAL(18,2) NOT NULL,
  cofins          DECIMAL(18,2) NOT NULL,
  icms            DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, ano),
  CONSTRAINT fk_nt_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ITENS NÃO RECORRENTES de jan até o corte, por negócio e conta contábil
-- (provisões, perdas, indenizações, multas, receitas pontuais): as
-- oportunidades e os riscos de 2027 (pedido do cliente, 2026-11-18). Sinal do
-- Qlik: + despesa, − receita/reversão. O que cada conta é mora em
-- `Oportunidades::CONTAS`. Ver database/nao_recorrentes_*.php.
CREATE TABLE IF NOT EXISTS negocio_nao_recorrente (
  negocio_id INT NOT NULL,
  ano        SMALLINT NOT NULL,
  mes_ate    TINYINT NOT NULL,
  conta      VARCHAR(32) NOT NULL,
  valor      DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, ano, conta),
  CONSTRAINT fk_nnr_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Os mesmos itens não recorrentes, MÊS A MÊS — para o quadro único de
-- oportunidades e riscos mostrar o mês e para tirar o item da série da linha
-- antes da busca por meses fora do padrão (sem contar o mesmo valor duas vezes).
-- Ver database/nao_recorrentes_mensal_*.php.
CREATE TABLE IF NOT EXISTS negocio_nao_recorrente_mes (
  negocio_id INT NOT NULL,
  ano        SMALLINT NOT NULL,
  conta      VARCHAR(32) NOT NULL,
  mes        TINYINT NOT NULL,
  valor      DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, ano, conta, mes),
  CONSTRAINT fk_nnrm_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ESTOQUE como oportunidade do ano do planejamento (pedido do cliente,
-- 2026-11-18): perdas (3.07.01.01.06) e acerto de estoque (3.11.03.03.01) mês a
-- mês, do negócio (filial_id = 0) e das filiais, e o estoque parado por filial.
-- Carga: database/estoque_oportunidade_*.php. Sinal do Qlik: + despesa, − crédito.
CREATE TABLE IF NOT EXISTS estoque_perda_mes (
  negocio_id INT NOT NULL,
  filial_id  INT NOT NULL DEFAULT 0,
  ano        SMALLINT NOT NULL,
  conta      VARCHAR(32) NOT NULL,
  mes        TINYINT NOT NULL,
  valor      DECIMAL(18,2) NOT NULL,
  PRIMARY KEY (negocio_id, filial_id, ano, conta, mes),
  CONSTRAINT fk_epm_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CENÁRIOS de faturamento carregados de planilha do negócio para a proposta da
-- Receita de Vendas (pedido do cliente, 2026-11-19). Guarda o faturamento no
-- formato antigo, a taxa de PIS/COFINS usada e o valor lançado no formato novo,
-- e os itens dos três cenários — a tela mostra de onde veio o número.
CREATE TABLE IF NOT EXISTS projecao_cenario (
  negocio_id         INT NOT NULL,
  ano                SMALLINT NOT NULL,
  conta              VARCHAR(16) NOT NULL,
  cenario            VARCHAR(20) NOT NULL,
  fonte              VARCHAR(255) NOT NULL,
  taxa_pis_cofins    DECIMAL(12,10) NOT NULL,
  faturamento_antigo DECIMAL(18,2) NOT NULL,
  receita_vendas     DECIMAL(18,2) NOT NULL,
  itens              JSON NOT NULL,
  aplicado_em        DATETIME NOT NULL,
  PRIMARY KEY (negocio_id, ano, conta),
  CONSTRAINT fk_pc_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LANÇAMENTOS que explicam o quadro de oportunidades (pedido do cliente,
-- 2026-11-18: "um expansor onde eu identifique o lançamento e a filial que
-- referenciam o valor"): os de R$ 10 mil ou mais nas linhas 8, 9 e 11 e nas
-- contas dos itens não recorrentes, e os juros recebidos (3153 sócio, 3155 não
-- sócio) de R$ 5 mil ou mais. Sinal do Qlik: + despesa, − receita.
-- Carga: database/lancamentos_*.php.
CREATE TABLE IF NOT EXISTS dre_lancamento (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id  INT NOT NULL,
  ano         SMALLINT NOT NULL,
  mes         TINYINT NOT NULL,
  data        DATE NOT NULL,
  cod_filial  VARCHAR(10) NOT NULL,
  filial      VARCHAR(120) NOT NULL,
  conta       VARCHAR(32) NOT NULL,
  reduzida    VARCHAR(10) NOT NULL,
  conta_nome  VARCHAR(120) NOT NULL,
  linha_dre   VARCHAR(10) NULL,
  historico   VARCHAR(120) NOT NULL,
  complemento VARCHAR(255) NOT NULL,
  valor       DECIMAL(18,2) NOT NULL,
  KEY ix_lanc_linha (negocio_id, ano, linha_dre, mes),
  KEY ix_lanc_conta (negocio_id, ano, conta),
  KEY ix_lanc_reduzida (negocio_id, ano, reduzida),
  CONSTRAINT fk_lanc_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Juros recebidos no ano (sem piso de valor), por filial e conta: o total contra
-- o qual se mede a concentração dos lançamentos grandes.
CREATE TABLE IF NOT EXISTS juros_recebido (
  negocio_id  INT NOT NULL,
  ano         SMALLINT NOT NULL,
  mes_ate     TINYINT NOT NULL,
  cod_filial  VARCHAR(10) NOT NULL,
  filial      VARCHAR(120) NOT NULL,
  reduzida    VARCHAR(10) NOT NULL,
  total       DECIMAL(18,2) NOT NULL,
  lancamentos INT NOT NULL,
  PRIMARY KEY (negocio_id, ano, cod_filial, reduzida),
  CONSTRAINT fk_juros_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produtos parados: valor do estoque sem venda há mais de `dias_minimo` dias, por
-- filial, na posição de `data_base`. filial_id NULL = nome do Qlik sem filial
-- no cadastro (entra só no total do negócio).
CREATE TABLE IF NOT EXISTS estoque_parado (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  negocio_id  INT NOT NULL,
  filial_id   INT NULL,
  data_base   DATE NOT NULL,
  dias_minimo SMALLINT NOT NULL,
  nome_origem VARCHAR(120) NOT NULL,
  valor       DECIMAL(18,2) NOT NULL,
  itens       INT NOT NULL,
  UNIQUE KEY uk_estoque_parado (negocio_id, data_base, nome_origem),
  CONSTRAINT fk_ep_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O DESAFIO que a Controladoria propõe a cada negócio para o ano do planejamento
-- (pedido do cliente, 2026-11-18): receita bruta, receita líquida e resultado
-- líquido. É REFERÊNCIA decidida, não conta viva: sai da visão realista do ano
-- corrente congelada no dia em que foi aprovada, e recarregar a DRE não o move —
-- uma meta que anda sozinha com o fechamento deixa de ser meta. A margem não é
-- guardada (é resultado ÷ receita líquida, e guardá-la seria a segunda fonte).
-- Vem por carga (`database/desafio_*.php`) e só se lê na tela da Projeção.
CREATE TABLE IF NOT EXISTS desafio_ano (
  ano          SMALLINT NOT NULL PRIMARY KEY,
  meta_margem  DECIMAL(6,2) NOT NULL,
  metodo       TEXT NOT NULL,
  fonte        VARCHAR(255) NOT NULL,
  decidido_em  DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS negocio_desafio (
  negocio_id      INT NOT NULL,
  ano             SMALLINT NOT NULL,
  receita_bruta   DECIMAL(18,2) NOT NULL,
  receita_liquida DECIMAL(18,2) NOT NULL,
  resultado       DECIMAL(18,2) NOT NULL,
  nota            VARCHAR(255) NOT NULL,
  PRIMARY KEY (negocio_id, ano),
  CONSTRAINT fk_nd_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

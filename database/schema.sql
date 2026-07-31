-- Sistema de Planejamento Estratégico Copérdia — schema MySQL 8

-- Sessões no banco: o login sobrevive a deploys (container efêmero no Railway)
CREATE TABLE IF NOT EXISTS sessao (
  id            VARCHAR(128) PRIMARY KEY,
  dados         MEDIUMBLOB,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sessao_atualizado (atualizado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  email         VARCHAR(120) NOT NULL UNIQUE,
  senha_hash    VARCHAR(255) NOT NULL,
  perfil        ENUM('ADMIN','CONTROLADORIA','DIRECAO','GESTOR','LEITURA') NOT NULL DEFAULT 'LEITURA',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS negocio (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  cod_negocio     VARCHAR(10) NOT NULL UNIQUE,
  nome            VARCHAR(120) NOT NULL,
  gestor_id       INT NULL,
  origem          ENUM('QLIK','MANUAL') NOT NULL DEFAULT 'MANUAL',
  sincronizado_em DATETIME NULL,
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_negocio_gestor FOREIGN KEY (gestor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_negocio (
  usuario_id INT NOT NULL,
  negocio_id INT NOT NULL,
  PRIMARY KEY (usuario_id, negocio_id),
  CONSTRAINT fk_un_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  CONSTRAINT fk_un_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ciclo (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(60) NOT NULL,
  ano_base    SMALLINT NOT NULL,
  ano_inicio  SMALLINT NOT NULL,
  ano_fim     SMALLINT NOT NULL,
  status      ENUM('EM_ELABORACAO','VIGENTE','ENCERRADO') NOT NULL DEFAULT 'EM_ELABORACAO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS driver (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(60) NOT NULL,
  ordem  TINYINT NOT NULL DEFAULT 0,
  ativo  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eixo (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(60) NOT NULL,
  ordem  TINYINT NOT NULL DEFAULT 0,
  ativo  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cenario_item (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  ano              SMALLINT NULL,  -- análise anual; horizontes seguem plurianuais
  tipo             ENUM('SITUACAO_ATUAL','TENDENCIA') NOT NULL,
  ordem            SMALLINT NOT NULL DEFAULT 0,
  descricao        TEXT NOT NULL,
  CONSTRAINT fk_cenario_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fator (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  ano              SMALLINT NULL,  -- análise anual; horizontes seguem plurianuais
  etapa            ENUM('PESTEL','PORTER','SWOT') NOT NULL,
  categoria        VARCHAR(40) NOT NULL,
  descricao        TEXT NOT NULL,
  promovido_de_id  INT NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fator_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_fator_origem FOREIGN KEY (promovido_de_id) REFERENCES fator(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gut (
  fator_id   INT PRIMARY KEY,
  gravidade  TINYINT NOT NULL CHECK (gravidade  BETWEEN 1 AND 5),
  urgencia   TINYINT NOT NULL CHECK (urgencia   BETWEEN 1 AND 5),
  tendencia  TINYINT NOT NULL CHECK (tendencia  BETWEEN 1 AND 5),
  score      SMALLINT GENERATED ALWAYS AS (gravidade * urgencia * tendencia) STORED,
  CONSTRAINT fk_gut_fator FOREIGN KEY (fator_id) REFERENCES fator(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cascata_fator (
  cascata_id INT NOT NULL,
  fator_id   INT NOT NULL,
  PRIMARY KEY (cascata_id, fator_id),
  CONSTRAINT fk_cf_cascata FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id) ON DELETE CASCADE,
  CONSTRAINT fk_cf_fator FOREIGN KEY (fator_id) REFERENCES fator(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS indicador (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  nome             VARCHAR(120) NOT NULL,
  unidade          VARCHAR(20)  NOT NULL DEFAULT 'R$ mil',
  sentido          ENUM('MAIOR_MELHOR','MENOR_MELHOR') NOT NULL DEFAULT 'MAIOR_MELHOR',
  metrica_ancora   TINYINT(1) NOT NULL DEFAULT 0,
  horizonte_id     INT NULL,
  CONSTRAINT fk_ind_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_ind_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS indicador_valor (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  indicador_id  INT NOT NULL,
  ano           SMALLINT NOT NULL,
  tipo          ENUM('META','REAL') NOT NULL,
  versao_meta   TINYINT NOT NULL DEFAULT 1,
  valor         DECIMAL(15,2) NOT NULL,
  UNIQUE KEY uk_ind_ano (indicador_id, ano, tipo, versao_meta),
  CONSTRAINT fk_iv_indicador FOREIGN KEY (indicador_id) REFERENCES indicador(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projeto (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  tipo             ENUM('ESTRATEGICO','OPERACIONAL') NOT NULL,
  ano              SMALLINT NULL,
  titulo           TEXT NOT NULL,
  responsavel      VARCHAR(255),
  prazo            VARCHAR(60),
  data_inicio      DATE NULL,
  data_fim         DATE NULL,
  horizonte_id     INT NULL,
  cascata_id       INT NULL,
  impacto          ENUM('RENTABILIDADE','FATURAMENTO','SUSTENTABILIDADE','PESSOAS') NULL,
  classificacao    ENUM('PRIORITARIO','NORMAL') NOT NULL DEFAULT 'NORMAL',
  status           ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO') NOT NULL DEFAULT 'NAO_INICIADO',
  ordem            SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_proj_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_proj_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id),
  CONSTRAINT fk_proj_cascata FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Frentes de trabalho dentro de um projeto (projeto → iniciativa → ação)
CREATE TABLE IF NOT EXISTS iniciativa (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  projeto_id  INT NOT NULL,
  titulo      TEXT NOT NULL,
  descricao   TEXT,
  status      ENUM('ABERTA','EM_ANDAMENTO','CONCLUIDA') NOT NULL DEFAULT 'ABERTA',
  ordem       SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ini_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  status       ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO','PAUSADO','AGUARDANDO_VALIDACAO') NOT NULL DEFAULT 'NAO_INICIADO',
  prioridade   ENUM('ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA',
  progresso    TINYINT NOT NULL DEFAULT 0,
  concluido_em DATETIME NULL,
  ordem        SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_desd_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE,
  CONSTRAINT fk_desd_iniciativa FOREIGN KEY (iniciativa_id) REFERENCES iniciativa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS envelope_capital (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  horizonte_id     INT NOT NULL,
  valor_limite     DECIMAL(15,2) NOT NULL,
  flex_percentual  DECIMAL(5,2) NOT NULL DEFAULT 0,
  regras           TEXT,
  CONSTRAINT fk_env_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_env_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS diario_bordo (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ref_tipo     ENUM('PROJETO','DESDOBRAMENTO','INVESTIMENTO','CASCATA') NOT NULL,
  ref_id       INT NOT NULL,
  data_reg     DATE NOT NULL,
  autor_id     INT NOT NULL,
  texto        TEXT NOT NULL,
  status_atual ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO') NULL,
  progresso    TINYINT NULL,
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ref (ref_tipo, ref_id, data_reg),
  CONSTRAINT fk_db_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

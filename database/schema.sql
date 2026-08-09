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
  CONSTRAINT fk_cenario_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fator (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  -- análise anual; horizontes seguem plurianuais
  ano              SMALLINT NULL,
  etapa            ENUM('PESTEL','PORTER','SWOT') NOT NULL,
  categoria        VARCHAR(40) NOT NULL,
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
  CONSTRAINT fk_fator_origem FOREIGN KEY (promovido_de_id) REFERENCES fator(id)
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
  criado_por       INT NOT NULL,
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
  CONSTRAINT fk_cruz_acao_por FOREIGN KEY (acao_por) REFERENCES usuario(id),
  -- SET NULL, como no fator: apagada a ação, o cruzamento volta sozinho para a
  -- fila de "aguardando plano de ação" em vez de apontar para o que não existe.
  CONSTRAINT fk_cruz_desdobramento FOREIGN KEY (desdobramento_id)
    REFERENCES desdobramento(id) ON DELETE SET NULL
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
  metrica_ancora   TINYINT(1) NOT NULL DEFAULT 0,
  horizonte_id     INT NULL,
  CONSTRAINT fk_ind_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_ind_horiz FOREIGN KEY (horizonte_id) REFERENCES horizonte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicador_valor (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  indicador_id  INT NOT NULL,
  ano           SMALLINT NOT NULL,
  tipo          ENUM('META','REAL') NOT NULL,
  versao_meta   TINYINT NOT NULL DEFAULT 1,
  valor         DECIMAL(15,2) NOT NULL,
  UNIQUE KEY uk_ind_ano (indicador_id, ano, tipo, versao_meta),
  CONSTRAINT fk_iv_indicador FOREIGN KEY (indicador_id) REFERENCES indicador(id) ON DELETE CASCADE
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
  status      ENUM('ABERTA','EM_ANDAMENTO','CONCLUIDA') NOT NULL DEFAULT 'ABERTA',
  ordem       SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ini_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE
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
  recorrencia     ENUM('NENHUMA','SEMANAL','MENSAL') NOT NULL DEFAULT 'NENHUMA',
  recorrencia_dia TINYINT NULL,
  recorrencia_ate DATE NULL,
  status       ENUM('NAO_INICIADO','EM_ANDAMENTO','CONCLUIDO','ATRASADO','CANCELADO','PAUSADO','AGUARDANDO_VALIDACAO') NOT NULL DEFAULT 'NAO_INICIADO',
  prioridade   ENUM('ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA',
  progresso    TINYINT NOT NULL DEFAULT 0,
  progresso_anterior TINYINT NULL,
  concluido_em DATETIME NULL,
  ordem        SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_desd_projeto FOREIGN KEY (projeto_id) REFERENCES projeto(id) ON DELETE CASCADE,
  CONSTRAINT fk_desd_iniciativa FOREIGN KEY (iniciativa_id) REFERENCES iniciativa(id) ON DELETE CASCADE
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
  autor_id     INT NOT NULL,
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
  ref_tipo     ENUM('PROJETO','DESDOBRAMENTO','INVESTIMENTO','CASCATA') NOT NULL,
  ref_id       INT NOT NULL,
  autor_id     INT NOT NULL,
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
  autor_id        INT NOT NULL,
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
  -- dois lados, declara de qual fala. `origem` é a MARCA de isolamento entre
  -- os ritos — é por ela que as listagens da tempestade deixam a resposta de
  -- quiz de fora. Nunca por pergunta_id (FK SET NULL, que soltaria o item para
  -- dentro da tela errada) nem por tipo_resposta: alvo sem lado (PESTEL, SWOT)
  -- responde com tipo_resposta NULL e vazaria para a fila da Coleta.
  origem           ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE',
  pergunta_id      INT NULL,
  tipo_resposta    ENUM('ESCOLHA','RENUNCIA','SITUACAO_ATUAL','TENDENCIA') NULL,
  texto            TEXT NOT NULL,
  texto_tratado    TEXT NULL,
  destino_sugerido ENUM('CENARIO','PESTEL','PORTER','SWOT','NAO_SEI') NOT NULL DEFAULT 'NAO_SEI',
  situacao         ENUM('NOVO','SELECIONADO','ACEITO','DESCARTADO','DIVIDIDO') NOT NULL DEFAULT 'NOVO',
  impacto          ENUM('ALTO','BAIXO') NULL,
  esforco          ENUM('BAIXO','ALTO') NULL,
  votos            SMALLINT NOT NULL DEFAULT 0,
  destino_tipo     ENUM('CENARIO','FATOR','ACAO','CASCATA') NULL,
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
  CONSTRAINT fk_ci_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
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
  modo            ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE',
  criado_por      INT NOT NULL,
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
  alvo_tipo    ENUM('CASCATA','CENARIO','FATOR','LIVRE') NOT NULL DEFAULT 'CASCATA',
  -- a pergunta nas palavras do condutor (o padrão vem do alvo, em App\Services\Quiz)
  enunciado    VARCHAR(255) NULL,
  -- CASCATA: a célula (driver x horizonte x eixo). Nulos nos demais alvos.
  horizonte_id INT NULL,
  driver_id    INT NULL,
  eixo_id      INT NULL,
  -- CENARIO e FATOR: a análise de diagnóstico é anual
  ano          SMALLINT NULL,
  -- FATOR: qual coluna do PESTEL/Porter/SWOT
  etapa        ENUM('PESTEL','PORTER','SWOT') NULL,
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
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_part (rodada_id, token),
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


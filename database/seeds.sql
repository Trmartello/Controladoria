-- Seeds iniciais (idempotentes) — o usuário admin é criado pelo migrate.php

INSERT INTO driver (nome, ordem) SELECT 'Aonde Jogar', 1
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Aonde Jogar');
INSERT INTO driver (nome, ordem) SELECT 'Como Vencer', 2
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Como Vencer');
INSERT INTO driver (nome, ordem) SELECT 'Envelope', 3
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Envelope');
INSERT INTO driver (nome, ordem) SELECT 'Capacidades e Recursos', 4
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Capacidades e Recursos');
INSERT INTO driver (nome, ordem) SELECT 'Iniciativas Estruturantes', 5
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Iniciativas Estruturantes');
INSERT INTO driver (nome, ordem) SELECT 'Métrica-Âncora', 6
  WHERE NOT EXISTS (SELECT 1 FROM driver WHERE nome = 'Métrica-Âncora');

INSERT INTO eixo (nome, ordem) SELECT 'Mercado', 1
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Mercado');
INSERT INTO eixo (nome, ordem) SELECT 'Portfólio', 2
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Portfólio');
INSERT INTO eixo (nome, ordem) SELECT 'Marca', 3
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Marca');
INSERT INTO eixo (nome, ordem) SELECT 'Pessoas', 4
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Pessoas');
INSERT INTO eixo (nome, ordem) SELECT 'Eficiência', 5
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Eficiência');
INSERT INTO eixo (nome, ordem) SELECT 'Financeiro', 6
  WHERE NOT EXISTS (SELECT 1 FROM eixo WHERE nome = 'Financeiro');

INSERT INTO ciclo (nome, ano_base, ano_inicio, ano_fim, status)
  SELECT '2027–2035', 2026, 2027, 2035, 'EM_ELABORACAO'
  WHERE NOT EXISTS (SELECT 1 FROM ciclo WHERE nome = '2027–2035');

INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem)
  SELECT c.id, 'H1', 2027, 2029, 'Recuperação',
    'Margem bruta antes de investimentos: recuperação da margem da rede, eficiência, armazenagem priorizada, desalavancagem.', 1
  FROM ciclo c WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM horizonte h WHERE h.ciclo_id = c.id AND h.nome = 'H1');
INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem)
  SELECT c.id, 'H2', 2030, 2032, 'Crescimento Seletivo',
    'Densidade: crescimento de market share — mais share por cooperado, não mais bandeiras.', 2
  FROM ciclo c WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM horizonte h WHERE h.ciclo_id = c.id AND h.nome = 'H2');
INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem)
  SELECT c.id, 'H3', 2033, 2035, 'Consolidação',
    'Referência Regional: Copérdia mais rentável, armazenagem própria >= 70%, autonomia financeira.', 3
  FROM ciclo c WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM horizonte h WHERE h.ciclo_id = c.id AND h.nome = 'H3');

-- Planejamento corporativo do ciclo
INSERT INTO planejamento (ciclo_id, escopo, negocio_id)
  SELECT c.id, 'CORPORATIVO', NULL FROM ciclo c WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM planejamento p
                    WHERE p.ciclo_id = c.id AND p.escopo = 'CORPORATIVO');

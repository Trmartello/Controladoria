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

-- ===== Fase 6: indicadores do planejamento corporativo (massa de validação
-- da planilha 2026 — metas ilustrativas a revisar com a controladoria) =====

INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
  SELECT p.id, 'Margem bruta antes de investimentos', '%', 'MAIOR_MELHOR', 1, h.id
  FROM planejamento p
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN horizonte h ON h.ciclo_id = c.id AND h.nome = 'H1'
  WHERE p.escopo = 'CORPORATIVO'
    AND NOT EXISTS (SELECT 1 FROM indicador i WHERE i.planejamento_id = p.id
                    AND i.nome = 'Margem bruta antes de investimentos');

INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
  SELECT p.id, 'Market share por cooperado', '%', 'MAIOR_MELHOR', 1, h.id
  FROM planejamento p
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN horizonte h ON h.ciclo_id = c.id AND h.nome = 'H2'
  WHERE p.escopo = 'CORPORATIVO'
    AND NOT EXISTS (SELECT 1 FROM indicador i WHERE i.planejamento_id = p.id
                    AND i.nome = 'Market share por cooperado');

INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
  SELECT p.id, 'Armazenagem própria', '%', 'MAIOR_MELHOR', 1, h.id
  FROM planejamento p
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN horizonte h ON h.ciclo_id = c.id AND h.nome = 'H3'
  WHERE p.escopo = 'CORPORATIVO'
    AND NOT EXISTS (SELECT 1 FROM indicador i WHERE i.planejamento_id = p.id
                    AND i.nome = 'Armazenagem própria');

INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
  SELECT p.id, 'Cobertura de juros', 'x', 'MAIOR_MELHOR', 0, NULL
  FROM planejamento p
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  WHERE p.escopo = 'CORPORATIVO'
    AND NOT EXISTS (SELECT 1 FROM indicador i WHERE i.planejamento_id = p.id
                    AND i.nome = 'Cobertura de juros');

-- Metas plurianuais (versão 1) dos indicadores acima
INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 18.0 valor UNION ALL SELECT 2028, 19.0 UNION ALL SELECT 2029, 20.0
        UNION ALL SELECT 2030, 20.5 UNION ALL SELECT 2031, 21.0 UNION ALL SELECT 2032, 21.5
        UNION ALL SELECT 2033, 22.0 UNION ALL SELECT 2034, 22.5 UNION ALL SELECT 2035, 23.0) v
  WHERE i.nome = 'Margem bruta antes de investimentos'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id
                    AND iv.ano = v.ano AND iv.tipo = 'META' AND iv.versao_meta = 1);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 52.0 valor UNION ALL SELECT 2028, 54.0 UNION ALL SELECT 2029, 56.0
        UNION ALL SELECT 2030, 59.0 UNION ALL SELECT 2031, 62.0 UNION ALL SELECT 2032, 65.0
        UNION ALL SELECT 2033, 66.0 UNION ALL SELECT 2034, 68.0 UNION ALL SELECT 2035, 70.0) v
  WHERE i.nome = 'Market share por cooperado'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id
                    AND iv.ano = v.ano AND iv.tipo = 'META' AND iv.versao_meta = 1);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 40.0 valor UNION ALL SELECT 2028, 44.0 UNION ALL SELECT 2029, 48.0
        UNION ALL SELECT 2030, 52.0 UNION ALL SELECT 2031, 56.0 UNION ALL SELECT 2032, 60.0
        UNION ALL SELECT 2033, 64.0 UNION ALL SELECT 2034, 67.0 UNION ALL SELECT 2035, 70.0) v
  WHERE i.nome = 'Armazenagem própria'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id
                    AND iv.ano = v.ano AND iv.tipo = 'META' AND iv.versao_meta = 1);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 1.5 valor UNION ALL SELECT 2028, 1.8 UNION ALL SELECT 2029, 2.0
        UNION ALL SELECT 2030, 2.2 UNION ALL SELECT 2031, 2.4 UNION ALL SELECT 2032, 2.6
        UNION ALL SELECT 2033, 2.8 UNION ALL SELECT 2034, 2.9 UNION ALL SELECT 2035, 3.0) v
  WHERE i.nome = 'Cobertura de juros'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id
                    AND iv.ano = v.ano AND iv.tipo = 'META' AND iv.versao_meta = 1);

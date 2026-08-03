-- Seeds iniciais (idempotentes) — o usuário admin é criado pelo migrate.php.
-- Cada bloco só insere quando o contexto está VAZIO (e não por nome), para um
-- registro renomeado pela interface não ser recriado no deploy seguinte.
-- Regra do parser (migrate.php): um statement por bloco, terminado em ';' no
-- fim de linha; nunca usar ';' em fim de linha nem '--' em início de linha
-- dentro de strings.

INSERT INTO driver (nome, ordem)
  SELECT v.nome, v.ordem FROM (
    SELECT 'Aonde Jogar' AS nome, 1 AS ordem
    UNION ALL SELECT 'Como Vencer', 2
    UNION ALL SELECT 'Envelope', 3
    UNION ALL SELECT 'Capacidades e Recursos', 4
    UNION ALL SELECT 'Iniciativas Estruturantes', 5
    UNION ALL SELECT 'Métrica-Âncora', 6) v
  WHERE NOT EXISTS (SELECT 1 FROM driver);

INSERT INTO eixo (nome, ordem)
  SELECT v.nome, v.ordem FROM (
    SELECT 'Mercado' AS nome, 1 AS ordem
    UNION ALL SELECT 'Portfólio', 2
    UNION ALL SELECT 'Marca', 3
    UNION ALL SELECT 'Pessoas', 4
    UNION ALL SELECT 'Eficiência', 5
    UNION ALL SELECT 'Financeiro', 6) v
  WHERE NOT EXISTS (SELECT 1 FROM eixo);

-- Negócios do campo FlagFilialNegocio do Comercial Global (códigos oficiais,
-- lista de 03/08/2026). Espelha App\Services\QlikSync::NEGOCIOS_FONTE, que é a
-- fonte da verdade; os códigos 10, 14, 15 e 16 não existem na fonte, e o 5
-- (JUROS S. COTA CAPITAL) fica de fora por ser resultado financeiro, não
-- unidade que planeja.
-- Instalação que já tem negócios cadastrados não passa por aqui: quem aplica a
-- lista nela é o passo "negócios oficiais" do migrate.php.
INSERT INTO negocio (cod_negocio, nome, origem)
  SELECT v.cod, v.nome, 'QLIK' FROM (
    SELECT '1' AS cod, 'CEREAIS' AS nome
    UNION ALL SELECT '2', 'PECUARIA'
    UNION ALL SELECT '3', 'FRUTICULTURA'
    UNION ALL SELECT '4', 'LEITE'
    UNION ALL SELECT '6', 'F. DE RACOES'
    UNION ALL SELECT '7', 'UTM'
    UNION ALL SELECT '8', 'AGROPECUARIAS'
    UNION ALL SELECT '9', 'SUPERMERCADOS'
    UNION ALL SELECT '11', 'P. COMBUSTIVEIS'
    UNION ALL SELECT '12', 'P. RESF. LEITE'
    UNION ALL SELECT '13', 'UBS'
    UNION ALL SELECT '17', 'USINA FOTOVOLTAICA') v
  WHERE NOT EXISTS (SELECT 1 FROM negocio);

INSERT INTO ciclo (nome, ano_base, ano_inicio, ano_fim, status)
  SELECT '2027–2035', 2026, 2027, 2035, 'EM_ELABORACAO'
  WHERE NOT EXISTS (SELECT 1 FROM ciclo);

INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem)
  SELECT c.id, v.nome, v.ano_inicio, v.ano_fim, v.tema, v.objetivo, v.ordem
  FROM ciclo c
  CROSS JOIN (
    SELECT 'H1' AS nome, 2027 AS ano_inicio, 2029 AS ano_fim, 'Recuperação' AS tema,
      'Margem bruta antes de investimentos: recuperação da margem da rede, eficiência, armazenagem priorizada, desalavancagem.' AS objetivo, 1 AS ordem
    UNION ALL SELECT 'H2', 2030, 2032, 'Crescimento Seletivo',
      'Densidade: crescimento de market share — mais share por cooperado, não mais bandeiras.', 2
    UNION ALL SELECT 'H3', 2033, 2035, 'Consolidação',
      'Referência Regional: Copérdia mais rentável, armazenagem própria >= 70%, autonomia financeira.', 3) v
  WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM horizonte h WHERE h.ciclo_id = c.id);

-- Planejamento corporativo do ciclo
INSERT INTO planejamento (ciclo_id, escopo, negocio_id)
  SELECT c.id, 'CORPORATIVO', NULL FROM ciclo c WHERE c.nome = '2027–2035'
    AND NOT EXISTS (SELECT 1 FROM planejamento p
                    WHERE p.ciclo_id = c.id AND p.escopo = 'CORPORATIVO');

-- ===== Fase 6: indicadores do planejamento corporativo (massa de validação
-- da planilha 2026 — metas ilustrativas a revisar com a controladoria).
-- Só insere quando o planejamento corporativo ainda não tem indicador algum. =====

INSERT INTO indicador (planejamento_id, nome, unidade, sentido, metrica_ancora, horizonte_id)
  SELECT p.id, v.nome, v.unidade, 'MAIOR_MELHOR', v.ancora, h.id
  FROM planejamento p
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  CROSS JOIN (
    SELECT 'Margem bruta antes de investimentos' AS nome, '%' AS unidade, 1 AS ancora, 'H1' AS horizonte
    UNION ALL SELECT 'Market share por cooperado', '%', 1, 'H2'
    UNION ALL SELECT 'Armazenagem própria', '%', 1, 'H3'
    UNION ALL SELECT 'Cobertura de juros', 'x', 0, NULL) v
  LEFT JOIN horizonte h ON h.ciclo_id = c.id AND h.nome = v.horizonte
  WHERE p.escopo = 'CORPORATIVO'
    AND NOT EXISTS (SELECT 1 FROM indicador i WHERE i.planejamento_id = p.id);

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
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 52.0 valor UNION ALL SELECT 2028, 54.0 UNION ALL SELECT 2029, 56.0
        UNION ALL SELECT 2030, 59.0 UNION ALL SELECT 2031, 62.0 UNION ALL SELECT 2032, 65.0
        UNION ALL SELECT 2033, 66.0 UNION ALL SELECT 2034, 68.0 UNION ALL SELECT 2035, 70.0) v
  WHERE i.nome = 'Market share por cooperado'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 40.0 valor UNION ALL SELECT 2028, 44.0 UNION ALL SELECT 2029, 48.0
        UNION ALL SELECT 2030, 52.0 UNION ALL SELECT 2031, 56.0 UNION ALL SELECT 2032, 60.0
        UNION ALL SELECT 2033, 64.0 UNION ALL SELECT 2034, 67.0 UNION ALL SELECT 2035, 70.0) v
  WHERE i.nome = 'Armazenagem própria'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id);

INSERT INTO indicador_valor (indicador_id, ano, tipo, versao_meta, valor)
  SELECT i.id, v.ano, 'META', 1, v.valor
  FROM indicador i
  JOIN planejamento p ON p.id = i.planejamento_id AND p.escopo = 'CORPORATIVO'
  JOIN ciclo c ON c.id = p.ciclo_id AND c.nome = '2027–2035'
  JOIN (SELECT 2027 ano, 1.5 valor UNION ALL SELECT 2028, 1.8 UNION ALL SELECT 2029, 2.0
        UNION ALL SELECT 2030, 2.2 UNION ALL SELECT 2031, 2.4 UNION ALL SELECT 2032, 2.6
        UNION ALL SELECT 2033, 2.8 UNION ALL SELECT 2034, 2.9 UNION ALL SELECT 2035, 3.0) v
  WHERE i.nome = 'Cobertura de juros'
    AND NOT EXISTS (SELECT 1 FROM indicador_valor iv WHERE iv.indicador_id = i.id);

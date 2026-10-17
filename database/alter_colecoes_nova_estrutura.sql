-- Coleções — nova estrutura (Etapa 1).
-- Coleção = agrupamento comercial de VARIAÇÕES (uma variação pode estar em várias coleções).
-- Apenas ADD COLUMN / CREATE TABLE — não remove colunas, não apaga nem migra dados.
-- Mantém colecoes.status e produtos.colecao_id intactos (compatibilidade).
-- Idempotente: pode ser executado mais de uma vez.
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

-- colecoes.tipo — registros existentes recebem 'especial'
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'tipo'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE colecoes
     ADD COLUMN tipo ENUM(''catalogo'',''especial'') NOT NULL DEFAULT ''especial'' AFTER nome',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- colecoes.etapa — registros existentes recebem 'planejamento'
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'etapa'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE colecoes
     ADD COLUMN etapa ENUM(''planejamento'',''desenvolvimento'',''pre_venda'',''producao'',''entrega'',''encerrada'')
       NOT NULL DEFAULT ''planejamento'' AFTER periodo_fim',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- colecoes.encerrada_em
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'encerrada_em'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE colecoes ADD COLUMN encerrada_em DATETIME NULL AFTER etapa',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- colecoes.resultado_observacao
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'resultado_observacao'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE colecoes ADD COLUMN resultado_observacao TEXT NULL AFTER encerrada_em',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- índices de apoio para listagem/filtro
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND INDEX_NAME = 'idx_colecoes_tipo'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE colecoes ADD INDEX idx_colecoes_tipo (tipo)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND INDEX_NAME = 'idx_colecoes_etapa'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE colecoes ADD INDEX idx_colecoes_etapa (etapa)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vínculo coleção ↔ variação, com o resultado daquela variação naquela coleção.
-- valor_vendido: valor exato; o arredondamento do sistema é só na exibição.
CREATE TABLE IF NOT EXISTS colecao_variacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colecao_id INT UNSIGNED NOT NULL,
  variacao_id INT UNSIGNED NOT NULL,
  qtd_vendida INT NULL,
  qtd_produzida INT NULL,
  valor_vendido DECIMAL(15,2) NULL,
  observacao TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_colecao_variacao (colecao_id, variacao_id),
  INDEX idx_colecao_variacoes_colecao (colecao_id),
  INDEX idx_colecao_variacoes_variacao (variacao_id),
  CONSTRAINT fk_colecao_variacoes_colecao
    FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_colecao_variacoes_variacao
    FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prompt 14 — Variações de produto e estoque por variação
USE papel_magenta;

CREATE TABLE IF NOT EXISTS produto_variacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  estoque INT NOT NULL DEFAULT 0,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  observacao TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variacao_codigo (codigo),
  INDEX idx_variacao_produto (produto_id),
  INDEX idx_variacao_status (status),
  CONSTRAINT fk_variacao_produto
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preparação para arquivos por variação (UI futura). NULL = arquivo do produto inteiro.
-- Idempotente: só adiciona se a coluna ainda não existir.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'arquivos_produtos'
    AND COLUMN_NAME = 'variacao_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE arquivos_produtos
     ADD COLUMN variacao_id INT UNSIGNED NULL AFTER produto_id,
     ADD INDEX idx_arquivos_variacao (variacao_id),
     ADD CONSTRAINT fk_arquivos_variacao
       FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

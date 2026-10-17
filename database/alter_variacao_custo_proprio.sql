-- Custo próprio por variação (compatível: default herdar).
-- Executar com o banco já selecionado. NÃO executar automaticamente em produção.
-- Não remove colunas nem apaga dados.

ALTER TABLE produto_variacoes
  ADD COLUMN custo_modo ENUM('herdar','proprio') NOT NULL DEFAULT 'herdar'
    AFTER observacao;

CREATE TABLE IF NOT EXISTS variacao_composicao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variacao_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  quantidade DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  modo ENUM('unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento') NOT NULL DEFAULT 'unidade',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variacao_material (variacao_id, material_id),
  INDEX idx_var_comp_material (material_id),
  CONSTRAINT fk_var_comp_variacao FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_var_comp_material FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

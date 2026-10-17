-- Custo manual/avulso na formação (produto e variação).
-- Compatível phpMyAdmin / KingHost: SEM PROCEDURE, DELIMITER, PREPARE ou SQL dinâmico.
-- Executar UMA ÚNICA VEZ, com o banco já selecionado.
-- Não remove colunas, não apaga dados (sem DELETE/TRUNCATE/DROP TABLE/UPDATE).
-- Pré-requisito: alter_variacao_custo_proprio.sql já aplicada.
--
-- FKs (schema.sql / alter_variacao_custo_proprio.sql):
--   produto_composicao.material_id  -> fk_composicao_material
--   variacao_composicao.material_id -> fk_var_comp_material
--
-- Ordem: DROP FK -> MODIFY/ADD -> ADD FK (produto); depois o mesmo em variacao.

-- ——— 1) produto_composicao ———
ALTER TABLE produto_composicao
  DROP FOREIGN KEY fk_composicao_material;

ALTER TABLE produto_composicao
  MODIFY material_id INT UNSIGNED NULL,
  ADD COLUMN descricao_manual VARCHAR(160) NULL AFTER material_id,
  ADD COLUMN preco_manual DECIMAL(14,4) NULL AFTER descricao_manual,
  MODIFY modo ENUM(
    'unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento','manual'
  ) NOT NULL DEFAULT 'unidade';

ALTER TABLE produto_composicao
  ADD CONSTRAINT fk_composicao_material
    FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE RESTRICT;

-- ——— 2) variacao_composicao ———
ALTER TABLE variacao_composicao
  DROP FOREIGN KEY fk_var_comp_material;

ALTER TABLE variacao_composicao
  MODIFY material_id INT UNSIGNED NULL,
  ADD COLUMN descricao_manual VARCHAR(160) NULL AFTER material_id,
  ADD COLUMN preco_manual DECIMAL(14,4) NULL AFTER descricao_manual,
  MODIFY modo ENUM(
    'unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento','manual'
  ) NOT NULL DEFAULT 'unidade';

ALTER TABLE variacao_composicao
  ADD CONSTRAINT fk_var_comp_material
    FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE RESTRICT;

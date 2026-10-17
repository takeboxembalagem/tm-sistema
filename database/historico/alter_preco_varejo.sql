USE papel_magenta;

ALTER TABLE produtos
  ADD COLUMN modo_preco ENUM('margem','markup','manual') NOT NULL DEFAULT 'margem' AFTER quantidade_producao,
  ADD COLUMN margem_desejada DECIMAL(8,4) NULL DEFAULT 60.0000 AFTER modo_preco,
  ADD COLUMN markup DECIMAL(10,4) NULL DEFAULT 2.0000 AFTER margem_desejada,
  ADD COLUMN preco_praticado DECIMAL(12,4) NULL AFTER markup;

INSERT INTO configuracoes (chave, valor, descricao) VALUES
('margem_padrao_varejo', '60', 'Margem padrão (%) para formação automática de preço de varejo'),
('markup_padrao', '2', 'Markup padrão para formação automática de preço de varejo')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

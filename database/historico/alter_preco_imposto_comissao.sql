USE papel_magenta;

ALTER TABLE produtos
  ADD COLUMN imposto_percentual DECIMAL(8,4) NOT NULL DEFAULT 9.5000 AFTER margem_desejada,
  ADD COLUMN comissao_percentual DECIMAL(8,4) NOT NULL DEFAULT 10.0000 AFTER imposto_percentual;

INSERT INTO configuracoes (chave, valor, descricao) VALUES
('imposto_padrao_varejo', '9.5', 'Imposto padrão (%) sobre o preço de venda no varejo'),
('comissao_padrao_varejo', '10', 'Comissão padrão (%) sobre o preço de venda no varejo')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

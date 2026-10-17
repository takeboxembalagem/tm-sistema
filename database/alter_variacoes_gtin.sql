-- GTIN nas variações: unidade individual e apresentação em pacote (ambos opcionais).
-- O produto pai não tem GTIN.
-- NÃO executar automaticamente em produção / KingHost.
-- Apenas ADD COLUMN e ADD UNIQUE KEY — não remove colunas nem altera ou apaga dados.
-- Executar uma única vez, com o banco já selecionado, quando as colunas ainda não existirem.
-- Os índices únicos aceitam vários NULL; a duplicidade entre as duas colunas é verificada pela aplicação.

ALTER TABLE produto_variacoes
  ADD COLUMN gtin_unidade VARCHAR(14) NULL
    COMMENT 'GTIN da unidade individual (texto, preserva zeros à esquerda)'
    AFTER quantidade_pacote,
  ADD COLUMN gtin_pacote VARCHAR(14) NULL
    COMMENT 'GTIN da apresentação em pacote (texto, preserva zeros à esquerda)'
    AFTER gtin_unidade,
  ADD UNIQUE KEY uq_variacao_gtin_unidade (gtin_unidade),
  ADD UNIQUE KEY uq_variacao_gtin_pacote (gtin_pacote);

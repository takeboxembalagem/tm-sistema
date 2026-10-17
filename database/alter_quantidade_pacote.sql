-- Quantidade por pacote (unidade de venda alternativa).
-- NÃO executar automaticamente em produção / KingHost.
-- Apenas ADD COLUMN — não remove colunas nem apaga dados.
-- Aplicar manualmente no ambiente LOCAL para testes, se as colunas ainda não existirem.

-- Produto: quantidade por pacote (NULL = sem pacote definido).
ALTER TABLE produtos
  ADD COLUMN quantidade_pacote INT UNSIGNED NULL
    COMMENT 'Qtd unidades por pacote; NULL = sem pacote'
    AFTER status;

-- Variação: herda do produto por padrão; pode personalizar.
ALTER TABLE produto_variacoes
  ADD COLUMN pacote_modo ENUM('herdar','proprio') NOT NULL DEFAULT 'herdar'
    COMMENT 'herdar = usa quantidade_pacote do produto; proprio = usa a da variação'
    AFTER custo_modo,
  ADD COLUMN quantidade_pacote INT UNSIGNED NULL
    COMMENT 'Só usado quando pacote_modo = proprio; NULL quando herdar ou sem pacote'
    AFTER pacote_modo;

-- Medidas de embalagem no produto: tamanho aberta + peso do pacote (kg).
-- Reutiliza a coluna existente `tamanho` como "Tamanho montada".
-- Reutiliza a coluna existente `quantidade_pacote` (sem alteração).
-- NÃO executar automaticamente em produção / KingHost.
-- Apenas ADD COLUMN — não remove colunas nem apaga dados.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

ALTER TABLE produtos
  ADD COLUMN tamanho_aberta VARCHAR(80) NULL
    COMMENT 'Tamanho da embalagem aberta (texto livre)'
    AFTER tamanho,
  ADD COLUMN peso_pacote_kg DECIMAL(10,3) NULL
    COMMENT 'Peso do pacote em kg (NULL se nao informado)'
    AFTER quantidade_pacote;

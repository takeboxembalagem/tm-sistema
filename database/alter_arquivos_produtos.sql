USE papel_magenta;

ALTER TABLE arquivos_produtos
  ADD COLUMN nome VARCHAR(180) NOT NULL DEFAULT '' AFTER tipo,
  ADD COLUMN arquivo_fisico VARCHAR(255) NOT NULL DEFAULT '' AFTER nome_original,
  ADD COLUMN extensao VARCHAR(20) NULL AFTER caminho,
  ADD COLUMN observacao TEXT NULL AFTER tamanho,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD INDEX idx_arquivos_tipo (tipo),
  ADD INDEX idx_arquivos_nome (nome);

UPDATE arquivos_produtos
SET arquivo_fisico = CASE
        WHEN caminho LIKE '%/%' THEN SUBSTRING_INDEX(caminho, '/', -1)
        ELSE caminho
    END,
    extensao = LOWER(SUBSTRING_INDEX(nome_original, '.', -1)),
    nome = CASE WHEN nome = '' OR nome IS NULL THEN nome_original ELSE nome END
WHERE arquivo_fisico = '' OR arquivo_fisico IS NULL;

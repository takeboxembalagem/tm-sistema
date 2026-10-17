USE papel_magenta;

ALTER TABLE colecoes
  ADD COLUMN codigo VARCHAR(40) NULL AFTER id,
  ADD COLUMN imagem VARCHAR(255) NULL AFTER descricao,
  ADD COLUMN observacoes TEXT NULL AFTER imagem,
  ADD UNIQUE KEY uq_colecoes_codigo (codigo);

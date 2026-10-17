USE papel_magenta;

ALTER TABLE produtos
  ADD COLUMN tamanho VARCHAR(80) NULL AFTER nome;

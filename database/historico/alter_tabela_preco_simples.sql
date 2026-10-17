-- Prompt 15 — Tabela de Preços simples (garante margem 10% em bases antigas)
USE papel_magenta;

INSERT INTO tabela_preco_margens_cfg (margem_percentual, ordem)
SELECT 10.0000, 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM tabela_preco_margens_cfg WHERE margem_percentual = 10.0000
);

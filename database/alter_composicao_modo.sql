USE papel_magenta;

ALTER TABLE produto_composicao
  ADD COLUMN modo ENUM('quantidade','aproveitamento') NOT NULL DEFAULT 'quantidade' AFTER quantidade;

CREATE OR REPLACE VIEW vw_produto_custo AS
SELECT
  p.id AS produto_id,
  COALESCE(SUM(
    CASE
      WHEN pc.modo = 'aproveitamento' THEN m.custo_atual / NULLIF(pc.quantidade, 0)
      ELSE pc.quantidade * m.custo_atual
    END
  ), 0) AS custo_total
FROM produtos p
LEFT JOIN produto_composicao pc ON pc.produto_id = p.id
LEFT JOIN materiais m ON m.id = pc.material_id AND m.status = 'ativo'
GROUP BY p.id;

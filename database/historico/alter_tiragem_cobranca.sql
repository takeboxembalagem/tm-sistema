USE papel_magenta;

ALTER TABLE produtos
  ADD COLUMN quantidade_producao INT UNSIGNED NOT NULL DEFAULT 1000 AFTER status;

ALTER TABLE materiais
  ADD COLUMN forma_cobranca ENUM('unidade','milheiro','lote','quantidade','aproveitamento') NOT NULL DEFAULT 'unidade' AFTER unidade_id;

ALTER TABLE produto_composicao
  MODIFY COLUMN modo ENUM('unidade','milheiro','lote','quantidade','aproveitamento') NOT NULL DEFAULT 'unidade';

CREATE OR REPLACE VIEW vw_produto_custo AS
SELECT
  p.id AS produto_id,
  p.quantidade_producao,
  COALESCE(SUM(
    CASE
      WHEN pc.modo = 'unidade' THEN m.custo_atual * p.quantidade_producao
      WHEN pc.modo = 'milheiro' THEN m.custo_atual * (p.quantidade_producao / 1000)
      WHEN pc.modo = 'lote' THEN m.custo_atual
      WHEN pc.modo = 'quantidade' THEN m.custo_atual * pc.quantidade * p.quantidade_producao
      WHEN pc.modo = 'aproveitamento' THEN m.custo_atual * CEILING(p.quantidade_producao / NULLIF(pc.quantidade, 0))
      ELSE 0
    END
  ), 0) AS custo_lote,
  CASE
    WHEN p.quantidade_producao > 0 THEN
      COALESCE(SUM(
        CASE
          WHEN pc.modo = 'unidade' THEN m.custo_atual * p.quantidade_producao
          WHEN pc.modo = 'milheiro' THEN m.custo_atual * (p.quantidade_producao / 1000)
          WHEN pc.modo = 'lote' THEN m.custo_atual
          WHEN pc.modo = 'quantidade' THEN m.custo_atual * pc.quantidade * p.quantidade_producao
          WHEN pc.modo = 'aproveitamento' THEN m.custo_atual * CEILING(p.quantidade_producao / NULLIF(pc.quantidade, 0))
          ELSE 0
        END
      ), 0) / p.quantidade_producao
    ELSE 0
  END AS custo_unitario
FROM produtos p
LEFT JOIN produto_composicao pc ON pc.produto_id = p.id
LEFT JOIN materiais m ON m.id = pc.material_id AND m.status = 'ativo'
GROUP BY p.id, p.quantidade_producao;

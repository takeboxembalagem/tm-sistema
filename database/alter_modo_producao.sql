-- Produção de referência: peças ou folhas + rendimento e formato da folha
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

ALTER TABLE produtos
  ADD COLUMN modo_producao ENUM('pecas','folhas') NOT NULL DEFAULT 'pecas'
    AFTER quantidade_producao,
  ADD COLUMN rendimento_folha INT UNSIGNED NULL
    AFTER modo_producao,
  ADD COLUMN folha_largura DECIMAL(10,2) NULL
    AFTER rendimento_folha,
  ADD COLUMN folha_altura DECIMAL(10,2) NULL
    AFTER folha_largura;

-- Atualiza a view de custo (opcional; o PHP já calcula sem depender dela)
CREATE OR REPLACE VIEW vw_produto_custo AS
SELECT
  p.id AS produto_id,
  p.quantidade_producao,
  COALESCE(SUM(
    CASE
      WHEN pc.modo = 'unidade' THEN m.custo_atual * (
        CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
          THEN p.quantidade_producao * p.rendimento_folha
          ELSE p.quantidade_producao END
      )
      WHEN pc.modo = 'milheiro' THEN m.custo_atual * (
        (
          CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
            THEN p.quantidade_producao * p.rendimento_folha
            ELSE p.quantidade_producao END
        ) / 1000
      )
      WHEN pc.modo = 'lote' THEN m.custo_atual
      WHEN pc.modo = 'quantidade' THEN m.custo_atual * pc.quantidade * (
        CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
          THEN p.quantidade_producao * p.rendimento_folha
          ELSE p.quantidade_producao END
      )
      WHEN pc.modo = 'aproveitamento' THEN
        CASE
          WHEN p.modo_producao = 'folhas' THEN m.custo_atual * p.quantidade_producao
          ELSE m.custo_atual * CEILING(p.quantidade_producao / NULLIF(pc.quantidade, 0))
        END
      ELSE 0
    END
  ), 0) AS custo_lote,
  CASE
    WHEN (
      CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
        THEN p.quantidade_producao * p.rendimento_folha
        ELSE p.quantidade_producao END
    ) > 0 THEN
      COALESCE(SUM(
        CASE
          WHEN pc.modo = 'unidade' THEN m.custo_atual * (
            CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
              THEN p.quantidade_producao * p.rendimento_folha
              ELSE p.quantidade_producao END
          )
          WHEN pc.modo = 'milheiro' THEN m.custo_atual * (
            (
              CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
                THEN p.quantidade_producao * p.rendimento_folha
                ELSE p.quantidade_producao END
            ) / 1000
          )
          WHEN pc.modo = 'lote' THEN m.custo_atual
          WHEN pc.modo = 'quantidade' THEN m.custo_atual * pc.quantidade * (
            CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
              THEN p.quantidade_producao * p.rendimento_folha
              ELSE p.quantidade_producao END
          )
          WHEN pc.modo = 'aproveitamento' THEN
            CASE
              WHEN p.modo_producao = 'folhas' THEN m.custo_atual * p.quantidade_producao
              ELSE m.custo_atual * CEILING(p.quantidade_producao / NULLIF(pc.quantidade, 0))
            END
          ELSE 0
        END
      ), 0) / (
        CASE WHEN p.modo_producao = 'folhas' AND IFNULL(p.rendimento_folha, 0) > 0
          THEN p.quantidade_producao * p.rendimento_folha
          ELSE p.quantidade_producao END
      )
    ELSE 0
  END AS custo_unitario
FROM produtos p
LEFT JOIN produto_composicao pc ON pc.produto_id = p.id
LEFT JOIN materiais m ON m.id = pc.material_id AND m.status = 'ativo'
GROUP BY p.id, p.quantidade_producao, p.modo_producao, p.rendimento_folha;

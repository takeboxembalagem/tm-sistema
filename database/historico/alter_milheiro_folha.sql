-- OBSOLETO para a formação de custo do produto.
-- A modalidade NÃO multiplica mais o preço na formação (preço = valor absoluto).
-- NÃO executar este arquivo na KingHost / produção.
-- Mantido apenas como histórico.

-- Novo modo de cobrança: milheiro de folhas (milheiro permanece = milheiro de peças).
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).
-- NÃO reexecutar se o ENUM já contiver milheiro_folha.

ALTER TABLE materiais
  MODIFY COLUMN forma_cobranca
    ENUM('unidade','milheiro','milheiro_folha','lote','quantidade','aproveitamento')
    NOT NULL DEFAULT 'unidade';

ALTER TABLE produto_composicao
  MODIFY COLUMN modo
    ENUM('unidade','milheiro','milheiro_folha','lote','quantidade','aproveitamento')
    NOT NULL DEFAULT 'unidade';

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
      WHEN pc.modo = 'milheiro_folha' THEN m.custo_atual * (
        (
          CASE
            WHEN p.modo_producao = 'folhas' THEN p.quantidade_producao
            WHEN IFNULL(p.rendimento_folha, 0) > 0 THEN CEILING(p.quantidade_producao / p.rendimento_folha)
            ELSE 0
          END
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
          WHEN pc.modo = 'milheiro_folha' THEN m.custo_atual * (
            (
              CASE
                WHEN p.modo_producao = 'folhas' THEN p.quantidade_producao
                WHEN IFNULL(p.rendimento_folha, 0) > 0 THEN CEILING(p.quantidade_producao / p.rendimento_folha)
                ELSE 0
              END
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

-- OBS.: A formação do produto usa o preço do custo como VALOR ABSOLUTO.
-- Modalidade (unidade/folha/milheiro/lote) é só classificação no módulo Custos.
-- Este arquivo NÃO é necessário para corrigir o cálculo absoluto (feito em PHP).
--
-- Opcional / legado: só se ainda precisar normalizar ENUMs ou forçar produção em folhas.
-- NÃO executar na KingHost sem revisão explícita.
-- Preferência atual: NÃO usar esta migração nem alter_milheiro_folha.sql.

-- View alinhada ao cálculo absoluto (opcional; o PHP não depende dela)
CREATE OR REPLACE VIEW vw_produto_custo AS
SELECT
  p.id AS produto_id,
  p.quantidade_producao,
  COALESCE(SUM(m.custo_atual), 0) AS custo_lote,
  CASE
    WHEN (p.quantidade_producao * IFNULL(NULLIF(p.rendimento_folha, 0), 1)) > 0 THEN
      COALESCE(SUM(m.custo_atual), 0) / (p.quantidade_producao * IFNULL(NULLIF(p.rendimento_folha, 0), 1))
    ELSE 0
  END AS custo_unitario
FROM produtos p
LEFT JOIN produto_composicao pc ON pc.produto_id = p.id
LEFT JOIN materiais m ON m.id = pc.material_id AND m.status = 'ativo'
GROUP BY p.id, p.quantidade_producao, p.rendimento_folha;

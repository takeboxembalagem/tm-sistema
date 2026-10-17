-- =============================================================================
-- Papel Magenta — Limpeza de dados COMERCIAIS / DEMO
-- =============================================================================
-- ATENÇÃO:
-- - NÃO execute este arquivo na KingHost em produção com dados reais.
-- - Use apenas em cópia local / banco descartável, se precisar esvaziar dados
--   de desenvolvimento mantendo a estrutura e o seed estrutural.
-- - Depois da limpeza, rode: php scripts/criar_admin.php
--
-- Preserva:
--   categorias, categorias_materiais, unidades_medida, faixas_preco,
--   configuracoes, tabela_preco_config, tabela_preco_margens_cfg
--
-- Zera (dados comerciais / demo / históricos):
--   produtos, variações, materiais, composições, coleções, arquivos,
--   históricos, logs, usuários, tabelas comerciais legadas, qtd cfg
-- =============================================================================

USE papel_magenta;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE arquivos_produtos;
TRUNCATE TABLE produto_composicao;
TRUNCATE TABLE produto_variacoes;
TRUNCATE TABLE historico_precos;
TRUNCATE TABLE historico_custos;
TRUNCATE TABLE logs_alteracoes;
TRUNCATE TABLE precos_produtos;
TRUNCATE TABLE tabela_preco_quantidades;
TRUNCATE TABLE tabela_preco_produtos;
TRUNCATE TABLE tabela_preco_margens;
TRUNCATE TABLE tabelas_preco;
TRUNCATE TABLE tabela_preco_qtd_cfg;
TRUNCATE TABLE produtos;
TRUNCATE TABLE materiais;
TRUNCATE TABLE colecoes;
TRUNCATE TABLE usuarios;

SET FOREIGN_KEY_CHECKS = 1;

-- AUTO_INCREMENT reinicia com TRUNCATE nas tabelas acimaidas.
-- Tabelas estruturais preservadas mantêm seus IDs.

-- =============================================================================
-- Verificação pós-instalação limpa (somente leitura)
-- =============================================================================
-- Executar com o banco de produção JÁ selecionado no phpMyAdmin/cliente.
-- Não cria nem seleciona banco.

SELECT 'usuarios' AS tabela, COUNT(*) AS total FROM usuarios
UNION ALL SELECT 'produtos', COUNT(*) FROM produtos
UNION ALL SELECT 'produto_variacoes', COUNT(*) FROM produto_variacoes
UNION ALL SELECT 'materiais', COUNT(*) FROM materiais
UNION ALL SELECT 'produto_composicao', COUNT(*) FROM produto_composicao
UNION ALL SELECT 'colecoes', COUNT(*) FROM colecoes
UNION ALL SELECT 'arquivos_produtos', COUNT(*) FROM arquivos_produtos
UNION ALL SELECT 'historico_custos', COUNT(*) FROM historico_custos
UNION ALL SELECT 'historico_precos', COUNT(*) FROM historico_precos
UNION ALL SELECT 'logs_alteracoes', COUNT(*) FROM logs_alteracoes
UNION ALL SELECT 'categorias', COUNT(*) FROM categorias
UNION ALL SELECT 'categorias_materiais', COUNT(*) FROM categorias_materiais
UNION ALL SELECT 'unidades_medida', COUNT(*) FROM unidades_medida
UNION ALL SELECT 'configuracoes', COUNT(*) FROM configuracoes
UNION ALL SELECT 'tabela_preco_margens_cfg', COUNT(*) FROM tabela_preco_margens_cfg
UNION ALL SELECT 'agenda_planos', COUNT(*) FROM agenda_planos
UNION ALL SELECT 'agenda_tarefas', COUNT(*) FROM agenda_tarefas;

-- Estrutura atual da Agenda: deve listar plano_id, data_inicio, responsavel_id e em_andamento (4 linhas).
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_tarefas'
  AND COLUMN_NAME IN ('plano_id', 'data_inicio', 'responsavel_id', 'em_andamento')
ORDER BY COLUMN_NAME;

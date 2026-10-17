-- =============================================================================
-- T&M — seed.sql
-- =============================================================================
-- Instalação limpa / produção: dados estruturais apenas.
-- Seed atual. (database/historico/seed_estrutura.sql é uma cópia antiga — não usar.)
--
-- Importar com o banco de produção JÁ selecionado no phpMyAdmin/cliente.
-- Não cria nem seleciona banco (hospedagem compartilhada sem privilégio
-- para CREATE DATABASE / USE de outro schema).
--
-- NÃO inclui administrador. Após importar schema + este arquivo:
--   php scripts/criar_admin.php
--
-- Demo local opcional (nunca em produção):
--   SOURCE database/historico/seed_demo.sql;
-- =============================================================================

INSERT INTO categorias (nome, descricao, status) VALUES
('Caixas', 'Embalagens tipo caixa', 'ativo'),
('Sacolas', 'Sacolas prontas', 'ativo'),
('Embalagens', 'Demais embalagens', 'ativo'),
('Kits', 'Conjuntos e kits', 'ativo'),
('Outros', 'Itens diversos', 'ativo');

INSERT INTO categorias_materiais (nome, status) VALUES
('Papel', 'ativo'),
('Acabamento', 'ativo'),
('Serviço', 'ativo'),
('Insumo', 'ativo'),
('Mão de obra', 'ativo'),
('Outros', 'ativo');

INSERT INTO unidades_medida (nome, sigla, status) VALUES
('Folha', 'folha', 'ativo'),
('Unidade', 'unidade', 'ativo'),
('Quilograma', 'kg', 'ativo'),
('Metro', 'metro', 'ativo'),
('Hora', 'hora', 'ativo'),
('Serviço', 'serviço', 'ativo'),
('Outro', 'outro', 'ativo');

INSERT INTO faixas_preco (tipo, nome, qtd_min, qtd_max, multiplicador, ordem, status) VALUES
('varejo', 'Varejo', 1, 9, 3.0000, 1, 'ativo'),
('atacado', '10 a 49', 10, 49, 2.4000, 2, 'ativo'),
('atacado', '50 a 99', 50, 99, 2.1000, 3, 'ativo'),
('atacado', '100 a 499', 100, 499, 1.8500, 4, 'ativo'),
('atacado', '500+', 500, NULL, 1.6500, 5, 'ativo');

INSERT INTO configuracoes (chave, valor, descricao) VALUES
('margem_minima_varejo', '40', 'Alerta quando a margem de varejo ficar abaixo deste percentual'),
('margem_minima_atacado', '25', 'Alerta quando a margem de atacado ficar abaixo deste percentual'),
('margem_padrao_varejo', '60', 'Margem padrão (%) para formação automática de preço de varejo'),
('markup_padrao', '2', 'Markup padrão para formação automática de preço de varejo'),
('imposto_padrao_varejo', '9.5', 'Imposto padrão (%) sobre o preço de venda no varejo'),
('comissao_padrao_varejo', '10', 'Comissão padrão (%) sobre o preço de venda no varejo'),
('moeda', 'BRL', 'Código da moeda');

INSERT INTO tabela_preco_config (id, imposto_percentual, incluir_comissao, comissao_percentual)
VALUES (1, 9.5000, 0, 10.0000)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO tabela_preco_margens_cfg (margem_percentual, ordem)
SELECT v.m, v.o FROM (
  SELECT 10.0000 AS m, 0 AS o UNION ALL
  SELECT 20.0000, 1 UNION ALL
  SELECT 30.0000, 2 UNION ALL
  SELECT 40.0000, 3 UNION ALL
  SELECT 50.0000, 4 UNION ALL
  SELECT 60.0000, 5
) v
WHERE NOT EXISTS (SELECT 1 FROM tabela_preco_margens_cfg LIMIT 1);

-- =============================================================================
-- Papel Magenta — Seed DEMO (apenas desenvolvimento local — NÃO usar em produção)
-- =============================================================================
-- Contém coleções de exemplo. Produtos/materiais/arquivos de teste costumam
-- ser criados pela interface e NÃO devem ir para a KingHost.
--
-- Uso local opcional (após schema + seed_estrutura + criar_admin):
--   SOURCE database/seed_demo.sql;
-- =============================================================================

USE papel_magenta;

INSERT INTO colecoes (nome, descricao, periodo_inicio, periodo_fim, status) VALUES
('Natal 2026', 'Coleção natalina (demo)', '2026-11-01', '2026-12-31', 'ativo'),
('Dia das Mães 2027', 'Coleção Dia das Mães (demo)', '2027-04-01', '2027-05-15', 'ativo'),
('Dia dos Namorados 2027', 'Coleção Dia dos Namorados (demo)', '2027-05-16', '2027-06-20', 'ativo');

-- Agenda — tarefas gerais do dia a dia (independente de coleções, produtos e estoque).
-- Apenas CREATE TABLE — não altera nem apaga dados existentes. Sem chaves estrangeiras.
-- Resumos (hoje/atrasadas/próximos 7 dias) são calculados a partir desta tabela.
-- Idempotente: pode ser executado mais de uma vez.
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

CREATE TABLE IF NOT EXISTS agenda_tarefas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  data_tarefa DATE NOT NULL,
  prioridade ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
  observacao TEXT NULL,
  concluida TINYINT(1) NOT NULL DEFAULT 0,
  concluida_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agenda_tarefas_data (data_tarefa),
  INDEX idx_agenda_tarefas_concluida (concluida),
  INDEX idx_agenda_tarefas_prioridade (prioridade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

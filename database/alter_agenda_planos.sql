-- Agenda — planos (agrupam subtarefas de agenda_tarefas em etapa futura).
-- Apenas CREATE TABLE — não altera nem apaga dados existentes e não mexe em outras tabelas.
-- Progresso NÃO é armazenado: será calculado a partir das subtarefas.
-- data_inicio <= data_fim é validado na aplicação.
-- Sem IF NOT EXISTS: falha se a tabela já existir (executar uma única vez).
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

CREATE TABLE agenda_planos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  observacao TEXT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  responsavel_id INT UNSIGNED NULL,
  status ENUM('planejado','em_andamento','pausado','concluido','cancelado') NOT NULL DEFAULT 'planejado',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agenda_planos_status (status),
  INDEX idx_agenda_planos_data_fim (data_fim),
  INDEX idx_agenda_planos_responsavel (responsavel_id),
  CONSTRAINT fk_agenda_planos_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

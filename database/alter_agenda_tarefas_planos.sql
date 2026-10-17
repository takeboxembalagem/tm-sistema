-- Agenda — campos de subtarefa em agenda_tarefas (vínculo com agenda_planos).
-- Apenas ALTER TABLE ... ADD — não altera nem apaga colunas/dados existentes e não mexe em outras tabelas.
-- Requer agenda_planos (database/alter_agenda_planos.sql) já criada.
-- Tarefas existentes ficam válidas: plano_id/responsavel_id/data_inicio NULL e em_andamento 0.
-- Situação derivada (sem coluna status): concluida=1 → concluída; em_andamento=1 → em andamento; senão pendente.
-- data_inicio <= data_tarefa é validado na aplicação.
-- Plano com subtarefas não pode ser excluído (ON DELETE RESTRICT).
-- Sem IF NOT EXISTS: falha se algum campo já existir (executar uma única vez).
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

ALTER TABLE agenda_tarefas
  ADD COLUMN plano_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN data_inicio DATE NULL AFTER titulo,
  ADD COLUMN responsavel_id INT UNSIGNED NULL AFTER prioridade,
  ADD COLUMN em_andamento TINYINT(1) NOT NULL DEFAULT 0 AFTER observacao,
  ADD INDEX idx_agenda_tarefas_plano (plano_id),
  ADD INDEX idx_agenda_tarefas_responsavel (responsavel_id),
  ADD CONSTRAINT fk_agenda_tarefas_plano FOREIGN KEY (plano_id) REFERENCES agenda_planos(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_agenda_tarefas_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL;

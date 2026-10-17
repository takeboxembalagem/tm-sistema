-- Coleções — planejamento (checklist de tarefas por coleção).
-- Apenas CREATE TABLE — não altera nem apaga dados existentes.
-- Totais (total/concluídas/pendentes) são calculados a partir desta tabela.
-- Idempotente: pode ser executado mais de uma vez.
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).
-- Requer a tabela colecoes.

CREATE TABLE IF NOT EXISTS colecao_tarefas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colecao_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  data_prazo DATE NULL,
  concluida TINYINT(1) NOT NULL DEFAULT 0,
  concluida_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_colecao_tarefas_colecao (colecao_id),
  CONSTRAINT fk_colecao_tarefas_colecao
    FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

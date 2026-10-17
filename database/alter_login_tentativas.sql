-- Limite de tentativas de login (falhas por e-mail e por IP).
-- Apenas CREATE TABLE — não altera nem apaga dados existentes, não toca em usuarios. Sem chaves estrangeiras.
-- Uma linha por chave: tipo = 'email' (e-mail digitado no login, mesmo e-mail inexistente) ou
-- tipo = 'ip' (REMOTE_ADDR). Não guarda senha, hash, token, cookie nem sessão.
-- chave usa utf8mb4_unicode_ci, a mesma comparação de usuarios.email: variações de maiúsculas
-- contam como o mesmo e-mail, exatamente como no login.
-- Idempotente: pode ser executado mais de uma vez.
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

CREATE TABLE IF NOT EXISTS login_tentativas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('email','ip') NOT NULL,
  chave VARCHAR(180) NOT NULL,
  tentativas INT UNSIGNED NOT NULL DEFAULT 0,
  janela_inicio DATETIME NOT NULL,
  bloqueado_ate DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_tentativas_tipo_chave (tipo, chave),
  INDEX idx_login_tentativas_janela (janela_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

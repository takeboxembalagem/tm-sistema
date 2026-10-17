-- Perfis de acesso: admin | usuario
-- Executar uma vez no banco existente (local/produção).
-- Não apaga dados. Usuários existentes passam a admin.

ALTER TABLE usuarios
  ADD COLUMN perfil ENUM('admin', 'usuario') NOT NULL DEFAULT 'usuario'
  AFTER status;

UPDATE usuarios SET perfil = 'admin';

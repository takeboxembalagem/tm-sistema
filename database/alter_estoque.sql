-- Módulo Estoque (peças e papéis) — separado do estoque de variações de produto.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

CREATE TABLE IF NOT EXISTS estoque_pecas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(180) NOT NULL,
  tipo ENUM('tampa','fundo','outra') NOT NULL DEFAULT 'outra',
  produto_id INT UNSIGNED NULL,
  cor VARCHAR(80) NULL,
  estampa VARCHAR(120) NULL,
  quantidade DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  unidade VARCHAR(20) NOT NULL DEFAULT 'un',
  localizacao VARCHAR(120) NULL,
  imagem VARCHAR(255) NULL,
  observacao TEXT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_estoque_pecas_codigo (codigo),
  INDEX idx_estoque_pecas_status (status),
  INDEX idx_estoque_pecas_nome (nome),
  INDEX idx_estoque_pecas_tipo (tipo),
  INDEX idx_estoque_pecas_produto (produto_id),
  CONSTRAINT fk_estoque_pecas_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estoque_papeis (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(180) NOT NULL,
  cor VARCHAR(80) NULL,
  formato VARCHAR(80) NULL,
  gramatura VARCHAR(40) NULL,
  quantidade DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  unidade VARCHAR(20) NOT NULL DEFAULT 'folha',
  localizacao VARCHAR(120) NULL,
  imagem VARCHAR(255) NULL,
  observacao TEXT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_estoque_papeis_codigo (codigo),
  INDEX idx_estoque_papeis_status (status),
  INDEX idx_estoque_papeis_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estoque_pecas_movimentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  peca_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','saida','ajuste') NOT NULL,
  quantidade DECIMAL(12,4) NOT NULL,
  saldo_anterior DECIMAL(12,4) NULL,
  saldo_apos DECIMAL(12,4) NOT NULL,
  observacao VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_estoque_pecas_mov_peca (peca_id, created_at),
  CONSTRAINT fk_estoque_pecas_mov_peca FOREIGN KEY (peca_id) REFERENCES estoque_pecas(id) ON DELETE CASCADE,
  CONSTRAINT fk_estoque_pecas_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estoque_papeis_movimentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  papel_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','saida','ajuste') NOT NULL,
  quantidade DECIMAL(12,4) NOT NULL,
  saldo_anterior DECIMAL(12,4) NULL,
  saldo_apos DECIMAL(12,4) NOT NULL,
  observacao VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_estoque_papeis_mov_papel (papel_id, created_at),
  CONSTRAINT fk_estoque_papeis_mov_papel FOREIGN KEY (papel_id) REFERENCES estoque_papeis(id) ON DELETE CASCADE,
  CONSTRAINT fk_estoque_papeis_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

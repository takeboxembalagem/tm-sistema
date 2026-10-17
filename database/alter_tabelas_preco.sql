USE papel_magenta;

CREATE TABLE IF NOT EXISTS tabelas_preco (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  descricao TEXT NULL,
  colecao_id INT UNSIGNED NULL,
  imposto_percentual DECIMAL(8,4) NOT NULL DEFAULT 9.5000,
  incluir_comissao TINYINT(1) NOT NULL DEFAULT 0,
  comissao_percentual DECIMAL(8,4) NOT NULL DEFAULT 10.0000,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tabelas_preco_status (status),
  INDEX idx_tabelas_preco_nome (nome),
  CONSTRAINT fk_tabelas_preco_colecao FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tabela_preco_margens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tabela_id INT UNSIGNED NOT NULL,
  margem_percentual DECIMAL(8,4) NOT NULL,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tabela_margem (tabela_id, margem_percentual),
  INDEX idx_tabela_margens_ordem (tabela_id, ordem),
  CONSTRAINT fk_tabela_margens_tabela FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tabela_preco_produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tabela_id INT UNSIGNED NOT NULL,
  produto_id INT UNSIGNED NOT NULL,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tabela_produto (tabela_id, produto_id),
  INDEX idx_tabela_produtos_ordem (tabela_id, ordem),
  CONSTRAINT fk_tabela_produtos_tabela FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE,
  CONSTRAINT fk_tabela_produtos_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tabela_preco_quantidades (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tabela_id INT UNSIGNED NOT NULL,
  produto_id INT UNSIGNED NOT NULL,
  quantidade INT UNSIGNED NOT NULL,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tabela_produto_qtd (tabela_id, produto_id, quantidade),
  INDEX idx_tabela_qtd_ordem (tabela_id, produto_id, ordem),
  CONSTRAINT fk_tabela_qtd_tabela FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE,
  CONSTRAINT fk_tabela_qtd_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

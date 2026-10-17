-- Empresa — cadastro central (registro único), contas bancárias, chaves PIX e documentos.
-- Apenas CREATE TABLE — não altera nem apaga dados existentes e não mexe em outras tabelas.
-- Idempotente: pode ser executado mais de uma vez.
-- Não armazena senhas, tokens ou qualquer credencial bancária.
-- NÃO executar automaticamente em produção / KingHost.
-- Executar com o banco já selecionado (KingHost / phpMyAdmin).

-- Registro único: a aplicação sempre usa id = 1.
CREATE TABLE IF NOT EXISTS empresa (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  razao_social VARCHAR(200) NOT NULL,
  nome_fantasia VARCHAR(200) NULL,
  cnpj VARCHAR(18) NULL,
  inscricao_estadual VARCHAR(30) NULL,
  inscricao_municipal VARCHAR(30) NULL,
  cnae_principal VARCHAR(150) NULL,
  regime_tributario VARCHAR(30) NULL,
  data_abertura DATE NULL,
  cep VARCHAR(9) NULL,
  endereco VARCHAR(200) NULL,
  numero VARCHAR(20) NULL,
  complemento VARCHAR(100) NULL,
  bairro VARCHAR(100) NULL,
  cidade VARCHAR(100) NULL,
  estado CHAR(2) NULL,
  telefone VARCHAR(30) NULL,
  whatsapp VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  site VARCHAR(200) NULL,
  pessoa_contato VARCHAR(120) NULL,
  observacoes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_contas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  banco VARCHAR(100) NOT NULL,
  agencia VARCHAR(20) NOT NULL,
  conta VARCHAR(30) NOT NULL,
  tipo_conta ENUM('corrente','poupanca','outro') NOT NULL DEFAULT 'corrente',
  titular VARCHAR(150) NULL,
  documento_titular VARCHAR(20) NULL,
  observacao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_empresa_contas_empresa (empresa_id),
  CONSTRAINT fk_empresa_contas_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_pix (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  tipo_chave ENUM('cnpj','telefone','email','aleatoria','outro') NOT NULL,
  chave VARCHAR(150) NOT NULL,
  identificacao VARCHAR(100) NULL,
  banco VARCHAR(100) NULL,
  observacao VARCHAR(255) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_empresa_pix_empresa (empresa_id),
  CONSTRAINT fk_empresa_pix_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arquivos ficam em uploads/ (fora do document root); acesso só pela rota autenticada.
CREATE TABLE IF NOT EXISTS empresa_documentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nome VARCHAR(150) NOT NULL,
  tipo_documento ENUM('cnpj','contrato_social','alteracao_contratual','inscricao_estadual','inscricao_municipal','certidao','comprovante_bancario','outro') NOT NULL DEFAULT 'outro',
  nome_arquivo VARCHAR(190) NOT NULL,
  caminho_arquivo VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  tamanho INT UNSIGNED NOT NULL,
  observacao VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_empresa_documentos_empresa (empresa_id),
  INDEX idx_empresa_documentos_tipo (tipo_documento),
  CONSTRAINT fk_empresa_documentos_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

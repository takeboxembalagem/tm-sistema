-- T&M — Gestão de Produtos e Preços
-- Banco inicial (MySQL/MariaDB, utf8mb4)
-- Importar com o banco de produção JÁ selecionado no phpMyAdmin/cliente.
-- Não cria nem seleciona banco (hospedagem compartilhada sem CREATE DATABASE).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  perfil ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Falhas de login por e-mail digitado e por IP (limite de tentativas). Não guarda senha, hash nem sessão.
CREATE TABLE login_tentativas (
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

CREATE TABLE categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  descricao VARCHAR(255) NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categorias_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE colecoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NULL,
  nome VARCHAR(120) NOT NULL,
  tipo ENUM('catalogo','especial') NOT NULL DEFAULT 'especial',
  descricao TEXT NULL,
  imagem VARCHAR(255) NULL,
  observacoes TEXT NULL,
  periodo_inicio DATE NULL,
  periodo_fim DATE NULL,
  etapa ENUM('planejamento','desenvolvimento','pre_venda','producao','entrega','encerrada') NOT NULL DEFAULT 'planejamento',
  encerrada_em DATETIME NULL,
  resultado_observacao TEXT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_colecoes_codigo (codigo),
  INDEX idx_colecoes_status (status),
  INDEX idx_colecoes_nome (nome),
  INDEX idx_colecoes_tipo (tipo),
  INDEX idx_colecoes_etapa (etapa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_interno VARCHAR(40) NOT NULL,
  nome VARCHAR(180) NOT NULL,
  tamanho VARCHAR(80) NULL,
  tamanho_aberta VARCHAR(80) NULL COMMENT 'Tamanho da embalagem aberta (texto livre)',
  descricao TEXT NULL,
  categoria_id INT UNSIGNED NULL,
  colecao_id INT UNSIGNED NULL,
  foto_principal VARCHAR(255) NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  quantidade_pacote INT UNSIGNED NULL COMMENT 'Qtd unidades por pacote; NULL = sem pacote',
  peso_pacote_kg DECIMAL(10,3) NULL COMMENT 'Peso do pacote em kg (NULL se nao informado)',
  quantidade_producao INT UNSIGNED NOT NULL DEFAULT 1000,
  modo_producao ENUM('pecas','folhas') NOT NULL DEFAULT 'pecas',
  rendimento_folha INT UNSIGNED NULL,
  folha_largura DECIMAL(10,2) NULL,
  folha_altura DECIMAL(10,2) NULL,
  modo_preco ENUM('margem','markup','manual') NOT NULL DEFAULT 'margem',
  margem_desejada DECIMAL(8,4) NULL DEFAULT 60.0000,
  imposto_percentual DECIMAL(8,4) NOT NULL DEFAULT 9.5000,
  comissao_percentual DECIMAL(8,4) NOT NULL DEFAULT 10.0000,
  markup DECIMAL(10,4) NULL DEFAULT 2.0000,
  preco_praticado DECIMAL(12,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_produtos_codigo (codigo_interno),
  INDEX idx_produtos_nome (nome),
  INDEX idx_produtos_status (status),
  INDEX idx_produtos_categoria (categoria_id),
  INDEX idx_produtos_colecao (colecao_id),
  CONSTRAINT fk_produtos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
  CONSTRAINT fk_produtos_colecao FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_materiais (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  UNIQUE KEY uq_cat_materiais_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE unidades_medida (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(40) NOT NULL,
  sigla VARCHAR(20) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  UNIQUE KEY uq_unidades_sigla (sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE materiais (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(180) NOT NULL,
  categoria_material_id INT UNSIGNED NULL,
  unidade_id INT UNSIGNED NULL,
  forma_cobranca ENUM('unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento') NOT NULL DEFAULT 'unidade',
  custo_atual DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  fornecedor VARCHAR(180) NULL,
  observacao TEXT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_materiais_nome (nome),
  INDEX idx_materiais_status (status),
  CONSTRAINT fk_materiais_categoria FOREIGN KEY (categoria_material_id) REFERENCES categorias_materiais(id) ON DELETE SET NULL,
  CONSTRAINT fk_materiais_unidade FOREIGN KEY (unidade_id) REFERENCES unidades_medida(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produto_composicao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NULL,
  descricao_manual VARCHAR(160) NULL,
  preco_manual DECIMAL(14,4) NULL,
  quantidade DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  modo ENUM('unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento','manual') NOT NULL DEFAULT 'unidade',
  UNIQUE KEY uq_produto_material (produto_id, material_id),
  INDEX idx_composicao_material (material_id),
  CONSTRAINT fk_composicao_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  CONSTRAINT fk_composicao_material FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produto_variacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  estoque INT NOT NULL DEFAULT 0,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  observacao TEXT NULL,
  custo_modo ENUM('herdar','proprio') NOT NULL DEFAULT 'herdar',
  pacote_modo ENUM('herdar','proprio') NOT NULL DEFAULT 'herdar',
  quantidade_pacote INT UNSIGNED NULL COMMENT 'Só usado quando pacote_modo = proprio',
  gtin_unidade VARCHAR(14) NULL COMMENT 'GTIN da unidade individual (texto, preserva zeros à esquerda)',
  gtin_pacote VARCHAR(14) NULL COMMENT 'GTIN da apresentação em pacote (texto, preserva zeros à esquerda)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variacao_codigo (codigo),
  UNIQUE KEY uq_variacao_gtin_unidade (gtin_unidade),
  UNIQUE KEY uq_variacao_gtin_pacote (gtin_pacote),
  INDEX idx_variacao_produto (produto_id),
  INDEX idx_variacao_status (status),
  CONSTRAINT fk_variacao_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo coleção ↔ variação (N:N), com o resultado da variação naquela coleção.
CREATE TABLE colecao_variacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colecao_id INT UNSIGNED NOT NULL,
  variacao_id INT UNSIGNED NOT NULL,
  qtd_vendida INT NULL,
  qtd_produzida INT NULL,
  valor_vendido DECIMAL(15,2) NULL,
  observacao TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_colecao_variacao (colecao_id, variacao_id),
  INDEX idx_colecao_variacoes_colecao (colecao_id),
  INDEX idx_colecao_variacoes_variacao (variacao_id),
  CONSTRAINT fk_colecao_variacoes_colecao FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_colecao_variacoes_variacao FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planejamento da coleção (checklist). Não altera a etapa da coleção.
CREATE TABLE colecao_tarefas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colecao_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  data_prazo DATE NULL,
  concluida TINYINT(1) NOT NULL DEFAULT 0,
  concluida_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_colecao_tarefas_colecao (colecao_id),
  CONSTRAINT fk_colecao_tarefas_colecao FOREIGN KEY (colecao_id) REFERENCES colecoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agenda: planos/projetos que agrupam subtarefas de agenda_tarefas. Progresso é calculado, não armazenado.
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

-- Agenda: tarefas gerais do dia a dia e subtarefas de plano (plano_id). Independente de coleções (não usa colecao_tarefas).
-- Situação derivada: concluida=1 → concluída; em_andamento=1 → em andamento; senão pendente.
-- Plano com subtarefas não pode ser excluído (ON DELETE RESTRICT).
CREATE TABLE agenda_tarefas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plano_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  data_inicio DATE NULL,
  data_tarefa DATE NOT NULL,
  prioridade ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
  responsavel_id INT UNSIGNED NULL,
  observacao TEXT NULL,
  em_andamento TINYINT(1) NOT NULL DEFAULT 0,
  concluida TINYINT(1) NOT NULL DEFAULT 0,
  concluida_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_agenda_tarefas_data (data_tarefa),
  INDEX idx_agenda_tarefas_concluida (concluida),
  INDEX idx_agenda_tarefas_prioridade (prioridade),
  INDEX idx_agenda_tarefas_plano (plano_id),
  INDEX idx_agenda_tarefas_responsavel (responsavel_id),
  CONSTRAINT fk_agenda_tarefas_plano FOREIGN KEY (plano_id) REFERENCES agenda_planos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_agenda_tarefas_responsavel FOREIGN KEY (responsavel_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresa: cadastro central (registro único, id = 1), contas, PIX e documentos.
-- Não armazena senhas, tokens ou credenciais bancárias.
CREATE TABLE empresa (
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

CREATE TABLE empresa_contas (
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

CREATE TABLE empresa_pix (
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

CREATE TABLE empresa_documentos (
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

CREATE TABLE variacao_composicao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variacao_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NULL,
  descricao_manual VARCHAR(160) NULL,
  preco_manual DECIMAL(14,4) NULL,
  quantidade DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  modo ENUM('unidade','folha','milheiro','milheiro_folha','lote','quantidade','aproveitamento','manual') NOT NULL DEFAULT 'unidade',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_variacao_material (variacao_id, material_id),
  INDEX idx_var_comp_material (material_id),
  CONSTRAINT fk_var_comp_variacao FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_var_comp_material FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regras/faixas configuráveis (varejo e atacado). Não fixar faixas no código.
CREATE TABLE faixas_preco (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('varejo','atacado') NOT NULL,
  nome VARCHAR(80) NOT NULL,
  qtd_min INT UNSIGNED NOT NULL DEFAULT 1,
  qtd_max INT UNSIGNED NULL,
  multiplicador DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  INDEX idx_faixas_tipo (tipo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE precos_produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  faixa_id INT UNSIGNED NOT NULL,
  preco DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  origem ENUM('calculado','manual') NOT NULL DEFAULT 'calculado',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_produto_faixa (produto_id, faixa_id),
  CONSTRAINT fk_precos_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  CONSTRAINT fk_precos_faixa FOREIGN KEY (faixa_id) REFERENCES faixas_preco(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historico_custos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  material_id INT UNSIGNED NOT NULL,
  custo_anterior DECIMAL(12,4) NOT NULL,
  custo_novo DECIMAL(12,4) NOT NULL,
  diferenca DECIMAL(12,4) NOT NULL,
  percentual DECIMAL(10,4) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  observacao VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hist_custos_material (material_id, created_at),
  CONSTRAINT fk_hist_custos_material FOREIGN KEY (material_id) REFERENCES materiais(id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_custos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historico_precos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  custo DECIMAL(12,4) NOT NULL,
  varejo DECIMAL(12,2) NULL,
  atacado_100 DECIMAL(12,2) NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hist_precos_produto (produto_id, created_at),
  CONSTRAINT fk_hist_precos_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_precos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arquivos_produtos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  produto_id INT UNSIGNED NOT NULL,
  variacao_id INT UNSIGNED NULL,
  tipo VARCHAR(40) NOT NULL DEFAULT 'outro',
  nome VARCHAR(180) NOT NULL DEFAULT '',
  nome_original VARCHAR(255) NOT NULL,
  arquivo_fisico VARCHAR(255) NOT NULL,
  caminho VARCHAR(255) NOT NULL,
  extensao VARCHAR(20) NULL,
  mime VARCHAR(120) NULL,
  tamanho INT UNSIGNED NULL,
  observacao TEXT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_arquivos_produto (produto_id),
  INDEX idx_arquivos_variacao (variacao_id),
  INDEX idx_arquivos_tipo (tipo),
  INDEX idx_arquivos_nome (nome),
  CONSTRAINT fk_arquivos_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  CONSTRAINT fk_arquivos_variacao FOREIGN KEY (variacao_id) REFERENCES produto_variacoes(id) ON DELETE SET NULL,
  CONSTRAINT fk_arquivos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE logs_alteracoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entidade VARCHAR(60) NOT NULL,
  entidade_id INT UNSIGNED NULL,
  descricao VARCHAR(255) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_logs_created (created_at),
  CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(80) NOT NULL,
  valor TEXT NULL,
  descricao VARCHAR(255) NULL,
  UNIQUE KEY uq_config_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custo dinâmico: soma(preço × quantidade da linha) ÷ (folhas × pç/folha)
CREATE OR REPLACE VIEW vw_produto_custo AS
SELECT
  p.id AS produto_id,
  p.quantidade_producao,
  COALESCE(SUM(m.custo_atual * IFNULL(NULLIF(pc.quantidade, 0), 1)), 0) AS custo_lote,
  CASE
    WHEN (p.quantidade_producao * IFNULL(NULLIF(p.rendimento_folha, 0), 1)) > 0 THEN
      COALESCE(SUM(m.custo_atual * IFNULL(NULLIF(pc.quantidade, 0), 1)), 0)
        / (p.quantidade_producao * IFNULL(NULLIF(p.rendimento_folha, 0), 1))
    ELSE 0
  END AS custo_unitario
FROM produtos p
LEFT JOIN produto_composicao pc ON pc.produto_id = p.id
LEFT JOIN materiais m ON m.id = pc.material_id AND m.status = 'ativo'
GROUP BY p.id, p.quantidade_producao, p.rendimento_folha;

CREATE TABLE tabelas_preco (
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

CREATE TABLE tabela_preco_margens (
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

CREATE TABLE tabela_preco_produtos (
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

CREATE TABLE tabela_preco_quantidades (
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

-- Configuração global da Tabela de Preços (consome produtos existentes)
CREATE TABLE tabela_preco_config (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  imposto_percentual DECIMAL(8,4) NOT NULL DEFAULT 9.5000,
  incluir_comissao TINYINT(1) NOT NULL DEFAULT 0,
  comissao_percentual DECIMAL(8,4) NOT NULL DEFAULT 10.0000,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tabela_preco_margens_cfg (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  margem_percentual DECIMAL(8,4) NOT NULL,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_margem_cfg (margem_percentual)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tabela_preco_qtd_cfg (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quantidade INT UNSIGNED NOT NULL,
  ordem INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qtd_cfg (quantidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estoque físico (peças / papéis) — independente do estoque de produto_variacoes
CREATE TABLE estoque_pecas (
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

CREATE TABLE estoque_papeis (
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

CREATE TABLE estoque_pecas_movimentos (
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

CREATE TABLE estoque_papeis_movimentos (
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

SET FOREIGN_KEY_CHECKS = 1;

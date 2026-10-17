# T&M — Instalação limpa (produção / KingHost)

Este documento prepara uma **instalação nova** do sistema T&M, sem dados de desenvolvimento.

Não altera a KingHost por si só — siga os passos quando for publicar.

## O que a instalação limpa contém

| Item | Situação |
|------|----------|
| Estrutura do banco (tabelas, FKs, views) | Sim (`schema.sql`) |
| Categorias, unidades, parâmetros, margens da tabela | Sim (`seed.sql`) |
| Produtos / variações / custos / coleções / arquivos | **0** |
| Históricos e logs comerciais | **0** |
| Usuário administrador | **1** (criado no servidor com senha definida na hora) |

## Ordem da instalação

1. Importar o banco SQL
2. Configurar o `config/config.php` (banco, `base_url`, `app_key`, `install_key`)
3. Acessar `/instalacao`
4. Informar a `install_key` e criar o primeiro administrador
5. Esvaziar a `install_key` no `config/config.php`
6. Acessar `/login` e entrar com o administrador criado
7. Concluir os demais passos (document root, uploads, verificação)

## 1. Arquivos SQL a importar (nessa ordem)

1. `database/schema.sql` — cria todas as tabelas e a view no banco já selecionado (não cria o banco)
2. `database/seed.sql` — dados estruturais apenas  

Numa instalação nova **não** execute nenhum `database/alter_*.sql`: a estrutura atual já está
inteira em `schema.sql` (inclui Agenda com planos).
**Não** importe nada de `database/historico/` (migrations e scripts históricos, seed de
demonstração e script de limpeza). Ver `database/historico/README.md`.

O `schema.sql` falha se alguma tabela já existir: importe só em banco vazio.
Validação local da instalação limpa (banco temporário, nunca em produção): `php scripts/test_schema_instalacao.php`.

## 2. Configuração da aplicação

1. Copie `config/config.example.php` → `config/config.php`  
2. Preencha host, banco, usuário, senha e `base_url` da KingHost  
3. Preencha `app_key` com uma chave nova desta instalação (`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`) —
   sem ela o login não funciona  
4. Preencha `install_key` com **outra** chave nova, gerada com o mesmo comando:

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   `'install_key' => '<chave gerada>',` — cada cliente/instalação tem a sua: não reutilize a de outro
   cliente nem o `app_key`. Ela fica somente no `config/config.php` **do servidor** desta instalação.  
5. **Não** versionar / não publicar `config/config.php` com senha ou chaves  

Para acessar pelo navegador, o domínio já deve apontar para `public/` (ver [Document root](#document-root)).

## 3–4. Criar o administrador inicial

Só é possível com a tabela `usuarios` vazia. Depois do primeiro administrador, as duas formas
abaixo ficam bloqueadas (novos usuários são criados dentro do sistema).

### Opção A — pelo navegador (`/instalacao`)

A tela de instalação só funciona com a **chave de instalação** (`install_key`) configurada no passo 2:

1. Abra `https://<domínio>/instalacao`.
2. Informe a chave e os dados do administrador (nome, e-mail, senha com mínimo de 8 caracteres).

Sem `install_key` configurada (ou com menos de 32 caracteres), a tela mostra "Instalação bloqueada"
e não cria ninguém. Não envie a chave por URL, e-mail ou chat.

### Opção B — por linha de comando (SSH)

Não usa a `install_key`. No servidor (SSH) ou em máquina com acesso ao mesmo `config/config.php` de produção:

```bash
php scripts/criar_admin.php
```

Ou:

```bash
php scripts/criar_admin.php "Seu Nome" "seu@email.com"
```

O script:

- só roda se `usuarios` estiver vazio;
- cria o usuário com `perfil = admin`;
- pede a senha interativamente (mínimo 8 caracteres);
- grava com `password_hash()` — **nunca** senha em texto puro;
- **não** usa senha padrão do README.

Guarde o e-mail e a senha em local seguro.

## 5. Encerrar a instalação

Depois de criado o administrador, esvazie `install_key` (`'install_key' => '',`) no `config/config.php`.
Assim a instalação concluída não mantém a chave de instalação configurada no servidor.

## 6. Primeiro acesso

Abra `https://<domínio>/login` e entre com o e-mail e a senha do administrador criado.

## 7. Demais passos

### Document root

Aponte o domínio para a pasta **`public/`**.

Pastas `app/`, `config/`, `database/`, `uploads/` devem ficar **fora** do acesso web público.

### Uploads

Estrutura necessária (vazia de arquivos de teste):

- `uploads/` + `uploads/index.php` (+ `.gitkeep`)
- `public/uploads/` + `public/uploads/index.php` (+ `.gitkeep`)

Não enviar CDR/PDF/JPG/PNG de desenvolvimento.

### Verificação

Após importar e criar o admin, execute (somente leitura):

```sql
SOURCE database/producao_verificar.sql;
```

Esperado aproximadamente:

- `usuarios` = 1  
- `produtos` / `produto_variacoes` / `materiais` / `colecoes` / `arquivos_produtos` / históricos = **0**  
- `categorias`, `unidades_medida`, `configuracoes`, `tabela_preco_margens_cfg` > 0  
- `agenda_planos`, `agenda_tarefas` = **0**
  (se alguma não existir, a consulta dá erro: a estrutura está incompleta)
- a última consulta lista 4 colunas de `agenda_tarefas`: `data_inicio`, `em_andamento`, `plano_id`, `responsavel_id`

## Banco já existente (não é instalação nova)

A estrutura atual é a de `database/schema.sql`. Um banco existente é atualizado **somente** pelas
migrations listadas abaixo, uma de cada vez, depois de conferir que ela ainda é necessária.
**Não** execute todas as migrations da pasta `database/` "para garantir": várias são antigas, não são
reexecutáveis ou criam estruturas desatualizadas (ver [Migrations que não fazem parte da atualização](#migrations-que-não-fazem-parte-da-atualização)).

Este documento **não** registra quais migrations já foram aplicadas na produção. Confira sempre a
estrutura real antes (somente leitura, via `information_schema`).

### Antes de qualquer migration

1. Faça backup completo do banco pelo painel da hospedagem.
2. Confira a versão e o modo do servidor: `SELECT VERSION(), @@sql_mode;`
3. Confira o que já existe (somente leitura):

   ```sql
   SELECT TABLE_NAME FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME IN ('login_tentativas', 'agenda_planos');

   SELECT COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_tarefas'
     AND COLUMN_NAME IN ('plano_id', 'data_inicio', 'responsavel_id', 'em_andamento');

   SELECT COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_variacoes'
     AND COLUMN_NAME IN ('gtin_unidade', 'gtin_pacote');
   ```

4. Aplique as migrations **antes** de publicar o código que depende delas.

### Migrations da atualização (nesta ordem, só as que faltarem)

| # | Arquivo | Quando aplicar | Reexecução |
|---|---|---|---|
| 1 | `alter_login_tentativas.sql` | Tabela `login_tentativas` ausente (sem ela o login não funciona) | Segura (`IF NOT EXISTS`) |
| 2 | `alter_agenda_planos.sql` | Tabela `agenda_planos` ausente (Agenda com planos/projetos) | Falha com "tabela já existe", sem alterar nada |
| 3 | `alter_agenda_tarefas_planos.sql` | As 4 colunas de `agenda_tarefas` ausentes. Requer a #2 | Falha com "coluna duplicada", sem alterar nada |
| 4 | `alter_variacoes_gtin.sql` | Colunas `gtin_unidade` e `gtin_pacote` ausentes em `produto_variacoes` | Falha com "coluna duplicada", sem alterar nada |

- Se só **parte** das 4 colunas de `agenda_tarefas` existir, ou só uma das duas colunas GTIN, **não** execute a migration:
  faça uma análise específica antes.
- As migrations 2 a 4 só criam tabelas, colunas, índices e chaves estrangeiras: não alteram nem apagam dados.
- Depois de aplicar, execute `database/producao_verificar.sql` e confira que as consultas rodam sem erro.

Bancos criados **antes** dos módulos de Estoque, Coleções (planejamento/resultados) ou Empresa podem
precisar também de `alter_estoque.sql`, `alter_colecoes_nova_estrutura.sql`, `alter_colecoes_tarefas.sql`
ou `alter_empresa.sql`. São idempotentes (só criam o que falta), mas aplique-os apenas depois de
conferir no `information_schema` que a estrutura correspondente realmente não existe.

### Migrations que não fazem parte da atualização

Os demais `database/alter_*.sql` registram mudanças antigas que já estão no `schema.sql`.
**Não** execute nenhum deles num banco existente sem uma análise específica da estrutura
(comparação via `information_schema`). Riscos conhecidos:

- `alter_colecoes.sql`, `alter_arquivos_produtos.sql`, `alter_composicao_modo.sql`,
  `alter_produtos_tamanho.sql`, `alter_produto_variacoes.sql`, `alter_tabelas_preco.sql` e
  `alter_tabela_preco_global.sql` começam com `USE papel_magenta;` — falham na hospedagem
  (ou atuam em outro banco, se existir um com esse nome).
- `alter_colecoes.sql` é a versão antiga da estrutura de coleções; a atual é `alter_colecoes_nova_estrutura.sql`.
- `alter_composicao_modo.sql` define `produto_composicao.modo` com valores antigos e recria a view.
- `alter_produto_variacoes.sql` e `alter_variacao_custo_proprio.sql` criam, se a tabela não existir,
  versões desatualizadas de `produto_variacoes` e `variacao_composicao`.
- `alter_arquivos_produtos.sql` atualiza dados (`UPDATE`) de `arquivos_produtos`.
- `alter_modo_producao.sql`, `alter_produtos_embalagem_medidas.sql`, `alter_quantidade_pacote.sql`
  e `alter_variacao_custo_proprio.sql` usam `ADD COLUMN` sem verificar se a coluna já existe.
- `alter_agenda_tarefas.sql` cria a versão **antiga** de `agenda_tarefas` (sem planos) se a tabela não existir.

**Nunca** execute nada de `database/historico/` (ver `database/historico/README.md`).

Se um banco antigo estiver diferente de `schema.sql` em algo que não esteja na tabela acima, faça
antes uma análise específica (somente leitura) e só então escreva uma migration **nova e aditiva**
para a diferença encontrada.

## Limpeza de dados

Não há rotina de limpeza no fluxo de instalação ou manutenção.

O antigo `limpeza_dados_comerciais.sql` foi movido para `database/historico/`: ele desliga
as chaves estrangeiras, faz TRUNCATE de várias tabelas (inclusive `usuarios`) e está
desatualizado. Para começar do zero, prefira uma instalação nova com `schema.sql` + `seed.sql`.

## Arquivos alterados neste preparo

Ver relatório da entrega no chat / lista “Não enviar para produção”.

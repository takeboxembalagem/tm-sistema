# T&M — Não enviar para produção (KingHost)

Lista de itens que **não** devem ir no pacote/deploy de produção.
Nenhum destes arquivos foi apagado automaticamente do projeto local.

## Credenciais e ambiente local

- `config/config.php` (credenciais locais / reais — criar no servidor a partir de `config.example.php`)
- Qualquer `.env` (se existir no futuro)
- Backups `.sql` / dumps com dados comerciais
- Arquivos `.zip` / `.rar` de backup do projeto

## Dados e arquivos de desenvolvimento

- Conteúdo de `uploads/arquivos/**` (CDR, PDF, imagens de teste)
- Conteúdo de `public/uploads/produtos/**` e `public/uploads/colecoes/**` (fotos de teste)

## Scripts / docs sensíveis ou só locais

- Pasta `database/historico/` inteira — migrations e scripts históricos, seed de demonstração
  (`seed_demo.sql`) e script de limpeza (`limpeza_dados_comerciais.sql`). **Não executar**
  em produção; ver `database/historico/README.md`
- Histórico de conversas / notas com senhas
- README antigo com senha padrão (já removida; não republicar senhas)

## Opcional omitir no deploy

- `database/alter_*.sql` — já consolidados no `schema.sql` para instalação nova (não executar numa
  instalação nova). Para atualizar um banco existente, envie e execute **somente** as migrations da
  lista de `database/INSTALACAO_PRODUCAO.md` ("Banco já existente") que a conferência mostrar como
  necessárias — por exemplo `alter_agenda_planos.sql`, `alter_agenda_tarefas_planos.sql`
  e `alter_variacoes_gtin.sql`. Não executar as demais sem análise específica
- Pasta `scripts/` exceto `criar_admin.php` (ou envie só esse script e remova após o primeiro admin)
- Arquivos de IDE (`.cursor/`, `.vscode/`)

## Enviar (essencial)

- `app/`
- `public/` (sem fotos de teste em `public/uploads/**`)
- `config/config.example.php` (não o `config.php` preenchido)
- `database/schema.sql`
- `database/seed.sql`
- `database/INSTALACAO_PRODUCAO.md`
- `database/producao_verificar.sql`
- `scripts/criar_admin.php`
- `uploads/index.php` + `.gitkeep` (pasta vazia pronta)
- `public/uploads/index.php` + `.gitkeep`
- `README.md` atualizado

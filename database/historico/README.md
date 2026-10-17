# database/historico — migrations e scripts históricos

Os arquivos desta pasta são **migrations e scripts históricos**. Eles registram
como o banco chegou ao estado atual e foram mantidos apenas para consulta.

**NÃO execute nenhum arquivo desta pasta em produção.** Nem para "atualizar" a
produção, nem para "garantir" que uma coluna existe, nem em partes.

Vários deles não são reexecutáveis: falham no meio, deixam o banco em estado
parcial ou alteram dados válidos sem aviso. Com `sql_mode` não estrito, um ENUM
reduzido transforma valores existentes em `''` silenciosamente.

## Fonte atual da estrutura

| Finalidade | Arquivo |
|---|---|
| Estrutura atual do banco (tabelas, chaves, view) | `database/schema.sql` |
| Dados estruturais atuais (seed) | `database/seed.sql` |
| Conferência somente leitura após instalar | `database/producao_verificar.sql` |
| Guia de instalação | `database/INSTALACAO_PRODUCAO.md` |

Uma instalação nova usa somente `schema.sql` + `seed.sql` + `scripts/criar_admin.php`.
Todas as alterações dos arquivos desta pasta já estão consolidadas em `schema.sql`, assim como as
dos `database/alter_*.sql` (inclusive Agenda com planos).
A atualização de um banco **existente** segue apenas a lista de migrations de
`database/INSTALACAO_PRODUCAO.md` (seção "Banco já existente"), nunca os arquivos desta pasta.

## Alterações futuras

- Toda mudança de estrutura deve ser feita por uma **nova migration**, em `database/`,
  **aditiva e segura**: só acrescenta (tabela, coluna, índice ou valor de ENUM),
  pode ser executada mais de uma vez sem efeito colateral e nunca remove valores
  de ENUM, colunas, chaves estrangeiras ou dados.
- Migrations históricas **não** devem ser reexecutadas para corrigir ou atualizar
  um banco. Se um banco antigo estiver diferente de `schema.sql`, faça antes uma
  análise específica (comparando a estrutura via `information_schema`) e só então
  escreva uma migration nova para a diferença encontrada.

## Arquivos de maior risco

| Arquivo | Risco se executado hoje |
|---|---|
| `alter_usuarios_perfil.sql` | Termina com `UPDATE usuarios SET perfil = 'admin';` **sem WHERE**. Se essa linha rodar (arquivo dividido em blocos, `--force` ou o `ALTER` removido), **todos os usuários viram administradores**, inclusive inativos. A coluna `perfil` já existe em `schema.sql`. |
| `alter_milheiro_folha.sql` | Redefine `materiais.forma_cobranca` e `produto_composicao.modo` **sem** `folha` e sem `manual`. Valores válidos gravados são perdidos (viram `''` ou geram erro). Já era marcado como obsoleto. |
| `alter_tiragem_cobranca.sql` | Redefine `produto_composicao.modo` com ENUM reduzido (sem `folha`, `milheiro_folha` e `manual`) e cria `materiais.forma_cobranca` sem `folha`. Destrói valores válidos. |
| `alter_composicao_custo_manual.sql` | Começa com `DROP FOREIGN KEY` em `produto_composicao` e `variacao_composicao`. Numa reexecução, o `DROP` funciona e o `ADD COLUMN` seguinte falha: **as tabelas ficam sem a chave estrangeira** para `materiais`. |
| `alter_preco_varejo.sql` | `INSERT ... ON DUPLICATE KEY UPDATE valor = VALUES(valor)` em `configuracoes`: **sobrescreve** margem e markup já configurados. |
| `alter_preco_imposto_comissao.sql` | Mesmo padrão: **sobrescreve** imposto e comissão já configurados. |
| `alter_tabela_preco_simples.sql` | Recria a margem de **10%** na Tabela de Preços, contrariando o comportamento atual. |
| `limpeza_dados_comerciais.sql` | Desliga as chaves estrangeiras (`SET FOREIGN_KEY_CHECKS = 0`) e faz **TRUNCATE** de produtos, materiais, coleções, históricos e **usuarios**. Além disso está desatualizado: não limpa as tabelas mais novas e deixa registros órfãos. Nunca usar em banco com dados reais. |

## Demais arquivos

| Arquivo | Motivo de estar aqui |
|---|---|
| `alter_formacao_simplificada.sql` | Apenas substitui a view `vw_produto_custo`, que a aplicação não usa. Opcional e superado. |
| `seed_estrutura.sql` | Duplicata antiga de `seed.sql`, com `USE papel_magenta;` (falha na hospedagem). Use `database/seed.sql`. |
| `seed_demo.sql` | Dados de demonstração de coleções, só para ambiente local. Nunca em produção. |
| `schema.zip` | Cópia do `schema.sql` de 14/09/2026 (com `CREATE DATABASE`/`USE`). Serve apenas como evidência de como a produção foi criada; não é fonte de verdade. Fica fora do git (`*.zip` no `.gitignore`). |

Os comentários internos desses arquivos citam caminhos antigos (`database/...`);
eles foram mantidos sem alteração para preservar o histórico.

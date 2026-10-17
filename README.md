# T&M — Gestão de Produtos e Preços

Sistema web (PHP + MySQL) para produtos, custos, composição, preços, tabela de preços, coleções, arquivos e variações.

Compatível com ambiente local e hospedagem KingHost (PHP/MySQL + Nginx).

## Requisitos

- PHP 8.0+ (extensões PDO e pdo_mysql)
- MySQL 5.7+ ou MariaDB 10.3+

## Instalação limpa (produção)

Siga o guia completo:

**[`database/INSTALACAO_PRODUCAO.md`](database/INSTALACAO_PRODUCAO.md)**

Resumo:

1. Importar `database/schema.sql`
2. Importar `database/seed.sql` (somente estrutura — sem produtos/demo)
3. Configurar `config/config.php` a partir de `config.example.php`
4. Criar o administrador: `php scripts/criar_admin.php`
5. Document root = pasta `public/`

**Não** execute nada de `database/historico/` (migrations e scripts históricos) — ver `database/historico/README.md`.  
**Não** há senha padrão fixa no código — a senha é definida na criação do admin.

## Instalação local (desenvolvimento)

1. Importe:

```text
database/schema.sql
database/seed.sql
```

2. Crie o admin:

```bash
php scripts/criar_admin.php
```

3. (Opcional, só local) dados de demonstração de coleções:

```text
database/historico/seed_demo.sql
```

4. Ajuste `config/config.php` e aponte o document root para `public/`:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

No Windows, prefira `127.0.0.1` (não `localhost`) para IPv4.

## KingHost

- Document root → `public/`
- MySQL e `base_url` em `config/config.php` (só no servidor)
- Ver também `database/NAO_ENVIAR_PRODUCAO.md`

## Estrutura

Pastas principais: `app`, `config`, `database`, `public`, `uploads`, `scripts`.

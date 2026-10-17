<?php
/**
 * Copie este arquivo para config.php e ajuste os valores.
 * Em produção (KingHost), altere banco, usuário, senha, host e BASE_URL.
 */
return [
    'app_name' => 'T&M — Gestão de Produtos e Preços',
    'app_short' => 'T&M',
    'base_url' => 'http://localhost:8080',
    'timezone' => 'America/Sao_Paulo',
    /** Segredo da aplicação (HMAC da sessão). Obrigatório. Gere com: php -r "echo bin2hex(random_bytes(32));" */
    'app_key' => '',
    /**
     * Chave de instalação: autoriza criar o primeiro administrador em /instalacao (só com o banco sem usuários).
     * Uma por instalação, diferente do app_key; gere com o mesmo comando. Vazia = instalação pela web bloqueada.
     * Pode ser esvaziada depois que o administrador for criado.
     */
    'install_key' => '',

    'db' => [
        'host' => '127.0.0.1',
        /** Ambiente local conhecido: MariaDB em 3308. Ajuste se o seu serviço usar outra porta. */
        'port' => '3308',
        'name' => 'tm_sistema_dev',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name' => 'tm_sess',
        'lifetime' => 0,
    ],

    /**
     * Marca impressa nas etiquetas (módulo Etiquetas).
     * Deixe logo vazio enquanto não houver arquivo da marca T&M — o sistema usa o texto do nome.
     */
    'marca_etiquetas' => [
        'nome' => 'T&M',
        'cnpj' => '',
        'site' => '',
        'logo' => '',
    ],
];

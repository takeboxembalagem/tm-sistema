<?php

declare(strict_types=1);

/**
 * Cria o administrador inicial da instalação.
 *
 * Uso (CLI):
 *   php scripts/criar_admin.php
 *   php scripts/criar_admin.php "Nome" "email@dominio.com"
 *
 * A senha é solicitada de forma interativa (não fica gravada no código).
 * Só funciona se a tabela usuarios estiver vazia (instalação limpa).
 *
 * NÃO execute este script em produção depois que já houver usuários.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado apenas via linha de comando.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

$existentes = (int) Database::pdo()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($existentes > 0) {
    fwrite(STDERR, "Já existem {$existentes} usuário(s). Abortado — instalação não está vazia.\n");
    exit(1);
}

$nome = $argv[1] ?? '';
$email = $argv[2] ?? '';

if ($nome === '') {
    echo 'Nome do administrador: ';
    $nome = trim((string) fgets(STDIN));
}
if ($email === '') {
    echo 'E-mail do administrador: ';
    $email = trim((string) fgets(STDIN));
}

$nome = trim($nome);
$email = strtolower(trim($email));

if ($nome === '' || mb_strlen($nome) > 120) {
    fwrite(STDERR, "Nome inválido.\n");
    exit(1);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(1);
}

echo 'Senha (mínimo 8 caracteres): ';
$senha = lerSenhaOculta();
echo PHP_EOL . 'Confirmar senha: ';
$confirma = lerSenhaOculta();
echo PHP_EOL;

if (mb_strlen($senha) < 8) {
    fwrite(STDERR, "A senha deve ter no mínimo 8 caracteres.\n");
    exit(1);
}
if ($senha !== $confirma) {
    fwrite(STDERR, "A confirmação da senha não confere.\n");
    exit(1);
}

$resultado = Instalacao::criarPrimeiroAdmin([
    'nome' => $nome,
    'email' => $email,
    'senha_hash' => Usuario::hashSenha($senha),
]);
if ($resultado === Instalacao::OCUPADO) {
    fwrite(STDERR, "Outra instalação está em andamento. Tente novamente em alguns segundos.\n");
    exit(1);
}
if ($resultado !== Instalacao::CRIADO) {
    fwrite(STDERR, "Já existe usuário cadastrado. Abortado — instalação não está vazia.\n");
    exit(1);
}

echo "Administrador criado com sucesso (perfil=admin).\n";
echo "E-mail: {$email}\n";
echo "Guarde a senha em local seguro. Ela não é armazenada em texto puro.\n";
exit(0);

function lerSenhaOculta(): string
{
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        // Windows: leitura normal (eco visível). Prefira digitar em ambiente privado.
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    system('stty -echo');
    $line = fgets(STDIN);
    system('stty echo');
    return $line === false ? '' : rtrim($line, "\r\n");
}

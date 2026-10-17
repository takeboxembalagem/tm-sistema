<?php

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        $stored = $_SESSION['_csrf'] ?? '';
        if (!is_string($sent) || $stored === '' || !hash_equals($stored, $sent)) {
            http_response_code(419);
            echo 'Sessão expirada ou token inválido. Atualize a página e tente novamente.';
            exit;
        }
    }
}

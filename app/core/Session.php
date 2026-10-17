<?php

declare(strict_types=1);

final class Session
{
    public static function start(array $options = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::requestIsHttps();
        $lifetime = (int) ($options['lifetime'] ?? 0);

        // Só use_only_cookies. NÃO usar ini_set de cookie_* / use_strict_mode:
        // na KingHost isso interferiu no Set-Cookie e quebrou CSRF GET→POST do login
        // ($_SESSION['_csrf'] sumia porque o browser não recebia/reenviava pm_sess).
        ini_set('session.use_only_cookies', '1');

        session_name($options['name'] ?? 'pm_sess');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // cookie_* NÃO deve ir em session_start() — em PHP 8.x / alguns hosts
        // (ex.: KingHost) falha com "Setting option cookie_lifetime failed".
        session_start();

        // Garante Set-Cookie do ID atual (mesmo quirk que afectava o regenerate).
        self::emitCookie();
    }

    /**
     * Regenera o ID após autenticação (mitiga session fixation).
     * Usa delete_old_session=false: em hospedagem compartilhada, true
     * pode apagar a sessão antes do browser gravar o novo cookie —
     * sintoma típico: CSRF do login funciona, mas após Entrar a sessão some.
     */
    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(false);
        self::emitCookie();
    }

    /** Garante flush em disco antes de Location: (redirect). */
    public static function writeClose(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Emite o cookie da sessão com os params actuais.
     * Necessário em hosts onde session_start / regenerate_id não enviam
     * Set-Cookie de forma fiável.
     */
    private static function emitCookie(): void
    {
        if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        $lifetime = (int) ($params['lifetime'] ?? 0);
        setcookie(session_name(), session_id(), [
            'expires' => $lifetime > 0 ? time() + $lifetime : 0,
            'path' => $params['path'] ?: '/',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    /**
     * Detecta HTTPS pelo pedido real (proxy/SSL termination).
     * NÃO usa base_url: se config for https:// mas o browser estiver em HTTP,
     * Secure=1 faz o browser rejeitar o cookie → sessão nova a cada request → CSRF 419.
     */
    private static function requestIsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first === 'https') {
                return true;
            }
        }

        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        return false;
    }
}

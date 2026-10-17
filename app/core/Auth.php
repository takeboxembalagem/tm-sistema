<?php

declare(strict_types=1);

final class Auth
{
    /** Cache por requisição: evita consultas repetidas ao banco. */
    private static ?bool $sessionActiveOk = null;

    /** Chave da sessão com a impressão digital da senha (fora de $_SESSION['user'], que as views leem). */
    public const CHAVE_IMPRESSAO = '_senha_fp';

    public const MSG_SEM_CHAVE = 'Login indisponível: configuração de segurança ausente (app_key). Contate o administrador.';

    public const LOGIN_OK = 'ok';
    public const LOGIN_FALHA = 'falha';
    public const LOGIN_BLOQUEADO = 'bloqueado';

    /**
     * Hash bcrypt (mesmo algoritmo/custo de Usuario::hashSenha) de uma senha aleatória descartada:
     * ninguém conhece a senha e o hash não existe no banco. Serve só para que e-mail inexistente
     * também pague o custo do password_verify, sem a diferença óbvia de tempo.
     */
    private const HASH_FICTICIO = '$2y$10$tIrt33ks2AxA5XSzI.YQv.AovDQYA9boxtqKjxqn5V68z/pgWipnq';

    /**
     * Login com limite de tentativas (LoginTentativa). E-mail inexistente, senha errada e usuário
     * inativo têm o mesmo caminho: password_verify executado, falha contada, mesmo retorno.
     * Bloqueado não verifica a senha e não conta a tentativa.
     */
    public static function tentarLogin(string $email, string $password, string $ip): string
    {
        LoginTentativa::limparAntigos();

        $pdo = Database::pdo();
        for ($tentativa = 1; ; $tentativa++) {
            try {
                $pdo->beginTransaction();
                $travado = LoginTentativa::travar($email, $ip);
                if ($travado['bloqueado']) {
                    $pdo->commit();
                    return self::LOGIN_BLOQUEADO;
                }

                $stmt = $pdo->prepare(
                    'SELECT id, nome, email, senha_hash, status, perfil
                     FROM usuarios
                     WHERE email = :email
                     LIMIT 1'
                );
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch() ?: null;

                $senhaOk = password_verify($password, $user ? (string) $user['senha_hash'] : self::HASH_FICTICIO);
                if (!$user || !$senhaOk || $user['status'] !== 'ativo') {
                    LoginTentativa::registrarFalha($travado);
                    $pdo->commit();
                    return self::LOGIN_FALHA;
                }

                LoginTentativa::registrarSucesso($travado);
                $pdo->commit();
                break;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // 1213 = deadlock, 1205 = espera de lock: repete a transação inteira.
                if ($tentativa < 3 && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                    continue;
                }
                throw $e;
            }
        }

        $impressao = self::impressaoSenha((string) $user['senha_hash']);

        Session::regenerate();
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'nome' => $user['nome'],
            'email' => $user['email'],
            'perfil' => self::normalizePerfil($user['perfil'] ?? null),
        ];
        $_SESSION[self::CHAVE_IMPRESSAO] = $impressao;
        self::$sessionActiveOk = true;

        return self::LOGIN_OK;
    }

    public static function chaveConfigurada(): bool
    {
        return strlen((string) config('app_key', '')) >= 32;
    }

    /**
     * HMAC-SHA256 do senha_hash com o app_key: muda sempre que a senha muda, sem guardar a senha
     * nem o hash na sessão.
     */
    public static function impressaoSenha(string $senhaHash): string
    {
        if (!self::chaveConfigurada()) {
            throw new RuntimeException('app_key ausente ou curto em config/config.php.');
        }
        return hash_hmac('sha256', $senhaHash, (string) config('app_key'));
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        if (self::user() === null) {
            return false;
        }

        return self::ensureActiveSession();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login');
        }
    }

    public static function isAdmin(): bool
    {
        if (!self::check()) {
            return false;
        }

        return (self::user()['perfil'] ?? '') === 'admin';
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            flash('errors', ['Você não tem permissão para acessar esta área.']);
            redirect('/configuracoes');
        }
    }

    public static function logout(): void
    {
        self::$sessionActiveOk = null;
        Session::destroy();
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * Confirma no banco que o usuário da sessão ainda existe, está ativo e não trocou de senha
     * desde o login. Uma consulta por requisição (cache estático).
     */
    private static function ensureActiveSession(): bool
    {
        if (self::$sessionActiveOk !== null) {
            return self::$sessionActiveOk;
        }

        $sessionUser = $_SESSION['user'] ?? null;
        if (!is_array($sessionUser)) {
            self::$sessionActiveOk = false;
            return false;
        }

        $id = (int) ($sessionUser['id'] ?? 0);
        if ($id <= 0) {
            self::invalidateInactiveSession();
            self::$sessionActiveOk = false;
            return false;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, status, perfil, senha_hash FROM usuarios WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row || ($row['status'] ?? '') !== 'ativo') {
            self::invalidateInactiveSession();
            self::$sessionActiveOk = false;
            return false;
        }

        // Sessão sem impressão (criada antes desta verificação existir) não é revalidada com a senha
        // atual: não há como saber se a senha mudou depois dela, então exige novo login.
        $impressao = $_SESSION[self::CHAVE_IMPRESSAO] ?? null;
        if (!is_string($impressao) || $impressao === '') {
            self::invalidarSessao('Sua sessão expirou. Entre novamente.');
            self::$sessionActiveOk = false;
            return false;
        }
        if (!self::chaveConfigurada()) {
            self::invalidarSessao(self::MSG_SEM_CHAVE);
            self::$sessionActiveOk = false;
            return false;
        }
        if (!hash_equals(self::impressaoSenha((string) $row['senha_hash']), $impressao)) {
            self::invalidarSessao('Sua senha foi alterada. Entre novamente.');
            self::$sessionActiveOk = false;
            return false;
        }

        // Mantém o perfil sincronizado com o banco (ex.: demoção por outro admin).
        $_SESSION['user']['perfil'] = self::normalizePerfil($row['perfil'] ?? null);

        self::$sessionActiveOk = true;
        return true;
    }

    private static function invalidateInactiveSession(): void
    {
        self::invalidarSessao('Seu acesso foi desativado. Entre em contato com o administrador.');
    }

    private static function invalidarSessao(string $mensagem): void
    {
        Session::destroy();
        Session::start($GLOBALS['config']['session'] ?? []);
        $_SESSION['flash_error'] = $mensagem;
    }

    private static function normalizePerfil(?string $perfil): string
    {
        return $perfil === 'admin' ? 'admin' : 'usuario';
    }
}

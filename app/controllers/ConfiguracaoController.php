<?php

declare(strict_types=1);

final class ConfiguracaoController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $isAdmin = Auth::isAdmin();

        View::render('configuracoes/index', [
            'title' => 'Configurações',
            'page' => 'configuracoes',
            'usuarioAtual' => Auth::user(),
            'isAdmin' => $isAdmin,
            'usuarios' => $isAdmin ? Usuario::all() : [],
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function senhaForm(): void
    {
        Auth::requireLogin();

        View::render('configuracoes/senha', [
            'title' => 'Alterar minha senha',
            'page' => 'configuracoes',
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function senhaStore(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $atual = (string) ($_POST['senha_atual'] ?? '');
        $nova = (string) ($_POST['nova_senha'] ?? '');
        $confirma = (string) ($_POST['confirmar_senha'] ?? '');

        $user = Usuario::find((int) Auth::id());
        if (!$user) {
            Auth::logout();
            redirect('/login');
        }

        $errors = [];
        if ($atual === '' || !Usuario::verificarSenha($atual, (string) $user['senha_hash'])) {
            $errors[] = 'Não foi possível alterar a senha. Verifique os dados informados.';
        } elseif (mb_strlen($nova) < 8) {
            $errors[] = 'A nova senha deve ter no mínimo 8 caracteres.';
        } elseif ($nova !== $confirma) {
            $errors[] = 'A confirmação da nova senha não confere.';
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/configuracoes/senha');
        }

        Usuario::setSenha((int) $user['id'], Usuario::hashSenha($nova));

        Auth::logout();
        Session::start($GLOBALS['config']['session'] ?? []);
        $_SESSION['flash_success'] = 'Senha alterada com sucesso. Faça login novamente.';
        redirect('/login');
    }

    public static function create(): void
    {
        Auth::requireAdmin();
        self::formUsuario(null);
    }

    public static function store(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        self::saveUsuario(null);
    }

    public static function edit(int $id): void
    {
        Auth::requireAdmin();
        $usuario = Usuario::findPublic($id);
        if (!$usuario) {
            self::notFound();
            return;
        }
        self::formUsuario($usuario);
    }

    public static function update(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $usuario = Usuario::findPublic($id);
        if (!$usuario) {
            self::notFound();
            return;
        }
        self::saveUsuario($usuario);
    }

    public static function toggle(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $usuario = Usuario::findPublic($id);
        if (!$usuario) {
            self::notFound();
            return;
        }

        if ($usuario['status'] === 'ativo') {
            if (Usuario::contarAtivos() <= 1) {
                flash('errors', ['Não é possível inativar o único usuário ativo do sistema.']);
                redirect('/configuracoes');
            }
            if (($usuario['perfil'] ?? '') === 'admin' && Usuario::contarAdminsAtivos() <= 1) {
                flash('errors', ['Não é possível inativar o único administrador ativo do sistema.']);
                redirect('/configuracoes');
            }
            Usuario::setStatus($id, 'inativo');
            flash('success', 'Usuário inativado.');
        } else {
            Usuario::setStatus($id, 'ativo');
            flash('success', 'Usuário ativado.');
        }

        redirect('/configuracoes');
    }

    public static function senhaUsuarioForm(int $id): void
    {
        Auth::requireAdmin();
        $usuario = Usuario::findPublic($id);
        if (!$usuario) {
            self::notFound();
            return;
        }

        View::render('configuracoes/usuario_senha', [
            'title' => 'Alterar senha do usuário',
            'page' => 'configuracoes',
            'usuario' => $usuario,
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function senhaUsuarioStore(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $usuario = Usuario::findPublic($id);
        if (!$usuario) {
            self::notFound();
            return;
        }

        $nova = (string) ($_POST['nova_senha'] ?? '');
        $confirma = (string) ($_POST['confirmar_senha'] ?? '');
        $errors = [];

        if (mb_strlen($nova) < 8) {
            $errors[] = 'A nova senha deve ter no mínimo 8 caracteres.';
        } elseif ($nova !== $confirma) {
            $errors[] = 'A confirmação da nova senha não confere.';
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/configuracoes/usuarios/' . $id . '/senha');
        }

        Usuario::setSenha($id, Usuario::hashSenha($nova));
        flash('success', 'Senha do usuário atualizada.');
        redirect('/configuracoes');
    }

    /**
     * @param array<string, mixed>|null $usuario
     */
    private static function formUsuario(?array $usuario): void
    {
        View::render('configuracoes/usuario_form', [
            'title' => $usuario ? 'Editar usuário' : 'Novo usuário',
            'page' => 'configuracoes',
            'usuario' => $usuario,
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function saveUsuario(?array $existing): void
    {
        $id = $existing ? (int) $existing['id'] : null;
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $perfil = Usuario::normalizePerfil((string) ($_POST['perfil'] ?? 'usuario'));
        $senha = (string) ($_POST['senha'] ?? '');
        $confirma = (string) ($_POST['confirmar_senha'] ?? '');

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }

        $errors = [];
        if ($nome === '') {
            $errors[] = 'Informe o nome.';
        } elseif (mb_strlen($nome) > 120) {
            $errors[] = 'O nome deve ter no máximo 120 caracteres.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        } elseif (mb_strlen($email) > 180) {
            $errors[] = 'O e-mail deve ter no máximo 180 caracteres.';
        } elseif (Usuario::emailExiste($email, $id)) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }

        if ($id === null) {
            $status = 'ativo';
            if (mb_strlen($senha) < 8) {
                $errors[] = 'A senha deve ter no mínimo 8 caracteres.';
            } elseif ($senha !== $confirma) {
                $errors[] = 'A confirmação da senha não confere.';
            }
        } else {
            $perfilAnterior = Usuario::normalizePerfil($existing['perfil'] ?? null);
            $statusAnterior = (string) ($existing['status'] ?? '');

            if ($status === 'inativo' && $statusAnterior === 'ativo' && Usuario::contarAtivos() <= 1) {
                $errors[] = 'Não é possível inativar o único usuário ativo do sistema.';
            }

            if (
                $statusAnterior === 'ativo'
                && $perfilAnterior === 'admin'
                && Usuario::contarAdminsAtivos() <= 1
            ) {
                if ($status === 'inativo') {
                    $errors[] = 'Não é possível inativar o único administrador ativo do sistema.';
                } elseif ($perfil !== 'admin') {
                    $errors[] = 'Não é possível alterar o perfil do único administrador ativo.';
                }
            }
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'nome' => $nome,
                'email' => $email,
                'status' => $status,
                'perfil' => $perfil,
            ]);
            redirect($id ? '/configuracoes/usuarios/' . $id . '/editar' : '/configuracoes/usuarios/novo');
        }

        if ($id === null) {
            Usuario::create([
                'nome' => $nome,
                'email' => $email,
                'senha_hash' => Usuario::hashSenha($senha),
                'status' => 'ativo',
                'perfil' => $perfil,
            ]);
            flash('success', 'Usuário cadastrado com sucesso.');
        } else {
            Usuario::update($id, [
                'nome' => $nome,
                'email' => $email,
                'status' => $status,
                'perfil' => $perfil,
            ]);

            if ($id === Auth::id()) {
                $_SESSION['user']['nome'] = $nome;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['perfil'] = $perfil;
            }

            flash('success', 'Usuário atualizado.');
        }

        redirect('/configuracoes');
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Usuário não encontrado', 'page' => 'configuracoes']);
    }
}

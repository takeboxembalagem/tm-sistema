<?php
$isEdit = !empty($usuario);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $usuario) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($usuario && array_key_exists($key, $usuario) && $usuario[$key] !== null) {
        return (string) $usuario[$key];
    }
    return (string) $fallback;
};
$action = $isEdit
    ? url('/configuracoes/usuarios/' . $usuario['id'])
    : url('/configuracoes/usuarios');
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2><?= $isEdit ? 'Editar usuário' : 'Novo usuário' ?></h2>

    <form method="post" action="<?= e($action) ?>" class="form" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Nome *
                <input type="text" name="nome" maxlength="120" required value="<?= e($val('nome')) ?>">
            </label>
            <label>
                E-mail *
                <input type="email" name="email" maxlength="180" required value="<?= e($val('email')) ?>">
            </label>
            <label>
                Perfil *
                <select name="perfil" required>
                    <option value="usuario" <?= $val('perfil', 'usuario') === 'usuario' ? 'selected' : '' ?>>Usuário</option>
                    <option value="admin" <?= $val('perfil') === 'admin' ? 'selected' : '' ?>>Administrador</option>
                </select>
            </label>

            <?php if (!$isEdit): ?>
                <label>
                    Senha inicial *
                    <input type="password" name="senha" required minlength="8" autocomplete="new-password">
                    <small class="hint">Mínimo de 8 caracteres.</small>
                </label>
                <label>
                    Confirmar senha *
                    <input type="password" name="confirmar_senha" required minlength="8" autocomplete="new-password">
                </label>
            <?php else: ?>
                <label>
                    Status
                    <select name="status">
                        <option value="ativo" <?= $val('status', 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inativo" <?= $val('status') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </label>
                <p class="hint span-2" style="margin:0">
                    A senha é alterada pela ação “Alterar senha” na lista de usuários.
                </p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Cadastrar usuário' ?></button>
            <a class="btn-secondary" href="<?= e(url('/configuracoes')) ?>">Cancelar</a>
        </div>
    </form>
</div>

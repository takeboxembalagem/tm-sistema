<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2>Alterar senha do usuário</h2>
    <p class="hint" style="margin-top:0">
        Usuário: <strong><?= e($usuario['nome']) ?></strong> (<?= e($usuario['email']) ?>)
    </p>
    <p class="hint">A senha atual nunca é exibida. Defina uma nova senha.</p>

    <form method="post" action="<?= e(url('/configuracoes/usuarios/' . $usuario['id'] . '/senha')) ?>" class="form" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Nova senha *
                <input type="password" name="nova_senha" required minlength="8" autocomplete="new-password">
                <small class="hint">Mínimo de 8 caracteres.</small>
            </label>
            <label>
                Confirmar nova senha *
                <input type="password" name="confirmar_senha" required minlength="8" autocomplete="new-password">
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Salvar senha</button>
            <a class="btn-secondary" href="<?= e(url('/configuracoes')) ?>">Cancelar</a>
        </div>
    </form>
</div>

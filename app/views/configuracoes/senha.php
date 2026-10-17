<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2>Alterar minha senha</h2>
    <p class="hint">Após salvar, será necessário entrar novamente no sistema.</p>

    <form method="post" action="<?= e(url('/configuracoes/senha')) ?>" class="form" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label class="span-2">
                Senha atual *
                <input type="password" name="senha_atual" required minlength="1" autocomplete="current-password">
            </label>
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
            <button type="submit" class="btn-primary">Salvar nova senha</button>
            <a class="btn-secondary" href="<?= e(url('/configuracoes')) ?>">Cancelar</a>
        </div>
    </form>
</div>

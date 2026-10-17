<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">T&M</span>
        <h1>T&M</h1>
        <p>Gestão de produtos e preços</p>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" class="form" autocomplete="on">
        <?= Csrf::field() ?>
        <label>
            E-mail
            <input type="email" name="email" required maxlength="180" value="<?= old('email') ?>">
        </label>
        <label>
            Senha
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn-primary">Entrar</button>
    </form>
</div>

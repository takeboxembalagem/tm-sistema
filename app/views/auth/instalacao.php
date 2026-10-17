<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">T&M</span>
        <h1>T&M</h1>
        <p>Criar administrador inicial</p>
    </div>

    <?php if (empty($habilitada)): ?>
        <div class="alert alert-error">
            Instalação bloqueada: nenhuma chave de instalação configurada no servidor.
        </div>
        <p class="hint">
            Para criar o primeiro administrador, defina <code>install_key</code> em <code>config/config.php</code>
            no servidor (ver <code>database/INSTALACAO_PRODUCAO.md</code>) e recarregue esta página.
        </p>
    <?php else: ?>
        <p class="hint" style="margin-top:0">
            Nenhum usuário cadastrado. Crie o primeiro administrador para acessar o sistema.
            Esta tela deixa de funcionar assim que o administrador for criado.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/instalacao')) ?>" class="form" autocomplete="off">
            <?= Csrf::field() ?>
            <label>
                Chave de instalação *
                <input type="password" name="chave_instalacao" required autocomplete="off">
                <small class="hint">Valor de <code>install_key</code> no <code>config/config.php</code> do servidor.</small>
            </label>
            <label>
                Nome *
                <input type="text" name="nome" required maxlength="120" value="<?= e($old['nome'] ?? '') ?>">
            </label>
            <label>
                E-mail *
                <input type="email" name="email" required maxlength="180" value="<?= e($old['email'] ?? '') ?>">
            </label>
            <label>
                Senha *
                <input type="password" name="senha" required minlength="8" autocomplete="new-password">
                <small class="hint">Mínimo de 8 caracteres.</small>
            </label>
            <label>
                Confirmar senha *
                <input type="password" name="confirmar_senha" required minlength="8" autocomplete="new-password">
            </label>
            <button type="submit" class="btn-primary">Criar administrador</button>
        </form>
    <?php endif; ?>
</div>

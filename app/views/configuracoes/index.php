<?php
$usuarios = $usuarios ?? [];
$usuarioAtual = $usuarioAtual ?? Auth::user();
$isAdmin = !empty($isAdmin);
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card" style="margin-bottom:16px">
    <h2>Minha conta</h2>
    <p class="hint" style="margin-top:0">
        Logado como <strong><?= e($usuarioAtual['nome'] ?? '') ?></strong>
        (<?= e($usuarioAtual['email'] ?? '') ?>).
    </p>
    <div class="form-actions">
        <a class="btn-primary" href="<?= e(url('/configuracoes/senha')) ?>">Alterar minha senha</a>
    </div>
</section>

<?php if ($isAdmin): ?>
<section class="card table-card">
    <div class="toolbar" style="margin-bottom:12px">
        <div>
            <h2 style="margin:0">Usuários</h2>
            <p class="hint" style="margin:4px 0 0">Gerencie quem pode acessar o sistema.</p>
        </div>
        <a class="btn-primary" href="<?= e(url('/configuracoes/usuarios/novo')) ?>">+ Novo usuário</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$usuarios): ?>
                    <tr>
                        <td colspan="5" class="empty">Nenhum usuário cadastrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td>
                                <strong><?= e($u['nome']) ?></strong>
                                <?php if ((int) $u['id'] === (int) ($usuarioAtual['id'] ?? 0)): ?>
                                    <span class="hint">· você</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($u['email']) ?></td>
                            <td><?= ($u['perfil'] ?? '') === 'admin' ? 'Administrador' : 'Usuário' ?></td>
                            <td>
                                <span class="badge <?= ($u['status'] ?? '') === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= ($u['status'] ?? '') === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="<?= e(url('/configuracoes/usuarios/' . $u['id'] . '/editar')) ?>">Editar</a>
                                <a href="<?= e(url('/configuracoes/usuarios/' . $u['id'] . '/senha')) ?>">Alterar senha</a>
                                <form method="post" action="<?= e(url('/configuracoes/usuarios/' . $u['id'] . '/status')) ?>">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn">
                                        <?= ($u['status'] ?? '') === 'ativo' ? 'Inativar' : 'Ativar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

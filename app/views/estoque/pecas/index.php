<?php
$qs = static function (array $extra = []) use ($filters, $pageNum): string {
    $params = array_filter([
        'q' => $filters['q'] ?? '',
        'status' => $filters['status'] ?? '',
        'tipo' => $filters['tipo'] ?? '',
        'page' => $extra['page'] ?? $pageNum,
    ], static fn ($v) => $v !== '' && $v !== 0 && $v !== '0' && $v !== 1);
    if (($extra['page'] ?? $pageNum) > 1) {
        $params['page'] = $extra['page'] ?? $pageNum;
    }
    return http_build_query($params);
};
$tipoLabel = static fn (string $t): string => match ($t) {
    'tampa' => 'Tampa',
    'fundo' => 'Fundo',
    default => 'Outra',
};
?>
<?php require __DIR__ . '/../_tabs.php'; ?>

<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/estoque/pecas')) ?>">
        <input type="search" name="q" placeholder="Buscar por nome, código, cor…" value="<?= e($filters['q'] ?? '') ?>">
        <select name="tipo">
            <option value="">Todos os tipos</option>
            <option value="tampa" <?= (($filters['tipo'] ?? '') === 'tampa') ? 'selected' : '' ?>>Tampa</option>
            <option value="fundo" <?= (($filters['tipo'] ?? '') === 'fundo') ? 'selected' : '' ?>>Fundo</option>
            <option value="outra" <?= (($filters['tipo'] ?? '') === 'outra') ? 'selected' : '' ?>>Outra</option>
        </select>
        <select name="status">
            <option value="">Todos</option>
            <option value="ativo" <?= (($filters['status'] ?? '') === 'ativo') ? 'selected' : '' ?>>Ativos</option>
            <option value="inativo" <?= (($filters['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativos</option>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
    </form>
    <a class="btn-primary" href="<?= e(url('/estoque/pecas/novo')) ?>">+ Nova peça</a>
</section>

<?php require __DIR__ . '/../_pdf.php'; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th></th>
                    <th>Código</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Saldo</th>
                    <th>Local</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$itens): ?>
                    <tr>
                        <td colspan="8" class="empty">Nenhuma peça cadastrada.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($itens as $row): ?>
                        <?php $thumb = upload_url($row['imagem'] ?? null); ?>
                        <tr>
                            <td>
                                <?php if ($thumb): ?>
                                    <img class="thumb" src="<?= e($thumb) ?>" alt="">
                                <?php else: ?>
                                    <span class="thumb thumb-empty">TM</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($row['codigo']) ?></td>
                            <td><a href="<?= e(url('/estoque/pecas/' . $row['id'])) ?>"><?= e($row['nome']) ?></a></td>
                            <td><?= e($tipoLabel((string) $row['tipo'])) ?></td>
                            <td><strong><?= e(number_br($row['quantidade'], 0)) ?></strong> <?= e($row['unidade']) ?></td>
                            <td><?= e($row['localizacao'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= $row['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= $row['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="<?= e(url('/estoque/pecas/' . $row['id'])) ?>">Abrir</a>
                                <a href="<?= e(url('/estoque/pecas/' . $row['id'] . '/editar')) ?>">Editar</a>
                                <form method="post" action="<?= e(url('/estoque/pecas/' . $row['id'] . '/status')) ?>">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn"><?= $row['status'] === 'ativo' ? 'Inativar' : 'Ativar' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
    <nav class="pagination" style="margin-top:16px">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="<?= $i === $pageNum ? 'is-active' : '' ?>" href="<?= e(url('/estoque/pecas?' . $qs(['page' => $i]))) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

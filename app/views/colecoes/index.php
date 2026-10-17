<?php
$qs = static function (array $extra = []) use ($filters, $pageNum): string {
    $params = array_filter([
        'q' => $filters['q'] ?? '',
        'status' => $filters['status'] ?? '',
        'page' => $extra['page'] ?? $pageNum,
    ], static fn ($v) => $v !== '' && $v !== 0 && $v !== '0' && $v !== 1);
    if (($extra['page'] ?? $pageNum) > 1) {
        $params['page'] = $extra['page'] ?? $pageNum;
    }
    return http_build_query($params);
};
?>
<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/colecoes')) ?>">
        <input type="search" name="q" placeholder="Buscar por nome ou código..." value="<?= e($filters['q'] ?? '') ?>">
        <select name="status">
            <option value="">Todas</option>
            <option value="ativo" <?= (($filters['status'] ?? '') === 'ativo') ? 'selected' : '' ?>>Ativas</option>
            <option value="inativo" <?= (($filters['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativas</option>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
    </form>
    <a class="btn-primary" href="<?= e(url('/colecoes/novo')) ?>">+ Nova coleção</a>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$colecoes): ?>
    <div class="card">
        <p class="empty" style="margin:0;padding:24px;text-align:center">Nenhuma coleção encontrada. Cadastre a primeira linha comercial.</p>
    </div>
<?php else: ?>
    <div class="colecao-grid">
        <?php foreach ($colecoes as $item): ?>
            <?php $capa = upload_url($item['imagem'] ?? null); ?>
            <article class="colecao-card">
                <a class="colecao-card-cover" href="<?= e(url('/colecoes/' . $item['id'])) ?>">
                    <?php if ($capa): ?>
                        <img src="<?= e($capa) ?>" alt="">
                    <?php else: ?>
                        <span class="colecao-card-placeholder">TM</span>
                    <?php endif; ?>
                </a>
                <div class="colecao-card-body">
                    <?php if (!empty($item['codigo'])): ?>
                        <div class="hint"><?= e($item['codigo']) ?></div>
                    <?php endif; ?>
                    <h3><a href="<?= e(url('/colecoes/' . $item['id'])) ?>"><?= e($item['nome']) ?></a></h3>
                    <p class="hint"><?= e((Colecao::TIPOS[$item['tipo'] ?? ''] ?? '—') . ' · ' . (Colecao::ETAPAS[$item['etapa'] ?? ''] ?? '—')) ?></p>
                    <p class="hint"><?= (int) $item['variacoes_count'] ?> variaç<?= ((int) $item['variacoes_count'] === 1) ? 'ão' : 'ões' ?></p>
                    <span class="badge <?= $item['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                        <?= $item['status'] === 'ativo' ? 'Ativa' : 'Inativa' ?>
                    </span>
                    <div class="actions" style="margin-top:12px;flex-wrap:wrap">
                        <a href="<?= e(url('/colecoes/' . $item['id'])) ?>">Abrir</a>
                        <a href="<?= e(url('/colecoes/' . $item['id'] . '/editar')) ?>">Editar</a>
                        <form method="post" action="<?= e(url('/colecoes/' . $item['id'] . '/status')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn"><?= $item['status'] === 'ativo' ? 'Inativar' : 'Ativar' ?></button>
                        </form>
                        <?php if (Auth::isAdmin()): ?>
                            <a href="<?= e(url('/colecoes/' . $item['id'] . '/excluir')) ?>">Excluir</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination" style="margin-top:16px">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a class="<?= $i === $pageNum ? 'is-active' : '' ?>" href="<?= e(url('/colecoes?' . $qs(['page' => $i]))) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
$qs = static function (array $extra = []) use ($filters, $pageNum): string {
    $params = array_filter([
        'q' => $filters['q'] ?? '',
        'categoria_id' => $filters['categoria_id'] ?? 0,
        'colecao_id' => $filters['colecao_id'] ?? 0,
        'status' => $filters['status'] ?? '',
        'page' => $extra['page'] ?? $pageNum,
    ], static fn ($v) => $v !== '' && $v !== 0 && $v !== '0');
    return http_build_query($params);
};
?>
<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/produtos')) ?>">
        <input type="search" name="q" placeholder="Buscar por código ou nome..." value="<?= e($filters['q'] ?? '') ?>">
        <select name="categoria_id">
            <option value="">Categoria</option>
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int) $categoria['id'] ?>" <?= ((int) ($filters['categoria_id'] ?? 0) === (int) $categoria['id']) ? 'selected' : '' ?>>
                    <?= e($categoria['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="colecao_id">
            <option value="">Coleção</option>
            <?php foreach ($colecoes as $colecao): ?>
                <option value="<?= (int) $colecao['id'] ?>" <?= ((int) ($filters['colecao_id'] ?? 0) === (int) $colecao['id']) ? 'selected' : '' ?>>
                    <?= e($colecao['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">Todos</option>
            <option value="ativo" <?= (($filters['status'] ?? '') === 'ativo') ? 'selected' : '' ?>>Ativos</option>
            <option value="inativo" <?= (($filters['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativos</option>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
    </form>
    <a class="btn-primary" href="<?= e(url('/produtos/novo')) ?>">+ Novo Produto</a>
</section>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Código</th>
                    <th>Produto</th>
                    <th>Tamanho</th>
                    <th>Categoria</th>
                    <th>Coleção</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$produtos): ?>
                    <tr>
                        <td colspan="8" class="empty">Nenhum produto encontrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($produtos as $produto): ?>
                        <?php $foto = upload_url($produto['foto_principal'] ?? null); ?>
                        <tr>
                            <td>
                                <?php if ($foto): ?>
                                    <img class="thumb" src="<?= e($foto) ?>" alt="">
                                <?php else: ?>
                                    <span class="thumb thumb-empty">TM</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($produto['codigo_interno']) ?></td>
                            <td>
                                <a href="<?= e(url('/produtos/' . $produto['id'])) ?>"><?= e($produto['nome']) ?></a>
                            </td>
                            <td><?= e($produto['tamanho'] ?: '—') ?></td>
                            <td><?= e($produto['categoria_nome'] ?: '—') ?></td>
                            <td><?= e($produto['colecao_nome'] ?: 'Sem coleção') ?></td>
                            <td>
                                <span class="badge <?= $produto['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= $produto['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="<?= e(url('/produtos/' . $produto['id'] . '/editar')) ?>">Editar</a>
                                <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/duplicar')) ?>"
                                      onsubmit="return confirm('Deseja duplicar este produto?\n\nO produto será copiado com seus dados e composição. Arquivos, variações e estoque não serão duplicados.');">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn">Duplicar</button>
                                </form>
                                <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/status')) ?>">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn">
                                        <?= $produto['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>
                                <a class="link-danger" href="<?= e(url('/produtos/' . $produto['id'] . '/excluir')) ?>">Excluir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a class="<?= $i === $pageNum ? 'is-active' : '' ?>" href="<?= e(url('/produtos') . '?' . $qs(['page' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</div>

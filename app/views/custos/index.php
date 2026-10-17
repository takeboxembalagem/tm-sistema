<?php
$qs = static function (array $extra = []) use ($filters, $pageNum): string {
    $params = array_filter([
        'q' => $filters['q'] ?? '',
        'categoria_id' => $filters['categoria_id'] ?? 0,
        'unidade_id' => $filters['unidade_id'] ?? 0,
        'status' => $filters['status'] ?? '',
        'page' => $extra['page'] ?? $pageNum,
    ], static fn ($v) => $v !== '' && $v !== 0 && $v !== '0');
    return http_build_query($params);
};
?>
<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/custos')) ?>">
        <input type="search" name="q" placeholder="Buscar material..." value="<?= e($filters['q'] ?? '') ?>">
        <select name="categoria_id">
            <option value="">Categoria</option>
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int) $categoria['id'] ?>" <?= ((int) ($filters['categoria_id'] ?? 0) === (int) $categoria['id']) ? 'selected' : '' ?>>
                    <?= e($categoria['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="unidade_id">
            <option value="">Unidade</option>
            <?php foreach ($unidades as $unidade): ?>
                <option value="<?= (int) $unidade['id'] ?>" <?= ((int) ($filters['unidade_id'] ?? 0) === (int) $unidade['id']) ? 'selected' : '' ?>>
                    <?= e($unidade['nome']) ?>
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
    <?php if (Auth::isAdmin()): ?>
        <a class="btn-primary" href="<?= e(url('/custos/novo')) ?>">+ Novo Custo</a>
    <?php endif; ?>
</section>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Material</th>
                    <th>Categoria</th>
                    <th>Unidade</th>
                    <th class="num">Custo atual</th>
                    <th>Fornecedor</th>
                    <th>Atualizado em</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$materiais): ?>
                    <tr>
                        <td colspan="8" class="empty">Nenhum custo encontrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($materiais as $item): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/custos/' . $item['id'])) ?>"><?= e($item['nome']) ?></a>
                            </td>
                            <td><?= e($item['categoria_nome'] ?: '—') ?></td>
                            <td><?= e($item['unidade_sigla'] ?: ($item['unidade_nome'] ?: '—')) ?></td>
                            <td class="num"><?= e(money_br($item['custo_atual'])) ?></td>
                            <td><?= e($item['fornecedor'] ?: '—') ?></td>
                            <td><?= e($item['updated_at'] ? date('d/m/Y H:i', strtotime($item['updated_at'])) : '—') ?></td>
                            <td>
                                <span class="badge <?= $item['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= $item['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <?php if (Auth::isAdmin()): ?>
                                    <a href="<?= e(url('/custos/' . $item['id'] . '/editar')) ?>">Editar</a>
                                    <form method="post" action="<?= e(url('/custos/' . $item['id'] . '/status')) ?>">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">
                                            <?= $item['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="<?= e(url('/custos/' . $item['id'])) ?>">Ver</a>
                                <?php endif; ?>
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
                <a class="<?= $i === $pageNum ? 'is-active' : '' ?>" href="<?= e(url('/custos') . '?' . $qs(['page' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</div>

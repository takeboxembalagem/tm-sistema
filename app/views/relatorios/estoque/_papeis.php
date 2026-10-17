<?php require __DIR__ . '/_indicadores_itens.php'; ?>

<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/relatorios/estoque')) ?>">
        <input type="hidden" name="aba" value="papeis">
        <input type="search" name="cor" placeholder="Cor" value="<?= e($filtros['cor']) ?>">
        <input type="search" name="formato" placeholder="Formato" value="<?= e($filtros['formato']) ?>">
        <input type="search" name="gramatura" placeholder="Gramatura" value="<?= e($filtros['gramatura']) ?>">
        <input type="search" name="local" placeholder="Local" value="<?= e($filtros['local']) ?>">
        <select name="status">
            <option value="">Todos os status</option>
            <option value="ativo" <?= $selected($filtros['status'], 'ativo') ?>>Ativos</option>
            <option value="inativo" <?= $selected($filtros['status'], 'inativo') ?>>Inativos</option>
        </select>
        <select name="situacao">
            <?php foreach (RelatorioEstoque::SITUACOES_ITEM as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['situacao'], $v) ?>><?= e($v === '' ? 'Todas as situações' : $rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
        <a class="btn-secondary" href="<?= e($urlAba('papeis')) ?>">Limpar filtros</a>
    </form>
</section>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Papel</th>
                    <th>Cor</th>
                    <th>Formato</th>
                    <th>Gramatura</th>
                    <th class="num">Quantidade</th>
                    <th>Unidade</th>
                    <th>Local</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pagina['items']): ?>
                    <tr><td colspan="9" class="empty">Nenhum papel encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($pagina['items'] as $r): ?>
                        <tr>
                            <td><?= e($r['codigo']) ?></td>
                            <td><a href="<?= e(url('/estoque/papeis/' . (int) $r['id'])) ?>"><?= e($r['nome']) ?></a></td>
                            <td><?= e($r['cor'] ?: '-') ?></td>
                            <td><?= e($r['formato'] ?: '-') ?></td>
                            <td><?= e($r['gramatura'] ?: '-') ?></td>
                            <td class="num"><strong><?= e(number_br($r['quantidade'], 0)) ?></strong></td>
                            <td><?= e($r['unidade']) ?></td>
                            <td><?= e($r['localizacao'] ?: '-') ?></td>
                            <td><?= $statusBadge((string) $r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="hint"><?= e(number_br($pagina['total'], 0)) ?> papel(éis) listado(s).</p>

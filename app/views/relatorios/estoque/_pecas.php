<?php require __DIR__ . '/_indicadores_itens.php'; ?>

<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/relatorios/estoque')) ?>">
        <input type="hidden" name="aba" value="pecas">
        <select name="tipo">
            <option value="">Todos os tipos</option>
            <?php foreach (RelatorioEstoque::TIPOS_PECA as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['tipo'], $v) ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="produto_id">
            <option value="">Todos os produtos</option>
            <?php foreach ($produtos as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= $selected($filtros['produto_id'], $p['id']) ?>><?= e($p['nome'] . ' (' . $p['codigo_interno'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="cor" placeholder="Cor" value="<?= e($filtros['cor']) ?>">
        <input type="search" name="estampa" placeholder="Estampa" value="<?= e($filtros['estampa']) ?>">
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
        <a class="btn-secondary" href="<?= e($urlAba('pecas')) ?>">Limpar filtros</a>
    </form>
</section>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Peça</th>
                    <th>Tipo</th>
                    <th>Produto</th>
                    <th>Cor</th>
                    <th>Estampa</th>
                    <th class="num">Quantidade</th>
                    <th>Unidade</th>
                    <th>Local</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pagina['items']): ?>
                    <tr><td colspan="10" class="empty">Nenhuma peça encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($pagina['items'] as $r): ?>
                        <tr>
                            <td><?= e($r['codigo']) ?></td>
                            <td><a href="<?= e(url('/estoque/pecas/' . (int) $r['id'])) ?>"><?= e($r['nome']) ?></a></td>
                            <td><?= e($tipoPecaLabel($r['tipo'])) ?></td>
                            <td><?= e($r['produto_nome'] ?: '-') ?></td>
                            <td><?= e($r['cor'] ?: '-') ?></td>
                            <td><?= e($r['estampa'] ?: '-') ?></td>
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
<p class="hint"><?= e(number_br($pagina['total'], 0)) ?> peça(s) listada(s).</p>

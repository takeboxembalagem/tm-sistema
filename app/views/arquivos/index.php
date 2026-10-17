<?php
$fmt = static function (?string $dt): string {
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : $dt;
};
$totalGeral = 0;
foreach ($produtosLista as $p) {
    $totalGeral += (int) ($p['total_arquivos'] ?? 0);
}
?>
<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/arquivos')) ?>">
        <input type="search" name="q" placeholder="Buscar caixa, produto ou arquivo..."
               value="<?= e($q ?? '') ?>" style="min-width:min(100%,320px)">
        <button type="submit" class="btn-secondary">Buscar</button>
        <?php if (($q ?? '') !== ''): ?>
            <a class="btn-secondary" href="<?= e(url('/arquivos')) ?>">Limpar</a>
        <?php endif; ?>
    </form>
</section>

<?php if (!empty($avisosLimite)): ?>
    <div class="alert alert-warn">
        <?php foreach ($avisosLimite as $aviso): ?>
            <div><?= e($aviso) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card table-card">
    <div class="toolbar" style="margin-bottom:8px">
        <div>
            <h2 style="margin:0">Caixas / Produtos</h2>
            <p class="hint" style="margin:4px 0 0">
                <?= (int) count($produtosLista) ?> produto(s)
                · <?= (int) $totalGeral ?> arquivo(s)
                <?php if (($q ?? '') !== ''): ?>
                    · filtro: “<?= e($q) ?>”
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Caixa / Produto</th>
                    <th class="num">Arquivos</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$produtosLista): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            <?php if (($q ?? '') !== ''): ?>
                                Nenhum produto ou arquivo correspondente à busca.
                            <?php else: ?>
                                Nenhum produto cadastrado. Cadastre produtos para organizar os arquivos.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($produtosLista as $item): ?>
                        <?php $n = (int) ($item['total_arquivos'] ?? 0); ?>
                        <tr>
                            <td><strong><?= e($item['codigo_interno']) ?></strong></td>
                            <td><?= e($item['nome']) ?></td>
                            <td class="num"><?= $n ?> arquivo<?= $n === 1 ? '' : 's' ?></td>
                            <td>
                                <span class="badge <?= ($item['status'] ?? '') === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= ($item['status'] ?? '') === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a class="btn-secondary" href="<?= e(url('/arquivos/produto/' . $item['id'])) ?>">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<section class="grid-kpis" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
    <div class="card"><span class="kpi-label">Itens cadastrados</span><span class="kpi-value"><?= e(number_br($indicadores['total'], 0)) ?></span></div>
    <div class="card"><span class="kpi-label">Com estoque</span><span class="kpi-value"><?= e(number_br($indicadores['com_estoque'], 0)) ?></span></div>
    <div class="card"><span class="kpi-label">Zerados</span><span class="kpi-value"><?= e(number_br($indicadores['zerado'], 0)) ?></span></div>
    <div class="card">
        <span class="kpi-label">Quantidade total</span>
        <?php if ($indicadores['quantidade_total'] !== null): ?>
            <span class="kpi-value"><?= e(number_br($indicadores['quantidade_total'], 0)) ?></span> <?= e((string) $indicadores['unidade']) ?>
        <?php elseif ($indicadores['total'] === 0): ?>
            <span class="kpi-value">0</span>
        <?php else: ?>
            <span class="hint">Unidades diferentes — total não somado.</span>
        <?php endif; ?>
    </div>
</section>

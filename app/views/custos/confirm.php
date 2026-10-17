<?php
$anterior = (float) $pending['custo_anterior'];
$novo = (float) $pending['custo_novo'];
if ($anterior == 0.0) {
    $percentual = $novo == 0.0 ? 0.0 : 100.0;
} else {
    $percentual = (($novo - $anterior) / $anterior) * 100;
}
?>
<div class="card form-card">
    <h2>Alteração de custo</h2>
    <p><strong><?= e($material['nome']) ?></strong></p>

    <dl class="defs" style="margin:18px 0">
        <dt>Custo atual</dt>
        <dd><strong><?= e(money_br($anterior)) ?></strong></dd>
        <dt>Novo custo</dt>
        <dd><strong><?= e(money_br($novo)) ?></strong></dd>
        <dt>Variação</dt>
        <dd><strong><?= e(percent_br($percentual)) ?></strong></dd>
    </dl>

    <div class="alert alert-warn">
        Essa alteração afetará o custo dos produtos que utilizam este material.
    </div>

    <form method="post" action="<?= e(url('/custos/' . $material['id'] . '/confirmar-custo')) ?>" class="form-actions">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-primary">Confirmar alteração</button>
        <a class="btn-secondary" href="<?= e(url('/custos/' . $material['id'] . '/editar')) ?>">Cancelar</a>
    </form>
</div>

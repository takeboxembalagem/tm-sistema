<?php
$produto = $produto ?? [];
$variacao = $variacao ?? null;
$voltarUrl = $voltarUrl ?? '#';
$urlCadastrado = $urlCadastrado ?? '#';
$urlManual = $urlManual ?? '#';
?>
<div class="card form-card">
    <h2 style="margin-top:0">Adicionar custo</h2>
    <?php if ($variacao): ?>
        <p class="hint" style="margin-top:0">
            Variação: <strong><?= e($variacao['codigo'] . ' — ' . $variacao['nome']) ?></strong>
            · Produto: <?= e(($produto['codigo_interno'] ?? '') . ' — ' . ($produto['nome'] ?? '')) ?>
        </p>
    <?php else: ?>
        <p class="hint" style="margin-top:0">
            Produto: <strong><?= e(($produto['codigo_interno'] ?? '') . ' — ' . ($produto['nome'] ?? '')) ?></strong>
        </p>
    <?php endif; ?>

    <p class="hint">Escolha como deseja incluir o custo nesta formação:</p>

    <div style="display:grid; gap:12px; max-width:420px; margin:16px 0">
        <a class="btn-primary" href="<?= e($urlCadastrado) ?>" style="text-align:center; text-decoration:none">
            Custo cadastrado
        </a>
        <a class="btn-secondary" href="<?= e($urlManual) ?>" style="text-align:center; text-decoration:none">
            Custo manual
        </a>
    </div>

    <p class="hint" style="margin-bottom:0">
        <strong>Cadastrado</strong> = vem do módulo Custos.<br>
        <strong>Manual</strong> = só nesta formação, sem cadastrar globalmente.
    </p>

    <div class="form-actions" style="margin-top:20px">
        <a class="btn-secondary" href="<?= e($voltarUrl) ?>">Cancelar</a>
    </div>
</div>

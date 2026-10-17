<?php
$old = $old ?? [];
$estoqueAtual = (int) ($variacao['estoque'] ?? 0);
$estoqueVal = array_key_exists('estoque', $old) ? (string) $old['estoque'] : (string) $estoqueAtual;
$motivoVal = (string) ($old['motivo'] ?? '');
$produtoId = (int) $produto['id'];
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2>Ajustar estoque</h2>
    <p class="hint" style="margin-top:0">
        <?= e($produto['codigo_interno'] . ' — ' . $produto['nome']) ?>
        · variação <strong><?= e($variacao['nome']) ?></strong>
        (<?= e($variacao['codigo']) ?>)
    </p>
    <p class="hint">Estoque atual: <strong><?= e(number_br($estoqueAtual, 0)) ?></strong> unidades</p>

    <form method="post" action="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/estoque')) ?>" class="form">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Nova quantidade *
                <input type="number" name="estoque" min="0" step="1" required value="<?= e($estoqueVal) ?>">
            </label>
            <label>
                Motivo *
                <select name="motivo" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($motivos as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $motivoVal === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="hint">O estoque não pode ficar negativo. Não há movimentação completa neste momento — apenas o saldo atual.</p>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Salvar ajuste</button>
            <a class="btn-secondary" href="<?= e(url('/produtos/' . $produtoId . '#variacoes')) ?>">Cancelar</a>
        </div>
    </form>
</div>

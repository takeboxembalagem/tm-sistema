<?php
$isEdit = !empty($item);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $item) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($item && array_key_exists($key, $item) && $item[$key] !== null) {
        return (string) $item[$key];
    }
    return (string) $fallback;
};
$action = $isEdit ? url('/estoque/papeis/' . $item['id']) : url('/estoque/papeis');
$capa = $isEdit ? upload_url($item['imagem'] ?? null) : null;
?>
<?php require __DIR__ . '/../_tabs.php'; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2><?= $isEdit ? 'Editar papel' : 'Novo papel' ?></h2>
    <p class="hint">Estoque físico de papéis. O saldo só muda por movimentos<?= $isEdit ? '' : ' (ou quantidade inicial)' ?>.</p>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Código *
                <input type="text" name="codigo" required maxlength="40" value="<?= e($val('codigo')) ?>">
            </label>
            <label>
                Nome *
                <input type="text" name="nome" required maxlength="180" value="<?= e($val('nome')) ?>">
            </label>
            <label>
                Cor
                <input type="text" name="cor" maxlength="80" value="<?= e($val('cor')) ?>">
            </label>
            <label>
                Formato
                <input type="text" name="formato" maxlength="80" value="<?= e($val('formato')) ?>" placeholder="Ex.: 66x96">
            </label>
            <label>
                Gramatura
                <input type="text" name="gramatura" maxlength="40" value="<?= e($val('gramatura')) ?>" placeholder="Ex.: 180g">
            </label>
            <?php if (!$isEdit): ?>
                <label>
                    Quantidade inicial
                    <input type="text" name="quantidade" value="<?= e($val('quantidade', '0')) ?>" placeholder="0">
                </label>
            <?php endif; ?>
            <label>
                Unidade
                <input type="text" name="unidade" maxlength="20" value="<?= e($val('unidade', 'folha')) ?>">
            </label>
            <label>
                Localização
                <input type="text" name="localizacao" maxlength="120" value="<?= e($val('localizacao')) ?>">
            </label>
            <label class="span-2">
                Observação
                <textarea name="observacao" rows="2"><?= e($val('observacao')) ?></textarea>
            </label>
            <label>
                Imagem
                <input type="file" name="imagem" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <small class="hint">JPG, PNG ou WEBP · máx. 2 MB · opcional</small>
            </label>
            <?php if ($isEdit): ?>
                <label>
                    Status
                    <select name="status">
                        <option value="ativo" <?= $val('status', 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inativo" <?= $val('status') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <?php if ($capa): ?>
            <p class="hint">Imagem atual</p>
            <img class="preview" src="<?= e($capa) ?>" alt="">
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Cadastrar papel' ?></button>
            <a class="btn-secondary" href="<?= e(url($isEdit ? '/estoque/papeis/' . $item['id'] : '/estoque/papeis')) ?>">Cancelar</a>
        </div>
    </form>
</div>

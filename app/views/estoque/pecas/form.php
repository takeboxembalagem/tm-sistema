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
$action = $isEdit ? url('/estoque/pecas/' . $item['id']) : url('/estoque/pecas');
$capa = $isEdit ? upload_url($item['imagem'] ?? null) : null;
$produtoSel = $val('produto_id');
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
    <h2><?= $isEdit ? 'Editar peça' : 'Nova peça' ?></h2>
    <p class="hint">Estoque físico de tampas, fundos e outras peças. O saldo só muda por movimentos<?= $isEdit ? '' : ' (ou quantidade inicial)' ?>.</p>

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
                Tipo *
                <select name="tipo">
                    <option value="tampa" <?= $val('tipo', 'outra') === 'tampa' ? 'selected' : '' ?>>Tampa</option>
                    <option value="fundo" <?= $val('tipo') === 'fundo' ? 'selected' : '' ?>>Fundo</option>
                    <option value="outra" <?= $val('tipo', 'outra') === 'outra' ? 'selected' : '' ?>>Outra</option>
                </select>
            </label>
            <label>
                Produto (opcional)
                <select name="produto_id">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($produtos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $produtoSel === (string) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['codigo_interno'] . ' — ' . $p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Cor
                <input type="text" name="cor" maxlength="80" value="<?= e($val('cor')) ?>">
            </label>
            <label>
                Estampa
                <input type="text" name="estampa" maxlength="120" value="<?= e($val('estampa')) ?>">
            </label>
            <?php if (!$isEdit): ?>
                <label>
                    Quantidade inicial
                    <input type="text" name="quantidade" value="<?= e($val('quantidade', '0')) ?>" placeholder="0">
                </label>
            <?php endif; ?>
            <label>
                Unidade
                <input type="text" name="unidade" maxlength="20" value="<?= e($val('unidade', 'un')) ?>">
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
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Cadastrar peça' ?></button>
            <a class="btn-secondary" href="<?= e(url($isEdit ? '/estoque/pecas/' . $item['id'] : '/estoque/pecas')) ?>">Cancelar</a>
        </div>
    </form>
</div>

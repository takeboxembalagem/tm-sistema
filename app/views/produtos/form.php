<?php
$isEdit = !empty($produto);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $produto) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($produto && array_key_exists($key, $produto) && $produto[$key] !== null) {
        return (string) $produto[$key];
    }
    return (string) $fallback;
};
$action = $isEdit ? url('/produtos/' . $produto['id']) : url('/produtos');
$foto = $isEdit ? upload_url($produto['foto_principal'] ?? null) : null;
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2>Informações do Produto</h2>
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Código *
                <input type="text" name="codigo_interno" required maxlength="40" value="<?= e($val('codigo_interno')) ?>">
            </label>
            <label>
                Nome *
                <input type="text" name="nome" required maxlength="180" value="<?= e($val('nome')) ?>">
            </label>
            <label>
                Tamanho montada
                <input type="text" name="tamanho" maxlength="80" placeholder="Ex.: 8 x 8 x 4 cm" value="<?= e($val('tamanho')) ?>">
            </label>
            <label>
                Tamanho aberta
                <input type="text" name="tamanho_aberta" maxlength="80" placeholder="Ex.: 24 x 16 cm" value="<?= e($val('tamanho_aberta')) ?>">
            </label>
            <label class="full">
                Descrição
                <textarea name="descricao" rows="4"><?= e($val('descricao')) ?></textarea>
            </label>
            <label>
                Categoria
                <select name="categoria_id">
                    <option value="">Selecione</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?= (int) $categoria['id'] ?>" <?= ((int) $val('categoria_id') === (int) $categoria['id']) ? 'selected' : '' ?>>
                            <?= e($categoria['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Coleção
                <select name="colecao_id">
                    <option value="">Sem coleção</option>
                    <?php foreach ($colecoes as $colecao): ?>
                        <option value="<?= (int) $colecao['id'] ?>" <?= ((int) $val('colecao_id') === (int) $colecao['id']) ? 'selected' : '' ?>>
                            <?= e($colecao['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Imagem
                <input type="file" name="foto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
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
            <label>
                Quantidade por pacote
                <input type="number" name="quantidade_pacote" min="1" step="1"
                       placeholder="Ex.: 10 (opcional)"
                       value="<?= e($val('quantidade_pacote')) ?>">
                <span class="hint">Inteiro positivo. Vazio = sem pacote. Não altera custo/preço unitário.</span>
            </label>
            <label>
                Peso do pacote (kg)
                <input type="text" name="peso_pacote_kg" inputmode="decimal"
                       placeholder="Ex.: 1,250"
                       value="<?= e($val('peso_pacote_kg')) ?>">
                <span class="hint">Opcional. Use vírgula ou ponto (ex.: 0,850).</span>
            </label>
        </div>
        <?php if ($foto): ?>
            <p class="hint">Imagem atual</p>
            <img class="preview" src="<?= e($foto) ?>" alt="">
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar Produto' : 'Salvar Produto' ?></button>
            <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Cancelar</a>
        </div>
    </form>
</div>

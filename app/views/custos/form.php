<?php
$isEdit = !empty($material);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $material) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($material && array_key_exists($key, $material) && $material[$key] !== null) {
        if ($key === 'custo_atual') {
            return number_format((float) $material[$key], 4, ',', '.');
        }
        return (string) $material[$key];
    }
    return (string) $fallback;
};
$action = $isEdit ? url('/custos/' . $material['id']) : url('/custos');
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2><?= $isEdit ? 'Editar custo' : 'Novo custo' ?></h2>
    <form method="post" action="<?= e($action) ?>" class="form">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label class="full">
                Nome *
                <input type="text" name="nome" required maxlength="180" value="<?= e($val('nome')) ?>">
            </label>
            <label>
                Categoria *
                <select name="categoria_material_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($categorias as $categoria): ?>
                        <option value="<?= (int) $categoria['id'] ?>" <?= ((int) $val('categoria_material_id') === (int) $categoria['id']) ? 'selected' : '' ?>>
                            <?= e($categoria['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Unidade *
                <select name="unidade_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($unidades as $unidade): ?>
                        <option value="<?= (int) $unidade['id'] ?>" <?= ((int) $val('unidade_id') === (int) $unidade['id']) ? 'selected' : '' ?>>
                            <?= e($unidade['nome']) ?> (<?= e($unidade['sigla']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Modalidade de cobrança *
                <select name="forma_cobranca" id="forma_cobranca" required>
                    <?php
                    $formaAtual = $val('forma_cobranca', 'unidade');
                    $formaUi = normalizar_forma_cobranca($formaAtual);
                    if (!array_key_exists($formaUi, formas_cobranca_cadastro())) {
                        $formaUi = 'unidade';
                    }
                    foreach (formas_cobranca_cadastro() as $key => $label):
                    ?>
                        <option value="<?= e($key) ?>" <?= $formaUi === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Custo atual *
                <input type="text" name="custo_atual" required inputmode="decimal" placeholder="0,00" value="<?= e($val('custo_atual', '0,00')) ?>">
            </label>
            <label>
                Fornecedor
                <input type="text" name="fornecedor" maxlength="180" value="<?= e($val('fornecedor')) ?>">
            </label>
            <label class="full">
                Observação
                <textarea name="observacao" rows="3"><?= e($val('observacao')) ?></textarea>
            </label>
            <?php if ($isEdit): ?>
                <label class="full">
                    Observação da alteração de custo
                    <input type="text" name="observacao_historico" maxlength="255" placeholder="Ex.: Reajuste do fornecedor" value="<?= e($val('observacao_historico')) ?>">
                </label>
                <label>
                    Status
                    <select name="status">
                        <option value="ativo" <?= $val('status', 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inativo" <?= $val('status') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <p class="hint">A modalidade classifica o custo (referência). Na formação do produto o preço cadastrado entra como valor absoluto. Use até 4 casas decimais (ex.: 0,0045).</p>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Salvar</button>
            <a class="btn-secondary" href="<?= e(url('/custos')) ?>">Cancelar</a>
        </div>
    </form>
</div>

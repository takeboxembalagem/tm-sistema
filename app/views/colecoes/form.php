<?php
$isEdit = !empty($colecao);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $colecao) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($colecao && array_key_exists($key, $colecao) && $colecao[$key] !== null) {
        return (string) $colecao[$key];
    }
    return (string) $fallback;
};
$action = $isEdit ? url('/colecoes/' . $colecao['id']) : url('/colecoes');
$capa = $isEdit ? upload_url($colecao['imagem'] ?? null) : null;
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2><?= $isEdit ? 'Editar coleção' : 'Nova coleção' ?></h2>
    <p class="hint">Organize produtos em linhas comerciais (Natal, Páscoa, Premium…). Não altera custo nem preço.</p>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Nome *
                <input type="text" name="nome" required maxlength="120" value="<?= e($val('nome')) ?>"
                       placeholder="Ex.: Natal 2027">
            </label>
            <label>
                Código
                <input type="text" name="codigo" maxlength="40" value="<?= e($val('codigo')) ?>"
                       placeholder="Opcional">
            </label>
            <label>
                Tipo *
                <select name="tipo" required>
                    <?php foreach (Colecao::TIPOS as $tipoValor => $tipoRotulo): ?>
                        <option value="<?= e($tipoValor) ?>" <?= $val('tipo', 'especial') === $tipoValor ? 'selected' : '' ?>><?= e($tipoRotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Etapa *
                <select name="etapa" required>
                    <?php foreach (Colecao::ETAPAS as $etapaValor => $etapaRotulo): ?>
                        <option value="<?= e($etapaValor) ?>" <?= $val('etapa', 'planejamento') === $etapaValor ? 'selected' : '' ?>><?= e($etapaRotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Período inicial
                <input type="date" name="periodo_inicio" value="<?= e($val('periodo_inicio')) ?>">
            </label>
            <label>
                Período final
                <input type="date" name="periodo_fim" value="<?= e($val('periodo_fim')) ?>">
            </label>
            <label class="span-2">
                Descrição
                <textarea name="descricao" rows="3" placeholder="Linha de embalagens…"><?= e($val('descricao')) ?></textarea>
            </label>
            <label class="span-2">
                Observações
                <textarea name="observacoes" rows="2"><?= e($val('observacoes')) ?></textarea>
            </label>
            <label>
                Imagem / capa
                <input type="file" name="imagem" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                <small class="hint">JPG, PNG ou WEBP · máx. 2 MB · opcional</small>
            </label>
            <?php if ($isEdit): ?>
                <label>
                    Status
                    <select name="status">
                        <option value="ativo" <?= $val('status', 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativa</option>
                        <option value="inativo" <?= $val('status') === 'inativo' ? 'selected' : '' ?>>Inativa</option>
                    </select>
                </label>
            <?php endif; ?>
        </div>
        <?php if ($capa): ?>
            <p class="hint">Capa atual</p>
            <img class="preview" src="<?= e($capa) ?>" alt="">
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Cadastrar coleção' ?></button>
            <a class="btn-secondary" href="<?= e(url($isEdit ? '/colecoes/' . $colecao['id'] : '/colecoes')) ?>">Cancelar</a>
        </div>
    </form>
</div>

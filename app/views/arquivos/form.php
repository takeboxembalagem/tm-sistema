<?php
$isEdit = !empty($arquivo);
$old = $old ?? [];
$produtoFixo = $produtoFixo ?? null;
$produtoId = (int) ($produtoFixo['id'] ?? $old['produto_id'] ?? $produtoPrefill ?? $arquivo['produto_id'] ?? 0);
$tipo = (string) ($old['tipo'] ?? $arquivo['tipo'] ?? 'outro');
$nome = (string) ($old['nome'] ?? $arquivo['nome'] ?? '');
$observacao = (string) ($old['observacao'] ?? $arquivo['observacao'] ?? '');
$action = $isEdit ? url('/arquivos/' . $arquivo['id']) : url('/arquivos');
$voltarUrl = $produtoId > 0 ? url('/arquivos/produto/' . $produtoId) : url('/arquivos');
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($avisosLimite)): ?>
    <div class="alert alert-warn">
        <?php foreach ($avisosLimite as $aviso): ?>
            <div><?= e($aviso) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <h2><?= $isEdit ? 'Editar arquivo' : 'Adicionar arquivo' ?></h2>
    <?php if ($produtoFixo): ?>
        <p class="hint" style="margin-top:0">
            Caixa / produto:
            <strong><?= e($produtoFixo['codigo_interno'] . ' — ' . $produtoFixo['nome']) ?></strong>
        </p>
    <?php endif; ?>
    <p class="hint">
        Limite: até <?= e((string) ($limiteMb ?? 20)) ?> MB.
        Formatos: JPG, PNG, WEBP, CDR, AI, EPS, PDF, ZIP, RAR.
    </p>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <input type="hidden" name="produto_id" value="<?= (int) $produtoId ?>">

        <div class="form-grid">
            <label>
                Tipo de arquivo
                <select name="tipo" required>
                    <?php foreach ($tipos as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $tipo === $key ? 'selected' : '' ?>>
                            <?= e(Arquivo::tipoIcone($key) . ' ' . $label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="span-2">
                Nome / descrição
                <input type="text" name="nome" maxlength="180" required value="<?= e($nome) ?>"
                       placeholder="Ex.: Nesting Caixa 38 — 12 unidades">
            </label>

            <label class="span-2">
                <?= $isEdit ? 'Substituir arquivo (opcional)' : 'Arquivo' ?>
                <input type="file" name="arquivo" <?= $isEdit ? '' : 'required' ?>
                       accept=".jpg,.jpeg,.png,.webp,.cdr,.ai,.eps,.pdf,.zip,.rar,image/jpeg,image/png,image/webp,application/pdf">
                <?php if ($isEdit): ?>
                    <small class="hint">
                        Atual: <strong><?= e($arquivo['nome_original']) ?></strong>
                        (<?= e(strtoupper((string) $arquivo['extensao'])) ?>,
                        <?= e(Arquivo::formatTamanho(isset($arquivo['tamanho']) ? (int) $arquivo['tamanho'] : null)) ?>)
                        — deixe em branco para manter o arquivo atual.
                    </small>
                <?php endif; ?>
            </label>

            <label class="span-2">
                Observação
                <textarea name="observacao" rows="3" maxlength="2000"
                          placeholder="Opcional"><?= e($observacao) ?></textarea>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Enviar arquivo' ?></button>
            <a class="btn-secondary" href="<?= e($voltarUrl) ?>">Cancelar</a>
        </div>
    </form>
</div>

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
$produto = $produto ?? [];
$variacao = $variacao ?? null;
$action = $action ?? '#';
$voltarUrl = $voltarUrl ?? '#';
$prodRef = Composicao::resolverProducao($produto);

$descDefault = $val('descricao', $val('descricao_manual'));
$precoDefault = '';
if (array_key_exists('preco', $old)) {
    $precoDefault = (string) $old['preco'];
} elseif ($isEdit) {
    $p = (float) ($item['preco_manual'] ?? $item['custo_atual'] ?? 0);
    $precoDefault = number_format($p, 2, ',', '.');
}
$qtdDefault = '1';
if (array_key_exists('quantidade', $old)) {
    $qtdDefault = (string) $old['quantidade'];
} elseif ($isEdit && isset($item['quantidade'])) {
    $q = (float) $item['quantidade'];
    $qtdDefault = $q > 0 ? rtrim(rtrim(number_format($q, 4, ',', ''), '0'), ',') : '1';
}
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <?php if ($variacao): ?>
        <p class="hint">
            Variação: <strong><?= e($variacao['codigo'] . ' — ' . $variacao['nome']) ?></strong>
            · Produto: <?= e($produto['codigo_interno'] . ' — ' . $produto['nome']) ?>
        </p>
    <?php else: ?>
        <p class="hint">Produto: <strong><?= e($produto['codigo_interno']) ?> — <?= e($produto['nome']) ?></strong></p>
    <?php endif; ?>
    <p class="hint">
        Produção:
        <strong><?= e(number_br((int) $prodRef['folhas'], 0)) ?> folhas</strong>
        × <strong><?= e(number_br((int) $prodRef['rendimento'], 0)) ?> pç/folha</strong>
        = <strong><?= e(number_br((int) $prodRef['pecas'], 0)) ?> peças</strong>
    </p>

    <h2><?= $isEdit ? 'Editar custo manual' : 'Custo manual' ?></h2>
    <p class="hint">Não cria registro no módulo Custos. Modalidade: <strong>Manual</strong> (somente informativa). Cálculo: preço × quantidade.</p>

    <form method="post" action="<?= e($action) ?>" class="form" id="form-custo-manual">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label class="full">
                Descrição *
                <input type="text" name="descricao" maxlength="160" required
                       value="<?= e($descDefault) ?>" placeholder="Ex.: Etiqueta Caixa 01">
            </label>
            <label>
                Preço unitário *
                <input type="text" name="preco" id="preco_manual" inputmode="decimal" required
                       value="<?= e($precoDefault) ?>" placeholder="0,35">
            </label>
            <label>
                Quantidade *
                <input type="text" name="quantidade" id="qtd_manual" inputmode="decimal" required
                       value="<?= e($qtdDefault) ?>">
            </label>
        </div>

        <div id="manual-preview" class="card" style="margin:12px 0; padding:12px; background:var(--surface-2, #f7f7f7)">
            <p class="hint" style="margin:0">Prévia nesta produção</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Adicionar' ?></button>
            <a class="btn-secondary" href="<?= e($voltarUrl) ?>">Cancelar</a>
        </div>
    </form>
</div>

<script>
(function () {
    var pecas = <?= (int) $prodRef['pecas'] ?>;
    var precoEl = document.getElementById('preco_manual');
    var qtdEl = document.getElementById('qtd_manual');
    var info = document.getElementById('manual-preview');
    if (!precoEl || !qtdEl || !info) return;
    function parseBr(v) {
        v = (v || '').toString().trim().replace(/[^\d,.\-]/g, '');
        if (!v) return null;
        if (v.indexOf(',') >= 0 && v.indexOf('.') >= 0) v = v.replace(/\./g, '').replace(',', '.');
        else if (v.indexOf(',') >= 0) v = v.replace(',', '.');
        var n = parseFloat(v);
        return isNaN(n) ? null : n;
    }
    function ceilMoney(n) {
        if (n <= 0) return 0;
        return Math.ceil((n * 100) - 1e-9) / 100;
    }
    function money(n) {
        return 'R$ ' + ceilMoney(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function refresh() {
        var p = parseBr(precoEl.value);
        var q = parseBr(qtdEl.value);
        if (p === null || p < 0) p = 0;
        if (q === null || q <= 0) q = 1;
        var lote = p * q;
        var unit = pecas > 0 ? lote / pecas : 0;
        info.innerHTML =
            '<p style="margin:0 0 6px"><span class="hint">Modalidade: </span><strong>Manual</strong></p>' +
            '<p style="margin:0 0 6px"><span class="hint">Nesta produção: </span><strong>' + money(lote) + '</strong></p>' +
            '<p style="margin:0"><span class="hint">Por peça: </span><strong>' + money(unit) + '</strong></p>';
    }
    precoEl.addEventListener('input', refresh);
    qtdEl.addEventListener('input', refresh);
    refresh();
})();
</script>

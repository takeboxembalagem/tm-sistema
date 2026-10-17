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
$action = $isEdit
    ? url('/produtos/' . $produto['id'] . '/composicao/' . $item['id'])
    : url('/produtos/' . $produto['id'] . '/composicao');
$prodRef = Composicao::resolverProducao($produto);
$materiaisJson = [];
foreach ($materiais as $m) {
    $forma = normalizar_forma_cobranca((string) ($m['forma_cobranca'] ?? 'unidade'));
    $materiaisJson[(int) $m['id']] = [
        'nome' => $m['nome'],
        'custo' => (float) $m['custo_atual'],
        'custo_fmt' => money_br($m['custo_atual']),
        'categoria' => $m['categoria_nome'] ?: '—',
        'forma' => $forma,
        'forma_label' => forma_cobranca_label($forma),
    ];
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
    <p class="hint">Produto: <strong><?= e($produto['codigo_interno']) ?> — <?= e($produto['nome']) ?></strong></p>
    <p class="hint">
        Produção:
        <strong><?= e(number_br((int) $prodRef['folhas'], 0)) ?> folhas</strong>
        × <strong><?= e(number_br((int) $prodRef['rendimento'], 0)) ?> pç/folha</strong>
        = <strong><?= e(number_br((int) $prodRef['pecas'], 0)) ?> peças</strong>
    </p>

    <h2><?= $isEdit ? 'Trocar custo' : 'Adicionar custo cadastrado' ?></h2>
    <p class="hint">Selecione um custo já cadastrado. A modalidade e o preço vêm do módulo Custos.</p>

    <?php if (!$materiais): ?>
        <div class="alert alert-error">Não há custos ativos disponíveis. Cadastre em Custos ou remova um já vinculado.</div>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('/produtos/' . $produto['id'] . '#composicao')) ?>">Voltar</a>
            <?php if (Auth::isAdmin()): ?>
                <a class="btn-primary" href="<?= e(url('/custos/novo')) ?>">Novo custo</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e($action) ?>" class="form" id="form-add-custo">
            <?= Csrf::field() ?>
            <div class="form-grid">
                <label class="full">
                    Buscar custo
                    <input type="search" id="material_busca" data-busca-select="material_id" data-busca-status="material_busca_status"
                           placeholder="Digite parte do nome (ex.: 250, cartão)" autocomplete="off">
                    <span class="hint" id="material_busca_status" aria-live="polite"></span>
                </label>
                <label class="full">
                    Custo *
                    <select name="material_id" id="material_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($materiais as $m): ?>
                            <?php $forma = normalizar_forma_cobranca((string) ($m['forma_cobranca'] ?? 'unidade')); ?>
                            <option value="<?= (int) $m['id'] ?>" data-nome="<?= e($m['nome']) ?>" <?= (int) $val('material_id') === (int) $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['nome']) ?> · <?= e(forma_cobranca_label($forma)) ?> · <?= e(money_br($m['custo_atual'])) ?><?= ($m['status'] ?? 'ativo') === 'inativo' ? ' · INATIVO (atual)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Quantidade *
                    <?php
                    $qtdDefault = '1';
                    if (array_key_exists('quantidade', $old)) {
                        $qtdDefault = (string) $old['quantidade'];
                    } elseif ($isEdit && isset($item['quantidade'])) {
                        $q = (float) $item['quantidade'];
                        $qtdDefault = $q > 0 ? rtrim(rtrim(number_format($q, 4, ',', ''), '0'), ',') : '1';
                    }
                    ?>
                    <input type="text" name="quantidade" id="quantidade_custo" inputmode="decimal" required value="<?= e($qtdDefault) ?>">
                </label>
            </div>

            <div id="material-info" class="card" style="margin:12px 0; padding:12px; background:var(--surface-2, #f7f7f7)">
                <p class="hint" style="margin:0">Selecione um custo para ver modalidade, preço e prévia nesta produção.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar' : 'Adicionar' ?></button>
                <a class="btn-secondary" href="<?= e(url('/produtos/' . $produto['id'] . '#composicao')) ?>">Cancelar</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="<?= e(url('/assets/js/busca-custo.js')) ?>"></script>
<script>
(function () {
    var data = <?= json_encode($materiaisJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var pecas = <?= (int) $prodRef['pecas'] ?>;
    var select = document.getElementById('material_id');
    var qtdEl = document.getElementById('quantidade_custo');
    var info = document.getElementById('material-info');
    if (!select || !info) return;

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
        var m = data[select.value];
        while (info.firstChild) info.removeChild(info.firstChild);
        if (!m) {
            var p = document.createElement('p');
            p.className = 'hint';
            p.style.margin = '0';
            p.textContent = 'Selecione um custo para ver modalidade, preço e prévia nesta produção.';
            info.appendChild(p);
            return;
        }
        var q = qtdEl ? parseBr(qtdEl.value) : 1;
        if (q === null || q <= 0) q = 1;
        var lote = m.custo * q;
        var unit = pecas > 0 ? lote / pecas : 0;
        var lines = [
            ['Custo', m.nome],
            ['Modalidade', m.forma_label],
            ['Preço', m.custo_fmt],
            ['Quantidade', String(q)],
            ['Nesta produção', money(lote)],
            ['Por peça', money(unit)]
        ];
        lines.forEach(function (pair) {
            var row = document.createElement('p');
            row.style.margin = '0 0 6px';
            var lab = document.createElement('span');
            lab.className = 'hint';
            lab.textContent = pair[0] + ': ';
            var val = document.createElement('strong');
            val.textContent = pair[1];
            row.appendChild(lab);
            row.appendChild(val);
            info.appendChild(row);
        });
    }
    select.addEventListener('change', refresh);
    if (qtdEl) qtdEl.addEventListener('input', refresh);
    refresh();
})();
</script>

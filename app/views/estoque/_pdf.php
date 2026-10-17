<?php
$pdfPecas = ($estoqueTab ?? 'pecas') === 'pecas';
$pdfBase = $pdfPecas ? '/estoque/pecas' : '/estoque/papeis';
?>
<section class="card pdf-docs">
    <div class="pdf-docs-item">
        <h2>Inventário em PDF</h2>
        <p class="hint">
            <?= $pdfPecas ? 'Todas as peças cadastradas' : 'Todos os papéis cadastrados' ?>, inclusive sem saldo,
            com o saldo do momento da geração e uma coluna para a contagem física.
        </p>
        <a class="btn-secondary" href="<?= e(url($pdfBase . '/pdf/inventario')) ?>" target="_blank" rel="noopener">Gerar PDF do inventário</a>
    </div>
    <form class="pdf-docs-item" method="get" action="<?= e(url($pdfBase . '/pdf/movimentacoes')) ?>" target="_blank">
        <h2>Movimentações em PDF</h2>
        <div class="filters">
            <label class="hint">De <input type="date" name="inicio" value="<?= e(date('Y-m-01')) ?>"></label>
            <label class="hint">Até <input type="date" name="fim" value="<?= e(date('Y-m-t')) ?>"></label>
            <label class="hint radio-line">
                <input type="checkbox" name="todo" value="1" onchange="this.form.inicio.disabled = this.form.fim.disabled = this.checked">
                Todo o histórico
            </label>
        </div>
        <div class="filters">
            <select name="item_id">
                <option value=""><?= $pdfPecas ? 'Todas as peças' : 'Todos os papéis' ?></option>
                <?php foreach ($itensPdf ?? [] as $opcao): ?>
                    <option value="<?= (int) $opcao['id'] ?>"><?= e($opcao['codigo'] . ' — ' . $opcao['nome'] . ($opcao['status'] === 'ativo' ? '' : ' (inativo)')) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="tipo">
                <option value="">Todos os tipos</option>
                <?php foreach (RelatorioEstoque::TIPOS_MOVIMENTO as $valor => $rotulo): ?>
                    <option value="<?= e($valor) ?>"><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-secondary">Gerar PDF das movimentações</button>
        </div>
    </form>
</section>

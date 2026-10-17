<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/relatorios/estoque')) ?>">
        <input type="hidden" name="aba" value="data">
        <label class="hint">Data de referência <input type="date" name="data" value="<?= e($filtros['data']) ?>" required></label>
        <select name="estoque">
            <?php foreach (RelatorioEstoque::ESTOQUES as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['estoque'], $v) ?>><?= e($v === '' ? 'Todos os estoques' : $rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="local" placeholder="Local (opcional)" value="<?= e($filtros['local']) ?>">
        <button type="submit" class="btn-primary">Consultar</button>
    </form>
</section>

<?php if ($erroData): ?>
    <div class="alert alert-error"><?= e($erroData) ?></div>
<?php endif; ?>

<?php if ($consultado): ?>
    <h2 style="margin:0 0 12px">Posição em <?= e(date('d/m/Y', strtotime($filtros['data']))) ?></h2>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Item</th>
                        <th>Tipo / Características</th>
                        <th class="num">Estoque na data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$linhas): ?>
                        <tr><td colspan="4" class="empty">Nenhum item com movimentação até esta data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($linhas as $r): ?>
                            <?php
                            if ($r['estoque'] === 'pecas') {
                                $carac = array_filter(['Peça · ' . $tipoPecaLabel($r['tipo']), $r['produto_nome'], $r['cor'], $r['estampa']]);
                            } else {
                                $carac = array_filter(['Papel', $r['cor'], $r['formato'], $r['gramatura']]);
                            }
                            ?>
                            <tr>
                                <td><?= e($r['codigo']) ?></td>
                                <td><?= e($r['nome']) ?></td>
                                <td><?= e(implode(' · ', $carac)) ?></td>
                                <td class="num"><strong><?= e(number_br($r['saldo_na_data'], 0)) ?></strong> <?= e($r['unidade']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="hint">Saldo no fim do dia escolhido, conforme o último movimento registrado até então. Itens criados depois dessa data não aparecem. O filtro de local usa a localização atual do item.</p>
<?php else: ?>
    <p class="hint">Escolha a data e clique em Consultar para ver a posição histórica de peças e papéis.</p>
<?php endif; ?>

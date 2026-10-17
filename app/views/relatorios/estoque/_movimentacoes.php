<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/relatorios/estoque')) ?>">
        <input type="hidden" name="aba" value="movimentacoes">
        <label class="hint">De <input type="date" name="inicio" value="<?= e((string) $filtros['inicio']) ?>"></label>
        <label class="hint">Até <input type="date" name="fim" value="<?= e((string) $filtros['fim']) ?>"></label>
        <select name="estoque">
            <?php foreach (RelatorioEstoque::ESTOQUES as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['estoque'], $v) ?>><?= e($v === '' ? 'Todos os estoques' : $rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tipo">
            <option value="">Todos os tipos</option>
            <?php foreach (RelatorioEstoque::TIPOS_MOVIMENTO as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['tipo'], $v) ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="item" placeholder="Item (código ou nome)" value="<?= e($filtros['item']) ?>">
        <select name="usuario_id">
            <option value="">Todos os usuários</option>
            <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $selected($filtros['usuario_id'], $u['id']) ?>><?= e($u['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
        <a class="btn-secondary" href="<?= e($urlAba('movimentacoes')) ?>">Limpar filtros</a>
    </form>
</section>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Estoque</th>
                    <th>Tipo</th>
                    <th>Item</th>
                    <th class="num">Quantidade</th>
                    <th class="num">Saldo anterior</th>
                    <th class="num">Saldo depois</th>
                    <th>Observação</th>
                    <th>Usuário</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pagina['items']): ?>
                    <tr><td colspan="9" class="empty">Nenhuma movimentação encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($pagina['items'] as $m): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $m['created_at']))) ?></td>
                            <td><?= $m['estoque'] === 'pecas' ? 'Peças' : 'Papéis' ?></td>
                            <td><span class="badge badge-<?= e($m['tipo']) ?>"><?= e(RelatorioEstoque::TIPOS_MOVIMENTO[$m['tipo']] ?? $m['tipo']) ?></span></td>
                            <td><?= e($m['item_codigo'] . ' — ' . $m['item_nome']) ?></td>
                            <td class="num"><?= e(number_br($m['quantidade'], 0)) ?> <?= e($m['item_unidade']) ?></td>
                            <td class="num"><?= $m['saldo_anterior'] !== null ? e(number_br($m['saldo_anterior'], 0)) : '—' ?></td>
                            <td class="num"><?= e(number_br($m['saldo_apos'], 0)) ?></td>
                            <td><?= e($m['observacao'] ?: '-') ?></td>
                            <td><?= e($m['usuario_nome'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="hint"><?= e(number_br($pagina['total'], 0)) ?> movimentação(ões) encontrada(s). Mais recentes primeiro.</p>

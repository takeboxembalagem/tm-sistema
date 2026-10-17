<section class="grid-kpis" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
    <div class="card"><span class="kpi-label">Variações cadastradas</span><span class="kpi-value"><?= e(number_br($indicadores['total'], 0)) ?></span></div>
    <div class="card"><span class="kpi-label">Com estoque</span><span class="kpi-value"><?= e(number_br($indicadores['com_estoque'], 0)) ?></span></div>
    <div class="card"><span class="kpi-label">Estoque zerado</span><span class="kpi-value"><?= e(number_br($indicadores['zerado'], 0)) ?></span></div>
    <div class="card"><span class="kpi-label">Estoque baixo (até <?= e((string) Variacao::ESTOQUE_BAIXO) ?>)</span><span class="kpi-value"><?= e(number_br($indicadores['baixo'], 0)) ?></span></div>
</section>

<section class="toolbar">
    <form class="filters" method="get" action="<?= e(url('/relatorios/estoque')) ?>">
        <input type="hidden" name="aba" value="variacoes">
        <select name="produto_id">
            <option value="">Todos os produtos</option>
            <?php foreach ($produtos as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= $selected($filtros['produto_id'], $p['id']) ?>><?= e($p['nome'] . ' (' . $p['codigo_interno'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="variacao" placeholder="Variação" value="<?= e($filtros['variacao']) ?>">
        <input type="search" name="codigo" placeholder="Código" value="<?= e($filtros['codigo']) ?>">
        <select name="status">
            <option value="">Todos os status</option>
            <option value="ativo" <?= $selected($filtros['status'], 'ativo') ?>>Ativas</option>
            <option value="inativo" <?= $selected($filtros['status'], 'inativo') ?>>Inativas</option>
        </select>
        <select name="colecao_id">
            <option value="">Todas as coleções</option>
            <?php foreach ($colecoes as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $selected($filtros['colecao_id'], $c['id']) ?>><?= e($c['nome'] . ($c['status'] === 'ativo' ? '' : ' (inativa)')) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="situacao">
            <?php foreach (RelatorioEstoque::SITUACOES_VARIACAO as $v => $rotulo): ?>
                <option value="<?= e($v) ?>" <?= $selected($filtros['situacao'], $v) ?>><?= e($v === '' ? 'Todas as situações' : $rotulo) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
        <a class="btn-secondary" href="<?= e($urlAba('variacoes')) ?>">Limpar filtros</a>
    </form>
</section>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Variação</th>
                    <th>Código</th>
                    <th>GTIN da unidade individual</th>
                    <th>GTIN do pacote</th>
                    <th class="num">Estoque</th>
                    <th class="num">Pacotes</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pagina['items']): ?>
                    <tr><td colspan="8" class="empty">Nenhuma variação encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($pagina['items'] as $v): ?>
                        <tr>
                            <td><a href="<?= e(url('/produtos/' . (int) $v['produto_id'])) ?>"><?= e($v['produto_nome']) ?></a></td>
                            <td>
                                <?= e($v['nome']) ?>
                                <?php if ($v['status'] !== 'ativo'): ?> <span class="badge badge-off">Inativa</span><?php endif; ?>
                            </td>
                            <td><?= e($v['codigo']) ?></td>
                            <td><?= e($v['gtin_unidade'] ?? '—') ?></td>
                            <td><?= e($v['gtin_pacote'] ?? '—') ?></td>
                            <td class="num"><?= e(number_br((int) $v['estoque'], 0)) ?></td>
                            <td class="num" title="<?= $v['qtd_pacote'] !== null ? e(number_br($v['qtd_pacote'], 0) . ' un. por pacote') : 'Sem quantidade por pacote' ?>">
                                <?= $v['pacotes'] !== null ? e(number_br($v['pacotes'], 0)) : '—' ?>
                            </td>
                            <td>
                                <span class="stock-badge stock-<?= e($v['nivel']) ?>" title="<?= e(Variacao::nivelLabel($v['nivel'])) ?>"><?= e($situacaoLabel($v['nivel'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="hint"><?= e(number_br($pagina['total'], 0)) ?> variação(ões) listada(s). Pacotes = pacotes completos, pela mesma regra da Tabela de Preços.</p>

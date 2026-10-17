<?php
$config = $config ?? [];
$margens = $margens ?? [];
$linhas = $linhas ?? [];
$precoView = (($precoView ?? 'unitario') === 'pacote') ? 'pacote' : 'unitario';
$verPacote = $precoView === 'pacote';
$incluir = !empty($config['incluir_comissao']);
$imposto = (float) ($config['imposto_percentual'] ?? 9.5);
$comissao = (float) ($config['comissao_percentual'] ?? 10);
$colspan = ($verPacote ? 4 : 3) + count($margens);
$qs = static function (array $extra = []) use ($filters, $pageNum, $precoView): string {
    $params = [];
    if (($filters['q'] ?? '') !== '') {
        $params['q'] = $filters['q'];
    }
    if (!empty($filters['mostrar_inativos'])) {
        $params['mostrar_inativos'] = '1';
    }
    if ($precoView === 'pacote') {
        $params['preco_view'] = 'pacote';
    }
    $page = $extra['page'] ?? $pageNum;
    if ($page > 1) {
        $params['page'] = $page;
    }
    if (array_key_exists('preco_view', $extra)) {
        if ($extra['preco_view'] === 'pacote') {
            $params['preco_view'] = 'pacote';
        } else {
            unset($params['preco_view']);
        }
    }
    return http_build_query($params);
};
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error compact-alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card tabela-config-bar">
    <div class="tabela-config-head">
        <h2>Tabela de Preços</h2>
        <p class="hint">Produtos e variações · custo da composição · margens comerciais · preço cheio não é usado</p>
    </div>

    <?php if (!Auth::isAdmin()): ?>
    <div class="tabela-config-row" id="config-somente-leitura">
        <span class="cfg-label">Imposto <strong><?= e(number_br($imposto, 2)) ?>%</strong></span>
        <span class="cfg-label">Comissão <strong><?= $incluir ? e(number_br($comissao, 2)) . '%' : 'não incluída' ?></strong></span>
        <span class="cfg-label">Margens
            <strong><?= $margens ? e(implode(' · ', array_map(static fn ($m) => number_br($m['margem_percentual'], 2) . '%', $margens))) : '—' ?></strong>
        </span>
        <span class="hint">Configuração alterada somente por administradores.</span>
    </div>
    <?php else: ?>
    <form method="post" action="<?= e(url('/tabela-precos/config')) ?>" class="tabela-config-row" id="form-config-tabela">
        <?= Csrf::field() ?>
        <label class="cfg-inline">
            Imposto
            <span class="cfg-input">
                <input type="text" name="imposto_percentual" inputmode="decimal" required
                       value="<?= e(number_br($imposto, 2)) ?>">
                <em>%</em>
            </span>
        </label>
        <label class="radio-line cfg-check">
            <input type="checkbox" name="incluir_comissao" id="incluir_comissao" value="1" <?= $incluir ? 'checked' : '' ?>>
            Incluir comissão
        </label>
        <label class="cfg-inline" id="wrap-comissao">
            Comissão
            <span class="cfg-input">
                <input type="text" name="comissao_percentual" inputmode="decimal"
                       value="<?= e(number_br($comissao, 2)) ?>">
                <em>%</em>
            </span>
        </label>
        <button type="submit" class="btn-secondary">Aplicar</button>
    </form>

    <div class="tabela-config-row" id="margens">
        <span class="cfg-label">Margens</span>
        <div class="margem-chips">
            <?php foreach ($margens as $m): ?>
                <div class="margem-chip">
                    <form method="post" action="<?= e(url('/tabela-precos/margens/' . $m['id'])) ?>" class="margem-edit">
                        <?= Csrf::field() ?>
                        <input type="text" name="margem_percentual" inputmode="decimal"
                               value="<?= e(number_br($m['margem_percentual'], 2)) ?>" title="Editar margem">
                        <span>%</span>
                        <button type="submit" class="link-btn" title="Salvar">✓</button>
                    </form>
                    <?php if (count($margens) > 1): ?>
                        <form method="post" action="<?= e(url('/tabela-precos/margens/' . $m['id'] . '/excluir')) ?>"
                              onsubmit="return confirm('Excluir margem <?= e(number_br($m['margem_percentual'], 2)) ?>%?');">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link-btn" title="Excluir">×</button>
                        </form>
                    <?php else: ?>
                        <span class="hint" id="margem-unica" title="É necessário manter pelo menos uma margem na Tabela de Preços.">única</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <form method="post" action="<?= e(url('/tabela-precos/margens')) ?>" class="chip-add">
                <?= Csrf::field() ?>
                <input type="text" name="margem_percentual" inputmode="decimal" required placeholder="ex. 70">
                <button type="submit" class="btn-secondary btn-chip">+ Adicionar margem</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</section>

<section class="toolbar tabela-toolbar">
    <form class="filters" method="get" action="<?= e(url('/tabela-precos')) ?>">
        <input type="search" name="q" placeholder="Buscar produto ou variação..."
               value="<?= e($filters['q'] ?? '') ?>" style="min-width:min(100%,280px)">
        <label class="radio-line" style="align-self:center">
            <input type="checkbox" name="mostrar_inativos" value="1" <?= !empty($filters['mostrar_inativos']) ? 'checked' : '' ?>>
            Mostrar inativos
        </label>
        <label class="cfg-inline" style="align-self:center">
            Preço
            <select name="preco_view" onchange="this.form.submit()">
                <option value="unitario" <?= !$verPacote ? 'selected' : '' ?>>Unitário</option>
                <option value="pacote" <?= $verPacote ? 'selected' : '' ?>>Pacote</option>
            </select>
        </label>
        <button type="submit" class="btn-secondary">Buscar</button>
    </form>
</section>

<div class="card table-card tabela-precos-card">
    <div class="table-wrap">
        <table class="table tabela-matriz">
            <thead>
                <tr>
                    <th>Produto / Variação</th>
                    <th class="num">Qtd</th>
                    <?php if ($verPacote): ?>
                        <th class="num">Pacote</th>
                        <th class="num">Custo do pacote</th>
                    <?php else: ?>
                        <th class="num">Custo</th>
                    <?php endif; ?>
                    <?php foreach ($margens as $m): ?>
                        <th class="num"><?= e(number_br($m['margem_percentual'], 0)) ?>%</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$linhas): ?>
                    <tr>
                        <td colspan="<?= max(3, $colspan) ?>" class="empty">
                            Nenhum produto encontrado. Cadastre produtos (e variações) no módulo Produtos.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $varSeq = 0; ?>
                    <?php foreach ($linhas as $idx => $linha): ?>
                        <?php
                        $tipo = $linha['tipo'] ?? 'produto';
                        $p = $linha['produto'];
                        $pid = (int) $p['id'];
                        $qtdPacote = $linha['qtd_pacote'] ?? null;
                        $temPacote = $qtdPacote !== null && (int) $qtdPacote > 0;
                        if ($tipo === 'variacao') {
                            $varSeq++;
                            $linhaClass = ($varSeq % 2 === 1) ? 'tabela-variacao-impar' : 'tabela-variacao-par';
                        } else {
                            $varSeq = 0;
                            $linhaClass = 'tabela-pai-row';
                        }
                        ?>
                        <?php if ($tipo === 'grupo'): ?>
                            <tr class="tabela-grupo-row <?= e($linhaClass) ?>">
                                <td>
                                    <a class="tabela-grupo-link" href="<?= e(url('/produtos/' . $pid)) ?>">
                                        ▸ <?= e($linha['codigo']) ?> — <?= e($linha['label']) ?>
                                    </a>
                                    <?php if (($p['status'] ?? '') !== 'ativo'): ?>
                                        <span class="badge badge-off">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num" title="Estoque total das variações">
                                    <?php
                                    $estoqueGrupo = (int) ($linha['estoque'] ?? 0);
                                    $pacotesGrupo = $verPacote ? pacotes_completos_do_grupo($linhas, $idx) : null;
                                    ?>
                                    <?php if ($pacotesGrupo !== null): ?>
                                        <strong><?= e(number_br($pacotesGrupo, 0)) ?> pct.</strong>
                                    <?php else: ?>
                                        <strong><?= e(number_br($estoqueGrupo, 0)) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <?php if ($verPacote): ?>
                                    <td class="num"><span class="hint">—</span></td>
                                    <td class="num"><span class="hint">—</span></td>
                                <?php else: ?>
                                    <td class="num"><span class="hint">—</span></td>
                                <?php endif; ?>
                                <?php foreach ($margens as $m): ?>
                                    <td class="num"><span class="hint">—</span></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php elseif ($tipo === 'variacao'): ?>
                            <tr class="tabela-variacao-row <?= e($linhaClass) ?>">
                                <td class="tabela-variacao-label">
                                    <span class="var-indent">↳</span>
                                    <strong><?= e($linha['label']) ?></strong>
                                    <span class="hint codigo-linha"><?= e($linha['codigo']) ?></span>
                                    <?php if (($linha['variacao']['status'] ?? '') !== 'ativo'): ?>
                                        <span class="badge badge-off">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <?php
                                    $est = (int) ($linha['estoque'] ?? 0);
                                    $nivel = Variacao::nivelEstoque($est);
                                    $pacotesLinha = $verPacote
                                        ? pacotes_completos($est, $temPacote ? (int) $qtdPacote : null)
                                        : null;
                                    ?>
                                    <span class="stock-badge stock-<?= e($nivel) ?>" title="<?= e(Variacao::nivelLabel($nivel)) ?>">
                                        <?php if ($pacotesLinha !== null): ?>
                                            <?= e(number_br($pacotesLinha, 0)) ?> pct.
                                        <?php else: ?>
                                            <?= e(number_br($est, 0)) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <?php if ($verPacote): ?>
                                    <td class="num">
                                        <?php if ($temPacote): ?>
                                            <?= e(number_br((int) $qtdPacote, 0)) ?>
                                        <?php else: ?>
                                            <span class="hint" title="Não definido">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num custo-cell">
                                        <?php if ($temPacote): ?>
                                            <?= e(money_br((float) $linha['custo'] * (int) $qtdPacote)) ?>
                                        <?php else: ?>
                                            <span class="hint">Não definido</span>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td class="num custo-cell"><?= e(money_br((float) $linha['custo'])) ?></td>
                                <?php endif; ?>
                                <?php foreach ($margens as $m): ?>
                                    <?php $cell = $linha['precos'][(int) $m['id']] ?? null; ?>
                                    <td class="num">
                                        <?php if ($verPacote): ?>
                                            <?php if ($temPacote && $cell && $cell['preco'] !== null): ?>
                                                <?= e(money_br((float) $cell['preco'] * (int) $qtdPacote)) ?>
                                            <?php elseif (!$temPacote): ?>
                                                <span class="hint">—</span>
                                            <?php else: ?>
                                                <span class="hint" title="<?= e($cell['erro'] ?? '') ?>">—</span>
                                            <?php endif; ?>
                                        <?php elseif ($cell && $cell['preco'] !== null): ?>
                                            <?= e(money_br($cell['preco'])) ?>
                                        <?php else: ?>
                                            <span class="hint" title="<?= e($cell['erro'] ?? '') ?>">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php else: ?>
                            <tr class="tabela-produto-row <?= e($linhaClass) ?>">
                                <td>
                                    <a href="<?= e(url('/produtos/' . $pid)) ?>">
                                        <strong><?= e($linha['codigo']) ?> — <?= e($linha['label']) ?></strong>
                                    </a>
                                    <?php if (($p['status'] ?? '') !== 'ativo'): ?>
                                        <span class="badge badge-off">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><span class="hint" title="Sem variações">—</span></td>
                                <?php if ($verPacote): ?>
                                    <td class="num">
                                        <?php if ($temPacote): ?>
                                            <?= e(number_br((int) $qtdPacote, 0)) ?>
                                        <?php else: ?>
                                            <span class="hint" title="Não definido">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num custo-cell">
                                        <?php if ($temPacote): ?>
                                            <?= e(money_br((float) $linha['custo'] * (int) $qtdPacote)) ?>
                                        <?php else: ?>
                                            <span class="hint">Não definido</span>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td class="num custo-cell"><?= e(money_br((float) $linha['custo'])) ?></td>
                                <?php endif; ?>
                                <?php foreach ($margens as $m): ?>
                                    <?php $cell = $linha['precos'][(int) $m['id']] ?? null; ?>
                                    <td class="num">
                                        <?php if ($verPacote): ?>
                                            <?php if ($temPacote && $cell && $cell['preco'] !== null): ?>
                                                <?= e(money_br((float) $cell['preco'] * (int) $qtdPacote)) ?>
                                            <?php elseif (!$temPacote): ?>
                                                <span class="hint">—</span>
                                            <?php else: ?>
                                                <span class="hint" title="<?= e($cell['erro'] ?? '') ?>">—</span>
                                            <?php endif; ?>
                                        <?php elseif ($cell && $cell['preco'] !== null): ?>
                                            <?= e(money_br($cell['preco'])) ?>
                                        <?php else: ?>
                                            <span class="hint" title="<?= e($cell['erro'] ?? '') ?>">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a class="<?= $i === $pageNum ? 'is-active' : '' ?>" href="<?= e(url('/tabela-precos?' . $qs(['page' => $i]))) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</div>
<script>
(function () {
    var ck = document.getElementById('incluir_comissao');
    var wrap = document.getElementById('wrap-comissao');
    if (ck && wrap) {
        function sync() { wrap.style.display = ck.checked ? '' : 'none'; }
        ck.addEventListener('change', sync);
        sync();
    }
})();
</script>

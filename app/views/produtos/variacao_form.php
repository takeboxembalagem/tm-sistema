<?php
$isEdit = !empty($variacao);
$old = $old ?? [];
$val = static function (string $key, $fallback = '') use ($old, $variacao) {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($variacao && array_key_exists($key, $variacao) && $variacao[$key] !== null) {
        return (string) $variacao[$key];
    }
    return (string) $fallback;
};
$produtoId = (int) $produto['id'];
$action = $isEdit
    ? url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'])
    : url('/produtos/' . $produtoId . '/variacoes');
$codigoDefault = $isEdit ? '' : (string) ($sugestaoCodigo ?? '');
$modoCusto = $modoCusto ?? 'herdar';
if (!$isEdit && array_key_exists('custo_modo', $old)) {
    $modoCusto = (string) $old['custo_modo'];
}
if ($modoCusto !== 'proprio') {
    $modoCusto = 'herdar';
}
$custoProdutoUnitario = (float) ($custoProdutoUnitario ?? 0);
$producao = $producao ?? Composicao::resolverProducao($produto);
$composicaoVar = $composicaoVar ?? [];
$totaisVar = $totaisVar ?? null;
$custoModoUrl = $isEdit
    ? url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/custo-modo')
    : '';
?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= e((string) $success) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <div class="toolbar" style="margin-bottom:12px">
        <h2 style="margin:0"><?= $isEdit ? 'Editar variação' : 'Nova variação' ?></h2>
        <a class="btn-secondary" href="<?= e(url('/produtos/' . $produtoId . '#variacoes')) ?>">Voltar ao produto</a>
    </div>
    <p class="hint" style="margin-top:0">
        Produto:
        <strong><?= e($produto['codigo_interno'] . ' — ' . $produto['nome']) ?></strong>
    </p>

    <form method="post" action="<?= e($action) ?>" class="form" id="form-variacao">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Nome da variação *
                <input type="text" name="nome" maxlength="120" required
                       value="<?= e($val('nome')) ?>" placeholder="Ex.: Azul">
            </label>
            <label>
                Código *
                <input type="text" name="codigo" maxlength="40" required
                       value="<?= e($val('codigo', $codigoDefault)) ?>"
                       placeholder="Ex.: <?= e($produto['codigo_interno']) ?>-AZ">
            </label>
            <?php if ($isEdit): ?>
                <label>
                    Estoque atual
                    <input type="text" value="<?= e((string) (int) $variacao['estoque']) ?>" readonly disabled>
                    <span class="hint">
                        Alterado somente pelo
                        <a href="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/estoque')) ?>">ajuste de estoque</a>.
                    </span>
                </label>
            <?php else: ?>
                <label>
                    Estoque inicial *
                    <input type="number" name="estoque" min="0" step="1" required
                           value="<?= e($val('estoque', '0')) ?>">
                </label>
            <?php endif; ?>

            <label>
                Status
                <select name="status">
                    <option value="ativo" <?= $val('status', 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativa</option>
                    <option value="inativo" <?= $val('status') === 'inativo' ? 'selected' : '' ?>>Inativa</option>
                </select>
            </label>
            <label class="span-2">
                Observação
                <textarea name="observacao" rows="3" maxlength="2000"
                          placeholder="Opcional"><?= e($val('observacao')) ?></textarea>
            </label>
        </div>

        <?php
        $colecoesOpcoes = $colecoesOpcoes ?? [];
        $colecoesAtuais = array_map('intval', $colecoesAtuais ?? []);
        $colecoesMarcadas = array_key_exists('colecoes', $old)
            ? array_map('intval', (array) $old['colecoes'])
            : $colecoesAtuais;
        ?>
        <section style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border, #ddd)">
            <h3 style="margin:0 0 8px">Coleções</h3>
            <p class="hint" style="margin-top:0">
                Opcional. Marque as coleções das quais esta variação participa.
                <?php if ($isEdit): ?>
                    Atualmente em <strong><?= count($colecoesAtuais) ?></strong> coleç<?= count($colecoesAtuais) === 1 ? 'ão' : 'ões' ?>.
                <?php endif; ?>
            </p>
            <input type="hidden" name="colecoes_enviadas" value="1">
            <?php if (!$colecoesOpcoes): ?>
                <p class="hint">Nenhuma coleção ativa cadastrada.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:6px; max-height:260px; overflow-y:auto">
                    <?php foreach ($colecoesOpcoes as $col): ?>
                        <?php $cid = (int) $col['id']; ?>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0">
                            <input type="checkbox" name="colecoes[]" value="<?= $cid ?>" <?= in_array($cid, $colecoesMarcadas, true) ? 'checked' : '' ?>>
                            <span>
                                <?= e($col['nome']) ?>
                                <span class="hint">
                                    <?= e((Colecao::TIPOS[$col['tipo'] ?? ''] ?? '—') . ' · ' . (Colecao::ETAPAS[$col['etapa'] ?? ''] ?? '—')) ?>
                                </span>
                                <?php if (in_array($cid, $colecoesAtuais, true)): ?>
                                    <span class="badge badge-ok">Vinculada</span>
                                <?php endif; ?>
                                <?php if (($col['status'] ?? '') !== 'ativo'): ?>
                                    <span class="badge badge-off">Inativa</span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php
        $modoPacote = 'herdar';
        if (array_key_exists('pacote_modo', $old)) {
            $modoPacote = (string) $old['pacote_modo'];
        } elseif ($isEdit && isset($variacao['pacote_modo'])) {
            $modoPacote = (string) $variacao['pacote_modo'];
        }
        if ($modoPacote !== 'proprio') {
            $modoPacote = 'herdar';
        }
        $qtdPacoteProduto = quantidade_pacote_resolvida($produto, null);
        $qtdPacoteCampo = $val('quantidade_pacote');
        ?>
        <section style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border, #ddd)">
            <h3 style="margin:0 0 8px">Unidade de venda / Pacote</h3>
            <p class="hint" style="margin-top:0">
                Não altera custo nem preço unitário. Pacote = visualização (preço unitário × quantidade).
            </p>
            <div class="form-grid" style="margin-bottom:12px">
                <label class="span-2" style="display:flex; flex-direction:column; gap:8px">
                    <span>
                        <input type="radio" name="pacote_modo" value="herdar" <?= $modoPacote === 'herdar' ? 'checked' : '' ?>
                               onchange="document.getElementById('wrap-qtd-pacote-var').style.display='none'">
                        Herdar do produto
                        <span class="hint">
                            (<?= $qtdPacoteProduto ? e(number_br($qtdPacoteProduto, 0)) . ' un./pacote' : 'sem pacote' ?>)
                        </span>
                    </span>
                    <span>
                        <input type="radio" name="pacote_modo" value="proprio" <?= $modoPacote === 'proprio' ? 'checked' : '' ?>
                               onchange="document.getElementById('wrap-qtd-pacote-var').style.display=''">
                        Personalizar
                    </span>
                </label>
                <label id="wrap-qtd-pacote-var" style="<?= $modoPacote === 'proprio' ? '' : 'display:none' ?>">
                    Quantidade por pacote
                    <input type="number" name="quantidade_pacote" min="1" step="1"
                           placeholder="Ex.: 10 (opcional)"
                           value="<?= e($qtdPacoteCampo) ?>">
                    <span class="hint">Inteiro positivo. Vazio = sem pacote nesta variação.</span>
                </label>
            </div>
        </section>

        <section style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border, #ddd)">
            <h3 style="margin:0 0 8px">GTIN</h3>
            <p class="hint" style="margin-top:0">
                Opcionais. Informe apenas códigos já atribuídos (GTIN-8, 12, 13 ou 14). Cada código só pode ser usado uma vez no sistema.
            </p>
            <div class="form-grid" style="margin-bottom:12px">
                <label>
                    GTIN da unidade individual
                    <input type="text" name="gtin_unidade" maxlength="20" inputmode="numeric" autocomplete="off"
                           value="<?= e($val('gtin_unidade')) ?>" placeholder="Opcional">
                    <span class="hint">Código da peça vendida separadamente.</span>
                </label>
                <label>
                    GTIN do pacote
                    <input type="text" name="gtin_pacote" maxlength="20" inputmode="numeric" autocomplete="off"
                           value="<?= e($val('gtin_pacote')) ?>" placeholder="Opcional">
                    <span class="hint">Código da apresentação em pacote desta variação.</span>
                </label>
            </div>
        </section>

        <?php if (!$isEdit): ?>
        <section style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border, #ddd)">
            <h3 style="margin:0 0 8px">Formação de custo</h3>
            <p class="hint" style="margin-top:0">
                Após cadastrar, você poderá personalizar o custo sem salvar a ficha novamente.
            </p>
            <div class="form-grid" style="margin-bottom:12px">
                <label class="span-2" style="display:flex; flex-direction:column; gap:8px">
                    <span>
                        <input type="radio" name="custo_modo" value="herdar" <?= $modoCusto === 'herdar' ? 'checked' : '' ?>>
                        Herdar custo do produto
                        <span class="hint">(<?= e(money_br($custoProdutoUnitario)) ?> / peça)</span>
                    </span>
                    <span>
                        <input type="radio" name="custo_modo" value="proprio" <?= $modoCusto === 'proprio' ? 'checked' : '' ?>>
                        Personalizar custo
                        <span class="hint">(copia a composição do produto ao cadastrar)</span>
                    </span>
                </label>
            </div>
        </section>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Salvar variação' : 'Cadastrar variação' ?></button>
            <a class="btn-secondary" href="<?= e(url('/produtos/' . $produtoId . '#variacoes')) ?>">Voltar ao produto</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card" style="margin-top:16px" id="custo">
    <h3 style="margin:0 0 8px">Formação de custo</h3>
    <p class="hint" style="margin-top:0">
        Produção do produto (reutilizada):
        <strong><?= e(number_br((int) $producao['folhas'], 0)) ?> folhas</strong>
        × <strong><?= e(number_br((int) $producao['rendimento'], 0)) ?> pç/folha</strong>
        = <strong><?= e(number_br((int) $producao['pecas'], 0)) ?> peças</strong>
    </p>

    <div class="form-grid" style="margin:12px 0 16px">
        <div class="span-2" style="display:flex; flex-direction:column; gap:10px">
            <?php if ($modoCusto === 'herdar'): ?>
                <label style="display:flex; align-items:center; gap:8px; cursor:default">
                    <input type="radio" checked disabled>
                    <span>Herdar custo do produto</span>
                </label>
                <form method="post" action="<?= e($custoModoUrl) ?>" style="margin:0">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="custo_modo" value="proprio">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0">
                        <input type="radio" onclick="this.form.submit()">
                        <span>Personalizar custo</span>
                    </label>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e($custoModoUrl) ?>" style="margin:0">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="custo_modo" value="herdar">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0">
                        <input type="radio" onclick="this.form.submit()">
                        <span>Herdar custo do produto</span>
                    </label>
                </form>
                <label style="display:flex; align-items:center; gap:8px; cursor:default">
                    <input type="radio" checked disabled>
                    <span>Personalizar custo</span>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($modoCusto === 'herdar'): ?>
        <p class="hint">Esta variação utiliza a formação de custo do produto principal.</p>
        <p style="margin:8px 0 0">
            Custo herdado do produto:
            <strong style="font-size:1.15rem"><?= e(money_br($custoProdutoUnitario)) ?></strong>
            <span class="hint">por peça</span>
        </p>
    <?php else: ?>
        <div class="toolbar" style="margin:16px 0 12px">
            <h3 style="margin:0">Custos desta variação</h3>
            <a class="btn-primary" href="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/composicao/adicionar')) ?>">+ Adicionar custo</a>
        </div>
        <p class="hint" style="margin-top:0">Custo = preço × quantidade. A modalidade é só informativa. Produção = do produto pai.</p>

        <form method="post" action="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/composicao/recalcular')) ?>" id="form-var-comp">
            <?= Csrf::field() ?>
            <div class="table-wrap">
                <table class="table" id="tabela-custos-var">
                    <thead>
                        <tr>
                            <th>Custo</th>
                            <th>Modalidade</th>
                            <th class="num">Preço</th>
                            <th class="num">Quantidade</th>
                            <th class="num">Custo nesta produção</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$composicaoVar): ?>
                            <tr>
                                <td colspan="6" class="empty">Nenhum custo na composição própria. Use “+ Adicionar custo”.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($composicaoVar as $linha): ?>
                                <?php
                                $qtdCusto = (float) ($linha['quantidade'] ?? 1);
                                if ($qtdCusto <= 0) {
                                    $qtdCusto = 1.0;
                                }
                                $qtdFmt = rtrim(rtrim(number_format($qtdCusto, 4, ',', ''), '0'), ',');
                                $nomeLinha = (string) ($linha['material_nome'] ?? '');
                                $ehManual = composicao_eh_manual($linha);
                                $acaoEditar = $ehManual ? 'Editar' : 'Trocar';
                                ?>
                                <tr data-preco="<?= e((string) (float) ($linha['preco'] ?? 0)) ?>">
                                    <td>
                                        <?= e($nomeLinha) ?>
                                        <?php if ($ehManual): ?>
                                            <span class="badge badge-off" style="margin-left:6px">MANUAL</span>
                                        <?php elseif (($linha['material_status'] ?? '') === 'inativo'): ?>
                                            <span class="badge badge-ajuste custo-inativo" style="margin-left:6px"
                                                  title="Custo inativo no cadastro: continua considerado nesta formação, mas não fica disponível para novas composições.">INATIVO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($linha['forma_label']) ?></td>
                                    <td class="num"><?= e(money_br($linha['preco'] ?? $linha['custo_atual'])) ?></td>
                                    <td class="num">
                                        <input type="text" name="qtd_custo[<?= (int) $linha['id'] ?>]" class="qtd-custo-input"
                                               inputmode="decimal" value="<?= e($qtdFmt) ?>" style="width:5.5rem; text-align:right">
                                    </td>
                                    <td class="num"><strong class="custo-linha-prod"><?= e(money_br($linha['custo_lote'])) ?></strong></td>
                                    <td class="actions">
                                        <a href="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/composicao/' . $linha['id'] . '/editar')) ?>"><?= e($acaoEditar) ?></a>
                                        <button type="submit" class="link-btn"
                                                formaction="<?= e(url('/produtos/' . $produtoId . '/variacoes/' . $variacao['id'] . '/composicao/' . $linha['id'] . '/remover')) ?>"
                                                formmethod="post"
                                                onclick="return confirm('Remover este custo?');">Remover</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (array_filter($composicaoVar, static fn (array $l): bool => !composicao_eh_manual($l) && ($l['material_status'] ?? '') === 'inativo')): ?>
                <p class="alert alert-warn" id="aviso-custo-inativo" style="margin-top:8px">
                    Custos marcados como INATIVO foram desativados no cadastro, mas continuam considerados nesta composição.
                    Troque-os por um custo ativo se não devem mais ser usados.
                </p>
            <?php endif; ?>
            <?php if ($composicaoVar): ?>
                <div class="form-actions" style="margin-top:8px">
                    <button type="submit" class="btn-secondary">Recalcular</button>
                </div>
            <?php endif; ?>
        </form>

        <div class="resumo-producao" style="margin-top:12px">
            <div>
                <span>Peças resultantes</span>
                <strong><?= e(number_br((int) ($totaisVar['pecas'] ?? $producao['pecas'] ?? 0), 0)) ?></strong>
            </div>
            <div>
                <span>Custo total</span>
                <strong id="resumo-var-total"><?= e(money_br((float) ($totaisVar['custo_lote'] ?? 0))) ?></strong>
            </div>
            <div class="destaque">
                <span>Custo por peça</span>
                <strong id="resumo-var-peca"><?= e(money_br((float) ($totaisVar['custo_unitario'] ?? 0))) ?></strong>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($modoCusto === 'proprio'): ?>
<script>
(function () {
    var tabela = document.getElementById('tabela-custos-var');
    var pecas = <?= (int) ($totaisVar['pecas'] ?? $producao['pecas'] ?? 0) ?>;
    var resumoTotal = document.getElementById('resumo-var-total');
    var resumoPeca = document.getElementById('resumo-var-peca');
    if (!tabela) return;
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
    function preview() {
        var total = 0;
        tabela.querySelectorAll('tbody tr[data-preco]').forEach(function (tr) {
            var preco = parseFloat(tr.getAttribute('data-preco')) || 0;
            var inp = tr.querySelector('.qtd-custo-input');
            var q = inp ? parseBr(inp.value) : 1;
            if (q === null || q <= 0) q = 0;
            var lote = preco * q;
            total += lote;
            var cell = tr.querySelector('.custo-linha-prod');
            if (cell) cell.textContent = money(lote);
        });
        if (resumoTotal) resumoTotal.textContent = money(total);
        if (resumoPeca) resumoPeca.textContent = pecas > 0 ? money(total / pecas) : 'R$ 0,00';
    }
    tabela.querySelectorAll('.qtd-custo-input').forEach(function (el) {
        el.addEventListener('input', preview);
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>

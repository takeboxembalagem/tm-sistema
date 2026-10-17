<?php
$foto = upload_url($produto['foto_principal'] ?? null);
$fmt = static function (?string $dt): string {
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : $dt;
};
$temComposicao = !empty($composicao);
$producao = $producao ?? [];
$qtd = (int) ($producao['pecas'] ?? 0);
$folhasHead = (int) ($producao['folhas'] ?? ($quantidadeProducao ?? 1000));
$rendHead = (int) ($producao['rendimento_folha'] ?? ($produto['rendimento_folha'] ?? 0));
?>
<section class="detail-head">
    <?php if ($foto): ?>
        <img class="detail-photo" src="<?= e($foto) ?>" alt="">
    <?php else: ?>
        <span class="detail-photo thumb-empty">TM</span>
    <?php endif; ?>
    <div>
        <p class="hint"><?= e($produto['codigo_interno']) ?></p>
        <h2><?= e($produto['nome']) ?></h2>
        <span class="badge <?= $produto['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
            <?= $produto['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
        </span>
        <div class="form-actions" style="margin-top:16px">
            <a class="btn-primary" href="<?= e(url('/produtos/' . $produto['id'] . '/editar')) ?>">Editar</a>
            <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/duplicar')) ?>"
                  onsubmit="return confirm('Deseja duplicar este produto?\n\nO produto será copiado com seus dados e composição. Arquivos, variações e estoque não serão duplicados.');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-secondary">Duplicar produto</button>
            </form>
            <a class="btn-secondary" href="<?= e(url('/arquivos/produto/' . $produto['id'])) ?>">Ver arquivos</a>
            <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Voltar</a>
        </div>
    </div>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Dados</h2>
    <dl class="defs">
        <dt>Tamanho montada</dt>
        <dd><?= e($produto['tamanho'] ?: '—') ?></dd>
        <dt>Tamanho aberta</dt>
        <dd><?= e(($produto['tamanho_aberta'] ?? '') ?: '—') ?></dd>
        <dt>Quantidade por pacote</dt>
        <dd>
            <?php
            $qtdPacoteFicha = $produto['quantidade_pacote'] ?? null;
            if ($qtdPacoteFicha !== null && $qtdPacoteFicha !== '' && (int) $qtdPacoteFicha > 0):
            ?>
                <?= e(number_br((int) $qtdPacoteFicha, 0)) ?> unidades
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>
        <dt>Peso do pacote</dt>
        <dd>
            <?php
            $pesoPacoteFicha = $produto['peso_pacote_kg'] ?? null;
            if ($pesoPacoteFicha !== null && $pesoPacoteFicha !== ''):
            ?>
                <?= e(number_br($pesoPacoteFicha, 3)) ?> kg
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>
        <dt>Descrição</dt>
        <dd><?= e($produto['descricao'] ?: '—') ?></dd>
        <dt>Categoria</dt>
        <dd><?= e($produto['categoria_nome'] ?: '—') ?></dd>
        <dt>Coleção</dt>
        <dd><?= e($produto['colecao_nome'] ?: 'Sem coleção') ?></dd>
        <dt>Criado em</dt>
        <dd><?= e($fmt($produto['created_at'] ?? null)) ?></dd>
        <dt>Atualizado em</dt>
        <dd><?= e($fmt($produto['updated_at'] ?? null)) ?></dd>
    </dl>
</div>

<?php
$variacoes = $variacoes ?? [];
$estoqueTotal = (int) ($estoqueTotal ?? 0);
$temVariacoes = !empty($variacoes);
?>
<section class="card" id="variacoes" style="margin-top:16px">
    <div class="toolbar" style="margin-bottom:12px">
        <div>
            <h2 style="margin:0">Variações</h2>
            <p class="hint" style="margin:4px 0 0">
                <?php if ($temVariacoes): ?>
                    Estoque total: <strong><?= e(number_br($estoqueTotal, 0)) ?></strong> unidades
                    <span class="hint">· controle individual por variação</span>
                <?php else: ?>
                    Nenhuma variação cadastrada. O produto pode funcionar sem variações.
                <?php endif; ?>
            </p>
        </div>
        <a class="btn-primary" href="<?= e(url('/produtos/' . $produto['id'] . '/variacoes/novo')) ?>">+ Nova variação</a>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nome</th>
                    <th class="num">Estoque</th>
                    <th>Custo</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$temVariacoes): ?>
                    <tr>
                        <td colspan="6" class="empty">Sem variações. Use “Nova variação” para cores, tamanhos ou modelos do estoque.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($variacoes as $v): ?>
                        <?php
                        $est = (int) ($v['estoque'] ?? 0);
                        $nivel = Variacao::nivelEstoque($est);
                        $modoVar = ($v['custo_modo_resolvido'] ?? 'herdar') === 'proprio' ? 'proprio' : 'herdar';
                        $custoVar = (float) ($v['custo_unitario_exibicao'] ?? $custoUnitario ?? 0);
                        $pacotesCompletos = pacotes_completos($est, quantidade_pacote_resolvida($produto, $v));
                        ?>
                        <tr>
                            <td><strong><?= e($v['codigo']) ?></strong></td>
                            <td><?= e($v['nome']) ?></td>
                            <td class="num">
                                <span class="stock-badge stock-<?= e($nivel) ?>" title="<?= e(Variacao::nivelLabel($nivel)) ?>">
                                    <?= e(number_br($est, 0)) ?>
                                </span>
                                <span class="hint">un.<?php if ($pacotesCompletos !== null): ?> · <?= e(number_br($pacotesCompletos, 0)) ?> pct.<?php endif; ?></span>
                            </td>
                            <td>
                                <?php if ($modoVar === 'proprio'): ?>
                                    <span class="badge badge-ok">PRÓPRIO</span>
                                <?php else: ?>
                                    <span class="badge badge-off">HERDADO</span>
                                <?php endif; ?>
                                <strong><?= e(money_br($custoVar)) ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= ($v['status'] ?? '') === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
                                    <?= ($v['status'] ?? '') === 'ativo' ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="<?= e(url('/produtos/' . $produto['id'] . '/variacoes/' . $v['id'] . '/editar')) ?>">Editar</a>
                                <a href="<?= e(url('/produtos/' . $produto['id'] . '/variacoes/' . $v['id'] . '/editar#custo')) ?>">Formação de custo</a>
                                <a href="<?= e(url('/produtos/' . $produto['id'] . '/variacoes/' . $v['id'] . '/estoque')) ?>">Ajustar estoque</a>
                                <?php if (Auth::isAdmin()): ?>
                                    <form method="post"
                                          action="<?= e(url('/produtos/' . $produto['id'] . '/variacoes/' . $v['id'] . '/excluir')) ?>"
                                          onsubmit="return confirm('Excluir esta variação?');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="resumo-custo" style="margin-top:16px">
    <h2>Custos</h2>
    <dl class="defs">
        <dt>Produção</dt>
        <dd>
            <?= e(number_br($folhasHead, 0)) ?> folhas
            <?php if ($rendHead > 0): ?>
                × <?= e(number_br($rendHead, 0)) ?> pç/folha
                = <strong><?= e(number_br($qtd, 0)) ?> peças</strong>
            <?php endif; ?>
        </dd>
        <dt>Custo do lote</dt>
        <dd><?= $temComposicao ? e(money_br($custoLote)) : '—' ?></dd>
        <dt>Custo unitário</dt>
        <dd class="kpi-value" style="font-size:1.25rem">
            <?= $temComposicao ? e(money_br($custoUnitario)) : 'Custo ainda não calculado' ?>
        </dd>
        <dt>Componentes</dt>
        <dd><strong><?= (int) count($composicao) ?></strong></dd>
    </dl>
    <div class="form-actions" style="margin-top:12px">
        <a class="btn-secondary" href="#composicao">Ver composição</a>
        <a class="btn-secondary" href="<?= e(url('/arquivos/produto/' . $produto['id'])) ?>">Ver arquivos</a>
    </div>
</section>

<?php
$preco = $preco ?? PrecoFormacao::analisar($produto, (float) $custoUnitario);
$modoPreco = $preco['modo'];
?>
<section class="card" id="precos" style="margin-top:16px">
    <h2>Formação de preço · Varejo</h2>
    <p class="hint">Custo unitário da composição + imposto, comissão e margem/markup. Atacado em etapa posterior.</p>

    <?php if (!$temComposicao): ?>
        <p class="hint">Defina a composição do produto para calcular o custo e formar o preço.</p>
    <?php endif; ?>

    <?php if (!empty($preco['erro_calculo'])): ?>
        <div class="alert alert-error"><?= e($preco['erro_calculo']) ?></div>
    <?php endif; ?>

    <?php if (!empty($preco['alerta_margem']) && $preco['margem_real'] !== null): ?>
        <div class="alert alert-warn">
            Margem abaixo da margem mínima (<?= e(number_br($preco['margem_minima'], 2)) ?>%). Margem real atual: <?= e(number_br($preco['margem_real'], 2)) ?>%.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/preco')) ?>" class="form preco-form" id="form-preco">
        <?= Csrf::field() ?>
        <fieldset class="mode-fieldset">
            <legend>Método de formação</legend>
            <label class="radio-line">
                <input type="radio" name="modo_preco" value="margem" <?= $modoPreco === 'margem' ? 'checked' : '' ?>>
                Margem
            </label>
            <label class="radio-line">
                <input type="radio" name="modo_preco" value="markup" <?= $modoPreco === 'markup' ? 'checked' : '' ?>>
                Markup (sobre o custo)
            </label>
            <label class="radio-line">
                <input type="radio" name="modo_preco" value="manual" <?= $modoPreco === 'manual' ? 'checked' : '' ?>>
                Manual
            </label>
        </fieldset>

        <div class="formacao-grid">
            <div class="formacao-custo">
                <span>Custo unitário</span>
                <strong><?= e(money_br($preco['custo'])) ?></strong>
            </div>

            <label>
                Imposto (%)
                <input type="text" name="imposto_percentual" id="imposto_percentual" inputmode="decimal"
                       value="<?= e(number_br($preco['imposto_percentual'], 2)) ?>">
            </label>
            <label>
                Comissão (%)
                <input type="text" name="comissao_percentual" id="comissao_percentual" inputmode="decimal"
                       value="<?= e(number_br($preco['comissao_percentual'], 2)) ?>">
            </label>
            <label id="wrap-margem">
                Margem desejada (%)
                <input type="text" name="margem_desejada" id="margem_desejada" inputmode="decimal"
                       value="<?= e(number_br($preco['margem_desejada'], 2)) ?>">
            </label>
            <label id="wrap-markup">
                Markup (× custo)
                <input type="text" name="markup" id="markup" inputmode="decimal"
                       value="<?= e(number_br($preco['markup'], 4)) ?>">
                <small class="hint">Preço = custo × markup</small>
            </label>
            <label id="wrap-praticado">
                Preço praticado / de venda
                <input type="text" name="preco_praticado" id="preco_praticado" inputmode="decimal"
                       value="<?= $preco['preco_praticado'] !== null ? e(number_br(arredondar_para_cima_centavos($preco['preco_praticado']), 2)) : '' ?>">
            </label>
            <label id="wrap-sync" class="radio-line" style="align-self:end">
                <input type="checkbox" name="sincronizar_calculado" id="sincronizar_calculado" value="1" <?= ($modoPreco !== 'manual' && !empty($preco['sincronizado'])) ? 'checked' : '' ?>>
                Usar automaticamente o preço calculado
            </label>
        </div>

        <p id="preco-erro-js" class="alert alert-error" style="display:none;margin-top:12px"></p>

        <div class="calc-preview">
            <span class="kpi-label">Preço calculado</span>
            <strong id="preco-calc-preview"><?= $preco['preco_calculado'] !== null ? e(money_br($preco['preco_calculado'])) : '—' ?></strong>
        </div>

        <div class="preco-resultado">
            <h3>Resultado</h3>
            <div class="preco-resumo">
                <div>
                    <span>Imposto</span>
                    <strong id="res-imposto"><?= $preco['imposto_reais'] !== null ? e(money_br($preco['imposto_reais'])) : '—' ?></strong>
                </div>
                <div>
                    <span>Comissão</span>
                    <strong id="res-comissao"><?= $preco['comissao_reais'] !== null ? e(money_br($preco['comissao_reais'])) : '—' ?></strong>
                </div>
                <div>
                    <span>Custo</span>
                    <strong id="res-custo"><?= e(money_br($preco['custo'])) ?></strong>
                </div>
                <div>
                    <span>Lucro</span>
                    <strong id="res-lucro"><?= $preco['lucro_unitario'] !== null ? e(money_br_sinal($preco['lucro_unitario'])) : '—' ?></strong>
                </div>
                <div class="destaque">
                    <span>Margem real</span>
                    <strong id="res-margem"><?= $preco['margem_real'] !== null ? e(number_br($preco['margem_real'], 2)) . '%' : '—' ?></strong>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <?php if (Auth::isAdmin()): ?>
                <button type="submit" class="btn-primary">Salvar formação de preço</button>
            <?php else: ?>
                <p class="hint" id="preco-somente-admin">Simulação apenas: somente administradores podem salvar a formação de preço.</p>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card" id="composicao" style="margin-top:16px">
    <div class="toolbar" style="margin-bottom:12px">
        <h2 style="margin:0">Formação de custo</h2>
        <a class="btn-primary" href="<?= e(url('/produtos/' . $produto['id'] . '/composicao/adicionar')) ?>">+ Adicionar custo</a>
    </div>

    <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/tiragem')) ?>" class="tiragem-box" id="form-producao-ref" style="flex-wrap:wrap; align-items:flex-end; gap:12px">
        <?= Csrf::field() ?>
        <?php
        $qtdRef = (int) ($quantidadeProducao ?? $produto['quantidade_producao'] ?? 1000);
        $rendRef = (int) ($produto['rendimento_folha'] ?? 0);
        $larguraRef = $produto['folha_largura'] ?? '';
        $alturaRef = $produto['folha_altura'] ?? '';
        $pecasRes = (int) ($producao['pecas'] ?? ($rendRef > 0 ? $qtdRef * $rendRef : 0));
        $folhasRes = (int) ($producao['folhas'] ?? $qtdRef);
        ?>
        <div style="min-width:180px">
            <strong>Formação da produção</strong>
            <p class="hint" style="margin:4px 0 0">Folhas × peças por folha</p>
        </div>
        <label class="tiragem-field">
            Tamanho da folha
            <span class="tiragem-input" style="gap:4px">
                <input type="text" name="folha_largura" id="folha_largura" inputmode="decimal" style="width:4.5rem" value="<?= $larguraRef !== '' && $larguraRef !== null ? e(number_br((float) $larguraRef, 2)) : '' ?>" placeholder="66">
                <em>×</em>
                <input type="text" name="folha_altura" id="folha_altura" inputmode="decimal" style="width:4.5rem" value="<?= $alturaRef !== '' && $alturaRef !== null ? e(number_br((float) $alturaRef, 2)) : '' ?>" placeholder="96">
                <em>cm</em>
            </span>
        </label>
        <label class="tiragem-field">
            Quantidade de folhas
            <span class="tiragem-input">
                <input type="number" name="quantidade_producao" id="quantidade_producao" min="1" step="1" required value="<?= $qtdRef ?>">
                <em>folhas</em>
            </span>
        </label>
        <label class="tiragem-field">
            Peças por folha
            <span class="tiragem-input">
                <input type="number" name="rendimento_folha" id="rendimento_folha" min="1" step="1" required value="<?= $rendRef > 0 ? $rendRef : '' ?>" placeholder="16">
                <em>pç/folha</em>
            </span>
        </label>
        <div id="resultado-producao" style="min-width:160px">
            <span class="kpi-label">Peças resultantes</span>
            <strong id="pecas-resultantes" style="font-size:1.35rem; display:block"><?= e(number_br($pecasRes, 0)) ?></strong>
            <div class="hint" id="hint-folhas"><?= e(number_br($folhasRes, 0)) ?> × <?= e(number_br(max(0, $rendRef), 0)) ?> pç/folha</div>
        </div>
        <button type="submit" class="btn-secondary">Recalcular</button>
    </form>
    <script>
    (function () {
        var qtd = document.getElementById('quantidade_producao');
        var rend = document.getElementById('rendimento_folha');
        var pecasEl = document.getElementById('pecas-resultantes');
        var hint = document.getElementById('hint-folhas');
        if (!qtd || !rend) return;
        function sync() {
            var n = parseInt(qtd.value, 10) || 0;
            var r = parseInt(rend.value, 10) || 0;
            var pecas = (n > 0 && r > 0) ? (n * r) : 0;
            pecasEl.textContent = pecas.toLocaleString('pt-BR');
            hint.textContent = n.toLocaleString('pt-BR') + ' × ' + r.toLocaleString('pt-BR') + ' pç/folha';
        }
        qtd.addEventListener('input', sync);
        rend.addEventListener('input', sync);
        sync();
    })();
    </script>

    <h3 style="margin:20px 0 8px">Custos</h3>
    <p class="hint" style="margin-top:0">Altere a quantidade de cada custo e clique em Recalcular. Custo nesta produção = preço × quantidade.</p>
    <div class="table-wrap">
        <table class="table" id="tabela-custos-formacao">
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
                <?php if (!$composicao): ?>
                    <tr>
                        <td colspan="6" class="empty">Nenhum custo na formação. Use “+ Adicionar custo” (cadastrado ou manual).</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($composicao as $linha): ?>
                        <?php
                        $qtdCusto = (float) ($linha['quantidade'] ?? 1);
                        if ($qtdCusto <= 0) {
                            $qtdCusto = 1.0;
                        }
                        $qtdCustoFmt = rtrim(rtrim(number_format($qtdCusto, 4, ',', ''), '0'), ',');
                        $nomeLinha = (string) ($linha['material_nome'] ?? '');
                        $ehManual = composicao_eh_manual($linha);
                        $acaoEditar = $ehManual ? 'Editar' : 'Trocar';
                        ?>
                        <tr data-preco="<?= e((string) (float) ($linha['preco'] ?? $linha['custo_atual'])) ?>">
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
                                <input
                                    type="text"
                                    form="form-producao-ref"
                                    name="qtd_custo[<?= (int) $linha['id'] ?>]"
                                    class="qtd-custo-input"
                                    inputmode="decimal"
                                    value="<?= e($qtdCustoFmt) ?>"
                                    style="width:5.5rem; text-align:right"
                                    aria-label="Quantidade de <?= e($nomeLinha) ?>"
                                >
                            </td>
                            <td class="num"><strong class="custo-linha-prod"><?= e(money_br($linha['custo_lote'])) ?></strong></td>
                            <td class="actions">
                                <a href="<?= e(url('/produtos/' . $produto['id'] . '/composicao/' . $linha['id'] . '/editar')) ?>"><?= e($acaoEditar) ?></a>
                                <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/composicao/' . $linha['id'] . '/remover')) ?>" onsubmit="return confirm(<?= e(json_encode('Remover "' . $nomeLinha . '" da formação?', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>);">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link-btn">Remover</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (array_filter($composicao, static fn (array $l): bool => !composicao_eh_manual($l) && ($l['material_status'] ?? '') === 'inativo')): ?>
        <p class="alert alert-warn" id="aviso-custo-inativo" style="margin-top:8px">
            Custos marcados como INATIVO foram desativados no cadastro, mas continuam considerados nesta formação.
            Troque-os por um custo ativo se não devem mais ser usados.
        </p>
    <?php endif; ?>
    <?php if ($composicao): ?>
        <div class="form-actions" style="margin-top:8px">
            <button type="submit" form="form-producao-ref" class="btn-secondary">Recalcular</button>
        </div>
    <?php endif; ?>

    <div class="resumo-producao">
        <div>
            <span>Peças resultantes</span>
            <strong id="resumo-pecas"><?= e(number_br($qtd, 0)) ?></strong>
        </div>
        <div>
            <span>Custo total</span>
            <strong id="resumo-custo-total"><?= $temComposicao ? e(money_br($custoLote)) : 'R$ 0,00' ?></strong>
        </div>
        <div class="destaque">
            <span>Custo por peça</span>
            <strong id="resumo-custo-peca"><?= $temComposicao ? e(money_br($custoUnitario)) : 'R$ 0,00' ?></strong>
        </div>
    </div>
</section>
<script>
(function () {
    var tabela = document.getElementById('tabela-custos-formacao');
    var pecasEl = document.getElementById('pecas-resultantes');
    var resumoTotal = document.getElementById('resumo-custo-total');
    var resumoPeca = document.getElementById('resumo-custo-peca');
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
        var pecas = 0;
        if (pecasEl) {
            pecas = parseInt((pecasEl.textContent || '').replace(/\./g, ''), 10) || 0;
        }
        if (resumoTotal) resumoTotal.textContent = money(total);
        if (resumoPeca) resumoPeca.textContent = pecas > 0 ? money(total / pecas) : 'R$ 0,00';
    }
    tabela.querySelectorAll('.qtd-custo-input').forEach(function (el) {
        el.addEventListener('input', preview);
    });
    var qtdProd = document.getElementById('quantidade_producao');
    var rendProd = document.getElementById('rendimento_folha');
    if (qtdProd) qtdProd.addEventListener('input', preview);
    if (rendProd) rendProd.addEventListener('input', preview);
})();
</script>

<script>
(function () {
    var custo = <?= json_encode((float) $custoUnitario) ?>;
    var form = document.getElementById('form-preco');
    if (!form) return;

    var wrapMargem = document.getElementById('wrap-margem');
    var wrapMarkup = document.getElementById('wrap-markup');
    var wrapPraticado = document.getElementById('wrap-praticado');
    var wrapSync = document.getElementById('wrap-sync');
    var sync = document.getElementById('sincronizar_calculado');
    var preview = document.getElementById('preco-calc-preview');
    var praticado = document.getElementById('preco_praticado');
    var erroJs = document.getElementById('preco-erro-js');

    function modo() {
        var el = form.querySelector('input[name="modo_preco"]:checked');
        return el ? el.value : 'margem';
    }

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

    function moneySinal(n) {
        if (n < 0 && ceilMoney(-n) > 0) return '-' + money(-n);
        return ceilMoney(n) > 0 ? money(n) : money(0);
    }

    function pct(n) {
        return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }

    function setResultado(preco, impostoPct, comissaoPct) {
        if (preco === null) {
            document.getElementById('res-imposto').textContent = '—';
            document.getElementById('res-comissao').textContent = '—';
            document.getElementById('res-lucro').textContent = '—';
            document.getElementById('res-margem').textContent = '—';
            return;
        }
        var imp = preco * (impostoPct / 100);
        var com = preco * (comissaoPct / 100);
        var lucro = preco - custo - imp - com;
        var margem = preco > 0 ? (lucro / preco) * 100 : null;
        document.getElementById('res-imposto').textContent = money(imp);
        document.getElementById('res-comissao').textContent = money(com);
        document.getElementById('res-custo').textContent = money(custo);
        document.getElementById('res-lucro').textContent = moneySinal(lucro);
        document.getElementById('res-margem').textContent = margem === null ? '—' : pct(margem);
    }

    function calc() {
        var m = modo();
        wrapMargem.style.display = m === 'margem' ? '' : 'none';
        wrapMarkup.style.display = m === 'markup' ? '' : 'none';
        wrapSync.style.display = m === 'manual' ? 'none' : '';
        if (m === 'manual') {
            sync.checked = false;
            praticado.readOnly = false;
        } else {
            praticado.readOnly = sync.checked;
        }

        var imposto = parseBr(document.getElementById('imposto_percentual').value);
        var comissao = parseBr(document.getElementById('comissao_percentual').value);
        if (imposto === null) imposto = 0;
        if (comissao === null) comissao = 0;

        var valor = null;
        var erro = '';

        if (imposto < 0 || comissao < 0) {
            erro = 'Percentuais não podem ser negativos.';
        } else if (m === 'margem') {
            var margem = parseBr(document.getElementById('margem_desejada').value);
            if (margem === null || margem < 0) {
                erro = 'Informe uma margem válida (0 ou maior).';
            } else if ((imposto + comissao + margem) >= 100) {
                erro = 'Imposto + comissão + margem devem somar menos que 100%.';
            } else {
                valor = custo / (1 - ((imposto + comissao + margem) / 100));
            }
        } else if (m === 'markup') {
            var mk = parseBr(document.getElementById('markup').value);
            if (mk !== null && mk > 0) valor = custo * mk;
        } else {
            valor = parseBr(praticado.value);
        }

        if (erro) {
            erroJs.style.display = '';
            erroJs.textContent = erro;
            preview.textContent = '—';
            setResultado(null, 0, 0);
            return;
        }
        erroJs.style.display = 'none';
        erroJs.textContent = '';

        preview.textContent = valor === null ? '—' : money(valor);
        if (m !== 'manual' && sync.checked && valor !== null) {
            praticado.value = ceilMoney(valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        var precoRes = valor;
        if (m === 'manual') precoRes = parseBr(praticado.value);
        else if (m !== 'manual' && sync.checked && valor !== null) precoRes = valor;
        else if (m !== 'manual') precoRes = parseBr(praticado.value);
        setResultado(precoRes, imposto, comissao);
    }

    form.querySelectorAll('input[name="modo_preco"]').forEach(function (el) {
        el.addEventListener('change', calc);
    });
    ['imposto_percentual', 'comissao_percentual', 'margem_desejada', 'markup', 'preco_praticado'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', calc);
    });
    sync.addEventListener('change', calc);
    calc();
})();
</script>

<?php
/** @var list<array{id:int|string, codigo_interno:string, nome:string}> $produtos */
/** @var list<array<string, mixed>> $variacoes */
/** @var int $produtoId */
/** @var int $variacaoId */
/** @var int $quantidade */
/** @var int $posicaoInicial */
/** @var ?array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int} $etiqueta */
/** @var array{nome:string, cnpj:string, site:string, logo?:string} $marca */
/** @var list<string> $errors */

$qsBase = static function (array $extra = []) use ($produtoId, $variacaoId, $quantidade, $posicaoInicial): string {
    $params = array_merge([
        'produto_id' => $produtoId,
        'variacao_id' => $variacaoId,
        'quantidade' => $quantidade,
        'posicao_inicial' => $posicaoInicial,
    ], $extra);
    return http_build_query($params);
};

$folhaSlots = array_chunk(EtiquetaController::ORDEM_VISUAL, 2);
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="etiquetas-layout">
    <section class="card">
        <h2>Gerar etiquetas</h2>
        <p class="muted" style="margin-top:-6px;margin-bottom:14px;">
            Modelo SL61083 — folha Carta, 10 etiquetas (2 colunas × 5 linhas).
        </p>

        <form id="form-etiquetas" class="form-grid" method="get" action="<?= e(url('/etiquetas')) ?>">
            <label class="full">
                Produto
                <select name="produto_id" id="etiqueta-produto" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($produtos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $produtoId === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e(($p['codigo_interno'] ?? '') . ' — ' . ($p['nome'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="full">
                Variação
                <select name="variacao_id" id="etiqueta-variacao" <?= $produtoId ? '' : 'disabled' ?> required>
                    <option value=""><?= $produtoId ? 'Selecione…' : 'Selecione o produto primeiro' ?></option>
                    <?php foreach ($variacoes as $v): ?>
                        <option value="<?= (int) $v['id'] ?>" <?= $variacaoId === (int) $v['id'] ? 'selected' : '' ?>>
                            <?= e(($v['codigo'] ?? '') . ' — ' . ($v['nome'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Quantidade de etiquetas
                <input type="number" name="quantidade" id="etiqueta-quantidade"
                       min="1" max="500" value="<?= (int) $quantidade ?>" required>
            </label>

            <label>
                Posição inicial da folha
                <select name="posicao_inicial" id="etiqueta-posicao">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?= $i ?>" <?= $posicaoInicial === $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>

            <div class="full etiquetas-pos-help">
                <p class="muted" style="margin:0 0 10px;">
                    Use a posição inicial para continuar uma folha parcialmente usada.
                    Ex.: se as posições 1, 2 e 3 já foram utilizadas, escolha posição inicial <strong>4</strong>
                    — as posições 1–3 ficam vazias e a primeira etiqueta nova começa na 4.
                </p>
                <div class="etiquetas-pos-grid" id="etiqueta-pos-grid" aria-label="Mapa de posições da folha">
                    <?php foreach ($folhaSlots as $row): ?>
                        <?php foreach ($row as $pos): ?>
                            <button type="button"
                                    class="etiquetas-pos-cell<?= $posicaoInicial === $pos ? ' is-start' : ($pos < $posicaoInicial ? ' is-skip' : '') ?>"
                                    data-pos="<?= $pos ?>"
                                    title="Posição <?= $pos ?>">
                                <?= $pos ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <p class="muted" style="margin:8px 0 0;font-size:0.8rem;">
                    Ordem da folha (coluna-primeiro): 1–5 na coluna esquerda (topo → base), 6–10 na coluna direita.
                </p>
            </div>

            <div class="form-actions full">
                <button type="submit" class="btn-secondary">Atualizar prévia</button>
                <a class="btn-secondary" id="btn-preview"
                   href="<?= e(url('/etiquetas/preview?' . $qsBase())) ?>"
                   target="_blank" rel="noopener">Pré-visualizar</a>
                <a class="btn-primary" id="btn-imprimir"
                   href="<?= e(url('/etiquetas/imprimir?' . $qsBase())) ?>"
                   target="_blank" rel="noopener">Imprimir</a>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Prévia da etiqueta</h2>
        <?php if ($etiqueta): ?>
            <div class="etiqueta-amostra">
                <div class="etiqueta-amostra-nome"><?= e($etiqueta['nome']) ?></div>
                <div class="etiqueta-amostra-codigo"><?= e($etiqueta['codigo']) ?></div>
                <div class="etiqueta-amostra-sec">
                    <?php if ($etiqueta['tamanho_montada'] !== ''): ?>
                        <div>Tamanho montada: <?= e($etiqueta['tamanho_montada']) ?></div>
                    <?php else: ?>
                        <div class="muted">Tamanho montada: —</div>
                    <?php endif; ?>
                    <?php if ($etiqueta['quantidade_pacote'] !== null): ?>
                        <div>Quantidade do pacote: <?= (int) $etiqueta['quantidade_pacote'] ?></div>
                    <?php else: ?>
                        <div class="muted">Quantidade do pacote: —</div>
                    <?php endif; ?>
                </div>
                <div class="etiqueta-amostra-rodape">
                    <?php if (!empty($marca['logo'])): ?>
                        <img class="etiqueta-amostra-logo" src="<?= e($marca['logo']) ?>" alt="<?= e($marca['nome']) ?>">
                    <?php else: ?>
                        <div><?= e($marca['nome']) ?></div>
                    <?php endif; ?>
                    <div>CNPJ: <?= e($marca['cnpj']) ?></div>
                    <div><?= e($marca['site']) ?></div>
                </div>
            </div>
            <p class="muted" style="margin-top:12px;font-size:0.85rem;">
                Dados lidos do cadastro (somente leitura). Nada é alterado em Produto ou Variação.
            </p>
        <?php else: ?>
            <p class="muted">Selecione um produto e uma variação para ver a prévia.</p>
        <?php endif; ?>
    </section>
</div>

<style>
.etiquetas-layout {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 18px;
    align-items: start;
}
@media (max-width: 960px) {
    .etiquetas-layout { grid-template-columns: 1fr; }
}
.etiquetas-pos-grid {
    display: grid;
    grid-template-columns: repeat(2, 48px);
    gap: 6px;
}
.etiquetas-pos-cell {
    width: 48px;
    height: 36px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    font-weight: 600;
    color: var(--text);
}
.etiquetas-pos-cell.is-skip {
    background: #f0f0f0;
    color: var(--muted);
    text-decoration: line-through;
}
.etiquetas-pos-cell.is-start {
    background: var(--accent-soft);
    border-color: var(--accent);
    color: var(--accent);
}
.etiqueta-amostra {
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 18px 20px;
    background: #fff;
    max-width: 320px;
}
.etiqueta-amostra-nome {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.25;
    margin-bottom: 6px;
}
.etiqueta-amostra-codigo {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 8px;
}
.etiqueta-amostra-sec {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 16px;
    line-height: 1.35;
}
.etiqueta-amostra-rodape {
    font-size: 0.75rem;
    color: var(--muted);
    line-height: 1.4;
    border-top: 1px solid var(--line);
    padding-top: 10px;
}
.etiqueta-amostra-logo {
    display: block;
    height: 28px;
    width: auto;
    max-width: 140px;
    object-fit: contain;
    margin-bottom: 6px;
}
.muted { color: var(--muted); }
</style>

<script>
(function () {
    const produtoSel = document.getElementById('etiqueta-produto');
    const variacaoSel = document.getElementById('etiqueta-variacao');
    const qtdInput = document.getElementById('etiqueta-quantidade');
    const posSel = document.getElementById('etiqueta-posicao');
    const grid = document.getElementById('etiqueta-pos-grid');
    const btnPreview = document.getElementById('btn-preview');
    const btnImprimir = document.getElementById('btn-imprimir');
    const variacoesUrl = <?= json_encode(url('/etiquetas/variacoes'), JSON_UNESCAPED_UNICODE) ?>;
    const previewBase = <?= json_encode(url('/etiquetas/preview'), JSON_UNESCAPED_UNICODE) ?>;
    const printBase = <?= json_encode(url('/etiquetas/imprimir'), JSON_UNESCAPED_UNICODE) ?>;

    function syncPosGrid() {
        const start = parseInt(posSel.value, 10) || 1;
        grid.querySelectorAll('.etiquetas-pos-cell').forEach(function (cell) {
            const pos = parseInt(cell.getAttribute('data-pos'), 10);
            cell.classList.toggle('is-start', pos === start);
            cell.classList.toggle('is-skip', pos < start);
        });
    }

    function buildQuery() {
        const params = new URLSearchParams({
            produto_id: produtoSel.value || '',
            variacao_id: variacaoSel.value || '',
            quantidade: qtdInput.value || '1',
            posicao_inicial: posSel.value || '1'
        });
        return params.toString();
    }

    function syncActionLinks() {
        const qs = buildQuery();
        const ready = produtoSel.value && variacaoSel.value;
        btnPreview.href = previewBase + '?' + qs;
        btnImprimir.href = printBase + '?' + qs;
        btnPreview.classList.toggle('is-disabled', !ready);
        btnImprimir.classList.toggle('is-disabled', !ready);
        if (!ready) {
            btnPreview.setAttribute('aria-disabled', 'true');
            btnImprimir.setAttribute('aria-disabled', 'true');
        } else {
            btnPreview.removeAttribute('aria-disabled');
            btnImprimir.removeAttribute('aria-disabled');
        }
    }

    function guardClick(e) {
        if (!produtoSel.value || !variacaoSel.value) {
            e.preventDefault();
            alert('Selecione um produto e uma variação antes de continuar.');
        }
    }

    produtoSel.addEventListener('change', function () {
        const id = produtoSel.value;
        variacaoSel.innerHTML = '';
        if (!id) {
            variacaoSel.disabled = true;
            variacaoSel.innerHTML = '<option value="">Selecione o produto primeiro</option>';
            syncActionLinks();
            return;
        }
        variacaoSel.disabled = true;
        variacaoSel.innerHTML = '<option value="">Carregando…</option>';
        fetch(variacoesUrl + '?produto_id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const list = (data && data.variacoes) ? data.variacoes : [];
                variacaoSel.innerHTML = '';
                const opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = list.length ? 'Selecione…' : 'Nenhuma variação cadastrada';
                variacaoSel.appendChild(opt0);
                list.forEach(function (v) {
                    const opt = document.createElement('option');
                    opt.value = String(v.id);
                    opt.textContent = (v.codigo || '') + ' — ' + (v.nome || '');
                    variacaoSel.appendChild(opt);
                });
                variacaoSel.disabled = false;
                syncActionLinks();
            })
            .catch(function () {
                variacaoSel.innerHTML = '<option value="">Erro ao carregar</option>';
                variacaoSel.disabled = false;
                syncActionLinks();
            });
    });

    variacaoSel.addEventListener('change', syncActionLinks);
    qtdInput.addEventListener('input', syncActionLinks);
    posSel.addEventListener('change', function () {
        syncPosGrid();
        syncActionLinks();
    });

    grid.querySelectorAll('.etiquetas-pos-cell').forEach(function (cell) {
        cell.addEventListener('click', function () {
            posSel.value = cell.getAttribute('data-pos');
            syncPosGrid();
            syncActionLinks();
        });
    });

    btnPreview.addEventListener('click', guardClick);
    btnImprimir.addEventListener('click', guardClick);

    syncPosGrid();
    syncActionLinks();
})();
</script>

<?php
$fmt = static function (?string $dt): string {
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : $dt;
};
$grupos = $grupos ?? [];
$total = (int) ($total ?? 0);
?>
<section class="detail-head" style="align-items:center">
    <div style="flex:1">
        <p class="hint" style="margin:0 0 4px">
            <a href="<?= e(url('/arquivos')) ?>">Arquivos</a> · caixa / produto
        </p>
        <h2 style="margin:0"><?= e($produto['nome']) ?></h2>
        <p class="hint" style="margin:6px 0 0">
            <strong><?= e($produto['codigo_interno']) ?></strong>
            · <?= $total ?> arquivo<?= $total === 1 ? '' : 's' ?>
        </p>
    </div>
    <div class="form-actions" style="margin:0">
        <a class="btn-primary" href="<?= e(url('/arquivos/novo?produto_id=' . (int) $produto['id'])) ?>">+ Adicionar arquivo</a>
        <a class="btn-secondary" href="<?= e(url('/produtos/' . (int) $produto['id'])) ?>">Ver produto</a>
        <a class="btn-secondary" href="<?= e(url('/arquivos')) ?>">Voltar</a>
    </div>
</section>

<?php if (!empty($avisosLimite)): ?>
    <div class="alert alert-warn">
        <?php foreach ($avisosLimite as $aviso): ?>
            <div><?= e($aviso) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($total === 0): ?>
    <div class="card">
        <p class="hint" style="margin:0">Nenhum arquivo nesta caixa. Use “Adicionar arquivo” para enviar fotos, Corel, faca, nesting, PDF ou mockup.</p>
    </div>
<?php else: ?>
    <?php foreach ($grupos as $tipo => $lista): ?>
        <?php if (!$lista) {
            continue;
        } ?>
        <section class="card" style="margin-top:16px">
            <h2 style="margin:0 0 12px">
                <?= e(Arquivo::tipoIcone($tipo) . ' ' . Arquivo::tipoLabel($tipo)) ?>
                <span class="hint" style="font-weight:400">· <?= count($lista) ?></span>
            </h2>
            <div class="arquivo-cards">
                <?php foreach ($lista as $arq): ?>
                    <?php
                    $isImg = Arquivo::isImagem($arq['extensao'] ?? null, $arq['mime'] ?? null);
                    $canView = Arquivo::podeVisualizarInline($arq);
                    ?>
                    <article class="arquivo-card">
                        <div class="arquivo-card-preview">
                            <?php if ($isImg): ?>
                                <a href="<?= e(url('/arquivos/' . $arq['id'] . '/download?inline=1')) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(url('/arquivos/' . $arq['id'] . '/download?inline=1')) ?>" alt="">
                                </a>
                            <?php else: ?>
                                <span class="arquivo-icon lg"><?= e(Arquivo::tipoIcone((string) $arq['tipo'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="arquivo-card-body">
                            <strong><?= e($arq['nome']) ?></strong>
                            <div class="hint">
                                .<?= e(strtoupper((string) ($arq['extensao'] ?: '?'))) ?>
                                · <?= e(Arquivo::formatTamanho(isset($arq['tamanho']) ? (int) $arq['tamanho'] : null)) ?>
                                · <?= e($fmt($arq['created_at'] ?? null)) ?>
                            </div>
                            <?php if (!empty($arq['observacao'])): ?>
                                <p class="hint" style="margin-top:6px"><?= e($arq['observacao']) ?></p>
                            <?php endif; ?>
                            <div class="actions" style="margin-top:10px">
                                <?php if ($canView): ?>
                                    <a href="<?= e(url('/arquivos/' . $arq['id'] . '/download?inline=1')) ?>" target="_blank" rel="noopener">Visualizar</a>
                                <?php endif; ?>
                                <a href="<?= e(url('/arquivos/' . $arq['id'] . '/download')) ?>">Baixar</a>
                                <a href="<?= e(url('/arquivos/' . $arq['id'] . '/editar')) ?>">Editar</a>
                                <?php if (Auth::isAdmin()): ?>
                                    <form method="post" action="<?= e(url('/arquivos/' . $arq['id'] . '/excluir')) ?>" onsubmit="return confirm('Excluir este arquivo?');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

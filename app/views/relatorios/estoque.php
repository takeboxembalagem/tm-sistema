<?php
$filtros = $filtros ?? [];
$qs = static function (array $extra = []) use ($aba, $filtros): string {
    $params = ['aba' => $aba];
    foreach (array_merge($filtros, $extra) as $k => $v) {
        if ($v !== null && $v !== '' && !($k === 'page' && (int) $v <= 1)) {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
};
$urlAba = static fn (string $a): string => url('/relatorios/estoque?aba=' . $a);
$selected = static fn ($atual, $valor): string => (string) $atual === (string) $valor ? 'selected' : '';
$situacaoLabel = static fn (string $nivel): string => match ($nivel) {
    'zerado' => 'Zerado',
    'baixo' => 'Baixo',
    default => 'Normal',
};
$tipoPecaLabel = static fn (?string $t): string => RelatorioEstoque::TIPOS_PECA[(string) $t] ?? 'Outra';
$statusBadge = static function (string $status): string {
    $ativo = $status === 'ativo';
    return '<span class="badge ' . ($ativo ? 'badge-ok' : 'badge-off') . '">' . ($ativo ? 'Ativo' : 'Inativo') . '</span>';
};
?>
<p class="hint" style="margin-top:0">Consulta e acompanhamento dos estoques da T&M</p>

<nav class="filters" style="margin-bottom:16px;gap:8px">
    <?php foreach ($abas as $chave => $rotulo): ?>
        <a class="btn-<?= $aba === $chave ? 'primary' : 'secondary' ?>" href="<?= e($urlAba($chave)) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($aba !== 'data' || !empty($consultado)): ?>
    <p class="relatorio-pdf">
        <a class="btn-secondary" href="<?= e(url('/relatorios/estoque/pdf?' . $qs())) ?>" target="_blank" rel="noopener">Gerar PDF</a>
        <span class="hint">Com os filtros aplicados, sem paginação.</span>
    </p>
<?php endif; ?>

<?php require __DIR__ . '/estoque/_' . $aba . '.php'; ?>

<?php if (isset($pagina) && $pagina['pages'] > 1): ?>
    <nav class="pagination" style="margin-top:16px">
        <?php for ($i = 1; $i <= $pagina['pages']; $i++): ?>
            <a class="<?= $i === $pagina['page'] ? 'is-active' : '' ?>" href="<?= e(url('/relatorios/estoque?' . $qs(['page' => $i]))) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

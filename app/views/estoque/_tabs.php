<?php
$tab = $estoqueTab ?? 'pecas';
?>
<nav class="filters" style="margin-bottom:16px;gap:8px">
    <a class="btn-<?= $tab === 'pecas' ? 'primary' : 'secondary' ?>" href="<?= e(url('/estoque/pecas')) ?>">Peças</a>
    <a class="btn-<?= $tab === 'papeis' ? 'primary' : 'secondary' ?>" href="<?= e(url('/estoque/papeis')) ?>">Papéis</a>
</nav>

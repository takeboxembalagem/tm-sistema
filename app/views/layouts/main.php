<?php
$areas = Areas::visiveis();
$areaAtual = Areas::daPagina((string) ($page ?? ''));
$areaInfo = Areas::todas()[$areaAtual];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? config('app_short')) ?> — <?= e(config('app_short')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="layout" data-sidebar="open">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand" data-area-atual="<?= e($areaAtual) ?>">
            <span class="brand-mark"><?= e($areaInfo['sigla']) ?></span>
            <div class="brand-text">
                <strong><?= e($areaInfo['nome']) ?></strong>
                <span><?= e($areaInfo['descricao']) ?></span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($areas as $chave => $area): ?>
                <?php $aberta = $chave === $areaAtual; ?>
                <div class="nav-area<?= $aberta ? ' is-current' : '' ?>" data-area="<?= e($chave) ?>">
                    <a class="nav-area-head" href="<?= e(url($area['inicio'])) ?>" title="<?= e($area['nome']) ?>"
                       aria-expanded="<?= $aberta ? 'true' : 'false' ?>" aria-controls="nav-area-<?= e($chave) ?>"><span><?= e($area['icone']) ?></span><em><?= e($area['nome']) ?></em><i class="nav-area-caret" aria-hidden="true">▾</i></a>
                    <div class="nav-area-items" id="nav-area-<?= e($chave) ?>"<?= $aberta ? '' : ' hidden' ?>>
                        <?php foreach ($area['itens'] as $item): ?>
                            <a class="<?= ($page ?? '') === $item['page'] ? 'is-active' : '' ?>" href="<?= e(url($item['url'])) ?>" title="<?= e($item['rotulo']) ?>"><span><?= e($item['icone']) ?></span><em><?= e($item['rotulo']) ?></em></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-user">
            <div>
                <strong><?= e(Auth::user()['nome'] ?? '') ?></strong>
                <span><?= e(Auth::user()['email'] ?? '') ?></span>
            </div>
            <form method="post" action="<?= e(url('/logout')) ?>" class="logout-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-text">Sair</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn" id="toggle-sidebar" aria-label="Recolher menu">☰</button>
            <span class="area-badge" data-area="<?= e($areaAtual) ?>"><?= e($areaInfo['nome']) ?></span>
            <h1><?= e($title ?? '') ?></h1>
        </header>
        <main class="content">
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
    <script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>

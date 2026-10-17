<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> - <?= e(config('app_short')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
    <style>
        body { background: #fff; margin: 0; }
        .envio { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        .envio-texto { white-space: pre-wrap; word-break: break-word; font: inherit; line-height: 1.55; margin: 0; }
        @media print {
            /* Margem zero: o navegador não tem onde desenhar cabeçalho/rodapé (data, título, URL, página). */
            @page { margin: 0; }
            html, body { background: #fff; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .envio {
                max-width: none;
                margin: 0;
                padding: 12mm 16mm;
                -webkit-box-decoration-break: clone;
                box-decoration-break: clone;
            }
            .envio .card { border: 0; border-radius: 0; box-shadow: none; padding: 0; background: #fff; }
            .envio-texto { color: #000; font-size: 11pt; line-height: 1.5; orphans: 3; widows: 3; }
        }
    </style>
</head>
<body>
    <main class="envio">
        <div class="filters no-print" style="gap:8px;margin-bottom:16px">
            <?php if ($textoEnvio !== ''): ?>
                <button type="button" class="btn-primary" data-copy-target="#texto-envio" data-copy-msg="Cadastro copiado." data-copy-keep-label>Copiar cadastro</button>
                <button type="button" class="btn-secondary" data-print>Imprimir / Salvar PDF</button>
            <?php endif; ?>
            <a class="btn-secondary" href="<?= e(url('/empresa')) ?>">Voltar</a>
        </div>
        <div id="copy-status" class="alert alert-success no-print" role="status" hidden></div>

        <div class="card">
            <?php if ($textoEnvio === ''): ?>
                <p class="hint">Nenhum dado cadastrado ainda.</p>
            <?php else: ?>
                <pre class="envio-texto" id="texto-envio"><?= e($textoEnvio) ?></pre>
            <?php endif; ?>
        </div>
    </main>
    <script src="<?= e(url('/assets/js/empresa.js')) ?>"></script>
</body>
</html>

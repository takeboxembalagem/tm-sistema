<?php
/** @var list<string> $errors */
/** @var list<array<int, bool>> $folhas */
/** @var ?array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int} $etiqueta */
/** @var array{nome:string, cnpj:string, site:string, logo?:string} $marca */
/** @var bool $autoPrint */
/** @var string $modo */
/** @var string $pdfUrl */

$ordemVisual = EtiquetaController::ORDEM_VISUAL;
$pdfUrl = $pdfUrl ?? '';
$cssUrl = url('/assets/css/etiquetas-print.css');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Etiquetas') ?></title>
    <link rel="stylesheet" href="<?= e($cssUrl) ?>">
</head>
<body class="etq-body<?= $autoPrint ? ' etq-print-mode' : ' etq-preview-mode' ?>">
<?php if (!$autoPrint): ?>
    <div class="etq-toolbar no-print">
        <a href="<?= e(url('/etiquetas')) ?>">← Voltar</a>
        <?php if ($etiqueta && empty($errors) && $pdfUrl !== ''): ?>
            <a href="<?= e($pdfUrl) ?>">Gerar PDF</a>
        <?php endif; ?>
        <span class="etq-toolbar-note">Modelo SL61083 — Carta — 10 etiquetas/folha</span>
    </div>
<?php endif; ?>

<?php if (empty($errors)): ?>
    <div class="etq-print-hint no-print" role="note">
        <strong>Como imprimir (Epson / impressora comum)</strong>
        Use tamanho real / escala <strong>100%</strong>.
        Desative “Ajustar à página”, “Fit to page” e qualquer opção de “Redimensionar”.
        A folha deve sair em Carta, sem compactar o layout.
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="etq-error no-print">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
        <p><a href="<?= e(url('/etiquetas')) ?>">Voltar às etiquetas</a></p>
    </div>
<?php else: ?>
    <?php foreach ($folhas as $folhaIndex => $folha): ?>
        <section class="etq-sheet" aria-label="Folha <?= (int) $folhaIndex + 1 ?>">
            <?php foreach ($ordemVisual as $pos): ?>
                <?php $preenchida = !empty($folha[$pos]); ?>
                <div class="etq-label<?= $preenchida ? '' : ' is-empty' ?>" data-pos="<?= (int) $pos ?>">
                    <?php if ($preenchida && $etiqueta): ?>
                        <div class="etq-label-inner">
                            <div class="etq-nome"><?= e($etiqueta['nome']) ?></div>
                            <div class="etq-codigo"><?= e($etiqueta['codigo']) ?></div>
                            <div class="etq-sec">
                                <?php if ($etiqueta['tamanho_montada'] !== ''): ?>
                                    <div><?= e($etiqueta['tamanho_montada']) ?></div>
                                <?php endif; ?>
                                <?php if ($etiqueta['quantidade_pacote'] !== null): ?>
                                    <div>Pcte: <?= (int) $etiqueta['quantidade_pacote'] ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="etq-rodape">
                                <?php if (!empty($marca['logo'])): ?>
                                    <img class="etq-logo" src="<?= e($marca['logo']) ?>" alt="<?= e($marca['nome']) ?>">
                                <?php else: ?>
                                    <div class="etq-marca"><?= e($marca['nome']) ?></div>
                                <?php endif; ?>
                                <div>CNPJ: <?= e($marca['cnpj']) ?></div>
                                <div><?= e($marca['site']) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($autoPrint && empty($errors)): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 200);
    });
</script>
<?php endif; ?>
</body>
</html>

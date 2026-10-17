<?php
$qtdVariacoes = (int) $variacoesCount;
$qtdArquivos = (int) $arquivosCount;
$qtdPecas = (int) $pecasEstoqueCount;
$qtdResultados = (int) $resultadosCount;
$qtdVinculos = (int) $vinculosSemResultado;
?>
<div class="card form-card">
    <h2>Excluir produto</h2>
    <p>
        <strong><?= e($produto['codigo_interno']) ?></strong>
        — <?= e($produto['nome']) ?>
    </p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($qtdResultados > 0): ?>
        <div class="alert alert-warn">
            Este produto possui resultados comerciais registrados em <?= $qtdResultados ?> vínculo(s)
            de coleção e <strong>não pode ser excluído</strong>, para preservar o histórico.
        </div>
        <p class="hint">Para retirá-lo de uso, desative o produto na listagem.</p>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Voltar para a listagem</a>
            <a class="btn-secondary" href="<?= e(url('/produtos/' . $produto['id'])) ?>">Ver produto</a>
        </div>
    <?php elseif ($qtdPecas > 0): ?>
        <div class="alert alert-warn">
            Este produto está vinculado a <?= $qtdPecas ?> peça(s) do estoque físico
            e <strong>não pode ser excluído</strong> enquanto esse vínculo existir.
        </div>
        <p class="hint">
            A exclusão removeria a associação com o produto na consulta da peça, e as
            movimentações não guardam o produto original. Desvincule as peças no estoque
            (sem alterar saldos automaticamente) e tente novamente.
        </p>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('/estoque/pecas')) ?>">Abrir estoque de peças</a>
            <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Voltar para a listagem</a>
        </div>
    <?php elseif ($qtdArquivos > 0): ?>
        <div class="alert alert-warn">
            Este produto possui <?= $qtdArquivos ?> arquivo(s) técnico(s) vinculado(s)
            e <strong>não pode ser excluído</strong> enquanto esses arquivos existirem.
        </div>
        <p class="hint">
            Remova ou desvincule os arquivos na tela de arquivos do produto e tente novamente.
            A exclusão não apaga a biblioteca central nem arquivos de outros produtos.
        </p>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('/arquivos/produto/' . $produto['id'])) ?>">Abrir arquivos</a>
            <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Voltar para a listagem</a>
        </div>
    <?php else: ?>
        <div class="alert alert-warn">
            Esta operação é <strong>permanente</strong> e não poderá ser desfeita pela aplicação.
            O produto pai e todas as variações vinculadas serão excluídos.
        </div>
        <ul class="hint">
            <li>
                <?= $qtdVariacoes === 0
                    ? 'Nenhuma variação vinculada.'
                    : $qtdVariacoes . ' variação(ões) serão excluídas (incluindo composições próprias e GTINs).' ?>
            </li>
            <?php if ($qtdVinculos > 0): ?>
                <li>
                    <?= $qtdVinculos ?> vínculo(s) com coleção sem resultado comercial serão removidos.
                    As coleções em si permanecem.
                </li>
            <?php endif; ?>
            <li>Composição de custos, preços e vínculos exclusivos deste produto serão removidos.</li>
            <li>Materiais, outros produtos, coleções e o estoque físico não serão apagados.</li>
            <li>Nenhuma peça de estoque está vinculada a este produto.</li>
        </ul>
        <form method="post" action="<?= e(url('/produtos/' . $produto['id'] . '/excluir')) ?>" class="form">
            <?= Csrf::field() ?>
            <div class="form-actions">
                <button type="submit" class="btn-danger">Excluir permanentemente</button>
                <a class="btn-secondary" href="<?= e(url('/produtos')) ?>">Cancelar</a>
            </div>
        </form>
    <?php endif; ?>
</div>

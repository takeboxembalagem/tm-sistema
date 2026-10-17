<?php
$qtd = (int) $produtosCount;
$qtdVariacoes = (int) $variacoesCount;
$qtdTarefas = (int) $tarefasCount;
$qtdResultados = (int) $resultadosCount;
?>
<div class="card form-card">
    <h2>Excluir coleção</h2>
    <p><strong><?= e($colecao['nome']) ?></strong></p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($qtdResultados > 0): ?>
        <div class="alert alert-warn">
            Esta coleção possui resultados comerciais registrados em <?= $qtdResultados ?> variação(ões)
            e não pode ser excluída, para preservar o histórico.
        </div>
        <p class="hint">Para retirá-la de uso, inative a coleção na tela de detalhes.</p>
        <div class="form-actions">
            <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Voltar para a coleção</a>
        </div>
    <?php else: ?>
        <?php if ($qtdVariacoes > 0 || $qtdTarefas > 0): ?>
            <ul class="hint">
                <?php if ($qtdVariacoes > 0): ?>
                    <li><?= $qtdVariacoes ?> variação(ões) vinculada(s), sem resultados registrados: serão desvinculadas e <strong>mantidas</strong>.</li>
                <?php endif; ?>
                <?php if ($qtdTarefas > 0): ?>
                    <li><?= $qtdTarefas ?> tarefa(s) da coleção: serão <strong>removidas</strong>.</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($qtd > 0): ?>
            <div class="alert alert-warn">
                Esta coleção possui produtos vinculados (<?= $qtd ?>).
            </div>
            <p class="hint">
                A exclusão da coleção <strong>não apaga os produtos</strong>.
                Eles permanecerão no sistema sem coleção.
            </p>
            <form method="post" action="<?= e(url('/colecoes/' . $colecao['id'] . '/excluir')) ?>" class="form">
                <?= Csrf::field() ?>
                <input type="hidden" name="confirmar_desvincular" value="1">
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Remover vínculo e excluir coleção</button>
                    <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Cancelar</a>
                </div>
            </form>
        <?php else: ?>
            <p class="hint">Esta coleção não possui produtos. Confirme a exclusão.</p>
            <form method="post" action="<?= e(url('/colecoes/' . $colecao['id'] . '/excluir')) ?>" class="form">
                <?= Csrf::field() ?>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Excluir coleção</button>
                    <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

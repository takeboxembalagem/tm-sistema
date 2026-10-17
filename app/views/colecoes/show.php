<?php
$capa = upload_url($colecao['imagem'] ?? null);
$fmtData = static function (?string $d): string {
    if ($d === null || $d === '') {
        return 'Não informado';
    }
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : $d;
};
$tipoRotulo = Colecao::TIPOS[$colecao['tipo'] ?? ''] ?? 'Não informado';
$etapaRotulo = Colecao::ETAPAS[$colecao['etapa'] ?? ''] ?? 'Não informado';
$temPeriodo = !empty($colecao['periodo_inicio']) || !empty($colecao['periodo_fim']);
?>
<section class="detail-head">
    <?php if ($capa): ?>
        <img class="detail-photo" src="<?= e($capa) ?>" alt="">
    <?php else: ?>
        <span class="detail-photo thumb-empty">TM</span>
    <?php endif; ?>
    <div>
        <?php if (!empty($colecao['codigo'])): ?>
            <p class="hint"><?= e($colecao['codigo']) ?></p>
        <?php endif; ?>
        <h2><?= e($colecao['nome']) ?></h2>
        <p class="hint"><?= e($tipoRotulo . ' · ' . $etapaRotulo) ?></p>
        <?php if ($temPeriodo): ?>
            <p class="hint">Período: <?= e($fmtData($colecao['periodo_inicio'] ?? null) . ' até ' . $fmtData($colecao['periodo_fim'] ?? null)) ?></p>
        <?php endif; ?>
        <span class="badge <?= $colecao['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
            <?= $colecao['status'] === 'ativo' ? 'Ativa' : 'Inativa' ?>
        </span>
        <div class="form-actions" style="margin-top:16px">
            <a class="btn-primary" href="<?= e(url('/colecoes/' . $colecao['id'] . '/editar')) ?>">Editar</a>
            <form method="post" action="<?= e(url('/colecoes/' . $colecao['id'] . '/status')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-secondary"><?= $colecao['status'] === 'ativo' ? 'Inativar' : 'Reativar' ?></button>
            </form>
            <?php if (Auth::isAdmin()): ?>
                <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'] . '/excluir')) ?>">Excluir</a>
            <?php endif; ?>
            <a class="btn-text" href="<?= e(url('/colecoes')) ?>">Voltar</a>
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

<?php
$tarefasUrl = '/colecoes/' . $colecao['id'] . '/tarefas';
$tarefaOld = $tarefaOld ?? [];
$valTarefa = static function (string $key, ?array $tarefa) use ($tarefaOld): string {
    if (array_key_exists($key, $tarefaOld)) {
        return (string) $tarefaOld[$key];
    }
    return $tarefa ? (string) ($tarefa[$key] ?? '') : '';
};
?>
<section class="card" id="planejamento" style="margin-bottom:16px">
    <div class="toolbar" style="margin-bottom:12px">
        <h2 style="margin:0">
            Planejamento — <?= (int) $totaisTarefas['concluidas'] ?> de <?= (int) $totaisTarefas['total'] ?> concluídas
        </h2>
        <?php if ($novaTarefa): ?>
            <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Cancelar</a>
        <?php else: ?>
            <a class="btn-primary" href="<?= e(url('/colecoes/' . $colecao['id'] . '?nova_tarefa=1#planejamento')) ?>">+ Nova tarefa</a>
        <?php endif; ?>
    </div>

    <?php if ($novaTarefa): ?>
        <form method="post" action="<?= e(url($tarefasUrl)) ?>" class="filters" style="margin-bottom:12px; align-items:flex-end">
            <?= Csrf::field() ?>
            <label style="flex:1; min-width:220px">
                Título da ação *
                <input type="text" name="titulo" maxlength="255" required autofocus
                       value="<?= e($valTarefa('titulo', null)) ?>" placeholder="Ex.: Criar artes">
            </label>
            <label>
                Prazo
                <input type="date" name="data_prazo" value="<?= e($valTarefa('data_prazo', null)) ?>">
            </label>
            <button type="submit" class="btn-primary">Salvar tarefa</button>
        </form>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:1%"></th>
                    <th>Ação</th>
                    <th>Prazo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tarefas): ?>
                    <tr>
                        <td colspan="4" class="empty">Nenhuma tarefa no planejamento. Use “+ Nova tarefa”.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tarefas as $t): ?>
                        <?php
                        $tid = (int) $t['id'];
                        $feita = (int) $t['concluida'] === 1;
                        $tarefaBase = $tarefasUrl . '/' . $tid;
                        ?>
                        <?php if ($editarTarefaId === $tid): ?>
                            <tr>
                                <td colspan="4">
                                    <form method="post" action="<?= e(url($tarefaBase . '/editar')) ?>" class="filters" style="align-items:flex-end">
                                        <?= Csrf::field() ?>
                                        <label style="flex:1; min-width:220px">
                                            Título da ação *
                                            <input type="text" name="titulo" maxlength="255" required autofocus
                                                   value="<?= e($valTarefa('titulo', $t)) ?>">
                                        </label>
                                        <label>
                                            Prazo
                                            <input type="date" name="data_prazo" value="<?= e($valTarefa('data_prazo', $t)) ?>">
                                        </label>
                                        <button type="submit" class="btn-primary">Salvar</button>
                                        <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Cancelar</a>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td style="font-size:1.2rem"><?= $feita ? '☑' : '☐' ?></td>
                                <td>
                                    <?php if ($feita): ?>
                                        <span class="hint"><?= e($t['titulo']) ?></span>
                                        <div class="hint">Concluída em <?= e(date('d/m/Y H:i', (int) strtotime((string) $t['concluida_em']))) ?></div>
                                    <?php else: ?>
                                        <strong><?= e($t['titulo']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($t['data_prazo']) ? e($fmtData($t['data_prazo'])) : '<span class="hint">Sem prazo</span>' ?></td>
                                <td class="actions">
                                    <form method="post" action="<?= e(url($tarefaBase . ($feita ? '/reabrir' : '/concluir'))) ?>">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn"><?= $feita ? 'Reabrir' : 'Concluir' ?></button>
                                    </form>
                                    <a href="<?= e(url('/colecoes/' . $colecao['id'] . '?editar_tarefa=' . $tid . '#planejamento')) ?>">Editar</a>
                                    <form method="post" action="<?= e(url($tarefaBase . '/excluir')) ?>"
                                          onsubmit="return confirm('Excluir esta tarefa?');">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="card">
    <h2>Dados</h2>
    <dl class="defs">
        <dt>Nome</dt>
        <dd><?= e($colecao['nome']) ?></dd>
        <dt>Código</dt>
        <dd><?= e($colecao['codigo'] ?: 'Não informado') ?></dd>
        <dt>Tipo</dt>
        <dd><?= e($tipoRotulo) ?></dd>
        <dt>Etapa</dt>
        <dd><?= e($etapaRotulo) ?></dd>
        <dt>Período</dt>
        <dd><?= $temPeriodo ? e($fmtData($colecao['periodo_inicio'] ?? null) . ' até ' . $fmtData($colecao['periodo_fim'] ?? null)) : 'Não informado' ?></dd>
        <dt>Descrição</dt>
        <dd><?= e($colecao['descricao'] ?: '—') ?></dd>
        <dt>Observações</dt>
        <dd><?= e($colecao['observacoes'] ?: '—') ?></dd>
        <dt>Variações</dt>
        <dd><strong><?= (int) $variacoesCount ?></strong></dd>
    </dl>
</div>

<section class="card" id="variacoes" style="margin-top:16px">
    <div class="toolbar" style="margin-bottom:12px">
        <h2 style="margin:0">Variações (<?= (int) $variacoesCount ?>)</h2>
        <?php if ($adicionandoVariacao): ?>
            <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'])) ?>">Fechar busca</a>
        <?php else: ?>
            <a class="btn-primary" href="<?= e(url('/colecoes/' . $colecao['id'] . '?adicionar_variacao=1#variacoes')) ?>">Adicionar variação</a>
        <?php endif; ?>
    </div>

    <?php if ($adicionandoVariacao): ?>
        <form method="get" action="<?= e(url('/colecoes/' . $colecao['id'])) ?>#variacoes" class="filters" style="margin-bottom:12px">
            <input type="hidden" name="adicionar_variacao" value="1">
            <input type="search" name="busca_variacao" value="<?= e($buscaVariacao) ?>"
                   placeholder="Buscar por código, nome da variação ou produto pai..." style="flex:1;min-width:220px" autofocus>
            <button type="submit" class="btn-secondary">Buscar</button>
        </form>

        <div class="table-wrap" style="margin-bottom:16px">
            <table class="table">
                <thead>
                    <tr>
                        <th>Variação disponível</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$variacoesDisponiveis): ?>
                        <tr>
                            <td colspan="2" class="empty">Nenhuma variação disponível<?= $buscaVariacao !== '' ? ' para esta busca' : '' ?>.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($variacoesDisponiveis as $vd): ?>
                            <tr>
                                <td>
                                    <strong><?= e($vd['variacao_nome'] . ' — ' . $vd['variacao_codigo']) ?></strong>
                                    <div class="hint">Produto pai: <?= e($vd['produto_nome']) ?> (<?= e($vd['produto_codigo']) ?>)</div>
                                </td>
                                <td class="actions">
                                    <form method="post" action="<?= e(url('/colecoes/' . $colecao['id'] . '/vincular-variacao')) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="variacao_id" value="<?= (int) $vd['variacao_id'] ?>">
                                        <input type="hidden" name="busca_variacao" value="<?= e($buscaVariacao) ?>">
                                        <button type="submit" class="link-btn">Adicionar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($maisVariacoes): ?>
            <p class="hint" style="margin-top:-8px;margin-bottom:16px">Mostrando as <?= (int) $limiteBuscaVariacoes ?> primeiras. Refine a busca para encontrar outras.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    $resultadoOld = $resultadoOld ?? [];
    $valResultado = static function (string $key, array $v) use ($resultadoOld): string {
        if (array_key_exists($key, $resultadoOld)) {
            return (string) $resultadoOld[$key];
        }
        $atual = $v[$key] ?? null;
        if ($atual === null || $atual === '') {
            return '';
        }
        return $key === 'valor_vendido' ? number_br((float) $atual, 2) : (string) $atual;
    };
    $fmtQtd = static fn ($q): string => $q === null || $q === '' ? '—' : number_br((int) $q, 0);
    ?>
    <p class="hint" style="margin-bottom:8px">
        Os resultados desta coleção são apenas registros históricos/comerciais e não alteram o estoque da variação.
    </p>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Variação</th>
                    <th>Produto pai</th>
                    <th>Produzida</th>
                    <th>Vendida</th>
                    <th>Valor vendido</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$variacoes): ?>
                    <tr>
                        <td colspan="7" class="empty">Nenhuma variação nesta coleção.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($variacoes as $v): ?>
                        <?php
                        $vid = (int) $v['variacao_id'];
                        $semResultado = $v['qtd_produzida'] === null && $v['qtd_vendida'] === null
                            && $v['valor_vendido'] === null && ($v['observacao'] ?? null) === null;
                        ?>
                        <tr>
                            <td><strong><?= e($v['variacao_codigo']) ?></strong></td>
                            <td><?= e($v['variacao_nome']) ?></td>
                            <td>
                                <a href="<?= e(url('/produtos/' . $v['produto_id'])) ?>"><?= e($v['produto_nome']) ?></a>
                                <div class="hint"><?= e($v['produto_codigo']) ?></div>
                            </td>
                            <td><?= e($fmtQtd($v['qtd_produzida'])) ?></td>
                            <td><?= e($fmtQtd($v['qtd_vendida'])) ?></td>
                            <td><?= $v['valor_vendido'] === null ? '—' : e(money_br((float) $v['valor_vendido'])) ?></td>
                            <td class="actions">
                                <?php if ($editarResultadoId !== $vid): ?>
                                    <a href="<?= e(url('/colecoes/' . $colecao['id'] . '?editar_resultado=' . $vid . '#variacoes')) ?>"><?= $semResultado ? 'Informar resultado' : 'Editar resultado' ?></a>
                                <?php endif; ?>
                                <form method="post"
                                      action="<?= e(url('/colecoes/' . $colecao['id'] . '/desvincular-variacao')) ?>"
                                      onsubmit="return confirm('Remover esta variação da coleção?\n\nA variação e o produto não serão excluídos.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="variacao_id" value="<?= $vid ?>">
                                    <button type="submit" class="link-btn">Remover</button>
                                </form>
                            </td>
                        </tr>
                        <?php if ($editarResultadoId === $vid): ?>
                            <tr>
                                <td colspan="7">
                                    <form method="post" action="<?= e(url('/colecoes/' . $colecao['id'] . '/variacoes/' . $vid . '/resultado')) ?>">
                                        <?= Csrf::field() ?>
                                        <p style="margin:0 0 8px"><strong>Resultado — <?= e($v['variacao_nome']) ?></strong></p>
                                        <div class="filters" style="align-items:flex-end">
                                            <label>
                                                Quantidade produzida
                                                <input type="text" name="qtd_produzida" inputmode="numeric" autofocus
                                                       value="<?= e($valResultado('qtd_produzida', $v)) ?>" placeholder="Ex.: 600">
                                            </label>
                                            <label>
                                                Quantidade vendida
                                                <input type="text" name="qtd_vendida" inputmode="numeric"
                                                       value="<?= e($valResultado('qtd_vendida', $v)) ?>" placeholder="Ex.: 500">
                                            </label>
                                            <label>
                                                Valor vendido (R$)
                                                <input type="text" name="valor_vendido" inputmode="decimal"
                                                       value="<?= e($valResultado('valor_vendido', $v)) ?>" placeholder="Ex.: 5.000,00">
                                            </label>
                                            <label style="flex:1; min-width:220px">
                                                Observação
                                                <input type="text" name="observacao" maxlength="2000"
                                                       value="<?= e($valResultado('observacao', $v)) ?>" placeholder="Opcional">
                                            </label>
                                            <button type="submit" class="btn-primary">Salvar</button>
                                            <a class="btn-secondary" href="<?= e(url('/colecoes/' . $colecao['id'] . '#variacoes')) ?>">Cancelar</a>
                                        </div>
                                        <p class="hint" style="margin:8px 0 0">Deixe em branco o que ainda não souber. Estes números não alteram o estoque.</p>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if ($variacoes): ?>
                <tfoot>
                    <tr>
                        <th colspan="3">Total</th>
                        <th><?= e(number_br($totaisResultado['qtd_produzida'], 0)) ?></th>
                        <th><?= e(number_br($totaisResultado['qtd_vendida'], 0)) ?></th>
                        <th><?= e(money_br($totaisResultado['valor_vendido'])) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>
<?php
$capa = upload_url($item['imagem'] ?? null);
$old = $old ?? [];
$movTipo = (string) ($old['tipo'] ?? 'entrada');
$movQtd = (string) ($old['quantidade'] ?? '');
$movObs = (string) ($old['observacao'] ?? '');
$temHistorico = !empty($movimentos);
$tipoLabel = match ((string) $item['tipo']) {
    'tampa' => 'Tampa',
    'fundo' => 'Fundo',
    default => 'Outra',
};
$tipoBadge = static function (string $tipo): string {
    return match ($tipo) {
        'entrada' => 'badge-entrada',
        'saida' => 'badge-saida',
        'ajuste' => 'badge-ajuste',
        default => '',
    };
};
?>
<?php require __DIR__ . '/../_tabs.php'; ?>

<section class="detail-head detail-head--estoque">
    <?php if ($capa): ?>
        <img class="detail-photo" src="<?= e($capa) ?>" alt="">
    <?php else: ?>
        <span class="detail-photo thumb-empty">TM</span>
    <?php endif; ?>
    <div>
        <p class="hint"><?= e($item['codigo']) ?></p>
        <h2><?= e($item['nome']) ?></h2>
        <p>
            <strong><?= e(number_br($item['quantidade'], 0)) ?></strong> <?= e($item['unidade']) ?>
            <?php if (!empty($item['localizacao'])): ?>
                <span class="hint"> · Local: <?= e($item['localizacao']) ?></span>
            <?php endif; ?>
        </p>
        <span class="badge <?= $item['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
            <?= $item['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
        </span>
        <div class="form-actions" style="margin-top:16px">
            <a class="btn-primary" href="<?= e(url('/estoque/pecas/' . $item['id'] . '/editar')) ?>">Editar</a>
            <form method="post" action="<?= e(url('/estoque/pecas/' . $item['id'] . '/status')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-secondary"><?= $item['status'] === 'ativo' ? 'Inativar' : 'Reativar' ?></button>
            </form>
            <?php if (Auth::isAdmin() && !$temHistorico): ?>
                <form method="post" action="<?= e(url('/estoque/pecas/' . $item['id'] . '/excluir')) ?>" onsubmit="return confirm('Excluir esta peça?');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn-secondary">Excluir</button>
                </form>
            <?php endif; ?>
            <a class="btn-text" href="<?= e(url('/estoque/pecas')) ?>">Voltar</a>
        </div>
        <?php if (Auth::isAdmin() && $temHistorico): ?>
            <p class="hint" id="exclusao-bloqueada-historico">Peças com movimentações não podem ser excluídas. Inative-a para preservar o histórico.</p>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Dados</h2>
    <dl class="defs">
        <dt>Tipo</dt>
        <dd><?= e($tipoLabel) ?></dd>
        <dt>Produto</dt>
        <dd>
            <?php if (!empty($item['produto_id'])): ?>
                <a href="<?= e(url('/produtos/' . $item['produto_id'])) ?>">
                    <?= e(($item['produto_codigo'] ?? '') . ' — ' . ($item['produto_nome'] ?? '')) ?>
                </a>
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>
        <dt>Cor</dt>
        <dd><?= e($item['cor'] ?: '—') ?></dd>
        <dt>Estampa</dt>
        <dd><?= e($item['estampa'] ?: '—') ?></dd>
        <dt>Localização</dt>
        <dd><?= e($item['localizacao'] ?: '—') ?></dd>
        <dt>Observação</dt>
        <dd><?= e($item['observacao'] ?: '—') ?></dd>
    </dl>
</div>

<section class="card" style="margin-top:16px">
    <h2>Registrar movimento</h2>
    <form method="post" action="<?= e(url('/estoque/pecas/' . $item['id'] . '/movimentos')) ?>" class="form"
          onsubmit="var t=this.tipo.value,q=this.quantidade.value;if(t==='entrada')return true;if(t==='ajuste')return confirm('Confirmar AJUSTE: o valor informado ('+q+') será o NOVO SALDO.');return confirm('Confirmar SAÍDA de '+q+'?');">
        <?= Csrf::field() ?>
        <div class="form-grid">
            <label>
                Tipo *
                <select name="tipo" required>
                    <option value="entrada" <?= $movTipo === 'entrada' ? 'selected' : '' ?>>Entrada</option>
                    <option value="saida" <?= $movTipo === 'saida' ? 'selected' : '' ?>>Saída</option>
                    <option value="ajuste" <?= $movTipo === 'ajuste' ? 'selected' : '' ?>>Ajuste (novo saldo absoluto)</option>
                </select>
            </label>
            <label>
                Quantidade / saldo *
                <input type="text" name="quantidade" required placeholder="Ex.: 10 ou 10,5" value="<?= e($movQtd) ?>">
                <small class="hint">Em ajuste, informe o novo saldo total (não o delta).</small>
            </label>
            <label class="span-2">
                Observação
                <input type="text" name="observacao" maxlength="255" value="<?= e($movObs) ?>">
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Registrar</button>
        </div>
    </form>
</section>

<section class="card" style="margin-top:16px">
    <h2>Histórico</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Qtd.</th>
                    <th>Anterior</th>
                    <th>Após</th>
                    <th>Obs.</th>
                    <th>Usuário</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$movimentos): ?>
                    <tr><td colspan="7" class="empty">Nenhum movimento.</td></tr>
                <?php else: ?>
                    <?php foreach ($movimentos as $m): ?>
                        <?php $t = (string) $m['tipo']; ?>
                        <tr>
                            <td><?= e($m['created_at']) ?></td>
                            <td>
                                <span class="badge <?= e($tipoBadge($t)) ?>"><?= e(ucfirst($t)) ?></span>
                            </td>
                            <td><?= e(number_br($m['quantidade'], 0)) ?></td>
                            <td><?= $m['saldo_anterior'] !== null ? e(number_br($m['saldo_anterior'], 0)) : '—' ?></td>
                            <td><?= e(number_br($m['saldo_apos'], 0)) ?></td>
                            <td><?= e($m['observacao'] ?: '—') ?></td>
                            <td><?= e($m['usuario_nome'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

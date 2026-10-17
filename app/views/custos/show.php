<?php
$fmt = static function (?string $dt): string {
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('d/m/Y H:i', $t) : $dt;
};
?>
<section class="detail-head">
    <div>
        <p class="hint"><?= e($material['categoria_nome'] ?: 'Sem categoria') ?> · <?= e($material['unidade_sigla'] ?: ($material['unidade_nome'] ?: '—')) ?> · <?= e(forma_cobranca_label((string) ($material['forma_cobranca'] ?? 'unidade'))) ?></p>
        <h2><?= e($material['nome']) ?></h2>
        <p class="kpi-value" style="margin:8px 0 12px"><?= e(money_br($material['custo_atual'])) ?></p>
        <span class="badge <?= $material['status'] === 'ativo' ? 'badge-ok' : 'badge-off' ?>">
            <?= $material['status'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
        </span>
        <div class="form-actions" style="margin-top:16px">
            <?php if (Auth::isAdmin()): ?>
                <a class="btn-primary" href="<?= e(url('/custos/' . $material['id'] . '/editar')) ?>">Editar</a>
            <?php endif; ?>
            <a class="btn-secondary" href="<?= e(url('/custos')) ?>">Voltar</a>
        </div>
    </div>
</section>

<div class="card">
    <h2>Informações</h2>
    <dl class="defs">
        <dt>Forma de cobrança</dt>
        <dd><?= e(forma_cobranca_label((string) ($material['forma_cobranca'] ?? 'unidade'))) ?></dd>
        <dt>Fornecedor</dt>
        <dd><?= e($material['fornecedor'] ?: '—') ?></dd>
        <dt>Observação</dt>
        <dd><?= e($material['observacao'] ?: '—') ?></dd>
        <dt>Criado em</dt>
        <dd><?= e($fmt($material['created_at'] ?? null)) ?></dd>
        <dt>Atualizado em</dt>
        <dd><?= e($fmt($material['updated_at'] ?? null)) ?></dd>
    </dl>
</div>

<div class="card" style="margin-top:16px">
    <h2>Histórico de custos</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th class="num">Custo anterior</th>
                    <th class="num">Novo custo</th>
                    <th class="num">Variação</th>
                    <th>Usuário</th>
                    <th>Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$historico): ?>
                    <tr>
                        <td colspan="6" class="empty">Nenhuma alteração de custo registrada.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historico as $row): ?>
                        <tr>
                            <td><?= e($fmt($row['created_at'] ?? null)) ?></td>
                            <td class="num"><?= e(money_br($row['custo_anterior'])) ?></td>
                            <td class="num"><?= e(money_br($row['custo_novo'])) ?></td>
                            <td class="num"><?= e(percent_br($row['percentual'])) ?></td>
                            <td><?= e($row['usuario_nome'] ?: '—') ?></td>
                            <td><?= e($row['observacao'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card muted-block" style="margin-top:16px">
    <h2>Produtos que utilizam este material</h2>
    <p class="hint">Área reservada para a próxima etapa (composição).</p>
</div>

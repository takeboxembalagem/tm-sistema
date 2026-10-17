<?php
$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime($hoje . ' +1 day'));

$link = static function (array $extra = []) use ($filtros): string {
    $p = array_merge($filtros, $extra);
    $params = array_filter([
        'aba' => ($p['aba'] ?? '') === 'projetos' ? 'projetos' : '',
        'periodo' => $p['periodo'] !== 'todas' ? $p['periodo'] : '',
        'situacao' => $p['situacao'] !== 'pendentes' ? $p['situacao'] : '',
        'nova' => $p['nova'] ?? '',
        'editar' => $p['editar'] ?? '',
        'novo_plano' => $p['novo_plano'] ?? '',
        'editar_plano' => $p['editar_plano'] ?? '',
        'plano' => $p['plano'] ?? '',
        'nova_etapa' => $p['nova_etapa'] ?? '',
    ], static fn ($v) => $v !== '' && $v !== null);
    return url('/agenda' . ($params ? '?' . http_build_query($params) : ''));
};
$filtrosOcultos = static function () use ($filtros): string {
    return '<input type="hidden" name="periodo" value="' . e($filtros['periodo']) . '">'
        . '<input type="hidden" name="situacao" value="' . e($filtros['situacao']) . '">';
};
$linkProjetos = static fn (array $extra = []): string => $link(['aba' => 'projetos'] + $extra);
$ocultosProjetos = static fn (): string => $filtrosOcultos() . '<input type="hidden" name="aba" value="projetos">';
$naAbaProjetos = $aba === 'projetos';
$val = static function (string $key, ?array $tarefa, string $padrao = '') use ($old): string {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    return $tarefa ? (string) ($tarefa[$key] ?? '') : $padrao;
};
$badgePrioridade = static fn (string $p): string => match ($p) {
    'alta' => 'badge-saida',
    'media' => 'badge-ajuste',
    default => 'badge-ok',
};
$dia = static function (string $data) use ($hoje, $amanha): string {
    $fmt = date('d/m/Y', (int) strtotime($data));
    if ($data === $hoje) {
        return '<strong>Hoje</strong><div class="hint">' . e($fmt) . '</div>';
    }
    if ($data === $amanha) {
        return '<strong>Amanhã</strong><div class="hint">' . e($fmt) . '</div>';
    }
    return e($fmt);
};
/** Ativos + o responsável atual (mesmo inativo, marcado "(inativo)"); outros inativos ficam de fora. */
$selectResponsavel = static function (string $selecionado, int $atual) use ($usuarios): void {
    ?>
    <label>
        Responsável
        <select name="responsavel_id">
            <option value="">Sem responsável</option>
            <?php foreach ($usuarios as $u): ?>
                <?php
                $uid = (int) $u['id'];
                $inativo = $u['status'] !== 'ativo';
                if ($inativo && $uid !== $atual) {
                    continue;
                }
                ?>
                <option value="<?= $uid ?>" <?= $selecionado === (string) $uid ? 'selected' : '' ?>><?= e($u['nome'] . ($inativo ? ' (inativo)' : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php
};
$camposTarefa = static function (?array $tarefa) use ($val, $selectResponsavel): void {
    ?>
    <label style="flex:1; min-width:220px">
        Tarefa *
        <input type="text" name="titulo" maxlength="255" required autofocus
               value="<?= e($val('titulo', $tarefa)) ?>" placeholder="Ex.: Ligar para cliente João">
    </label>
    <label>
        Data *
        <input type="date" name="data_tarefa" required value="<?= e($val('data_tarefa', $tarefa, date('Y-m-d'))) ?>">
    </label>
    <label>
        Prioridade *
        <select name="prioridade" required>
            <?php foreach (AgendaTarefa::PRIORIDADES as $chave => $rotulo): ?>
                <option value="<?= e($chave) ?>" <?= $val('prioridade', $tarefa, 'media') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php $selectResponsavel($val('responsavel_id', $tarefa), (int) ($tarefa['responsavel_id'] ?? 0)); ?>
    <label style="flex:1 1 100%">
        Observação
        <textarea name="observacao" rows="2" maxlength="2000" placeholder="Opcional"><?= e($val('observacao', $tarefa)) ?></textarea>
    </label>
    <?php
};
$valPlano = static function (string $key, ?array $plano, string $padrao = '') use ($planoOld): string {
    if (array_key_exists($key, $planoOld)) {
        return (string) $planoOld[$key];
    }
    return $plano ? (string) ($plano[$key] ?? '') : $padrao;
};
$camposPlano = static function (?array $plano) use ($valPlano, $selectResponsavel): void {
    ?>
    <label class="agenda-campo-largo">
        Projeto *
        <input type="text" name="titulo" maxlength="255" required autofocus
               value="<?= e($valPlano('titulo', $plano)) ?>" placeholder="Ex.: Lançamento coleção de Natal">
    </label>
    <label>
        Início *
        <input type="date" name="data_inicio" required value="<?= e($valPlano('data_inicio', $plano, date('Y-m-d'))) ?>">
    </label>
    <label>
        Fim *
        <input type="date" name="data_fim" required value="<?= e($valPlano('data_fim', $plano)) ?>">
    </label>
    <div class="agenda-campo-largo">
        <?php $selectResponsavel($valPlano('responsavel_id', $plano), (int) ($plano['responsavel_id'] ?? 0)); ?>
    </div>
    <label class="agenda-campo-largo">
        Observação
        <textarea name="observacao" rows="3" maxlength="2000" placeholder="Opcional"><?= e($valPlano('observacao', $plano)) ?></textarea>
    </label>
    <?php
};
$badgeStatusPlano = static fn (string $s): string => match ($s) {
    'em_andamento' => 'badge-ajuste',
    'concluido' => 'badge-ok',
    'cancelado' => 'badge-saida',
    default => 'badge-off',
};
$dataBr = static fn (string $data): string => date('d/m/Y', (int) strtotime($data));
$verConcluidas = $filtros['situacao'] === 'concluidas';
$usuariosPorId = array_column($usuarios, null, 'id');
$planoEmEdicao = null;
foreach ($planos as $pl) {
    if ((int) $pl['id'] === $editarPlanoId) {
        $planoEmEdicao = $pl;
    }
}
$podeExcluirPlano = Auth::isAdmin();
$dialogoPlano = $novoPlano || $planoEmEdicao !== null;
$planoDaEtapaEditada = null;
foreach ($etapasPorPlano as $pid => $lista) {
    if (in_array($editarId, array_map('intval', array_column($lista, 'id')), true)) {
        $planoDaEtapaEditada = (int) $pid;
    }
}
$situacoesEtapa = [
    'pendente' => ['Pendente', 'badge-off'],
    'em_andamento' => ['Em andamento', 'badge-ajuste'],
    'concluida' => ['Concluída', 'badge-ok'],
];

$camposEtapa = static function (?array $tarefa, array $plano) use ($val, $selectResponsavel): void {
    ?>
    <label style="flex:1; min-width:220px">
        Etapa *
        <input type="text" name="titulo" maxlength="255" required autofocus
               value="<?= e($val('titulo', $tarefa)) ?>" placeholder="Ex.: Fotografar produtos">
    </label>
    <label>
        Início
        <input type="date" name="data_inicio" value="<?= e($val('data_inicio', $tarefa)) ?>">
    </label>
    <label>
        Prazo *
        <input type="date" name="data_tarefa" required value="<?= e($val('data_tarefa', $tarefa, (string) $plano['data_fim'])) ?>">
    </label>
    <label>
        Prioridade *
        <select name="prioridade" required>
            <?php foreach (AgendaTarefa::PRIORIDADES as $chave => $rotulo): ?>
                <option value="<?= e($chave) ?>" <?= $val('prioridade', $tarefa, 'media') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php $selectResponsavel($val('responsavel_id', $tarefa), (int) ($tarefa['responsavel_id'] ?? 0)); ?>
    <label style="flex:1 1 100%">
        Observação
        <textarea name="observacao" rows="2" maxlength="2000" placeholder="Opcional"><?= e($val('observacao', $tarefa)) ?></textarea>
    </label>
    <?php
};

$linhaEtapa = static function (array $t, array $plano) use ($linkProjetos, $ocultosProjetos, $camposEtapa, $dataBr, $usuariosPorId, $situacoesEtapa, $editarId): void {
    $tid = (int) $t['id'];
    $pid = (int) $plano['id'];
    $situacao = AgendaTarefa::situacao($t);
    $feita = $situacao === 'concluida';
    $base = '/agenda/' . $tid;
    $ocultos = $ocultosProjetos() . '<input type="hidden" name="plano" value="' . $pid . '">';
    $resp = $usuariosPorId[(int) ($t['responsavel_id'] ?? 0)] ?? null;
    [$rotulo, $badge] = $situacoesEtapa[$situacao];
    ?>
    <?php if ($editarId === $tid): ?>
        <tr>
            <td colspan="6">
                <form method="post" action="<?= e(url($base . '/editar')) ?>" class="filters" style="align-items:flex-end">
                    <?= Csrf::field() ?>
                    <?= $ocultos ?>
                    <?php $camposEtapa($t, $plano); ?>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Salvar</button>
                        <a class="btn-secondary" href="<?= e($linkProjetos(['plano' => (string) $pid]) . '#plano-' . $pid) ?>">Cancelar</a>
                    </div>
                </form>
            </td>
        </tr>
    <?php else: ?>
        <tr>
            <td data-rotulo="Situação"><span class="badge <?= $badge ?>"><?= e($rotulo) ?></span></td>
            <td data-rotulo="Etapa" class="agenda-etapa-nome">
                <?php if ($feita): ?>
                    <span class="hint"><s><?= e($t['titulo']) ?></s></span>
                <?php else: ?>
                    <strong><?= e($t['titulo']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($t['observacao'])): ?>
                    <div class="hint"><?= e($t['observacao']) ?></div>
                <?php endif; ?>
            </td>
            <td data-rotulo="Responsável">
                <?php if ($resp): ?>
                    <?= e($resp['nome'] . ($resp['status'] !== 'ativo' ? ' (inativo)' : '')) ?>
                <?php else: ?>
                    <span class="hint">Sem responsável</span>
                <?php endif; ?>
            </td>
            <td data-rotulo="Início"><?= $t['data_inicio'] !== null ? e($dataBr((string) $t['data_inicio'])) : '<span class="hint">—</span>' ?></td>
            <td data-rotulo="Prazo"><?= e($dataBr((string) $t['data_tarefa'])) ?></td>
            <td class="agenda-etapa-acoes">
                <div class="actions">
                    <form method="post" action="<?= e(url($base . ($feita ? '/reabrir' : '/concluir'))) ?>">
                        <?= Csrf::field() ?>
                        <?= $ocultos ?>
                        <button type="submit" class="link-btn"><?= $feita ? 'Reabrir' : 'Concluir' ?></button>
                    </form>
                    <?php if (!$feita): ?>
                        <form method="post" action="<?= e(url($base . '/andamento')) ?>">
                            <?= Csrf::field() ?>
                            <?= $ocultos ?>
                            <button type="submit" class="link-btn"><?= $situacao === 'em_andamento' ? 'Voltar a pendente' : 'Em andamento' ?></button>
                        </form>
                    <?php endif; ?>
                    <a href="<?= e($linkProjetos(['editar' => (string) $tid, 'plano' => (string) $pid]) . '#plano-' . $pid) ?>">Editar</a>
                    <form method="post" action="<?= e(url($base . '/excluir')) ?>"
                          onsubmit="return confirm('Excluir esta etapa?');">
                        <?= Csrf::field() ?>
                        <?= $ocultos ?>
                        <button type="submit" class="link-btn">Excluir</button>
                    </form>
                </div>
            </td>
        </tr>
    <?php endif; ?>
    <?php
};

$etapasDoPlano = static function (array $pl) use ($linkProjetos, $ocultosProjetos, $camposEtapa, $linhaEtapa, $etapasPorPlano, $novaEtapaPlanoId, $planoAberto, $planoDaEtapaEditada): void {
    $pid = (int) $pl['id'];
    $etapas = $etapasPorPlano[$pid] ?? [];
    $novaAqui = $novaEtapaPlanoId === $pid;
    $aberto = $novaAqui || $planoAberto === $pid || $planoDaEtapaEditada === $pid;
    ?>
    <details class="agenda-etapas" <?= $aberto ? 'open' : '' ?>>
        <summary>Ver etapas <span class="hint">(<?= (int) $pl['total_subtarefas'] ?>)</span></summary>
        <div class="agenda-etapas-corpo">
            <div class="agenda-etapas-cab">
                <h3>Etapas do projeto</h3>
                <?php if (!$novaAqui): ?>
                    <a class="btn-secondary agenda-btn-pequeno" href="<?= e($linkProjetos(['nova_etapa' => (string) $pid]) . '#plano-' . $pid) ?>">+ Nova etapa</a>
                <?php endif; ?>
            </div>
            <?php if ($novaAqui): ?>
                <div class="agenda-etapa-form">
                    <h4>Nova etapa em “<?= e($pl['titulo']) ?>”</h4>
                    <form method="post" action="<?= e(url('/agenda/criar')) ?>" class="filters" style="align-items:flex-end">
                        <?= Csrf::field() ?>
                        <?= $ocultosProjetos() ?>
                        <input type="hidden" name="plano" value="<?= $pid ?>">
                        <input type="hidden" name="plano_id" value="<?= $pid ?>">
                        <?php $camposEtapa(null, $pl); ?>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Salvar</button>
                            <a class="btn-secondary" href="<?= e($linkProjetos(['plano' => (string) $pid]) . '#plano-' . $pid) ?>">Cancelar</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            <?php if (!$etapas): ?>
                <?php if (!$novaAqui): ?>
                    <p class="hint agenda-etapas-vazio">Este projeto ainda não possui etapas.</p>
                <?php endif; ?>
            <?php else: ?>
                <table class="table agenda-etapas-tabela">
                    <thead>
                        <tr>
                            <th class="col-curta">Situação</th>
                            <th>Etapa</th>
                            <th>Responsável</th>
                            <th class="col-curta">Início</th>
                            <th class="col-curta">Prazo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($etapas as $t): ?>
                            <?php $linhaEtapa($t, $pl); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>
    <?php
};

$linhaPlano = static function (array $pl) use ($linkProjetos, $ocultosProjetos, $badgeStatusPlano, $dataBr, $podeExcluirPlano, $etapasDoPlano): void {
    $pid = (int) $pl['id'];
    $resp = $pl['responsavel_id'] !== null
        ? 'Responsável: ' . $pl['responsavel_nome'] . ($pl['responsavel_status'] !== 'ativo' ? ' (inativo)' : '')
        : 'Sem responsável';
    ?>
    <div class="agenda-plano-bloco<?= $pl['status'] === 'em_andamento' ? ' agenda-plano-ativo' : '' ?>" id="plano-<?= $pid ?>">
    <div class="agenda-plano-linha" role="row">
        <div class="agenda-plano-status" role="cell">
            <span class="badge <?= $badgeStatusPlano((string) $pl['status']) ?>"><?= e(AgendaPlano::STATUS[$pl['status']] ?? (string) $pl['status']) ?></span>
        </div>
        <div class="agenda-plano-periodo" role="cell"><?= e($dataBr((string) $pl['data_inicio'])) ?> → <?= e($dataBr((string) $pl['data_fim'])) ?></div>
        <div class="agenda-plano-titulo" role="cell"><?= e($pl['titulo']) ?></div>
        <div class="agenda-plano-resp hint" role="cell"><?= e($resp) ?></div>
        <div class="agenda-plano-progresso" role="cell">
            <?php if ($pl['progresso'] === null): ?>
                <span class="hint"><?= e($pl['rotulo_progresso']) ?></span>
            <?php else: ?>
                <span><strong><?= e($pl['rotulo_progresso']) ?></strong> · <?= (int) $pl['subtarefas_concluidas'] ?> de <?= (int) $pl['total_subtarefas'] ?></span>
                <div class="agenda-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $pl['progresso'] ?>">
                    <span style="width:<?= (int) $pl['progresso'] ?>%"></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="agenda-plano-acoes" role="cell">
            <form method="post" action="<?= e(url('/agenda/planos/' . $pid . '/status')) ?>" class="agenda-status">
                <?= Csrf::field() ?>
                <?= $ocultosProjetos() ?>
                <select name="status" aria-label="Status do projeto">
                    <?php foreach (AgendaPlano::STATUS as $chave => $rotulo): ?>
                        <option value="<?= e($chave) ?>" <?= $pl['status'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="link-btn">Alterar status</button>
            </form>
            <div class="actions">
                <a href="<?= e($linkProjetos(['editar_plano' => (string) $pid])) ?>">Editar projeto</a>
                <?php if ($podeExcluirPlano): ?>
                    <form method="post" action="<?= e(url('/agenda/planos/' . $pid . '/excluir')) ?>"
                          onsubmit="return confirm('Excluir este projeto?');">
                        <?= Csrf::field() ?>
                        <?= $ocultosProjetos() ?>
                        <button type="submit" class="link-btn">Excluir</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php $etapasDoPlano($pl); ?>
    </div>
    <?php
};
?>
<style>
.agenda-topo { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.agenda-topo .hint { margin: 0; }
.agenda-botoes { display: flex; flex-wrap: wrap; gap: 10px; }
.agenda-botoes a { display: inline-flex; align-items: center; padding: 10px 16px; border-radius: 10px; font-weight: 600; text-decoration: none; }
.agenda-secao { margin-bottom: 28px; }
.agenda-secao-cab { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; margin: 0 0 12px; }
.agenda-secao-cab h2 { margin: 0; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
.agenda-secao-cab .hint { margin: 0; font-size: 0.85rem; }
.agenda-vazio { margin: 0; font-size: 0.9rem; }
.agenda-planos-cab, .agenda-plano-linha { display: grid; grid-template-columns: 110px 170px minmax(120px, 1.4fr) minmax(120px, 1fr) 130px 230px; gap: 12px; align-items: center; padding: 10px 12px; }
.agenda-planos-cab { padding-top: 10px; border-bottom: 1px solid var(--line); color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
.agenda-plano-bloco { background: #eaf2f8; box-shadow: inset 3px 0 0 #c3d8ea; border-bottom: 1px solid #fff; }
.agenda-plano-bloco.agenda-plano-ativo { box-shadow: inset 4px 0 0 #3b78ae; }
.agenda-plano-linha { font-size: 0.9rem; }
.agenda-etapas > summary { cursor: pointer; padding: 0 12px 10px 16px; font-size: 0.85rem; font-weight: 600; color: var(--accent); width: max-content; }
.agenda-etapas > summary .hint { font-weight: 400; }
.agenda-etapas-corpo { margin: 0 12px 12px 16px; padding: 12px 14px; background: #fff; border-radius: 8px; }
.agenda-etapas-cab { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
.agenda-etapas-cab h3 { margin: 0; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
.agenda-etapa-form { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
.agenda-etapa-form h4 { margin: 0 0 8px; font-size: 0.9rem; }
.agenda-etapas-vazio { margin: 0; font-size: 0.9rem; }
.agenda-btn-pequeno { padding: 6px 12px; font-size: 0.85rem; }
.agenda-etapas-tabela { width: 100%; font-size: 0.9rem; }
.agenda-etapas-tabela th, .agenda-etapas-tabela td { padding: 8px; vertical-align: top; }
.agenda-etapas-tabela .col-curta, .agenda-etapas-tabela td[data-rotulo="Situação"], .agenda-etapas-tabela td[data-rotulo="Início"], .agenda-etapas-tabela td[data-rotulo="Prazo"] { width: 1%; white-space: nowrap; }
.agenda-etapas-tabela .agenda-etapa-nome { overflow-wrap: anywhere; }
.agenda-etapa-acoes { width: 190px; }
.agenda-etapa-acoes .actions { flex-wrap: wrap; gap: 4px 12px; }
@media (max-width: 1100px) {
    .agenda-etapas-tabela thead { display: none; }
    .agenda-etapas-tabela, .agenda-etapas-tabela tbody { display: block; }
    .agenda-etapas-tabela tr { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr); gap: 6px 12px; padding: 10px 0; border-bottom: 1px solid var(--line); }
    .agenda-etapas-tabela td, .agenda-etapas-tabela td[data-rotulo] { display: block; width: auto; padding: 0; border: 0; white-space: normal; }
    .agenda-etapas-tabela td[data-rotulo]::before { content: attr(data-rotulo); display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .agenda-etapas-tabela td[data-rotulo="Situação"]::before, .agenda-etapas-tabela td.agenda-etapa-nome::before { display: none; }
    .agenda-etapas-tabela td.agenda-etapa-nome, .agenda-etapas-tabela td.agenda-etapa-acoes, .agenda-etapas-tabela td[colspan] { grid-column: 1 / -1; }
    .agenda-etapas-tabela td[data-rotulo="Situação"] { grid-column: 1 / -1; order: -1; }
}
.agenda-plano-linha > div { min-width: 0; }
.agenda-plano-linha .badge { white-space: nowrap; }
.agenda-plano-periodo { white-space: nowrap; }
.agenda-plano-titulo { font-weight: 600; overflow-wrap: anywhere; }
.agenda-plano-resp { margin: 0; overflow-wrap: anywhere; }
.agenda-plano-progresso { display: flex; flex-direction: column; gap: 4px; white-space: nowrap; }
.agenda-barra { height: 6px; max-width: 120px; background: #d3e2ef; border-radius: 999px; overflow: hidden; }
.agenda-barra > span { display: block; height: 100%; background: var(--accent); }
.agenda-plano-acoes { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.agenda-plano-acoes .actions { flex-wrap: wrap; }
.agenda-plano-vazio { padding: 14px 12px; margin: 0; }
.agenda-abas { display: flex; gap: 4px; margin: 0 0 20px; border-bottom: 1px solid var(--line); }
.agenda-abas a { padding: 10px 18px; margin-bottom: -1px; border-bottom: 3px solid transparent; font-weight: 600; color: var(--muted); text-decoration: none; }
.agenda-abas a:hover { color: inherit; }
.agenda-abas a.agenda-aba-ativa { color: var(--accent); border-bottom-color: var(--accent); }
.agenda-status { display: flex; align-items: center; gap: 8px; margin: 0; min-width: 0; }
.agenda-status select { min-width: 0; max-width: 125px; padding: 4px 6px; border: 1px solid #c3d8ea; border-radius: 8px; background: #fff; font: inherit; font-size: 0.85rem; }
@media (max-width: 1320px) {
    .agenda-planos-cab { display: none; }
    .agenda-plano-linha {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 230px;
        grid-template-areas: "titulo titulo acoes" "status periodo acoes" "resp progresso acoes";
        row-gap: 6px;
    }
    .agenda-plano-titulo { grid-area: titulo; }
    .agenda-plano-status { grid-area: status; }
    .agenda-plano-periodo { grid-area: periodo; }
    .agenda-plano-resp { grid-area: resp; }
    .agenda-plano-progresso { grid-area: progresso; }
    .agenda-plano-acoes { grid-area: acoes; }
}
@media (max-width: 900px) {
    .agenda-plano-linha {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        grid-template-areas: "titulo titulo" "status periodo" "resp progresso" "acoes acoes";
    }
    .agenda-plano-acoes { flex-direction: row; flex-wrap: wrap; align-items: center; gap: 8px 16px; }
}
.agenda-filtros { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 12px; }
.agenda-filtros a { display: inline-flex; padding: 6px 12px; border-radius: 999px; font-size: 0.85rem; text-decoration: none; }
.agenda-filtros .agenda-separador { width: 1px; height: 22px; margin: 0 6px; background: var(--line); }
.agenda-tabela th, .agenda-tabela td { padding: 8px 10px; vertical-align: top; }
.agenda-tabela .col-curta { width: 1%; white-space: nowrap; }
.agenda-dialog { border: 0; border-radius: var(--radius); padding: 22px; width: min(560px, calc(100vw - 32px)); box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18); color: inherit; }
.agenda-dialog::backdrop { background: rgba(30, 20, 25, 0.35); }
.agenda-dialog h2 { margin: 0 0 14px; font-size: 1rem; }
.agenda-dialog .alert { margin-bottom: 14px; }
.agenda-dialog-form { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.agenda-dialog-form label { display: flex; flex-direction: column; gap: 6px; font-size: 0.9rem; }
.agenda-dialog-form input, .agenda-dialog-form select, .agenda-dialog-form textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; font: inherit; color: inherit; }
.agenda-dialog-form .agenda-campo-largo, .agenda-dialog-form .form-actions { grid-column: 1 / -1; }
@media (max-width: 900px) {
    .agenda-tabela td.actions { flex-direction: column; align-items: flex-start; gap: 6px; }
}
@media (max-width: 520px) {
    .agenda-dialog-form { grid-template-columns: 1fr; }
}
</style>

<?php if (!empty($errors) && !$dialogoPlano): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<nav class="agenda-abas" aria-label="Áreas da Agenda">
    <a href="<?= e($link()) ?>" class="<?= $naAbaProjetos ? '' : 'agenda-aba-ativa' ?>" <?= $naAbaProjetos ? '' : 'aria-current="page"' ?>>Tarefas</a>
    <a href="<?= e($linkProjetos()) ?>" class="<?= $naAbaProjetos ? 'agenda-aba-ativa' : '' ?>" <?= $naAbaProjetos ? 'aria-current="page"' : '' ?>>Projetos</a>
</nav>

<?php if ($naAbaProjetos): ?>
<section class="agenda-topo">
    <p class="hint">Projetos são objetivos maiores, divididos em etapas.</p>
    <div class="agenda-botoes">
        <a class="btn-primary" href="<?= e($linkProjetos(['novo_plano' => '1'])) ?>">+ Novo projeto</a>
    </div>
</section>

<?php if ($dialogoPlano): ?>
    <dialog id="agenda-plano-dialogo" class="agenda-dialog" aria-labelledby="agenda-plano-dialogo-titulo" data-voltar="<?= e($linkProjetos()) ?>">
        <h2 id="agenda-plano-dialogo-titulo"><?= $planoEmEdicao ? 'Editar projeto' : 'Novo projeto' ?></h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(url($planoEmEdicao ? '/agenda/planos/' . (int) $planoEmEdicao['id'] . '/editar' : '/agenda/planos/criar')) ?>" class="agenda-dialog-form">
            <?= Csrf::field() ?>
            <?= $ocultosProjetos() ?>
            <?php $camposPlano($planoEmEdicao); ?>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Salvar</button>
                <a class="btn-secondary" href="<?= e($linkProjetos()) ?>">Cancelar</a>
            </div>
        </form>
    </dialog>
    <script>
    (function () {
        var dialogo = document.getElementById('agenda-plano-dialogo');
        if (typeof dialogo.showModal === 'function') {
            dialogo.showModal();
        } else {
            dialogo.setAttribute('open', '');
        }
        dialogo.addEventListener('cancel', function (evento) {
            evento.preventDefault();
            window.location.href = dialogo.dataset.voltar;
        });
    })();
    </script>
<?php endif; ?>

<section class="agenda-secao" id="projetos">
    <div class="agenda-secao-cab">
        <h2>Projetos</h2>
    </div>
    <div class="card table-card">
        <div class="agenda-planos" role="table" aria-label="Projetos">
            <div class="agenda-planos-cab" role="row">
                <span role="columnheader">Status</span>
                <span role="columnheader">Período</span>
                <span role="columnheader">Projeto</span>
                <span role="columnheader">Responsável</span>
                <span role="columnheader">Progresso</span>
                <span role="columnheader">Ações</span>
            </div>
            <?php if (!$planos): ?>
                <p class="hint agenda-plano-vazio">Nenhum projeto cadastrado.</p>
            <?php else: ?>
                <?php foreach ($planos as $pl): ?>
                    <?php $linhaPlano($pl); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="agenda-topo">
    <p class="hint">Tarefas avulsas são atividades do dia a dia. Etapas de projetos ficam na aba Projetos.</p>
    <div class="agenda-botoes">
        <a class="btn-primary" href="<?= e($link(['nova' => '1'])) ?>#tarefas-avulsas">+ Nova tarefa</a>
    </div>
</section>

<section class="agenda-secao" id="tarefas-avulsas">
<div class="agenda-secao-cab">
    <h2>Tarefas avulsas</h2>
    <p class="hint">
        Hoje: <strong><?= (int) $resumo['hoje'] ?></strong> <?= $resumo['hoje'] === 1 ? 'tarefa' : 'tarefas' ?>
        · Atrasadas: <strong><?= (int) $resumo['atrasadas'] ?></strong>
        · Próximos 7 dias: <strong><?= (int) $resumo['proximos'] ?></strong>
    </p>
</div>

<nav class="agenda-filtros" aria-label="Filtros de tarefas">
    <?php foreach (AgendaTarefa::PERIODOS as $chave => $rotulo): ?>
        <a class="btn-<?= $filtros['periodo'] === $chave ? 'primary' : 'secondary' ?>" href="<?= e($link(['periodo' => $chave])) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
    <span class="agenda-separador" aria-hidden="true"></span>
    <?php foreach (AgendaTarefa::SITUACOES as $chave => $rotulo): ?>
        <a class="btn-<?= $filtros['situacao'] === $chave ? 'primary' : 'secondary' ?>" href="<?= e($link(['situacao' => $chave])) ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($novaTarefa): ?>
    <section class="card" style="margin-bottom:16px">
        <h2>Nova tarefa</h2>
        <form method="post" action="<?= e(url('/agenda/criar')) ?>" class="filters" style="align-items:flex-end">
            <?= Csrf::field() ?>
            <?= $filtrosOcultos() ?>
            <?php $camposTarefa(null); ?>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Salvar</button>
                <a class="btn-secondary" href="<?= e($link()) ?>">Cancelar</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<div class="card table-card">
    <div class="table-wrap">
        <table class="table agenda-tabela">
            <thead>
                <tr>
                    <th class="col-curta">Prioridade</th>
                    <th class="col-curta">Dia</th>
                    <th>Tarefa</th>
                    <th class="col-curta">Status</th>
                    <th class="col-curta">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tarefas): ?>
                    <tr>
                        <td colspan="5" class="empty">
                            <?= $verConcluidas ? 'Nenhuma tarefa concluída neste período.' : 'Nenhuma tarefa pendente neste período.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tarefas as $t): ?>
                        <?php
                        $tid = (int) $t['id'];
                        $feita = (int) $t['concluida'] === 1;
                        $atrasada = !$feita && $t['data_tarefa'] < $hoje;
                        $base = '/agenda/' . $tid;
                        ?>
                        <?php if ($editarId === $tid): ?>
                            <tr>
                                <td colspan="5">
                                    <form method="post" action="<?= e(url($base . '/editar')) ?>" class="filters" style="align-items:flex-end">
                                        <?= Csrf::field() ?>
                                        <?= $filtrosOcultos() ?>
                                        <?php $camposTarefa($t); ?>
                                        <div class="form-actions">
                                            <button type="submit" class="btn-primary">Salvar</button>
                                            <a class="btn-secondary" href="<?= e($link()) ?>">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $feita ? 'badge-off' : $badgePrioridade((string) $t['prioridade']) ?>">
                                        <?= e(AgendaTarefa::PRIORIDADES[$t['prioridade']] ?? (string) $t['prioridade']) ?>
                                    </span>
                                </td>
                                <td><?= $dia((string) $t['data_tarefa']) ?></td>
                                <td>
                                    <?php if ($feita): ?>
                                        <span class="hint"><s><?= e($t['titulo']) ?></s></span>
                                    <?php else: ?>
                                        <strong><?= e($t['titulo']) ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($t['observacao'])): ?>
                                        <div class="hint"><?= e($t['observacao']) ?></div>
                                    <?php endif; ?>
                                    <?php $resp = $usuariosPorId[(int) ($t['responsavel_id'] ?? 0)] ?? null; ?>
                                    <?php if ($resp): ?>
                                        <div class="hint">Responsável: <?= e($resp['nome'] . ($resp['status'] !== 'ativo' ? ' (inativo)' : '')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($feita): ?>
                                        <span class="badge badge-ok">Concluída</span>
                                        <div class="hint"><?= e(date('d/m/Y H:i', (int) strtotime((string) $t['concluida_em']))) ?></div>
                                    <?php elseif ($atrasada): ?>
                                        <span class="badge badge-saida">Atrasada</span>
                                    <?php else: ?>
                                        <span class="badge badge-off">Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <form method="post" action="<?= e(url($base . ($feita ? '/reabrir' : '/concluir'))) ?>">
                                        <?= Csrf::field() ?>
                                        <?= $filtrosOcultos() ?>
                                        <button type="submit" class="link-btn"><?= $feita ? 'Reabrir' : 'Concluir' ?></button>
                                    </form>
                                    <a href="<?= e($link(['editar' => (string) $tid])) ?>">Editar</a>
                                    <form method="post" action="<?= e(url($base . '/excluir')) ?>"
                                          onsubmit="return confirm('Excluir esta tarefa?');">
                                        <?= Csrf::field() ?>
                                        <?= $filtrosOcultos() ?>
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
</div>
</section>
<?php endif; ?>

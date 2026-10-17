<?php
$old = $old ?? [];
$oldForm = (string) ($old['form'] ?? '');
/** Valor do formulário: dados devolvidos após erro (mesmo formulário) ou registro em edição. */
$val = static function (string $form, string $campo, ?array $registro = null, string $padrao = '') use ($old, $oldForm): string {
    if ($oldForm === $form && array_key_exists($campo, $old)) {
        return (string) $old[$campo];
    }
    if ($registro !== null && isset($registro[$campo])) {
        return (string) $registro[$campo];
    }
    return $padrao;
};
$v = static fn (string $campo): string => trim((string) ($empresa[$campo] ?? ''));
$def = static function (string $rotulo, string $valor): void {
    if ($valor === '') {
        return;
    }
    echo '<dt>' . e($rotulo) . '</dt><dd>' . e($valor) . '</dd>';
};
$ativoBadge = static fn ($ativo, string $sim, string $nao): string => (int) $ativo === 1
    ? '<span class="badge badge-ok">' . e($sim) . '</span>'
    : '<span class="badge badge-off">' . e($nao) . '</span>';
$pixAtivos = array_values(array_filter($pix, static fn (array $p): bool => (int) $p['ativo'] === 1));
$isAdmin = Auth::isAdmin();
$abrirFormConta = $isAdmin && ($novaConta || ($oldForm === 'conta' && $editarContaId === null));
$abrirFormPix = $isAdmin && ($novoPix || ($oldForm === 'pix' && $editarPixId === null));
$abrirFormDoc = $isAdmin && ($novoDocumento || $oldForm === 'documento');
$cardGrid = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:16px';
?>
<p class="hint" style="margin-top:0">Cadastro central da empresa, dados bancários, PIX e documentos.</p>

<section class="toolbar">
    <div class="filters" style="gap:8px">
        <?php if ($isAdmin): ?>
            <a class="btn-secondary" href="<?= e(url('/empresa/editar')) ?>">Editar dados</a>
        <?php endif; ?>
        <?php if ($empresa): ?>
            <button type="button" class="btn-primary" data-copy-target="#texto-envio" data-copy-msg="Cadastro copiado." data-copy-keep-label>Copiar cadastro para envio</button>
            <a class="btn-secondary" href="<?= e(url('/empresa/envio')) ?>" target="_blank" rel="noopener">Ver cadastro para envio</a>
        <?php endif; ?>
    </div>
</section>

<div id="copy-status" class="alert alert-success" role="status" hidden></div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$empresa): ?>
    <div class="card">
        <h2>Nenhum dado cadastrado</h2>
        <?php if ($isAdmin): ?>
            <p class="hint">Comece preenchendo os dados cadastrais. Depois você poderá adicionar contas bancárias, chaves PIX e documentos.</p>
            <a class="btn-primary" href="<?= e(url('/empresa/editar')) ?>">Preencher dados da empresa</a>
        <?php else: ?>
            <p class="hint">Os dados da empresa são cadastrados por um administrador.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <textarea id="texto-envio" hidden readonly><?= e($textoEnvio) ?></textarea>

    <?php if ($v('cnpj') !== '' || $v('email') !== '' || $v('telefone') !== '' || $pixAtivos): ?>
        <section class="filters" style="gap:8px;margin-bottom:16px" aria-label="Cópia rápida">
            <?php if ($v('cnpj') !== ''): ?>
                <button type="button" class="btn-secondary" data-copy="<?= e($v('cnpj')) ?>" data-copy-msg="CNPJ copiado.">Copiar CNPJ</button>
            <?php endif; ?>
            <?php if ($v('email') !== ''): ?>
                <button type="button" class="btn-secondary" data-copy="<?= e($v('email')) ?>" data-copy-msg="E-mail copiado.">Copiar e-mail</button>
            <?php endif; ?>
            <?php if ($v('telefone') !== ''): ?>
                <button type="button" class="btn-secondary" data-copy="<?= e($v('telefone')) ?>" data-copy-msg="Telefone copiado.">Copiar telefone</button>
            <?php endif; ?>
            <?php foreach ($pixAtivos as $p): ?>
                <button type="button" class="btn-secondary" data-copy="<?= e($p['chave']) ?>" data-copy-msg="Chave PIX copiada."
                        title="<?= e($p['chave']) ?>">Copiar PIX<?= count($pixAtivos) > 1 ? ' (' . e(EmpresaPix::TIPOS[$p['tipo_chave']] ?? '') . ')' : '' ?></button>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div style="<?= $cardGrid ?>">
        <section class="card">
            <h2>Dados cadastrais</h2>
            <dl class="defs">
                <?php
                $def('Razão Social', $v('razao_social'));
                $def('Nome Fantasia', $v('nome_fantasia'));
                $def('CNPJ', $v('cnpj'));
                $def('Inscrição Estadual', $v('inscricao_estadual'));
                $def('Inscrição Municipal', $v('inscricao_municipal'));
                $def('CNAE principal', $v('cnae_principal'));
                $def('Regime tributário', Empresa::regimeLabel($v('regime_tributario')));
                $def('Data de abertura', $v('data_abertura') !== '' ? date('d/m/Y', strtotime($v('data_abertura'))) : '');
                ?>
            </dl>
        </section>

        <section class="card">
            <h2>Endereço</h2>
            <?php if ($v('endereco') === '' && $v('cep') === '' && $v('cidade') === ''): ?>
                <p class="hint">Endereço não informado.</p>
            <?php else: ?>
                <dl class="defs">
                    <?php
                    $def('CEP', $v('cep'));
                    $def('Endereço', $v('endereco'));
                    $def('Número', $v('numero'));
                    $def('Complemento', $v('complemento'));
                    $def('Bairro', $v('bairro'));
                    $def('Cidade', $v('cidade'));
                    $def('Estado', $v('estado'));
                    ?>
                </dl>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Contato</h2>
            <?php if ($v('telefone') === '' && $v('whatsapp') === '' && $v('email') === '' && $v('site') === '' && $v('pessoa_contato') === ''): ?>
                <p class="hint">Contato não informado.</p>
            <?php else: ?>
                <dl class="defs">
                    <?php
                    $def('Telefone', $v('telefone'));
                    $def('WhatsApp', $v('whatsapp'));
                    $def('E-mail', $v('email'));
                    ?>
                    <?php if ($v('site') !== ''): ?>
                        <dt>Site</dt>
                        <dd><a href="<?= e(Empresa::urlSite($v('site'))) ?>" target="_blank" rel="noopener noreferrer"><?= e($v('site')) ?></a></dd>
                    <?php endif; ?>
                    <?php $def('Pessoa de contato', $v('pessoa_contato')); ?>
                </dl>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($v('observacoes') !== ''): ?>
        <section class="card" style="margin-bottom:16px">
            <h2>Observações</h2>
            <p style="white-space:pre-line;margin:0"><?= e($v('observacoes')) ?></p>
        </section>
    <?php endif; ?>

    <section class="card" id="contas" style="margin-bottom:16px">
        <div class="toolbar" style="margin-bottom:8px">
            <h2 style="margin:0">Dados bancários</h2>
            <?php if ($isAdmin && !$abrirFormConta): ?>
                <a class="btn-secondary" href="<?= e(url('/empresa?nova_conta=1#contas')) ?>">+ Adicionar conta</a>
            <?php endif; ?>
        </div>
        <p class="hint">Não registre senhas, tokens, códigos de segurança ou qualquer credencial de acesso bancário.</p>

        <?php
        $formConta = static function (?array $conta) use ($val): void {
            $acao = $conta ? url('/empresa/contas/' . (int) $conta['id'] . '/editar') : url('/empresa/contas');
            ?>
            <form method="post" action="<?= e($acao) ?>" class="form" style="margin:12px 0">
                <?= Csrf::field() ?>
                <div class="form-grid">
                    <label>Banco *<input type="text" name="banco" required maxlength="100" value="<?= e($val('conta', 'banco', $conta)) ?>" placeholder="Ex.: Banco do Brasil"></label>
                    <label>Tipo de conta *
                        <select name="tipo_conta" required>
                            <?php foreach (EmpresaConta::TIPOS as $tv => $tr): ?>
                                <option value="<?= e($tv) ?>" <?= $val('conta', 'tipo_conta', $conta, 'corrente') === $tv ? 'selected' : '' ?>><?= e($tr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Agência *<input type="text" name="agencia" required maxlength="20" value="<?= e($val('conta', 'agencia', $conta)) ?>"></label>
                    <label>Conta *<input type="text" name="conta" required maxlength="30" value="<?= e($val('conta', 'conta', $conta)) ?>" placeholder="Ex.: 12345-6"></label>
                    <label>Titular<input type="text" name="titular" maxlength="150" value="<?= e($val('conta', 'titular', $conta)) ?>"></label>
                    <label>CPF/CNPJ do titular<input type="text" name="documento_titular" maxlength="20" value="<?= e($val('conta', 'documento_titular', $conta)) ?>"></label>
                    <label class="span-2">Observação<input type="text" name="observacao" maxlength="255" value="<?= e($val('conta', 'observacao', $conta)) ?>"></label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary"><?= $conta ? 'Salvar conta' : 'Adicionar conta' ?></button>
                    <a class="btn-secondary" href="<?= e(url('/empresa#contas')) ?>">Cancelar</a>
                </div>
            </form>
            <?php
        };
        ?>

        <?php if ($abrirFormConta) { $formConta(null); } ?>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Banco</th><th>Agência</th><th>Conta</th><th>Tipo</th><th>Titular</th><th>Situação</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (!$contas): ?>
                        <tr><td colspan="7" class="empty">Nenhuma conta bancária cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contas as $c): ?>
                        <?php $base = '/empresa/contas/' . (int) $c['id']; ?>
                        <tr>
                            <td>
                                <?= e($c['banco']) ?>
                                <?php if ($c['observacao']): ?><div class="hint"><?= e($c['observacao']) ?></div><?php endif; ?>
                            </td>
                            <td><?= e($c['agencia']) ?></td>
                            <td><?= e($c['conta']) ?></td>
                            <td><?= e(EmpresaConta::TIPOS[$c['tipo_conta']] ?? '') ?></td>
                            <td>
                                <?= e($c['titular'] ?: '-') ?>
                                <?php if ($c['documento_titular']): ?><div class="hint"><?= e($c['documento_titular']) ?></div><?php endif; ?>
                            </td>
                            <td><?= $ativoBadge($c['ativo'], 'Ativa', 'Inativa') ?></td>
                            <td class="actions">
                                <?php if ($isAdmin): ?>
                                    <a href="<?= e(url('/empresa?editar_conta=' . (int) $c['id'] . '#contas')) ?>">Editar</a>
                                    <form method="post" action="<?= e(url($base . '/status')) ?>">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn"><?= (int) $c['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                                    </form>
                                    <form method="post" action="<?= e(url($base . '/excluir')) ?>" onsubmit="return confirm('Excluir esta conta bancária?')">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($isAdmin && $editarContaId === (int) $c['id']): ?>
                            <tr><td colspan="7"><?php $formConta($c); ?></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" id="pix" style="margin-bottom:16px">
        <div class="toolbar" style="margin-bottom:8px">
            <h2 style="margin:0">PIX</h2>
            <?php if ($isAdmin && !$abrirFormPix): ?>
                <a class="btn-secondary" href="<?= e(url('/empresa?novo_pix=1#pix')) ?>">+ Adicionar chave</a>
            <?php endif; ?>
        </div>

        <?php
        $formPix = static function (?array $chave) use ($val): void {
            $acao = $chave ? url('/empresa/pix/' . (int) $chave['id'] . '/editar') : url('/empresa/pix');
            ?>
            <form method="post" action="<?= e($acao) ?>" class="form" style="margin:12px 0">
                <?= Csrf::field() ?>
                <div class="form-grid">
                    <label>Tipo da chave *
                        <select name="tipo_chave" required>
                            <?php foreach (EmpresaPix::TIPOS as $tv => $tr): ?>
                                <option value="<?= e($tv) ?>" <?= $val('pix', 'tipo_chave', $chave, 'cnpj') === $tv ? 'selected' : '' ?>><?= e($tr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Chave *<input type="text" name="chave" required maxlength="150" value="<?= e($val('pix', 'chave', $chave)) ?>"></label>
                    <label>Identificação<input type="text" name="identificacao" maxlength="100" value="<?= e($val('pix', 'identificacao', $chave)) ?>" placeholder="Ex.: PIX principal"></label>
                    <label>Banco<input type="text" name="banco" maxlength="100" value="<?= e($val('pix', 'banco', $chave)) ?>"></label>
                    <label class="span-2">Observação<input type="text" name="observacao" maxlength="255" value="<?= e($val('pix', 'observacao', $chave)) ?>"></label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary"><?= $chave ? 'Salvar chave' : 'Adicionar chave' ?></button>
                    <a class="btn-secondary" href="<?= e(url('/empresa#pix')) ?>">Cancelar</a>
                </div>
            </form>
            <?php
        };
        ?>

        <?php if ($abrirFormPix) { $formPix(null); } ?>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Tipo</th><th>Chave</th><th>Identificação</th><th>Banco</th><th>Situação</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (!$pix): ?>
                        <tr><td colspan="6" class="empty">Nenhuma chave PIX cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pix as $p): ?>
                        <?php $base = '/empresa/pix/' . (int) $p['id']; ?>
                        <tr>
                            <td><?= e(EmpresaPix::TIPOS[$p['tipo_chave']] ?? '') ?></td>
                            <td>
                                <strong style="word-break:break-all"><?= e($p['chave']) ?></strong>
                                <?php if ($p['observacao']): ?><div class="hint"><?= e($p['observacao']) ?></div><?php endif; ?>
                            </td>
                            <td><?= e($p['identificacao'] ?: '-') ?></td>
                            <td><?= e($p['banco'] ?: '-') ?></td>
                            <td><?= $ativoBadge($p['ativo'], 'Ativa', 'Inativa') ?></td>
                            <td class="actions">
                                <button type="button" class="link-btn" data-copy="<?= e($p['chave']) ?>" data-copy-msg="Chave PIX copiada.">Copiar PIX</button>
                                <?php if ($isAdmin): ?>
                                    <a href="<?= e(url('/empresa?editar_pix=' . (int) $p['id'] . '#pix')) ?>">Editar</a>
                                    <form method="post" action="<?= e(url($base . '/status')) ?>">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn"><?= (int) $p['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                                    </form>
                                    <form method="post" action="<?= e(url($base . '/excluir')) ?>" onsubmit="return confirm('Excluir esta chave PIX?')">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($isAdmin && $editarPixId === (int) $p['id']): ?>
                            <tr><td colspan="6"><?php $formPix($p); ?></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" id="documentos">
        <div class="toolbar" style="margin-bottom:8px">
            <h2 style="margin:0">Documentos</h2>
            <?php if ($isAdmin && !$abrirFormDoc): ?>
                <a class="btn-secondary" href="<?= e(url('/empresa?novo_documento=1#documentos')) ?>">+ Enviar documento</a>
            <?php endif; ?>
        </div>

        <?php if ($abrirFormDoc): ?>
            <form method="post" action="<?= e(url('/empresa/documentos')) ?>" enctype="multipart/form-data" class="form" style="margin:12px 0">
                <?= Csrf::field() ?>
                <div class="form-grid">
                    <label>Nome do documento *<input type="text" name="nome" required maxlength="150" value="<?= e($val('documento', 'nome')) ?>" placeholder="Ex.: Cartão CNPJ 2026"></label>
                    <label>Tipo *
                        <select name="tipo_documento" required>
                            <?php foreach (EmpresaDocumento::TIPOS as $tv => $tr): ?>
                                <option value="<?= e($tv) ?>" <?= $val('documento', 'tipo_documento', null, 'cnpj') === $tv ? 'selected' : '' ?>><?= e($tr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="span-2">Arquivo * <span class="hint">PDF, JPG, JPEG ou PNG — até <?= e(Arquivo::formatTamanho(Upload::limiteEfetivoBytes())) ?></span>
                        <input type="file" name="arquivo" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                    </label>
                    <label class="span-2">Observação<input type="text" name="observacao" maxlength="255" value="<?= e($val('documento', 'observacao')) ?>"></label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Enviar documento</button>
                    <a class="btn-secondary" href="<?= e(url('/empresa#documentos')) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Documento</th><th>Tipo</th><th>Arquivo</th><th class="num">Tamanho</th><th>Enviado em</th><th>Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (!$documentos): ?>
                        <tr><td colspan="6" class="empty">Nenhum documento enviado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documentos as $d): ?>
                        <?php $base = '/empresa/documentos/' . (int) $d['id']; ?>
                        <tr>
                            <td>
                                <?= e($d['nome']) ?>
                                <?php if ($d['observacao']): ?><div class="hint"><?= e($d['observacao']) ?></div><?php endif; ?>
                            </td>
                            <td><?= e(EmpresaDocumento::TIPOS[$d['tipo_documento']] ?? '') ?></td>
                            <td style="word-break:break-all"><?= e($d['nome_arquivo']) ?></td>
                            <td class="num"><?= e(Arquivo::formatTamanho((int) $d['tamanho'])) ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $d['created_at']))) ?></td>
                            <td class="actions">
                                <a href="<?= e(url($base . '/download?inline=1')) ?>" target="_blank" rel="noopener">Visualizar</a>
                                <a href="<?= e(url($base . '/download')) ?>">Baixar</a>
                                <?php if ($isAdmin): ?>
                                    <form method="post" action="<?= e(url($base . '/excluir')) ?>" onsubmit="return confirm('Excluir este documento? O arquivo será apagado.')">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link-btn">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<script src="<?= e(url('/assets/js/empresa.js')) ?>"></script>

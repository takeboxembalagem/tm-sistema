<?php
$old = $old ?? [];
$val = static function (string $campo) use ($old, $empresa): string {
    if (array_key_exists($campo, $old)) {
        return (string) $old[$campo];
    }
    return (string) ($empresa[$campo] ?? '');
};
$input = static function (string $campo, string $rotulo, int $max, string $extra = '') use ($val): void {
    echo '<label>' . e($rotulo) . '<input type="text" name="' . e($campo) . '" maxlength="' . $max . '" value="' . e($val($campo)) . '"' . $extra . '></label>';
};
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/empresa')) ?>" class="form">
    <?= Csrf::field() ?>

    <section class="card form-card">
        <h2>Dados cadastrais</h2>
        <div class="form-grid">
            <?php $input('razao_social', 'Razão Social *', 200, ' required'); ?>
            <?php $input('nome_fantasia', 'Nome Fantasia', 200); ?>
            <?php $input('cnpj', 'CNPJ', 18, ' placeholder="00.000.000/0000-00"'); ?>
            <?php $input('inscricao_estadual', 'Inscrição Estadual', 30, ' placeholder="Número ou ISENTO"'); ?>
            <?php $input('inscricao_municipal', 'Inscrição Municipal', 30); ?>
            <?php $input('cnae_principal', 'CNAE principal', 150, ' placeholder="Código e descrição"'); ?>
            <label>Regime tributário
                <select name="regime_tributario">
                    <option value="">Não informado</option>
                    <?php foreach (Empresa::REGIMES as $rv => $rr): ?>
                        <option value="<?= e($rv) ?>" <?= $val('regime_tributario') === $rv ? 'selected' : '' ?>><?= e($rr) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Data de abertura<input type="date" name="data_abertura" max="<?= e(date('Y-m-d')) ?>" value="<?= e($val('data_abertura')) ?>"></label>
        </div>
    </section>

    <section class="card form-card">
        <h2>Endereço</h2>
        <div class="form-grid">
            <?php $input('cep', 'CEP', 9, ' placeholder="00000-000" inputmode="numeric"'); ?>
            <?php $input('endereco', 'Endereço', 200, ' placeholder="Rua, avenida…"'); ?>
            <?php $input('numero', 'Número', 20); ?>
            <?php $input('complemento', 'Complemento', 100); ?>
            <?php $input('bairro', 'Bairro', 100); ?>
            <?php $input('cidade', 'Cidade', 100); ?>
            <label>Estado
                <select name="estado">
                    <option value="">—</option>
                    <?php foreach (Empresa::UFS as $uf): ?>
                        <option value="<?= e($uf) ?>" <?= strtoupper($val('estado')) === $uf ? 'selected' : '' ?>><?= e($uf) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </section>

    <section class="card form-card">
        <h2>Contato</h2>
        <div class="form-grid">
            <?php $input('telefone', 'Telefone', 30, ' placeholder="(00) 0000-0000" inputmode="tel"'); ?>
            <?php $input('whatsapp', 'WhatsApp', 30, ' placeholder="(00) 00000-0000" inputmode="tel"'); ?>
            <label>E-mail<input type="email" name="email" maxlength="150" value="<?= e($val('email')) ?>"></label>
            <?php $input('site', 'Site', 200, ' placeholder="www.exemplo.com.br"'); ?>
            <?php $input('pessoa_contato', 'Pessoa de contato', 120); ?>
        </div>
    </section>

    <section class="card form-card">
        <h2>Observações</h2>
        <label>Observações gerais <span class="hint">(uso interno — não entram no cadastro para envio)</span>
            <textarea name="observacoes" rows="4" maxlength="5000"><?= e($val('observacoes')) ?></textarea>
        </label>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Salvar dados</button>
        <a class="btn-secondary" href="<?= e(url('/empresa')) ?>">Cancelar</a>
    </div>
</form>

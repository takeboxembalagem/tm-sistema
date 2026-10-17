<?php

declare(strict_types=1);

final class EmpresaController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $empresa = Empresa::find();
        $contas = $empresa ? EmpresaConta::listar() : [];
        $pix = $empresa ? EmpresaPix::listar() : [];

        View::render('empresa/index', [
            'title' => 'Empresa',
            'page' => 'empresa',
            'empresa' => $empresa,
            'contas' => $contas,
            'pix' => $pix,
            'documentos' => $empresa ? EmpresaDocumento::listar() : [],
            'textoEnvio' => Empresa::textoEnvio($empresa, $contas, $pix),
            'novaConta' => isset($_GET['nova_conta']),
            'editarContaId' => self::idGet('editar_conta'),
            'novoPix' => isset($_GET['novo_pix']),
            'editarPixId' => self::idGet('editar_pix'),
            'novoDocumento' => isset($_GET['novo_documento']),
            'old' => flash('empresa_old') ?? [],
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function edit(): void
    {
        Auth::requireAdmin();

        View::render('empresa/form', [
            'title' => 'Editar dados da empresa',
            'page' => 'empresa',
            'empresa' => Empresa::find(),
            'old' => flash('empresa_old') ?? [],
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function update(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        [$data, $errors] = self::dadosEmpresa();
        if ($errors) {
            flash('errors', $errors);
            flash('empresa_old', self::postStrings(Empresa::CAMPOS));
            redirect('/empresa/editar');
        }

        Empresa::salvar($data);
        flash('success', 'Dados da empresa salvos.');
        redirect('/empresa');
    }

    /** Página limpa para copiar, imprimir ou salvar como PDF pelo navegador. */
    public static function envio(): void
    {
        Auth::requireLogin();

        $empresa = Empresa::find();
        View::render('empresa/envio', [
            'title' => 'Cadastro da empresa',
            'empresa' => $empresa,
            'textoEnvio' => Empresa::textoEnvio($empresa, $empresa ? EmpresaConta::listar() : [], $empresa ? EmpresaPix::listar() : []),
        ], '');
    }

    // ---- Contas bancárias ----

    public static function contaStore(): void
    {
        self::exigirPostComEmpresa();
        [$data, $errors] = self::dadosConta();
        if ($errors) {
            self::voltarComErros($errors, 'conta', ['banco', 'agencia', 'conta', 'tipo_conta', 'titular', 'documento_titular', 'observacao'], 'nova_conta=1', 'contas');
        }
        EmpresaConta::create($data);
        flash('success', 'Conta bancária adicionada.');
        redirect('/empresa#contas');
    }

    public static function contaUpdate(int $id): void
    {
        self::exigirPostComEmpresa();
        self::exigirConta($id);
        [$data, $errors] = self::dadosConta();
        if ($errors) {
            self::voltarComErros($errors, 'conta', ['banco', 'agencia', 'conta', 'tipo_conta', 'titular', 'documento_titular', 'observacao'], 'editar_conta=' . $id, 'contas');
        }
        EmpresaConta::update($id, $data);
        flash('success', 'Conta bancária atualizada.');
        redirect('/empresa#contas');
    }

    public static function contaStatus(int $id): void
    {
        self::exigirPostComEmpresa();
        $conta = self::exigirConta($id);
        $ativar = (int) $conta['ativo'] !== 1;
        EmpresaConta::setAtivo($id, $ativar);
        flash('success', $ativar ? 'Conta bancária ativada.' : 'Conta bancária desativada.');
        redirect('/empresa#contas');
    }

    public static function contaDestroy(int $id): void
    {
        self::exigirPostComEmpresa();
        self::exigirConta($id);
        EmpresaConta::delete($id);
        flash('success', 'Conta bancária excluída.');
        redirect('/empresa#contas');
    }

    // ---- PIX ----

    public static function pixStore(): void
    {
        self::exigirPostComEmpresa();
        [$data, $errors] = self::dadosPix(null);
        if ($errors) {
            self::voltarComErros($errors, 'pix', ['tipo_chave', 'chave', 'identificacao', 'banco', 'observacao'], 'novo_pix=1', 'pix');
        }
        EmpresaPix::create($data);
        flash('success', 'Chave PIX adicionada.');
        redirect('/empresa#pix');
    }

    public static function pixUpdate(int $id): void
    {
        self::exigirPostComEmpresa();
        self::exigirPix($id);
        [$data, $errors] = self::dadosPix($id);
        if ($errors) {
            self::voltarComErros($errors, 'pix', ['tipo_chave', 'chave', 'identificacao', 'banco', 'observacao'], 'editar_pix=' . $id, 'pix');
        }
        EmpresaPix::update($id, $data);
        flash('success', 'Chave PIX atualizada.');
        redirect('/empresa#pix');
    }

    public static function pixStatus(int $id): void
    {
        self::exigirPostComEmpresa();
        $pix = self::exigirPix($id);
        $ativar = (int) $pix['ativo'] !== 1;
        EmpresaPix::setAtivo($id, $ativar);
        flash('success', $ativar ? 'Chave PIX ativada.' : 'Chave PIX desativada.');
        redirect('/empresa#pix');
    }

    public static function pixDestroy(int $id): void
    {
        self::exigirPostComEmpresa();
        self::exigirPix($id);
        EmpresaPix::delete($id);
        flash('success', 'Chave PIX excluída.');
        redirect('/empresa#pix');
    }

    // ---- Documentos ----

    public static function documentoStore(): void
    {
        self::exigirPostComEmpresa();

        $str = self::postStrings(['nome', 'tipo_documento', 'observacao']);
        $errors = [];
        if ($str['nome'] === '') {
            $errors[] = 'Informe o nome do documento.';
        } elseif (mb_strlen($str['nome']) > 150) {
            $errors[] = 'O nome do documento deve ter no máximo 150 caracteres.';
        }
        if (!isset(EmpresaDocumento::TIPOS[$str['tipo_documento']])) {
            $errors[] = 'Selecione o tipo do documento.';
        }
        if (mb_strlen($str['observacao']) > 255) {
            $errors[] = 'A observação deve ter no máximo 255 caracteres.';
        }

        $upload = null;
        if (!$errors) {
            $upload = Upload::documentoEmpresa($_FILES['arquivo'] ?? []);
            if (!$upload['ok']) {
                $errors[] = (string) $upload['error'];
            }
        }
        if ($errors) {
            self::voltarComErros($errors, 'documento', ['nome', 'tipo_documento', 'observacao'], 'novo_documento=1', 'documentos');
        }

        try {
            EmpresaDocumento::create([
                'nome' => $str['nome'],
                'tipo_documento' => $str['tipo_documento'],
                'nome_arquivo' => $upload['nome_original'],
                'caminho_arquivo' => $upload['caminho'],
                'mime_type' => $upload['mime'],
                'tamanho' => $upload['tamanho'],
                'observacao' => $str['observacao'] !== '' ? $str['observacao'] : null,
            ]);
        } catch (Throwable $e) {
            Upload::removerArquivoFisico($upload['caminho']);
            throw $e;
        }

        flash('success', 'Documento enviado.');
        redirect('/empresa#documentos');
    }

    public static function documentoDownload(int $id): void
    {
        Auth::requireLogin();

        $doc = EmpresaDocumento::find($id);
        $mime = $doc ? (EmpresaDocumento::MIMES[EmpresaDocumento::extensao($doc)] ?? null) : null;
        $root = realpath(Upload::storageRoot());
        $real = $doc ? realpath(Upload::absolutePath((string) $doc['caminho_arquivo'])) : false;
        if ($mime === null || $root === false || $real === false || !str_starts_with($real, $root) || !is_file($real)) {
            http_response_code(404);
            echo 'Documento não encontrado.';
            return;
        }

        $nome = str_replace(["\r", "\n", '"'], '', (string) $doc['nome_arquivo']);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($real));
        header((isset($_GET['inline']) ? 'Content-Disposition: inline' : 'Content-Disposition: attachment') . '; filename="' . $nome . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($real);
        exit;
    }

    public static function documentoDestroy(int $id): void
    {
        self::exigirPostComEmpresa();
        $doc = EmpresaDocumento::find($id);
        if (!$doc) {
            flash('errors', ['Documento não encontrado.']);
            redirect('/empresa#documentos');
        }
        EmpresaDocumento::delete($id);
        Upload::removerArquivoFisico((string) $doc['caminho_arquivo']);
        flash('success', 'Documento excluído.');
        redirect('/empresa#documentos');
    }

    // ---- Apoio ----

    /** Escrita em contas, PIX e documentos: somente admin, e só depois de salvar o cadastro principal. */
    private static function exigirPostComEmpresa(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        if (!Empresa::find()) {
            flash('errors', ['Cadastre os dados da empresa antes.']);
            redirect('/empresa');
        }
    }

    private static function exigirConta(int $id): array
    {
        $conta = EmpresaConta::find($id);
        if (!$conta) {
            flash('errors', ['Conta bancária não encontrada.']);
            redirect('/empresa#contas');
        }
        return $conta;
    }

    private static function exigirPix(int $id): array
    {
        $pix = EmpresaPix::find($id);
        if (!$pix) {
            flash('errors', ['Chave PIX não encontrada.']);
            redirect('/empresa#pix');
        }
        return $pix;
    }

    private static function voltarComErros(array $errors, string $form, array $campos, string $abrir, string $ancora): void
    {
        flash('errors', $errors);
        flash('empresa_old', ['form' => $form] + self::postStrings($campos));
        redirect('/empresa?' . $abrir . '#' . $ancora);
    }

    /** @return array<string, string> */
    private static function postStrings(array $campos): array
    {
        $out = [];
        foreach ($campos as $c) {
            $out[$c] = is_string($_POST[$c] ?? null) ? trim($_POST[$c]) : '';
        }
        return $out;
    }

    private static function idGet(string $chave): ?int
    {
        $v = $_GET[$chave] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $id = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function nulo(string $v): ?string
    {
        return $v !== '' ? $v : null;
    }

    private static function telefoneValido(string $v): bool
    {
        $digitos = strlen(Empresa::soDigitos($v));
        return preg_match('/^[0-9\s().+\-]+$/', $v) === 1 && $digitos >= 10 && $digitos <= 13 && mb_strlen($v) <= 30;
    }

    /** @return array{0:array<string, ?string>, 1:list<string>} */
    private static function dadosEmpresa(): array
    {
        $s = self::postStrings(Empresa::CAMPOS);
        $errors = [];

        $limites = [
            'razao_social' => [200, 'Razão Social'], 'nome_fantasia' => [200, 'Nome Fantasia'],
            'cnae_principal' => [150, 'CNAE principal'], 'endereco' => [200, 'Endereço'], 'numero' => [20, 'Número'],
            'complemento' => [100, 'Complemento'], 'bairro' => [100, 'Bairro'], 'cidade' => [100, 'Cidade'],
            'site' => [200, 'Site'], 'pessoa_contato' => [120, 'Pessoa de contato'], 'observacoes' => [5000, 'Observações'],
        ];
        foreach ($limites as $campo => [$max, $rotulo]) {
            if (mb_strlen($s[$campo]) > $max) {
                $errors[] = "{$rotulo}: máximo de {$max} caracteres.";
            }
        }

        if ($s['razao_social'] === '') {
            $errors[] = 'Informe a Razão Social.';
        }
        if ($s['cnpj'] !== '') {
            if (Empresa::cnpjValido($s['cnpj'])) {
                $s['cnpj'] = Empresa::formatarCnpj($s['cnpj']);
            } else {
                $errors[] = 'CNPJ inválido.';
            }
        }
        foreach (['inscricao_estadual' => 'Inscrição Estadual', 'inscricao_municipal' => 'Inscrição Municipal'] as $campo => $rotulo) {
            if ($s[$campo] !== '' && preg_match('/^[A-Za-z0-9 .\/\-]{1,30}$/', $s[$campo]) !== 1) {
                $errors[] = "{$rotulo} inválida (use números, letras, ponto, barra ou hífen; até 30 caracteres).";
            }
        }
        if ($s['regime_tributario'] !== '' && !isset(Empresa::REGIMES[$s['regime_tributario']])) {
            $errors[] = 'Regime tributário inválido.';
        }
        if ($s['data_abertura'] !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $s['data_abertura']);
            if ($dt === false || $dt->format('Y-m-d') !== $s['data_abertura']) {
                $errors[] = 'Data de abertura inválida.';
            } elseif ($s['data_abertura'] > date('Y-m-d')) {
                $errors[] = 'A data de abertura não pode ser futura.';
            }
        }
        if ($s['cep'] !== '') {
            $cep = Empresa::soDigitos($s['cep']);
            if (strlen($cep) === 8 && preg_match('/^[0-9.\-\s]+$/', $s['cep']) === 1) {
                $s['cep'] = substr($cep, 0, 5) . '-' . substr($cep, 5);
            } else {
                $errors[] = 'CEP inválido (8 dígitos).';
            }
        }
        $s['estado'] = strtoupper($s['estado']);
        if ($s['estado'] !== '' && !in_array($s['estado'], Empresa::UFS, true)) {
            $errors[] = 'Estado inválido.';
        }
        foreach (['telefone' => 'Telefone', 'whatsapp' => 'WhatsApp'] as $campo => $rotulo) {
            if ($s[$campo] !== '' && !self::telefoneValido($s[$campo])) {
                $errors[] = "{$rotulo} inválido (DDD + número).";
            }
        }
        if ($s['email'] !== '') {
            $s['email'] = mb_strtolower($s['email']);
            if (filter_var($s['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($s['email']) > 150) {
                $errors[] = 'E-mail inválido.';
            }
        }
        if ($s['site'] !== '') {
            $url = Empresa::urlSite($s['site']);
            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://[^\s/:]+\.[^\s/:]+#i', $url) !== 1) {
                $errors[] = 'Site inválido.';
            }
        }

        return [array_map([self::class, 'nulo'], $s), $errors];
    }

    /** @return array{0:array<string, ?string>, 1:list<string>} */
    private static function dadosConta(): array
    {
        $s = self::postStrings(['banco', 'agencia', 'conta', 'tipo_conta', 'titular', 'documento_titular', 'observacao']);
        $errors = [];
        if ($s['banco'] === '' || mb_strlen($s['banco']) > 100) {
            $errors[] = 'Informe o banco (até 100 caracteres).';
        }
        if (preg_match('/^[0-9A-Za-z\- ]{1,20}$/', $s['agencia']) !== 1) {
            $errors[] = 'Informe a agência (números, letras e hífen; até 20 caracteres).';
        }
        if (preg_match('/^[0-9A-Za-z\- ]{1,30}$/', $s['conta']) !== 1) {
            $errors[] = 'Informe a conta (números, letras e hífen; até 30 caracteres).';
        }
        if (!isset(EmpresaConta::TIPOS[$s['tipo_conta']])) {
            $errors[] = 'Selecione o tipo de conta.';
        }
        if (mb_strlen($s['titular']) > 150) {
            $errors[] = 'Titular: máximo de 150 caracteres.';
        }
        if ($s['documento_titular'] !== '') {
            $cpf = Empresa::soDigitos($s['documento_titular']);
            if (strlen($cpf) === 11 && preg_match('/^[0-9.\-\s]+$/', $s['documento_titular']) === 1) {
                $s['documento_titular'] = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9);
            } elseif (Empresa::cnpjValido($s['documento_titular'])) {
                $s['documento_titular'] = Empresa::formatarCnpj($s['documento_titular']);
            } else {
                $errors[] = 'CPF/CNPJ do titular inválido.';
            }
        }
        if (mb_strlen($s['observacao']) > 255) {
            $errors[] = 'A observação deve ter no máximo 255 caracteres.';
        }
        return [array_map([self::class, 'nulo'], $s), $errors];
    }

    /** @return array{0:array<string, ?string>, 1:list<string>} */
    private static function dadosPix(?int $id): array
    {
        $s = self::postStrings(['tipo_chave', 'chave', 'identificacao', 'banco', 'observacao']);
        $errors = [];
        if (!isset(EmpresaPix::TIPOS[$s['tipo_chave']])) {
            $errors[] = 'Selecione o tipo da chave.';
        } else {
            [$chave, $erro] = EmpresaPix::normalizarChave($s['tipo_chave'], $s['chave']);
            if ($erro !== null) {
                $errors[] = $erro;
            } elseif (EmpresaPix::chaveExiste($chave, $id)) {
                $errors[] = 'Esta chave PIX já está cadastrada.';
            } else {
                $s['chave'] = $chave;
            }
        }
        if (mb_strlen($s['identificacao']) > 100) {
            $errors[] = 'Identificação: máximo de 100 caracteres.';
        }
        if (mb_strlen($s['banco']) > 100) {
            $errors[] = 'Banco: máximo de 100 caracteres.';
        }
        if (mb_strlen($s['observacao']) > 255) {
            $errors[] = 'A observação deve ter no máximo 255 caracteres.';
        }
        return [array_map([self::class, 'nulo'], $s), $errors];
    }
}

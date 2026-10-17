<?php

declare(strict_types=1);

final class EstoquePapelController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
        ];

        $result = EstoquePapel::paginate($filters, (int) ($_GET['page'] ?? 1), 24);

        View::render('estoque/papeis/index', [
            'title' => 'Estoque — Papéis',
            'page' => 'estoque',
            'estoqueTab' => 'papeis',
            'itens' => $result['items'],
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'itensPdf' => EstoquePapel::paginate([], 1, 1000000)['items'],
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function create(): void
    {
        Auth::requireLogin();
        self::form(null);
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::save(null);
    }

    public static function show(int $id): void
    {
        Auth::requireLogin();
        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        View::render('estoque/papeis/show', [
            'title' => $item['nome'],
            'page' => 'estoque',
            'estoqueTab' => 'papeis',
            'item' => $item,
            'movimentos' => EstoquePapel::movimentos($id),
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    public static function edit(int $id): void
    {
        Auth::requireLogin();
        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }
        self::form($item);
    }

    public static function update(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }
        self::save($item);
    }

    public static function toggle(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        $novo = $item['status'] === 'ativo' ? 'inativo' : 'ativo';
        EstoquePapel::setStatus($id, $novo);
        flash('success', $novo === 'inativo' ? 'Papel inativado.' : 'Papel reativado.');
        redirect('/estoque/papeis/' . $id);
    }

    public static function destroy(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        $resultado = EstoquePapel::excluirSemMovimentos($id);
        if ($resultado['status'] === 'nao_encontrado') {
            self::notFound();
            return;
        }
        if ($resultado['status'] === 'com_movimentos') {
            flash('errors', ['Este papel possui movimentações de estoque e não pode ser excluído. Inative-o para preservar o histórico.']);
            redirect('/estoque/papeis/' . $id);
        }
        Upload::removerImagemPublica($resultado['imagem']);

        flash('success', 'Papel excluído do estoque.');
        redirect('/estoque/papeis');
    }

    public static function movimento(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $item = EstoquePapel::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        $tipo = (string) ($_POST['tipo'] ?? '');
        $quantidadeRaw = trim((string) ($_POST['quantidade'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $quantidade = parse_quantidade_br($quantidadeRaw);

        $errors = [];
        if (!in_array($tipo, ['entrada', 'saida', 'ajuste'], true)) {
            $errors[] = 'Selecione o tipo de movimento.';
        }
        if ($quantidade === null) {
            $errors[] = 'Informe uma quantidade válida.';
        }

        $old = [
            'tipo' => $tipo,
            'quantidade' => $quantidadeRaw,
            'observacao' => $observacao,
        ];

        if ($errors) {
            flash('errors', $errors);
            flash('old', $old);
            redirect('/estoque/papeis/' . $id);
        }

        try {
            EstoquePapel::registrarMovimento(
                $id,
                $tipo,
                (float) $quantidade,
                $observacao !== '' ? $observacao : null,
                Auth::id()
            );
            flash('success', 'Movimento registrado.');
        } catch (RuntimeException $e) {
            flash('errors', [$e->getMessage()]);
            flash('old', $old);
        }

        redirect('/estoque/papeis/' . $id);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function form(?array $existing): void
    {
        View::render('estoque/papeis/form', [
            'title' => $existing ? 'Editar papel' : 'Novo papel',
            'page' => 'estoque',
            'estoqueTab' => 'papeis',
            'item' => $existing,
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function save(?array $existing): void
    {
        $id = $existing ? (int) $existing['id'] : null;
        $codigo = trim((string) ($_POST['codigo'] ?? ''));
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $cor = trim((string) ($_POST['cor'] ?? ''));
        $formato = trim((string) ($_POST['formato'] ?? ''));
        $gramatura = trim((string) ($_POST['gramatura'] ?? ''));
        $unidade = trim((string) ($_POST['unidade'] ?? 'folha'));
        $localizacao = trim((string) ($_POST['localizacao'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $quantidadeRaw = trim((string) ($_POST['quantidade'] ?? '0'));

        $errors = [];
        if ($codigo === '') {
            $errors[] = 'Informe o código.';
        } elseif (mb_strlen($codigo) > 40) {
            $errors[] = 'O código deve ter no máximo 40 caracteres.';
        } elseif (EstoquePapel::codigoExiste($codigo, $id)) {
            $errors[] = 'Este código já está cadastrado.';
        }

        if ($nome === '') {
            $errors[] = 'Informe o nome.';
        } elseif (mb_strlen($nome) > 180) {
            $errors[] = 'O nome deve ter no máximo 180 caracteres.';
        }

        if ($unidade === '') {
            $unidade = 'folha';
        } elseif (mb_strlen($unidade) > 20) {
            $errors[] = 'A unidade deve ter no máximo 20 caracteres.';
        }

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }
        if ($existing === null) {
            $status = 'ativo';
        }

        $quantidade = 0.0;
        if ($existing === null) {
            $parsed = parse_quantidade_br($quantidadeRaw !== '' ? $quantidadeRaw : '0');
            if ($parsed === null || $parsed < 0) {
                $errors[] = 'Informe uma quantidade inicial válida (zero ou maior).';
            } else {
                $quantidade = round($parsed, 4);
            }
        }

        $upload = Upload::papelImagem($_FILES['imagem'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
        if (!$upload['ok']) {
            $errors[] = $upload['error'] ?? 'Falha no envio da imagem.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'codigo' => $codigo,
                'nome' => $nome,
                'cor' => $cor,
                'formato' => $formato,
                'gramatura' => $gramatura,
                'unidade' => $unidade,
                'localizacao' => $localizacao,
                'observacao' => $observacao,
                'status' => $status,
                'quantidade' => $quantidadeRaw,
            ]);
            redirect($id ? '/estoque/papeis/' . $id . '/editar' : '/estoque/papeis/novo');
        }

        $imagem = $existing['imagem'] ?? null;
        if ($upload['path']) {
            $imagem = $upload['path'];
        }

        if ($id) {
            EstoquePapel::update($id, [
                'codigo' => $codigo,
                'nome' => $nome,
                'cor' => $cor !== '' ? $cor : null,
                'formato' => $formato !== '' ? $formato : null,
                'gramatura' => $gramatura !== '' ? $gramatura : null,
                'unidade' => $unidade,
                'localizacao' => $localizacao !== '' ? $localizacao : null,
                'imagem' => $imagem,
                'observacao' => $observacao !== '' ? $observacao : null,
                'status' => $status,
            ]);
            if ($upload['path'] && !empty($existing['imagem']) && $existing['imagem'] !== $upload['path']) {
                Upload::removerImagemPublica((string) $existing['imagem']);
            }
            flash('success', 'Papel atualizado.');
            redirect('/estoque/papeis/' . $id);
        }

        $novoId = EstoquePapel::create([
            'codigo' => $codigo,
            'nome' => $nome,
            'cor' => $cor !== '' ? $cor : null,
            'formato' => $formato !== '' ? $formato : null,
            'gramatura' => $gramatura !== '' ? $gramatura : null,
            'quantidade' => $quantidade,
            'unidade' => $unidade,
            'localizacao' => $localizacao !== '' ? $localizacao : null,
            'imagem' => $imagem,
            'observacao' => $observacao !== '' ? $observacao : null,
            'status' => $status,
        ], Auth::id());

        flash('success', 'Papel cadastrado.');
        redirect('/estoque/papeis/' . $novoId);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Papel não encontrado', 'page' => 'estoque']);
    }
}

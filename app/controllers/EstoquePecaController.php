<?php

declare(strict_types=1);

final class EstoquePecaController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
            'tipo' => (string) ($_GET['tipo'] ?? ''),
        ];

        $result = EstoquePeca::paginate($filters, (int) ($_GET['page'] ?? 1), 24);

        View::render('estoque/pecas/index', [
            'title' => 'Estoque — Peças',
            'page' => 'estoque',
            'estoqueTab' => 'pecas',
            'itens' => $result['items'],
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'itensPdf' => EstoquePeca::paginate([], 1, 1000000)['items'],
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
        $item = EstoquePeca::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        View::render('estoque/pecas/show', [
            'title' => $item['nome'],
            'page' => 'estoque',
            'estoqueTab' => 'pecas',
            'item' => $item,
            'movimentos' => EstoquePeca::movimentos($id),
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    public static function edit(int $id): void
    {
        Auth::requireLogin();
        $item = EstoquePeca::find($id);
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
        $item = EstoquePeca::find($id);
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
        $item = EstoquePeca::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        $novo = $item['status'] === 'ativo' ? 'inativo' : 'ativo';
        EstoquePeca::setStatus($id, $novo);
        flash('success', $novo === 'inativo' ? 'Peça inativada.' : 'Peça reativada.');
        redirect('/estoque/pecas/' . $id);
    }

    public static function destroy(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $item = EstoquePeca::find($id);
        if (!$item) {
            self::notFound();
            return;
        }

        $resultado = EstoquePeca::excluirSemMovimentos($id);
        if ($resultado['status'] === 'nao_encontrado') {
            self::notFound();
            return;
        }
        if ($resultado['status'] === 'com_movimentos') {
            flash('errors', ['Esta peça possui movimentações de estoque e não pode ser excluída. Inative-a para preservar o histórico.']);
            redirect('/estoque/pecas/' . $id);
        }
        Upload::removerImagemPublica($resultado['imagem']);

        flash('success', 'Peça excluída do estoque.');
        redirect('/estoque/pecas');
    }

    public static function movimento(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $item = EstoquePeca::find($id);
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
            redirect('/estoque/pecas/' . $id);
        }

        try {
            EstoquePeca::registrarMovimento(
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

        redirect('/estoque/pecas/' . $id);
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function form(?array $existing): void
    {
        View::render('estoque/pecas/form', [
            'title' => $existing ? 'Editar peça' : 'Nova peça',
            'page' => 'estoque',
            'estoqueTab' => 'pecas',
            'item' => $existing,
            'produtos' => Produto::opcoesSelect(true),
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
        $tipo = (string) ($_POST['tipo'] ?? 'outra');
        $produtoIdRaw = (int) ($_POST['produto_id'] ?? 0);
        $cor = trim((string) ($_POST['cor'] ?? ''));
        $estampa = trim((string) ($_POST['estampa'] ?? ''));
        $unidade = trim((string) ($_POST['unidade'] ?? 'un'));
        $localizacao = trim((string) ($_POST['localizacao'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $quantidadeRaw = trim((string) ($_POST['quantidade'] ?? '0'));

        $errors = [];
        if ($codigo === '') {
            $errors[] = 'Informe o código.';
        } elseif (mb_strlen($codigo) > 40) {
            $errors[] = 'O código deve ter no máximo 40 caracteres.';
        } elseif (EstoquePeca::codigoExiste($codigo, $id)) {
            $errors[] = 'Este código já está cadastrado.';
        }

        if ($nome === '') {
            $errors[] = 'Informe o nome.';
        } elseif (mb_strlen($nome) > 180) {
            $errors[] = 'O nome deve ter no máximo 180 caracteres.';
        }

        if (!in_array($tipo, ['tampa', 'fundo', 'outra'], true)) {
            $tipo = 'outra';
        }

        $produtoId = null;
        if ($produtoIdRaw > 0) {
            if (!Produto::find($produtoIdRaw)) {
                $errors[] = 'Produto vinculado inválido.';
            } else {
                $produtoId = $produtoIdRaw;
            }
        }

        if ($unidade === '') {
            $unidade = 'un';
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

        $upload = Upload::pecaImagem($_FILES['imagem'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
        if (!$upload['ok']) {
            $errors[] = $upload['error'] ?? 'Falha no envio da imagem.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'codigo' => $codigo,
                'nome' => $nome,
                'tipo' => $tipo,
                'produto_id' => $produtoIdRaw > 0 ? (string) $produtoIdRaw : '',
                'cor' => $cor,
                'estampa' => $estampa,
                'unidade' => $unidade,
                'localizacao' => $localizacao,
                'observacao' => $observacao,
                'status' => $status,
                'quantidade' => $quantidadeRaw,
            ]);
            redirect($id ? '/estoque/pecas/' . $id . '/editar' : '/estoque/pecas/novo');
        }

        $imagem = $existing['imagem'] ?? null;
        if ($upload['path']) {
            $imagem = $upload['path'];
        }

        if ($id) {
            EstoquePeca::update($id, [
                'codigo' => $codigo,
                'nome' => $nome,
                'tipo' => $tipo,
                'produto_id' => $produtoId,
                'cor' => $cor !== '' ? $cor : null,
                'estampa' => $estampa !== '' ? $estampa : null,
                'unidade' => $unidade,
                'localizacao' => $localizacao !== '' ? $localizacao : null,
                'imagem' => $imagem,
                'observacao' => $observacao !== '' ? $observacao : null,
                'status' => $status,
            ]);
            if ($upload['path'] && !empty($existing['imagem']) && $existing['imagem'] !== $upload['path']) {
                Upload::removerImagemPublica((string) $existing['imagem']);
            }
            flash('success', 'Peça atualizada.');
            redirect('/estoque/pecas/' . $id);
        }

        $novoId = EstoquePeca::create([
            'codigo' => $codigo,
            'nome' => $nome,
            'tipo' => $tipo,
            'produto_id' => $produtoId,
            'cor' => $cor !== '' ? $cor : null,
            'estampa' => $estampa !== '' ? $estampa : null,
            'quantidade' => $quantidade,
            'unidade' => $unidade,
            'localizacao' => $localizacao !== '' ? $localizacao : null,
            'imagem' => $imagem,
            'observacao' => $observacao !== '' ? $observacao : null,
            'status' => $status,
        ], Auth::id());

        flash('success', 'Peça cadastrada.');
        redirect('/estoque/pecas/' . $novoId);
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Peça não encontrada', 'page' => 'estoque']);
    }
}

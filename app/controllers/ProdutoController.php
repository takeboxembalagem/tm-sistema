<?php

declare(strict_types=1);

final class ProdutoController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'categoria_id' => (int) ($_GET['categoria_id'] ?? 0),
            'colecao_id' => (int) ($_GET['colecao_id'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
        ];

        $result = Produto::paginate($filters, (int) ($_GET['page'] ?? 1), 20);

        View::render('produtos/index', [
            'title' => 'Produtos',
            'page' => 'produtos',
            'produtos' => $result['items'],
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'categorias' => Produto::categoriasAtivas(),
            'colecoes' => Produto::colecoesAtivas(),
            'success' => flash('success'),
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
        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        $totais = Composicao::totais($id);
        $qtdProd = (int) ($totais['pecas'] ?? $produto['quantidade_producao'] ?? 1000);
        $preco = PrecoFormacao::analisar($produto, (float) $totais['custo_unitario']);

        $variacoes = Variacao::listarPorProduto($id);
        $custoProduto = (float) $totais['custo_unitario'];
        foreach ($variacoes as &$v) {
            $modo = (($v['custo_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
            $v['custo_modo_resolvido'] = $modo;
            $v['custo_unitario_exibicao'] = $modo === 'proprio'
                ? (float) Composicao::totaisVariacao((int) $v['id'], $produto)['custo_unitario']
                : $custoProduto;
        }
        unset($v);

        View::render('produtos/show', [
            'title' => $produto['nome'],
            'page' => 'produtos',
            'produto' => $produto,
            'composicao' => Composicao::listarPorProduto($id, $produto),
            'custoTotal' => $totais['custo_unitario'],
            'custoLote' => $totais['custo_lote'],
            'custoUnitario' => $totais['custo_unitario'],
            'quantidadeProducao' => (int) ($produto['quantidade_producao'] ?? 1000),
            'producao' => $totais,
            'preco' => $preco,
            'variacoes' => $variacoes,
            'estoqueTotal' => Variacao::estoqueTotal($id),
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function duplicar(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        try {
            $novoId = Produto::duplicar($id);
        } catch (Throwable $e) {
            flash('errors', ['Não foi possível duplicar o produto. Tente novamente.']);
            redirect('/produtos/' . $id);
        }

        flash(
            'success',
            'Produto duplicado com sucesso. Arquivos, variações e estoque não foram copiados — edite código, nome e demais dados da cópia.'
        );
        redirect('/produtos/' . $novoId . '/editar');
    }

    public static function edit(int $id): void
    {
        Auth::requireLogin();
        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        self::form($produto);
    }

    public static function update(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        self::save($produto);
    }

    public static function toggle(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($id);
        if (!$produto) {
            redirect('/produtos');
        }

        $novo = $produto['status'] === 'ativo' ? 'inativo' : 'ativo';
        Produto::setStatus($id, $novo);
        flash('success', $novo === 'inativo' ? 'Produto desativado.' : 'Produto ativado.');
        redirect('/produtos');
    }

    public static function confirmDelete(int $id): void
    {
        Auth::requireLogin();
        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        View::render('produtos/confirm_delete', [
            'title' => 'Excluir produto',
            'page' => 'produtos',
            'produto' => $produto,
            'variacoesCount' => Produto::contarVariacoes($id),
            'arquivosCount' => Produto::contarArquivos($id),
            'pecasEstoqueCount' => Produto::contarPecasEstoque($id),
            'resultadosCount' => Produto::contarResultadosComerciais($id),
            'vinculosSemResultado' => Produto::contarVinculosColecaoSemResultado($id),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function destroy(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $produto = Produto::find($id);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        $codigo = (string) $produto['codigo_interno'];
        $nome = (string) $produto['nome'];

        $resultado = Produto::excluirSeguro($id);
        if (!$resultado['ok']) {
            if ($resultado['motivo'] === 'nao_encontrado') {
                http_response_code(404);
                View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
                return;
            }
            if ($resultado['motivo'] === 'arquivos') {
                $n = Produto::contarArquivos($id);
                flash('errors', [
                    'Este produto possui ' . $n . ' arquivo(s) técnico(s) vinculado(s). '
                    . 'Remova ou desvincule os arquivos antes de excluir.',
                ]);
            } elseif ($resultado['motivo'] === 'estoque') {
                $n = Produto::contarPecasEstoque($id);
                flash('errors', [
                    'Este produto está vinculado a ' . $n . ' peça(s) do estoque físico. '
                    . 'Desvincule as peças (ou exclua-as no estoque) antes de excluir o produto. '
                    . 'Saldos e movimentações não são alterados automaticamente.',
                ]);
            } else {
                $n = Produto::contarResultadosComerciais($id);
                flash('errors', [
                    'Este produto possui resultados comerciais registrados em ' . $n
                    . ' vínculo(s) de coleção e não pode ser excluído. '
                    . 'Para retirá-lo de uso, desative-o.',
                ]);
            }
            redirect('/produtos/' . $id . '/excluir');
        }

        // Só após o commit: e apenas se a foto for exclusiva deste produto.
        Upload::removerImagemPublica($resultado['foto']);

        $msg = 'Produto "' . $codigo . ' — ' . $nome . '" excluído permanentemente.';
        if ($resultado['variacoes'] > 0) {
            $msg .= ' ' . $resultado['variacoes'] . ' variação(ões) removida(s) junto com o produto.';
        }
        flash('success', $msg);
        redirect('/produtos');
    }

    private static function form(?array $produto): void
    {
        $incluirColecao = $produto ? (int) ($produto['colecao_id'] ?? 0) : (int) ($_GET['colecao_id'] ?? 0);
        $old = flash('old') ?? [];
        if (!$produto && empty($old) && $incluirColecao > 0) {
            $old['colecao_id'] = (string) $incluirColecao;
        }

        View::render('produtos/form', [
            'title' => $produto ? 'Editar Produto' : 'Novo Produto',
            'page' => 'produtos',
            'produto' => $produto,
            'categorias' => Produto::categoriasAtivas(),
            'colecoes' => Produto::colecoesParaSelect($incluirColecao > 0 ? $incluirColecao : null),
            'errors' => flash('errors') ?? [],
            'old' => $old,
            'success' => flash('success'),
        ]);
    }

    private static function save(?array $existing): void
    {
        $id = $existing ? (int) $existing['id'] : null;
        $codigo = trim((string) ($_POST['codigo_interno'] ?? ''));
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $tamanho = trim((string) ($_POST['tamanho'] ?? ''));
        $tamanhoAberta = trim((string) ($_POST['tamanho_aberta'] ?? ''));
        $descricao = trim((string) ($_POST['descricao'] ?? ''));
        $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
        $colecaoId = (int) ($_POST['colecao_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'ativo');
        $qtdPacoteRaw = trim((string) ($_POST['quantidade_pacote'] ?? ''));
        $quantidadePacote = parse_quantidade_pacote($qtdPacoteRaw);
        $pesoPacoteRaw = trim((string) ($_POST['peso_pacote_kg'] ?? ''));
        $pesoPacoteKg = null;
        if ($pesoPacoteRaw !== '') {
            $pesoPacoteKg = parse_money_br($pesoPacoteRaw);
        }

        $errors = [];
        if ($codigo === '') {
            $errors[] = 'Informe o código do produto.';
        } elseif (mb_strlen($codigo) > 40) {
            $errors[] = 'O código deve ter no máximo 40 caracteres.';
        } elseif (Produto::codigoExiste($codigo, $id)) {
            $errors[] = 'Este código de produto já está cadastrado.';
        }

        if ($nome === '') {
            $errors[] = 'Informe o nome do produto.';
        } elseif (mb_strlen($nome) > 180) {
            $errors[] = 'O nome deve ter no máximo 180 caracteres.';
        }

        if (mb_strlen($tamanho) > 80) {
            $errors[] = 'O tamanho montada deve ter no máximo 80 caracteres.';
        }

        if (mb_strlen($tamanhoAberta) > 80) {
            $errors[] = 'O tamanho aberta deve ter no máximo 80 caracteres.';
        }

        if ($quantidadePacote === false) {
            $errors[] = 'Quantidade por pacote deve ser um número inteiro positivo (ou deixe em branco).';
            $quantidadePacote = null;
        }

        if ($pesoPacoteRaw !== '' && ($pesoPacoteKg === null || $pesoPacoteKg < 0)) {
            $errors[] = 'Informe um peso do pacote válido em kg (ou deixe em branco).';
            $pesoPacoteKg = null;
        }

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }

        if ($existing === null) {
            $status = 'ativo';
        }

        $categoriaId = self::idValido($categoriaId, Produto::categoriasAtivas()) ?: null;

        // Aceita coleção ativa; em edição, mantém a atual mesmo se inativa.
        $colecaoAtual = $existing ? (int) ($existing['colecao_id'] ?? 0) : 0;
        if ($colecaoId > 0) {
            $opcoes = Produto::colecoesParaSelect($colecaoAtual > 0 ? $colecaoAtual : null);
            $colecaoId = self::idValido($colecaoId, $opcoes) ?: null;
        } else {
            $colecaoId = null;
        }

        $upload = Upload::produtoImagem($_FILES['foto'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
        if (!$upload['ok']) {
            $errors[] = $upload['error'] ?? 'Falha no envio da imagem.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'codigo_interno' => $codigo,
                'nome' => $nome,
                'tamanho' => $tamanho,
                'tamanho_aberta' => $tamanhoAberta,
                'descricao' => $descricao,
                'categoria_id' => (string) ($categoriaId ?? ''),
                'colecao_id' => (string) ($colecaoId ?? ''),
                'status' => $status,
                'quantidade_pacote' => $qtdPacoteRaw,
                'peso_pacote_kg' => $pesoPacoteRaw,
            ]);
            redirect($id ? '/produtos/' . $id . '/editar' : '/produtos/novo');
        }

        $foto = $existing['foto_principal'] ?? null;
        if ($upload['path']) {
            $foto = $upload['path'];
        }

        $payload = [
            'codigo_interno' => $codigo,
            'nome' => $nome,
            'tamanho' => $tamanho !== '' ? $tamanho : null,
            'tamanho_aberta' => $tamanhoAberta !== '' ? $tamanhoAberta : null,
            'descricao' => $descricao !== '' ? $descricao : null,
            'categoria_id' => $categoriaId,
            'colecao_id' => $colecaoId,
            'foto_principal' => $foto,
            'status' => $status,
            'quantidade_pacote' => $quantidadePacote,
            'peso_pacote_kg' => $pesoPacoteKg,
        ];

        if ($id) {
            Produto::update($id, $payload);
            flash('success', 'Produto atualizado com sucesso.');
        } else {
            Produto::create($payload);
            flash('success', 'Produto cadastrado com sucesso.');
        }

        redirect('/produtos');
    }

    private static function idValido(int $id, array $lista): int
    {
        if ($id <= 0) {
            return 0;
        }
        foreach ($lista as $item) {
            if ((int) $item['id'] === $id) {
                return $id;
            }
        }
        return 0;
    }
}

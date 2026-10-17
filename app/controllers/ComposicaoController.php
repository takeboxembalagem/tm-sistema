<?php

declare(strict_types=1);

final class ComposicaoController
{
    public static function escolher(int $produtoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        View::render('produtos/composicao_escolha', [
            'title' => 'Adicionar custo',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => null,
            'voltarUrl' => url('/produtos/' . $produtoId . '#composicao'),
            'urlCadastrado' => url('/produtos/' . $produtoId . '/composicao/cadastrado/novo'),
            'urlManual' => url('/produtos/' . $produtoId . '/composicao/manual/novo'),
        ]);
    }

    public static function create(int $produtoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        self::form($produto, null);
    }

    public static function createManual(int $produtoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        self::formManual($produto, null);
    }

    public static function store(int $produtoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        self::save($produto, null);
    }

    public static function storeManual(int $produtoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }
        self::saveManual($produto, null);
    }

    public static function edit(int $produtoId, int $composicaoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        $item = Composicao::find($composicaoId);
        if (!$produto || !$item || (int) $item['produto_id'] !== $produtoId) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Componente não encontrado', 'page' => 'produtos']);
            return;
        }
        if (composicao_eh_manual($item)) {
            self::formManual($produto, $item);
            return;
        }
        self::form($produto, $item);
    }

    public static function update(int $produtoId, int $composicaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        $item = Composicao::find($composicaoId);
        if (!$produto || !$item || (int) $item['produto_id'] !== $produtoId) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Componente não encontrado', 'page' => 'produtos']);
            return;
        }
        if (composicao_eh_manual($item)) {
            self::saveManual($produto, $item);
            return;
        }
        self::save($produto, $item);
    }

    public static function destroy(int $produtoId, int $composicaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        $item = Composicao::find($composicaoId);
        if (!$produto || !$item || (int) $item['produto_id'] !== $produtoId) {
            redirect('/produtos/' . $produtoId);
        }

        Composicao::delete($composicaoId);
        flash('success', 'Custo removido da formação.');
        redirect('/produtos/' . $produtoId . '#composicao');
    }

    public static function updateTiragem(int $produtoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            redirect('/produtos');
        }

        $rawFolhas = trim((string) ($_POST['quantidade_producao'] ?? ''));
        $rawRend = trim((string) ($_POST['rendimento_folha'] ?? ''));
        $folhas = parse_money_br($rawFolhas);
        $rend = parse_money_br($rawRend);
        $errors = [];

        if ($folhas === null || $folhas <= 0 || floor($folhas) != $folhas) {
            $errors[] = 'Informe a quantidade de folhas (inteiro maior que zero).';
        }
        if ($rend === null || $rend <= 0 || floor($rend) != $rend) {
            $errors[] = 'Informe as peças por folha (inteiro maior que zero).';
        }

        $largura = null;
        $altura = null;
        $larguraRaw = trim((string) ($_POST['folha_largura'] ?? ''));
        $alturaRaw = trim((string) ($_POST['folha_altura'] ?? ''));
        if ($larguraRaw !== '') {
            $largura = parse_money_br($larguraRaw);
            if ($largura === null || $largura <= 0) {
                $errors[] = 'Informe a largura da folha válida.';
            }
        }
        if ($alturaRaw !== '') {
            $altura = parse_money_br($alturaRaw);
            if ($altura === null || $altura <= 0) {
                $errors[] = 'Informe a altura da folha válida.';
            }
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/produtos/' . $produtoId . '#composicao');
        }

        Produto::setProducaoReferencia($produtoId, [
            'quantidade_producao' => (int) $folhas,
            'rendimento_folha' => (int) $rend,
            'folha_largura' => $largura,
            'folha_altura' => $altura,
        ]);

        // Quantidades dos custos na formação (independentes das folhas).
        $qtds = $_POST['qtd_custo'] ?? [];
        $pendentes = [];
        if (is_array($qtds) && $qtds !== []) {
            $linhas = Composicao::listarPorProduto($produtoId);
            $idsOk = [];
            foreach ($linhas as $linha) {
                $idsOk[(int) $linha['id']] = true;
            }
            foreach ($qtds as $compId => $rawQtd) {
                $compId = (int) $compId;
                if ($compId <= 0 || empty($idsOk[$compId])) {
                    continue;
                }
                $q = parse_money_br(trim((string) $rawQtd));
                if ($q === null || $q <= 0) {
                    $errors[] = 'Quantidade inválida em um dos custos (informe um valor maior que zero).';
                    continue;
                }
                $pendentes[$compId] = (float) $q;
            }
            if ($errors) {
                flash('errors', $errors);
                redirect('/produtos/' . $produtoId . '#composicao');
            }
            foreach ($pendentes as $compId => $q) {
                Composicao::updateQuantidade($compId, $q);
            }
        }

        flash('success', 'Formação atualizada. Custos recalculados.');
        redirect('/produtos/' . $produtoId . '#composicao');
    }

    private static function form(array $produto, ?array $item): void
    {
        $exceptMaterial = $item ? (int) $item['material_id'] : null;
        View::render('produtos/composicao_form', [
            'title' => $item ? 'Trocar custo' : 'Adicionar custo cadastrado',
            'page' => 'produtos',
            'produto' => $produto,
            'item' => $item,
            'materiais' => Composicao::materiaisAtivosDisponiveis((int) $produto['id'], $exceptMaterial),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    private static function formManual(array $produto, ?array $item): void
    {
        View::render('produtos/composicao_manual_form', [
            'title' => $item ? 'Editar custo manual' : 'Adicionar custo manual',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => null,
            'item' => $item,
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
            'action' => $item
                ? url('/produtos/' . $produto['id'] . '/composicao/' . $item['id'])
                : url('/produtos/' . $produto['id'] . '/composicao/manual'),
            'voltarUrl' => url('/produtos/' . $produto['id'] . '#composicao'),
        ]);
    }

    private static function save(array $produto, ?array $existing): void
    {
        $produtoId = (int) $produto['id'];
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $quantidadeRaw = trim((string) ($_POST['quantidade'] ?? '1'));
        $errors = [];

        $quantidade = parse_money_br($quantidadeRaw !== '' ? $quantidadeRaw : '1');
        if ($quantidade === null || $quantidade <= 0) {
            $errors[] = 'Informe a quantidade do custo (maior que zero).';
            $quantidade = 1.0;
        }

        $material = null;
        if ($materialId > 0) {
            $stmt = Database::pdo()->prepare(
                'SELECT id, nome, custo_atual, status, forma_cobranca FROM materiais WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $materialId]);
            $material = $stmt->fetch() ?: null;
        }

        if (!$material) {
            $errors[] = 'Selecione um custo cadastrado.';
        } elseif ($material['status'] !== 'ativo'
            && !($existing && (int) $existing['material_id'] === $materialId)) {
            // Inativo só permanece em vínculo já existente; nunca entra como novo.
            $errors[] = 'Não é possível usar um custo inativo.';
        } elseif (Composicao::existeMaterial($produtoId, $materialId, $existing ? (int) $existing['id'] : null)) {
            $errors[] = 'Este custo já está na formação deste produto.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'material_id' => (string) $materialId,
                'quantidade' => $quantidadeRaw,
            ]);
            redirect($existing
                ? '/produtos/' . $produtoId . '/composicao/' . $existing['id'] . '/editar'
                : '/produtos/' . $produtoId . '/composicao/cadastrado/novo');
        }

        $modoDb = (string) $material['forma_cobranca'];
        if (!array_key_exists($modoDb, formas_cobranca()) || $modoDb === 'manual') {
            $modoDb = 'unidade';
        }

        if ($existing) {
            Composicao::update((int) $existing['id'], $materialId, $modoDb, (float) $quantidade);
            flash('success', 'Custo atualizado na formação.');
        } else {
            Composicao::create($produtoId, $materialId, $modoDb, (float) $quantidade);
            flash('success', 'Custo adicionado à formação.');
        }

        redirect('/produtos/' . $produtoId . '#composicao');
    }

    private static function saveManual(array $produto, ?array $existing): void
    {
        $produtoId = (int) $produto['id'];
        $descricao = trim((string) ($_POST['descricao'] ?? ''));
        $precoRaw = trim((string) ($_POST['preco'] ?? ''));
        $quantidadeRaw = trim((string) ($_POST['quantidade'] ?? '1'));
        $errors = [];

        if ($descricao === '') {
            $errors[] = 'Informe a descrição do custo manual.';
        } elseif (mb_strlen($descricao) > 160) {
            $errors[] = 'A descrição deve ter no máximo 160 caracteres.';
        }

        $preco = parse_money_br($precoRaw);
        if ($preco === null || $preco < 0) {
            $errors[] = 'Informe o preço unitário (zero ou maior).';
            $preco = 0.0;
        }

        $quantidade = parse_money_br($quantidadeRaw !== '' ? $quantidadeRaw : '1');
        if ($quantidade === null || $quantidade <= 0) {
            $errors[] = 'Informe a quantidade (maior que zero).';
            $quantidade = 1.0;
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'descricao' => $descricao,
                'preco' => $precoRaw,
                'quantidade' => $quantidadeRaw,
            ]);
            redirect($existing
                ? '/produtos/' . $produtoId . '/composicao/' . $existing['id'] . '/editar'
                : '/produtos/' . $produtoId . '/composicao/manual/novo');
        }

        if ($existing) {
            Composicao::updateManual((int) $existing['id'], $descricao, (float) $preco, (float) $quantidade);
            flash('success', 'Custo manual atualizado.');
        } else {
            Composicao::createManual($produtoId, $descricao, (float) $preco, (float) $quantidade);
            flash('success', 'Custo manual adicionado à formação.');
        }

        redirect('/produtos/' . $produtoId . '#composicao');
    }
}

<?php

declare(strict_types=1);

final class VariacaoController
{
    private const ROTULO_GTIN_UNIDADE = 'GTIN da unidade individual';
    private const ROTULO_GTIN_PACOTE = 'GTIN do pacote';

    public static function create(int $produtoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            self::produtoNotFound();
            return;
        }
        self::form($produto, null);
    }

    public static function store(int $produtoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        if (!$produto) {
            self::produtoNotFound();
            return;
        }
        self::save($produto, null);
    }

    public static function edit(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            return;
        }
        self::form($produto, $variacao);
    }

    public static function update(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            return;
        }
        self::save($produto, $variacao);
    }

    public static function destroy(int $produtoId, int $variacaoId): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            return;
        }

        $nArq = Variacao::contarArquivos($variacaoId);
        if ($nArq > 0) {
            flash(
                'errors',
                ['Esta variação possui ' . $nArq . ' arquivo(s) vinculado(s). Remova ou desvincule os arquivos antes de excluir.']
            );
            redirect('/produtos/' . $produtoId . '#variacoes');
        }

        $nVinculos = count(Colecao::idsDaVariacao($variacaoId));
        if (!Variacao::excluirSemHistoricoComercial($variacaoId)) {
            $nResultados = Colecao::contarResultadosComerciaisDaVariacao($variacaoId);
            if ($nResultados === 0) {
                self::variacaoNotFound();
                return;
            }
            flash('errors', [
                'Esta variação possui resultados registrados em ' . $nResultados
                . ' coleção(ões) e não pode ser excluída. Para retirá-la de uso, inative-a.',
            ]);
            redirect('/produtos/' . $produtoId . '#variacoes');
        }

        flash(
            'success',
            $nVinculos > 0
                ? 'Variação excluída. Ela foi retirada de ' . $nVinculos . ' coleção(ões) sem resultados registrados.'
                : 'Variação excluída.'
        );
        redirect('/produtos/' . $produtoId . '#variacoes');
    }

    public static function adjustForm(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            return;
        }

        View::render('produtos/variacao_estoque', [
            'title' => 'Ajustar estoque',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => $variacao,
            'motivos' => Variacao::MOTIVOS_AJUSTE,
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    public static function adjustStore(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            return;
        }

        $raw = trim((string) ($_POST['estoque'] ?? ''));
        $motivo = (string) ($_POST['motivo'] ?? '');
        $errors = [];

        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            $errors[] = 'Informe a nova quantidade de estoque (número inteiro).';
        } else {
            $estoque = (int) $raw;
            if ($estoque < 0) {
                $errors[] = 'O estoque não pode ser negativo.';
            }
        }

        if (!isset(Variacao::MOTIVOS_AJUSTE[$motivo])) {
            $errors[] = 'Selecione o motivo do ajuste.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', ['estoque' => $raw, 'motivo' => $motivo]);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/estoque');
        }

        Variacao::setEstoque($variacaoId, (int) $raw);
        flash('success', 'Estoque da variação "' . $variacao['nome'] . '" atualizado para ' . (int) $raw . '.');
        redirect('/produtos/' . $produtoId . '#variacoes');
    }

    /**
     * Altera somente custo_modo (herdar/proprio), copia composição se necessário e permanece na formação.
     */
    public static function updateCustoModo(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);

        $modo = (string) ($_POST['custo_modo'] ?? 'herdar');
        if ($modo !== 'proprio') {
            $modo = 'herdar';
        }

        Variacao::setCustoModo($variacaoId, $modo);

        if ($modo === 'proprio' && Composicao::contarLinhasVariacao($variacaoId) === 0) {
            $n = Composicao::copiarDeProdutoParaVariacao($produtoId, $variacaoId);
            flash(
                'success',
                $n > 0
                    ? 'Custo personalizado ativado. Composição do produto copiada como ponto de partida.'
                    : 'Custo personalizado ativado. Adicione custos à variação.'
            );
        } elseif ($modo === 'proprio') {
            flash('success', 'Custo personalizado ativo. A composição própria desta variação está disponível abaixo.');
        } else {
            flash('success', 'Variação voltou a herdar o custo do produto. A composição própria foi mantida (não utilizada).');
        }

        redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
    }

    /** Recalcula quantidades da composição própria da variação. */
    public static function updateComposicaoQuantidades(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);

        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” para alterar a composição da variação.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }

        $qtds = $_POST['qtd_custo'] ?? [];
        $errors = [];
        $pendentes = [];
        if (is_array($qtds)) {
            $linhas = Composicao::listarPorVariacao($variacaoId, $produto);
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
                    $errors[] = 'Quantidade inválida em um dos custos.';
                    continue;
                }
                $pendentes[$compId] = (float) $q;
            }
        }
        if ($errors) {
            flash('errors', $errors);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }
        foreach ($pendentes as $compId => $q) {
            Composicao::updateQuantidadeVariacao($compId, $q);
        }
        flash('success', 'Composição da variação recalculada.');
        redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
    }

    public static function composicaoEscolher(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” antes de adicionar custos.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }
        View::render('produtos/composicao_escolha', [
            'title' => 'Adicionar custo',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => $variacao,
            'voltarUrl' => url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo'),
            'urlCadastrado' => url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/cadastrado/novo'),
            'urlManual' => url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/manual/novo'),
        ]);
    }

    public static function composicaoCreate(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” antes de adicionar custos.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }
        self::composicaoForm($produto, $variacao, null);
    }

    public static function composicaoCreateManual(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” antes de adicionar custos.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }
        self::composicaoFormManual($produto, $variacao, null);
    }

    public static function composicaoStore(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        self::composicaoSave($produto, $variacao, null);
    }

    public static function composicaoStoreManual(int $produtoId, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        self::composicaoSaveManual($produto, $variacao, null);
    }

    public static function composicaoEdit(int $produtoId, int $variacaoId, int $linhaId): void
    {
        Auth::requireLogin();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        $item = Composicao::findVariacaoLinha($linhaId);
        if (!$item || (int) $item['variacao_id'] !== $variacaoId) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Item não encontrado', 'page' => 'produtos']);
            return;
        }
        if (composicao_eh_manual($item)) {
            self::composicaoFormManual($produto, $variacao, $item);
            return;
        }
        self::composicaoForm($produto, $variacao, $item);
    }

    public static function composicaoUpdate(int $produtoId, int $variacaoId, int $linhaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [$produto, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        $item = Composicao::findVariacaoLinha($linhaId);
        if (!$item || (int) $item['variacao_id'] !== $variacaoId) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Item não encontrado', 'page' => 'produtos']);
            return;
        }
        if (composicao_eh_manual($item)) {
            self::composicaoSaveManual($produto, $variacao, $item);
            return;
        }
        self::composicaoSave($produto, $variacao, $item);
    }

    public static function composicaoDestroy(int $produtoId, int $variacaoId, int $linhaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        [, $variacao] = self::requireVariacaoDoProduto($produtoId, $variacaoId);
        $item = Composicao::findVariacaoLinha($linhaId);
        if (!$item || (int) $item['variacao_id'] !== $variacaoId) {
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }
        Composicao::deleteVariacao($linhaId);
        flash('success', 'Custo removido da variação.');
        redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
    }

    /**
     * @return array{0:array,1:array}
     */
    private static function requireVariacaoDoProduto(int $produtoId, int $variacaoId): array
    {
        $produto = Produto::find($produtoId);
        $variacao = Variacao::find($variacaoId);
        if (!$produto || !$variacao || (int) $variacao['produto_id'] !== $produtoId) {
            self::variacaoNotFound();
            exit;
        }
        return [$produto, $variacao];
    }

    private static function form(array $produto, ?array $existing): void
    {
        $old = flash('old') ?? [];
        $sugestaoCodigo = '';
        if (!$existing && empty($old['codigo'])) {
            $sugestaoCodigo = (string) $produto['codigo_interno'] . '-';
        }

        $custoProduto = Composicao::custoParaTiragem((int) $produto['id'], $produto);
        $composicaoVar = [];
        $totaisVar = null;
        $modoCusto = 'herdar';
        if ($existing) {
            $modoCusto = (($existing['custo_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
            if ($modoCusto === 'proprio') {
                $composicaoVar = Composicao::listarPorVariacao((int) $existing['id'], $produto);
                $totaisVar = Composicao::totaisVariacao((int) $existing['id'], $produto);
            }
        }

        View::render('produtos/variacao_form', [
            'title' => $existing ? 'Editar variação' : 'Nova variação',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => $existing,
            'errors' => flash('errors') ?? [],
            'old' => $old,
            'sugestaoCodigo' => $sugestaoCodigo,
            'custoProdutoUnitario' => (float) $custoProduto['custo_unitario'],
            'producao' => Composicao::resolverProducao($produto),
            'composicaoVar' => $composicaoVar,
            'totaisVar' => $totaisVar,
            'modoCusto' => $modoCusto,
            'colecoesOpcoes' => Colecao::opcoesParaVariacao($existing ? (int) $existing['id'] : null),
            'colecoesAtuais' => $existing ? Colecao::idsDaVariacao((int) $existing['id']) : [],
            'success' => flash('success'),
        ]);
    }

    private static function save(array $produto, ?array $existing): void
    {
        $produtoId = (int) $produto['id'];
        $id = $existing ? (int) $existing['id'] : null;
        $codigo = trim((string) ($_POST['codigo'] ?? ''));
        $nome = trim((string) ($_POST['nome'] ?? ''));
        // Na edição o estoque é ignorado: o saldo só muda pelo ajuste de estoque.
        $estoqueRaw = $existing ? '' : trim((string) ($_POST['estoque'] ?? '0'));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        // Em edição, custo_modo só muda pela ação dedicada (updateCustoModo).
        if ($existing) {
            $custoModo = (($existing['custo_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
        } else {
            $custoModo = (string) ($_POST['custo_modo'] ?? 'herdar');
            if ($custoModo !== 'proprio') {
                $custoModo = 'herdar';
            }
        }

        $pacoteModo = (string) ($_POST['pacote_modo'] ?? 'herdar');
        if ($pacoteModo !== 'proprio') {
            $pacoteModo = 'herdar';
        }
        $qtdPacoteRaw = trim((string) ($_POST['quantidade_pacote'] ?? ''));
        $quantidadePacote = null;
        if ($pacoteModo === 'proprio') {
            $quantidadePacote = parse_quantidade_pacote($qtdPacoteRaw);
        }

        $errors = [];

        if ($nome === '') {
            $errors[] = 'Informe o nome da variação.';
        } elseif (mb_strlen($nome) > 120) {
            $errors[] = 'O nome deve ter no máximo 120 caracteres.';
        }

        if ($codigo === '') {
            $errors[] = 'Informe o código da variação.';
        } elseif (mb_strlen($codigo) > 40) {
            $errors[] = 'O código deve ter no máximo 40 caracteres.';
        } elseif (Variacao::codigoExiste($codigo, $id)) {
            $errors[] = 'Este código já está em uso por outra variação ou produto.';
        }

        $estoque = 0;
        if (!$existing) {
            if ($estoqueRaw === '' || !preg_match('/^-?\d+$/', $estoqueRaw)) {
                $errors[] = 'Informe o estoque (número inteiro).';
            } else {
                $estoque = (int) $estoqueRaw;
                if ($estoque < 0) {
                    $errors[] = 'O estoque não pode ser negativo.';
                }
            }
        }

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }

        if (mb_strlen($observacao) > 2000) {
            $errors[] = 'A observação deve ter no máximo 2000 caracteres.';
        }

        $gtinUnidadeRaw = trim((string) ($_POST['gtin_unidade'] ?? ''));
        $gtinPacoteRaw = trim((string) ($_POST['gtin_pacote'] ?? ''));
        [$gtinUnidade, $erroGtin] = parse_gtin($gtinUnidadeRaw, self::ROTULO_GTIN_UNIDADE);
        if ($erroGtin !== null) {
            $errors[] = $erroGtin;
        }
        [$gtinPacote, $erroGtin] = parse_gtin($gtinPacoteRaw, self::ROTULO_GTIN_PACOTE);
        if ($erroGtin !== null) {
            $errors[] = $erroGtin;
        }
        if ($gtinUnidade !== null && $gtinUnidade === $gtinPacote) {
            $errors[] = self::ROTULO_GTIN_PACOTE . ': deve ser diferente do ' . self::ROTULO_GTIN_UNIDADE . ' desta variação.';
        } else {
            foreach ([self::ROTULO_GTIN_UNIDADE => $gtinUnidade, self::ROTULO_GTIN_PACOTE => $gtinPacote] as $rotulo => $gtin) {
                $dono = $gtin !== null ? Variacao::gtinEmUso($gtin, $id) : null;
                if ($dono !== null) {
                    $errors[] = $rotulo . ': o código ' . $gtin . ' já está em uso na variação "'
                        . $dono['codigo'] . ' — ' . $dono['nome'] . '".';
                }
            }
        }

        if ($pacoteModo === 'proprio' && $quantidadePacote === false) {
            $errors[] = 'Quantidade por pacote deve ser um número inteiro positivo (ou deixe em branco).';
            $quantidadePacote = null;
        }

        // Sem o marcador, o formulário não trouxe a seção de coleções: os vínculos ficam como estão.
        $colecoesEnviadas = isset($_POST['colecoes_enviadas']);
        $colecaoIds = [];
        if ($colecoesEnviadas) {
            $permitidas = array_map(
                static fn (array $c): int => (int) $c['id'],
                Colecao::opcoesParaVariacao($id)
            );
            $recebidas = $_POST['colecoes'] ?? [];
            $colecoesValidas = is_array($recebidas);
            foreach ($colecoesValidas ? $recebidas : [] as $raw) {
                $cid = (is_string($raw) && preg_match('/^[1-9]\d*$/', $raw)) ? (int) $raw : 0;
                if ($cid === 0 || !in_array($cid, $permitidas, true)) {
                    $colecoesValidas = false;
                    break;
                }
                $colecaoIds[] = $cid;
            }
            if (!$colecoesValidas) {
                $errors[] = 'Selecione apenas coleções válidas.';
                $colecaoIds = [];
            }
            $colecaoIds = array_values(array_unique($colecaoIds));

            if ($id && $colecoesValidas) {
                foreach (Colecao::colecoesComResultadoDaVariacao($id) as $c) {
                    if (!in_array((int) $c['id'], $colecaoIds, true)) {
                        $errors[] = 'A variação possui resultados registrados na coleção "'
                            . $c['nome'] . '" e não pode ser removida dela.';
                    }
                }
            }
        }

        $old = [
            'codigo' => $codigo,
            'nome' => $nome,
            'status' => $status,
            'observacao' => $observacao,
            'custo_modo' => $custoModo,
            'pacote_modo' => $pacoteModo,
            'quantidade_pacote' => $qtdPacoteRaw,
            'gtin_unidade' => $gtinUnidadeRaw,
            'gtin_pacote' => $gtinPacoteRaw,
            'colecoes' => $colecaoIds,
        ];
        if (!$existing) {
            $old['estoque'] = $estoqueRaw;
        }
        $urlForm = $id
            ? '/produtos/' . $produtoId . '/variacoes/' . $id . '/editar'
            : '/produtos/' . $produtoId . '/variacoes/novo';

        if ($errors) {
            flash('errors', $errors);
            flash('old', $old);
            redirect($urlForm);
        }

        $payload = [
            'produto_id' => $produtoId,
            'codigo' => $codigo,
            'nome' => $nome,
            'status' => $status,
            'observacao' => $observacao !== '' ? $observacao : null,
            'custo_modo' => $custoModo,
            'pacote_modo' => $pacoteModo,
            'quantidade_pacote' => $pacoteModo === 'proprio' ? $quantidadePacote : null,
            'gtin_unidade' => $gtinUnidade,
            'gtin_pacote' => $gtinPacote,
        ];
        if (!$id) {
            $payload['estoque'] = $estoque;
        }

        if ($id) {
            try {
                Variacao::update($id, $payload);
            } catch (PDOException $e) {
                self::rejeitarGtinDuplicado($e, $old, $urlForm);
            }
            if ($colecoesEnviadas) {
                try {
                    Colecao::sincronizarColecoesDaVariacao($id, $colecaoIds);
                } catch (DomainException) {
                    flash('errors', [
                        'Os dados da variação foram salvos, mas as coleções não foram alteradas: '
                        . 'há resultados registrados em uma coleção desmarcada.',
                    ]);
                    redirect('/produtos/' . $produtoId . '/variacoes/' . $id . '/editar');
                }
            }
            flash('success', 'Variação atualizada.');
            redirect('/produtos/' . $produtoId . '/variacoes/' . $id . '/editar');
        }

        try {
            $novoId = Variacao::create($payload);
        } catch (PDOException $e) {
            self::rejeitarGtinDuplicado($e, $old, $urlForm);
        }
        if ($colecoesEnviadas && $colecaoIds) {
            Colecao::sincronizarColecoesDaVariacao($novoId, $colecaoIds);
        }
        if ($custoModo === 'proprio') {
            Composicao::copiarDeProdutoParaVariacao($produtoId, $novoId);
            flash('success', 'Variação cadastrada com custo personalizado (cópia da composição do produto).');
            redirect('/produtos/' . $produtoId . '/variacoes/' . $novoId . '/editar#custo');
        }
        flash('success', 'Variação cadastrada.');
        redirect('/produtos/' . $produtoId . '#variacoes');
    }

    private static function composicaoForm(array $produto, array $variacao, ?array $item): void
    {
        $except = $item ? (int) $item['material_id'] : null;
        View::render('produtos/variacao_composicao_form', [
            'title' => $item ? 'Trocar custo da variação' : 'Adicionar custo cadastrado',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => $variacao,
            'item' => $item,
            'materiais' => Composicao::materiaisAtivosDisponiveisVariacao((int) $variacao['id'], $except),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    private static function composicaoFormManual(array $produto, array $variacao, ?array $item): void
    {
        $produtoId = (int) $produto['id'];
        $variacaoId = (int) $variacao['id'];
        View::render('produtos/composicao_manual_form', [
            'title' => $item ? 'Editar custo manual' : 'Adicionar custo manual',
            'page' => 'produtos',
            'produto' => $produto,
            'variacao' => $variacao,
            'item' => $item,
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
            'action' => $item
                ? url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/' . $item['id'])
                : url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/manual'),
            'voltarUrl' => url('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo'),
        ]);
    }

    private static function composicaoSave(array $produto, array $variacao, ?array $existing): void
    {
        $produtoId = (int) $produto['id'];
        $variacaoId = (int) $variacao['id'];

        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” para editar a composição.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }

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
        } elseif (Composicao::existeMaterialVariacao($variacaoId, $materialId, $existing ? (int) $existing['id'] : null)) {
            $errors[] = 'Este custo já está na composição desta variação.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', ['material_id' => (string) $materialId, 'quantidade' => $quantidadeRaw]);
            redirect($existing
                ? '/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/' . $existing['id'] . '/editar'
                : '/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/cadastrado/novo');
        }

        $modoDb = (string) $material['forma_cobranca'];
        if (!array_key_exists($modoDb, formas_cobranca()) || $modoDb === 'manual') {
            $modoDb = 'unidade';
        }

        if ($existing) {
            Composicao::updateVariacao((int) $existing['id'], $materialId, $modoDb, (float) $quantidade);
            flash('success', 'Custo da variação atualizado.');
        } else {
            Composicao::createVariacao($variacaoId, $materialId, $modoDb, (float) $quantidade);
            flash('success', 'Custo adicionado à variação.');
        }
        redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
    }

    private static function composicaoSaveManual(array $produto, array $variacao, ?array $existing): void
    {
        $produtoId = (int) $produto['id'];
        $variacaoId = (int) $variacao['id'];

        if (($variacao['custo_modo'] ?? 'herdar') !== 'proprio') {
            flash('errors', ['Ative “Personalizar custo” para editar a composição.']);
            redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
        }

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
                ? '/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/' . $existing['id'] . '/editar'
                : '/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/composicao/manual/novo');
        }

        if ($existing) {
            Composicao::updateManualVariacao((int) $existing['id'], $descricao, (float) $preco, (float) $quantidade);
            flash('success', 'Custo manual da variação atualizado.');
        } else {
            Composicao::createManualVariacao($variacaoId, $descricao, (float) $preco, (float) $quantidade);
            flash('success', 'Custo manual adicionado à variação.');
        }
        redirect('/produtos/' . $produtoId . '/variacoes/' . $variacaoId . '/editar#custo');
    }

    /**
     * Gravação concorrente que passou pela validação mas esbarrou nos índices únicos de GTIN:
     * volta ao formulário com os dados digitados. Qualquer outro erro de banco é relançado.
     */
    private static function rejeitarGtinDuplicado(PDOException $e, array $old, string $urlForm): void
    {
        $info = $e->errorInfo ?? [];
        if ((int) ($info[1] ?? 0) !== 1062 || !str_contains((string) ($info[2] ?? ''), 'uq_variacao_gtin_')) {
            throw $e;
        }
        flash('errors', ['Um dos GTINs informados acabou de ser usado em outra variação. Confira os códigos e salve novamente.']);
        flash('old', $old);
        redirect($urlForm);
    }

    private static function produtoNotFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
    }

    private static function variacaoNotFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Variação não encontrada', 'page' => 'produtos']);
    }
}

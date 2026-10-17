<?php

declare(strict_types=1);

final class ColecaoController
{
    private const LIMITE_BUSCA_VARIACOES = 50;

    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => (string) ($_GET['status'] ?? ''),
        ];

        $result = Colecao::paginate($filters, (int) ($_GET['page'] ?? 1), 24);

        View::render('colecoes/index', [
            'title' => 'Coleções',
            'page' => 'colecoes',
            'colecoes' => $result['items'],
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
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
        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $buscaVariacao = trim((string) ($_GET['busca_variacao'] ?? ''));
        $adicionandoVariacao = isset($_GET['adicionar_variacao']) || $buscaVariacao !== '';
        $variacoesDisponiveis = $adicionandoVariacao
            ? Colecao::variacoesDisponiveisParaVincular($id, $buscaVariacao, self::LIMITE_BUSCA_VARIACOES + 1)
            : [];
        $maisVariacoes = count($variacoesDisponiveis) > self::LIMITE_BUSCA_VARIACOES;
        if ($maisVariacoes) {
            $variacoesDisponiveis = array_slice($variacoesDisponiveis, 0, self::LIMITE_BUSCA_VARIACOES);
        }

        View::render('colecoes/show', [
            'title' => $colecao['nome'],
            'page' => 'colecoes',
            'colecao' => $colecao,
            'variacoes' => Colecao::variacoes($id),
            'variacoesCount' => Colecao::contarVariacoes($id),
            'adicionandoVariacao' => $adicionandoVariacao,
            'buscaVariacao' => $buscaVariacao,
            'variacoesDisponiveis' => $variacoesDisponiveis,
            'maisVariacoes' => $maisVariacoes,
            'limiteBuscaVariacoes' => self::LIMITE_BUSCA_VARIACOES,
            'tarefas' => Colecao::tarefas($id),
            'totaisTarefas' => Colecao::totaisTarefas($id),
            'novaTarefa' => isset($_GET['nova_tarefa']),
            'editarTarefaId' => self::idPositivo($_GET['editar_tarefa'] ?? null),
            'tarefaOld' => flash('tarefa_old') ?? [],
            'totaisResultado' => Colecao::totaisResultado($id),
            'editarResultadoId' => self::idPositivo($_GET['editar_resultado'] ?? null),
            'resultadoOld' => flash('resultado_old') ?? [],
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function edit(int $id): void
    {
        Auth::requireLogin();
        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }
        self::form($colecao);
    }

    public static function update(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }
        self::save($colecao);
    }

    public static function toggle(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $novo = $colecao['status'] === 'ativo' ? 'inativo' : 'ativo';
        Colecao::setStatus($id, $novo);
        flash('success', $novo === 'inativo' ? 'Coleção inativada.' : 'Coleção reativada.');
        redirect('/colecoes/' . $id);
    }

    public static function confirmDelete(int $id): void
    {
        Auth::requireAdmin();
        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        View::render('colecoes/confirm_delete', [
            'title' => 'Excluir coleção',
            'page' => 'colecoes',
            'colecao' => $colecao,
            'produtosCount' => Colecao::contarProdutos($id),
            'variacoesCount' => Colecao::contarVariacoes($id),
            'tarefasCount' => Colecao::contarTarefas($id),
            'resultadosCount' => Colecao::contarResultadosComerciais($id),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function destroy(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $count = Colecao::contarProdutos($id);
        $confirmar = isset($_POST['confirmar_desvincular']);

        if ($count > 0 && !$confirmar) {
            flash('errors', ['Esta coleção possui produtos vinculados.']);
            redirect('/colecoes/' . $id . '/excluir');
        }

        $nVariacoes = Colecao::contarVariacoes($id);
        $nTarefas = Colecao::contarTarefas($id);
        $imagem = $colecao['imagem'] ?? null;

        if (!Colecao::excluirSemHistoricoComercial($id)) {
            if (!Colecao::find($id)) {
                self::notFound();
                return;
            }
            flash('errors', [
                'Esta coleção possui resultados comerciais registrados e não pode ser excluída. '
                . 'Para retirá-la de uso, inative-a.',
            ]);
            redirect('/colecoes/' . $id . '/excluir');
        }
        Upload::removerImagemPublica($imagem !== null ? (string) $imagem : null);

        $detalhes = [];
        if ($nVariacoes > 0) {
            $detalhes[] = $nVariacoes . ' variação(ões) desvinculada(s) e mantida(s)';
        }
        if ($nTarefas > 0) {
            $detalhes[] = $nTarefas . ' tarefa(s) removida(s)';
        }
        flash(
            'success',
            'Coleção excluída. Os produtos foram mantidos.'
            . ($detalhes ? ' ' . implode('; ', $detalhes) . '.' : '')
        );
        redirect('/colecoes');
    }

    public static function vincularProduto(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $produtoId = (int) ($_POST['produto_id'] ?? 0);
        $produto = $produtoId > 0 ? Produto::find($produtoId) : null;
        if (!$produto) {
            flash('errors', ['Selecione um produto válido.']);
            redirect('/colecoes/' . $id);
        }

        Colecao::vincularProduto($id, $produtoId);
        flash('success', 'Produto adicionado à coleção.');
        redirect('/colecoes/' . $id);
    }

    public static function vincularVariacao(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $busca = trim((string) ($_POST['busca_variacao'] ?? ''));
        $voltar = '/colecoes/' . $id . '?' . http_build_query(['adicionar_variacao' => 1, 'busca_variacao' => $busca]);

        $variacaoId = self::idPositivo($_POST['variacao_id'] ?? null);
        if ($variacaoId === null || !Variacao::find($variacaoId)) {
            flash('errors', ['Selecione uma variação válida.']);
            redirect($voltar);
        }

        if (!Colecao::vincularVariacao($id, $variacaoId)) {
            flash('errors', ['Esta variação já está nesta coleção.']);
            redirect($voltar);
        }

        flash('success', 'Variação adicionada à coleção.');
        redirect($voltar);
    }

    public static function desvincularVariacao(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        $voltar = '/colecoes/' . $id;

        $variacaoId = self::idPositivo($_POST['variacao_id'] ?? null);
        if ($variacaoId === null) {
            flash('errors', ['Selecione uma variação válida.']);
            redirect($voltar);
        }

        if (!Colecao::desvincularVariacao($id, $variacaoId)) {
            flash('errors', [
                Colecao::variacaoVinculada($id, $variacaoId)
                    ? 'Esta variação possui resultados registrados nesta coleção e não pode ser removida dela.'
                    : 'Esta variação não está nesta coleção.',
            ]);
            redirect($voltar);
        }

        flash('success', 'Variação removida da coleção. A variação e o produto foram mantidos.');
        redirect($voltar);
    }

    /**
     * Grava produzida/vendida/valor/observação da variação nesta coleção.
     * Não mexe em estoque nem na etapa da coleção.
     */
    public static function salvarResultadoVariacao(int $id, int $variacaoId): void
    {
        Auth::requireLogin();
        Csrf::verify();

        if (!Colecao::find($id)) {
            self::notFound();
            return;
        }

        $voltar = '/colecoes/' . $id;
        if (!Colecao::variacaoVinculada($id, $variacaoId)) {
            flash('errors', ['Esta variação não está nesta coleção.']);
            redirect($voltar);
        }

        // Os quatro campos são sobrescritos juntos; um campo ausente apagaria o valor gravado.
        foreach (['qtd_produzida', 'qtd_vendida', 'valor_vendido', 'observacao'] as $campo) {
            if (!array_key_exists($campo, $_POST) || !is_string($_POST[$campo])) {
                flash('errors', ['Formulário de resultado incompleto. Nada foi alterado.']);
                redirect($voltar . '?editar_resultado=' . $variacaoId);
            }
        }

        $produzidaRaw = trim($_POST['qtd_produzida']);
        $vendidaRaw = trim($_POST['qtd_vendida']);
        $valorRaw = trim($_POST['valor_vendido']);
        $observacao = trim($_POST['observacao']);

        $errors = [];
        $produzida = self::quantidadeResultado($produzidaRaw);
        if ($produzida === false) {
            $errors[] = 'Quantidade produzida inválida. Use um número inteiro igual ou maior que zero, sem pontos ou vírgulas.';
        }
        $vendida = self::quantidadeResultado($vendidaRaw);
        if ($vendida === false) {
            $errors[] = 'Quantidade vendida inválida. Use um número inteiro igual ou maior que zero, sem pontos ou vírgulas.';
        }
        $valor = self::valorResultado($valorRaw);
        if ($valor === false) {
            $errors[] = 'Valor vendido inválido. Use o formato 1.250,50 (no máximo 2 casas decimais).';
        }
        if (mb_strlen($observacao) > 2000) {
            $errors[] = 'A observação deve ter no máximo 2000 caracteres.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('resultado_old', [
                'qtd_produzida' => $produzidaRaw,
                'qtd_vendida' => $vendidaRaw,
                'valor_vendido' => $valorRaw,
                'observacao' => $observacao,
            ]);
            redirect($voltar . '?editar_resultado=' . $variacaoId);
        }

        Colecao::atualizarResultadoVariacao($id, $variacaoId, [
            'qtd_produzida' => $produzida,
            'qtd_vendida' => $vendida,
            'valor_vendido' => $valor,
            'observacao' => $observacao,
        ]);
        flash('success', 'Resultado da variação salvo. O estoque não foi alterado.');
        redirect($voltar);
    }

    public static function criarTarefa(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $colecao = Colecao::find($id);
        if (!$colecao) {
            self::notFound();
            return;
        }

        [$titulo, $prazo, $errors] = self::dadosTarefa();
        if ($errors) {
            flash('errors', $errors);
            flash('tarefa_old', ['titulo' => $titulo, 'data_prazo' => (string) $prazo]);
            redirect('/colecoes/' . $id . '?nova_tarefa=1');
        }

        Colecao::criarTarefa($id, $titulo, $prazo);
        flash('success', 'Tarefa adicionada ao planejamento.');
        redirect('/colecoes/' . $id);
    }

    public static function atualizarTarefa(int $id, int $tarefaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::requireTarefa($id, $tarefaId);

        [$titulo, $prazo, $errors] = self::dadosTarefa();
        if ($errors) {
            flash('errors', $errors);
            flash('tarefa_old', ['titulo' => $titulo, 'data_prazo' => (string) $prazo]);
            redirect('/colecoes/' . $id . '?editar_tarefa=' . $tarefaId);
        }

        Colecao::atualizarTarefa($id, $tarefaId, $titulo, $prazo);
        flash('success', 'Tarefa atualizada.');
        redirect('/colecoes/' . $id);
    }

    public static function concluirTarefa(int $id, int $tarefaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::requireTarefa($id, $tarefaId);

        Colecao::concluirTarefa($id, $tarefaId);
        flash('success', 'Tarefa concluída.');
        redirect('/colecoes/' . $id);
    }

    public static function reabrirTarefa(int $id, int $tarefaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::requireTarefa($id, $tarefaId);

        Colecao::reabrirTarefa($id, $tarefaId);
        flash('success', 'Tarefa reaberta.');
        redirect('/colecoes/' . $id);
    }

    public static function excluirTarefa(int $id, int $tarefaId): void
    {
        Auth::requireLogin();
        Csrf::verify();
        self::requireTarefa($id, $tarefaId);

        Colecao::excluirTarefa($id, $tarefaId);
        flash('success', 'Tarefa excluída.');
        redirect('/colecoes/' . $id);
    }

    /**
     * Encerra a requisição se a coleção não existir (404) ou se a tarefa não pertencer a ela.
     *
     * @return array<string, mixed>
     */
    private static function requireTarefa(int $colecaoId, int $tarefaId): array
    {
        if (!Colecao::find($colecaoId)) {
            self::notFound();
            exit;
        }
        $tarefa = Colecao::findTarefa($colecaoId, $tarefaId);
        if (!$tarefa) {
            flash('errors', ['Tarefa não encontrada nesta coleção.']);
            redirect('/colecoes/' . $colecaoId);
        }
        return $tarefa;
    }

    /**
     * @return array{0:string, 1:?string, 2:list<string>}
     */
    private static function dadosTarefa(): array
    {
        $titulo = is_string($_POST['titulo'] ?? null) ? trim($_POST['titulo']) : '';
        $prazo = is_string($_POST['data_prazo'] ?? null) ? trim($_POST['data_prazo']) : '';

        $errors = [];
        if ($titulo === '') {
            $errors[] = 'Informe o título da tarefa.';
        } elseif (mb_strlen($titulo) > 255) {
            $errors[] = 'O título da tarefa deve ter no máximo 255 caracteres.';
        }
        if ($prazo !== '' && !self::dataValida($prazo)) {
            $errors[] = 'Prazo inválido.';
        }

        return [$titulo, $prazo !== '' ? $prazo : null, $errors];
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private static function form(?array $existing): void
    {
        View::render('colecoes/form', [
            'title' => $existing ? 'Editar coleção' : 'Nova coleção',
            'page' => 'colecoes',
            'colecao' => $existing,
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
        $descricao = trim((string) ($_POST['descricao'] ?? ''));
        $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $tipo = (string) ($_POST['tipo'] ?? '');
        $etapa = (string) ($_POST['etapa'] ?? '');
        $periodoInicio = trim((string) ($_POST['periodo_inicio'] ?? ''));
        $periodoFim = trim((string) ($_POST['periodo_fim'] ?? ''));

        $errors = [];
        if ($nome === '') {
            $errors[] = 'Informe o nome da coleção.';
        } elseif (mb_strlen($nome) > 120) {
            $errors[] = 'O nome deve ter no máximo 120 caracteres.';
        }

        if ($codigo !== '') {
            if (mb_strlen($codigo) > 40) {
                $errors[] = 'O código deve ter no máximo 40 caracteres.';
            } elseif (Colecao::codigoExiste($codigo, $id)) {
                $errors[] = 'Este código de coleção já está cadastrado.';
            }
        }

        if (!array_key_exists($tipo, Colecao::TIPOS)) {
            $errors[] = 'Selecione um tipo de coleção válido.';
        }
        if (!array_key_exists($etapa, Colecao::ETAPAS)) {
            $errors[] = 'Selecione uma etapa válida.';
        }

        $inicioValido = $periodoInicio === '' || self::dataValida($periodoInicio);
        $fimValido = $periodoFim === '' || self::dataValida($periodoFim);
        if (!$inicioValido) {
            $errors[] = 'Período inicial inválido.';
        }
        if (!$fimValido) {
            $errors[] = 'Período final inválido.';
        }
        if ($inicioValido && $fimValido && $periodoInicio !== '' && $periodoFim !== '' && $periodoInicio > $periodoFim) {
            $errors[] = 'O período inicial não pode ser posterior ao período final.';
        }

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }
        if ($existing === null) {
            $status = 'ativo';
        }

        $upload = Upload::colecaoImagem($_FILES['imagem'] ?? ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']);
        if (!$upload['ok']) {
            $errors[] = $upload['error'] ?? 'Falha no envio da imagem.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', [
                'codigo' => $codigo,
                'nome' => $nome,
                'tipo' => $tipo,
                'descricao' => $descricao,
                'observacoes' => $observacoes,
                'periodo_inicio' => $periodoInicio,
                'periodo_fim' => $periodoFim,
                'etapa' => $etapa,
                'status' => $status,
            ]);
            redirect($id ? '/colecoes/' . $id . '/editar' : '/colecoes/novo');
        }

        $imagem = $existing['imagem'] ?? null;
        if ($upload['path']) {
            $imagem = $upload['path'];
        }

        $payload = [
            'codigo' => $codigo !== '' ? $codigo : null,
            'nome' => $nome,
            'tipo' => $tipo,
            'descricao' => $descricao !== '' ? $descricao : null,
            'imagem' => $imagem,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
            'periodo_inicio' => $periodoInicio !== '' ? $periodoInicio : null,
            'periodo_fim' => $periodoFim !== '' ? $periodoFim : null,
            'etapa' => $etapa,
            'status' => $status,
        ];

        if ($id) {
            Colecao::update($id, $payload);
            if ($upload['path'] && !empty($existing['imagem']) && $existing['imagem'] !== $upload['path']) {
                Upload::removerImagemPublica((string) $existing['imagem']);
            }
            flash('success', 'Coleção atualizada.');
            redirect('/colecoes/' . $id);
        }

        $novoId = Colecao::create($payload);
        flash('success', 'Coleção cadastrada.');
        redirect('/colecoes/' . $novoId);
    }

    /** Data no formato do input type="date" (AAAA-MM-DD) e existente no calendário. */
    private static function dataValida(string $data): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $dt !== false && $dt->format('Y-m-d') === $data;
    }

    private static function idPositivo(mixed $valor): ?int
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /**
     * Quantidade de resultado: vazio = null; só dígitos (0 aceito), até o limite do INT do banco.
     */
    private static function quantidadeResultado(string $raw): int|null|false
    {
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{1,10}$/', $raw) || (int) $raw > 2147483647) {
            return false;
        }
        return (int) $raw;
    }

    /**
     * Valor monetário BR para DECIMAL(15,2), devolvido como string "1250.50" (sem float, sem arredondar).
     * Aceita "R$ 1.250,50", "1250,50", "1.250", "1250.50"; rejeita negativos e mais de 2 casas.
     */
    private static function valorResultado(string $raw): string|null|false
    {
        $v = trim(preg_replace('/^R\$\s*/u', '', $raw) ?? '');
        if ($v === '') {
            return null;
        }

        if (preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+),(\d{1,2})$/', $v, $m)
            || preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+)$/', $v, $m)) {
            $inteiro = str_replace('.', '', $m[1]);
            $centavos = $m[2] ?? '';
        } elseif (preg_match('/^(\d+)\.(\d{1,2})$/', $v, $m)) {
            $inteiro = $m[1];
            $centavos = $m[2];
        } else {
            return false;
        }

        $inteiro = ltrim($inteiro, '0');
        if (strlen($inteiro) > 13) {
            return false;
        }
        return ($inteiro === '' ? '0' : $inteiro) . '.' . str_pad($centavos, 2, '0');
    }

    private static function notFound(): void
    {
        http_response_code(404);
        View::render('pages/not_found', ['title' => 'Coleção não encontrada', 'page' => 'colecoes']);
    }
}

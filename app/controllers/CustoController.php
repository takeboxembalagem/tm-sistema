<?php

declare(strict_types=1);

final class CustoController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'categoria_id' => (int) ($_GET['categoria_id'] ?? 0),
            'unidade_id' => (int) ($_GET['unidade_id'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
        ];

        $result = Material::paginate($filters, (int) ($_GET['page'] ?? 1), 20);

        View::render('custos/index', [
            'title' => 'Custos e Materiais',
            'page' => 'custos',
            'materiais' => $result['items'],
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'categorias' => Material::categoriasAtivas(),
            'unidades' => Material::unidadesAtivas(),
            'success' => flash('success'),
        ]);
    }

    public static function create(): void
    {
        Auth::requireAdmin();
        self::form(null);
    }

    public static function store(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        self::save(null);
    }

    public static function show(int $id): void
    {
        Auth::requireLogin();
        $material = Material::find($id);
        if (!$material) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Custo não encontrado', 'page' => 'custos']);
            return;
        }

        View::render('custos/show', [
            'title' => $material['nome'],
            'page' => 'custos',
            'material' => $material,
            'historico' => Material::historico($id),
        ]);
    }

    public static function edit(int $id): void
    {
        Auth::requireAdmin();
        $material = Material::find($id);
        if (!$material) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Custo não encontrado', 'page' => 'custos']);
            return;
        }
        self::form($material);
    }

    public static function update(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $material = Material::find($id);
        if (!$material) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Custo não encontrado', 'page' => 'custos']);
            return;
        }
        self::save($material);
    }

    public static function confirmForm(int $id): void
    {
        Auth::requireAdmin();
        $material = Material::find($id);
        $pending = $_SESSION['pending_custo'] ?? null;

        if (!$material || !is_array($pending) || (int) ($pending['id'] ?? 0) !== $id) {
            flash('errors', ['Não há alteração de custo pendente.']);
            redirect('/custos/' . $id . '/editar');
        }

        View::render('custos/confirm', [
            'title' => 'Confirmar alteração de custo',
            'page' => 'custos',
            'material' => $material,
            'pending' => $pending,
        ]);
    }

    public static function confirmStore(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $material = Material::find($id);
        $pending = $_SESSION['pending_custo'] ?? null;

        if (!$material || !is_array($pending) || (int) ($pending['id'] ?? 0) !== $id) {
            flash('errors', ['Não há alteração de custo pendente.']);
            redirect('/custos');
        }

        $payload = $pending['payload'];
        $custoAnterior = (float) $pending['custo_anterior'];
        $custoNovo = (float) $pending['custo_novo'];
        $obsHistorico = $pending['observacao_historico'] ?? null;

        try {
            Material::atualizarCustoComHistorico(
                $id,
                $payload,
                $custoAnterior,
                $custoNovo,
                $obsHistorico,
                Auth::id()
            );
        } catch (Throwable $e) {
            flash('errors', ['Não foi possível confirmar a alteração. Tente novamente.']);
            redirect('/custos/' . $id . '/editar');
        }

        unset($_SESSION['pending_custo']);
        $inativou = $material['status'] === 'ativo' && ($payload['status'] ?? 'ativo') === 'inativo';
        flash('success', 'Custo atualizado com sucesso.' . ($inativou ? self::avisoInativacao($id) : ''));
        redirect('/custos/' . $id);
    }

    public static function toggle(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $material = Material::find($id);
        if (!$material) {
            redirect('/custos');
        }

        $novo = $material['status'] === 'ativo' ? 'inativo' : 'ativo';
        Material::setStatus($id, $novo);
        flash('success', $novo === 'inativo'
            ? 'Custo desativado.' . self::avisoInativacao($id)
            : 'Custo ativado.');
        redirect('/custos');
    }

    /** Aviso anexado à mensagem quando um custo em uso é inativado ('' se não houver uso). */
    private static function avisoInativacao(int $id): string
    {
        $usos = Material::contarUsos($id);
        if ($usos['produtos'] === 0 && $usos['variacoes'] === 0) {
            return '';
        }
        return ' Este custo está sendo usado em ' . $usos['produtos'] . ' produto(s) e '
            . $usos['variacoes'] . ' variação(ões). Ao inativá-lo, ele continuará sendo considerado '
            . 'nas composições existentes, mas não ficará disponível para novas composições.';
    }

    private static function form(?array $material): void
    {
        View::render('custos/form', [
            'title' => $material ? 'Editar Custo' : 'Novo Custo',
            'page' => 'custos',
            'material' => $material,
            'categorias' => Material::categoriasAtivas(),
            'unidades' => Material::unidadesAtivas(),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
        ]);
    }

    private static function save(?array $existing): void
    {
        $id = $existing ? (int) $existing['id'] : null;
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $categoriaId = (int) ($_POST['categoria_material_id'] ?? 0);
        $unidadeId = (int) ($_POST['unidade_id'] ?? 0);
        $formaUi = (string) ($_POST['forma_cobranca'] ?? 'unidade');
        $forma = forma_cobranca_from_cadastro($formaUi);
        $custoRaw = trim((string) ($_POST['custo_atual'] ?? ''));
        $fornecedor = trim((string) ($_POST['fornecedor'] ?? ''));
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $obsHistorico = trim((string) ($_POST['observacao_historico'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'ativo');

        $errors = [];

        if ($nome === '') {
            $errors[] = 'Informe o nome do material.';
        } elseif (mb_strlen($nome) > 180) {
            $errors[] = 'O nome deve ter no máximo 180 caracteres.';
        }

        $categoriaId = self::idValido($categoriaId, Material::categoriasAtivas());
        if ($categoriaId <= 0) {
            $errors[] = 'Selecione a categoria.';
        }

        $unidadeId = self::idValido($unidadeId, Material::unidadesAtivas());
        if ($unidadeId <= 0) {
            $errors[] = 'Selecione a unidade.';
        }

        if (!array_key_exists($formaUi, formas_cobranca_cadastro())) {
            $errors[] = 'Selecione a modalidade de cobrança.';
            $forma = 'unidade';
            $formaUi = 'unidade';
        }

        $custo = parse_money_br($custoRaw);
        if ($custo === null) {
            $errors[] = 'Informe um custo válido.';
        } elseif ($custo < 0) {
            $errors[] = 'O custo não pode ser negativo.';
        }

        if (mb_strlen($fornecedor) > 180) {
            $errors[] = 'O fornecedor deve ter no máximo 180 caracteres.';
        }

        if ($status !== 'ativo' && $status !== 'inativo') {
            $status = 'ativo';
        }
        if ($existing === null) {
            $status = 'ativo';
        }

        $oldData = [
            'nome' => $nome,
            'categoria_material_id' => (string) $categoriaId,
            'unidade_id' => (string) $unidadeId,
            'forma_cobranca' => $formaUi,
            'custo_atual' => $custoRaw,
            'fornecedor' => $fornecedor,
            'observacao' => $observacao,
            'observacao_historico' => $obsHistorico,
            'status' => $status,
        ];

        if ($errors) {
            flash('errors', $errors);
            flash('old', $oldData);
            redirect($id ? '/custos/' . $id . '/editar' : '/custos/novo');
        }

        $payload = [
            'nome' => $nome,
            'categoria_material_id' => $categoriaId,
            'unidade_id' => $unidadeId,
            'forma_cobranca' => $forma,
            'custo_atual' => round((float) $custo, 4),
            'fornecedor' => $fornecedor !== '' ? $fornecedor : null,
            'observacao' => $observacao !== '' ? $observacao : null,
            'status' => $status,
        ];

        if ($id === null) {
            Material::create($payload);
            flash('success', 'Custo cadastrado com sucesso.');
            redirect('/custos');
        }

        $custoAnterior = (float) $existing['custo_atual'];
        $custoNovo = (float) $payload['custo_atual'];
        $custoMudou = abs($custoAnterior - $custoNovo) >= 0.00005;

        if ($custoMudou) {
            $_SESSION['pending_custo'] = [
                'id' => $id,
                'payload' => $payload,
                'custo_anterior' => $custoAnterior,
                'custo_novo' => $custoNovo,
                'observacao_historico' => $obsHistorico !== '' ? $obsHistorico : null,
            ];
            redirect('/custos/' . $id . '/confirmar-custo');
        }

        Material::update($id, $payload);
        unset($_SESSION['pending_custo']);
        $inativou = $existing['status'] === 'ativo' && $payload['status'] === 'inativo';
        flash('success', 'Custo atualizado com sucesso.' . ($inativou ? self::avisoInativacao($id) : ''));
        redirect('/custos');
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

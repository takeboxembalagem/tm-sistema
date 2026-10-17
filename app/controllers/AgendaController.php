<?php

declare(strict_types=1);

final class AgendaController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filtros = self::filtros($_GET);
        $semPlano = static fn (array $t): bool => $t['plano_id'] === null;

        // Etapas = tarefas com plano_id: ficam só dentro do plano, sem filtro de período, pendentes antes das concluídas.
        $etapasPorPlano = [];
        foreach (['pendentes', 'concluidas'] as $situacao) {
            foreach (AgendaTarefa::listar(['situacao' => $situacao]) as $t) {
                if (!$semPlano($t)) {
                    $etapasPorPlano[(int) $t['plano_id']][] = $t;
                }
            }
        }

        $planoAberto = self::idPositivo($_GET['plano'] ?? null);
        $novaEtapaPlanoId = self::idPositivo($_GET['nova_etapa'] ?? null);
        $editarId = self::idPositivo($_GET['editar'] ?? null);
        $editarPlanoId = self::idPositivo($_GET['editar_plano'] ?? null);
        $editandoEtapa = false;
        foreach ($etapasPorPlano as $lista) {
            $editandoEtapa = $editandoEtapa || in_array($editarId, array_map('intval', array_column($lista, 'id')), true);
        }
        // Projetos = interface dos planos; abre direto quando a URL já aponta para um projeto ou etapa.
        $abaProjetos = ($_GET['aba'] ?? null) === 'projetos' || isset($_GET['novo_plano']) || $editarPlanoId !== null
            || $planoAberto !== null || $novaEtapaPlanoId !== null || $editandoEtapa;

        View::render('agenda/index', [
            'title' => 'Agenda',
            'page' => 'agenda',
            'aba' => $abaProjetos ? 'projetos' : 'tarefas',
            'tarefas' => array_values(array_filter(AgendaTarefa::listar($filtros), $semPlano)),
            'etapasPorPlano' => $etapasPorPlano,
            'planoAberto' => $planoAberto,
            'novaEtapaPlanoId' => $novaEtapaPlanoId,
            'resumo' => AgendaTarefa::resumo(),
            'usuarios' => Usuario::all(),
            'filtros' => $filtros,
            'novaTarefa' => isset($_GET['nova']),
            'editarId' => $editarId,
            'old' => flash('agenda_old') ?? [],
            'planos' => AgendaPlano::listar(),
            'novoPlano' => isset($_GET['novo_plano']),
            'editarPlanoId' => $editarPlanoId,
            'planoOld' => flash('agenda_plano_old') ?? [],
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function store(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $voltar = self::voltar();
        [$data, $errors] = self::dados();
        if (!$errors) {
            $errors = AgendaTarefa::validarVinculos($data);
        }
        if ($errors) {
            $planoId = $data['plano_id'] ?? null;
            self::voltarComErros($errors, $data, $voltar, $planoId !== null ? 'nova_etapa=' . $planoId : 'nova=1');
        }

        AgendaTarefa::create($data);
        flash('success', ($data['plano_id'] ?? null) !== null ? 'Etapa adicionada ao projeto.' : 'Tarefa adicionada à agenda.');
        redirect($voltar);
    }

    public static function update(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        $tarefa = self::requireTarefa($id, $voltar);

        [$data, $errors] = self::dados();
        if (!$errors) {
            $errors = AgendaTarefa::validarVinculos($data, $tarefa);
        }
        if ($errors) {
            self::voltarComErros($errors, $data, $voltar, 'editar=' . $id);
        }

        AgendaTarefa::update($id, $data);
        flash('success', 'Tarefa atualizada.');
        redirect($voltar);
    }

    public static function concluir(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        $tarefa = self::requireTarefa($id, $voltar);

        if ((int) $tarefa['concluida'] === 0 && (int) ($tarefa['em_andamento'] ?? 0) === 1) {
            AgendaTarefa::alternarAndamento($id);
        }
        AgendaTarefa::concluir($id);
        flash('success', 'Tarefa concluída.');
        redirect($voltar);
    }

    public static function andamento(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        $tarefa = self::requireTarefa($id, $voltar);

        if (!AgendaTarefa::alternarAndamento($id)) {
            flash('errors', ['Tarefa concluída. Reabra a tarefa para marcá-la em andamento.']);
            redirect($voltar);
        }
        flash('success', (int) ($tarefa['em_andamento'] ?? 0) === 1 ? 'Tarefa voltou para pendente.' : 'Tarefa em andamento.');
        redirect($voltar);
    }

    public static function reabrir(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        self::requireTarefa($id, $voltar);

        AgendaTarefa::reabrir($id);
        flash('success', 'Tarefa reaberta.');
        redirect($voltar);
    }

    public static function destroy(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        self::requireTarefa($id, $voltar);

        AgendaTarefa::delete($id);
        flash('success', 'Tarefa excluída.');
        redirect($voltar);
    }

    public static function planoStore(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();

        $dados = self::dadosPlano();
        try {
            AgendaPlano::criar($dados);
        } catch (InvalidArgumentException $e) {
            self::voltarComErrosPlano($e->getMessage(), $dados, $voltar, 'novo_plano=1');
        }
        flash('success', 'Projeto criado.');
        redirect($voltar);
    }

    public static function planoUpdate(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        self::requirePlano($id, $voltar);

        $dados = self::dadosPlano();
        try {
            AgendaPlano::editar($id, $dados);
        } catch (InvalidArgumentException $e) {
            self::voltarComErrosPlano($e->getMessage(), $dados, $voltar, 'editar_plano=' . $id);
        }
        flash('success', 'Projeto atualizado.');
        redirect($voltar);
    }

    public static function planoStatus(int $id): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $voltar = self::voltar();
        self::requirePlano($id, $voltar);

        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $resultado = AgendaPlano::alterarStatus($id, $status);
        if ($resultado === 'alterado') {
            flash('success', 'Status do projeto atualizado.');
        } else {
            flash('errors', [match ($resultado) {
                'subtarefas_abertas' => 'O projeto tem etapas pendentes ou em andamento e não pode ser concluído.',
                'status_invalido' => 'Selecione um status válido.',
                default => 'Projeto não encontrado.',
            }]);
        }
        redirect($voltar);
    }

    public static function planoDestroy(int $id): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $voltar = self::voltar();
        self::requirePlano($id, $voltar);

        $resultado = AgendaPlano::excluir($id);
        if ($resultado === 'excluido') {
            flash('success', 'Projeto excluído.');
        } else {
            flash('errors', [$resultado === 'com_subtarefas'
                ? 'Este projeto possui etapas e não pode ser excluído.'
                : 'Projeto não encontrado.']);
        }
        redirect($voltar);
    }

    /**
     * Filtros válidos a partir de GET/POST; valores desconhecidos voltam ao padrão.
     *
     * @return array{periodo:string, situacao:string}
     */
    private static function filtros(array $origem): array
    {
        $periodo = is_string($origem['periodo'] ?? null) ? $origem['periodo'] : '';
        $situacao = is_string($origem['situacao'] ?? null) ? $origem['situacao'] : '';
        return [
            'periodo' => isset(AgendaTarefa::PERIODOS[$periodo]) ? $periodo : 'todas',
            'situacao' => isset(AgendaTarefa::SITUACOES[$situacao]) ? $situacao : 'pendentes',
        ];
    }

    /** URL da agenda com a aba, os filtros que estavam na tela e o plano aberto (enviados em campos ocultos). */
    private static function voltar(): string
    {
        $filtros = self::filtros($_POST);
        $plano = self::idPositivo($_POST['plano'] ?? null);
        $params = array_filter([
            'aba' => ($_POST['aba'] ?? null) === 'projetos' ? 'projetos' : '',
            'periodo' => $filtros['periodo'] !== 'todas' ? $filtros['periodo'] : '',
            'situacao' => $filtros['situacao'] !== 'pendentes' ? $filtros['situacao'] : '',
            'plano' => $plano !== null ? (string) $plano : '',
        ]);
        return '/agenda' . ($params ? '?' . http_build_query($params) : '');
    }

    private static function voltarComErros(array $errors, array $data, string $voltar, string $abrir): void
    {
        flash('errors', $errors);
        flash('agenda_old', [
            'titulo' => $data['titulo'],
            'data_tarefa' => $data['data_tarefa'],
            'prioridade' => $data['prioridade'],
            'observacao' => (string) $data['observacao'],
        ] + (array_key_exists('responsavel_id', $data) ? ['responsavel_id' => (string) $data['responsavel_id']] : [])
          + (array_key_exists('data_inicio', $data) ? ['data_inicio' => (string) $data['data_inicio']] : []));
        redirect($voltar . (str_contains($voltar, '?') ? '&' : '?') . $abrir);
    }

    private static function requireTarefa(int $id, string $voltar): array
    {
        $tarefa = AgendaTarefa::find($id);
        if (!$tarefa) {
            flash('errors', ['Tarefa não encontrada.']);
            redirect($voltar);
        }
        return $tarefa;
    }

    private static function voltarComErrosPlano(string $erro, array $dados, string $voltar, string $abrir): void
    {
        flash('errors', [$erro]);
        flash('agenda_plano_old', $dados);
        redirect($voltar . (str_contains($voltar, '?') ? '&' : '?') . $abrir);
    }

    private static function requirePlano(int $id, string $voltar): void
    {
        if (!AgendaPlano::existe($id)) {
            flash('errors', ['Projeto não encontrado.']);
            redirect($voltar);
        }
    }

    /**
     * Só os campos editáveis; status não vem do formulário (criação usa o padrão do model, mudança só por planoStatus).
     *
     * @return array{titulo:string, observacao:string, data_inicio:string, data_fim:string, responsavel_id:string}
     */
    private static function dadosPlano(): array
    {
        $dados = [];
        foreach (['titulo', 'observacao', 'data_inicio', 'data_fim', 'responsavel_id'] as $campo) {
            $dados[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
        }
        return $dados;
    }

    /**
     * @return array{0:array{titulo:string, data_tarefa:string, prioridade:string, observacao:?string, plano_id?:?int, responsavel_id?:?int, data_inicio?:?string}, 1:list<string>}
     */
    private static function dados(): array
    {
        $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $titulo = $str('titulo');
        $dataTarefa = $str('data_tarefa');
        $prioridade = $str('prioridade');
        $observacao = $str('observacao');

        $errors = [];
        if ($titulo === '') {
            $errors[] = 'Informe a tarefa.';
        } elseif (mb_strlen($titulo) > 255) {
            $errors[] = 'A tarefa deve ter no máximo 255 caracteres.';
        }
        if ($dataTarefa === '') {
            $errors[] = 'Informe a data.';
        } elseif (!self::dataValida($dataTarefa)) {
            $errors[] = 'Data inválida.';
        }
        if (!isset(AgendaTarefa::PRIORIDADES[$prioridade])) {
            $errors[] = 'Selecione uma prioridade válida.';
        }
        if (mb_strlen($observacao) > 2000) {
            $errors[] = 'A observação deve ter no máximo 2000 caracteres.';
        }

        $data = [
            'titulo' => $titulo,
            'data_tarefa' => $dataTarefa,
            'prioridade' => $prioridade,
            'observacao' => $observacao !== '' ? $observacao : null,
        ];

        // Campos de vínculo só entram quando enviados (na edição, ausente = manter o valor gravado); vazio = NULL.
        foreach (['plano_id' => 'Selecione um projeto válido.', 'responsavel_id' => 'Selecione um responsável válido.'] as $campo => $erro) {
            if (array_key_exists($campo, $_POST)) {
                $valor = $str($campo);
                $data[$campo] = $valor !== '' ? self::idPositivo($valor) : null;
                if ($valor !== '' && $data[$campo] === null) {
                    $errors[] = $erro;
                }
            }
        }
        if (array_key_exists('data_inicio', $_POST)) {
            $dataInicio = $str('data_inicio');
            $data['data_inicio'] = $dataInicio !== '' ? $dataInicio : null;
            if ($dataInicio !== '' && !self::dataValida($dataInicio)) {
                $errors[] = 'Data de início inválida.';
            }
        }

        return [$data, $errors];
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
}

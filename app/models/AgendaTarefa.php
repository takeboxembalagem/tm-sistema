<?php

declare(strict_types=1);

/**
 * Tarefas gerais da Agenda. Independente de coleções (não usa colecao_tarefas).
 * "Hoje" vem do PHP (timezone da aplicação), não do NOW() do banco.
 */
final class AgendaTarefa
{
    public const PRIORIDADES = [
        'alta' => 'Alta',
        'media' => 'Média',
        'baixa' => 'Baixa',
    ];

    public const PERIODOS = [
        'todas' => 'Todas',
        'hoje' => 'Hoje',
        'amanha' => 'Amanhã',
        '7dias' => 'Próximos 7 dias',
    ];

    public const SITUACOES = [
        'pendentes' => 'Pendentes',
        'concluidas' => 'Concluídas',
    ];

    /** "Próximos 7 dias" = hoje + 6 dias. */
    public const DIAS_PROXIMOS = 7;

    /**
     * @param array{periodo?:string, situacao?:string, prioridade?:string} $filtros
     * @return list<array<string, mixed>>
     */
    public static function listar(array $filtros = []): array
    {
        $hoje = date('Y-m-d');
        $where = [];
        $params = [];

        $concluidas = ($filtros['situacao'] ?? 'pendentes') === 'concluidas';
        $where[] = 'concluida = :concluida';
        $params['concluida'] = $concluidas ? 1 : 0;

        switch ($filtros['periodo'] ?? 'todas') {
            case 'hoje':
                $where[] = 'data_tarefa = :hoje';
                $params['hoje'] = $hoje;
                break;
            case 'amanha':
                $where[] = 'data_tarefa = :amanha';
                $params['amanha'] = date('Y-m-d', strtotime($hoje . ' +1 day'));
                break;
            case '7dias':
                $where[] = 'data_tarefa BETWEEN :inicio AND :fim';
                $params['inicio'] = $hoje;
                $params['fim'] = self::fimProximosDias($hoje);
                break;
        }

        $prioridade = $filtros['prioridade'] ?? '';
        if (isset(self::PRIORIDADES[$prioridade])) {
            $where[] = 'prioridade = :prioridade';
            $params['prioridade'] = $prioridade;
        }

        $ordem = $concluidas
            ? 'concluida_em DESC, id DESC'
            : "data_tarefa ASC, FIELD(prioridade, 'alta', 'media', 'baixa'), titulo ASC, id ASC";

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM agenda_tarefas WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $ordem
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM agenda_tarefas WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Derivada de concluida/em_andamento (não há coluna status); concluida prevalece.
     *
     * @param array<string, mixed> $tarefa
     * @return 'pendente'|'em_andamento'|'concluida'
     */
    public static function situacao(array $tarefa): string
    {
        if ((int) ($tarefa['concluida'] ?? 0) === 1) {
            return 'concluida';
        }
        return (int) ($tarefa['em_andamento'] ?? 0) === 1 ? 'em_andamento' : 'pendente';
    }

    /**
     * Contagens de pendentes avulsas (etapas de projetos ficam fora), calculadas na hora.
     *
     * @return array{hoje:int, atrasadas:int, proximos:int}
     */
    public static function resumo(): array
    {
        $hoje = date('Y-m-d');
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(data_tarefa = :hoje1), 0) AS hoje,
                    COALESCE(SUM(data_tarefa < :hoje2), 0) AS atrasadas,
                    COALESCE(SUM(data_tarefa BETWEEN :inicio AND :fim), 0) AS proximos
             FROM agenda_tarefas
             WHERE concluida = 0 AND plano_id IS NULL'
        );
        $stmt->execute([
            'hoje1' => $hoje,
            'hoje2' => $hoje,
            'inicio' => $hoje,
            'fim' => self::fimProximosDias($hoje),
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'hoje' => (int) ($row['hoje'] ?? 0),
            'atrasadas' => (int) ($row['atrasadas'] ?? 0),
            'proximos' => (int) ($row['proximos'] ?? 0),
        ];
    }

    /**
     * Regras de plano, responsável e data de início (data_tarefa = data final).
     * Na edição, $atual é a tarefa gravada: campo de vínculo ausente em $data mantém o valor dela,
     * e o responsável já atribuído pode continuar mesmo se ficou inativo.
     *
     * @param array{data_tarefa:string, plano_id?:?int, responsavel_id?:?int, data_inicio?:?string} $data
     * @param array<string, mixed>|null $atual
     * @return list<string>
     */
    public static function validarVinculos(array $data, ?array $atual = null): array
    {
        $valor = static fn (string $k): mixed => array_key_exists($k, $data) ? $data[$k] : ($atual[$k] ?? null);
        $dataTarefa = (string) $data['data_tarefa'];
        $dataInicio = $valor('data_inicio');
        $planoId = $valor('plano_id');
        $responsavelId = $valor('responsavel_id');
        $pdo = Database::pdo();

        $errors = [];
        if ($dataInicio !== null) {
            if (!is_string($dataInicio) || !self::dataValida($dataInicio)) {
                $errors[] = 'Data de início inválida.';
                $dataInicio = null;
            } elseif ($dataInicio > $dataTarefa) {
                $errors[] = 'A data de início não pode ser posterior à data da tarefa.';
            }
        }

        if ($planoId !== null) {
            $plano = null;
            if (is_int($planoId) || ctype_digit((string) $planoId)) {
                $stmt = $pdo->prepare('SELECT data_inicio, data_fim FROM agenda_planos WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int) $planoId]);
                $plano = $stmt->fetch() ?: null;
            }
            if ($plano === null) {
                $errors[] = 'Projeto não encontrado.';
            } else {
                if ($dataInicio !== null && $dataInicio < $plano['data_inicio']) {
                    $errors[] = 'A data de início não pode ser anterior ao início do projeto.';
                }
                if ($dataTarefa > $plano['data_fim']) {
                    $errors[] = 'A data da tarefa não pode ser posterior ao fim do projeto.';
                }
            }
        }

        if ($responsavelId !== null) {
            $status = false;
            if (is_int($responsavelId) || ctype_digit((string) $responsavelId)) {
                $stmt = $pdo->prepare('SELECT status FROM usuarios WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int) $responsavelId]);
                $status = $stmt->fetchColumn();
            }
            $mantido = $atual !== null && (int) $responsavelId === (int) ($atual['responsavel_id'] ?? 0);
            if ($status === false) {
                $errors[] = 'Responsável não encontrado.';
            } elseif ($status !== 'ativo' && !$mantido) {
                $errors[] = 'Responsável inativo. Escolha um usuário ativo.';
            }
        }

        return $errors;
    }

    /**
     * Dados já validados (validarVinculos). plano_id, responsavel_id e data_inicio ausentes ficam NULL.
     *
     * @param array{titulo:string, data_tarefa:string, prioridade:string, observacao:?string, plano_id?:?int, responsavel_id?:?int, data_inicio?:?string} $data
     */
    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO agenda_tarefas (titulo, data_tarefa, prioridade, observacao, plano_id, responsavel_id, data_inicio)
             VALUES (:titulo, :data_tarefa, :prioridade, :observacao, :plano_id, :responsavel_id, :data_inicio)'
        );
        $stmt->execute([
            'titulo' => $data['titulo'],
            'data_tarefa' => $data['data_tarefa'],
            'prioridade' => $data['prioridade'],
            'observacao' => $data['observacao'],
            'plano_id' => $data['plano_id'] ?? null,
            'responsavel_id' => $data['responsavel_id'] ?? null,
            'data_inicio' => $data['data_inicio'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Não mexe em concluida/concluida_em (só Concluir/Reabrir).
     * plano_id, responsavel_id e data_inicio só são alterados quando presentes em $data (null = limpar).
     *
     * @param array{titulo:string, data_tarefa:string, prioridade:string, observacao:?string, plano_id?:?int, responsavel_id?:?int, data_inicio?:?string} $data
     */
    public static function update(int $id, array $data): void
    {
        $params = [
            'titulo' => $data['titulo'],
            'data_tarefa' => $data['data_tarefa'],
            'prioridade' => $data['prioridade'],
            'observacao' => $data['observacao'],
            'id' => $id,
        ];
        $vinculos = '';
        foreach (['plano_id', 'responsavel_id', 'data_inicio'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $vinculos .= ", {$campo} = :{$campo}";
                $params[$campo] = $data[$campo];
            }
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE agenda_tarefas
             SET titulo = :titulo,
                 data_tarefa = :data_tarefa,
                 prioridade = :prioridade,
                 observacao = :observacao' . $vinculos . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /** Só marca se ainda estiver pendente (não sobrescreve concluida_em). */
    public static function concluir(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE agenda_tarefas
             SET concluida = 1, concluida_em = :agora
             WHERE id = :id AND concluida = 0'
        );
        $stmt->execute(['agora' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /** Pendente ↔ em andamento; não mexe em concluida e não altera tarefa concluída. */
    public static function alternarAndamento(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE agenda_tarefas SET em_andamento = 1 - em_andamento WHERE id = :id AND concluida = 0'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function reabrir(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE agenda_tarefas SET concluida = 0, concluida_em = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM agenda_tarefas WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Data no formato do input type="date" (AAAA-MM-DD) e existente no calendário. */
    private static function dataValida(string $data): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $dt !== false && $dt->format('Y-m-d') === $data;
    }

    private static function fimProximosDias(string $hoje): string
    {
        return date('Y-m-d', strtotime($hoje . ' +' . (self::DIAS_PROXIMOS - 1) . ' days'));
    }
}

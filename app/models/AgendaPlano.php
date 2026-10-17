<?php

declare(strict_types=1);

/**
 * Planos da Agenda: agrupam subtarefas (agenda_tarefas.plano_id).
 * Progresso não é gravado: vem das subtarefas (concluida = 1 conta; pendente e em andamento não).
 * Autorização fica no controller; aqui só integridade dos dados.
 */
final class AgendaPlano
{
    public const STATUS = [
        'planejado' => 'Planejado',
        'em_andamento' => 'Em andamento',
        'pausado' => 'Pausado',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    public const STATUS_PADRAO = 'planejado';

    public const ROTULO_SEM_SUBTAREFAS = 'Sem subtarefas';

    /**
     * Abertos primeiro (em andamento, planejado, pausado), depois concluídos e cancelados; dentro de cada status, prazo mais próximo.
     * Inclui responsavel_nome/responsavel_status e progresso, sem consulta por plano.
     *
     * @return list<array<string, mixed>>
     */
    public static function listar(): array
    {
        $rows = Database::pdo()->query(
            "SELECT p.*, u.nome AS responsavel_nome, u.status AS responsavel_status,
                    COALESCE(s.total, 0) AS total_subtarefas, COALESCE(s.concluidas, 0) AS subtarefas_concluidas
             FROM agenda_planos p
             LEFT JOIN usuarios u ON u.id = p.responsavel_id
             LEFT JOIN (
                 SELECT plano_id, COUNT(*) AS total, SUM(concluida = 1) AS concluidas
                 FROM agenda_tarefas
                 WHERE plano_id IS NOT NULL
                 GROUP BY plano_id
             ) s ON s.plano_id = p.id
             ORDER BY FIELD(p.status, 'em_andamento', 'planejado', 'pausado', 'concluido', 'cancelado'),
                      p.data_fim ASC, p.titulo ASC, p.id ASC"
        )->fetchAll();
        return array_map([self::class, 'comProgresso'], $rows);
    }

    /** Plano com responsavel_nome/responsavel_status, total_subtarefas, subtarefas_concluidas, progresso e rotulo_progresso. */
    public static function buscar(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, u.nome AS responsavel_nome, u.status AS responsavel_status,
                    (SELECT COUNT(*) FROM agenda_tarefas t WHERE t.plano_id = p.id) AS total_subtarefas,
                    (SELECT COALESCE(SUM(t.concluida = 1), 0) FROM agenda_tarefas t WHERE t.plano_id = p.id) AS subtarefas_concluidas
             FROM agenda_planos p
             LEFT JOIN usuarios u ON u.id = p.responsavel_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::comProgresso($row) : null;
    }

    /**
     * Normaliza e valida os campos editáveis (sem status).
     * Responsável precisa estar ativo, exceto quando é o já atribuído ($responsavelAtual, na edição).
     *
     * @param array<string, mixed> $data titulo, observacao, data_inicio, data_fim, responsavel_id (vazio/null = sem responsável)
     * @return array{0:array{titulo:string, observacao:?string, data_inicio:string, data_fim:string, responsavel_id:?int}, 1:list<string>}
     */
    public static function validar(array $data, ?int $responsavelAtual = null): array
    {
        $str = static fn (string $k): string => is_string($data[$k] ?? null) ? trim($data[$k]) : '';
        $titulo = $str('titulo');
        $observacao = $str('observacao');
        $dataInicio = $str('data_inicio');
        $dataFim = $str('data_fim');

        $errors = [];
        if ($titulo === '') {
            $errors[] = 'Informe o título do projeto.';
        } elseif (mb_strlen($titulo) > 255) {
            $errors[] = 'O título deve ter no máximo 255 caracteres.';
        }
        if (mb_strlen($observacao) > 2000) {
            $errors[] = 'A observação deve ter no máximo 2000 caracteres.';
        }
        $inicioOk = false;
        if ($dataInicio === '') {
            $errors[] = 'Informe a data de início.';
        } elseif (!self::dataValida($dataInicio)) {
            $errors[] = 'Data de início inválida.';
        } else {
            $inicioOk = true;
        }
        $fimOk = false;
        if ($dataFim === '') {
            $errors[] = 'Informe a data de fim.';
        } elseif (!self::dataValida($dataFim)) {
            $errors[] = 'Data de fim inválida.';
        } else {
            $fimOk = true;
        }
        if ($inicioOk && $fimOk && $dataInicio > $dataFim) {
            $errors[] = 'A data de início não pode ser posterior à data de fim.';
        }

        $responsavelId = null;
        $resp = $data['responsavel_id'] ?? null;
        if ($resp !== null && $resp !== '') {
            $responsavelId = self::idPositivo($resp);
            if ($responsavelId === null) {
                $errors[] = 'Responsável inválido.';
            } else {
                $status = self::statusUsuario($responsavelId);
                if ($status === null) {
                    $errors[] = 'Responsável não encontrado.';
                } elseif ($status !== 'ativo' && $responsavelId !== $responsavelAtual) {
                    $errors[] = 'Responsável inativo. Escolha um usuário ativo.';
                }
            }
        }

        return [[
            'titulo' => $titulo,
            'observacao' => $observacao !== '' ? $observacao : null,
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'responsavel_id' => $responsavelId,
        ], $errors];
    }

    /**
     * @param array<string, mixed> $data campos de validar() + status opcional (padrão planejado)
     * @throws InvalidArgumentException dados inválidos (mensagens de validar(); nada é gravado)
     */
    public static function criar(array $data): int
    {
        [$dados, $errors] = self::validar($data);
        $status = $data['status'] ?? self::STATUS_PADRAO;
        if (!is_string($status) || !isset(self::STATUS[$status])) {
            $errors[] = 'Status inválido.';
        }
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO agenda_planos (titulo, observacao, data_inicio, data_fim, responsavel_id, status)
             VALUES (:titulo, :observacao, :data_inicio, :data_fim, :responsavel_id, :status)'
        );
        $stmt->execute($dados + ['status' => $status]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Não mexe no status (só alterarStatus).
     *
     * @param array<string, mixed> $data campos de validar()
     * @return bool false se o plano não existe
     * @throws InvalidArgumentException dados inválidos (mensagens de validar(); nada é gravado)
     */
    public static function editar(int $id, array $data): bool
    {
        $stmt = Database::pdo()->prepare('SELECT responsavel_id FROM agenda_planos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $atual = $stmt->fetch();
        if (!$atual) {
            return false;
        }

        [$dados, $errors] = self::validar($data, $atual['responsavel_id'] !== null ? (int) $atual['responsavel_id'] : null);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE agenda_planos
             SET titulo = :titulo,
                 observacao = :observacao,
                 data_inicio = :data_inicio,
                 data_fim = :data_fim,
                 responsavel_id = :responsavel_id
             WHERE id = :id'
        );
        $stmt->execute($dados + ['id' => $id]);
        return $stmt->rowCount() > 0 || self::existe($id);
    }

    /**
     * "concluido" só é aplicado se nenhuma subtarefa estiver pendente ou em andamento (concluida = 0);
     * a verificação está no próprio UPDATE, sem intervalo entre checar e gravar.
     *
     * @return 'alterado'|'nao_encontrado'|'status_invalido'|'subtarefas_abertas'
     */
    public static function alterarStatus(int $id, string $status): string
    {
        if (!isset(self::STATUS[$status])) {
            return 'status_invalido';
        }

        if ($status === 'concluido') {
            $stmt = Database::pdo()->prepare(
                "UPDATE agenda_planos SET status = 'concluido'
                 WHERE id = :id
                   AND NOT EXISTS (SELECT 1 FROM agenda_tarefas WHERE plano_id = :plano_id AND concluida = 0)"
            );
            $stmt->execute(['id' => $id, 'plano_id' => $id]);
        } else {
            $stmt = Database::pdo()->prepare('UPDATE agenda_planos SET status = :status WHERE id = :id');
            $stmt->execute(['status' => $status, 'id' => $id]);
        }
        if ($stmt->rowCount() > 0) {
            return 'alterado';
        }

        // 0 linhas: plano inexistente, já estava nesse status ou (concluido) há subtarefa aberta.
        if (!self::existe($id)) {
            return 'nao_encontrado';
        }
        if ($status === 'concluido' && self::subtarefasAbertas($id) > 0) {
            return 'subtarefas_abertas';
        }
        return 'alterado';
    }

    /**
     * Só exclui plano sem nenhuma subtarefa; subtarefas nunca são apagadas.
     * A FK fk_agenda_tarefas_plano (ON DELETE RESTRICT) é a segunda barreira.
     *
     * @return 'excluido'|'nao_encontrado'|'com_subtarefas'
     */
    public static function excluir(int $id): string
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM agenda_planos
             WHERE id = :id
               AND NOT EXISTS (SELECT 1 FROM agenda_tarefas WHERE plano_id = :plano_id)'
        );
        try {
            $stmt->execute(['id' => $id, 'plano_id' => $id]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                return 'com_subtarefas';
            }
            throw $e;
        }
        if ($stmt->rowCount() > 0) {
            return 'excluido';
        }
        return self::existe($id) ? 'com_subtarefas' : 'nao_encontrado';
    }

    /** Percentual inteiro de subtarefas concluídas; null = sem subtarefas. */
    public static function progresso(int $planoId): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(concluida = 1), 0) AS concluidas
             FROM agenda_tarefas
             WHERE plano_id = :plano_id'
        );
        $stmt->execute(['plano_id' => $planoId]);
        $row = $stmt->fetch() ?: [];
        return self::percentual((int) ($row['total'] ?? 0), (int) ($row['concluidas'] ?? 0));
    }

    public static function rotuloProgresso(int $planoId): string
    {
        return self::rotulo(self::progresso($planoId));
    }

    /** Arredonda para baixo: 100% só quando todas estão concluídas. */
    public static function percentual(int $total, int $concluidas): ?int
    {
        if ($total <= 0) {
            return null;
        }
        return intdiv(max(0, min($concluidas, $total)) * 100, $total);
    }

    public static function rotulo(?int $progresso): string
    {
        return $progresso === null ? self::ROTULO_SEM_SUBTAREFAS : $progresso . '%';
    }

    public static function existe(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM agenda_planos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** Subtarefas pendentes ou em andamento (concluida = 0). */
    public static function subtarefasAbertas(int $planoId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM agenda_tarefas WHERE plano_id = :plano_id AND concluida = 0');
        $stmt->execute(['plano_id' => $planoId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private static function comProgresso(array $row): array
    {
        $row['total_subtarefas'] = (int) $row['total_subtarefas'];
        $row['subtarefas_concluidas'] = (int) $row['subtarefas_concluidas'];
        $row['progresso'] = self::percentual($row['total_subtarefas'], $row['subtarefas_concluidas']);
        $row['rotulo_progresso'] = self::rotulo($row['progresso']);
        return $row;
    }

    /** Status do usuário; null se não existe. */
    private static function statusUsuario(int $id): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT status FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    /** Data no formato do input type="date" (AAAA-MM-DD) e existente no calendário. */
    private static function dataValida(string $data): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $dt !== false && $dt->format('Y-m-d') === $data;
    }

    private static function idPositivo(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 1 ? $valor : null;
        }
        if (!is_string($valor)) {
            return null;
        }
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}

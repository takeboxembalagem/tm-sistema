<?php

declare(strict_types=1);

final class EstoquePeca
{
    public static function paginate(array $filters, int $page, int $perPage = 24): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.nome LIKE :q_nome OR e.codigo LIKE :q_codigo OR e.cor LIKE :q_cor OR e.estampa LIKE :q_estampa)';
            $params['q_nome'] = '%' . $q . '%';
            $params['q_codigo'] = '%' . $q . '%';
            $params['q_cor'] = '%' . $q . '%';
            $params['q_estampa'] = '%' . $q . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'ativo' || $status === 'inativo') {
            $where[] = 'e.status = :status';
            $params['status'] = $status;
        }

        $tipo = (string) ($filters['tipo'] ?? '');
        if (in_array($tipo, ['tampa', 'fundo', 'outra'], true)) {
            $where[] = 'e.tipo = :tipo';
            $params['tipo'] = $tipo;
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT e.*, p.nome AS produto_nome, p.codigo_interno AS produto_codigo
             FROM estoque_pecas e
             LEFT JOIN produtos p ON p.id = e.produto_id
             {$sqlWhere}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        usort($items, static function (array $a, array $b): int {
            $cmp = strnatcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strnatcasecmp((string) ($a['codigo'] ?? ''), (string) ($b['codigo'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $items = array_slice($items, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT e.*, p.nome AS produto_nome, p.codigo_interno AS produto_codigo
             FROM estoque_pecas e
             LEFT JOIN produtos p ON p.id = e.produto_id
             WHERE e.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function codigoExiste(string $codigo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM estoque_pecas WHERE codigo = :codigo';
        $params = ['codigo' => $codigo];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(array $data, ?int $usuarioId = null): int
    {
        $quantidade = round((float) ($data['quantidade'] ?? 0), 4);
        $data['quantidade'] = $quantidade;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO estoque_pecas
                    (codigo, nome, tipo, produto_id, cor, estampa, quantidade, unidade, localizacao, imagem, observacao, status)
                 VALUES
                    (:codigo, :nome, :tipo, :produto_id, :cor, :estampa, :quantidade, :unidade, :localizacao, :imagem, :observacao, :status)'
            );
            $stmt->execute($data);
            $id = (int) $pdo->lastInsertId();

            if ($quantidade > 0) {
                self::insertMovimento($pdo, $id, 'entrada', $quantidade, 0.0, $quantidade, 'Estoque inicial', $usuarioId);
            }

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE estoque_pecas
             SET codigo = :codigo,
                 nome = :nome,
                 tipo = :tipo,
                 produto_id = :produto_id,
                 cor = :cor,
                 estampa = :estampa,
                 unidade = :unidade,
                 localizacao = :localizacao,
                 imagem = :imagem,
                 observacao = :observacao,
                 status = :status
             WHERE id = :id'
        );
        $data['id'] = $id;
        $stmt->execute($data);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare('UPDATE estoque_pecas SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /** Sem verificação de histórico: uso restrito à limpeza de dados temporários de teste. */
    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM estoque_pecas WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Exclusão pela tela: só apaga a peça que não tem nenhum movimento (a FK apagaria o histórico em cascata).
     * A linha fica travada com FOR UPDATE, a mesma trava de registrarMovimento, então nenhum movimento
     * entra entre a verificação e o DELETE.
     *
     * @return array{status:'excluido'|'com_movimentos'|'nao_encontrado', imagem:?string}
     */
    public static function excluirSemMovimentos(int $id): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, imagem FROM estoque_pecas WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->rollBack();
                return ['status' => 'nao_encontrado', 'imagem' => null];
            }

            $mov = $pdo->prepare('SELECT 1 FROM estoque_pecas_movimentos WHERE peca_id = :id LIMIT 1 FOR UPDATE');
            $mov->execute(['id' => $id]);
            if ($mov->fetchColumn()) {
                $pdo->rollBack();
                return ['status' => 'com_movimentos', 'imagem' => null];
            }

            $del = $pdo->prepare('DELETE FROM estoque_pecas WHERE id = :id');
            $del->execute(['id' => $id]);
            $pdo->commit();

            return ['status' => 'excluido', 'imagem' => $row['imagem'] !== null ? (string) $row['imagem'] : null];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @throws RuntimeException saldo insuficiente ou dados inválidos
     */
    public static function registrarMovimento(
        int $id,
        string $tipo,
        float $quantidade,
        ?string $observacao,
        ?int $usuarioId
    ): void {
        if (!in_array($tipo, ['entrada', 'saida', 'ajuste'], true)) {
            throw new RuntimeException('Tipo de movimento inválido.');
        }

        $quantidade = round($quantidade, 4);
        if ($tipo === 'ajuste') {
            if ($quantidade < 0) {
                throw new RuntimeException('O saldo do ajuste não pode ser negativo.');
            }
        } elseif ($quantidade <= 0) {
            throw new RuntimeException('Informe uma quantidade maior que zero.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, quantidade FROM estoque_pecas WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Peça não encontrada.');
            }

            $saldoAnterior = round((float) $row['quantidade'], 4);

            if ($tipo === 'entrada') {
                $saldoApos = round($saldoAnterior + $quantidade, 4);
            } elseif ($tipo === 'saida') {
                $saldoApos = round($saldoAnterior - $quantidade, 4);
                if ($saldoApos < 0) {
                    throw new RuntimeException('Saldo insuficiente. Não é possível registrar saída maior que o estoque atual.');
                }
            } else {
                $saldoApos = $quantidade;
            }

            self::insertMovimento($pdo, $id, $tipo, $quantidade, $saldoAnterior, $saldoApos, $observacao, $usuarioId);

            $upd = $pdo->prepare('UPDATE estoque_pecas SET quantidade = :quantidade WHERE id = :id');
            $upd->execute(['quantidade' => $saldoApos, 'id' => $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function movimentos(int $pecaId, int $limit = 100): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.nome AS usuario_nome
             FROM estoque_pecas_movimentos m
             LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.peca_id = :id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['id' => $pecaId]);
        return $stmt->fetchAll();
    }

    private static function insertMovimento(
        PDO $pdo,
        int $pecaId,
        string $tipo,
        float $quantidade,
        ?float $saldoAnterior,
        float $saldoApos,
        ?string $observacao,
        ?int $usuarioId
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO estoque_pecas_movimentos
                (peca_id, tipo, quantidade, saldo_anterior, saldo_apos, observacao, usuario_id)
             VALUES
                (:peca_id, :tipo, :quantidade, :saldo_anterior, :saldo_apos, :observacao, :usuario_id)'
        );
        $stmt->execute([
            'peca_id' => $pecaId,
            'tipo' => $tipo,
            'quantidade' => round($quantidade, 4),
            'saldo_anterior' => $saldoAnterior !== null ? round($saldoAnterior, 4) : null,
            'saldo_apos' => round($saldoApos, 4),
            'observacao' => $observacao !== null && $observacao !== '' ? $observacao : null,
            'usuario_id' => $usuarioId,
        ]);
    }
}

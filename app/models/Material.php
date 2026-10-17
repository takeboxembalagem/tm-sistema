<?php

declare(strict_types=1);

final class Material
{
    public static function paginate(array $filters, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(m.nome LIKE :q_nome OR m.fornecedor LIKE :q_fornecedor)';
            $params['q_nome'] = '%' . $q . '%';
            $params['q_fornecedor'] = '%' . $q . '%';
        }

        $categoriaId = (int) ($filters['categoria_id'] ?? 0);
        if ($categoriaId > 0) {
            $where[] = 'm.categoria_material_id = :categoria_id';
            $params['categoria_id'] = $categoriaId;
        }

        $unidadeId = (int) ($filters['unidade_id'] ?? 0);
        if ($unidadeId > 0) {
            $where[] = 'm.unidade_id = :unidade_id';
            $params['unidade_id'] = $unidadeId;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'ativo' || $status === 'inativo') {
            $where[] = 'm.status = :status';
            $params['status'] = $status;
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM materiais m {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = Database::pdo()->prepare(
            "SELECT m.id, m.nome, m.custo_atual, m.fornecedor, m.status, m.updated_at,
                    cm.nome AS categoria_nome,
                    um.nome AS unidade_nome,
                    um.sigla AS unidade_sigla
             FROM materiais m
             LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
             LEFT JOIN unidades_medida um ON um.id = m.unidade_id
             {$sqlWhere}
             ORDER BY m.updated_at DESC, m.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*,
                    cm.nome AS categoria_nome,
                    um.nome AS unidade_nome,
                    um.sigla AS unidade_sigla
             FROM materiais m
             LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
             LEFT JOIN unidades_medida um ON um.id = m.unidade_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO materiais
                (nome, categoria_material_id, unidade_id, forma_cobranca, custo_atual, fornecedor, observacao, status)
             VALUES
                (:nome, :categoria_material_id, :unidade_id, :forma_cobranca, :custo_atual, :fornecedor, :observacao, :status)'
        );
        $stmt->execute($data);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE materiais
             SET nome = :nome,
                 categoria_material_id = :categoria_material_id,
                 unidade_id = :unidade_id,
                 forma_cobranca = :forma_cobranca,
                 custo_atual = :custo_atual,
                 fornecedor = :fornecedor,
                 observacao = :observacao,
                 status = :status
             WHERE id = :id'
        );
        $data['id'] = $id;
        $stmt->execute($data);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare('UPDATE materiais SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Quantos produtos e variações (distintos) usam o custo na composição.
     *
     * @return array{produtos:int, variacoes:int}
     */
    public static function contarUsos(int $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT produto_id) FROM produto_composicao WHERE material_id = :id');
        $stmt->execute(['id' => $id]);
        $produtos = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT variacao_id) FROM variacao_composicao WHERE material_id = :id');
        $stmt->execute(['id' => $id]);
        return ['produtos' => $produtos, 'variacoes' => (int) $stmt->fetchColumn()];
    }

    public static function atualizarCustoComHistorico(
        int $id,
        array $data,
        float $custoAnterior,
        float $custoNovo,
        ?string $observacaoHistorico,
        ?int $usuarioId
    ): void {
        $diferenca = $custoNovo - $custoAnterior;
        if ($custoAnterior == 0.0) {
            $percentual = $custoNovo == 0.0 ? 0.0 : 100.0;
        } else {
            $percentual = (($custoNovo - $custoAnterior) / $custoAnterior) * 100;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $hist = $pdo->prepare(
                'INSERT INTO historico_custos
                    (material_id, custo_anterior, custo_novo, diferenca, percentual, usuario_id, observacao)
                 VALUES
                    (:material_id, :custo_anterior, :custo_novo, :diferenca, :percentual, :usuario_id, :observacao)'
            );
            $hist->execute([
                'material_id' => $id,
                'custo_anterior' => round($custoAnterior, 4),
                'custo_novo' => round($custoNovo, 4),
                'diferenca' => round($diferenca, 4),
                'percentual' => round($percentual, 4),
                'usuario_id' => $usuarioId,
                'observacao' => $observacaoHistorico,
            ]);

            self::update($id, $data);

            $log = $pdo->prepare(
                'INSERT INTO logs_alteracoes (entidade, entidade_id, descricao, usuario_id)
                 VALUES (:entidade, :entidade_id, :descricao, :usuario_id)'
            );
            $log->execute([
                'entidade' => 'material',
                'entidade_id' => $id,
                'descricao' => sprintf(
                    'Custo do material "%s" alterado de %s para %s.',
                    $data['nome'],
                    money_br($custoAnterior),
                    money_br($custoNovo)
                ),
                'usuario_id' => $usuarioId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function historico(int $materialId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT h.*, u.nome AS usuario_nome
             FROM historico_custos h
             LEFT JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.material_id = :id
             ORDER BY h.created_at DESC, h.id DESC'
        );
        $stmt->execute(['id' => $materialId]);
        return $stmt->fetchAll();
    }

    public static function categoriasAtivas(): array
    {
        return Database::pdo()
            ->query("SELECT id, nome FROM categorias_materiais WHERE status = 'ativo' ORDER BY nome")
            ->fetchAll();
    }

    public static function unidadesAtivas(): array
    {
        return Database::pdo()
            ->query("SELECT id, nome, sigla FROM unidades_medida WHERE status = 'ativo' ORDER BY nome")
            ->fetchAll();
    }
}

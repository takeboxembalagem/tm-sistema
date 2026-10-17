<?php

declare(strict_types=1);

final class Colecao
{
    public const TIPOS = [
        'catalogo' => 'Catálogo',
        'especial' => 'Especial',
    ];

    public const ETAPAS = [
        'planejamento' => 'Planejamento',
        'desenvolvimento' => 'Desenvolvimento',
        'pre_venda' => 'Pré-venda',
        'producao' => 'Produção',
        'entrega' => 'Entrega',
        'encerrada' => 'Encerrada',
    ];

    public static function paginate(array $filters, int $page, int $perPage = 24): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.nome LIKE :q_nome OR c.codigo LIKE :q_codigo)';
            $params['q_nome'] = '%' . $q . '%';
            $params['q_codigo'] = '%' . $q . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'ativo' || $status === 'inativo') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM colecoes c {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = Database::pdo()->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM produtos p WHERE p.colecao_id = c.id) AS produtos_count,
                    (SELECT COUNT(*) FROM colecao_variacoes cv WHERE cv.colecao_id = c.id) AS variacoes_count
             FROM colecoes c
             {$sqlWhere}
             ORDER BY c.updated_at DESC, c.id DESC
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
            "SELECT c.*,
                    (SELECT COUNT(*) FROM produtos p WHERE p.colecao_id = c.id) AS produtos_count
             FROM colecoes c
             WHERE c.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function codigoExiste(string $codigo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM colecoes WHERE codigo = :codigo';
        $params = ['codigo' => $codigo];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO colecoes (codigo, nome, tipo, descricao, imagem, observacoes,
                                   periodo_inicio, periodo_fim, etapa, status)
             VALUES (:codigo, :nome, :tipo, :descricao, :imagem, :observacoes,
                     :periodo_inicio, :periodo_fim, :etapa, :status)'
        );
        $stmt->execute($data);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE colecoes
             SET codigo = :codigo,
                 nome = :nome,
                 tipo = :tipo,
                 descricao = :descricao,
                 imagem = :imagem,
                 observacoes = :observacoes,
                 periodo_inicio = :periodo_inicio,
                 periodo_fim = :periodo_fim,
                 etapa = :etapa,
                 status = :status
             WHERE id = :id'
        );
        $data['id'] = $id;
        $stmt->execute($data);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare('UPDATE colecoes SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM colecoes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Condição SQL (sobre colecao_variacoes) que caracteriza resultado comercial registrado.
     * Vínculos assim não podem ser apagados: excluir a coleção ou a variação os levaria em cascata.
     */
    public const SQL_TEM_RESULTADO = "(COALESCE(qtd_vendida, 0) > 0
        OR COALESCE(qtd_produzida, 0) > 0
        OR COALESCE(valor_vendido, 0) > 0
        OR TRIM(COALESCE(observacao, '')) <> '')";

    public static function contarResultadosComerciais(int $colecaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM colecao_variacoes
             WHERE colecao_id = :id AND ' . self::SQL_TEM_RESULTADO
        );
        $stmt->execute(['id' => $colecaoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function contarResultadosComerciaisDaVariacao(int $variacaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM colecao_variacoes
             WHERE variacao_id = :id AND ' . self::SQL_TEM_RESULTADO
        );
        $stmt->execute(['id' => $variacaoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Coleções em que a variação tem resultado comercial registrado.
     *
     * @return list<array<string, mixed>>
     */
    public static function colecoesComResultadoDaVariacao(int $variacaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, codigo, nome
             FROM colecoes
             WHERE id IN (
                SELECT colecao_id FROM colecao_variacoes
                WHERE variacao_id = :id AND ' . self::SQL_TEM_RESULTADO . '
             )
             ORDER BY nome ASC, id ASC'
        );
        $stmt->execute(['id' => $variacaoId]);
        return $stmt->fetchAll();
    }

    public static function vinculoTemResultado(int $colecaoId, int $variacaoId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM colecao_variacoes
             WHERE colecao_id = :colecao_id AND variacao_id = :variacao_id AND ' . self::SQL_TEM_RESULTADO
        );
        $stmt->execute(['colecao_id' => $colecaoId, 'variacao_id' => $variacaoId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Exclui a coleção (desvinculando produtos legados) somente se nenhum vínculo tiver
     * resultado comercial. A verificação é refeita sob bloqueio, dentro da transação.
     */
    public static function excluirSemHistoricoComercial(int $id): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM colecoes WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            if ($stmt->fetchColumn() === false) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM colecao_variacoes
                 WHERE colecao_id = :id AND ' . self::SQL_TEM_RESULTADO . ' FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                return false;
            }

            self::desvincularProdutos($id);
            self::delete($id);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function desvincularProdutos(int $colecaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produtos SET colecao_id = NULL WHERE colecao_id = :id'
        );
        $stmt->execute(['id' => $colecaoId]);
        return $stmt->rowCount();
    }

    public static function contarProdutos(int $colecaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM produtos WHERE colecao_id = :id'
        );
        $stmt->execute(['id' => $colecaoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function produtos(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.codigo_interno, p.nome, p.tamanho, p.status, p.foto_principal,
                    c.nome AS categoria_nome
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             WHERE p.colecao_id = :id
             ORDER BY p.nome ASC, p.codigo_interno ASC, p.id ASC"
        );
        $stmt->execute(['id' => $colecaoId]);
        return $stmt->fetchAll();
    }

    /** Coleções ativas para select (e a atual, mesmo inativa, se informada). */
    public static function opcoesSelect(?int $incluirId = null): array
    {
        if ($incluirId !== null && $incluirId > 0) {
            $stmt = Database::pdo()->prepare(
                "SELECT id, codigo, nome, status
                 FROM colecoes
                 WHERE status = 'ativo' OR id = :id
                 ORDER BY nome ASC"
            );
            $stmt->execute(['id' => $incluirId]);
            return $stmt->fetchAll();
        }

        return Database::pdo()
            ->query("SELECT id, codigo, nome, status FROM colecoes WHERE status = 'ativo' ORDER BY nome ASC")
            ->fetchAll();
    }

    public static function existsAtiva(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM colecoes WHERE id = :id AND status = 'ativo' LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function exists(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM colecoes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function vincularProduto(int $colecaoId, int $produtoId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produtos SET colecao_id = :colecao_id WHERE id = :id'
        );
        $stmt->execute(['colecao_id' => $colecaoId, 'id' => $produtoId]);
    }

    public static function produtosDisponiveisParaVincular(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, codigo_interno, nome
             FROM produtos
             WHERE status = 'ativo'
               AND (colecao_id IS NULL OR colecao_id <> :colecao_id)
             ORDER BY nome ASC, codigo_interno ASC, id ASC
             LIMIT 500"
        );
        $stmt->execute(['colecao_id' => $colecaoId]);
        return $stmt->fetchAll();
    }

    /**
     * Variações vinculadas à coleção, com o produto pai como referência.
     *
     * @return list<array<string, mixed>>
     */
    public static function variacoes(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cv.id AS vinculo_id, cv.colecao_id, cv.variacao_id,
                    cv.qtd_vendida, cv.qtd_produzida, cv.valor_vendido, cv.observacao,
                    cv.created_at, cv.updated_at,
                    v.codigo AS variacao_codigo, v.nome AS variacao_nome,
                    v.status AS variacao_status, v.estoque AS variacao_estoque,
                    p.id AS produto_id, p.codigo_interno AS produto_codigo,
                    p.nome AS produto_nome, p.status AS produto_status
             FROM colecao_variacoes cv
             INNER JOIN produto_variacoes v ON v.id = cv.variacao_id
             INNER JOIN produtos p ON p.id = v.produto_id
             WHERE cv.colecao_id = :id
             ORDER BY p.nome ASC, v.nome ASC, v.id ASC'
        );
        $stmt->execute(['id' => $colecaoId]);
        return $stmt->fetchAll();
    }

    public static function contarVariacoes(int $colecaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM colecao_variacoes WHERE colecao_id = :id'
        );
        $stmt->execute(['id' => $colecaoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function variacaoVinculada(int $colecaoId, int $variacaoId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM colecao_variacoes
             WHERE colecao_id = :colecao_id AND variacao_id = :variacao_id
             LIMIT 1'
        );
        $stmt->execute(['colecao_id' => $colecaoId, 'variacao_id' => $variacaoId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Variações ativas (de produtos ativos) que ainda não estão nesta coleção.
     * Uma variação já vinculada a OUTRA coleção continua disponível.
     *
     * @return list<array<string, mixed>>
     */
    public static function variacoesDisponiveisParaVincular(int $colecaoId, string $busca = '', int $limite = 500): array
    {
        $limite = max(1, min($limite, 1000));
        $where = [
            "v.status = 'ativo'",
            "p.status = 'ativo'",
            'NOT EXISTS (
                SELECT 1 FROM colecao_variacoes cv
                WHERE cv.colecao_id = :colecao_id AND cv.variacao_id = v.id
            )',
        ];
        $params = ['colecao_id' => $colecaoId];

        $busca = trim($busca);
        if ($busca !== '') {
            $where[] = '(v.codigo LIKE :q_vcod OR v.nome LIKE :q_vnome
                         OR p.codigo_interno LIKE :q_pcod OR p.nome LIKE :q_pnome)';
            $like = '%' . $busca . '%';
            $params['q_vcod'] = $like;
            $params['q_vnome'] = $like;
            $params['q_pcod'] = $like;
            $params['q_pnome'] = $like;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT v.id AS variacao_id, v.codigo AS variacao_codigo, v.nome AS variacao_nome,
                    p.id AS produto_id, p.codigo_interno AS produto_codigo, p.nome AS produto_nome
             FROM produto_variacoes v
             INNER JOIN produtos p ON p.id = v.produto_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.nome ASC, v.nome ASC, v.id ASC
             LIMIT ' . $limite
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Vincula a variação à coleção. Retorna false se ela já estava nesta coleção.
     */
    public static function vincularVariacao(int $colecaoId, int $variacaoId): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO colecao_variacoes (colecao_id, variacao_id)
                 VALUES (:colecao_id, :variacao_id)'
            );
            $stmt->execute(['colecao_id' => $colecaoId, 'variacao_id' => $variacaoId]);
            return true;
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Remove o vínculo somente se ele não tiver resultado comercial (checado no próprio DELETE).
     */
    public static function desvincularVariacao(int $colecaoId, int $variacaoId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM colecao_variacoes
             WHERE colecao_id = :colecao_id AND variacao_id = :variacao_id
               AND NOT ' . self::SQL_TEM_RESULTADO
        );
        $stmt->execute(['colecao_id' => $colecaoId, 'variacao_id' => $variacaoId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * IDs das coleções às quais a variação pertence.
     *
     * @return list<int>
     */
    public static function idsDaVariacao(int $variacaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT colecao_id FROM colecao_variacoes WHERE variacao_id = :id ORDER BY colecao_id'
        );
        $stmt->execute(['id' => $variacaoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Coleções selecionáveis no cadastro da variação: as ativas e,
     * mesmo inativas, as que já contêm a variação (para poder mantê-las ou removê-las).
     *
     * @return list<array<string, mixed>>
     */
    public static function opcoesParaVariacao(?int $variacaoId = null): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, codigo, nome, tipo, etapa, status
             FROM colecoes
             WHERE status = 'ativo'
                OR id IN (SELECT colecao_id FROM colecao_variacoes WHERE variacao_id = :id)
             ORDER BY nome ASC, id ASC"
        );
        $stmt->execute(['id' => $variacaoId ?? 0]);
        return $stmt->fetchAll();
    }

    /**
     * Deixa a variação exatamente nas coleções informadas: vincula as novas,
     * mantém as que continuam e desvincula as que saíram. Não toca em outras variações.
     *
     * @param list<int> $colecaoIds IDs já validados
     * @throws DomainException se precisar remover um vínculo com resultado comercial (nada é alterado)
     */
    public static function sincronizarColecoesDaVariacao(int $variacaoId, array $colecaoIds): void
    {
        $desejadas = array_values(array_unique(array_map('intval', $colecaoIds)));
        $atuais = self::idsDaVariacao($variacaoId);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach (array_diff($desejadas, $atuais) as $colecaoId) {
                self::vincularVariacao($colecaoId, $variacaoId);
            }
            foreach (array_diff($atuais, $desejadas) as $colecaoId) {
                if (!self::desvincularVariacao($colecaoId, $variacaoId)
                    && self::variacaoVinculada($colecaoId, $variacaoId)) {
                    throw new DomainException('Vínculo com resultado comercial não pode ser removido.');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Grava o resultado da variação nesta coleção. Campos ausentes ou null ficam NULL.
     * valor_vendido deve chegar já normalizado (ex.: "7000.00"); não há ceil aqui.
     *
     * @param array{qtd_vendida?:?int, qtd_produzida?:?int, valor_vendido?:string|int|float|null, observacao?:?string} $dados
     */
    public static function atualizarResultadoVariacao(int $colecaoId, int $variacaoId, array $dados): bool
    {
        $valor = $dados['valor_vendido'] ?? null;
        if ($valor !== null && $valor !== '') {
            $valor = is_string($valor) ? $valor : number_format((float) $valor, 2, '.', '');
        } else {
            $valor = null;
        }

        $obs = $dados['observacao'] ?? null;
        $obs = ($obs === null || trim((string) $obs) === '') ? null : (string) $obs;

        $stmt = Database::pdo()->prepare(
            'UPDATE colecao_variacoes
             SET qtd_vendida = :qtd_vendida,
                 qtd_produzida = :qtd_produzida,
                 valor_vendido = :valor_vendido,
                 observacao = :observacao
             WHERE colecao_id = :colecao_id AND variacao_id = :variacao_id'
        );
        $stmt->execute([
            'qtd_vendida' => isset($dados['qtd_vendida']) ? (int) $dados['qtd_vendida'] : null,
            'qtd_produzida' => isset($dados['qtd_produzida']) ? (int) $dados['qtd_produzida'] : null,
            'valor_vendido' => $valor,
            'observacao' => $obs,
            'colecao_id' => $colecaoId,
            'variacao_id' => $variacaoId,
        ]);
        return self::variacaoVinculada($colecaoId, $variacaoId);
    }

    /**
     * Totais calculados a partir de colecao_variacoes (NULL conta como 0).
     *
     * @return array{variacoes:int, qtd_vendida:int, qtd_produzida:int, valor_vendido:float}
     */
    public static function totaisResultado(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS variacoes,
                    COALESCE(SUM(qtd_vendida), 0) AS qtd_vendida,
                    COALESCE(SUM(qtd_produzida), 0) AS qtd_produzida,
                    COALESCE(SUM(valor_vendido), 0) AS valor_vendido
             FROM colecao_variacoes
             WHERE colecao_id = :id'
        );
        $stmt->execute(['id' => $colecaoId]);
        $row = $stmt->fetch() ?: [];
        return [
            'variacoes' => (int) ($row['variacoes'] ?? 0),
            'qtd_vendida' => (int) ($row['qtd_vendida'] ?? 0),
            'qtd_produzida' => (int) ($row['qtd_produzida'] ?? 0),
            'valor_vendido' => (float) ($row['valor_vendido'] ?? 0),
        ];
    }

    public static function totalVendido(int $colecaoId): int
    {
        return self::totaisResultado($colecaoId)['qtd_vendida'];
    }

    public static function totalProduzido(int $colecaoId): int
    {
        return self::totaisResultado($colecaoId)['qtd_produzida'];
    }

    public static function valorVendidoTotal(int $colecaoId): float
    {
        return self::totaisResultado($colecaoId)['valor_vendido'];
    }

    /**
     * Checklist de planejamento: pendentes primeiro; em cada grupo, prazo crescente,
     * sem prazo por último e, no empate, ordem de criação.
     *
     * @return list<array<string, mixed>>
     */
    public static function tarefas(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, colecao_id, titulo, data_prazo, concluida, concluida_em, created_at, updated_at
             FROM colecao_tarefas
             WHERE colecao_id = :id
             ORDER BY concluida ASC, data_prazo IS NULL ASC, data_prazo ASC, id ASC'
        );
        $stmt->execute(['id' => $colecaoId]);
        return $stmt->fetchAll();
    }

    /** Tarefa somente se pertencer à coleção informada. */
    public static function findTarefa(int $colecaoId, int $tarefaId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, colecao_id, titulo, data_prazo, concluida, concluida_em, created_at, updated_at
             FROM colecao_tarefas
             WHERE id = :tarefa_id AND colecao_id = :colecao_id
             LIMIT 1'
        );
        $stmt->execute(['tarefa_id' => $tarefaId, 'colecao_id' => $colecaoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array{total:int, concluidas:int, pendentes:int}
     */
    public static function totaisTarefas(int $colecaoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(concluida = 1), 0) AS concluidas
             FROM colecao_tarefas
             WHERE colecao_id = :id'
        );
        $stmt->execute(['id' => $colecaoId]);
        $row = $stmt->fetch() ?: [];
        $total = (int) ($row['total'] ?? 0);
        $concluidas = (int) ($row['concluidas'] ?? 0);
        return ['total' => $total, 'concluidas' => $concluidas, 'pendentes' => $total - $concluidas];
    }

    public static function contarTarefas(int $colecaoId): int
    {
        return self::totaisTarefas($colecaoId)['total'];
    }

    public static function criarTarefa(int $colecaoId, string $titulo, ?string $dataPrazo): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO colecao_tarefas (colecao_id, titulo, data_prazo)
             VALUES (:colecao_id, :titulo, :data_prazo)'
        );
        $stmt->execute(['colecao_id' => $colecaoId, 'titulo' => $titulo, 'data_prazo' => $dataPrazo]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function atualizarTarefa(int $colecaoId, int $tarefaId, string $titulo, ?string $dataPrazo): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE colecao_tarefas
             SET titulo = :titulo, data_prazo = :data_prazo
             WHERE id = :tarefa_id AND colecao_id = :colecao_id'
        );
        $stmt->execute([
            'titulo' => $titulo,
            'data_prazo' => $dataPrazo,
            'tarefa_id' => $tarefaId,
            'colecao_id' => $colecaoId,
        ]);
    }

    /** concluida_em é sempre a data/hora do servidor, no fuso configurado da aplicação. */
    public static function concluirTarefa(int $colecaoId, int $tarefaId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE colecao_tarefas
             SET concluida = 1, concluida_em = :agora
             WHERE id = :tarefa_id AND colecao_id = :colecao_id AND concluida = 0'
        );
        $stmt->execute(['agora' => date('Y-m-d H:i:s'), 'tarefa_id' => $tarefaId, 'colecao_id' => $colecaoId]);
    }

    public static function reabrirTarefa(int $colecaoId, int $tarefaId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE colecao_tarefas
             SET concluida = 0, concluida_em = NULL
             WHERE id = :tarefa_id AND colecao_id = :colecao_id'
        );
        $stmt->execute(['tarefa_id' => $tarefaId, 'colecao_id' => $colecaoId]);
    }

    public static function excluirTarefa(int $colecaoId, int $tarefaId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM colecao_tarefas WHERE id = :tarefa_id AND colecao_id = :colecao_id'
        );
        $stmt->execute(['tarefa_id' => $tarefaId, 'colecao_id' => $colecaoId]);
    }
}

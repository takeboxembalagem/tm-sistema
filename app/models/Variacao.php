<?php

declare(strict_types=1);

final class Variacao
{
    public const MOTIVOS_AJUSTE = [
        'venda' => 'Venda',
        'ajuste_manual' => 'Ajuste manual',
        'perda' => 'Perda',
        'outro' => 'Outro',
    ];

    /** Limiar simples para indicador “estoque baixo” (sem config). */
    public const ESTOQUE_BAIXO = 10;

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarPorProduto(int $produtoId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT v.*
             FROM produto_variacoes v
             WHERE v.produto_id = :produto_id'
        );
        $stmt->execute(['produto_id' => $produtoId]);
        $items = $stmt->fetchAll();

        usort($items, 'cmp_natural_variacao');

        return $items;
    }

    public static function estoqueTotal(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(estoque), 0)
             FROM produto_variacoes
             WHERE produto_id = :produto_id'
        );
        $stmt->execute(['produto_id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT v.*, p.nome AS produto_nome, p.codigo_interno AS produto_codigo
             FROM produto_variacoes v
             INNER JOIN produtos p ON p.id = v.produto_id
             WHERE v.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function codigoExiste(string $codigo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM produto_variacoes WHERE codigo = :codigo';
        $params = ['codigo' => $codigo];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return true;
        }

        // Evita conflito com código do produto principal (sem consultar variações de novo).
        $stmtP = Database::pdo()->prepare(
            'SELECT id FROM produtos WHERE codigo_interno = :codigo LIMIT 1'
        );
        $stmtP->execute(['codigo' => $codigo]);
        return (bool) $stmtP->fetchColumn();
    }

    /**
     * Variação (exceto $exceptId) que já usa o GTIN como unidade individual ou como pacote.
     *
     * @return array{id:int, codigo:string, nome:string}|null
     */
    public static function gtinEmUso(string $gtin, ?int $exceptId = null): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, codigo, nome
             FROM produto_variacoes
             WHERE (gtin_unidade = :gtin_unidade OR gtin_pacote = :gtin_pacote)
               AND id <> :id
             LIMIT 1'
        );
        $stmt->execute(['gtin_unidade' => $gtin, 'gtin_pacote' => $gtin, 'id' => $exceptId ?? 0]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'codigo' => (string) $row['codigo'], 'nome' => (string) $row['nome']] : null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO produto_variacoes
                (produto_id, codigo, nome, estoque, status, observacao, custo_modo, pacote_modo, quantidade_pacote,
                 gtin_unidade, gtin_pacote)
             VALUES
                (:produto_id, :codigo, :nome, :estoque, :status, :observacao, :custo_modo, :pacote_modo, :quantidade_pacote,
                 :gtin_unidade, :gtin_pacote)'
        );
        $pacoteModo = (($data['pacote_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
        $stmt->execute([
            'produto_id' => $data['produto_id'],
            'codigo' => $data['codigo'],
            'nome' => $data['nome'],
            'estoque' => $data['estoque'],
            'status' => $data['status'],
            'observacao' => $data['observacao'],
            'custo_modo' => (($data['custo_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar',
            'pacote_modo' => $pacoteModo,
            'quantidade_pacote' => $pacoteModo === 'proprio' ? ($data['quantidade_pacote'] ?? null) : null,
            'gtin_unidade' => $data['gtin_unidade'] ?? null,
            'gtin_pacote' => $data['gtin_pacote'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Não altera o estoque: o saldo só muda por setEstoque (ajuste de estoque).
     * Os GTINs só são gravados quando as chaves vêm em $data, para não apagar códigos já salvos.
     */
    public static function update(int $id, array $data): void
    {
        $pacoteModo = (($data['pacote_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
        $params = [
            'id' => $id,
            'codigo' => $data['codigo'],
            'nome' => $data['nome'],
            'status' => $data['status'],
            'observacao' => $data['observacao'],
            'custo_modo' => (($data['custo_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar',
            'pacote_modo' => $pacoteModo,
            'quantidade_pacote' => $pacoteModo === 'proprio' ? ($data['quantidade_pacote'] ?? null) : null,
        ];
        $gtins = '';
        foreach (['gtin_unidade', 'gtin_pacote'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $gtins .= ', ' . $campo . ' = :' . $campo;
                $params[$campo] = $data[$campo];
            }
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_variacoes
             SET codigo = :codigo,
                 nome = :nome,
                 status = :status,
                 observacao = :observacao,
                 custo_modo = :custo_modo,
                 pacote_modo = :pacote_modo,
                 quantidade_pacote = :quantidade_pacote' . $gtins . '
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    public static function setCustoModo(int $id, string $modo): void
    {
        $modo = $modo === 'proprio' ? 'proprio' : 'herdar';
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_variacoes SET custo_modo = :modo WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'modo' => $modo]);
    }

    /** Resolve custo unitário da variação (herdado ou próprio). */
    public static function custoUnitarioResolvido(array $variacao, array $produto): float
    {
        $modo = ($variacao['custo_modo'] ?? 'herdar') === 'proprio' ? 'proprio' : 'herdar';
        if ($modo === 'proprio') {
            $tot = Composicao::totaisVariacao((int) $variacao['id'], $produto);
            return (float) $tot['custo_unitario'];
        }
        $tot = Composicao::custoParaTiragem((int) $produto['id'], $produto);
        return (float) $tot['custo_unitario'];
    }

    /** Resolve quantidade por pacote (herdada do produto ou própria). */
    public static function quantidadePacoteResolvida(array $variacao, array $produto): ?int
    {
        return quantidade_pacote_resolvida($produto, $variacao);
    }

    public static function setEstoque(int $id, int $estoque): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_variacoes SET estoque = :estoque WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'estoque' => max(0, $estoque)]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM produto_variacoes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Exclui a variação somente se ela não tiver resultado comercial em nenhuma coleção
     * (os vínculos sairiam em cascata). A verificação é refeita sob bloqueio, na transação.
     */
    public static function excluirSemHistoricoComercial(int $id): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM produto_variacoes WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            if ($stmt->fetchColumn() === false) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM colecao_variacoes
                 WHERE variacao_id = :id AND ' . Colecao::SQL_TEM_RESULTADO . ' FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                return false;
            }

            self::delete($id);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function contarArquivos(int $variacaoId): int
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM arquivos_produtos WHERE variacao_id = :id'
            );
            $stmt->execute(['id' => $variacaoId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return 'ok'|'baixo'|'zerado' */
    public static function nivelEstoque(int $estoque): string
    {
        if ($estoque <= 0) {
            return 'zerado';
        }
        if ($estoque <= self::ESTOQUE_BAIXO) {
            return 'baixo';
        }
        return 'ok';
    }

    public static function nivelLabel(string $nivel): string
    {
        return match ($nivel) {
            'zerado' => 'Sem estoque',
            'baixo' => 'Estoque baixo',
            default => 'Estoque normal',
        };
    }
}

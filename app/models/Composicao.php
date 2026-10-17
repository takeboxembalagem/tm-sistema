<?php

declare(strict_types=1);

final class Composicao
{
    /**
     * Produção de referência: sempre folhas × peças/folha = peças.
     *
     * @return array{
     *   pecas:int,
     *   folhas:int,
     *   rendimento:int,
     *   folha_largura:?float,
     *   folha_altura:?float
     * }
     */
    public static function resolverProducao(array $produto): array
    {
        $folhas = max(1, (int) ($produto['quantidade_producao'] ?? 1000));
        $rendimento = max(0, (int) ($produto['rendimento_folha'] ?? 0));
        $pecas = $rendimento > 0 ? ($folhas * $rendimento) : 0;
        $largura = isset($produto['folha_largura']) && $produto['folha_largura'] !== null && $produto['folha_largura'] !== ''
            ? (float) $produto['folha_largura']
            : null;
        $altura = isset($produto['folha_altura']) && $produto['folha_altura'] !== null && $produto['folha_altura'] !== ''
            ? (float) $produto['folha_altura']
            : null;

        return [
            'pecas' => $pecas,
            'folhas' => $folhas,
            'rendimento' => $rendimento,
            'folha_largura' => $largura,
            'folha_altura' => $altura,
        ];
    }

    public static function listarPorProduto(int $produtoId, ?array $produto = null): array
    {
        if ($produto === null) {
            $produto = Produto::find($produtoId) ?? ['quantidade_producao' => 1000, 'rendimento_folha' => 1];
        }
        $producao = self::resolverProducao($produto);

        $stmt = Database::pdo()->prepare(
            'SELECT pc.id, pc.produto_id, pc.material_id, pc.descricao_manual, pc.preco_manual,
                    pc.quantidade, pc.modo,
                    m.nome AS material_nome,
                    m.custo_atual,
                    m.status AS material_status,
                    m.forma_cobranca AS forma_padrao,
                    cm.nome AS categoria_nome,
                    um.nome AS unidade_nome,
                    um.sigla AS unidade_sigla
             FROM produto_composicao pc
             LEFT JOIN materiais m ON m.id = pc.material_id
             LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
             LEFT JOIN unidades_medida um ON um.id = m.unidade_id
             WHERE pc.produto_id = :produto_id
             ORDER BY pc.id ASC'
        );
        $stmt->execute(['produto_id' => $produtoId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            self::hidratarLinhaComposicao($row, $producao);
        }
        unset($row);

        return $rows;
    }

    public static function totais(int $produtoId): array
    {
        $produto = Produto::find($produtoId);
        if (!$produto) {
            return [
                'quantidade_producao' => 1000,
                'pecas' => 0,
                'folhas' => 1000,
                'rendimento_folha' => 0,
                'custo_lote' => 0.0,
                'custo_unitario' => 0.0,
            ];
        }

        $producao = self::resolverProducao($produto);
        $calc = self::custoParaTiragem($produtoId, $produto);

        return [
            'quantidade_producao' => (int) ($produto['quantidade_producao'] ?? 1000),
            'pecas' => $producao['pecas'],
            'folhas' => $producao['folhas'],
            'rendimento_folha' => $producao['rendimento'],
            'custo_lote' => (float) $calc['custo_lote'],
            'custo_unitario' => (float) $calc['custo_unitario'],
        ];
    }

    /**
     * @return array{quantidade:int, pecas:int, folhas:int, custo_lote:float, custo_unitario:float}
     */
    public static function custoParaTiragem(int $produtoId, array|int $produtoOrPecas = 1000): array
    {
        if (is_int($produtoOrPecas)) {
            // Compat: inteiro antigo = peças → assume 1 peça/folha para não quebrar callers.
            $produto = [
                'quantidade_producao' => max(1, $produtoOrPecas),
                'rendimento_folha' => 1,
            ];
        } else {
            $produto = $produtoOrPecas;
        }

        $producao = self::resolverProducao($produto);
        $linhas = self::listarPorProduto($produtoId, $produto);
        $lote = 0.0;
        // Custo inativo continua no cálculo: inativo só impede novos vínculos.
        foreach ($linhas as $linha) {
            $lote += (float) $linha['custo_lote'];
        }
        $pecas = max(0, $producao['pecas']);

        return [
            'quantidade' => $pecas,
            'pecas' => $pecas,
            'folhas' => $producao['folhas'],
            'custo_lote' => $lote,
            'custo_unitario' => $pecas > 0 ? ($lote / $pecas) : 0.0,
        ];
    }

    /** @deprecated use totais() */
    public static function custoTotal(int $produtoId): float
    {
        return self::totais($produtoId)['custo_unitario'];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pc.*, m.nome AS material_nome, m.custo_atual, m.status AS material_status,
                    m.forma_cobranca AS forma_padrao,
                    cm.nome AS categoria_nome, um.nome AS unidade_nome, um.sigla AS unidade_sigla
             FROM produto_composicao pc
             LEFT JOIN materiais m ON m.id = pc.material_id
             LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
             LEFT JOIN unidades_medida um ON um.id = m.unidade_id
             WHERE pc.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        self::marcarTipoLinha($row);
        return $row;
    }

    public static function existeMaterial(int $produtoId, int $materialId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM produto_composicao
                WHERE produto_id = :produto_id AND material_id = :material_id';
        $params = ['produto_id' => $produtoId, 'material_id' => $materialId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(int $produtoId, int $materialId, string $modo, float $quantidade = 1.0): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO produto_composicao (produto_id, material_id, descricao_manual, preco_manual, quantidade, modo)
             VALUES (:produto_id, :material_id, NULL, NULL, :quantidade, :modo)'
        );
        $stmt->execute([
            'produto_id' => $produtoId,
            'material_id' => $materialId,
            'quantidade' => $quantidade,
            'modo' => $modo,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function createManual(int $produtoId, string $descricao, float $preco, float $quantidade = 1.0): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO produto_composicao
                (produto_id, material_id, descricao_manual, preco_manual, quantidade, modo)
             VALUES
                (:produto_id, NULL, :descricao_manual, :preco_manual, :quantidade, \'manual\')'
        );
        $stmt->execute([
            'produto_id' => $produtoId,
            'descricao_manual' => $descricao,
            'preco_manual' => max(0.0, $preco),
            'quantidade' => $quantidade > 0 ? $quantidade : 1.0,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, int $materialId, string $modo, float $quantidade = 1.0): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_composicao
             SET material_id = :material_id,
                 descricao_manual = NULL,
                 preco_manual = NULL,
                 quantidade = :quantidade,
                 modo = :modo
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'material_id' => $materialId,
            'quantidade' => $quantidade,
            'modo' => $modo,
        ]);
    }

    public static function updateManual(int $id, string $descricao, float $preco, float $quantidade = 1.0): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_composicao
             SET material_id = NULL,
                 descricao_manual = :descricao_manual,
                 preco_manual = :preco_manual,
                 quantidade = :quantidade,
                 modo = \'manual\'
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'descricao_manual' => $descricao,
            'preco_manual' => max(0.0, $preco),
            'quantidade' => $quantidade > 0 ? $quantidade : 1.0,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM produto_composicao WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Custos ativos ainda não usados no produto, mais o custo atual da linha em edição
     * ($exceptMaterialId), mesmo que inativo — para continuar identificável ao trocar.
     */
    public static function materiaisAtivosDisponiveis(int $produtoId, ?int $exceptMaterialId = null): array
    {
        $sql = "SELECT m.id, m.nome, m.custo_atual, m.forma_cobranca, m.status,
                       cm.nome AS categoria_nome,
                       um.nome AS unidade_nome,
                       um.sigla AS unidade_sigla
                FROM materiais m
                LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
                LEFT JOIN unidades_medida um ON um.id = m.unidade_id
                WHERE (
                    m.status = 'ativo'
                    AND m.id NOT IN (
                      SELECT material_id FROM produto_composicao
                      WHERE produto_id = :produto_id AND material_id IS NOT NULL
                    )
                  )
                  OR m.id = :except_material_id
                ORDER BY m.nome";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            'produto_id' => $produtoId,
            'except_material_id' => $exceptMaterialId ?? 0,
        ]);
        return $stmt->fetchAll();
    }

    public static function updateQuantidade(int $id, float $quantidade): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produto_composicao SET quantidade = :quantidade WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'quantidade' => max(0.0001, $quantidade),
        ]);
    }

    /**
     * Custo na formação = preço cadastrado × quantidade informada.
     * Modalidade não entra no cálculo.
     *
     * @param array{pecas:int,folhas:int,rendimento:int} $producao
     * @return array{custo_lote:float, custo_unitario:float, texto:string}
     */
    public static function calcularLinha(string $forma, float $preco, array $producao, float $quantidade = 1.0): array
    {
        $pecas = max(0, (int) ($producao['pecas'] ?? 0));
        $qtd = $quantidade > 0 ? $quantidade : 1.0;
        $lote = max(0.0, $preco) * $qtd;
        $texto = money_br($preco) . ' × ' . number_br($qtd, 4);

        return [
            'custo_lote' => $lote,
            'custo_unitario' => $pecas > 0 ? ($lote / $pecas) : 0.0,
            'texto' => $texto,
        ];
    }

    // ——— Composição própria da variação (reusa produção do produto pai) ———

    public static function contarLinhasVariacao(int $variacaoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM variacao_composicao WHERE variacao_id = :id'
        );
        $stmt->execute(['id' => $variacaoId]);
        return (int) $stmt->fetchColumn();
    }

    public static function listarPorVariacao(int $variacaoId, array $produto): array
    {
        $producao = self::resolverProducao($produto);
        $stmt = Database::pdo()->prepare(
            'SELECT vc.id, vc.variacao_id, vc.material_id, vc.descricao_manual, vc.preco_manual,
                    vc.quantidade, vc.modo,
                    m.nome AS material_nome,
                    m.custo_atual,
                    m.status AS material_status,
                    m.forma_cobranca AS forma_padrao,
                    cm.nome AS categoria_nome,
                    um.nome AS unidade_nome,
                    um.sigla AS unidade_sigla
             FROM variacao_composicao vc
             LEFT JOIN materiais m ON m.id = vc.material_id
             LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
             LEFT JOIN unidades_medida um ON um.id = m.unidade_id
             WHERE vc.variacao_id = :variacao_id
             ORDER BY vc.id ASC'
        );
        $stmt->execute(['variacao_id' => $variacaoId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            self::hidratarLinhaComposicao($row, $producao);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{pecas:int,folhas:int,rendimento_folha:int,custo_lote:float,custo_unitario:float}
     */
    public static function totaisVariacao(int $variacaoId, array $produto): array
    {
        $producao = self::resolverProducao($produto);
        $linhas = self::listarPorVariacao($variacaoId, $produto);
        $lote = 0.0;
        // Custo inativo continua no cálculo: inativo só impede novos vínculos.
        foreach ($linhas as $linha) {
            $lote += (float) $linha['custo_lote'];
        }
        $pecas = max(0, $producao['pecas']);

        return [
            'pecas' => $pecas,
            'folhas' => $producao['folhas'],
            'rendimento_folha' => $producao['rendimento'],
            'custo_lote' => $lote,
            'custo_unitario' => $pecas > 0 ? ($lote / $pecas) : 0.0,
        ];
    }

    /** Copia composição atual do produto para a variação (só se a variação ainda estiver vazia). */
    public static function copiarDeProdutoParaVariacao(int $produtoId, int $variacaoId): int
    {
        if (self::contarLinhasVariacao($variacaoId) > 0) {
            return 0;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT material_id, descricao_manual, preco_manual, quantidade, modo
             FROM produto_composicao WHERE produto_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $produtoId]);
        $linhas = $stmt->fetchAll();
        $n = 0;
        foreach ($linhas as $linha) {
            self::inserirLinhaVariacao($variacaoId, $linha);
            $n++;
        }
        return $n;
    }

    public static function findVariacaoLinha(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT vc.*, m.nome AS material_nome, m.custo_atual, m.status AS material_status,
                    m.forma_cobranca AS forma_padrao
             FROM variacao_composicao vc
             LEFT JOIN materiais m ON m.id = vc.material_id
             WHERE vc.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        self::marcarTipoLinha($row);
        return $row;
    }

    public static function existeMaterialVariacao(int $variacaoId, int $materialId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM variacao_composicao
                WHERE variacao_id = :variacao_id AND material_id = :material_id';
        $params = ['variacao_id' => $variacaoId, 'material_id' => $materialId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = Database::pdo()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function createVariacao(int $variacaoId, int $materialId, string $modo, float $quantidade = 1.0): int
    {
        return self::inserirLinhaVariacao($variacaoId, [
            'material_id' => $materialId,
            'descricao_manual' => null,
            'preco_manual' => null,
            'quantidade' => $quantidade,
            'modo' => $modo,
        ]);
    }

    public static function createManualVariacao(int $variacaoId, string $descricao, float $preco, float $quantidade = 1.0): int
    {
        return self::inserirLinhaVariacao($variacaoId, [
            'material_id' => null,
            'descricao_manual' => $descricao,
            'preco_manual' => max(0.0, $preco),
            'quantidade' => $quantidade > 0 ? $quantidade : 1.0,
            'modo' => 'manual',
        ]);
    }

    public static function updateVariacao(int $id, int $materialId, string $modo, float $quantidade = 1.0): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE variacao_composicao
             SET material_id = :material_id,
                 descricao_manual = NULL,
                 preco_manual = NULL,
                 quantidade = :quantidade,
                 modo = :modo
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'material_id' => $materialId,
            'quantidade' => $quantidade,
            'modo' => $modo,
        ]);
    }

    public static function updateManualVariacao(int $id, string $descricao, float $preco, float $quantidade = 1.0): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE variacao_composicao
             SET material_id = NULL,
                 descricao_manual = :descricao_manual,
                 preco_manual = :preco_manual,
                 quantidade = :quantidade,
                 modo = \'manual\'
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'descricao_manual' => $descricao,
            'preco_manual' => max(0.0, $preco),
            'quantidade' => $quantidade > 0 ? $quantidade : 1.0,
        ]);
    }

    public static function deleteVariacao(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM variacao_composicao WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function updateQuantidadeVariacao(int $id, float $quantidade): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE variacao_composicao SET quantidade = :quantidade WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'quantidade' => max(0.0001, $quantidade),
        ]);
    }

    /** Igual a materiaisAtivosDisponiveis, para a composição própria da variação. */
    public static function materiaisAtivosDisponiveisVariacao(int $variacaoId, ?int $exceptMaterialId = null): array
    {
        $sql = "SELECT m.id, m.nome, m.custo_atual, m.forma_cobranca, m.status,
                       cm.nome AS categoria_nome,
                       um.nome AS unidade_nome,
                       um.sigla AS unidade_sigla
                FROM materiais m
                LEFT JOIN categorias_materiais cm ON cm.id = m.categoria_material_id
                LEFT JOIN unidades_medida um ON um.id = m.unidade_id
                WHERE (
                    m.status = 'ativo'
                    AND m.id NOT IN (
                      SELECT material_id FROM variacao_composicao
                      WHERE variacao_id = :variacao_id AND material_id IS NOT NULL
                    )
                  )
                  OR m.id = :except_material_id
                ORDER BY m.nome";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            'variacao_id' => $variacaoId,
            'except_material_id' => $exceptMaterialId ?? 0,
        ]);
        return $stmt->fetchAll();
    }

    /** @param array{pecas:int,folhas:int,rendimento:int} $producao */
    private static function hidratarLinhaComposicao(array &$row, array $producao): void
    {
        self::marcarTipoLinha($row);
        $qtdLinha = (float) ($row['quantidade'] ?? 1);
        if ($qtdLinha <= 0) {
            $qtdLinha = 1.0;
        }

        if (($row['tipo'] ?? '') === 'manual') {
            $forma = 'manual';
            $preco = (float) ($row['preco_manual'] ?? 0);
            $row['material_nome'] = (string) ($row['descricao_manual'] ?? 'Custo manual');
            $row['custo_atual'] = $preco;
            $row['material_status'] = 'ativo';
            $row['forma_padrao'] = 'manual';
        } else {
            $forma = normalizar_forma_cobranca((string) ($row['forma_padrao'] ?: $row['modo'] ?: 'unidade'));
            $preco = (float) ($row['custo_atual'] ?? 0);
        }

        $calc = self::calcularLinha($forma, $preco, $producao, $qtdLinha);
        $row['modo'] = $forma;
        $row['quantidade'] = $qtdLinha;
        $row['custo_lote'] = $calc['custo_lote'];
        $row['custo_unitario'] = $calc['custo_unitario'];
        $row['calculo_texto'] = $calc['texto'];
        $row['forma_label'] = forma_cobranca_label($forma);
        $row['preco'] = $preco;
    }

    private static function marcarTipoLinha(array &$row): void
    {
        $row['tipo'] = composicao_eh_manual($row) ? 'manual' : 'cadastrado';
        if ($row['tipo'] === 'manual') {
            $row['material_nome'] = (string) ($row['descricao_manual'] ?? $row['material_nome'] ?? 'Custo manual');
            if (!isset($row['custo_atual']) || $row['custo_atual'] === null) {
                $row['custo_atual'] = (float) ($row['preco_manual'] ?? 0);
            }
        }
    }

    /** @param array{material_id?:mixed,descricao_manual?:?string,preco_manual?:mixed,quantidade?:mixed,modo?:string} $linha */
    private static function inserirLinhaVariacao(int $variacaoId, array $linha): int
    {
        $materialId = $linha['material_id'] ?? null;
        if ($materialId === '' || $materialId === 0 || $materialId === '0') {
            $materialId = null;
        }
        if ($materialId !== null) {
            $materialId = (int) $materialId;
        }

        $modo = (string) ($linha['modo'] ?? 'unidade');
        $descricao = $linha['descricao_manual'] ?? null;
        $preco = $linha['preco_manual'] ?? null;

        if ($materialId === null || $modo === 'manual') {
            $modo = 'manual';
            $materialId = null;
            $descricao = trim((string) $descricao);
            $preco = max(0.0, (float) $preco);
        } else {
            $descricao = null;
            $preco = null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO variacao_composicao
                (variacao_id, material_id, descricao_manual, preco_manual, quantidade, modo)
             VALUES
                (:variacao_id, :material_id, :descricao_manual, :preco_manual, :quantidade, :modo)'
        );
        $stmt->execute([
            'variacao_id' => $variacaoId,
            'material_id' => $materialId,
            'descricao_manual' => $descricao !== '' ? $descricao : null,
            'preco_manual' => $preco,
            'quantidade' => (float) ($linha['quantidade'] ?? 1),
            'modo' => $modo,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }
}

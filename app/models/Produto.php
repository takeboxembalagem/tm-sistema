<?php

declare(strict_types=1);

final class Produto
{
    public static function paginate(array $filters, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.codigo_interno LIKE :q_codigo OR p.nome LIKE :q_nome)';
            $params['q_codigo'] = '%' . $q . '%';
            $params['q_nome'] = '%' . $q . '%';
        }

        $categoriaId = (int) ($filters['categoria_id'] ?? 0);
        if ($categoriaId > 0) {
            $where[] = 'p.categoria_id = :categoria_id';
            $params['categoria_id'] = $categoriaId;
        }

        $colecaoId = (int) ($filters['colecao_id'] ?? 0);
        if ($colecaoId > 0) {
            $where[] = 'p.colecao_id = :colecao_id';
            $params['colecao_id'] = $colecaoId;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'ativo' || $status === 'inativo') {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM produtos p {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // Fetch filtrado completo + ordenação natural em PHP (slice da página).
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.codigo_interno, p.nome, p.tamanho, p.foto_principal, p.status,
                    c.nome AS categoria_nome, co.nome AS colecao_nome
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN colecoes co ON co.id = p.colecao_id
             {$sqlWhere}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        usort($items, 'cmp_natural_produto');
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
            'SELECT p.*, c.nome AS categoria_nome, co.nome AS colecao_nome
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN colecoes co ON co.id = p.colecao_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array{id:int|string, codigo_interno:string, nome:string}> */
    public static function opcoesSelect(bool $somenteAtivos = true): array
    {
        $sql = 'SELECT id, codigo_interno, nome FROM produtos';
        if ($somenteAtivos) {
            $sql .= " WHERE status = 'ativo'";
        }
        $sql .= ' ORDER BY nome ASC, codigo_interno ASC, id ASC';
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll();
    }

    public static function codigoExiste(string $codigo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM produtos WHERE codigo_interno = :codigo';
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

        // Conflito com código de qualquer variação.
        try {
            $stmtV = Database::pdo()->prepare(
                'SELECT id FROM produto_variacoes WHERE codigo = :codigo LIMIT 1'
            );
            $stmtV->execute(['codigo' => $codigo]);
            return (bool) $stmtV->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO produtos (codigo_interno, nome, tamanho, tamanho_aberta, descricao, categoria_id, colecao_id, foto_principal, status, quantidade_pacote, peso_pacote_kg)
             VALUES (:codigo_interno, :nome, :tamanho, :tamanho_aberta, :descricao, :categoria_id, :colecao_id, :foto_principal, :status, :quantidade_pacote, :peso_pacote_kg)'
        );
        $stmt->execute($data);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Duplica ficha + composição + parâmetros de preço/custo.
     * NÃO copia: arquivos técnicos, variações nem estoque.
     *
     * @return int ID do novo produto
     */
    public static function duplicar(int $produtoId): int
    {
        $origem = self::find($produtoId);
        if (!$origem) {
            throw new InvalidArgumentException('Produto não encontrado.');
        }

        $novoCodigo = self::gerarCodigoCopia((string) $origem['codigo_interno']);
        $novoNome = mb_substr(rtrim((string) $origem['nome']) . ' — Cópia', 0, 180);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO produtos (
                    codigo_interno, nome, tamanho, tamanho_aberta, descricao, categoria_id, colecao_id,
                    foto_principal, status, quantidade_pacote, peso_pacote_kg, quantidade_producao,
                    modo_producao, rendimento_folha, folha_largura, folha_altura,
                    modo_preco, margem_desejada, imposto_percentual, comissao_percentual,
                    markup, preco_praticado
                 ) VALUES (
                    :codigo_interno, :nome, :tamanho, :tamanho_aberta, :descricao, :categoria_id, :colecao_id,
                    :foto_principal, :status, :quantidade_pacote, :peso_pacote_kg, :quantidade_producao,
                    :modo_producao, :rendimento_folha, :folha_largura, :folha_altura,
                    :modo_preco, :margem_desejada, :imposto_percentual, :comissao_percentual,
                    :markup, :preco_praticado
                 )'
            );
            $qPacote = $origem['quantidade_pacote'] ?? null;
            if ($qPacote !== null && $qPacote !== '' && (int) $qPacote > 0) {
                $qPacote = (int) $qPacote;
            } else {
                $qPacote = null;
            }
            $pesoPacote = $origem['peso_pacote_kg'] ?? null;
            if ($pesoPacote !== null && $pesoPacote !== '') {
                $pesoPacote = (float) $pesoPacote;
                if ($pesoPacote < 0) {
                    $pesoPacote = null;
                }
            } else {
                $pesoPacote = null;
            }
            $stmt->execute([
                'codigo_interno' => $novoCodigo,
                'nome' => $novoNome,
                'tamanho' => $origem['tamanho'] ?? null,
                'tamanho_aberta' => $origem['tamanho_aberta'] ?? null,
                'descricao' => $origem['descricao'] ?? null,
                'categoria_id' => $origem['categoria_id'] ?? null,
                'colecao_id' => $origem['colecao_id'] ?? null,
                'foto_principal' => $origem['foto_principal'] ?? null,
                // Inativo para não entrar em uso comercial até revisão do usuário.
                'status' => 'inativo',
                'quantidade_pacote' => $qPacote,
                'peso_pacote_kg' => $pesoPacote,
                'quantidade_producao' => max(1, (int) ($origem['quantidade_producao'] ?? 1000)),
                'modo_producao' => 'folhas',
                'rendimento_folha' => max(1, (int) ($origem['rendimento_folha'] ?? 1)),
                'folha_largura' => $origem['folha_largura'] ?? null,
                'folha_altura' => $origem['folha_altura'] ?? null,
                'modo_preco' => (string) ($origem['modo_preco'] ?? 'margem'),
                'margem_desejada' => $origem['margem_desejada'] ?? 60,
                'imposto_percentual' => $origem['imposto_percentual'] ?? 9.5,
                'comissao_percentual' => $origem['comissao_percentual'] ?? 10,
                'markup' => $origem['markup'] ?? 2,
                'preco_praticado' => $origem['preco_praticado'] ?? null,
            ]);
            $novoId = (int) $pdo->lastInsertId();

            $comp = $pdo->prepare(
                'SELECT material_id, descricao_manual, preco_manual, quantidade, modo
                 FROM produto_composicao WHERE produto_id = :id ORDER BY id ASC'
            );
            $comp->execute(['id' => $produtoId]);
            $linhas = $comp->fetchAll();

            if ($linhas) {
                $ins = $pdo->prepare(
                    'INSERT INTO produto_composicao
                        (produto_id, material_id, descricao_manual, preco_manual, quantidade, modo)
                     VALUES
                        (:produto_id, :material_id, :descricao_manual, :preco_manual, :quantidade, :modo)'
                );
                foreach ($linhas as $linha) {
                    $materialId = $linha['material_id'];
                    if ($materialId === '' || $materialId === null) {
                        $materialId = null;
                    } else {
                        $materialId = (int) $materialId;
                    }
                    $ins->execute([
                        'produto_id' => $novoId,
                        'material_id' => $materialId,
                        'descricao_manual' => $linha['descricao_manual'] ?? null,
                        'preco_manual' => $linha['preco_manual'] ?? null,
                        'quantidade' => $linha['quantidade'],
                        'modo' => $linha['modo'],
                    ]);
                }
            }

            $pdo->commit();
            return $novoId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Gera código único provisório (ex.: CX-038-COPIA, CX-038-COPIA2). */
    public static function gerarCodigoCopia(string $codigoOrigem): string
    {
        $base = trim($codigoOrigem);
        if ($base === '') {
            $base = 'PROD';
        }
        $suffix = '-COPIA';
        $maxLen = 40;
        $candidato = mb_substr($base, 0, $maxLen - mb_strlen($suffix)) . $suffix;
        $n = 1;
        while (self::codigoExiste($candidato)) {
            $n++;
            $extra = '-COPIA' . $n;
            $candidato = mb_substr($base, 0, $maxLen - mb_strlen($extra)) . $extra;
            if ($n > 9999) {
                $candidato = mb_substr('CPY-' . bin2hex(random_bytes(8)), 0, $maxLen);
                if (!self::codigoExiste($candidato)) {
                    break;
                }
            }
        }
        return $candidato;
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produtos
             SET codigo_interno = :codigo_interno,
                 nome = :nome,
                 tamanho = :tamanho,
                 tamanho_aberta = :tamanho_aberta,
                 descricao = :descricao,
                 categoria_id = :categoria_id,
                 colecao_id = :colecao_id,
                 foto_principal = :foto_principal,
                 status = :status,
                 quantidade_pacote = :quantidade_pacote,
                 peso_pacote_kg = :peso_pacote_kg
             WHERE id = :id'
        );
        $data['id'] = $id;
        $stmt->execute($data);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare('UPDATE produtos SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public static function contarVariacoes(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM produto_variacoes WHERE produto_id = :id'
        );
        $stmt->execute(['id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    /** Arquivos técnicos vinculados ao produto (inclui os ligados a uma variação). */
    public static function contarArquivos(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM arquivos_produtos WHERE produto_id = :id'
        );
        $stmt->execute(['id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Peças do estoque físico vinculadas a este produto.
     * A FK faz ON DELETE SET NULL: excluir o produto apagaria o vínculo comercial
     * (produto_codigo/produto_nome na consulta da peça), sem gravar o produto original
     * nas movimentações — por isso a exclusão é bloqueada enquanto houver vínculo.
     */
    public static function contarPecasEstoque(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM estoque_pecas WHERE produto_id = :id'
        );
        $stmt->execute(['id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Mesma regra de Colecao::SQL_TEM_RESULTADO, com alias cv (necessário no JOIN com produto_variacoes).
     */
    private const SQL_CV_TEM_RESULTADO = "(COALESCE(cv.qtd_vendida, 0) > 0
        OR COALESCE(cv.qtd_produzida, 0) > 0
        OR COALESCE(cv.valor_vendido, 0) > 0
        OR TRIM(COALESCE(cv.observacao, '')) <> '')";

    /**
     * Quantidade de vínculos de variações deste produto com resultado comercial em coleções.
     * Esses registros não podem sair em cascata.
     */
    public static function contarResultadosComerciais(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM colecao_variacoes cv
             INNER JOIN produto_variacoes v ON v.id = cv.variacao_id
             WHERE v.produto_id = :id AND ' . self::SQL_CV_TEM_RESULTADO
        );
        $stmt->execute(['id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    /** Vínculos coleção↔variação sem resultado (serão removidos com as variações). */
    public static function contarVinculosColecaoSemResultado(int $produtoId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM colecao_variacoes cv
             INNER JOIN produto_variacoes v ON v.id = cv.variacao_id
             WHERE v.produto_id = :id AND NOT (' . self::SQL_CV_TEM_RESULTADO . ')'
        );
        $stmt->execute(['id' => $produtoId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Exclui o produto e o que lhe é exclusivo (variações, composições, preços, vínculos
     * de coleção sem resultado) somente se não houver arquivos, peças de estoque vinculadas
     * nem histórico comercial. Materiais, coleções e estoque físico não são apagados.
     *
     * A foto só é devolvida para remoção física quando nenhum outro produto usa o mesmo
     * caminho (a duplicação copia a referência). O arquivo nunca é apagado dentro da
     * transação: o controller só remove depois do commit bem-sucedido.
     *
     * @return array{ok:bool, motivo:?string, foto:?string, variacoes:int}
     *         motivo: null | 'nao_encontrado' | 'arquivos' | 'estoque' | 'resultados'
     */
    public static function excluirSeguro(int $id): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, foto_principal FROM produtos WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'motivo' => 'nao_encontrado', 'foto' => null, 'variacoes' => 0];
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM produto_variacoes WHERE produto_id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $variacaoIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $nVariacoes = count($variacaoIds);

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM arquivos_produtos WHERE produto_id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'motivo' => 'arquivos', 'foto' => null, 'variacoes' => $nVariacoes];
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM estoque_pecas WHERE produto_id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'motivo' => 'estoque', 'foto' => null, 'variacoes' => $nVariacoes];
            }

            if ($variacaoIds !== []) {
                $placeholders = implode(',', array_fill(0, count($variacaoIds), '?'));
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM colecao_variacoes
                     WHERE variacao_id IN (' . $placeholders . ')
                       AND ' . Colecao::SQL_TEM_RESULTADO . ' FOR UPDATE'
                );
                $stmt->execute($variacaoIds);
                if ((int) $stmt->fetchColumn() > 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'motivo' => 'resultados', 'foto' => null, 'variacoes' => $nVariacoes];
                }
            }

            $foto = $row['foto_principal'] !== null && $row['foto_principal'] !== ''
                ? (string) $row['foto_principal']
                : null;
            $fotoExclusiva = null;
            if ($foto !== null) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM produtos
                     WHERE foto_principal = :foto AND id <> :id'
                );
                $stmt->execute(['foto' => $foto, 'id' => $id]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $fotoExclusiva = $foto;
                }
            }

            $stmt = $pdo->prepare('DELETE FROM produtos WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $pdo->commit();

            return [
                'ok' => true,
                'motivo' => null,
                'foto' => $fotoExclusiva,
                'variacoes' => $nVariacoes,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function setQuantidadeProducao(int $id, int $quantidade): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produtos SET quantidade_producao = :qtd WHERE id = :id'
        );
        $stmt->execute(['qtd' => max(1, $quantidade), 'id' => $id]);
    }

    /**
     * Atualiza formação da produção (folhas × peças/folha).
     * Sempre grava modo_producao = folhas (coluna mantida por compatibilidade).
     *
     * @param array{
     *   quantidade_producao:int,
     *   rendimento_folha:int,
     *   folha_largura:?float,
     *   folha_altura:?float
     * } $data
     */
    public static function setProducaoReferencia(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE produtos
             SET quantidade_producao = :qtd,
                 modo_producao = :modo,
                 rendimento_folha = :rendimento,
                 folha_largura = :largura,
                 folha_altura = :altura
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'qtd' => max(1, (int) $data['quantidade_producao']),
            'modo' => 'folhas',
            'rendimento' => max(1, (int) ($data['rendimento_folha'] ?? 1)),
            'largura' => $data['folha_largura'],
            'altura' => $data['folha_altura'],
        ]);
    }

    public static function categoriasAtivas(): array
    {
        return Database::pdo()
            ->query("SELECT id, nome FROM categorias WHERE status = 'ativo' ORDER BY nome")
            ->fetchAll();
    }

    public static function colecoesAtivas(): array
    {
        return Colecao::opcoesSelect();
    }

    /** @return list<array{id:int|string, codigo:?string, nome:string, status?:string}> */
    public static function colecoesParaSelect(?int $incluirId = null): array
    {
        return Colecao::opcoesSelect($incluirId);
    }
}

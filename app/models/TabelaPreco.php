<?php

declare(strict_types=1);

/**
 * Tabela de Preços = visão comercial global.
 * Produtos + variações + custo da composição + margens configuráveis.
 * Não usa preço cheio nem quantidades comerciais.
 */
final class TabelaPreco
{
    /** Margens iniciais quando a config está vazia. */
    public const MARGENS_PADRAO = [10, 20, 30, 40, 50, 60];

    /**
     * Cria a configuração e as margens padrão só quando estão totalmente vazias (instalação nova).
     * Com dados existentes apenas consulta: margens excluídas ou editadas pelo usuário não voltam.
     */
    public static function ensureDefaults(): void
    {
        $pdo = Database::pdo();
        $temConfig = (int) $pdo->query('SELECT COUNT(*) FROM tabela_preco_config WHERE id = 1')->fetchColumn();
        if ($temConfig === 0) {
            $pdo->exec(
                "INSERT IGNORE INTO tabela_preco_config (id, imposto_percentual, incluir_comissao, comissao_percentual)
                 VALUES (1, 9.5000, 0, 10.0000)"
            );
        }

        $countM = (int) $pdo->query('SELECT COUNT(*) FROM tabela_preco_margens_cfg')->fetchColumn();
        if ($countM === 0) {
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO tabela_preco_margens_cfg (margem_percentual, ordem) VALUES (:m, :o)'
            );
            foreach (self::MARGENS_PADRAO as $i => $m) {
                $ins->execute(['m' => $m, 'o' => $i]);
            }
        }
    }

    public static function config(): array
    {
        self::ensureDefaults();
        $row = Database::pdo()->query('SELECT * FROM tabela_preco_config WHERE id = 1 LIMIT 1')->fetch();
        if (!$row) {
            return [
                'imposto_percentual' => 9.5,
                'incluir_comissao' => 0,
                'comissao_percentual' => 10.0,
            ];
        }
        return $row;
    }

    public static function salvarConfig(float $imposto, bool $incluirComissao, float $comissao): void
    {
        self::ensureDefaults();
        $stmt = Database::pdo()->prepare(
            'UPDATE tabela_preco_config
             SET imposto_percentual = :imposto,
                 incluir_comissao = :incluir,
                 comissao_percentual = :comissao
             WHERE id = 1'
        );
        $stmt->execute([
            'imposto' => round($imposto, 4),
            'incluir' => $incluirComissao ? 1 : 0,
            'comissao' => round($comissao, 4),
        ]);
    }

    public static function margens(): array
    {
        self::ensureDefaults();
        return Database::pdo()
            ->query('SELECT * FROM tabela_preco_margens_cfg ORDER BY margem_percentual ASC, id ASC')
            ->fetchAll();
    }

    public static function addMargem(float $margem): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO tabela_preco_margens_cfg (margem_percentual, ordem) VALUES (:m, 0)'
        );
        $stmt->execute(['m' => round($margem, 4)]);
        self::reordenarMargens();
    }

    public static function updateMargem(int $id, float $margem): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE tabela_preco_margens_cfg SET margem_percentual = :m WHERE id = :id'
        );
        $stmt->execute(['m' => round($margem, 4), 'id' => $id]);
        self::reordenarMargens();
    }

    /**
     * Exclui a margem, exceto se for a última (a tabela precisa de pelo menos uma).
     * As margens ficam travadas (FOR UPDATE) para duas exclusões simultâneas não esvaziarem a tabela.
     *
     * @return bool false quando a exclusão foi recusada por ser a última margem
     */
    public static function deleteMargem(int $id): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $ids = $pdo->query('SELECT id FROM tabela_preco_margens_cfg FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) <= 1 && in_array($id, array_map('intval', $ids), true)) {
                $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare('DELETE FROM tabela_preco_margens_cfg WHERE id = :id');
            $stmt->execute(['id' => $id]);
            self::reordenarMargens();
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Reindexa ordem conforme valor numérico crescente da margem. */
    public static function reordenarMargens(): void
    {
        $rows = Database::pdo()
            ->query('SELECT id FROM tabela_preco_margens_cfg ORDER BY margem_percentual ASC, id ASC')
            ->fetchAll();
        $upd = Database::pdo()->prepare('UPDATE tabela_preco_margens_cfg SET ordem = :o WHERE id = :id');
        foreach ($rows as $i => $row) {
            $upd->execute(['o' => $i, 'id' => $row['id']]);
        }
    }

    /**
     * Custo unitário usado na tabela (composição do produto).
     * Variações herdam o custo do produto; preparado para custo próprio futuro.
     */
    public static function custoParaLinha(array $produto, ?array $variacao = null): float
    {
        if ($variacao !== null && array_key_exists('custo_unitario', $variacao) && $variacao['custo_unitario'] !== null) {
            return (float) $variacao['custo_unitario'];
        }
        return (float) ($produto['custo_unitario'] ?? 0);
    }

    /**
     * Grupos produto → linhas de preço (variações ou o próprio produto).
     *
     * @return array{items:list<array>, total:int, page:int, pages:int, per_page:int}
     */
    public static function listarGrupos(array $filters, int $page, int $perPage = 40): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        $mostrarInativos = !empty($filters['mostrar_inativos']);

        if (!$mostrarInativos) {
            $where[] = "p.status = 'ativo'";
        }

        if ($q !== '') {
            $where[] = '(
                p.codigo_interno LIKE :q_pcod OR p.nome LIKE :q_pnome
                OR EXISTS (
                    SELECT 1 FROM produto_variacoes pv
                    WHERE pv.produto_id = p.id
                      AND (pv.codigo LIKE :q_vcod OR pv.nome LIKE :q_vnome)
                      AND (:q_inativos = 1 OR pv.status = \'ativo\')
                )
            )';
            $params['q_pcod'] = '%' . $q . '%';
            $params['q_pnome'] = '%' . $q . '%';
            $params['q_vcod'] = '%' . $q . '%';
            $params['q_vnome'] = '%' . $q . '%';
            $params['q_inativos'] = $mostrarInativos ? 1 : 0;
        }

        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Custo via Composicao (não depende da view vw_produto_custo).
        // Evita HTTP 500 se a view não existir na hospedagem; funciona com 0 produtos.
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.codigo_interno, p.nome, p.tamanho, p.status, p.quantidade_producao,
                    p.modo_producao, p.rendimento_folha, p.folha_largura, p.folha_altura,
                    p.quantidade_pacote
             FROM produtos p
             {$sqlWhere}"
        );
        $stmt->execute($params);
        $produtos = $stmt->fetchAll();

        usort($produtos, 'cmp_natural_produto');

        $total = count($produtos);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $produtos = array_slice($produtos, $offset, $perPage);

        foreach ($produtos as &$produto) {
            $tot = Composicao::custoParaTiragem((int) $produto['id'], $produto);
            $produto['custo_unitario'] = $tot['custo_unitario'];
            $produto['custo_lote'] = $tot['custo_lote'];
        }
        unset($produto);

        $grupos = [];
        foreach ($produtos as $produto) {
            $variacoes = Variacao::listarPorProduto((int) $produto['id']);
            if (!$mostrarInativos) {
                $variacoes = array_values(array_filter(
                    $variacoes,
                    static fn (array $v): bool => ($v['status'] ?? '') === 'ativo'
                ));
            }

            if ($q !== '') {
                $produtoMatch = stripos((string) $produto['codigo_interno'], $q) !== false
                    || stripos((string) $produto['nome'], $q) !== false;
                if (!$produtoMatch && $variacoes) {
                    $variacoes = array_values(array_filter(
                        $variacoes,
                        static function (array $v) use ($q): bool {
                            return stripos((string) ($v['codigo'] ?? ''), $q) !== false
                                || stripos((string) ($v['nome'] ?? ''), $q) !== false;
                        }
                    ));
                }
            }

            // Anexa custo_unitario próprio quando custo_modo = proprio (custoParaLinha já lê isso).
            foreach ($variacoes as &$v) {
                if (($v['custo_modo'] ?? 'herdar') === 'proprio') {
                    $totV = Composicao::totaisVariacao((int) $v['id'], $produto);
                    $v['custo_unitario'] = $totV['custo_unitario'];
                } else {
                    $v['custo_unitario'] = null; // força herança do produto
                }
            }
            unset($v);

            $grupos[] = [
                'produto' => $produto,
                'variacoes' => $variacoes,
            ];
        }

        return [
            'items' => $grupos,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Monta linhas de exibição com preços por margem.
     * Preços e custos continuam unitários; qtd_pacote é só para visualização alternativa.
     *
     * @return list<array{
     *   tipo:string,
     *   produto:array,
     *   variacao:?array,
     *   label:string,
     *   codigo:string,
     *   custo:?float,
     *   precos:?array,
     *   qtd_pacote:?int
     * }>
     */
    public static function montarLinhas(array $grupos, array $config, array $margens): array
    {
        $imposto = (float) $config['imposto_percentual'];
        $comissao = !empty($config['incluir_comissao']) ? (float) $config['comissao_percentual'] : 0.0;
        $linhas = [];

        foreach ($grupos as $grupo) {
            $produto = $grupo['produto'];
            $variacoes = $grupo['variacoes'] ?? [];

            if ($variacoes) {
                $estoqueTotal = 0;
                foreach ($variacoes as $v) {
                    $estoqueTotal += (int) ($v['estoque'] ?? 0);
                }

                $linhas[] = [
                    'tipo' => 'grupo',
                    'produto' => $produto,
                    'variacao' => null,
                    'label' => (string) $produto['nome'],
                    'codigo' => (string) $produto['codigo_interno'],
                    'estoque' => $estoqueTotal,
                    'custo' => null,
                    'precos' => null,
                    'qtd_pacote' => null,
                ];

                foreach ($variacoes as $variacao) {
                    $custo = self::custoParaLinha($produto, $variacao);
                    $linhas[] = [
                        'tipo' => 'variacao',
                        'produto' => $produto,
                        'variacao' => $variacao,
                        'label' => (string) $variacao['nome'],
                        'codigo' => (string) $variacao['codigo'],
                        'estoque' => (int) ($variacao['estoque'] ?? 0),
                        'custo' => $custo,
                        'precos' => self::precosPorMargens($custo, $imposto, $comissao, $margens),
                        'qtd_pacote' => quantidade_pacote_resolvida($produto, $variacao),
                    ];
                }
            } else {
                $custo = self::custoParaLinha($produto, null);
                $linhas[] = [
                    'tipo' => 'produto',
                    'produto' => $produto,
                    'variacao' => null,
                    'label' => (string) $produto['nome'],
                    'codigo' => (string) $produto['codigo_interno'],
                    'estoque' => null,
                    'custo' => $custo,
                    'precos' => self::precosPorMargens($custo, $imposto, $comissao, $margens),
                    'qtd_pacote' => quantidade_pacote_resolvida($produto, null),
                ];
            }
        }

        return $linhas;
    }

    /**
     * @return array<int, array{preco:?float, erro:?string, margem:float}>
     */
    private static function precosPorMargens(float $custo, float $imposto, float $comissao, array $margens): array
    {
        $precos = [];
        foreach ($margens as $m) {
            $mid = (int) $m['id'];
            $margem = (float) $m['margem_percentual'];
            $preco = TabelaPrecoCalculo::calcularPreco($custo, $imposto, $comissao, $margem);
            $precos[$mid] = [
                'preco' => $preco,
                'erro' => $preco === null ? TabelaPrecoCalculo::mensagemErroSoma() : null,
                'margem' => $margem,
            ];
        }
        return $precos;
    }
}

<?php

declare(strict_types=1);

/**
 * Consultas somente leitura da Central de Relatórios de Estoque.
 * Regras de nível, pacotes e ordenação vêm de Variacao, helpers e dos models de Estoque.
 */
final class RelatorioEstoque
{
    public const SITUACOES_VARIACAO = [
        '' => 'Todas',
        'com_estoque' => 'Com estoque',
        'zerado' => 'Zerado',
        'baixo' => 'Baixo',
    ];

    public const SITUACOES_ITEM = [
        '' => 'Todas',
        'com_estoque' => 'Com estoque',
        'zerado' => 'Zerado',
    ];

    public const TIPOS_PECA = [
        'tampa' => 'Tampa',
        'fundo' => 'Fundo',
        'outra' => 'Outra',
    ];

    public const TIPOS_MOVIMENTO = [
        'entrada' => 'Entrada',
        'saida' => 'Saída',
        'ajuste' => 'Ajuste',
    ];

    public const ESTOQUES = [
        '' => 'Todos',
        'pecas' => 'Peças',
        'papeis' => 'Papéis',
    ];

    /** Itens sem limite prático para reaproveitar paginate() dos models de Estoque sem paginar. */
    private const SEM_PAGINACAO = 1000000;

    /**
     * Variações com produto pai, nível (Variacao::nivelEstoque) e pacotes (helpers da Tabela de Preços).
     * Indicadores consideram todos os filtros exceto "situação".
     *
     * @return array{linhas:list<array<string, mixed>>, indicadores:array{total:int, com_estoque:int, zerado:int, baixo:int}}
     */
    public static function variacoes(array $f): array
    {
        $where = [];
        $params = [];
        if ($f['produto_id'] !== null) {
            $where[] = 'v.produto_id = :produto_id';
            $params['produto_id'] = $f['produto_id'];
        }
        if ($f['variacao'] !== '') {
            $where[] = 'v.nome LIKE :variacao';
            $params['variacao'] = '%' . $f['variacao'] . '%';
        }
        if ($f['codigo'] !== '') {
            $where[] = 'v.codigo LIKE :codigo';
            $params['codigo'] = '%' . $f['codigo'] . '%';
        }
        if ($f['status'] !== '') {
            $where[] = 'v.status = :status';
            $params['status'] = $f['status'];
        }
        if ($f['colecao_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM colecao_variacoes cv WHERE cv.variacao_id = v.id AND cv.colecao_id = :colecao_id)';
            $params['colecao_id'] = $f['colecao_id'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT v.id, v.codigo, v.nome, v.estoque, v.status, v.pacote_modo, v.quantidade_pacote,
                    v.gtin_unidade, v.gtin_pacote,
                    p.id AS produto_id, p.nome AS produto_nome, p.codigo_interno AS produto_codigo,
                    p.quantidade_pacote AS produto_quantidade_pacote
             FROM produto_variacoes v
             INNER JOIN produtos p ON p.id = v.produto_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        usort($rows, static function (array $a, array $b): int {
            $cmp = cmp_natural_produto(
                ['nome' => $a['produto_nome'], 'codigo_interno' => $a['produto_codigo'], 'id' => $a['produto_id']],
                ['nome' => $b['produto_nome'], 'codigo_interno' => $b['produto_codigo'], 'id' => $b['produto_id']]
            );
            return $cmp !== 0 ? $cmp : cmp_natural_variacao($a, $b);
        });

        $indicadores = ['total' => 0, 'com_estoque' => 0, 'zerado' => 0, 'baixo' => 0];
        $linhas = [];
        foreach ($rows as $r) {
            $estoque = (int) $r['estoque'];
            $nivel = Variacao::nivelEstoque($estoque);
            $indicadores['total']++;
            if ($estoque > 0) {
                $indicadores['com_estoque']++;
            }
            if ($nivel === 'zerado') {
                $indicadores['zerado']++;
            } elseif ($nivel === 'baixo') {
                $indicadores['baixo']++;
            }

            $passa = match ($f['situacao']) {
                'com_estoque' => $estoque > 0,
                'zerado' => $nivel === 'zerado',
                'baixo' => $nivel === 'baixo',
                default => true,
            };
            if (!$passa) {
                continue;
            }

            $qtdPacote = quantidade_pacote_resolvida(
                ['quantidade_pacote' => $r['produto_quantidade_pacote']],
                ['pacote_modo' => $r['pacote_modo'], 'quantidade_pacote' => $r['quantidade_pacote']]
            );
            $r['nivel'] = $nivel;
            $r['qtd_pacote'] = $qtdPacote;
            $r['pacotes'] = pacotes_completos($estoque, $qtdPacote);
            $linhas[] = $r;
        }

        return ['linhas' => $linhas, 'indicadores' => $indicadores];
    }

    /**
     * Peças via EstoquePeca::paginate (mesma consulta e ordenação do módulo), com filtros extras.
     *
     * @return array{linhas:list<array<string, mixed>>, indicadores:array<string, mixed>}
     */
    public static function pecas(array $f): array
    {
        $base = EstoquePeca::paginate(['status' => $f['status'], 'tipo' => $f['tipo']], 1, self::SEM_PAGINACAO)['items'];
        $base = array_values(array_filter($base, static function (array $r) use ($f): bool {
            return ($f['produto_id'] === null || (int) ($r['produto_id'] ?? 0) === $f['produto_id'])
                && self::contem($r['cor'] ?? null, $f['cor'])
                && self::contem($r['estampa'] ?? null, $f['estampa'])
                && self::contem($r['localizacao'] ?? null, $f['local']);
        }));
        return self::itensComSituacao($base, $f['situacao']);
    }

    /**
     * Papéis via EstoquePapel::paginate (mesma consulta e ordenação do módulo), com filtros extras.
     *
     * @return array{linhas:list<array<string, mixed>>, indicadores:array<string, mixed>}
     */
    public static function papeis(array $f): array
    {
        $base = EstoquePapel::paginate(['status' => $f['status']], 1, self::SEM_PAGINACAO)['items'];
        $base = array_values(array_filter($base, static function (array $r) use ($f): bool {
            return self::contem($r['cor'] ?? null, $f['cor'])
                && self::contem($r['formato'] ?? null, $f['formato'])
                && self::contem($r['gramatura'] ?? null, $f['gramatura'])
                && self::contem($r['localizacao'] ?? null, $f['local']);
        }));
        return self::itensComSituacao($base, $f['situacao']);
    }

    /**
     * Movimentos de peças e papéis, mais recentes primeiro, paginados no banco.
     *
     * @return array{items:list<array<string, mixed>>, total:int, page:int, pages:int}
     */
    public static function movimentacoes(array $f, int $page, int $perPage = 50): array
    {
        $partes = [];
        $params = [];
        foreach (['pecas' => ['estoque_pecas_movimentos', 'estoque_pecas', 'peca_id'], 'papeis' => ['estoque_papeis_movimentos', 'estoque_papeis', 'papel_id']] as $estoque => [$tabMov, $tabItem, $fk]) {
            if ($f['estoque'] !== '' && $f['estoque'] !== $estoque) {
                continue;
            }
            $w = [];
            if ($f['inicio'] !== null) {
                $w[] = "m.created_at >= :{$estoque}_inicio";
                $params["{$estoque}_inicio"] = $f['inicio'] . ' 00:00:00';
            }
            if ($f['fim'] !== null) {
                $w[] = "m.created_at <= :{$estoque}_fim";
                $params["{$estoque}_fim"] = $f['fim'] . ' 23:59:59';
            }
            if ($f['tipo'] !== '') {
                $w[] = "m.tipo = :{$estoque}_tipo";
                $params["{$estoque}_tipo"] = $f['tipo'];
            }
            if ($f['item'] !== '') {
                $w[] = "(i.codigo LIKE :{$estoque}_item_cod OR i.nome LIKE :{$estoque}_item_nome)";
                $params["{$estoque}_item_cod"] = '%' . $f['item'] . '%';
                $params["{$estoque}_item_nome"] = '%' . $f['item'] . '%';
            }
            if ($f['usuario_id'] !== null) {
                $w[] = "m.usuario_id = :{$estoque}_usuario";
                $params["{$estoque}_usuario"] = $f['usuario_id'];
            }
            $partes[] = "SELECT '{$estoque}' AS estoque, m.id, m.tipo, m.quantidade, m.saldo_anterior, m.saldo_apos,
                                m.observacao, m.created_at, i.codigo AS item_codigo, i.nome AS item_nome,
                                i.unidade AS item_unidade, u.nome AS usuario_nome
                         FROM {$tabMov} m
                         INNER JOIN {$tabItem} i ON i.id = m.{$fk}
                         LEFT JOIN usuarios u ON u.id = m.usuario_id"
                . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
        }

        $union = implode(' UNION ALL ', $partes);
        $pdo = Database::pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM ({$union}) x");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare("SELECT * FROM ({$union}) x ORDER BY created_at DESC, estoque ASC, id DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Histórico de movimentos de um estoque (peças ou papéis) em ordem cronológica, para o PDF do módulo Estoque.
     * Filtros: inicio/fim (Y-m-d ou null = sem limite), item_id (null = todos), tipo ('' = todos).
     * Com $limite, devolve no máximo $limite + 1 linhas, para quem chama saber que passou do limite.
     *
     * @return list<array<string, mixed>>
     */
    public static function historicoMovimentos(string $estoque, array $f, int $limite): array
    {
        [$tabMov, $tabItem, $fk] = match ($estoque) {
            'pecas' => ['estoque_pecas_movimentos', 'estoque_pecas', 'peca_id'],
            'papeis' => ['estoque_papeis_movimentos', 'estoque_papeis', 'papel_id'],
            default => throw new InvalidArgumentException('Estoque inválido.'),
        };

        $where = [];
        $params = [];
        if ($f['inicio'] !== null) {
            $where[] = 'm.created_at >= :inicio';
            $params['inicio'] = $f['inicio'] . ' 00:00:00';
        }
        if ($f['fim'] !== null) {
            $where[] = 'm.created_at <= :fim';
            $params['fim'] = $f['fim'] . ' 23:59:59';
        }
        if ($f['item_id'] !== null) {
            $where[] = "m.{$fk} = :item_id";
            $params['item_id'] = $f['item_id'];
        }
        if ($f['tipo'] !== '') {
            $where[] = 'm.tipo = :tipo';
            $params['tipo'] = $f['tipo'];
        }

        $stmt = Database::pdo()->prepare(
            "SELECT m.id, m.tipo, m.quantidade, m.saldo_anterior, m.saldo_apos, m.observacao, m.created_at,
                    i.codigo AS item_codigo, i.nome AS item_nome, i.unidade AS item_unidade, u.nome AS usuario_nome
             FROM {$tabMov} m
             INNER JOIN {$tabItem} i ON i.id = m.{$fk}
             LEFT JOIN usuarios u ON u.id = m.usuario_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY m.created_at ASC, m.id ASC LIMIT ' . ($limite + 1)
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Saldo de cada item no fim do dia de referência = saldo_apos do último movimento até então.
     * Itens sem movimento até a data ainda não existiam no estoque e não aparecem.
     *
     * @return list<array<string, mixed>>
     */
    public static function posicaoEmData(string $data, string $estoque, string $local): array
    {
        $ate = $data . ' 23:59:59';
        $pdo = Database::pdo();
        $linhas = [];

        $consultas = [
            'pecas' => ['estoque_pecas', 'estoque_pecas_movimentos', 'peca_id',
                'i.tipo, i.cor, i.estampa, p.nome AS produto_nome', 'LEFT JOIN produtos p ON p.id = i.produto_id'],
            'papeis' => ['estoque_papeis', 'estoque_papeis_movimentos', 'papel_id',
                'i.cor, i.formato, i.gramatura', ''],
        ];
        foreach ($consultas as $chave => [$tabItem, $tabMov, $fk, $extras, $join]) {
            if ($estoque !== '' && $estoque !== $chave) {
                continue;
            }
            $params = ['ate' => $ate];
            $whereLocal = '';
            if ($local !== '') {
                $whereLocal = ' AND i.localizacao LIKE :local';
                $params['local'] = '%' . $local . '%';
            }
            $stmt = $pdo->prepare(
                "SELECT i.id, i.codigo, i.nome, i.unidade, i.localizacao, i.status, {$extras},
                        m.saldo_apos AS saldo_na_data, m.created_at AS ultimo_movimento
                 FROM {$tabItem} i
                 {$join}
                 INNER JOIN {$tabMov} m ON m.id = (
                     SELECT m2.id FROM {$tabMov} m2
                     WHERE m2.{$fk} = i.id AND m2.created_at <= :ate
                     ORDER BY m2.created_at DESC, m2.id DESC
                     LIMIT 1
                 )
                 WHERE 1 = 1{$whereLocal}"
            );
            $stmt->execute($params);
            $itens = $stmt->fetchAll();
            usort($itens, static function (array $a, array $b): int {
                $cmp = strnatcasecmp((string) $a['nome'], (string) $b['nome']);
                return $cmp !== 0 ? $cmp : strnatcasecmp((string) $a['codigo'], (string) $b['codigo']);
            });
            foreach ($itens as $item) {
                $item['estoque'] = $chave;
                $linhas[] = $item;
            }
        }
        return $linhas;
    }

    /** @return list<array<string, mixed>> */
    public static function colecoesOpcoes(): array
    {
        return Database::pdo()
            ->query('SELECT id, codigo, nome, status FROM colecoes ORDER BY nome ASC, id ASC')
            ->fetchAll();
    }

    /**
     * Situação (com estoque/zerado) e indicadores para peças/papéis.
     * Quantidade total só quando todos os itens filtrados usam a mesma unidade.
     */
    private static function itensComSituacao(array $itens, string $situacao): array
    {
        $ind = ['total' => 0, 'com_estoque' => 0, 'zerado' => 0, 'quantidade_total' => null, 'unidade' => null];
        $soma = 0.0;
        $unidades = [];
        $linhas = [];
        foreach ($itens as $r) {
            $qtd = (float) $r['quantidade'];
            $ind['total']++;
            $qtd > 0 ? $ind['com_estoque']++ : $ind['zerado']++;
            $soma += $qtd;
            $unidades[(string) $r['unidade']] = true;

            if (($situacao === 'com_estoque' && $qtd <= 0) || ($situacao === 'zerado' && $qtd > 0)) {
                continue;
            }
            $linhas[] = $r;
        }
        if (count($unidades) === 1) {
            $ind['quantidade_total'] = $soma;
            $ind['unidade'] = array_key_first($unidades);
        }
        return ['linhas' => $linhas, 'indicadores' => $ind];
    }

    private static function contem(?string $valor, string $busca): bool
    {
        return $busca === '' || mb_stripos((string) $valor, $busca) !== false;
    }
}

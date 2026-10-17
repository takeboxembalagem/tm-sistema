<?php

declare(strict_types=1);

/**
 * Documentos PDF do Estoque (inventário e histórico de movimentações) e dos relatórios de estoque.
 * Recebe os dados já consultados pelos mesmos métodos das telas; aqui só há formatação.
 * Quantidades seguem a tela: number_br(..., 0) com a unidade cadastrada do item.
 */
final class EstoqueRelatoriosPdf
{
    private const ESTOQUES = [
        'pecas' => [
            'plural' => 'Peças',
            'singular' => 'Peça',
            'todos' => 'Todas as peças',
            'abrangencia' => 'todas as peças cadastradas (ativas e inativas), inclusive sem saldo',
        ],
        'papeis' => [
            'plural' => 'Papéis',
            'singular' => 'Papel',
            'todos' => 'Todos os papéis',
            'abrangencia' => 'todos os papéis cadastrados (ativos e inativos), inclusive sem saldo',
        ],
    ];

    private const STATUS_ITEM = ['ativo' => 'Ativos', 'inativo' => 'Inativos'];
    private const STATUS_VARIACAO = ['ativo' => 'Ativas', 'inativo' => 'Inativas'];

    /**
     * Inventário completo (ativos e inativos, com e sem saldo) com coluna em branco para a contagem física.
     *
     * @param array{linhas:list<array<string, mixed>>, indicadores:array<string, mixed>} $dados
     */
    public static function inventario(string $estoque, array $dados): DocumentoPdf
    {
        $nomes = self::ESTOQUES[$estoque];
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Inventário de ' . $nomes['plural'], DocumentoPdf::PAISAGEM);
        $doc->filtros([
            'Itens' => $nomes['abrangencia'],
            'Saldo' => 'posição no momento da emissão',
        ]);
        $doc->resumo(self::resumoItens($dados['indicadores']));

        if ($estoque === 'pecas') {
            $colunas = [
                ['titulo' => 'Código', 'peso' => 22, 'ajustar' => true],
                ['titulo' => 'Peça', 'peso' => 50],
                ['titulo' => 'Tipo', 'peso' => 14, 'ajustar' => true],
                ['titulo' => 'Produto', 'peso' => 36],
                ['titulo' => 'Cor', 'peso' => 22],
                ['titulo' => 'Estampa', 'peso' => 26],
                ['titulo' => 'Local', 'peso' => 26],
                ['titulo' => 'Status', 'peso' => 14, 'ajustar' => true],
                ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
                ['titulo' => 'Unidade', 'peso' => 14, 'ajustar' => true],
                ['titulo' => 'Contagem física', 'peso' => 34],
            ];
            $linhas = array_map(static fn (array $r): array => [
                (string) $r['codigo'],
                (string) $r['nome'],
                self::tipoPeca($r['tipo'] ?? null),
                self::texto($r['produto_nome'] ?? null),
                self::texto($r['cor'] ?? null),
                self::texto($r['estampa'] ?? null),
                self::texto($r['localizacao'] ?? null),
                self::status((string) $r['status']),
                number_br($r['quantidade'], 0),
                (string) $r['unidade'],
                '',
            ], $dados['linhas']);
        } else {
            $colunas = [
                ['titulo' => 'Código', 'peso' => 22, 'ajustar' => true],
                ['titulo' => 'Papel', 'peso' => 54],
                ['titulo' => 'Cor', 'peso' => 24],
                ['titulo' => 'Formato', 'peso' => 26],
                ['titulo' => 'Gramatura', 'peso' => 20],
                ['titulo' => 'Local', 'peso' => 28],
                ['titulo' => 'Status', 'peso' => 14, 'ajustar' => true],
                ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
                ['titulo' => 'Unidade', 'peso' => 14, 'ajustar' => true],
                ['titulo' => 'Contagem física', 'peso' => 34],
            ];
            $linhas = array_map(static fn (array $r): array => [
                (string) $r['codigo'],
                (string) $r['nome'],
                self::texto($r['cor'] ?? null),
                self::texto($r['formato'] ?? null),
                self::texto($r['gramatura'] ?? null),
                self::texto($r['localizacao'] ?? null),
                self::status((string) $r['status']),
                number_br($r['quantidade'], 0),
                (string) $r['unidade'],
                '',
            ], $dados['linhas']);
        }

        $doc->tabela($colunas, $linhas, 'Nenhum item cadastrado.');
        $doc->nota(number_br(count($linhas), 0) . ' item(ns). Ordem igual à da tela de Estoque (nome e código). '
            . 'A coluna "Contagem física" fica em branco para a conferência manual.');
        return $doc;
    }

    /**
     * Histórico de movimentações de um estoque, em ordem cronológica.
     *
     * @param array{inicio:?string, fim:?string, item:?array, tipo:string} $f
     * @param list<array<string, mixed>> $linhas
     */
    public static function movimentacoesDoEstoque(string $estoque, array $f, array $linhas): DocumentoPdf
    {
        $nomes = self::ESTOQUES[$estoque];
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Movimentações de ' . $nomes['plural'], DocumentoPdf::PAISAGEM);
        $doc->filtros([
            'Período' => self::periodo($f['inicio'], $f['fim']),
            $nomes['singular'] => $f['item'] !== null ? $f['item']['codigo'] . ' — ' . $f['item']['nome'] : $nomes['todos'],
            'Tipo' => $f['tipo'] !== '' ? RelatorioEstoque::TIPOS_MOVIMENTO[$f['tipo']] : 'Todos',
        ]);

        $porTipo = array_fill_keys(array_keys(RelatorioEstoque::TIPOS_MOVIMENTO), 0);
        foreach ($linhas as $m) {
            $porTipo[$m['tipo']] = ($porTipo[$m['tipo']] ?? 0) + 1;
        }
        $doc->resumo([
            'Movimentações' => number_br(count($linhas), 0),
            'Entradas' => number_br($porTipo['entrada'], 0),
            'Saídas' => number_br($porTipo['saida'], 0),
            'Ajustes' => number_br($porTipo['ajuste'], 0),
        ]);

        $doc->tabela([
            ['titulo' => 'Data', 'peso' => 22, 'ajustar' => true],
            ['titulo' => 'Código', 'peso' => 20, 'ajustar' => true],
            ['titulo' => $nomes['singular'], 'peso' => 44],
            ['titulo' => 'Tipo', 'peso' => 13, 'ajustar' => true],
            ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
            ['titulo' => 'Saldo anterior', 'peso' => 17, 'alinhar' => 'R', 'ajustar' => true],
            ['titulo' => 'Saldo depois', 'peso' => 17, 'alinhar' => 'R', 'ajustar' => true],
            ['titulo' => 'Observação', 'peso' => 56],
            ['titulo' => 'Usuário', 'peso' => 28],
        ], array_map(static fn (array $m): array => [
            DocumentoPdf::dataHora((string) $m['created_at']),
            (string) $m['item_codigo'],
            (string) $m['item_nome'],
            RelatorioEstoque::TIPOS_MOVIMENTO[$m['tipo']] ?? (string) $m['tipo'],
            number_br($m['quantidade'], 0) . ' ' . $m['item_unidade'],
            $m['saldo_anterior'] !== null ? number_br($m['saldo_anterior'], 0) : '—',
            number_br($m['saldo_apos'], 0),
            self::texto($m['observacao'] ?? null),
            self::texto($m['usuario_nome'] ?? null),
        ], $linhas), 'Nenhuma movimentação encontrada com estes filtros.');

        $doc->nota('Ordem cronológica (mais antigas primeiro). Em ajustes, a quantidade registrada é o novo saldo informado.');
        return $doc;
    }

    /** Relatório "Estoque de Variações" com os filtros e indicadores da tela. */
    public static function relatorioVariacoes(array $f, array $resultado): DocumentoPdf
    {
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Estoque de Variações', DocumentoPdf::PAISAGEM);
        $filtros = [];
        if ($f['produto_id'] !== null) {
            $filtros['Produto'] = self::nomeProduto($f['produto_id']);
        }
        if ($f['variacao'] !== '') {
            $filtros['Variação'] = $f['variacao'];
        }
        if ($f['codigo'] !== '') {
            $filtros['Código'] = $f['codigo'];
        }
        if ($f['status'] !== '') {
            $filtros['Status'] = self::STATUS_VARIACAO[$f['status']];
        }
        if ($f['colecao_id'] !== null) {
            $filtros['Coleção'] = self::nomeColecao($f['colecao_id']);
        }
        if ($f['situacao'] !== '') {
            $filtros['Situação'] = RelatorioEstoque::SITUACOES_VARIACAO[$f['situacao']];
        }
        $doc->filtros($filtros);

        $ind = $resultado['indicadores'];
        $doc->resumo([
            'Variações cadastradas' => number_br($ind['total'], 0),
            'Com estoque' => number_br($ind['com_estoque'], 0),
            'Estoque zerado' => number_br($ind['zerado'], 0),
            'Estoque baixo (até ' . Variacao::ESTOQUE_BAIXO . ')' => number_br($ind['baixo'], 0),
        ]);

        $doc->tabela([
            ['titulo' => 'Produto', 'peso' => 34],
            ['titulo' => 'Variação', 'peso' => 34],
            ['titulo' => 'Código', 'peso' => 18, 'ajustar' => true],
            ['titulo' => 'GTIN da unidade individual', 'peso' => 18, 'ajustar' => true],
            ['titulo' => 'GTIN do pacote', 'peso' => 18, 'ajustar' => true],
            ['titulo' => 'Estoque', 'peso' => 13, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
            ['titulo' => 'Pacotes', 'peso' => 13, 'alinhar' => 'R', 'ajustar' => true],
            ['titulo' => 'Situação', 'peso' => 14, 'ajustar' => true],
        ], array_map(static fn (array $v): array => [
            (string) $v['produto_nome'],
            $v['nome'] . ($v['status'] !== 'ativo' ? ' (inativa)' : ''),
            (string) $v['codigo'],
            self::texto($v['gtin_unidade'] ?? null),
            self::texto($v['gtin_pacote'] ?? null),
            number_br((int) $v['estoque'], 0),
            $v['pacotes'] !== null ? number_br($v['pacotes'], 0) : '—',
            match ($v['nivel']) {
                'zerado' => 'Zerado',
                'baixo' => 'Baixo',
                default => 'Normal',
            },
        ], $resultado['linhas']), 'Nenhuma variação encontrada.');

        $doc->nota(number_br(count($resultado['linhas']), 0) . ' variação(ões) listada(s). '
            . 'Pacotes = pacotes completos, pela mesma regra da Tabela de Preços.');
        return $doc;
    }

    /** Relatório "Estoque de Peças" com os filtros e indicadores da tela. */
    public static function relatorioPecas(array $f, array $resultado): DocumentoPdf
    {
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Estoque de Peças', DocumentoPdf::PAISAGEM);
        $filtros = [];
        if ($f['tipo'] !== '') {
            $filtros['Tipo'] = RelatorioEstoque::TIPOS_PECA[$f['tipo']];
        }
        if ($f['produto_id'] !== null) {
            $filtros['Produto'] = self::nomeProduto($f['produto_id']);
        }
        foreach (['cor' => 'Cor', 'estampa' => 'Estampa', 'local' => 'Local'] as $chave => $rotulo) {
            if ($f[$chave] !== '') {
                $filtros[$rotulo] = $f[$chave];
            }
        }
        self::filtrosStatusSituacao($filtros, $f);
        $doc->filtros($filtros);
        $doc->resumo(self::resumoItens($resultado['indicadores']));

        $doc->tabela([
            ['titulo' => 'Código', 'peso' => 22, 'ajustar' => true],
            ['titulo' => 'Peça', 'peso' => 50],
            ['titulo' => 'Tipo', 'peso' => 14, 'ajustar' => true],
            ['titulo' => 'Produto', 'peso' => 38],
            ['titulo' => 'Cor', 'peso' => 22],
            ['titulo' => 'Estampa', 'peso' => 26],
            ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
            ['titulo' => 'Unidade', 'peso' => 14, 'ajustar' => true],
            ['titulo' => 'Local', 'peso' => 28],
            ['titulo' => 'Status', 'peso' => 14, 'ajustar' => true],
        ], array_map(static fn (array $r): array => [
            (string) $r['codigo'],
            (string) $r['nome'],
            self::tipoPeca($r['tipo'] ?? null),
            self::texto($r['produto_nome'] ?? null),
            self::texto($r['cor'] ?? null),
            self::texto($r['estampa'] ?? null),
            number_br($r['quantidade'], 0),
            (string) $r['unidade'],
            self::texto($r['localizacao'] ?? null),
            self::status((string) $r['status']),
        ], $resultado['linhas']), 'Nenhuma peça encontrada.');

        $doc->nota(number_br(count($resultado['linhas']), 0) . ' peça(s) listada(s).');
        return $doc;
    }

    /** Relatório "Estoque de Papéis" com os filtros e indicadores da tela. */
    public static function relatorioPapeis(array $f, array $resultado): DocumentoPdf
    {
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Estoque de Papéis', DocumentoPdf::PAISAGEM);
        $filtros = [];
        foreach (['cor' => 'Cor', 'formato' => 'Formato', 'gramatura' => 'Gramatura', 'local' => 'Local'] as $chave => $rotulo) {
            if ($f[$chave] !== '') {
                $filtros[$rotulo] = $f[$chave];
            }
        }
        self::filtrosStatusSituacao($filtros, $f);
        $doc->filtros($filtros);
        $doc->resumo(self::resumoItens($resultado['indicadores']));

        $doc->tabela([
            ['titulo' => 'Código', 'peso' => 22, 'ajustar' => true],
            ['titulo' => 'Papel', 'peso' => 56],
            ['titulo' => 'Cor', 'peso' => 26],
            ['titulo' => 'Formato', 'peso' => 28],
            ['titulo' => 'Gramatura', 'peso' => 20],
            ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
            ['titulo' => 'Unidade', 'peso' => 14, 'ajustar' => true],
            ['titulo' => 'Local', 'peso' => 30],
            ['titulo' => 'Status', 'peso' => 14, 'ajustar' => true],
        ], array_map(static fn (array $r): array => [
            (string) $r['codigo'],
            (string) $r['nome'],
            self::texto($r['cor'] ?? null),
            self::texto($r['formato'] ?? null),
            self::texto($r['gramatura'] ?? null),
            number_br($r['quantidade'], 0),
            (string) $r['unidade'],
            self::texto($r['localizacao'] ?? null),
            self::status((string) $r['status']),
        ], $resultado['linhas']), 'Nenhum papel encontrado.');

        $doc->nota(number_br(count($resultado['linhas']), 0) . ' papel(éis) listado(s).');
        return $doc;
    }

    /**
     * Relatório "Movimentações de Peças e Papéis" (mais recentes primeiro, como na tela).
     *
     * @param list<array<string, mixed>> $linhas
     */
    public static function relatorioMovimentacoes(array $f, array $linhas): DocumentoPdf
    {
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Movimentações de Peças e Papéis', DocumentoPdf::PAISAGEM);
        $filtros = [];
        if ($f['inicio'] !== null || $f['fim'] !== null) {
            $filtros['Período'] = self::periodo($f['inicio'], $f['fim']);
        }
        if ($f['estoque'] !== '') {
            $filtros['Estoque'] = RelatorioEstoque::ESTOQUES[$f['estoque']];
        }
        if ($f['tipo'] !== '') {
            $filtros['Tipo'] = RelatorioEstoque::TIPOS_MOVIMENTO[$f['tipo']];
        }
        if ($f['item'] !== '') {
            $filtros['Item'] = $f['item'];
        }
        if ($f['usuario_id'] !== null) {
            $usuario = Usuario::find($f['usuario_id']);
            $filtros['Usuário'] = $usuario !== null ? (string) $usuario['nome'] : 'Usuário #' . $f['usuario_id'];
        }
        $doc->filtros($filtros);

        $doc->tabela([
            ['titulo' => 'Data', 'peso' => 22, 'ajustar' => true],
            ['titulo' => 'Estoque', 'peso' => 13, 'ajustar' => true],
            ['titulo' => 'Tipo', 'peso' => 13, 'ajustar' => true],
            ['titulo' => 'Item', 'peso' => 58],
            ['titulo' => 'Quantidade', 'peso' => 20, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
            ['titulo' => 'Saldo anterior', 'peso' => 17, 'alinhar' => 'R', 'ajustar' => true],
            ['titulo' => 'Saldo depois', 'peso' => 17, 'alinhar' => 'R', 'ajustar' => true],
            ['titulo' => 'Observação', 'peso' => 50],
            ['titulo' => 'Usuário', 'peso' => 27],
        ], array_map(static fn (array $m): array => [
            DocumentoPdf::dataHora((string) $m['created_at']),
            $m['estoque'] === 'pecas' ? 'Peças' : 'Papéis',
            RelatorioEstoque::TIPOS_MOVIMENTO[$m['tipo']] ?? (string) $m['tipo'],
            $m['item_codigo'] . ' — ' . $m['item_nome'],
            number_br($m['quantidade'], 0) . ' ' . $m['item_unidade'],
            $m['saldo_anterior'] !== null ? number_br($m['saldo_anterior'], 0) : '—',
            number_br($m['saldo_apos'], 0),
            self::texto($m['observacao'] ?? null),
            self::texto($m['usuario_nome'] ?? null),
        ], $linhas), 'Nenhuma movimentação encontrada.');

        $doc->nota(number_br(count($linhas), 0) . ' movimentação(ões) encontrada(s). Mais recentes primeiro.');
        return $doc;
    }

    /**
     * Relatório "Posição do Estoque por Data".
     *
     * @param list<array<string, mixed>> $linhas
     */
    public static function relatorioPosicaoEmData(array $f, array $linhas): DocumentoPdf
    {
        $doc = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Posição do Estoque por Data', DocumentoPdf::RETRATO);
        $filtros = ['Data de referência' => DocumentoPdf::data($f['data'])];
        if ($f['estoque'] !== '') {
            $filtros['Estoque'] = RelatorioEstoque::ESTOQUES[$f['estoque']];
        }
        if ($f['local'] !== '') {
            $filtros['Local'] = $f['local'];
        }
        $doc->filtros($filtros);
        $doc->subtitulo('Posição em ' . DocumentoPdf::data($f['data']));

        $doc->tabela([
            ['titulo' => 'Código', 'peso' => 20, 'ajustar' => true],
            ['titulo' => 'Item', 'peso' => 40],
            ['titulo' => 'Tipo / Características', 'peso' => 52],
            ['titulo' => 'Estoque na data', 'peso' => 22, 'alinhar' => 'R', 'negrito' => true, 'ajustar' => true],
        ], array_map(static function (array $r): array {
            if ($r['estoque'] === 'pecas') {
                $carac = array_filter(['Peça · ' . self::tipoPeca($r['tipo'] ?? null), $r['produto_nome'] ?? null, $r['cor'] ?? null, $r['estampa'] ?? null]);
            } else {
                $carac = array_filter(['Papel', $r['cor'] ?? null, $r['formato'] ?? null, $r['gramatura'] ?? null]);
            }
            return [
                (string) $r['codigo'],
                (string) $r['nome'],
                implode(' · ', $carac),
                number_br($r['saldo_na_data'], 0) . ' ' . $r['unidade'],
            ];
        }, $linhas), 'Nenhum item com movimentação até esta data.');

        $doc->nota('Saldo no fim do dia escolhido, conforme o último movimento registrado até então. '
            . 'Itens criados depois dessa data não aparecem. O filtro de local usa a localização atual do item.');
        return $doc;
    }

    /** @return array<string, string> */
    private static function resumoItens(array $ind): array
    {
        if ($ind['quantidade_total'] !== null) {
            $total = number_br($ind['quantidade_total'], 0) . ' ' . $ind['unidade'];
        } elseif ((int) $ind['total'] === 0) {
            $total = '0';
        } else {
            $total = 'Unidades diferentes — não somado';
        }
        return [
            'Itens cadastrados' => number_br($ind['total'], 0),
            'Com estoque' => number_br($ind['com_estoque'], 0),
            'Zerados' => number_br($ind['zerado'], 0),
            'Quantidade total' => $total,
        ];
    }

    private static function filtrosStatusSituacao(array &$filtros, array $f): void
    {
        if ($f['status'] !== '') {
            $filtros['Status'] = self::STATUS_ITEM[$f['status']];
        }
        if ($f['situacao'] !== '') {
            $filtros['Situação'] = RelatorioEstoque::SITUACOES_ITEM[$f['situacao']];
        }
    }

    private static function periodo(?string $inicio, ?string $fim): string
    {
        if ($inicio === null && $fim === null) {
            return 'todo o histórico';
        }
        if ($inicio === null) {
            return 'até ' . DocumentoPdf::data($fim);
        }
        if ($fim === null) {
            return 'a partir de ' . DocumentoPdf::data($inicio);
        }
        return DocumentoPdf::data($inicio) . ' a ' . DocumentoPdf::data($fim);
    }

    private static function nomeProduto(int $id): string
    {
        $p = Produto::find($id);
        return $p !== null ? $p['nome'] . ' (' . $p['codigo_interno'] . ')' : 'Produto #' . $id;
    }

    private static function nomeColecao(int $id): string
    {
        foreach (RelatorioEstoque::colecoesOpcoes() as $c) {
            if ((int) $c['id'] === $id) {
                return (string) $c['nome'];
            }
        }
        return 'Coleção #' . $id;
    }

    private static function tipoPeca(?string $tipo): string
    {
        return RelatorioEstoque::TIPOS_PECA[(string) $tipo] ?? 'Outra';
    }

    private static function status(string $status): string
    {
        return $status === 'ativo' ? 'Ativo' : 'Inativo';
    }

    private static function texto(?string $valor): string
    {
        return $valor !== null && $valor !== '' ? $valor : '—';
    }
}

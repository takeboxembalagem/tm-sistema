<?php

declare(strict_types=1);

/**
 * Central de relatórios. Cada relatório entra no catálogo abaixo;
 * 'rota' = null aparece como "Em desenvolvimento".
 */
final class RelatorioController
{
    private const GRUPOS = [
        [
            'titulo' => 'Estoque',
            'relatorios' => [
                ['titulo' => 'Estoque de Variações', 'descricao' => 'Posição atual do estoque de produtos acabados.', 'rota' => '/relatorios/estoque?aba=variacoes'],
                ['titulo' => 'Estoque de Peças', 'descricao' => 'Posição atual do estoque de peças.', 'rota' => '/relatorios/estoque?aba=pecas'],
                ['titulo' => 'Estoque de Papéis', 'descricao' => 'Posição atual do estoque de papéis.', 'rota' => '/relatorios/estoque?aba=papeis'],
                ['titulo' => 'Movimentações de Peças e Papéis', 'descricao' => 'Entradas, saídas e ajustes registrados.', 'rota' => '/relatorios/estoque?aba=movimentacoes'],
                ['titulo' => 'Posição do Estoque por Data', 'descricao' => 'Consulte a posição histórica de peças e papéis.', 'rota' => '/relatorios/estoque?aba=data'],
            ],
        ],
        [
            'titulo' => 'Produtos e Cadastro',
            'relatorios' => [
                ['titulo' => 'Pendências de Cadastro', 'descricao' => 'Identifique produtos e variações que precisam de complementação.', 'rota' => null],
            ],
        ],
        [
            'titulo' => 'Custos e Preços',
            'relatorios' => [
                ['titulo' => 'Custo × Preço × Margem', 'descricao' => 'Compare custos, preços e margens dos produtos.', 'rota' => null],
            ],
        ],
        [
            'titulo' => 'Coleções',
            'relatorios' => [
                ['titulo' => 'Resultados das Coleções', 'descricao' => 'Consulte produção, vendas e valores registrados nas coleções.', 'rota' => null],
            ],
        ],
    ];

    private const ABAS_ESTOQUE = [
        'variacoes' => 'Variações',
        'pecas' => 'Peças',
        'papeis' => 'Papéis',
        'movimentacoes' => 'Movimentações',
        'data' => 'Posição por Data',
    ];

    private const POR_PAGINA = 50;

    public static function index(): void
    {
        Auth::requireLogin();

        View::render('relatorios/index', [
            'title' => 'Relatórios',
            'page' => 'relatorios',
            'grupos' => self::GRUPOS,
        ]);
    }

    /** Somente leitura: nenhuma ação desta tela altera estoque. */
    public static function estoque(): void
    {
        Auth::requireLogin();

        $aba = (string) ($_GET['aba'] ?? 'variacoes');
        if (!isset(self::ABAS_ESTOQUE[$aba])) {
            $aba = 'variacoes';
        }

        $dados = [
            'title' => 'Estoque',
            'page' => 'relatorios',
            'aba' => $aba,
            'abas' => self::ABAS_ESTOQUE,
        ];

        $f = self::filtrosEstoque($aba);

        switch ($aba) {
            case 'variacoes':
                $resultado = RelatorioEstoque::variacoes($f);
                $dados += [
                    'filtros' => $f,
                    'indicadores' => $resultado['indicadores'],
                    'pagina' => self::paginar($resultado['linhas']),
                    'produtos' => Produto::opcoesSelect(false),
                    'colecoes' => RelatorioEstoque::colecoesOpcoes(),
                ];
                break;

            case 'pecas':
                $resultado = RelatorioEstoque::pecas($f);
                $dados += [
                    'filtros' => $f,
                    'indicadores' => $resultado['indicadores'],
                    'pagina' => self::paginar($resultado['linhas']),
                    'produtos' => Produto::opcoesSelect(false),
                ];
                break;

            case 'papeis':
                $resultado = RelatorioEstoque::papeis($f);
                $dados += [
                    'filtros' => $f,
                    'indicadores' => $resultado['indicadores'],
                    'pagina' => self::paginar($resultado['linhas']),
                ];
                break;

            case 'movimentacoes':
                $dados += [
                    'filtros' => $f,
                    'pagina' => RelatorioEstoque::movimentacoes($f, self::paginaGet(), self::POR_PAGINA),
                    'usuarios' => Usuario::all(),
                ];
                break;

            case 'data':
                $consultado = isset($_GET['data']);
                $data = self::dataGet('data');
                $erro = $consultado && $data === null ? 'Informe uma data de referência válida.' : null;
                $dados += [
                    'filtros' => $f,
                    'consultado' => $consultado && $erro === null,
                    'erroData' => $erro,
                    'linhas' => $consultado && $erro === null
                        ? RelatorioEstoque::posicaoEmData($f['data'], $f['estoque'], $f['local'])
                        : [],
                ];
                break;
        }

        View::render('relatorios/estoque', $dados);
    }

    /**
     * PDF da aba de estoque com os mesmos filtros e consultas da tela, sem paginação. Somente leitura.
     */
    public static function estoquePdf(): void
    {
        Auth::requireLogin();

        $aba = (string) ($_GET['aba'] ?? '');
        if (!isset(self::ABAS_ESTOQUE[$aba])) {
            DocumentoPdf::erro('Relatório não encontrado.', 404, '/relatorios');
            return;
        }
        $voltar = '/relatorios/estoque?aba=' . $aba;
        $f = self::filtrosEstoque($aba);

        switch ($aba) {
            case 'variacoes':
                $montar = static fn (): DocumentoPdf => EstoqueRelatoriosPdf::relatorioVariacoes($f, RelatorioEstoque::variacoes($f));
                break;
            case 'pecas':
                $montar = static fn (): DocumentoPdf => EstoqueRelatoriosPdf::relatorioPecas($f, RelatorioEstoque::pecas($f));
                break;
            case 'papeis':
                $montar = static fn (): DocumentoPdf => EstoqueRelatoriosPdf::relatorioPapeis($f, RelatorioEstoque::papeis($f));
                break;
            case 'movimentacoes':
                $limite = EstoquePdfController::LIMITE_LINHAS;
                $pagina = RelatorioEstoque::movimentacoes($f, 1, $limite);
                if ($pagina['total'] > $limite) {
                    DocumentoPdf::erro(
                        'Há mais de ' . number_br($limite, 0) . ' movimentações com estes filtros, o que deixaria o PDF grande demais. '
                        . 'Reduza o período ou use outros filtros.',
                        422,
                        $voltar
                    );
                    return;
                }
                $montar = static fn (): DocumentoPdf => EstoqueRelatoriosPdf::relatorioMovimentacoes($f, $pagina['items']);
                break;
            default:
                if (self::dataGet('data') === null) {
                    DocumentoPdf::erro('Informe uma data de referência válida.', 422, $voltar);
                    return;
                }
                $montar = static fn (): DocumentoPdf => EstoqueRelatoriosPdf::relatorioPosicaoEmData(
                    $f,
                    RelatorioEstoque::posicaoEmData($f['data'], $f['estoque'], $f['local'])
                );
        }

        DocumentoPdf::responder($montar, DocumentoPdf::nomeArquivo('relatorio-estoque-' . $aba), $voltar);
    }

    /** Filtros de cada aba, lidos e validados do GET (a tela e o PDF usam os mesmos). */
    private static function filtrosEstoque(string $aba): array
    {
        return match ($aba) {
            'variacoes' => [
                'produto_id' => self::idGet('produto_id'),
                'variacao' => self::textoGet('variacao'),
                'codigo' => self::textoGet('codigo'),
                'status' => self::opcaoGet('status', ['ativo', 'inativo']),
                'colecao_id' => self::idGet('colecao_id'),
                'situacao' => self::opcaoGet('situacao', array_keys(RelatorioEstoque::SITUACOES_VARIACAO)),
            ],
            'pecas' => [
                'tipo' => self::opcaoGet('tipo', array_keys(RelatorioEstoque::TIPOS_PECA)),
                'produto_id' => self::idGet('produto_id'),
                'cor' => self::textoGet('cor'),
                'estampa' => self::textoGet('estampa'),
                'local' => self::textoGet('local'),
                'status' => self::opcaoGet('status', ['ativo', 'inativo']),
                'situacao' => self::opcaoGet('situacao', array_keys(RelatorioEstoque::SITUACOES_ITEM)),
            ],
            'papeis' => [
                'cor' => self::textoGet('cor'),
                'formato' => self::textoGet('formato'),
                'gramatura' => self::textoGet('gramatura'),
                'local' => self::textoGet('local'),
                'status' => self::opcaoGet('status', ['ativo', 'inativo']),
                'situacao' => self::opcaoGet('situacao', array_keys(RelatorioEstoque::SITUACOES_ITEM)),
            ],
            'movimentacoes' => [
                'inicio' => self::dataGet('inicio'),
                'fim' => self::dataGet('fim'),
                'estoque' => self::opcaoGet('estoque', array_keys(RelatorioEstoque::ESTOQUES)),
                'tipo' => self::opcaoGet('tipo', array_keys(RelatorioEstoque::TIPOS_MOVIMENTO)),
                'item' => self::textoGet('item'),
                'usuario_id' => self::idGet('usuario_id'),
            ],
            default => [
                'data' => self::dataGet('data') ?? date('Y-m-d'),
                'estoque' => self::opcaoGet('estoque', array_keys(RelatorioEstoque::ESTOQUES)),
                'local' => self::textoGet('local'),
            ],
        };
    }

    /** @return array{items:list<array<string, mixed>>, total:int, page:int, pages:int} */
    private static function paginar(array $linhas): array
    {
        $total = count($linhas);
        $pages = max(1, (int) ceil($total / self::POR_PAGINA));
        $page = max(1, min(self::paginaGet(), $pages));
        return [
            'items' => array_slice($linhas, ($page - 1) * self::POR_PAGINA, self::POR_PAGINA),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    private static function paginaGet(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    private static function textoGet(string $chave): string
    {
        $v = $_GET[$chave] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, 100) : '';
    }

    private static function opcaoGet(string $chave, array $permitidos): string
    {
        $v = self::textoGet($chave);
        return in_array($v, $permitidos, true) ? $v : '';
    }

    private static function idGet(string $chave): ?int
    {
        $v = self::textoGet($chave);
        return preg_match('/^[1-9]\d{0,9}$/', $v) === 1 && (int) $v <= 2147483647 ? (int) $v : null;
    }

    private static function dataGet(string $chave): ?string
    {
        $v = self::textoGet($chave);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }
}

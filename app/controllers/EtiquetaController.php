<?php

declare(strict_types=1);

/**
 * Módulo independente de geração/impressão de etiquetas (modelo SL61083).
 * Não persiste dados; apenas lê Produto/Variação existentes.
 */
final class EtiquetaController
{
    private const QTD_MIN = 1;
    private const QTD_MAX = 500;
    private const POS_MIN = 1;
    private const POS_MAX = 10;
    private const LABELS_PER_SHEET = 10;

    /**
     * Ordem física da SL61083 na grade (coluna-primeiro):
     *  1 | 6
     *  2 | 7
     *  3 | 8
     *  4 | 9
     *  5 | 10
     *
     * @var list<int>
     */
    public const ORDEM_VISUAL = [1, 6, 2, 7, 3, 8, 4, 9, 5, 10];

    public static function index(): void
    {
        Auth::requireLogin();

        $produtoId = (int) ($_GET['produto_id'] ?? 0);
        $variacaoId = (int) ($_GET['variacao_id'] ?? 0);
        $quantidade = (int) ($_GET['quantidade'] ?? 10);
        $posicaoInicial = (int) ($_GET['posicao_inicial'] ?? 1);

        if ($quantidade < self::QTD_MIN || $quantidade > self::QTD_MAX) {
            $quantidade = 10;
        }
        if ($posicaoInicial < self::POS_MIN || $posicaoInicial > self::POS_MAX) {
            $posicaoInicial = 1;
        }

        $produtos = Produto::opcoesSelect(true);
        $variacoes = [];
        $produto = null;
        $variacao = null;
        $etiqueta = null;
        $errors = [];

        if ($produtoId > 0) {
            $produto = Produto::find($produtoId);
            if (!$produto) {
                $errors[] = 'Produto não encontrado.';
                $produtoId = 0;
            } else {
                $variacoes = Variacao::listarPorProduto($produtoId);
            }
        }

        if ($produto && $variacaoId > 0) {
            $variacao = Variacao::find($variacaoId);
            if (!$variacao || (int) $variacao['produto_id'] !== $produtoId) {
                $errors[] = 'Variação inválida para o produto selecionado.';
                $variacao = null;
                $variacaoId = 0;
            } else {
                $etiqueta = self::montarDadosEtiqueta($produto, $variacao);
            }
        }

        View::render('etiquetas/index', [
            'title' => 'Etiquetas',
            'page' => 'etiquetas',
            'produtos' => $produtos,
            'variacoes' => $variacoes,
            'produtoId' => $produtoId,
            'variacaoId' => $variacaoId,
            'quantidade' => $quantidade,
            'posicaoInicial' => $posicaoInicial,
            'etiqueta' => $etiqueta,
            'marca' => self::marcaEtiquetasConfig(),
            'errors' => $errors,
        ]);
    }

    /** JSON: lista de variações do produto (para o select em cascata). */
    public static function variacoesJson(): void
    {
        Auth::requireLogin();

        header('Content-Type: application/json; charset=utf-8');

        $produtoId = (int) ($_GET['produto_id'] ?? 0);
        if ($produtoId <= 0 || !Produto::find($produtoId)) {
            echo json_encode(['variacoes' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        $items = [];
        foreach (Variacao::listarPorProduto($produtoId) as $v) {
            $items[] = [
                'id' => (int) $v['id'],
                'nome' => (string) ($v['nome'] ?? ''),
                'codigo' => (string) ($v['codigo'] ?? ''),
            ];
        }

        echo json_encode(['variacoes' => $items], JSON_UNESCAPED_UNICODE);
    }

    public static function preview(): void
    {
        Auth::requireLogin();
        self::renderFolha(false);
    }

    public static function imprimir(): void
    {
        Auth::requireLogin();
        self::renderFolha(true);
    }

    public static function pdf(): void
    {
        Auth::requireLogin();

        $parsed = self::parseRequest();
        if ($parsed['errors'] || !is_array($parsed['produto']) || !is_array($parsed['variacao'])) {
            http_response_code(422);
            View::render('etiquetas/folha', [
                'title' => 'Etiquetas — erro',
                'errors' => $parsed['errors'] !== [] ? $parsed['errors'] : ['Não foi possível gerar o PDF.'],
                'folhas' => [],
                'etiqueta' => null,
                'marca' => self::marcaEtiquetasConfig(),
                'autoPrint' => false,
                'modo' => 'preview',
                'pdfUrl' => '',
            ], '');
            return;
        }

        $etiqueta = self::montarDadosEtiqueta($parsed['produto'], $parsed['variacao']);
        $folhas = self::montarFolhas($parsed['quantidade'], $parsed['posicaoInicial']);
        $binary = EtiquetaPdf::render(
            $folhas,
            $etiqueta,
            self::marcaEtiquetasConfig(),
            self::logoFilesystemPath(),
            self::ORDEM_VISUAL,
        );

        $filename = self::nomeArquivoPdf($etiqueta['codigo']);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $binary;
        exit;
    }

    private static function renderFolha(bool $autoPrint): void
    {
        $parsed = self::parseRequest();
        if ($parsed['errors']) {
            http_response_code(422);
            View::render('etiquetas/folha', [
                'title' => 'Etiquetas — erro',
                'errors' => $parsed['errors'],
                'folhas' => [],
                'etiqueta' => null,
                'marca' => self::marcaEtiquetasConfig(),
                'autoPrint' => false,
                'modo' => $autoPrint ? 'imprimir' : 'preview',
                'pdfUrl' => '',
            ], '');
            return;
        }

        $etiqueta = self::montarDadosEtiqueta($parsed['produto'], $parsed['variacao']);
        $folhas = self::montarFolhas($parsed['quantidade'], $parsed['posicaoInicial']);
        $pdfUrl = url('/etiquetas/pdf?' . http_build_query([
            'produto_id' => (int) $parsed['produto']['id'],
            'variacao_id' => (int) $parsed['variacao']['id'],
            'quantidade' => $parsed['quantidade'],
            'posicao_inicial' => $parsed['posicaoInicial'],
        ]));

        View::render('etiquetas/folha', [
            'title' => $autoPrint ? 'Imprimir etiquetas' : 'Pré-visualizar etiquetas',
            'errors' => [],
            'folhas' => $folhas,
            'etiqueta' => $etiqueta,
            'marca' => self::marcaEtiquetasConfig(),
            'autoPrint' => $autoPrint,
            'modo' => $autoPrint ? 'imprimir' : 'preview',
            'quantidade' => $parsed['quantidade'],
            'posicaoInicial' => $parsed['posicaoInicial'],
            'pdfUrl' => $pdfUrl,
        ], '');
    }

    /**
     * @return array{
     *   errors: list<string>,
     *   produto: ?array,
     *   variacao: ?array,
     *   quantidade: int,
     *   posicaoInicial: int
     * }
     */
    private static function parseRequest(): array
    {
        $errors = [];
        $produtoId = (int) ($_GET['produto_id'] ?? 0);
        $variacaoId = (int) ($_GET['variacao_id'] ?? 0);
        $quantidade = (int) ($_GET['quantidade'] ?? 0);
        $posicaoInicial = (int) ($_GET['posicao_inicial'] ?? 1);

        $produto = $produtoId > 0 ? Produto::find($produtoId) : null;
        if (!$produto) {
            $errors[] = 'Selecione um produto válido.';
        }

        $variacao = $variacaoId > 0 ? Variacao::find($variacaoId) : null;
        if (!$variacao || ($produto && (int) $variacao['produto_id'] !== (int) $produto['id'])) {
            $errors[] = 'Selecione uma variação válida.';
            $variacao = null;
        }

        if ($quantidade < self::QTD_MIN || $quantidade > self::QTD_MAX) {
            $errors[] = 'Quantidade deve ser entre ' . self::QTD_MIN . ' e ' . self::QTD_MAX . '.';
        }

        if ($posicaoInicial < self::POS_MIN || $posicaoInicial > self::POS_MAX) {
            $errors[] = 'Posição inicial deve ser entre 1 e 10.';
        }

        return [
            'errors' => $errors,
            'produto' => $produto,
            'variacao' => $variacao,
            'quantidade' => $quantidade,
            'posicaoInicial' => $posicaoInicial,
        ];
    }

    /**
     * @return array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int}
     */
    private static function montarDadosEtiqueta(array $produto, array $variacao): array
    {
        $qtd = quantidade_pacote_resolvida($produto, $variacao);
        $tamanho = trim((string) ($produto['tamanho'] ?? ''));

        return [
            'nome' => (string) ($variacao['nome'] ?? ''),
            'codigo' => (string) ($variacao['codigo'] ?? ''),
            'tamanho_montada' => $tamanho,
            'quantidade_pacote' => $qtd,
        ];
    }

    /**
     * Monta folhas Carta com 10 posições (coluna-primeiro: 1–5 esq., 6–10 dir.).
     * Posição inicial N deixa 1..(N-1) vazias na primeira folha.
     *
     * @return list<array<int, bool>> cada folha: pos 1..10 => preenchida?
     */
    private static function montarFolhas(int $quantidade, int $posicaoInicial): array
    {
        $folhas = [];
        $restante = $quantidade;
        $inicio = $posicaoInicial;

        while ($restante > 0) {
            $folha = [];
            for ($p = 1; $p <= self::LABELS_PER_SHEET; $p++) {
                $folha[$p] = false;
            }
            for ($p = $inicio; $p <= self::LABELS_PER_SHEET && $restante > 0; $p++) {
                $folha[$p] = true;
                $restante--;
            }
            $folhas[] = $folha;
            $inicio = 1;
        }

        return $folhas;
    }

    /** @return array{nome:string, cnpj:string, site:string, logo:string} */
    private static function marcaEtiquetasConfig(): array
    {
        $cfg = config('marca_etiquetas', []);
        if (!is_array($cfg)) {
            $cfg = [];
        }

        $logoPath = trim((string) ($cfg['logo'] ?? ''));
        $logoUrl = $logoPath !== '' ? url($logoPath) : '';

        return [
            'nome' => (string) ($cfg['nome'] ?? 'T&M'),
            'cnpj' => (string) ($cfg['cnpj'] ?? ''),
            'site' => (string) ($cfg['site'] ?? ''),
            'logo' => $logoUrl,
        ];
    }

    private static function logoFilesystemPath(): string
    {
        $cfg = config('marca_etiquetas', []);
        if (!is_array($cfg)) {
            return '';
        }

        $configured = trim((string) ($cfg['logo'] ?? ''));
        if ($configured === '') {
            return '';
        }

        $relative = ltrim(str_replace('\\', '/', $configured), '/');
        $absolute = dirname(__DIR__, 2) . '/public/' . $relative;
        $real = realpath($absolute);

        return ($real !== false && is_file($real)) ? $real : '';
    }

    private static function nomeArquivoPdf(string $codigo): string
    {
        $slug = strtolower($codigo);
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'etiqueta';
        }
        if (strlen($slug) > 60) {
            $slug = rtrim(substr($slug, 0, 60), '-');
        }

        return 'etiquetas-' . $slug . '.pdf';
    }
}

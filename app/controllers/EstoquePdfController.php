<?php

declare(strict_types=1);

/**
 * PDFs do módulo Estoque (peças e papéis): inventário atual e histórico de movimentações.
 * Somente leitura; mesma regra de acesso das telas de Estoque (usuário logado).
 */
final class EstoquePdfController
{
    /** Acima disso o PDF de movimentações não é gerado: o usuário reduz o período ou escolhe um item. */
    public const LIMITE_LINHAS = 10000;

    private const ESTOQUES = ['pecas', 'papeis'];

    public static function inventario(string $estoque): void
    {
        Auth::requireLogin();
        self::validarEstoque($estoque);

        $semFiltro = ['status' => '', 'situacao' => '', 'cor' => '', 'local' => ''];
        DocumentoPdf::responder(
            static fn (): DocumentoPdf => EstoqueRelatoriosPdf::inventario(
                $estoque,
                $estoque === 'pecas'
                    ? RelatorioEstoque::pecas($semFiltro + ['tipo' => '', 'produto_id' => null, 'estampa' => ''])
                    : RelatorioEstoque::papeis($semFiltro + ['formato' => '', 'gramatura' => ''])
            ),
            DocumentoPdf::nomeArquivo('inventario-' . $estoque),
            '/estoque/' . $estoque
        );
    }

    public static function movimentacoes(string $estoque): void
    {
        Auth::requireLogin();
        self::validarEstoque($estoque);
        $voltar = '/estoque/' . $estoque;

        $erro = null;
        $f = self::filtrosMovimentacoes($estoque, $erro);
        if ($erro !== null) {
            DocumentoPdf::erro($erro, 422, $voltar);
            return;
        }

        $linhas = RelatorioEstoque::historicoMovimentos($estoque, [
            'inicio' => $f['inicio'],
            'fim' => $f['fim'],
            'item_id' => $f['item'] !== null ? (int) $f['item']['id'] : null,
            'tipo' => $f['tipo'],
        ], self::LIMITE_LINHAS);
        if (count($linhas) > self::LIMITE_LINHAS) {
            DocumentoPdf::erro(
                'Há mais de ' . number_br(self::LIMITE_LINHAS, 0) . ' movimentações com estes filtros, o que deixaria o PDF grande demais. '
                . 'Reduza o período ou escolha um item específico.',
                422,
                $voltar
            );
            return;
        }

        DocumentoPdf::responder(
            static fn (): DocumentoPdf => EstoqueRelatoriosPdf::movimentacoesDoEstoque($estoque, $f, $linhas),
            DocumentoPdf::nomeArquivo('movimentacoes-' . $estoque),
            $voltar
        );
    }

    /**
     * Período padrão = mês atual. "todo=1" ignora as datas. Item e tipo são opcionais.
     *
     * @return array{inicio:?string, fim:?string, item:?array, tipo:string}
     */
    private static function filtrosMovimentacoes(string $estoque, ?string &$erro): array
    {
        $f = ['inicio' => null, 'fim' => null, 'item' => null, 'tipo' => ''];

        if (self::get('todo') !== '1') {
            $inicio = self::get('inicio');
            $fim = self::get('fim');
            $f['inicio'] = $inicio === '' ? date('Y-m-01') : self::data($inicio);
            $f['fim'] = $fim === '' ? date('Y-m-t') : self::data($fim);
            if ($f['inicio'] === null || $f['fim'] === null) {
                $erro = 'Informe datas válidas para o período.';
                return $f;
            }
            if ($f['inicio'] > $f['fim']) {
                $erro = 'A data inicial não pode ser posterior à data final.';
                return $f;
            }
        }

        $itemId = self::get('item_id');
        if ($itemId !== '') {
            $item = preg_match('/^[1-9]\d{0,9}$/', $itemId) === 1 && (int) $itemId <= 2147483647
                ? ($estoque === 'pecas' ? EstoquePeca::find((int) $itemId) : EstoquePapel::find((int) $itemId))
                : null;
            if ($item === null) {
                $erro = $estoque === 'pecas' ? 'Peça não encontrada.' : 'Papel não encontrado.';
                return $f;
            }
            $f['item'] = $item;
        }

        $tipo = self::get('tipo');
        if ($tipo !== '' && !isset(RelatorioEstoque::TIPOS_MOVIMENTO[$tipo])) {
            $erro = 'Tipo de movimentação inválido.';
            return $f;
        }
        $f['tipo'] = $tipo;

        return $f;
    }

    private static function validarEstoque(string $estoque): void
    {
        if (!in_array($estoque, self::ESTOQUES, true)) {
            throw new InvalidArgumentException('Estoque inválido.');
        }
    }

    private static function get(string $chave): string
    {
        $v = $_GET[$chave] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private static function data(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }
}

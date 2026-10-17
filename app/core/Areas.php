<?php

declare(strict_types=1);

/**
 * Área principal do sistema (produtos, preços, estoque, agenda, etc.).
 * O menu só esconde o que o usuário não pode abrir; quem bloqueia é exigir()/exigirPorCaminho(), no servidor.
 */
final class Areas
{
    public const PAPEL_MAGENTA = 'papel_magenta';

    /**
     * @return array<string, array{nome:string, descricao:string, sigla:string, icone:string, inicio:string,
     *     prefixo:?string, itens:list<array{page:string, url:string, icone:string, rotulo:string}>}>
     */
    public static function todas(): array
    {
        return [
            self::PAPEL_MAGENTA => [
                'nome' => 'T&M',
                'descricao' => 'Produtos e preços',
                'sigla' => 'T&M',
                'icone' => '📦',
                'inicio' => '/produtos',
                'prefixo' => null,
                'itens' => [
                    ['page' => 'produtos', 'url' => '/produtos', 'icone' => '📦', 'rotulo' => 'Produtos'],
                    ['page' => 'custos', 'url' => '/custos', 'icone' => '💰', 'rotulo' => 'Custos'],
                    ['page' => 'tabela', 'url' => '/tabela-precos', 'icone' => '📊', 'rotulo' => 'Tabela de Preços'],
                    ['page' => 'colecoes', 'url' => '/colecoes', 'icone' => '🎨', 'rotulo' => 'Coleções'],
                    ['page' => 'estoque', 'url' => '/estoque/pecas', 'icone' => '🗃️', 'rotulo' => 'Estoque'],
                    ['page' => 'etiquetas', 'url' => '/etiquetas', 'icone' => '🏷️', 'rotulo' => 'Etiquetas'],
                    ['page' => 'arquivos', 'url' => '/arquivos', 'icone' => '📁', 'rotulo' => 'Arquivos'],
                    ['page' => 'agenda', 'url' => '/agenda', 'icone' => '📅', 'rotulo' => 'Agenda'],
                    ['page' => 'relatorios', 'url' => '/relatorios', 'icone' => '📈', 'rotulo' => 'Relatórios'],
                    ['page' => 'empresa', 'url' => '/empresa', 'icone' => '🏢', 'rotulo' => 'Empresa'],
                    ['page' => 'configuracoes', 'url' => '/configuracoes', 'icone' => '⚙️', 'rotulo' => 'Configurações'],
                ],
            ],
        ];
    }

    /** Área a que pertence a tela ($page); telas sem área conhecida ficam na área principal. */
    public static function daPagina(string $page): string
    {
        foreach (self::todas() as $chave => $area) {
            foreach ($area['itens'] as $item) {
                if ($item['page'] === $page) {
                    return $chave;
                }
            }
        }
        return self::PAPEL_MAGENTA;
    }

    /**
     * Área cujo prefixo de URL contém o caminho.
     * Nesta edição só existe a área principal (sem prefixo dedicado) — sempre null.
     */
    public static function doCaminho(string $path): ?string
    {
        return null;
    }

    /** Área principal: qualquer usuário logado (as restrições de admin seguem nos controllers). */
    public static function podeAcessar(string $area): bool
    {
        if (!Auth::check()) {
            return false;
        }
        return $area === self::PAPEL_MAGENTA;
    }

    /** @return array<string, array> áreas que o usuário logado pode abrir, na ordem do menu. */
    public static function visiveis(): array
    {
        return array_filter(self::todas(), static fn (string $chave): bool => self::podeAcessar($chave), ARRAY_FILTER_USE_KEY);
    }

    /** Sem login → tela de login; logado sem permissão → 403 (sem redirecionar para outra área). */
    public static function exigir(string $area): void
    {
        Auth::requireLogin();
        if (!self::podeAcessar($area)) {
            self::negar();
        }
    }

    /** Proteção pelo prefixo da URL (nesta edição não há prefixos de área secundária). */
    public static function exigirPorCaminho(string $path): void
    {
        $area = self::doCaminho($path);
        if ($area !== null) {
            self::exigir($area);
        }
    }

    private static function negar(): void
    {
        http_response_code(403);
        View::render('pages/forbidden', [
            'title' => 'Acesso negado',
            'page' => '',
        ]);
        exit;
    }
}

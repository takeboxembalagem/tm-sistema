<?php

declare(strict_types=1);

final class TabelaPrecoController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'mostrar_inativos' => isset($_GET['mostrar_inativos']),
        ];

        $precoView = (string) ($_GET['preco_view'] ?? 'unitario');
        if ($precoView !== 'pacote') {
            $precoView = 'unitario';
        }

        $config = TabelaPreco::config();
        $margens = TabelaPreco::margens();
        $result = TabelaPreco::listarGrupos($filters, (int) ($_GET['page'] ?? 1), 40);
        $linhas = TabelaPreco::montarLinhas($result['items'], $config, $margens);

        View::render('tabela_precos/index', [
            'title' => 'Tabela de Preços',
            'page' => 'tabela',
            'config' => $config,
            'margens' => $margens,
            'linhas' => $linhas,
            'total' => $result['total'],
            'pageNum' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'precoView' => $precoView,
            'success' => flash('success'),
            'errors' => flash('errors') ?? [],
        ]);
    }

    public static function salvarConfig(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $imposto = parse_money_br((string) ($_POST['imposto_percentual'] ?? '9,50'));
        $incluir = isset($_POST['incluir_comissao']);
        $comissao = parse_money_br((string) ($_POST['comissao_percentual'] ?? '10,00'));

        $errors = [];
        if ($imposto === null || $imposto < 0) {
            $errors[] = 'Informe um imposto válido (0 ou maior).';
        }
        if ($comissao === null || $comissao < 0) {
            $errors[] = 'Informe uma comissão válida (0 ou maior).';
        }
        $comEfetiva = $incluir ? (float) ($comissao ?? 0) : 0.0;
        if ($imposto !== null && ($imposto + $comEfetiva) >= 100) {
            $errors[] = TabelaPrecoCalculo::mensagemErroSoma();
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/tabela-precos');
        }

        TabelaPreco::salvarConfig((float) $imposto, $incluir, (float) $comissao);
        flash('success', 'Configuração atualizada. Os preços foram recalculados.');
        redirect('/tabela-precos');
    }

    public static function addMargem(): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $config = TabelaPreco::config();
        $margem = parse_money_br((string) ($_POST['margem_percentual'] ?? ''));
        $errors = [];
        if ($margem === null || $margem < 0) {
            $errors[] = 'Informe uma margem válida (0 ou maior).';
        } else {
            $comissao = !empty($config['incluir_comissao']) ? (float) $config['comissao_percentual'] : 0.0;
            if (!TabelaPrecoCalculo::somaValida((float) $config['imposto_percentual'], $comissao, (float) $margem)) {
                $errors[] = TabelaPrecoCalculo::mensagemErroSoma();
            }
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/tabela-precos#margens');
        }

        try {
            TabelaPreco::addMargem((float) $margem);
            flash('success', 'Margem adicionada.');
        } catch (Throwable $e) {
            flash('errors', ['Esta margem já existe.']);
        }
        redirect('/tabela-precos#margens');
    }

    public static function updateMargem(int $margemId): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $config = TabelaPreco::config();
        $margem = parse_money_br((string) ($_POST['margem_percentual'] ?? ''));
        if ($margem === null || $margem < 0) {
            flash('errors', ['Informe uma margem válida (0 ou maior).']);
            redirect('/tabela-precos#margens');
        }

        $comissao = !empty($config['incluir_comissao']) ? (float) $config['comissao_percentual'] : 0.0;
        if (!TabelaPrecoCalculo::somaValida((float) $config['imposto_percentual'], $comissao, (float) $margem)) {
            flash('errors', [TabelaPrecoCalculo::mensagemErroSoma()]);
            redirect('/tabela-precos#margens');
        }

        try {
            TabelaPreco::updateMargem($margemId, (float) $margem);
            flash('success', 'Margem atualizada.');
        } catch (Throwable $e) {
            flash('errors', ['Não foi possível atualizar a margem.']);
        }
        redirect('/tabela-precos#margens');
    }

    public static function deleteMargem(int $margemId): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        if (!TabelaPreco::deleteMargem($margemId)) {
            flash('errors', ['É necessário manter pelo menos uma margem na Tabela de Preços.']);
            redirect('/tabela-precos#margens');
        }
        flash('success', 'Margem removida.');
        redirect('/tabela-precos#margens');
    }
}

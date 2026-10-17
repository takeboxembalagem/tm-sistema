<?php

declare(strict_types=1);

final class PrecoController
{
    public static function update(int $produtoId): void
    {
        Auth::requireAdmin();
        Csrf::verify();

        $produto = Produto::find($produtoId);
        if (!$produto) {
            http_response_code(404);
            View::render('pages/not_found', ['title' => 'Produto não encontrado', 'page' => 'produtos']);
            return;
        }

        $modo = (string) ($_POST['modo_preco'] ?? 'margem');
        $margemRaw = trim((string) ($_POST['margem_desejada'] ?? ''));
        $markupRaw = trim((string) ($_POST['markup'] ?? ''));
        $impostoRaw = trim((string) ($_POST['imposto_percentual'] ?? ''));
        $comissaoRaw = trim((string) ($_POST['comissao_percentual'] ?? ''));
        $praticadoRaw = trim((string) ($_POST['preco_praticado'] ?? ''));
        $sincronizar = isset($_POST['sincronizar_calculado']) || ($praticadoRaw === '' && $modo !== 'manual');

        $errors = [];
        if (!in_array($modo, ['margem', 'markup', 'manual'], true)) {
            $errors[] = 'Selecione um método de formação válido.';
            $modo = 'margem';
        }

        $totais = Composicao::totais($produtoId);
        $custo = (float) $totais['custo_unitario'];

        $margem = parse_money_br($margemRaw !== '' ? $margemRaw : (string) PrecoFormacao::margemPadrao());
        $markup = parse_money_br($markupRaw !== '' ? $markupRaw : (string) PrecoFormacao::markupPadrao());
        $imposto = parse_money_br($impostoRaw !== '' ? $impostoRaw : (string) PrecoFormacao::impostoPadrao());
        $comissao = parse_money_br($comissaoRaw !== '' ? $comissaoRaw : (string) PrecoFormacao::comissaoPadrao());

        if ($imposto === null || $imposto < 0) {
            $errors[] = 'Informe um imposto válido (0 ou maior).';
        }
        if ($comissao === null || $comissao < 0) {
            $errors[] = 'Informe uma comissão válida (0 ou maior).';
        }

        if ($modo === 'margem') {
            if ($margem === null || $margem < 0) {
                $errors[] = 'Informe uma margem válida (0 ou maior).';
            } elseif (
                $imposto !== null && $comissao !== null && $margem !== null
                && !PrecoFormacao::somaPercentuaisValida((float) $imposto, (float) $comissao, (float) $margem)
            ) {
                $errors[] = 'Imposto + comissão + margem devem somar menos que 100%.';
            }
        }

        if ($modo === 'markup' && ($markup === null || $markup <= 0)) {
            $errors[] = 'Informe um markup maior que zero.';
        }

        $calculado = null;
        if ($modo === 'margem' && empty($errors) && $margem !== null && $imposto !== null && $comissao !== null) {
            $calculado = PrecoFormacao::calcularPreco(
                $custo,
                'margem',
                (float) $margem,
                1.0,
                (float) $imposto,
                (float) $comissao
            );
            if ($calculado === null) {
                $errors[] = 'Imposto + comissão + margem devem somar menos que 100%.';
            }
        }
        if ($modo === 'markup' && $markup !== null && empty($errors)) {
            $calculado = PrecoFormacao::calcularPreco(
                $custo,
                'markup',
                0.0,
                (float) $markup,
                (float) ($imposto ?? 0),
                (float) ($comissao ?? 0)
            );
        }

        $praticadoSalvar = null;
        if ($modo === 'manual') {
            $praticado = parse_money_br($praticadoRaw);
            if ($praticado === null || $praticado < 0) {
                $errors[] = 'Informe um preço de venda válido.';
            } else {
                $praticadoSalvar = round((float) $praticado, 4);
            }
        } elseif ($sincronizar) {
            $praticadoSalvar = null;
        } else {
            $praticado = parse_money_br($praticadoRaw);
            if ($praticado === null || $praticado < 0) {
                $errors[] = 'Informe um preço praticado válido.';
            } else {
                $praticadoSalvar = round((float) $praticado, 4);
            }
        }

        if ($errors) {
            flash('errors', $errors);
            redirect('/produtos/' . $produtoId . '#precos');
        }

        PrecoFormacao::salvar($produtoId, [
            'modo_preco' => $modo,
            'margem_desejada' => round((float) ($margem ?? PrecoFormacao::margemPadrao()), 4),
            'imposto_percentual' => round((float) ($imposto ?? PrecoFormacao::impostoPadrao()), 4),
            'comissao_percentual' => round((float) ($comissao ?? PrecoFormacao::comissaoPadrao()), 4),
            'markup' => round((float) ($markup ?? PrecoFormacao::markupPadrao()), 4),
            'preco_praticado' => $praticadoSalvar,
        ], $custo, Auth::id());

        flash('success', 'Formação de preço atualizada.');
        redirect('/produtos/' . $produtoId . '#precos');
    }
}

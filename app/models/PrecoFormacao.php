<?php

declare(strict_types=1);

final class PrecoFormacao
{
    public static function config(string $chave, string $default = ''): string
    {
        $stmt = Database::pdo()->prepare('SELECT valor FROM configuracoes WHERE chave = :chave LIMIT 1');
        $stmt->execute(['chave' => $chave]);
        $valor = $stmt->fetchColumn();
        return $valor === false || $valor === null || $valor === '' ? $default : (string) $valor;
    }

    public static function margemPadrao(): float
    {
        return (float) self::config('margem_padrao_varejo', '60');
    }

    public static function markupPadrao(): float
    {
        return (float) self::config('markup_padrao', '2');
    }

    public static function impostoPadrao(): float
    {
        return (float) self::config('imposto_padrao_varejo', '9.5');
    }

    public static function comissaoPadrao(): float
    {
        return (float) self::config('comissao_padrao_varejo', '10');
    }

    public static function margemMinima(): float
    {
        return (float) self::config('margem_minima_varejo', '40');
    }

    public static function somaPercentuaisValida(float $imposto, float $comissao, float $margem): bool
    {
        return ($imposto + $comissao + $margem) < 100;
    }

    /**
     * @return array<string, mixed>
     */
    public static function analisar(array $produto, float $custoUnitario): array
    {
        $modo = (string) ($produto['modo_preco'] ?? 'margem');
        if (!in_array($modo, ['margem', 'markup', 'manual'], true)) {
            $modo = 'margem';
        }

        $margemDesejada = self::valorOuPadrao($produto['margem_desejada'] ?? null, self::margemPadrao());
        $markup = self::valorOuPadrao($produto['markup'] ?? null, self::markupPadrao());
        $imposto = self::valorOuPadrao($produto['imposto_percentual'] ?? null, self::impostoPadrao());
        $comissao = self::valorOuPadrao($produto['comissao_percentual'] ?? null, self::comissaoPadrao());

        $temPraticadoSalvo = array_key_exists('preco_praticado', $produto)
            && $produto['preco_praticado'] !== null
            && $produto['preco_praticado'] !== '';

        $calculado = null;
        $praticado = null;
        $sincronizado = false;
        $erroCalculo = null;

        if ($modo === 'manual') {
            $praticado = $temPraticadoSalvo ? (float) $produto['preco_praticado'] : null;
            $calculado = $praticado;
        } else {
            $calculado = self::calcularPreco($custoUnitario, $modo, $margemDesejada, $markup, $imposto, $comissao);
            if ($modo === 'margem' && $calculado === null) {
                $erroCalculo = 'Imposto + comissão + margem devem somar menos que 100%.';
            }
            if ($temPraticadoSalvo) {
                $praticado = (float) $produto['preco_praticado'];
                $sincronizado = $calculado !== null && abs($praticado - $calculado) < 0.00005;
            } else {
                $praticado = $calculado;
                $sincronizado = true;
            }
        }

        $impostoReais = null;
        $comissaoReais = null;
        $lucro = null;
        $margemReal = null;

        if ($praticado !== null) {
            $impostoReais = $praticado * ($imposto / 100);
            $comissaoReais = $praticado * ($comissao / 100);
            $lucro = $praticado - $custoUnitario - $impostoReais - $comissaoReais;
            if ($praticado > 0) {
                $margemReal = ($lucro / $praticado) * 100;
            }
        }

        $minima = self::margemMinima();
        $alerta = $margemReal !== null && $margemReal < $minima;

        return [
            'custo' => $custoUnitario,
            'modo' => $modo,
            'margem_desejada' => $margemDesejada,
            'markup' => $markup,
            'imposto_percentual' => $imposto,
            'comissao_percentual' => $comissao,
            'preco_calculado' => $calculado,
            'preco_praticado' => $praticado,
            'imposto_reais' => $impostoReais,
            'comissao_reais' => $comissaoReais,
            'lucro_unitario' => $lucro,
            'margem_real' => $margemReal,
            'alerta_margem' => $alerta,
            'margem_minima' => $minima,
            'sincronizado' => $sincronizado,
            'erro_calculo' => $erroCalculo,
        ];
    }

    public static function calcularPreco(
        float $custo,
        string $modo,
        float $margemPct,
        float $markup,
        float $impostoPct = 0.0,
        float $comissaoPct = 0.0
    ): ?float {
        if ($custo < 0) {
            return null;
        }

        if ($modo === 'margem') {
            if ($impostoPct < 0 || $comissaoPct < 0 || $margemPct < 0) {
                return null;
            }
            if (!self::somaPercentuaisValida($impostoPct, $comissaoPct, $margemPct)) {
                return null;
            }
            $denominador = 1 - (($impostoPct + $comissaoPct + $margemPct) / 100);
            if ($denominador <= 0) {
                return null;
            }
            return $custo / $denominador;
        }

        if ($modo === 'markup') {
            if ($markup <= 0) {
                return null;
            }
            return $custo * $markup;
        }

        return null;
    }

    public static function salvar(int $produtoId, array $data, float $custoUnitario, ?int $usuarioId): void
    {
        $pdo = Database::pdo();
        $atual = Produto::find($produtoId);
        $precoAnterior = $atual['preco_praticado'] ?? null;

        $stmt = $pdo->prepare(
            'UPDATE produtos
             SET modo_preco = :modo_preco,
                 margem_desejada = :margem_desejada,
                 imposto_percentual = :imposto_percentual,
                 comissao_percentual = :comissao_percentual,
                 markup = :markup,
                 preco_praticado = :preco_praticado
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $produtoId,
            'modo_preco' => $data['modo_preco'],
            'margem_desejada' => $data['margem_desejada'],
            'imposto_percentual' => $data['imposto_percentual'],
            'comissao_percentual' => $data['comissao_percentual'],
            'markup' => $data['markup'],
            'preco_praticado' => $data['preco_praticado'],
        ]);

        $novo = $data['preco_praticado'];
        $efetivo = $novo;
        if ($novo === null && in_array($data['modo_preco'], ['margem', 'markup'], true)) {
            $efetivo = self::calcularPreco(
                $custoUnitario,
                $data['modo_preco'],
                (float) $data['margem_desejada'],
                (float) $data['markup'],
                (float) $data['imposto_percentual'],
                (float) $data['comissao_percentual']
            );
        }

        $mudou = false;
        if ($efetivo !== null) {
            if ($precoAnterior === null) {
                $mudou = true;
            } else {
                $mudou = abs((float) $precoAnterior - (float) $efetivo) >= 0.00005;
            }
        }

        if ($mudou && $efetivo !== null) {
            $hist = $pdo->prepare(
                'INSERT INTO historico_precos (produto_id, custo, varejo, atacado_100, usuario_id)
                 VALUES (:produto_id, :custo, :varejo, NULL, :usuario_id)'
            );
            $hist->execute([
                'produto_id' => $produtoId,
                'custo' => round($custoUnitario, 4),
                'varejo' => arredondar_para_cima_centavos((float) $efetivo),
                'usuario_id' => $usuarioId,
            ]);
        }
    }

    private static function valorOuPadrao($valor, float $padrao): float
    {
        if ($valor === null || $valor === '') {
            return $padrao;
        }
        return (float) $valor;
    }
}

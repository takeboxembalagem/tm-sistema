<?php

declare(strict_types=1);

/**
 * Cálculo comercial da Tabela de Preços (independente da formação do preço cheio do produto).
 */
final class TabelaPrecoCalculo
{
    public static function somaValida(float $imposto, float $comissao, float $margem): bool
    {
        return ($imposto + $comissao + $margem) < 100;
    }

    /**
     * PREÇO = CUSTO / (1 - imposto - comissão - margem)
     * Comissão deve ser 0 quando desativada.
     */
    public static function calcularPreco(float $custo, float $impostoPct, float $comissaoPct, float $margemPct): ?float
    {
        if ($custo < 0 || $impostoPct < 0 || $comissaoPct < 0 || $margemPct < 0) {
            return null;
        }
        if (!self::somaValida($impostoPct, $comissaoPct, $margemPct)) {
            return null;
        }
        $den = 1 - (($impostoPct + $comissaoPct + $margemPct) / 100);
        if ($den <= 0) {
            return null;
        }
        return $custo / $den;
    }

    public static function mensagemErroSoma(): string
    {
        return 'Imposto + comissão + margem devem somar menos que 100%.';
    }
}

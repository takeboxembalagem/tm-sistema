<?php

declare(strict_types=1);

/**
 * Teste CLI do helper cmp_natural_produto (ordenação natural + paginação slice).
 * Não toca no banco.
 *
 * Uso: php scripts/test_cmp_natural_produto.php
 */

require_once dirname(__DIR__) . '/app/core/helpers.php';

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

$nomes = [
    'Caixa 1', 'Caixa 2', 'Caixa 3', 'Caixa 10', 'Caixa 11',
    'Sacola 1', 'Sacola 2', 'Sacola 10',
];

// Embaralha ordem de entrada (ids distintos para desempate)
$items = [];
foreach ($nomes as $i => $nome) {
    $items[] = [
        'id' => 100 - $i,
        'codigo_interno' => 'Z' . (100 - $i),
        'nome' => $nome,
    ];
}
shuffle($items);

usort($items, 'cmp_natural_produto');
$ordenado = array_column($items, 'nome');
assert_same($nomes, $ordenado, 'ordem natural exacta Caixa/Sacola');

// Empate de nome → codigo_interno
$tieNome = [
    ['id' => 2, 'codigo_interno' => 'B10', 'nome' => 'Mesmo'],
    ['id' => 1, 'codigo_interno' => 'A2', 'nome' => 'Mesmo'],
];
usort($tieNome, 'cmp_natural_produto');
assert_same(['A2', 'B10'], array_column($tieNome, 'codigo_interno'), 'desempate codigo_interno');

// Empate nome+codigo → id
$tieId = [
    ['id' => 9, 'codigo_interno' => 'X', 'nome' => 'Igual'],
    ['id' => 3, 'codigo_interno' => 'X', 'nome' => 'Igual'],
];
usort($tieId, 'cmp_natural_produto');
assert_same([3, 9], array_map('intval', array_column($tieId, 'id')), 'desempate id');

// Paginação: sort completo depois array_slice (como Produto::paginate)
$todos = [];
foreach ($nomes as $i => $nome) {
    $todos[] = ['id' => $i + 1, 'codigo_interno' => 'C' . ($i + 1), 'nome' => $nome];
}
shuffle($todos);
usort($todos, 'cmp_natural_produto');
$perPage = 3;
$page = 2;
$offset = ($page - 1) * $perPage;
$pagina = array_slice($todos, $offset, $perPage);
assert_same(
    ['Caixa 10', 'Caixa 11', 'Sacola 1'],
    array_column($pagina, 'nome'),
    'paginação: slice após sort (page 2, per_page 3)'
);

// Variações: codigo depois nome
$vars = [
    ['codigo' => 'V10', 'nome' => 'Dez'],
    ['codigo' => 'V2', 'nome' => 'Dois'],
    ['codigo' => 'V2', 'nome' => 'Alpha'],
];
usort($vars, 'cmp_natural_variacao');
assert_same(
    ['V2/Alpha', 'V2/Dois', 'V10/Dez'],
    array_map(static fn (array $v): string => $v['codigo'] . '/' . $v['nome'], $vars),
    'cmp_natural_variacao codigo→nome'
);

echo "\nTodos os testes passaram.\n";
exit(0);

<?php
/**
 * Testes locais — leitura de quantidade de estoque (peças / papéis) no formato BR.
 * NÃO executar em produção. Uso: php scripts/test_quantidade_estoque.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[OK] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "[FALHA] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
}

// --- 1) Helper (sem banco) ---
$validos = [
    '1' => 1.0,
    '10' => 10.0,
    '100' => 100.0,
    '1000' => 1000.0,
    '1.000' => 1000.0,
    '10.000' => 10000.0,
    '1,5' => 1.5,
    '10,5' => 10.5,
    '1.000,5' => 1000.5,
    '1.500,5' => 1500.5,
    '10.500,25' => 10500.25,
    '1.000.000' => 1000000.0,
    '0' => 0.0,
    ' 1.000 ' => 1000.0,
    '-5' => -5.0,
];
foreach ($validos as $entrada => $esperado) {
    $r = parse_quantidade_br((string) $entrada);
    ok("helper \"{$entrada}\" → {$esperado}", $r !== null && abs($r - $esperado) < 1e-9, 'obtido=' . var_export($r, true));
}

$invalidos = ['', 'abc', '10cx', '10 cx', 'cx10', '1.5', '10.5', '1.00', '1.0000', '1,000,5', '1..000', ',5', '1,', '.000', '1 000', '1.000.', '--1', '1e3'];
foreach ($invalidos as $entrada) {
    $r = parse_quantidade_br($entrada);
    ok("helper rejeita \"{$entrada}\"", $r === null, 'obtido=' . var_export($r, true));
}

// --- 2) Integração no banco local (itens temporários) ---
$pdo = Database::pdo();

$snapshot = static function () use ($pdo): string {
    $partes = [];
    foreach (['estoque_pecas', 'estoque_papeis'] as $t) {
        $rows = $pdo->query("SELECT id, quantidade FROM {$t} WHERE codigo NOT LIKE 'TST-QTD-%' ORDER BY id")->fetchAll();
        $partes[] = $t . ':' . json_encode($rows);
    }
    $partes[] = 'mov_pecas:' . $pdo->query(
        "SELECT COUNT(*) FROM estoque_pecas_movimentos m JOIN estoque_pecas p ON p.id = m.peca_id WHERE p.codigo NOT LIKE 'TST-QTD-%'"
    )->fetchColumn();
    $partes[] = 'mov_papeis:' . $pdo->query(
        "SELECT COUNT(*) FROM estoque_papeis_movimentos m JOIN estoque_papeis p ON p.id = m.papel_id WHERE p.codigo NOT LIKE 'TST-QTD-%'"
    )->fetchColumn();
    return md5(implode('|', $partes));
};

$antes = $snapshot();
$suffix = bin2hex(random_bytes(3));
$pecaId = null;
$papelId = null;

try {
    $pecaId = EstoquePeca::create([
        'codigo' => 'TST-QTD-PC-' . $suffix,
        'nome' => 'Teste quantidade peça',
        'tipo' => 'outra',
        'produto_id' => null,
        'cor' => null,
        'estampa' => null,
        'quantidade' => parse_quantidade_br('1.000'),
        'unidade' => 'un',
        'localizacao' => null,
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $saldo = (float) EstoquePeca::find($pecaId)['quantidade'];
    ok('peça: quantidade inicial "1.000" gravada como 1000', abs($saldo - 1000.0) < 1e-9, "saldo={$saldo}");

    EstoquePeca::registrarMovimento($pecaId, 'entrada', (float) parse_quantidade_br('10.000'), null, null);
    $saldo = (float) EstoquePeca::find($pecaId)['quantidade'];
    ok('peça: entrada "10.000" → saldo 11000', abs($saldo - 11000.0) < 1e-9, "saldo={$saldo}");

    EstoquePeca::registrarMovimento($pecaId, 'saida', (float) parse_quantidade_br('1.000,5'), null, null);
    $saldo = (float) EstoquePeca::find($pecaId)['quantidade'];
    ok('peça: saída "1.000,5" → saldo 9999,5', abs($saldo - 9999.5) < 1e-9, "saldo={$saldo}");

    EstoquePeca::registrarMovimento($pecaId, 'ajuste', (float) parse_quantidade_br('10.500,25'), null, null);
    $saldo = (float) EstoquePeca::find($pecaId)['quantidade'];
    ok('peça: ajuste "10.500,25" → saldo 10500,25', abs($saldo - 10500.25) < 1e-9, "saldo={$saldo}");

    $papelId = EstoquePapel::create([
        'codigo' => 'TST-QTD-PP-' . $suffix,
        'nome' => 'Teste quantidade papel',
        'cor' => null,
        'formato' => null,
        'gramatura' => null,
        'quantidade' => parse_quantidade_br('1.000'),
        'unidade' => 'folhas',
        'localizacao' => null,
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $saldo = (float) EstoquePapel::find($papelId)['quantidade'];
    ok('papel: quantidade inicial "1.000" gravada como 1000', abs($saldo - 1000.0) < 1e-9, "saldo={$saldo}");

    EstoquePapel::registrarMovimento($papelId, 'saida', (float) parse_quantidade_br('1,5'), null, null);
    $saldo = (float) EstoquePapel::find($papelId)['quantidade'];
    ok('papel: saída "1,5" → saldo 998,5', abs($saldo - 998.5) < 1e-9, "saldo={$saldo}");

    EstoquePapel::registrarMovimento($papelId, 'ajuste', (float) parse_quantidade_br('10.000'), null, null);
    $saldo = (float) EstoquePapel::find($papelId)['quantidade'];
    ok('papel: ajuste "10.000" → saldo 10000', abs($saldo - 10000.0) < 1e-9, "saldo={$saldo}");
} catch (Throwable $e) {
    ok('integração sem exceção', false, $e->getMessage());
} finally {
    if ($pecaId !== null) {
        EstoquePeca::delete($pecaId);
    }
    if ($papelId !== null) {
        EstoquePapel::delete($papelId);
    }
}

$sobras = (int) $pdo->query(
    "SELECT (SELECT COUNT(*) FROM estoque_pecas WHERE codigo LIKE 'TST-QTD-%') + (SELECT COUNT(*) FROM estoque_papeis WHERE codigo LIKE 'TST-QTD-%')"
)->fetchColumn();
ok('itens temporários removidos', $sobras === 0, "restantes={$sobras}");
ok('estoque existente inalterado (saldos e movimentos)', $snapshot() === $antes);

echo PHP_EOL . "Resultado: {$pass} ok, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

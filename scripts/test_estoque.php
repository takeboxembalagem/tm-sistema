<?php
/**
 * Testes locais — módulo Estoque (peças / papéis).
 * NÃO executar em produção. Uso: php scripts/test_estoque.php
 *
 * Checklist: 1 php -l · 2 tabelas · 3 create peça · 4 qtd inicial+histórico ·
 * 5 produto opcional · 6 update · 7 entrada · 8 saída · 9 bloqueio overdraft ·
 * 10 ajuste · 11 papel CRUD · 12 status · 13 delete + imagem
 */
declare(strict_types=1);

ob_start(); // evita "headers already sent" por warning do php -l (mysqli)

$root = dirname(__DIR__);
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

echo "=== TEST Estoque ===\n\n";

// 1) php -l nos arquivos novos/alterados
$lintFiles = [
    'app/models/EstoquePeca.php',
    'app/models/EstoquePapel.php',
    'app/controllers/EstoquePecaController.php',
    'app/controllers/EstoquePapelController.php',
    'app/core/Upload.php',
    'app/bootstrap.php',
    'app/routes.php',
    'app/views/layouts/main.php',
    'app/views/estoque/_tabs.php',
    'app/views/estoque/pecas/index.php',
    'app/views/estoque/pecas/form.php',
    'app/views/estoque/pecas/show.php',
    'app/views/estoque/papeis/index.php',
    'app/views/estoque/papeis/form.php',
    'app/views/estoque/papeis/show.php',
    'scripts/test_estoque.php',
];
$lintOk = true;
foreach ($lintFiles as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        ok("1. php -l {$rel}", false, 'arquivo ausente');
        $lintOk = false;
        continue;
    }
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    $joined = implode(' ', $out);
    // Ignora warning de módulo mysqli duplicado; falha só se houver "Parse error" / exit != 0 sem "No syntax errors"
    $syntaxOk = $code === 0 || str_contains($joined, 'No syntax errors detected');
    if (!$syntaxOk) {
        ok("1. php -l {$rel}", false, $joined);
        $lintOk = false;
    }
}
ok('1. php -l (todos os arquivos do módulo)', $lintOk);

// Sem configuração ou sem banco os testes 2–13 não rodam: isso é falha, não sucesso.
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    ok('config/config.php presente', false, 'testes 2–13 (banco) NÃO executados');
    echo "\nResumo: {$pass} ok / {$fail} falha(s).\n";
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/app/bootstrap.php';

// Probe antes de Database::pdo(), que encerra o processo sem passar pelo resumo.
$db = config('db', []);
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'] ?? '127.0.0.1',
        $db['port'] ?? '3306',
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );
    $probe = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $probe->query('SELECT 1');
    $probe = null;
} catch (Throwable $e) {
    ok('conexão com o MySQL', false, $e->getMessage());
    echo "Testes 2–13 (banco) NÃO executados. Inicie o MySQL (XAMPP) e rode php scripts/test_estoque.php de novo.\n";
    echo "\nResumo: {$pass} ok / {$fail} falha(s). DB=off\n";
    exit(1);
}

$pdo = Database::pdo();

// O teste não altera a estrutura do banco: tabela ausente = migration não aplicada.
$tables = ['estoque_pecas', 'estoque_papeis', 'estoque_pecas_movimentos', 'estoque_papeis_movimentos'];
$missing = [];
foreach ($tables as $t) {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
    if (!$stmt->fetchColumn()) {
        $missing[] = $t;
    }
}
if ($missing) {
    ok('2. tabelas estoque existem', false, 'ausentes: ' . implode(', ', $missing) . ' — aplique database/alter_estoque.sql');
    echo "Testes 3–13 NÃO executados (schema do estoque não aplicado).\n";
    echo "\nResumo: {$pass} ok / {$fail} falha(s).\n";
    exit(1);
}
ok('2. tabelas estoque existem', true, '4 tabelas');

$suffix = bin2hex(random_bytes(3));
$pecaIds = [];
$papelIds = [];
$produtoId = null;
$createdProduto = false;

try {
    $produtoId = (int) ($pdo->query("SELECT id FROM produtos WHERE status = 'ativo' LIMIT 1")->fetchColumn() ?: 0);
    if ($produtoId <= 0) {
        $produtoId = Produto::create([
            'codigo_interno' => 'TST-EST-' . $suffix,
            'nome' => 'Produto teste estoque',
            'tamanho' => null,
            'tamanho_aberta' => null,
            'descricao' => null,
            'categoria_id' => null,
            'colecao_id' => null,
            'foto_principal' => null,
            'status' => 'ativo',
            'quantidade_pacote' => null,
            'peso_pacote_kg' => null,
        ]);
        $createdProduto = true;
    }

    // 3) create peça sem qtd inicial
    $idZero = EstoquePeca::create([
        'codigo' => 'PEC-Z-' . $suffix,
        'nome' => 'Peça zero ' . $suffix,
        'tipo' => 'tampa',
        'produto_id' => null,
        'cor' => 'branco',
        'estampa' => null,
        'quantidade' => 0,
        'unidade' => 'un',
        'localizacao' => 'A1',
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ], null);
    $pecaIds[] = $idZero;
    $z = EstoquePeca::find($idZero);
    ok('3. create peça (qtd 0)', $z !== null && (float) $z['quantidade'] === 0.0, 'id=' . $idZero);
    ok('3b. create qtd 0 sem movimento', count(EstoquePeca::movimentos($idZero)) === 0);

    // 4) create com quantidade inicial + histórico
    $idInit = EstoquePeca::create([
        'codigo' => 'PEC-I-' . $suffix,
        'nome' => 'Peça inicial ' . $suffix,
        'tipo' => 'fundo',
        'produto_id' => $produtoId,
        'cor' => 'kraft',
        'estampa' => 'floral',
        'quantidade' => 100,
        'unidade' => 'un',
        'localizacao' => 'B2',
        'imagem' => null,
        'observacao' => 'teste',
        'status' => 'ativo',
    ], null);
    $pecaIds[] = $idInit;
    $init = EstoquePeca::find($idInit);
    $movs = EstoquePeca::movimentos($idInit);
    ok('4. qtd inicial + histórico', $init !== null && (float) $init['quantidade'] === 100.0 && count($movs) === 1
        && ($movs[0]['tipo'] ?? '') === 'entrada'
        && ($movs[0]['observacao'] ?? '') === 'Estoque inicial'
        && (float) ($movs[0]['saldo_anterior'] ?? -1) === 0.0
        && (float) ($movs[0]['saldo_apos'] ?? -1) === 100.0);

    // 5) produto opcional
    ok('5. produto opcional vinculado', (int) ($init['produto_id'] ?? 0) === $produtoId);

    // 6) update metadados (sem alterar saldo)
    EstoquePeca::update($idInit, [
        'codigo' => 'PEC-I-' . $suffix,
        'nome' => 'Peça inicial editada ' . $suffix,
        'tipo' => 'fundo',
        'produto_id' => $produtoId,
        'cor' => 'kraft escuro',
        'estampa' => 'floral',
        'unidade' => 'un',
        'localizacao' => 'B3',
        'imagem' => null,
        'observacao' => 'editado',
        'status' => 'ativo',
    ]);
    $upd = EstoquePeca::find($idInit);
    ok('6. update peça (saldo intacto)', $upd !== null
        && str_contains((string) $upd['nome'], 'editada')
        && (float) $upd['quantidade'] === 100.0
        && ($upd['localizacao'] ?? '') === 'B3');

    // 7) entrada
    EstoquePeca::registrarMovimento($idInit, 'entrada', 25, 'compra', null);
    $afterIn = EstoquePeca::find($idInit);
    ok('7. entrada', $afterIn !== null && (float) $afterIn['quantidade'] === 125.0);

    // 8) saída
    EstoquePeca::registrarMovimento($idInit, 'saida', 25, 'uso', null);
    $afterOut = EstoquePeca::find($idInit);
    ok('8. saída', $afterOut !== null && (float) $afterOut['quantidade'] === 100.0);

    // 9) bloqueio overdraft
    $blocked = false;
    $saldoAntes = (float) (EstoquePeca::find($idInit)['quantidade'] ?? 0);
    try {
        EstoquePeca::registrarMovimento($idInit, 'saida', 99999, 'overdraft', null);
    } catch (RuntimeException $e) {
        $blocked = true;
    }
    $saldoDepois = (float) (EstoquePeca::find($idInit)['quantidade'] ?? -1);
    ok('9. bloqueio overdraft', $blocked && $saldoDepois === $saldoAntes, 'saldo=' . $saldoDepois);

    // 10) ajuste absoluto
    EstoquePeca::registrarMovimento($idInit, 'ajuste', 42, 'inventário', null);
    $aj = EstoquePeca::find($idInit);
    $last = EstoquePeca::movimentos($idInit)[0] ?? null;
    ok('10. ajuste absoluto', $aj !== null && (float) $aj['quantidade'] === 42.0
        && $last && ($last['tipo'] ?? '') === 'ajuste'
        && (float) ($last['quantidade'] ?? -1) === 42.0
        && (float) ($last['saldo_apos'] ?? -1) === 42.0
        && (float) ($last['saldo_anterior'] ?? -1) === 100.0);

    // 11) papel CRUD + movimentos
    $papelId = EstoquePapel::create([
        'codigo' => 'PAP-' . $suffix,
        'nome' => 'Papel teste ' . $suffix,
        'cor' => 'branco',
        'formato' => '66x96',
        'gramatura' => '180g',
        'quantidade' => 50,
        'unidade' => 'folha',
        'localizacao' => 'P1',
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ], null);
    $papelIds[] = $papelId;
    EstoquePapel::registrarMovimento($papelId, 'entrada', 10, null, null);
    EstoquePapel::registrarMovimento($papelId, 'saida', 5, null, null);
    $papel = EstoquePapel::find($papelId);
    ok('11. papel CRUD + movimentos', $papel !== null && (float) $papel['quantidade'] === 55.0
        && count(EstoquePapel::movimentos($papelId)) === 3);

    // 12) status
    EstoquePeca::setStatus($idInit, 'inativo');
    EstoquePapel::setStatus($papelId, 'inativo');
    ok('12. status inativo', (EstoquePeca::find($idInit)['status'] ?? '') === 'inativo'
        && (EstoquePapel::find($papelId)['status'] ?? '') === 'inativo');
    EstoquePeca::setStatus($idInit, 'ativo');
    EstoquePapel::setStatus($papelId, 'ativo');

    // 13) imagem upload (PM_TEST_UPLOAD) + delete
    if (!defined('PM_TEST_UPLOAD')) {
        define('PM_TEST_UPLOAD', true);
    }
    $tmpDir = sys_get_temp_dir();
    $tmpPng = $tmpDir . DIRECTORY_SEPARATOR . 'pm_estoque_' . $suffix . '.png';
    $pngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($tmpPng, $pngBin);
    $upload = Upload::pecaImagem([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmpPng,
        'size' => strlen((string) $pngBin),
        'name' => 'dot.png',
    ]);
    $imgOk = !empty($upload['ok']) && !empty($upload['path']);
    $absImg = $imgOk ? ($root . '/public/uploads/' . $upload['path']) : '';
    if ($imgOk) {
        EstoquePeca::update($idZero, [
            'codigo' => 'PEC-Z-' . $suffix,
            'nome' => 'Peça zero ' . $suffix,
            'tipo' => 'tampa',
            'produto_id' => null,
            'cor' => 'branco',
            'estampa' => null,
            'unidade' => 'un',
            'localizacao' => 'A1',
            'imagem' => $upload['path'],
            'observacao' => null,
            'status' => 'ativo',
        ]);
    }
    EstoquePeca::delete($idZero);
    $pecaIds = array_values(array_filter($pecaIds, static fn ($id) => (int) $id !== (int) $idZero));
    if ($imgOk && is_file($absImg)) {
        Upload::removerImagemPublica((string) $upload['path']);
    }
    ok('13. imagem upload + delete peça', $imgOk && !EstoquePeca::find($idZero)
        && ($absImg === '' || !is_file($absImg)), $imgOk ? (string) $upload['path'] : ($upload['error'] ?? 'upload falhou'));

} catch (Throwable $e) {
    ok('exceção inesperada', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $limpezaErros = [];
    foreach ($pecaIds as $id) {
        try {
            $row = EstoquePeca::find((int) $id);
            if ($row && !empty($row['imagem'])) {
                Upload::removerImagemPublica((string) $row['imagem']);
            }
            EstoquePeca::delete((int) $id);
        } catch (Throwable $e) {
            $limpezaErros[] = "peça {$id}: " . $e->getMessage();
        }
    }
    foreach ($papelIds as $id) {
        try {
            EstoquePapel::delete((int) $id);
        } catch (Throwable $e) {
            $limpezaErros[] = "papel {$id}: " . $e->getMessage();
        }
    }
    if ($createdProduto && $produtoId) {
        try {
            $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $produtoId]);
        } catch (Throwable $e) {
            $limpezaErros[] = "produto {$produtoId}: " . $e->getMessage();
        }
    }
    ok('limpeza dos dados de teste', $limpezaErros === [], implode(' | ', $limpezaErros));
}

echo "\nResumo: {$pass} ok / {$fail} falha(s). DB=ok\n";
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Testes locais — exibição do lucro real negativo na formação de preço (money_br_sinal / moneySinal).
 * Cria dados temporários (TST-LUCRO-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_lucro_negativo.php
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

$pdo = Database::pdo();
$sufixo = date('His') . random_int(100, 999);
$produtoId = 0;
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_' . $sufixo . '.php';
$nodeScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.js';

// --- A) Formatador PHP ---
$exemplos = [
    [12.341, 'R$ 12,35'],
    [12.34, 'R$ 12,34'],
    [0, 'R$ 0,00'],
    [-0.0, 'R$ 0,00'],
    [-0.0000000000001, 'R$ 0,00'],
    [0.0000000000001, 'R$ 0,00'],
    [-12.34, '-R$ 12,34'],
    [-12.341, '-R$ 12,35'],
    [-1500.50, '-R$ 1.500,50'],
    [-0.001, '-R$ 0,01'],
    [-1234567.891, '-R$ 1.234.567,90'],
];
foreach ($exemplos as [$valor, $esperado]) {
    $r = money_br_sinal($valor);
    ok('A. money_br_sinal(' . var_export($valor, true) . ") = {$esperado}", $r === $esperado, "obtido={$r}");
}
foreach ([0, 0.004, 0.01, 1, 12.341, 99.999, 1500.5, 1234567.891] as $positivo) {
    ok('A. positivo igual a money_br(' . var_export($positivo, true) . ')', money_br_sinal($positivo) === money_br($positivo));
}
ok('A. aceita string numérica', money_br_sinal('-20.0000') === '-R$ 20,00');

// --- B) PrecoFormacao::analisar ---
$casos = [
    'prejuízo' => [100.0, 80.0, 0, 0, -20.0, -25.0],
    'positivo' => [100.0, 150.0, 0, 0, 50.0, 100 / 3],
    'positivo com imposto e comissão' => [50.0, 100.0, 9.5, 10, 30.5, 30.5],
    'lucro zero' => [100.0, 100.0, 0, 0, 0.0, 0.0],
    'preço praticado zero' => [100.0, 0.0, 0, 0, -100.0, null],
];
foreach ($casos as $rotulo => [$custo, $praticado, $imp, $com, $lucroEsp, $margemEsp]) {
    $a = PrecoFormacao::analisar([
        'modo_preco' => 'manual',
        'preco_praticado' => $praticado,
        'imposto_percentual' => $imp,
        'comissao_percentual' => $com,
    ], $custo);
    ok("B. {$rotulo}: lucro = {$lucroEsp}", abs((float) $a['lucro_unitario'] - $lucroEsp) < 1e-9,
        'lucro=' . var_export($a['lucro_unitario'], true));
    ok(
        "B. {$rotulo}: margem = " . var_export($margemEsp, true),
        $margemEsp === null ? $a['margem_real'] === null : abs((float) $a['margem_real'] - $margemEsp) < 1e-9,
        'margem=' . var_export($a['margem_real'], true)
    );
}
$a = PrecoFormacao::analisar(['modo_preco' => 'manual', 'preco_praticado' => 80, 'imposto_percentual' => 0, 'comissao_percentual' => 0], 100.0);
ok('B. prejuízo exibido como -R$ 20,00 e margem -25,00%', money_br_sinal($a['lucro_unitario']) === '-R$ 20,00'
    && number_br($a['margem_real'], 2) === '-25,00');

function rota(string $uri, int $userId): string
{
    global $runner, $sessDir;
    $req = ['uri' => $uri, 'user_id' => $userId, 'sess_dir' => $sessDir, 'root' => dirname(__DIR__)];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    return (string) shell_exec($cmd);
}

function textoPorId(DOMDocument $dom, string $id): ?string
{
    $el = $dom->getElementById($id);
    return $el ? trim($el->textContent) : null;
}

try {
    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($userId === 0) {
        ok('há usuário ativo local para simular sessão', false);
        throw new RuntimeException('Sem usuário ativo local.');
    }

    // --- C) Página real com preço abaixo do custo ---
    $produtoId = Produto::create([
        'codigo_interno' => 'TST-LUCRO-' . $sufixo,
        'nome' => 'Teste Lucro Negativo',
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
    $pdo->prepare(
        "INSERT INTO produto_composicao (produto_id, material_id, descricao_manual, preco_manual, quantidade, modo)
         VALUES (:p, NULL, 'Custo teste', 100.00, 1, 'manual')"
    )->execute(['p' => $produtoId]);
    $pdo->prepare('UPDATE produtos SET quantidade_producao = 1, rendimento_folha = 1 WHERE id = :id')
        ->execute(['id' => $produtoId]);

    $custoUnit = (float) Composicao::totais($produtoId)['custo_unitario'];
    $precoPraticado = round($custoUnit * 0.8, 4);
    $pdo->prepare(
        "UPDATE produtos SET modo_preco = 'manual', preco_praticado = :pp, imposto_percentual = 0, comissao_percentual = 0
         WHERE id = :id"
    )->execute(['pp' => $precoPraticado, 'id' => $produtoId]);
    ok('C. custo unitário do produto temporário > 0', $custoUnit > 0, 'custo=' . $custoUnit);

    $esperado = PrecoFormacao::analisar(Produto::find($produtoId), $custoUnit);
    ok('C. cálculo interno: lucro negativo', (float) $esperado['lucro_unitario'] < 0);

    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'admin'];
$_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
register_shutdown_function(static function (): void {
    echo (string) ob_get_clean();
});
require $req['root'] . '/app/routes.php';
PHP);

    $html = rota('/produtos/' . $produtoId, $userId);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $lucroTela = textoPorId($dom, 'res-lucro');
    $margemTela = textoPorId($dom, 'res-margem');
    ok('C. res-lucro mostra o prejuízo com sinal', $lucroTela === money_br_sinal($esperado['lucro_unitario'])
        && str_starts_with((string) $lucroTela, '-R$ '), 'res-lucro=' . var_export($lucroTela, true));
    ok('C. res-margem continua negativa e correta (-25,00%)', $margemTela === '-25,00%'
        && $margemTela === number_br($esperado['margem_real'], 2) . '%', 'res-margem=' . var_export($margemTela, true));
    ok('C. res-custo inalterado (money_br)', textoPorId($dom, 'res-custo') === money_br($custoUnit));
    ok('C. res-imposto inalterado (money_br)', textoPorId($dom, 'res-imposto') === money_br($esperado['imposto_reais']));

    // --- D) JavaScript real da página, executado no Node ---
    $nodeOk = trim((string) shell_exec('node -v 2>&1'));
    if (!preg_match('/^v\d+/', $nodeOk)) {
        ok('D. Node.js disponível', false, $nodeOk);
    } else {
        $js = null;
        foreach ($dom->getElementsByTagName('script') as $script) {
            $src = $script->textContent;
            if (str_contains($src, 'function moneySinal')
                && preg_match('/function ceilMoney\(n\)[\s\S]*?function moneySinal\(n\) \{[\s\S]*?\n    \}/', $src, $m)) {
                $js = $m[0];
            }
        }
        ok('D. funções ceilMoney/money/moneySinal encontradas na página', $js !== null);
        $valoresJs = [12.341, 12.34, 0, -0.0, -0.0000000000001, 0.0000000000001, -12.34, -12.341, -1500.5, -0.001, 1500.5, -1234567.891];
        file_put_contents($nodeScript, (string) $js . "\nconst v = " . json_encode($valoresJs)
            . ";\nv[3] = -0;\nprocess.stdout.write(JSON.stringify({sinal: v.map(moneySinal), money: v.map(money)}));\n");
        $res = json_decode((string) shell_exec('node ' . escapeshellarg($nodeScript) . ' 2>&1'), true);
        if (!is_array($res)) {
            ok('D. node executou moneySinal', false);
        } else {
            foreach ($valoresJs as $i => $valor) {
                $php = money_br_sinal($valor);
                $jsTxt = str_replace("\u{00A0}", ' ', (string) $res['sinal'][$i]);
                ok('D. moneySinal(' . var_export($valor, true) . ") = PHP ({$php})", $jsTxt === $php, "js={$jsTxt}");
            }
            ok('D. money() continua zerando negativos (sem alteração)', $res['money'][6] === 'R$ 0,00' && $res['money'][8] === 'R$ 0,00');
        }
    }
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($produtoId > 0) {
        $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :id')->execute(['id' => $produtoId]);
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $produtoId]);
    }
    foreach ([$runner, $nodeScript] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    if (is_dir($sessDir)) {
        foreach (glob($sessDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($sessDir);
    }
}

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-LUCRO-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

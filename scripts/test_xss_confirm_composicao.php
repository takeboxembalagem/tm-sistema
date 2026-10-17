<?php
/**
 * Testes locais — confirm() de "Remover" custo da formação (produtos/show) não executa
 * JavaScript vindo do nome do custo. Renderiza a página real e executa o onsubmit no Node.
 * Cria dados temporários (TST-XSS-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_xss_confirm_composicao.php
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
$nodeInput = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.json';

$nomes = [
    'apóstrofo' => "O'Brien",
    'aspas' => 'Caixa "Premium"',
    'barra invertida' => 'Barra \\ invertida',
    'barra invertida no fim' => 'Termina com \\',
    'quebra de linha' => "Linha 1\nLinha 2",
    'injeção JS' => "x');alert(document.cookie);//",
    'injeção JS com aspas' => 'x");alert(document.cookie);//',
    'injeção JS concatenada' => "x'+alert(document.cookie)+'",
    'HTML < > &' => '<script>alert(1)</script> & <b>negrito</b>',
    'fecha atributo' => '" onmouseover="alert(1)',
];

function rota(string $method, string $uri, array $post, int $userId): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'method' => $method,
        'uri' => $uri,
        'post' => $post + ['_csrf' => $token],
        'token' => $token,
        'user_id' => $userId,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
    ];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['html' => $out, 'flash' => null];
    }
    $res = json_decode(substr($out, $pos + 10), true) ?: [];
    $res['html'] = substr($out, 0, $pos);
    return $res;
}

/** Executa cada handler no Node com confirm/alert/document simulados. */
function executarNoNode(array $handlers, bool $respostaConfirm): ?array
{
    global $nodeScript, $nodeInput;
    file_put_contents($nodeInput, json_encode(['handlers' => $handlers, 'resposta' => $respostaConfirm]));
    $out = shell_exec('node ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($nodeInput) . ' 2>&1');
    $res = json_decode((string) $out, true);
    return is_array($res) ? $res : null;
}

/** onsubmit (já decodificado como o navegador faz) de cada form de remoção, por id da linha. */
function handlersRemover(string $html, int $produtoId): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $handlers = [];
    foreach ($dom->getElementsByTagName('form') as $form) {
        $action = $form->getAttribute('action');
        if (preg_match('#/produtos/' . $produtoId . '/composicao/(\d+)/remover$#', $action, $m)) {
            $handlers[(int) $m[1]] = $form->getAttribute('onsubmit');
        }
    }
    return $handlers;
}

try {
    $nodeOk = trim((string) shell_exec('node -v 2>&1'));
    if (!preg_match('/^v\d+/', $nodeOk)) {
        ok('Node.js disponível para executar o JavaScript', false, $nodeOk);
        throw new RuntimeException('Node.js indisponível.');
    }

    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($userId === 0) {
        ok('há usuário ativo local para simular sessão', false);
        throw new RuntimeException('Sem usuário ativo local.');
    }

    $produtoId = Produto::create([
        'codigo_interno' => 'TST-XSS-' . $sufixo,
        'nome' => 'Teste XSS confirm',
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

    $linhas = [];
    $ins = $pdo->prepare(
        "INSERT INTO produto_composicao (produto_id, material_id, descricao_manual, preco_manual, quantidade, modo)
         VALUES (:p, NULL, :d, 1.00, 1, 'manual')"
    );
    foreach ($nomes as $rotulo => $nome) {
        $ins->execute(['p' => $produtoId, 'd' => $nome]);
        $linhas[(int) $pdo->lastInsertId()] = $rotulo;
    }

    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
if ($req['method'] === 'POST') {
    $_POST = $req['post'];
}
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
$_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'admin'];
$_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode(['flash' => $_SESSION['_flash'] ?? null]);
});
require $req['root'] . '/app/routes.php';
PHP);

    file_put_contents($nodeScript, <<<'JS'
const fs = require('fs');
const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const out = input.handlers.map((code) => {
    const mensagens = [];
    let executouInjecao = false;
    globalThis.confirm = (m) => { mensagens.push(m); return input.resposta; };
    globalThis.alert = () => { executouInjecao = true; };
    globalThis.document = { get cookie() { executouInjecao = true; return ''; } };
    let retorno = null;
    let erro = null;
    try {
        retorno = (new Function(code))();
    } catch (e) {
        erro = String(e);
    }
    return { mensagens, retorno, executouInjecao, erro };
});
process.stdout.write(JSON.stringify(out));
JS);

    $pagina = rota('GET', '/produtos/' . $produtoId, [], $userId);
    $handlers = handlersRemover($pagina['html'], $produtoId);
    ok('página renderizada com um form "Remover" por custo', count($handlers) === count($nomes), 'forms=' . count($handlers));

    $ids = array_keys($linhas);
    $codigos = array_map(static fn (int $id): string => $handlers[$id] ?? '', $ids);

    foreach ([false => 'cancelar', true => 'confirmar'] as $resposta => $acao) {
        $res = executarNoNode($codigos, (bool) $resposta);
        if ($res === null) {
            ok("node executou os handlers ({$acao})", false);
            continue;
        }
        foreach ($ids as $i => $id) {
            $rotulo = $linhas[$id];
            $esperado = 'Remover "' . $nomes[$rotulo] . '" da formação?';
            $r = $res[$i];
            ok(
                "{$rotulo} ({$acao}): confirm exibe o texto exato, sem executar injeção",
                $r['erro'] === null && $r['executouInjecao'] === false
                    && $r['mensagens'] === [$esperado] && $r['retorno'] === (bool) $resposta,
                $r['erro'] !== null ? 'erro=' . $r['erro'] : 'retorno=' . var_export($r['retorno'], true)
            );
        }
    }

    foreach ($ids as $id) {
        $codigo = $handlers[$id] ?? '';
        $literal = preg_match('/^return confirm\(("(?:[^"\\\\\r\n]|\\\\.)*")\);$/', $codigo, $m) ? $m[1] : null;
        ok(
            "{$linhas[$id]}: onsubmit é só confirm(<string JSON única>)",
            $literal !== null && !preg_match('/[\'<>&]/', $literal)
                && json_decode($literal) === 'Remover "' . $nomes[$linhas[$id]] . '" da formação?'
        );
    }

    // Controle negativo: o padrão antigo (só e()) seria explorável — prova que o teste detecta o problema.
    $antigo = html_entity_decode(
        "return confirm('Remover &quot;" . e($nomes['injeção JS concatenada']) . "&quot; da formação?');",
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $res = executarNoNode([$antigo], false);
    ok('controle: padrão antigo com e() executaria a injeção', ($res[0]['executouInjecao'] ?? false) === true);

    // Confirmar continua removendo pela rota normal (o action do form não mudou).
    $idRemover = array_search('injeção JS', $linhas, true);
    $r = rota('POST', '/produtos/' . $produtoId . '/composicao/' . $idRemover . '/remover', [], $userId);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM produto_composicao WHERE id = :id');
    $stmt->execute(['id' => $idRemover]);
    ok('confirmar: POST de remoção remove a linha', (int) $stmt->fetchColumn() === 0);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM produto_composicao WHERE produto_id = :p');
    $stmt->execute(['p' => $produtoId]);
    ok('confirmar: demais linhas preservadas', (int) $stmt->fetchColumn() === count($nomes) - 1);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($produtoId > 0) {
        $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :id')->execute(['id' => $produtoId]);
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $produtoId]);
    }
    foreach ([$runner, $nodeScript, $nodeInput] as $f) {
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-XSS-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

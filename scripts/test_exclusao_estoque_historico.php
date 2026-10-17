<?php
/**
 * Testes locais — exclusão de peças e papéis protegida pelo histórico de movimentos (P1-6).
 * Item sem movimento: admin exclui e a imagem é removida. Item com qualquer movimento: exclusão
 * bloqueada, cadastro, saldo, imagem e histórico intactos. Inclui concorrência real com um segundo processo.
 * Cria dados temporários (TST-HIST-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_exclusao_estoque_historico.php
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

if (!defined('PM_TEST_UPLOAD')) {
    define('PM_TEST_UPLOAD', true);
}

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
$prefixo = 'TST-HIST-' . $sufixo;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_hist_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$concorrente = $tmpDir . DIRECTORY_SEPARATOR . 'concorrente.php';
$usuariosTmp = [];
$imagens = [];
$uploadsRoot = dirname(__DIR__) . '/public/uploads';
$dirsImagem = [];
foreach (['estoque/pecas', 'estoque/papeis'] as $sub) {
    foreach ([$sub, $sub . '/' . date('Y'), $sub . '/' . date('Y') . '/' . date('m')] as $d) {
        $dirsImagem[$uploadsRoot . '/' . $d] = is_dir($uploadsRoot . '/' . $d);
    }
}

/** Checksum de todas as tabelas + estrutura: qualquer dado permanente alterado muda a assinatura. */
function assinaturaBanco(): string
{
    $pdo = Database::pdo();
    $tabelas = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_COLUMN);
    $s = '';
    foreach ($tabelas as $t) {
        $s .= $t . '=' . $pdo->query('CHECKSUM TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] . ';';
    }
    $pdo->exec('SET SESSION group_concat_max_len = 16777216');
    $s .= (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE) ORDER BY TABLE_NAME, ORDINAL_POSITION)
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
    $s .= (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS(':', CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE) ORDER BY CONSTRAINT_NAME)
        FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn();
    return md5($s);
}

/** Executa a rota real num subprocesso, logado como $userId; devolve HTML e flash. */
function rota(string $method, string $uri, int $userId, array $post = []): array
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

function flashTexto(array $r, string $tipo): string
{
    $v = $r['flash'][$tipo] ?? '';
    return is_array($v) ? implode(' | ', $v) : (string) $v;
}

function valor(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function arquivoExiste(?string $caminho): bool
{
    clearstatcache();
    return $caminho !== null && $caminho !== '' && is_file(dirname(__DIR__) . '/public/uploads/' . $caminho);
}

function novaImagem(string $tipo): string
{
    global $tmpDir, $imagens;
    $tmp = $tmpDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '.png';
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($tmp, $png);
    $file = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($png), 'name' => 'dot.png'];
    $up = $tipo === 'peca' ? Upload::pecaImagem($file) : Upload::papelImagem($file);
    if (empty($up['ok']) || empty($up['path'])) {
        throw new RuntimeException('Falha ao gravar imagem temporária: ' . ($up['error'] ?? ''));
    }
    $imagens[] = (string) $up['path'];
    return (string) $up['path'];
}

function novaPeca(string $sufixoCodigo, float $quantidade, bool $comImagem): int
{
    global $prefixo;
    return EstoquePeca::create([
        'codigo' => $prefixo . '-' . $sufixoCodigo, 'nome' => 'Peça ' . $sufixoCodigo, 'tipo' => 'outra', 'produto_id' => null,
        'cor' => null, 'estampa' => null, 'quantidade' => $quantidade, 'unidade' => 'un', 'localizacao' => null,
        'imagem' => $comImagem ? novaImagem('peca') : null, 'observacao' => null, 'status' => 'ativo',
    ], null);
}

function novoPapel(string $sufixoCodigo, float $quantidade, bool $comImagem): int
{
    global $prefixo;
    return EstoquePapel::create([
        'codigo' => $prefixo . '-' . $sufixoCodigo, 'nome' => 'Papel ' . $sufixoCodigo, 'cor' => null, 'formato' => null,
        'gramatura' => null, 'quantidade' => $quantidade, 'unidade' => 'folha', 'localizacao' => null,
        'imagem' => $comImagem ? novaImagem('papel') : null, 'observacao' => null, 'status' => 'ativo',
    ], null);
}

/** Retrato do item e do histórico para comparar antes/depois de uma tentativa bloqueada. */
function retrato(string $tabela, string $tabMov, string $fk, int $id): string
{
    $item = Database::pdo()->prepare("SELECT id, codigo, nome, quantidade, imagem, status FROM {$tabela} WHERE id = :id");
    $item->execute(['id' => $id]);
    $mov = Database::pdo()->prepare("SELECT GROUP_CONCAT(CONCAT_WS(':', id, tipo, quantidade, IFNULL(saldo_anterior, 'N'), saldo_apos) ORDER BY id) FROM {$tabMov} WHERE {$fk} = :id");
    $mov->execute(['id' => $id]);
    return json_encode([$item->fetch(), $mov->fetchColumn()]);
}

/**
 * Segundo processo que segura a trava da linha (FOR UPDATE) por alguns segundos:
 * modo "movimento" insere um movimento como registrarMovimento; modo "exclusao" apaga o item sem movimentos.
 *
 * @return array{0:resource, 1:array<int, resource>}
 */
function iniciarConcorrente(string $modo, string $tabela, string $tabMov, string $fk, int $id): array
{
    global $concorrente;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($concorrente) . ' ' . implode(' ', array_map('escapeshellarg', [dirname(__DIR__), $modo, $tabela, $tabMov, $fk, (string) $id]));
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Não foi possível iniciar o processo concorrente.');
    }
    $linha = trim((string) fgets($pipes[1]));
    if ($linha !== 'TRAVADO') {
        throw new RuntimeException('Processo concorrente não travou a linha: ' . $linha . stream_get_contents($pipes[2]));
    }
    return [$proc, $pipes];
}

function finalizarConcorrente(array $c): string
{
    [$proc, $pipes] = $c;
    $saida = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $saida;
}

$sigAntes = assinaturaBanco();
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;
$msgPeca = 'Esta peça possui movimentações de estoque e não pode ser excluída. Inative-a para preservar o histórico.';
$msgPapel = 'Este papel possui movimentações de estoque e não pode ser excluído. Inative-o para preservar o histórico.';

try {
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
$_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
$_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode(['flash' => $_SESSION['_flash'] ?? null]);
});
require $req['root'] . '/app/routes.php';
PHP);
    file_put_contents($concorrente, <<<'PHP'
<?php
declare(strict_types=1);
[, $root, $modo, $tabela, $tabMov, $fk, $id] = $argv;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/app/bootstrap.php';
$pdo = Database::pdo();
$pdo->beginTransaction();
$row = $pdo->query("SELECT id, quantidade FROM {$tabela} WHERE id = " . (int) $id . ' FOR UPDATE')->fetch();
if ($modo === 'movimento') {
    $saldo = (float) $row['quantidade'];
    $pdo->prepare("INSERT INTO {$tabMov} ({$fk}, tipo, quantidade, saldo_anterior, saldo_apos, observacao) VALUES (:id, 'entrada', 1, :a, :b, 'concorrente')")
        ->execute(['id' => (int) $id, 'a' => $saldo, 'b' => $saldo + 1]);
    $pdo->prepare("UPDATE {$tabela} SET quantidade = :q WHERE id = :id")->execute(['q' => $saldo + 1, 'id' => (int) $id]);
} else {
    $pdo->prepare("DELETE FROM {$tabela} WHERE id = :id")->execute(['id' => (int) $id]);
}
echo "TRAVADO\n";
fflush(STDOUT);
sleep(2);
$pdo->commit();
echo 'COMMIT';
PHP);

    $admin = Usuario::create(['nome' => 'TST HIST admin', 'email' => 'tst-hist-' . $sufixo . '-admin@teste.local',
        'senha_hash' => Usuario::hashSenha('senha-' . $sufixo), 'status' => 'ativo', 'perfil' => 'admin']);
    $usuariosTmp[] = $admin;
    $user = Usuario::create(['nome' => 'TST HIST usuario', 'email' => 'tst-hist-' . $sufixo . '-usuario@teste.local',
        'senha_hash' => Usuario::hashSenha('senha-' . $sufixo), 'status' => 'ativo', 'perfil' => 'usuario']);
    $usuariosTmp[] = $user;

    // ================= PEÇAS =================
    $p1 = novaPeca('P1', 0, true);
    $img1 = (string) EstoquePeca::find($p1)['imagem'];
    $html = rota('GET', '/estoque/pecas/' . $p1, $admin)['html'];
    ok('peça sem movimento: admin vê o botão Excluir', str_contains($html, '/estoque/pecas/' . $p1 . '/excluir') && !str_contains($html, 'exclusao-bloqueada-historico'));
    $r = rota('POST', '/estoque/pecas/' . $p1 . '/excluir', $admin);
    ok('1. peça sem movimento: exclusão permitida e imagem removida', !EstoquePeca::find($p1) && !arquivoExiste($img1)
        && flashTexto($r, 'success') === 'Peça excluída do estoque.', flashTexto($r, 'errors'));

    $casosPeca = [];
    $p2 = novaPeca('P2', 10, true);
    $casosPeca['2. peça com estoque inicial'] = $p2;
    $p3 = novaPeca('P3', 0, true);
    EstoquePeca::registrarMovimento($p3, 'entrada', 5, null, null);
    $casosPeca['3. peça só com entrada'] = $p3;
    $p4 = novaPeca('P4', 10, true);
    EstoquePeca::registrarMovimento($p4, 'saida', 3, null, null);
    $pdo->prepare("DELETE FROM estoque_pecas_movimentos WHERE peca_id = :id AND tipo = 'entrada'")->execute(['id' => $p4]);
    $casosPeca['4. peça só com saída'] = $p4;
    $p5 = novaPeca('P5', 0, true);
    EstoquePeca::registrarMovimento($p5, 'ajuste', 7, null, null);
    $casosPeca['5. peça só com ajuste'] = $p5;

    foreach ($casosPeca as $nome => $id) {
        $tipos = $pdo->prepare('SELECT GROUP_CONCAT(tipo ORDER BY id) FROM estoque_pecas_movimentos WHERE peca_id = :id');
        $tipos->execute(['id' => $id]);
        $antes = retrato('estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', $id);
        $img = (string) EstoquePeca::find($id)['imagem'];
        $html = rota('GET', '/estoque/pecas/' . $id, $admin)['html'];
        $semBotao = !str_contains($html, '/estoque/pecas/' . $id . '/excluir') && str_contains($html, 'exclusao-bloqueada-historico');
        $r = rota('POST', '/estoque/pecas/' . $id . '/excluir', $admin);
        ok($nome . ': exclusão bloqueada com orientação para inativar', flashTexto($r, 'errors') === $msgPeca && flashTexto($r, 'success') === '',
            'movimentos=' . $tipos->fetchColumn());
        ok($nome . ': tela sem botão Excluir e com aviso', $semBotao);
        ok($nome . ': cadastro, saldo e movimentos intactos', EstoquePeca::find($id) !== null
            && retrato('estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', $id) === $antes);
        ok($nome . ': imagem mantida', arquivoExiste($img));
    }

    $antes = retrato('estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', $p2);
    rota('POST', '/estoque/pecas/' . $p2 . '/status', $admin);
    $depois = json_decode(retrato('estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', $p2), true);
    $esperado = json_decode($antes, true);
    $esperado[0]['status'] = 'inativo';
    ok('9. inativação preserva cadastro, saldo, imagem e histórico', $depois == $esperado && arquivoExiste((string) $depois[0]['imagem']));
    $html = rota('GET', '/estoque/pecas/' . $p2, $admin)['html'];
    ok('9. peça inativa com histórico: botão Reativar disponível e Excluir oculto', str_contains($html, 'Reativar') && !str_contains($html, '/estoque/pecas/' . $p2 . '/excluir'));
    $r = rota('POST', '/estoque/pecas/' . $p2 . '/excluir', $admin);
    ok('9. peça inativa com histórico continua sem poder ser excluída', flashTexto($r, 'errors') === $msgPeca && EstoquePeca::find($p2) !== null);

    // ================= PAPÉIS =================
    $q1 = novoPapel('Q1', 0, true);
    $imgQ1 = (string) EstoquePapel::find($q1)['imagem'];
    ok('papel sem movimento: admin vê o botão Excluir', str_contains(rota('GET', '/estoque/papeis/' . $q1, $admin)['html'], '/estoque/papeis/' . $q1 . '/excluir'));
    $r = rota('POST', '/estoque/papeis/' . $q1 . '/excluir', $admin);
    ok('10. papel sem movimento: exclusão permitida e imagem removida', !EstoquePapel::find($q1) && !arquivoExiste($imgQ1)
        && flashTexto($r, 'success') === 'Papel excluído do estoque.', flashTexto($r, 'errors'));

    $q2 = novoPapel('Q2', 0, true);
    EstoquePapel::registrarMovimento($q2, 'entrada', 4, null, null);
    $q3 = novoPapel('Q3', 50, true);
    EstoquePapel::registrarMovimento($q3, 'saida', 5, null, null);
    EstoquePapel::registrarMovimento($q3, 'ajuste', 30, null, null);
    foreach (['papel só com entrada' => $q2, 'papel com estoque inicial, saída e ajuste' => $q3] as $nome => $id) {
        $antes = retrato('estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', $id);
        $img = (string) EstoquePapel::find($id)['imagem'];
        $html = rota('GET', '/estoque/papeis/' . $id, $admin)['html'];
        $r = rota('POST', '/estoque/papeis/' . $id . '/excluir', $admin);
        ok('11. ' . $nome . ': exclusão bloqueada com orientação para inativar', flashTexto($r, 'errors') === $msgPapel && flashTexto($r, 'success') === '');
        ok('11. ' . $nome . ': tela sem botão Excluir e com aviso', !str_contains($html, '/estoque/papeis/' . $id . '/excluir') && str_contains($html, 'exclusao-bloqueada-historico'));
        ok('12/13. ' . $nome . ': cadastro, saldo e movimentos intactos', EstoquePapel::find($id) !== null
            && retrato('estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', $id) === $antes);
        ok('14. ' . $nome . ': imagem mantida', arquivoExiste($img));
    }

    $antes = retrato('estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', $q3);
    rota('POST', '/estoque/papeis/' . $q3 . '/status', $admin);
    $depois = json_decode(retrato('estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', $q3), true);
    $esperado = json_decode($antes, true);
    $esperado[0]['status'] = 'inativo';
    ok('15. inativação do papel preserva cadastro, saldo, imagem e histórico', $depois == $esperado && arquivoExiste((string) $depois[0]['imagem']));
    rota('POST', '/estoque/papeis/' . $q3 . '/status', $admin);
    ok('15. reativação do papel mantém o histórico', EstoquePapel::find($q3)['status'] === 'ativo'
        && (int) valor('SELECT COUNT(*) FROM estoque_papeis_movimentos WHERE papel_id = :id', ['id' => $q3]) === 3);

    // ================= SEGURANÇA =================
    $p6 = novaPeca('P6', 0, true);
    $q6 = novoPapel('Q6', 0, true);
    $html = rota('GET', '/estoque/pecas/' . $p6, $user)['html'];
    ok('16. usuário comum: tela sem botão Excluir nem aviso de admin', !str_contains($html, '/estoque/pecas/' . $p6 . '/excluir') && !str_contains($html, 'exclusao-bloqueada-historico'));
    $r = rota('POST', '/estoque/pecas/' . $p6 . '/excluir', $user);
    ok('16. usuário comum: não exclui peça sem histórico', str_contains(flashTexto($r, 'errors'), 'Você não tem permissão')
        && EstoquePeca::find($p6) !== null && arquivoExiste((string) EstoquePeca::find($p6)['imagem']));
    $r = rota('POST', '/estoque/papeis/' . $q6 . '/excluir', $user);
    ok('16. usuário comum: não exclui papel sem histórico', str_contains(flashTexto($r, 'errors'), 'Você não tem permissão') && EstoquePapel::find($q6) !== null);
    $r = rota('POST', '/estoque/pecas/' . $p6 . '/excluir', $admin, ['_csrf' => 'invalido']);
    ok('17. CSRF: admin com token inválido não exclui peça (419)', str_contains($r['html'], 'Sessão expirada ou token inválido')
        && EstoquePeca::find($p6) !== null && arquivoExiste((string) EstoquePeca::find($p6)['imagem']));
    $r = rota('POST', '/estoque/papeis/' . $q6 . '/excluir', $admin, ['_csrf' => 'invalido']);
    ok('17. CSRF: admin com token inválido não exclui papel (419)', str_contains($r['html'], 'Sessão expirada ou token inválido') && EstoquePapel::find($q6) !== null);
    $r = rota('POST', '/estoque/pecas/999999999/excluir', $admin);
    ok('peça inexistente: página 404, sem exceção', str_contains($r['html'], 'Peça não encontrada') && flashTexto($r, 'success') === '');

    // ================= CONCORRÊNCIA =================
    foreach ([
        'peça' => ['estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', 'EstoquePeca', $p6],
        'papel' => ['estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', 'EstoquePapel', $q6],
    ] as $nome => [$tabela, $tabMov, $fk, $model, $id]) {
        // B registra um movimento e segura a trava; A tenta excluir: espera a trava e vê o movimento.
        $c = iniciarConcorrente('movimento', $tabela, $tabMov, $fk, $id);
        $t0 = microtime(true);
        $res = $model::excluirSemMovimentos($id);
        $espera = microtime(true) - $t0;
        $saida = finalizarConcorrente($c);
        ok("concorrência ({$nome}): movimento simultâneo impede a exclusão", $res['status'] === 'com_movimentos' && $espera >= 1.5
            && $model::find($id) !== null && (int) valor("SELECT COUNT(*) FROM {$tabMov} WHERE {$fk} = :id", ['id' => $id]) === 1,
            sprintf('status=%s espera=%.1fs concorrente=%s', $res['status'], $espera, $saida));
        ok("concorrência ({$nome}): imagem mantida", arquivoExiste((string) $model::find($id)['imagem']));
    }

    foreach ([
        'peça' => ['estoque_pecas', 'estoque_pecas_movimentos', 'peca_id', 'EstoquePeca', novaPeca('P7', 0, false), 'Peça não encontrada.'],
        'papel' => ['estoque_papeis', 'estoque_papeis_movimentos', 'papel_id', 'EstoquePapel', novoPapel('Q7', 0, false), 'Papel não encontrado.'],
    ] as $nome => [$tabela, $tabMov, $fk, $model, $id, $msgNaoEncontrado]) {
        // A exclui (sem movimentos) e segura a trava; B tenta registrar movimento: espera e não acha o item.
        $c = iniciarConcorrente('exclusao', $tabela, $tabMov, $fk, $id);
        $erro = '';
        try {
            $model::registrarMovimento($id, 'entrada', 1, null, null);
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }
        finalizarConcorrente($c);
        ok("concorrência ({$nome}): movimento após exclusão simultânea é recusado sem gerar órfão", $erro === $msgNaoEncontrado
            && $model::find($id) === null && (int) valor("SELECT COUNT(*) FROM {$tabMov} WHERE {$fk} = :id", ['id' => $id]) === 0, $erro);
    }

    // ================= delete() de limpeza segue sem bloqueio =================
    EstoquePeca::delete($p5);
    EstoquePapel::delete($q2);
    ok('delete() de limpeza continua apagando itens temporários com histórico', EstoquePeca::find($p5) === null && EstoquePapel::find($q2) === null
        && (int) valor('SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = :id', ['id' => $p5]) === 0);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $like = $prefixo . '-%';
    $pdo->prepare('DELETE FROM estoque_pecas WHERE codigo LIKE :c')->execute(['c' => $like]);
    $pdo->prepare('DELETE FROM estoque_papeis WHERE codigo LIKE :c')->execute(['c' => $like]);
    foreach ($usuariosTmp as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $uid]);
    }
    foreach (array_unique($imagens) as $img) {
        Upload::removerImagemPublica($img);
    }
    foreach (array_reverse($dirsImagem, true) as $dir => $existia) {
        if (!$existia && is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }
    }
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
}

$sobras = (int) valor("SELECT (SELECT COUNT(*) FROM estoque_pecas WHERE codigo LIKE 'TST-HIST-%') + (SELECT COUNT(*) FROM estoque_papeis WHERE codigo LIKE 'TST-HIST-%')
    + (SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-hist-%')");
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('limpeza: nenhuma imagem temporária restante', count(array_filter(array_unique($imagens), 'arquivoExiste')) === 0 && !is_dir($tmpDir));
$sigDepois = assinaturaBanco();
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('banco idêntico ao inicial (dados permanentes e estrutura)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

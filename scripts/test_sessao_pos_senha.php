<?php
/**
 * Testes locais — sessões invalidadas após alteração de senha (P1-9, impressão digital da senha).
 * Login real por POST /login, sessões reais em arquivo (um "computador" = um ID de sessão),
 * usuários temporários (tst-sps-*) removidos ao final. Nada injeta usuário na sessão, exceto o
 * cenário de sessão antiga (sem impressão), que simula uma sessão criada antes do deploy.
 * NÃO executar em produção. Uso: php scripts/test_sessao_pos_senha.php
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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sps_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$ipTeste = '192.0.2.' . random_int(1, 254);
const MSG_SENHA = 'Sua senha foi alterada. Entre novamente.';
const MSG_ANTIGA = 'Sua sessão expirou. Entre novamente.';

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
    $s .= (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, 'N')) ORDER BY TABLE_NAME, ORDINAL_POSITION)
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
    return md5($s);
}

/**
 * Requisição real num subprocesso, com o cookie de sessão $sid (vazio = navegador novo).
 * $opcoes: 'legado' => id (grava usuário na sessão sem impressão), 'sem_chave' => true (app_key vazio só nesse processo).
 */
function req(string $method, string $uri, string $sid = '', array $post = [], array $opcoes = []): array
{
    global $runner, $sessDir, $ipTeste;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $r = ['method' => $method, 'uri' => $uri, 'sid' => $sid, 'post' => $post + ['_csrf' => $token], 'token' => $token, 'ip' => $ipTeste,
        'sess_dir' => $sessDir, 'root' => dirname(__DIR__), 'opcoes' => $opcoes];
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($r))));
    $pos = strrpos($out, '@@RESULT@@');
    $res = $pos === false ? [] : (json_decode(substr($out, $pos + 10), true) ?: []);
    $res['html'] = $pos === false ? $out : substr($out, 0, $pos);
    return $res;
}

function login(string $email, string $senha, array $opcoes = []): array
{
    return req('POST', '/login', 'novo' . bin2hex(random_bytes(12)), ['email' => $email, 'password' => $senha], $opcoes);
}

function logado(array $r): bool
{
    return !empty($r['user']) && !empty($r['fp']);
}

function arquivoSessao(string $sid): string
{
    global $sessDir;
    clearstatcache();
    $f = $sessDir . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    return is_file($f) ? (string) file_get_contents($f) : '';
}

function hashDe(int $id): string
{
    return (string) (Usuario::find($id)['senha_hash'] ?? '');
}

$sigAntes = assinaturaBanco();
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;
$sqlUsuariosPermanentes = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', id, nome, email, senha_hash, status, perfil, created_at, updated_at) ORDER BY id), '')) FROM usuarios WHERE email NOT LIKE 'tst-sps-%'";
$usuariosAntes = (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn();

try {
    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
if ($req['sid'] !== '') {
    session_id($req['sid']);
}
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
$_SERVER['REMOTE_ADDR'] = $req['ip'];
if ($req['method'] === 'POST') {
    $_POST = $req['post'];
}
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
if (!empty($req['opcoes']['legado'])) {
    $_SESSION['user'] = ['id' => (int) $req['opcoes']['legado'], 'nome' => 'Legado', 'email' => 'legado@local', 'perfil' => 'usuario'];
    Session::writeClose();
    echo '@@RESULT@@' . json_encode(['sid' => session_id()]);
    exit;
}
if (!empty($req['opcoes']['sem_chave'])) {
    $GLOBALS['config']['app_key'] = '';
}
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode([
        'sid' => session_id(), 'code' => http_response_code(),
        'user' => $_SESSION['user'] ?? null, 'fp' => $_SESSION[Auth::CHAVE_IMPRESSAO] ?? null,
        'flash_error' => $_SESSION['flash_error'] ?? null, 'flash_success' => $_SESSION['flash_success'] ?? null,
        'flash' => $_SESSION['_flash'] ?? null,
    ]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $senha1 = 'senha-um-' . $sufixo;
    $senha2 = 'senha-dois-' . $sufixo;
    $senha3 = 'senha-tres-' . $sufixo;
    $senhaAdm = 'senha-adm-' . $sufixo;
    $emailU = 'tst-sps-' . $sufixo . '-u@teste.local';
    $emailV = 'tst-sps-' . $sufixo . '-v@teste.local';
    $emailA = 'tst-sps-' . $sufixo . '-a@teste.local';
    $u = Usuario::create(['nome' => 'TST SPS U', 'email' => $emailU, 'senha_hash' => Usuario::hashSenha($senha1), 'status' => 'ativo', 'perfil' => 'usuario']);
    $v = Usuario::create(['nome' => 'TST SPS V', 'email' => $emailV, 'senha_hash' => Usuario::hashSenha($senha1), 'status' => 'ativo', 'perfil' => 'usuario']);
    $a = Usuario::create(['nome' => 'TST SPS A', 'email' => $emailA, 'senha_hash' => Usuario::hashSenha($senhaAdm), 'status' => 'ativo', 'perfil' => 'admin']);

    // 1-3. Login real cria a impressão
    $pre = 'pre' . bin2hex(random_bytes(12));
    $r1 = req('POST', '/login', $pre, ['email' => $emailU, 'password' => $senha1]);
    $sid1 = (string) ($r1['sid'] ?? '');
    $esperada = hash_hmac('sha256', hashDe($u), (string) config('app_key'));
    ok('1. login real por POST cria a impressão digital na sessão', logado($r1) && hash_equals($esperada, (string) $r1['fp']));
    ok('1. login continua regenerando o ID da sessão', $sid1 !== '' && $sid1 !== $pre);
    $conteudo = arquivoSessao($sid1);
    ok('2. sessão não contém a senha em texto nem o senha_hash', $conteudo !== '' && !str_contains($conteudo, $senha1) && !str_contains($conteudo, hashDe($u))
        && preg_match('/^[0-9a-f]{64}$/', (string) $r1['fp']) === 1);
    $r = req('GET', '/produtos', $sid1);
    ok('3. sessão autenticada continua válida nas requisições seguintes', logado($r) && !in_array($r['code'] ?? null, [302, 403, 419], true)
        && empty($r['flash_error']) && str_contains($r['html'], 'TST SPS U'));
    ok('2. página não expõe a impressão nem o hash', !str_contains($r['html'], (string) $r1['fp']) && !str_contains($r['html'], hashDe($u)));

    // 4-10. Dois computadores, troca da própria senha
    $r2 = login($emailU, $senha1);
    $sid2 = (string) ($r2['sid'] ?? '');
    ok('4. dois computadores com sessões independentes', logado($r2) && $sid2 !== $sid1 && logado(req('GET', '/produtos', $sid1)) && logado(req('GET', '/produtos', $sid2)));
    $r = req('POST', '/configuracoes/senha', $sid1, ['senha_atual' => $senha1, 'nova_senha' => $senha2, 'confirmar_senha' => $senha2]);
    ok('5. computador 1 troca a senha', ($r['flash_success'] ?? '') === 'Senha alterada com sucesso. Faça login novamente.' && Usuario::verificarSenha($senha2, hashDe($u)));
    ok('6. computador 1 é deslogado (comportamento atual)', !logado($r) && !logado(req('GET', '/produtos', $sid1)));
    $r = req('GET', '/produtos', $sid2);
    ok('7. computador 2 é deslogado na próxima requisição', !logado($r) && ($r['code'] ?? 0) === 302);
    ok('8. mensagem de senha alterada para o computador 2', ($r['flash_error'] ?? '') === MSG_SENHA);
    $r = req('GET', '/login', (string) ($r['sid'] ?? ''));
    ok('8. a mensagem aparece na tela de login', str_contains($r['html'], MSG_SENHA));
    ok('7. o cookie antigo do computador 2 não volta a valer', !logado(req('GET', '/produtos', $sid2)));
    ok('9. login com a senha antiga falha', !logado(login($emailU, $senha1)));
    $r3 = login($emailU, $senha2);
    ok('10. login com a senha nova funciona', logado($r3));
    $sid3 = (string) $r3['sid'];

    // 11-12. Admin redefine a senha de outro usuário
    $rAdm = login($emailA, $senhaAdm);
    $sidAdm = (string) ($rAdm['sid'] ?? '');
    $r = req('POST', '/configuracoes/usuarios/' . $u . '/senha', $sidAdm, ['nova_senha' => $senha3, 'confirmar_senha' => $senha3]);
    ok('11. admin redefine a senha de outro usuário', Usuario::verificarSenha($senha3, hashDe($u)) && str_contains(json_encode($r['flash'] ?? [], JSON_UNESCAPED_UNICODE), 'Senha do usuário atualizada.'));
    $r = req('GET', '/produtos', $sid3);
    ok('12. sessão antiga desse usuário é invalidada com a mensagem', !logado($r) && ($r['flash_error'] ?? '') === MSG_SENHA);
    ok('12. sessão do admin continua válida', logado(req('GET', '/produtos', $sidAdm)));

    // 13-14. Inativação e perfil
    $sidU = (string) login($emailU, $senha3)['sid'];
    req('POST', '/configuracoes/usuarios/' . $u, $sidAdm, ['nome' => 'TST SPS U', 'email' => $emailU, 'status' => 'ativo', 'perfil' => 'admin']);
    $r = req('GET', '/produtos', $sidU);
    ok('14. promoção percebida na próxima requisição (sessão continua válida)', logado($r) && ($r['user']['perfil'] ?? '') === 'admin');
    req('POST', '/configuracoes/usuarios/' . $u, $sidAdm, ['nome' => 'TST SPS U', 'email' => $emailU, 'status' => 'ativo', 'perfil' => 'usuario']);
    $r = req('GET', '/produtos', $sidU);
    ok('14. rebaixamento percebido na próxima requisição', logado($r) && ($r['user']['perfil'] ?? '') === 'usuario');
    $r = req('POST', '/configuracoes/usuarios/' . $v . '/senha', $sidU, ['nova_senha' => 'invasor-123', 'confirmar_senha' => 'invasor-123']);
    ok('16. usuário comum não redefine a senha de outro', str_contains(json_encode($r['flash'] ?? [], JSON_UNESCAPED_UNICODE), 'Você não tem permissão')
        && Usuario::verificarSenha($senha1, hashDe($v)));
    req('POST', '/configuracoes/usuarios/' . $u . '/status', $sidAdm);
    $r = req('GET', '/produtos', $sidU);
    ok('13. usuário inativado continua sendo derrubado', !logado($r) && ($r['flash_error'] ?? '') === 'Seu acesso foi desativado. Entre em contato com o administrador.');
    ok('13. usuário inativo não consegue logar', !logado(login($emailU, $senha3)));
    req('POST', '/configuracoes/usuarios/' . $u . '/status', $sidAdm);

    // 15. CSRF
    $sidU = (string) login($emailU, $senha3)['sid'];
    $hashAntes = hashDe($u);
    $r = req('POST', '/configuracoes/senha', $sidU, ['_csrf' => 'invalido', 'senha_atual' => $senha3, 'nova_senha' => 'outra-senha-1', 'confirmar_senha' => 'outra-senha-1']);
    ok('15. CSRF inválido na troca de senha é recusado (419) e a senha não muda', str_contains($r['html'], 'Sessão expirada ou token inválido') && hashDe($u) === $hashAntes);
    ok('15. a sessão continua válida depois da recusa', logado(req('GET', '/produtos', $sidU)));
    $r = req('POST', '/login', '', ['_csrf' => 'invalido', 'email' => $emailU, 'password' => $senha3]);
    ok('15. login continua exigindo CSRF', str_contains($r['html'], 'Sessão expirada ou token inválido') && !logado($r));

    // 17-18. Sessão antiga (sem impressão)
    $legado = req('GET', '/login', 'leg' . bin2hex(random_bytes(12)), [], ['legado' => $u]);
    $sidLeg = (string) ($legado['sid'] ?? '');
    ok('17. sessão antiga simulada tem usuário e não tem impressão', str_contains(arquivoSessao($sidLeg), 'Legado') && !str_contains(arquivoSessao($sidLeg), Auth::CHAVE_IMPRESSAO));
    $r = req('GET', '/produtos', $sidLeg);
    ok('17. sessão sem impressão é tratada como antiga e invalidada', !logado($r) && ($r['code'] ?? 0) === 302 && ($r['flash_error'] ?? '') === MSG_ANTIGA);
    ok('17. a sessão antiga não ganha impressão nem volta a valer', !logado(req('GET', '/produtos', $sidLeg)) && !str_contains(arquivoSessao($sidLeg), 'Legado'));
    $r = login($emailU, $senha3);
    ok('18. novo login cria a impressão novamente', logado($r) && hash_equals(hash_hmac('sha256', hashDe($u), (string) config('app_key')), (string) $r['fp']));

    // Impressão adulterada e app_key ausente
    $sidT = (string) $r['sid'];
    $c = arquivoSessao($sidT);
    file_put_contents($sessDir . DIRECTORY_SEPARATOR . 'sess_' . $sidT, str_replace((string) $r['fp'], str_repeat('0', 64), $c));
    $r = req('GET', '/produtos', $sidT);
    ok('impressão adulterada na sessão invalida a sessão', !logado($r) && ($r['flash_error'] ?? '') === MSG_SENHA);
    $r = login($emailU, $senha3, ['sem_chave' => true]);
    ok('sem app_key: login recusado com mensagem clara, sem criar sessão autenticada', !logado($r) && ($r['flash_error'] ?? '') === Auth::MSG_SEM_CHAVE);
    $sidK = (string) login($emailU, $senha3)['sid'];
    $r = req('GET', '/produtos', $sidK, [], ['sem_chave' => true]);
    ok('sem app_key: sessão existente não é aceita', !logado($r) && ($r['flash_error'] ?? '') === Auth::MSG_SEM_CHAVE);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo->exec("DELETE FROM usuarios WHERE email LIKE 'tst-sps-%'");
    $pdo->prepare("DELETE FROM login_tentativas WHERE (tipo = 'email' AND chave LIKE 'tst-sps-%') OR (tipo = 'ip' AND chave = :ip)")
        ->execute(['ip' => $ipTeste]);
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
}

ok('usuários temporários e sessões removidos', (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-sps-%'")->fetchColumn() === 0 && !is_dir($tmpDir));
ok('usuários permanentes intactos', (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn() === $usuariosAntes);
$sigDepois = assinaturaBanco();
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('banco idêntico ao inicial (dados permanentes e estrutura)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

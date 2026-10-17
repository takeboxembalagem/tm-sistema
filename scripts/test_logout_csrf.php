<?php
/**
 * Testes locais — logout somente por POST com CSRF (P1-9).
 * Requisições HTTP reais via php-cgi sobre public/index.php: cabeçalhos Status/Location/Set-Cookie,
 * cookie jar por "navegador", token CSRF lido do HTML, login real por POST. Nada é injetado na sessão.
 * Usuários temporários (tst-lgo-*) e sessões em pasta temporária, removidos ao final.
 * NÃO executar em produção. Uso: php scripts/test_logout_csrf.php
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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_lgo_' . $sufixo;
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php-cgi.exe' : 'php-cgi');
$cookieName = (string) (config('session')['name'] ?? 'pm_sess');
$ipTeste = '192.0.2.' . random_int(1, 254);

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

function escritasInnodb(): int
{
    $total = 0;
    foreach (Database::pdo()->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted')") as $l) {
        $total += (int) $l['Value'];
    }
    return $total;
}

/** Navegador mínimo: cookie jar por referência. */
function http(array &$jar, string $method, string $uri, array $post = []): array
{
    global $cgi, $tmpDir, $ipTeste;
    $body = $method === 'POST' ? http_build_query($post) : '';
    $cookie = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    $env = array_merge(getenv(), [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
        'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'QUERY_STRING' => '',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => (string) strlen($body),
        'HTTP_COOKIE' => $cookie, 'REMOTE_ADDR' => $ipTeste,
    ]);
    $cmd = escapeshellarg($cgi) . ' -d session.save_path=' . escapeshellarg($tmpDir) . ' -d display_errors=1 -d error_reporting=32767';
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    $sep = strpos($out, "\r\n\r\n");
    $head = $sep === false ? $out : substr($out, 0, $sep);
    $html = $sep === false ? '' : substr($out, $sep + 4);
    $status = 200;
    $location = null;
    $setCookies = [];
    foreach (preg_split('/\r\n/', $head) as $linha) {
        if (preg_match('/^Status:\s*(\d{3})/i', $linha, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('/^Location:\s*(.+)$/i', $linha, $m)) {
            $location = trim($m[1]);
        } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)(.*)$/i', $linha, $m)) {
            $setCookies[] = trim($linha);
            $apagar = $m[2] === '' || $m[2] === 'deleted' || preg_match('/Max-Age=0/i', $m[3]);
            if ($apagar) {
                unset($jar[$m[1]]);
            } else {
                $jar[$m[1]] = $m[2];
            }
        }
    }
    return ['status' => $status, 'location' => $location, 'html' => $html, 'set_cookies' => $setCookies, 'stderr' => $err];
}

function token(string $html): string
{
    return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
}

function semErroPhp(array $r): bool
{
    return !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/i', $r['html'] . $r['stderr']);
}

function arquivoSessao(array $jar): string
{
    global $tmpDir, $cookieName;
    clearstatcache();
    $f = $tmpDir . DIRECTORY_SEPARATOR . 'sess_' . ($jar[$cookieName] ?? '');
    return isset($jar[$cookieName]) && is_file($f) ? (string) file_get_contents($f) : '';
}

/** Login real: GET /login (cookie + token) e POST /login. */
function login(array &$jar, string $email, string $senha): array
{
    $g = http($jar, 'GET', '/login');
    return http($jar, 'POST', '/login', ['_csrf' => token($g['html']), 'email' => $email, 'password' => $senha]);
}

function vaiPara(array $r, string $path): bool
{
    return in_array($r['status'], [302, 303], true) && $r['location'] !== null && str_ends_with((string) parse_url($r['location'], PHP_URL_PATH), $path);
}

$sigAntes = assinaturaBanco();
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;
$sqlUsuariosPermanentes = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', id, nome, email, senha_hash, status, perfil, created_at, updated_at) ORDER BY id), '')) FROM usuarios WHERE email NOT LIKE 'tst-lgo-%'";
$usuariosAntes = (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn();

try {
    if (!is_file($cgi)) {
        throw new RuntimeException('php-cgi não encontrado em ' . $cgi);
    }
    @mkdir($tmpDir, 0700, true);

    $senha = 'senha-lgo-' . $sufixo;
    $senhaNova = 'senha-lgo-nova-' . $sufixo;
    $email = 'tst-lgo-' . $sufixo . '@teste.local';
    $u = Usuario::create(['nome' => 'TST LGO', 'email' => $email, 'senha_hash' => Usuario::hashSenha($senha), 'status' => 'ativo', 'perfil' => 'usuario']);

    // 1-2. Login real
    $nav = [];
    $r = login($nav, $email, $senha);
    ok('1. login real por POST redireciona para /produtos', vaiPara($r, '/produtos') && isset($nav[$cookieName]), 'status=' . $r['status']);
    $p = http($nav, 'GET', '/produtos');
    $tokenMenu = token($p['html']);
    ok('2. sessão autenticada (página protegida abre com o usuário)', $p['status'] === 200 && str_contains($p['html'], 'TST LGO') && semErroPhp($p));
    ok('menu: "Sair" é formulário POST para /logout com o token CSRF da sessão',
        preg_match('#<form method="post" action="[^"]*/logout" class="logout-form">\s*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">\s*<button type="submit" class="btn-text">Sair</button>#', $p['html']) === 1
        && $tokenMenu !== '' && !preg_match('#<a [^>]*href="[^"]*/logout"#', $p['html']));
    $sidLogado = $nav[$cookieName];

    // 3-4. GET /logout não desloga (C)
    $r = http($nav, 'GET', '/logout');
    ok('3. GET /logout não desloga: redireciona para /produtos', vaiPara($r, '/produtos') && semErroPhp($r), 'status=' . $r['status'] . ' location=' . ($r['location'] ?? '-'));
    ok('3. GET /logout não expira o cookie', ($nav[$cookieName] ?? '') === $sidLogado && !preg_grep('/deleted|Max-Age=0/i', $r['set_cookies']));
    $p = http($nav, 'GET', '/produtos');
    ok('4. sessão continua válida depois do GET', $p['status'] === 200 && str_contains($p['html'], 'TST LGO') && str_contains(arquivoSessao($nav), 'TST LGO'));

    // 5-6. POST sem CSRF / CSRF inválido (B, F)
    $r = http($nav, 'POST', '/logout');
    ok('5. POST /logout sem CSRF retorna 419', $r['status'] === 419 && str_contains($r['html'], 'Sessão expirada ou token inválido'));
    $r = http($nav, 'POST', '/logout', ['_csrf' => str_repeat('a', 64)]);
    ok('F. POST /logout com CSRF inválido retorna 419', $r['status'] === 419);
    $outro = [];
    $tokenOutro = token(http($outro, 'GET', '/login')['html']);
    $r = http($nav, 'POST', '/logout', ['_csrf' => $tokenOutro]);
    ok('F. POST /logout com token de outra sessão retorna 419', $r['status'] === 419 && $tokenOutro !== '' && $tokenOutro !== $tokenMenu);
    $p = http($nav, 'GET', '/produtos');
    ok('6. sessão continua válida depois dos POSTs inválidos', $p['status'] === 200 && str_contains($p['html'], 'TST LGO') && ($nav[$cookieName] ?? '') === $sidLogado);

    // 7-9, 11. POST válido (A)
    $arquivoAntes = $tmpDir . DIRECTORY_SEPARATOR . 'sess_' . $sidLogado;
    $escritasAntes = escritasInnodb();
    $sigLogout = assinaturaBanco();
    $r = http($nav, 'POST', '/logout', ['_csrf' => token($p['html'])]);
    $escritasDepois = escritasInnodb();
    ok('7. POST /logout com CSRF válido encerra a sessão e redireciona para /login', vaiPara($r, '/login') && semErroPhp($r), 'status=' . $r['status'] . ' location=' . ($r['location'] ?? '-'));
    clearstatcache();
    ok('8. cookie expirado na resposta', (bool) preg_grep('/^Set-Cookie:\s*' . preg_quote($cookieName, '/') . '=(deleted|);.*(expires=Thu, 01[- ]Jan[- ]1970|Max-Age=0)/i', $r['set_cookies']) && !isset($nav[$cookieName]));
    ok('8. arquivo da sessão destruído no servidor', !is_file($arquivoAntes));
    $p = http($nav, 'GET', '/produtos');
    ok('9. requisição seguinte exige login', vaiPara($p, '/login'));
    $velho = [$cookieName => $sidLogado];
    $p = http($velho, 'GET', '/produtos');
    ok('9. reutilizar o cookie antigo não recupera a sessão', vaiPara($p, '/login'));
    ok('11. POST /logout não gera escrita no banco', $escritasDepois === $escritasAntes && assinaturaBanco() === $sigLogout, 'escritas=' . ($escritasDepois - $escritasAntes));

    // 10, D, E. Sem sessão
    $anon = [];
    $r = http($anon, 'GET', '/logout');
    ok('10/D. GET /logout sem sessão vai para /login sem erro', vaiPara($r, '/login') && semErroPhp($r));
    ok('D. GET /logout sem sessão não cria sessão autenticada', vaiPara(http($anon, 'GET', '/produtos'), '/login') && !str_contains(arquivoSessao($anon), 'user|'));
    $tokAnon = token(http($anon, 'GET', '/login')['html']);
    $r = http($anon, 'POST', '/logout', ['_csrf' => $tokAnon]);
    ok('E. POST /logout sem login e com CSRF: vai para /login sem erro', $tokAnon !== '' && vaiPara($r, '/login') && semErroPhp($r));
    ok('E. continua sem sessão autenticada', vaiPara(http($anon, 'GET', '/produtos'), '/login'));
    $r = http($anon, 'POST', '/logout');
    ok('E. POST /logout sem login e sem CSRF: 419', $r['status'] === 419);

    // 12-13. CSRF e outros fluxos de autenticação
    $nav = [];
    $g = http($nav, 'GET', '/login');
    $r = http($nav, 'POST', '/login', ['_csrf' => 'invalido', 'email' => $email, 'password' => $senha]);
    ok('12. login continua exigindo CSRF (419)', $r['status'] === 419);
    $r = http($nav, 'POST', '/login', ['_csrf' => token($g['html']), 'email' => $email, 'password' => 'senha-errada']);
    ok('13. senha errada continua falhando', vaiPara($r, '/login') && vaiPara(http($nav, 'GET', '/produtos'), '/login'));
    $r = login($nav, $email, $senha);
    ok('13. novo login depois do logout funciona', vaiPara($r, '/produtos') && http($nav, 'GET', '/produtos')['status'] === 200);
    $f = http($nav, 'GET', '/configuracoes/senha');
    $r = http($nav, 'POST', '/configuracoes/senha', ['_csrf' => 'invalido', 'senha_atual' => $senha, 'nova_senha' => $senhaNova, 'confirmar_senha' => $senhaNova]);
    ok('12. troca de senha continua exigindo CSRF', $r['status'] === 419 && Usuario::verificarSenha($senha, (string) Usuario::find($u)['senha_hash']));
    $r = http($nav, 'POST', '/configuracoes/senha', ['_csrf' => token($f['html']), 'senha_atual' => $senha, 'nova_senha' => $senhaNova, 'confirmar_senha' => $senhaNova]);
    ok('13. troca da própria senha continua deslogando e indo para /login', vaiPara($r, '/login') && vaiPara(http($nav, 'GET', '/produtos'), '/login')
        && Usuario::verificarSenha($senhaNova, (string) Usuario::find($u)['senha_hash']));
    ok('13. login com a senha nova funciona', vaiPara(login($nav, $email, $senhaNova), '/produtos'));
    $nav2 = [];
    login($nav2, $email, $senhaNova);
    $p1 = http($nav, 'GET', '/produtos');
    http($nav, 'POST', '/logout', ['_csrf' => token($p1['html'])]);
    ok('13. logout num navegador não derruba a sessão de outro navegador', vaiPara(http($nav, 'GET', '/produtos'), '/login') && http($nav2, 'GET', '/produtos')['status'] === 200);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo->exec("DELETE FROM usuarios WHERE email LIKE 'tst-lgo-%'");
    $pdo->prepare("DELETE FROM login_tentativas WHERE (tipo = 'email' AND chave LIKE 'tst-lgo-%') OR (tipo = 'ip' AND chave = :ip)")
        ->execute(['ip' => $ipTeste]);
    if (is_dir($tmpDir)) {
        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($tmpDir);
    }
}

ok('usuários temporários e sessões removidos', (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-lgo-%'")->fetchColumn() === 0 && !is_dir($tmpDir));
ok('usuários permanentes intactos', (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn() === $usuariosAntes);
$sigDepois = assinaturaBanco();
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('banco idêntico ao inicial (dados permanentes e estrutura)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

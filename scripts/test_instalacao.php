<?php
/**
 * Testes locais — instalação inicial (/instalacao): chave de instalação e criação única do primeiro admin.
 * Requisições HTTP reais via php-cgi (cookies, token CSRF lido do HTML) e processos concorrentes.
 * Roda num banco temporário (pm_tmp_inst_*, só com a tabela usuarios copiada do banco local), apagado ao final;
 * install_key de teste é aleatória por execução. O banco local real só é lido (assinatura antes/depois).
 * NÃO executar em produção. Uso: php scripts/test_instalacao.php
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
$db = config('db');
$sufixo = date('His') . random_int(100, 999);
$bancoTeste = 'pm_tmp_inst_' . $sufixo;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_inst_' . $sufixo;
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$worker = $tmpDir . DIRECTORY_SEPARATOR . 'worker.php';
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php-cgi.exe' : 'php-cgi');
$chave = bin2hex(random_bytes(32));
$chaveAtual = $chave;
$respostas = [];

function assinaturaBanco(PDO $pdo): string
{
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

function ambiente(string $method, string $uri, string $body, string $cookie): array
{
    global $runner, $bancoTeste, $chaveAtual;
    return array_merge(getenv(), [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $runner,
        'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'QUERY_STRING' => '',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => (string) strlen($body),
        'HTTP_COOKIE' => $cookie, 'REMOTE_ADDR' => '192.0.2.10',
        'PM_ROOT' => dirname(__DIR__), 'PM_TEST_DB' => $bancoTeste, 'PM_TEST_INSTALL_KEY' => $chaveAtual,
    ]);
}

function abrirCgi(string $method, string $uri, array $post, array $jar): array
{
    global $cgi, $tmpDir;
    $body = $method === 'POST' ? http_build_query($post) : '';
    $cookie = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    $cmd = escapeshellarg($cgi) . ' -d session.save_path=' . escapeshellarg($tmpDir) . ' -d display_errors=1 -d error_reporting=32767';
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ambiente($method, $uri, $body, $cookie));
    return ['proc' => $p, 'pipes' => $pipes, 'body' => $body];
}

function concluirCgi(array $h, array &$jar): array
{
    global $respostas;
    fwrite($h['pipes'][0], $h['body']);
    fclose($h['pipes'][0]);
    $out = (string) stream_get_contents($h['pipes'][1]);
    $err = (string) stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);

    $sep = strpos($out, "\r\n\r\n");
    $head = $sep === false ? $out : substr($out, 0, $sep);
    $html = $sep === false ? '' : substr($out, $sep + 4);
    $status = 200;
    $location = null;
    foreach (preg_split('/\r\n/', $head) as $linha) {
        if (preg_match('/^Status:\s*(\d{3})/i', $linha, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('/^Location:\s*(.+)$/i', $linha, $m)) {
            $location = trim($m[1]);
        } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $linha, $m)) {
            $jar[$m[1]] = $m[2];
        }
    }
    $respostas[] = $out . $err;
    return ['status' => $status, 'location' => $location, 'html' => $html, 'stderr' => $err];
}

function http(array &$jar, string $method, string $uri, array $post = []): array
{
    return concluirCgi(abrirCgi($method, $uri, $post, $jar), $jar);
}

function token(string $html): string
{
    return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
}

function vaiPara(array $r, string $path): bool
{
    return in_array($r['status'], [302, 303], true) && $r['location'] !== null && str_ends_with((string) parse_url($r['location'], PHP_URL_PATH), $path);
}

function semErroPhp(array $r): bool
{
    return $r['status'] < 500 && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/i', $r['html'] . $r['stderr']);
}

/** Navegador novo: GET (cookie + token) e POST de instalação. */
function instalar(array $campos, ?array &$jar = null, string $paginaToken = '/instalacao'): array
{
    $jar = [];
    $g = http($jar, 'GET', $paginaToken);
    return http($jar, 'POST', '/instalacao', ['_csrf' => token($g['html'])] + $campos);
}

function campos(string $email, string $senha, ?string $chaveInformada): array
{
    $c = ['nome' => 'Admin Teste', 'email' => $email, 'senha' => $senha, 'confirmar_senha' => $senha];
    if ($chaveInformada !== null) {
        $c['chave_instalacao'] = $chaveInformada;
    }
    return $c;
}

function abrirWorker(string $email): array
{
    global $worker, $tmpDir, $bancoTeste;
    $env = array_merge(getenv(), ['PM_ROOT' => dirname(__DIR__), 'PM_TEST_DB' => $bancoTeste, 'PM_SESS_DIR' => $tmpDir]);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($email);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    return ['proc' => $p, 'pipes' => $pipes];
}

function concluirWorker(array $h): string
{
    $out = (string) stream_get_contents($h['pipes'][1]) . (string) stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);
    return trim($out);
}

$sigAntes = assinaturaBanco($pdo);
echo "Assinatura do banco local antes: {$sigAntes}" . PHP_EOL;

try {
    if (!is_file($cgi)) {
        throw new RuntimeException('php-cgi não encontrado em ' . $cgi);
    }
    @mkdir($tmpDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
require getenv('PM_ROOT') . '/app/bootstrap.php';
$GLOBALS['config']['db']['name'] = (string) getenv('PM_TEST_DB');
$k = getenv('PM_TEST_INSTALL_KEY');
if ($k === false || $k === '__ausente__') {
    unset($GLOBALS['config']['install_key']);
} else {
    $GLOBALS['config']['install_key'] = $k;
}
require getenv('PM_ROOT') . '/app/routes.php';
PHP);
    file_put_contents($worker, <<<'PHP'
<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
ini_set('session.save_path', (string) getenv('PM_SESS_DIR'));
require getenv('PM_ROOT') . '/app/bootstrap.php';
$GLOBALS['config']['db']['name'] = (string) getenv('PM_TEST_DB');
echo Instalacao::criarPrimeiroAdmin(['nome' => 'Worker', 'email' => $argv[1], 'senha_hash' => Usuario::hashSenha('senha-worker-123')]);
PHP);

    $pdo->exec("CREATE DATABASE `{$bancoTeste}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE `{$bancoTeste}`.usuarios LIKE `{$db['name']}`.usuarios");
    $tpdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? '3306', $bancoTeste), $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $contar = static fn (): int => (int) $tpdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $limpar = static function () use ($tpdo): void {
        $tpdo->exec('DELETE FROM usuarios');
    };
    $hashQualquer = password_hash('senha-existente-1', PASSWORD_DEFAULT);
    $senha = 'senha-admin-' . $sufixo;

    // 1. Bloqueada quando já existe usuário (comum ativo, só inativo, admin)
    $casos = ['usuário comum ativo' => ['usuario', 'ativo'], 'somente usuário inativo' => ['usuario', 'inativo'], 'admin existente' => ['admin', 'ativo']];
    foreach ($casos as $rotulo => [$perfil, $status]) {
        $limpar();
        $tpdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, status, perfil) VALUES (?, ?, ?, ?, ?)')
            ->execute(['Existente', 'existente@teste.local', $hashQualquer, $status, $perfil]);
        $jar = [];
        $r = http($jar, 'GET', '/instalacao');
        ok("1. {$rotulo}: GET /instalacao redireciona para /login", vaiPara($r, '/login') && semErroPhp($r), 'status=' . $r['status']);
        $r = instalar(campos('novo-admin@teste.local', $senha, $chave), $jar, '/login');
        ok("1. {$rotulo}: POST com CSRF e chave correta redireciona para /login sem criar ninguém",
            vaiPara($r, '/login') && semErroPhp($r) && $contar() === 1
            && (int) $tpdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'novo-admin@teste.local'")->fetchColumn() === 0);
    }
    $chaveAtual = '__ausente__';
    $jar = [];
    ok('1. com usuário e sem chave configurada: GET continua indo para /login', vaiPara(http($jar, 'GET', '/instalacao'), '/login'));
    $chaveAtual = $chave;
    $limpar();

    // 3. Recusada sem chave configurada (ausente, vazia, curta)
    foreach (['ausente' => '__ausente__', 'vazia' => '', 'curta (31)' => substr($chave, 0, 31)] as $rotulo => $config) {
        $chaveAtual = $config;
        $jar = [];
        $g = http($jar, 'GET', '/instalacao');
        ok("3. chave {$rotulo} no config: tela mostra instalação bloqueada, sem formulário",
            $g['status'] === 200 && str_contains($g['html'], 'Instalação bloqueada') && !str_contains($g['html'], '<form') && semErroPhp($g));
        ok("3. chave {$rotulo} no config: página bloqueada nem gera token CSRF (POST seria 419)", token($g['html']) === '');
        // Token obtido enquanto a chave estava configurada; a recusa precisa vir da rota, não só do CSRF.
        $chaveAtual = $chave;
        $tok = token(http($jar, 'GET', '/instalacao')['html']);
        $chaveAtual = $config;
        $informada = $config === '__ausente__' ? '' : $config;
        $r = http($jar, 'POST', '/instalacao', ['_csrf' => $tok] + campos('a@teste.local', $senha, $informada));
        ok("3. chave {$rotulo} no config: POST (informando o mesmo valor) não cria usuário",
            vaiPara($r, '/instalacao') && semErroPhp($r) && $contar() === 0, 'status=' . $r['status']);
    }
    $chaveAtual = $chave;

    // 2. Autorizada com banco vazio e chave configurada
    $jar = [];
    $g = http($jar, 'GET', '/instalacao');
    ok('2. banco vazio + chave configurada: formulário aparece com campo da chave e CSRF',
        $g['status'] === 200 && str_contains($g['html'], 'name="chave_instalacao"') && token($g['html']) !== '' && !str_contains($g['html'], 'Instalação bloqueada'));
    ok('2. a chave não aparece na página', !str_contains($g['html'], $chave));
    $jar = [];
    $r = http($jar, 'GET', '/login');
    ok('2. /login com banco vazio continua levando para /instalacao', vaiPara($r, '/instalacao'));

    // 3. Recusada com chave errada
    $erradas = ['ausente no POST' => null, 'vazia' => '', 'errada' => bin2hex(random_bytes(32)), 'prefixo' => substr($chave, 0, 63), 'maiúsculas' => strtoupper($chave)];
    foreach ($erradas as $rotulo => $informada) {
        $r = instalar(campos('admin@teste.local', $senha, $informada), $jar);
        ok("3. chave {$rotulo}: recusada sem criar usuário", vaiPara($r, '/instalacao') && semErroPhp($r) && $contar() === 0);
    }
    $g = http($jar, 'GET', '/instalacao');
    ok('3. mensagem genérica de chave inválida, sem ecoar a chave', str_contains($g['html'], 'Chave de instalação inválida.') && !str_contains($g['html'], strtoupper($chave)));

    // 6. CSRF obrigatório (banco vazio, chave correta)
    $jar = [];
    $g = http($jar, 'GET', '/instalacao');
    $r = http($jar, 'POST', '/instalacao', campos('admin@teste.local', $senha, $chave));
    ok('6. POST sem CSRF retorna 419 e não cria usuário', $r['status'] === 419 && $contar() === 0);
    $r = http($jar, 'POST', '/instalacao', ['_csrf' => str_repeat('a', 64)] + campos('admin@teste.local', $senha, $chave));
    ok('6. POST com CSRF inválido retorna 419', $r['status'] === 419 && $contar() === 0);
    $outro = [];
    $tokOutro = token(http($outro, 'GET', '/instalacao')['html']);
    $r = http($jar, 'POST', '/instalacao', ['_csrf' => $tokOutro] + campos('admin@teste.local', $senha, $chave));
    ok('6. POST com token de outra sessão retorna 419', $r['status'] === 419 && $tokOutro !== '' && $tokOutro !== token($g['html']) && $contar() === 0);

    // Validação continua valendo com a chave correta
    $r = instalar(campos('admin@teste.local', 'curta', $chave), $jar);
    ok('validação: senha curta com chave correta não cria usuário', vaiPara($r, '/instalacao') && $contar() === 0
        && str_contains(http($jar, 'GET', '/instalacao')['html'], 'A senha deve ter no mínimo 8 caracteres.'));

    // 4, 7, 8, 9. Criação do primeiro administrador
    $r = instalar(campos('Admin-' . $sufixo . '@Teste.Local', $senha, $chave) + ['perfil' => 'usuario', 'status' => 'inativo'], $jar);
    ok('4. chave correta: cria o administrador e redireciona para /login', vaiPara($r, '/login') && semErroPhp($r) && $contar() === 1);
    $l = http($jar, 'GET', '/login');
    ok('4. /login mostra a mensagem de sucesso', str_contains($l['html'], 'Administrador criado com sucesso'));
    $u = $tpdo->query('SELECT * FROM usuarios')->fetch() ?: [];
    ok('4. nome e e-mail gravados (e-mail em minúsculas)', ($u['nome'] ?? '') === 'Admin Teste' && ($u['email'] ?? '') === strtolower('Admin-' . $sufixo . '@Teste.Local'));
    $info = password_get_info((string) ($u['senha_hash'] ?? ''));
    ok('7. senha gravada com password_hash (algoritmo reconhecido, verifica, sem texto puro)',
        $info['algo'] !== null && $info['algoName'] !== 'unknown' && password_verify($senha, (string) $u['senha_hash'])
        && !password_needs_rehash((string) $u['senha_hash'], PASSWORD_DEFAULT) && !str_contains((string) $u['senha_hash'], $senha), (string) $info['algoName']);
    ok('8. perfil criado é admin (campo perfil enviado no POST é ignorado)', ($u['perfil'] ?? '') === 'admin');
    ok('9. status criado é ativo (campo status enviado no POST é ignorado)', ($u['status'] ?? '') === 'ativo');

    // 5. Segunda criação (sequencial)
    $r = instalar(campos('segundo@teste.local', $senha, $chave), $jar, '/login');
    ok('5. segunda instalação depois de criado: redireciona para /login, sem 500, sem novo usuário', vaiPara($r, '/login') && semErroPhp($r) && $contar() === 1);
    ok('5. GET /instalacao depois de instalado vai para /login', vaiPara(http($jar, 'GET', '/instalacao'), '/login'));

    // 5. Concorrência real: vários POSTs simultâneos (e-mails diferentes e repetidos)
    $limpar();
    $preparados = [];
    for ($i = 0; $i < 6; $i++) {
        $j = [];
        $tok = token(http($j, 'GET', '/instalacao')['html']);
        $email = $i < 3 ? "conc-{$i}@teste.local" : 'conc-mesmo@teste.local';
        $preparados[] = ['jar' => $j, 'post' => ['_csrf' => $tok] + campos($email, $senha, $chave)];
    }
    $abertos = array_map(static fn ($p) => abrirCgi('POST', '/instalacao', $p['post'], $p['jar']), $preparados);
    $resultados = [];
    foreach ($abertos as $i => $h) {
        $resultados[] = concluirCgi($h, $preparados[$i]['jar']);
    }
    $todosOk = array_reduce($resultados, static fn ($c, $r) => $c && vaiPara($r, '/login') && semErroPhp($r), true);
    ok('5. 6 POSTs simultâneos: todos terminam em /login sem erro 500', $todosOk, implode(',', array_map(static fn ($r) => $r['status'], $resultados)));
    ok('5. 6 POSTs simultâneos: exatamente um administrador criado', $contar() === 1 && (int) $tpdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn() === 1);

    // 5. Serialização pelo lock: com o lock ocupado ninguém grava; ao liberar, só o primeiro cria
    $limpar();
    $lock = static fn (string $f): int => (int) $tpdo->query("SELECT {$f}(CONCAT('pm_instalacao_', SHA1(DATABASE()))" . ($f === 'GET_LOCK' ? ', 0' : '') . ')')->fetchColumn();
    ok('lock do teste obtido', $lock('GET_LOCK') === 1);
    $w1 = abrirWorker('worker-1@teste.local');
    $w2 = abrirWorker('worker-2@teste.local');
    sleep(2);
    $esperando = proc_get_status($w1['proc'])['running'] && proc_get_status($w2['proc'])['running'];
    ok('5. com o lock ocupado os dois pedidos esperam e nada é gravado', $esperando && $contar() === 0);
    $lock('RELEASE_LOCK');
    $res = [concluirWorker($w1), concluirWorker($w2)];
    sort($res);
    ok('5. ao liberar: um cria e o outro encontra a instalação feita', $res === [Instalacao::CRIADO, Instalacao::JA_INSTALADO], implode(',', $res));
    ok('5. continua havendo um único usuário', $contar() === 1);

    // 5. Lock não obtido a tempo: mensagem amigável, sem 500 e sem gravar
    $limpar();
    $lock('GET_LOCK');
    $inicio = microtime(true);
    $r = instalar(campos('ocupado@teste.local', $senha, $chave), $jar);
    $lock('RELEASE_LOCK');
    ok('5. lock ocupado além do limite: volta para /instalacao sem 500 e sem criar usuário',
        vaiPara($r, '/instalacao') && semErroPhp($r) && $contar() === 0, sprintf('%.1fs', microtime(true) - $inicio));
    ok('5. mensagem de instalação em andamento', str_contains(http($jar, 'GET', '/instalacao')['html'], 'Outra instalação está em andamento'));

    // Sigilo da chave
    $vazou = false;
    foreach ($respostas as $resp) {
        if (str_contains($resp, $chave)) {
            $vazou = true;
        }
    }
    $sessoes = '';
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $f) {
        $sessoes .= (string) file_get_contents($f);
    }
    ok('a chave nunca aparece em respostas, cabeçalhos, erros ou arquivos de sessão', !$vazou && !str_contains($sessoes, $chave), count($respostas) . ' respostas');
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $tpdo = null;
    if (str_starts_with($bancoTeste, 'pm_tmp_inst_')) {
        $pdo->exec("DROP DATABASE IF EXISTS `{$bancoTeste}`");
    }
    if (is_dir($tmpDir)) {
        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($tmpDir);
    }
}

$restou = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($bancoTeste))->fetchColumn();
ok('banco temporário e arquivos temporários removidos', $restou === 0 && !is_dir($tmpDir));
$sigDepois = assinaturaBanco($pdo);
echo "Assinatura do banco local depois: {$sigDepois}" . PHP_EOL;
ok('banco local idêntico ao inicial', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

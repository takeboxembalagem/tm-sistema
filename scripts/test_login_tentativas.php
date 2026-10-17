<?php
/**
 * Testes locais — limite de tentativas de login (P1-9).
 * Requisições HTTP reais via php-cgi sobre public/index.php (REMOTE_ADDR controlado por requisição,
 * cookie jar, token CSRF lido do HTML). Concorrência com processos php-cgi disparados juntos.
 * O "passar do tempo" é simulado recuando janela_inicio/bloqueado_ate das linhas de teste.
 * Usuários tst-lt-* e linhas de login_tentativas dos e-mails/IPs de teste são removidos ao final.
 * NÃO executar em produção. Requer database/alter_login_tentativas.sql aplicado no banco local.
 * Uso: php scripts/test_login_tentativas.php
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

const MSG_INCORRETO = 'E-mail ou senha incorretos.';
const MSG_BLOQUEIO = 'Muitas tentativas de login. Tente novamente mais tarde.';

$pdo = Database::pdo();
$sufixo = date('His') . random_int(100, 999);
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_lt_' . $sufixo;
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php-cgi.exe' : 'php-cgi');
$cookieName = (string) (config('session')['name'] ?? 'pm_sess');
$ipsUsados = [];

function novoIp(): string
{
    global $ipsUsados;
    do {
        $ip = '198.51.100.' . random_int(1, 254);
    } while (in_array($ip, $ipsUsados, true));
    $ipsUsados[] = $ip;
    return $ip;
}

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

/** Dispara o php-cgi sem esperar; o corpo é enviado em enviar(). */
function iniciar(array $jar, string $method, string $uri, array $post, string $ip): array
{
    global $cgi, $tmpDir;
    $body = $method === 'POST' ? http_build_query($post) : '';
    $cookie = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    $env = array_merge(getenv(), [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'REDIRECT_STATUS' => '200', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php',
        'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'QUERY_STRING' => '',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => (string) strlen($body),
        'HTTP_COOKIE' => $cookie, 'REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => '203.0.113.250',
    ]);
    $cmd = escapeshellarg($cgi) . ' -d session.save_path=' . escapeshellarg($tmpDir) . ' -d display_errors=1 -d error_reporting=32767';
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    return ['p' => $p, 'pipes' => $pipes, 'body' => $body];
}

function enviar(array $h): void
{
    fwrite($h['pipes'][0], $h['body']);
    fclose($h['pipes'][0]);
}

function coletar(array $h, array &$jar): array
{
    $out = (string) stream_get_contents($h['pipes'][1]);
    $err = (string) stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['p']);

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
        } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)(.*)$/i', $linha, $m)) {
            if ($m[2] === '' || $m[2] === 'deleted' || preg_match('/Max-Age=0/i', $m[3])) {
                unset($jar[$m[1]]);
            } else {
                $jar[$m[1]] = $m[2];
            }
        }
    }
    return ['status' => $status, 'location' => $location, 'html' => $html, 'stderr' => $err];
}

function http(array &$jar, string $method, string $uri, array $post, string $ip): array
{
    $h = iniciar($jar, $method, $uri, $post, $ip);
    enviar($h);
    return coletar($h, $jar);
}

function token(string $html): string
{
    return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
}

function semErroPhp(array $r): bool
{
    return !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/i', $r['html'] . $r['stderr']);
}

function vaiPara(array $r, string $path): bool
{
    return in_array($r['status'], [302, 303], true) && $r['location'] !== null && str_ends_with((string) parse_url($r['location'], PHP_URL_PATH), $path);
}

/** Navegador: jar + token + IP. */
function navegador(string $ip): array
{
    $nav = ['jar' => [], 'ip' => $ip, 'token' => ''];
    $g = http($nav['jar'], 'GET', '/login', [], $ip);
    $nav['token'] = token($g['html']);
    return $nav;
}

/** Mensagem exibida na tela de login (consome o flash). */
function mensagem(array &$nav): string
{
    $g = http($nav['jar'], 'GET', '/login', [], $nav['ip']);
    if (str_contains($g['html'], MSG_BLOQUEIO)) {
        return 'bloqueio';
    }
    if (str_contains($g['html'], MSG_INCORRETO)) {
        return 'incorreto';
    }
    return vaiPara($g, '/produtos') ? 'logado' : 'outra';
}

/** POST /login real; devolve 'logado' | 'incorreto' | 'bloqueio' | 'outra'. */
function tentar(array &$nav, string $email, string $senha): string
{
    $r = http($nav['jar'], 'POST', '/login', ['_csrf' => $nav['token'], 'email' => $email, 'password' => $senha], $nav['ip']);
    if (!semErroPhp($r)) {
        return 'erro-php';
    }
    if (vaiPara($r, '/produtos')) {
        return 'logado';
    }
    return vaiPara($r, '/login') ? mensagem($nav) : 'outra(' . $r['status'] . ')';
}

function linha(string $tipo, string $chave): ?array
{
    $st = Database::pdo()->prepare(
        'SELECT tentativas, janela_inicio, bloqueado_ate,
                TIMESTAMPDIFF(SECOND, NOW(), bloqueado_ate) AS segundos_bloqueio
         FROM login_tentativas WHERE tipo = :t AND chave = :c'
    );
    $st->execute(['t' => $tipo, 'c' => $chave]);
    return $st->fetch() ?: null;
}

function tentativas(string $tipo, string $chave): int
{
    return (int) (linha($tipo, $chave)['tentativas'] ?? 0);
}

function recuarMinutos(string $tipo, string $chave, int $min): void
{
    Database::pdo()->prepare(
        "UPDATE login_tentativas
         SET janela_inicio = janela_inicio - INTERVAL {$min} MINUTE,
             bloqueado_ate = bloqueado_ate - INTERVAL {$min} MINUTE
         WHERE tipo = :t AND chave = :c"
    )->execute(['t' => $tipo, 'c' => $chave]);
}

/** Dispara N POST /login juntos (cada um com seu navegador) e devolve as mensagens. */
function simultaneos(array &$navs, array $emails, string $senha): array
{
    $hs = [];
    foreach ($navs as $i => $nav) {
        $hs[$i] = iniciar($nav['jar'], 'POST', '/login', ['_csrf' => $nav['token'], 'email' => $emails[$i], 'password' => $senha], $nav['ip']);
    }
    foreach ($hs as $h) {
        enviar($h);
    }
    $res = [];
    foreach ($hs as $i => $h) {
        $r = coletar($h, $navs[$i]['jar']);
        $res[$i] = !semErroPhp($r) ? 'erro-php' : (vaiPara($r, '/login') ? mensagem($navs[$i]) : 'outra(' . $r['status'] . ')');
    }
    return $res;
}

$sigAntes = assinaturaBanco();
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;
$sqlUsuariosPermanentes = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', id, nome, email, senha_hash, status, perfil, created_at, updated_at) ORDER BY id), '')) FROM usuarios WHERE email NOT LIKE 'tst-lt-%'";
$usuariosAntes = (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn();
$tentativasAntes = (int) $pdo->query("SELECT COUNT(*) FROM login_tentativas")->fetchColumn();

try {
    if (!is_file($cgi)) {
        throw new RuntimeException('php-cgi não encontrado em ' . $cgi);
    }
    @mkdir($tmpDir, 0700, true);

    $senha = 'senha-lt-' . $sufixo;
    $errada = 'errada-lt-' . $sufixo;
    $em = static fn (string $n): string => 'tst-lt-' . $sufixo . '-' . $n . '@teste.local';
    $hash = Usuario::hashSenha($senha);
    $criar = static fn (string $n, string $status = 'ativo') => Usuario::create(['nome' => 'TST LT ' . strtoupper($n), 'email' => $em($n), 'senha_hash' => $hash, 'status' => $status, 'perfil' => 'usuario']);
    foreach (['u', 'v', 'w', 'c', 'd', 'e'] as $n) {
        $criar($n);
    }
    $criar('inativo', 'inativo');
    $criar('inativo2', 'inativo');

    // 1, 22, 23, 24, 25. Login correto e fluxos existentes
    $ip1 = novoIp();
    $nav = navegador($ip1);
    ok('1. login correto funciona normalmente', tentar($nav, $em('u'), $senha) === 'logado' && $nav['token'] !== '');
    $p = http($nav['jar'], 'GET', '/produtos', [], $ip1);
    ok('22. sessão normal continua funcionando', $p['status'] === 200 && str_contains($p['html'], 'TST LT U'));
    clearstatcache();
    $arq = $tmpDir . DIRECTORY_SEPARATOR . 'sess_' . ($nav['jar'][$cookieName] ?? '');
    ok('23. impressão digital da senha continua sendo criada', is_file($arq) && preg_match('/' . preg_quote(Auth::CHAVE_IMPRESSAO, '/') . '\|s:64:"[0-9a-f]{64}"/', (string) file_get_contents($arq)) === 1);
    $r = http($nav['jar'], 'GET', '/configuracoes/usuarios/novo', [], $ip1);
    ok('25. autorização continua funcionando (usuário comum barrado na área de admin)', vaiPara($r, '/configuracoes'));
    $r = http($nav['jar'], 'POST', '/logout', ['_csrf' => token($p['html'])], $ip1);
    ok('24. logout continua funcionando', vaiPara($r, '/login') && vaiPara(http($nav['jar'], 'GET', '/produtos', [], $ip1), '/login'));
    ok('1. login correto não deixa falha registrada para a conta', linha('email', $em('u')) === null && tentativas('ip', $ip1) === 0);

    // 2-5, 14, 18. Limite por e-mail
    $nav = navegador($ip1);
    ok('2. senha errada: mensagem genérica', tentar($nav, $em('u'), $errada) === 'incorreto');
    ok('2. senha errada registra falha (conta e IP)', tentativas('email', $em('u')) === 1 && tentativas('ip', $ip1) === 1);
    $res = [];
    for ($i = 2; $i <= 4; $i++) {
        $res[] = tentar($nav, $em('u'), $errada);
    }
    $l = linha('email', $em('u'));
    ok('3. 2ª a 4ª falha continuam permitindo tentar (mesma mensagem, sem bloqueio)', $res === ['incorreto', 'incorreto', 'incorreto'] && (int) $l['tentativas'] === 4 && $l['bloqueado_ate'] === null);
    $r5 = tentar($nav, $em('u'), $errada);
    $l = linha('email', $em('u'));
    ok('4. 5ª falha atinge o limite (resposta ainda genérica, bloqueio gravado por 15 min)', $r5 === 'incorreto' && (int) $l['tentativas'] === 5
        && (int) $l['segundos_bloqueio'] > 14 * 60 && (int) $l['segundos_bloqueio'] <= 15 * 60, 'segundos=' . ($l['segundos_bloqueio'] ?? '-'));
    ok('5. tentativa seguinte bloqueada, mesmo com a senha correta', tentar($nav, $em('u'), $senha) === 'bloqueio');
    ok('5. tentativa bloqueada não é contada', tentativas('email', $em('u')) === 5 && tentativas('ip', $ip1) === 5);
    ok('14. limite por e-mail ignora maiúsculas/espaços como o login', tentar($nav, '  ' . strtoupper($em('u')) . ' ', $senha) === 'bloqueio');
    ok('18. outro e-mail no mesmo IP não herda o bloqueio da conta', tentar($nav, $em('v'), $errada) === 'incorreto' && tentar($nav, $em('v'), $senha) === 'logado');
    $outroIp = navegador(novoIp());
    ok('14. a mesma conta continua bloqueada vindo de outro IP', tentar($outroIp, $em('u'), $senha) === 'bloqueio');

    // 6-8, 19. Duração, expiração e zeragem
    recuarMinutos('email', $em('u'), 14);
    $nav = navegador($ip1);
    ok('6. após 14 minutos o bloqueio continua', tentar($nav, $em('u'), $senha) === 'bloqueio');
    recuarMinutos('email', $em('u'), 2);
    ok('7. depois de 15 minutos pode tentar de novo', tentar($nav, $em('u'), $senha) === 'logado');
    ok('19. login correto depois do bloqueio limpa a conta', linha('email', $em('u')) === null);
    $nav = navegador($ip1);
    for ($i = 1; $i <= 4; $i++) {
        tentar($nav, $em('u'), $errada);
    }
    $antesOk = tentativas('email', $em('u'));
    $okDepois = tentar($nav, $em('u'), $senha);
    $nav = navegador($ip1);
    ok('8/19. login correto após 4 falhas zera o contador da conta', $antesOk === 4 && $okDepois === 'logado' && linha('email', $em('u')) === null
        && tentar($nav, $em('u'), $errada) === 'incorreto' && tentativas('email', $em('u')) === 1);
    ok('8. o contador do IP não é zerado pelo sucesso de uma conta', tentativas('ip', $ip1) >= 10);

    // 9. password_verify também para e-mail inexistente
    $ref = new ReflectionClassConstant(Auth::class, 'HASH_FICTICIO');
    $ficticio = (string) $ref->getValue();
    $infoF = password_get_info($ficticio);
    $infoR = password_get_info(Usuario::hashSenha('x'));
    ok('9. hash fictício: mesmo algoritmo e custo das senhas reais, sem senha conhecida', $infoF['algo'] === $infoR['algo'] && $infoF['options'] === $infoR['options']
        && !password_verify('', $ficticio) && !password_verify($senha, $ficticio) && $ref->isPrivate());
    $codigo = (string) file_get_contents(dirname(__DIR__) . '/app/core/Auth.php');
    ok('9. hash fictício não é gerado por requisição', substr_count($codigo, 'password_hash(') === 0);
    $ipT = novoIp();
    $medir = static function (string $email) use ($errada, $ipT): float {
        $t = hrtime(true);
        $r = Auth::tentarLogin($email, $errada, $ipT);
        $ms = (hrtime(true) - $t) / 1e6;
        return $r === Auth::LOGIN_FALHA ? $ms : -1.0;
    };
    $tInex = $tExist = $tInat = [];
    for ($i = 1; $i <= 4; $i++) {
        $tExist[] = $medir($em('w'));
        $tInex[] = $medir($em('nao-existe-' . $i));
        $tInat[] = $medir($em('inativo2'));
    }
    $med = static function (array $v): float {
        sort($v);
        return ($v[1] + $v[2]) / 2;
    };
    $t0 = hrtime(true);
    password_verify($errada, $ficticio);
    $tVerify = (hrtime(true) - $t0) / 1e6;
    $mI = $med($tInex);
    $mE = $med($tExist);
    $mN = $med($tInat);
    ok('9. e-mail inexistente também paga o password_verify (sem a diferença óbvia de tempo)', min(array_merge($tInex, $tExist, $tInat)) > 0
        && $mI >= 0.6 * $mE && $mI >= 0.6 * $tVerify && $mN >= 0.6 * $mE,
        sprintf('mediana ms: inexistente=%.1f existente=%.1f inativo=%.1f; password_verify=%.1f', $mI, $mE, $mN, $tVerify));

    // 10, 11. Inexistente e inativo: mesma mensagem, mesma contagem, mesmo bloqueio
    $ip2 = novoIp();
    $nav = navegador($ip2);
    $inex = $em('ninguem');
    $r = [tentar($nav, $inex, $errada), tentar($nav, $em('inativo'), $senha), tentar($nav, $em('inativo'), $errada), tentar($nav, $em('d'), $errada)];
    ok('10/11. inexistente, inativo (senha certa ou errada) e senha errada: mesma mensagem', $r === ['incorreto', 'incorreto', 'incorreto', 'incorreto']);
    ok('10/11. todos são contados da mesma forma', tentativas('email', $inex) === 1 && tentativas('email', $em('inativo')) === 2 && tentativas('email', $em('d')) === 1);
    for ($i = 0; $i < 4; $i++) {
        tentar($nav, $inex, $errada);
    }
    for ($i = 0; $i < 3; $i++) {
        tentar($nav, $em('inativo'), $senha);
    }
    ok('11. inativo e inexistente bloqueiam igual, sem revelar a conta', tentar($nav, $inex, $errada) === 'bloqueio' && tentar($nav, $em('inativo'), $senha) === 'bloqueio');
    $g = http($nav['jar'], 'GET', '/login', [], $ip2);
    ok('10. tela de login não indica tentativas restantes nem existência da conta', !preg_match('/restam|restante|não existe|nao existe|inativ|desativad/iu', $g['html']));

    // 12, 13. CSRF
    $ip3 = novoIp();
    $nav = navegador($ip3);
    tentar($nav, $em('d'), $errada);
    $antesE = linha('email', $em('d'));
    $antesI = linha('ip', $ip3);
    $r = http($nav['jar'], 'POST', '/login', ['_csrf' => 'invalido', 'email' => $em('d'), 'password' => $errada], $ip3);
    $r2 = http($nav['jar'], 'POST', '/login', ['email' => $em('d'), 'password' => $senha], $ip3);
    ok('12. CSRF inválido ou ausente no login retorna 419', $r['status'] === 419 && $r2['status'] === 419 && str_contains($r['html'], 'Sessão expirada ou token inválido'));
    ok('13. CSRF inválido não incrementa contador de conta nem de IP', linha('email', $em('d')) == $antesE && linha('ip', $ip3) == $antesI);

    // 15-17. Limite por IP
    $ipX = novoIp();
    $nav = navegador($ipX);
    $respostas = [];
    for ($i = 0; $i < 30; $i++) {
        $email = $em('ip-' . intdiv($i, 4));
        $r = http($nav['jar'], 'POST', '/login', ['_csrf' => $nav['token'], 'email' => $email, 'password' => $errada], $ipX);
        $respostas[] = vaiPara($r, '/login') && semErroPhp($r);
    }
    $lip = linha('ip', $ipX);
    ok('15/16. 30 falhas espalhadas em 8 contas pelo mesmo IP (nenhuma conta chega a 5)', !in_array(false, $respostas, true) && (int) $lip['tentativas'] === 30
        && $lip['bloqueado_ate'] !== null && tentativas('email', $em('ip-0')) === 4, 'ip=' . ($lip['tentativas'] ?? '-'));
    ok('16. IP bloqueado: conta nova com a senha correta também é barrada', tentar($nav, $em('v'), $senha) === 'bloqueio' && tentativas('ip', $ipX) === 30);
    ok('15. IP falso em X-Forwarded-For não contorna o bloqueio (só REMOTE_ADDR)', linha('ip', '203.0.113.250') === null);
    $ipY = novoIp();
    $navY = navegador($ipY);
    ok('17. outro IP não herda o bloqueio', tentar($navY, $em('v'), $senha) === 'logado');
    recuarMinutos('ip', $ipX, 16);
    $nav = navegador($ipX);
    ok('15. bloqueio do IP também expira após 15 minutos', tentar($nav, $em('v'), $senha) === 'logado');

    // 20. Concorrência
    $ipC = novoIp();
    $navs = [navegador($ipC)];
    for ($i = 1; $i <= 4; $i++) {
        tentar($navs[0], $em('c'), $errada);
    }
    $navs = [navegador($ipC), navegador($ipC)];
    $res = simultaneos($navs, [$em('c'), $em('c')], $errada);
    sort($res);
    ok('20. 2 falhas simultâneas com 4 registradas: uma conta (5ª), a outra já encontra o bloqueio', $res === ['bloqueio', 'incorreto'] && tentativas('email', $em('c')) === 5,
        implode(',', $res) . ' tentativas=' . tentativas('email', $em('c')));
    $ipC2 = novoIp();
    $nav = navegador($ipC2);
    for ($i = 1; $i <= 3; $i++) {
        tentar($nav, $em('e'), $errada);
    }
    $navs = [];
    for ($i = 0; $i < 6; $i++) {
        $navs[] = navegador($ipC2);
    }
    $res = simultaneos($navs, array_fill(0, 6, $em('e')), $errada);
    $cont = array_count_values($res);
    ok('20. 6 falhas simultâneas com 3 registradas: exatamente 2 verificadas, 4 bloqueadas, contador = 5', ($cont['incorreto'] ?? 0) === 2 && ($cont['bloqueio'] ?? 0) === 4
        && tentativas('email', $em('e')) === 5, json_encode($cont));
    $ipC3 = novoIp();
    $navs = [];
    $emails = [];
    for ($i = 0; $i < 6; $i++) {
        $navs[] = navegador($ipC3);
        $emails[] = $em('par-' . $i);
    }
    $res = simultaneos($navs, $emails, $errada);
    ok('20. 6 falhas simultâneas em contas novas pelo mesmo IP: nenhuma perdida, sem deadlock/erro', array_count_values($res) === ['incorreto' => 6] && tentativas('ip', $ipC3) === 6,
        json_encode(array_count_values($res)) . ' ip=' . tentativas('ip', $ipC3));

    // 21. Conteúdo da tabela
    $cols = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_tentativas'")->fetchColumn();
    $dump = json_encode($pdo->query('SELECT * FROM login_tentativas')->fetchAll());
    ok('21. tabela só tem as colunas previstas', $cols === 'id,tipo,chave,tentativas,janela_inicio,bloqueado_ate,created_at,updated_at', (string) $cols);
    ok('21. tabela não contém senhas, hashes, tokens nem sessão', !str_contains($dump, $senha) && !str_contains($dump, $errada) && !str_contains($dump, '$2y$')
        && !str_contains($dump, $nav['token']) && !str_contains($dump, (string) ($nav['jar'][$cookieName] ?? 'x-sem-cookie')));

    // Limpeza oportunística
    $pdo->prepare("INSERT INTO login_tentativas (tipo, chave, tentativas, janela_inicio, bloqueado_ate) VALUES
        ('email', :velho, 3, NOW() - INTERVAL 25 HOUR, NULL),
        ('email', :velhoBloq, 5, NOW() - INTERVAL 25 HOUR, NOW() + INTERVAL 5 MINUTE),
        ('email', :recente, 2, NOW() - INTERVAL 2 HOUR, NULL)")
        ->execute(['velho' => $em('limp-velho'), 'velhoBloq' => $em('limp-bloq'), 'recente' => $em('limp-recente')]);
    $nav = navegador(novoIp());
    tentar($nav, $em('d'), $errada);
    ok('limpeza: tentativa de login remove linha antiga (> 24 h)', linha('email', $em('limp-velho')) === null);
    ok('limpeza: não remove linha com bloqueio vigente nem linha recente', linha('email', $em('limp-bloq')) !== null && linha('email', $em('limp-recente')) !== null);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo->exec("DELETE FROM usuarios WHERE email LIKE 'tst-lt-%'");
    $pdo->exec("DELETE FROM login_tentativas WHERE tipo = 'email' AND chave LIKE 'tst-lt-%'");
    if ($ipsUsados) {
        $in = implode(',', array_fill(0, count($ipsUsados), '?'));
        $pdo->prepare("DELETE FROM login_tentativas WHERE tipo = 'ip' AND chave IN ({$in})")->execute($ipsUsados);
    }
    if (is_dir($tmpDir)) {
        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($tmpDir);
    }
}

ok('usuários temporários, tentativas de teste e sessões removidos', (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-lt-%'")->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM login_tentativas')->fetchColumn() === $tentativasAntes && !is_dir($tmpDir));
ok('usuários permanentes intactos', (string) $pdo->query($sqlUsuariosPermanentes)->fetchColumn() === $usuariosAntes);
$sigDepois = assinaturaBanco();
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('banco idêntico ao inicial (dados permanentes e estrutura)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Testes locais — timezone da sessão MySQL derivado de config['timezone'] (P1-7).
 * Outros fusos são simulados só em memória ($GLOBALS['config']); nenhum arquivo de configuração é alterado.
 * Somente leitura no banco. NÃO executar em produção. Uso: php scripts/test_timezone_sessao.php
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

/** Descarta a conexão atual para que a próxima chamada de Database::pdo() conecte de novo. */
function reconectar(): PDO
{
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    return Database::pdo();
}

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
    $s .= (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS(':', CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE) ORDER BY CONSTRAINT_NAME)
        FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn();
    return md5($s);
}

function sessao(PDO $pdo): array
{
    return $pdo->query('SELECT @@session.time_zone AS tz, TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS diff, NOW() AS agora')->fetch();
}

/** '-03:00' → '-03:00:00'; '+05:30' → '05:30:00' (formato do TIMEDIFF). */
function diffEsperado(string $offset): string
{
    return ($offset[0] === '-' ? '-' : '') . substr($offset, 1) . ':00';
}

$tzOriginal = $GLOBALS['config']['timezone'] ?? null;
$pdo = Database::pdo();
$sigAntes = assinaturaBanco($pdo);
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;

try {
    // 1-4: configuração atual
    ok('1. conexão normal funciona', (int) $pdo->query('SELECT 1')->fetchColumn() === 1);
    $esperado = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone'))))->format('P');
    $s = sessao($pdo);
    ok('2. @@session.time_zone = offset do config[timezone]', $s['tz'] === $esperado && $esperado === '-03:00', "config={$tzOriginal} sessão={$s['tz']}");
    ok('3. NOW() - UTC_TIMESTAMP() = -03:00', $s['diff'] === '-03:00:00', (string) $s['diff']);
    ok('3. NOW() do MySQL bate com o relógio do PHP', abs(strtotime((string) $s['agora']) - time()) <= 5, $s['agora'] . ' x ' . date('Y-m-d H:i:s'));
    ok('4. timezone PHP continua America/Sao_Paulo', date_default_timezone_get() === 'America/Sao_Paulo' && $tzOriginal === 'America/Sao_Paulo');

    // Referência de DATETIME existente: mudar o fuso da sessão não pode converter valores gravados
    $refDatetime = $pdo->query('SELECT MAX(created_at) FROM usuarios')->fetchColumn();

    // Outros fusos, só em memória
    foreach (['UTC' => '+00:00', 'Asia/Tokyo' => '+09:00', 'Asia/Kolkata' => '+05:30', 'America/Manaus' => '-04:00'] as $nome => $offset) {
        $GLOBALS['config']['timezone'] = $nome;
        $p = reconectar();
        $s = sessao($p);
        ok("outro fuso ({$nome}): sessão {$offset}", $s['tz'] === $offset && $s['diff'] === diffEsperado($offset), "sessão={$s['tz']} diff={$s['diff']}");
        ok("outro fuso ({$nome}): DATETIME existente não é convertido", $p->query('SELECT MAX(created_at) FROM usuarios')->fetchColumn() === $refDatetime);
        ok("outro fuso ({$nome}): timezone PHP não muda", date_default_timezone_get() === 'America/Sao_Paulo');
    }

    $GLOBALS['config']['timezone'] = 'Fuso/Inexistente';
    $s = sessao(reconectar());
    ok('timezone inválido na configuração: cai no fuso do PHP (-03:00)', $s['tz'] === '-03:00', (string) $s['tz']);

    unset($GLOBALS['config']['timezone']);
    $s = sessao(reconectar());
    ok('sem chave timezone: usa o fuso do PHP (-03:00)', $s['tz'] === '-03:00', (string) $s['tz']);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($tzOriginal === null) {
        unset($GLOBALS['config']['timezone']);
    } else {
        $GLOBALS['config']['timezone'] = $tzOriginal;
    }
    $pdo = reconectar();
}

ok('configuração restaurada: sessão de volta a -03:00', sessao($pdo)['tz'] === '-03:00');
$sigDepois = assinaturaBanco($pdo);
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('5/6. nenhuma tabela alterada: assinatura do banco idêntica', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

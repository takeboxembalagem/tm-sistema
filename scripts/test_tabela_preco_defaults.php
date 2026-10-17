<?php
/**
 * Testes locais — padrões da Tabela de Preços (P1-8).
 * Padrões só numa tabela realmente vazia; margens excluídas/editadas não voltam; GET não grava;
 * a última margem não pode ser excluída; reordenação continua nas operações de escrita.
 * Fotografa tabela_preco_config e tabela_preco_margens_cfg e restaura as linhas originais ao final.
 * NÃO executar em produção. Uso: php scripts/test_tabela_preco_defaults.php
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
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_tp_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$usuariosTmp = [];

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

/** Executa a rota real num subprocesso, logado como $userId; devolve HTML e flash. */
function rota(string $method, string $uri, int $userId, array $post = []): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = ['method' => $method, 'uri' => $uri, 'post' => $post + ['_csrf' => $token], 'token' => $token,
        'user_id' => $userId, 'sess_dir' => $sessDir, 'root' => dirname(__DIR__)];
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req))));
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

/** Estado completo (todas as colunas, inclusive ids e timestamps) das duas tabelas. */
function estado(): string
{
    $pdo = Database::pdo();
    return json_encode([
        $pdo->query('SELECT * FROM tabela_preco_config ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM tabela_preco_margens_cfg ORDER BY id')->fetchAll(),
    ]);
}

function valores(): array
{
    return array_map(static fn ($v) => (float) $v,
        Database::pdo()->query('SELECT margem_percentual FROM tabela_preco_margens_cfg ORDER BY margem_percentual')->fetchAll(PDO::FETCH_COLUMN));
}

/** ordem é sequencial 0..n-1 seguindo o valor da margem. */
function ordemCorreta(): bool
{
    $rows = Database::pdo()->query('SELECT ordem FROM tabela_preco_margens_cfg ORDER BY margem_percentual, id')->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows) === range(0, count($rows) - 1);
}

/** Linhas gravadas/alteradas/apagadas em tabelas InnoDB (contadores globais do servidor local). */
function escritasInnodb(): int
{
    $rows = Database::pdo()->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_rows_inserted', 'Innodb_rows_updated', 'Innodb_rows_deleted')")->fetchAll();
    return array_sum(array_map(static fn ($r) => (int) $r['Value'], $rows));
}

function idMargem(float $m): int
{
    $stmt = Database::pdo()->prepare('SELECT id FROM tabela_preco_margens_cfg WHERE margem_percentual = :m');
    $stmt->execute(['m' => $m]);
    return (int) $stmt->fetchColumn();
}

function definirMargens(array $valores): void
{
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM tabela_preco_margens_cfg');
    $ins = $pdo->prepare('INSERT INTO tabela_preco_margens_cfg (margem_percentual, ordem) VALUES (:m, :o)');
    foreach ($valores as $i => $v) {
        $ins->execute(['m' => $v, 'o' => $i]);
    }
}

$sigAntes = assinaturaBanco();
echo "Assinatura do banco antes: {$sigAntes}" . PHP_EOL;
$configOriginal = $pdo->query('SELECT * FROM tabela_preco_config ORDER BY id')->fetchAll();
$margensOriginais = $pdo->query('SELECT * FROM tabela_preco_margens_cfg ORDER BY id')->fetchAll();
$estadoOriginal = estado();
$autoIncAntes = (int) $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabela_preco_margens_cfg'")->fetchColumn();
$linhasAntes = TabelaPreco::montarLinhas(TabelaPreco::listarGrupos([], 1, 1000)['items'], TabelaPreco::config(), TabelaPreco::margens());
ok('leitura inicial (config + margens) não alterou nada', estado() === $estadoOriginal);

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
    foreach (['admin', 'usuario'] as $perfil) {
        $usuariosTmp[$perfil] = Usuario::create(['nome' => 'TST TP ' . $perfil, 'email' => 'tst-tp-' . $sufixo . '-' . $perfil . '@teste.local',
            'senha_hash' => Usuario::hashSenha('senha-' . $sufixo), 'status' => 'ativo', 'perfil' => $perfil]);
    }
    $admin = $usuariosTmp['admin'];
    $user = $usuariosTmp['usuario'];

    // F (estado real): abrir a tela não grava nada
    $antes = estado();
    $w0 = escritasInnodb();
    sleep(1);
    $r = rota('GET', '/tabela-precos', $admin);
    $w1 = escritasInnodb();
    ok('F. GET /tabela-precos (dados reais) renderiza', str_contains($r['html'], 'Tabela de Preços'));
    ok('F. GET /tabela-precos não altera margens, configuração nem updated_at', estado() === $antes);
    ok('F. GET /tabela-precos não grava nenhuma linha InnoDB', $w1 - $w0 === 0, 'escritas=' . ($w1 - $w0));

    // A. Primeira inicialização
    $pdo->exec('DELETE FROM tabela_preco_margens_cfg');
    $pdo->exec('DELETE FROM tabela_preco_config');
    TabelaPreco::ensureDefaults();
    $cfg = $pdo->query('SELECT * FROM tabela_preco_config')->fetchAll();
    ok('A. configuração padrão criada (9,5% imposto, comissão desligada, 10%)', count($cfg) === 1 && (int) $cfg[0]['id'] === 1
        && (float) $cfg[0]['imposto_percentual'] === 9.5 && (int) $cfg[0]['incluir_comissao'] === 0 && (float) $cfg[0]['comissao_percentual'] === 10.0);
    ok('A. seis margens padrão criadas (10..60)', valores() === [10.0, 20.0, 30.0, 40.0, 50.0, 60.0], implode(',', valores()));
    ok('A. ordem inicial correta', ordemCorreta());

    // B. Estado normal
    $antes = estado();
    sleep(1);
    TabelaPreco::ensureDefaults();
    TabelaPreco::config();
    TabelaPreco::margens();
    ok('B. nova execução mantém ids, valores e updated_at', estado() === $antes);

    // C. Excluir 10
    ok('C. exclusão da margem 10 permitida', TabelaPreco::deleteMargem(idMargem(10)) === true);
    TabelaPreco::ensureDefaults();
    ok('C. 10% NÃO volta após ensureDefaults()', valores() === [20.0, 30.0, 40.0, 50.0, 60.0], implode(',', valores()));
    rota('GET', '/tabela-precos', $admin);
    ok('C. 10% NÃO volta ao abrir a tela', valores() === [20.0, 30.0, 40.0, 50.0, 60.0]);

    // D. Editar 10 para 15 (pela rota real)
    definirMargens([10, 20, 30]);
    $r = rota('POST', '/tabela-precos/margens/' . idMargem(10), $admin, ['margem_percentual' => '15']);
    ok('D. admin edita 10 para 15', flashTexto($r, 'success') === 'Margem atualizada.' && valores() === [15.0, 20.0, 30.0], implode(',', valores()));
    $htmlAdmin = rota('GET', '/tabela-precos', $admin)['html'];
    $htmlUser = rota('GET', '/tabela-precos', $user)['html'];
    ok('D. ao abrir a tela fica só 15, 20, 30 (10% não reaparece)', valores() === [15.0, 20.0, 30.0]
        && str_contains($htmlUser, '15,00% · 20,00% · 30,00%')
        && preg_match_all('/name="margem_percentual" inputmode="decimal"\s+value="([^"]+)"/', $htmlAdmin, $mm) === 3 && $mm[1] === ['15,00', '20,00', '30,00']);

    // E. Última margem
    definirMargens([20, 30]);
    $r = rota('POST', '/tabela-precos/margens/' . idMargem(20) . '/excluir', $admin);
    ok('E. com 2 margens, admin exclui uma', flashTexto($r, 'success') === 'Margem removida.' && valores() === [30.0]);
    $html = rota('GET', '/tabela-precos', $admin)['html'];
    ok('E. com 1 margem, a tela não mostra o botão de excluir', !str_contains($html, '/tabela-precos/margens/' . idMargem(30) . '/excluir') && str_contains($html, 'id="margem-unica"'));
    $r = rota('POST', '/tabela-precos/margens/' . idMargem(30) . '/excluir', $admin);
    ok('E. POST excluir a última margem é recusado com mensagem', flashTexto($r, 'errors') === 'É necessário manter pelo menos uma margem na Tabela de Preços.'
        && flashTexto($r, 'success') === '' && valores() === [30.0]);
    ok('E. deleteMargem() no model também recusa a última', TabelaPreco::deleteMargem(idMargem(30)) === false && valores() === [30.0]);
    TabelaPreco::ensureDefaults();
    ok('E. com 1 margem, ensureDefaults() não recria as padrão', valores() === [30.0]);

    // G. Ordem
    definirMargens([50, 10, 30]);
    $pdo->exec("UPDATE tabela_preco_margens_cfg SET ordem = 7, updated_at = '2026-01-01 00:00:00'");
    $antes = estado();
    $w0 = escritasInnodb();
    rota('GET', '/tabela-precos', $admin);
    TabelaPreco::ensureDefaults();
    ok('G. GET com ordem fora de sequência não reordena nem grava', estado() === $antes && escritasInnodb() - $w0 === 0);
    rota('POST', '/tabela-precos/margens', $admin, ['margem_percentual' => '20']);
    ok('G. adicionar margem reordena', valores() === [10.0, 20.0, 30.0, 50.0] && ordemCorreta());
    $pdo->exec('UPDATE tabela_preco_margens_cfg SET ordem = 9');
    rota('POST', '/tabela-precos/margens/' . idMargem(50), $admin, ['margem_percentual' => '5']);
    ok('G. editar margem reordena', valores() === [5.0, 10.0, 20.0, 30.0] && ordemCorreta()
        && (int) valor('SELECT ordem FROM tabela_preco_margens_cfg WHERE margem_percentual = 5') === 0);
    $pdo->exec('UPDATE tabela_preco_margens_cfg SET ordem = 9');
    rota('POST', '/tabela-precos/margens/' . idMargem(10) . '/excluir', $admin);
    ok('G. excluir margem reordena', valores() === [5.0, 20.0, 30.0] && ordemCorreta());

    // H. Configuração
    $r = rota('POST', '/tabela-precos/config', $admin, ['imposto_percentual' => '12,25', 'incluir_comissao' => '1', 'comissao_percentual' => '4,50']);
    $antes = estado();
    sleep(1);
    rota('GET', '/tabela-precos', $admin);
    TabelaPreco::ensureDefaults();
    $cfg = TabelaPreco::config();
    ok('H. imposto e comissão salvos pelo admin', (float) $cfg['imposto_percentual'] === 12.25 && (int) $cfg['incluir_comissao'] === 1 && (float) $cfg['comissao_percentual'] === 4.5,
        flashTexto($r, 'errors'));
    ok('H. leitura e ensureDefaults() preservam a configuração (inclusive updated_at)', estado() === $antes);

    // I. Fórmula
    ok('I. fórmula conhecida: custo 10, imposto 9,5, comissão 10, margem 30 → 19,80198…',
        abs((float) TabelaPrecoCalculo::calcularPreco(10.0, 9.5, 10.0, 30.0) - 10 / 0.505) < 1e-9);
    $linhas = TabelaPreco::montarLinhas([['produto' => ['id' => 1, 'nome' => 'X', 'codigo_interno' => 'X', 'custo_unitario' => 10.0, 'quantidade_pacote' => null], 'variacoes' => []]],
        ['imposto_percentual' => 9.5, 'incluir_comissao' => 1, 'comissao_percentual' => 10.0], [['id' => 7, 'margem_percentual' => 30.0]]);
    ok('I. montarLinhas usa a mesma fórmula', abs((float) $linhas[0]['precos'][7]['preco'] - 10 / 0.505) < 1e-9);

    // J. Segurança
    $antes = estado();
    $r1 = rota('POST', '/tabela-precos/margens', $user, ['margem_percentual' => '77']);
    $r2 = rota('POST', '/tabela-precos/margens/' . idMargem(20), $user, ['margem_percentual' => '77']);
    $r3 = rota('POST', '/tabela-precos/margens/' . idMargem(20) . '/excluir', $user);
    $r4 = rota('POST', '/tabela-precos/config', $user, ['imposto_percentual' => '1', 'comissao_percentual' => '1']);
    $bloq = static fn (array $r): bool => str_contains(flashTexto($r, 'errors'), 'Você não tem permissão');
    ok('J. usuário comum bloqueado em adicionar, editar, excluir margem e salvar configuração', $bloq($r1) && $bloq($r2) && $bloq($r3) && $bloq($r4) && estado() === $antes);
    $r = rota('POST', '/tabela-precos/margens/' . idMargem(20) . '/excluir', $admin, ['_csrf' => 'invalido']);
    ok('J. CSRF: admin com token inválido recusado (419)', str_contains($r['html'], 'Sessão expirada ou token inválido') && estado() === $antes);
    $r = rota('GET', '/tabela-precos', $user);
    ok('J. usuário comum continua vendo a tabela somente leitura', str_contains($r['html'], 'config-somente-leitura') && estado() === $antes);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo->exec('DELETE FROM tabela_preco_margens_cfg');
    $pdo->exec('DELETE FROM tabela_preco_config');
    foreach ([['tabela_preco_config', $configOriginal], ['tabela_preco_margens_cfg', $margensOriginais]] as [$tabela, $linhas]) {
        foreach ($linhas as $row) {
            $cols = array_keys($row);
            $pdo->prepare("INSERT INTO {$tabela} (`" . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')')->execute($row);
        }
    }
    foreach ($usuariosTmp as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $uid]);
    }
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
}

function valor(string $sql): mixed
{
    return Database::pdo()->query($sql)->fetchColumn();
}

ok('K. configuração e margens restauradas exatamente (ids, valores, ordem, timestamps)', estado() === $estadoOriginal);
$linhasDepois = TabelaPreco::montarLinhas(TabelaPreco::listarGrupos([], 1, 1000)['items'], TabelaPreco::config(), TabelaPreco::margens());
ok('I/K. preços da tabela real idênticos antes e depois', $linhasDepois == $linhasAntes);
ok('K. usuários e arquivos temporários removidos', (int) valor("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-tp-%'") === 0 && !is_dir($tmpDir));
$autoIncDepois = (int) valor("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabela_preco_margens_cfg'");
echo "AUTO_INCREMENT de tabela_preco_margens_cfg: {$autoIncAntes} → {$autoIncDepois} (avança com INSERTs temporários; não é dado nem estrutura)" . PHP_EOL;
$sigDepois = assinaturaBanco();
echo "Assinatura do banco depois: {$sigDepois}" . PHP_EOL;
ok('K. banco idêntico ao inicial (dados permanentes e estrutura)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Testes locais — GTIN da unidade individual e do pacote nas variações.
 * Cria dados temporários (TST-GTN-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_gtin_variacoes.php
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

$db = config('db');
if (!in_array((string) ($db['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true) || (string) ($db['name'] ?? '') !== 'tm_sistema_dev') {
    ok('ambiente: banco local tm_sistema_dev', false, 'teste abortado sem gravar nada');
    echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
    exit(1);
}

/**
 * Dígito verificador GS1 por uma implementação independente da do sistema:
 * completa à esquerda até 17 dígitos e aplica os pesos 3,1,3,1... a partir da esquerda.
 */
function dvReferencia(string $semDv): int
{
    $soma = 0;
    foreach (str_split(str_pad($semDv, 17, '0', STR_PAD_LEFT)) as $i => $d) {
        $soma += (int) $d * ($i % 2 === 0 ? 3 : 1);
    }
    return (10 - $soma % 10) % 10;
}

function comDv(string $semDv): string
{
    return $semDv . dvReferencia($semDv);
}

/** Troca o último dígito por outro, tornando o dígito verificador incorreto. */
function dvErrado(string $gtin): string
{
    return substr($gtin, 0, -1) . (((int) substr($gtin, -1) + 1) % 10);
}

$pdo = Database::pdo();
$pdo->exec('SET SESSION group_concat_max_len = 16777216');
$sufixo = date('His') . random_int(100, 999);
$prefixo = 'TST-GTN-' . $sufixo;
$produtoId = 0;
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_gtin_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_gtin_' . $sufixo . '.php';

/** Executa uma requisição pelo roteador real, em subprocesso. */
function rota(string $metodo, string $uri, array $post, ?int $userId): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'metodo' => $metodo,
        'uri' => $uri,
        'post' => $post + ['_csrf' => $token],
        'token' => $token,
        'user_id' => $userId,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
    ];
    // Saída em arquivo: shell_exec no Windows lê em modo texto e corta o PDF no byte 0x1A.
    $saida = $sessDir . DIRECTORY_SEPARATOR . 'saida.bin';
    $proc = proc_open(
        [PHP_BINARY, $runner, base64_encode(json_encode($req))],
        [0 => ['pipe', 'r'], 1 => ['file', $saida, 'wb'], 2 => ['file', $saida . '.err', 'wb']],
        $pipes
    );
    fclose($pipes[0]);
    proc_close($proc);
    $out = (string) file_get_contents($saida) . (string) @file_get_contents($saida . '.err');
    @unlink($saida);
    @unlink($saida . '.err');
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['code' => null, 'flash' => null, 'html' => '', 'raw' => $out];
    }
    $res = json_decode(substr($out, $pos + 10), true) ?: [];
    $res['raw'] = substr($out, 0, $pos);
    return $res;
}

function flashTexto(array $r, string $tipo): string
{
    $v = $r['flash'][$tipo] ?? '';
    return is_array($v) ? implode(' | ', $v) : (string) $v;
}

function gtins(int $variacaoId): array
{
    $stmt = Database::pdo()->prepare('SELECT gtin_unidade, gtin_pacote FROM produto_variacoes WHERE id = :id');
    $stmt->execute(['id' => $variacaoId]);
    $row = $stmt->fetch() ?: [];
    return [$row['gtin_unidade'] ?? null, $row['gtin_pacote'] ?? null];
}

/** Texto do PDF pelo Ghostscript (txtwrite, uma linha por linha impressa); null se o Ghostscript não existir. */
function textoPdf(string $bin): ?string
{
    global $sessDir;
    $gs = (glob('C:/Program Files/gs/*/bin/gswin64c.exe') ?: [])[0] ?? (is_file('/usr/bin/gs') ? '/usr/bin/gs' : null);
    if ($gs === null) {
        return null;
    }
    $pdf = $sessDir . DIRECTORY_SEPARATOR . 'relatorio.pdf';
    $txt = $sessDir . DIRECTORY_SEPARATOR . 'relatorio.txt';
    file_put_contents($pdf, $bin);
    shell_exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -o ' . escapeshellarg($txt) . ' ' . escapeshellarg($pdf) . ' 2>&1');
    $texto = is_file($txt) ? (string) file_get_contents($txt) : '';
    @unlink($pdf);
    @unlink($txt);
    return (string) preg_replace('/[ \t]+/u', ' ', $texto);
}

/**
 * Linhas da tabela do relatório de variações na tela: [código, GTIN unidade, GTIN pacote, produto].
 *
 * @return list<array{0:string, 1:string, 2:string, 3:string}>
 */
function linhasTela(string $html): array
{
    $linhas = [];
    preg_match_all('#<tr>\s*(<td.*?)</tr>#s', $html, $trs);
    foreach ($trs[1] as $tr) {
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr, $tds);
        if (count($tds[1]) !== 8) {
            continue;
        }
        $c = array_map(static fn (string $t): string => trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')), $tds[1]);
        $linhas[] = [$c[2], $c[3], $c[4], $c[0]];
    }
    return $linhas;
}

/** Na ordem do texto do PDF: [código, GTIN unidade, GTIN pacote] das linhas que contêm códigos de variação do teste. */
function linhasPdf(string $texto, string $prefixo): array
{
    $linhas = [];
    $re = '/(' . preg_quote($prefixo, '/') . '-[A-Z]+) (\d{8,14}|—) (\d{8,14}|—) /u';
    foreach (preg_split('/\R/u', $texto) as $linha) {
        if (preg_match($re, $linha, $m)) {
            $linhas[] = [$m[1], $m[2], $m[3]];
        }
    }
    return $linhas;
}

function md5Sql(string $sql): string
{
    return (string) Database::pdo()->query($sql)->fetchColumn();
}

function idPorCodigo(string $codigo): int
{
    $stmt = Database::pdo()->prepare('SELECT id FROM produto_variacoes WHERE codigo = :c');
    $stmt->execute(['c' => $codigo]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_VARIACOES_EXISTENTES = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', id, produto_id, codigo, nome, estoque, status,
        IFNULL(observacao, 'N'), custo_modo, pacote_modo, IFNULL(quantidade_pacote, 'N'), IFNULL(gtin_unidade, 'N'), IFNULL(gtin_pacote, 'N'))
        ORDER BY id SEPARATOR '|'), '')) FROM produto_variacoes WHERE codigo NOT LIKE 'TST-GTN-%'";
const SQL_SIG_PRODUTOS_EXISTENTES = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', id, codigo_interno, nome, status, IFNULL(quantidade_pacote, 'N'))
        ORDER BY id SEPARATOR '|'), '')) FROM produtos WHERE codigo_interno NOT LIKE 'TST-GTN-%'";
const SQL_SIG_COMPOSICAO = "SELECT MD5(CONCAT(
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, produto_id, IFNULL(material_id, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM produto_composicao), ''),
        '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, variacao_id, IFNULL(material_id, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM variacao_composicao), '')))";

$sigEstrutura = md5Sql(SQL_SIG_ESTRUTURA);
$sigVariacoes = md5Sql(SQL_SIG_VARIACOES_EXISTENTES);
$sigProdutos = md5Sql(SQL_SIG_PRODUTOS_EXISTENTES);
$sigComposicao = md5Sql(SQL_SIG_COMPOSICAO);

// ================= 1. Estrutura =================
$colunas = static function (string $tabela) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME LIKE :c'
    );
    $stmt->execute(['t' => $tabela, 'c' => '%gtin%']);
    return $stmt->fetchAll();
};
$colsVar = $colunas('produto_variacoes');
ok('estrutura: produto_variacoes tem exatamente gtin_unidade e gtin_pacote',
    array_column($colsVar, 'COLUMN_NAME') === ['gtin_unidade', 'gtin_pacote'], implode(',', array_column($colsVar, 'COLUMN_NAME')));
ok('estrutura: os dois campos são VARCHAR(14) NULL (texto)',
    count($colsVar) === 2 && array_filter($colsVar, static fn ($c) => $c['COLUMN_TYPE'] !== 'varchar(14)' || $c['IS_NULLABLE'] !== 'YES') === []);
ok('estrutura: tabela produtos sem nenhum campo GTIN', $colunas('produtos') === []);
$indices = $pdo->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_variacoes' AND COLUMN_NAME LIKE 'gtin%' ORDER BY INDEX_NAME")->fetchAll();
ok('estrutura: índices únicos uq_variacao_gtin_pacote e uq_variacao_gtin_unidade',
    array_map(static fn ($i) => $i['INDEX_NAME'] . ':' . $i['COLUMN_NAME'] . ':' . $i['NON_UNIQUE'], $indices)
        === ['uq_variacao_gtin_pacote:gtin_pacote:0', 'uq_variacao_gtin_unidade:gtin_unidade:0']);
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$migracao = preg_replace('/^--.*$/m', '', (string) file_get_contents(dirname(__DIR__) . '/database/alter_variacoes_gtin.sql'));
ok('schema.sql: declara os dois campos e os dois índices',
    str_contains($schema, 'gtin_unidade VARCHAR(14) NULL') && str_contains($schema, 'gtin_pacote VARCHAR(14) NULL')
    && str_contains($schema, 'UNIQUE KEY uq_variacao_gtin_unidade (gtin_unidade)') && str_contains($schema, 'UNIQUE KEY uq_variacao_gtin_pacote (gtin_pacote)'));
ok('migração: só ADD COLUMN/ADD UNIQUE KEY em produto_variacoes',
    preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|INSERT|MODIFY|CHANGE|RENAME|produtos\b)/i', $migracao) === 0
    && substr_count($migracao, 'ADD COLUMN') === 2 && substr_count($migracao, 'ADD UNIQUE KEY') === 2);

// ================= 2. Formatos e dígitos verificadores =================
$validos = [
    'GTIN-8' => '96385074',
    'GTIN-12' => '036000291452',
    'GTIN-13' => '4006381333931',
    'GTIN-14' => '00012345600012',
];
foreach ($validos as $tipo => $codigo) {
    ok("referência independente confirma o exemplo {$tipo} {$codigo}", dvReferencia(substr($codigo, 0, -1)) === (int) substr($codigo, -1));
    [$v, $erro] = parse_gtin($codigo, 'Campo');
    ok("{$tipo} válido aceito: {$codigo}", $v === $codigo && $erro === null, (string) $erro);
    [$v, $erro] = parse_gtin(dvErrado($codigo), 'Campo');
    ok("{$tipo} com dígito verificador incorreto recusado: " . dvErrado($codigo),
        $v === null && str_contains((string) $erro, 'dígito verificador'), (string) $erro);
}
for ($i = 0; $i < 200; $i++) {
    $base = (string) random_int(1000000, 9999999) . (string) random_int(100000, 999999);
    foreach ([7, 11, 12, 13] as $tam) {
        $g = comDv(substr($base, 0, $tam));
        if (gtin_digito_verificador($g) !== dvReferencia(substr($g, 0, -1))) {
            ok('dígito verificador do sistema = referência (aleatórios)', false, $g);
            break 2;
        }
    }
}
ok('dígito verificador do sistema = referência em 800 códigos aleatórios', true);

foreach (['1234567', '96385074 1', '123456789', '12345678901', '123456789012345', '0'] as $codigo) {
    [$v, $erro] = parse_gtin($codigo, 'GTIN do pacote');
    ok("comprimento não permitido recusado: \"{$codigo}\"", $v === null && str_contains((string) $erro, '8, 12, 13 ou 14 dígitos')
        && str_starts_with((string) $erro, 'GTIN do pacote:'), (string) $erro);
}
foreach (['400638133393A', 'ABCDEFGH', '4006381.333931', '4006381/333931', '+4006381333931', "4006381\t333931", '４００６３８１３３３９３１', '4006381333931,'] as $codigo) {
    [$v, $erro] = parse_gtin($codigo, 'GTIN da unidade individual');
    ok('letras/caracteres inválidos recusados: ' . json_encode($codigo, JSON_UNESCAPED_UNICODE),
        $v === null && str_contains((string) $erro, 'apenas números') && str_starts_with((string) $erro, 'GTIN da unidade individual:'), (string) $erro);
}
[$v] = parse_gtin('  4006381 333931  ', 'x');
ok('espaços entre/ao redor dos dígitos são descartados', $v === '4006381333931', (string) $v);
[$v] = parse_gtin('0-36000-29145-2', 'x');
ok('hífens são descartados sem alterar o comprimento', $v === '036000291452', (string) $v);
[$v, $erro] = parse_gtin('   ', 'x');
ok('campo vazio → NULL sem erro', $v === null && $erro === null);
[$v] = parse_gtin('00012345600012', 'x');
ok('zeros à esquerda preservados (GTIN-14)', $v === '00012345600012' && strlen($v) === 14);
[$v, $erro] = parse_gtin('12345600012', 'x');
ok('código sem os zeros à esquerda não é completado automaticamente', $v === null && $erro !== null, (string) $erro);

$userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

try {
    if ($userId === 0) {
        throw new RuntimeException('Sem usuário ativo local para simular a sessão.');
    }
    $produtoId = Produto::create([
        'codigo_interno' => $prefixo,
        'nome' => 'Teste código de barras',
        'tamanho' => null,
        'tamanho_aberta' => null,
        'descricao' => null,
        'categoria_id' => null,
        'colecao_id' => null,
        'foto_principal' => null,
        'status' => 'ativo',
        'quantidade_pacote' => 10,
        'peso_pacote_kg' => null,
    ]);

    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = $req['metodo'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
$_POST = $req['metodo'] === 'POST' ? $req['post'] : [];
parse_str((string) parse_url($req['uri'], PHP_URL_QUERY), $_GET);
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
if ($req['user_id'] !== null) {
    $_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'admin'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo '@@RESULT@@' . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['_flash'] ?? null, 'html' => $html]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $base = '/produtos/' . $produtoId . '/variacoes';
    $nova = static fn (string $sufixoCodigo, array $campos = []): array => $campos + [
        'codigo' => $prefixo . '-' . $sufixoCodigo,
        'nome' => 'Variação ' . $sufixoCodigo,
        'estoque' => '5',
        'status' => 'ativo',
        'observacao' => '',
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
    ];

    // Códigos de circulação restrita (prefixo 2), gerados só para este teste e apagados ao final.
    $g = [];
    for ($i = 0; $i < 6; $i++) {
        $g[$i] = comDv('2' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT));
    }

    // ================= 3. Formulários e persistência =================
    $r = rota('GET', $base . '/novo', [], $userId);
    ok('formulário nova variação: exibe "GTIN da unidade individual" e "GTIN do pacote"',
        str_contains((string) $r['html'], 'GTIN da unidade individual') && str_contains((string) $r['html'], 'GTIN do pacote')
        && str_contains((string) $r['html'], 'name="gtin_unidade"') && str_contains((string) $r['html'], 'name="gtin_pacote"'));

    $r = rota('POST', $base, $nova('SEM'), $userId);
    $vSem = idPorCodigo($prefixo . '-SEM');
    ok('cadastro sem GTIN: salvo com os dois campos NULL', $vSem > 0 && gtins($vSem) === [null, null], flashTexto($r, 'errors'));

    $r = rota('POST', $base, $nova('UN', ['gtin_unidade' => $g[0]]), $userId);
    $vUn = idPorCodigo($prefixo . '-UN');
    ok('cadastro só com GTIN da unidade', $vUn > 0 && gtins($vUn) === [$g[0], null], flashTexto($r, 'errors'));

    $r = rota('POST', $base, $nova('PC', ['gtin_pacote' => $g[1]]), $userId);
    $vPc = idPorCodigo($prefixo . '-PC');
    ok('cadastro só com GTIN do pacote', $vPc > 0 && gtins($vPc) === [null, $g[1]], flashTexto($r, 'errors'));

    $r = rota('POST', $base, $nova('DOIS', ['gtin_unidade' => '0 3600029145 2', 'gtin_pacote' => '00012345600012']), $userId);
    $vDois = idPorCodigo($prefixo . '-DOIS');
    ok('cadastro com os dois campos (normalizados, zeros à esquerda preservados)',
        $vDois > 0 && gtins($vDois) === ['036000291452', '00012345600012'], flashTexto($r, 'errors'));
    ok('cadastro com GTIN: estoque inicial e modos preservados',
        (int) (Variacao::find($vDois)['estoque'] ?? -1) === 5 && (Variacao::find($vDois)['pacote_modo'] ?? '') === 'herdar');

    $r = rota('GET', $base . '/' . $vDois . '/editar', [], $userId);
    ok('edição: reexibe os valores salvos', str_contains((string) $r['html'], 'value="036000291452"')
        && str_contains((string) $r['html'], 'value="00012345600012"'));

    $edit = static fn (int $id, string $sufixoCodigo, array $campos): array => $campos + [
        'codigo' => $prefixo . '-' . $sufixoCodigo,
        'nome' => 'Variação ' . $sufixoCodigo,
        'status' => 'ativo',
        'observacao' => '',
        'pacote_modo' => 'herdar',
    ];
    $r = rota('POST', $base . '/' . $vDois, $edit($vDois, 'DOIS', ['gtin_unidade' => $g[2], 'gtin_pacote' => $g[3]]), $userId);
    ok('edição dos dois campos', gtins($vDois) === [$g[2], $g[3]], flashTexto($r, 'errors'));

    $r = rota('POST', $base . '/' . $vDois, $edit($vDois, 'DOIS', ['gtin_unidade' => $g[2], 'gtin_pacote' => '']), $userId);
    ok('remoção do GTIN do pacote (vazio → NULL)', gtins($vDois) === [$g[2], null], flashTexto($r, 'errors'));
    $r = rota('POST', $base . '/' . $vDois, $edit($vDois, 'DOIS', ['gtin_unidade' => '', 'gtin_pacote' => $g[3]]), $userId);
    ok('remoção do GTIN da unidade e novo GTIN do pacote', gtins($vDois) === [null, $g[3]], flashTexto($r, 'errors'));

    $r = rota('POST', $base . '/' . $vDois, $edit($vDois, 'DOIS', ['gtin_unidade' => $g[2], 'gtin_pacote' => $g[3]]), $userId);
    $r = rota('POST', $base . '/' . $vDois, $edit($vDois, 'DOIS', ['nome' => 'Variação DOIS editada', 'gtin_unidade' => $g[2], 'gtin_pacote' => $g[3]]), $userId);
    ok('edição sem falso positivo com os próprios códigos', flashTexto($r, 'errors') === ''
        && (Variacao::find($vDois)['nome'] ?? '') === 'Variação DOIS editada' && gtins($vDois) === [$g[2], $g[3]], flashTexto($r, 'errors'));

    Variacao::update($vDois, [
        'codigo' => $prefixo . '-DOIS', 'nome' => 'Variação DOIS editada', 'status' => 'ativo', 'observacao' => null,
        'custo_modo' => 'herdar', 'pacote_modo' => 'herdar', 'quantidade_pacote' => null,
    ]);
    ok('model: update sem as chaves GTIN não apaga os códigos salvos', gtins($vDois) === [$g[2], $g[3]]);

    // ================= 4. Unicidade =================
    $antes = Variacao::find($vPc);
    $tentativa = static fn (array $campos) => rota('POST', $base . '/' . $vPc,
        $edit($vPc, 'PC', ['nome' => 'Nome que não deve ser gravado'] + $campos), $userId);

    $r = $tentativa(['gtin_unidade' => $g[0], 'gtin_pacote' => $g[1]]);
    ok('duplicidade unidade × unidade de outra variação recusada',
        str_contains(flashTexto($r, 'errors'), 'GTIN da unidade individual: o código ' . $g[0] . ' já está em uso na variação "' . $prefixo . '-UN'),
        flashTexto($r, 'errors'));

    $r = $tentativa(['gtin_unidade' => '', 'gtin_pacote' => $g[3]]);
    ok('duplicidade pacote × pacote de outra variação recusada',
        str_contains(flashTexto($r, 'errors'), 'GTIN do pacote: o código ' . $g[3] . ' já está em uso na variação "' . $prefixo . '-DOIS'),
        flashTexto($r, 'errors'));

    $r = $tentativa(['gtin_unidade' => $g[3], 'gtin_pacote' => $g[1]]);
    ok('duplicidade unidade × pacote de outra variação recusada',
        str_contains(flashTexto($r, 'errors'), 'GTIN da unidade individual: o código ' . $g[3] . ' já está em uso'), flashTexto($r, 'errors'));

    $r = $tentativa(['gtin_unidade' => '', 'gtin_pacote' => $g[0]]);
    ok('duplicidade pacote × unidade de outra variação recusada',
        str_contains(flashTexto($r, 'errors'), 'GTIN do pacote: o código ' . $g[0] . ' já está em uso'), flashTexto($r, 'errors'));

    $r = $tentativa(['gtin_unidade' => $g[1], 'gtin_pacote' => $g[1]]);
    ok('mesmo código nos dois campos da mesma variação recusado',
        str_contains(flashTexto($r, 'errors'), 'GTIN do pacote: deve ser diferente do GTIN da unidade individual'), flashTexto($r, 'errors'));

    $r = $tentativa(['gtin_unidade' => '400638133393A', 'gtin_pacote' => dvErrado($g[4])]);
    $erros = flashTexto($r, 'errors');
    ok('código inválido: mensagens indicam cada campo',
        str_contains($erros, 'GTIN da unidade individual: use apenas números') && str_contains($erros, 'GTIN do pacote: dígito verificador inválido'), $erros);
    ok('falha de validação: valores digitados voltam ao formulário',
        ($r['flash']['old']['gtin_unidade'] ?? null) === '400638133393A' && ($r['flash']['old']['gtin_pacote'] ?? null) === dvErrado($g[4])
        && ($r['flash']['old']['nome'] ?? null) === 'Nome que não deve ser gravado');
    ok('falha de validação: variação preservada no banco', Variacao::find($vPc) == $antes);

    $r = rota('POST', $base, $nova('DUP', ['gtin_unidade' => $g[4], 'gtin_pacote' => $g[2]]), $userId);
    ok('cadastro novo com GTIN já usado: recusado e nada gravado',
        idPorCodigo($prefixo . '-DUP') === 0 && str_contains(flashTexto($r, 'errors'), 'GTIN do pacote: o código ' . $g[2] . ' já está em uso'),
        flashTexto($r, 'errors'));

    $erroIndice = '';
    try {
        Variacao::create([
            'produto_id' => $produtoId, 'codigo' => $prefixo . '-IDX', 'nome' => 'Índice', 'estoque' => 0, 'status' => 'ativo',
            'observacao' => null, 'gtin_unidade' => $g[0],
        ]);
    } catch (PDOException $e) {
        $erroIndice = (string) ($e->errorInfo[2] ?? '');
    }
    ok('banco: índice único barra GTIN da unidade repetido mesmo sem a validação da aplicação',
        str_contains($erroIndice, 'uq_variacao_gtin_unidade') && idPorCodigo($prefixo . '-IDX') === 0, $erroIndice);
    $nulos = (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE '{$prefixo}-%' AND gtin_unidade IS NULL")->fetchColumn();
    ok('banco: várias variações podem ficar sem GTIN (vários NULL)', $nulos >= 2, "nulos={$nulos}");

    // ================= 5. Relatório Estoque de Variações: banco × tela × PDF =================
    $filtrosBase = ['produto_id' => $produtoId, 'variacao' => '', 'codigo' => '', 'status' => '', 'colecao_id' => null, 'situacao' => ''];
    $esperado = static function (array $f) use ($pdo): array {
        $linhas = [];
        foreach (RelatorioEstoque::variacoes($f)['linhas'] as $l) {
            $stmt = $pdo->prepare('SELECT gtin_unidade, gtin_pacote FROM produto_variacoes WHERE id = :id');
            $stmt->execute(['id' => $l['id']]);
            $banco = $stmt->fetch();
            $linhas[] = [(string) $l['codigo'], $banco['gtin_unidade'] ?? '—', $banco['gtin_pacote'] ?? '—'];
        }
        return $linhas;
    };
    $semProduto = static fn (array $linhas): array => array_map(static fn (array $l): array => array_slice($l, 0, 3), $linhas);

    $esp = $esperado($filtrosBase);
    $porCodigo = array_column($esp, null, 0);
    ok('relatório: as 4 variações de teste cobrem os quatro casos (sem GTIN, só unidade, só pacote, ambos)',
        count($esp) === 4
        && $porCodigo[$prefixo . '-SEM'] === [$prefixo . '-SEM', '—', '—']
        && $porCodigo[$prefixo . '-UN'] === [$prefixo . '-UN', $g[0], '—']
        && $porCodigo[$prefixo . '-PC'] === [$prefixo . '-PC', '—', $g[1]]
        && $porCodigo[$prefixo . '-DOIS'] === [$prefixo . '-DOIS', $g[2], $g[3]], json_encode($esp));

    $qs = 'aba=variacoes&produto_id=' . $produtoId;
    $r = rota('GET', '/relatorios/estoque?' . $qs, [], $userId);
    $html = (string) $r['html'];
    ok('tela: colunas "GTIN da unidade individual" e "GTIN do pacote"',
        str_contains($html, '<th>GTIN da unidade individual</th>') && str_contains($html, '<th>GTIN do pacote</th>'));
    $tela = linhasTela($html);
    ok('tela: GTINs de cada variação iguais ao banco, na ordem do relatório (vazio = —)', $semProduto($tela) === $esp, json_encode($tela));
    ok('tela: coluna Produto mostra só o nome do produto pai, sem GTIN',
        $tela !== [] && array_unique(array_column($tela, 3)) === ['Teste código de barras']);
    ok('tela: link "Gerar PDF" preserva os filtros', str_contains($html, 'relatorios/estoque/pdf?' . htmlspecialchars($qs, ENT_QUOTES)));

    $r = rota('GET', '/relatorios/estoque/pdf?' . $qs, [], $userId);
    $bin = (string) $r['raw'];
    $texto = str_starts_with($bin, '%PDF-') ? textoPdf($bin) : null;
    if ($texto === null) {
        ok('PDF: gerado e legível pelo Ghostscript', false, str_starts_with($bin, '%PDF-') ? 'Ghostscript indisponível' : substr($bin, 0, 200));
    } else {
        ok('PDF: cabeçalhos "GTIN da unidade" / "individual" e "GTIN do pacote"',
            str_contains($texto, 'GTIN da unidade') && str_contains($texto, 'individual') && str_contains($texto, 'GTIN do pacote'));
        $linhasDoc = linhasPdf($texto, $prefixo);
        ok('PDF: mesmas linhas, ordem e GTINs da tela e do banco (códigos inteiros, vazio = —)', $linhasDoc === $esp, json_encode($linhasDoc));
        ok('PDF: página em paisagem', preg_match('#/MediaBox \[0(\.0+)? 0(\.0+)? 841\.89\d* 595\.27\d*\]#', $bin) === 1);
    }

    $filtrado = $filtrosBase + [];
    $filtrado['codigo'] = '-PC';
    $espF = $esperado($filtrado);
    $r = rota('GET', '/relatorios/estoque?' . $qs . '&codigo=-PC', [], $userId);
    ok('filtro de código na tela: só a variação filtrada, com seus GTINs', $semProduto(linhasTela((string) $r['html'])) === $espF
        && $espF === [[$prefixo . '-PC', '—', $g[1]]], json_encode(linhasTela((string) $r['html'])));
    $r = rota('GET', '/relatorios/estoque/pdf?' . $qs . '&codigo=-PC', [], $userId);
    $textoF = str_starts_with((string) $r['raw'], '%PDF-') ? textoPdf((string) $r['raw']) : null;
    ok('filtro de código no PDF: mesma linha da tela e filtro informado', $textoF !== null
        && linhasPdf($textoF, $prefixo) === $espF && str_contains($textoF, 'Código: -PC'));

    Variacao::setEstoque($vDois, 0);
    $filtrado = $filtrosBase;
    $filtrado['situacao'] = 'zerado';
    $espZ = $esperado($filtrado);
    $r = rota('GET', '/relatorios/estoque?' . $qs . '&situacao=zerado', [], $userId);
    $r2 = rota('GET', '/relatorios/estoque/pdf?' . $qs . '&situacao=zerado', [], $userId);
    $textoZ = str_starts_with((string) $r2['raw'], '%PDF-') ? textoPdf((string) $r2['raw']) : null;
    ok('filtro de situação: tela e PDF iguais ao banco, sem as variações com estoque',
        count($espZ) === 1 && $espZ[0][0] === $prefixo . '-DOIS'
        && $semProduto(linhasTela((string) $r['html'])) === $espZ && $textoZ !== null && linhasPdf($textoZ, $prefixo) === $espZ, json_encode($espZ));

    // ================= 6. Produto pai sem GTIN =================
    foreach ([['/produtos/novo', 'formulário do produto (novo)', 'name="codigo_interno"'],
              ['/produtos/' . $produtoId . '/editar', 'formulário do produto (edição)', 'value="' . $prefixo . '"'],
              ['/produtos/' . $produtoId, 'detalhes do produto', $prefixo . '-DOIS']] as [$uri, $tela, $marcador]) {
        $r = rota('GET', $uri, [], $userId);
        // No CLI, http_response_code() devolve false quando a página não define status (200).
        ok("produto pai: {$tela} sem campo ou texto GTIN", in_array($r['code'] ?? null, [false, 200], true)
            && str_contains((string) $r['html'], $marcador) && stripos((string) $r['html'], 'gtin') === false
            && !str_contains((string) $r['html'], $g[2]),
            'marcador=' . (str_contains((string) $r['html'], $marcador) ? 'sim' : 'não') . ' gtin=' . (stripos((string) $r['html'], 'gtin') === false ? 'não' : 'sim'));
    }
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($produtoId > 0) {
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $produtoId]);
    }
    if (is_file($runner)) {
        unlink($runner);
    }
    if (is_dir($sessDir)) {
        foreach (glob($sessDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($sessDir);
    }
}

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-GTN-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-GTN-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada pelo teste', md5Sql(SQL_SIG_ESTRUTURA) === $sigEstrutura);
ok('variações preexistentes inalteradas (inclusive sem GTIN)', md5Sql(SQL_SIG_VARIACOES_EXISTENTES) === $sigVariacoes);
ok('produtos preexistentes inalterados', md5Sql(SQL_SIG_PRODUTOS_EXISTENTES) === $sigProdutos);
ok('composições inalteradas', md5Sql(SQL_SIG_COMPOSICAO) === $sigComposicao);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

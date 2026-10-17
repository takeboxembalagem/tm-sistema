<?php
/**
 * Testes locais — PDFs do Estoque (inventário e movimentações de peças e papéis) e dos relatórios de estoque.
 * Chama as rotas reais (subprocesso e um servidor PHP temporário para os cabeçalhos HTTP), confere o conteúdo
 * do PDF pelo texto extraído com o Ghostscript, filtros, páginas extensas, acentos, logos, permissões,
 * erros sem detalhes internos e que nenhum saldo, movimento ou cadastro muda ao gerar os documentos.
 * Cria peças, papéis, movimentos e usuários temporários (TSTPDF-* / tst-pdf-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_pdfs_estoque_relatorios.php [--manter-pdfs]
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
$avisos = 0;

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

function aviso(string $texto): void
{
    global $avisos;
    $avisos++;
    echo "[AVISO] {$texto}" . PHP_EOL;
}

$pdo = Database::pdo();
$conexao = $pdo->query('SELECT DATABASE() AS banco, @@port AS porta')->fetch();
if ($conexao['banco'] !== 'tm_sistema_dev' || (int) $conexao['porta'] !== 3308) {
    exit('ABORTADO: o teste só roda no banco local tm_sistema_dev (127.0.0.1:3308).' . PHP_EOL);
}
$pdo->exec('SET SESSION group_concat_max_len = 67108864');

$manterPdfs = in_array('--manter-pdfs', $argv, true);
$sufixo = date('His') . random_int(100, 999);
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_pdf_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$routerHttp = $tmpDir . DIRECTORY_SEPARATOR . 'router_http.php';
$usuariosTmp = [];
$pecasTmp = [];
$papeisTmp = [];
$servidor = null;

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";

/** Estoque inteiro (inclui os dados temporários): não pode mudar ao gerar PDFs. */
const SQL_SIG_ESTOQUE = "SELECT MD5(CONCAT_WS('#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo, nome, quantidade, status, updated_at) ORDER BY id) FROM estoque_pecas), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo, nome, quantidade, status, updated_at) ORDER BY id) FROM estoque_papeis), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, peca_id, tipo, quantidade, IFNULL(saldo_anterior, 'N'), saldo_apos, created_at) ORDER BY id) FROM estoque_pecas_movimentos), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, papel_id, tipo, quantidade, IFNULL(saldo_anterior, 'N'), saldo_apos, created_at) ORDER BY id) FROM estoque_papeis_movimentos), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, estoque, status, updated_at) ORDER BY id) FROM produto_variacoes), ''),
        (SELECT COUNT(*) FROM logs_alteracoes)))";

/** Dados permanentes (fora dos temporários do teste): iguais antes e depois do teste. */
const SQL_SIG_PERMANENTE = "SELECT MD5(CONCAT_WS('#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo, quantidade, updated_at) ORDER BY id) FROM estoque_pecas WHERE codigo NOT LIKE 'TSTPDF-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo, quantidade, updated_at) ORDER BY id) FROM estoque_papeis WHERE codigo NOT LIKE 'TSTPDF-%'), ''),
        (SELECT COUNT(*) FROM estoque_pecas_movimentos m JOIN estoque_pecas p ON p.id = m.peca_id WHERE p.codigo NOT LIKE 'TSTPDF-%'),
        (SELECT COUNT(*) FROM estoque_papeis_movimentos m JOIN estoque_papeis p ON p.id = m.papel_id WHERE p.codigo NOT LIKE 'TSTPDF-%'),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, estoque, updated_at) ORDER BY id) FROM produto_variacoes), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, email, perfil, status) ORDER BY id) FROM usuarios WHERE email NOT LIKE 'tst-pdf-%'), ''),
        (SELECT COUNT(*) FROM logs_alteracoes)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();

/** Arquivos temporários de logo deixados na pasta do sistema (Windows: pm_XXXX.tmp; outros: pm_logo_XXXX). */
function logosTemporarias(): int
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    return count(array_merge(glob($dir . 'pm_logo_*') ?: [], glob($dir . 'pm_*.tmp') ?: []));
}

/** Executa a rota real num subprocesso; $userId = 0 → sem login. Devolve corpo, status HTTP e Location. */
function rota(string $uri, int $userId): array
{
    global $runner, $sessDir, $tmpDir;
    $req = ['uri' => $uri, 'user_id' => $userId, 'sess_dir' => $sessDir, 'root' => dirname(__DIR__)];
    // Saída em arquivo: shell_exec no Windows lê em modo texto e corta o PDF no byte 0x1A.
    $saida = $tmpDir . DIRECTORY_SEPARATOR . 'saida.bin';
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
        return ['corpo' => $out, 'status' => null, 'location' => null];
    }
    $res = json_decode(substr($out, $pos + 10), true) ?: [];
    $res['corpo'] = substr($out, 0, $pos);
    $res['status'] = ($res['status'] ?? false) === false ? 200 : (int) $res['status'];
    return $res;
}

function ehPdf(string $bin): bool
{
    return str_starts_with($bin, '%PDF-') && str_contains(substr($bin, -32), '%%EOF');
}

function paginas(string $bin): int
{
    return preg_match_all('#/Type /Page[^s]#', $bin);
}

function ghostscript(): ?string
{
    static $gs = false;
    if ($gs !== false) {
        return $gs;
    }
    $candidatos = array_merge(
        glob('C:/Program Files/gs/*/bin/gswin64c.exe') ?: [],
        glob('C:/Program Files (x86)/gs/*/bin/gswin32c.exe') ?: [],
        ['/usr/bin/gs', '/usr/local/bin/gs']
    );
    foreach ($candidatos as $c) {
        if (is_file($c)) {
            return $gs = $c;
        }
    }
    return $gs = null;
}

/** Texto do PDF extraído pelo Ghostscript (espaços normalizados); null se o Ghostscript não estiver disponível. */
function textoPdf(string $bin, string $nome): ?string
{
    global $tmpDir;
    $arquivo = $tmpDir . DIRECTORY_SEPARATOR . $nome . '.pdf';
    file_put_contents($arquivo, $bin);
    $gs = ghostscript();
    if ($gs === null) {
        return null;
    }
    $txt = $tmpDir . DIRECTORY_SEPARATOR . $nome . '.txt';
    shell_exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -o ' . escapeshellarg($txt) . ' ' . escapeshellarg($arquivo) . ' 2>&1');
    $texto = is_file($txt) ? (string) file_get_contents($txt) : '';
    @unlink($txt);
    return (string) preg_replace('/[ \t]+/u', ' ', $texto);
}

/** Gera o PDF pela rota e devolve [resposta, texto]. */
function pdf(string $uri, int $userId, string $nome): array
{
    $r = rota($uri, $userId);
    $texto = ($r['status'] === 200 && ehPdf($r['corpo'])) ? textoPdf($r['corpo'], $nome) : null;
    return [$r, $texto];
}

function contem(?string $texto, string ...$trechos): bool
{
    if ($texto === null) {
        return true;
    }
    foreach ($trechos as $t) {
        if (!str_contains($texto, $t)) {
            return false;
        }
    }
    return true;
}

function naoContem(?string $texto, string ...$trechos): bool
{
    if ($texto === null) {
        return true;
    }
    foreach ($trechos as $t) {
        if (str_contains($texto, $t)) {
            return false;
        }
    }
    return true;
}

function erroInterno(string $corpo): bool
{
    $corpo = preg_replace('/Warning:\s*Module "mysqli" is already loaded in Unknown on line 0\s*/i', '', $corpo) ?? $corpo;
    return (bool) preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|SQLSTATE|PDOException|TCPDF ERROR)/i', $corpo);
}

function novoUsuario(string $perfil, string $tag): int
{
    global $usuariosTmp, $sufixo;
    $id = Usuario::create([
        'nome' => 'TST PDF ' . $tag,
        'email' => 'tst-pdf-' . $sufixo . '-' . $tag . '@teste.local',
        'senha_hash' => Usuario::hashSenha('senha-' . $tag . '-' . $sufixo),
        'status' => 'ativo',
        'perfil' => $perfil,
    ]);
    $usuariosTmp[] = $id;
    return $id;
}

function novaPeca(string $codigo, string $nome, string $tipo, float $qtd, array $extra = []): int
{
    global $pecasTmp;
    $id = EstoquePeca::create($extra + [
        'codigo' => $codigo, 'nome' => $nome, 'tipo' => $tipo, 'produto_id' => null, 'cor' => null, 'estampa' => null,
        'quantidade' => $qtd, 'unidade' => 'un', 'localizacao' => null, 'imagem' => null, 'observacao' => null, 'status' => 'ativo',
    ], null);
    $pecasTmp[] = $id;
    return $id;
}

function novoPapel(string $codigo, string $nome, float $qtd, array $extra = []): int
{
    global $papeisTmp;
    $id = EstoquePapel::create($extra + [
        'codigo' => $codigo, 'nome' => $nome, 'cor' => null, 'formato' => null, 'gramatura' => null,
        'quantidade' => $qtd, 'unidade' => 'folha', 'localizacao' => null, 'imagem' => null, 'observacao' => null, 'status' => 'ativo',
    ], null);
    $papeisTmp[] = $id;
    return $id;
}

/** GET HTTP no servidor temporário (cabeçalhos reais). */
function http(string $uri, int $userId): array
{
    global $porta;
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'X-Pm-Test-User: ' . $userId,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 60,
    ]]);
    $corpo = @file_get_contents('http://127.0.0.1:' . $porta . $uri, false, $ctx);
    $cab = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $linha) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $linha, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($linha, ':')) {
            [$k, $v] = explode(':', $linha, 2);
            $cab[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'cabecalhos' => $cab, 'corpo' => (string) $corpo];
}

$logosAntes = logosTemporarias();

try {
    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
$q = parse_url($req['uri'], PHP_URL_QUERY);
$_GET = [];
if (is_string($q)) {
    parse_str($q, $_GET);
}
ob_start();
require $req['root'] . '/app/bootstrap.php';
if ((int) $req['user_id'] > 0) {
    $_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
register_shutdown_function(static function (): void {
    $corpo = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    $location = null;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    echo $corpo . '@@RESULT@@' . json_encode(['status' => http_response_code(), 'location' => $location]);
});
require $req['root'] . '/app/routes.php';
PHP);

    // ---------------------------------------------------------------- 1. Logos e componente
    $raiz = dirname(__DIR__);
    ok('1. Logos PM/Takebox não fazem parte do repositório T&M',
        !is_file($raiz . '/public/assets/img/logo-papel-magenta.png')
        && !is_file($raiz . '/public/assets/img/takebox-logo.png'));

    $docPm = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Teste área T&M');
    $binPm = $docPm->gerar();
    $txtPm = textoPdf($binPm, 'componente-tm');
    ok('1. Documento T&M usa texto no cabeçalho (sem embutir logo de outra marca)',
        ehPdf($binPm) && !str_contains($binPm, '/Subtype /Image') && contem($txtPm, 'T&M', 'Teste área T&M'));
    $docPmPaisagem = new DocumentoPdf(Areas::PAPEL_MAGENTA, 'Teste paisagem', DocumentoPdf::PAISAGEM);
    $binPmPaisagem = $docPmPaisagem->gerar();
    ok('1. Documento T&M em paisagem (texto)', ehPdf($binPmPaisagem) && !str_contains($binPmPaisagem, '/Subtype /Image'));
    $areaInvalida = false;
    try {
        new DocumentoPdf('takebox', 'X');
    } catch (InvalidArgumentException $e) {
        $areaInvalida = true;
    }
    ok('1. Área sem identidade visual é recusada', $areaInvalida);
    ok('1. Nenhuma logo temporária fica na pasta do sistema', logosTemporarias() === $logosAntes);

    // ---------------------------------------------------------------- 2. Dados temporários
    $admin = novoUsuario('admin', 'admin');
    $usuario = novoUsuario('usuario', 'usuario');
    $pdo->prepare('UPDATE usuarios SET nome = :n WHERE id = :id')->execute(['n' => 'Usuário Ação Teste', 'id' => $usuario]);

    $tiposCiclo = ['tampa' => 'T', 'fundo' => 'F', 'outra' => 'O'];
    $chavesTipo = array_keys($tiposCiclo);
    for ($i = 1; $i <= 66; $i++) {
        $tipo = $chavesTipo[$i % 3];
        $qtd = $i % 5 === 0 ? 0 : $i * 3;
        novaPeca(
            sprintf('TSTPDF-%s-%03d', $tiposCiclo[$tipo], $i),
            'Peça teste ' . $i . ($i % 11 === 0 ? ' com nome longo para conferir a quebra de linha na célula da tabela do PDF' : ''),
            $tipo,
            $qtd,
            ['cor' => $i % 2 ? 'Azul' : 'Vermelho', 'localizacao' => 'Prateleira ' . (($i % 4) + 1)]
        );
    }
    $pecaAcento = novaPeca('TSTPDF-T-900', 'Tampa Coração Ação Çedilha — “Ñandú” ½', 'tampa', 1234, ['cor' => 'Lilás', 'estampa' => 'Pássaros', 'localizacao' => 'Depósito Água']);
    $pecaInativa = novaPeca('TSTPDF-F-901', 'Fundo inativo', 'fundo', 7);
    EstoquePeca::setStatus($pecaInativa, 'inativo');
    $pecaZero = novaPeca('TSTPDF-O-902', 'Peça sem saldo', 'outra', 0);
    $pecaHist = novaPeca('TSTPDF-T-903', 'Peça com histórico longo', 'tampa', 1000);

    EstoquePeca::registrarMovimento($pecaAcento, 'saida', 34, 'TSTPDF-OBS-SAIDA retirada para produção', $usuario);
    EstoquePeca::registrarMovimento($pecaAcento, 'ajuste', 1200, 'TSTPDF-OBS-AJUSTE contagem física', $admin);
    EstoquePeca::registrarMovimento($pecaAcento, 'entrada', 50, 'TSTPDF-OBS-ENTRADA compra', $usuario);
    for ($i = 1; $i <= 240; $i++) {
        EstoquePeca::registrarMovimento($pecaHist, $i % 2 ? 'saida' : 'entrada', 1, 'TSTPDF-HIST ' . $i, $admin);
    }
    // Movimento antigo (mês anterior) para conferir o período padrão e "todo o histórico".
    $pecaAntiga = novaPeca('TSTPDF-F-904', 'Peça movimento antigo', 'fundo', 5);
    $mesPassado = date('Y-m-d', strtotime('first day of last month')) . ' 10:00:00';
    $dataMesPassado = substr($mesPassado, 0, 10);
    $pdo->prepare("UPDATE estoque_pecas_movimentos SET observacao = 'TSTPDF-ANTIGO', created_at = :d WHERE peca_id = :id")
        ->execute(['d' => $mesPassado, 'id' => $pecaAntiga]);

    $papelA = novoPapel('TSTPDF-PA-001', 'Papel Kraft Ação', 500, ['cor' => 'Pardo', 'formato' => '66×96', 'gramatura' => '180 g/m²', 'localizacao' => 'Mezanino']);
    $papelB = novoPapel('TSTPDF-PA-002', 'Papel sem saldo', 0, ['cor' => 'Branco', 'formato' => 'A4', 'gramatura' => '90 g/m²']);
    $papelC = novoPapel('TSTPDF-PA-003', 'Papel em pacotes', 12, ['unidade' => 'pacote', 'cor' => 'Azul']);
    EstoquePapel::registrarMovimento($papelA, 'saida', 120, 'TSTPDF-PAPEL-SAIDA corte', $usuario);
    EstoquePapel::registrarMovimento($papelC, 'ajuste', 10, 'TSTPDF-PAPEL-AJUSTE', $admin);
    $pdo->prepare("UPDATE estoque_papeis_movimentos SET observacao = 'TSTPDF-PAPEL-ANTIGO', created_at = :d WHERE papel_id = :id AND tipo = 'entrada'")
        ->execute(['d' => $mesPassado, 'id' => $papelA]);

    ok('2. Dados temporários criados', count($pecasTmp) === 71 && count($papeisTmp) === 3);
    $sigEstoque = (string) $pdo->query(SQL_SIG_ESTOQUE)->fetchColumn();

    // ---------------------------------------------------------------- 3. Telas com as opções de PDF
    $r = rota('/estoque/pecas', $usuario);
    ok('3. Estoque de peças mostra "Gerar PDF do inventário" e o formulário de movimentações (mês atual)',
        $r['status'] === 200 && str_contains($r['corpo'], '/estoque/pecas/pdf/inventario') && str_contains($r['corpo'], '/estoque/pecas/pdf/movimentacoes')
        && str_contains($r['corpo'], 'value="' . date('Y-m-01') . '"') && str_contains($r['corpo'], 'value="' . date('Y-m-t') . '"')
        && str_contains($r['corpo'], 'name="todo"') && str_contains($r['corpo'], 'TSTPDF-T-900') && !erroInterno($r['corpo']));
    $r = rota('/estoque/papeis', $usuario);
    ok('3. Estoque de papéis mostra as duas opções de PDF',
        $r['status'] === 200 && str_contains($r['corpo'], '/estoque/papeis/pdf/inventario') && str_contains($r['corpo'], '/estoque/papeis/pdf/movimentacoes')
        && str_contains($r['corpo'], 'TSTPDF-PA-001') && !erroInterno($r['corpo']));
    $r = rota('/relatorios/estoque?aba=pecas&tipo=tampa&cor=Azul', $usuario);
    ok('3. Relatório mostra "Gerar PDF" levando os filtros aplicados',
        $r['status'] === 200 && str_contains($r['corpo'], 'relatorios/estoque/pdf?aba=pecas&amp;tipo=tampa&amp;cor=Azul'));
    $r = rota('/relatorios/estoque?aba=data', $usuario);
    ok('3. Posição por data sem consulta não oferece PDF', $r['status'] === 200 && !str_contains($r['corpo'], 'relatorios/estoque/pdf'));
    $r = rota('/relatorios/estoque?aba=data&data=' . date('Y-m-d'), $usuario);
    ok('3. Posição por data consultada oferece PDF', $r['status'] === 200 && str_contains($r['corpo'], 'relatorios/estoque/pdf?aba=data&amp;data=' . date('Y-m-d')));
    foreach (['variacoes', 'pecas', 'papeis', 'movimentacoes'] as $aba) {
        $r = rota('/relatorios/estoque?aba=' . $aba, $usuario);
        ok("3. Tela do relatório '{$aba}' continua funcionando e oferece PDF", $r['status'] === 200 && str_contains($r['corpo'], 'relatorios/estoque/pdf?aba=' . $aba) && !erroInterno($r['corpo']));
    }

    // ---------------------------------------------------------------- 4. Inventário de peças
    [$r, $t] = pdf('/estoque/pecas/pdf/inventario', $usuario, 'inventario-pecas');
    ok('4. Inventário de peças: PDF válido', $r['status'] === 200 && ehPdf($r['corpo']), 'status=' . $r['status']);
    ok('4. Inventário de peças: várias páginas, A4 paisagem', paginas($r['corpo']) >= 2 && str_contains($r['corpo'], '/MediaBox [0.000000 0.000000 841.890000 595.276000]'), 'páginas=' . paginas($r['corpo']));
    $faltando = [];
    foreach ($pdo->query("SELECT codigo FROM estoque_pecas WHERE codigo LIKE 'TSTPDF-%'")->fetchAll(PDO::FETCH_COLUMN) as $cod) {
        if (!contem($t, $cod)) {
            $faltando[] = $cod;
        }
    }
    ok('4. Inventário de peças lista todas as peças (inclusive sem saldo e inativas)', $faltando === [], implode(',', array_slice($faltando, 0, 5)));
    ok('4. Inventário de peças: título, abrangência, contagem física, acentos e quantidades',
        contem($t, 'Inventário de Peças', 'Filtros aplicados', 'inclusive sem saldo', 'Contagem física', 'Tampa Coração Ação Çedilha', '“Ñandú” ½', 'Lilás', 'Pássaros', 'Depósito Água', '1.250', 'Inativo'));
    $ind = RelatorioEstoque::pecas(['tipo' => '', 'produto_id' => null, 'cor' => '', 'estampa' => '', 'local' => '', 'status' => '', 'situacao' => ''])['indicadores'];
    ok('4. Inventário de peças: totais iguais aos do relatório',
        contem($t, 'Itens cadastrados', 'Com estoque', 'Zerados', 'Quantidade total') && contem($t, number_br($ind['total'], 0) . ' item(ns)'),
        "total={$ind['total']}");
    ok('4. Cabeçalho da tabela repetido em cada página', $t === null || substr_count($t, 'Código') >= paginas($r['corpo']));
    ok('4. Numeração de páginas', contem($t, 'Página 1 de ' . paginas($r['corpo'])));

    // ---------------------------------------------------------------- 5. Inventário de papéis
    [$r, $t] = pdf('/estoque/papeis/pdf/inventario', $usuario, 'inventario-papeis');
    ok('5. Inventário de papéis: PDF válido', $r['status'] === 200 && ehPdf($r['corpo']));
    ok('5. Inventário de papéis: todos os papéis, campos reais e unidades',
        contem($t, 'Inventário de Papéis', 'TSTPDF-PA-001', 'TSTPDF-PA-002', 'TSTPDF-PA-003', 'Papel Kraft Ação', '66×96', '180 g/m²', 'Mezanino', 'pacote', 'folha'));
    ok('5. Inventário de papéis: unidades diferentes não são somadas (regra da tela)', contem($t, 'Unidades diferentes'));

    // ---------------------------------------------------------------- 6. Movimentações de peças
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes', $usuario, 'mov-pecas-padrao');
    ok('6. Movimentações de peças (padrão): PDF válido', $r['status'] === 200 && ehPdf($r['corpo']));
    ok('6. Período padrão = mês atual, informado no documento',
        contem($t, 'Movimentações de Peças', 'Período: ' . date('01/m/Y') . ' a ' . date('t/m/Y'), 'Peça: Todas as peças', 'Tipo: Todos'));
    ok('6. Padrão inclui movimentos do mês e exclui o do mês anterior',
        contem($t, 'TSTPDF-OBS-SAIDA', 'TSTPDF-OBS-AJUSTE', 'TSTPDF-OBS-ENTRADA', 'Usuário Ação Teste') && naoContem($t, 'TSTPDF-ANTIGO'));
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes?todo=1&inicio=' . date('Y-m-d') . '&fim=' . date('Y-m-d'), $usuario, 'mov-pecas-todo');
    ok('6. Todo o histórico ignora as datas e inclui o movimento antigo', $r['status'] === 200 && contem($t, 'todo o histórico', 'TSTPDF-ANTIGO', DocumentoPdf::data($dataMesPassado)));
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes?inicio=' . $dataMesPassado . '&fim=' . $dataMesPassado, $usuario, 'mov-pecas-periodo');
    ok('6. Período escolhido: só o dia do movimento antigo', $r['status'] === 200 && contem($t, 'TSTPDF-ANTIGO') && naoContem($t, 'TSTPDF-OBS-SAIDA'));
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes?item_id=' . $pecaAcento, $usuario, 'mov-pecas-item');
    ok('6. Peça específica: só os movimentos dela, com o nome no filtro',
        $r['status'] === 200 && contem($t, 'Peça: TSTPDF-T-900 — Tampa Coração', 'TSTPDF-OBS-SAIDA', 'Estoque inicial') && naoContem($t, 'TSTPDF-HIST', 'TSTPDF-T-903'));
    ok('6. Valores do movimento: quantidade com unidade, saldos anterior/depois',
        contem($t, '34 un', '1.234', '1.200', '1.250'));
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes?tipo=ajuste&item_id=' . $pecaAcento, $usuario, 'mov-pecas-tipo');
    ok('6. Tipo de movimentação: só ajustes, com a contagem por tipo',
        $r['status'] === 200 && contem($t, 'Tipo: Ajuste', 'TSTPDF-OBS-AJUSTE') && naoContem($t, 'TSTPDF-OBS-SAIDA', 'TSTPDF-OBS-ENTRADA'));
    [$r, $t] = pdf('/estoque/pecas/pdf/movimentacoes?todo=1&item_id=' . $pecaHist, $usuario, 'mov-pecas-longo');
    $pags = paginas($r['corpo']);
    ok('6. Histórico extenso (241 movimentos): várias páginas, cabeçalho repetido, ordem cronológica',
        $r['status'] === 200 && $pags >= 5 && ($t === null || (substr_count($t, 'Observação') >= $pags
            && strpos($t, 'TSTPDF-HIST 1 ') < strpos($t, 'TSTPDF-HIST 240'))), "páginas={$pags}");
    ok('6. Resumo do histórico extenso: 241 movimentações (120 entradas, 120 saídas + inicial)', contem($t, 'Movimentações', '241', '121', '120'));

    foreach ([
        'inicio=2026-13-01' => 'Informe datas válidas',
        'inicio=' . date('Y-m-d') . '&fim=' . date('Y-m-d', strtotime('-1 day')) => 'A data inicial não pode ser posterior',
        'item_id=2147483000' => 'Peça não encontrada',
        'item_id=abc' => 'Peça não encontrada',
        'tipo=transferencia' => 'Tipo de movimentação inválido',
    ] as $qs => $msg) {
        $r = rota('/estoque/pecas/pdf/movimentacoes?' . $qs, $usuario);
        ok("6. Filtro inválido ({$qs}) → 422 com mensagem clara, sem PDF nem detalhes internos",
            $r['status'] === 422 && str_contains($r['corpo'], $msg) && !ehPdf($r['corpo']) && !erroInterno($r['corpo']), 'status=' . $r['status']);
    }
    $r = rota('/estoque/pecas/pdf/movimentacoes?item_id=' . $papelA . '&todo=1', $usuario);
    ok('6. Id de papel no PDF de peças não mistura estoques', $r['status'] === 422 || ($r['status'] === 200 && naoContem(textoPdf($r['corpo'], 'mov-cruzado'), 'TSTPDF-PA-')));
    $lim = RelatorioEstoque::historicoMovimentos('pecas', ['inicio' => null, 'fim' => null, 'item_id' => $pecaHist, 'tipo' => ''], 5);
    ok('6. Consulta com limite devolve limite + 1 (para recusar PDFs grandes demais)', count($lim) === 6);

    // ---------------------------------------------------------------- 7. Movimentações de papéis
    [$r, $t] = pdf('/estoque/papeis/pdf/movimentacoes', $usuario, 'mov-papeis-padrao');
    ok('7. Movimentações de papéis (padrão): mês atual, sem o movimento antigo',
        $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Movimentações de Papéis', 'Papel: Todos os papéis', 'TSTPDF-PAPEL-SAIDA', 'TSTPDF-PAPEL-AJUSTE', '120 folha')
        && naoContem($t, 'TSTPDF-PAPEL-ANTIGO'));
    [$r, $t] = pdf('/estoque/papeis/pdf/movimentacoes?todo=1&item_id=' . $papelA . '&tipo=entrada', $usuario, 'mov-papeis-filtros');
    ok('7. Papel específico + tipo + todo o histórico', $r['status'] === 200 && contem($t, 'Papel: TSTPDF-PA-001 — Papel Kraft Ação', 'Tipo: Entrada', 'TSTPDF-PAPEL-ANTIGO')
        && naoContem($t, 'TSTPDF-PAPEL-SAIDA', 'TSTPDF-PA-003'));
    $r = rota('/estoque/papeis/pdf/movimentacoes?item_id=2147483000', $usuario);
    ok('7. Papel inexistente → 422', $r['status'] === 422 && str_contains($r['corpo'], 'Papel não encontrado'));

    // ---------------------------------------------------------------- 8. Relatórios (todas as abas)
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=variacoes', $usuario, 'rel-variacoes');
    $indV = RelatorioEstoque::variacoes(['produto_id' => null, 'variacao' => '', 'codigo' => '', 'status' => '', 'colecao_id' => null, 'situacao' => ''])['indicadores'];
    ok('8. Estoque de Variações: PDF com título, indicadores e regra de pacotes',
        $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Estoque de Variações', 'Variações cadastradas', 'Estoque baixo (até ' . Variacao::ESTOQUE_BAIXO . ')',
            'Pacotes = pacotes completos', number_br($indV['total'], 0) . ' variação(ões)', 'Nenhum filtro'));
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=variacoes&status=inativo&situacao=baixo&codigo=' . rawurlencode('nada-assim-TSTPDF'), $usuario, 'rel-variacoes-filtros');
    ok('8. Estoque de Variações: filtros exibidos e respeitados', $r['status'] === 200 && contem($t, 'Status: Inativas', 'Situação: Baixo', 'Código: nada-assim-TSTPDF', 'Nenhuma variação encontrada'));

    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=pecas&tipo=tampa&situacao=zerado', $usuario, 'rel-pecas');
    $res = RelatorioEstoque::pecas(['tipo' => 'tampa', 'produto_id' => null, 'cor' => '', 'estampa' => '', 'local' => '', 'status' => '', 'situacao' => 'zerado']);
    $codigosEsperados = array_column($res['linhas'], 'codigo');
    $tst = array_values(array_filter($codigosEsperados, static fn ($c) => str_starts_with($c, 'TSTPDF-')));
    ok('8. Estoque de Peças: filtros tipo+situação exibidos', $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Estoque de Peças', 'Tipo: Tampa', 'Situação: Zerado'));
    ok('8. Estoque de Peças: exatamente as linhas da consulta da tela', $tst !== [] && contem($t, ...$tst) && naoContem($t, 'TSTPDF-F-', 'TSTPDF-O-', 'TSTPDF-T-900')
        && contem($t, count($res['linhas']) . ' peça(s) listada(s)'), count($res['linhas']) . ' linhas');
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=pecas&cor=' . rawurlencode('Lilás') . '&estampa=' . rawurlencode('Pássaros') . '&local=' . rawurlencode('Água') . '&status=ativo', $usuario, 'rel-pecas-texto');
    ok('8. Estoque de Peças: filtros de texto acentuados', $r['status'] === 200 && contem($t, 'Cor: Lilás', 'Estampa: Pássaros', 'Local: Água', 'Status: Ativos', 'TSTPDF-T-900', '1 peça(s) listada(s)'));

    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=papeis&situacao=com_estoque', $usuario, 'rel-papeis');
    ok('8. Estoque de Papéis: filtro de situação e linhas corretas',
        $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Estoque de Papéis', 'Situação: Com estoque', 'TSTPDF-PA-001', 'TSTPDF-PA-003') && naoContem($t, 'TSTPDF-PA-002'));
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=papeis&formato=A4&gramatura=90', $usuario, 'rel-papeis-texto');
    ok('8. Estoque de Papéis: formato e gramatura', $r['status'] === 200 && contem($t, 'Formato: A4', 'Gramatura: 90', 'TSTPDF-PA-002') && naoContem($t, 'TSTPDF-PA-001'));

    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=movimentacoes&estoque=papeis&tipo=saida', $usuario, 'rel-mov');
    ok('8. Movimentações de Peças e Papéis: estoque + tipo',
        $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Movimentações de Peças e Papéis', 'Estoque: Papéis', 'Tipo: Saída', 'TSTPDF-PAPEL-SAIDA', 'Mais recentes primeiro')
        && naoContem($t, 'TSTPDF-OBS-SAIDA', 'TSTPDF-PAPEL-AJUSTE'));
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=movimentacoes&usuario_id=' . $usuario . '&item=' . rawurlencode('TSTPDF-T-900') . '&inicio=' . date('Y-m-01') . '&fim=' . date('Y-m-t'), $usuario, 'rel-mov-usuario');
    ok('8. Movimentações: usuário, item e período exibidos e respeitados',
        $r['status'] === 200 && contem($t, 'Usuário: Usuário Ação Teste', 'Item: TSTPDF-T-900', 'Período: ' . date('01/m/Y'), 'TSTPDF-OBS-SAIDA', 'TSTPDF-OBS-ENTRADA')
        && naoContem($t, 'TSTPDF-OBS-AJUSTE'));
    $pagina = RelatorioEstoque::movimentacoes(['inicio' => null, 'fim' => null, 'estoque' => '', 'tipo' => '', 'item' => 'TSTPDF', 'usuario_id' => null], 1, 50);
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=movimentacoes&item=TSTPDF', $usuario, 'rel-mov-todas');
    ok('8. Movimentações: PDF traz todas as linhas (sem a paginação de 50 da tela)',
        $r['status'] === 200 && $pagina['total'] > 50 && contem($t, number_br($pagina['total'], 0) . ' movimentação(ões) encontrada(s)', 'TSTPDF-HIST 1 '), 'total=' . $pagina['total']);
    $r = rota('/relatorios/estoque/pdf?aba=movimentacoes&item=' . rawurlencode("O'Brien\" OR 1=1 -- <script>"), $usuario);
    ok('8. Movimentações: texto com aspas/SQL no filtro é tratado como texto', $r['status'] === 200 && ehPdf($r['corpo']));

    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=data&data=' . $dataMesPassado . '&estoque=pecas', $usuario, 'rel-data');
    ok('8. Posição do Estoque por Data: data, estoque e item que existia na data',
        $r['status'] === 200 && ehPdf($r['corpo']) && contem($t, 'Posição do Estoque por Data', 'Data de referência: ' . DocumentoPdf::data($dataMesPassado),
            'Posição em ' . DocumentoPdf::data($dataMesPassado), 'TSTPDF-F-904', 'Itens criados depois dessa data não aparecem') && naoContem($t, 'TSTPDF-T-900', 'TSTPDF-PA-001'));
    [$r, $t] = pdf('/relatorios/estoque/pdf?aba=data&data=' . date('Y-m-d') . '&local=' . rawurlencode('Mezanino'), $usuario, 'rel-data-local');
    ok('8. Posição por data: filtro de local', $r['status'] === 200 && contem($t, 'Local: Mezanino', 'TSTPDF-PA-001', '380 folha') && naoContem($t, 'TSTPDF-T-900'));
    foreach (['aba=data' => 422, 'aba=data&data=2026-02-30' => 422, 'aba=xyz' => 404, '' => 404] as $qs => $esperado) {
        $r = rota('/relatorios/estoque/pdf?' . $qs, $usuario);
        ok("8. Relatório inválido ({$qs}) → {$esperado} sem detalhes internos", $r['status'] === $esperado && !ehPdf($r['corpo']) && !erroInterno($r['corpo']), 'status=' . $r['status']);
    }

    // ---------------------------------------------------------------- 9. Permissões
    $rotasPdf = [
        '/estoque/pecas/pdf/inventario', '/estoque/pecas/pdf/movimentacoes', '/estoque/papeis/pdf/inventario',
        '/estoque/papeis/pdf/movimentacoes', '/relatorios/estoque/pdf?aba=pecas', '/relatorios/estoque/pdf?aba=movimentacoes',
    ];
    // No CLI o PHP não expõe os cabeçalhos (Location); o destino /login é conferido no teste HTTP (item 10).
    foreach ($rotasPdf as $uri) {
        $r = rota($uri, 0);
        ok("9. Sem login: {$uri} → redirecionamento (302), sem PDF", $r['status'] === 302 && !ehPdf($r['corpo']), 'status=' . $r['status']);
    }
    $r = rota('/estoque/pecas/pdf/inventario', $admin);
    ok('9. Administrador gera o PDF', $r['status'] === 200 && ehPdf($r['corpo']));
    $tela = rota('/relatorios/estoque?aba=pecas', $usuario);
    $doc = rota('/relatorios/estoque/pdf?aba=pecas', $usuario);
    ok('9. Usuário comum: mesma regra das telas (tela e PDF liberados)', $tela['status'] === 200 && $doc['status'] === 200 && ehPdf($doc['corpo']));
    $pdo->prepare("UPDATE usuarios SET status = 'inativo' WHERE id = :id")->execute(['id' => $usuario]);
    $r = rota('/estoque/pecas/pdf/inventario', $usuario);
    ok('9. Usuário inativado perde o acesso ao PDF na requisição seguinte', $r['status'] !== 200 && !ehPdf($r['corpo']), 'status=' . $r['status']);
    $pdo->prepare("UPDATE usuarios SET status = 'ativo' WHERE id = :id")->execute(['id' => $usuario]);
    $r = rota('/estoque/pecas/pdf/inventario', 2147483000);
    ok('9. Sessão de usuário inexistente não gera PDF', $r['status'] !== 200 && !ehPdf($r['corpo']));

    // ---------------------------------------------------------------- 10. Cabeçalhos HTTP reais
    file_put_contents($routerHttp, <<<'PHP'
<?php
declare(strict_types=1);
$uid = (int) ($_SERVER['HTTP_X_PM_TEST_USER'] ?? 0);
ini_set('session.save_path', getenv('PM_TEST_SESS'));
$_SERVER['SCRIPT_NAME'] = '/index.php';
require getenv('PM_TEST_ROOT') . '/app/bootstrap.php';
if ($uid > 0) {
    $_SESSION['user'] = ['id' => $uid, 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find($uid)['senha_hash'] ?? ''));
}
require getenv('PM_TEST_ROOT') . '/app/routes.php';
PHP);
    $porta = random_int(18100, 18900);
    $env = array_merge(getenv(), ['PM_TEST_SESS' => $sessDir, 'PM_TEST_ROOT' => dirname(__DIR__)]);
    $servidor = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $porta, $routerHttp],
        [0 => ['pipe', 'r'], 1 => ['file', $tmpDir . DIRECTORY_SEPARATOR . 'srv.log', 'a'], 2 => ['file', $tmpDir . DIRECTORY_SEPARATOR . 'srv.log', 'a']],
        $pipes,
        $tmpDir,
        $env
    );
    $pronto = false;
    for ($i = 0; $i < 50 && !$pronto; $i++) {
        usleep(100000);
        $s = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            $pronto = true;
        }
    }
    if (!$pronto) {
        aviso('servidor HTTP temporário não subiu; cabeçalhos não conferidos');
    } else {
        $h = http('/estoque/pecas/pdf/inventario', $usuario);
        $disp = $h['cabecalhos']['content-disposition'] ?? '';
        ok('10. HTTP: Content-Type application/pdf e corpo PDF', $h['status'] === 200 && ($h['cabecalhos']['content-type'] ?? '') === 'application/pdf' && ehPdf($h['corpo']));
        ok('10. HTTP: abre no navegador (inline) com nome de arquivo datado', (bool) preg_match('/^inline; filename="inventario-pecas-\d{8}-\d{4}\.pdf"$/', $disp), $disp);
        ok('10. HTTP: sem cache e nosniff', str_contains($h['cabecalhos']['cache-control'] ?? '', 'no-store') && ($h['cabecalhos']['x-content-type-options'] ?? '') === 'nosniff');
        ok('10. HTTP: Content-Length confere', (int) ($h['cabecalhos']['content-length'] ?? -1) === strlen($h['corpo']));
        $h = http('/relatorios/estoque/pdf?aba=movimentacoes&estoque=pecas', $usuario);
        ok('10. HTTP: relatório em PDF', $h['status'] === 200 && ($h['cabecalhos']['content-type'] ?? '') === 'application/pdf'
            && (bool) preg_match('/filename="relatorio-estoque-movimentacoes-\d{8}-\d{4}\.pdf"/', $h['cabecalhos']['content-disposition'] ?? ''));
        foreach (['/estoque/papeis/pdf/movimentacoes', '/estoque/pecas/pdf/inventario', '/relatorios/estoque/pdf?aba=variacoes'] as $uri) {
            $h = http($uri, 0);
            ok("10. HTTP: sem login {$uri} → 302 para o login", $h['status'] === 302 && str_ends_with($h['cabecalhos']['location'] ?? '', '/login') && !ehPdf($h['corpo']));
        }
        $h = http('/estoque/pecas/pdf/movimentacoes?inicio=2026-99-99', $usuario);
        ok('10. HTTP: filtro inválido → 422 em HTML', $h['status'] === 422 && str_contains($h['cabecalhos']['content-type'] ?? 'text/html', 'text/html') && !erroInterno($h['corpo']));
    }

    // ---------------------------------------------------------------- 11. Nada mudou
    ok('11. Gerar os PDFs não alterou saldos, cadastros, movimentos nem variações', (string) $pdo->query(SQL_SIG_ESTOQUE)->fetchColumn() === $sigEstoque);
    ok('11. Nenhuma logo temporária restante na pasta do sistema', logosTemporarias() === $logosAntes);
    if (ghostscript() === null) {
        aviso('Ghostscript não encontrado: conteúdo dos PDFs não conferido pelo texto (só estrutura e status).');
    }
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    if (is_resource($servidor)) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    foreach ($pecasTmp as $id) {
        EstoquePeca::delete($id);
    }
    foreach ($papeisTmp as $id) {
        EstoquePapel::delete($id);
    }
    foreach ($usuariosTmp as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $uid]);
    }
    foreach ([$runner, $routerHttp, $tmpDir . DIRECTORY_SEPARATOR . 'srv.log'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    if (is_dir($sessDir)) {
        foreach (glob($sessDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($sessDir);
    }
    if ($manterPdfs) {
        echo PHP_EOL . 'PDFs mantidos para conferência visual em: ' . $tmpDir . PHP_EOL;
    } elseif (is_dir($tmpDir)) {
        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }
}

$sobras = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM estoque_pecas WHERE codigo LIKE 'TSTPDF-%')
    + (SELECT COUNT(*) FROM estoque_papeis WHERE codigo LIKE 'TSTPDF-%')
    + (SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-pdf-%')")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada pelo teste', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('dados permanentes intactos (estoque, movimentos, variações, usuários)', (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s), {$avisos} aviso(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

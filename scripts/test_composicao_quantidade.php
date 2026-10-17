<?php
/**
 * Testes locais — quantidades da composição (1, 4, 400, 1000, 1500, 10000 e 1000,5) sobrevivem
 * ao ciclo real da tela: valor gravado → campo renderizado → formulário enviado → leitura → valor gravado.
 * Fluxos: Recalcular do produto, Recalcular da variação, Trocar custo e Editar custo manual (produto e variação).
 * Cria dados temporários (TST-CQTD-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_composicao_quantidade.php
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
$pdo->exec('SET SESSION group_concat_max_len = 16777216');
$sufixo = date('His') . random_int(100, 999);
$prefixo = 'TST-CQTD-' . $sufixo;
$produtos = [];
$materiais = [];
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_' . $sufixo . '.php';
$nodeScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.js';
$nodeInput = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.json';

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_PERMANENTE = "SELECT MD5(CONCAT(
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, status, custo_atual) ORDER BY id SEPARATOR '|') FROM materiais WHERE nome NOT LIKE 'TST-CQTD-%'), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, IFNULL(material_id, 'N'), quantidade) ORDER BY id SEPARATOR '|') FROM produto_composicao), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, IFNULL(material_id, 'N'), quantidade) ORDER BY id SEPARATOR '|') FROM variacao_composicao), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, quantidade_producao, rendimento_folha) ORDER BY id SEPARATOR '|') FROM produtos WHERE codigo_interno NOT LIKE 'TST-CQTD-%'), ''), '#',
        (SELECT COUNT(*) FROM historico_custos)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();

/** Quantidade gravada → texto esperado no campo editável (sem separador de milhar). */
const QUANTIDADES = [
    ['q' => 1.0, 'campo' => '1'],
    ['q' => 4.0, 'campo' => '4'],
    ['q' => 400.0, 'campo' => '400'],
    ['q' => 1000.0, 'campo' => '1000'],
    ['q' => 1500.0, 'campo' => '1500'],
    ['q' => 10000.0, 'campo' => '10000'],
    ['q' => 1000.5, 'campo' => '1000,5'],
];

function novoMaterial(string $nome, string $forma, float $custo): int
{
    global $materiais, $prefixo, $categoriaId, $unidadeId;
    $id = Material::create([
        'nome' => $prefixo . ' ' . $nome,
        'categoria_material_id' => $categoriaId,
        'unidade_id' => $unidadeId,
        'forma_cobranca' => $forma,
        'custo_atual' => $custo,
        'fornecedor' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $materiais[] = $id;
    return $id;
}

function novoProduto(string $sufixoCodigo): int
{
    global $produtos, $prefixo, $pdo;
    $id = Produto::create([
        'codigo_interno' => $prefixo . '-' . $sufixoCodigo,
        'nome' => 'Teste quantidade composição ' . $sufixoCodigo,
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
    $pdo->prepare('UPDATE produtos SET quantidade_producao = 1000, rendimento_folha = 4 WHERE id = :id')->execute(['id' => $id]);
    $produtos[] = $id;
    return $id;
}

function rota(string $method, string $uri, array $post = []): array
{
    global $runner, $sessDir, $userId;
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

function dom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/**
 * Campos que o navegador enviaria no formulário (inclui campos de fora ligados por form="id"),
 * com os valores exatamente como renderizados. O _csrf é trocado pelo token do teste.
 */
function dadosFormulario(DOMXPath $x, string $formXpath): ?array
{
    $form = $x->query($formXpath)->item(0);
    if (!$form instanceof DOMElement) {
        return null;
    }
    $nos = iterator_to_array($x->query('.//input|.//select|.//textarea', $form));
    $id = $form->getAttribute('id');
    if ($id !== '') {
        foreach ($x->query("//input[@form='{$id}']|//select[@form='{$id}']|//textarea[@form='{$id}']") as $n) {
            $nos[] = $n;
        }
    }
    $pares = [];
    foreach ($nos as $n) {
        $nome = $n->getAttribute('name');
        if ($nome === '' || $nome === '_csrf') {
            continue;
        }
        if ($n->nodeName === 'input') {
            $tipo = strtolower($n->getAttribute('type') ?: 'text');
            if (in_array($tipo, ['submit', 'button', 'image', 'reset', 'file'], true)
                || (in_array($tipo, ['checkbox', 'radio'], true) && !$n->hasAttribute('checked'))) {
                continue;
            }
            $valor = $n->getAttribute('value');
        } elseif ($n->nodeName === 'select') {
            $opt = $x->query('.//option[@selected]', $n)->item(0) ?? $x->query('.//option', $n)->item(0);
            $valor = $opt instanceof DOMElement ? $opt->getAttribute('value') : '';
        } else {
            $valor = $n->textContent;
        }
        $pares[] = rawurlencode($nome) . '=' . rawurlencode($valor);
    }
    parse_str(implode('&', $pares), $dados);
    return $dados;
}

function quantidadeGravada(int $id, string $tabela): ?float
{
    $stmt = Database::pdo()->prepare("SELECT quantidade FROM {$tabela} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (float) $v;
}

function precoManual(int $id, string $tabela): ?float
{
    $stmt = Database::pdo()->prepare("SELECT preco_manual FROM {$tabela} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $v = $stmt->fetchColumn();
    return $v === false || $v === null ? null : (float) $v;
}

/** Igual ao test_custo_inativo_composicao: roda no Node o script real da prévia com as linhas renderizadas. */
function previaJs(DOMXPath $x, string $tabelaId, string $totalId, string $pecaId): ?array
{
    global $nodeScript, $nodeInput;
    $script = null;
    foreach ($x->query('//script') as $s) {
        if (str_contains($s->textContent, "getElementById('{$tabelaId}')")) {
            $script = $s->textContent;
        }
    }
    if ($script === null) {
        return null;
    }
    $rows = [];
    foreach ($x->query("//table[@id='{$tabelaId}']//tbody/tr[@data-preco]") as $tr) {
        $inp = $x->query(".//input[contains(@class,'qtd-custo-input')]", $tr)->item(0);
        $rows[] = ['preco' => $tr->getAttribute('data-preco'), 'qtd' => $inp ? $inp->getAttribute('value') : null];
    }
    $pecasEl = $x->query("//*[@id='pecas-resultantes']")->item(0);
    file_put_contents($nodeInput, json_encode([
        'script' => $script,
        'rows' => $rows,
        'ids' => ['tabela' => $tabelaId, 'total' => $totalId, 'peca' => $pecaId],
        'pecas' => $pecasEl ? trim($pecasEl->textContent) : '',
    ]));
    file_put_contents($nodeScript, <<<'JS'
const fs = require('fs');
const d = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const rows = d.rows.map((r) => {
    const inp = { value: r.qtd, listeners: {}, addEventListener(t, f) { this.listeners[t] = f; } };
    const cell = { textContent: '' };
    return {
        inp, cell,
        getAttribute: (n) => (n === 'data-preco' ? r.preco : null),
        querySelector: (s) => (s === '.qtd-custo-input' ? (r.qtd === null ? null : inp) : s === '.custo-linha-prod' ? cell : null),
    };
});
const els = {};
els[d.ids.tabela] = { querySelectorAll: (s) => (s.indexOf('tr[data-preco]') >= 0 ? rows : rows.filter((r) => r.qtd !== null).map((r) => r.inp)) };
els[d.ids.total] = { textContent: '' };
els[d.ids.peca] = { textContent: '' };
els['pecas-resultantes'] = { textContent: d.pecas };
globalThis.document = { getElementById: (id) => els[id] || null };
eval(d.script);
const comInput = rows.find((r) => r.inp.listeners.input);
if (comInput) comInput.inp.listeners.input();
process.stdout.write(JSON.stringify({ total: els[d.ids.total].textContent, cells: rows.map((r) => r.cell.textContent) }));
JS);
    $res = json_decode((string) shell_exec('node ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($nodeInput) . ' 2>&1'), true);
    if (!is_array($res)) {
        return null;
    }
    $res['total'] = str_replace("\u{00A0}", ' ', (string) $res['total']);
    $res['cells'] = array_map(static fn ($c) => str_replace("\u{00A0}", ' ', (string) $c), $res['cells']);
    return $res;
}

/** Valor do campo qtd_custo[id] renderizado na tabela. */
function campoQtd(DOMXPath $x, int $linhaId): ?string
{
    $inp = $x->query("//input[@name='qtd_custo[{$linhaId}]']")->item(0);
    return $inp instanceof DOMElement ? $inp->getAttribute('value') : null;
}

/**
 * Recalcular pela tela: GET da página, envia o formulário com os valores renderizados e confere o gravado.
 * @param array<int, array{q: float, campo: string}> $linhas id da linha => quantidade esperada
 */
function verificarRecalcular(string $rotulo, string $paginaUri, string $formXpath, string $postUri, array $linhas, string $tabela,
    string $tabelaId, string $totalId, string $pecaId): void
{
    for ($rodada = 1; $rodada <= 2; $rodada++) {
        $x = dom(rota('GET', $paginaUri)['html']);
        foreach ($linhas as $id => $esp) {
            $campo = campoQtd($x, $id);
            ok("{$rotulo} (rodada {$rodada}): quantidade " . $esp['campo'] . ' exibida como "' . $esp['campo'] . '"', $campo === $esp['campo'], 'campo=' . var_export($campo, true));
        }
        if ($rodada === 1) {
            $totalServidor = trim((string) ($x->query("//*[@id='{$totalId}']")->item(0)->textContent ?? ''));
            $js = previaJs($x, $tabelaId, $totalId, $pecaId);
            ok("{$rotulo}: prévia JS da tabela = total do servidor ({$totalServidor})", $js !== null && $js['total'] === $totalServidor, 'js=' . ($js['total'] ?? 'null'));
        }
        $dados = dadosFormulario($x, $formXpath);
        $r = rota('POST', $postUri, $dados ?? []);
        foreach ($linhas as $id => $esp) {
            $gravada = quantidadeGravada($id, $tabela);
            ok("{$rotulo} (rodada {$rodada}): após Recalcular, " . $esp['campo'] . ' continua ' . $esp['campo'],
                $gravada !== null && abs($gravada - $esp['q']) < 1e-9, 'gravada=' . var_export($gravada, true) . ' ' . flashTexto($r, 'errors'));
        }
    }
}

try {
    $categoriaId = (int) ($pdo->query("SELECT id FROM categorias_materiais WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $unidadeId = (int) ($pdo->query("SELECT id FROM unidades_medida WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($categoriaId === 0 || $unidadeId === 0 || $userId === 0) {
        throw new RuntimeException('Faltam categoria/unidade/usuário ativos locais para o teste.');
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

    // --- Dados: custos de modalidades diferentes (folha, milheiro, unidade) ---
    $formas = ['folha', 'milheiro', 'unidade', 'unidade', 'folha', 'unidade', 'folha'];
    $precos = [1.45, 1100.00, 0.10, 0.02, 0.37, 0.005, 2.20];
    $mats = [];
    foreach (QUANTIDADES as $i => $esp) {
        $mats[$i] = novoMaterial('Custo ' . ($i + 1), $formas[$i], $precos[$i]);
    }

    $p = novoProduto('P');
    $linhasP = [];
    foreach (QUANTIDADES as $i => $esp) {
        $linhasP[Composicao::create($p, $mats[$i], $formas[$i], $esp['q'])] = $esp;
    }
    $manualP1000 = Composicao::createManual($p, $prefixo . ' Manual mil', 1234.5, 1000.0);
    $manualP105 = Composicao::createManual($p, $prefixo . ' Manual decimal', 0.35, 1000.5);
    $linhasP[$manualP1000] = ['q' => 1000.0, 'campo' => '1000'];
    $linhasP[$manualP105] = ['q' => 1000.5, 'campo' => '1000,5'];

    $v = Variacao::create([
        'produto_id' => $p,
        'codigo' => $prefixo . '-V',
        'nome' => 'Variação teste',
        'estoque' => 0,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'proprio',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
    $linhasV = [];
    foreach (QUANTIDADES as $i => $esp) {
        $linhasV[Composicao::createVariacao($v, $mats[$i], $formas[$i], $esp['q'])] = $esp;
    }
    $manualV1000 = Composicao::createManualVariacao($v, $prefixo . ' Manual var mil', 1234.5, 1000.0);
    $linhasV[$manualV1000] = ['q' => 1000.0, 'campo' => '1000'];

    // --- Fórmula inalterada: custo do item = preço × quantidade gravada ---
    $esperadoP = 1234.5 * 1000 + 0.35 * 1000.5;
    foreach (QUANTIDADES as $i => $esp) {
        $esperadoP += $precos[$i] * $esp['q'];
    }
    ok('fórmula: total do produto = Σ preço × quantidade', abs((float) Composicao::totais($p)['custo_lote'] - $esperadoP) < 1e-6,
        'total=' . Composicao::totais($p)['custo_lote'] . ' esperado=' . $esperadoP);

    // --- A) Produto → composição → Recalcular ---
    verificarRecalcular('A) produto', '/produtos/' . $p, "//form[@id='form-producao-ref']", '/produtos/' . $p . '/tiragem', $linhasP,
        'produto_composicao', 'tabela-custos-formacao', 'resumo-custo-total', 'resumo-custo-peca');
    $prodDepois = Produto::find($p);
    ok('A) produto: Recalcular mantém 1000 folhas e 4 pç/folha', (int) $prodDepois['quantidade_producao'] === 1000 && (int) $prodDepois['rendimento_folha'] === 4);

    // --- B) Variação → composição → Recalcular ---
    verificarRecalcular('B) variação', '/produtos/' . $p . '/variacoes/' . $v . '/editar', "//form[@id='form-var-comp']",
        '/produtos/' . $p . '/variacoes/' . $v . '/composicao/recalcular', $linhasV,
        'variacao_composicao', 'tabela-custos-var', 'resumo-var-total', 'resumo-var-peca');

    // --- C) Trocar custo → salvar sem alterar quantidade (produto e variação) ---
    foreach (['produto' => [$linhasP, 'produto_composicao', '/produtos/' . $p . '/composicao/'],
              'variação' => [$linhasV, 'variacao_composicao', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/']] as $onde => [$linhas, $tabela, $base]) {
        foreach ($linhas as $id => $esp) {
            if (in_array($id, [$manualP1000, $manualP105, $manualV1000], true)) {
                continue;
            }
            $x = dom(rota('GET', $base . $id . '/editar')['html']);
            $campo = $x->query("//input[@id='quantidade_custo']")->item(0);
            $valor = $campo instanceof DOMElement ? $campo->getAttribute('value') : null;
            ok("C) Trocar ({$onde}): quantidade " . $esp['campo'] . ' exibida como "' . $esp['campo'] . '"', $valor === $esp['campo'], 'campo=' . var_export($valor, true));
            $r = rota('POST', $base . $id, dadosFormulario($x, "//form[.//input[@id='quantidade_custo']]") ?? []);
            $gravada = quantidadeGravada($id, $tabela);
            ok("C) Trocar ({$onde}): salvar sem alterar mantém " . $esp['campo'], $gravada !== null && abs($gravada - $esp['q']) < 1e-9,
                'gravada=' . var_export($gravada, true) . ' ' . flashTexto($r, 'errors'));
        }
    }

    // --- D) Editar custo manual → salvar sem alterar quantidade (preço monetário também preservado) ---
    foreach ([['produto', $manualP1000, 'produto_composicao', '/produtos/' . $p . '/composicao/', 1234.5, '1.234,50'],
              ['produto', $manualP105, 'produto_composicao', '/produtos/' . $p . '/composicao/', 0.35, '0,35'],
              ['variação', $manualV1000, 'variacao_composicao', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/', 1234.5, '1.234,50']] as [$onde, $id, $tabela, $base, $preco, $precoCampo]) {
        $esp = $onde === 'variação' ? $linhasV[$id] : $linhasP[$id];
        $x = dom(rota('GET', $base . $id . '/editar')['html']);
        $qEl = $x->query("//input[@id='qtd_manual']")->item(0);
        $pEl = $x->query("//input[@id='preco_manual']")->item(0);
        $valor = $qEl instanceof DOMElement ? $qEl->getAttribute('value') : null;
        ok("D) Editar manual ({$onde}): quantidade " . $esp['campo'] . ' exibida como "' . $esp['campo'] . '"', $valor === $esp['campo'], 'campo=' . var_export($valor, true));
        ok("D) Editar manual ({$onde}): preço continua formatado como dinheiro ({$precoCampo})",
            $pEl instanceof DOMElement && $pEl->getAttribute('value') === $precoCampo, 'campo=' . ($pEl instanceof DOMElement ? $pEl->getAttribute('value') : 'null'));
        $r = rota('POST', $base . $id, dadosFormulario($x, "//form[@id='form-custo-manual']") ?? []);
        $gravada = quantidadeGravada($id, $tabela);
        ok("D) Editar manual ({$onde}): salvar sem alterar mantém " . $esp['campo'] . ' e o preço',
            $gravada !== null && abs($gravada - $esp['q']) < 1e-9 && abs((float) precoManual($id, $tabela) - $preco) < 1e-9,
            'gravada=' . var_export($gravada, true) . ' preço=' . var_export(precoManual($id, $tabela), true) . ' ' . flashTexto($r, 'errors'));
    }

    ok('fórmula: total do produto inalterado depois de todos os fluxos', abs((float) Composicao::totais($p)['custo_lote'] - $esperadoP) < 1e-6,
        'total=' . Composicao::totais($p)['custo_lote']);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($produtos as $pid) {
        $pdo->prepare('DELETE vc FROM variacao_composicao vc JOIN produto_variacoes pv ON pv.id = vc.variacao_id WHERE pv.produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produto_variacoes WHERE produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $pid]);
    }
    foreach ($materiais as $mid) {
        $pdo->prepare('DELETE FROM historico_custos WHERE material_id = :id')->execute(['id' => $mid]);
        $pdo->prepare('DELETE FROM materiais WHERE id = :id')->execute(['id' => $mid]);
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM materiais WHERE nome LIKE 'TST-CQTD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-CQTD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-CQTD-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('custos, composições, produção e histórico permanentes intactos', (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Testes locais — custo inativo continua no cálculo das composições existentes,
 * não entra em novos vínculos, aparece marcado como INATIVO e gera aviso ao ser inativado.
 * Cria dados temporários (TST-CINAT-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_custo_inativo_composicao.php
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
$prefixo = 'TST-CINAT-' . $sufixo;
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
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, status, custo_atual) ORDER BY id SEPARATOR '|') FROM materiais WHERE nome NOT LIKE 'TST-CINAT-%'), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, IFNULL(material_id, 'N'), quantidade) ORDER BY id SEPARATOR '|') FROM produto_composicao), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, IFNULL(material_id, 'N'), quantidade) ORDER BY id SEPARATOR '|') FROM variacao_composicao), ''), '#',
        (SELECT COUNT(*) FROM historico_custos)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();

function novoMaterial(string $nome, float $custo, string $status = 'ativo'): int
{
    global $materiais, $prefixo, $categoriaId, $unidadeId;
    $id = Material::create([
        'nome' => $prefixo . ' ' . $nome,
        'categoria_material_id' => $categoriaId,
        'unidade_id' => $unidadeId,
        'forma_cobranca' => 'unidade',
        'custo_atual' => $custo,
        'fornecedor' => null,
        'observacao' => null,
        'status' => $status,
    ]);
    $materiais[] = $id;
    return $id;
}

function novoProduto(string $sufixoCodigo): int
{
    global $produtos, $prefixo, $pdo;
    $id = Produto::create([
        'codigo_interno' => $prefixo . '-' . $sufixoCodigo,
        'nome' => 'Teste custo inativo ' . $sufixoCodigo,
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
    $pdo->prepare('UPDATE produtos SET quantidade_producao = 1, rendimento_folha = 1 WHERE id = :id')->execute(['id' => $id]);
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

function linhaComposicao(int $id, string $tabela = 'produto_composicao'): ?array
{
    $stmt = Database::pdo()->prepare("SELECT material_id, quantidade FROM {$tabela} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Executa no Node o script real da prévia (extraído da página) com um DOM mínimo
 * montado a partir das linhas renderizadas; retorna o total e as células calculadas.
 */
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

    // --- Dados: custos, produto com 2 cadastrados + 1 manual, variação com custo próprio ---
    $mA = novoMaterial('Ativo A', 10.00);
    $mI = novoMaterial('Vai inativar', 5.00);
    $mB = novoMaterial('Ativo B', 4.00);
    $mU = novoMaterial('Sem uso', 1.00);
    $mX = novoMaterial('Inativo desde o início', 7.00, 'inativo');

    $p = novoProduto('P');
    $prod = Produto::find($p);
    $lA = Composicao::create($p, $mA, 'unidade', 2.0);
    $lI = Composicao::create($p, $mI, 'unidade', 1.0);
    $lM = Composicao::createManual($p, 'Manual teste', 3.00, 1.0);

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
    $vI = Composicao::createVariacao($v, $mI, 'unidade', 2.0);
    $vA = Composicao::createVariacao($v, $mA, 'unidade', 1.0);

    $p2 = novoProduto('P2');

    $totalP = static fn (): float => (float) Composicao::totais($p)['custo_lote'];
    $totalV = static fn (): float => (float) Composicao::totaisVariacao($v, Produto::find($p))['custo_lote'];

    // --- 1) Custo ativo: cálculo de referência ---
    ok('1. produto com custos ativos: total = 2×10 + 1×5 + manual 3 = 28', abs($totalP() - 28.0) < 1e-9, 'total=' . $totalP());
    ok('1. variação com custos ativos: total = 2×5 + 1×10 = 20', abs($totalV() - 20.0) < 1e-9, 'total=' . $totalV());
    $linhaA = array_values(array_filter(Composicao::listarPorProduto($p), static fn ($l) => (int) $l['id'] === $lA))[0];
    $calcA = Composicao::calcularLinha('unidade', 10.0, Composicao::resolverProducao($prod), 2.0);
    ok('1. linha ativa calculada pela mesma fórmula (calcularLinha)', abs((float) $linhaA['custo_lote'] - $calcA['custo_lote']) < 1e-9);

    // --- 8) Contagem de uso ---
    ok('8. contarUsos(custo em produto e variação) = 1 produto, 1 variação', Material::contarUsos($mI) === ['produtos' => 1, 'variacoes' => 1]);
    ok('8. contarUsos(custo sem uso) = 0 e 0', Material::contarUsos($mU) === ['produtos' => 0, 'variacoes' => 0]);
    Composicao::create($p2, $mI, 'unidade', 1.0);
    ok('8. contarUsos conta produtos distintos (2)', Material::contarUsos($mI) === ['produtos' => 2, 'variacoes' => 1]);
    $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :p')->execute(['p' => $p2]);

    // --- 9) Inativação com aviso (custo em uso) ---
    $r = rota('POST', '/custos/' . $mI . '/status');
    ok('9. custo em uso: inativação permitida', (Material::find($mI)['status'] ?? '') === 'inativo');
    ok('9. custo em uso: aviso informa 1 produto e 1 variação', str_contains(flashTexto($r, 'success'),
        'Este custo está sendo usado em 1 produto(s) e 1 variação(ões). Ao inativá-lo, ele continuará sendo considerado nas composições existentes, mas não ficará disponível para novas composições.'),
        flashTexto($r, 'success'));

    // --- 2, 3, 4) Inativo continua no cálculo; manual igual ---
    ok('2. produto: custo inativo continua no total (28)', abs($totalP() - 28.0) < 1e-9, 'total=' . $totalP());
    ok('3. variação: custo inativo continua no total (20)', abs($totalV() - 20.0) < 1e-9, 'total=' . $totalV());
    $linhas = Composicao::listarPorProduto($p);
    $porId = array_column($linhas, null, 'id');
    ok('2. linha inativa mantém preço e custo (5,00)', abs((float) $porId[$lI]['custo_lote'] - 5.0) < 1e-9 && $porId[$lI]['material_status'] === 'inativo');
    ok('4. linha manual inalterada (3,00, tipo manual)', abs((float) $porId[$lM]['custo_lote'] - 3.0) < 1e-9 && composicao_eh_manual($porId[$lM]));
    $preco = PrecoFormacao::analisar(Produto::find($p), (float) Composicao::totais($p)['custo_unitario']);
    ok('2. formação de preço recebe o custo com o inativo (28)', abs((float) $preco['custo'] - 28.0) < 1e-9);
    ok('2. custo resolvido da variação com o inativo (20)', abs(Variacao::custoUnitarioResolvido(Variacao::find($v), Produto::find($p)) - 20.0) < 1e-9);

    // --- Tela do produto: selo INATIVO, aviso e prévia JS = servidor ---
    $x = dom(rota('GET', '/produtos/' . $p)['html']);
    $selos = $x->query("//table[@id='tabela-custos-formacao']//span[contains(@class,'custo-inativo')]");
    ok('produto: exatamente 1 selo INATIVO', $selos->length === 1);
    $linhaSelo = $selos->length ? $x->query('ancestor::tr[1]', $selos->item(0))->item(0) : null;
    ok('produto: selo na linha do custo inativo', $linhaSelo && str_contains($linhaSelo->textContent, 'Vai inativar')
        && trim($selos->item(0)->textContent) === 'INATIVO');
    ok('produto: aviso visível de custo inativo considerado', $x->query("//*[@id='aviso-custo-inativo']")->length === 1);
    $manualRow = null;
    foreach ($x->query("//table[@id='tabela-custos-formacao']//tbody/tr") as $tr) {
        if (str_contains($tr->textContent, 'Manual teste')) {
            $manualRow = $tr;
        }
    }
    ok('4. linha manual sem selo INATIVO', $manualRow && $x->query(".//span[contains(@class,'custo-inativo')]", $manualRow)->length === 0);
    $totalServidor = trim((string) ($x->query("//*[@id='resumo-custo-total']")->item(0)->textContent ?? ''));
    $js = previaJs($x, 'tabela-custos-formacao', 'resumo-custo-total', 'resumo-custo-peca');
    ok('5. produto: servidor mostra R$ 28,00', $totalServidor === 'R$ 28,00', $totalServidor);
    ok('5. produto: prévia JS = servidor (inclui o inativo)', $js !== null && $js['total'] === $totalServidor, 'js=' . ($js['total'] ?? 'null'));
    ok('5. produto: prévia JS calcula a linha inativa (R$ 5,00)', $js !== null && in_array('R$ 5,00', $js['cells'], true));

    // --- Tela da variação ---
    $x = dom(rota('GET', '/produtos/' . $p . '/variacoes/' . $v . '/editar')['html']);
    $selos = $x->query("//table[@id='tabela-custos-var']//span[contains(@class,'custo-inativo')]");
    ok('variação: exatamente 1 selo INATIVO', $selos->length === 1);
    ok('variação: aviso visível de custo inativo considerado', $x->query("//*[@id='aviso-custo-inativo']")->length === 1);
    $totalServidorV = trim((string) ($x->query("//*[@id='resumo-var-total']")->item(0)->textContent ?? ''));
    $jsV = previaJs($x, 'tabela-custos-var', 'resumo-var-total', 'resumo-var-peca');
    ok('5. variação: servidor mostra R$ 20,00', $totalServidorV === 'R$ 20,00', $totalServidorV);
    ok('5. variação: prévia JS = servidor (inclui o inativo)', $jsV !== null && $jsV['total'] === $totalServidorV, 'js=' . ($jsV['total'] ?? 'null'));

    // --- Listas de seleção ---
    $idsLista = static fn (array $lista): array => array_map(static fn ($m) => (int) $m['id'], $lista);
    $novos = $idsLista(Composicao::materiaisAtivosDisponiveis($p2));
    ok('6. lista para novo vínculo: sem custos inativos', !in_array($mI, $novos, true) && !in_array($mX, $novos, true) && in_array($mB, $novos, true));
    $edicao = $idsLista(Composicao::materiaisAtivosDisponiveis($p, $mI));
    ok('7. lista ao trocar linha inativa: inclui o custo atual e os ativos livres', in_array($mI, $edicao, true)
        && in_array($mB, $edicao, true) && !in_array($mX, $edicao, true) && !in_array($mA, $edicao, true));
    $x = dom(rota('GET', '/produtos/' . $p . '/composicao/' . $lI . '/editar')['html']);
    $opt = $x->query("//select[@id='material_id']/option[@value='{$mI}']")->item(0);
    ok('7. formulário "Trocar": custo atual identificável como INATIVO e selecionado',
        $opt && str_contains($opt->textContent, 'INATIVO (atual)') && $opt->hasAttribute('selected'));
    $edicaoV = $idsLista(Composicao::materiaisAtivosDisponiveisVariacao($v, $mI));
    ok('7. variação: lista ao trocar inclui o custo atual inativo', in_array($mI, $edicaoV, true) && !in_array($mX, $edicaoV, true));
    $x = dom(rota('GET', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/' . $vI . '/editar')['html']);
    $opt = $x->query("//select[@id='material_id']/option[@value='{$mI}']")->item(0);
    ok('7. variação: formulário "Trocar" mostra INATIVO (atual)', $opt && str_contains($opt->textContent, 'INATIVO (atual)'));

    // --- 6) Novo vínculo com custo inativo: recusado (POST direto) ---
    $antes = (int) $pdo->query("SELECT COUNT(*) FROM produto_composicao WHERE produto_id = {$p2}")->fetchColumn();
    $r = rota('POST', '/produtos/' . $p2 . '/composicao', ['material_id' => (string) $mI, 'quantidade' => '1']);
    $depois = (int) $pdo->query("SELECT COUNT(*) FROM produto_composicao WHERE produto_id = {$p2}")->fetchColumn();
    ok('6. produto: POST novo vínculo com custo inativo recusado', $antes === $depois && str_contains(flashTexto($r, 'errors'), 'inativo'), flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/composicao/' . $lA, ['material_id' => (string) $mX, 'quantidade' => '2']);
    ok('6. produto: trocar linha ativa por OUTRO custo inativo recusado', (int) (linhaComposicao($lA)['material_id'] ?? 0) === $mA, flashTexto($r, 'errors'));
    $antesV = Composicao::contarLinhasVariacao($v);
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/composicao', ['material_id' => (string) $mX, 'quantidade' => '1']);
    ok('6. variação: POST novo vínculo com custo inativo recusado', Composicao::contarLinhasVariacao($v) === $antesV
        && str_contains(flashTexto($r, 'errors'), 'inativo'), flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p2 . '/composicao', ['material_id' => (string) $mB, 'quantidade' => '1']);
    ok('1. novo vínculo com custo ativo continua aceito', (float) Composicao::totais($p2)['custo_lote'] === 4.0, flashTexto($r, 'success'));

    // --- 7) Vínculo existente com custo inativo: aceito e substituível ---
    $r = rota('POST', '/produtos/' . $p . '/composicao/' . $lI, ['material_id' => (string) $mI, 'quantidade' => '3']);
    $l = linhaComposicao($lI);
    ok('7. produto: salvar linha existente mantendo o custo inativo é aceito', (int) $l['material_id'] === $mI
        && abs((float) $l['quantidade'] - 3.0) < 1e-9, flashTexto($r, 'errors') . flashTexto($r, 'success'));
    ok('7. produto: total reflete a nova quantidade do inativo (20 + 15 + 3 = 38)', abs($totalP() - 38.0) < 1e-9, 'total=' . $totalP());
    $r = rota('POST', '/produtos/' . $p . '/composicao/' . $lI, ['material_id' => (string) $mB, 'quantidade' => '3']);
    ok('7. produto: custo inativo substituído por custo ativo', (int) (linhaComposicao($lI)['material_id'] ?? 0) === $mB, flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/' . $vI, ['material_id' => (string) $mI, 'quantidade' => '1']);
    $l = linhaComposicao($vI, 'variacao_composicao');
    ok('7. variação: salvar linha existente mantendo o custo inativo é aceito', (int) $l['material_id'] === $mI
        && abs((float) $l['quantidade'] - 1.0) < 1e-9, flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/' . $vI, ['material_id' => (string) $mB, 'quantidade' => '1']);
    ok('7. variação: custo inativo substituído por custo ativo', (int) (linhaComposicao($vI, 'variacao_composicao')['material_id'] ?? 0) === $mB, flashTexto($r, 'errors'));

    // --- 9) Inativação sem uso e pelo formulário de edição ---
    $r = rota('POST', '/custos/' . $mU . '/status');
    ok('9. custo sem uso: inativado com a mensagem atual, sem aviso', (Material::find($mU)['status'] ?? '') === 'inativo'
        && flashTexto($r, 'success') === 'Custo desativado.', flashTexto($r, 'success'));
    $r = rota('POST', '/custos/' . $mA, [
        'nome' => $prefixo . ' Ativo A',
        'categoria_material_id' => (string) $categoriaId,
        'unidade_id' => (string) $unidadeId,
        'forma_cobranca' => 'unidade',
        'custo_atual' => '10,00',
        'status' => 'inativo',
    ]);
    ok('9. inativar pelo formulário de edição: inativado com aviso de uso', (Material::find($mA)['status'] ?? '') === 'inativo'
        && str_contains(flashTexto($r, 'success'), 'usado em 1 produto(s) e 1 variação(ões)'), flashTexto($r, 'success'));
    ok('9. inativar pelo formulário: custo_atual e histórico intactos', abs((float) Material::find($mA)['custo_atual'] - 10.0) < 1e-9
        && (int) $pdo->query("SELECT COUNT(*) FROM historico_custos WHERE material_id = {$mA}")->fetchColumn() === 0);
    ok('2. produto com dois custos inativos continua somando todos (20 + 12 + 3 = 35)', abs($totalP() - 35.0) < 1e-9, 'total=' . $totalP());

    // --- 10) Reativação ---
    $r = rota('POST', '/custos/' . $mI . '/status');
    ok('10. reativação: status ativo e mensagem atual', (Material::find($mI)['status'] ?? '') === 'ativo'
        && flashTexto($r, 'success') === 'Custo ativado.', flashTexto($r, 'success'));
    ok('10. reativação: custo_atual preservado (5,00)', abs((float) Material::find($mI)['custo_atual'] - 5.0) < 1e-9);
    ok('10. reativação: volta a ficar disponível para novas composições', in_array($mI, $idsLista(Composicao::materiaisAtivosDisponiveis($p2)), true));
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($produtos as $pid) {
        $pdo->prepare('DELETE vc FROM variacao_composicao vc JOIN produto_variacoes pv ON pv.id = vc.variacao_id WHERE pv.produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $pid]);
    }
    foreach ($materiais as $mid) {
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM materiais WHERE nome LIKE 'TST-CINAT-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-CINAT-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('custos, composições e histórico permanentes intactos', (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

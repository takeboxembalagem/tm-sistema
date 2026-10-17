<?php
/**
 * Testes locais — lista e busca de custos ao adicionar/trocar custo na composição (produto e variação).
 * Confere as opções renderizadas pelo servidor, roda no Node o script real da busca (public/assets/js/busca-custo.js)
 * e envia os formulários renderizados para garantir que a inclusão e a troca continuam funcionando.
 * Cria dados temporários (TST-CBUSCA-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_composicao_busca_custos.php
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
$prefixo = 'TST-CBUSCA-' . $sufixo;
$produtos = [];
$materiais = [];
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_' . $sufixo . '.php';
$nodeScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.js';
$nodeInput = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_node_' . $sufixo . '.json';
$buscaJs = dirname(__DIR__) . '/public/assets/js/busca-custo.js';

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_PERMANENTE = "SELECT MD5(CONCAT(
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, nome, status, custo_atual, forma_cobranca) ORDER BY id SEPARATOR '|') FROM materiais WHERE nome NOT LIKE 'TST-CBUSCA-%'), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, produto_id, IFNULL(material_id, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM produto_composicao
            WHERE produto_id NOT IN (SELECT id FROM produtos WHERE codigo_interno LIKE 'TST-CBUSCA-%')), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, variacao_id, IFNULL(material_id, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM variacao_composicao
            WHERE variacao_id NOT IN (SELECT id FROM produto_variacoes WHERE codigo LIKE 'TST-CBUSCA-%')), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo, estoque, custo_modo) ORDER BY id SEPARATOR '|') FROM produto_variacoes WHERE codigo NOT LIKE 'TST-CBUSCA-%'), ''), '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo_interno, nome, quantidade_producao) ORDER BY id SEPARATOR '|') FROM produtos WHERE codigo_interno NOT LIKE 'TST-CBUSCA-%'), ''), '#',
        (SELECT COUNT(*) FROM historico_custos), '#', (SELECT COUNT(*) FROM logs_alteracoes), '#',
        (SELECT COUNT(*) FROM estoque_pecas_movimentos), '#', (SELECT COUNT(*) FROM estoque_papeis_movimentos)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();

function novoMaterial(string $nome, string $forma, float $custo, string $status = 'ativo'): int
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
        'status' => $status,
    ]);
    $materiais[] = $id;
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

/** Campos que o navegador enviaria no formulário, com os valores renderizados; $troca sobrescreve campos. */
function dadosFormulario(DOMXPath $x, string $formXpath, array $troca = []): ?array
{
    $form = $x->query($formXpath)->item(0);
    if (!$form instanceof DOMElement) {
        return null;
    }
    $pares = [];
    foreach ($x->query('.//input|.//select|.//textarea', $form) as $n) {
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
    return array_replace($dados, $troca);
}

/** Opções do select de custos renderizado: [id => ['nome' => data-nome, 'texto' => ..., 'selected' => bool]]. */
function opcoesRenderizadas(DOMXPath $x): ?array
{
    $sel = $x->query("//select[@id='material_id']")->item(0);
    if (!$sel instanceof DOMElement) {
        return null;
    }
    $out = [];
    foreach ($x->query(".//option[@value!='']", $sel) as $o) {
        $out[(int) $o->getAttribute('value')] = [
            'nome' => $o->getAttribute('data-nome'),
            'texto' => trim(preg_replace('/\s+/', ' ', $o->textContent) ?? ''),
            'selected' => $o->hasAttribute('selected'),
        ];
    }
    return $out;
}

/** IDs ativos fora da composição — o que a seleção normal deve oferecer. */
function elegiveis(string $tabela, string $coluna, int $id): array
{
    $stmt = Database::pdo()->prepare(
        "SELECT id FROM materiais WHERE status = 'ativo'
         AND id NOT IN (SELECT material_id FROM {$tabela} WHERE {$coluna} = :id AND material_id IS NOT NULL) ORDER BY id"
    );
    $stmt->execute(['id' => $id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function semAcento(string $s): string
{
    return mb_strtolower(strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C',
    ]));
}

/**
 * Roda no Node o busca-custo.js real sobre as opções renderizadas, com um DOM mínimo.
 * Cada passo: ['digitar' => termo] | ['tecla' => 'Enter' ou 'ArrowDown'].
 * Retorna o estado após cada passo: opções visíveis, valor, placeholder, size, status e quantos 'change'.
 */
function simularBusca(DOMXPath $x, array $passos): ?array
{
    global $nodeScript, $nodeInput, $buscaJs;
    $campo = $x->query("//input[@data-busca-select='material_id']")->item(0);
    $sel = $x->query("//select[@id='material_id']")->item(0);
    if (!$campo instanceof DOMElement || !$sel instanceof DOMElement) {
        return null;
    }
    $opcoes = [];
    foreach ($x->query('.//option', $sel) as $o) {
        $opcoes[] = [
            'value' => $o->getAttribute('value'),
            'texto' => $o->textContent,
            'nome' => $o->hasAttribute('data-nome') ? $o->getAttribute('data-nome') : null,
            'selected' => $o->hasAttribute('selected'),
        ];
    }
    file_put_contents($nodeInput, json_encode([
        'script' => file_get_contents($buscaJs),
        'campo' => ['select' => $campo->getAttribute('data-busca-select'), 'status' => $campo->getAttribute('data-busca-status')],
        'opcoes' => $opcoes,
        'passos' => $passos,
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($nodeScript, <<<'JS'
const fs = require('fs');
const d = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
function mkOption(o) {
    return { value: o.value, textContent: o.texto, getAttribute: (n) => (n === 'data-nome' ? o.nome : null), _sel: o.selected };
}
const select = {
    children: d.opcoes.map(mkOption), listeners: {}, changes: 0, focado: false, _size: null,
    get options() { return this.children; },
    get firstChild() { return this.children[0] || null; },
    removeChild(c) { this.children = this.children.filter((x) => x !== c); },
    appendChild(c) { this.children.push(c); },
    get value() { const s = this.children.find((c) => c._sel); return s ? s.value : (this.children[0] ? this.children[0].value : ''); },
    set value(v) { this.children.forEach((c) => { c._sel = c.value === v; }); },
    set size(n) { this._size = n; }, get size() { return this._size; },
    removeAttribute(n) { if (n === 'size') this._size = null; },
    addEventListener(t, f) { this.listeners[t] = f; },
    dispatchEvent(ev) { if (ev.type === 'change') this.changes++; },
    focus() { this.focado = true; },
};
const status = { textContent: '' };
const campo = {
    value: '', listeners: {},
    getAttribute: (n) => (n === 'data-busca-select' ? d.campo.select : n === 'data-busca-status' ? d.campo.status : null),
    addEventListener(t, f) { this.listeners[t] = f; },
};
const els = {}; els[d.campo.select] = select; els[d.campo.status] = status;
globalThis.document = { getElementById: (id) => els[id] || null, querySelectorAll: (s) => (s === 'input[data-busca-select]' ? [campo] : []) };
eval(d.script);
const estado = () => ({
    visiveis: select.children.filter((c) => c.value !== '').map((c) => c.value),
    placeholder: (select.children.find((c) => c.value === '') || {}).textContent,
    valor: select.value, size: select.size, status: status.textContent, changes: select.changes, focado: select.focado,
});
const saida = [estado()];
for (const p of d.passos) {
    if ('digitar' in p) { campo.value = p.digitar; campo.listeners.input(); }
    if ('tecla' in p) { let prevenido = false; campo.listeners.keydown({ key: p.tecla, preventDefault() { prevenido = true; } }); saida.push(Object.assign(estado(), { prevenido })); continue; }
    saida.push(estado());
}
process.stdout.write(JSON.stringify(saida));
JS);
    $res = json_decode((string) shell_exec('node ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($nodeInput) . ' 2>&1'), true);
    return is_array($res) ? $res : null;
}

/** Ids cujos nomes contêm todos os termos (sem acento e sem diferenciar maiúsculas) — referência independente do JS. */
function esperadoBusca(array $opcoes, string $termo): array
{
    $termos = array_values(array_filter(preg_split('/\s+/', semAcento($termo)) ?: [], static fn ($t) => $t !== ''));
    $out = [];
    foreach ($opcoes as $id => $o) {
        $chave = semAcento($o['nome']);
        $todos = true;
        foreach ($termos as $t) {
            if (!str_contains($chave, $t)) {
                $todos = false;
            }
        }
        if ($todos) {
            $out[] = (string) $id;
        }
    }
    return $out;
}

/** Bloco de verificações da tela de seleção (produto ou variação). */
function verificarTela(string $rotulo, DOMXPath $x, array $esperados, array $ausentes, array $ids): void
{
    $op = opcoesRenderizadas($x);
    ok("{$rotulo}: select de custos renderizado", $op !== null);
    $op ??= [];
    $obtidos = array_keys($op);
    sort($obtidos);
    $esp = $esperados;
    sort($esp);
    ok("{$rotulo}: todas as " . count($esp) . ' opções elegíveis renderizadas (sem corte)', $obtidos === $esp,
        'html=' . count($obtidos) . ' esperado=' . count($esp));
    foreach ($ausentes as $nome => $id) {
        ok("{$rotulo}: {$nome} não é oferecido", !isset($op[$id]));
    }
    $campo = $x->query("//input[@type='search'][@data-busca-select='material_id']")->item(0);
    ok("{$rotulo}: campo de busca presente e sem name (não é enviado no formulário)",
        $campo instanceof DOMElement && $campo->getAttribute('name') === '');
    ok("{$rotulo}: script da busca incluído", $x->query("//script[contains(@src,'/assets/js/busca-custo.js')]")->length === 1);
    ok("{$rotulo}: toda opção tem data-nome igual ao nome do custo",
        array_filter($op, static fn ($o) => $o['nome'] === '' || !str_starts_with($o['texto'], $o['nome'])) === []);

    $termos = ['250', 'cartao', 'CARTÃO', 'CaRtÃo', 'impressao cmyk', 'agua', 'ÁGUA', 'verniz', $GLOBALS['prefixo'] . ' Papel'];
    $passos = array_map(static fn ($t) => ['digitar' => $t], $termos);
    $passos[] = ['digitar' => 'zzz termo inexistente'];
    $passos[] = ['digitar' => ''];
    $sim = simularBusca($x, $passos);
    ok("{$rotulo}: script da busca executado no Node", is_array($sim) && count($sim) === count($passos) + 1);
    if (!is_array($sim)) {
        return;
    }
    $todos = array_map('strval', $obtidos);
    ok("{$rotulo}: sem termo, todas as opções visíveis e status \"" . count($todos) . ' custos disponíveis"',
        $sim[0]['visiveis'] === array_map('strval', array_keys($op)) && $sim[0]['status'] === count($todos) . ' custos disponíveis'
        && $sim[0]['size'] === null, 'status=' . $sim[0]['status']);
    foreach ($termos as $i => $termo) {
        $r = $sim[$i + 1];
        $espB = esperadoBusca($op, $termo);
        ok("{$rotulo}: busca \"{$termo}\" mostra exatamente os custos cujo nome contém o termo (" . count($espB) . ')',
            $r['visiveis'] === $espB && $r['status'] === count($espB) . ' de ' . count($todos) . ' custos encontrados',
            'visiveis=' . count($r['visiveis']) . ' status=' . $r['status']);
    }
    $alvos = [
        '250' => $ids['cartao'], 'cartao' => $ids['cartao'], 'CARTÃO' => $ids['cartao'], 'CaRtÃo' => $ids['cartao'],
        'impressao cmyk' => $ids['cmyk'], 'agua' => $ids['agua'], 'ÁGUA' => $ids['agua'], 'verniz' => $ids['agua'],
        $GLOBALS['prefixo'] . ' Papel' => $ids['cartao'],
    ];
    foreach ($alvos as $termo => $id) {
        $k = array_search($termo, $termos, true);
        if (isset($op[$id])) {
            ok("{$rotulo}: \"{$termo}\" encontra \"" . $op[$id]['nome'] . '"', in_array((string) $id, $sim[$k + 1]['visiveis'], true));
        }
    }
    $nada = $sim[count($termos) + 1];
    ok("{$rotulo}: termo inexistente → nenhuma opção e aviso \"Nenhum custo encontrado\"",
        $nada['visiveis'] === [] && $nada['placeholder'] === 'Nenhum custo encontrado' && $nada['valor'] === '');
    $limpo = $sim[count($termos) + 2];
    ok("{$rotulo}: apagar o termo volta a lista completa (placeholder \"Selecione\", sem size)",
        $limpo['visiveis'] === $sim[0]['visiveis'] && $limpo['placeholder'] === 'Selecione' && $limpo['size'] === null);
    ok("{$rotulo}: lista filtrada aparece aberta (size entre 2 e 8)", $sim[1]['size'] >= 2 && $sim[1]['size'] <= 8, 'size=' . $sim[1]['size']);
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

    // --- Dados temporários ---
    $ids = [
        'cartao' => novoMaterial('Papel Cartão 250 gr 66x96', 'folha', 1.85),
        'cmyk' => novoMaterial('Impressão CMYK 4x0', 'milheiro', 150.00),
        'corte' => novoMaterial('Corte e Vinco', 'milheiro', 85.00),
        'agua' => novoMaterial("Verniz à Base d'ÁGUA", 'milheiro', 70.00),
        'hotstamp' => novoMaterial('Hotstamp Prata', 'lote', 175.00),
        'inativo' => novoMaterial('Colagem Manual', 'milheiro', 90.00, 'inativo'),
    ];
    $p = Produto::create([
        'codigo_interno' => $prefixo . '-P', 'nome' => 'Teste busca de custos', 'tamanho' => null, 'tamanho_aberta' => null,
        'descricao' => null, 'categoria_id' => null, 'colecao_id' => null, 'foto_principal' => null, 'status' => 'ativo',
        'quantidade_pacote' => null, 'peso_pacote_kg' => null,
    ]);
    $produtos[] = $p;
    Composicao::create($p, $ids['corte'], 'milheiro', 1.0);
    $linhaHot = Composicao::create($p, $ids['hotstamp'], 'lote', 1.0);
    $v = Variacao::create([
        'produto_id' => $p, 'codigo' => $prefixo . '-V', 'nome' => 'Variação teste busca', 'estoque' => 0, 'status' => 'ativo',
        'observacao' => null, 'custo_modo' => 'proprio', 'pacote_modo' => 'herdar', 'quantidade_pacote' => null,
    ]);
    Composicao::createVariacao($v, $ids['cmyk'], 'milheiro', 1.0);
    $linhaHotV = Composicao::createVariacao($v, $ids['hotstamp'], 'lote', 1.0);
    // Inativa um custo que já está nas duas composições (troca deve mantê-lo; seleção normal não).
    Material::setStatus($ids['hotstamp'], 'inativo');

    $totalAtivos = (int) $pdo->query("SELECT COUNT(*) FROM materiais WHERE status = 'ativo'")->fetchColumn();

    // --- 1/4/5: Produto, seleção normal ---
    $x = dom(rota('GET', "/produtos/{$p}/composicao/cadastrado/novo")['html']);
    $espP = elegiveis('produto_composicao', 'produto_id', $p);
    $doSistema = array_map(static fn ($r) => (int) $r['id'], Composicao::materiaisAtivosDisponiveis($p));
    sort($doSistema);
    ok('Produto: elegíveis = consulta do sistema (Composicao::materiaisAtivosDisponiveis)', $doSistema === $espP,
        "ativos={$totalAtivos} elegíveis=" . count($espP));
    verificarTela('Produto (adicionar)', $x, $espP,
        ['custo inativo' => $ids['inativo'], 'custo já incluído (Corte e Vinco)' => $ids['corte'], 'custo incluído e depois inativado' => $ids['hotstamp']], $ids);

    // --- 6: Produto, trocar custo (exceção do custo atual, mesmo inativo) ---
    $xT = dom(rota('GET', "/produtos/{$p}/composicao/{$linhaHot}/editar")['html']);
    $opT = opcoesRenderizadas($xT) ?? [];
    ok('Produto (trocar): custo atual inativo aparece, selecionado e marcado "INATIVO (atual)"',
        isset($opT[$ids['hotstamp']]) && $opT[$ids['hotstamp']]['selected'] && str_contains($opT[$ids['hotstamp']]['texto'], 'INATIVO (atual)'));
    ok('Produto (trocar): demais opções = ativos fora da composição', count($opT) === count($espP) + 1 && !isset($opT[$ids['corte']]) && !isset($opT[$ids['inativo']]));
    $simT = simularBusca($xT, [['digitar' => 'prata'], ['digitar' => 'cartao'], ['digitar' => '']]);
    ok('Produto (trocar): custo atual continua selecionado ao buscar um termo que o encontra',
        is_array($simT) && $simT[0]['valor'] === (string) $ids['hotstamp'] && $simT[1]['valor'] === (string) $ids['hotstamp'] && $simT[1]['changes'] === 0);
    ok('Produto (trocar): termo que não o encontra limpa a seleção e avisa a prévia (change)',
        is_array($simT) && $simT[2]['valor'] === '' && $simT[2]['changes'] === 1);

    // --- Teclado: Enter escolhe o primeiro encontrado sem enviar o formulário; seleção mantida se continua visível ---
    $simK = simularBusca($x, [['digitar' => 'cartao 66x96'], ['tecla' => 'Enter'], ['digitar' => '250'], ['digitar' => 'cmyk'], ['tecla' => 'ArrowDown']]);
    $primeiro = $simK[1]['visiveis'][0] ?? null;
    ok('Busca: Enter seleciona o primeiro encontrado, não envia o formulário e passa o foco para a lista',
        is_array($simK) && $simK[2]['prevenido'] && $simK[2]['valor'] === $primeiro && $simK[2]['focado'] && $simK[2]['changes'] === 1);
    ok('Busca: seleção mantida quando o novo termo ainda encontra o custo', is_array($simK) && $simK[3]['valor'] === $primeiro && $simK[3]['changes'] === 1);
    ok('Busca: seleção limpa quando o novo termo não encontra mais o custo', is_array($simK) && $simK[4]['valor'] === '' && $simK[4]['changes'] === 2);
    ok('Busca: seta para baixo leva o foco à lista sem enviar', is_array($simK) && $simK[5]['prevenido']);

    // --- 8: Produto, incluir pelo formulário renderizado ---
    $dados = dadosFormulario($x, "//form[@id='form-add-custo']", ['material_id' => (string) $ids['cartao'], 'quantidade' => '1000']);
    ok('Produto: formulário não envia o campo de busca', is_array($dados) && array_diff(array_keys($dados), ['material_id', 'quantidade']) === []);
    $r = rota('POST', "/produtos/{$p}/composicao", $dados ?? []);
    $st = $pdo->prepare('SELECT quantidade, modo FROM produto_composicao WHERE produto_id = :p AND material_id = :m');
    $st->execute(['p' => $p, 'm' => $ids['cartao']]);
    $linha = $st->fetch();
    ok('Produto: custo selecionado é incluído como antes (quantidade 1000, modalidade folha)',
        $linha && abs((float) $linha['quantidade'] - 1000) < 1e-9 && $linha['modo'] === 'folha', flashTexto($r, 'errors') . flashTexto($r, 'success'));
    $x2 = dom(rota('GET', "/produtos/{$p}/composicao/cadastrado/novo")['html']);
    ok('Produto: custo recém-incluído deixa de ser oferecido', !isset((opcoesRenderizadas($x2) ?? [])[$ids['cartao']]));
    $r = rota('POST', "/produtos/{$p}/composicao", ['material_id' => (string) $ids['inativo'], 'quantidade' => '1']);
    ok('Produto: servidor continua recusando custo inativo', str_contains(flashTexto($r, 'errors'), 'inativo'), flashTexto($r, 'errors'));
    $r = rota('POST', "/produtos/{$p}/composicao", ['material_id' => (string) $ids['corte'], 'quantidade' => '1']);
    ok('Produto: servidor continua recusando custo repetido', str_contains(flashTexto($r, 'errors'), 'já está'), flashTexto($r, 'errors'));
    $dadosT = dadosFormulario($xT, "//form[.//select[@id='material_id']]", ['quantidade' => '2']);
    $r = rota('POST', "/produtos/{$p}/composicao/{$linhaHot}", $dadosT ?? []);
    $st = $pdo->prepare('SELECT material_id, quantidade FROM produto_composicao WHERE id = :id');
    $st->execute(['id' => $linhaHot]);
    $lt = $st->fetch();
    ok('Produto (trocar): salvar mantendo o custo atual inativo continua permitido',
        $lt && (int) $lt['material_id'] === $ids['hotstamp'] && abs((float) $lt['quantidade'] - 2) < 1e-9, flashTexto($r, 'errors'));

    // --- 7: Variação, seleção normal, troca e inclusão ---
    $xv = dom(rota('GET', "/produtos/{$p}/variacoes/{$v}/composicao/cadastrado/novo")['html']);
    $espV = elegiveis('variacao_composicao', 'variacao_id', $v);
    $doSistemaV = array_map(static fn ($r) => (int) $r['id'], Composicao::materiaisAtivosDisponiveisVariacao($v));
    sort($doSistemaV);
    ok('Variação: elegíveis = consulta do sistema (materiaisAtivosDisponiveisVariacao)', $doSistemaV === $espV, 'elegíveis=' . count($espV));
    verificarTela('Variação (adicionar)', $xv, $espV,
        ['custo inativo' => $ids['inativo'], 'custo já incluído (CMYK)' => $ids['cmyk'], 'custo incluído e depois inativado' => $ids['hotstamp']], $ids);
    ok('Variação: lista é própria (Corte e Vinco do produto é oferecido na variação)', isset((opcoesRenderizadas($xv) ?? [])[$ids['corte']]));

    $xvT = dom(rota('GET', "/produtos/{$p}/variacoes/{$v}/composicao/{$linhaHotV}/editar")['html']);
    $opVT = opcoesRenderizadas($xvT) ?? [];
    ok('Variação (trocar): custo atual inativo aparece, selecionado e marcado "INATIVO (atual)"',
        isset($opVT[$ids['hotstamp']]) && $opVT[$ids['hotstamp']]['selected'] && str_contains($opVT[$ids['hotstamp']]['texto'], 'INATIVO (atual)'));
    ok('Variação (trocar): demais opções = ativos fora da composição', count($opVT) === count($espV) + 1 && !isset($opVT[$ids['cmyk']]));

    $dadosV = dadosFormulario($xv, "//form[.//select[@id='material_id']]", ['material_id' => (string) $ids['agua'], 'quantidade' => '1500']);
    ok('Variação: formulário não envia o campo de busca', array_diff(array_keys($dadosV ?? []), ['material_id', 'quantidade']) === []);
    $r = rota('POST', "/produtos/{$p}/variacoes/{$v}/composicao", $dadosV ?? []);
    $st = $pdo->prepare('SELECT quantidade, modo FROM variacao_composicao WHERE variacao_id = :v AND material_id = :m');
    $st->execute(['v' => $v, 'm' => $ids['agua']]);
    $lv = $st->fetch();
    ok('Variação: custo selecionado é incluído como antes (quantidade 1500, modalidade milheiro)',
        $lv && abs((float) $lv['quantidade'] - 1500) < 1e-9 && $lv['modo'] === 'milheiro', flashTexto($r, 'errors') . flashTexto($r, 'success'));
    $r = rota('POST', "/produtos/{$p}/variacoes/{$v}/composicao", ['material_id' => (string) $ids['inativo'], 'quantidade' => '1']);
    ok('Variação: servidor continua recusando custo inativo', flashTexto($r, 'errors') !== '', flashTexto($r, 'errors'));
    $dadosVT = dadosFormulario($xvT, "//form[.//select[@id='material_id']]", ['quantidade' => '3']);
    $r = rota('POST', "/produtos/{$p}/variacoes/{$v}/composicao/{$linhaHotV}", $dadosVT ?? []);
    $st = $pdo->prepare('SELECT material_id, quantidade FROM variacao_composicao WHERE id = :id');
    $st->execute(['id' => $linhaHotV]);
    $lvt = $st->fetch();
    ok('Variação (trocar): salvar mantendo o custo atual inativo continua permitido',
        $lvt && (int) $lvt['material_id'] === $ids['hotstamp'] && abs((float) $lvt['quantidade'] - 3) < 1e-9, flashTexto($r, 'errors'));
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM materiais WHERE nome LIKE 'TST-CBUSCA-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-CBUSCA-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-CBUSCA-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('custos, produtos, variações, composições, histórico, logs e estoque permanentes intactos', (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

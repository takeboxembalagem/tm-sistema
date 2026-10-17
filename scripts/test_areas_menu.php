<?php
/**
 * Testes locais — área única (T&M) e menu retrátil.
 * Chama as rotas reais como ADMIN, como USUÁRIO e sem login: telas atuais intactas, 11 links,
 * área ativa, grupo aberto, rotas comerciais removidas → 404, e o JavaScript real do menu
 * (public/assets/js/app.js) executado no Node.
 * Cria dados temporários (TST-AREAS-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_areas_menu.php
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
$conexao = $pdo->query('SELECT DATABASE() AS banco, @@port AS porta')->fetch();
if ($conexao['banco'] !== 'tm_sistema_dev' || (int) $conexao['porta'] !== 3308) {
    exit('ABORTADO: o teste só roda no banco local tm_sistema_dev (127.0.0.1:3308).' . PHP_EOL);
}
$pdo->exec('SET SESSION group_concat_max_len = 16777216');
$sufixo = date('His') . random_int(100, 999);
$prefixo = 'TST-AREAS-' . $sufixo;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_areas_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$nodeScript = $tmpDir . DIRECTORY_SEPARATOR . 'menu.js';
$nodeInput = $tmpDir . DIRECTORY_SEPARATOR . 'menu.json';
$usuariosTmp = [];
$produtosTmp = [];
$materiaisTmp = [];

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_PERMANENTE = "SELECT MD5(CONCAT_WS('#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, email, status, perfil, senha_hash) ORDER BY id) FROM usuarios WHERE email NOT LIKE 'tst-areas-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, nome, status, custo_atual) ORDER BY id) FROM materiais WHERE nome NOT LIKE 'TST-AREAS-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, codigo_interno, status, IFNULL(preco_praticado, 'N')) ORDER BY id) FROM produtos WHERE codigo_interno NOT LIKE 'TST-AREAS-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, estoque, status) ORDER BY id) FROM produto_variacoes), ''),
        (SELECT COUNT(*) FROM produto_composicao), (SELECT COUNT(*) FROM variacao_composicao),
        (SELECT COUNT(*) FROM historico_custos), (SELECT COUNT(*) FROM historico_precos), (SELECT COUNT(*) FROM logs_alteracoes),
        (SELECT COUNT(*) FROM estoque_pecas_movimentos), (SELECT COUNT(*) FROM estoque_papeis_movimentos),
        (SELECT COUNT(*) FROM colecoes), (SELECT COUNT(*) FROM agenda_tarefas), (SELECT COUNT(*) FROM arquivos_produtos),
        (SELECT COUNT(*) FROM login_tentativas)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();

/** Executa a rota real num subprocesso; $userId = 0 → sem login. Devolve html, status HTTP e flash. */
function rota(string $method, string $uri, int $userId, array $post = [], bool $comCsrf = true): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'method' => $method,
        'uri' => $uri,
        'post' => $comCsrf ? $post + ['_csrf' => $token] : $post,
        'token' => $token,
        'user_id' => $userId,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
    ];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['html' => $out, 'status' => null, 'flash' => null];
    }
    $res = json_decode(substr($out, $pos + 10), true) ?: [];
    $res['html'] = substr($out, 0, $pos);
    $res['status'] = ($res['status'] ?? false) === false ? 200 : (int) $res['status'];
    return $res;
}

function dom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

function erroPhp(string $html): bool
{
    // Ignora aviso conhecido do php.ini local (mysqli carregado duas vezes); não é erro da aplicação.
    $html = preg_replace('/Warning:\s*Module "mysqli" is already loaded in Unknown on line 0\s*/', '', $html) ?? $html;
    return (bool) preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)/', $html);
}

/** Estado do menu renderizado. */
function menu(DOMXPath $x): array
{
    $areas = [];
    foreach ($x->query("//nav[contains(@class,'sidebar-nav')]/div[contains(concat(' ', @class, ' '),' nav-area ')]") as $div) {
        $head = $x->query("./a[contains(@class,'nav-area-head')]", $div)->item(0);
        $painel = $x->query("./div[contains(@class,'nav-area-items')]", $div)->item(0);
        $itens = [];
        foreach ($painel ? $x->query('./a', $painel) : [] as $a) {
            $itens[] = [
                'href' => $a->getAttribute('href'),
                'title' => $a->getAttribute('title'),
                'icone' => trim($x->query('./span', $a)->item(0)->textContent ?? ''),
                'rotulo' => trim($x->query('./em', $a)->item(0)->textContent ?? ''),
                'ativo' => $a->getAttribute('class') === 'is-active',
            ];
        }
        $areas[$div->getAttribute('data-area')] = [
            'atual' => str_contains(' ' . $div->getAttribute('class') . ' ', ' is-current '),
            'head_href' => $head ? $head->getAttribute('href') : null,
            'expandido' => $head ? $head->getAttribute('aria-expanded') : null,
            'controla' => $head ? $head->getAttribute('aria-controls') : null,
            'painel_id' => $painel ? $painel->getAttribute('id') : null,
            'aberto' => $painel && !$painel->hasAttribute('hidden'),
            'itens' => $itens,
        ];
    }
    $badge = $x->query("//header[contains(@class,'topbar')]//span[contains(@class,'area-badge')]")->item(0);
    $brand = $x->query("//div[contains(@class,'sidebar-brand')]")->item(0);
    return [
        'areas' => $areas,
        'badge' => $badge ? trim($badge->textContent) : null,
        'badge_area' => $badge ? $badge->getAttribute('data-area') : null,
        'brand_area' => $brand ? $brand->getAttribute('data-area-atual') : null,
        'brand_nome' => $brand ? trim($x->query(".//div[contains(@class,'brand-text')]/strong", $brand)->item(0)->textContent ?? '') : null,
    ];
}

/** Um único grupo aberto: o da área esperada (cabeçalho aria-expanded e painel coerentes). */
function grupoUnicoAberto(array $m, string $area): bool
{
    $abertos = array_keys(array_filter($m['areas'], static fn ($a) => $a['aberto']));
    foreach ($m['areas'] as $chave => $a) {
        if (($a['expandido'] === 'true') !== ($chave === $area) || $a['atual'] !== ($chave === $area) || $a['controla'] !== $a['painel_id']) {
            return false;
        }
    }
    return $abertos === [$area];
}

/** Roda o app.js real no Node com um DOM mínimo montado a partir do menu renderizado. */
function simularMenu(array $m, array $cenarios): ?array
{
    global $nodeScript, $nodeInput;
    $heads = [];
    foreach ($m['areas'] as $chave => $a) {
        $heads[] = ['area' => $chave, 'expandido' => $a['expandido'], 'controla' => $a['controla'], 'aberto' => $a['aberto']];
    }
    file_put_contents($nodeInput, json_encode([
        'script' => file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js'),
        'heads' => $heads,
        'cenarios' => $cenarios,
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($nodeScript, <<<'JS'
const fs = require('fs');
const d = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const resultados = [];
for (const c of d.cenarios) {
    const attrs = { 'data-sidebar': c.sidebar };
    const layout = { getAttribute: (n) => attrs[n], setAttribute: (n, v) => { attrs[n] = v; } };
    const toggleListeners = {};
    const toggle = { addEventListener: (t, f) => { toggleListeners[t] = f; } };
    const paineis = {};
    const heads = d.heads.map((h) => {
        const a = { 'aria-expanded': h.expandido, 'aria-controls': h.controla };
        paineis[h.controla] = { hidden: !h.aberto };
        const listeners = {};
        return {
            area: h.area, listeners,
            getAttribute: (n) => a[n], setAttribute: (n, v) => { a[n] = v; },
            addEventListener: (t, f) => { listeners[t] = f; },
        };
    });
    const store = {};
    globalThis.localStorage = { getItem: (k) => store[k] || null, setItem: (k, v) => { store[k] = v; } };
    globalThis.window = { matchMedia: () => ({ matches: c.largura > 800 }) };
    globalThis.document = {
        querySelector: (s) => (s === '.layout' ? layout : null),
        querySelectorAll: (s) => (s === '.nav-area-head' ? heads : []),
        getElementById: (id) => (id === 'toggle-sidebar' ? toggle : (paineis[id] || null)),
    };
    eval(d.script);
    const passos = [];
    for (const area of c.cliques) {
        const h = heads.find((x) => x.area === area);
        let prevenido = false;
        h.listeners.click({ preventDefault() { prevenido = true; } });
        passos.push({
            clicou: area, navegou: !prevenido,
            expandidos: heads.filter((x) => x.getAttribute('aria-expanded') === 'true').map((x) => x.area),
            abertos: heads.filter((x) => !paineis[x.getAttribute('aria-controls')].hidden).map((x) => x.area),
        });
    }
    if (c.alternarBarra) {
        toggleListeners.click();
    }
    resultados.push({ passos, sidebar: attrs['data-sidebar'], salvo: store['tm-sidebar'] || null });
}
process.stdout.write(JSON.stringify(resultados));
JS);
    $res = json_decode((string) shell_exec('node ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($nodeInput) . ' 2>&1'), true);
    return is_array($res) ? $res : null;
}

function novoUsuario(string $perfil): int
{
    global $usuariosTmp, $sufixo;
    $id = Usuario::create([
        'nome' => 'TST AREAS ' . $perfil,
        'email' => 'tst-areas-' . $sufixo . '-' . $perfil . '@teste.local',
        'senha_hash' => Usuario::hashSenha('senha-' . $perfil . '-' . $sufixo),
        'status' => 'ativo',
        'perfil' => $perfil,
    ]);
    $usuariosTmp[] = $id;
    return $id;
}

const LINKS_PM = [
    ['/produtos', 'Produtos', '📦'], ['/custos', 'Custos', '💰'], ['/tabela-precos', 'Tabela de Preços', '📊'],
    ['/colecoes', 'Coleções', '🎨'], ['/estoque/pecas', 'Estoque', '🗃️'], ['/etiquetas', 'Etiquetas', '🏷️'],
    ['/arquivos', 'Arquivos', '📁'], ['/agenda', 'Agenda', '📅'], ['/relatorios', 'Relatórios', '📈'],
    ['/empresa', 'Empresa', '🏢'], ['/configuracoes', 'Configurações', '⚙️'],
];

try {
    $categoriaId = (int) ($pdo->query("SELECT id FROM categorias_materiais WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $unidadeId = (int) ($pdo->query("SELECT id FROM unidades_medida WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($categoriaId === 0 || $unidadeId === 0) {
        throw new RuntimeException('Faltam categoria/unidade ativas locais para o teste.');
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
if ((int) $req['user_id'] > 0) {
    // Perfil da sessão propositalmente 'usuario': o Auth deve sincronizar com o banco a cada requisição.
    $_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode(['status' => http_response_code(), 'flash' => $_SESSION['_flash'] ?? null]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $admin = novoUsuario('admin');
    $user = novoUsuario('usuario');
    ok('setup: usuários temporários admin e usuario', Usuario::find($admin)['perfil'] === 'admin' && Usuario::find($user)['perfil'] === 'usuario');

    $materialId = Material::create([
        'nome' => $prefixo . ' Custo', 'categoria_material_id' => $categoriaId, 'unidade_id' => $unidadeId,
        'forma_cobranca' => 'unidade', 'custo_atual' => 1.5, 'fornecedor' => null, 'observacao' => null, 'status' => 'ativo',
    ]);
    $materiaisTmp[] = $materialId;
    $produtoId = Produto::create([
        'codigo_interno' => $prefixo . '-P', 'nome' => 'Teste áreas', 'tamanho' => null, 'tamanho_aberta' => null,
        'descricao' => null, 'categoria_id' => null, 'colecao_id' => null, 'foto_principal' => null, 'status' => 'ativo',
        'quantidade_pacote' => null, 'peso_pacote_kg' => null,
    ]);
    $produtosTmp[] = $produtoId;

    // ================= Areas: sem prefixos de área secundária =================
    $casos = ['/takebox', '/takebox/', '/takebox/clientes/1', '/TAKEBOX', '/central', '/central/atendimentos',
        '/takeboxx', '/centralizar', '/produtos', '/', '/configuracoes/usuarios/1'];
    $erradosPrefixo = [];
    foreach ($casos as $caminho) {
        if (Areas::doCaminho($caminho) !== null) {
            $erradosPrefixo[] = $caminho;
        }
    }
    ok('Areas::doCaminho sempre null (edição com área única)', $erradosPrefixo === [],
        implode(', ', $erradosPrefixo));
    ok('Areas::todas contém só papel_magenta', array_keys(Areas::todas()) === ['papel_magenta']);

    // ================= 1/2/3/4: telas atuais da área principal =================
    // [rota, page do item ativo, status admin, status usuário] — 302 = restrição administrativa já existente.
    $telas = [
        ['/produtos', 'produtos', 200, 200], ['/produtos/novo', 'produtos', 200, 200], ["/produtos/{$produtoId}", 'produtos', 200, 200],
        ["/produtos/{$produtoId}/editar", 'produtos', 200, 200], ["/produtos/{$produtoId}/composicao/cadastrado/novo", 'produtos', 200, 200],
        ['/custos', 'custos', 200, 200], ['/custos/novo', 'custos', 200, 302], ["/custos/{$materialId}", 'custos', 200, 200],
        ["/custos/{$materialId}/editar", 'custos', 200, 302], ['/tabela-precos', 'tabela', 200, 200], ['/colecoes', 'colecoes', 200, 200],
        ['/estoque/pecas', 'estoque', 200, 200], ['/estoque/papeis', 'estoque', 200, 200], ['/etiquetas', 'etiquetas', 200, 200],
        ['/arquivos', 'arquivos', 200, 200], ['/agenda', 'agenda', 200, 200], ['/relatorios', 'relatorios', 200, 200],
        ['/relatorios/estoque', 'relatorios', 200, 200], ['/empresa', 'empresa', 200, 200], ['/empresa/editar', 'empresa', 200, 302],
        ['/configuracoes', 'configuracoes', 200, 200], ['/configuracoes/senha', 'configuracoes', 200, 200],
        ['/configuracoes/usuarios/novo', 'configuracoes', 200, 302],
    ];
    $linksEsperados = array_map(static fn ($l) => ['href' => url($l[0]), 'title' => $l[1], 'icone' => $l[2], 'rotulo' => $l[1]], LINKS_PM);
    foreach (['admin' => $admin, 'usuário' => $user] as $rotulo => $uid) {
        $falhasStatus = [];
        $falhasMenu = [];
        foreach ($telas as [$uri, $pageAtivo, $stAdmin, $stUser]) {
            $esperado = $uid === $admin ? $stAdmin : $stUser;
            $r = rota('GET', $uri, $uid);
            if ($r['status'] !== $esperado || erroPhp($r['html'])) {
                $falhasStatus[] = "{$uri}={$r['status']}" . (erroPhp($r['html']) ? '+erroPHP' : '');
                continue;
            }
            if ($esperado !== 200) {
                continue;
            }
            $m = menu(dom($r['html']));
            $pm = $m['areas']['papel_magenta'] ?? null;
            $links = $pm ? array_map(static fn ($i) => array_diff_key($i, ['ativo' => 1]), $pm['itens']) : [];
            $ativos = $pm ? array_values(array_filter($pm['itens'], static fn ($i) => $i['ativo'])) : [];
            $esperadoAtivo = $pageAtivo === null ? [] : [array_column(Areas::todas()['papel_magenta']['itens'], 'rotulo', 'page')[$pageAtivo]];
            $problemas = [];
            if ($links !== $linksEsperados) {
                $problemas[] = '11 links diferentes';
            }
            if (array_column($ativos, 'title') !== $esperadoAtivo) {
                $problemas[] = 'item ativo=' . implode(',', array_column($ativos, 'title'));
            }
            if (!grupoUnicoAberto($m, 'papel_magenta')) {
                $problemas[] = 'grupos abertos incorretos';
            }
            if ($m['badge'] !== 'T&M' || $m['brand_area'] !== 'papel_magenta' || $m['brand_nome'] !== 'T&M') {
                $problemas[] = 'área ativa=' . $m['badge'];
            }
            if ($problemas) {
                $falhasMenu[] = $uri . ' (' . implode('; ', $problemas) . ')';
            }
        }
        ok("Área principal ({$rotulo}): " . count($telas) . ' telas com o mesmo resultado de antes e sem erro de PHP', $falhasStatus === [], implode(', ', $falhasStatus));
        ok("Área principal ({$rotulo}): 11 links atuais (mesma ordem, rota, nome e ícone), item ativo certo, só o grupo T&M aberto e área indicada",
            $falhasMenu === [], implode(' | ', $falhasMenu));
    }

    // ================= 5: menu com área única =================
    $mAdmin = menu(dom(rota('GET', '/produtos', $admin)['html']));
    ok('Admin: menu com só T&M e cabeçalho para /produtos',
        array_keys($mAdmin['areas']) === ['papel_magenta']
        && array_column($mAdmin['areas'], 'head_href') === [url('/produtos')]
        && grupoUnicoAberto($mAdmin, 'papel_magenta')
        && $mAdmin['badge'] === 'T&M' && $mAdmin['brand_nome'] === 'T&M');

    // ================= 6: usuário comum — mesma área; rotas removidas → 404 =================
    $mUser = menu(dom(rota('GET', '/produtos', $user)['html']));
    $htmlUser = rota('GET', '/agenda', $user)['html'];
    ok('Usuário: menu mostra só T&M (sem links Takebox/Central)',
        array_keys($mUser['areas']) === ['papel_magenta'] && !str_contains($htmlUser, url('/takebox')) && !str_contains($htmlUser, url('/central')));
    $tentativas = [
        ['GET', '/takebox'], ['GET', '/takebox/'], ['GET', '/TAKEBOX'], ['GET', '/takebox/clientes'],
        ['POST', '/takebox'], ['GET', '/central'], ['GET', '/central/atendimentos'],
        ['POST', '/central'], ['POST', '/central/atendimentos'],
    ];
    foreach ($tentativas as [$metodo, $uri]) {
        $r = rota($metodo, $uri, $user, ['nome' => 'x']);
        ok("Usuário: {$metodo} {$uri} → 404 (módulo removido)",
            $r['status'] === 404 && !erroPhp($r['html']), "status={$r['status']}");
    }
    foreach (['/takebox', '/central', '/central/atendimentos'] as $uri) {
        $r = rota('GET', $uri, 0);
        ok("Sem login: GET {$uri} → 404 (módulo removido; sem rota para exigir login)", $r['status'] === 404 && !erroPhp($r['html']), "status={$r['status']}");
    }

    // ================= 7: comportamento do menu (JS real) e layout =================
    $sim = simularMenu($mAdmin, [
        ['sidebar' => 'open', 'largura' => 1280, 'cliques' => ['papel_magenta']],
        ['sidebar' => 'collapsed', 'largura' => 1280, 'cliques' => ['papel_magenta']],
        ['sidebar' => 'open', 'largura' => 390, 'cliques' => ['papel_magenta'], 'alternarBarra' => true],
    ]);
    ok('JS do menu executado no Node', is_array($sim) && count($sim) === 3, is_array($sim) ? '' : 'sem resultado');
    if (is_array($sim)) {
        [$a, $b, $c] = $sim;
        ok('Barra aberta: clicar no cabeçalho da área já aberta leva à página inicial',
            $a['passos'][0]['navegou'] && $a['passos'][0]['abertos'] === ['papel_magenta']);
        ok('Barra recolhida (só ícones): o ícone da área leva direto à página inicial',
            $b['passos'][0]['navegou'] && $b['passos'][0]['abertos'] === ['papel_magenta']);
        ok('Tela pequena: o botão ☰ recolhe a barra e guarda a preferência',
            $c['sidebar'] === 'collapsed' && $c['salvo'] === 'collapsed');
    }
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    ok('CSS: grupos recolhidos ficam ocultos e o painel aberto aparece', str_contains($css, '.nav-area-items[hidden] { display: none; }'));
    ok('CSS: barra recolhida mantém só os ícones (textos e seta ocultos) e não muda a largura atual',
        str_contains($css, '.layout[data-sidebar="collapsed"] .sidebar-nav em,') && str_contains($css, '.layout[data-sidebar="collapsed"] .nav-area-caret { display: none; }')
        && str_contains($css, '--sidebar-collapsed: 76px;'));
    ok('CSS: em telas pequenas a gaveta mostra textos e setas (regras do ≤ 800px preservadas)',
        (bool) preg_match('/@media \(max-width: 800px\) \{\s*\.sidebar \{ position: fixed;[^}]*\}[\s\S]*?\.layout\[data-sidebar="collapsed"\] \.nav-area-caret \{ display: inline; \}/', $css));
    ok('CSS: sem estilos de área Takebox/Central comerciais',
        !str_contains($css, '.nav-area[data-area="takebox"]') && !str_contains($css, '.nav-area[data-area="central"]')
        && !str_contains($css, '.central-kpis') && !str_contains($css, '.tb-form'));
    ok('Sem JavaScript: cabeçalho real para /produtos e só o grupo T&M aberto',
        array_column($mAdmin['areas'], 'head_href') === [url('/produtos')] && grupoUnicoAberto($mAdmin, 'papel_magenta'));
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($produtosTmp as $pid) {
        $pdo->prepare('DELETE FROM produto_composicao WHERE produto_id = :id')->execute(['id' => $pid]);
        $pdo->prepare('DELETE FROM produtos WHERE id = :id')->execute(['id' => $pid]);
    }
    foreach ($materiaisTmp as $mid) {
        $pdo->prepare('DELETE FROM historico_custos WHERE material_id = :id')->execute(['id' => $mid]);
        $pdo->prepare('DELETE FROM materiais WHERE id = :id')->execute(['id' => $mid]);
    }
    foreach ($usuariosTmp as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $uid]);
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
    if (is_dir($tmpDir)) {
        rmdir($tmpDir);
    }
}

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-areas-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM materiais WHERE nome LIKE 'TST-AREAS-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-AREAS-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada (nenhuma tabela ou coluna criada)', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('usuários, custos, produtos, variações, composições, históricos, estoque e tentativas de login permanentes intactos',
    (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

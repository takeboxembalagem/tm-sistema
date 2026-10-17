<?php
/**
 * Testes locais — autorização por perfil (P1-5).
 * Chama as rotas reais como ADMIN e como USUÁRIO: operações administrativas são bloqueadas
 * no servidor para o usuário; as operacionais continuam liberadas; CSRF segue valendo.
 * Cria dados temporários (TST-AUTHZ-*), restaura Empresa e Tabela de Preços e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_autorizacao_perfis.php
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
$prefixo = 'TST-AUTHZ-' . $sufixo;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_authz_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';
$usuariosTmp = [];
$arquivosFisicos = [];

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_PERMANENTE = "SELECT MD5(CONCAT_WS('#',
        (SELECT GROUP_CONCAT(CONCAT_WS(':', id, razao_social, IFNULL(cnpj, 'N'), IFNULL(endereco, 'N'), IFNULL(observacoes, 'N'), updated_at) ORDER BY id) FROM empresa),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, banco, agencia, conta, ativo) ORDER BY id) FROM empresa_contas), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, chave, ativo) ORDER BY id) FROM empresa_pix), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, caminho_arquivo) ORDER BY id) FROM empresa_documentos), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', imposto_percentual, incluir_comissao, comissao_percentual, updated_at)) FROM tabela_preco_config), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, margem_percentual, ordem, updated_at) ORDER BY id) FROM tabela_preco_margens_cfg), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, nome, status, custo_atual) ORDER BY id) FROM materiais WHERE nome NOT LIKE 'TST-AUTHZ-%'), ''),
        (SELECT COUNT(*) FROM historico_custos), (SELECT COUNT(*) FROM logs_alteracoes), (SELECT COUNT(*) FROM historico_precos),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, status, perfil, senha_hash) ORDER BY id) FROM usuarios WHERE email NOT LIKE 'tst-authz-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, IFNULL(preco_praticado, 'N'), status) ORDER BY id) FROM produtos WHERE codigo_interno NOT LIKE 'TST-AUTHZ-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, estoque, status) ORDER BY id) FROM produto_variacoes), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, quantidade, status) ORDER BY id) FROM estoque_pecas WHERE codigo NOT LIKE 'TST-AUTHZ-%'), ''),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, quantidade, status) ORDER BY id) FROM estoque_papeis WHERE codigo NOT LIKE 'TST-AUTHZ-%'), ''),
        (SELECT COUNT(*) FROM estoque_pecas_movimentos), (SELECT COUNT(*) FROM estoque_papeis_movimentos),
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, status) ORDER BY id) FROM colecoes), ''),
        (SELECT COUNT(*) FROM colecao_variacoes), (SELECT COUNT(*) FROM colecao_tarefas),
        (SELECT COUNT(*) FROM agenda_tarefas), (SELECT COUNT(*) FROM arquivos_produtos),
        (SELECT COUNT(*) FROM produto_composicao), (SELECT COUNT(*) FROM variacao_composicao)))";

$sigEstrutura = (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn();
$sigPermanente = (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn();
$empresaOriginal = $pdo->query('SELECT * FROM empresa ORDER BY id LIMIT 1')->fetch() ?: null;
$empresaCriadaParaTeste = false;
if (!$empresaOriginal) {
    // Instalação limpa não traz linha em empresa (cadastro via UI). Placeholder temporário só para este teste.
    $pdo->exec("INSERT INTO empresa (id, razao_social) VALUES (1, 'TST-AUTHZ Empresa Temporaria T&M')");
    $empresaOriginal = $pdo->query('SELECT * FROM empresa WHERE id = 1')->fetch() ?: null;
    $empresaCriadaParaTeste = true;
}
$configOriginal = $pdo->query('SELECT * FROM tabela_preco_config WHERE id = 1')->fetch() ?: null;
$margensOriginais = $pdo->query('SELECT id, ordem, updated_at FROM tabela_preco_margens_cfg')->fetchAll();

/** Executa a rota real num subprocesso, logado como $userId; devolve HTML e flash. */
function rota(string $method, string $uri, int $userId, array $post = [], array $files = [], array $sessao = []): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'method' => $method,
        'uri' => $uri,
        'post' => $post + ['_csrf' => $token],
        'files' => $files,
        'sessao' => $sessao,
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

function bloqueado(array $r): bool
{
    return str_contains(flashTexto($r, 'errors'), 'Você não tem permissão');
}

function csrfRecusado(array $r): bool
{
    return str_contains($r['html'], 'Sessão expirada ou token inválido');
}

function valor(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function novoUsuario(string $perfil, string $senha): int
{
    global $usuariosTmp, $sufixo;
    $id = Usuario::create([
        'nome' => 'TST AUTHZ ' . $perfil,
        'email' => 'tst-authz-' . $sufixo . '-' . $perfil . '@teste.local',
        'senha_hash' => Usuario::hashSenha($senha),
        'status' => 'ativo',
        'perfil' => $perfil,
    ]);
    $usuariosTmp[] = $id;
    return $id;
}

/** Arquivo PDF mínimo temporário no formato de $_FILES (aceito com PM_TEST_UPLOAD). */
function pdfUpload(string $nome): array
{
    global $tmpDir;
    $tmp = $tmpDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '.tmp';
    $conteudo = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    file_put_contents($tmp, $conteudo);
    return ['arquivo' => ['name' => $nome, 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($conteudo)]];
}

function arquivoExiste(string $caminho): bool
{
    clearstatcache();
    return is_file(Upload::absolutePath($caminho));
}

try {
    $categoriaId = (int) ($pdo->query("SELECT id FROM categorias_materiais WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $unidadeId = (int) ($pdo->query("SELECT id FROM unidades_medida WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($categoriaId === 0 || $unidadeId === 0 || !$empresaOriginal || !$configOriginal) {
        throw new RuntimeException('Faltam categoria/unidade ativas, empresa ou configuração da tabela local para o teste.');
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
if ($req['files']) {
    define('PM_TEST_UPLOAD', true);
    $_FILES = $req['files'];
}
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
$_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
$_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
foreach ($req['sessao'] as $k => $v) {
    $_SESSION[$k] = $v;
}
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode(['flash' => $_SESSION['_flash'] ?? null]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $senhaUser = 'senha-user-' . $sufixo;
    $admin = novoUsuario('admin', 'senha-admin-' . $sufixo);
    $user = novoUsuario('usuario', $senhaUser);
    ok('setup: perfis temporários admin e usuario', Usuario::find($admin)['perfil'] === 'admin' && Usuario::find($user)['perfil'] === 'usuario');

    // ================= PRODUTO / VARIAÇÃO / COMPOSIÇÃO (usuário) =================
    $r = rota('POST', '/produtos', $user, ['codigo_interno' => $prefixo . '-P', 'nome' => 'Produto authz']);
    $p = (int) valor('SELECT id FROM produtos WHERE codigo_interno = :c', ['c' => $prefixo . '-P']);
    ok('usuário: cria produto', $p > 0, flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p, $user, ['codigo_interno' => $prefixo . '-P', 'nome' => 'Produto authz editado', 'status' => 'ativo']);
    ok('usuário: edita produto', valor('SELECT nome FROM produtos WHERE id = :id', ['id' => $p]) === 'Produto authz editado', flashTexto($r, 'errors'));
    rota('POST', '/produtos/' . $p . '/status', $user);
    $st1 = valor('SELECT status FROM produtos WHERE id = :id', ['id' => $p]);
    rota('POST', '/produtos/' . $p . '/status', $user);
    ok('usuário: inativa e reativa produto', $st1 === 'inativo' && valor('SELECT status FROM produtos WHERE id = :id', ['id' => $p]) === 'ativo');
    rota('POST', '/produtos/' . $p . '/duplicar', $user);
    ok('usuário: duplica produto', (int) valor('SELECT COUNT(*) FROM produtos WHERE codigo_interno = :c', ['c' => $prefixo . '-P-COPIA']) === 1);
    $r = rota('POST', '/produtos/' . $p . '/tiragem', $user, ['quantidade_producao' => '10', 'rendimento_folha' => '2']);
    ok('usuário: altera tiragem', (int) valor('SELECT rendimento_folha FROM produtos WHERE id = :id', ['id' => $p]) === 2, flashTexto($r, 'errors'));

    $r = rota('POST', '/produtos/' . $p . '/variacoes', $user, [
        'codigo' => $prefixo . '-V', 'nome' => 'Variação authz', 'estoque' => '3', 'status' => 'ativo',
        'custo_modo' => 'herdar', 'pacote_modo' => 'herdar',
    ]);
    $v = (int) valor('SELECT id FROM produto_variacoes WHERE codigo = :c', ['c' => $prefixo . '-V']);
    ok('usuário: cria variação', $v > 0, flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v, $user, [
        'codigo' => $prefixo . '-V', 'nome' => 'Variação authz editada', 'status' => 'ativo', 'custo_modo' => 'herdar', 'pacote_modo' => 'herdar',
    ]);
    ok('usuário: edita variação', valor('SELECT nome FROM produto_variacoes WHERE id = :id', ['id' => $v]) === 'Variação authz editada', flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/estoque', $user, ['estoque' => '7', 'motivo' => 'ajuste_manual']);
    ok('usuário: ajusta estoque da variação', (int) valor('SELECT estoque FROM produto_variacoes WHERE id = :id', ['id' => $v]) === 7, flashTexto($r, 'errors'));

    // ================= CUSTOS =================
    $postCusto = static fn (string $nome, string $custo, string $status = 'ativo'): array => [
        'nome' => $nome, 'categoria_material_id' => (string) $categoriaId, 'unidade_id' => (string) $unidadeId,
        'forma_cobranca' => 'unidade', 'custo_atual' => $custo, 'status' => $status,
    ];
    $r = rota('GET', '/custos/novo', $user);
    ok('usuário: GET /custos/novo bloqueado', bloqueado($r) && !str_contains($r['html'], 'name="custo_atual"'));
    $r = rota('POST', '/custos', $user, $postCusto($prefixo . ' Custo user', '1,00'));
    ok('usuário: POST criar custo bloqueado', bloqueado($r) && (int) valor("SELECT COUNT(*) FROM materiais WHERE nome = :n", ['n' => $prefixo . ' Custo user']) === 0);
    $r = rota('POST', '/custos', $admin, $postCusto($prefixo . ' Custo', '2,00'));
    $m = (int) valor('SELECT id FROM materiais WHERE nome = :n', ['n' => $prefixo . ' Custo']);
    ok('admin: cria custo', $m > 0, flashTexto($r, 'errors'));
    ok('usuário: GET /custos e /custos/{id} continuam acessíveis', !bloqueado(rota('GET', '/custos', $user))
        && str_contains(rota('GET', '/custos/' . $m, $user)['html'], $prefixo . ' Custo'));
    $r = rota('GET', '/custos/' . $m . '/editar', $user);
    ok('usuário: GET editar custo bloqueado', bloqueado($r));
    $r = rota('POST', '/custos/' . $m, $user, $postCusto($prefixo . ' Custo hack', '2,00'));
    ok('usuário: POST editar custo bloqueado', bloqueado($r) && valor('SELECT nome FROM materiais WHERE id = :id', ['id' => $m]) === $prefixo . ' Custo');
    $pend = ['pending_custo' => ['id' => $m, 'payload' => [
        'nome' => $prefixo . ' Custo', 'categoria_material_id' => $categoriaId, 'unidade_id' => $unidadeId, 'forma_cobranca' => 'unidade',
        'custo_atual' => 9.0, 'fornecedor' => null, 'observacao' => null, 'status' => 'ativo',
    ], 'custo_anterior' => 2.0, 'custo_novo' => 9.0, 'observacao_historico' => null]];
    $r = rota('GET', '/custos/' . $m . '/confirmar-custo', $user, [], [], $pend);
    ok('usuário: GET confirmar alteração de custo bloqueado', bloqueado($r));
    $r = rota('POST', '/custos/' . $m . '/confirmar-custo', $user, [], [], $pend);
    ok('usuário: POST confirmar alteração de valor bloqueado (valor e histórico intactos)', bloqueado($r)
        && (float) valor('SELECT custo_atual FROM materiais WHERE id = :id', ['id' => $m]) === 2.0
        && (int) valor('SELECT COUNT(*) FROM historico_custos WHERE material_id = :id', ['id' => $m]) === 0);
    $r = rota('POST', '/custos/' . $m . '/status', $user);
    ok('usuário: POST ativar/inativar custo bloqueado', bloqueado($r) && valor('SELECT status FROM materiais WHERE id = :id', ['id' => $m]) === 'ativo');
    $r = rota('POST', '/custos/' . $m, $admin, $postCusto($prefixo . ' Custo', '2,00') + ['fornecedor' => 'Fornecedor TST']);
    ok('admin: edita custo', valor('SELECT fornecedor FROM materiais WHERE id = :id', ['id' => $m]) === 'Fornecedor TST', flashTexto($r, 'errors'));
    $r = rota('POST', '/custos/' . $m . '/confirmar-custo', $admin, [], [], $pend);
    ok('admin: confirma alteração de valor (grava histórico)', (float) valor('SELECT custo_atual FROM materiais WHERE id = :id', ['id' => $m]) === 9.0
        && (int) valor('SELECT COUNT(*) FROM historico_custos WHERE material_id = :id', ['id' => $m]) === 1, flashTexto($r, 'errors'));
    rota('POST', '/custos/' . $m . '/status', $admin);
    $stA = valor('SELECT status FROM materiais WHERE id = :id', ['id' => $m]);
    rota('POST', '/custos/' . $m . '/status', $admin);
    ok('admin: inativa e reativa custo', $stA === 'inativo' && valor('SELECT status FROM materiais WHERE id = :id', ['id' => $m]) === 'ativo');

    // CSRF continua valendo (admin e usuário)
    $r = rota('POST', '/custos/' . $m . '/status', $admin, ['_csrf' => 'invalido']);
    ok('CSRF: admin com token inválido recusado (419)', csrfRecusado($r) && valor('SELECT status FROM materiais WHERE id = :id', ['id' => $m]) === 'ativo');

    // Composição (usuário) com custo ativo
    rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/custo-modo', $user, ['custo_modo' => 'proprio']);
    ok('usuário: altera modo de custo da variação', valor('SELECT custo_modo FROM produto_variacoes WHERE id = :id', ['id' => $v]) === 'proprio');
    $r = rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/composicao', $user, ['material_id' => (string) $m, 'quantidade' => '2']);
    $lv = (int) valor('SELECT id FROM variacao_composicao WHERE variacao_id = :v AND material_id = :m', ['v' => $v, 'm' => $m]);
    ok('usuário: adiciona custo à composição própria da variação', $lv > 0 && flashTexto($r, 'errors') === '', flashTexto($r, 'errors'));
    $r = rota('POST', '/produtos/' . $p . '/composicao', $user, ['material_id' => (string) $m, 'quantidade' => '1']);
    $lc = (int) valor('SELECT id FROM produto_composicao WHERE produto_id = :p AND material_id = :m', ['p' => $p, 'm' => $m]);
    ok('usuário: adiciona custo à composição do produto', $lc > 0 && flashTexto($r, 'errors') === '', flashTexto($r, 'errors'));
    rota('POST', '/produtos/' . $p . '/variacoes/' . $v . '/composicao/' . $lv . '/remover', $user);
    ok('usuário: remove linha da composição da variação', (int) valor('SELECT COUNT(*) FROM variacao_composicao WHERE id = :id', ['id' => $lv]) === 0);
    rota('POST', '/produtos/' . $p . '/composicao/' . $lc . '/remover', $user);
    ok('usuário: remove linha da composição do produto', (int) valor('SELECT COUNT(*) FROM produto_composicao WHERE id = :id', ['id' => $lc]) === 0);

    // ================= PREÇO PRATICADO =================
    $postPreco = ['modo_preco' => 'manual', 'preco_praticado' => '12,34', 'imposto_percentual' => '9,50', 'comissao_percentual' => '10,00'];
    $r = rota('POST', '/produtos/' . $p . '/preco', $user, $postPreco);
    ok('usuário: POST alterar preço praticado bloqueado', bloqueado($r) && valor('SELECT preco_praticado FROM produtos WHERE id = :id', ['id' => $p]) === null);
    $r = rota('POST', '/produtos/' . $p . '/preco', $admin, $postPreco);
    ok('admin: altera preço praticado', abs((float) valor('SELECT preco_praticado FROM produtos WHERE id = :id', ['id' => $p]) - 12.34) < 1e-9, flashTexto($r, 'errors'));

    // ================= TABELA DE PREÇOS =================
    $cfg = static fn (): array => Database::pdo()->query('SELECT imposto_percentual, incluir_comissao, comissao_percentual FROM tabela_preco_config WHERE id = 1')->fetch();
    $cfgAntes = $cfg();
    $margemExistente = (int) valor('SELECT id FROM tabela_preco_margens_cfg ORDER BY id LIMIT 1');
    $margemValor = (string) valor('SELECT margem_percentual FROM tabela_preco_margens_cfg WHERE id = :id', ['id' => $margemExistente]);
    $r = rota('POST', '/tabela-precos/config', $user, ['imposto_percentual' => '1,00', 'comissao_percentual' => '1,00']);
    ok('usuário: POST configuração da tabela bloqueado', bloqueado($r) && $cfg() == $cfgAntes);
    $r = rota('POST', '/tabela-precos/margens', $user, ['margem_percentual' => '37,19']);
    ok('usuário: POST adicionar margem bloqueado', bloqueado($r) && (int) valor('SELECT COUNT(*) FROM tabela_preco_margens_cfg WHERE margem_percentual = 37.19') === 0);
    $r = rota('POST', '/tabela-precos/margens/' . $margemExistente, $user, ['margem_percentual' => '37,19']);
    ok('usuário: POST editar margem bloqueado', bloqueado($r) && (string) valor('SELECT margem_percentual FROM tabela_preco_margens_cfg WHERE id = :id', ['id' => $margemExistente]) === $margemValor);
    $r = rota('POST', '/tabela-precos/margens/' . $margemExistente . '/excluir', $user);
    ok('usuário: POST excluir margem bloqueado', bloqueado($r) && (int) valor('SELECT COUNT(*) FROM tabela_preco_margens_cfg WHERE id = :id', ['id' => $margemExistente]) === 1);
    $r = rota('POST', '/tabela-precos/config', $admin, ['imposto_percentual' => '9,75', 'incluir_comissao' => '1', 'comissao_percentual' => '10,00']);
    ok('admin: altera configuração da tabela', abs((float) $cfg()['imposto_percentual'] - 9.75) < 1e-9, flashTexto($r, 'errors'));
    rota('POST', '/tabela-precos/margens', $admin, ['margem_percentual' => '37,13']);
    $mg = (int) valor('SELECT id FROM tabela_preco_margens_cfg WHERE margem_percentual = 37.13');
    ok('admin: adiciona margem', $mg > 0);
    rota('POST', '/tabela-precos/margens/' . $mg, $admin, ['margem_percentual' => '37,17']);
    ok('admin: edita margem', abs((float) valor('SELECT margem_percentual FROM tabela_preco_margens_cfg WHERE id = :id', ['id' => $mg]) - 37.17) < 1e-9);
    rota('POST', '/tabela-precos/margens/' . $mg . '/excluir', $admin);
    ok('admin: exclui margem', (int) valor('SELECT COUNT(*) FROM tabela_preco_margens_cfg WHERE id = :id', ['id' => $mg]) === 0);

    // ================= EMPRESA =================
    $razao = static fn (): string => (string) valor('SELECT razao_social FROM empresa ORDER BY id LIMIT 1');
    $razaoAntes = $razao();
    $r = rota('GET', '/empresa/editar', $user);
    ok('usuário: GET editar empresa bloqueado', bloqueado($r) && !str_contains($r['html'], 'name="razao_social"'));
    $r = rota('POST', '/empresa', $user, ['razao_social' => $prefixo . ' HACK', 'cnpj' => '', 'endereco' => 'Rua hack']);
    ok('usuário: POST alterar dados (CNPJ/razão/endereço) bloqueado', bloqueado($r) && $razao() === $razaoAntes);
    $r = rota('POST', '/empresa', $admin, ['razao_social' => $prefixo . ' Empresa']);
    ok('admin: altera dados da empresa', $razao() === $prefixo . ' Empresa', flashTexto($r, 'errors'));

    $postConta = ['banco' => $prefixo . ' Banco', 'agencia' => '1234', 'conta' => '5678-9', 'tipo_conta' => 'corrente'];
    $contas = static fn (): int => (int) valor('SELECT COUNT(*) FROM empresa_contas WHERE banco LIKE :b', ['b' => 'TST-AUTHZ-%']);
    $r = rota('POST', '/empresa/contas', $user, $postConta);
    ok('usuário: POST criar conta bancária bloqueado', bloqueado($r) && $contas() === 0);
    rota('POST', '/empresa/contas', $admin, $postConta);
    $ct = (int) valor('SELECT id FROM empresa_contas WHERE banco = :b', ['b' => $prefixo . ' Banco']);
    ok('admin: cria conta bancária', $ct > 0);
    $r = rota('POST', '/empresa/contas/' . $ct . '/editar', $user, ['agencia' => '9999'] + $postConta);
    ok('usuário: POST editar conta bancária bloqueado', bloqueado($r) && valor('SELECT agencia FROM empresa_contas WHERE id = :id', ['id' => $ct]) === '1234');
    $r = rota('POST', '/empresa/contas/' . $ct . '/status', $user);
    ok('usuário: POST ativar/inativar conta bloqueado', bloqueado($r) && (int) valor('SELECT ativo FROM empresa_contas WHERE id = :id', ['id' => $ct]) === 1);
    $r = rota('POST', '/empresa/contas/' . $ct . '/excluir', $user);
    ok('usuário: POST excluir conta bloqueado', bloqueado($r) && $contas() === 1);
    rota('POST', '/empresa/contas/' . $ct . '/editar', $admin, ['agencia' => '4321'] + $postConta);
    ok('admin: edita conta bancária', valor('SELECT agencia FROM empresa_contas WHERE id = :id', ['id' => $ct]) === '4321');
    rota('POST', '/empresa/contas/' . $ct . '/status', $admin);
    ok('admin: desativa conta bancária', (int) valor('SELECT ativo FROM empresa_contas WHERE id = :id', ['id' => $ct]) === 0);
    rota('POST', '/empresa/contas/' . $ct . '/excluir', $admin);
    ok('admin: exclui conta bancária', $contas() === 0);

    $chave = 'tst-authz-' . $sufixo . '@teste.local';
    $postPix = ['tipo_chave' => 'email', 'chave' => $chave, 'identificacao' => 'TST'];
    $pixN = static fn (): int => (int) valor('SELECT COUNT(*) FROM empresa_pix WHERE chave LIKE :c', ['c' => 'tst-authz-%']);
    $r = rota('POST', '/empresa/pix', $user, $postPix);
    ok('usuário: POST criar PIX bloqueado', bloqueado($r) && $pixN() === 0);
    rota('POST', '/empresa/pix', $admin, $postPix);
    $px = (int) valor('SELECT id FROM empresa_pix WHERE chave = :c', ['c' => $chave]);
    ok('admin: cria PIX', $px > 0);
    $r = rota('POST', '/empresa/pix/' . $px . '/editar', $user, ['chave' => 'tst-authz-hack-' . $sufixo . '@teste.local'] + $postPix);
    ok('usuário: POST editar PIX bloqueado', bloqueado($r) && valor('SELECT chave FROM empresa_pix WHERE id = :id', ['id' => $px]) === $chave);
    $r = rota('POST', '/empresa/pix/' . $px . '/status', $user);
    ok('usuário: POST ativar/inativar PIX bloqueado', bloqueado($r) && (int) valor('SELECT ativo FROM empresa_pix WHERE id = :id', ['id' => $px]) === 1);
    $r = rota('POST', '/empresa/pix/' . $px . '/excluir', $user);
    ok('usuário: POST excluir PIX bloqueado', bloqueado($r) && $pixN() === 1);
    rota('POST', '/empresa/pix/' . $px . '/editar', $admin, ['identificacao' => 'TST editada'] + $postPix);
    ok('admin: edita PIX', valor('SELECT identificacao FROM empresa_pix WHERE id = :id', ['id' => $px]) === 'TST editada');
    rota('POST', '/empresa/pix/' . $px . '/status', $admin);
    ok('admin: desativa PIX', (int) valor('SELECT ativo FROM empresa_pix WHERE id = :id', ['id' => $px]) === 0);
    rota('POST', '/empresa/pix/' . $px . '/excluir', $admin);
    ok('admin: exclui PIX', $pixN() === 0);

    $postDoc = ['nome' => $prefixo . ' Doc', 'tipo_documento' => 'cnpj'];
    $docs = static fn (): int => (int) valor('SELECT COUNT(*) FROM empresa_documentos WHERE nome LIKE :n', ['n' => 'TST-AUTHZ-%']);
    $up = pdfUpload('doc-user.pdf');
    $r = rota('POST', '/empresa/documentos', $user, $postDoc, $up);
    ok('usuário: POST enviar documento bloqueado', bloqueado($r) && $docs() === 0);
    @unlink($up['arquivo']['tmp_name']);
    $r = rota('POST', '/empresa/documentos', $admin, $postDoc, pdfUpload('doc-admin.pdf'));
    $doc = $pdo->query("SELECT id, caminho_arquivo FROM empresa_documentos WHERE nome = " . $pdo->quote($prefixo . ' Doc'))->fetch() ?: null;
    if ($doc) {
        $arquivosFisicos[] = (string) $doc['caminho_arquivo'];
    }
    ok('admin: envia documento', $doc !== null && arquivoExiste((string) $doc['caminho_arquivo']), flashTexto($r, 'errors'));
    $docId = (int) ($doc['id'] ?? 0);
    $r = rota('GET', '/empresa', $user);
    ok('usuário: visualiza Empresa (dados, sem ações de escrita)', str_contains($r['html'], $prefixo . ' Empresa')
        && str_contains($r['html'], '/empresa/documentos/' . $docId . '/download')
        && !str_contains($r['html'], '/empresa/editar') && !str_contains($r['html'], 'nova_conta=1')
        && !str_contains($r['html'], 'novo_pix=1') && !str_contains($r['html'], 'novo_documento=1')
        && !str_contains($r['html'], '/empresa/documentos/' . $docId . '/excluir'));
    $r = rota('GET', '/empresa', $admin);
    ok('admin: vê as ações de escrita da Empresa', str_contains($r['html'], '/empresa/editar') && str_contains($r['html'], 'nova_conta=1')
        && str_contains($r['html'], '/empresa/documentos/' . $docId . '/excluir'));
    $r = rota('GET', '/empresa/documentos/' . $docId . '/download', $user);
    ok('usuário: baixa documento da Empresa', str_starts_with($r['html'], '%PDF-'));
    $r = rota('POST', '/empresa/documentos/' . $docId . '/excluir', $user);
    ok('usuário: POST excluir documento bloqueado (registro e arquivo mantidos)', bloqueado($r) && $docs() === 1 && arquivoExiste((string) $doc['caminho_arquivo']));
    rota('POST', '/empresa/documentos/' . $docId . '/excluir', $admin);
    ok('admin: exclui documento (registro e arquivo)', $docs() === 0 && !arquivoExiste((string) $doc['caminho_arquivo']));

    // ================= ESTOQUE DE PEÇAS =================
    $postPeca = ['codigo' => $prefixo . '-PC', 'nome' => 'Peça authz', 'tipo' => 'outra', 'unidade' => 'un', 'quantidade' => '10', 'status' => 'ativo'];
    $r = rota('POST', '/estoque/pecas', $user, $postPeca);
    $pc = (int) valor('SELECT id FROM estoque_pecas WHERE codigo = :c', ['c' => $prefixo . '-PC']);
    ok('usuário: cria peça', $pc > 0, flashTexto($r, 'errors'));
    rota('POST', '/estoque/pecas/' . $pc, $user, ['nome' => 'Peça authz editada'] + $postPeca);
    ok('usuário: edita peça', valor('SELECT nome FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 'Peça authz editada');
    rota('POST', '/estoque/pecas/' . $pc . '/movimentos', $user, ['tipo' => 'entrada', 'quantidade' => '5']);
    rota('POST', '/estoque/pecas/' . $pc . '/movimentos', $user, ['tipo' => 'saida', 'quantidade' => '3']);
    $qPc = (float) valor('SELECT quantidade FROM estoque_pecas WHERE id = :id', ['id' => $pc]);
    rota('POST', '/estoque/pecas/' . $pc . '/movimentos', $user, ['tipo' => 'ajuste', 'quantidade' => '20']);
    ok('usuário: entrada, saída e ajuste de peça', $qPc === 12.0 && (float) valor('SELECT quantidade FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 20.0);
    rota('POST', '/estoque/pecas/' . $pc . '/status', $user);
    $stPc = valor('SELECT status FROM estoque_pecas WHERE id = :id', ['id' => $pc]);
    rota('POST', '/estoque/pecas/' . $pc . '/status', $user);
    ok('usuário: inativa e reativa peça', $stPc === 'inativo' && valor('SELECT status FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 'ativo');
    $r = rota('POST', '/estoque/pecas/' . $pc . '/movimentos', $user, ['tipo' => 'entrada', 'quantidade' => '1', '_csrf' => 'invalido']);
    ok('CSRF: usuário com token inválido recusado (419)', csrfRecusado($r) && (float) valor('SELECT quantidade FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 20.0);
    $movPc = (int) valor('SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = :id', ['id' => $pc]);
    $html = rota('GET', '/estoque/pecas/' . $pc, $user)['html'];
    ok('usuário: tela da peça sem botão Excluir', str_contains($html, 'Peça authz editada') && !str_contains($html, '/estoque/pecas/' . $pc . '/excluir'));
    ok('admin: peça com histórico sem botão Excluir', !str_contains(rota('GET', '/estoque/pecas/' . $pc, $admin)['html'], '/estoque/pecas/' . $pc . '/excluir'));
    $r = rota('POST', '/estoque/pecas/' . $pc . '/excluir', $user);
    ok('usuário: POST excluir peça bloqueado (peça e histórico mantidos)', bloqueado($r)
        && (int) valor('SELECT COUNT(*) FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 1
        && (int) valor('SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = :id', ['id' => $pc]) === $movPc && $movPc > 0);
    rota('POST', '/estoque/pecas/' . $pc . '/excluir', $admin);
    ok('admin: peça com histórico não é excluída', (int) valor('SELECT COUNT(*) FROM estoque_pecas WHERE id = :id', ['id' => $pc]) === 1
        && (int) valor('SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = :id', ['id' => $pc]) === $movPc);
    rota('POST', '/estoque/pecas', $user, ['codigo' => $prefixo . '-PC0', 'quantidade' => '0'] + $postPeca);
    $pc0 = (int) valor('SELECT id FROM estoque_pecas WHERE codigo = :c', ['c' => $prefixo . '-PC0']);
    ok('admin: peça sem histórico com botão Excluir', $pc0 > 0 && str_contains(rota('GET', '/estoque/pecas/' . $pc0, $admin)['html'], '/estoque/pecas/' . $pc0 . '/excluir'));
    $r = rota('POST', '/estoque/pecas/' . $pc0 . '/excluir', $user);
    ok('usuário: POST excluir peça sem histórico bloqueado', bloqueado($r) && (int) valor('SELECT COUNT(*) FROM estoque_pecas WHERE id = :id', ['id' => $pc0]) === 1);
    rota('POST', '/estoque/pecas/' . $pc0 . '/excluir', $admin);
    ok('admin: exclui peça sem histórico', (int) valor('SELECT COUNT(*) FROM estoque_pecas WHERE id = :id', ['id' => $pc0]) === 0);

    // ================= ESTOQUE DE PAPÉIS =================
    $postPapel = ['codigo' => $prefixo . '-PP', 'nome' => 'Papel authz', 'unidade' => 'folha', 'quantidade' => '10', 'status' => 'ativo'];
    $r = rota('POST', '/estoque/papeis', $user, $postPapel);
    $pp = (int) valor('SELECT id FROM estoque_papeis WHERE codigo = :c', ['c' => $prefixo . '-PP']);
    ok('usuário: cria papel', $pp > 0, flashTexto($r, 'errors'));
    rota('POST', '/estoque/papeis/' . $pp, $user, ['nome' => 'Papel authz editado'] + $postPapel);
    ok('usuário: edita papel', valor('SELECT nome FROM estoque_papeis WHERE id = :id', ['id' => $pp]) === 'Papel authz editado');
    rota('POST', '/estoque/papeis/' . $pp . '/movimentos', $user, ['tipo' => 'entrada', 'quantidade' => '5']);
    rota('POST', '/estoque/papeis/' . $pp . '/movimentos', $user, ['tipo' => 'saida', 'quantidade' => '3']);
    $qPp = (float) valor('SELECT quantidade FROM estoque_papeis WHERE id = :id', ['id' => $pp]);
    rota('POST', '/estoque/papeis/' . $pp . '/movimentos', $user, ['tipo' => 'ajuste', 'quantidade' => '20']);
    ok('usuário: entrada, saída e ajuste de papel', $qPp === 12.0 && (float) valor('SELECT quantidade FROM estoque_papeis WHERE id = :id', ['id' => $pp]) === 20.0);
    rota('POST', '/estoque/papeis/' . $pp . '/status', $user);
    $stPp = valor('SELECT status FROM estoque_papeis WHERE id = :id', ['id' => $pp]);
    rota('POST', '/estoque/papeis/' . $pp . '/status', $user);
    ok('usuário: inativa e reativa papel', $stPp === 'inativo' && valor('SELECT status FROM estoque_papeis WHERE id = :id', ['id' => $pp]) === 'ativo');
    $movPp = (int) valor('SELECT COUNT(*) FROM estoque_papeis_movimentos WHERE papel_id = :id', ['id' => $pp]);
    ok('usuário: tela do papel sem botão Excluir', !str_contains(rota('GET', '/estoque/papeis/' . $pp, $user)['html'], '/estoque/papeis/' . $pp . '/excluir'));
    $r = rota('POST', '/estoque/papeis/' . $pp . '/excluir', $user);
    ok('usuário: POST excluir papel bloqueado (papel e histórico mantidos)', bloqueado($r)
        && (int) valor('SELECT COUNT(*) FROM estoque_papeis WHERE id = :id', ['id' => $pp]) === 1
        && (int) valor('SELECT COUNT(*) FROM estoque_papeis_movimentos WHERE papel_id = :id', ['id' => $pp]) === $movPp && $movPp > 0);
    rota('POST', '/estoque/papeis/' . $pp . '/excluir', $admin);
    ok('admin: papel com histórico não é excluído', (int) valor('SELECT COUNT(*) FROM estoque_papeis WHERE id = :id', ['id' => $pp]) === 1
        && (int) valor('SELECT COUNT(*) FROM estoque_papeis_movimentos WHERE papel_id = :id', ['id' => $pp]) === $movPp);
    rota('POST', '/estoque/papeis', $user, ['codigo' => $prefixo . '-PP0', 'quantidade' => '0'] + $postPapel);
    $pp0 = (int) valor('SELECT id FROM estoque_papeis WHERE codigo = :c', ['c' => $prefixo . '-PP0']);
    $r = rota('POST', '/estoque/papeis/' . $pp0 . '/excluir', $user);
    ok('usuário: POST excluir papel sem histórico bloqueado', $pp0 > 0 && bloqueado($r) && (int) valor('SELECT COUNT(*) FROM estoque_papeis WHERE id = :id', ['id' => $pp0]) === 1);
    rota('POST', '/estoque/papeis/' . $pp0 . '/excluir', $admin);
    ok('admin: exclui papel sem histórico', (int) valor('SELECT COUNT(*) FROM estoque_papeis WHERE id = :id', ['id' => $pp0]) === 0);

    // ================= COLEÇÕES =================
    $postCol = static fn (string $cod): array => ['codigo' => $cod, 'nome' => 'Coleção ' . $cod, 'tipo' => 'catalogo', 'etapa' => 'planejamento', 'status' => 'ativo'];
    $r = rota('POST', '/colecoes', $user, $postCol($prefixo . '-C1'));
    $c1 = (int) valor('SELECT id FROM colecoes WHERE codigo = :c', ['c' => $prefixo . '-C1']);
    ok('usuário: cria coleção', $c1 > 0, flashTexto($r, 'errors'));
    rota('POST', '/colecoes/' . $c1, $user, ['nome' => 'Coleção editada'] + $postCol($prefixo . '-C1'));
    ok('usuário: edita coleção', valor('SELECT nome FROM colecoes WHERE id = :id', ['id' => $c1]) === 'Coleção editada');
    $vinc = static fn (): int => (int) valor('SELECT COUNT(*) FROM colecao_variacoes WHERE colecao_id = :c AND variacao_id = :v', ['c' => $c1, 'v' => $v]);
    rota('POST', '/colecoes/' . $c1 . '/vincular-variacao', $user, ['variacao_id' => (string) $v]);
    $vinc1 = $vinc();
    rota('POST', '/colecoes/' . $c1 . '/desvincular-variacao', $user, ['variacao_id' => (string) $v]);
    ok('usuário: vincula e desvincula variação', $vinc1 === 1 && $vinc() === 0);
    rota('POST', '/colecoes/' . $c1 . '/vincular-variacao', $user, ['variacao_id' => (string) $v]);
    $r = rota('POST', '/colecoes/' . $c1 . '/variacoes/' . $v . '/resultado', $user, ['qtd_produzida' => '5', 'qtd_vendida' => '2', 'valor_vendido' => '10,00', 'observacao' => '']);
    ok('usuário: registra resultado da variação', (int) valor('SELECT qtd_produzida FROM colecao_variacoes WHERE colecao_id = :c AND variacao_id = :v', ['c' => $c1, 'v' => $v]) === 5, flashTexto($r, 'errors'));
    rota('POST', '/colecoes/' . $c1 . '/tarefas', $user, ['titulo' => $prefixo . ' tarefa']);
    $tf = (int) valor('SELECT id FROM colecao_tarefas WHERE colecao_id = :c', ['c' => $c1]);
    rota('POST', '/colecoes/' . $c1 . '/tarefas/' . $tf . '/editar', $user, ['titulo' => $prefixo . ' tarefa editada']);
    $tfEdit = valor('SELECT titulo FROM colecao_tarefas WHERE id = :id', ['id' => $tf]);
    rota('POST', '/colecoes/' . $c1 . '/tarefas/' . $tf . '/concluir', $user);
    rota('POST', '/colecoes/' . $c1 . '/tarefas/' . $tf . '/reabrir', $user);
    rota('POST', '/colecoes/' . $c1 . '/tarefas/' . $tf . '/excluir', $user);
    ok('usuário: cria, edita, conclui, reabre e exclui tarefa da coleção', $tf > 0 && $tfEdit === $prefixo . ' tarefa editada'
        && (int) valor('SELECT COUNT(*) FROM colecao_tarefas WHERE id = :id', ['id' => $tf]) === 0);

    rota('POST', '/colecoes', $user, $postCol($prefixo . '-C2'));
    $c2 = (int) valor('SELECT id FROM colecoes WHERE codigo = :c', ['c' => $prefixo . '-C2']);
    ok('usuário: telas de coleção sem Excluir', !str_contains(rota('GET', '/colecoes/' . $c2, $user)['html'], '/colecoes/' . $c2 . '/excluir')
        && !str_contains(rota('GET', '/colecoes', $user)['html'], '/colecoes/' . $c2 . '/excluir'));
    ok('admin: telas de coleção com Excluir', str_contains(rota('GET', '/colecoes/' . $c2, $admin)['html'], '/colecoes/' . $c2 . '/excluir'));
    ok('usuário: GET confirmação de exclusão da coleção bloqueado', bloqueado(rota('GET', '/colecoes/' . $c2 . '/excluir', $user)));
    $r = rota('POST', '/colecoes/' . $c2 . '/excluir', $user);
    ok('usuário: POST excluir coleção bloqueado', bloqueado($r) && (int) valor('SELECT COUNT(*) FROM colecoes WHERE id = :id', ['id' => $c2]) === 1);
    rota('POST', '/colecoes/' . $c2 . '/excluir', $admin);
    ok('admin: exclui coleção', (int) valor('SELECT COUNT(*) FROM colecoes WHERE id = :id', ['id' => $c2]) === 0);

    // ================= AGENDA / ETIQUETAS =================
    $hoje = date('Y-m-d');
    rota('POST', '/agenda/criar', $user, ['titulo' => $prefixo . ' agenda', 'data_tarefa' => $hoje, 'prioridade' => 'media']);
    $ag = (int) valor('SELECT id FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' agenda']);
    rota('POST', '/agenda/' . $ag . '/editar', $user, ['titulo' => $prefixo . ' agenda editada', 'data_tarefa' => $hoje, 'prioridade' => 'alta']);
    $agEdit = valor('SELECT prioridade FROM agenda_tarefas WHERE id = :id', ['id' => $ag]);
    rota('POST', '/agenda/' . $ag . '/concluir', $user);
    rota('POST', '/agenda/' . $ag . '/reabrir', $user);
    rota('POST', '/agenda/' . $ag . '/excluir', $user);
    ok('usuário: agenda (criar, editar, concluir, reabrir, excluir)', $ag > 0 && $agEdit === 'alta'
        && (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE id = :id', ['id' => $ag]) === 0);
    $r = rota('GET', '/etiquetas', $user);
    ok('usuário: acessa Etiquetas', !bloqueado($r) && trim($r['html']) !== '' && !str_contains($r['html'], 'name="password"'));

    // ================= ARQUIVOS =================
    $r = rota('POST', '/arquivos', $user, ['produto_id' => (string) $p, 'tipo' => 'pdf', 'nome' => $prefixo . ' arquivo'], pdfUpload('arquivo.pdf'));
    $arq = $pdo->query('SELECT id, caminho FROM arquivos_produtos WHERE nome = ' . $pdo->quote($prefixo . ' arquivo'))->fetch() ?: null;
    if ($arq) {
        $arquivosFisicos[] = (string) $arq['caminho'];
    }
    ok('usuário: envia arquivo de produto', $arq !== null && arquivoExiste((string) $arq['caminho']), flashTexto($r, 'errors'));
    $arqId = (int) ($arq['id'] ?? 0);
    rota('POST', '/arquivos/' . $arqId, $user, ['tipo' => 'pdf', 'nome' => $prefixo . ' arquivo editado']);
    ok('usuário: edita arquivo de produto', valor('SELECT nome FROM arquivos_produtos WHERE id = :id', ['id' => $arqId]) === $prefixo . ' arquivo editado');
    ok('usuário: lista de arquivos sem Excluir', !str_contains(rota('GET', '/arquivos/produto/' . $p, $user)['html'], '/arquivos/' . $arqId . '/excluir'));
    ok('admin: lista de arquivos com Excluir', str_contains(rota('GET', '/arquivos/produto/' . $p, $admin)['html'], '/arquivos/' . $arqId . '/excluir'));
    $r = rota('POST', '/arquivos/' . $arqId . '/excluir', $user);
    ok('usuário: POST excluir arquivo bloqueado (registro e arquivo mantidos)', bloqueado($r)
        && (int) valor('SELECT COUNT(*) FROM arquivos_produtos WHERE id = :id', ['id' => $arqId]) === 1 && arquivoExiste((string) $arq['caminho']));
    rota('POST', '/arquivos/' . $arqId . '/excluir', $admin);
    ok('admin: exclui arquivo (registro e arquivo)', (int) valor('SELECT COUNT(*) FROM arquivos_produtos WHERE id = :id', ['id' => $arqId]) === 0
        && !arquivoExiste((string) $arq['caminho']));

    // ================= VARIAÇÃO: EXCLUSÃO =================
    $p2 = (int) valor('SELECT id FROM produtos WHERE codigo_interno = :c', ['c' => $prefixo . '-P-COPIA']);
    rota('POST', '/produtos/' . $p2 . '/variacoes', $user, ['codigo' => $prefixo . '-V2', 'nome' => 'Variação a excluir', 'estoque' => '0', 'status' => 'ativo', 'custo_modo' => 'herdar', 'pacote_modo' => 'herdar']);
    $v2 = (int) valor('SELECT id FROM produto_variacoes WHERE codigo = :c', ['c' => $prefixo . '-V2']);
    $html = rota('GET', '/produtos/' . $p2, $user)['html'];
    ok('usuário: tela do produto sem Excluir variação e sem salvar preço', $v2 > 0
        && !str_contains($html, '/variacoes/' . $v2 . '/excluir') && !str_contains($html, 'Salvar formação de preço')
        && str_contains($html, 'id="preco-somente-admin"') && str_contains($html, '/variacoes/' . $v2 . '/editar'));
    $html = rota('GET', '/produtos/' . $p2, $admin)['html'];
    ok('admin: tela do produto com Excluir variação e salvar preço', str_contains($html, '/variacoes/' . $v2 . '/excluir') && str_contains($html, 'Salvar formação de preço'));
    $r = rota('POST', '/produtos/' . $p2 . '/variacoes/' . $v2 . '/excluir', $user);
    ok('usuário: POST excluir variação bloqueado', bloqueado($r) && (int) valor('SELECT COUNT(*) FROM produto_variacoes WHERE id = :id', ['id' => $v2]) === 1);
    rota('POST', '/produtos/' . $p2 . '/variacoes/' . $v2 . '/excluir', $admin);
    ok('admin: exclui variação', (int) valor('SELECT COUNT(*) FROM produto_variacoes WHERE id = :id', ['id' => $v2]) === 0);

    // ================= TELAS: CUSTOS E TABELA =================
    $html = rota('GET', '/custos', $user)['html'];
    ok('usuário: lista de custos sem Novo/Editar/Desativar', !str_contains($html, '/custos/novo') && !str_contains($html, '/custos/' . $m . '/editar')
        && !str_contains($html, '/custos/' . $m . '/status'));
    ok('usuário: detalhe do custo sem Editar', !str_contains(rota('GET', '/custos/' . $m, $user)['html'], '/custos/' . $m . '/editar'));
    $html = rota('GET', '/custos', $admin)['html'];
    ok('admin: lista de custos com Novo/Editar/Desativar', str_contains($html, '/custos/novo') && str_contains($html, '/custos/' . $m . '/status'));
    $html = rota('GET', '/tabela-precos', $user)['html'];
    ok('usuário: tabela de preços visível, configuração somente leitura', str_contains($html, 'id="config-somente-leitura"')
        && !str_contains($html, 'form-config-tabela') && !str_contains($html, '/tabela-precos/margens'));
    $html = rota('GET', '/tabela-precos', $admin)['html'];
    ok('admin: tabela de preços com formulários de configuração', str_contains($html, 'form-config-tabela') && str_contains($html, '/tabela-precos/margens')
        && !str_contains($html, 'config-somente-leitura'));

    // ================= USUÁRIOS / SENHA =================
    $r = rota('POST', '/configuracoes/usuarios/' . $user, $user, ['nome' => 'TST AUTHZ usuario', 'email' => 'tst-authz-' . $sufixo . '-usuario@teste.local', 'status' => 'ativo', 'perfil' => 'admin']);
    ok('usuário: não altera o próprio perfil para admin', bloqueado($r) && Usuario::find($user)['perfil'] === 'usuario');
    $r = rota('POST', '/configuracoes/usuarios/' . $admin . '/senha', $user, ['nova_senha' => 'hackhackhack', 'confirmar_senha' => 'hackhackhack']);
    ok('usuário: não altera senha de outro usuário', bloqueado($r) && !Usuario::verificarSenha('hackhackhack', (string) Usuario::find($admin)['senha_hash']));
    $novaSenha = 'nova-senha-' . $sufixo;
    rota('POST', '/configuracoes/senha', $user, ['senha_atual' => $senhaUser, 'nova_senha' => $novaSenha, 'confirmar_senha' => $novaSenha]);
    ok('usuário: troca a própria senha', Usuario::verificarSenha($novaSenha, (string) Usuario::find($user)['senha_hash']));
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    foreach ($pdo->query("SELECT caminho FROM arquivos_produtos WHERE nome LIKE 'TST-AUTHZ-%'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $arquivosFisicos[] = (string) $c;
    }
    foreach ($pdo->query("SELECT caminho_arquivo FROM empresa_documentos WHERE nome LIKE 'TST-AUTHZ-%'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $arquivosFisicos[] = (string) $c;
    }
    $pdo->exec("DELETE FROM arquivos_produtos WHERE nome LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM empresa_documentos WHERE nome LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM empresa_contas WHERE banco LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM empresa_pix WHERE chave LIKE 'tst-authz-%'");
    $pdo->exec("DELETE FROM agenda_tarefas WHERE titulo LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM colecoes WHERE codigo LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM estoque_pecas WHERE codigo LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM estoque_papeis WHERE codigo LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE vc FROM variacao_composicao vc JOIN produto_variacoes pv ON pv.id = vc.variacao_id JOIN produtos p ON p.id = pv.produto_id WHERE p.codigo_interno LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE pc FROM produto_composicao pc JOIN produtos p ON p.id = pc.produto_id WHERE p.codigo_interno LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM produtos WHERE codigo_interno LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE l FROM logs_alteracoes l JOIN materiais m ON m.id = l.entidade_id AND l.entidade = 'material' WHERE m.nome LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM materiais WHERE nome LIKE 'TST-AUTHZ-%'");
    $pdo->exec("DELETE FROM tabela_preco_margens_cfg WHERE margem_percentual IN (37.13, 37.17, 37.19)");
    $restMargem = $pdo->prepare('UPDATE tabela_preco_margens_cfg SET ordem = :o, updated_at = :u WHERE id = :id');
    foreach ($margensOriginais as $mo) {
        $restMargem->execute(['o' => $mo['ordem'], 'u' => $mo['updated_at'], 'id' => $mo['id']]);
    }
    if ($configOriginal) {
        $pdo->prepare('UPDATE tabela_preco_config SET imposto_percentual = :i, incluir_comissao = :c, comissao_percentual = :p, updated_at = :u WHERE id = 1')
            ->execute(['i' => $configOriginal['imposto_percentual'], 'c' => $configOriginal['incluir_comissao'], 'p' => $configOriginal['comissao_percentual'], 'u' => $configOriginal['updated_at']]);
    }
    if (!empty($empresaCriadaParaTeste)) {
        $pdo->exec('DELETE FROM empresa_documentos WHERE empresa_id = 1');
        $pdo->exec('DELETE FROM empresa_contas WHERE empresa_id = 1');
        $pdo->exec('DELETE FROM empresa_pix WHERE empresa_id = 1');
        $pdo->exec('DELETE FROM empresa WHERE id = 1');
    } elseif ($empresaOriginal) {
        $cols = array_keys($empresaOriginal);
        $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :{$c}", array_diff($cols, ['id'])));
        $pdo->prepare("UPDATE empresa SET {$set} WHERE id = :id")->execute($empresaOriginal);
    }
    foreach ($usuariosTmp as $uid) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $uid]);
    }
    foreach (array_unique($arquivosFisicos) as $c) {
        Upload::removerArquivoFisico($c);
    }
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
}

$sobras = 0;
foreach ([
    "SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM materiais WHERE nome LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'tst-authz-%'",
    "SELECT COUNT(*) FROM estoque_pecas WHERE codigo LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM estoque_papeis WHERE codigo LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM colecoes WHERE codigo LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM agenda_tarefas WHERE titulo LIKE 'TST-AUTHZ-%'",
    "SELECT COUNT(*) FROM empresa_documentos WHERE nome LIKE 'TST-AUTHZ-%'",
] as $sql) {
    $sobras += (int) $pdo->query($sql)->fetchColumn();
}
$fisicosRestantes = count(array_filter(array_unique($arquivosFisicos), 'arquivoExiste'));
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('limpeza: nenhum arquivo físico temporário restante', $fisicosRestantes === 0 && !is_dir($tmpDir), "restantes={$fisicosRestantes}");
ok('estrutura do banco inalterada', (string) $pdo->query(SQL_SIG_ESTRUTURA)->fetchColumn() === $sigEstrutura);
ok('dados permanentes intactos (Empresa, tabela de preços, custos, usuários, estoque, coleções)', (string) $pdo->query(SQL_SIG_PERMANENTE)->fetchColumn() === $sigPermanente);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

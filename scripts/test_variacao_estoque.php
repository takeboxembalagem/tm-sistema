<?php
/**
 * Testes locais — editar variação não altera estoque; só o ajuste de estoque altera.
 * Cria dados temporários (TST-VEST-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_variacao_estoque.php
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
$prefixo = 'TST-VEST-' . $sufixo;
$produtoId = 0;
$colecaoId = 0;
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_' . $sufixo . '.php';

/** Executa uma requisição POST pelo roteador real, em subprocesso. */
function rota(string $uri, array $post, ?int $userId, bool $csrfValido = true): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'uri' => $uri,
        'post' => $post + ['_csrf' => $csrfValido ? $token : 'token-invalido'],
        'token' => $token,
        'user_id' => $userId,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
    ];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['code' => null, 'flash' => null, 'raw' => $out];
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

function estoque(int $variacaoId): ?int
{
    $v = Variacao::find($variacaoId);
    return $v ? (int) $v['estoque'] : null;
}

function md5Tabela(string $sql): string
{
    return (string) Database::pdo()->query($sql)->fetchColumn();
}

const SQL_SIG_ESTRUTURA = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
        IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()";
const SQL_SIG_COMPOSICAO = "SELECT MD5(CONCAT(
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, produto_id, IFNULL(material_id, 'N'), IFNULL(descricao_manual, 'N'), IFNULL(preco_manual, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM produto_composicao), ''),
        '#',
        IFNULL((SELECT GROUP_CONCAT(CONCAT_WS(':', id, variacao_id, IFNULL(material_id, 'N'), IFNULL(descricao_manual, 'N'), IFNULL(preco_manual, 'N'), quantidade, modo) ORDER BY id SEPARATOR '|') FROM variacao_composicao), '')))";
const SQL_SIG_COLECOES = "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', colecao_id, variacao_id, IFNULL(qtd_vendida, 'N'),
        IFNULL(qtd_produzida, 'N'), IFNULL(valor_vendido, 'N'), IFNULL(observacao, 'N'))
        ORDER BY colecao_id, variacao_id SEPARATOR '|'), '')) FROM colecao_variacoes";

$sigEstrutura = md5Tabela(SQL_SIG_ESTRUTURA);
$sigColecoesInicial = md5Tabela(SQL_SIG_COLECOES);

try {
    $produtoId = Produto::create([
        'codigo_interno' => $prefixo,
        'nome' => 'Teste Estoque Variação',
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

    // --- Model: update ignora estoque mesmo se vier no array ---
    $vModel = Variacao::create([
        'produto_id' => $produtoId,
        'codigo' => $prefixo . '-M',
        'nome' => 'Modelo',
        'estoque' => 30,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
    Variacao::update($vModel, [
        'codigo' => $prefixo . '-M',
        'nome' => 'Modelo alterado',
        'estoque' => 999,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
    ok('model: Variacao::update ignora estoque do array', estoque($vModel) === 30);
    ok('model: Variacao::update grava os demais campos', (Variacao::find($vModel)['nome'] ?? '') === 'Modelo alterado');

    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($userId === 0) {
        ok('rotas: há usuário ativo local para simular sessão', false);
        throw new RuntimeException('Sem usuário ativo local.');
    }
    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
$_POST = $req['post'];
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
if ($req['user_id'] !== null) {
    $_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'admin'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
register_shutdown_function(static function (): void {
    ob_end_clean();
    echo '@@RESULT@@' . json_encode(['code' => http_response_code(), 'flash' => $_SESSION['_flash'] ?? null]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $base = '/produtos/' . $produtoId . '/variacoes';

    // --- 6) Criação continua gravando o estoque inicial ---
    $r = rota($base, [
        'codigo' => $prefixo . '-A',
        'nome' => 'Azul',
        'estoque' => '50',
        'status' => 'ativo',
        'observacao' => '',
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
    ], $userId);
    $stmt = $pdo->prepare('SELECT id FROM produto_variacoes WHERE codigo = :c');
    $stmt->execute(['c' => $prefixo . '-A']);
    $v = (int) ($stmt->fetchColumn() ?: 0);
    ok('6. criação pela rota: variação cadastrada', $v > 0, flashTexto($r, 'success') . flashTexto($r, 'errors'));
    ok('6. criação pela rota: estoque inicial gravado (50)', $v > 0 && estoque($v) === 50);

    $r = rota($base, [
        'codigo' => $prefixo . '-NEG',
        'nome' => 'Negativo',
        'estoque' => '-1',
        'status' => 'ativo',
        'pacote_modo' => 'herdar',
    ], $userId);
    ok('6. criação com estoque negativo continua recusada', str_contains(flashTexto($r, 'errors'), 'negativo'));

    // Coleção com resultado vinculada, para provar que a edição não mexe nela
    $colecaoId = Colecao::create([
        'codigo' => null,
        'nome' => $prefixo . ' coleção',
        'tipo' => 'especial',
        'descricao' => null,
        'imagem' => null,
        'observacoes' => null,
        'periodo_inicio' => null,
        'periodo_fim' => null,
        'etapa' => 'planejamento',
        'status' => 'ativo',
    ]);
    Colecao::vincularVariacao($colecaoId, $v);
    Colecao::atualizarResultadoVariacao($colecaoId, $v, ['qtd_vendida' => 3, 'valor_vendido' => '90.00']);

    $sigComposicao = md5Tabela(SQL_SIG_COMPOSICAO);
    $sigColecoes = md5Tabela(SQL_SIG_COLECOES);
    $custoModoAntes = Variacao::find($v)['custo_modo'] ?? null;

    $edit = static fn (array $campos): array => $campos + [
        'codigo' => $prefixo . '-A',
        'nome' => 'Azul',
        'status' => 'ativo',
        'observacao' => '',
        'pacote_modo' => 'herdar',
    ];

    // --- 1) Cenário da auditoria: formulário aberto com 50, outra operação muda para 40 ---
    Variacao::setEstoque($v, 40);
    $r = rota($base . '/' . $v, $edit(['nome' => 'Azul Royal', 'estoque' => '50']), $userId);
    ok('1. editar nome: nome gravado', (Variacao::find($v)['nome'] ?? '') === 'Azul Royal', flashTexto($r, 'success') . flashTexto($r, 'errors'));
    ok('1. editar nome com formulário antigo (50): estoque continua 40', estoque($v) === 40, 'estoque=' . var_export(estoque($v), true));

    // --- 2) Editar código ---
    $r = rota($base . '/' . $v, $edit(['nome' => 'Azul Royal', 'codigo' => $prefixo . '-AZ']), $userId);
    ok('2. editar código: código gravado', (Variacao::find($v)['codigo'] ?? '') === $prefixo . '-AZ', flashTexto($r, 'errors'));
    ok('2. editar código: estoque continua 40', estoque($v) === 40);

    // --- 3) Editar dados normais (status, observação, pacote) ---
    $r = rota($base . '/' . $v, $edit([
        'nome' => 'Azul Royal',
        'codigo' => $prefixo . '-AZ',
        'status' => 'inativo',
        'observacao' => 'Obs teste',
        'pacote_modo' => 'proprio',
        'quantidade_pacote' => '12',
    ]), $userId);
    $row = Variacao::find($v) ?? [];
    ok('3. editar dados normais: campos gravados', ($row['status'] ?? '') === 'inativo'
        && ($row['observacao'] ?? '') === 'Obs teste' && (int) ($row['quantidade_pacote'] ?? 0) === 12, flashTexto($r, 'errors'));
    ok('3. editar dados normais: estoque continua 40', estoque($v) === 40);

    // --- 4 e 10) Estoque forjado no POST da rota de edição é ignorado ---
    foreach (['999', '0', '-5', 'abc'] as $forjado) {
        $r = rota($base . '/' . $v, $edit(['nome' => 'Azul Royal', 'codigo' => $prefixo . '-AZ', 'estoque' => $forjado]), $userId);
        ok("4/10. POST de edição com estoque=\"{$forjado}\": ignorado", estoque($v) === 40 && flashTexto($r, 'errors') === '',
            'estoque=' . var_export(estoque($v), true) . ' ' . flashTexto($r, 'errors'));
    }

    // --- 7 e 8) Composição, custo e coleções intactos após as edições ---
    ok('7. composição (produto e variações) inalterada', md5Tabela(SQL_SIG_COMPOSICAO) === $sigComposicao);
    ok('7. custo_modo da variação inalterado', (Variacao::find($v)['custo_modo'] ?? null) === $custoModoAntes);
    ok('8. coleções/resultados inalterados', md5Tabela(SQL_SIG_COLECOES) === $sigColecoes);

    // --- 5) Ajuste próprio continua alterando o saldo ---
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '25', 'motivo' => 'ajuste_manual'], $userId);
    ok('5. ajuste de estoque: saldo alterado para 25', estoque($v) === 25, flashTexto($r, 'success') . flashTexto($r, 'errors'));
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '0', 'motivo' => 'venda'], $userId);
    ok('5. ajuste de estoque: aceita zerar', estoque($v) === 0);
    Variacao::setEstoque($v, 25);
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '-3', 'motivo' => 'ajuste_manual'], $userId);
    ok('5. ajuste de estoque: negativo recusado', estoque($v) === 25 && flashTexto($r, 'errors') !== '');
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '10'], $userId);
    ok('5. ajuste de estoque: sem motivo recusado', estoque($v) === 25 && flashTexto($r, 'errors') !== '');
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '1.000', 'motivo' => 'ajuste_manual'], $userId);
    ok('5. ajuste de estoque: valor não inteiro recusado', estoque($v) === 25);

    // --- 9) CSRF e login continuam exigidos ---
    $r = rota($base . '/' . $v, $edit(['nome' => 'CSRF', 'codigo' => $prefixo . '-AZ']), $userId, false);
    ok('9. edição com CSRF inválido: HTTP 419', ($r['code'] ?? null) === 419, 'code=' . var_export($r['code'] ?? null, true));
    ok('9. edição com CSRF inválido: nada gravado', (Variacao::find($v)['nome'] ?? '') === 'Azul Royal');
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '7', 'motivo' => 'ajuste_manual'], $userId, false);
    ok('9. ajuste com CSRF inválido: HTTP 419', ($r['code'] ?? null) === 419);
    ok('9. ajuste com CSRF inválido: estoque mantido', estoque($v) === 25);
    $r = rota($base . '/' . $v . '/estoque', ['estoque' => '7', 'motivo' => 'ajuste_manual'], null);
    ok('9. ajuste sem login: estoque mantido', estoque($v) === 25);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($colecaoId > 0) {
        $pdo->prepare('DELETE FROM colecoes WHERE id = :id')->execute(['id' => $colecaoId]);
    }
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-VEST-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-VEST-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM colecoes WHERE nome LIKE 'TST-VEST-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");
ok('estrutura do banco inalterada', md5Tabela(SQL_SIG_ESTRUTURA) === $sigEstrutura);
ok('8. vínculos de coleções preexistentes intactos', md5Tabela(SQL_SIG_COLECOES) === $sigColecoesInicial);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

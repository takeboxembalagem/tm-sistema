<?php
/**
 * Testes locais — proteção de exclusão de coleções/variações com histórico comercial.
 * Cria dados temporários (TST-EXC-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_exclusao_colecoes.php
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
$colecoesCriadas = [];
$produtoId = 0;
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_sess_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_runner_' . $sufixo . '.php';

function novaColecao(string $nome): int
{
    global $colecoesCriadas, $sufixo;
    $id = Colecao::create([
        'codigo' => null,
        'nome' => 'TST-EXC-' . $sufixo . ' ' . $nome,
        'tipo' => 'especial',
        'descricao' => null,
        'imagem' => null,
        'observacoes' => null,
        'periodo_inicio' => null,
        'periodo_fim' => null,
        'etapa' => 'planejamento',
        'status' => 'ativo',
    ]);
    $colecoesCriadas[] = $id;
    return $id;
}

function novaVariacao(int $produtoId, string $nome): int
{
    global $sufixo;
    return Variacao::create([
        'produto_id' => $produtoId,
        'codigo' => 'TST-EXC-' . $sufixo . '-' . $nome,
        'nome' => 'Teste ' . $nome,
        'estoque' => 0,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
}

function colecaoExiste(int $id): bool
{
    return Colecao::find($id) !== null;
}

function variacaoExiste(int $id): bool
{
    return Variacao::find($id) !== null;
}

function vinculo(int $colecaoId, int $variacaoId): ?array
{
    $stmt = Database::pdo()->prepare(
        'SELECT qtd_vendida, qtd_produzida, valor_vendido, observacao
         FROM colecao_variacoes WHERE colecao_id = :c AND variacao_id = :v'
    );
    $stmt->execute(['c' => $colecaoId, 'v' => $variacaoId]);
    return $stmt->fetch() ?: null;
}

function contarTarefas(int $colecaoId): int
{
    return Colecao::contarTarefas($colecaoId);
}

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

$sigAntes = (string) $pdo->query(
    "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
            IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();
$resultadosExistentes = (string) $pdo->query(
    "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', colecao_id, variacao_id, IFNULL(qtd_vendida, 'N'),
            IFNULL(qtd_produzida, 'N'), IFNULL(valor_vendido, 'N'), IFNULL(observacao, 'N'))
            ORDER BY colecao_id, variacao_id SEPARATOR '|'), ''))
     FROM colecao_variacoes"
)->fetchColumn();
$tarefasExistentes = (int) $pdo->query('SELECT COUNT(*) FROM colecao_tarefas')->fetchColumn();

try {
    $produtoId = Produto::create([
        'codigo_interno' => 'TST-EXC-' . $sufixo,
        'nome' => 'Teste Exclusão Coleções',
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

    // --- 1) Coleção sem variações nem histórico pode ser excluída ---
    $c1 = novaColecao('vazia');
    ok('1. coleção vazia: exclusão permitida', Colecao::excluirSemHistoricoComercial($c1) === true);
    ok('1. coleção vazia: removida do banco', !colecaoExiste($c1));

    // --- 2) Coleção com variação sem resultado: exclui, variação mantida, tarefas removidas ---
    $v1 = novaVariacao($produtoId, 'V1');
    $c2 = novaColecao('sem resultado');
    Colecao::vincularVariacao($c2, $v1);
    Colecao::criarTarefa($c2, 'Tarefa teste', null);
    ok('2. contador de resultados = 0', Colecao::contarResultadosComerciais($c2) === 0);
    ok('2. exclusão permitida', Colecao::excluirSemHistoricoComercial($c2) === true);
    ok('2. coleção removida', !colecaoExiste($c2));
    ok('2. variação mantida', variacaoExiste($v1));
    ok('2. vínculo vazio removido', vinculo($c2, $v1) === null);
    ok('2. tarefas da coleção removidas', contarTarefas($c2) === 0);

    // Valores zerados / observação só com espaços não contam como histórico
    $c2b = novaColecao('zerada');
    Colecao::vincularVariacao($c2b, $v1);
    $pdo->prepare(
        "UPDATE colecao_variacoes SET qtd_vendida = 0, qtd_produzida = 0, valor_vendido = 0.00, observacao = '   '
         WHERE colecao_id = :c AND variacao_id = :v"
    )->execute(['c' => $c2b, 'v' => $v1]);
    ok('2. resultados zerados não bloqueiam', Colecao::excluirSemHistoricoComercial($c2b) === true && !colecaoExiste($c2b));

    // --- 3, 4, 5) Cada campo de resultado bloqueia sozinho ---
    $v2 = novaVariacao($produtoId, 'V2');
    $casos = [
        '3. qtd_vendida > 0' => ['qtd_vendida' => 5],
        '4. qtd_produzida > 0' => ['qtd_produzida' => 3],
        '5. valor_vendido > 0' => ['valor_vendido' => '100.00'],
        '5b. observação preenchida' => ['observacao' => 'Vendeu na feira'],
    ];
    $bloqueadas = [];
    foreach ($casos as $rotulo => $dados) {
        $c = novaColecao($rotulo);
        Colecao::vincularVariacao($c, $v2);
        Colecao::atualizarResultadoVariacao($c, $v2, $dados);
        Colecao::criarTarefa($c, 'Tarefa ' . $rotulo, null);
        $antes = vinculo($c, $v2);
        ok("{$rotulo}: exclusão bloqueada", Colecao::excluirSemHistoricoComercial($c) === false);
        ok("{$rotulo}: coleção mantida", colecaoExiste($c));
        ok("{$rotulo}: resultado intacto", vinculo($c, $v2) === $antes);
        ok("{$rotulo}: tarefa mantida", contarTarefas($c) === 1);
        $bloqueadas[$c] = $antes;
    }

    // --- 6) Variação com resultado não pode ser excluída ---
    ok('6. variação com resultado: exclusão bloqueada', Variacao::excluirSemHistoricoComercial($v2) === false);
    ok('6. variação mantida', variacaoExiste($v2));
    ok('6. contador por variação = 4', Colecao::contarResultadosComerciaisDaVariacao($v2) === 4);

    $v3 = novaVariacao($produtoId, 'V3');
    $c6 = novaColecao('variacao sem resultado');
    Colecao::vincularVariacao($c6, $v3);
    ok('6. variação sem resultado: exclusão permitida', Variacao::excluirSemHistoricoComercial($v3) === true);
    ok('6. variação removida e vínculo vazio removido', !variacaoExiste($v3) && vinculo($c6, $v3) === null);
    ok('6. coleção da variação removida mantida', colecaoExiste($c6));

    // --- Desvincular: bloqueado com resultado, permitido sem ---
    $cBloq = array_key_first($bloqueadas);
    ok('desvincular com resultado: recusado', Colecao::desvincularVariacao($cBloq, $v2) === false);
    ok('desvincular com resultado: vínculo intacto', vinculo($cBloq, $v2) === $bloqueadas[$cBloq]);
    $v4 = novaVariacao($produtoId, 'V4');
    Colecao::vincularVariacao($c6, $v4);
    ok('desvincular sem resultado: permitido', Colecao::desvincularVariacao($c6, $v4) === true && vinculo($c6, $v4) === null);

    $idsAntes = Colecao::idsDaVariacao($v2);
    $lancou = false;
    try {
        Colecao::sincronizarColecoesDaVariacao($v2, [$c6]);
    } catch (DomainException) {
        $lancou = true;
    }
    ok('sincronizar removendo vínculo com resultado: recusado', $lancou);
    ok('sincronizar recusado: nada alterado (rollback)', Colecao::idsDaVariacao($v2) === $idsAntes);
    Colecao::sincronizarColecoesDaVariacao($v4, [$c6]);
    Colecao::sincronizarColecoesDaVariacao($v4, []);
    ok('sincronizar sem resultado: vincula e desvincula', Colecao::idsDaVariacao($v4) === []);

    // --- 9 e 10) Rotas reais (subprocesso) ---
    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($userId === 0) {
        ok('rotas: há usuário ativo local para simular sessão', false);
    } else {
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

        $produtoUri = '/produtos/' . $produtoId;

        $r = rota('/colecoes/' . $cBloq . '/excluir', [], $userId);
        ok('9. POST excluir coleção com resultado: bloqueado', colecaoExiste($cBloq), flashTexto($r, 'errors'));
        ok('9. mensagem de erro clara', str_contains(flashTexto($r, 'errors'), 'resultados comerciais'));
        ok('9. resultado intacto após POST', vinculo($cBloq, $v2) === $bloqueadas[$cBloq]);

        $r = rota('/colecoes/' . $cBloq . '/excluir', ['confirmar_desvincular' => '1'], $userId);
        ok('9. POST com confirmar_desvincular não contorna', colecaoExiste($cBloq));

        $r = rota($produtoUri . '/variacoes/' . $v2 . '/excluir', [], $userId);
        ok('9. POST excluir variação com resultado: bloqueado', variacaoExiste($v2), flashTexto($r, 'errors'));
        ok('9. mensagem cita 4 coleções e inativação', str_contains(flashTexto($r, 'errors'), '4 coleção(ões)')
            && str_contains(flashTexto($r, 'errors'), 'inative'));

        $r = rota('/colecoes/' . $cBloq . '/desvincular-variacao', ['variacao_id' => (string) $v2], $userId);
        ok('9. POST desvincular com resultado: bloqueado', vinculo($cBloq, $v2) === $bloqueadas[$cBloq], flashTexto($r, 'errors'));

        $r = rota($produtoUri . '/variacoes/' . $v2, [
            'codigo' => 'TST-EXC-' . $sufixo . '-V2',
            'nome' => 'Nome alterado',
            'estoque' => '0',
            'status' => 'ativo',
            'pacote_modo' => 'herdar',
            'colecoes_enviadas' => '1',
            'colecoes' => [],
        ], $userId);
        ok('9. editar variação desmarcando coleções com resultado: recusado',
            Colecao::idsDaVariacao($v2) === $idsAntes, flashTexto($r, 'errors'));
        ok('9. edição recusada não gravou outros campos', (Variacao::find($v2)['nome'] ?? '') === 'Teste V2');

        $v5 = novaVariacao($produtoId, 'V5');
        $c9 = novaColecao('rota ok');
        Colecao::vincularVariacao($c9, $v5);
        Colecao::criarTarefa($c9, 'Tarefa rota', null);
        $r = rota('/colecoes/' . $c9 . '/excluir', [], $userId);
        ok('9. POST excluir coleção sem resultado: permitido', !colecaoExiste($c9) && variacaoExiste($v5), flashTexto($r, 'success'));
        ok('9. mensagem informa variação mantida e tarefa removida',
            str_contains(flashTexto($r, 'success'), '1 variação(ões) desvinculada(s) e mantida(s)')
            && str_contains(flashTexto($r, 'success'), '1 tarefa(s) removida(s)'));

        $c9b = novaColecao('rota variacao');
        Colecao::vincularVariacao($c9b, $v5);
        $r = rota($produtoUri . '/variacoes/' . $v5 . '/excluir', [], $userId);
        ok('9. POST excluir variação sem resultado: permitido', !variacaoExiste($v5), flashTexto($r, 'success'));
        ok('9. mensagem informa coleções deixadas', str_contains(flashTexto($r, 'success'), '1 coleção(ões)'));

        // --- 10) CSRF e login continuam exigidos ---
        $c10 = novaColecao('csrf');
        $r = rota('/colecoes/' . $c10 . '/excluir', [], $userId, false);
        ok('10. CSRF inválido: HTTP 419', ($r['code'] ?? null) === 419, 'code=' . var_export($r['code'] ?? null, true));
        ok('10. CSRF inválido: coleção mantida', colecaoExiste($c10));
        $r = rota('/colecoes/' . $c10 . '/excluir', [], null);
        ok('10. sem login: coleção mantida', colecaoExiste($c10));
    }

    // --- 7 e 8) Resultados e tarefas preexistentes intactos ---
    foreach ($bloqueadas as $c => $antes) {
        ok("7/8. coleção {$c}: resultado e tarefa intactos ao final", vinculo($c, $v2) === $antes && contarTarefas($c) === 1);
    }
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($colecoesCriadas) {
        $in = implode(',', array_map('intval', $colecoesCriadas));
        $pdo->exec("DELETE FROM colecoes WHERE id IN ({$in})");
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

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM colecoes WHERE nome LIKE 'TST-EXC-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-EXC-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-EXC-%'")->fetchColumn();
ok('limpeza: nenhum dado temporário restante', $sobras === 0, "sobras={$sobras}");

$sigDepois = (string) $pdo->query(
    "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
            IFNULL(COLUMN_DEFAULT, 'NULL')) ORDER BY TABLE_NAME, ORDINAL_POSITION SEPARATOR '|'))
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();
ok('estrutura do banco inalterada', $sigAntes === $sigDepois);
$resultadosDepois = (string) $pdo->query(
    "SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS(':', colecao_id, variacao_id, IFNULL(qtd_vendida, 'N'),
            IFNULL(qtd_produzida, 'N'), IFNULL(valor_vendido, 'N'), IFNULL(observacao, 'N'))
            ORDER BY colecao_id, variacao_id SEPARATOR '|'), ''))
     FROM colecao_variacoes"
)->fetchColumn();
ok('7. resultados preexistentes intactos', $resultadosExistentes === $resultadosDepois);
ok('8. tarefas preexistentes intactas', $tarefasExistentes === (int) $pdo->query('SELECT COUNT(*) FROM colecao_tarefas')->fetchColumn());

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

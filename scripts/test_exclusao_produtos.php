<?php
/**
 * Testes locais — exclusão segura de produtos (pai + variações exclusivas).
 * Cria dados temporários (TST-EXPROD-*) e remove tudo ao final.
 * NÃO executar em produção. Uso: php scripts/test_exclusao_produtos.php
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
$prefixo = 'TST-EXPROD-' . $sufixo;
$idsProdutos = [];
$idsColecoes = [];
$idsPecas = [];
$idsArquivos = [];
$fotoDirAbsLimpeza = null;
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_exprod_' . $sufixo;
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_exprod_runner_' . $sufixo . '.php';

function novoProduto(string $rotulo): int
{
    global $idsProdutos, $prefixo;
    $id = Produto::create([
        'codigo_interno' => $prefixo . '-' . $rotulo,
        'nome' => 'Exclusão produto ' . $rotulo,
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
    $idsProdutos[] = $id;
    return $id;
}

function novaVariacao(int $produtoId, string $rotulo, ?string $gtinU = null, ?string $gtinP = null): int
{
    global $prefixo;
    return Variacao::create([
        'produto_id' => $produtoId,
        'codigo' => $prefixo . '-V-' . $rotulo,
        'nome' => 'Var ' . $rotulo,
        'estoque' => 3,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
        'gtin_unidade' => $gtinU,
        'gtin_pacote' => $gtinP,
    ]);
}

function novaColecao(string $rotulo): int
{
    global $idsColecoes, $prefixo;
    $id = Colecao::create([
        'codigo' => null,
        'nome' => $prefixo . ' ' . $rotulo,
        'tipo' => 'especial',
        'descricao' => null,
        'imagem' => null,
        'observacoes' => null,
        'periodo_inicio' => null,
        'periodo_fim' => null,
        'etapa' => 'planejamento',
        'status' => 'ativo',
    ]);
    $idsColecoes[] = $id;
    return $id;
}

function produtoExiste(int $id): bool
{
    return Produto::find($id) !== null;
}

function variacaoExiste(int $id): bool
{
    return Variacao::find($id) !== null;
}

function rota(string $method, string $uri, array $post, ?int $userId, bool $csrfValido = true, string $perfil = 'admin'): array
{
    global $runner, $sessDir;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $path = $uri;
    $query = [];
    if (str_contains($uri, '?')) {
        [$path, $qs] = explode('?', $uri, 2);
        parse_str($qs, $query);
    }
    $req = [
        'method' => $method,
        'uri' => $path,
        'query' => $query,
        'post' => $post + ($method === 'POST' ? ['_csrf' => $csrfValido ? $token : 'token-invalido'] : []),
        'token' => $token,
        'user_id' => $userId,
        'perfil' => $perfil,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
    ];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['code' => null, 'flash' => null, 'html' => $out, 'raw' => $out];
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
$produtosPermanentes = (int) $pdo->query(
    "SELECT COUNT(*) FROM produtos WHERE codigo_interno NOT LIKE 'TST-%'"
)->fetchColumn();
$variacoesPermanentes = (int) $pdo->query(
    "SELECT COUNT(*) FROM produto_variacoes WHERE codigo NOT LIKE 'TST-%'"
)->fetchColumn();
$estoquePecasAntes = (int) $pdo->query('SELECT COUNT(*) FROM estoque_pecas')->fetchColumn();
$movPecasAntes = (int) $pdo->query('SELECT COUNT(*) FROM estoque_pecas_movimentos')->fetchColumn();
$materiaisAntes = (int) $pdo->query('SELECT COUNT(*) FROM materiais')->fetchColumn();
$colecoesAntes = (int) $pdo->query('SELECT COUNT(*) FROM colecoes')->fetchColumn();

try {
    // --- 1) Produto sem dependências protegidas ---
    $p1 = novoProduto('SIMPLES');
    $r = Produto::excluirSeguro($p1);
    ok('1. produto simples: exclusão permitida', $r['ok'] === true && $r['variacoes'] === 0);
    ok('1. produto simples: removido', !produtoExiste($p1));

    // --- 2) Produto com variações, composição, GTINs e vínculo de coleção sem resultado ---
    $p2 = novoProduto('COM-VARS');
    $v2a = novaVariacao($p2, 'A', '96385074', null);
    $v2b = novaVariacao($p2, 'B', null, '036000291452');
    Composicao::createManual($p2, 'Custo manual teste', 1.5, 2.0);
    Composicao::createManualVariacao($v2a, 'Custo var teste', 0.8, 1.0);
    $c2 = novaColecao('sem resultado');
    Colecao::vincularVariacao($c2, $v2a);
    ok('2. contadores pré-exclusão', Produto::contarVariacoes($p2) === 2
        && Produto::contarResultadosComerciais($p2) === 0
        && Produto::contarVinculosColecaoSemResultado($p2) === 1
        && Produto::contarArquivos($p2) === 0);
    $r = Produto::excluirSeguro($p2);
    ok('2. produto com variações: exclusão permitida', $r['ok'] === true && $r['variacoes'] === 2);
    ok('2. produto e variações removidos', !produtoExiste($p2) && !variacaoExiste($v2a) && !variacaoExiste($v2b));
    ok('2. GTINs liberados (podem ser reutilizados)', Variacao::gtinEmUso('96385074') === null
        && Variacao::gtinEmUso('036000291452') === null);
    $compRestante = (int) $pdo->query(
        'SELECT COUNT(*) FROM produto_composicao WHERE produto_id = ' . (int) $p2
    )->fetchColumn();
    $varCompRestante = (int) $pdo->query(
        'SELECT COUNT(*) FROM variacao_composicao WHERE variacao_id IN (' . (int) $v2a . ',' . (int) $v2b . ')'
    )->fetchColumn();
    ok('2. composições exclusivas removidas', $compRestante === 0 && $varCompRestante === 0);
    ok('2. coleção preservada; vínculo sem resultado removido', Colecao::find($c2) !== null
        && Colecao::idsDaVariacao($v2a) === []);

    // --- 3) Outro produto permanece intacto ---
    $pVizinho = novoProduto('VIZINHO');
    $vVizinho = novaVariacao($pVizinho, 'VZ', '4006381333931', null);
    $p3 = novoProduto('ALVO');
    novaVariacao($p3, 'ALVO1');
    $r = Produto::excluirSeguro($p3);
    ok('3. exclusão do alvo ok', $r['ok'] === true);
    ok('3. vizinho intacto', produtoExiste($pVizinho) && variacaoExiste($vVizinho)
        && (Variacao::find($vVizinho)['gtin_unidade'] ?? null) === '4006381333931');

    // --- 4) Bloqueio por histórico comercial ---
    $p4 = novoProduto('HIST');
    $v4 = novaVariacao($p4, 'H1');
    $c4 = novaColecao('com resultado');
    Colecao::vincularVariacao($c4, $v4);
    Colecao::atualizarResultadoVariacao($c4, $v4, ['qtd_vendida' => 10]);
    ok('4. contador de resultados = 1', Produto::contarResultadosComerciais($p4) === 1);
    $r = Produto::excluirSeguro($p4);
    ok('4. exclusão bloqueada por histórico', $r['ok'] === false && $r['motivo'] === 'resultados');
    ok('4. produto, variação e resultado intactos', produtoExiste($p4) && variacaoExiste($v4)
        && Colecao::contarResultadosComerciaisDaVariacao($v4) === 1);

    // --- 5) Bloqueio por arquivos técnicos ---
    $p5 = novoProduto('ARQ');
    $v5 = novaVariacao($p5, 'ARQ1');
    $arqId = Arquivo::create([
        'produto_id' => $p5,
        'tipo' => 'outro',
        'nome' => 'Arquivo teste',
        'nome_original' => 'teste.txt',
        'arquivo_fisico' => 'teste-exprod.txt',
        'caminho' => 'arquivos/teste-exprod-' . $sufixo . '.txt',
        'extensao' => 'txt',
        'mime' => 'text/plain',
        'tamanho' => 4,
        'observacao' => null,
        'usuario_id' => null,
    ]);
    $idsArquivos[] = $arqId;
    $r = Produto::excluirSeguro($p5);
    ok('5. exclusão bloqueada por arquivo', $r['ok'] === false && $r['motivo'] === 'arquivos');
    ok('5. produto e arquivo intactos', produtoExiste($p5) && Arquivo::find($arqId) !== null);

    // --- 6) Estoque físico: vínculo bloqueia (FK faria SET NULL e apagaria a associação comercial) ---
    $p6 = novoProduto('EST');
    $pecaId = EstoquePeca::create([
        'codigo' => $prefixo . '-PECA',
        'nome' => 'Peça teste exclusão',
        'tipo' => 'outra',
        'produto_id' => $p6,
        'cor' => null,
        'estampa' => null,
        'quantidade' => 5,
        'unidade' => 'un',
        'localizacao' => null,
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $idsPecas[] = $pecaId;
    $movAntesPeca = (int) $pdo->query(
        'SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = ' . (int) $pecaId
    )->fetchColumn();
    $saldoAntes = (float) (EstoquePeca::find($pecaId)['quantidade'] ?? -1);
    ok('6. contador de peças vinculadas = 1', Produto::contarPecasEstoque($p6) === 1);
    $r = Produto::excluirSeguro($p6);
    ok('6. exclusão bloqueada por peça vinculada', $r['ok'] === false && $r['motivo'] === 'estoque');
    $peca = EstoquePeca::find($pecaId);
    ok('6. peça intacta (produto_id e saldo preservados)', $peca !== null
        && (int) $peca['produto_id'] === $p6
        && (float) $peca['quantidade'] === $saldoAntes
        && (float) $peca['quantidade'] === 5.0);
    $movDepoisPeca = (int) $pdo->query(
        'SELECT COUNT(*) FROM estoque_pecas_movimentos WHERE peca_id = ' . (int) $pecaId
    )->fetchColumn();
    ok('6. movimentos da peça intactos (nada alterado)', $movAntesPeca === $movDepoisPeca && $movDepoisPeca > 0);
    ok('6. produto permanece (sem SET NULL silencioso)', produtoExiste($p6));

    // Peça com saldo zero também bloqueia (vínculo comercial, não só saldo)
    $p6b = novoProduto('EST0');
    $peca0 = EstoquePeca::create([
        'codigo' => $prefixo . '-PECA0',
        'nome' => 'Peça saldo zero',
        'tipo' => 'outra',
        'produto_id' => $p6b,
        'cor' => null,
        'estampa' => null,
        'quantidade' => 0,
        'unidade' => 'un',
        'localizacao' => null,
        'imagem' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $idsPecas[] = $peca0;
    $r = Produto::excluirSeguro($p6b);
    ok('6b. saldo zero com vínculo: exclusão bloqueada', $r['ok'] === false && $r['motivo'] === 'estoque'
        && produtoExiste($p6b) && (int) (EstoquePeca::find($peca0)['produto_id'] ?? 0) === $p6b);

    // --- 6c) Foto principal: exclusiva vs compartilhada (duplicação copia o caminho) ---
    $uploadsRoot = dirname(__DIR__) . '/public/uploads';
    $fotoDirRel = 'produtos/teste-exprod/' . $sufixo;
    $fotoDirAbs = $uploadsRoot . '/' . $fotoDirRel;
    $fotoDirAbsLimpeza = $fotoDirAbs;
    if (!is_dir($fotoDirAbs)) {
        mkdir($fotoDirAbs, 0755, true);
    }
    $fotoExclusivaRel = $fotoDirRel . '/unica.png';
    $fotoCompartilhadaRel = $fotoDirRel . '/compartilhada.png';
    $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($fotoDirAbs . '/unica.png', $png1x1);
    file_put_contents($fotoDirAbs . '/compartilhada.png', $png1x1);

    $pFoto = novoProduto('FOTO');
    $pdo->prepare('UPDATE produtos SET foto_principal = :f WHERE id = :id')
        ->execute(['f' => $fotoExclusivaRel, 'id' => $pFoto]);
    $r = Produto::excluirSeguro($pFoto);
    ok('6c. exclusão com foto exclusiva: ok e devolve caminho', $r['ok'] === true && $r['foto'] === $fotoExclusivaRel);
    Upload::removerImagemPublica($r['foto']);
    ok('6c. arquivo exclusivo removido após commit', !is_file($fotoDirAbs . '/unica.png'));

    $pFotoA = novoProduto('FOTO-A');
    $pdo->prepare('UPDATE produtos SET foto_principal = :f WHERE id = :id')
        ->execute(['f' => $fotoCompartilhadaRel, 'id' => $pFotoA]);
    $pFotoB = Produto::duplicar($pFotoA);
    $idsProdutos[] = $pFotoB;
    ok('6c. duplicação compartilha o caminho da foto',
        (string) (Produto::find($pFotoB)['foto_principal'] ?? '') === $fotoCompartilhadaRel);
    $r = Produto::excluirSeguro($pFotoA);
    ok('6c. exclusão com foto compartilhada: ok sem devolver caminho', $r['ok'] === true && $r['foto'] === null);
    ok('6c. arquivo compartilhado permanece no disco', is_file($fotoDirAbs . '/compartilhada.png'));
    ok('6c. cópia ainda referencia a foto',
        (string) (Produto::find($pFotoB)['foto_principal'] ?? '') === $fotoCompartilhadaRel);

    // --- 7) Rollback: motivo resultados não remove nada parcial ---
    $p7 = novoProduto('ROLL');
    $v7a = novaVariacao($p7, 'R1');
    $v7b = novaVariacao($p7, 'R2');
    Composicao::createManual($p7, 'Linha rollback', 2.0, 1.0);
    $c7 = novaColecao('rollback');
    Colecao::vincularVariacao($c7, $v7a);
    Colecao::atualizarResultadoVariacao($c7, $v7a, ['valor_vendido' => '99.50']);
    $antesComp = (int) $pdo->query(
        'SELECT COUNT(*) FROM produto_composicao WHERE produto_id = ' . (int) $p7
    )->fetchColumn();
    $r = Produto::excluirSeguro($p7);
    ok('7. rollback: exclusão recusada', $r['ok'] === false);
    ok('7. rollback: produto e duas variações intactos', produtoExiste($p7)
        && variacaoExiste($v7a) && variacaoExiste($v7b));
    ok('7. rollback: composição intacta', (int) $pdo->query(
        'SELECT COUNT(*) FROM produto_composicao WHERE produto_id = ' . (int) $p7
    )->fetchColumn() === $antesComp);
    ok('7. rollback: resultado comercial intacto', Colecao::contarResultadosComerciaisDaVariacao($v7a) === 1);

    // --- 8) Rotas HTTP: confirmação, exclusão, CSRF, método, autorização, ações existentes ---
    $userId = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' AND perfil = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $userComum = (int) ($pdo->query("SELECT id FROM usuarios WHERE status = 'ativo' AND perfil <> 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if ($userId === 0) {
        ok('8. há usuário admin ativo local', false);
    } else {
        @mkdir($sessDir, 0700, true);
        file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'] . (!empty($req['query']) ? ('?' . http_build_query($req['query'])) : '');
$_GET = $req['query'] ?? [];
$_POST = $req['post'];
ob_start();
require $req['root'] . '/app/bootstrap.php';
$_SESSION['_csrf'] = $req['token'];
if ($req['user_id'] !== null) {
    $_SESSION['user'] = [
        'id' => $req['user_id'],
        'nome' => 'Teste',
        'email' => 'teste@local',
        'perfil' => $req['perfil'] ?? 'admin',
    ];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
$html = '';
register_shutdown_function(static function () use (&$html): void {
    $html = (string) ob_get_contents();
    ob_end_clean();
    echo '@@RESULT@@' . json_encode([
        'code' => http_response_code(),
        'flash' => $_SESSION['_flash'] ?? null,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE);
});
require $req['root'] . '/app/routes.php';
PHP);

        $pOk = novoProduto('HTTP-OK');
        $vOk = novaVariacao($pOk, 'HTTP1');
        $r = rota('GET', '/produtos/' . $pOk . '/excluir', [], $userId);
        $codeGet = $r['code'] ?? null;
        ok('8. GET confirmação: página renderizada', ($codeGet === 200 || $codeGet === false || $codeGet === null)
            && str_contains((string) ($r['html'] ?? ''), 'Excluir produto'),
            'code=' . var_export($codeGet, true));
        $html = (string) ($r['html'] ?? $r['raw'] ?? '');
        ok('8. confirmação mostra código e nome', str_contains($html, $prefixo . '-HTTP-OK')
            && str_contains($html, 'Exclusão produto HTTP-OK'));
        ok('8. confirmação informa variações e caráter permanente', str_contains($html, '1 variação(ões)')
            && str_contains($html, 'permanente'));
        ok('8. confirmação não excluiu ainda', produtoExiste($pOk) && variacaoExiste($vOk));

        $r = rota('POST', '/produtos/' . $pOk . '/excluir', [], $userId);
        ok('8. POST exclusão permitida', !produtoExiste($pOk) && !variacaoExiste($vOk), flashTexto($r, 'success'));
        ok('8. mensagem de sucesso', str_contains(flashTexto($r, 'success'), 'excluído permanentemente')
            && str_contains(flashTexto($r, 'success'), '1 variação(ões)'));

        $pBloq = novoProduto('HTTP-BLOQ');
        $vBloq = novaVariacao($pBloq, 'HB');
        $cBloq = novaColecao('http bloq');
        Colecao::vincularVariacao($cBloq, $vBloq);
        Colecao::atualizarResultadoVariacao($cBloq, $vBloq, ['observacao' => 'Histórico comercial']);
        $r = rota('GET', '/produtos/' . $pBloq . '/excluir', [], $userId);
        $html = (string) ($r['html'] ?? $r['raw'] ?? '');
        ok('8. GET com histórico: informa impedimento', str_contains($html, 'não pode ser excluído')
            && str_contains($html, 'resultados comerciais'));
        ok('8. GET com histórico: sem botão permanente', !str_contains($html, 'Excluir permanentemente'));
        $r = rota('POST', '/produtos/' . $pBloq . '/excluir', [], $userId);
        ok('8. POST com histórico: bloqueado', produtoExiste($pBloq) && variacaoExiste($vBloq), flashTexto($r, 'errors'));
        ok('8. POST com histórico: mensagem clara', str_contains(flashTexto($r, 'errors'), 'resultados comerciais'));

        $pCsrf = novoProduto('CSRF');
        $r = rota('POST', '/produtos/' . $pCsrf . '/excluir', [], $userId, false);
        ok('8. CSRF inválido: HTTP 419', ($r['code'] ?? null) === 419, 'code=' . var_export($r['code'] ?? null, true));
        ok('8. CSRF inválido: produto mantido', produtoExiste($pCsrf));

        $r = rota('POST', '/produtos/' . $pCsrf . '/excluir', [], null);
        ok('8. sem login: produto mantido', produtoExiste($pCsrf));

        // Desativar e Duplicar continuam funcionando; listagem mantém as quatro ações
        $pAcoes = novoProduto('ACOES');
        $r = rota('GET', '/produtos?q=' . rawurlencode($prefixo . '-ACOES'), [], $userId);
        $html = (string) ($r['html'] ?? $r['raw'] ?? '');
        ok('8. listagem mantém Editar, Duplicar, Desativar e Excluir',
            str_contains($html, 'Editar')
            && str_contains($html, 'Duplicar')
            && str_contains($html, 'Desativar')
            && str_contains($html, 'Excluir')
            && str_contains($html, 'link-danger'),
            'len=' . strlen($html));

        $r = rota('POST', '/produtos/' . $pAcoes . '/status', [], $userId);
        ok('8. Desativar continua funcionando', (Produto::find($pAcoes)['status'] ?? '') === 'inativo', flashTexto($r, 'success'));
        $r = rota('POST', '/produtos/' . $pAcoes . '/duplicar', [], $userId);
        $stmt = $pdo->prepare("SELECT id FROM produtos WHERE codigo_interno = :c LIMIT 1");
        $stmt->execute(['c' => $prefixo . '-ACOES-COPIA']);
        $novoId = (int) ($stmt->fetchColumn() ?: 0);
        if ($novoId > 0) {
            $idsProdutos[] = $novoId;
        }
        ok('8. Duplicar continua funcionando', $novoId > 0 && produtoExiste($novoId), 'novoId=' . $novoId);

        // Método inadequado: GET não exclui (só confirma)
        $pGet = novoProduto('GETONLY');
        $r = rota('GET', '/produtos/' . $pGet . '/excluir', [], $userId);
        ok('8. GET /excluir não remove o produto', produtoExiste($pGet));
    }

    ok('materiais permanentes intactos', (int) $pdo->query('SELECT COUNT(*) FROM materiais')->fetchColumn() === $materiaisAntes);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($idsArquivos) {
        $in = implode(',', array_map('intval', $idsArquivos));
        $pdo->exec("DELETE FROM arquivos_produtos WHERE id IN ({$in})");
    }
    if ($idsPecas) {
        $in = implode(',', array_map('intval', $idsPecas));
        $pdo->exec("DELETE FROM estoque_pecas_movimentos WHERE peca_id IN ({$in})");
        $pdo->exec("DELETE FROM estoque_pecas WHERE id IN ({$in})");
    }
    if ($idsColecoes) {
        $in = implode(',', array_map('intval', $idsColecoes));
        $pdo->exec("DELETE FROM colecoes WHERE id IN ({$in})");
    }
    // Produtos de teste (cascata remove variações/composições)
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE codigo_interno LIKE :p");
    $stmt->execute(['p' => $prefixo . '%']);
    // Duplicatas -COPIA
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE codigo_interno LIKE :p");
    $stmt->execute(['p' => $prefixo . '%']);
    if (is_file($runner)) {
        @unlink($runner);
    }
    if (is_dir($sessDir)) {
        foreach (glob($sessDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($sessDir);
    }
    if (is_string($fotoDirAbsLimpeza) && is_dir($fotoDirAbsLimpeza)) {
        foreach (glob($fotoDirAbsLimpeza . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($fotoDirAbsLimpeza);
        $pai = dirname($fotoDirAbsLimpeza);
        if (is_dir($pai) && count(glob($pai . DIRECTORY_SEPARATOR . '*') ?: []) === 0) {
            @rmdir($pai);
        }
    }
}

$sobras = (int) $pdo->query("SELECT COUNT(*) FROM produtos WHERE codigo_interno LIKE 'TST-EXPROD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM produto_variacoes WHERE codigo LIKE 'TST-EXPROD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM colecoes WHERE nome LIKE 'TST-EXPROD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM estoque_pecas WHERE codigo LIKE 'TST-EXPROD-%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM arquivos_produtos WHERE caminho LIKE '%teste-exprod-%'")->fetchColumn();
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
ok('resultados comerciais preexistentes intactos', $resultadosExistentes === $resultadosDepois);
ok('produtos permanentes intactos', $produtosPermanentes === (int) $pdo->query(
    "SELECT COUNT(*) FROM produtos WHERE codigo_interno NOT LIKE 'TST-%'"
)->fetchColumn());
ok('variações permanentes intactas', $variacoesPermanentes === (int) $pdo->query(
    "SELECT COUNT(*) FROM produto_variacoes WHERE codigo NOT LIKE 'TST-%'"
)->fetchColumn());
ok('estoque de peças (quantidade de registros) não reduzido indevidamente',
    (int) $pdo->query('SELECT COUNT(*) FROM estoque_pecas')->fetchColumn() === $estoquePecasAntes);
ok('movimentos de estoque de peças preexistentes intactos em quantidade',
    (int) $pdo->query('SELECT COUNT(*) FROM estoque_pecas_movimentos')->fetchColumn() === $movPecasAntes);
ok('coleções permanentes: contagem restaurada',
    (int) $pdo->query('SELECT COUNT(*) FROM colecoes')->fetchColumn() === $colecoesAntes);
ok('materiais intactos ao final',
    (int) $pdo->query('SELECT COUNT(*) FROM materiais')->fetchColumn() === $materiaisAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

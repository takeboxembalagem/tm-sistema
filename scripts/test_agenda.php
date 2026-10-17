<?php
/**
 * Testes locais — Agenda.
 *
 * Parte A (executável hoje): regressão da Agenda atual (tarefas simples) e segurança das rotas existentes.
 *   Chama as rotas reais (subprocesso logado) e o model AgendaTarefa.
 * Parte B (preparada): Planos, Subtarefas, responsáveis, datas, situação das subtarefas, progresso calculado,
 *   conclusão e exclusão de plano, compatibilidade e segurança das rotas novas.
 *   Só roda quando a estrutura nova existir no banco local (agenda_planos + plano_id/responsavel_id/data_inicio/
 *   em_andamento em agenda_tarefas). Até lá cada caso aparece como [BLOQUEADO] — não conta como OK nem como falha.
 *   Rotas e métodos esperados da implementação ficam no bloco "CONTRATO PREVISTO" abaixo.
 *
 * Isolamento: banco temporário pm_tmp_agenda_* com a estrutura copiada (SHOW CREATE TABLE) do banco local,
 * apagado ao final. Usuários e tarefas de teste existem só nele. O banco local só é lido (assinatura antes/depois).
 * Não cria nem altera estrutura do banco local: se agenda_tarefas não tiver as colunas atuais, falha e para.
 * NÃO executar em produção. Uso: php scripts/test_agenda.php
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

/* ===================== CONTRATO PREVISTO (Parte B) =====================
 * Ajustar aqui se a implementação de Planos/Subtarefas escolher outros nomes.
 * Plano:      POST /agenda/planos/criar            (titulo, observacao, data_inicio, data_fim, responsavel_id)
 *             POST /agenda/planos/{id}/editar      (mesmos campos)
 *             POST /agenda/planos/{id}/status      (status)
 *             POST /agenda/planos/{id}/excluir
 * Subtarefa:  POST /agenda/criar e /agenda/{id}/editar aceitam também plano_id, responsavel_id, data_inicio
 *             POST /agenda/{id}/andamento          (marca em_andamento = 1)
 * Progresso:  AgendaPlano::progresso(int $planoId): ?int        (null = sem subtarefas)
 *             AgendaPlano::rotuloProgresso(int $planoId): string ('Sem subtarefas' ou 'N%')
 * Situação:   AgendaTarefa::situacao(array $tarefa): string     ('pendente' | 'em_andamento' | 'concluida')
 * Tela:       GET /agenda?nova=1 tem <select name="responsavel_id"> só com usuários ativos; responsável inativo já
 *             atribuído aparece na listagem como "Nome (inativo)".
 */
const ROTA_PLANO_CRIAR = '/agenda/planos/criar';
const PLANO_STATUS = ['planejado', 'em_andamento', 'pausado', 'concluido', 'cancelado'];
const COLUNAS_PLANO = ['id', 'titulo', 'observacao', 'data_inicio', 'data_fim', 'responsavel_id', 'status', 'created_at', 'updated_at'];
const COLUNAS_SUBTAREFA = ['plano_id', 'responsavel_id', 'data_inicio', 'em_andamento'];

function rotaPlano(int $id, string $acao): string
{
    return '/agenda/planos/' . $id . '/' . $acao;
}

function rotaAndamento(int $id): string
{
    return '/agenda/' . $id . '/andamento';
}

$pass = 0;
$fail = 0;
$bloqueados = [];

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

function bloqueado(string $grupo, string $nome): void
{
    global $bloqueados;
    $bloqueados[$grupo] = ($bloqueados[$grupo] ?? 0) + 1;
    echo "[BLOQUEADO] {$grupo}: {$nome}" . PHP_EOL;
}

function assinaturaBanco(PDO $pdo): string
{
    $tabelas = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_COLUMN);
    $s = '';
    foreach ($tabelas as $t) {
        $s .= $t . '=' . $pdo->query('CHECKSUM TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] . ';';
    }
    $pdo->exec('SET SESSION group_concat_max_len = 16777216');
    $s .= (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, 'N')) ORDER BY TABLE_NAME, ORDINAL_POSITION)
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
    return md5($s);
}

/** Aponta a aplicação (config + conexão do Database) para outro banco. */
function usarBanco(string $nome): PDO
{
    $GLOBALS['config']['db']['name'] = $nome;
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    return Database::pdo();
}

function colunas(string $tabela): array
{
    $stmt = Database::pdo()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute(['t' => $tabela]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$pdoLocal = Database::pdo();
$bancoLocal = (string) config('db.name');

$colunasEsperadas = ['id', 'titulo', 'data_tarefa', 'prioridade', 'observacao', 'concluida', 'concluida_em', 'created_at', 'updated_at'];
$colunasExistentes = colunas('agenda_tarefas');
$colunasFaltando = array_values(array_diff($colunasEsperadas, $colunasExistentes));
if ($colunasFaltando) {
    ok('estrutura: agenda_tarefas com as colunas esperadas', false,
        ($colunasExistentes ? 'colunas ausentes: ' . implode(', ', $colunasFaltando) : 'tabela agenda_tarefas ausente')
        . ' — aplique database/alter_agenda_tarefas.sql');
    echo "Demais testes NÃO executados (estrutura da Agenda ausente).\n";
    echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
    exit(1);
}
ok('estrutura: agenda_tarefas com as colunas esperadas', true);

$sufixo = date('His') . random_int(100, 999);
$prefixo = 'TST-AGENDA-' . $sufixo;
$bancoTeste = 'pm_tmp_agenda_' . $sufixo;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_agenda_' . $sufixo;
$sessDir = $tmpDir . DIRECTORY_SEPARATOR . 'sess';
$runner = $tmpDir . DIRECTORY_SEPARATOR . 'runner.php';

$sigLocalAntes = assinaturaBanco($pdoLocal);
echo "Assinatura do banco local antes: {$sigLocalAntes}" . PHP_EOL;

/** Executa a rota real num subprocesso (banco temporário), logado como $userId (0 = sem login). A query string vira $_GET. */
function rota(string $method, string $uri, int $userId, array $post = []): array
{
    global $runner, $sessDir, $bancoTeste;
    $token = 'tok-' . bin2hex(random_bytes(8));
    $req = [
        'method' => $method,
        'uri' => $uri,
        'post' => $post + ['_csrf' => $token],
        'token' => $token,
        'user_id' => $userId,
        'sess_dir' => $sessDir,
        'root' => dirname(__DIR__),
        'db' => $bancoTeste,
    ];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode(json_encode($req)));
    $out = (string) shell_exec($cmd);
    $pos = strrpos($out, '@@RESULT@@');
    if ($pos === false) {
        return ['html' => $out, 'flash' => null, 'code' => 0];
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

function csrfRecusado(array $r): bool
{
    return str_contains($r['html'], 'Sessão expirada ou token inválido');
}

function semErroPhp(array $r): bool
{
    return !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/i', $r['html']);
}

function valor(string $sql, array $params = []): mixed
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function linha(string $sql, array $params = []): ?array
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function totalTarefas(): int
{
    return (int) valor('SELECT COUNT(*) FROM agenda_tarefas');
}

/** Linha <tr> da tabela da Agenda que contém o título (null se a tarefa não aparece na tela). */
function linhaHtml(string $html, string $titulo): ?string
{
    foreach (preg_split('/<tr\b/', $html) ?: [] as $trecho) {
        if (str_contains($trecho, $titulo)) {
            return $trecho;
        }
    }
    return null;
}

/** Ids de $grupo devolvidos por AgendaTarefa::listar, na ordem da listagem. */
function idsListados(array $filtros, array $grupo): array
{
    $ids = array_map('intval', array_column(AgendaTarefa::listar($filtros), 'id'));
    return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $grupo, true)));
}

function mesmoConjunto(array $a, array $b): bool
{
    sort($a);
    sort($b);
    return $a === $b;
}

function nova(string $nome, string $data, string $prioridade = 'media'): int
{
    global $prefixo;
    return AgendaTarefa::create([
        'titulo' => $prefixo . ' ' . $nome,
        'data_tarefa' => $data,
        'prioridade' => $prioridade,
        'observacao' => null,
    ]);
}

$hoje = date('Y-m-d');
$dia = static fn (int $n): string => date('Y-m-d', (int) strtotime($hoje . ' ' . sprintf('%+d', $n) . ' days'));

$pdo = null;
try {
    // ================= Banco temporário (estrutura copiada do banco local, sem dados) =================
    $tabelasLocais = $pdoLocal->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_COLUMN);
    $pdoLocal->exec("CREATE DATABASE `{$bancoTeste}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = usarBanco($bancoTeste);
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $bancoTeste || $bancoTeste === $bancoLocal) {
        throw new RuntimeException('conexão de teste não está no banco temporário — abortado');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tabelasLocais as $t) {
        $pdo->exec((string) $pdoLocal->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec('SET SESSION group_concat_max_len = 16777216');
    ok('setup: banco temporário com a estrutura do banco local', count(colunas('agenda_tarefas')) === count($colunasExistentes)
        && (int) valor('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') === count($tabelasLocais),
        $bancoTeste . ', ' . count($tabelasLocais) . ' tabelas');

    @mkdir($sessDir, 0700, true);
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$req = json_decode(base64_decode($argv[1]), true);
ini_set('session.save_path', $req['sess_dir']);
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = $req['uri'];
$query = parse_url($req['uri'], PHP_URL_QUERY);
if (is_string($query)) {
    parse_str($query, $_GET);
}
if ($req['method'] === 'POST') {
    $_POST = $req['post'];
}
ob_start();
require $req['root'] . '/app/bootstrap.php';
$GLOBALS['config']['db']['name'] = $req['db'];
$_SESSION['_csrf'] = $req['token'];
if ((int) $req['user_id'] > 0) {
    $_SESSION['user'] = ['id' => $req['user_id'], 'nome' => 'Teste', 'email' => 'teste@local', 'perfil' => 'usuario'];
    $_SESSION[Auth::CHAVE_IMPRESSAO] = Auth::impressaoSenha((string) (Usuario::find((int) $req['user_id'])['senha_hash'] ?? ''));
}
register_shutdown_function(static function (): void {
    $html = (string) ob_get_clean();
    echo $html . '@@RESULT@@' . json_encode(['flash' => $_SESSION['_flash'] ?? null, 'code' => http_response_code()]);
});
require $req['root'] . '/app/routes.php';
PHP);

    $novoUsuario = static function (string $nome, string $perfil, string $status) use ($sufixo): int {
        return Usuario::create([
            'nome' => $nome,
            'email' => 'tst-agenda-' . strtolower(str_replace(' ', '-', $nome)) . '-' . $sufixo . '@teste.local',
            'senha_hash' => Usuario::hashSenha('senha-agenda-' . $sufixo),
            'status' => $status,
            'perfil' => $perfil,
        ]);
    };
    $user = $novoUsuario('TST AGENDA usuario', 'usuario', 'ativo');
    $admin = $novoUsuario('TST AGENDA admin', 'admin', 'ativo');
    $inativo = $novoUsuario('TST AGENDA inativo', 'usuario', 'inativo');
    ok('setup: usuários temporários (comum, admin, inativo) no banco temporário',
        (Usuario::find($user)['perfil'] ?? '') === 'usuario' && (Usuario::find($admin)['perfil'] ?? '') === 'admin'
        && (Usuario::find($inativo)['status'] ?? '') === 'inativo');

    $resumoAntes = AgendaTarefa::resumo();

    // ======================================================================
    // PARTE A — AGENDA ATUAL (REGRESSÃO)
    // ======================================================================

    // ================= 1. CRIAÇÃO =================
    $tituloCriar = $prefixo . ' Criar';
    $r = rota('POST', '/agenda/criar', $user, [
        'titulo' => '  ' . $tituloCriar . '  ',
        'data_tarefa' => $dia(3),
        'prioridade' => 'alta',
        'observacao' => 'Observação ' . $prefixo,
    ]);
    $criada = linha('SELECT * FROM agenda_tarefas WHERE titulo = :t', ['t' => $tituloCriar]);
    $idCriar = (int) ($criada['id'] ?? 0);
    ok('1. cria tarefa simples (título sem espaços nas pontas, data, prioridade, observação)', $criada !== null
        && $criada['data_tarefa'] === $dia(3)
        && $criada['prioridade'] === 'alta'
        && $criada['observacao'] === 'Observação ' . $prefixo
        && (int) $criada['concluida'] === 0
        && $criada['concluida_em'] === null, flashTexto($r, 'errors'));
    ok('1b. criação: mensagem de sucesso', flashTexto($r, 'success') === 'Tarefa adicionada à agenda.', flashTexto($r, 'success'));

    rota('POST', '/agenda/criar', $user, ['titulo' => $prefixo . ' SemObs', 'data_tarefa' => $dia(40), 'prioridade' => 'baixa', 'observacao' => '   ']);
    ok('1c. observação em branco é gravada como NULL',
        (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t AND observacao IS NULL', ['t' => $prefixo . ' SemObs']) === 1);

    $titulo255 = $prefixo . ' ' . str_repeat('ç', 255 - mb_strlen($prefixo) - 1);
    $r = rota('POST', '/agenda/criar', $user, ['titulo' => $titulo255, 'data_tarefa' => $dia(40), 'prioridade' => 'media', 'observacao' => str_repeat('x', 2000)]);
    ok('1d. limites aceitos: título com 255 caracteres (multibyte) e observação com 2000',
        mb_strlen($titulo255) === 255 && (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t', ['t' => $titulo255]) === 1,
        flashTexto($r, 'errors'));

    // ================= 2. VALIDAÇÕES =================
    $marca = 'marca ' . $prefixo;
    $base = ['titulo' => $prefixo . ' Invalida', 'data_tarefa' => $hoje, 'prioridade' => 'media', 'observacao' => $marca];
    $casos = [
        'título vazio' => [['titulo' => '   '], 'Informe a tarefa.'],
        'título com 256 caracteres' => [['titulo' => $prefixo . ' ' . str_repeat('a', 256 - mb_strlen($prefixo) - 1)], 'A tarefa deve ter no máximo 255 caracteres.'],
        'data vazia' => [['data_tarefa' => ''], 'Informe a data.'],
        'data inexistente (2026-02-30)' => [['data_tarefa' => '2026-02-30'], 'Data inválida.'],
        'data em formato errado (31/12/2026)' => [['data_tarefa' => '31/12/2026'], 'Data inválida.'],
        'prioridade inválida' => [['prioridade' => 'urgente'], 'Selecione uma prioridade válida.'],
        'observação com 2001 caracteres' => [['observacao' => $marca . str_repeat('x', 2001 - mb_strlen($marca))], 'A observação deve ter no máximo 2000 caracteres.'],
    ];
    foreach ($casos as $nome => [$alteracao, $mensagem]) {
        $antes = totalTarefas();
        $r = rota('POST', '/agenda/criar', $user, array_merge($base, $alteracao));
        ok("2. validação: {$nome} é recusado sem gravar", str_contains(flashTexto($r, 'errors'), $mensagem) && totalTarefas() === $antes,
            flashTexto($r, 'errors'));
    }
    $r = rota('POST', '/agenda/criar', $user, array_merge($base, ['prioridade' => 'urgente']));
    ok('2b. validação: os dados digitados voltam para o formulário',
        ($r['flash']['agenda_old']['titulo'] ?? null) === $prefixo . ' Invalida'
        && ($r['flash']['agenda_old']['observacao'] ?? null) === $marca);

    // ================= Massa para filtros, situação e resumo =================
    $fOntem = nova('FLT ontem', $dia(-1));
    $fHoje = nova('FLT hoje', $hoje);
    $fAmanha = nova('FLT amanha', $dia(1));
    $fSeis = nova('FLT seis', $dia(6));
    $fSete = nova('FLT sete', $dia(7));
    $fFeita = nova('FLT feita', $hoje);
    AgendaTarefa::concluir($fFeita);
    $grupoFlt = [$fOntem, $fHoje, $fAmanha, $fSeis, $fSete, $fFeita];

    // ================= 5. RESUMO =================
    // Pendentes criadas até aqui: ontem (atrasada), hoje (hoje + próximos), amanhã, +3 (Criar) e +6 (próximos); +7 e +40 ficam fora.
    $resumoDepois = AgendaTarefa::resumo();
    ok('5. resumo: pendentes de hoje (+1)', $resumoDepois['hoje'] - $resumoAntes['hoje'] === 1,
        "antes={$resumoAntes['hoje']} depois={$resumoDepois['hoje']}");
    ok('5b. resumo: atrasadas (+1)', $resumoDepois['atrasadas'] - $resumoAntes['atrasadas'] === 1,
        "antes={$resumoAntes['atrasadas']} depois={$resumoDepois['atrasadas']}");
    ok('5c. resumo: próximos 7 dias = hoje até hoje+6 (+4)', $resumoDepois['proximos'] - $resumoAntes['proximos'] === 4,
        "antes={$resumoAntes['proximos']} depois={$resumoDepois['proximos']}");

    // ================= 3. FILTROS (model) =================
    $esperados = [
        'todas + pendentes' => [['periodo' => 'todas', 'situacao' => 'pendentes'], [$fOntem, $fHoje, $fAmanha, $fSeis, $fSete]],
        'hoje + pendentes' => [['periodo' => 'hoje', 'situacao' => 'pendentes'], [$fHoje]],
        'amanhã + pendentes' => [['periodo' => 'amanha', 'situacao' => 'pendentes'], [$fAmanha]],
        'próximos 7 dias + pendentes' => [['periodo' => '7dias', 'situacao' => 'pendentes'], [$fHoje, $fAmanha, $fSeis]],
        'todas + concluídas' => [['periodo' => 'todas', 'situacao' => 'concluidas'], [$fFeita]],
        'hoje + concluídas' => [['periodo' => 'hoje', 'situacao' => 'concluidas'], [$fFeita]],
        'amanhã + concluídas' => [['periodo' => 'amanha', 'situacao' => 'concluidas'], []],
    ];
    foreach ($esperados as $nome => [$filtros, $ids]) {
        $obtidos = idsListados($filtros, $grupoFlt);
        ok("3. filtro {$nome}", mesmoConjunto($obtidos, $ids), 'obtidos=' . implode(',', $obtidos) . ' esperados=' . implode(',', $ids));
    }

    // ================= 3b. FILTROS e 4. SITUAÇÃO (tela real) =================
    $t = static fn (string $nome): string => $prefixo . ' ' . $nome;
    $html = rota('GET', '/agenda?periodo=hoje', $user)['html'];
    ok('3b. tela: filtro Hoje mostra só a pendente de hoje', str_contains($html, $t('FLT hoje'))
        && !str_contains($html, $t('FLT amanha')) && !str_contains($html, $t('FLT ontem')) && !str_contains($html, $t('FLT feita')));
    $html = rota('GET', '/agenda?situacao=concluidas', $user)['html'];
    ok('3c. tela: filtro Concluídas mostra a concluída e não as pendentes', str_contains($html, $t('FLT feita'))
        && !str_contains($html, $t('FLT hoje')));
    $htmlPadrao = rota('GET', '/agenda?periodo=xyz&situacao=abc', $user)['html'];
    ok('3d. tela: filtros desconhecidos voltam ao padrão (Todas + Pendentes)', str_contains($htmlPadrao, $t('FLT ontem'))
        && str_contains($htmlPadrao, $t('FLT sete')) && !str_contains($htmlPadrao, $t('FLT feita')));

    $lOntem = linhaHtml($htmlPadrao, $t('FLT ontem')) ?? '';
    $lHoje = linhaHtml($htmlPadrao, $t('FLT hoje')) ?? '';
    $lFeita = linhaHtml($html, $t('FLT feita')) ?? '';
    ok('4. situação: pendente com data passada aparece como Atrasada', str_contains($lOntem, 'Atrasada'));
    ok('4b. situação: pendente de hoje aparece como Pendente (não atrasada)', str_contains($lHoje, 'Pendente') && !str_contains($lHoje, 'Atrasada'));
    ok('4c. situação: concluída aparece como Concluída, com ação Reabrir', str_contains($lFeita, 'Concluída') && str_contains($lFeita, 'Reabrir')
        && !str_contains($lFeita, 'Atrasada'));

    // Etapas de projeto (atrasada, hoje, +3) não entram no resumo da aba Tarefas; as avulsas de 5–5c continuam contando.
    $pidResumo = AgendaPlano::criar(['titulo' => $prefixo . ' RES projeto', 'observacao' => '', 'data_inicio' => $dia(-5), 'data_fim' => $dia(10), 'responsavel_id' => '']);
    $etapasResumo = [];
    foreach (['RES etapa ontem' => $dia(-1), 'RES etapa hoje' => $hoje, 'RES etapa mais3' => $dia(3)] as $nomeEtapa => $dataEtapa) {
        $etapasResumo[] = AgendaTarefa::create(['titulo' => $prefixo . ' ' . $nomeEtapa, 'data_tarefa' => $dataEtapa, 'prioridade' => 'media', 'observacao' => null, 'plano_id' => $pidResumo]);
    }
    $resumoTela = AgendaTarefa::resumo();
    $htmlResumo = rota('GET', '/agenda', $user)['html'];
    $htmlProjetoResumo = rota('GET', '/agenda?aba=projetos&plano=' . $pidResumo, $user)['html'];
    $ok5d = $resumoTela === $resumoDepois
        && str_contains($htmlResumo, 'Hoje: <strong>' . $resumoTela['hoje'] . '</strong>')
        && str_contains($htmlResumo, 'Atrasadas: <strong>' . $resumoTela['atrasadas'] . '</strong>')
        && str_contains($htmlResumo, 'Próximos 7 dias: <strong>' . $resumoTela['proximos'] . '</strong>')
        && str_contains($htmlResumo, $t('FLT hoje')) && !str_contains($htmlResumo, $t('RES etapa hoje'))
        && str_contains($htmlProjetoResumo, $t('RES etapa ontem')) && str_contains($htmlProjetoResumo, $t('RES etapa hoje'))
        && str_contains($htmlProjetoResumo, $t('RES etapa mais3'))
        && AgendaPlano::progresso($pidResumo) === 0;
    foreach ($etapasResumo as $idEtapa) {
        AgendaTarefa::delete($idEtapa);
    }
    $ok5d = $ok5d && AgendaPlano::excluir($pidResumo) === 'excluido';
    ok('5d. resumo da aba Tarefas ignora etapas de projetos (hoje, atrasada, próximos 7 dias) e confere com a tela', $ok5d,
        'antes=' . json_encode($resumoDepois) . ' com etapas=' . json_encode($resumoTela));

    // ================= 6. ORDENAÇÃO =================
    $oA = nova('ORD b', $dia(30), 'baixa');
    $oB = nova('ORD z', $dia(30), 'alta');
    $oC = nova('ORD a', $dia(30), 'alta');
    $oD = nova('ORD m', $dia(30), 'media');
    $oE = nova('ORD m', $dia(30), 'media');
    $oF = nova('ORD y', $dia(29), 'baixa');
    $ordemPend = idsListados(['periodo' => 'todas', 'situacao' => 'pendentes'], [$oA, $oB, $oC, $oD, $oE, $oF]);
    $esperadaPend = [$oF, $oC, $oB, $oD, $oE, $oA];
    ok('6. ordenação de pendentes: data, prioridade (alta→baixa), título e id', $ordemPend === $esperadaPend,
        'obtida=' . implode(',', $ordemPend) . ' esperada=' . implode(',', $esperadaPend));

    $cG = nova('ORDC g', $dia(50));
    $cH = nova('ORDC h', $dia(50));
    $cI = nova('ORDC i', $dia(50));
    $fixarConclusao = $pdo->prepare('UPDATE agenda_tarefas SET concluida = 1, concluida_em = :em WHERE id = :id');
    $fixarConclusao->execute(['em' => '2026-01-03 10:00:00', 'id' => $cG]);
    $fixarConclusao->execute(['em' => '2026-01-05 10:00:00', 'id' => $cH]);
    $fixarConclusao->execute(['em' => '2026-01-05 10:00:00', 'id' => $cI]);
    $ordemConc = idsListados(['periodo' => 'todas', 'situacao' => 'concluidas'], [$cG, $cH, $cI]);
    ok('6b. ordenação de concluídas: concluida_em DESC (empate: id DESC)', $ordemConc === [$cI, $cH, $cG],
        'obtida=' . implode(',', $ordemConc) . ' esperada=' . implode(',', [$cI, $cH, $cG]));

    // ================= 7. CONCLUIR =================
    $r = rota('POST', '/agenda/' . $idCriar . '/concluir', $user);
    $c = AgendaTarefa::find($idCriar);
    ok('7. concluir: tarefa pendente passa a concluída com concluida_em preenchido', (int) ($c['concluida'] ?? 0) === 1
        && $c['concluida_em'] !== null && abs(strtotime((string) $c['concluida_em']) - time()) < 120, (string) ($c['concluida_em'] ?? 'null'));
    ok('7b. concluir: mensagem de sucesso', flashTexto($r, 'success') === 'Tarefa concluída.');
    $pdo->prepare('UPDATE agenda_tarefas SET concluida_em = :em WHERE id = :id')->execute(['em' => '2026-01-01 08:00:00', 'id' => $idCriar]);
    rota('POST', '/agenda/' . $idCriar . '/concluir', $user);
    ok('7c. concluir de novo não sobrescreve concluida_em', (AgendaTarefa::find($idCriar)['concluida_em'] ?? '') === '2026-01-01 08:00:00');

    // ================= 8. REABRIR =================
    $r = rota('POST', '/agenda/' . $idCriar . '/reabrir', $user);
    $c = AgendaTarefa::find($idCriar);
    ok('8. reabrir: volta para pendente e limpa concluida_em', $c !== null && (int) $c['concluida'] === 0 && $c['concluida_em'] === null,
        'concluida=' . ($c['concluida'] ?? '?') . ' concluida_em=' . var_export($c['concluida_em'] ?? null, true));
    ok('8b. reabrir: mensagem de sucesso', flashTexto($r, 'success') === 'Tarefa reaberta.');

    // ================= 9. EDITAR =================
    $tituloEditado = $prefixo . ' Criar editada';
    $r = rota('POST', '/agenda/' . $idCriar . '/editar', $user, [
        'titulo' => $tituloEditado, 'data_tarefa' => $dia(5), 'prioridade' => 'baixa', 'observacao' => 'Editada ' . $prefixo,
    ]);
    $c = AgendaTarefa::find($idCriar);
    ok('9. editar: altera título, data, prioridade e observação', ($c['titulo'] ?? '') === $tituloEditado && $c['data_tarefa'] === $dia(5)
        && $c['prioridade'] === 'baixa' && $c['observacao'] === 'Editada ' . $prefixo, flashTexto($r, 'errors'));
    ok('9b. editar pendente: continua pendente', (int) $c['concluida'] === 0 && $c['concluida_em'] === null
        && flashTexto($r, 'success') === 'Tarefa atualizada.');

    AgendaTarefa::concluir($idCriar);
    $pdo->prepare('UPDATE agenda_tarefas SET concluida_em = :em WHERE id = :id')->execute(['em' => '2026-01-02 09:30:00', 'id' => $idCriar]);
    rota('POST', '/agenda/' . $idCriar . '/editar', $user, [
        'titulo' => $tituloEditado . ' 2', 'data_tarefa' => $dia(8), 'prioridade' => 'alta', 'observacao' => '',
    ]);
    $c = AgendaTarefa::find($idCriar);
    ok('9c. editar concluída: altera os dados e mantém concluida e concluida_em', ($c['titulo'] ?? '') === $tituloEditado . ' 2'
        && (int) $c['concluida'] === 1 && $c['concluida_em'] === '2026-01-02 09:30:00' && $c['observacao'] === null);

    $antesEdicao = AgendaTarefa::find($idCriar);
    $r = rota('POST', '/agenda/' . $idCriar . '/editar', $user, ['titulo' => '', 'data_tarefa' => $dia(8), 'prioridade' => 'alta', 'observacao' => '']);
    ok('9d. editar com dados inválidos: recusa e não altera a tarefa', str_contains(flashTexto($r, 'errors'), 'Informe a tarefa.')
        && AgendaTarefa::find($idCriar) == $antesEdicao);

    // ================= 11. CSRF =================
    $tokenRuim = ['_csrf' => 'token-invalido'];
    $antes = totalTarefas();
    $r = rota('POST', '/agenda/criar', $user, $tokenRuim + ['titulo' => $prefixo . ' CSRF', 'data_tarefa' => $hoje, 'prioridade' => 'media']);
    ok('11. CSRF inválido: criar é recusado', csrfRecusado($r) && totalTarefas() === $antes
        && (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' CSRF']) === 0);
    $r = rota('POST', '/agenda/' . $fHoje . '/concluir', $user, $tokenRuim);
    ok('11b. CSRF inválido: concluir é recusado', csrfRecusado($r) && (int) AgendaTarefa::find($fHoje)['concluida'] === 0);
    $r = rota('POST', '/agenda/' . $fFeita . '/reabrir', $user, $tokenRuim);
    ok('11c. CSRF inválido: reabrir é recusado', csrfRecusado($r) && (int) AgendaTarefa::find($fFeita)['concluida'] === 1);
    $antesCsrf = AgendaTarefa::find($fHoje);
    $r = rota('POST', '/agenda/' . $fHoje . '/editar', $user, $tokenRuim + ['titulo' => $prefixo . ' hack', 'data_tarefa' => $hoje, 'prioridade' => 'alta']);
    ok('11d. CSRF inválido: editar é recusado', csrfRecusado($r) && AgendaTarefa::find($fHoje) == $antesCsrf);
    $r = rota('POST', '/agenda/' . $fHoje . '/excluir', $user, $tokenRuim);
    ok('11e. CSRF inválido: excluir é recusado', csrfRecusado($r) && AgendaTarefa::find($fHoje) !== null);

    // ================= 12. SEGURANÇA (rotas atuais) =================
    $r = rota('GET', '/agenda', 0);
    ok('12. sem login: GET /agenda redireciona (302) sem mostrar tarefas', ($r['code'] ?? 0) === 302 && !str_contains($r['html'], $t('FLT hoje')),
        'code=' . ($r['code'] ?? '?'));
    $antes = totalTarefas();
    $r = rota('POST', '/agenda/criar', 0, ['titulo' => $prefixo . ' SemLogin', 'data_tarefa' => $hoje, 'prioridade' => 'media']);
    ok('12b. sem login: criar (com CSRF válido) redireciona e não grava', ($r['code'] ?? 0) === 302 && totalTarefas() === $antes);
    $antesSemLogin = AgendaTarefa::find($fHoje);
    rota('POST', '/agenda/' . $fHoje . '/concluir', 0);
    rota('POST', '/agenda/' . $fHoje . '/excluir', 0);
    ok('12c. sem login: concluir e excluir não alteram a tarefa', AgendaTarefa::find($fHoje) == $antesSemLogin);
    $r = rota('POST', '/agenda/criar', $inativo, ['titulo' => $prefixo . ' Inativo', 'data_tarefa' => $hoje, 'prioridade' => 'media']);
    ok('12d. usuário inativo: sessão é recusada e nada é gravado', ($r['code'] ?? 0) === 302 && totalTarefas() === $antes);

    $payload = $prefixo . " x'); DROP TABLE agenda_tarefas; -- \" OR 1=1";
    $r = rota('POST', '/agenda/criar', $user, ['titulo' => $payload, 'data_tarefa' => $dia(60), 'prioridade' => 'media', 'observacao' => "' OR '1'='1"]);
    ok('12e. SQL: texto com aspas e comandos é gravado literalmente (uma linha, tabela intacta)',
        (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t AND observacao = :o', ['t' => $payload, 'o' => "' OR '1'='1"]) === 1
        && totalTarefas() === $antes + 1, flashTexto($r, 'errors'));
    $r = rota('GET', '/agenda?' . http_build_query(['periodo' => "' OR '1'='1", 'situacao' => "concluidas' OR 1=1 -- "]), $user);
    ok('12f. SQL: filtros com injeção voltam ao padrão, sem erro e sem mostrar concluídas',
        semErroPhp($r) && str_contains($r['html'], $t('FLT ontem')) && !str_contains($r['html'], $t('FLT feita')));
    $antes = totalTarefas();
    $r = rota('POST', '/agenda/abc/concluir', $user);
    ok('12g. id não numérico na rota: 404 e nada muda', ($r['code'] ?? 0) === 404 && totalTarefas() === $antes
        && (int) AgendaTarefa::find($fHoje)['concluida'] === 0, 'code=' . ($r['code'] ?? '?'));
    $outras = $pdo->query('SELECT * FROM agenda_tarefas WHERE id <> ' . (int) $fAmanha . ' ORDER BY id')->fetchAll();
    rota('POST', '/agenda/' . $fAmanha . '/concluir', $user);
    ok('12h. ação por id altera só o registro indicado', (int) AgendaTarefa::find($fAmanha)['concluida'] === 1
        && $pdo->query('SELECT * FROM agenda_tarefas WHERE id <> ' . (int) $fAmanha . ' ORDER BY id')->fetchAll() == $outras);

    // ================= 10. EXCLUSÃO =================
    $r = rota('POST', '/agenda/' . $idCriar . '/excluir', $user);
    ok('10. excluir: a tarefa deixa de existir no banco', AgendaTarefa::find($idCriar) === null
        && (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE id = :id', ['id' => $idCriar]) === 0);
    ok('10b. excluir: mensagem de sucesso', flashTexto($r, 'success') === 'Tarefa excluída.');
    $antes = totalTarefas();
    $r = rota('POST', '/agenda/' . $idCriar . '/concluir', $user);
    ok('10c. ação em tarefa inexistente: avisa e não altera nada', flashTexto($r, 'errors') === 'Tarefa não encontrada.' && totalTarefas() === $antes);

    // ======================================================================
    // PARTE B — PLANOS E SUBTAREFAS (preparada; roda quando a estrutura nova existir)
    // ======================================================================
    $colPlano = colunas('agenda_planos');
    $colTarefa = colunas('agenda_tarefas');
    $faltaPlano = array_values(array_diff(COLUNAS_PLANO, $colPlano));
    $faltaSub = array_values(array_diff(COLUNAS_SUBTAREFA, $colTarefa));
    $estruturaNova = $faltaPlano === [] && $faltaSub === [];
    echo PHP_EOL . ($estruturaNova
        ? 'Estrutura de Planos/Subtarefas encontrada: executando a Parte B.'
        : 'Estrutura de Planos/Subtarefas ausente (' . ($colPlano ? 'agenda_planos sem: ' . implode(', ', $faltaPlano) : 'sem agenda_planos')
            . ($faltaSub ? '; agenda_tarefas sem: ' . implode(', ', $faltaSub) : '') . '): Parte B BLOQUEADA até a migration.') . PHP_EOL;

    $planoNovo = static function (array $d = []) use ($prefixo, $dia): int {
        $d += ['titulo' => $prefixo . ' PLANO', 'observacao' => null, 'data_inicio' => $dia(0), 'data_fim' => $dia(10), 'responsavel_id' => null, 'status' => 'planejado'];
        Database::pdo()->prepare('INSERT INTO agenda_planos (titulo, observacao, data_inicio, data_fim, responsavel_id, status)
            VALUES (:titulo, :observacao, :data_inicio, :data_fim, :responsavel_id, :status)')->execute($d);
        return (int) Database::pdo()->lastInsertId();
    };
    $subNova = static function (?int $planoId, array $d = []) use ($prefixo, $dia): int {
        $d += ['titulo' => $prefixo . ' SUB', 'data_tarefa' => $dia(5), 'prioridade' => 'media', 'data_inicio' => null,
            'responsavel_id' => null, 'concluida' => 0, 'em_andamento' => 0];
        $d['concluida_em'] = (int) $d['concluida'] === 1 ? date('Y-m-d H:i:s') : null;
        $d['plano_id'] = $planoId;
        Database::pdo()->prepare('INSERT INTO agenda_tarefas (titulo, data_tarefa, prioridade, plano_id, data_inicio, responsavel_id, concluida, concluida_em, em_andamento)
            VALUES (:titulo, :data_tarefa, :prioridade, :plano_id, :data_inicio, :responsavel_id, :concluida, :concluida_em, :em_andamento)')->execute($d);
        return (int) Database::pdo()->lastInsertId();
    };
    $plano = static fn (int $id): ?array => linha('SELECT * FROM agenda_planos WHERE id = :id', ['id' => $id]);
    $tarefa = static fn (int $id): ?array => linha('SELECT * FROM agenda_tarefas WHERE id = :id', ['id' => $id]);
    $planosComTitulo = static fn (string $titulo): int => (int) valor('SELECT COUNT(*) FROM agenda_planos WHERE titulo = :t', ['t' => $titulo]);
    $tarefasComTitulo = static fn (string $titulo): int => (int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t', ['t' => $titulo]);
    $criarPlano = static function (string $titulo, array $campos = [], ?int $uid = null) use ($user, $dia): array {
        return rota('POST', ROTA_PLANO_CRIAR, $uid ?? $user, $campos + ['titulo' => $titulo, 'observacao' => '', 'data_inicio' => $dia(0), 'data_fim' => $dia(10), 'responsavel_id' => '']);
    };
    $criarSub = static function (string $titulo, array $campos = [], ?int $uid = null) use ($user, $dia): array {
        return rota('POST', '/agenda/criar', $uid ?? $user, $campos + ['titulo' => $titulo, 'data_tarefa' => $dia(5), 'prioridade' => 'media', 'observacao' => '']);
    };
    $mudarStatus = static fn (int $id, string $status, ?int $uid = null): array => rota('POST', rotaPlano($id, 'status'), $uid ?? $user, ['status' => $status]);
    $selectsResponsavel = static function (string $html): string {
        preg_match_all('#<select[^>]*name="responsavel_id"[^>]*>(.*?)</select>#s', $html, $m);
        return implode("\n", $m[1]);
    };

    /** @var array<int, array{0: string, 1: string, 2: callable(): array{0: bool, 1?: string}}> $preparados */
    $preparados = [];
    $caso = static function (string $grupo, string $nome, callable $fn) use (&$preparados): void {
        $preparados[] = [$grupo, $nome, $fn];
    };

    // ---------- PLANOS ----------
    $caso('Planos', 'P1. criar plano válido: grava título, observação, datas e responsável nulo', static function () use ($criarPlano, $prefixo, $dia): array {
        $tit = $prefixo . ' P1';
        $r = $criarPlano($tit, ['observacao' => 'Obs P1', 'data_inicio' => $dia(1), 'data_fim' => $dia(9)]);
        $p = linha('SELECT * FROM agenda_planos WHERE titulo = :t', ['t' => $tit]);
        return [$p !== null && $p['observacao'] === 'Obs P1' && $p['data_inicio'] === $dia(1) && $p['data_fim'] === $dia(9) && $p['responsavel_id'] === null,
            flashTexto($r, 'errors')];
    });
    $caso('Planos', 'P2. título obrigatório: não grava', static function () use ($criarPlano): array {
        $antes = (int) valor('SELECT COUNT(*) FROM agenda_planos');
        $criarPlano('   ');
        return [(int) valor('SELECT COUNT(*) FROM agenda_planos') === $antes];
    });
    $caso('Planos', 'P3. data inicial obrigatória: não grava', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $criarPlano($prefixo . ' P3', ['data_inicio' => '']);
        return [$planosComTitulo($prefixo . ' P3') === 0];
    });
    $caso('Planos', 'P4. data final obrigatória: não grava', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $criarPlano($prefixo . ' P4', ['data_fim' => '']);
        return [$planosComTitulo($prefixo . ' P4') === 0];
    });
    $caso('Planos', 'P5. status inicial é planejado (status enviado no POST é ignorado)', static function () use ($criarPlano, $prefixo): array {
        $criarPlano($prefixo . ' P5', ['status' => 'concluido']);
        return [(string) valor('SELECT status FROM agenda_planos WHERE titulo = :t', ['t' => $prefixo . ' P5']) === 'planejado'];
    });
    $caso('Planos', 'P6. plano muda de status (em_andamento → pausado → cancelado)', static function () use ($planoNovo, $mudarStatus, $plano): array {
        $id = $planoNovo();
        $seq = [];
        foreach (['em_andamento', 'pausado', 'cancelado'] as $s) {
            $mudarStatus($id, $s);
            $seq[] = $plano($id)['status'] ?? '';
        }
        return [$seq === ['em_andamento', 'pausado', 'cancelado'], implode(',', $seq)];
    });
    $caso('Planos', 'P7. status fora da lista é recusado sem alterar', static function () use ($planoNovo, $mudarStatus, $plano): array {
        $id = $planoNovo();
        $mudarStatus($id, 'arquivado');
        return [($plano($id)['status'] ?? '') === 'planejado'];
    });
    $caso('Planos', 'P8. progresso não é armazenado (agenda_planos sem coluna de progresso)', static function (): array {
        $extra = array_intersect(colunas('agenda_planos'), ['progresso', 'percentual', 'porcentagem', 'percentual_concluido']);
        return [$extra === [], implode(',', $extra)];
    });
    $caso('Planos', 'P9. plano sem subtarefas é tratado como "Sem subtarefas"', static function () use ($planoNovo): array {
        $id = $planoNovo();
        return [AgendaPlano::progresso($id) === null && AgendaPlano::rotuloProgresso($id) === 'Sem subtarefas'];
    });
    $caso('Planos', 'P10. editar plano altera título, observação e datas', static function () use ($planoNovo, $plano, $prefixo, $dia, $user): array {
        $id = $planoNovo();
        rota('POST', rotaPlano($id, 'editar'), $user, ['titulo' => $prefixo . ' P10 editado', 'observacao' => 'nova', 'data_inicio' => $dia(2), 'data_fim' => $dia(4), 'responsavel_id' => '']);
        $p = $plano($id);
        return [($p['titulo'] ?? '') === $prefixo . ' P10 editado' && $p['observacao'] === 'nova' && $p['data_inicio'] === $dia(2) && $p['data_fim'] === $dia(4)];
    });

    // ---------- SUBTAREFAS ----------
    $caso('Subtarefas', 'T1. subtarefa criada com plano existente fica vinculada ao plano', static function () use ($planoNovo, $criarSub, $prefixo): array {
        $pid = $planoNovo();
        $r = $criarSub($prefixo . ' T1', ['plano_id' => (string) $pid]);
        return [(int) valor('SELECT plano_id FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' T1']) === $pid, flashTexto($r, 'errors')];
    });
    $caso('Subtarefas', 'T2. plano inexistente: subtarefa recusada sem gravar', static function () use ($criarSub, $prefixo, $tarefasComTitulo): array {
        $criarSub($prefixo . ' T2', ['plano_id' => '99999999']);
        return [$tarefasComTitulo($prefixo . ' T2') === 0];
    });
    $caso('Subtarefas', 'T3. tarefa simples (sem plano) continua com plano_id NULL', static function () use ($criarSub, $prefixo): array {
        $criarSub($prefixo . ' T3');
        $x = linha('SELECT plano_id FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' T3']);
        return [$x !== null && $x['plano_id'] === null];
    });
    $caso('Subtarefas', 'T4. data_inicio vazia é aceita e gravada como NULL', static function () use ($planoNovo, $criarSub, $prefixo): array {
        $pid = $planoNovo();
        $criarSub($prefixo . ' T4', ['plano_id' => (string) $pid, 'data_inicio' => '']);
        $x = linha('SELECT data_inicio FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' T4']);
        return [$x !== null && $x['data_inicio'] === null];
    });
    $caso('Subtarefas', 'T5. data_tarefa continua sendo a data final da subtarefa', static function () use ($planoNovo, $criarSub, $prefixo, $dia): array {
        $pid = $planoNovo();
        $criarSub($prefixo . ' T5', ['plano_id' => (string) $pid, 'data_inicio' => $dia(2), 'data_tarefa' => $dia(6)]);
        $x = linha('SELECT data_inicio, data_tarefa FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' T5']);
        return [$x !== null && $x['data_inicio'] === $dia(2) && $x['data_tarefa'] === $dia(6)];
    });

    // ---------- SITUAÇÃO DAS SUBTAREFAS ----------
    $caso('Status subtarefas', 'ST1. concluida=0 e em_andamento=0 → pendente', static function () use ($planoNovo, $subNova, $tarefa): array {
        $id = $subNova($planoNovo());
        return [AgendaTarefa::situacao($tarefa($id)) === 'pendente'];
    });
    $caso('Status subtarefas', 'ST2. concluida=0 e em_andamento=1 → em andamento', static function () use ($planoNovo, $subNova, $tarefa): array {
        $id = $subNova($planoNovo(), ['em_andamento' => 1]);
        return [AgendaTarefa::situacao($tarefa($id)) === 'em_andamento'];
    });
    $caso('Status subtarefas', 'ST3. concluida=1 → concluída (com em_andamento 0 ou 1)', static function () use ($planoNovo, $subNova, $tarefa): array {
        $pid = $planoNovo();
        $a = $subNova($pid, ['concluida' => 1, 'em_andamento' => 0]);
        $b = $subNova($pid, ['concluida' => 1, 'em_andamento' => 1]);
        return [AgendaTarefa::situacao($tarefa($a)) === 'concluida' && AgendaTarefa::situacao($tarefa($b)) === 'concluida'];
    });
    $caso('Status subtarefas', 'ST4. marcar em andamento pela rota: em_andamento=1 e continua não concluída', static function () use ($planoNovo, $subNova, $tarefa, $user): array {
        $id = $subNova($planoNovo());
        rota('POST', rotaAndamento($id), $user);
        $x = $tarefa($id);
        return [(int) ($x['em_andamento'] ?? 0) === 1 && (int) $x['concluida'] === 0];
    });
    $caso('Status subtarefas', 'ST5. concluir subtarefa em andamento: passa a concluída', static function () use ($planoNovo, $subNova, $tarefa, $user): array {
        $id = $subNova($planoNovo(), ['em_andamento' => 1]);
        rota('POST', '/agenda/' . $id . '/concluir', $user);
        return [AgendaTarefa::situacao($tarefa($id)) === 'concluida'];
    });
    $caso('Status subtarefas', 'ST6. sem campo "status" adicional em agenda_tarefas', static function (): array {
        return [!in_array('status', colunas('agenda_tarefas'), true)];
    });

    // ---------- PROGRESSO ----------
    $comSubs = static function (array $estados) use ($planoNovo, $subNova): int {
        $pid = $planoNovo();
        foreach ($estados as $e) {
            $subNova($pid, ['concluida' => $e === 'c' ? 1 : 0, 'em_andamento' => $e === 'a' ? 1 : 0]);
        }
        return $pid;
    };
    $caso('Progresso', 'PG1. 0 subtarefas → "Sem subtarefas"', static function () use ($comSubs): array {
        $pid = $comSubs([]);
        return [AgendaPlano::progresso($pid) === null && AgendaPlano::rotuloProgresso($pid) === 'Sem subtarefas'];
    });
    $caso('Progresso', 'PG2. 1 subtarefa pendente → 0%', static function () use ($comSubs): array {
        $pid = $comSubs(['p']);
        return [AgendaPlano::progresso($pid) === 0 && AgendaPlano::rotuloProgresso($pid) === '0%'];
    });
    $caso('Progresso', 'PG3. 1 subtarefa concluída → 100%', static function () use ($comSubs): array {
        return [AgendaPlano::progresso($comSubs(['c'])) === 100];
    });
    $caso('Progresso', 'PG4. 2 subtarefas, uma concluída → 50%', static function () use ($comSubs): array {
        $pid = $comSubs(['c', 'p']);
        return [AgendaPlano::progresso($pid) === 50 && AgendaPlano::rotuloProgresso($pid) === '50%'];
    });
    $caso('Progresso', 'PG5. várias (1 concluída, 1 em andamento, 2 pendentes) → 25% (em andamento não conta)', static function () use ($comSubs): array {
        return [AgendaPlano::progresso($comSubs(['c', 'a', 'p', 'p'])) === 25];
    });
    $caso('Progresso', 'PG6. todas concluídas (3) → 100%', static function () use ($comSubs): array {
        $pid = $comSubs(['c', 'c', 'c']);
        return [AgendaPlano::progresso($pid) === 100 && AgendaPlano::rotuloProgresso($pid) === '100%'];
    });
    $caso('Progresso', 'PG7. considera só subtarefas do próprio plano (outro plano e tarefa simples não contam)', static function () use ($comSubs, $subNova): array {
        $pid = $comSubs(['p', 'p']);
        $comSubs(['c', 'c', 'c']);
        $subNova(null, ['concluida' => 1]);
        return [AgendaPlano::progresso($pid) === 0];
    });
    $caso('Progresso', 'PG8. progresso é recalculado ao concluir e reabrir (não fica gravado)', static function () use ($planoNovo, $subNova, $user): array {
        $pid = $planoNovo();
        $a = $subNova($pid);
        $subNova($pid);
        rota('POST', '/agenda/' . $a . '/concluir', $user);
        $depoisConcluir = AgendaPlano::progresso($pid);
        rota('POST', '/agenda/' . $a . '/reabrir', $user);
        return [$depoisConcluir === 50 && AgendaPlano::progresso($pid) === 0, "após concluir={$depoisConcluir}"];
    });

    // ---------- CONCLUSÃO DO PLANO ----------
    $caso('Conclusão do plano', 'CO1. plano sem subtarefas pode ser concluído', static function () use ($planoNovo, $mudarStatus, $plano): array {
        $id = $planoNovo();
        $mudarStatus($id, 'concluido');
        return [($plano($id)['status'] ?? '') === 'concluido'];
    });
    $caso('Conclusão do plano', 'CO2. plano com todas as subtarefas concluídas pode ser concluído', static function () use ($comSubs, $mudarStatus, $plano): array {
        $id = $comSubs(['c', 'c']);
        $mudarStatus($id, 'concluido');
        return [($plano($id)['status'] ?? '') === 'concluido'];
    });
    $caso('Conclusão do plano', 'CO3. subtarefa pendente bloqueia a conclusão', static function () use ($comSubs, $mudarStatus, $plano): array {
        $id = $comSubs(['c', 'p']);
        $mudarStatus($id, 'concluido');
        return [($plano($id)['status'] ?? '') === 'planejado'];
    });
    $caso('Conclusão do plano', 'CO4. subtarefa em andamento bloqueia a conclusão', static function () use ($comSubs, $mudarStatus, $plano): array {
        $id = $comSubs(['c', 'a']);
        $mudarStatus($id, 'concluido');
        return [($plano($id)['status'] ?? '') === 'planejado'];
    });

    // ---------- EXCLUSÃO DO PLANO ----------
    $caso('Exclusão do plano', 'EX1. plano vazio pode ser removido', static function () use ($planoNovo, $plano, $admin): array {
        $id = $planoNovo();
        rota('POST', rotaPlano($id, 'excluir'), $admin);
        return [$plano($id) === null];
    });
    $caso('Exclusão do plano', 'EX2. plano com subtarefa não é removido (subtarefa intacta)', static function () use ($planoNovo, $subNova, $plano, $tarefa, $admin): array {
        $id = $planoNovo();
        $s = $subNova($id);
        rota('POST', rotaPlano($id, 'excluir'), $admin);
        return [$plano($id) !== null && (int) ($tarefa($s)['plano_id'] ?? 0) === $id];
    });

    // ---------- RESPONSÁVEIS ----------
    $caso('Responsáveis', 'R1. plano com responsável válido (ativo) é gravado', static function () use ($criarPlano, $prefixo, $user): array {
        $criarPlano($prefixo . ' R1', ['responsavel_id' => (string) $user]);
        return [(int) valor('SELECT responsavel_id FROM agenda_planos WHERE titulo = :t', ['t' => $prefixo . ' R1']) === $user];
    });
    $caso('Responsáveis', 'R2. plano com responsável inexistente é recusado', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $criarPlano($prefixo . ' R2', ['responsavel_id' => '99999999']);
        return [$planosComTitulo($prefixo . ' R2') === 0];
    });
    $caso('Responsáveis', 'R3. plano com responsável nulo é aceito', static function () use ($criarPlano, $prefixo): array {
        $criarPlano($prefixo . ' R3', ['responsavel_id' => '']);
        $x = linha('SELECT responsavel_id FROM agenda_planos WHERE titulo = :t', ['t' => $prefixo . ' R3']);
        return [$x !== null && $x['responsavel_id'] === null];
    });
    $caso('Responsáveis', 'R4. plano novo com responsável inativo é recusado', static function () use ($criarPlano, $prefixo, $planosComTitulo, $inativo): array {
        $criarPlano($prefixo . ' R4', ['responsavel_id' => (string) $inativo]);
        return [$planosComTitulo($prefixo . ' R4') === 0];
    });
    $caso('Responsáveis', 'R5. subtarefa com responsável válido é gravada', static function () use ($planoNovo, $criarSub, $prefixo, $user): array {
        $criarSub($prefixo . ' R5', ['plano_id' => (string) $planoNovo(), 'responsavel_id' => (string) $user]);
        return [(int) valor('SELECT responsavel_id FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' R5']) === $user];
    });
    $caso('Responsáveis', 'R6. subtarefa com responsável inexistente é recusada', static function () use ($planoNovo, $criarSub, $prefixo, $tarefasComTitulo): array {
        $criarSub($prefixo . ' R6', ['plano_id' => (string) $planoNovo(), 'responsavel_id' => '99999999']);
        return [$tarefasComTitulo($prefixo . ' R6') === 0];
    });
    $caso('Responsáveis', 'R7. subtarefa com responsável nulo é aceita', static function () use ($planoNovo, $criarSub, $prefixo): array {
        $criarSub($prefixo . ' R7', ['plano_id' => (string) $planoNovo(), 'responsavel_id' => '']);
        $x = linha('SELECT responsavel_id FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' R7']);
        return [$x !== null && $x['responsavel_id'] === null];
    });
    $caso('Responsáveis', 'R8. subtarefa nova com responsável inativo é recusada', static function () use ($planoNovo, $criarSub, $prefixo, $tarefasComTitulo, $inativo): array {
        $criarSub($prefixo . ' R8', ['plano_id' => (string) $planoNovo(), 'responsavel_id' => (string) $inativo]);
        return [$tarefasComTitulo($prefixo . ' R8') === 0];
    });
    $caso('Responsáveis', 'R9. tela: seletor de responsável mostra usuários ativos e não mostra o inativo', static function () use ($selectsResponsavel, $user, $admin, $inativo): array {
        $opcoes = $selectsResponsavel(rota('GET', '/agenda?nova=1', $user)['html']);
        return [$opcoes !== '' && str_contains($opcoes, 'value="' . $user . '"') && str_contains($opcoes, 'value="' . $admin . '"')
            && !str_contains($opcoes, 'value="' . $inativo . '"')];
    });
    $caso('Responsáveis', 'R10. tela: responsável inativo já atribuído continua visível com "(inativo)"', static function () use ($planoNovo, $subNova, $prefixo, $inativo, $user, $dia): array {
        $subNova($planoNovo(), ['titulo' => $prefixo . ' R10', 'responsavel_id' => $inativo, 'data_tarefa' => $dia(3)]);
        $html = rota('GET', '/agenda?aba=projetos&periodo=todas', $user)['html'];
        return [str_contains(linhaHtml($html, $prefixo . ' R10') ?? '', 'TST AGENDA inativo (inativo)')];
    });

    // ---------- DATAS ----------
    $caso('Datas', 'D1. plano: início = fim é permitido', static function () use ($criarPlano, $prefixo, $planosComTitulo, $dia): array {
        $criarPlano($prefixo . ' D1', ['data_inicio' => $dia(3), 'data_fim' => $dia(3)]);
        return [$planosComTitulo($prefixo . ' D1') === 1];
    });
    $caso('Datas', 'D2. plano: início < fim é permitido', static function () use ($criarPlano, $prefixo, $planosComTitulo, $dia): array {
        $criarPlano($prefixo . ' D2', ['data_inicio' => $dia(3), 'data_fim' => $dia(4)]);
        return [$planosComTitulo($prefixo . ' D2') === 1];
    });
    $caso('Datas', 'D3. plano: início > fim é proibido', static function () use ($criarPlano, $prefixo, $planosComTitulo, $dia): array {
        $criarPlano($prefixo . ' D3', ['data_inicio' => $dia(5), 'data_fim' => $dia(4)]);
        return [$planosComTitulo($prefixo . ' D3') === 0];
    });
    $caso('Datas', 'D4. subtarefa: início = fim é permitido', static function () use ($criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $criarSub($prefixo . ' D4', ['data_inicio' => $dia(3), 'data_tarefa' => $dia(3)]);
        return [$tarefasComTitulo($prefixo . ' D4') === 1];
    });
    $caso('Datas', 'D5. subtarefa: início < fim é permitido', static function () use ($criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $criarSub($prefixo . ' D5', ['data_inicio' => $dia(3), 'data_tarefa' => $dia(4)]);
        return [$tarefasComTitulo($prefixo . ' D5') === 1];
    });
    $caso('Datas', 'D6. subtarefa: início > fim é proibido', static function () use ($criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $criarSub($prefixo . ' D6', ['data_inicio' => $dia(5), 'data_tarefa' => $dia(4)]);
        return [$tarefasComTitulo($prefixo . ' D6') === 0];
    });
    $caso('Datas', 'D7. subtarefa começando antes do plano é proibida', static function () use ($planoNovo, $criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $pid = $planoNovo(['data_inicio' => $dia(10), 'data_fim' => $dia(20)]);
        $criarSub($prefixo . ' D7', ['plano_id' => (string) $pid, 'data_inicio' => $dia(9), 'data_tarefa' => $dia(15)]);
        return [$tarefasComTitulo($prefixo . ' D7') === 0];
    });
    $caso('Datas', 'D8. subtarefa terminando depois do plano é proibida', static function () use ($planoNovo, $criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $pid = $planoNovo(['data_inicio' => $dia(10), 'data_fim' => $dia(20)]);
        $criarSub($prefixo . ' D8', ['plano_id' => (string) $pid, 'data_inicio' => $dia(12), 'data_tarefa' => $dia(21)]);
        return [$tarefasComTitulo($prefixo . ' D8') === 0];
    });
    $caso('Datas', 'D9. subtarefa dentro do período do plano é permitida', static function () use ($planoNovo, $criarSub, $prefixo, $tarefasComTitulo, $dia): array {
        $pid = $planoNovo(['data_inicio' => $dia(10), 'data_fim' => $dia(20)]);
        $criarSub($prefixo . ' D9', ['plano_id' => (string) $pid, 'data_inicio' => $dia(10), 'data_tarefa' => $dia(20)]);
        return [$tarefasComTitulo($prefixo . ' D9') === 1];
    });

    // ---------- COMPATIBILIDADE ----------
    $caso('Compatibilidade', 'C1. tarefa antiga (só colunas atuais) fica sem plano, sem responsável, sem início e pendente', static function () use ($prefixo, $dia, $tarefa): array {
        Database::pdo()->prepare('INSERT INTO agenda_tarefas (titulo, data_tarefa, prioridade) VALUES (:t, :d, :p)')
            ->execute(['t' => $prefixo . ' C1', 'd' => $dia(2), 'p' => 'media']);
        $x = $tarefa((int) Database::pdo()->lastInsertId());
        return [$x !== null && $x['plano_id'] === null && $x['responsavel_id'] === null && $x['data_inicio'] === null && (int) $x['em_andamento'] === 0];
    });
    $caso('Compatibilidade', 'C2. criar pela rota só com os campos atuais continua funcionando', static function () use ($criarSub, $prefixo): array {
        $criarSub($prefixo . ' C2');
        $x = linha('SELECT * FROM agenda_tarefas WHERE titulo = :t', ['t' => $prefixo . ' C2']);
        return [$x !== null && $x['plano_id'] === null && $x['responsavel_id'] === null && $x['data_inicio'] === null];
    });
    $caso('Compatibilidade', 'C3. editar tarefa antiga só com os campos atuais funciona e mantém os campos novos nulos', static function () use ($subNova, $tarefa, $prefixo, $dia, $user): array {
        $id = $subNova(null);
        rota('POST', '/agenda/' . $id . '/editar', $user, ['titulo' => $prefixo . ' C3 editada', 'data_tarefa' => $dia(4), 'prioridade' => 'alta', 'observacao' => '']);
        $x = $tarefa($id);
        return [($x['titulo'] ?? '') === $prefixo . ' C3 editada' && $x['plano_id'] === null && $x['responsavel_id'] === null && $x['data_inicio'] === null];
    });
    $caso('Compatibilidade', 'C4. concluir e reabrir tarefa antiga continuam funcionando', static function () use ($subNova, $tarefa, $user): array {
        $id = $subNova(null);
        rota('POST', '/agenda/' . $id . '/concluir', $user);
        $concluida = (int) ($tarefa($id)['concluida'] ?? 0) === 1;
        rota('POST', '/agenda/' . $id . '/reabrir', $user);
        return [$concluida && (int) ($tarefa($id)['concluida'] ?? 1) === 0];
    });
    $caso('Compatibilidade', 'C5. filtros atuais continuam listando tarefa simples', static function () use ($subNova, $hoje): array {
        $id = $subNova(null, ['data_tarefa' => $hoje]);
        return [in_array($id, array_map('intval', array_column(AgendaTarefa::listar(['periodo' => 'hoje', 'situacao' => 'pendentes']), 'id')), true)];
    });

    // ---------- SEGURANÇA (rotas novas) ----------
    $caso('Segurança', 'SG1. CSRF inválido: criar plano é recusado', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $r = $criarPlano($prefixo . ' SG1', ['_csrf' => 'token-invalido']);
        return [csrfRecusado($r) && $planosComTitulo($prefixo . ' SG1') === 0];
    });
    $caso('Segurança', 'SG2. CSRF inválido: mudar status é recusado', static function () use ($planoNovo, $plano, $user): array {
        $id = $planoNovo();
        $r = rota('POST', rotaPlano($id, 'status'), $user, ['status' => 'cancelado', '_csrf' => 'token-invalido']);
        return [csrfRecusado($r) && ($plano($id)['status'] ?? '') === 'planejado'];
    });
    $caso('Segurança', 'SG3. CSRF inválido: excluir plano é recusado', static function () use ($planoNovo, $plano, $admin): array {
        $id = $planoNovo();
        $r = rota('POST', rotaPlano($id, 'excluir'), $admin, ['_csrf' => 'token-invalido']);
        return [csrfRecusado($r) && $plano($id) !== null];
    });
    $caso('Segurança', 'SG4. sem login: criar plano não grava', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $criarPlano($prefixo . ' SG4', [], 0);
        return [$planosComTitulo($prefixo . ' SG4') === 0];
    });
    $caso('Segurança', 'SG5. usuário comum não exclui plano (operação administrativa)', static function () use ($planoNovo, $plano, $user): array {
        $id = $planoNovo();
        rota('POST', rotaPlano($id, 'excluir'), $user);
        return [$plano($id) !== null];
    });
    $caso('Segurança', 'SG6. id de plano inexistente: status e exclusão não alteram nada', static function () use ($user, $admin): array {
        $antes = Database::pdo()->query('SELECT * FROM agenda_planos ORDER BY id')->fetchAll();
        rota('POST', rotaPlano(99999999, 'status'), $user, ['status' => 'cancelado']);
        rota('POST', rotaPlano(99999999, 'excluir'), $admin);
        return [Database::pdo()->query('SELECT * FROM agenda_planos ORDER BY id')->fetchAll() == $antes];
    });
    $caso('Segurança', 'SG7. plano_id com injeção não vincula a tarefa a plano algum', static function () use ($planoNovo, $criarSub, $prefixo): array {
        $planoNovo();
        $criarSub($prefixo . ' SG7', ['plano_id' => '1 OR 1=1']);
        return [(int) valor('SELECT COUNT(*) FROM agenda_tarefas WHERE titulo = :t AND plano_id IS NOT NULL', ['t' => $prefixo . ' SG7']) === 0];
    });
    $caso('Segurança', 'SG8. título de plano com aspas e comandos é gravado literalmente', static function () use ($criarPlano, $prefixo, $planosComTitulo): array {
        $tit = $prefixo . " SG8 x'); DROP TABLE agenda_planos; --";
        $criarPlano($tit);
        return [$planosComTitulo($tit) === 1 && colunas('agenda_planos') !== []];
    });

    foreach ($preparados as [$grupo, $nome, $fn]) {
        if (!$estruturaNova) {
            bloqueado($grupo, $nome);
            continue;
        }
        try {
            $res = $fn();
            ok("{$grupo}: {$nome}", (bool) $res[0], (string) ($res[1] ?? ''));
        } catch (Throwable $e) {
            ok("{$grupo}: {$nome}", false, get_class($e) . ': ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdo = null;
    $limpezaErros = [];
    try {
        usarBanco($bancoLocal);
        if (str_starts_with($bancoTeste, 'pm_tmp_agenda_') && $bancoTeste !== $bancoLocal) {
            $pdoLocal->exec("DROP DATABASE IF EXISTS `{$bancoTeste}`");
        }
    } catch (Throwable $e) {
        $limpezaErros[] = 'banco temporário: ' . $e->getMessage();
    }
    if (is_dir($tmpDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmpDir);
    }
    ok('limpeza: sem erros', $limpezaErros === [], implode(' | ', $limpezaErros));
}

$restou = (int) $pdoLocal->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdoLocal->quote($bancoTeste))->fetchColumn();
ok('limpeza: banco temporário removido', $restou === 0);
ok('limpeza: pasta temporária removida', !is_dir($tmpDir));
$sigLocalDepois = assinaturaBanco($pdoLocal);
echo "Assinatura do banco local depois: {$sigLocalDepois}" . PHP_EOL;
ok('banco local idêntico ao inicial (estrutura e dados)', $sigLocalDepois === $sigLocalAntes);

$totalBloq = array_sum($bloqueados);
if ($totalBloq > 0) {
    echo PHP_EOL . 'Preparados e bloqueados até a migration de Planos/Subtarefas:' . PHP_EOL;
    foreach ($bloqueados as $grupo => $n) {
        echo "  - {$grupo}: {$n}" . PHP_EOL;
    }
}
echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s), {$totalBloq} bloqueado(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

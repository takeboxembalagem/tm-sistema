<?php
/**
 * Teste local — instalação limpa a partir de database/schema.sql + database/seed.sql.
 *
 * Cria um banco temporário pm_tmp_schema_* no MESMO servidor local, importa schema.sql e seed.sql nele,
 * confere as estruturas novas (Agenda/planos) e compara
 * tabelas, colunas, índices, chaves estrangeiras e view com o banco local principal via information_schema.
 * O banco local principal só é lido (assinatura de estrutura e dados antes/depois).
 * O banco temporário só é removido se o nome for exatamente o criado por este teste.
 * Recusa rodar se o host do config não for local. NÃO executar em produção.
 * Uso: php scripts/test_schema_instalacao.php [caminho/de/outro/schema.sql]
 *      (o argumento opcional serve só para controle, ex.: provar que um schema antigo é reprovado)
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

const PREFIXO_BANCO = 'pm_tmp_schema_';

$pass = 0;
$fail = 0;
$avisos = [];

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
    $avisos[] = $texto;
    echo "[AVISO] {$texto}" . PHP_EOL;
}

function conectar(array $db, ?string $banco): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['host'], $db['port'] ?? '3306')
        . ($banco !== null ? ';dbname=' . $banco : '');
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
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

/** Divide um arquivo SQL em comandos: ignora linhas de comentário "--" e separa por ";" no fim da linha. */
function comandosSql(string $arquivo): array
{
    $linhas = [];
    $conteudo = (string) file_get_contents($arquivo);
    if (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
        $conteudo = substr($conteudo, 3);
    }
    foreach (preg_split('/\R/', $conteudo) as $linha) {
        if (str_starts_with(ltrim($linha), '--')) {
            continue;
        }
        $linhas[] = $linha;
    }
    $partes = preg_split('/;\s*(?:\R|$)/', implode("\n", $linhas));
    return array_values(array_filter(array_map('trim', $partes), static fn (string $c): bool => $c !== ''));
}

/** Estrutura de um banco via information_schema: tabelas, colunas, índices, chaves estrangeiras e views. */
function estrutura(PDO $pdo, string $banco): array
{
    $q = static function (string $sql) use ($pdo, $banco): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['b' => $banco]);
        return $stmt->fetchAll();
    };
    $e = ['tabelas' => [], 'colunas' => [], 'ordem' => [], 'comentarios' => [], 'indices' => [], 'fks' => [], 'views' => []];
    foreach ($q('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = :b') as $r) {
        $e['tabelas'][$r['TABLE_NAME']] = $r['TABLE_TYPE'] . '|' . ($r['ENGINE'] ?? '') . '|' . ($r['TABLE_COLLATION'] ?? '');
    }
    foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, COLUMN_COMMENT
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :b') as $r) {
        $k = $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'];
        $e['colunas'][$k] = implode('|', [$r['COLUMN_TYPE'], $r['IS_NULLABLE'], $r['COLUMN_DEFAULT'] ?? 'SEM_DEFAULT', $r['EXTRA'], $r['COLLATION_NAME'] ?? '']);
        $e['ordem'][$k] = (int) $r['ORDINAL_POSITION'];
        $e['comentarios'][$k] = (string) $r['COLUMN_COMMENT'];
    }
    foreach ($q("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :b GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE") as $r) {
        $e['indices'][$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']] = ($r['NON_UNIQUE'] ? '' : 'UNICO:') . $r['cols'];
    }
    foreach ($q("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME,
                        GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols,
                        k.REFERENCED_TABLE_NAME, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS refs,
                        r.UPDATE_RULE, r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = :b AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE") as $r) {
        $e['fks'][$r['TABLE_NAME'] . '.' . $r['CONSTRAINT_NAME']] = "{$r['cols']}->{$r['REFERENCED_TABLE_NAME']}({$r['refs']}) UPD={$r['UPDATE_RULE']} DEL={$r['DELETE_RULE']}";
    }
    foreach ($q('SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = :b') as $r) {
        $e['views'][$r['TABLE_NAME']] = str_replace('`' . $banco . '`.', '', (string) $r['VIEW_DEFINITION']);
    }
    return $e;
}

/** @return list<string> diferenças "chave: principal=… temporário=…" */
function diferencas(array $principal, array $temporario): array
{
    $difs = [];
    foreach (array_unique(array_merge(array_keys($principal), array_keys($temporario))) as $k) {
        $a = $principal[$k] ?? 'AUSENTE';
        $b = $temporario[$k] ?? 'AUSENTE';
        if ($a !== $b) {
            $difs[] = "{$k}: principal={$a} | instalação limpa={$b}";
        }
    }
    sort($difs);
    return $difs;
}

function enumValores(PDO $pdo, string $banco, string $tabela, string $coluna): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :b AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $stmt->execute(['b' => $banco, 't' => $tabela, 'c' => $coluna]);
    $tipo = (string) $stmt->fetchColumn();
    if (!preg_match('/^enum\((.*)\)$/i', $tipo, $m)) {
        return [];
    }
    return str_getcsv($m[1], ',', "'", '');
}

/** Executa um comando que deve ser recusado pelo banco (restrição de chave estrangeira ou ENUM). */
function recusado(PDO $pdo, string $sql): bool
{
    try {
        $pdo->exec($sql);
        return false;
    } catch (PDOException) {
        return true;
    }
}

$db = config('db');
$bancoLocal = (string) ($db['name'] ?? '');
$hostLocal = in_array((string) ($db['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true);
if (!$hostLocal || $bancoLocal === '') {
    ok('ambiente: config aponta para servidor local', false, 'host não é local — teste abortado sem criar nada');
    echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s)." . PHP_EOL;
    exit(1);
}

$pdoServidor = conectar($db, null);
$pdoPrincipal = conectar($db, $bancoLocal);
$pdoPrincipal->exec('SET SESSION group_concat_max_len = 16777216');
$pdoServidor->exec('SET SESSION group_concat_max_len = 16777216');
ok('ambiente: servidor local e banco principal acessíveis', (string) $pdoPrincipal->query('SELECT DATABASE()')->fetchColumn() === $bancoLocal,
    $bancoLocal . ' / ' . $pdoPrincipal->query('SELECT VERSION()')->fetchColumn());

$sigAntes = assinaturaBanco($pdoPrincipal);
echo "Assinatura do banco principal antes: {$sigAntes}" . PHP_EOL;

$sufixo = date('His') . random_int(100, 999);
$bancoTeste = PREFIXO_BANCO . $sufixo;
$criadoPorEsteTeste = false;
$pdoTeste = null;

try {
    if ($bancoTeste === $bancoLocal || !str_starts_with($bancoTeste, PREFIXO_BANCO) || !preg_match('/^pm_tmp_schema_\d{9}$/', $bancoTeste)) {
        throw new RuntimeException('nome do banco temporário inválido — abortado');
    }
    $existe = $pdoServidor->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n');
    $existe->execute(['n' => $bancoTeste]);
    if ((int) $existe->fetchColumn() !== 0) {
        throw new RuntimeException("banco {$bancoTeste} já existe — abortado sem tocar nele");
    }
    $pdoServidor->exec("CREATE DATABASE `{$bancoTeste}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $criadoPorEsteTeste = true;
    $pdoTeste = conectar($db, $bancoTeste);
    if ((string) $pdoTeste->query('SELECT DATABASE()')->fetchColumn() !== $bancoTeste) {
        throw new RuntimeException('conexão de teste não está no banco temporário — abortado');
    }
    $pdoTeste->exec('SET SESSION group_concat_max_len = 16777216');
    ok('setup: banco temporário criado e vazio', (int) $pdoTeste->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() === 0, $bancoTeste);

    // ================= 1. Importar schema.sql e seed.sql =================
    $raiz = dirname(__DIR__);
    $arquivoSchema = $argv[1] ?? $raiz . '/database/schema.sql';
    if (!is_file($arquivoSchema)) {
        throw new RuntimeException('schema não encontrado: ' . $arquivoSchema);
    }
    echo 'Schema: ' . $arquivoSchema . PHP_EOL;
    $schema = comandosSql($arquivoSchema);
    $proibidos = array_filter($schema, static fn (string $c): bool => preg_match('/^\s*(DROP|TRUNCATE|DELETE|UPDATE|ALTER|USE|CREATE\s+DATABASE|INSERT)\b/i', $c) === 1);
    ok('schema.sql: sem DROP/TRUNCATE/DELETE/UPDATE/ALTER/USE/CREATE DATABASE/INSERT', $proibidos === [],
        $proibidos ? implode(' | ', array_map(static fn ($c) => substr($c, 0, 60), $proibidos)) : count($schema) . ' comandos');
    $erroSchema = '';
    foreach ($schema as $i => $cmd) {
        try {
            $pdoTeste->exec($cmd);
        } catch (PDOException $e) {
            $erroSchema = 'comando ' . ($i + 1) . ': ' . $e->getMessage();
            break;
        }
    }
    ok('instalação: schema.sql importado sem erro', $erroSchema === '', $erroSchema);

    $seed = comandosSql($raiz . '/database/seed.sql');
    $erroSeed = '';
    foreach ($seed as $i => $cmd) {
        try {
            $pdoTeste->exec($cmd);
        } catch (PDOException $e) {
            $erroSeed = 'comando ' . ($i + 1) . ': ' . $e->getMessage();
            break;
        }
    }
    ok('instalação: seed.sql importado sem erro', $erroSeed === '', count($seed) . ' comandos' . ($erroSeed !== '' ? ' — ' . $erroSeed : ''));
    ok('instalação: FOREIGN_KEY_CHECKS religado ao final do schema', (int) $pdoTeste->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() === 1);

    // ================= Comparação com o banco principal (antes de qualquer gravação no banco temporário) =================
    $principal = estrutura($pdoPrincipal, $bancoLocal);
    $limpa = estrutura($pdoTeste, $bancoTeste);
    foreach (['tabelas' => 'tabelas/views (tipo, engine, collation)', 'colunas' => 'colunas (tipo, nulo, default, extra, collation)',
              'indices' => 'índices', 'fks' => 'chaves estrangeiras'] as $parte => $rotulo) {
        $difs = diferencas($principal[$parte], $limpa[$parte]);
        ok("comparação: {$rotulo} idênticos ao banco principal", $difs === [], $difs === [] ? count($principal[$parte]) . ' itens' : count($difs) . ' diferença(s)');
        foreach ($difs as $d) {
            echo "    - {$d}" . PHP_EOL;
        }
    }
    foreach (diferencas($principal['ordem'], $limpa['ordem']) as $d) {
        aviso('ordem de coluna diferente: ' . $d);
    }
    foreach (diferencas($principal['comentarios'], $limpa['comentarios']) as $d) {
        aviso('comentário de coluna diferente: ' . $d);
    }
    foreach (diferencas($principal['views'], $limpa['views']) as $d) {
        aviso('definição de view diferente: ' . strtok($d, ':'));
    }

    // ================= 2. Estruturas novas =================
    $limpa = estrutura($pdoTeste, $bancoTeste);
    $colunasDe = static function (string $tabela) use ($limpa): array {
        $cols = [];
        foreach ($limpa['ordem'] as $k => $pos) {
            if (str_starts_with($k, $tabela . '.')) {
                $cols[$pos] = substr($k, strlen($tabela) + 1);
            }
        }
        ksort($cols);
        return array_values($cols);
    };

    ok('agenda_planos: colunas', $colunasDe('agenda_planos') === ['id', 'titulo', 'observacao', 'data_inicio', 'data_fim', 'responsavel_id', 'status', 'created_at', 'updated_at'],
        implode(',', $colunasDe('agenda_planos')));
    ok('agenda_planos: status = AgendaPlano::STATUS', enumValores($pdoTeste, $bancoTeste, 'agenda_planos', 'status') === array_keys(AgendaPlano::STATUS));
    ok('agenda_planos: FK responsável → usuarios (SET NULL)', ($limpa['fks']['agenda_planos.fk_agenda_planos_responsavel'] ?? '') === 'responsavel_id->usuarios(id) UPD=RESTRICT DEL=SET NULL');
    ok('agenda_tarefas: colunas atuais (plano_id, data_inicio, responsavel_id, em_andamento)',
        $colunasDe('agenda_tarefas') === ['id', 'plano_id', 'titulo', 'data_inicio', 'data_tarefa', 'prioridade', 'responsavel_id', 'observacao', 'em_andamento', 'concluida', 'concluida_em', 'created_at', 'updated_at'],
        implode(',', $colunasDe('agenda_tarefas')));
    ok('agenda_tarefas: em_andamento NOT NULL DEFAULT 0', str_starts_with($limpa['colunas']['agenda_tarefas.em_andamento'] ?? '', 'tinyint(1)|NO|0|'));
    ok('agenda_tarefas: índices de plano e responsável',
        ($limpa['indices']['agenda_tarefas.idx_agenda_tarefas_plano'] ?? '') === 'plano_id'
        && ($limpa['indices']['agenda_tarefas.idx_agenda_tarefas_responsavel'] ?? '') === 'responsavel_id');
    ok('agenda_tarefas: FK plano (RESTRICT) e responsável (SET NULL)',
        ($limpa['fks']['agenda_tarefas.fk_agenda_tarefas_plano'] ?? '') === 'plano_id->agenda_planos(id) UPD=RESTRICT DEL=RESTRICT'
        && ($limpa['fks']['agenda_tarefas.fk_agenda_tarefas_responsavel'] ?? '') === 'responsavel_id->usuarios(id) UPD=RESTRICT DEL=SET NULL');

    ok('schema: sem tabelas de áreas comerciais secundárias',
        !isset($limpa['tabelas']['usuario_areas'])
        && !isset($limpa['tabelas']['central_atendimentos'])
        && !isset($limpa['tabelas']['central_atendimento_eventos'])
        && !isset($limpa['tabelas']['takebox_clientes'])
        && !isset($limpa['tabelas']['takebox_cliente_tipos'])
        && !isset($limpa['tabelas']['takebox_cliente_pessoas']));

    // ================= 3. Restrições funcionando no banco limpo =================
    $pdoTeste->exec("INSERT INTO usuarios (id, nome, email, senha_hash, perfil) VALUES (1, 'Teste', 'teste@exemplo.invalid', 'x', 'usuario')");
    ok('restrição: tarefa com plano inexistente é recusada',
        recusado($pdoTeste, "INSERT INTO agenda_tarefas (plano_id, titulo, data_tarefa) VALUES (999, 't', '2026-01-01')"));
    $pdoTeste->exec("INSERT INTO agenda_planos (id, titulo, data_inicio, data_fim, status) VALUES (1, 'Plano teste', '2026-01-01', '2026-01-31', 'planejado')");
    $pdoTeste->exec("INSERT INTO agenda_tarefas (plano_id, titulo, data_tarefa) VALUES (1, 't', '2026-01-01')");
    ok('restrição: plano com tarefa não pode ser excluído', recusado($pdoTeste, 'DELETE FROM agenda_planos WHERE id = 1'));
    $pdoTeste->exec('DELETE FROM agenda_tarefas WHERE plano_id = 1');
    $pdoTeste->exec('DELETE FROM agenda_planos WHERE id = 1');
    $pdoTeste->exec('DELETE FROM usuarios WHERE id = 1');

    // ================= 4. Seed =================
    $contar = static fn (string $t): int => (int) $pdoTeste->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    ok('seed: dados estruturais carregados',
        $contar('categorias') > 0 && $contar('categorias_materiais') > 0 && $contar('unidades_medida') > 0
        && $contar('configuracoes') > 0 && $contar('tabela_preco_margens_cfg') > 0 && $contar('tabela_preco_config') === 1);
    ok('seed: sem dados comerciais', $contar('produtos') === 0 && $contar('materiais') === 0 && $contar('colecoes') === 0
        && $contar('agenda_planos') === 0 && $contar('agenda_tarefas') === 0);

    // ================= 5. producao_verificar.sql (somente leitura) roda no banco limpo =================
    $verificar = comandosSql($raiz . '/database/producao_verificar.sql');
    $somenteLeitura = array_filter($verificar, static fn (string $c): bool => preg_match('/^\s*SELECT\b/i', $c) !== 1) === [];
    $erroVerificar = '';
    foreach ($verificar as $cmd) {
        try {
            $pdoTeste->query($cmd)->fetchAll();
        } catch (PDOException $e) {
            $erroVerificar = $e->getMessage();
            break;
        }
    }
    ok('producao_verificar.sql: só SELECT e roda sem erro', $somenteLeitura && $erroVerificar === '', $erroVerificar);
} catch (Throwable $e) {
    ok('execução sem exceção', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $pdoTeste = null;
    $limpezaErro = '';
    try {
        if ($criadoPorEsteTeste && str_starts_with($bancoTeste, PREFIXO_BANCO) && $bancoTeste !== $bancoLocal && $bancoTeste !== 'tm_sistema_dev' && $bancoTeste !== 'papel_magenta_dev') {
            $pdoServidor->exec("DROP DATABASE `{$bancoTeste}`");
        }
    } catch (Throwable $e) {
        $limpezaErro = $e->getMessage();
    }
    ok('limpeza: sem erros', $limpezaErro === '', $limpezaErro);
}

$restou = $pdoServidor->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n');
$restou->execute(['n' => $bancoTeste]);
ok('limpeza: banco temporário removido', (int) $restou->fetchColumn() === 0, $bancoTeste);
$sigDepois = assinaturaBanco($pdoPrincipal);
echo "Assinatura do banco principal depois: {$sigDepois}" . PHP_EOL;
ok('banco principal idêntico ao inicial (estrutura e dados)', $sigDepois === $sigAntes);

echo PHP_EOL . "Resultado: {$pass} OK, {$fail} falha(s), " . count($avisos) . ' aviso(s).' . PHP_EOL;
exit($fail > 0 ? 1 : 0);

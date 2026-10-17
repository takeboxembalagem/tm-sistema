<?php
/**
 * Testes locais — Unidade de venda / Pacote (1–12).
 * NÃO executar em produção. Uso: php scripts/test_quantidade_pacote.php
 */
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::pdo();
$pass = 0;
$fail = 0;
$results = [];

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $results;
    if ($cond) {
        $pass++;
        $results[] = ['ok' => true, 'name' => $name, 'detail' => $detail];
        echo "[OK] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        $results[] = ['ok' => false, 'name' => $name, 'detail' => $detail];
        echo "[FALHA] {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
}

$suffix = bin2hex(random_bytes(3));
$codProd = 'TST-PAC-' . $suffix;
$idsLimpar = ['produtos' => [], 'variacoes' => [], 'materiais' => []];

try {
    // --- Setup produto A (sem pacote) ---
    $idA = Produto::create([
        'codigo_interno' => $codProd . '-A',
        'nome' => 'Teste Pacote A',
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
    $idsLimpar['produtos'][] = $idA;

    // --- Setup produto B (pacote 10) ---
    $idB = Produto::create([
        'codigo_interno' => $codProd . '-B',
        'nome' => 'Teste Pacote B',
        'tamanho' => null,
        'tamanho_aberta' => null,
        'descricao' => null,
        'categoria_id' => null,
        'colecao_id' => null,
        'foto_principal' => null,
        'status' => 'ativo',
        'quantidade_pacote' => 10,
        'peso_pacote_kg' => null,
    ]);
    $idsLimpar['produtos'][] = $idB;

    // Composição do B com material criado pelo teste: R$ 2,50 por unidade × 1, produção de 1 peça
    // → custo unitário conhecido de R$ 2,50.
    $matId = Material::create([
        'nome' => $codProd . ' material',
        'categoria_material_id' => null,
        'unidade_id' => null,
        'forma_cobranca' => 'unidade',
        'custo_atual' => 2.5,
        'fornecedor' => null,
        'observacao' => null,
        'status' => 'ativo',
    ]);
    $idsLimpar['materiais'][] = $matId;
    $pdo->prepare(
        'INSERT INTO produto_composicao (produto_id, material_id, quantidade, modo)
         VALUES (:p, :m, 1, \'unidade\')'
    )->execute(['p' => $idB, 'm' => $matId]);
    Produto::setProducaoReferencia($idB, [
        'quantidade_producao' => 1,
        'rendimento_folha' => 1,
        'folha_largura' => null,
        'folha_altura' => null,
    ]);

    // 1) Produto sem pacote
    $pa = Produto::find($idA);
    ok('1. Produto sem pacote', ($pa['quantidade_pacote'] ?? null) === null
        && quantidade_pacote_resolvida($pa) === null);

    // 2) Produto com pacote 10
    $pb = Produto::find($idB);
    ok('2. Produto com pacote 10', (int) ($pb['quantidade_pacote'] ?? 0) === 10
        && quantidade_pacote_resolvida($pb) === 10);

    // 3) Alterar 10 → 20
    Produto::update($idB, [
        'codigo_interno' => $pb['codigo_interno'],
        'nome' => $pb['nome'],
        'tamanho' => $pb['tamanho'],
        'tamanho_aberta' => $pb['tamanho_aberta'],
        'descricao' => $pb['descricao'],
        'categoria_id' => $pb['categoria_id'],
        'colecao_id' => $pb['colecao_id'],
        'foto_principal' => $pb['foto_principal'],
        'status' => $pb['status'],
        'quantidade_pacote' => 20,
        'peso_pacote_kg' => $pb['peso_pacote_kg'],
    ]);
    $pb2 = Produto::find($idB);
    ok('3. Alterar pacote 10→20', (int) $pb2['quantidade_pacote'] === 20
        && quantidade_pacote_resolvida($pb2) === 20);

    // 4) Variação herdar
    $vidHerdar = Variacao::create([
        'produto_id' => $idB,
        'codigo' => $codProd . '-VH',
        'nome' => 'Var Herdar',
        'estoque' => 5,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
    $idsLimpar['variacoes'][] = $vidHerdar;
    $vh = Variacao::find($vidHerdar);
    $pb2 = Produto::find($idB);
    ok('4. Variação herdar pacote do produto',
        ($vh['pacote_modo'] ?? '') === 'herdar'
        && ($vh['quantidade_pacote'] ?? null) === null
        && quantidade_pacote_resolvida($pb2, $vh) === 20);

    // 5) Variação personalizar
    $vidProp = Variacao::create([
        'produto_id' => $idB,
        'codigo' => $codProd . '-VP',
        'nome' => 'Var Proprio',
        'estoque' => 3,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'herdar',
        'pacote_modo' => 'proprio',
        'quantidade_pacote' => 6,
    ]);
    $idsLimpar['variacoes'][] = $vidProp;
    $vp = Variacao::find($vidProp);
    ok('5. Variação personalizar pacote',
        ($vp['pacote_modo'] ?? '') === 'proprio'
        && (int) $vp['quantidade_pacote'] === 6
        && quantidade_pacote_resolvida($pb2, $vp) === 6
        && quantidade_pacote_resolvida($pb2, $vh) === 20);

    // 6) Duas variações (herdar + próprio) resolvem corretamente
    ok('6. Duas variações resolvem pacote distinto',
        quantidade_pacote_resolvida($pb2, $vh) === 20
        && quantidade_pacote_resolvida($pb2, $vp) === 6);

    // 7) Margem: pacote = preço unitário × qtd (não recalcula fórmula)
    // Matematicamente custo×q/(1-x) == (custo/(1-x))×q — o ponto é: montarLinhas
    // guarda preço UNITÁRIO e qtd_pacote à parte; a view multiplica depois.
    $config = ['imposto_percentual' => 10.0, 'incluir_comissao' => 0, 'comissao_percentual' => 0];
    $margens = [['id' => 1, 'margem_percentual' => 40]];
    $custoU = 2.5;
    $precoU = TabelaPrecoCalculo::calcularPreco($custoU, 10.0, 0.0, 40.0);
    $gruposFake = [[
        'produto' => array_merge($pb2, ['custo_unitario' => $custoU]),
        'variacoes' => [],
    ]];
    $linhasFake = TabelaPreco::montarLinhas($gruposFake, $config, $margens);
    $linhaProd = $linhasFake[0] ?? null;
    $precoLinha = $linhaProd['precos'][1]['preco'] ?? null;
    ok('7. Margem: preço unitário na linha; pacote = × qtd depois',
        $precoU !== null
        && $linhaProd
        && abs((float) $precoLinha - (float) $precoU) < 1e-6
        && (int) ($linhaProd['qtd_pacote'] ?? 0) === 20
        && abs(((float) $precoLinha) * 20 - ((float) $precoU) * 20) < 1e-6,
        'unit=' . $precoU . ' pacote_view=' . ((float) $precoLinha * 20)
    );

    // 8) Custo pacote: linha real da tabela (variação que herda) × qtd_pacote, mesma conta da view
    $custoUnitarioAntes = (float) Composicao::custoParaTiragem($idB, Produto::find($idB))['custo_unitario'];
    $linhaVH = linha_tabela($codProd . '-B', $codProd . '-VH');
    $custoLinhaAntes = (float) ($linhaVH['custo'] ?? -1);
    $qtdLinhaAntes = (int) ($linhaVH['qtd_pacote'] ?? 0);
    $pacoteAntes = money_br($custoLinhaAntes * $qtdLinhaAntes);
    ok('8. Custo pacote = custo unitário × qtd (linha real da tabela)',
        abs($custoUnitarioAntes - 2.5) < 1e-9
        && $linhaVH !== null
        && abs($custoLinhaAntes - 2.5) < 1e-9
        && $qtdLinhaAntes === 20
        && $pacoteAntes === 'R$ 50,00',
        'custo_u=' . $custoUnitarioAntes . ' linha=' . $custoLinhaAntes . ' qtd=' . $qtdLinhaAntes . ' pacote=' . $pacoteAntes
    );

    // 9) Custo próprio da variação NÃO é alterado pelo pacote
    $vidCusto = Variacao::create([
        'produto_id' => $idB,
        'codigo' => $codProd . '-VC',
        'nome' => 'Var Custo Proprio',
        'estoque' => 1,
        'status' => 'ativo',
        'observacao' => null,
        'custo_modo' => 'proprio',
        'pacote_modo' => 'herdar',
        'quantidade_pacote' => null,
    ]);
    $idsLimpar['variacoes'][] = $vidCusto;
    Composicao::copiarDeProdutoParaVariacao($idB, $vidCusto);
    $pb2 = Produto::find($idB);
    $vc = Variacao::find($vidCusto);
    $custoAntes = Variacao::custoUnitarioResolvido($vc, $pb2);
    // muda pacote do produto — custo da variação próprio deve permanecer
    Produto::update($idB, [
        'codigo_interno' => $pb2['codigo_interno'],
        'nome' => $pb2['nome'],
        'tamanho' => $pb2['tamanho'],
        'tamanho_aberta' => $pb2['tamanho_aberta'],
        'descricao' => $pb2['descricao'],
        'categoria_id' => $pb2['categoria_id'],
        'colecao_id' => $pb2['colecao_id'],
        'foto_principal' => $pb2['foto_principal'],
        'status' => $pb2['status'],
        'quantidade_pacote' => 12,
        'peso_pacote_kg' => $pb2['peso_pacote_kg'],
    ]);
    $pb3 = Produto::find($idB);
    $custoDepois = Variacao::custoUnitarioResolvido(Variacao::find($vidCusto), $pb3);
    ok('9. Custo próprio da variação inalterado pelo pacote',
        abs($custoAntes - $custoDepois) < 1e-9
        && ($vc['custo_modo'] ?? '') === 'proprio'
        && quantidade_pacote_resolvida($pb3, $vc) === 12);

    // 10) Pacote 20 → 12 (feito no check 9): custo unitário igual ao do check 8, valor do pacote acompanha
    $custoUnitarioDepois = (float) Composicao::custoParaTiragem($idB, $pb3)['custo_unitario'];
    $linhaVH = linha_tabela($codProd . '-B', $codProd . '-VH');
    $custoLinhaDepois = (float) ($linhaVH['custo'] ?? -1);
    $qtdLinhaDepois = (int) ($linhaVH['qtd_pacote'] ?? 0);
    $pacoteDepois = money_br($custoLinhaDepois * $qtdLinhaDepois);
    ok('10. Pacote 20→12: custo unitário inalterado, valor do pacote R$ 50,00 → R$ 30,00',
        abs($custoUnitarioAntes - $custoUnitarioDepois) < 1e-9
        && abs($custoLinhaDepois - $custoLinhaAntes) < 1e-9
        && $qtdLinhaDepois === 12
        && $pacoteAntes === 'R$ 50,00'
        && $pacoteDepois === 'R$ 30,00',
        'custo_u=' . $custoUnitarioAntes . '→' . $custoUnitarioDepois . ' pacote=' . $pacoteAntes . '→' . $pacoteDepois
    );

    // Integração listarGrupos + montarLinhas com dados reais
    $result = TabelaPreco::listarGrupos(['q' => $codProd . '-B', 'mostrar_inativos' => true], 1, 40);
    $linhas = TabelaPreco::montarLinhas($result['items'], TabelaPreco::config(), TabelaPreco::margens());
    $achouVarHerdar = false;
    $achouVarProp = false;
    $ordemOk = true;
    $prevTipo = null;
    foreach ($linhas as $L) {
        if (($L['codigo'] ?? '') === $codProd . '-VH') {
            $achouVarHerdar = ((int) ($L['qtd_pacote'] ?? 0) === 12);
        }
        if (($L['codigo'] ?? '') === $codProd . '-VP') {
            $achouVarProp = ((int) ($L['qtd_pacote'] ?? 0) === 6);
        }
    }
    ok('11b. Tabela resolve pacote herdar/próprio na montagem',
        $achouVarHerdar && $achouVarProp,
        'linhas=' . count($linhas));

    // 12) Ordem da tabela (produto grupo → variações; natural sort por nome/código)
    $ordemNomes = [];
    foreach ($linhas as $L) {
        if (($L['tipo'] ?? '') === 'grupo') {
            $ordemNomes[] = 'G:' . $L['codigo'];
        } elseif (($L['tipo'] ?? '') === 'variacao') {
            $ordemNomes[] = 'V:' . $L['codigo'];
        } else {
            $ordemNomes[] = 'P:' . $L['codigo'];
        }
    }
    $idxG = array_search('G:' . $codProd . '-B', $ordemNomes, true);
    $idxH = array_search('V:' . $codProd . '-VH', $ordemNomes, true);
    $idxP = array_search('V:' . $codProd . '-VP', $ordemNomes, true);
    $idxC = array_search('V:' . $codProd . '-VC', $ordemNomes, true);
    ok('12. Ordem tabela: grupo antes das variações',
        $idxG !== false && $idxH !== false && $idxP !== false && $idxC !== false
        && $idxG < $idxH && $idxG < $idxP && $idxG < $idxC,
        implode(' | ', $ordemNomes));

    // Extra: duplicação copia quantidade_pacote
    $novoId = Produto::duplicar($idB);
    $idsLimpar['produtos'][] = $novoId;
    $copia = Produto::find($novoId);
    $varsCopia = Variacao::listarPorProduto($novoId);
    ok('Extra. Duplicar copia quantidade_pacote e NÃO copia variações',
        (int) ($copia['quantidade_pacote'] ?? 0) === 12
        && count($varsCopia) === 0);

    // Extra: parse rejeita 0 e negativo
    ok('Extra. parse_quantidade_pacote rejeita 0/negativo/texto',
        parse_quantidade_pacote('') === null
        && parse_quantidade_pacote('10') === 10
        && parse_quantidade_pacote('0') === false
        && parse_quantidade_pacote('-3') === false
        && parse_quantidade_pacote('abc') === false);

    // Extra: produto sem pacote aparece na tabela (não esconde)
    $resultA = TabelaPreco::listarGrupos(['q' => $codProd . '-A', 'mostrar_inativos' => true], 1, 40);
    $linhasA = TabelaPreco::montarLinhas($resultA['items'], TabelaPreco::config(), TabelaPreco::margens());
    $achouA = false;
    foreach ($linhasA as $L) {
        if (($L['codigo'] ?? '') === $codProd . '-A') {
            $achouA = ($L['qtd_pacote'] ?? null) === null;
        }
    }
    ok('Extra. Sem pacote: linha permanece com qtd_pacote null', $achouA);

} catch (Throwable $e) {
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    $fail++;
}

// Cleanup
try {
    foreach ($idsLimpar['variacoes'] as $vid) {
        $pdo->prepare('DELETE FROM produto_variacoes WHERE id = ?')->execute([$vid]);
    }
    foreach ($idsLimpar['produtos'] as $pid) {
        $pdo->prepare('DELETE FROM produtos WHERE id = ?')->execute([$pid]);
    }
    // Depois dos produtos: produto_composicao referencia o material com RESTRICT.
    foreach ($idsLimpar['materiais'] as $mid) {
        $pdo->prepare('DELETE FROM materiais WHERE id = ?')->execute([$mid]);
    }
    echo PHP_EOL . 'Cleanup OK.' . PHP_EOL;
} catch (Throwable $e) {
    echo '[FALHA] Cleanup falhou: ' . $e->getMessage() . PHP_EOL;
    $fail++;
}

echo PHP_EOL . "Resultado: {$pass} ok, {$fail} falha(s)." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

/** Linha da Tabela de Preços (listarGrupos + montarLinhas com config real) pelo código. */
function linha_tabela(string $busca, string $codigo): ?array
{
    $result = TabelaPreco::listarGrupos(['q' => $busca, 'mostrar_inativos' => true], 1, 40);
    foreach (TabelaPreco::montarLinhas($result['items'], TabelaPreco::config(), TabelaPreco::margens()) as $linha) {
        if (($linha['codigo'] ?? '') === $codigo) {
            return $linha;
        }
    }
    return null;
}

function money_to_float_test(string $s): float
{
    $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return (float) $s;
}

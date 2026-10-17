<?php

declare(strict_types=1);

function config(string $key = null, $default = null)
{
    $config = $GLOBALS['config'] ?? [];
    if ($key === null) {
        return $config;
    }
    $parts = explode('.', $key);
    $value = $config;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('base_url', ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base . ($path === '/' ? '' : $path);
}

function redirect(string $path): void
{
    // Grava a sessão antes do Location — evita perda de $_SESSION['user']
    // entre POST /login e GET /produtos em hospedagem compartilhada.
    Session::writeClose();
    header('Location: ' . url($path));
    exit;
}

function request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir)) ?: '/';
    }
    return '/' . ltrim($path, '/');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function old(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_POST)) {
        return e((string) $_POST[$key]);
    }
    return e($default);
}

function flash(string $key, $value = null)
{
    if (func_num_args() === 1) {
        $stored = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $stored;
    }
    $_SESSION['_flash'][$key] = $value;
    return $value;
}

function upload_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    return url('/uploads/' . ltrim($path, '/'));
}

/**
 * Normaliza quantidade por pacote do formulário.
 * Vazio → null (sem pacote). Inteiro positivo → int. Caso inválido → false.
 *
 * @return int|null|false
 */
function parse_quantidade_pacote(string $raw): int|null|false
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return false;
    }
    $n = (int) $raw;
    if ($n <= 0) {
        return false;
    }
    return $n;
}

/**
 * Valida um GTIN (GTIN-8, 12, 13 ou 14) pelo dígito verificador GS1.
 * Espaços e hífens entre os dígitos são descartados; nenhum outro caractere é aceito
 * e o código nunca é completado com zeros nem tem o comprimento alterado.
 * Vazio → [null, null]. Válido → [só dígitos, null]. Inválido → [null, mensagem].
 *
 * @return array{0:?string, 1:?string}
 */
function parse_gtin(string $raw, string $rotulo): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [null, null];
    }
    $gtin = str_replace([' ', '-'], '', $raw);
    if (!preg_match('/^\d+$/', $gtin)) {
        return [null, $rotulo . ': use apenas números (espaços e hífens são ignorados).'];
    }
    $tamanho = strlen($gtin);
    if (!in_array($tamanho, [8, 12, 13, 14], true)) {
        return [null, $rotulo . ': o código deve ter 8, 12, 13 ou 14 dígitos (informados: ' . $tamanho . ').'];
    }
    if (gtin_digito_verificador($gtin) !== (int) $gtin[$tamanho - 1]) {
        return [null, $rotulo . ': dígito verificador inválido. Confira o código.'];
    }
    return [$gtin, null];
}

/** Dígito verificador GS1 calculado sobre todos os dígitos do GTIN, exceto o último. */
function gtin_digito_verificador(string $gtin): int
{
    $soma = 0;
    $peso = 3;
    for ($i = strlen($gtin) - 2; $i >= 0; $i--) {
        $soma += (int) $gtin[$i] * $peso;
        $peso = 4 - $peso;
    }
    return (10 - $soma % 10) % 10;
}

/**
 * Resolve qtd por pacote: variação própria ou herança do produto.
 * Retorna null se não houver pacote definido.
 */
function quantidade_pacote_resolvida(array $produto, ?array $variacao = null): ?int
{
    if ($variacao !== null) {
        $modo = (($variacao['pacote_modo'] ?? 'herdar') === 'proprio') ? 'proprio' : 'herdar';
        if ($modo === 'proprio') {
            $q = $variacao['quantidade_pacote'] ?? null;
            if ($q === null || $q === '') {
                return null;
            }
            $n = (int) $q;
            return $n > 0 ? $n : null;
        }
    }
    $q = $produto['quantidade_pacote'] ?? null;
    if ($q === null || $q === '') {
        return null;
    }
    $n = (int) $q;
    return $n > 0 ? $n : null;
}

/**
 * Pacotes completos a partir do estoque em unidades.
 * Floor: nunca arredonda para cima. Null se não houver quantidade por pacote válida.
 */
function pacotes_completos(int $estoqueUnidades, ?int $quantidadePorPacote): ?int
{
    if ($quantidadePorPacote === null || $quantidadePorPacote <= 0) {
        return null;
    }
    if ($estoqueUnidades < 0) {
        $estoqueUnidades = 0;
    }
    return intdiv($estoqueUnidades, $quantidadePorPacote);
}

/**
 * Soma os pacotes completos das variações logo após uma linha de grupo.
 * Null se alguma variação do grupo não tiver quantidade por pacote válida.
 *
 * @param list<array<string, mixed>> $linhas
 */
function pacotes_completos_do_grupo(array $linhas, int $indiceGrupo): ?int
{
    $soma = 0;
    $vars = 0;
    $comPacote = 0;
    $n = count($linhas);
    for ($j = $indiceGrupo + 1; $j < $n; $j++) {
        if (($linhas[$j]['tipo'] ?? '') !== 'variacao') {
            break;
        }
        $vars++;
        $qtd = $linhas[$j]['qtd_pacote'] ?? null;
        $pct = pacotes_completos(
            (int) ($linhas[$j]['estoque'] ?? 0),
            $qtd === null ? null : (int) $qtd
        );
        if ($pct !== null) {
            $comPacote++;
            $soma += $pct;
        }
    }
    if ($vars > 0 && $comPacote === $vars) {
        return $soma;
    }
    return null;
}

/**
 * Arredonda sempre para cima no centavo (ceil × 100 / 100).
 * Usar apenas na apresentação final / valores monetários exibidos.
 */
function arredondar_para_cima_centavos($value): float
{
    $v = (float) $value;
    if ($v <= 0) {
        return 0.0;
    }
    return ceil(($v * 100) - 1e-9) / 100;
}

/**
 * Formatação monetária BR com exatamente 2 casas e arredondamento para cima.
 */
function money_br($value, ?int $decimals = null): string
{
    $rounded = arredondar_para_cima_centavos($value);
    return 'R$ ' . number_format($rounded, 2, ',', '.');
}

/**
 * Como money_br, mas exibe negativos com sinal (ex.: -R$ 12,35): o valor absoluto
 * é arredondado para cima. Resíduos próximos de zero aparecem como R$ 0,00.
 */
function money_br_sinal($value): string
{
    $v = (float) $value;
    if ($v >= 0) {
        return money_br($v);
    }
    $abs = arredondar_para_cima_centavos(-$v);
    return $abs > 0 ? '-R$ ' . number_format($abs, 2, ',', '.') : 'R$ 0,00';
}

/**
 * Formata número não monetário (ex.: quantidade) sem regra de centavos.
 */
function number_br($value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function percent_br($value, int $decimals = 2): string
{
    $v = (float) $value;
    $sign = $v > 0 ? '+' : '';
    return $sign . number_format($v, $decimals, ',', '.') . '%';
}

/**
 * Modalidades no cadastro de Custos (classificação/referência).
 * NÃO são usadas para recalcular preço na formação do produto.
 */
function formas_cobranca_cadastro(): array
{
    return [
        'unidade' => 'Por unidade',
        'folha' => 'Por folha',
        'milheiro' => 'Por milheiro',
        'lote' => 'Por lote',
    ];
}

/** Chaves aceitas no banco (inclui legado e custo manual na formação). */
function formas_cobranca(): array
{
    return [
        'unidade' => 'Por unidade',
        'folha' => 'Por folha',
        'milheiro' => 'Por milheiro',
        'milheiro_folha' => 'Por milheiro',
        'lote' => 'Por lote',
        'quantidade' => 'Por unidade',
        'aproveitamento' => 'Por folha',
        'manual' => 'Manual',
    ];
}

function forma_cobranca_label(string $forma): string
{
    $forma = normalizar_forma_cobranca($forma);
    return match ($forma) {
        'unidade' => 'Unidade',
        'folha' => 'Folha',
        'milheiro' => 'Milheiro',
        'lote' => 'Lote',
        'manual' => 'Manual',
        default => $forma,
    };
}

/** Normaliza legado para rótulo/UI (sem efeito no cálculo da formação). */
function normalizar_forma_cobranca(string $forma): string
{
    return match ($forma) {
        'aproveitamento' => 'folha',
        'quantidade' => 'unidade',
        'milheiro_folha' => 'milheiro',
        'unidade', 'folha', 'milheiro', 'lote', 'manual' => $forma,
        default => 'unidade',
    };
}

/** Linha de composição sem material cadastrado (custo avulso). */
function composicao_eh_manual(array $linha): bool
{
    if (($linha['tipo'] ?? '') === 'manual') {
        return true;
    }
    if (($linha['modo'] ?? '') === 'manual') {
        return true;
    }
    $mid = $linha['material_id'] ?? null;
    return $mid === null || $mid === '' || (int) $mid === 0;
}

/** Grava modalidade do formulário de custos (sem base peças/folhas). */
function forma_cobranca_from_cadastro(string $modalidade, string $milheiroBase = 'pecas'): string
{
    $modalidade = strtolower(trim($modalidade));
    if (in_array($modalidade, ['unidade', 'folha', 'milheiro', 'lote'], true)) {
        return $modalidade;
    }
    return 'unidade';
}

/**
 * Aceita "1.234,56", "0,0045", "0.0045" ou "1234.56".
 * Mantém precisão interna (até 6 casas) sem arredondar para cima.
 */
function parse_money_br(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? '';
    if ($raw === '' || $raw === '-' || $raw === ',' || $raw === '.') {
        return null;
    }

    if (str_contains($raw, ',') && str_contains($raw, '.')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (str_contains($raw, ',')) {
        $raw = str_replace(',', '.', $raw);
    }

    if (!is_numeric($raw)) {
        return null;
    }

    return (float) $raw;
}

/**
 * Quantidade de estoque (peças/papéis) no formato BR: ponto = milhar, vírgula = decimal.
 * Aceita "100", "1000", "10,5", "1.000", "10.500,25". Rejeita letras e formatos ambíguos ("1.5").
 */
function parse_quantidade_br(string $raw): ?float
{
    $raw = trim($raw);
    if (!preg_match('/^-?(\d+|\d{1,3}(\.\d{3})+)(,\d+)?$/', $raw)) {
        return null;
    }

    return (float) str_replace(['.', ','], ['', '.'], $raw);
}

/**
 * Comparação natural de produtos: nome → codigo_interno → id.
 * Usar com usort para ordem determinística (Caixa 2 antes de Caixa 10).
 */
function cmp_natural_produto(array $a, array $b): int
{
    $cmp = strnatcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    $cmp = strnatcasecmp((string) ($a['codigo_interno'] ?? ''), (string) ($b['codigo_interno'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
}

/**
 * Comparação natural de variações: codigo → nome (como listarPorProduto).
 */
function cmp_natural_variacao(array $a, array $b): int
{
    $cmp = strnatcasecmp((string) ($a['codigo'] ?? ''), (string) ($b['codigo'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return strnatcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
}

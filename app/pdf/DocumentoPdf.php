<?php

declare(strict_types=1);

/**
 * Página TCPDF com cabeçalho (logo da área, título, emissão) e rodapé (área, paginação) repetidos em todas as páginas.
 */
final class DocumentoPdfPagina extends TCPDF
{
    public string $tituloDocumento = '';
    public string $emissao = '';
    public string $nomeArea = '';

    /** @var array{arquivo:string, tipo:string, w:float, h:float}|null */
    public ?array $logo = null;

    /** @var array{0:int, 1:int, 2:int} */
    public array $corArea = [60, 60, 60];

    public float $logoAltura = 12.0;

    public function __construct(string $orientacao)
    {
        parent::__construct($orientacao, 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false;
    }

    public function Header()
    {
        $esq = $this->original_lMargin;
        $largura = $this->getPageWidth() - $esq - $this->original_rMargin;
        $topo = 10.0;

        $larguraLogo = 0.0;
        if ($this->logo !== null) {
            $this->Image($this->logo['arquivo'], $esq, $topo, $this->logo['w'], $this->logo['h'], $this->logo['tipo'], '', '', false, 300);
            $larguraLogo = $this->logo['w'];
        } else {
            $this->SetFont(DocumentoPdf::FONTE, 'B', 13);
            $this->SetTextColor($this->corArea[0], $this->corArea[1], $this->corArea[2]);
            $this->SetXY($esq, $topo + 2);
            $this->Cell(60, 7, $this->nomeArea, 0, 0, 'L');
            $larguraLogo = 60.0;
        }

        $xTitulo = $esq + $larguraLogo + 6;
        $larguraTitulo = $largura - $larguraLogo - 6;
        $this->SetTextColor(30, 30, 30);
        $this->SetFont(DocumentoPdf::FONTE, 'B', 13);
        $this->SetXY($xTitulo, $topo + 0.5);
        $this->Cell($larguraTitulo, 7, $this->tituloDocumento, 0, 0, 'R', false, '', 1);
        $this->SetFont(DocumentoPdf::FONTE, '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->SetXY($xTitulo, $topo + 7.5);
        $this->Cell($larguraTitulo, 5, 'Emitido em ' . $this->emissao, 0, 0, 'R');

        $y = $topo + $this->logoAltura + 3;
        $this->SetDrawColor($this->corArea[0], $this->corArea[1], $this->corArea[2]);
        $this->SetLineWidth(0.6);
        $this->Line($esq, $y, $esq + $largura, $y);
        $this->SetLineWidth(0.2);
    }

    public function Footer()
    {
        $esq = $this->original_lMargin;
        $largura = $this->getPageWidth() - $esq - $this->original_rMargin;
        $y = $this->getPageHeight() - 12;

        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line($esq, $y, $esq + $largura, $y);
        $this->SetFont(DocumentoPdf::FONTE, '', 7);
        $this->SetTextColor(110, 110, 110);
        $this->SetXY($esq, $y + 1.5);
        $this->Cell($largura * 0.75, 4, $this->nomeArea . ' · ' . $this->tituloDocumento, 0, 0, 'L', false, '', 1);
        $this->SetXY($esq, $y + 1.5);
        $this->Cell($largura, 4, $this->getAliasRightShift() . 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

/**
 * Documento PDF com a identidade visual da área principal (T&M): A4, fundo branco,
 * cabeçalho e rodapé repetidos, blocos de filtros e totais e tabelas que repetem o cabeçalho a cada página.
 * Enquanto não houver logo T&M, o cabeçalho usa texto (nome da área). Só desenha o que recebe;
 * consultas, filtros e totais ficam com quem monta o documento.
 */
final class DocumentoPdf
{
    public const RETRATO = 'P';
    public const PAISAGEM = 'L';
    public const FONTE = 'dejavusans';

    /**
     * Arquivo de logo opcional em public/assets/img (null = texto no cabeçalho).
     * Sem logo T&M fornecida: não reutilizar marcas anteriores.
     */
    private const LOGOS = [
        Areas::PAPEL_MAGENTA => null,
    ];

    /** Cor de destaque do cabeçalho/rodapé (neutra até haver identidade gráfica própria). */
    private const CORES = [
        Areas::PAPEL_MAGENTA => [55, 55, 55],
    ];

    private const MARGEM_X = 12.0;
    private const MARGEM_TOPO = 30.0;
    private const MARGEM_BASE = 18.0;
    private const LOGO_H = 12.0;
    private const LOGO_MAX_W = 48.0;

    /** Limite de pixels da logo para a conversão sem GD (evita processar imagens enormes). */
    private const LOGO_MAX_PIXELS = 4000000;

    private DocumentoPdfPagina $pdf;
    private ?string $logoTemporario = null;

    public function __construct(string $area, string $titulo, string $orientacao = self::RETRATO)
    {
        if (!isset(self::CORES[$area])) {
            throw new InvalidArgumentException('Área sem identidade visual para PDF.');
        }

        $this->pdf = new DocumentoPdfPagina($orientacao === self::PAISAGEM ? 'L' : 'P');
        $this->pdf->tituloDocumento = $titulo;
        $this->pdf->emissao = date('d/m/Y') . ' às ' . date('H:i');
        $this->pdf->nomeArea = (string) (Areas::todas()[$area]['nome'] ?? '');
        $this->pdf->corArea = self::CORES[$area];
        $this->pdf->logoAltura = self::LOGO_H;
        $arquivoLogo = self::LOGOS[$area] ?? null;
        $this->pdf->logo = $arquivoLogo !== null
            ? $this->prepararLogo(dirname(__DIR__, 2) . '/public/assets/img/' . $arquivoLogo)
            : null;

        $this->pdf->SetCreator($this->pdf->nomeArea);
        $this->pdf->SetAuthor($this->pdf->nomeArea);
        $this->pdf->SetTitle($titulo);
        $this->pdf->setPrintHeader(true);
        $this->pdf->setPrintFooter(true);
        $this->pdf->SetMargins(self::MARGEM_X, self::MARGEM_TOPO, self::MARGEM_X);
        $this->pdf->SetHeaderMargin(10);
        $this->pdf->SetFooterMargin(12);
        $this->pdf->SetAutoPageBreak(true, self::MARGEM_BASE);
        $this->pdf->setCellHeightRatio(1.25);
        $this->pdf->SetCompression(true);
        $this->pdf->setFontSubsetting(true);
        $this->pdf->SetDisplayMode('fullwidth', 'OneColumn');
        $this->pdf->SetFont(self::FONTE, '', 9);
        $this->pdf->SetTextColor(30, 30, 30);
        $this->pdf->AddPage();
    }

    public function __destruct()
    {
        $this->removerLogoTemporario();
    }

    /**
     * Bloco "Filtros aplicados" (rótulo => valor), logo abaixo do cabeçalho.
     *
     * @param array<string, string> $filtros
     */
    public function filtros(array $filtros): void
    {
        $partes = [];
        foreach ($filtros as $rotulo => $valor) {
            $partes[] = $rotulo . ': ' . $valor;
        }
        $texto = $partes ? implode('   •   ', $partes) : 'Nenhum filtro (todos os registros).';

        $this->pdf->SetFont(self::FONTE, 'B', 8);
        $this->pdf->SetTextColor(70, 70, 70);
        $this->pdf->Cell(0, 5, 'Filtros aplicados', 0, 1, 'L');
        $this->pdf->SetFont(self::FONTE, '', 8);
        $this->pdf->SetTextColor(30, 30, 30);
        $this->pdf->SetFillColor(246, 246, 246);
        $this->pdf->setCellPaddings(2, 1.5, 2, 1.5);
        $this->pdf->MultiCell(0, 0, $texto, 0, 'L', true, 1);
        $this->pdf->setCellPaddings(1, 0, 1, 0);
        $this->pdf->Ln(3);
    }

    /**
     * Totais/indicadores em caixas lado a lado (rótulo => valor já formatado).
     *
     * @param array<string, string> $itens
     */
    public function resumo(array $itens): void
    {
        if (!$itens) {
            return;
        }
        $largura = $this->larguraUtil();
        $espaco = 3.0;
        $w = ($largura - $espaco * (count($itens) - 1)) / count($itens);
        $h = 13.0;
        $this->garantirEspaco($h + 3);
        $x0 = $this->pdf->GetX();
        $y = $this->pdf->GetY();

        $this->pdf->SetDrawColor(215, 215, 215);
        $this->pdf->SetLineWidth(0.2);
        $i = 0;
        foreach ($itens as $rotulo => $valor) {
            $x = $x0 + $i * ($w + $espaco);
            $this->pdf->Rect($x, $y, $w, $h, 'D');
            $this->pdf->SetFont(self::FONTE, '', 7);
            $this->pdf->SetTextColor(100, 100, 100);
            $this->pdf->SetXY($x + 2, $y + 1.5);
            $this->pdf->Cell($w - 4, 4, $rotulo, 0, 0, 'L', false, '', 1);
            $this->pdf->SetFont(self::FONTE, 'B', 11);
            if ($this->pdf->GetStringWidth($valor) > $w - 4) {
                $this->pdf->SetFont(self::FONTE, 'B', 8.5);
            }
            $this->pdf->SetTextColor(30, 30, 30);
            $this->pdf->SetXY($x + 2, $y + 6);
            $this->pdf->Cell($w - 4, 6, $valor, 0, 0, 'L', false, '', 1);
            $i++;
        }
        $this->pdf->SetXY($x0, $y + $h + 4);
    }

    /** Subtítulo de seção (ex.: "Posição em 10/10/2026"). */
    public function subtitulo(string $texto): void
    {
        $this->garantirEspaco(10);
        $this->pdf->SetFont(self::FONTE, 'B', 10);
        $this->pdf->SetTextColor(30, 30, 30);
        $this->pdf->Cell(0, 6, $texto, 0, 1, 'L');
        $this->pdf->Ln(1);
    }

    /** Observação em texto pequeno (ex.: regra de cálculo já exibida na tela). */
    public function nota(string $texto): void
    {
        $this->pdf->Ln(2);
        $this->pdf->SetFont(self::FONTE, '', 7.5);
        $this->pdf->SetTextColor(90, 90, 90);
        $this->pdf->MultiCell(0, 0, $texto, 0, 'L', false, 1);
        $this->pdf->SetTextColor(30, 30, 30);
    }

    /**
     * Tabela A4: colunas "ajustar" (códigos, datas, números, rótulos curtos) recebem a largura do maior valor,
     * sem quebrar; as demais dividem o espaço restante pelo peso. As linhas crescem conforme o texto
     * e, na quebra de página, o cabeçalho da tabela é repetido.
     *
     * @param list<array{titulo:string, peso:float|int, alinhar?:string, negrito?:bool, ajustar?:bool}> $colunas
     * @param list<list<string>> $linhas
     */
    public function tabela(array $colunas, array $linhas, string $textoVazio, float $fonte = 8.0): void
    {
        $larguras = $this->larguras($colunas, $linhas, $fonte);
        $this->pdf->SetAutoPageBreak(false, self::MARGEM_BASE);
        $this->pdf->setCellPaddings(1.5, 1.2, 1.5, 1.2);

        $this->garantirEspaco(18);
        $this->cabecalhoTabela($colunas, $larguras, $fonte);

        if (!$linhas) {
            $this->pdf->SetFont(self::FONTE, '', $fonte);
            $this->pdf->SetTextColor(110, 110, 110);
            $this->pdf->MultiCell(array_sum($larguras), 0, $textoVazio, 'B', 'C', false, 1);
        }

        foreach ($linhas as $n => $linha) {
            $h = 0.0;
            foreach ($colunas as $i => $col) {
                $this->pdf->SetFont(self::FONTE, !empty($col['negrito']) ? 'B' : '', $fonte);
                $h = max($h, $this->pdf->getStringHeight($larguras[$i], (string) ($linha[$i] ?? '')));
            }

            if ($this->pdf->GetY() + $h > $this->limiteInferior()) {
                $this->pdf->AddPage();
                $this->cabecalhoTabela($colunas, $larguras, $fonte);
            }

            $x = self::MARGEM_X;
            $y = $this->pdf->GetY();
            $zebra = $n % 2 === 1;
            $this->pdf->SetFillColor(247, 247, 247);
            $this->pdf->SetDrawColor(225, 225, 225);
            $this->pdf->SetTextColor(30, 30, 30);
            foreach ($colunas as $i => $col) {
                $this->pdf->SetFont(self::FONTE, !empty($col['negrito']) ? 'B' : '', $fonte);
                $this->pdf->MultiCell($larguras[$i], $h, (string) ($linha[$i] ?? ''), 'B', $col['alinhar'] ?? 'L', $zebra, 0, $x, $y, true, 0, false, true, $h, 'M');
                $x += $larguras[$i];
            }
            $this->pdf->SetXY(self::MARGEM_X, $y + $h);
        }

        $this->pdf->setCellPaddings(1, 0, 1, 0);
        $this->pdf->SetAutoPageBreak(true, self::MARGEM_BASE);
        $this->pdf->Ln(1);
    }

    /** Conteúdo binário do PDF; a logo temporária (quando houver) é apagada em seguida. */
    public function gerar(): string
    {
        try {
            return $this->pdf->Output('documento.pdf', 'S');
        } finally {
            $this->removerLogoTemporario();
        }
    }

    /**
     * Monta e envia o PDF para visualização no navegador (o usuário pode salvar ou imprimir).
     * Falha inesperada vira mensagem genérica; o detalhe vai só para o log do servidor.
     *
     * @param callable(): DocumentoPdf $montar
     */
    public static function responder(callable $montar, string $nomeArquivo, string $voltar): void
    {
        try {
            $binario = $montar()->gerar();
        } catch (Throwable $e) {
            error_log('[PDF] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            self::erro('Não foi possível gerar o PDF. Tente novamente em instantes.', 500, $voltar);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $nome = preg_replace('/[^A-Za-z0-9._-]/', '-', $nomeArquivo) ?: 'documento.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nome . '"');
        header('Content-Length: ' . strlen($binario));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $binario;
        exit;
    }

    /** Página de erro do PDF (filtro inválido, limite excedido etc.), sem detalhes internos. */
    public static function erro(string $mensagem, int $status, string $voltar): void
    {
        http_response_code($status);
        View::render('pages/pdf_erro', [
            'title' => 'PDF não gerado',
            'page' => '',
            'mensagem' => $mensagem,
            'voltar' => $voltar,
        ]);
    }

    public static function data(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        $t = strtotime($valor);
        return $t === false ? '—' : date('d/m/Y', $t);
    }

    public static function dataHora(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        $t = strtotime($valor);
        return $t === false ? '—' : date('d/m/Y H:i', $t);
    }

    /** Nome de arquivo com data/hora da emissão, ex.: inventario-pecas-20261014-1730.pdf */
    public static function nomeArquivo(string $base): string
    {
        return $base . '-' . date('Ymd-Hi') . '.pdf';
    }

    private function cabecalhoTabela(array $colunas, array $larguras, float $fonte): void
    {
        $this->pdf->SetFont(self::FONTE, 'B', $fonte - 0.5);
        $h = 0.0;
        foreach ($colunas as $i => $col) {
            $h = max($h, $this->pdf->getStringHeight($larguras[$i], $col['titulo']));
        }
        $x = self::MARGEM_X;
        $y = $this->pdf->GetY();
        $this->pdf->SetFillColor(236, 236, 236);
        $this->pdf->SetDrawColor(190, 190, 190);
        $this->pdf->SetTextColor(40, 40, 40);
        foreach ($colunas as $i => $col) {
            $this->pdf->MultiCell($larguras[$i], $h, $col['titulo'], 'B', $col['alinhar'] ?? 'L', true, 0, $x, $y, true, 0, false, true, $h, 'M');
            $x += $larguras[$i];
        }
        $this->pdf->SetXY(self::MARGEM_X, $y + $h);
    }

    /** @return list<float> */
    private function larguras(array $colunas, array $linhas, float $fonte): array
    {
        $util = $this->larguraUtil();
        $folga = 3.0 + 1.6;
        $fixas = [];
        foreach ($colunas as $i => $col) {
            if (empty($col['ajustar'])) {
                continue;
            }
            $this->pdf->SetFont(self::FONTE, 'B', $fonte - 0.5);
            $maior = 0.0;
            foreach (preg_split('/\s+/u', $col['titulo']) ?: [] as $palavra) {
                $maior = max($maior, $this->pdf->GetStringWidth($palavra));
            }
            $this->pdf->SetFont(self::FONTE, !empty($col['negrito']) ? 'B' : '', $fonte);
            foreach ($linhas as $linha) {
                $maior = max($maior, $this->pdf->GetStringWidth((string) ($linha[$i] ?? '')));
            }
            $fixas[$i] = min($maior + $folga, $util * 0.3);
        }

        $restante = $util - array_sum($fixas);
        $pesoLivre = 0.0;
        foreach ($colunas as $i => $col) {
            if (!isset($fixas[$i])) {
                $pesoLivre += (float) $col['peso'];
            }
        }
        if ($pesoLivre <= 0 || $restante < $util * 0.25) {
            $total = array_sum(array_map(static fn (array $c): float => (float) $c['peso'], $colunas));
            return array_map(static fn (array $c): float => $util * (float) $c['peso'] / $total, $colunas);
        }

        $larguras = [];
        foreach ($colunas as $i => $col) {
            $larguras[] = $fixas[$i] ?? $restante * (float) $col['peso'] / $pesoLivre;
        }
        return $larguras;
    }

    private function larguraUtil(): float
    {
        return $this->pdf->getPageWidth() - 2 * self::MARGEM_X;
    }

    private function limiteInferior(): float
    {
        return $this->pdf->getPageHeight() - self::MARGEM_BASE;
    }

    private function garantirEspaco(float $altura): void
    {
        if ($this->pdf->GetY() + $altura > $this->limiteInferior()) {
            $this->pdf->AddPage();
        }
    }

    /**
     * Logo pronta para a TCPDF. JPEG vai direto. PNG com transparência é aplicado sobre fundo branco
     * aqui mesmo (a TCPDF exige GD ou Imagick para PNG com alfa, e o servidor pode não ter nenhum dos dois);
     * o resultado fica num arquivo temporário apagado ao gerar o documento.
     *
     * @return array{arquivo:string, tipo:string, w:float, h:float}|null null = sem logo utilizável (o nome da área é escrito no lugar)
     */
    private function prepararLogo(string $caminho): ?array
    {
        if (!is_file($caminho)) {
            return null;
        }
        $info = @getimagesize($caminho);
        if ($info === false || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }

        $h = self::LOGO_H;
        $w = $h * ((int) $info[0] / (int) $info[1]);
        if ($w > self::LOGO_MAX_W) {
            $w = self::LOGO_MAX_W;
            $h = $w * ((int) $info[1] / (int) $info[0]);
        }

        $mime = (string) ($info['mime'] ?? '');
        if ($mime === 'image/jpeg') {
            return ['arquivo' => $caminho, 'tipo' => 'JPEG', 'w' => $w, 'h' => $h];
        }
        if ($mime !== 'image/png') {
            return null;
        }

        $bytes = (string) file_get_contents($caminho);
        $tipoCor = strlen($bytes) > 25 ? ord($bytes[25]) : -1;
        $transparente = $tipoCor === 4 || $tipoCor === 6 || str_contains($bytes, 'tRNS');
        if (!$transparente) {
            return ['arquivo' => $caminho, 'tipo' => 'PNG', 'w' => $w, 'h' => $h];
        }

        $opaco = (int) $info[0] * (int) $info[1] <= self::LOGO_MAX_PIXELS ? self::pngSobreBranco($bytes) : null;
        if ($opaco !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'pm_logo_');
            if ($tmp !== false && file_put_contents($tmp, $opaco) !== false) {
                $this->logoTemporario = $tmp;
                return ['arquivo' => $tmp, 'tipo' => 'PNG', 'w' => $w, 'h' => $h];
            }
            if ($tmp !== false) {
                @unlink($tmp);
            }
        }
        if (extension_loaded('imagick') || function_exists('imagecreatefrompng')) {
            return ['arquivo' => $caminho, 'tipo' => 'PNG', 'w' => $w, 'h' => $h];
        }
        return null;
    }

    private function removerLogoTemporario(): void
    {
        if ($this->logoTemporario !== null) {
            @unlink($this->logoTemporario);
            $this->logoTemporario = null;
        }
    }

    /**
     * PNG 8 bits RGBA/cinza+alfa, não entrelaçado → PNG RGB opaco, com cada pixel composto sobre branco.
     * Outros formatos retornam null.
     */
    private static function pngSobreBranco(string $png): ?string
    {
        if (strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }
        $pos = 8;
        $total = strlen($png);
        $ihdr = null;
        $idat = '';
        while ($pos + 8 <= $total) {
            $tam = unpack('N', substr($png, $pos, 4))[1];
            $tipo = substr($png, $pos + 4, 4);
            $dados = substr($png, $pos + 8, $tam);
            $pos += 12 + $tam;
            if ($tipo === 'IHDR') {
                $ihdr = unpack('Nw/Nh/Cbits/Ccor/Ccomp/Cfiltro/Centrelacado', $dados);
            } elseif ($tipo === 'IDAT') {
                $idat .= $dados;
            } elseif ($tipo === 'IEND') {
                break;
            }
        }
        if (!is_array($ihdr) || $ihdr['bits'] !== 8 || $ihdr['entrelacado'] !== 0 || !in_array($ihdr['cor'], [4, 6], true)) {
            return null;
        }

        $largura = $ihdr['w'];
        $altura = $ihdr['h'];
        $canais = $ihdr['cor'] === 6 ? 4 : 2;
        $bytesLinha = $largura * $canais;
        $bruto = @gzuncompress($idat);
        if ($bruto === false || strlen($bruto) < ($bytesLinha + 1) * $altura) {
            return null;
        }

        $anterior = array_fill(0, $bytesLinha, 0);
        $saida = '';
        for ($y = 0; $y < $altura; $y++) {
            $inicio = $y * ($bytesLinha + 1);
            $filtro = ord($bruto[$inicio]);
            $l = array_values(unpack('C*', substr($bruto, $inicio + 1, $bytesLinha)));

            switch ($filtro) {
                case 0:
                    break;
                case 1:
                    for ($i = $canais; $i < $bytesLinha; $i++) {
                        $l[$i] = ($l[$i] + $l[$i - $canais]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $bytesLinha; $i++) {
                        $l[$i] = ($l[$i] + $anterior[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $bytesLinha; $i++) {
                        $esq = $i >= $canais ? $l[$i - $canais] : 0;
                        $l[$i] = ($l[$i] + (($esq + $anterior[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $bytesLinha; $i++) {
                        $a = $i >= $canais ? $l[$i - $canais] : 0;
                        $b = $anterior[$i];
                        $c = $i >= $canais ? $anterior[$i - $canais] : 0;
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $pred = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $l[$i] = ($l[$i] + $pred) & 0xFF;
                    }
                    break;
                default:
                    return null;
            }
            $anterior = $l;

            $rgb = [];
            for ($i = 0; $i < $bytesLinha; $i += $canais) {
                $alfa = $l[$i + $canais - 1];
                $fundo = 255 * (255 - $alfa) + 127;
                if ($canais === 4) {
                    $rgb[] = intdiv($l[$i] * $alfa + $fundo, 255);
                    $rgb[] = intdiv($l[$i + 1] * $alfa + $fundo, 255);
                    $rgb[] = intdiv($l[$i + 2] * $alfa + $fundo, 255);
                } else {
                    $v = intdiv($l[$i] * $alfa + $fundo, 255);
                    $rgb[] = $v;
                    $rgb[] = $v;
                    $rgb[] = $v;
                }
            }
            $saida .= "\0" . pack('C*', ...$rgb);
        }

        $chunk = static fn (string $tipo, string $dados): string
            => pack('N', strlen($dados)) . $tipo . $dados . pack('N', crc32($tipo . $dados));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $largura, $altura, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($saida, 9))
            . $chunk('IEND', '');
    }
}

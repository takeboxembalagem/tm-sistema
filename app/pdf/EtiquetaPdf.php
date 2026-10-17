<?php

declare(strict_types=1);

/**
 * Desenha a folha SL61083 em PDF.
 * Recebe folhas e dados já montados; não consulta cadastro nem decide posição ou quantidade.
 */
final class EtiquetaPdfDocument extends TCPDF
{
    public function __construct(float $width, float $height)
    {
        parent::__construct('P', 'mm', [$width, $height], true, 'UTF-8', false);
        $this->tcpdflink = false;
    }
}

final class EtiquetaPdf
{
    private const SHEET_W = 215.9;
    private const SHEET_H = 279.4;
    private const MARGIN_X = 6.35;
    private const MARGIN_Y = 12.7;
    private const LABEL_W = 101.6;
    private const LABEL_H = 50.8;
    private const PAD_X = 4.0;
    private const PAD_Y = 3.5;
    private const LOGO_H = 7.5;
    private const LOGO_MAX_W = 42.0;

    private EtiquetaPdfDocument $pdf;

    /** @var array{w: float, h: float, type: string}|null */
    private ?array $logoBox = null;

    private bool $logoResolved = false;

    /**
     * @param list<array<int, bool>> $folhas
     * @param array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int} $etiqueta
     * @param array{nome:string, cnpj:string, site:string} $marca
     * @param list<int> $ordemVisual
     */
    public static function render(
        array $folhas,
        array $etiqueta,
        array $marca,
        string $logoPath,
        array $ordemVisual,
    ): string {
        $drawer = new self();

        return $drawer->build($folhas, $etiqueta, $marca, $logoPath, $ordemVisual);
    }

    private function __construct()
    {
        $this->pdf = new EtiquetaPdfDocument(self::SHEET_W, self::SHEET_H);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetHeaderMargin(0);
        $this->pdf->SetFooterMargin(0);
        $this->pdf->SetAutoPageBreak(false, 0);
        $this->pdf->setCellPadding(0);
        $this->pdf->setCellMargins(0, 0, 0, 0);
        $this->pdf->setCellHeightRatio(1.2);
        $this->pdf->SetCompression(true);
        $this->pdf->setFontSubsetting(true);
        $this->pdf->SetDisplayMode('real', 'SinglePage');
        $this->pdf->SetCreator('T&M');
        $this->pdf->SetTitle('Etiquetas');
    }

    /**
     * @param list<array<int, bool>> $folhas
     * @param array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int} $etiqueta
     * @param array{nome:string, cnpj:string, site:string} $marca
     * @param list<int> $ordemVisual
     */
    private function build(
        array $folhas,
        array $etiqueta,
        array $marca,
        string $logoPath,
        array $ordemVisual,
    ): string {
        foreach ($folhas as $folha) {
            $this->pdf->AddPage('P', [self::SHEET_W, self::SHEET_H]);
            foreach ($ordemVisual as $index => $pos) {
                if (empty($folha[$pos])) {
                    continue;
                }
                [$x, $y] = $this->cellOrigin((int) $index);
                $this->drawLabel($x, $y, $etiqueta, $marca, $logoPath);
            }
        }

        return $this->pdf->Output('', 'S');
    }

    /** @return array{0: float, 1: float} */
    private function cellOrigin(int $index): array
    {
        $col = $index % 2;
        $row = intdiv($index, 2);

        return [
            self::MARGIN_X + ($col * self::LABEL_W),
            self::MARGIN_Y + ($row * self::LABEL_H),
        ];
    }

    /**
     * @param array{nome:string, codigo:string, tamanho_montada:string, quantidade_pacote:?int} $etiqueta
     * @param array{nome:string, cnpj:string, site:string} $marcaCfg
     */
    private function drawLabel(float $x, float $y, array $etiqueta, array $marcaCfg, string $logoPath): void
    {
        $ix = $x + self::PAD_X;
        $iy = $y + self::PAD_Y;
        $iw = self::LABEL_W - (2 * self::PAD_X);
        $bottom = $y + self::LABEL_H - self::PAD_Y;

        $nome = trim((string) ($etiqueta['nome'] ?? ''));
        $codigo = trim((string) ($etiqueta['codigo'] ?? ''));
        $tamanho = trim((string) ($etiqueta['tamanho_montada'] ?? ''));
        $pacote = $etiqueta['quantidade_pacote'] ?? null;
        $pacoteTxt = $pacote !== null ? 'Pcte: ' . (int) $pacote : '';
        $cnpj = 'CNPJ: ' . trim((string) ($marcaCfg['cnpj'] ?? ''));
        $site = trim((string) ($marcaCfg['site'] ?? ''));
        $marca = trim((string) ($marcaCfg['nome'] ?? ''));
        $logo = $this->logoSize($logoPath);

        $this->pdf->SetFont('dejavusans', '', 6);
        $cnpjH = $this->blockHeight($iw, $cnpj);
        $siteH = $site !== '' ? $this->blockHeight($iw, $site) : 0.0;

        if ($logo !== null) {
            $logoH = $logo['h'];
            $logoW = $logo['w'];
        } else {
            $this->pdf->SetFont('dejavusans', 'B', 6);
            $logoH = $marca !== '' ? $this->blockHeight($iw, $marca) : 0.0;
            $logoW = $iw;
        }

        $footerTop = $bottom - (1.2 + $logoH + 0.8 + $cnpjH + $siteH);

        $namePt = 11.0;
        $nameH = 0.0;
        if ($nome !== '') {
            $namePt = $this->fitLines($nome, 'B', $iw, 2, 11.0, 5.0);
            $this->pdf->SetFont('dejavusans', 'B', $namePt);
            $nameH = $this->blockHeight($iw, $nome);
        }

        $this->pdf->SetFont('dejavusans', 'B', 9);
        $sizeH = $tamanho !== '' ? $this->blockHeight($iw, $tamanho) : 0.0;
        $pacoteH = $pacoteTxt !== '' ? $this->blockHeight($iw, $pacoteTxt) : 0.0;
        $secGap = ($sizeH > 0.0 && $pacoteH > 0.0) ? 0.3 : 0.0;
        $secH = $sizeH + $secGap + $pacoteH;

        $codePt = 9.0;
        if ($codigo !== '') {
            $codeTop = $iy + $nameH + ($nome !== '' ? 1.2 : 0.0);
            $codeMaxH = $footerTop - $codeTop - ($secH > 0.0 ? 2.0 : 0.0) - $secH;
            if ($codeMaxH < 2.5) {
                $codeMaxH = 2.5;
            }
            $codePt = $this->fitHeight($codigo, 'B', $iw, $codeMaxH, 9.0, 5.0);
        }

        $cursor = $iy;
        if ($nome !== '') {
            $this->drawText($nome, $ix, $cursor, $iw, 'B', $namePt, 17, 17, 17);
            $cursor += $nameH + 1.2;
        }
        if ($codigo !== '') {
            $this->pdf->SetFont('dejavusans', 'B', $codePt);
            $codeH = $this->blockHeight($iw, $codigo);
            $this->drawText($codigo, $ix, $cursor, $iw, 'B', $codePt, 17, 17, 17);
            $cursor += $codeH + 2.0;
        }
        if ($tamanho !== '') {
            $this->drawText($tamanho, $ix, $cursor, $iw, 'B', 9.0, 17, 17, 17);
            $cursor += $sizeH + $secGap;
        }
        if ($pacoteTxt !== '') {
            $this->drawText($pacoteTxt, $ix, $cursor, $iw, 'B', 9.0, 17, 17, 17);
        }

        $logoY = $footerTop + 1.2;
        if ($logo !== null) {
            $logoX = $ix + (($iw - $logoW) / 2);
            $this->pdf->Image(
                $logoPath,
                $logoX,
                $logoY,
                $logoW,
                $logoH,
                $logo['type'],
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0,
                false,
                false,
                false
            );
        } elseif ($marca !== '') {
            $this->drawText($marca, $ix, $logoY, $iw, 'B', 6.0, 85, 85, 85);
        }

        $textY = $logoY + $logoH + 0.8;
        $this->drawText($cnpj, $ix, $textY, $iw, '', 6.0, 85, 85, 85);
        if ($site !== '') {
            $this->drawText($site, $ix, $textY + $cnpjH, $iw, '', 6.0, 85, 85, 85);
        }
    }

    /** @return array{w: float, h: float, type: string}|null */
    private function logoSize(string $path): ?array
    {
        if ($this->logoResolved) {
            return $this->logoBox;
        }
        $this->logoResolved = true;

        if ($path === '' || !is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);
        if ($info === false || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }

        $type = match ((string) ($info['mime'] ?? '')) {
            'image/png' => 'PNG',
            'image/jpeg' => 'JPEG',
            default => '',
        };
        if ($type === '') {
            return null;
        }

        $h = self::LOGO_H;
        $w = $h * ((int) $info[0] / (int) $info[1]);
        if ($w > self::LOGO_MAX_W) {
            $w = self::LOGO_MAX_W;
            $h = self::LOGO_MAX_W * ((int) $info[1] / (int) $info[0]);
        }

        $this->logoBox = ['w' => $w, 'h' => $h, 'type' => $type];

        return $this->logoBox;
    }

    private function fitLines(string $text, string $style, float $width, int $maxLines, float $maxPt, float $minPt): float
    {
        $pt = $maxPt;
        while ($pt > $minPt + 0.01) {
            $this->pdf->SetFont('dejavusans', $style, $pt);
            if ($this->pdf->getNumLines($text, $width) <= $maxLines) {
                return $pt;
            }
            $pt -= 0.5;
        }

        $this->pdf->SetFont('dejavusans', $style, $minPt);

        return $minPt;
    }

    private function fitHeight(string $text, string $style, float $width, float $maxHeight, float $maxPt, float $minPt): float
    {
        $pt = $maxPt;
        while ($pt > $minPt + 0.01) {
            $this->pdf->SetFont('dejavusans', $style, $pt);
            if ($this->blockHeight($width, $text) <= $maxHeight + 0.05) {
                return $pt;
            }
            $pt -= 0.5;
        }

        $this->pdf->SetFont('dejavusans', $style, $minPt);

        return $minPt;
    }

    private function blockHeight(float $width, string $text): float
    {
        if ($text === '') {
            return 0.0;
        }

        return (float) $this->pdf->getStringHeight($width, $text, true, false, null, 0);
    }

    private function drawText(
        string $text,
        float $x,
        float $y,
        float $w,
        string $style,
        float $pt,
        int $r,
        int $g,
        int $b,
    ): void {
        $this->pdf->SetFont('dejavusans', $style, $pt);
        $this->pdf->SetTextColor($r, $g, $b);
        $this->pdf->MultiCell($w, 0, $text, 0, 'C', false, 1, $x, $y, true, 0, false, false, 0, 'T', false);
    }
}

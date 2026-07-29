<?php
/**
 * includes/SimplePdf.php
 * ------------------------------------------------------------------
 * A minimal, dependency-free PDF writer used for "Export PDF" buttons
 * throughout the app (Attendance, Reports, Actions, etc). The tech
 * stack is native PHP only (no Composer/FPDF/mPDF), so this hand-rolled
 * writer produces valid PDF 1.4 output using the standard Helvetica
 * core font (no font embedding required) with simple table rendering
 * and automatic page breaks.
 *
 * Usage:
 *   $pdf = new SimplePdf('Report Title', 'Optional subtitle');
 *   $pdf->addTable(['Col A', 'Col B'], [250, 250], $rows);
 *   $pdf->output('report.pdf'); // streams the file for download
 * ------------------------------------------------------------------
 */

class SimplePdf
{
    private array $pages = [];
    private string $buffer = '';
    private float $y;
    private float $pageWidth = 612;   // Letter, points
    private float $pageHeight = 792;
    private float $margin = 36;
    private string $title;
    private string $subtitle;

    public function __construct(string $title = '', string $subtitle = '')
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->newPage();
    }

    /* ---------------------------------------------------------------
     * Low-level page / text primitives
     * --------------------------------------------------------------- */

    private function newPage(): void
    {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }
        $this->buffer = '';
        $this->y = $this->pageHeight - $this->margin;

        if ($this->title !== '') {
            $this->writeText($this->margin, $this->y, $this->title, 14, true);
            $this->y -= 18;
        }
        if ($this->subtitle !== '') {
            $this->writeText($this->margin, $this->y, $this->subtitle, 9, false, [0.4, 0.4, 0.4]);
            $this->y -= 16;
        }
        if ($this->title !== '' || $this->subtitle !== '') {
            $this->drawLine($this->margin, $this->y, $this->pageWidth - $this->margin, $this->y);
            $this->y -= 14;
        }
    }

    private function esc(string $s): string
    {
        // Escape PDF literal string special chars; strip anything outside Latin-1 (core font limitation).
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function writeText(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $font = $bold ? '/F2' : '/F1';
        [$r, $g, $b] = $rgb;
        $this->buffer .= sprintf(
            "q %.2f %.2f %.2f rg BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET Q\n",
            $r, $g, $b, $font, $size, $x, $y, $this->esc($text)
        );
    }

    private function drawLine(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
    {
        $this->buffer .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $y1, $x2, $y2);
    }

    private function drawFilledRect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $this->buffer .= sprintf("q %.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f Q\n", $r, $g, $b, $x, $y, $w, $h);
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < $this->margin) {
            $this->newPage();
        }
    }

    /** Rough character truncation so text fits a given column width at a given font size. */
    private function fit(string $text, float $colWidth, float $size): string
    {
        $avgCharWidth = $size * 0.52;
        $maxChars = max(3, (int)floor(($colWidth - 6) / $avgCharWidth));
        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars - 1) . '…' : $text;
    }

    /* ---------------------------------------------------------------
     * Public API
     * --------------------------------------------------------------- */

    /** Add a free-text paragraph line (auto page-break). */
    public function addLine(string $text, float $size = 10, bool $bold = false): void
    {
        $this->ensureSpace(16);
        $this->writeText($this->margin, $this->y, $text, $size, $bold);
        $this->y -= ($size + 6);
    }

    public function addSpacer(float $height = 10): void
    {
        $this->y -= $height;
    }

    /**
     * Render a simple bordered table with a header row.
     * @param string[] $headers
     * @param float[]  $colWidths  in points, should sum to <= (pageWidth - 2*margin)
     * @param array[]  $rows       each row is an array of string cell values, same count as $headers
     */
    public function addTable(array $headers, array $colWidths, array $rows): void
    {
        $rowHeight = 18;
        $fontSize = 8.5;

        $this->ensureSpace($rowHeight * 2);
        $this->drawTableHeader($headers, $colWidths, $rowHeight, $fontSize);

        foreach ($rows as $row) {
            $this->ensureSpace($rowHeight);
            // If a page break just happened, redraw the header for readability.
            if ($this->y > $this->pageHeight - $this->margin - 1) {
                $this->drawTableHeader($headers, $colWidths, $rowHeight, $fontSize);
            }
            $x = $this->margin;
            foreach ($row as $i => $cell) {
                $w = $colWidths[$i] ?? 80;
                $this->writeText($x + 3, $this->y - 13, $this->fit((string)$cell, $w, $fontSize), $fontSize);
                $x += $w;
            }
            $this->drawLine($this->margin, $this->y - $rowHeight, array_sum($colWidths) + $this->margin, $this->y - $rowHeight, 0.3);
            $this->y -= $rowHeight;
        }
        $this->addSpacer(10);
    }

    private function drawTableHeader(array $headers, array $colWidths, float $rowHeight, float $fontSize): void
    {
        $tableWidth = array_sum($colWidths);
        $this->drawFilledRect($this->margin, $this->y - $rowHeight, $tableWidth, $rowHeight, [0.94, 0.96, 0.98]);
        $x = $this->margin;
        foreach ($headers as $i => $h) {
            $w = $colWidths[$i] ?? 80;
            $this->writeText($x + 3, $this->y - 13, $this->fit((string)$h, $w, $fontSize), $fontSize, true);
            $x += $w;
        }
        $this->drawLine($this->margin, $this->y - $rowHeight, $this->margin + $tableWidth, $this->y - $rowHeight, 0.5);
        $this->y -= $rowHeight;
    }

    /* ---------------------------------------------------------------
     * PDF assembly / output
     * --------------------------------------------------------------- */

    private function build(): string
    {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }
        if (empty($this->pages)) {
            $this->pages[] = '';
        }

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $pageCount = count($this->pages);
        $kids = [];
        $objNum = 4; // 1=Catalog, 2=Pages, 3=Font1, 4.. = Font2/pages/content interleaved below

        // Fonts
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        $nextObj = 5;
        $pageObjNums = [];
        $contentObjNums = [];

        foreach ($this->pages as $i => $content) {
            $pageObjNums[$i] = $nextObj++;
            $contentObjNums[$i] = $nextObj++;
        }

        foreach ($this->pages as $i => $content) {
            $pageObj = $pageObjNums[$i];
            $contentObj = $contentObjNums[$i];
            $objects[$pageObj] =
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] " .
                "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObj} 0 R >>";
            $stream = $content;
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        }

        $kidsRefs = implode(' ', array_map(fn($n) => "{$n} 0 R", $pageObjNums));
        $objects[2] = "<< /Type /Pages /Kids [{$kidsRefs}] /Count {$pageCount} >>";

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObj; $n++) {
            if (isset($offsets[$n])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }

    /** Stream the generated PDF to the browser as a download. */
    public function output(string $filename = 'document.pdf'): void
    {
        $data = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $data;
        exit;
    }
}

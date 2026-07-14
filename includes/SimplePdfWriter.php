<?php

// includes/SimplePdfWriter.php

class SimplePdfWriter
{
    private $title;
    private $headers = [];
    private $rows = [];
    private $fileName;

    // PDF Builder state
    private $buffer = '';
    private $offsets = [];
    private $pageObjects = [];

    // Page dimensions — A4 Landscape
    private $pageWidth  = 842;
    private $pageHeight = 595;

    public function __construct($title, $fileName = 'export.pdf')
    {
        $this->title    = $title;
        $this->fileName = $fileName;
    }

    public function setHeaders(array $headers)
    {
        $this->headers = $headers;
    }

    public function addRow(array $row)
    {
        $this->rows[] = $row;
    }

    /** Compile PDF and stream download (attachment) */
    public function download()
    {
        $this->stream('attachment');
    }

    /** Compile PDF and render inline in browser */
    public function inline()
    {
        $this->stream('inline');
    }

    /** Internal: stream PDF with given disposition */
    private function stream($disposition = 'attachment')
    {
        $pdfData = $this->buildPdf();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $this->fileName . '"');
        header('Content-Length: ' . strlen($pdfData));
        header('Cache-Control: max-age=0');
        echo $pdfData;
        exit;
    }

    // ── Abbreviation map for long header labels ─────────────────────────
    private function abbreviateHeader($label)
    {
        $map = [
            'Upper Jersey Size'         => 'Upper Jersey',
            'Lower Jersey Size'         => 'Lower Jersey',
            'Helmet Size'               => 'Helmet',
            'Pad Size'                  => 'Pad',
            'Batting Hand'              => 'Bat Hand',
            'Half Sleeve Qty'           => 'Half Slv',
            'Full Sleeve Qty'           => 'Full Slv',
            'Jersey Name'               => 'Jsy Name',
            'Jersey Number (Option 1)'  => 'Jsy# 1',
            'Jersey Number (Option 2)'  => 'Jsy# 2',
            'Jersey Number (Option 3)'  => 'Jsy# 3',
            'Jersey Number'             => 'Jsy#',
            'Mobile Number'             => 'Mobile',
            'Player ID'                 => 'PID',
            'Initials'                  => 'Init.',
            'Socks Size'                => 'Socks',
            'Shorts Size'               => 'Shorts',
            'Trouser Size'              => 'Trouser',
            'Chest Size'                => 'Chest',
        ];
        return $map[$label] ?? (strlen($label) > 10 ? substr($label, 0, 9) . '.' : $label);
    }

    /** Truncate cell value to a max char count */
    private function truncate($text, $max = 12)
    {
        $text = (string)$text;
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** Main PDF assembler */
    private function buildPdf()
    {
        $this->buffer = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

        // Object 1: Catalog
        $this->startObject(1);
        $this->write("<< /Type /Catalog /Pages 2 0 R >>");
        $this->endObject();

        // Object 3: Regular font
        $this->startObject(3);
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
        $this->endObject();

        // Object 4: Bold font
        $this->startObject(4);
        $this->write("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
        $this->endObject();

        // ── Layout constants ─────────────────────────────────────────────
        $W          = $this->pageWidth;
        $H          = $this->pageHeight;
        $lm         = 30;   // left margin
        $rm         = 30;   // right margin
        $tm         = 50;   // top margin
        $bm         = 30;   // bottom margin
        $usableW    = $W - $lm - $rm;
        $rowH       = 18;
        $hdrH       = 22;
        $titleH     = 28;   // space consumed by title block

        $numCols    = count($this->headers);
        $colW       = $usableW / max(1, $numCols);

        // Font sizes scale down when many columns
        $hFontSz    = max(6, min(8, floor(55 / max(1, $numCols))));
        $dFontSz    = max(6, min(8, floor(55 / max(1, $numCols))));

        // Rows per page (title only on page 1)
        $usableHPage1 = $H - $tm - $bm - $titleH - $hdrH;
        $usableHOther = $H - $tm - $bm - $hdrH;
        $rpp1   = max(1, floor($usableHPage1 / $rowH));
        $rppN   = max(1, floor($usableHOther / $rowH));

        // Chunk rows across pages
        $pages = [];
        $remaining = $this->rows;
        $firstChunk = array_splice($remaining, 0, $rpp1);
        $pages[] = $firstChunk;
        while (!empty($remaining)) {
            $pages[] = array_splice($remaining, 0, $rppN);
        }
        if (empty($pages)) {
            $pages = [[]];
        }

        $totalPages = count($pages);
        $nextObjId  = 5;

        foreach ($pages as $pgIdx => $pageRows) {
            $pgObjId     = $nextObjId++;
            $strmObjId   = $nextObjId++;
            $this->pageObjects[] = $pgObjId;

            $s = ''; // stream content

            $y = $H - $tm;

            // ── Title block (page 1 only) ─────────────────────────────
            if ($pgIdx === 0) {
                // Red half-line
                $s .= "0.88 0.11 0.28 RG\n3 w\n";
                $s .= "$lm " . ($y + 14) . " m " . ($lm + $usableW / 2) . " " . ($y + 14) . " l S\n";
                // Blue half-line
                $s .= "0.0 0.40 1.00 RG\n";
                $s .= ($lm + $usableW / 2) . " " . ($y + 14) . " m " . ($W - $rm) . " " . ($y + 14) . " l S\n";

                // Title text
                $s .= "BT\n/F2 14 Tf\n0.95 0.95 1.0 rg\n$lm $y Td\n(" . $this->escapeText($this->title) . ") Tj\nET\n";
                // Date
                $s .= "BT\n/F1 7 Tf\n0.6 0.6 0.7 rg\n" . ($W - $rm - 130) . " $y Td\n(Exported: " . date('Y-m-d H:i:s') . ") Tj\nET\n";
                $y -= $titleH;
            }

            // ── Table header row ─────────────────────────────────────
            // Dark navy background
            $s .= "0.04 0.08 0.16 rg\n";
            $s .= "$lm " . ($y - $hdrH) . " $usableW $hdrH re f\n";

            // Header text
            $s .= "BT\n/F2 {$hFontSz} Tf\n1.0 1.0 1.0 rg\n";

            // Player Name column header
            $abbrevHeaders   = ['Player Name'];
            $abbrevHeaders[] = ''; // placeholder — built below
            for ($i = 0; $i < $numCols; $i++) {
                $ah = $this->abbreviateHeader($this->headers[$i]);
                $cx = $lm + ($i * $colW) + 3;
                $cy = $y - $hdrH + 7;
                $s .= "$cx $cy Td\n(" . $this->escapeText($ah) . ") Tj\n" . (-$cx) . " " . (-$cy) . " Td\n";
            }
            $s .= "ET\n";
            $y -= $hdrH;

            // ── Data rows ──────────────────────────────────────────────
            foreach ($pageRows as $rIdx => $row) {
                if ($rIdx % 2 === 1) {
                    $s .= "0.04 0.08 0.16 rg\n";
                    $s .= "$lm " . ($y - $rowH) . " $usableW $rowH re f\n";
                }
                // Row bottom border
                $s .= "0.12 0.23 0.40 RG\n0.3 w\n";
                $s .= "$lm " . ($y - $rowH) . " m " . ($W - $rm) . " " . ($y - $rowH) . " l S\n";

                // Cell values
                $s .= "BT\n/F1 {$dFontSz} Tf\n0.95 0.95 1.0 rg\n";
                for ($ci = 0; $ci < $numCols && $ci < count($row); $ci++) {
                    $cellText = $this->truncate((string)($row[$ci] ?? ''), 14);
                    $cx = $lm + ($ci * $colW) + 3;
                    $cy = $y - $rowH + 5;
                    $s .= "$cx $cy Td\n(" . $this->escapeText($cellText) . ") Tj\n" . (-$cx) . " " . (-$cy) . " Td\n";
                }
                $s .= "ET\n";
                $y -= $rowH;
            }

            // Table outer border
            $topY = $H - $tm - ($pgIdx === 0 ? $titleH : 0) - $hdrH;
            $s .= "0.12 0.23 0.40 RG\n0.8 w\n";
            $s .= "$lm $topY m $lm $y l S\n";
            $s .= ($W - $rm) . " $topY m " . ($W - $rm) . " $y l S\n";

            // Footer
            $s .= "BT\n/F1 7 Tf\n0.5 0.5 0.6 rg\n" . ($W / 2 - 20) . " $bm Td\n(Page " . ($pgIdx + 1) . " of $totalPages) Tj\nET\n";

            // Write stream object
            $this->startObject($strmObjId);
            $this->write("<< /Length " . strlen($s) . " >>");
            $this->write("stream");
            $this->write($s);
            $this->write("endstream");
            $this->endObject();

            // Write page object (landscape MediaBox)
            $this->startObject($pgObjId);
            $this->write("<< /Type /Page");
            $this->write("   /Parent 2 0 R");
            $this->write("   /MediaBox [0 0 $W $H]");
            $this->write("   /Contents $strmObjId 0 R");
            $this->write("   /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>");
            $this->write(">>");
            $this->endObject();
        }

        // Object 2: Pages container
        $this->startObject(2);
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $this->pageObjects));
        $this->write("<< /Type /Pages /Kids [$kids] /Count " . count($this->pageObjects) . " >>");
        $this->endObject();

        // xref table
        $xrefOffset = strlen($this->buffer);
        $this->write("xref");
        $this->write("0 $nextObjId");
        $this->write("0000000000 65535 f ");
        for ($i = 1; $i < $nextObjId; $i++) {
            $this->write(sprintf("%010d 00000 n ", $this->offsets[$i]));
        }

        // Trailer
        $this->write("trailer");
        $this->write("<< /Size $nextObjId /Root 1 0 R >>");
        $this->write("startxref");
        $this->write($xrefOffset);
        $this->write("%%EOF");

        return $this->buffer;
    }

    private function startObject($id)
    {
        $this->offsets[$id] = strlen($this->buffer);
        $this->buffer .= "$id 0 obj\n";
    }

    private function endObject()
    {
        $this->buffer .= "endobj\n";
    }

    private function write($data)
    {
        $this->buffer .= $data . "\n";
    }

    /** Escape special PDF string characters */
    private function escapeText($text)
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$text);
        return preg_replace('/[^\x20-\x7E]/', ' ', $text);
    }
}

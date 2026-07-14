<?php

// includes/SimpleXlsxWriter.php

class SimpleXlsxWriter
{
    private $rows = [];
    private $fileName;

    public function __construct($fileName = 'export.xlsx')
    {
        $this->fileName = $fileName;
    }

    /**
     * Add a row of data
     */
    public function addRow(array $row)
    {
        $this->rows[] = $row;
    }

    /**
     * Add multiple rows
     */
    public function addRows(array $rows)
    {
        foreach ($rows as $row) {
            $this->addRow($row);
        }
    }

    /**
     * Generate the XLSX binary string and send download headers
     */
    public function download()
    {
        $xlsxData = $this->buildXlsx();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $this->fileName . '"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');

        echo $xlsxData;
        exit;
    }

    /**
     * Build the OpenXML folder zip structure manually
     */
    private function buildXlsx()
    {
        $zip = new SimpleZipArchive();

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/sheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '</Types>';
        $zip->addFile('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFile('_rels/.rels', $rels);

        // 3. xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Sheet1" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
        $zip->addFile('xl/workbook.xml', $workbook);

        // 4. xl/_rels/workbook.xml.rels
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="sheets/sheet1.xml"/>' .
            '</Relationships>';
        $zip->addFile('xl/_rels/workbook.xml.rels', $workbookRels);

        // 5. xl/sheets/sheet1.xml (Cell data)
        $sheetData = '';
        $rowIndex = 1;
        foreach ($this->rows as $row) {
            $sheetData .= '<row r="' . $rowIndex . '">';
            $colIndex = 0;
            foreach ($row as $val) {
                $cellRef = $this->getColumnLetter($colIndex) . $rowIndex;
                // Escape special characters for XML
                $cleanVal = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');

                // Determine type: numeric or inline string
                if (is_numeric($val) && !preg_match('/^0[0-9]+/', $val)) {
                    $sheetData .= '<c r="' . $cellRef . '"><v>' . $cleanVal . '</v></c>';
                } else {
                    $sheetData .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $cleanVal . '</t></is></c>';
                }
                $colIndex++;
            }
            $sheetData .= '</row>';
            $rowIndex++;
        }

        $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>' . $sheetData . '</sheetData>' .
            '</worksheet>';
        $zip->addFile('xl/sheets/sheet1.xml', $sheet1);

        return $zip->getArchiveData();
    }

    /**
     * Map numeric index to Excel column letter (e.g. 0 -> A, 27 -> AB)
     */
    private function getColumnLetter($index)
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(($index % 26) + 65) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }
}

/**
 * Custom lightweight ZIP archiver constructed manually using binary structures.
 * Bypasses need for php-zip extension. Uses compression method 0 (store/uncompressed)
 * for standard compatibility across budget servers.
 */
class SimpleZipArchive
{
    private $files = [];

    public function addFile($name, $data)
    {
        $this->files[] = [
            'name' => $name,
            'data' => $data,
            'crc'  => crc32($data),
            'size' => strlen($data)
        ];
    }

    public function getArchiveData()
    {
        $zip = '';
        $offset = 0;
        $cd = '';

        foreach ($this->files as $file) {
            $nameLen = strlen($file['name']);
            $dataLen = $file['size'];

            // Local file header: 30 bytes fixed
            // V=4: sig | v=2: ver | v=2: flag | v=2: comp | v=2: time | v=2: date
            // V=4: crc | V=4: comp_sz | V=4: uncomp_sz | v=2: name_len | v=2: extra_len
            $lfh = pack(
                'VvvvvvVVVvv',
                0x04034b50, // local file header signature
                20,         // version needed to extract
                0,          // general purpose bit flag
                0,          // compression method (0=store)
                0,          // last mod file time
                0,          // last mod file date
                $file['crc'],
                $dataLen,   // compressed size   (4 bytes)
                $dataLen,   // uncompressed size (4 bytes)
                $nameLen,   // file name length
                0           // extra field length
            ) . $file['name'];

            $zip .= $lfh . $file['data'];

            // Central directory header: 46 bytes fixed
            // V=4: sig | v=2: ver_by | v=2: ver_need | v=2: flag | v=2: comp
            // v=2: time | v=2: date | V=4: crc | V=4: comp_sz | V=4: uncomp_sz
            // v=2: name_len | v=2: extra_len | v=2: comment_len
            // v=2: disk_start | v=2: int_attr | V=4: ext_attr | V=4: offset
            $cdh = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, // central directory signature
                0x0014,     // version made by
                0x0014,     // version needed to extract
                0,          // general purpose bit flag
                0,          // compression method
                0,          // last mod file time
                0,          // last mod file date
                $file['crc'],
                $dataLen,   // compressed size   (4 bytes)
                $dataLen,   // uncompressed size (4 bytes)
                $nameLen,   // file name length
                0,          // extra field length
                0,          // file comment length
                0,          // disk number start
                0,          // internal file attributes
                0,          // external file attributes (4 bytes)
                $offset     // relative offset of local header
            ) . $file['name'];

            $cd .= $cdh;
            $offset += strlen($lfh) + $dataLen;
        }

        $cdLen = strlen($cd);

        // End of central directory record
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50, // EOCD signature
            0,          // number of this disk
            0,          // disk with start of central directory
            count($this->files), // entries on this disk
            count($this->files), // total entries
            $cdLen,     // size of central directory
            $offset,    // offset of start of central directory
            0           // comment length
        );

        return $zip . $cd . $eocd;
    }
}

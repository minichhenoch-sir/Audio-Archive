<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

/**
 * Kleine Excel-Datei (.xlsx) ohne Fremdbibliothek (ab 0.36.0, Vikunja #5):
 * eine Tabelle mit fetter, fixierter Kopfzeile, Filter, Spaltenbreiten und
 * Zeilenumbruch. Reicht fuer Excel, LibreOffice und die Office-Programme in
 * Nextcloud. Gebaut mit ZipArchive (PHP-Erweiterung "zip", fuer Nextcloud
 * ohnehin Pflicht).
 */
class XlsxWriter {

    /**
     * @param list<string> $header
     * @param list<list<string|int|null>> $rows
     * @param list<int> $widths Spaltenbreiten in Zeichen
     * @return string Inhalt der .xlsx-Datei
     */
    public function build(string $sheetName, array $header, array $rows, array $widths = []): string {
        $tmp = tempnam(sys_get_temp_dir(), 'aa-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Keine Zwischendatei');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Zip nicht moeglich');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . self::esc("'" . str_replace("'", "''", mb_substr($sheetName, 0, 31)) . "'") . '!$A$1:$' . self::col(count($header) - 1) . '$' . (count($rows) + 1)
            . '</definedName></definedNames>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Stil 0: normal, 1: Kopfzeile fett mit grauem Grund, 2: oben ausgerichtet mit Umbruch
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8E8E8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int)$w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>' . self::row(1, $header, 1);
        foreach ($rows as $i => $row) {
            $xml .= self::row($i + 2, $row, 2);
        }
        $xml .= '</sheetData>'
            . '<autoFilter ref="A1:' . self::col(count($header) - 1) . (count($rows) + 1) . '"/>'
            . '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $data = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /** @param list<string|int|null> $cells */
    private static function row(int $r, array $cells, int $style): string {
        $xml = '<row r="' . $r . '">';
        foreach (array_values($cells) as $c => $value) {
            $ref = self::col($c) . $r;
            if (is_int($value)) {
                $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            } elseif ($value !== null && $value !== '') {
                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$value) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    /** Spaltenbuchstabe: 0 -> A, 25 -> Z, 26 -> AA */
    private static function col(int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function esc(string $s): string {
        // Steuerzeichen, die XML nicht erlaubt, entfernen
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

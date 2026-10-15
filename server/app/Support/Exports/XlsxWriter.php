<?php

namespace App\Support\Exports;

use RuntimeException;
use ZipArchive;

/**
 * A minimal Excel (.xlsx) writer: one worksheet per sheet, a bold header row, numbers as numbers, text as inline strings.
 * No library needed — an .xlsx file is a zip of a few XML files. Opens in Excel, Google Sheets, LibreOffice and Numbers.
 */
final class XlsxWriter
{
    /** @param list<array{title: string, headings: list<string>, rows: iterable<list<scalar|null>>}> $sheets */
    public static function bytes(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }
        $names = [];
        foreach (array_values($sheets) as $i => $sheet) {
            $name = self::sheetName($sheet['title'] ?? 'Sheet', $names);
            $names[] = $name;
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::worksheet($sheet));
        }
        $n = count($names);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .implode('', array_map(fn ($i) => '<sheet name="'.self::esc($names[$i - 1]).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>', range(1, $n))).'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', range(1, $n)))
            .'<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private static function worksheet(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $r = 1;
        $xml .= self::row($r++, $sheet['headings'], true);
        foreach ($sheet['rows'] as $row) {
            $xml .= self::row($r++, array_values($row), false);
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function row(int $r, array $cells, bool $bold): string
    {
        $out = '<row r="'.$r.'">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i).$r;
            $style = $bold ? ' s="1"' : '';
            if ($v === null || $v === '') {
                continue;
            }
            if (! $bold && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,11}(\.\d+)?$/', $v) && ! preg_match('/^0\d/', $v)))) {
                $out .= '<c r="'.$ref.'"'.$style.'><v>'.$v.'</v></c>'; // leading zeros (phone numbers, codes) stay text
            } else {
                $out .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::esc(is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v).'</t></is></c>';
            }
        }

        return $out.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function sheetName(string $title, array $taken): string
    {
        $name = mb_substr(trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title)) ?: 'Sheet', 0, 28);
        $base = $name;
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = mb_substr($base, 0, 25)." {$i}";
        }

        return $name;
    }

    private static function esc(string $s): string
    {
        // Strip characters XML 1.0 cannot hold, then escape.
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

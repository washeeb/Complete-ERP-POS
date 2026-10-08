<?php

namespace App\Domain\Imports;

use App\Domain\Support\DomainException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads an import file: CSV (any delimiter or encoding, see CsvFile) or Excel (.xlsx, the first worksheet). Headings can
 * be matched to the expected columns by hand (the import wizard's "match columns" step) when the file uses its own names.
 */
final class SheetFile
{
    /**
     * @param  array<string, string>  $mapping  expected column => heading in the file (both as CsvFile::key)
     * @return array{headings: list<string>, rows: list<array{line: int, values: array<string, string>}>}
     */
    public static function read(string $path, string $fileName, array $mapping = []): array
    {
        $data = str_ends_with(strtolower($fileName), '.xlsx') ? self::xlsx($path) : CsvFile::read($path);
        $mapping = array_filter($mapping, fn ($from, $to) => $from !== '' && $from !== $to, ARRAY_FILTER_USE_BOTH);
        if (! $mapping) {
            return $data;
        }
        $moved = array_diff(array_values($mapping), array_keys($mapping)); // a matched column is read under its new name only
        foreach ($data['rows'] as &$row) {
            $values = $row['values'];
            foreach ($mapping as $to => $from) {
                if (array_key_exists($from, $values)) {
                    $row['values'][$to] = $values[$from];
                }
            }
            foreach ($moved as $from) {
                unset($row['values'][$from]);
            }
        }
        unset($row);
        $data['headings'] = array_values(array_unique(array_merge(array_diff($data['headings'], $moved), array_keys($mapping))));

        return $data;
    }

    /** The first worksheet of an Excel workbook, the same shape as a CSV. */
    public static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new DomainException('This is not an Excel (.xlsx) file, or it is damaged. Save it again from Excel, or as CSV.');
        }
        try {
            $strings = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach (self::xml($xml)->si as $si) {
                    $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
                }
            }
            $sheet = self::firstSheetPath($zip);
            $xml = $zip->getFromName($sheet);
            if ($xml === false) {
                throw new DomainException('The workbook has no worksheet.');
            }
            $headings = null;
            $rows = [];
            foreach (self::xml($xml)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $col = self::colIndex(preg_replace('/\d+/', '', (string) $c['r']));
                    $type = (string) $c['t'];
                    $v = match ($type) {
                        's' => $strings[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    };
                    $cells[$col] = trim($v);
                }
                if (! array_filter($cells, fn ($v) => $v !== '')) {
                    continue;
                }
                $line = (int) $row['r'];
                if ($headings === null) {
                    $headings = [];
                    foreach ($cells as $i => $h) {
                        $headings[$i] = CsvFile::key($h);
                    }

                    continue;
                }
                if (count($rows) >= CsvFile::MAX_ROWS) {
                    throw new DomainException('A file can have at most '.number_format(CsvFile::MAX_ROWS).' rows. Split it into smaller files.');
                }
                $values = [];
                foreach ($headings as $i => $h) {
                    if ($h !== '') {
                        $values[$h] = $cells[$i] ?? '';
                    }
                }
                $rows[] = ['line' => $line, 'values' => $values];
            }
            if ($headings === null) {
                throw new DomainException('The worksheet has no heading row.');
            }

            return ['headings' => array_values(array_filter($headings)), 'rows' => $rows];
        } finally {
            $zip->close();
        }
    }

    /** "A" → 0, "AA" → 26 */
    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $book = self::xml($wb);
        $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $book->xpath('//m:sheets/m:sheet')[0] ?? null;
        $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
        foreach (self::xml($rels)->Relationship as $r) {
            if ((string) $r['Id'] === $rid) {
                $target = ltrim((string) $r['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($doc === false) {
            throw new DomainException('The Excel file could not be read. Save it again, or as CSV.');
        }

        return $doc;
    }
}

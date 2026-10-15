<?php

namespace App\Domain\Imports;

use App\Domain\Support\DomainException;

/**
 * Reads a CSV file the way people make them: saved from Excel, Google Sheets or LibreOffice, with commas, semicolons or
 * tabs, in UTF-8 or the Windows encoding, with or without a byte-order mark. Headings are matched loosely
 * ("Unit cost", "unit_cost", "UNIT COST*" are the same column); empty rows are skipped.
 */
final class CsvFile
{
    public const MAX_ROWS = 20000;

    /** @return array{headings: list<string>, rows: list<array{line: int, values: array<string, string>}>} */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new DomainException('The file is empty or could not be read.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // Excel's "CSV" on Windows
        }
        $first = strtok($raw, "\r\n");
        $delimiter = collect([',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $headings = null;
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            if ($headings === null) {
                $headings = array_map([self::class, 'key'], $cells);

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new DomainException('A file can have at most '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.');
            }
            $values = [];
            foreach ($headings as $i => $h) {
                if ($h !== '') {
                    $values[$h] = self::unformula(trim((string) ($cells[$i] ?? '')));
                }
            }
            $rows[] = ['line' => $line, 'values' => $values];
        }
        fclose($stream);
        if ($headings === null) {
            throw new DomainException('The file has no heading row.');
        }

        return ['headings' => array_values(array_filter($headings)), 'rows' => $rows];
    }

    /** "Unit cost (GHS)*" → "unit_cost_ghs" */
    public static function key(?string $heading): string
    {
        $h = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading)));
        $h = preg_replace('/[^a-z0-9]+/', '_', str_replace(['*', '(', ')'], ' ', $h));

        return trim($h, '_');
    }

    /** ="0123" (a CSV cell Excel keeps as text, leading zeros and all) → 0123. */
    public static function unformula(string $v): string
    {
        return preg_match('/^="(.*)"$/s', $v, $m) ? str_replace('""', '"', $m[1]) : $v;
    }
}

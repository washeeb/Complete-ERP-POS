<?php

namespace App\Domain\Banking;

use App\Domain\Support\DomainException;
use Illuminate\Support\Carbon;

/**
 * Reads a bank or MoMo statement exported as CSV (owner decision 2026-10-13: CSV now, bank APIs later — a feed
 * from an API would produce the same rows). The mapping names the columns once per account and is remembered:
 *
 *   delimiter (auto), header_rows (1), date, date_format (auto | d/m/Y | Y-m-d | m/d/Y | d-M-Y …), description,
 *   reference, amount (signed) or money_in + money_out, balance — a column is its header text or its 1-based number.
 */
final class StatementCsv
{
    public const DATE_FORMATS = ['auto' => 'Work it out', 'd/m/Y' => '31/12/2026', 'd-m-Y' => '31-12-2026', 'Y-m-d' => '2026-12-31', 'm/d/Y' => '12/31/2026',
        'd-M-Y' => '31-Dec-2026', 'd M Y' => '31 Dec 2026', 'd/m/y' => '31/12/26'];

    /** The header row and first data rows, to set up the mapping. */
    public static function preview(string $contents, int $rows = 5): array
    {
        [$table] = self::table($contents, ['delimiter' => null]);

        return array_slice($table, 0, $rows + 1);
    }

    /**
     * @return list<array{line_date: string, description: ?string, reference: ?string, amount_minor: int, balance_minor: ?int}>
     */
    public static function parse(string $contents, array $mapping): array
    {
        [$table] = self::table($contents, $mapping);
        $skip = (int) ($mapping['header_rows'] ?? 1);
        $header = $skip > 0 ? array_map(fn ($h) => mb_strtolower(trim((string) $h)), $table[$skip - 1] ?? []) : [];
        $col = function (?string $ref) use ($header): ?int {
            $ref = trim((string) $ref);
            if ($ref === '') {
                return null;
            }
            if (ctype_digit($ref)) {
                return (int) $ref - 1;
            }
            $i = array_search(mb_strtolower($ref), $header, true);

            return $i === false ? throw new DomainException("The statement has no column “{$ref}”.") : (int) $i;
        };
        $c = ['date' => $col($mapping['date'] ?? null), 'description' => $col($mapping['description'] ?? null), 'reference' => $col($mapping['reference'] ?? null),
            'amount' => $col($mapping['amount'] ?? null), 'in' => $col($mapping['money_in'] ?? null), 'out' => $col($mapping['money_out'] ?? null),
            'balance' => $col($mapping['balance'] ?? null)];
        if ($c['date'] === null || ($c['amount'] === null && $c['in'] === null && $c['out'] === null)) {
            throw new DomainException('Choose the date column and either the amount column or the money in / money out columns.');
        }

        $out = [];
        foreach (array_slice($table, $skip) as $n => $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $cell = fn (?int $i) => $i === null ? null : trim((string) ($row[$i] ?? ''));
            $rawDate = $cell($c['date']);
            if ($rawDate === '' || ! preg_match('/\d/', $rawDate)) {
                continue; // totals or notes at the bottom
            }
            $date = self::date($rawDate, $mapping['date_format'] ?? 'auto') ?? throw new DomainException('Row '.($n + $skip + 1).": “{$rawDate}” is not a date in the chosen format.");
            $amount = $c['amount'] !== null ? self::money($cell($c['amount']))
                : (self::money($cell($c['in'])) ?? 0) - abs(self::money($cell($c['out'])) ?? 0);
            if ($amount === null || $amount === 0) {
                continue;
            }
            $out[] = ['line_date' => $date, 'description' => mb_substr((string) $cell($c['description']), 0, 255) ?: null,
                'reference' => mb_substr((string) $cell($c['reference']), 0, 120) ?: null, 'amount_minor' => $amount,
                'balance_minor' => $c['balance'] !== null ? self::money($cell($c['balance'])) : null];
        }

        return $out;
    }

    /** "1,234.50", "GHS 1 234.50", "(12.00)", "12.00 DR", "-12" → minor units; blank → null. */
    public static function money(?string $v): ?int
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return null;
        }
        $negative = str_starts_with($v, '(') || str_starts_with($v, '-') || preg_match('/\bDR\b/i', $v) === 1;
        $digits = preg_replace('/[^0-9.]/', '', $v);
        if ($digits === '' || ! preg_match('/^\d*(\.\d+)?$/', $digits)) {
            throw new DomainException("“{$v}” is not an amount.");
        }
        [$whole, $frac] = array_pad(explode('.', $digits), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');

        return $negative ? -$minor : $minor;
    }

    private static function date(string $v, string $format): ?string
    {
        $v = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[AP]M)?$/i', '', trim($v)); // drop a time of day
        $formats = $format === 'auto' ? ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd-M-Y', 'd M Y', 'd/m/y', 'd.m.Y'] : [$format];
        foreach ($formats as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $v);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($d && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && (int) $d->format('Y') > 1990) {
                return Carbon::instance($d)->toDateString();
            }
        }

        return null;
    }

    /** @return array{0: list<list<string>>, 1: string} rows and the delimiter used */
    private static function table(string $contents, array $mapping): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents); // Excel's byte-order mark
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        $delimiter = $mapping['delimiter'] ?? null;
        if (! $delimiter) {
            $first = $lines[0] ?? '';
            $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter, '"', '');
        }

        return [$rows, $delimiter];
    }
}

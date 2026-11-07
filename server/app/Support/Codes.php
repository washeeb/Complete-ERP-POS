<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Codes the system gives (owner 2026-11-07: "automate them once and for all"): a short form of the name, or the next
 * number in a series. Codes that stand for something chosen on purpose (account numbers, shelf bins, table numbers,
 * units) stay typed.
 */
final class Codes
{
    /**
     * A free code from a name. One word keeps its start (Bakery → BAKERY); several share the room (Pharmacy Osu →
     * PHAOSU). With $glue the words stay apart (Staff price → STAFF_PRICE), for codes that name file columns. If the
     * code is taken, a number replaces its end (BAKER2, STAFF_PRICE_2).
     *
     * @param  iterable<string>  $taken
     */
    public static function fromName(string $name, iterable $taken, int $len, string $glue = '', string $fallback = 'X'): string
    {
        $words = array_values(array_filter(array_map(fn ($w) => (string) preg_replace('/[^A-Z0-9]/', '', $w),
            preg_split('/[\s\/&,.-]+/', strtoupper(Str::ascii($name))) ?: [])));
        if ($glue !== '') {
            $stem = substr(implode($glue, $words), 0, $len);
            $stem = rtrim($stem, $glue);
        } elseif (strlen(implode('', $words)) <= $len || count($words) < 2) {
            $stem = substr(implode('', $words), 0, $len);
        } else {
            $parts = array_slice($words, 0, min(count($words), $len));
            $each = intdiv($len, count($parts));
            $stem = implode('', array_map(fn ($w) => substr($w, 0, $each), $parts));
        }

        return self::free($stem !== '' ? $stem : $fallback, $taken, $len, $glue);
    }

    /**
     * The next number in a series: T01, T02… after the highest used (gaps are not reused, so a code never comes back).
     *
     * @param  iterable<string>  $taken
     */
    public static function numbered(string $prefix, iterable $taken, int $digits = 2): string
    {
        $max = 0;
        foreach ($taken as $code) {
            if (preg_match('/^'.preg_quote(strtoupper($prefix), '/').'(\d+)$/', strtoupper((string) $code), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return strtoupper($prefix).str_pad((string) ($max + 1), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * For codes people type (shelf bins, tables, account numbers): the one after the highest, with its last number plus
     * one at the same width (A-01-02 → A-01-03, 1010 → 1011). Only a suggestion: null when no code ends in a number.
     *
     * @param  iterable<string>  $taken
     */
    public static function after(iterable $taken): ?string
    {
        $codes = array_values(array_filter(array_map('strval', is_array($taken) ? $taken : iterator_to_array($taken, false)), fn ($c) => preg_match('/\d$/', $c)));
        if ($codes === []) {
            return null;
        }
        usort($codes, 'strnatcasecmp');
        $last = end($codes);
        preg_match('/^(.*?)(\d+)$/', $last, $m);

        return $m[1].str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
    }

    /** @param iterable<string> $taken */
    private static function free(string $stem, iterable $taken, int $len, string $glue): string
    {
        $used = [];
        foreach ($taken as $code) {
            $used[strtoupper((string) $code)] = true;
        }
        $code = $stem;
        for ($n = 2; isset($used[$code]); $n++) {
            $end = $glue.$n;
            $code = rtrim(substr($stem, 0, $len - strlen($end)), $glue).$end;
        }

        return $code;
    }
}

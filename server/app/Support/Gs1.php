<?php

namespace App\Support;

/**
 * The GS1 code on a pack (the square DataMatrix on medicine packs, or a GS1-128 barcode): product number (GTIN), batch,
 * expiry and serial in one scan, so receiving does not mean typing them. Scanners send it either with the invisible
 * group separator between variable-length parts, or in printed form with brackets: (01)…(17)…(10)….
 */
final class Gs1
{
    /** Application identifiers read here: fixed length, or variable up to a separator. */
    private const FIXED = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6];

    private const VARIABLE = ['10' => 20, '21' => 20, '30' => 8, '37' => 8, '240' => 30, '241' => 30, '710' => 20, '711' => 20, '712' => 20, '713' => 20];

    /**
     * @return array{gtin: ?string, batch: ?string, expires_on: ?string, serial: ?string}|null null when it is not a GS1 code
     */
    public static function parse(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^\](d2|C1|e0|Q3)/', '', $s) ?? $s; // symbology prefix some scanners add
        $s = str_replace(["\u{1D}", '<GS>', '{GS}', '^]'], "\x1D", $s);
        $ai = [];
        if (str_starts_with($s, '(')) {
            if (! preg_match_all('/\((\d{2,4})\)([^(]*)/', $s, $m, PREG_SET_ORDER)) {
                return null;
            }
            foreach ($m as [, $code, $value]) {
                $ai[$code] = trim($value);
            }
        } else {
            $i = 0;
            $n = strlen($s);
            while ($i < $n) {
                if ($s[$i] === "\x1D") {
                    $i++;

                    continue;
                }
                $code = null;
                foreach ([2, 3] as $len) {
                    $c = substr($s, $i, $len);
                    if (isset(self::FIXED[$c]) || isset(self::VARIABLE[$c])) {
                        $code = $c;
                        break;
                    }
                }
                if ($code === null) {
                    return $ai ? self::result($ai) : null; // an AI not read here: keep what came before
                }
                $i += strlen($code);
                if (isset(self::FIXED[$code])) {
                    $ai[$code] = substr($s, $i, self::FIXED[$code]);
                    $i += self::FIXED[$code];
                } else {
                    $end = strpos($s, "\x1D", $i);
                    $end = $end === false ? min($n, $i + self::VARIABLE[$code]) : min($end, $i + self::VARIABLE[$code]);
                    $ai[$code] = substr($s, $i, $end - $i);
                    $i = $end;
                }
            }
        }

        return isset($ai['01']) || isset($ai['10']) || isset($ai['17']) ? self::result($ai) : null;
    }

    private static function result(array $ai): array
    {
        return ['gtin' => isset($ai['01']) && ctype_digit($ai['01']) ? $ai['01'] : null, 'batch' => ($ai['10'] ?? '') !== '' ? $ai['10'] : null,
            'expires_on' => isset($ai['17']) ? self::date($ai['17']) : null, 'serial' => ($ai['21'] ?? '') !== '' ? $ai['21'] : null];
    }

    /** YYMMDD; a day of 00 means the last day of that month (GS1 rule). Years 51–99 are 1951–1999. */
    private static function date(string $yymmdd): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }
        $year = (int) $m[1] >= 51 ? 1900 + (int) $m[1] : 2000 + (int) $m[1];
        $day = (int) $m[3] === 0 ? (int) date('t', mktime(0, 0, 0, (int) $m[2], 1, $year)) : (int) $m[3];

        return checkdate((int) $m[2], $day, $year) ? sprintf('%04d-%02d-%02d', $year, (int) $m[2], $day) : null;
    }
}

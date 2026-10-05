<?php

namespace App\Support;

use App\Domain\Support\DomainException;

/**
 * Quantities travel as decimal strings ("1.500") and are computed as integer thousandths,
 * so the server and the till (pos/src/money.ts) get identical results. No floats.
 */
final class Qty
{
    public const SCALE = 1000;

    public static function toMilli(string|int $qty): int
    {
        $qty = trim((string) $qty);
        if (! preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $qty)) {
            throw new DomainException("Invalid quantity “{$qty}” (up to 3 decimal places).");
        }
        $negative = str_starts_with($qty, '-');
        [$whole, $frac] = array_pad(explode('.', ltrim($qty, '-')), 2, '');
        $milli = (int) $whole * self::SCALE + (int) str_pad($frac, 3, '0');

        return $negative ? -$milli : $milli;
    }

    public static function format(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return $sign.intdiv($abs, self::SCALE).'.'.str_pad((string) ($abs % self::SCALE), 3, '0', STR_PAD_LEFT);
    }

    /** Factor (e.g. "24.000000") → millionths, for exact base-unit conversion. */
    public static function factorMicro(string|float $factor): int
    {
        $f = number_format((float) $factor, 6, '.', ''); // stored as decimal(18,6) so this is exact
        [$w, $d] = explode('.', $f);

        return (int) $w * 1_000_000 + (int) $d;
    }

    /** Quantity in a unit → base-unit thousandths (half away from zero). */
    public static function toBaseMilli(int $qtyMilli, int $factorMicro): int
    {
        return self::divRound($qtyMilli * $factorMicro, 1_000_000);
    }

    /** Integer division rounding half away from zero. */
    public static function divRound(int $numerator, int $denominator): int
    {
        $q = intdiv(abs($numerator) * 2 + $denominator, 2 * $denominator);

        return ($numerator < 0) !== ($denominator < 0) ? -$q : $q;
    }
}

<?php

namespace App\Support;

use App\Domain\Support\DomainException;

/** Money is integer minor units + ISO currency. Strings in, strings out — never floats. */
final class Money
{
    /** Minor-unit exponent per currency (cedi has pesewas: 2; CFA franc has none: 0). */
    public const EXPONENTS = ['GHS' => 2, 'NGN' => 2, 'KES' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'XOF' => 0];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? throw new DomainException("Currency {$currency} is not configured.");
    }

    /** "12.5" (GHS) → 1250. Rejects more decimals than the currency has. */
    public static function toMinor(string|int $amount, string $currency): int
    {
        $exp = self::exponent($currency);
        $amount = str_replace([',', ' '], '', trim((string) $amount));
        $pattern = $exp > 0 ? '/^-?\d{1,13}(\.\d{1,'.$exp.'})?$/' : '/^-?\d{1,15}$/';
        if (! preg_match($pattern, $amount)) {
            throw new DomainException("Invalid amount “{$amount}” for {$currency}.");
        }
        $negative = str_starts_with($amount, '-');
        [$whole, $frac] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        $minor = (int) $whole * 10 ** $exp + (int) str_pad($frac, $exp, '0');

        return $negative ? -$minor : $minor;
    }

    /** 1250 → "12.50" */
    public static function format(int $minor, string $currency): string
    {
        $exp = self::exponent($currency);
        if ($exp === 0) {
            return (string) $minor;
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $div = 10 ** $exp;

        return $sign.intdiv($abs, $div).'.'.str_pad((string) ($abs % $div), $exp, '0', STR_PAD_LEFT);
    }

    /** 1250 → "GH₵ 12.50" for screens and receipts. */
    public static function display(int $minor, string $currency): string
    {
        $symbol = ['GHS' => 'GH₵', 'NGN' => '₦', 'KES' => 'KSh', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'XOF' => 'CFA'][$currency] ?? $currency;
        $formatted = self::format($minor, $currency);
        [$w, $f] = array_pad(explode('.', ltrim($formatted, '-')), 2, null);
        $w = number_format((int) $w, 0, '.', ',');

        return ($minor < 0 ? '-' : '').$symbol.' '.$w.($f !== null ? '.'.$f : '');
    }
}

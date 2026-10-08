<?php

namespace App\Domain\Messaging;

/** Ghana phone numbers: 024 111 2222, +233 24 111 2222 and 233241112222 are the same number. */
final class Phone
{
    /** Digits in international form without "+", e.g. 233241112222 (other countries' numbers pass through). */
    public static function international(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10 && $d[0] === '0') {
            $d = '233'.substr($d, 1);
        } elseif (strlen($d) === 9) {
            $d = '233'.$d;
        }

        return $d;
    }

    public static function looksValid(string $phone): bool
    {
        return (bool) preg_match('/^\d{11,15}$/', self::international($phone));
    }
}

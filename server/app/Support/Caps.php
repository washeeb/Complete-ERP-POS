<?php

namespace App\Support;

/**
 * Names and codes in capitals, for one uniform list (owner decision 2026-10-22): products, categories, brands, units,
 * suppliers and customers, e.g. PARACETAMOL 500MG TAB. Notes, addresses and descriptions stay as typed; passwords,
 * e-mail and web addresses are never changed.
 */
final class Caps
{
    public static function upper(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strtoupper($v, 'UTF-8');
    }

    /** The given keys of $data in capitals (only those present). */
    public static function keys(array $data, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && is_string($data[$k])) {
                $data[$k] = self::upper($data[$k]);
            }
        }

        return $data;
    }
}

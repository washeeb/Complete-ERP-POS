<?php

namespace App\Support;

use App\Models\User;

/**
 * A person's name as shown on records. Records keep the name used at the time (renaming an account never rewrites
 * history); when the account has since been renamed, both show: "Alberta Agyepong (as Janet Doe)".
 */
final class People
{
    public static function label(?string $userId, ?string $nameThen): ?string
    {
        $now = $userId ? (self::names()[$userId] ?? null) : null;
        if ($now === null || $nameThen === null || $nameThen === '' || $now === $nameThen) {
            return $nameThen ?: $now;
        }

        return "{$now} (as {$nameThen})";
    }

    /** @return array<string, string> user id => current name, once per request */
    private static function names(): array
    {
        return once(fn () => User::withoutGlobalScopes()->pluck('name', 'id')->all());
    }
}

<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;

/**
 * People on records. A record keeps the name the person had at the time (renaming an account never rewrites history);
 * screens and prints show that name only. When the account has been renamed since, a hint gives today's name, and the
 * user's Name history lists every rename.
 */
final class People
{
    /** Today's name of the account, when it differs from the name on the record; null otherwise. */
    public static function renamedTo(?string $userId, ?string $nameThen): ?string
    {
        $now = $userId ? (self::names()[$userId] ?? null) : null;

        return $now !== null && $nameThen !== null && $nameThen !== '' && $now !== $nameThen ? $now : null;
    }

    /**
     * Every change of an account's name or username, newest first.
     *
     * @return list<array{at: string, by: string, name: ?array, username: ?array}>
     */
    public static function history(string $userId): array
    {
        return AuditEvent::query()->where('subject_type', 'user')->where('subject_id', $userId)
            ->whereIn('action', ['user.created', 'user.updated'])->orderByDesc('seq')->get(['occurred_at', 'actor_name', 'action', 'changes'])
            ->filter(fn (AuditEvent $e) => $e->action === 'user.updated' && (isset($e->changes['name']) || isset($e->changes['username'])))
            ->map(fn (AuditEvent $e) => ['at' => $e->occurred_at->format('j M Y H:i'), 'by' => (string) $e->actor_name,
                'name' => $e->changes['name'] ?? null, 'username' => $e->changes['username'] ?? null])
            ->values()->all();
    }

    /** @return array<string, string> user id => current name, once per request */
    private static function names(): array
    {
        return once(fn () => User::withoutGlobalScopes()->pluck('name', 'id')->all());
    }
}

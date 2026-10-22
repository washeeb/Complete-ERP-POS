<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * "Checking 150 of 2,000…" for long work done while someone waits (owner request 2026-10-22): imports, bulk changes,
 * moving products. The work reports how far it got; the back office asks every second while a request is running and
 * shows it (resources/views/progress/overlay). Kept in the file cache: work inside a database transaction would hide
 * a database-cached figure until the end.
 */
final class Progress
{
    private const TTL = 900;

    public static function start(string $label, int $total, ?string $userId = null): void
    {
        self::put($userId, ['label' => $label, 'done' => 0, 'total' => max(0, $total), 'at' => now()->getTimestamp()]);
    }

    /** Report how many are done (cheap: written at most about every half second, and always at the end). */
    public static function tick(int $done, ?string $label = null, ?string $userId = null): void
    {
        $now = microtime(true);
        static $last = 0.0;
        $cur = self::current($userId);
        if (! $cur) {
            return;
        }
        if ($now - $last < 0.5 && $done < $cur['total']) {
            return;
        }
        $last = $now;
        self::put($userId, ['done' => min($done, $cur['total'] ?: $done), 'at' => now()->getTimestamp()] + ($label ? ['label' => $label] : []) + $cur);
    }

    public static function finish(?string $userId = null): void
    {
        if ($id = $userId ?? Auth::id()) {
            Cache::store('file')->forget("progress:{$id}");
        }
    }

    /** @return array{label: string, done: int, total: int, at: int}|null */
    public static function current(?string $userId = null): ?array
    {
        $id = $userId ?? Auth::id();

        return $id ? Cache::store('file')->get("progress:{$id}") : null;
    }

    /** What the progress card shows: nothing when the work stopped reporting (finished, or broke off) 20 s ago. */
    public static function live(?string $userId = null): ?array
    {
        $p = self::current($userId);

        return $p && ($p['at'] ?? 0) >= now()->getTimestamp() - 20 ? $p : null;
    }

    /**
     * Go through a list and report each step: Progress::each('Changing units', $records, fn ($r) => …).
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): void  $do
     */
    public static function each(string $label, iterable $items, callable $do): void
    {
        $list = is_array($items) ? $items : iterator_to_array($items, false);
        self::start($label, count($list));
        try {
            foreach (array_values($list) as $i => $item) {
                $do($item);
                self::tick($i + 1);
            }
        } finally {
            self::finish();
        }
    }

    private static function put(?string $userId, array $value): void
    {
        if ($id = $userId ?? Auth::id()) {
            Cache::store('file')->put("progress:{$id}", $value, self::TTL);
        }
    }
}

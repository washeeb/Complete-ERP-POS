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

    /** Tests only: sees every figure written (work inside a run clears its figure at the end). */
    public static ?\Closure $onPut = null;

    /**
     * $background: the work runs on the server without anyone waiting on the request (a queued import). Its own page
     * shows it; the progress card leaves it alone, so a slow page refresh does not pop up someone else's figures.
     */
    public static function start(string $label, int $total, ?string $userId = null, bool $background = false): void
    {
        self::put($userId, ['label' => $label, 'done' => 0, 'total' => max(0, $total), 'at' => now()->getTimestamp(),
            'started' => now()->getTimestamp(), 'background' => $background]);
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

    /** "About 45 s left", "About 3 min 20 s left": seconds alone only under a minute (owner 2026-11-07). */
    public static function timeLeft(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 60) {
            return "About {$seconds} s left";
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return "About {$m} min".($s ? " {$s} s" : '').' left';
    }

    /** @return array{label: string, done: int, total: int, at: int}|null */
    public static function current(?string $userId = null): ?array
    {
        $id = $userId ?? Auth::id();

        return $id ? Cache::store('file')->get("progress:{$id}") : null;
    }

    /**
     * What the progress card and the import page show: nothing when the work stopped reporting (finished, or broke off)
     * 20 s ago. "left" is worked out here from when the work started, so every place shows the same time left.
     */
    public static function live(?string $userId = null): ?array
    {
        $p = self::current($userId);
        if (! $p || ($p['at'] ?? 0) < now()->getTimestamp() - 20) {
            return null;
        }
        $secs = now()->getTimestamp() - (int) ($p['started'] ?? $p['at']);
        $p['left'] = $p['done'] > 0 && $p['done'] < $p['total'] && $secs > 0
            ? self::timeLeft((int) round($secs / $p['done'] * ($p['total'] - $p['done']))) : null;
        $p['background'] = (bool) ($p['background'] ?? false);

        return $p;
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
            if (self::$onPut) {
                (self::$onPut)($value);
            }
        }
    }
}

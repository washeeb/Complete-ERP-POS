<?php

namespace App\Support;

use App\Filament\Pages\OwnerTodayPage;
use App\Models\User;
use Filament\Facades\Filament;
use Throwable;

/**
 * A person's own preferences (Profile → Preferences; interface stage 1b). Each one is used by the back office:
 *  - home: the page opened after signing in and by the house icon (the dashboard, Today, or one of their favourites);
 *  - rows: how many rows a list shows at first;
 *  - theme: light, dark or as the computer is set — applied on each computer they sign in to (the switcher in their
 *    menu still changes it for that computer);
 *  - date: how dates are written in lists and records;
 *  - density: comfortable or compact lists.
 */
final class UserPrefs
{
    public const DEFAULTS = ['home' => 'dashboard', 'rows' => 10, 'theme' => 'system', 'date' => 'words', 'density' => 'comfortable'];

    public const ROWS = [10, 25, 50, 100];

    public const THEMES = ['system' => 'As the computer is set', 'light' => 'Light', 'dark' => 'Dark'];

    /** style => [label, date format, date-and-time format] */
    public const DATES = ['words' => ['2 Nov 2026', 'j M Y', 'j M Y H:i'], 'slash' => ['02/11/2026', 'd/m/Y', 'd/m/Y H:i'], 'iso' => ['2026-11-02', 'Y-m-d', 'Y-m-d H:i']];

    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact: more rows on the screen'];

    /** @return array{home: string, rows: int, theme: string, date: string, density: string} */
    public static function for(?User $user): array
    {
        return self::clean((array) ($user?->ui_prefs ?? []), $user);
    }

    public static function get(string $key): mixed
    {
        $user = auth()->user();

        return self::for($user instanceof User ? $user : null)[$key];
    }

    /** Known keys and allowed values only; anything else falls back to the standard. */
    public static function clean(array $in, ?User $user): array
    {
        $p = self::DEFAULTS;
        $home = (string) ($in['home'] ?? '');
        if (isset(self::homes($user)[$home])) {
            $p['home'] = $home;
        }
        if (in_array((int) ($in['rows'] ?? 0), self::ROWS, true)) {
            $p['rows'] = (int) $in['rows'];
        }
        foreach (['theme' => self::THEMES, 'date' => self::DATES, 'density' => self::DENSITIES] as $key => $allowed) {
            if (isset($allowed[$in[$key] ?? ''])) {
                $p[$key] = $in[$key];
            }
        }

        return $p;
    }

    /** @return array<string, string> the pages a person may choose as home: value => label */
    public static function homes(?User $user): array
    {
        $homes = ['dashboard' => 'Dashboard'];
        try {
            if ($user && OwnerTodayPage::canAccess()) {
                $homes['today'] = 'Today (the day at a glance)';
            }
        } catch (Throwable) {
        }
        foreach ((array) ($user?->ui_favourites ?? []) as $f) {
            $homes[$f['url']] = 'Favourite: '.$f['label'];
        }

        return $homes;
    }

    /** The person's home page address. */
    public static function homeUrl(?User $user): string
    {
        $panel = (string) Filament::getPanel('admin')->getUrl();
        $home = self::for($user)['home'];

        return match (true) {
            $home === 'dashboard' => $panel,
            $home === 'today' => url('/admin/today'),
            str_starts_with($home, '/admin') => url($home),
            default => $panel,
        };
    }

    public static function dateFormat(): string
    {
        return self::DATES[self::get('date')][1];
    }

    public static function dateTimeFormat(): string
    {
        return self::DATES[self::get('date')][2];
    }
}

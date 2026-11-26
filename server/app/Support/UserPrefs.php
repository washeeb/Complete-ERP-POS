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
    public const DEFAULTS = ['home' => 'dashboard', 'rows' => 10, 'theme' => 'system', 'date' => 'words', 'density' => 'comfortable', 'office_look' => 'top'];

    /** The new back office (/office): menu across the top (owner's default, 2026-11-16) or the slim side menu. */
    public const OFFICE_LOOKS = ['top' => 'Menu across the top', 'side' => 'Side menu'];

    /** The panels of the new back office's Home, which each person arranges (Personalize, Layout). */
    public const HOME_PANELS = ['reminders', 'shortcuts', 'tiles', 'kpis', 'trend', 'meter', 'top_items'];

    /**
     * A person's Home: columns (2 or 3), the panels in order with their column and whether hidden, up to 8 tiles (pages
     * of the back office) and the figure on the KPI meter. Anything unknown is dropped; null = the standard Home.
     *
     * @return array{cols: int, panels: list<array{id: string, col: int, hidden: bool}>, tiles: list<array{url: string, label: string}>, meter: ?string}|null
     */
    public static function cleanHome(mixed $in): ?array
    {
        if (! is_array($in)) {
            return null;
        }
        $cols = in_array((int) ($in['cols'] ?? 3), [2, 3], true) ? (int) $in['cols'] : 3;
        $panels = [];
        foreach ((array) ($in['panels'] ?? []) as $p) {
            $id = is_array($p) ? (string) ($p['id'] ?? '') : '';
            if (in_array($id, self::HOME_PANELS, true) && ! collect($panels)->contains('id', $id)) {
                $panels[] = ['id' => $id, 'col' => max(0, min($cols - 1, (int) ($p['col'] ?? 0))), 'hidden' => (bool) ($p['hidden'] ?? false)];
            }
        }
        $tiles = [];
        foreach (array_slice((array) ($in['tiles'] ?? []), 0, 8) as $t) {
            $raw = is_array($t) ? (string) ($t['url'] ?? '') : '';
            // Checked as given (a page of the back office, nothing odd in it), then kept at its new-look address (part 42).
            $url = preg_match('#^/(admin|office)(/[A-Za-z0-9/_\-]*)?(\?[^\s<>"]*)?$#', $raw) ? (string) OfficeUrl::from($raw) : '';
            if (preg_match('#^/office(/[A-Za-z0-9/_\-]*)?(\?[^\s<>"]*)?$#', $url)) {
                $tiles[] = ['url' => $url, 'label' => mb_substr(trim(strip_tags((string) ($t['label'] ?? ''))), 0, 60) ?: $url];
            }
        }
        $meter = preg_match('/^[a-z_]{2,30}$/', (string) ($in['meter'] ?? '')) ? (string) $in['meter'] : null;

        return ['cols' => $cols, 'panels' => $panels, 'tiles' => $tiles, 'meter' => $meter];
    }

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
        $home = str_starts_with($home, '/') ? (string) OfficeUrl::from($home) : $home; // a page: the new back office's address
        if (isset(self::homes($user)[$home])) {
            $p['home'] = $home;
        }
        if (in_array((int) ($in['rows'] ?? 0), self::ROWS, true)) {
            $p['rows'] = (int) $in['rows'];
        }
        foreach (['theme' => self::THEMES, 'date' => self::DATES, 'density' => self::DENSITIES, 'office_look' => self::OFFICE_LOOKS] as $key => $allowed) {
            if (isset($allowed[$in[$key] ?? ''])) {
                $p[$key] = $in[$key];
            }
        }
        if (($home = self::cleanHome($in['office_home'] ?? null)) !== null) {
            $p['office_home'] = $home; // only when the person arranged their Home; absent = the standard one
        }

        return $p;
    }

    /** @return array<string, string> the pages a person may choose as home: value => label */
    public static function homes(?User $user): array
    {
        $homes = ['dashboard' => 'Business Portal'];
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

    /**
     * Where signing in lands (owner decision 2026-11-16: the new back office for everyone once release 1 is out): the
     * new Home, or the person's own home page (Today, a favourite) opened inside it. The classic back office stays one
     * click away (under the person's name).
     */
    public static function officeHomeUrl(?User $user): string
    {
        $home = self::for($user)['home'];

        // Exactly this address: url() would drop the final slash of /office/ (one more redirect).
        return rtrim(url('/'), '/').match (true) {
            $home === 'today' => '/office/today',
            str_starts_with($home, '/') => (string) OfficeUrl::from($home), // a favourite: its page in the new look
            default => '/office/',
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

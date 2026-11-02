<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials in a coloured circle for people without a photo, drawn here. Filament's default asks ui-avatars.com for
 * the picture, which sends every signed-in person's name to an outside website on every page.
 */
final class LocalAvatars implements AvatarProvider
{
    /** Dark enough for white letters (WCAG AA), told apart at a glance. */
    private const COLOURS = ['#1D4ED8', '#0F766E', '#7C3AED', '#B45309', '#BE123C', '#15803D', '#4338CA', '#0E7490', '#A21CAF', '#9A3412'];

    public function get(Model $record): string
    {
        $name = trim((string) filament()->getNameForDefaultAvatar($record));

        return self::svgDataUri($name);
    }

    public static function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[\s\-.,]+/u', $name) ?: []));
        if ($words === []) {
            return '?';
        }
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : mb_substr($words[0], 1, 1);

        return mb_strtoupper($first.$last);
    }

    public static function svgDataUri(string $name): string
    {
        $colour = self::COLOURS[crc32(mb_strtolower($name)) % count(self::COLOURS)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="'.$colour.'"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="26" font-weight="600" fill="#FFFFFF">'
            .e(self::initials($name)).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

<?php

namespace App\Support;

/**
 * The ZionEX loader (branding/product/loader.svg, copied to public/brand by `npm run brand:loader`), for inlining so the
 * page's light/dark colours reach it: resources/views/branding/loader.blade.php.
 */
final class Loader
{
    private static ?string $svg = null;

    public static function svg(int $size = 48): string
    {
        self::$svg ??= (string) preg_replace('/<!--.*?-->\s*/s', '', (string) @file_get_contents(public_path('brand/loader.svg')));

        return (string) preg_replace('/width="\d+" height="\d+"/', 'width="'.$size.'" height="'.$size.'"', self::$svg, 1);
    }
}

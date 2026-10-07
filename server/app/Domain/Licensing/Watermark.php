<?php

namespace App\Domain\Licensing;

/**
 * Traceable output (owner decision 2026-10-15). Every printed document carries the licence and build it came
 * from twice: as a tiny "Ref" line, and hidden as zero-width characters inside the company name (survives
 * copy-paste into other documents and emails). `php artisan license:trace` reads them back.
 */
final class Watermark
{
    private const ZERO = "\u{200B}";

    private const ONE = "\u{200C}";

    private const MARK = "\u{2060}";

    /** "licence short id · build id", e.g. "9F3A2C1B·B7Q2". */
    public static function reference(): string
    {
        $s = app(LicenseManager::class)->status();
        $build = ReleaseManifest::check()['build_id'] ?? 'DEV';

        return ($s['short'] ?? 'UNLICENSED').'·'.$build;
    }

    /** Hide a short text as zero-width characters. */
    public static function hide(string $text): string
    {
        $bits = '';
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }

        return self::MARK.strtr($bits, ['0' => self::ZERO, '1' => self::ONE]).self::MARK;
    }

    /** Find hidden references in a piece of text (e.g. pasted from a suspicious document). @return list<string> */
    public static function reveal(string $text): array
    {
        $out = [];
        $pattern = '/'.preg_quote(self::MARK, '/').'((?:'.preg_quote(self::ZERO, '/').'|'.preg_quote(self::ONE, '/').')+)'.preg_quote(self::MARK, '/').'/u';
        preg_match_all($pattern, $text, $m);
        foreach ($m[1] as $hidden) {
            $bits = strtr($hidden, [self::ZERO => '0', self::ONE => '1']);
            $s = '';
            foreach (str_split($bits, 8) as $byte) {
                if (strlen($byte) === 8) {
                    $s .= chr(bindec($byte));
                }
            }
            $out[] = $s;
        }

        return $out;
    }

    /** Text with the hidden reference woven in after its first word. */
    public static function stamp(string $text): string
    {
        $hidden = self::hide(self::reference());
        $pos = strpos($text, ' ');

        return $pos === false ? $text.$hidden : substr($text, 0, $pos).$hidden.substr($text, $pos);
    }

    public static function meta(): string
    {
        return '<meta name="x-ref" content="'.e(base64_encode(self::reference())).'">';
    }
}

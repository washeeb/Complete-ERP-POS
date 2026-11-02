<?php

namespace App\Domain\Access;

use App\Domain\Support\DomainException;
use App\Models\User;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A person's profile photo (interface stage 1a). The upload is never kept as sent: it is decoded and drawn again as a
 * 256-pixel square (centre crop) JPEG, which drops anything hidden in the file (location, camera details, scripts).
 * Stored on the server's private disk and shown only to people signed in to the same company (route user.photo).
 */
final class ProfilePhoto
{
    public const SIZE = 256;

    public const DIR = 'avatars';

    /** Clean the uploaded bytes and store them; returns the stored path. */
    public static function store(User $user, string $bytes): string
    {
        $clean = self::clean($bytes);
        $path = self::DIR.'/'.$user->id.'-'.Str::lower(Str::random(10)).'.jpg';
        Storage::disk('local')->put($path, $clean);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::DIR.'/')) {
            Storage::disk('local')->delete($path);
        }
    }

    public static function clean(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > 10_000_000) {
            throw new DomainException('The photo is empty or larger than 10 MB.');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new DomainException('Use a JPEG, PNG or WebP photo.');
        }
        if ($info[0] < 64 || $info[1] < 64) {
            throw new DomainException('The photo is too small. Use one at least 256 pixels wide.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new DomainException('The photo has too many pixels. Save it smaller.');
        }
        try {
            $src = imagecreatefromstring($bytes);
        } catch (Throwable) {
            $src = false;
        }
        if (! $src instanceof GdImage) {
            throw new DomainException('The photo could not be read. Save it again as JPEG.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // a transparent PNG gets a white background
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);
        ob_start();
        imagejpeg($dst, null, 85);
        imagedestroy($dst);

        return (string) ob_get_clean();
    }
}

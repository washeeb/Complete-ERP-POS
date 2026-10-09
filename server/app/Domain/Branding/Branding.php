<?php

namespace App\Domain\Branding;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\BrandAsset;
use App\Models\Tenant;
use App\Security\Authorizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GdImage;
use Throwable;

/**
 * Two brands meet on every screen: the **product's** (the software: name, logo, colour — config/brand.php, artwork
 * from branding/product) and each **client company's** (its logo, dark-background logo, square icon and colour,
 * uploaded on Company → Branding or in the setup wizard). The client's brand leads wherever their staff and customers
 * look; "Powered by <product>" stays small in footers and on receipts unless the licence includes white_label.
 *
 * Uploaded images are checked and re-encoded (metadata dropped, size capped); SVGs are cleaned of scripts, event
 * handlers and outside references, and are served with a locked-down content policy.
 */
final class Branding
{
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    public const MAX_STORED_BYTES = 700 * 1024;

    private const RASTER = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    // --- the product -------------------------------------------------------------------------------------------------

    /** @return array{name: string, tagline: string, vendor: string, website: ?string, color: string, logo: string, logo_dark: string, mark: string, favicon: string} */
    public static function product(): array
    {
        return array_merge(['website' => null], config('brand'));
    }

    /** The licence removes "Powered by …" only when it names white_label explicitly. */
    public static function whiteLabel(): bool
    {
        $features = app(LicenseManager::class)->status()['features'] ?? null;

        return is_array($features) && in_array('white_label', $features, true);
    }

    public static function poweredBy(): ?string
    {
        return self::whiteLabel() ? null : 'Powered by '.config('brand.name');
    }

    // --- a client company ----------------------------------------------------------------------------------------------

    /**
     * Everything a screen, receipt or document needs to show the company's brand.
     *
     * @return array{company: string, color: string, on_color: string, custom_color: bool, logo_url: ?string, logo_dark_url: ?string, icon_url: string,
     *               has_logo: bool, receipt_logo: bool, powered_by: ?string, monogram: string}
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $product = self::product();
        if (! $tenant) {
            return ['company' => $product['name'], 'color' => $product['color'], 'on_color' => self::onColor($product['color']), 'custom_color' => false,
                'logo_url' => $product['logo'], 'logo_dark_url' => $product['logo_dark'], 'icon_url' => $product['favicon'], 'has_logo' => false,
                'receipt_logo' => false, 'powered_by' => null, 'monogram' => self::monogram($product['name'], $product['color'])];
        }
        $assets = BrandAsset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('bytes', '>', 0)->get(['kind', 'mime', 'sha256'])->keyBy('kind');
        $color = $tenant->brand_color ?: $product['color'];
        $url = fn (string $kind) => isset($assets[$kind]) ? url("/brand/{$tenant->slug}/{$kind}?v=".substr($assets[$kind]->sha256, 0, 12)) : null;
        $logo = $url('logo');

        return ['company' => $tenant->name, 'color' => $color, 'on_color' => self::onColor($color), 'custom_color' => (bool) $tenant->brand_color,
            'logo_url' => $logo, 'logo_dark_url' => $url('logo_dark') ?? $logo, 'icon_url' => $url('icon') ?? url("/brand/{$tenant->slug}/monogram.svg?v=".substr(md5($tenant->name.$color), 0, 8)),
            'has_logo' => $logo !== null, 'receipt_logo' => $logo !== null && (bool) $tenant->brand_receipt_logo, 'powered_by' => self::poweredBy(),
            'monogram' => self::monogram($tenant->name, $color)];
    }

    /** The logo as a data URI (for PDFs and prints that must work without the network); raster only unless $svgOk. */
    public static function logoDataUri(?string $tenantId, bool $svgOk = true): ?string
    {
        if (! $tenantId) {
            return null;
        }
        $a = BrandAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kind', 'logo')->where('bytes', '>', 0)->first(['mime', 'data']);
        if (! $a || (! $svgOk && $a->mime === 'image/svg+xml')) {
            return null;
        }

        return $a->dataUri();
    }

    /** Upload (or replace) a logo or icon. $path is the uploaded file on disk. */
    public function upload(Actor $actor, string $kind, string $path): BrandAsset
    {
        $this->require($actor);
        if (! isset(BrandAsset::KINDS[$kind])) {
            throw new DomainException('Unknown kind of image.');
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new DomainException('The file could not be read.');
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new DomainException('The image is larger than 2 MB. Save it smaller (a logo needs about 1200 pixels wide at most).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'], true) && str_contains(substr($bytes, 0, 2000), '<svg')) {
            [$data, $w, $h] = self::cleanSvg($bytes);
            $mime = 'image/svg+xml';
        } elseif (in_array($mime, self::RASTER, true)) {
            [$data, $mime, $w, $h] = self::reencode($bytes, $kind);
        } else {
            throw new DomainException('Use a PNG (best: transparent background), SVG, JPEG or WebP image.');
        }
        if (strlen($data) > self::MAX_STORED_BYTES) {
            throw new DomainException('The image is still too large after resizing. Use a simpler PNG or an SVG.');
        }
        if ($kind === 'icon' && ($w && $h) && max($w, $h) / max(1, min($w, $h)) > 1.25) {
            throw new DomainException('The icon should be square (or nearly). Use the symbol of the logo without the name.');
        }

        return $this->recorder->transaction(function () use ($actor, $kind, $data, $mime, $w, $h) {
            $asset = BrandAsset::where('kind', $kind)->first() ?? new BrandAsset(['kind' => $kind]);
            $asset->forceFill(['tenant_id' => $actor->tenantId, 'mime' => $mime, 'data' => base64_encode($data), 'width' => $w, 'height' => $h, 'bytes' => strlen($data),
                'sha256' => hash('sha256', $data)]);

            return $this->recorder->save($actor, $asset, $asset->exists ? 'brand.asset_replaced' : 'brand.asset_added', BrandAsset::KINDS[$kind]);
        });
    }

    public function remove(Actor $actor, string $kind): void
    {
        $this->require($actor);
        $asset = BrandAsset::where('kind', $kind)->where('bytes', '>', 0)->first();
        if (! $asset) {
            return;
        }
        // Emptied rather than deleted, so the removal reaches branch servers like any other change.
        $asset->forceFill(['data' => '', 'bytes' => 0, 'width' => 0, 'height' => 0, 'sha256' => hash('sha256', '')]);
        $this->recorder->transaction(fn () => $this->recorder->save($actor, $asset, 'brand.asset_removed', BrandAsset::KINDS[$kind]));
    }

    /** The brand colour (#RRGGBB, null = the product's) and whether receipts print the logo. */
    public function saveSettings(Actor $actor, ?string $color, bool $receiptLogo): Tenant
    {
        $this->require($actor);
        $color = $color ? strtoupper(trim($color)) : null;
        if ($color !== null && ! preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new DomainException('The colour is #RRGGBB, for example #0F766E.');
        }
        $tenant = Tenant::findOrFail($actor->tenantId);
        $tenant->forceFill(['brand_color' => $color, 'brand_receipt_logo' => $receiptLogo]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $tenant, 'brand.settings_saved'));
    }

    /** The main colour of the uploaded logo (ignoring white, black, greys and transparency), to offer as the brand colour. */
    public static function suggestColor(?string $tenantId): ?string
    {
        $a = $tenantId ? BrandAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('kind', ['logo', 'icon'])->where('bytes', '>', 0)->orderBy('kind', 'desc')->get(['kind', 'mime', 'data'])
            ->first(fn ($x) => $x->mime !== 'image/svg+xml') : null;
        if (! $a) {
            return $tenantId ? self::svgColor($tenantId) : null;
        }
        $img = @imagecreatefromstring(base64_decode($a->data));
        if (! $img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $buckets = [];
        $step = max(1, (int) floor(max($w, $h) / 80));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                [$r, $g, $b] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                if ($alpha > 60 || max($r, $g, $b) - min($r, $g, $b) < 40) {
                    continue; // transparent or grey
                }
                $key = ($r >> 4) << 8 | ($g >> 4) << 4 | ($b >> 4);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        imagedestroy($img);
        if (! $buckets) {
            return null;
        }
        arsort($buckets);
        $k = array_key_first($buckets);

        return sprintf('#%02X%02X%02X', (($k >> 8) & 0xF) * 16 + 8, (($k >> 4) & 0xF) * 16 + 8, ($k & 0xF) * 16 + 8);
    }

    // --- colour and placeholders ---------------------------------------------------------------------------------------

    /** WCAG relative luminance of #RRGGBB. */
    public static function luminance(string $hex): float
    {
        $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf(ltrim($hex, '#'), '%02x%02x%02x'));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** Text colour on the brand colour: white or near-black, whichever reads better. */
    public static function onColor(string $hex): string
    {
        return self::contrast($hex, '#FFFFFF') >= self::contrast($hex, '#111827') ? '#FFFFFF' : '#111827';
    }

    /** A logo-shaped placeholder until the company uploads its own: its initials on its colour. */
    public static function monogram(string $name, string $color): string
    {
        $words = preg_split('/[\s\-&.,]+/u', trim(preg_replace('/\b(ltd|limited|plc|enterprise|enterprises|company|co|ventures|gh)\b\.?/iu', '', $name))) ?: [];
        $words = array_values(array_filter($words));
        $initials = mb_strtoupper(count($words) === 1 ? mb_substr($words[0], 0, 2) : implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: '?';
        $fg = self::onColor($color);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><rect width="128" height="128" rx="28" fill="'.e($color).'"/>'
            .'<text x="64" y="64" dy=".35em" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="'.(mb_strlen($initials) > 1 ? 52 : 64).'" font-weight="700" fill="'.$fg.'">'
            .e($initials).'</text></svg>';
    }

    // --- image handling ------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: int, 3: int} bytes, mime, width, height */
    private static function reencode(string $bytes, string $kind): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 32 || $info[1] < 32) {
            throw new DomainException('The image is too small or damaged. Use one at least 256 pixels wide.');
        }
        if ($kind === 'icon' && max($info[0], $info[1]) / max(1, min($info[0], $info[1])) > 1.25) {
            throw new DomainException('The icon should be square (or nearly). Use the symbol of the logo without the name.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new DomainException('The image has too many pixels. Save it smaller.');
        }
        try {
            $src = imagecreatefromstring($bytes);
        } catch (Throwable) {
            $src = false;
        }
        if (! $src instanceof GdImage) {
            throw new DomainException('The image could not be read. Save it again as PNG.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $max = $kind === 'icon' ? 512 : 1200;
        $scale = min(1, $max / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        if ($kind === 'icon') { // square canvas, centred, transparent around
            $side = max($nw, $nh);
            $dst = imagecreatetruecolor($side, $side);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, (int) (($side - $nw) / 2), (int) (($side - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            [$nw, $nh] = [$side, $side];
        } else {
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        }
        imagedestroy($src);
        ob_start();
        if ($info['mime'] === 'image/jpeg' && $kind !== 'icon') {
            imagejpeg($dst, null, 90);
            $mime = 'image/jpeg';
        } else {
            imagepng($dst, null, 9);
            $mime = 'image/png';
        }
        imagedestroy($dst);

        return [(string) ob_get_clean(), $mime, $nw, $nh];
    }

    /** @return array{0: string, 1: int, 2: int} the cleaned SVG and its size */
    public static function cleanSvg(string $svg): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            throw new DomainException('This SVG contains a DOCTYPE, which is not allowed. Export it again as plain SVG.');
        }
        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if (! $ok || ! $doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') {
            throw new DomainException('This is not a valid SVG image.');
        }
        $xp = new DOMXPath($doc);
        foreach (['script', 'foreignObject', 'iframe', 'object', 'embed', 'audio', 'video', 'handler', 'listener', 'set', 'animate'] as $tag) {
            foreach (iterator_to_array($xp->query("//*[local-name()='{$tag}']")) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        foreach (iterator_to_array($xp->query('//*')) as $el) {
            /** @var DOMElement $el */
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $value = trim($attr->nodeValue ?? '');
                $bad = str_starts_with($name, 'on')
                    || (in_array($attr->localName, ['href'], true) && ! str_starts_with($value, '#') && ! preg_match('#^data:image/(png|jpeg|webp|gif);base64,#i', $value))
                    || preg_match('/javascript:|expression\s*\(|url\s*\(\s*[\'"]?\s*(https?:|\/\/|javascript:)/i', $value);
                if ($bad) {
                    $el->removeAttributeNode($attr);
                }
            }
            if (strtolower($el->localName) === 'style' && preg_match('/@import|url\s*\(\s*[\'"]?\s*(https?:|\/\/)|javascript:|expression\s*\(/i', $el->textContent)) {
                $el->parentNode?->removeChild($el);
            }
        }
        $root = $doc->documentElement;
        $vb = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        $w = (int) round((float) ($vb[2] ?? 0)) ?: (int) $root->getAttribute('width');
        $h = (int) round((float) ($vb[3] ?? 0)) ?: (int) $root->getAttribute('height');
        if ($w <= 0 || $h <= 0) {
            throw new DomainException('The SVG has no size (viewBox). Export it again with “responsive”/viewBox on.');
        }

        return [(string) $doc->saveXML($root), $w, $h];
    }

    /** First non-grey fill colour in an SVG logo. */
    private static function svgColor(string $tenantId): ?string
    {
        $a = BrandAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kind', 'logo')->where('mime', 'image/svg+xml')->where('bytes', '>', 0)->first(['data']);
        if (! $a || ! preg_match_all('/#([0-9a-f]{6})\b/i', base64_decode($a->data), $m)) {
            return null;
        }
        foreach ($m[1] as $hex) {
            [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');
            if (max($r, $g, $b) - min($r, $g, $b) >= 40) {
                return '#'.strtoupper($hex);
            }
        }

        return null;
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'setup.company.manage')) {
            throw new AccessDenied;
        }
    }
}

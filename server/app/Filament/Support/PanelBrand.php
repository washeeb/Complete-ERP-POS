<?php

namespace App\Filament\Support;

use App\Domain\Branding\Branding;
use App\Models\BrandAsset;
use App\Models\Tenant;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The back office's look for the person signed in: their company's logo, icon and colour (Company → Branding), or
 * the product's own until the company uploads one. Before signing in, a single-company installation shows that
 * company's logo; otherwise the product's.
 */
final class PanelBrand
{
    private static ?array $memo = null;

    private static ?string $memoKey = null;

    /** @return array{company: string, color: string, logo_url: ?string, logo_dark_url: ?string, icon_url: string, has_logo: bool, powered_by: ?string} */
    public static function current(): array
    {
        $tenantId = auth()->user()?->tenant_id;
        $key = $tenantId ?? 'guest';
        if (self::$memo !== null && self::$memoKey === $key && ! app()->runningUnitTests()) {
            return self::$memo;
        }
        $tenant = $tenantId ? Tenant::find($tenantId) : self::onlyCompany();
        self::$memoKey = $key;

        return self::$memo = Branding::forTenant($tenant);
    }

    /** The one real company of a single-company installation that has uploaded a logo (for the sign-in page). */
    private static function onlyCompany(): ?Tenant
    {
        try {
            $real = Tenant::where('is_practice', false)->limit(2)->get();
        } catch (\Throwable) {
            return null; // before the database is migrated
        }
        if ($real->count() !== 1 || ! BrandAsset::withoutGlobalScopes()->where('tenant_id', $real->first()->id)->where('kind', 'logo')->where('bytes', '>', 0)->exists()) {
            return null;
        }

        return $real->first();
    }

    public static function name(): string
    {
        return self::current()['has_logo'] || auth()->check() ? self::current()['company'] : (string) config('brand.name');
    }

    /** The company's logo; until it uploads one, its monogram placeholder and name (signed in) or the product logo. */
    public static function logo(): string|Htmlable
    {
        $b = self::current();

        return $b['logo_url'] ?? (auth()->check() ? self::placeholder($b) : (string) config('brand.logo'));
    }

    /** Dark mode: the dark logo; without one, the normal logo on a light plate so dark lettering stays readable. */
    public static function darkLogo(): string|Htmlable
    {
        $b = self::current();
        if (! $b['has_logo']) {
            return auth()->check() ? self::placeholder($b) : (string) config('brand.logo_dark');
        }
        if ($b['logo_dark_url'] !== $b['logo_url']) {
            return (string) $b['logo_dark_url'];
        }

        return new HtmlString('<span style="display:inline-flex;align-items:center;height:100%;padding:3px 8px;border-radius:8px;background:#F8FAFC">'
            .'<img src="'.e((string) $b['logo_url']).'" alt="'.e($b['company']).'" style="height:100%;max-height:2rem"></span>');
    }

    private static function placeholder(array $b): Htmlable
    {
        return new HtmlString('<span style="display:inline-flex;align-items:center;gap:.6rem;font-weight:700;font-size:1.05rem;white-space:nowrap">'
            .'<img src="'.e($b['icon_url']).'" alt="" style="height:2rem;width:2rem;border-radius:.45rem">'.e($b['company']).'</span>');
    }

    public static function favicon(): string
    {
        $b = self::current();

        return $b['has_logo'] || auth()->check() ? $b['icon_url'] : (string) config('brand.favicon');
    }

    public static function colors(): array
    {
        return ['primary' => Color::hex(self::current()['color'])];
    }
}

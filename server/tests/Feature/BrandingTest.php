<?php

use App\Domain\Access\AccessService;
use App\Domain\Branding\Branding;
use App\Domain\Devices\DeviceService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\BrandingPage;
use App\Models\BrandAsset;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\Exports\Exporter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->o = actorFor($this->owner);
    $this->branding = app(Branding::class);
    $this->tmp = tempnam(sys_get_temp_dir(), 'brand');
});

/** A PNG of the given size filled with one colour (and a white band), written to a temp file. */
function brandPng(string $path, int $w, int $h, array $rgb = [200, 30, 40]): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    imagefilledrectangle($img, 0, 0, $w, (int) ($h / 5), imagecolorallocate($img, 255, 255, 255));
    imagepng($img, $path);
    imagedestroy($img);

    return $path;
}

it('shows the product brand before sign-in, with a small "Powered by" line', function () {
    expect(config('brand.name'))->toBe('SES Labs')->and(config('brand.vendor'))->toBe('SyncLytics');
    $this->get('/admin/login')->assertOk()->assertSee('/brand/logo.svg', false)->assertSee('/brand/logo-dark.svg', false)->assertSee('Powered by SES Labs')->assertDontSee('Synclytics');
    expect(file_exists(public_path('brand/logo.svg')))->toBeTrue()->and(file_exists(public_path('brand/icon-512.png')))->toBeTrue();
});

it('takes a company logo, resizes and cleans it, suggests the colour, and serves it cacheable', function () {
    $big = brandPng($this->tmp.'.png', 2400, 800);
    $logo = $this->branding->upload($this->o, 'logo', $big);
    expect($logo->mime)->toBe('image/png')->and($logo->width)->toBe(1200)->and($logo->height)->toBe(400)->and($logo->bytes)->toBeLessThan(Branding::MAX_STORED_BYTES);

    // The colour is taken from the logo (white and greys ignored), and white text reads on it.
    $suggested = Branding::suggestColor($this->tenant->id);
    expect($suggested)->toMatch('/^#[0-9A-F]{6}$/')->and(hexdec(substr($suggested, 1, 2)))->toBeGreaterThan(150);
    expect(Branding::onColor($suggested))->toBe('#FFFFFF')->and(Branding::onColor('#FDE68A'))->toBe('#111827');

    $b = Branding::forTenant($this->tenant->fresh());
    expect($b['has_logo'])->toBeTrue()->and($b['logo_url'])->toContain("/brand/{$this->tenant->slug}/logo?v=")
        ->and($b['icon_url'])->toContain('/monogram.svg') // no icon yet: initials
        ->and($b['powered_by'])->toBe('Powered by SES Labs');
    $res = $this->get(parse_url($b['logo_url'], PHP_URL_PATH).'?'.parse_url($b['logo_url'], PHP_URL_QUERY));
    $res->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($res->headers->get('Cache-Control'))->toContain('immutable')->and($res->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    $this->get("/brand/{$this->tenant->slug}/monogram.svg")->assertOk()->assertSee('>DE<', false); // "Demo Ltd": one word → its first two letters
    expect(Branding::monogram('Kofi Mensah Enterprise Ltd', '#0F766E'))->toContain('>KM<');

    // An icon must be square; the colour is #RRGGBB; removing a logo stops serving it (and reaches branch servers as a change).
    expect(fn () => $this->branding->upload($this->o, 'icon', brandPng($this->tmp.'w.png', 900, 300)))->toThrow(DomainException::class, 'square');
    $this->branding->upload($this->o, 'icon', brandPng($this->tmp.'s.png', 300, 300));
    expect(fn () => $this->branding->saveSettings($this->o, 'red', true))->toThrow(DomainException::class, '#RRGGBB');
    $this->branding->saveSettings($this->o, '#0f766e', false);
    expect($this->tenant->fresh()->brand_color)->toBe('#0F766E')->and(Branding::forTenant($this->tenant->fresh())['receipt_logo'])->toBeFalse();
    $this->branding->remove($this->o, 'logo');
    expect(BrandAsset::where('kind', 'logo')->value('bytes'))->toBe(0)->and(Branding::forTenant($this->tenant->fresh())['has_logo'])->toBeFalse();
    $this->get("/brand/{$this->tenant->slug}/logo")->assertNotFound();
    expect(DB::table('outbox_events')->where('aggregate_type', 'brand_asset')->count())->toBeGreaterThanOrEqual(3); // replicated like other company data

    // Only people who may change the company set-up may change its brand.
    $cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    expect(fn () => $this->branding->upload(actorFor($cashier), 'logo', $big))->toThrow(AccessDenied::class);
});

it('cleans SVG logos of scripts, event handlers and outside references, and refuses DOCTYPEs', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 300 100" onload="alert(1)">'
        .'<script>alert(2)</script><foreignObject><div>x</div></foreignObject><style>@import url(https://evil.example/x.css);</style>'
        .'<a href="javascript:alert(3)"><rect width="300" height="100" fill="#123456"/></a>'
        .'<image xlink:href="https://evil.example/track.png" width="10" height="10"/><circle cx="50" cy="50" r="20" fill="#E11D48"/></svg>';
    file_put_contents($this->tmp.'.svg', $svg);
    $a = $this->branding->upload($this->o, 'logo', $this->tmp.'.svg');
    $clean = base64_decode(BrandAsset::find($a->id)->data);
    expect($a->mime)->toBe('image/svg+xml')->and($a->width)->toBe(300)->and($a->height)->toBe(100)
        ->and($clean)->not->toContain('<script')->not->toContain('onload')->not->toContain('foreignObject')->not->toContain('javascript:')
        ->not->toContain('evil.example')->toContain('<circle');
    expect(Branding::suggestColor($this->tenant->id))->toBe('#123456');
    $res = $this->get("/brand/{$this->tenant->slug}/logo")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($res->headers->get('Content-Security-Policy'))->toContain('sandbox');

    file_put_contents($this->tmp.'x.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&x;</text></svg>');
    expect(fn () => $this->branding->upload($this->o, 'logo', $this->tmp.'x.svg'))->toThrow(DomainException::class, 'DOCTYPE');
    file_put_contents($this->tmp.'.txt', 'not an image');
    expect(fn () => $this->branding->upload($this->o, 'logo', $this->tmp.'.txt'))->toThrow(DomainException::class, 'PNG');
});

it('brands the back office, the till, printed documents and PDFs — and white-label removes "Powered by"', function () {
    $this->branding->upload($this->o, 'logo', brandPng($this->tmp.'.png', 600, 200));
    $this->branding->saveSettings($this->o, '#7C3AED', true);
    $logoUrl = Branding::forTenant($this->tenant->fresh())['logo_url'];

    $this->actingAs(withMfa($this->owner));
    $this->get('/admin')->assertOk()->assertSee(e($logoUrl), false)->assertSee('Powered by SES Labs');
    $this->get(BrandingPage::getUrl())->assertOk()->assertSee('Your brand')->assertSee('#7C3AED');
    Livewire::test(BrandingPage::class)->set('files.icon', UploadedFile::fake()->image('icon.png', 256, 256))->assertHasNoErrors();
    expect(BrandAsset::where('kind', 'icon')->value('bytes'))->toBeGreaterThan(0);

    // A PDF export carries the logo (embedded, no network), and still renders.
    $pdf = app(Exporter::class)->pdf($this->o, 'Test', [['title' => 'Test', 'headings' => ['A'], 'rows' => [['1']]]]);
    ob_start();
    $pdf->sendContent();
    expect(ob_get_clean())->toStartWith('%PDF');
    expect(view('exports.table', ['title' => 'T', 'sheets' => [], 'filters' => [], 'truncated' => false, 'company' => 'Demo', 'by' => 'x', 'at' => now(), 'ref' => 'R',
        'logo' => Branding::logoDataUri($this->tenant->id, false), 'powered' => Branding::poweredBy()])->render())->toContain('data:image/png;base64,')->toContain('Powered by SES Labs');

    // The till gets the brand at start-up.
    $this->flushSession();
    app('auth')->forgetGuards();
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$device, $code] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'Till 1', 'kind' => 'pos']);
    $cashier = staff($this->owner, 'esi', 2, 'cashier', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($this->o, $cashier, '4826');
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['device_id' => $device->id, 'code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    $login = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->json();
    app('auth')->forgetGuards();
    $this->withToken($login['token'])->getJson('/api/v1/pos/bootstrap')->assertOk()
        ->assertJsonPath('brand.color', '#7C3AED')->assertJsonPath('brand.receipt_logo', true)->assertJsonPath('brand.logo_url', $logoUrl)
        ->assertJsonPath('brand.powered_by', 'Powered by SES Labs')->assertJsonPath('brand.product', 'SES Labs');

    // A white-label licence names the feature: "Powered by" goes everywhere.
    activateTestLicense(['features' => ['finance', 'hr', 'white_label']]);
    LicenseManager::forget();
    expect(Branding::whiteLabel())->toBeTrue()->and(Branding::poweredBy())->toBeNull();
    $this->withoutToken();
    app('auth')->forgetGuards();
    $this->actingAs(withMfa($this->owner), 'web');
    $this->get('/admin')->assertOk()->assertDontSee('Powered by');
    activateTestLicense(['features' => ['finance', 'hr']]);
    LicenseManager::forget();
    expect(Branding::whiteLabel())->toBeFalse();
});

it('keeps the branding page to people who manage the company', function () {
    $cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    $this->actingAs(withMfa($cashier));
    $this->get(BrandingPage::getUrl())->assertForbidden();
});

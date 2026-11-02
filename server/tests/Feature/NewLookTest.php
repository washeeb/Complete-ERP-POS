<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\ProfilePhoto;
use App\Domain\Branding\Branding;
use App\Domain\Support\DomainException;
use App\Filament\Support\LocalAvatars;
use App\Support\Look;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
});

/** A real photo's bytes: a 400×300 PNG. */
function photoBytes(): string
{
    $img = imagecreatetruecolor(400, 300);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

it('uses the tabs unless the company or the person chooses another look', function () {
    expect(Look::for($this->owner))->toBe('top');

    app(Settings::class)->set(actorFor($this->owner), 'ui.look', 'classic');
    expect(Look::for($this->owner->fresh()))->toBe('classic');

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'side']);
    expect(Look::for($this->owner->fresh()))->toBe('side');

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => '']); // back to the company's
    expect(Look::for($this->owner->fresh()))->toBe('classic');

    expect(fn () => app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'neon']))
        ->toThrow(DomainException::class);
});

it('shows the tabs, the page finder, + New and who is signed in, built from what the person may open', function () {
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/sales')->assertOk()->assertSee('zx-tabbar', false)->assertSee('Purchasing')->assertSee('Finance')
        ->assertSee('Find a page')->assertSee('Purchase order')->assertSee('zx-who', false)->assertSee('Favourites');

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'classic']);
    $this->actingAs(withMfa($this->owner->fresh()));
    $this->get('/admin/sales')->assertOk()->assertDontSee('zx-tabbar', false)->assertSee('zx-classic', false)->assertDontSee('zx-who', false);
});

it('gives a cashier only the tabs for the pages a cashier may open', function () {
    $cashier = staff($this->owner, 'efua', 3, 'cashier');
    $this->actingAs(withMfa($cashier->fresh()));
    $this->get('/admin/profile'); // builds the menu as the cashier (a new cashier is sent on to the first sign-in steps)
    expect(collect(Look::tabs())->pluck('label')->all())->toBe(['Sales', 'Setup']); // the home page is the house icon
});

it('keeps favourite pages for the person, only pages of the back office', function () {
    $this->actingAs(withMfa($this->owner));
    $add = $this->postJson(route('ui.favourite'), ['url' => '/admin/purchase-orders?tableSearch=x', 'label' => 'Purchase Orders'])->assertOk();
    expect($add->json('favourites'))->toBe([['url' => '/admin/purchase-orders?tableSearch=x', 'label' => 'Purchase Orders']]);
    $this->postJson(route('ui.favourite'), ['url' => url('/admin/products'), 'label' => '<b>Products</b>'])->assertOk();
    expect($this->owner->fresh()->ui_favourites[1])->toBe(['url' => '/admin/products', 'label' => 'Products']);
    expect(DB::table('audit_events')->where('action', 'user.favourites_changed')->count())->toBe(2);

    // Pressing the star again takes the page off.
    $this->postJson(route('ui.favourite'), ['url' => '/admin/purchase-orders?tableSearch=x', 'label' => 'x'])->assertOk()
        ->assertJsonCount(1, 'favourites');

    // Never a page elsewhere.
    $this->postJson(route('ui.favourite'), ['url' => 'https://evil.example/admin/x', 'label' => 'x'])->assertStatus(422);
    $this->postJson(route('ui.favourite'), ['url' => '/s/some-supplier-token', 'label' => 'x'])->assertStatus(422);

    $this->owner->forceFill(['ui_favourites' => collect(range(1, AccessService::MAX_FAVOURITES))->map(fn ($i) => ['url' => "/admin/p{$i}", 'label' => "P{$i}"])->all()])->saveQuietly();
    $this->actingAs(withMfa($this->owner->fresh()));
    $this->postJson(route('ui.favourite'), ['url' => '/admin/one-more', 'label' => 'x'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Remove one first'));
});

it('cleans a profile photo into a small square copy, shows it only within the company, and removes it', function () {
    Storage::disk('local')->put(AccessService::PHOTO_UPLOADS.'/up.png', photoBytes());
    $user = app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['photo' => AccessService::PHOTO_UPLOADS.'/up.png']);

    expect($user->avatar_path)->toStartWith(ProfilePhoto::DIR.'/');
    Storage::disk('local')->assertMissing(AccessService::PHOTO_UPLOADS.'/up.png');
    $info = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
    expect([$info[0], $info[1], $info['mime']])->toBe([256, 256, 'image/jpeg']);
    expect(DB::table('audit_events')->where('action', 'user.photo_changed')->exists())->toBeTrue();

    $this->actingAs(withMfa($user));
    $this->get($user->getFilamentAvatarUrl())->assertOk();
    [, $stranger] = installCompany('Other Ltd', 'boss@other.test');
    $this->actingAs($stranger)->get($user->getFilamentAvatarUrl())->assertForbidden();

    // Only a file the profile page uploaded is taken; anything else on the disk is refused.
    expect(fn () => app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $user->fresh(), ['photo' => 'agreements/x.pdf']))
        ->toThrow(DomainException::class);

    $old = $user->avatar_path;
    $user = app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $user->fresh(), ['photo' => null]);
    expect($user->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($old);
});

it('refuses a file that is not a photo', function () {
    expect(fn () => ProfilePhoto::clean('<?php echo 1;'))->toThrow(DomainException::class);
});

it('draws initials here instead of sending names to ui-avatars.com', function () {
    expect(LocalAvatars::initials('Ama Owner'))->toBe('AO')->and(LocalAvatars::initials('Kojo'))->toBe('KO');
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/sales')->assertOk()->assertDontSee('ui-avatars.com', false);
    $this->get('/admin/profile')->assertOk()->assertSee('How the back office looks for you');
});

it('colours the menu with the company\'s own menu colour, or a shade of its brand colour', function () {
    $branding = app(Branding::class);
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/sales')->assertOk()->assertDontSee('--zx-menu: #', false); // automatic: from the brand colour

    expect(fn () => $branding->saveSettings(actorFor($this->owner), null, true, 'teal'))->toThrow(DomainException::class, '#RRGGBB');
    $branding->saveSettings(actorFor($this->owner), '#0f766e', true, '#0e4d64');
    expect($this->tenant->fresh()->brand_menu_color)->toBe('#0E4D64');
    $branding->saveSettings(actorFor($this->owner), '#0f766e', false); // the set-up wizard leaves the menu colour as it is
    expect($this->tenant->fresh()->brand_menu_color)->toBe('#0E4D64');
    $this->get('/admin/sales')->assertOk()->assertSee('--zx-menu: #0E4D64', false);

    $branding->saveSettings(actorFor($this->owner), '#0f766e', true, null); // back to automatic
    expect($this->tenant->fresh()->brand_menu_color)->toBeNull();
});

it('lays the profile page out in parts, with the look as picture cards', function () {
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/profile')->assertOk()
        ->assertSee('Personal information')->assertSee('Two-factor sign-in')->assertSee('Passkeys')->assertSee('Need help?')
        ->assertSee('value="side"', false)->assertSee('zx-hero-ic', false);
});

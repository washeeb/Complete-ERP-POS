<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\ProfilePhoto;
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

it('keeps the classic look until the person or the company chooses the new one', function () {
    expect(Look::for($this->owner))->toBe('classic');

    app(Settings::class)->set(actorFor($this->owner), 'ui.look', 'top');
    expect(Look::for($this->owner->fresh()))->toBe('top');

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'side']);
    expect(Look::for($this->owner->fresh()))->toBe('side');

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => '']); // back to the company's
    expect(Look::for($this->owner->fresh()))->toBe('top');

    expect(fn () => app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'neon']))
        ->toThrow(DomainException::class);
});

it('shows the tabs, the page finder and + New in the new look, built from what the person may open', function () {
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/sales')->assertOk()->assertDontSee('zx-tabbar', false)->assertSee('zx-classic', false);

    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_look' => 'top']);
    $this->actingAs(withMfa($this->owner->fresh()));
    $this->get('/admin/sales')->assertOk()->assertSee('zx-tabbar', false)->assertSee('Purchasing')->assertSee('Finance')
        ->assertSee('Find a page')->assertSee('Purchase order');
});

it('gives a cashier only the tabs for the pages a cashier may open', function () {
    $cashier = staff($this->owner, 'efua', 3, 'cashier');
    app(AccessService::class)->updateOwnProfile(actorFor($cashier), $cashier, ['ui_look' => 'top']);
    $this->actingAs(withMfa($cashier->fresh()));
    $this->get('/admin/profile'); // builds the menu as the cashier (a new cashier is sent on to the first sign-in steps)
    expect(collect(Look::tabs())->pluck('label')->all())->toBe(['Home', 'Sales', 'Setup']);
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

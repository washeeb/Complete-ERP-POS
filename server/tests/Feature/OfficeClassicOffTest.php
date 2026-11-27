<?php

use App\Domain\Licensing\LicenseManager;
use App\Domain\Messaging\Notifier;
use App\Models\License;
use Illuminate\Support\Str;

// Part 43: the current back office is switched off (its pages open the new ones), the new back office has its own bell,
// and an unlicensed server opens the new licence page first.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
});

it('sends every page of the current back office to its new page, signed in or not', function () {
    config(['erp.classic_back_office' => false]); // as on every server (the tests of the old pages switch it on)
    $id = (string) Str::uuid7();
    $this->get('/')->assertRedirect(rtrim(url('/'), '/').'/office/');
    $this->get('/admin/login')->assertRedirect(url('/office/sign-in'));
    $this->actingAs($this->owner, 'web');
    $this->get('/admin')->assertRedirect(rtrim(url('/'), '/').'/office/');
    $this->get('/admin/products?tableSearch=rice')->assertRedirect(url('/office/products?q=rice'));
    $this->get("/admin/sales/{$id}")->assertRedirect(url("/office/sales/{$id}"));
    $this->get('/admin/profile')->assertRedirect(url('/office/profile'));
    $this->get('/admin/confirm-its-you')->assertRedirect(url('/office/confirm-its-you'));
    expect($this->getJson('/office-api/me')->assertOk()->json())->not->toHaveKey('classic');
});

it('shows the person\'s notifications on the bell: open, read, read all, clear — their own only', function () {
    $this->actingAs($this->owner, 'web');
    app(Notifier::class)->toPermission($this->tenant->id, 'admin.settings.manage', null, 'The server needs attention', 'Backups', url('/office/system-health'), topic: 'system');
    $this->owner->notifications()->create(['id' => (string) Str::uuid7(), 'type' => 'Filament\Notifications\DatabaseNotification',
        'data' => ['title' => 'Old link', 'body' => '<b>Kept</b> before part 42', 'actions' => [['name' => 'open', 'url' => url('/admin/stock-levels')]]]]);
    $bell = $this->getJson('/office-api/notifications')->assertOk()->json();
    expect($bell['unread'])->toBe(2)->and(collect($bell['items'])->pluck('title')->all())->toContain('The server needs attention', 'Old link');
    $old = collect($bell['items'])->firstWhere('title', 'Old link');
    expect($old)->toMatchArray(['url' => '/office/stock', 'body' => 'Kept before part 42', 'read' => false]); // at its new-look address
    $this->postJson("/office-api/notifications/{$old['id']}/read")->assertOk()->assertJson(['unread' => 1]);
    $this->getJson('/office-api/notifications/count')->assertJson(['unread' => 1]);
    $this->postJson('/office-api/notifications/read-all')->assertOk()->assertJson(['unread' => 0]);

    $other = withMfa(staff($this->owner, 'kojo', 4, 'branch_manager'));
    $theirs = $other->notifications()->create(['id' => (string) Str::uuid7(), 'type' => 'Filament\Notifications\DatabaseNotification', 'data' => ['title' => 'Theirs']]);
    $this->postJson("/office-api/notifications/{$theirs->id}/read")->assertNotFound();
    $this->postJson('/office-api/notifications/clear')->assertOk();
    expect($this->owner->notifications()->count())->toBe(0)->and($other->notifications()->count())->toBe(1);
});

it('opens the licence page first on a server that is not licensed yet, but lets two-factor sign-in be set up', function () {
    License::query()->update(['is_current' => false]);
    LicenseManager::forget();
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/me')->assertOk()->json('licence'))->toBe('/office/licence');
    $this->get('/office/sales')->assertRedirect('/office/licence');
    $this->get('/office/licence')->assertStatus(is_file(public_path('office/index.html')) ? 200 : 404); // the page itself, not sent away
    $this->get('/office/two-factor-setup')->assertStatus(is_file(public_path('office/index.html')) ? 200 : 404);
});

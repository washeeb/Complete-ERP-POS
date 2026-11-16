<?php

use App\Domain\Customers\CustomerService;
use App\Models\Customer;
use App\Security\ScopeType;

// The new back office (/office, owner decision 2026-11-15/16): what it shows comes from the current back office, for the
// person signed in, so both always agree on menus and permissions.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner->fresh());
});

it('sends someone signed out to sign in, and back to the same page of the new back office afterwards', function () {
    $this->getJson('/office-api/me')->assertUnauthorized();
    $this->get('/office-signin?to='.urlencode('/office/a/products?tableSearch=rice'))->assertRedirect('/admin/login');
    expect(session('url.intended'))->toBe(url('/office/a/products?tableSearch=rice'));

    $this->get('/office-signin?to='.urlencode('https://evil.example/app/'))->assertRedirect('/admin/login');
    expect(session('url.intended'))->toBe(url('/office/')); // never another site
    $this->get('/office-signin?to='.urlencode('//evil.example/app'))->assertRedirect('/admin/login');
    expect(session('url.intended'))->toBe(url('/office/'));

    $this->get('/office/a/products')->assertRedirect('/admin/login'); // an address inside the app, opened directly
    expect(session('url.intended'))->toBe(url('/office/a/products'));
});

it('tells the app who is signed in, their company and look, and keeps their choice of look', function () {
    $this->actingAs($this->owner, 'web');
    $this->getJson('/office-api/me')->assertOk()
        ->assertJsonPath('user.name', $this->owner->name)
        ->assertJsonPath('company.name', $this->tenant->name)
        ->assertJsonPath('prefs.office_look', 'top'); // the owner's default

    $this->postJson('/office-api/prefs', ['office_look' => 'side'])->assertOk()->assertJsonPath('prefs.office_look', 'side');
    expect($this->owner->fresh()->ui_prefs['office_look'])->toBe('side')
        ->and($this->owner->fresh()->ui_prefs['rows'])->toBe(10); // the other choices kept
    $this->postJson('/office-api/prefs', ['office_look' => 'sideways'])->assertUnprocessable();
});

it('gives each person the menu of the current back office, and only what they may open', function () {
    $this->actingAs($this->owner, 'web');
    $menu = $this->getJson('/office-api/menu')->assertOk()->json();
    $tabs = collect($menu['tabs'])->pluck('label');
    $urls = collect($menu['tabs'])->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url'))));
    expect($tabs)->toContain('Sales')->toContain('Stock')
        ->and($urls)->toContain('/admin/products')
        ->and($urls->every(fn ($u) => str_starts_with($u, '/admin')))->toBeTrue()
        ->and(collect($menu['create'])->flatMap(fn ($g) => collect($g['items'])->pluck('url')))->toContain('/admin/products/create');
});

it('leaves out of a teller\'s menu what a teller may not open', function () {
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    $this->actingAs($teller, 'web');
    $theirs = collect($this->getJson('/office-api/menu')->assertOk()->json('tabs'))
        ->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url'))));
    expect($theirs)->not->toContain('/admin/users')->not->toContain('/admin/staff-cards');
});

it('searches records the way the current back office does', function () {
    app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Kwabena Boateng Stores', 'phone' => '0244000003']);
    $this->actingAs($this->owner, 'web');
    $hits = collect($this->getJson('/office-api/search?q=Boateng')->assertOk()->json('results'));
    expect(mb_strtolower($hits->pluck('title')->implode(' ')))->toContain('kwabena boateng stores')
        ->and($hits->every(fn ($h) => str_starts_with($h['url'], '/admin')))->toBeTrue();
    $this->getJson('/office-api/search?q=K')->assertOk()->assertJsonPath('results', []);
});

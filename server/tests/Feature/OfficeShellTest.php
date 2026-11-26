<?php

use App\Domain\Customers\CustomerService;
use App\Http\Responses\HomeLoginResponse;
use App\Models\Customer;
use App\Security\ScopeType;
use Illuminate\Http\Request;

// The new back office (/office, owner decision 2026-11-15/16): what it shows comes from the current back office, for the
// person signed in, so both always agree on menus and permissions.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner->fresh());
});

it('sends someone signed out to sign in, and back to the same page of the new back office afterwards', function () {
    $this->getJson('/office-api/me')->assertUnauthorized();
    $this->get('/office-signin?to='.urlencode('/office/a/products?tableSearch=rice'))->assertRedirect('/office/sign-in');
    expect(session('url.intended'))->toBe(url('/office/a/products?tableSearch=rice'));

    $this->get('/office-signin?to='.urlencode('https://evil.example/app/'))->assertRedirect('/office/sign-in');
    expect(session('url.intended'))->toBe(url('/').'/office/'); // never another site
    $this->get('/office-signin?to='.urlencode('//evil.example/app'))->assertRedirect('/office/sign-in');
    expect(session('url.intended'))->toBe(url('/').'/office/');

    $this->get('/office/a/products')->assertRedirect('/office/sign-in'); // an address inside the app, opened directly
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
        ->and($urls)->toContain('/office/products') // the new back office's addresses (part 42)
        ->and($urls->every(fn ($u) => str_starts_with($u, '/office')))->toBeTrue()
        ->and(collect($menu['create'])->flatMap(fn ($g) => collect($g['items'])->pluck('url')))->toContain('/office/products/new');
});

it('leaves out of a teller\'s menu what a teller may not open', function () {
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    $this->actingAs($teller, 'web');
    $theirs = collect($this->getJson('/office-api/menu')->assertOk()->json('tabs'))
        ->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url'))));
    expect($theirs)->not->toContain('/office/users')->not->toContain('/office/staff-cards');
});

it('searches records the way the current back office does', function () {
    app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Kwabena Boateng Stores', 'phone' => '0244000003']);
    $this->actingAs($this->owner, 'web');
    $hits = collect($this->getJson('/office-api/search?q=Boateng')->assertOk()->json('results'));
    expect(mb_strtolower($hits->pluck('title')->implode(' ')))->toContain('kwabena boateng stores')
        ->and($hits->every(fn ($h) => str_starts_with($h['url'], '/office')))->toBeTrue();
    $this->getJson('/office-api/search?q=K')->assertOk()->assertJsonPath('results', []);
});

it('builds Home from what the person may see: reminders, KPIs against the period before, top items and the trend', function () {
    $this->actingAs($this->owner, 'web');
    $home = $this->getJson('/office-api/home?period=month')->assertOk()->json();
    expect($home['period']['label'])->toBe('This month')
        ->and(collect($home['kpis'])->pluck('key'))->toContain('sales')->toContain('stock')->toContain('open_po')
        ->and(collect($home['kpis'])->firstWhere('key', 'sales'))->toHaveKeys(['current', 'previous', 'change', 'spark'])
        ->and($home['trend']['months'])->toHaveCount(12)
        ->and(collect($home['trend']['series'])->pluck('key'))->toContain('sales')
        ->and(collect($home['reminders'])->every(fn ($r) => str_starts_with($r['url'], '/office')))->toBeTrue()
        ->and($home['layout'])->toBeNull();
    $this->getJson('/office-api/home?period=forever')->assertOk()->assertJsonPath('period.key', 'month');
});

it('keeps each person\'s arrangement of Home, and only what makes sense', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/home-layout', ['layout' => ['cols' => 2, 'meter' => 'stock',
        'panels' => [['id' => 'kpis', 'col' => 1], ['id' => 'trend', 'col' => 5, 'hidden' => true], ['id' => 'evil', 'col' => 0], ['id' => 'kpis', 'col' => 0]],
        'tiles' => [['url' => '/admin/products', 'label' => 'Products'], ['url' => 'https://evil.example/', 'label' => 'x'], ['url' => '/admin/sales?x=<script>', 'label' => '<b>S</b>']]]])
        ->assertOk()
        ->assertJsonPath('layout.cols', 2)
        ->assertJsonPath('layout.panels', [['id' => 'kpis', 'col' => 1, 'hidden' => false], ['id' => 'trend', 'col' => 1, 'hidden' => true]])
        ->assertJsonPath('layout.tiles', [['url' => '/office/products', 'label' => 'Products']]) // kept at its new-look address
        ->assertJsonPath('layout.meter', 'stock');
    $this->getJson('/office-api/home')->assertJsonPath('layout.cols', 2);
    $this->postJson('/office-api/prefs', ['office_look' => 'side'])->assertOk();
    $this->getJson('/office-api/home')->assertJsonPath('layout.cols', 2); // changing the look keeps the Home arrangement
    $this->postJson('/office-api/home-layout', ['layout' => null])->assertOk()->assertJsonPath('layout', null); // back to the standard Home
});

it('lands everyone in the new back office after signing in, their own home inside it, with the classic one a click away', function () {
    $resp = fn ($u) => (new HomeLoginResponse)->toResponse(tap(Request::create('/'), fn ($r) => $r->setUserResolver(fn () => $u)))->getTargetUrl();
    expect($resp($this->owner))->toEndWith('/office/');
    $this->actingAs($this->owner, 'web');
    $this->get('/admin/sales')->assertOk()->assertSee('New back office');
});

<?php

use App\Support\OfficeUrl;
use App\Support\UserPrefs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Part 42: what the server sends opens the new back office. The map (resources/office-routes.json) comes from the new back
// office's own address map (`npm run office:routes`; the pos tests check it against that map) and must hold every page of
// the current back office; saved addresses are moved once by a migration.

it('holds every page of the current back office in the map', function () {
    $map = collect(json_decode((string) file_get_contents(resource_path('office-routes.json')), true)['routes'])->pluck('office', 'admin');
    $missing = [];
    foreach (Route::getRoutes() as $route) {
        $uri = '/'.$route->uri();
        if (in_array('GET', $route->methods(), true) && ($uri === '/admin' || str_starts_with($uri, '/admin/')) && ! $map->has($uri)) {
            $missing[] = $uri;
        }
    }
    expect($missing)->toBe([])->and($map->filter(fn ($o) => $o === null)->keys()->all())->toBe([]); // `npm run office:routes` in pos
});

it('turns current-back-office addresses into the new ones, and leaves others alone', function () {
    $id = (string) Str::uuid7();
    expect(OfficeUrl::from('/admin'))->toBe('/office/')
        ->and(OfficeUrl::from(url("/admin/sales/{$id}")))->toBe("/office/sales/{$id}")
        ->and(OfficeUrl::from('/admin/products/create'))->toBe('/office/products/new')
        ->and(OfficeUrl::from("/admin/crm-leads/{$id}/edit"))->toBe("/office/crm/leads/{$id}")
        ->and(OfficeUrl::from('/admin/invoices?tableSearch=INV-7'))->toBe('/office/invoices?q=INV-7')
        ->and(OfficeUrl::from('/admin/import-data?type=products'))->toBe('/office/import?type=products')
        ->and(OfficeUrl::from('/admin/login'))->toBe('/office/sign-in')
        ->and(OfficeUrl::from('/office/sales'))->toBe('/office/sales')
        ->and(OfficeUrl::from('/pos/'))->toBe('/pos/')
        ->and(OfficeUrl::from('https://evil.example/admin/sales'))->toBe('https://evil.example/admin/sales')
        ->and(OfficeUrl::of('/admin/tickets/{record}', ['record' => $id]))->toBe("/office/tickets/{$id}");
});

it('keeps favourites, the home page and Home tiles at new-look addresses, and moves the saved ones', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $this->actingAs(withMfa($owner), 'web');
    $this->postJson('/admin-ui/favourite', ['url' => '/admin/products', 'label' => 'Products'])->assertOk()->assertJsonPath('favourites.0.url', '/office/products');
    $this->postJson('/admin-ui/favourite', ['url' => '/office/sales', 'label' => 'Till sales'])->assertOk()->assertJsonPath('favourites.1.url', '/office/sales');
    $this->postJson('/admin-ui/favourite', ['url' => 'https://evil.example/office/x', 'label' => 'X'])->assertStatus(422);

    // Saved before part 42: moved by the migration.
    $id = (string) Str::uuid7();
    DB::table('users')->where('id', $owner->id)->update([
        'ui_favourites' => json_encode([['url' => '/admin/stock-levels', 'label' => 'Stock'], ['url' => "/admin/sales/{$id}", 'label' => 'A sale']]),
        'ui_prefs' => json_encode(['home' => '/admin/stock-levels', 'rows' => 25, 'office_home' => ['cols' => 3, 'panels' => [], 'tiles' => [['url' => '/admin/invoices', 'label' => 'Invoices']], 'meter' => null]]),
    ]);
    $owner->notifications()->create(['id' => (string) Str::uuid7(), 'type' => 'Filament\Notifications\DatabaseNotification',
        'data' => ['title' => 'Lead given to you', 'actions' => [['name' => 'open', 'url' => url("/admin/crm-leads/{$id}/edit")]]]]);
    (require database_path('migrations/2027_01_10_100001_office_addresses.php'))->up();
    $u = $owner->fresh();
    expect(collect($u->ui_favourites)->pluck('url')->all())->toBe(['/office/stock', "/office/sales/{$id}"])
        ->and($u->ui_prefs['home'])->toBe('/office/stock')->and($u->ui_prefs['rows'])->toBe(25)
        ->and($u->ui_prefs['office_home']['tiles'][0]['url'])->toBe('/office/invoices')
        ->and($owner->notifications()->first()->data['actions'][0]['url'])->toBe("/office/crm/leads/{$id}");
    // Signing in lands on that home page, in the new look.
    expect(UserPrefs::officeHomeUrl($u))->toBe(url('/office/stock'));
});

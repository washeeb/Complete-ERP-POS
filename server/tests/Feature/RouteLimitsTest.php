<?php

use Illuminate\Support\Facades\Route;

// Laravel keys a plain limit (throttle:30,1) by person, or by address for someone not signed in: every route with one
// shared a single counter. Found 2026-11-16: the new back office's own calls used up the favourite star's 30 a minute
// ("Too many changes"), and adding to an online cart counted against checking out. Each limit now has its own name.

it('gives every request limit its own counter', function () {
    $shared = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'livewire')) {
            continue; // the Livewire library's own upload route: the only plain limit left, alone on its counter
        }
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && preg_match('/^throttle:\d+,\d+$/', $m)) {
                $shared[] = $route->uri().' '.$m;
            }
        }
    }
    expect($shared)->toBe([]);
});

it('keeps the favourite star working while the new back office is in use', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $this->actingAs(withMfa($owner->fresh()), 'web');
    foreach (range(1, 35) as $_) { // more than the star's 30 a minute, on the new back office's own counter
        $this->getJson('/office-api/todo-count')->assertOk();
    }
    $this->postJson('/admin-ui/favourite', ['url' => '/admin/products', 'label' => 'Products'])->assertOk()->assertJsonPath('favourites.0.url', '/admin/products');
});

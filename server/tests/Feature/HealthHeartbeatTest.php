<?php

use App\Domain\Support\SystemHealth;
use App\Filament\Pages\SystemHealthPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// A real (file) cache, as on servers: values go through serialize() and objects do not come back.
beforeEach(fn () => config(['cache.default' => 'file']));
afterEach(fn () => Cache::flush());

it('reads the scheduler heartbeat back from a file cache', function () {
    SystemHealth::beat('scheduler');
    SystemHealth::beat('relay');
    $checks = collect(app(SystemHealth::class)->run())->keyBy('check');
    expect($checks['Scheduler']['status'])->toBe('ok')
        ->and($checks['Relay (live updates, messages, webhooks)']['status'])->toBe('ok');
});

it('shows the health page when an old heartbeat (a date object) is still in the cache', function () {
    [, $owner] = installCompany();
    Cache::put('erp:heartbeat:scheduler', now(), now()->addDay()); // written by earlier versions
    expect(SystemHealth::lastBeat('scheduler'))->toBeNull();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($owner), 'web');
    Livewire::test(SystemHealthPage::class)->assertOk()->assertSee('Scheduler');
});

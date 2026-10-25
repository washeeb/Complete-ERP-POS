<?php

use App\Domain\Support\SystemHealth;
use App\Filament\Pages\SystemHealthPage;
use App\Models\SyncPeer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

it('counts only changes head office has not received as not yet synced', function () {
    [$tenant] = installCompany();
    config(['erp.node.role' => 'branch', 'erp.node.id' => 'BR1']);
    DB::table('outbox_events')->update(['occurred_at' => now()->subHours(2)]); // sync never marks events sent: not a backlog by itself
    $waiting = fn () => collect(app(SystemHealth::class)->run())->keyBy('check')['Changes not yet synced']['detail'];
    expect($waiting())->toBe('0');

    $peer = new SyncPeer;
    $peer->forceFill(['tenant_id' => $tenant->id, 'node_id' => 'HQ', 'name' => 'Head office', 'role' => 'hq', 'secret' => 'made-up-secret',
        'groups' => [], 'origin_node' => 'BR1'])->save();
    $group = $peer->groupsWeSend()[0];
    DB::table('outbox_events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'origin_node' => 'BR1', 'aggregate_type' => 'sale',
        'aggregate_id' => (string) Str::uuid7(), 'aggregate_version' => 1, 'event_type' => 'sale.posted', 'payload' => '{}', 'occurred_at' => now()->subHour(), 'sync_group' => $group]);
    expect($waiting())->toBe('1');

    $peer->forceFill(['last_pushed_seq' => (int) DB::table('outbox_events')->max('seq')])->save();
    expect($waiting())->toBe('0');
});

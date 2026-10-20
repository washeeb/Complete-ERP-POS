<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Remote\DeviceHealth;
use App\Domain\Support\AccessDenied;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Till;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-21 10:55', 'Africa/Accra')); // a trading morning, before the till signs in
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Branch::find($this->branches['HQ']->id);
    $this->hq->forceFill(['timezone' => 'Africa/Accra'])->save();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'Till 1 PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '4826');
    $this->token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->it = withMfa(staff($this->owner, 'it', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id));
    $this->health = app(DeviceHealth::class);
});

it('keeps the till\'s health and names what is wrong in plain words', function () {
    $this->withToken($this->token)->postJson('/api/v1/pos/health', ['app' => 'dev', 'queue_pending' => 4, 'queue_review' => 1, 'oldest_pending_min' => 45,
        'storage_free_mb' => 120, 'latency_ms' => 80, 'printer' => 'EPSON TM-T20', 'secret' => str_repeat('x', 5000)])->assertOk()->assertJson(['commands' => []]);
    $d = Device::find($this->device->id);
    expect($d->health['printer'])->toBe('EPSON TM-T20')->and($d->health)->not->toHaveKey('secret')
        ->and($this->health->problems($d))->toBe(['4 item(s) waiting to be sent for 45 min', '1 item(s) refused by the server', 'storage nearly full (120 MB free)']);

    // Silent during trading hours: offline; at night it is not a problem. IT is told once a day.
    Carbon::setTestNow(Carbon::parse('2026-10-21 11:40', 'Africa/Accra'));
    expect($this->health->problems($d->fresh())[0])->toStartWith('not heard from since');
    expect($this->health->alert($this->tenant->id))->toBeGreaterThan(0)->and($this->health->alert($this->tenant->id))->toBe(0);
    Carbon::setTestNow(Carbon::parse('2026-10-21 23:40', 'Africa/Accra'));
    expect($this->health->problems($d->fresh()))->toBe([]);
});

afterEach(fn () => Carbon::setTestNow());

it('sends a fix to the till with the Remote access add-on, and records what the till did', function () {
    // Without the add-on nobody on the company's side can send fixes.
    expect(fn () => $this->health->queue(actorFor($this->it), $this->device, 'sync_now'))->toThrow(AccessDenied::class);

    activateTestLicense(['features' => ['finance', 'hr', 'remote_access']]);
    LicenseManager::forget();
    $c = $this->health->queue(actorFor($this->it), $this->device, 'collect_logs', 'Till slow since morning');

    $commands = $this->withToken($this->token)->postJson('/api/v1/pos/health', ['app' => 'dev'])->assertOk()->json('commands');
    expect($commands)->toBe([['id' => $c->id, 'command' => 'collect_logs']])->and($c->fresh()->status)->toBe('sent');
    $this->withToken($this->token)->postJson('/api/v1/pos/health', ['app' => 'dev'])->assertJson(['commands' => []]); // handed over once
    $this->withToken($this->token)->postJson("/api/v1/pos/commands/{$c->id}", ['status' => 'done', 'result' => '{"errors":[]}'])->assertOk();
    expect($c->fresh()->status)->toBe('done')->and($c->fresh()->result)->toBe('{"errors":[]}')
        ->and(DB::table('audit_events')->where('action', 'device.fix_queued')->count())->toBe(1);

    app('auth')->forgetGuards();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->it, 'web');
    Livewire::test(ListDevices::class)->assertTableActionVisible('fix', $this->device->id)
        ->callTableAction('fix', $this->device->id, ['command' => 'reload', 'reason' => 'Frozen screen'])->assertNotified('Fix sent');
    expect(DeviceCommand::where('command', 'reload')->value('status'))->toBe('pending');
});

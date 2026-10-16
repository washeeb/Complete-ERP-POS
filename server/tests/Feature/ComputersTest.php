<?php

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\Computers\ComputerResource;
use App\Filament\Resources\Computers\Pages\ListComputers;
use App\Http\Middleware\TrackComputer;
use App\Models\AuditEvent;
use App\Models\Computer;
use App\Support\ClientInfo;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
});

it('gives each computer a lasting ID, lists it with who used it and from where, and lets it be named', function () {
    $first = $this->withHeaders(['X-Forwarded-For' => '41.66.1.2', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/141.0 Safari/537.36'])->get('/admin')->assertOk();
    $key = collect($first->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === TrackComputer::COOKIE)?->getValue();
    expect($key)->not->toBeNull();

    $pc = Computer::sole();
    expect($pc->last_ip)->toBe('41.66.1.2')->and($pc->last_user_name)->toBe($this->owner->name)->and($pc->last_client['browser'])->toBe('Chrome 141 on Windows')
        ->and($pc->label())->toStartWith('Unnamed computer');

    Livewire::test(ListComputers::class)->assertCanSeeTableRecords([$pc])
        ->callTableAction('rename', $pc, ['name' => 'Front desk PC'])->assertHasNoTableActionErrors();
    expect($pc->fresh()->name)->toBe('Front desk PC')
        ->and(AuditEvent::where('action', 'computer.named')->exists())->toBeTrue();
    $this->get(ComputerResource::getUrl())->assertOk()->assertSee('Front desk PC')->assertSee('https://ipinfo.io/41.66.1.2', false);
});

it('records the computer on every change made from it, and reads the desktop app\'s cookie', function () {
    $key = (string) Str::uuid7();
    Computer::withoutGlobalScopes()->forceCreate(['tenant_id' => $this->tenant->id, 'computer_key' => $key, 'name' => 'Office PC', 'first_seen_at' => now(),
        'last_seen_at' => now(), 'origin_node' => 'HQ']);
    $request = Request::create('/admin', 'GET', cookies: [TrackComputer::COOKIE => $key,
        'ses_client' => rawurlencode(json_encode(['computer' => 'OFFICE-PC', 'mac' => 'A4:BB:6D:01:02:03', 'local_ip' => '192.168.1.5']))]);
    expect(ClientInfo::from($request))->toMatchArray(['computer_key' => $key, 'computer' => 'OFFICE-PC', 'mac' => 'A4:BB:6D:01:02:03'])
        ->and(ClientInfo::summary(ClientInfo::from($request)))->toStartWith('Office PC · OFFICE-PC (192.168.1.5)');

    Cache::flush();
    $this->withUnencryptedCookie('ses_client', rawurlencode(json_encode(['computer' => 'OFFICE-PC'])))->withCookie(TrackComputer::COOKIE, $key)
        ->get('/admin')->assertOk();
    expect(Computer::where('computer_key', $key)->value('last_client'))->toMatchArray(['computer' => 'OFFICE-PC']);
    $this->get(AuditEventResource::getUrl('index', ['filters' => ['computer' => ['value' => $key]]]))->assertOk();
});

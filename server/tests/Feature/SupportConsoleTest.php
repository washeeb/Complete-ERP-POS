<?php

use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\HealthReport;
use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\Vendor\LicenseIssuer;
use App\Domain\Remote\RemoteAccess;
use App\Filament\Vendor\Pages\SupportConsole;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorLicense;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Mail::fake();
    config(['license.server.enabled' => true]);
    $issued = LicenseIssuer::issue(['customer' => 'Asante Pharmacy', 'request_code' => LicenseService::installationCode(), 'expires_at' => now()->addYear()->toDateString(),
        'server_url' => 'https://licence.example', 'checkin_days' => 30], TestCase::$licenseKeys); // the vendor tables live in this same test database
    app(LicenseService::class)->activate(actorFor($this->owner), $issued['key']);
    $this->licenceId = $issued['license_id'];
    $client = VendorCustomer::create(['code' => 'ASANTE', 'name' => 'Asante Pharmacy', 'legal_name' => 'Asante Holdings', 'email' => 'owner@asante.test']);
    VendorLicense::whereKey($this->licenceId)->update(['customer_id' => $client->id]);
});

it('reports the server’s health every hour without business figures, only from the licensed installation', function () {
    config(['erp.support_email' => 'support@example.test']);
    app(SupportReports::class)->report(actorFor($this->owner), 'back_office', 'Receipt printer prints blank pages');
    app(RemoteAccess::class)->grant(actorFor($this->owner), ['level' => 'view', 'hours' => 2, 'reason' => 'Printer']);
    $report = app(HealthReport::class)->build();
    expect(collect($report['checks'])->pluck('check'))->toContain('Database', 'Backups')
        ->and($report['support']['open_reports'][0]['summary'])->toContain('Receipt printer')->and($report['support']['access_open'])->toBe(['view'])
        ->and(json_encode($report))->not->toContain('total_minor')->not->toContain('price');

    $this->postJson('/api/license/health', ['installation_id' => 'someone-else'] + $report)->assertNotFound();
    $this->postJson('/api/license/health', $report)->assertOk();
    $l = VendorLicense::find($this->licenceId);
    expect($l->health_at)->not->toBeNull()->and($l->health['support']['access_open'])->toBe(['view'])->and($l->health)->not->toHaveKey('installation_id');

    // Sent by the hourly command; the owner can switch it off.
    Http::fake(['licence.example/*' => Http::response(['ok' => true])]);
    expect(app(HealthReport::class)->send()['ok'])->toBeTrue();
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://licence.example/api/license/health' && $r['license_id'] === $this->licenceId);
    app(Settings::class)->set(actorFor($this->owner), 'support.share_health', false);
    expect(app(HealthReport::class)->send())->toMatchArray(['ok' => false, 'message' => 'Sharing system health with support is switched off.']);
    Http::assertSentCount(1);
});

it('shows every client on the support console, worst first, and the silent ones', function () {
    $this->postJson('/api/license/health', app(HealthReport::class)->build())->assertOk();
    $l = VendorLicense::find($this->licenceId);
    expect(SupportConsole::state($l))->toBeIn(['fail', 'warning', 'ok']); // a test server has no backups yet: "fail"
    $this->travel(4)->hours();
    expect(SupportConsole::state($l->fresh()))->toBe('silent');

    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(SupportConsole::class)->assertOk()->assertSee('Asante Pharmacy')->assertSee('Not reporting')
        ->assertTableActionVisible('details', $l)->mountTableAction('details', $l)->assertHasNoErrors();
});

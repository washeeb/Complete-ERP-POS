<?php

use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\Vendor\CheckinServer;
use App\Domain\Licensing\Vendor\VendorBilling;
use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Domain\Support\DomainException;
use App\Filament\Vendor\Pages\Dashboard;
use App\Filament\Vendor\Resources\Licenses\Pages\IssueLicense;
use App\Filament\Vendor\Resources\Licenses\Pages\ViewLicense;
use App\Models\Vendor\VendorAddon;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPlan;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    config(['license.server.enabled' => true, 'license.server.url' => 'https://licence.example']);
    $this->customer = VendorCustomer::create(['code' => 'OHENEBA', 'name' => 'Oheneba Pharmacy', 'legal_name' => 'Oheneba Ventures', 'email' => 'owner@oheneba.test']);
    $this->plan = VendorPlan::create(['code' => 'BUSINESS', 'name' => 'Business', 'branches' => 2, 'devices' => 4, 'users' => 10, 'modules' => ['pharmacy'],
        'features' => ['finance'], 'months' => 12, 'price_minor' => 500000, 'currency' => 'GHS']);
    $this->branch = VendorAddon::create(['code' => 'BRANCH', 'name' => 'Extra branch', 'branches' => 1, 'devices' => 2, 'price_minor' => 150000, 'currency' => 'GHS']);
    $this->hr = VendorAddon::create(['code' => 'HR', 'name' => 'HR & payroll', 'features' => ['hr'], 'price_minor' => 100000, 'currency' => 'GHS']);
    $this->svc = app(VendorLicensing::class);
    $this->code = LicenseService::installationCode();
});

function vIssue($t, array $extra = []): VendorLicense
{
    return $t->svc->issue(actorFor($t->owner), ['customer_id' => $t->customer->id, 'request_code' => $t->code, 'plan_id' => $t->plan->id,
        'addons' => [['addon_id' => $t->branch->id, 'qty' => 2], ['addon_id' => $t->hr->id, 'qty' => 1]], 'site' => 'Cloud server',
        'starts_on' => '2026-10-19', 'invoice' => true] + $extra, TestCase::$licenseKeys);
}

it('issues a licence from a plan with add-ons, signed, invoiced and audited', function () {
    $l = vIssue($this);
    expect($l->payload['limits'])->toEqualCanonicalizing(['branches' => 4, 'devices' => 8, 'users' => 10])
        ->and($l->payload['features'])->toBe(['finance', 'hr'])->and($l->payload['modules'])->toBe(['pharmacy'])
        ->and($l->payload['customer'])->toBe('Oheneba Ventures')->and($l->payload['server_url'])->toBe('https://licence.example')
        ->and($l->price_minor)->toBe(500000 + 2 * 150000 + 100000)->and($l->expires_on->toDateString())->toBe('2027-10-18')
        ->and($l->status())->toBe('active')->and($l->license_key)->toStartWith('SYN1-');
    $inv = VendorInvoice::sole();
    expect($inv->total_minor)->toBe($l->price_minor)->and($inv->number)->toBe('SYN-'.now()->format('Y').'-0001')->and($inv->lines)->toHaveCount(3)
        ->and(DB::table('audit_events')->where('action', 'vendor.license.issued')->count())->toBe(1);

    // Typed-in limits win over the package; a trial is free and 30 days.
    $trial = $this->svc->issue(actorFor($this->owner), ['customer_id' => $this->customer->id, 'request_code' => $this->code, 'kind' => 'trial', 'starts_on' => '2026-10-19',
        'branches' => 1, 'modules' => null, 'features' => []], TestCase::$licenseKeys);
    expect($trial->payload['limits']['branches'])->toBe(1)->and($trial->payload['limits']['users'])->toBeNull()->and($trial->payload['modules'])->toBeNull()
        ->and($trial->payload['features'])->toBe([])->and($trial->price_minor)->toBe(0)->and($trial->expires_on->toDateString())->toBe('2026-11-18');

    expect(fn () => $this->svc->issue(actorFor($this->owner), ['customer_id' => $this->customer->id, 'request_code' => 'nonsense'], TestCase::$licenseKeys))
        ->toThrow(DomainException::class, 'SYNREQ');
});

it('renews for the next period with the same package and invoices it', function () {
    $l = vIssue($this);
    $r = $this->svc->renew(actorFor($this->owner), $l, [], TestCase::$licenseKeys);
    expect($r->starts_on->toDateString())->toBe('2027-10-19')->and($r->expires_on->toDateString())->toBe('2028-10-18')
        ->and($r->payload['limits'])->toEqualCanonicalizing($l->payload['limits'])->and($r->payload['features'])->toBe(['finance', 'hr'])->and($r->addons)->toHaveCount(2)
        ->and($r->price_minor)->toBe($l->price_minor)->and($l->fresh()->superseded_by)->toBe($r->id)->and(VendorInvoice::count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'vendor.license.renewed')->count())->toBe(1);
});

it('replaces a licence, follows the check-ins and reports health', function () {
    $l = vIssue($this);
    $new = $this->svc->issue(actorFor($this->owner), ['customer_id' => $this->customer->id, 'replaces' => $l->id, 'plan_id' => $this->plan->id,
        'starts_on' => '2027-10-19', 'invoice' => false], TestCase::$licenseKeys);
    expect($new->installation_id)->toBe($l->installation_id)->and($new->replaces)->toBe($l->id)->and($new->site)->toBe('Cloud server')
        ->and($l->fresh()->superseded_by)->toBe($new->id)->and($l->fresh()->status())->toBe('replaced')
        ->and($new->payload['limits']['branches'])->toBe(2);

    // The installation checks in with the old licence: it gets the new key, and is seen.
    $report = ['license_id' => $l->id, 'installation_id' => $l->installation_id, 'fingerprint' => 'abc', 'counts' => ['branches' => 3, 'devices' => 1, 'users' => 2],
        'version' => '0.35.0', 'nonce' => 'n'];
    $answer = CheckinServer::answer($report, '203.0.113.10', TestCase::$licenseKeys)['answer'];
    expect($answer['license_key'])->toBe($new->license_key)->and($l->fresh()->last_checkin_at)->not->toBeNull();
    CheckinServer::answer(['license_id' => $new->id] + $report, '203.0.113.10', TestCase::$licenseKeys);
    $h = VendorLicensing::health($new->fresh());
    expect($h['over'])->toBe(['branches 3 of 2'])->and($h['version'])->toBe('0.35.0')->and($h['machines'])->toBe(1);

    $this->svc->revoke(actorFor($this->owner), $new, 'Not paid');
    expect($new->fresh()->status())->toBe('revoked')
        ->and(CheckinServer::answer(['license_id' => $new->id] + $report, null, TestCase::$licenseKeys)['answer']['status'])->toBe('revoked');
    expect(fn () => $this->svc->issue(actorFor($this->owner), ['customer_id' => $this->customer->id, 'replaces' => $new->id], TestCase::$licenseKeys))
        ->toThrow(DomainException::class, 'revoked');
});

it('knows when a licence is ending, in grace or ended', function () {
    $l = vIssue($this, ['starts_on' => now()->toDateString(), 'expires_on' => now()->addDays(90)->toDateString()]);
    expect($l->status())->toBe('active');
    Carbon::setTestNow(now()->addDays(40));
    expect($l->status())->toBe('expiring');
    Carbon::setTestNow(now()->addDays(55));
    expect($l->status())->toBe('grace');
    Carbon::setTestNow(now()->addDays(20));
    expect($l->status())->toBe('expired');
    Carbon::setTestNow();
});

it('records payments against invoices, and voids only unpaid ones', function () {
    $l = vIssue($this);
    $inv = VendorInvoice::sole();
    $billing = app(VendorBilling::class);
    $billing->pay(actorFor($this->owner), $inv, 400000, 'momo', 'MP2610.1234.A');
    expect($inv->fresh()->status)->toBe('open')->and($inv->fresh()->balanceMinor())->toBe(500000);
    expect(fn () => $billing->pay(actorFor($this->owner), $inv, 600000, 'bank', null))->toThrow(DomainException::class, 'still owed');
    $billing->pay(actorFor($this->owner), $inv, 500000, 'bank', 'ABSA slip 77');
    expect($inv->fresh()->status)->toBe('paid')->and(VendorBilling::figures()['collected_month'])->toBe(900000);
    expect(fn () => $billing->void(actorFor($this->owner), $inv, 'Mistake'))->toThrow(DomainException::class, 'payments');
    expect(VendorBilling::figures()['arr'])->toBeGreaterThan(890000)->toBeLessThan(910000);
});

it('reminds the customer and SyncLytics staff once per step before the end', function () {
    vIssue($this, ['starts_on' => now()->subMonths(11)->toDateString(), 'expires_on' => now()->addDays(25)->toDateString()]);
    $this->artisan('vendor:license-reminders')->assertSuccessful();
    $this->artisan('vendor:license-reminders')->assertSuccessful();
    expect(DB::table('vendor_reminders')->pluck('kind')->all())->toBe(['30'])
        ->and(DB::table('messages')->where('channel', 'email')->where('to', 'owner@oheneba.test')->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $this->owner->id)->count())->toBeGreaterThan(0);
});

it('lets only SyncLytics owners into the generator, and its screens work', function () {
    $staff = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, setUpBranches($this->owner)['HQ']->id);
    expect(VendorLicensing::allowed($staff))->toBeFalse()->and(VendorLicensing::allowed($this->owner))->toBeTrue();
    config(['license.server.staff' => 'ama@demo.test']);
    expect(VendorLicensing::allowed($staff->fresh()))->toBeTrue();
    config(['license.server.enabled' => false]);
    expect(VendorLicensing::allowed($this->owner))->toBeFalse();
    config(['license.server.enabled' => true]);

    $l = vIssue($this);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(Dashboard::class)->assertOk();
    Livewire::test(ViewLicense::class, ['record' => $l->id])->assertSee('Oheneba Pharmacy')->assertSee($l->short());
    Livewire::test(IssueLicense::class)->assertFormFieldExists('request_code')->assertFormFieldExists('plan_id');
    $this->get(route('vendor.licenses.certificate', $l))->assertOk()->assertSee('LICENCE CERTIFICATE')->assertSee('Oheneba Ventures');
    $this->get(route('vendor.invoices.print', VendorInvoice::sole()))->assertOk()->assertSee('SYN-');
    $this->get(route('vendor.licenses.csv'))->assertOk();
    $this->actingAs($staff->fresh()->forceFill(['email' => 'x@demo.test']));
    config(['license.server.staff' => '']);
    $this->get(route('vendor.licenses.csv'))->assertForbidden();
});

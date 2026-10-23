<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Domain\Remote\RemoteAccess;
use App\Domain\Support\AccessDenied;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\SupportAccessGrants\Pages\ListSupportAccessGrants;
use App\Livewire\ReportProblem;
use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\SupportAccessGrant;
use App\Models\SupportReport;
use App\Models\Till;
use App\Models\User;
use App\Models\Vendor\VendorAddon;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Branch::find($this->branches['HQ']->id);
    $this->a = actorFor($this->owner);
    $this->remote = app(RemoteAccess::class);
    config(['erp.support_email' => 'support@example.test']);
    Mail::fake();
    Storage::fake('local');
});

it('asks during trading hours and connects unattended outside them, per branch', function () {
    $this->hq->forceFill(['timezone' => 'Africa/Accra'])->save();
    expect($this->remote->consentFor($this->hq, Carbon::parse('2026-10-21 10:00', 'Africa/Accra')))->toBe('ask')
        ->and($this->remote->consentFor($this->hq, Carbon::parse('2026-10-21 22:30', 'Africa/Accra')))->toBe('unattended')
        ->and($this->remote->consentFor($this->hq, Carbon::parse('2026-10-21 06:59', 'Africa/Accra')))->toBe('unattended');

    // A night shop (18:00-06:00) and a branch that always asks.
    $this->hq->forceFill(['settings' => ['remote.trading_hours' => '18:00-06:00']])->save();
    expect($this->remote->consentFor($this->hq->fresh(), Carbon::parse('2026-10-21 23:00', 'Africa/Accra')))->toBe('ask')
        ->and($this->remote->consentFor($this->hq->fresh(), Carbon::parse('2026-10-21 12:00', 'Africa/Accra')))->toBe('unattended');
    $this->hq->forceFill(['settings' => ['remote.consent' => 'ask']])->save();
    expect($this->remote->consentFor($this->hq->fresh(), Carbon::parse('2026-10-21 03:00', 'Africa/Accra')))->toBe('ask')
        ->and(RemoteAccess::hours('nonsense'))->toBe([0, 1440]); // unreadable hours: always ask
});

it('is a paid add-on for the company\'s staff, not included in "all features"', function () {
    $it = staff($this->owner, 'it', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id);
    activateTestLicense(['features' => null]);
    LicenseManager::forget();
    expect($this->remote->licensed())->toBeFalse()->and($this->remote->staffLevel($it, $this->hq))->toBeNull();

    activateTestLicense(['features' => ['finance', 'hr', 'remote_access']]);
    LicenseManager::forget();
    expect($this->remote->licensed())->toBeTrue()->and($this->remote->staffLevel($it, $this->hq))->toBe('control');

    // The licence generator: a plan with all features plus the Remote access add-on names it explicitly.
    $addon = VendorAddon::forceCreate(['code' => 'REMOTE', 'name' => 'Remote access', 'features' => ['remote_access'], 'price_minor' => 50000]);
    $pkg = app(VendorLicensing::class)->package(null, [['addon_id' => $addon->id, 'qty' => 1]]);
    expect($pkg['features'])->toContain('finance', 'hr', 'remote_access');
});

it('lets the owner give SyncLytics support access for a time or until a problem is closed, and withdraw it', function () {
    expect($this->remote->supportLevel($this->hq))->toBeNull();
    $g = $this->remote->grant($this->a, ['level' => 'view', 'hours' => 2, 'reason' => 'Training on stock counts']);
    expect($this->remote->supportLevel($this->hq))->toBe('view');
    Mail::assertSent(PlainMessage::class, fn ($m) => str_contains($m->subjectLine, 'Support access granted'));

    $this->travel(3)->hours();
    expect($this->remote->supportLevel($this->hq))->toBeNull(); // ended by itself
    $this->travelBack();
    $this->remote->revoke($this->a, $g); // back to today: withdraw the first one

    $report = app(SupportReports::class)->report($this->a, 'back_office', 'Printer on till 2 prints blank receipts');
    $this->remote->grant($this->a, ['level' => 'control', 'support_report_id' => $report->id, 'branch_id' => $this->hq->id, 'reason' => 'Problem '.$report->number]);
    expect($this->remote->supportLevel($this->hq))->toBe('control')
        ->and($this->remote->supportLevel(Branch::find($this->branches['BA']->id)))->toBeNull(); // one branch only
    $report->forceFill(['status' => 'closed'])->save();
    expect($this->remote->supportLevel($this->hq))->toBeNull(); // closed problem ends it

    $g2 = $this->remote->grant($this->a, ['level' => 'control', 'hours' => 4, 'reason' => 'Updates']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    $this->get('/admin')->assertSee('SyncLytics support can see and control all devices');
    Livewire::test(ListSupportAccessGrants::class)->assertSee('Updates')->callTableAction('revoke', $g2->id)->assertNotified('Support access withdrawn');
    expect($this->remote->supportLevel($this->hq))->toBeNull()->and($g2->fresh()->revoked_by_name)->toBe($this->owner->name);

    // Only someone allowed to may give it.
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->hq->id);
    expect(fn () => $this->remote->grant(actorFor($cashier), ['level' => 'view', 'hours' => 1, 'reason' => 'x']))->toThrow(AccessDenied::class);
});

it('keeps a picture of the screen with a problem report (till and back office) and e-mails it to support', function () {
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$device, $code] = app(DeviceService::class)->register($this->a, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($this->a, $cashier, '4826');
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/pos/problems', ['message' => 'Total looks wrong', 'screen' => 'sell', 'screenshot' => "data:image/png;base64,{$png}"])->assertCreated();
    $r = SupportReport::sole();
    expect($r->screenshot_path)->toBe("support-screenshots/{$r->id}.png");
    Storage::disk('local')->assertExists($r->screenshot_path);
    Mail::assertSent(PlainMessage::class, fn ($m) => $m->attachmentPath === $r->screenshot_path);

    // Back office: an attached picture, and support may connect until it is solved.
    app('auth')->forgetGuards();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ReportProblem::class)->set('message', 'Stock count will not post')->set('screenshot', UploadedFile::fake()->image('screen.png', 400, 300))
        ->set('letSupportConnect', true)->call('send')->assertHasNoErrors();
    $r2 = SupportReport::where('source', 'back_office')->sole();
    expect($r2->screenshot_path)->not->toBeNull()
        ->and(SupportAccessGrant::where('support_report_id', $r2->id)->value('level'))->toBe('control');
    $this->get(route('support.screenshot', $r2))->assertOk();
});

it('opens a device on the remote support relay only for those allowed, and logs each connection', function () {
    config(['remote.relay_url' => 'https://remote.example.test']);
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$device] = app(DeviceService::class)->register($this->a, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $device->forceFill(['status' => 'active', 'last_client' => ['computer' => 'TILL-01']])->save();
    $this->hq->forceFill(['settings' => ['remote.consent' => 'ask']])->save();

    // Staff: the add-on plus the permission; a view-only person cannot take control.
    activateTestLicense(['features' => ['finance', 'remote_access']]);
    LicenseManager::forget();
    $it = staff($this->owner, 'it', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id);
    $this->actingAs($it)->get(route('remote.connect', [$device, 'control']))->assertOk()
        ->assertSee('https://remote.example.test/?gotodevicename=TILL-01&amp;viewmode=11', false)
        ->assertSee('will be asked to accept');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListDevices::class)->assertTableActionVisible('view_screen', $device)->assertTableActionVisible('take_control', $device)
        ->assertActionVisible('remote_agent');
    $clerk = staff($this->owner, 'ama', 2);
    $this->actingAs($clerk)->get(route('remote.connect', [$device, 'view']))->assertForbidden();

    // Support: only while the owner's grant is in force, and only at its level.
    $this->artisan('erp:support-account', ['--email' => 'support@synclytics.test'])
        ->expectsQuestion('Password for the support account (12+ characters)', 'a-long-support-password')->assertSuccessful();
    $support = User::where('email', 'support@synclytics.test')->sole();
    $this->actingAs($support)->get(route('remote.connect', [$device, 'view']))->assertForbidden();
    $this->remote->grant($this->a, ['level' => 'view', 'hours' => 2, 'reason' => 'Printer help']);
    $this->actingAs($support)->get(route('remote.connect', [$device, 'view']))->assertOk();
    $this->actingAs($support)->get(route('remote.connect', [$device, 'control']))->assertForbidden();

    expect(AuditEvent::where('subject_id', $device->id)->whereIn('action', ['remote.control_opened', 'remote.view_opened'])->count())->toBe(2);

    // No relay configured: nothing to open.
    config(['remote.relay_url' => '']);
    $this->actingAs($it)->get(route('remote.connect', [$device, 'view']))->assertNotFound();
});

<?php

use App\Domain\Access\AccessService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\MachineFingerprint;
use App\Domain\Licensing\ReleaseManifest;
use App\Domain\Licensing\Signer;
use App\Domain\Licensing\Vendor\CheckinServer;
use App\Domain\Licensing\Vendor\LicenseIssuer;
use App\Domain\Licensing\Watermark;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Filament\Pages\LicensePage;
use App\Models\Customer;
use App\Models\License;
use App\Models\Role;
use App\Models\Till;
use App\Security\Authorizer;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->licenses = app(LicenseService::class);
});

/** Replace the licence (as the vendor would issue it) and forget the cached state. */
function relicense(array $over = []): string
{
    $key = activateTestLicense($over);
    LicenseManager::forget();

    return $key;
}

function newCustomer($t, string $name = 'Ama'): Customer
{
    return app(CustomerService::class)->save(actorFor($t->owner), new Customer, ['name' => $name, 'phone' => '024'.str_pad((string) (crc32($name) % 10000000), 7, '0', STR_PAD_LEFT)]);
}

it('starts unactivated: nothing can be changed until a genuine key for this installation is entered', function () {
    $cashier = staff($this->owner, 'efua', 2, 'teller');
    License::query()->update(['is_current' => false]);
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status()['state'])->toBe('unlicensed')
        ->and(fn () => newCustomer($this))->toThrow(LicenseException::class, 'not activated');

    // The back office sends everyone to the licence page.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner))->get('/admin')->assertRedirect(LicensePage::getUrl());
    $this->get(LicensePage::getUrl())->assertOk()->assertSee('Not activated')->assertSee('SYNREQ-');

    // A key signed with someone else's key, or for another installation, is refused.
    $pair = sodium_crypto_sign_keypair();
    $forged = LicenseIssuer::issue(['customer' => 'Pirate', 'request_code' => LicenseService::installationCode(), 'expires_at' => '2099-12-31'],
        ['kid' => 'dev', 'secret' => sodium_crypto_sign_secretkey($pair)], record: false);
    expect(fn () => $this->licenses->activate(actorFor($this->owner), $forged['key']))->toThrow(LicenseException::class, 'signature check failed');
    $other = LicenseIssuer::issue(['customer' => 'Other', 'request_code' => 'SYNREQ-'.Signer::b64(json_encode(['installation_id' => 'someone-else'])),
        'expires_at' => '2099-12-31'], TestCase::$licenseKeys, record: false);
    expect(fn () => $this->licenses->activate(actorFor($this->owner), $other['key']))->toThrow(LicenseException::class, 'different installation');
    expect(fn () => $this->licenses->activate(actorFor($cashier), $other['key']))->toThrow(AccessDenied::class);

    relicense();
    expect(newCustomer($this)->exists)->toBeTrue();
});

it('warns before expiry, keeps working through the grace period, then turns read-only with no output', function () {
    relicense(['expires_at' => now()->addDays(10)->toDateString()]);
    expect(app(LicenseManager::class)->status())->toMatchArray(['state' => 'warning', 'read_only' => false]);

    Carbon::setTestNow(now()->addDays(15)); // expired 5 days ago: grace
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status()['state'])->toBe('grace')->and(newCustomer($this, 'Kofi')->exists)->toBeTrue();

    Carbon::setTestNow(now()->addDays(20)); // grace over
    LicenseManager::forget();
    $s = app(LicenseManager::class)->status();
    expect($s['state'])->toBe('read_only')->and($s['message'])->toContain('grace period has ended')
        ->and(fn () => newCustomer($this, 'Yaw'))->toThrow(LicenseException::class, 'grace period has ended');

    // No printing; tills cannot sign in or sell, and their queued work is kept for later (423, not refused).
    $this->actingAs($this->owner)->get(route('print.statement', ['customer' => Customer::value('id'), 'from' => now()->toDateString(), 'to' => now()->toDateString()]))->assertStatus(423)->assertSee('Printing and downloads are switched off');
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $cashier = LicenseManager::bypass(fn () => staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id));
    LicenseManager::bypass(fn () => app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '482613'));
    [$device, $code] = LicenseManager::bypass(fn () => app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'T', 'kind' => 'pos']));
    $token = LicenseManager::bypass(fn () => $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token'));
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '482613'])->assertStatus(423)->assertJson(['error' => 'license_read_only']);

    // Renewal brings everything back at once.
    relicense(['expires_at' => now()->addYear()->toDateString()]);
    expect(newCustomer($this, 'Yaw')->exists)->toBeTrue();
    Carbon::setTestNow();
});

it('holds the installation to its licensed branches, devices, users, modules and features', function () {
    relicense(['branches' => 2, 'users' => 3, 'devices' => 0, 'features' => ['finance'], 'modules' => ['grocery']]);
    expect(fn () => app(OrgService::class)->createBranch(actorFor($this->owner), ['code' => 'BX', 'name' => 'Extra']))->toThrow(LicenseException::class, 'allows 2 branches');
    staff($this->owner, 'u1', 2);
    staff($this->owner, 'u2', 2); // with the owner: 3
    expect(fn () => staff($this->owner, 'u3', 2))->toThrow(LicenseException::class, 'allows 3 active users');
    expect(fn () => app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id,
        'till_id' => Till::first()->id, 'name' => 'T', 'kind' => 'pos']))->toThrow(LicenseException::class, 'tills and devices');
    expect(fn () => app(ModuleService::class)->setStatus(actorFor($this->owner), $this->branches['HQ'], 'pharmacy', 'enabled'))
        ->toThrow(LicenseException::class, 'not in your licence');

    // HR is not licensed: its permissions disappear, even for the owner; finance still works.
    $auth = app(Authorizer::class);
    $auth->forget();
    expect($auth->allows($this->owner->fresh(), 'payroll.prepare'))->toBeFalse()->and($auth->allows($this->owner->fresh(), 'finance.journal'))->toBeTrue();
});

it('asks to be re-activated when moved to another server, and refuses a licence for another installation', function () {
    relicense();
    $p = License::where('is_current', true)->first()->payload;
    $moved = LicenseIssuer::issue(['customer' => 'Test', 'request_code' => 'SYNREQ-'.Signer::b64(json_encode(['installation_id' => $p['installation_id'],
        'fingerprint' => 'another-machine'])), 'expires_at' => now()->addYear()->toDateString()], TestCase::$licenseKeys, record: false);
    $this->licenses->activate(actorFor($this->owner), $moved['key']);
    LicenseManager::forget();
    $s = app(LicenseManager::class)->status();
    if (MachineFingerprint::get() === 'unknown') {
        $this->markTestSkipped('No machine identity on this host.');
    }
    expect($s['state'])->toBe('warning')->and($s['message'])->toContain('different server');
    Carbon::setTestNow(now()->addDays(15));
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status()['state'])->toBe('read_only');
    Carbon::setTestNow();
});

it('checks in with the licence server: confirmed, revoked, renewed, forged answers ignored, and silence eventually locks', function () {
    $req = LicenseService::installationCode();
    $issued = LicenseIssuer::issue(['customer' => 'Test', 'request_code' => $req, 'expires_at' => now()->addYear()->toDateString(), 'server_url' => 'https://licence.example',
        'checkin_days' => 30], TestCase::$licenseKeys); // recorded in the (vendor) tables of this same test database
    $this->licenses->activate(actorFor($this->owner), $issued['key']);
    Http::fake(['licence.example/*' => fn ($request) => Http::response(CheckinServer::answer($request->data(), '10.0.0.1', TestCase::$licenseKeys))]);

    expect($this->licenses->checkIn())->toMatchArray(['ok' => true, 'status' => 'active']);
    expect(DB::table('vendor_checkins')->value('answer'))->toBe('active')->and(json_decode(DB::table('vendor_checkins')->value('report'), true)['counts']['branches'])->toBe(2);

    // A renewal issued by the vendor is handed over at the next check-in.
    $renewed = LicenseIssuer::issue(['customer' => 'Test', 'request_code' => $req, 'expires_at' => now()->addYears(2)->toDateString(), 'server_url' => 'https://licence.example',
        'checkin_days' => 30], TestCase::$licenseKeys);
    DB::table('vendor_licenses')->where('id', $issued['license_id'])->update(['superseded_by' => $renewed['license_id']]);
    $this->licenses->checkIn();
    LicenseManager::forget();
    expect(License::where('is_current', true)->value('id'))->toBe($renewed['license_id']);

    // Revoked: read-only after the check-in.
    DB::table('vendor_licenses')->where('id', $renewed['license_id'])->update(['revoked_at' => now(), 'revoked_reason' => 'Unpaid invoice']);
    expect($this->licenses->checkIn()['status'])->toBe('revoked');
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status())->toMatchArray(['state' => 'read_only'])->and(app(LicenseManager::class)->status()['message'])->toContain('Unpaid invoice');

    // A fake server (wrong key) cannot un-revoke it.
    relicense(['server_url' => 'https://other.test', 'checkin_days' => 30]);
    $pair = sodium_crypto_sign_keypair();
    Http::fake(['other.test/*' => fn ($request) => Http::response(CheckinServer::answer($request->data(), null, ['kid' => 'dev', 'secret' => sodium_crypto_sign_secretkey($pair)]))]);
    expect($this->licenses->checkIn())->toMatchArray(['ok' => false]);

    // Not reaching the server: warning after 30 days, read-only after 30 + 14.
    Carbon::setTestNow(now()->addDays(31));
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status()['state'])->toBe('warning');
    Carbon::setTestNow(now()->addDays(14));
    LicenseManager::forget();
    expect(app(LicenseManager::class)->status()['state'])->toBe('read_only');
    Carbon::setTestNow();
});

it('detects a modified release and trusts only production keys in it', function () {
    $pair = sodium_crypto_sign_keypair();
    $prod = ['kid' => 'syn-test', 'secret' => sodium_crypto_sign_secretkey($pair)];
    config(['license.public_keys' => ['syn-test' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
    $path = base_path(ReleaseManifest::PATH);
    @mkdir(dirname($path), 0755, true);
    $write = function (array $files) use ($path, $prod) {
        $m = ['build_id' => 'BTEST1234', 'customer' => 'Test', 'kid' => $prod['kid'], 'files' => $files];
        $m['sig'] = Signer::sign($m, $prod['secret']);
        file_put_contents($path, json_encode($m));
        LicenseManager::forget();
    };
    try {
        // In a release, the development key is no longer trusted: the test licence stops counting.
        $write(ReleaseManifest::hashes(base_path()));
        expect(ReleaseManifest::check()['ok'])->toBeTrue()->and(app(LicenseManager::class)->status()['read_only'])->toBeTrue();
        expect(fn () => relicense())->toThrow(LicenseException::class, 'signature check failed'); // a dev-signed key is refused in a release

        $issued = LicenseIssuer::issue(['customer' => 'Test', 'request_code' => LicenseService::installationCode(), 'expires_at' => now()->addYear()->toDateString()], $prod, record: false);
        $this->licenses->activate(Actor::system($this->tenant->id, 'Test', 'system'), $issued['key']);
        LicenseManager::forget();
        expect(app(LicenseManager::class)->status()['state'])->toBe('active')->and(Watermark::reference())->toEndWith('·BTEST1234');

        // One guarded file changed → read-only, reported with the file name.
        $files = ReleaseManifest::hashes(base_path());
        $files['app/Security/Authorizer.php'] = str_repeat('0', 64);
        $write($files);
        $s = app(LicenseManager::class)->status();
        expect($s['state'])->toBe('read_only')->and($s['problems'])->toContain('app/Security/Authorizer.php has been changed or removed.')
            ->and($this->licenses->report()['tampered'])->toBeTrue();
    } finally {
        @unlink($path);
        LicenseManager::forget();
    }
});

it('watermarks printed documents with a hidden reference that can be read back', function () {
    relicense();
    $ref = Watermark::reference();
    $hidden = Watermark::stamp('Demo Ltd');
    expect($hidden)->not->toBe('Demo Ltd')->and(preg_replace('/[\x{200B}\x{200C}\x{2060}]/u', '', $hidden))->toBe('Demo Ltd')
        ->and(Watermark::reveal("Copied: {$hidden} …"))->toBe([$ref]);

    $customer = newCustomer($this);
    $html = $this->actingAs($this->owner)->get(route('print.statement', ['customer' => $customer->id, 'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->toDateString()]))->assertOk()->getContent();
    expect(Watermark::reveal($html))->toContain($ref)->and($html)->toContain("Ref {$ref}");
});

it('builds a stamped, signed release whose manifest matches the delivered files', function () {
    $pair = sodium_crypto_sign_keypair();
    $keyFile = tempnam(sys_get_temp_dir(), 'synkey');
    file_put_contents($keyFile, json_encode(['kid' => 'syn-test', 'secret' => base64_encode(sodium_crypto_sign_secretkey($pair))]));
    config(['license.public_keys' => ['syn-test' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'synrel'.bin2hex(random_bytes(4));
    try {
        $this->artisan('release:build', ['--customer' => 'Kofi Stores', '--key' => $keyFile, '--out' => $out, '--without-assets' => true])->assertSuccessful();
        $build = DB::table('vendor_builds')->sole();
        $root = "{$out}/{$build->id}/server";
        $manifest = json_decode(file_get_contents("{$root}/".ReleaseManifest::PATH), true);
        $body = $manifest;
        unset($body['sig']);
        expect(Signer::verify($body, $manifest['sig'], sodium_crypto_sign_publickey($pair)))->toBeTrue()
            ->and($manifest['files'])->toBe(ReleaseManifest::hashes($root))
            ->and($manifest['files'])->toHaveKey('app/Security/Authorizer.php')
            ->and(Watermark::reveal(file_get_contents("{$root}/app/Models/Customer.php")))->toBe(["{$build->id}|Kofi Stores"])
            ->and(file_exists("{$root}/.env"))->toBeFalse()
            ->and(glob("{$out}/ses-labs-kofi-stores-{$build->id}.zip"))->toHaveCount(1);
    } finally {
        @unlink($keyFile);
        File::deleteDirectory($out);
    }
});

it('lets the owner grant roles on a licence without HR, without granting what the granter does not hold', function () {
    // Found on a test PC: Cashier includes HR's "request leave"; with HR not licensed, nobody could grant Cashier.
    relicense(['users' => 10, 'features' => ['finance']]);
    $till = Till::where('branch_id', $this->branches['BA']->id)->first();
    $access = app(AccessService::class);
    $cashier = staff($this->owner, 'kojo', 2);
    $access->grant(actorFor($this->owner), $cashier, Role::where('key', 'teller')->first(), ScopeType::Till, $till->id);
    app(Authorizer::class)->forget();

    // The grant stands, the licence still decides use: the cashier sells, but HR stays switched off.
    expect(app(Authorizer::class)->allows($cashier->fresh(), 'pos.access', $till))->toBeTrue()
        ->and(app(Authorizer::class)->allows($cashier->fresh(), 'leave.request', $till))->toBeFalse();

    // Someone who may grant roles but holds no HR rights (IT Admin) still cannot hand out an HR role.
    $itAdmin = staff($this->owner, 'esi', 4, 'it_admin');
    app(Authorizer::class)->forget();
    expect(fn () => $access->grant(actorFor($itAdmin), staff($this->owner, 'yaw', 4), Role::where('key', 'hr_manager')->first(), ScopeType::Tenant, $this->tenant->id))
        ->toThrow(AccessDenied::class, 'hold all of its permissions');
});

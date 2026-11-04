<?php

use App\Domain\Licensing\LicenseKey;
use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\Signer;
use App\Domain\Licensing\Vendor\LicenseIssuer;
use App\Domain\Licensing\Vendor\SyncDevices;
use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Domain\Support\DomainException;
use App\Filament\Vendor\Resources\SyncDevices\Pages\ListSyncDevices;
use App\Http\Middleware\VerifySyncSignature;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPlan;
use App\Models\Vendor\VendorSyncDevice;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    config(['license.server.enabled' => true, 'license.server.url' => 'https://licence.example']);
    $this->pair = sodium_crypto_sign_keypair();
    $r = app(SyncDevices::class)->create(actorFor($this->owner), 'Test PC');
    $this->code = $r['code'];
    $this->device = app(SyncDevices::class)->register($r['code'], 'Test PC', base64_encode(sodium_crypto_sign_publickey($this->pair)), '127.0.0.1');
});

/** A request signed the way the desktop app signs it. */
function syncCall($t, string $path, array $body, array $headers = [], ?int $time = null, ?string $nonce = null)
{
    $json = json_encode($body);
    $time ??= time();
    $nonce ??= (string) Str::uuid7();
    $sig = sodium_crypto_sign_detached(VerifySyncSignature::message('POST', "api/license/sync/{$path}", (string) $time, $nonce, $json), sodium_crypto_sign_secretkey($t->pair));

    return $t->call('POST', "/api/license/sync/{$path}", [], [], [], array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SYNC_DEVICE' => $t->device->id, 'HTTP_X_SYNC_TIME' => (string) $time, 'HTTP_X_SYNC_NONCE' => $nonce, 'HTTP_X_SYNC_SIGNATURE' => Signer::b64($sig)], $headers), $json);
}

function syncPush($t, array $changes, ?string $key = null)
{
    return syncCall($t, 'push', ['changes' => $changes], ['HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid7()]);
}

function syncCustomer(array $over = []): array
{
    return $over + ['code' => 'ACME', 'name' => 'Acme Stores', 'legal_name' => null, 'contact_name' => null, 'email' => 'owner@acme.test', 'phone' => null, 'whatsapp' => null,
        'address' => null, 'tin' => null, 'industry' => null, 'notes' => null, 'is_active' => true] + $over;
}

function syncKey(array $over = []): array
{
    return LicenseIssuer::issue(['customer' => 'Acme Stores', 'installation' => ['installation_id' => 'inst-1', 'fingerprint' => 'fp'], 'expires_at' => '2027-11-03', 'branches' => 1, 'devices' => 2, 'users' => 5] + $over,
        TestCase::$licenseKeys, 'Test', false);
}

it('registers a device with a one-time code', function () {
    $pair = sodium_crypto_sign_keypair();
    $r = app(SyncDevices::class)->create(actorFor($this->owner), "Owner's PC");
    expect(VendorSyncDevice::find($r['device']->id)->enrol_hash)->toBe(hash('sha256', $r['code']));
    $body = ['code' => $r['code'], 'name' => 'Office PC', 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))];
    $this->postJson('/api/license/sync/register', $body)->assertCreated()->assertJsonPath('name', 'Office PC');
    expect($r['device']->fresh()->status)->toBe('active')->and($r['device']->fresh()->enrol_hash)->toBeNull();
    $this->postJson('/api/license/sync/register', $body)->assertStatus(422); // the code works once
    expect(DB::table('audit_events')->where('action', 'vendor.sync.device_registered')->count())->toBe(2);
});

it('refuses an expired code', function () {
    $r = app(SyncDevices::class)->create(actorFor($this->owner), 'Late');
    $this->travel(16)->minutes();
    expect(fn () => app(SyncDevices::class)->register($r['code'], 'Late', base64_encode(str_repeat('a', 32)), null))->toThrow(DomainException::class);
});

it('refuses unsigned, tampered, stale, replayed and revoked requests', function () {
    $this->postJson('/api/license/sync/pull', ['cursor' => 0])->assertUnauthorized();
    syncCall($this, 'pull', ['cursor' => 0], time: time() - 301)->assertUnauthorized();
    $nonce = (string) Str::uuid7();
    syncCall($this, 'pull', ['cursor' => 0], nonce: $nonce)->assertOk();
    syncCall($this, 'pull', ['cursor' => 0], nonce: $nonce)->assertStatus(409);

    // Body changed after signing.
    $json = json_encode(['cursor' => 0]);
    $time = (string) time();
    $n = (string) Str::uuid7();
    $sig = Signer::b64(sodium_crypto_sign_detached(VerifySyncSignature::message('POST', 'api/license/sync/pull', $time, $n, $json), sodium_crypto_sign_secretkey($this->pair)));
    $this->call('POST', '/api/license/sync/pull', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SYNC_DEVICE' => $this->device->id,
        'HTTP_X_SYNC_TIME' => $time, 'HTTP_X_SYNC_NONCE' => $n, 'HTTP_X_SYNC_SIGNATURE' => $sig], '{"cursor":5}')->assertUnauthorized();

    app(SyncDevices::class)->revoke(actorFor($this->owner), $this->device);
    syncCall($this, 'pull', ['cursor' => 0])->assertUnauthorized();
});

it('answers nothing unless this installation is the licence server', function () {
    config(['license.server.enabled' => false]);
    syncCall($this, 'pull', ['cursor' => 0])->assertNotFound();
    $this->postJson('/api/license/sync/register', ['code' => 'x', 'name' => 'x', 'public_key' => 'x'])->assertNotFound();
});

it('requires https outside development', function () {
    app()->detectEnvironment(fn () => 'production');
    syncCall($this, 'pull', ['cursor' => 0])->assertStatus(426);
});

it('pulls changes after a cursor, each record once, as it stands now', function () {
    $c = VendorCustomer::create(['code' => 'PULL', 'name' => 'First']);
    $c->update(['name' => 'Second']);
    $p = VendorPlan::create(['code' => 'P1', 'name' => 'Plan']);
    $r = syncCall($this, 'pull', ['cursor' => 0])->assertOk()->json();
    $mine = collect($r['changes'])->where('id', $c->id)->values();
    expect($mine)->toHaveCount(1)->and($mine[0]['version'])->toBe(2)->and($mine[0]['data']['name'])->toBe('Second')
        ->and($r['has_more'])->toBeFalse()->and($r['meta']['features'])->toHaveKey('crm')->and($r['meta']['addon_features'])->toContain('iot');
    expect(DB::table('audit_events')->where('action', 'vendor.sync.full_pull')->count())->toBe(1);

    $again = syncCall($this, 'pull', ['cursor' => $r['cursor']])->assertOk()->json();
    expect($again['changes'])->toBe([])->and($again['cursor'])->toBe($r['cursor']);

    $p->delete();
    $del = syncCall($this, 'pull', ['cursor' => $r['cursor']])->json();
    expect($del['changes'][0])->toMatchArray(['entity' => 'plan', 'id' => $p->id, 'deleted' => true]);
});

it('pages through the feed', function () {
    foreach (range(1, 3) as $i) {
        VendorCustomer::create(['code' => "PAGE{$i}", 'name' => "Page {$i}"]);
    }
    $start = (int) DB::table('vendor_sync_changes')->max('seq') - 3;
    $a = syncCall($this, 'pull', ['cursor' => $start, 'limit' => 2])->json();
    expect($a['changes'])->toHaveCount(2)->and($a['has_more'])->toBeTrue();
    $b = syncCall($this, 'pull', ['cursor' => $a['cursor'], 'limit' => 2])->json();
    expect($b['changes'])->toHaveCount(1)->and($b['has_more'])->toBeFalse();
});

it('does not count check-in bookkeeping as a change', function () {
    $c = VendorCustomer::create(['code' => 'V1', 'name' => 'V']);
    expect($c->fresh()->version)->toBe(1);
    $c->update(['updated_at' => now()->addDay()]);
    expect($c->fresh()->version)->toBe(1);
    $c->update(['name' => 'W']);
    expect($c->fresh()->version)->toBe(2);
});

it('accepts a new record, and the same push again changes nothing', function () {
    $id = (string) Str::uuid7();
    $key = (string) Str::uuid7();
    $change = ['entity' => 'customer', 'id' => $id, 'base_version' => 0, 'data' => syncCustomer()];
    syncPush($this, [$change], $key)->assertOk()->assertJsonPath('results.0.status', 'applied')->assertJsonPath('results.0.version', 1);
    syncPush($this, [$change], $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('results.0.status', 'applied');
    expect(VendorCustomer::where('code', 'ACME')->count())->toBe(1)->and(VendorCustomer::find($id)->name)->toBe('Acme Stores');
    expect(DB::table('audit_events')->where('action', 'vendor.sync.created')->where('subject_id', $id)->count())->toBe(1);
});

it('wants an idempotency key on a push', function () {
    syncCall($this, 'push', ['changes' => []])->assertStatus(400);
});

it('applies a change based on the current version and reports a conflict otherwise', function () {
    $c = VendorCustomer::create(['code' => 'CONF', 'name' => 'Before']);
    $data = syncCustomer(['code' => 'CONF', 'name' => 'From app']);
    syncPush($this, [['entity' => 'customer', 'id' => $c->id, 'base_version' => 1, 'data' => $data]])->assertJsonPath('results.0.status', 'applied')->assertJsonPath('results.0.version', 2);
    expect($c->fresh()->name)->toBe('From app');

    $c->fresh()->update(['name' => 'From /vendor']); // version 3
    $r = syncPush($this, [['entity' => 'customer', 'id' => $c->id, 'base_version' => 2, 'data' => syncCustomer(['code' => 'CONF', 'name' => 'App again'])]]);
    $r->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.server.data.name', 'From /vendor')->assertJsonPath('results.0.server.version', 3);
    expect($c->fresh()->name)->toBe('From /vendor');

    syncPush($this, [['entity' => 'customer', 'id' => $c->id, 'base_version' => 3, 'data' => syncCustomer(['code' => 'CONF', 'name' => 'From /vendor'])]])->assertJsonPath('results.0.status', 'unchanged');
});

it('rejects bad records without losing the good ones in the batch', function () {
    $good = (string) Str::uuid7();
    VendorCustomer::create(['code' => 'TAKEN', 'name' => 'Taken']);
    $r = syncPush($this, [
        ['entity' => 'customer', 'id' => (string) Str::uuid7(), 'base_version' => 0, 'data' => syncCustomer(['code' => 'TAKEN'])],
        ['entity' => 'customer', 'id' => $good, 'base_version' => 0, 'data' => syncCustomer(['code' => 'GOOD'])],
        ['entity' => 'wizard', 'id' => (string) Str::uuid7(), 'base_version' => 0, 'data' => ['x' => 1]],
        ['entity' => 'customer', 'id' => (string) Str::uuid7(), 'base_version' => 4, 'data' => syncCustomer(['code' => 'GONE'])],
    ])->assertOk()->json('results');
    expect(array_column($r, 'status'))->toBe(['rejected', 'applied', 'rejected', 'rejected']);
    expect(VendorCustomer::find($good))->not->toBeNull();
});

it('takes a licence only with a key this server trusts, and reads it from the key', function () {
    $c = VendorCustomer::create(['code' => 'LIC', 'name' => 'Acme']);
    $k = syncKey();
    $id = $k['license_id'];
    syncPush($this, [
        ['entity' => 'license', 'id' => $id, 'base_version' => 0, 'data' => ['license_key' => $k['key'], 'customer_id' => $c->id, 'kind' => 'paid', 'price_minor' => 120000, 'currency' => 'GHS', 'expires_on' => '2099-01-01', 'installation_id' => 'forged']],
    ])->assertJsonPath('results.0.status', 'applied');
    $l = VendorLicense::find($id);
    expect($l->expires_on->toDateString())->toBe('2027-11-03')->and($l->installation_id)->toBe('inst-1')->and($l->payload['limits']['users'])->toBe(5)->and($l->version)->toBe(1)->and($l->client->id)->toBe($c->id);
    expect(LicenseKey::verify($l->license_key)['license_id'])->toBe($id);

    // The check-in server now knows it.
    $this->postJson('/api/license/checkin', ['license_id' => $id, 'installation_id' => 'inst-1', 'fingerprint' => 'fp', 'nonce' => 'n'])->assertOk()->assertJsonPath('answer.status', 'active');

    // Signed by someone else.
    $pair = sodium_crypto_sign_keypair();
    $forged = LicenseIssuer::issue(['customer' => 'X', 'installation' => ['installation_id' => 'i', 'fingerprint' => 'f'], 'expires_at' => '2030-01-01'], ['kid' => 'dev', 'secret' => sodium_crypto_sign_secretkey($pair)], 'x', false);
    syncPush($this, [['entity' => 'license', 'id' => $forged['license_id'], 'base_version' => 0, 'data' => ['license_key' => $forged['key']]]])->assertJsonPath('results.0.status', 'rejected');

    // A key that belongs to a different record.
    syncPush($this, [['entity' => 'license', 'id' => (string) Str::uuid7(), 'base_version' => 0, 'data' => ['license_key' => $k['key']]]])->assertJsonPath('results.0.status', 'rejected');
});

it('keeps signed fields out of reach and revocation final', function () {
    $k = syncKey();
    $id = $k['license_id'];
    syncPush($this, [['entity' => 'license', 'id' => $id, 'base_version' => 0, 'data' => ['license_key' => $k['key']]]]);
    $revokedAt = '2026-11-04T10:00:00+00:00';
    syncPush($this, [['entity' => 'license', 'id' => $id, 'base_version' => 1, 'data' => ['license_key' => 'SYN1-other', 'expires_on' => '2099-01-01', 'revoked_at' => $revokedAt, 'revoked_reason' => 'Stopped paying', 'revoked_by' => 'Owner']]])
        ->assertJsonPath('results.0.status', 'applied')->assertJsonPath('results.0.version', 2);
    $l = VendorLicense::find($id);
    expect($l->license_key)->toBe($k['key'])->and($l->revoked_reason)->toBe('Stopped paying')->and($l->status())->toBe('revoked');
    expect(DB::table('audit_events')->where('action', 'vendor.license.revoked')->where('subject_id', $id)->count())->toBe(1);
    $this->postJson('/api/license/checkin', ['license_id' => $id, 'installation_id' => 'inst-1', 'fingerprint' => 'fp', 'nonce' => 'n'])->assertJsonPath('answer.status', 'revoked');

    syncPush($this, [['entity' => 'license', 'id' => $id, 'base_version' => 2, 'data' => ['revoked_at' => null, 'revoked_reason' => null, 'notes' => 'still here']]])->assertJsonPath('results.0.status', 'applied');
    expect(VendorLicense::find($id)->revoked_at)->not->toBeNull()->and(VendorLicense::find($id)->notes)->toBe('still here');
});

it('hands a renewal pushed from the app to the installation at check-in', function () {
    $old = syncKey();
    $new = syncKey(['expires_at' => '2028-11-03']);
    syncPush($this, [
        ['entity' => 'license', 'id' => $new['license_id'], 'base_version' => 0, 'data' => ['license_key' => $new['key'], 'replaces' => $old['license_id']]],
        ['entity' => 'license', 'id' => $old['license_id'], 'base_version' => 0, 'data' => ['license_key' => $old['key'], 'superseded_by' => $new['license_id']]],
    ])->assertJsonPath('results.0.status', 'applied')->assertJsonPath('results.1.status', 'applied');
    $this->postJson('/api/license/checkin', ['license_id' => $old['license_id'], 'installation_id' => 'inst-1', 'fingerprint' => 'fp', 'nonce' => 'n'])->assertJsonPath('answer.license_key', $new['key']);
});

it('feeds licences issued in /vendor to the app, and a cursor only moves forward', function () {
    $customer = VendorCustomer::create(['code' => 'VEND', 'name' => 'Vend']);
    $start = (int) DB::table('vendor_sync_changes')->max('seq');
    $lic = app(VendorLicensing::class)->issue(actorFor($this->owner), ['customer_id' => $customer->id, 'request_code' => LicenseService::installationCode(), 'kind' => 'trial'], TestCase::$licenseKeys);
    $r = syncCall($this, 'pull', ['cursor' => $start])->assertOk()->json();
    expect(collect($r['changes'])->firstWhere('id', $lic->id)['data']['license_key'])->toBe($lic->license_key)->and($r['cursor'])->toBeGreaterThan($start);
});

it('lets staff manage devices in /vendor and offers a code once', function () {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel('vendor');
    Livewire::test(ListSyncDevices::class)->assertCanSeeTableRecords([$this->device])
        ->callAction('new', ['name' => 'Laptop'])->assertHasNoActionErrors();
    expect(VendorSyncDevice::where('name', 'Laptop')->value('status'))->toBe('pending');
    Livewire::test(ListSyncDevices::class)->callTableAction('revoke', $this->device);
    expect($this->device->fresh()->status)->toBe('revoked');
});

it('prunes used nonces and old answers', function () {
    syncCall($this, 'pull', ['cursor' => 0]);
    syncPush($this, []);
    $this->travel(31)->days();
    $this->artisan('license:sync-prune')->assertSuccessful();
    expect(DB::table('vendor_sync_nonces')->count())->toBe(0)->and(DB::table('vendor_sync_requests')->count())->toBe(0);
});

<?php

use App\Domain\Support\AuditVerifier;
use App\Domain\Support\Recorder;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

it('chains every change and verifies clean', function () {
    [$tenant, $owner] = installCompany();
    setUpBranches($owner);

    $result = app(AuditVerifier::class)->verify($tenant->id);

    expect($result['ok'])->toBeTrue()
        ->and($result['checked'])->toBe(AuditEvent::count())
        ->and(AuditEvent::count())->toBeGreaterThan(10);
});

it('blocks updates to audit rows at the database', function () {
    installCompany();
    DB::table('audit_events')->update(['actor_name' => 'someone else']);
})->throws(Exception::class, 'append-only');

it('blocks deletes of audit rows at the database', function () {
    installCompany();
    DB::table('audit_events')->delete();
})->throws(Exception::class, 'append-only');

it('detects a row altered behind the application', function () {
    [$tenant, $owner] = installCompany();
    setUpBranches($owner);
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update');
    DB::table('audit_events')->where('tenant_id', $tenant->id)->where('seq', 3)->update(['actor_name' => 'Forged']);

    $r = app(AuditVerifier::class)->verify($tenant->id);

    expect($r['ok'])->toBeFalse()->and($r['first_bad_seq'])->toBe(3);
});

it('detects deleted latest rows via the chain head', function () {
    [$tenant, $owner] = installCompany();
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update');
    $last = DB::table('audit_events')->where('tenant_id', $tenant->id)->max('seq');
    DB::table('audit_events')->where('tenant_id', $tenant->id)->where('seq', $last)->delete();

    expect(app(AuditVerifier::class)->verify($tenant->id)['ok'])->toBeFalse();
});

it('rolls back the change, its audit row and its outbox event together', function () {
    [$tenant, $owner] = installCompany();
    $before = [AuditEvent::count(), OutboxEvent::count(), Branch::count()];

    expect(fn () => app(Recorder::class)->transaction(function () use ($owner) {
        $b = new Branch(['code' => 'ZZ', 'name' => 'Ghost']);
        $b->tenant_id = $owner->tenant_id;
        app(Recorder::class)->save(actorFor($owner), $b, 'branch.created');
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect([AuditEvent::count(), OutboxEvent::count(), Branch::count()])->toBe($before);
});

it('never writes secrets into the audit log', function () {
    [$tenant, $owner] = installCompany();

    $row = AuditEvent::where('action', 'user.created')->first();

    expect(json_encode($row->changes))->not->toContain('correct-horse')
        ->and($row->changes['password'])->toBe(['[redacted]', '[changed]']);
});

it('stamps origin node and a monotonic outbox sequence', function () {
    [$tenant, $owner] = installCompany();
    setUpBranches($owner);

    $seqs = OutboxEvent::orderBy('seq')->pluck('seq')->all();

    expect(OutboxEvent::first()->origin_node)->toBe('TEST')
        ->and($seqs)->toBe(collect($seqs)->sort()->values()->all())
        ->and(count(array_unique($seqs)))->toBe(count($seqs));
});

it('names the installer, not a fabricated human, as the actor of bootstrap rows', function () {
    installCompany();

    $first = AuditEvent::orderBy('seq')->first();

    expect($first->actor_id)->toBeNull()
        ->and($first->actor_name)->toContain('Installer')
        ->and($first->source)->toBe('cli');
});

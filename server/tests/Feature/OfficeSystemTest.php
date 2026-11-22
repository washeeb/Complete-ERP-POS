<?php

use App\Domain\Launch\SupportReports;
use App\Models\AuditEvent;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\SupportReport;
use App\Security\ScopeType;

// Health, Backups, Licence, Audit log, Problem reports and Integrations in the new back office (part 22).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner); // no IT Admin yet: the owner still does the technical work
});

it('shows health, backups (with a tested folder destination) and the licence', function () {
    $this->actingAs($this->owner, 'web');
    $h = $this->getJson('/office-api/system/health')->assertOk()->json();
    expect($h['checks'])->not->toBeEmpty()->and($h['checks'][0])->toHaveKeys(['check', 'status', 'detail']);

    $bk = $this->getJson('/office-api/system/backups')->assertOk()->json();
    expect(collect($bk['types'])->pluck('id'))->toContain('folder')->and($bk['fields']['s3'])->not->toBeEmpty()
        ->and(collect($bk['fields']['s3'])->firstWhere('key', 'secret')['secret'])->toBeTrue();
    $dir = storage_path('framework/testing/backup-dest-'.uniqid());
    @mkdir($dir, 0777, true);
    $this->postJson('/office-api/system/backups/destinations', ['name' => 'USB', 'type' => 'folder', 'config' => ['path' => $dir.'/missing/deeper/x'.chr(0)]])->assertStatus(422);
    $saved = $this->postJson('/office-api/system/backups/destinations', ['name' => 'USB drive', 'type' => 'folder', 'config' => ['path' => $dir], 'keep' => 7, 'is_active' => true])->assertOk()->json();
    expect(BackupDestination::sole())->name->toBe('USB drive')->keep->toBe(7)
        ->and($saved['backups']['destinations'][0]['config'])->toBe(['path' => $dir])
        ->and(AuditEvent::where('action', 'backup_destination.saved')->exists())->toBeTrue();
    $this->postJson('/office-api/system/backups/destinations', ['name' => 'Cloud', 'type' => 's3', 'config' => []])->assertStatus(422); // a paid add-on
    $run = BackupRun::create(['file_name' => 'not-here.synbak', 'status' => 'ok', 'started_by' => 'Test', 'started_at' => now(), 'finished_at' => now()]);
    expect($this->getJson('/office-api/system/backups')->json('runs.0.download'))->toBe("/office-api/backups/{$run->id}/download");
    $this->getJson("/office-api/backups/{$run->id}/download")->assertNotFound(); // only the newest stay on the server

    $lic = $this->getJson('/office-api/system/licence')->assertOk()->json();
    expect($lic)->toHaveKeys(['state', 'state_label', 'usage', 'covers'])->and($lic['can_manage'])->toBeTrue()
        ->and($lic['code'])->not->toBeEmpty()->and($lic['qr'])->toStartWith('data:image/svg+xml;base64,');
    $this->postJson('/office-api/system/licence', ['action' => 'activate', 'key' => 'SYN1-not-a-key'])->assertStatus(422);

    // Anyone signed in sees why the system is locked; only the owner or general manager may change it.
    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($cashier, 'web');
    expect($this->getJson('/office-api/system/licence')->assertOk()->json())->toMatchArray(['can_manage' => false, 'code' => null]);
    $this->postJson('/office-api/system/licence', ['action' => 'checkin'])->assertForbidden();
    foreach (['system/health', 'system/backups', 'system/audit', 'system/problem-reports'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});

it('lists the audit log with an entry in words, and verifies the chain', function () {
    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/system/audit')->assertOk()->json();
    expect($list['total'])->toBeGreaterThan(0)->and($list['rows'][0])->toHaveKeys(['seq', 'when', 'by', 'action', 'record', 'source']);
    expect($list['rows'][0]['seq'])->toBeGreaterThan($list['rows'][count($list['rows']) - 1]['seq']); // newest first
    $id = $list['rows'][0]['id'];
    $one = $this->getJson("/office-api/system/audit/{$id}")->assertOk()->json();
    expect($one)->toHaveKeys(['action', 'action_key', 'changes', 'hash']);
    expect($this->getJson('/office-api/system/audit?source=cli')->json('total'))->toBe(AuditEvent::where('source', 'cli')->count());
    $v = $this->postJson('/office-api/system/audit/verify')->assertOk()->json();
    expect($v['good'])->toBeTrue()->and($v['message'])->toContain('intact');
});

it('lists problem reports, closes one and keeps the open list to open ones', function () {
    $r = app(SupportReports::class)->report(actorFor($this->owner), 'back_office', 'Posting the stock count said something went wrong.', '/admin/stock-counts/1', ['browser' => 'Edge']);
    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/system/problem-reports')->assertOk()->json();
    expect($list['status'])->toBe('open')->and($list['rows'][0])->toMatchArray(['number' => $r->number, 'source' => 'Back office', 'status' => 'open'])
        ->and($list['rows'][0]['context'])->toContain('Edge');
    $this->postJson("/office-api/system/problem-reports/{$r->id}/action", ['action' => 'close'])->assertStatus(422);
    $done = $this->postJson("/office-api/system/problem-reports/{$r->id}/action", ['action' => 'close', 'resolution' => 'Count reposted'])->assertOk()->json('report');
    expect($done)->toMatchArray(['status' => 'closed', 'resolution' => 'Count reposted'])->and(SupportReport::find($r->id)->status)->toBe('closed');
    expect($this->getJson('/office-api/system/problem-reports')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/system/problem-reports?status=all')->json('total'))->toBe(1);
    $this->getJson('/office-api/system/integrations')->assertForbidden(); // a paid add-on not on this licence
});

<?php

use App\Models\AccessRequest;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;

// Company-wide access, Who can do what, IT and support activity, and Staff cards in the new back office (settings and
// admin, part 3). Also: an IT Admin's "Give a branch" is kept as one request (it was rolled back before).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->sup = staff($this->owner, 'ama', 3, 'supervisor'); // company-wide
    $this->sup2 = staff($this->owner, 'kofi', 3, 'supervisor');
});

it('lists company-wide access and gives a branch instead; an IT Admin\'s is kept as one request the owner approves', function () {
    $this->actingAs($this->owner, 'web');
    $all = $this->getJson('/office-api/company-wide-access?show=all')->assertOk()->json();
    $row = collect($all['rows'])->firstWhere('person', 'Ama');
    expect($row)->not->toBeNull()->and($all['branches'])->not->toBeEmpty();
    $this->postJson("/office-api/company-wide-access/{$row['id']}/narrow", ['scope_type' => 'till', 'scope_id' => $this->hq->id])->assertStatus(422);
    $this->postJson("/office-api/company-wide-access/{$row['id']}/narrow", ['scope_type' => 'branch', 'scope_id' => $this->hq->id])->assertOk();
    expect(RoleAssignment::find($row['id'])->revoked_at)->not->toBeNull()
        ->and(RoleAssignment::where('user_id', $this->sup->id)->active()->sole())->scope_type->toBe(ScopeType::Branch)->scope_id->toBe($this->hq->id);

    $it = withMfa(staff($this->owner, 'it', 4, 'it_admin'));
    $wide = RoleAssignment::where('user_id', $this->sup2->id)->active()->sole();
    $this->actingAs($it, 'web');
    $r = $this->postJson("/office-api/company-wide-access/{$wide->id}/narrow", ['scope_type' => 'branch', 'scope_id' => $this->hq->id])->assertOk()->json();
    $req = AccessRequest::where('kind', 'access.narrow')->sole();
    expect($r['pending'])->toBeTrue()->and($wide->fresh()->revoked_at)->toBeNull()->and($req->summary)->toContain('Kofi');

    $this->actingAs($this->owner, 'web');
    $this->postJson("/office-api/access-requests/{$req->id}/approve")->assertOk()->assertJsonPath('message', 'Approved and done.');
    expect($wide->fresh()->revoked_at)->not->toBeNull()
        ->and(RoleAssignment::where('user_id', $this->sup2->id)->active()->sole())->scope_type->toBe(ScopeType::Branch);

    // The owner sees IT's activity, and who can do what.
    $act = $this->getJson('/office-api/it-activity?who=tech&when=all')->assertOk()->json();
    expect($act['total'])->toBeGreaterThan(0)->and(collect($act['rows'])->pluck('who_kind')->unique()->all())->toBe(['IT']);
    $rev = $this->getJson('/office-api/who-can-do-what')->assertOk()->json();
    expect($rev['counts']['people'])->toBeGreaterThanOrEqual(3)->and(collect($rev['people'])->pluck('name')->all())->toContain('Kofi');
    $csv = $this->get('/office-api/who-can-do-what/csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Person')->toContain('Kofi');
});

it('prints and stops staff cards for this company\'s people only, and keeps others out', function () {
    [, $otherOwner] = installCompany('Other Shop', 'owner@other.test');
    $this->actingAs($this->owner, 'web');
    $c = $this->getJson('/office-api/staff-cards?per=100')->assertOk()->json();
    expect(collect($c['rows'])->pluck('id')->all())->toContain($this->sup->id)->not->toContain($otherOwner->id);

    $this->postJson('/office-api/staff-cards/print', ['ids' => [$otherOwner->id], 'layout' => 'a4'])->assertNotFound();
    $p = $this->postJson('/office-api/staff-cards/print', ['ids' => [$this->sup->id, $this->sup2->id], 'layout' => 'card'])->assertOk()->json();
    expect($p['print'])->toContain('staff-cards')->and(User::whereKey([$this->sup->id, $this->sup2->id])->whereNotNull('badge_hash')->count())->toBe(2)
        ->and($this->getJson('/office-api/staff-cards?card=has')->assertOk()->json('total'))->toBe(2);
    $this->postJson('/office-api/staff-cards/stop', ['ids' => [$this->sup->id]])->assertOk();
    expect(User::find($this->sup->id)->badge_hash)->toBeNull();

    $clerk = staff($this->owner, 'efua', 2);
    $this->actingAs($clerk, 'web');
    foreach (['company-wide-access', 'who-can-do-what', 'it-activity', 'staff-cards'] as $page) {
        $this->getJson("/office-api/{$page}")->assertForbidden();
    }
});

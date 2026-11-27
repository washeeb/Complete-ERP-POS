<?php

use App\Filament\Support\Erp;
use App\Models\AuditEvent;
use App\Models\Industry;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\BranchContext;
use App\Support\IndustryContext;
use Illuminate\Support\Str;

// Parts 43–45 (owner 2026-11-27): one picker for the industry and branch the back office shows. People whose access
// covers two or more branches choose an industry, then all of its branches or one; an industry with only one of their
// branches has no branch to choose. Lists and new documents follow (Erp::branchIds); the choice is kept for the person.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'G1', 'name' => 'Grocery Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
        ['code' => 'G2', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
        ['code' => 'P1', 'name' => 'Pharmacy East', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
    ]);
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail()->id;
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail()->id;
    $this->owner = withMfa($this->owner);
});

/** The picker's entry for one industry ('' = all). */
function scopeEntry(array $scope, string $industry): ?array
{
    return collect($scope['industries'])->firstWhere('id', $industry);
}

it('offers each industry with only its own branches, and all of them under All industries', function () {
    $this->actingAs($this->owner, 'web');
    $sc = $this->getJson('/office-api/me')->assertOk()->json('scope');
    expect($sc['industry'])->toBe('')->and($sc['branch'])->toBe('')
        ->and(collect(scopeEntry($sc, '')['branches'])->pluck('id')->all())->toContain($this->b['HQ']->id, $this->b['G1']->id, $this->b['P1']->id)
        ->and(collect(scopeEntry($sc, $this->grocery)['branches'])->pluck('id')->sort()->values()->all())->toBe(collect([$this->b['G1']->id, $this->b['G2']->id])->sort()->values()->all())
        ->and(collect(scopeEntry($sc, $this->pharmacy)['branches'])->pluck('id')->all())->toBe([$this->b['P1']->id]); // one: no branch to choose
});

it('shows one industry, then one of its branches, which narrows lists and fills in new documents', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => ''])->assertOk()
        ->assertJsonPath('scope.industry', $this->grocery)->assertJsonPath('scope.branch', '');
    expect(collect(Erp::branchIds('stock.adjust'))->sort()->values()->all())->toBe(collect([$this->b['G1']->id, $this->b['G2']->id])->sort()->values()->all());

    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['G2']->id])->assertOk()->assertJsonPath('scope.branch', $this->b['G2']->id);
    expect(Erp::branchIds('stock.adjust'))->toBe([$this->b['G2']->id])
        ->and($this->getJson('/office-api/adjustments/form')->assertOk()->json('values.warehouse_id'))->toBe(Warehouse::where('branch_id', $this->b['G2']->id)->value('id'));

    // A branch of another industry, or someone else's, is refused.
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['P1']->id])->assertStatus(422);
    $this->postJson('/office-api/scope', ['industry' => (string) Str::uuid7(), 'branch' => ''])->assertStatus(422);
});

it('remembers the place for the person: a new sign-in opens there, with the recent places', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/scope', ['industry' => $this->pharmacy, 'branch' => ''])->assertOk();
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['G1']->id])->assertOk();

    session()->flush(); // signed in again, on another computer
    $sc = $this->getJson('/office-api/me')->assertOk()->json('scope');
    expect($sc['industry'])->toBe($this->grocery)->and($sc['branch'])->toBe($this->b['G1']->id)
        ->and(IndustryContext::selected())->toBe($this->grocery)->and(BranchContext::selected())->toBe($this->b['G1']->id)
        ->and(collect($sc['recent'])->pluck('label')->all())->toBe(['Grocery / Supermarket › G1 · Grocery Osu', 'Pharmacy › All branches']);
});

it('forgets a remembered branch the person can no longer reach', function () {
    $area = withMfa(staff($this->owner, 'area', 4, 'branch_manager', ScopeType::Industry, $this->grocery));
    $this->actingAs($area, 'web');
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['G2']->id])->assertOk();
    // Their access is narrowed to G1 only: G2, still saved, no longer counts.
    $area->roleAssignments()->update(['scope_type' => ScopeType::Branch->value, 'scope_id' => $this->b['G1']->id]);
    app(Authorizer::class)->forget(); // as a change made through People & access does
    session()->flush();
    $this->actingAs(User::find($area->id), 'web');
    expect($this->getJson('/office-api/me')->assertOk()->json('scope'))->toBeNull() // one branch now: nothing to choose
        ->and(BranchContext::selected())->toBeNull()
        ->and(Erp::branchIds('stock.adjust'))->toBe([$this->b['G1']->id]);
});

it('shows no picker to someone with one branch, and refuses theirs to be widened', function () {
    $clerk = withMfa(staff($this->owner, 'stock', 3, 'stock_controller', ScopeType::Branch, $this->b['G1']->id));
    $this->actingAs($clerk, 'web');
    expect($this->getJson('/office-api/me')->assertOk()->json('scope'))->toBeNull();
    $this->postJson('/office-api/scope', ['industry' => '', 'branch' => $this->b['HQ']->id])->assertStatus(422);
    expect(Erp::branchIds('stock.adjust'))->toBe([$this->b['G1']->id]);
});

it('shows a manager of two branches of one industry just that industry and its branches', function () {
    $area = withMfa(staff($this->owner, 'area', 4, 'branch_manager', ScopeType::Industry, $this->grocery));
    $this->actingAs($area, 'web');
    $sc = $this->getJson('/office-api/me')->assertOk()->json('scope');
    expect(collect($sc['industries'])->pluck('id')->all())->toBe([$this->grocery]) // no "All industries", no pharmacy
        ->and(count(scopeEntry($sc, $this->grocery)['branches']))->toBe(2);
});

it('keeps pages that need company-wide rights while one branch is shown (it narrows what they show, not who may open them)', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['G1']->id])->assertOk();
    expect(Erp::companyWide('finance.view'))->toBeTrue()
        ->and(Erp::branchIds('finance.view'))->toBe([$this->b['G1']->id]);
    $urls = collect($this->getJson('/office-api/menu')->assertOk()->json('tabs'))->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url'))));
    expect($urls)->toContain('/office/accounting-export');
    $this->getJson('/office-api/accounting-export')->assertOk();
});

it('shows the saved place in the audit log entry, in words', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/scope', ['industry' => $this->grocery, 'branch' => $this->b['G1']->id])->assertOk();
    $this->postJson('/office-api/scope', ['industry' => '', 'branch' => ''])->assertOk(); // two recent places: nested lists
    $id = AuditEvent::where('action', 'user.profile_updated')->orderByDesc('seq')->value('id');
    $changes = collect($this->getJson("/office-api/system/audit/{$id}")->assertOk()->json('changes'))->pluck('value')->implode(' ');
    expect($changes)->toContain('Recent')->not->toContain('Array');
});

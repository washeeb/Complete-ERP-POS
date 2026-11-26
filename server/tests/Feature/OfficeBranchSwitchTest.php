<?php

use App\Filament\Support\Erp;
use App\Models\Industry;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\BranchContext;
use Illuminate\Support\Str;

// Part 45: the branch switch beside the industry one. One branch chosen narrows every branch-filtered list (through
// Erp::branchIds) and the stores a new document offers, so it starts with that branch's store.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner); // HQ (retail & wholesale) and BA (grocery)
    $this->owner = withMfa($this->owner);
});

it('lets someone with several branches show one, which narrows lists and fills in new documents', function () {
    $this->actingAs($this->owner, 'web');
    $switch = $this->getJson('/office-api/me')->assertOk()->json('branch');
    expect($switch['selected'])->toBe('')->and(collect($switch['options'])->pluck('id')->all())->toContain($this->b['HQ']->id, $this->b['BA']->id);
    expect(Erp::branchIds('stock.adjust'))->toBeNull(); // the owner: everywhere

    $this->postJson('/office-api/branch', ['branch' => $this->b['BA']->id])->assertOk()->assertJson(['selected' => $this->b['BA']->id]);
    expect($this->getJson('/office-api/me')->json('branch.selected'))->toBe($this->b['BA']->id)
        ->and(BranchContext::selected())->toBe($this->b['BA']->id)
        ->and(Erp::branchIds('stock.adjust'))->toBe([$this->b['BA']->id]);
    $store = Warehouse::where('branch_id', $this->b['BA']->id)->value('id');
    expect($this->getJson('/office-api/adjustments/form')->assertOk()->json('values.warehouse_id'))->toBe($store); // filled in

    $this->postJson('/office-api/branch', ['branch' => (string) Str::uuid7()])->assertStatus(422); // not one of theirs
    $this->postJson('/office-api/branch', ['branch' => ''])->assertOk()->assertJson(['selected' => '']);
    expect(Erp::branchIds('stock.adjust'))->toBeNull();
});

it('offers only the chosen industry\'s branches, and forgets a branch of another industry', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/branch', ['branch' => $this->b['HQ']->id])->assertOk();
    $grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $this->postJson('/office-api/industry', ['industry' => $grocery->id])->assertOk();
    // Grocery has one branch here: no choice to make, and the head-office choice no longer applies.
    expect($this->getJson('/office-api/me')->json('branch'))->toBeNull()
        ->and(BranchContext::selected())->toBeNull()
        ->and(Erp::branchIds('stock.adjust'))->toBe([$this->b['BA']->id]);
});

it('shows no switch to someone with one branch, and refuses a branch they cannot see', function () {
    $clerk = withMfa(staff($this->owner, 'stock', 3, 'stock_controller', ScopeType::Branch, $this->b['BA']->id));
    $this->actingAs($clerk, 'web');
    expect($this->getJson('/office-api/me')->assertOk()->json('branch'))->toBeNull();
    $this->postJson('/office-api/branch', ['branch' => $this->b['HQ']->id])->assertStatus(422);
    expect(Erp::branchIds('stock.adjust'))->toBe([$this->b['BA']->id]); // never wider than their own
});

<?php

use App\Domain\Modules\ModuleService;
use App\Models\Branch;
use App\Support\Settings;

// Settings and Modules in the new back office (settings and admin, part 4), as the current pages.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
});

it('lists settings by group, saves only what changed and is yours, and refuses the rest', function () {
    $this->actingAs($this->owner, 'web');
    $g = collect($this->getJson('/office-api/settings')->assertOk()->json('groups'));
    $all = $g->flatMap(fn ($x) => $x['settings'])->keyBy('key');
    expect($all['stock.block_negative_sales'])->toMatchArray(['type' => 'bool', 'value' => false, 'may_change' => true])
        ->and($all['security.support_account'])->toMatchArray(['may_change' => false, 'who' => 'Changed by SyncLytics support'])
        ->and($g->pluck('group')->all())->not->toContain('Restaurant'); // that industry does not run here

    $r = $this->postJson('/office-api/settings', ['values' => ['stock.block_negative_sales' => true, 'pos.show_stock' => $all['pos.show_stock']['value'], 'no.such.key' => 1],
        'reason' => 'Stop selling what is not there'])->assertOk()->json();
    expect($r['saved'])->toBe(1)->and(app(Settings::class)->get('stock.block_negative_sales', $this->tenant->id))->toBeTrue();
    $this->postJson('/office-api/settings', ['values' => ['security.support_account' => ! $all['security.support_account']['value']]])->assertForbidden();
    $this->postJson('/office-api/settings', ['values' => ['security.pin_max_attempts' => 99]])->assertStatus(422); // above its maximum
});

it('shows a branch\'s modules and switches one on and off', function () {
    $this->actingAs($this->owner, 'web');
    $m = $this->getJson('/office-api/modules')->assertOk()->json();
    $branch = $m['branch_id'];
    $mod = collect($m['sections'][1]['modules'])->first(fn ($x) => $x['available'] && $x['status'] === 'disabled'); // an optional department
    $industry = collect($m['sections'][0]['modules'])->first(fn ($x) => $x['available'] && $x['status'] === 'disabled');
    expect($branch)->not->toBeNull()->and($mod)->not->toBeNull();

    $this->postJson('/office-api/modules', ['branch_id' => $branch, 'modules' => [$industry['key'] => 'enabled']])->assertStatus(422)
        ->assertJsonPath('detail', fn ($d) => str_contains($d, 'a branch runs one industry')); // the rule stays in ModuleService
    $this->postJson('/office-api/modules', ['branch_id' => $branch, 'modules' => [$mod['key'] => 'enabled'], 'reason' => 'Trying it'])->assertOk();
    expect(app(ModuleService::class)->statuses(Branch::find($branch))[$mod['key']])->toBe('enabled')
        ->and(collect($this->getJson("/office-api/modules?branch={$branch}")->json('sections'))->flatMap(fn ($s) => $s['modules'])->firstWhere('key', $mod['key'])['status'])->toBe('enabled');
    $this->postJson('/office-api/modules', ['branch_id' => $branch, 'modules' => [$mod['key'] => 'disabled']])->assertOk();
    expect(app(ModuleService::class)->statuses(Branch::find($branch))[$mod['key']] ?? 'disabled')->toBe('disabled');

    $clerk = staff($this->owner, 'efua', 2);
    $this->actingAs($clerk, 'web')->getJson('/office-api/settings')->assertForbidden();
    $this->getJson('/office-api/modules')->assertForbidden();
    $this->postJson('/office-api/modules', ['branch_id' => $branch, 'modules' => [$mod['key'] => 'enabled']])->assertForbidden();
});

<?php

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

// The books' set-up in the new back office (release 8, part 2): chart of accounts, month-end and budgets.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->accountant = withMfa(staff($this->owner, 'esi', 4, 'accountant'));
});

it('lists the chart, adds an account with the suggested code, changes it, and archives and restores it', function () {
    $this->actingAs($this->fm, 'web');
    $c = $this->getJson('/office-api/accounts')->assertOk()->json();
    $rent = collect($c['rows'])->firstWhere('code', '6200');
    expect($rent)->toMatchArray(['type' => 'expense', 'header' => false, 'balance' => 0, 'can_edit' => true])->and($rent['ledger'])->toContain('report=ledger');
    expect(collect($this->getJson('/office-api/accounts?type=income')->json('rows'))->pluck('type')->unique()->all())->toBe(['income']);

    $form = $this->getJson('/office-api/accounts/form')->assertOk()->json();
    $next = $form['next_by_type']['expense'];
    expect($next)->not->toBeNull()->and(array_column($form['purposes'], 'id'))->toContain('bank');

    $this->postJson('/office-api/accounts', ['code' => $next, 'name' => '', 'type' => 'expense'])->assertStatus(422);
    $id = $this->postJson('/office-api/accounts', ['code' => $next, 'name' => 'Generator fuel', 'type' => 'expense'])->assertOk()->json('account.id');
    $this->postJson("/office-api/accounts/{$id}", ['code' => $next, 'name' => 'Generator fuel and oil', 'type' => 'expense'])->assertOk();
    expect(Account::find($id)->name)->toBe('Generator fuel and oil');

    $this->postJson("/office-api/accounts/{$id}/archive", ['reason' => ''])->assertStatus(422);
    $this->postJson("/office-api/accounts/{$id}/archive", ['reason' => 'No generator any more'])->assertOk();
    expect(Account::find($id)->archived_at)->not->toBeNull()->and(collect($this->getJson('/office-api/accounts?show=archived')->json('rows'))->pluck('id')->all())->toContain($id);
    $this->postJson("/office-api/accounts/{$id}/archive", ['restore' => true])->assertOk();
    expect(Account::find($id)->archived_at)->toBeNull();

    $this->actingAs($this->accountant, 'web'); // may see the chart, not change it
    expect($this->getJson('/office-api/accounts')->json('rows.0.can_edit'))->toBeFalse();
    $this->postJson('/office-api/accounts', ['code' => '6999', 'name' => 'X', 'type' => 'expense'])->assertForbidden();
});

it('shows the year, sets up the next one, closes a month and reopens it with a reason', function () {
    $this->actingAs($this->fm, 'web');
    $m = $this->getJson('/office-api/month-end')->assertOk()->json();
    expect($m['rows'])->toHaveCount(12)->and($m['can_setup'])->toBeTrue();

    $next = $m['year'] + 1;
    $this->postJson('/office-api/month-end/year', ['year' => $next])->assertOk();
    expect(FiscalPeriod::where('year', $next)->count())->toBe(12);

    $p = FiscalPeriod::where('year', $m['year'])->orderBy('period')->first();
    $row = collect($m['rows'])->firstWhere('id', $p->id);
    expect($row)->toMatchArray(['can_close' => true, 'blockers' => []]); // nothing posted in it yet
    $this->postJson("/office-api/month-end/{$p->id}/close")->assertOk();
    expect($p->fresh()->status)->toBe('closed')->and(collect($this->getJson("/office-api/month-end?year={$m['year']}")->json('rows'))->firstWhere('id', $p->id)['can_reopen'])->toBeFalse();
    $this->postJson("/office-api/month-end/{$p->id}/reopen", ['reason' => 'Late supplier bill'])->assertForbidden(); // a finance manager closes, the general manager reopens

    $gm = withMfa(staff($this->owner, 'yaw', 5, 'general_manager'));
    $this->actingAs($gm, 'web');
    $this->postJson("/office-api/month-end/{$p->id}/reopen", ['reason' => ''])->assertStatus(422);
    $this->postJson("/office-api/month-end/{$p->id}/reopen", ['reason' => 'Late supplier bill'])->assertOk();
    expect($p->fresh()->status)->toBe('open');

    $this->actingAs($this->accountant, 'web');
    $this->postJson("/office-api/month-end/{$p->id}/close")->assertForbidden();
});

it('shows and saves a budget, spreads a yearly figure, and keeps budgets from a teller', function () {
    $this->actingAs($this->fm, 'web');
    $d = $this->getJson('/office-api/budgets')->assertOk()->json();
    expect($d['months'])->toHaveCount(12)->and($d['can_save'])->toBeTrue()->and($d['rows'])->not->toBeEmpty();

    $spread = $this->getJson('/office-api/budgets/spread?annual=1200')->assertOk()->json('amounts');
    expect($spread)->toHaveCount(12)->and(array_sum(array_map('floatval', $spread)))->toBe(1200.0);

    $rent = Account::where('code', '6200')->value('id');
    $this->postJson('/office-api/budgets', ['year' => $d['year'], 'branch' => $d['branch'], 'amounts' => [$rent => $spread]])->assertOk();
    expect((int) DB::table('budget_amounts')->where('account_id', $rent)->sum('amount_minor'))->toBe(120000);
    expect(collect($this->getJson("/office-api/budgets?year={$d['year']}&branch={$d['branch']}")->json('rows'))->firstWhere('id', $rent)['amounts'][0])->toBe('100.00');

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/budgets')->assertForbidden();
    $this->getJson('/office-api/accounts')->assertForbidden();
    $this->getJson('/office-api/month-end')->assertForbidden();
});

<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\InventoryService;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Journals in the new back office (release 8, part 1): the list and one journal, New journal, Opening balances,
// Reverse and Post now, through JournalService and AutoPoster.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $wh, $milo, $milo->units->first(), '20', 'opening', '', null, '40.00');
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->bank = Account::where('system_key', 'bank')->value('id');
    $this->rent = Account::where('code', '6200')->value('id');
});

it('posts waiting records, lists and opens journals, and exports them', function () {
    $this->actingAs($this->fm, 'web');
    $o = $this->getJson('/office-api/journals/options')->assertOk()->json();
    expect($o)->toMatchArray(['can_journal' => true, 'can_post_now' => true])->and(array_column($o['accounts'], 'id'))->toContain($this->rent);

    $p = $this->postJson('/office-api/journals/post-now')->assertOk()->json();
    expect($p['posted'])->toBeGreaterThan(0)->and($p['errors'])->toBe([]);
    expect($this->postJson('/office-api/journals/post-now')->json('message'))->toBe('Everything is already posted.');

    $l = $this->getJson('/office-api/journals?kind=auto')->assertOk()->json();
    expect($l['total'])->toBeGreaterThan(0)->and($l['rows'][0]['kind_label'])->toBe('Automatic');
    $j = $this->getJson("/office-api/journals/{$l['rows'][0]['id']}")->assertOk()->json('journal');
    expect($j['debits'])->toBe($j['credits'])->and($j['lines'])->not->toBeEmpty()->and($j['can_reverse'])->toBeFalse(); // an automatic journal is reversed at its source

    $csv = $this->get('/office-api/export/journals?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Posted by')->toContain('Automatic');
});

it('posts a journal, refuses one that does not balance, and reverses it once', function () {
    $this->actingAs($this->fm, 'web');
    $line = fn ($account, $dr, $cr) => ['account_id' => $account, 'debit' => $dr, 'credit' => $cr, 'branch_id' => $this->b['HQ']->id];
    $this->postJson('/office-api/journals', ['date' => now()->toDateString(), 'memo' => 'Rent', 'lines' => [$line($this->rent, '100', null), $line($this->bank, null, '90')]])
        ->assertStatus(422);
    $this->postJson('/office-api/journals', ['date' => now()->toDateString(), 'memo' => '', 'lines' => [$line($this->rent, '100', null), $line($this->bank, null, '100')]])
        ->assertStatus(422)->assertJsonPath('message', 'Describe the journal.');

    $new = $this->postJson('/office-api/journals', ['date' => now()->toDateString(), 'memo' => 'November rent', 'lines' => [$line($this->rent, '100', null), $line($this->bank, null, '100')]])
        ->assertOk()->json('journal');
    $j = $this->getJson("/office-api/journals/{$new['id']}")->json('journal');
    expect($j)->toMatchArray(['kind' => 'manual', 'memo' => 'November rent', 'debits' => 10000, 'credits' => 10000, 'can_reverse' => true]);
    expect($this->getJson('/office-api/journals?q=november')->json('total'))->toBe(1);

    $this->postJson("/office-api/journals/{$new['id']}/reverse", ['date' => now()->toDateString(), 'reason' => ''])->assertStatus(422);
    $rev = $this->postJson("/office-api/journals/{$new['id']}/reverse", ['date' => now()->toDateString(), 'reason' => 'Paid twice'])->assertOk()->json('journal');
    expect(JournalEntry::find($rev['id'])->reverses_id)->toBe($new['id']);
    $after = $this->getJson("/office-api/journals/{$new['id']}")->json('journal');
    expect($after['reversed_by']['id'])->toBe($rev['id'])->and($after['can_reverse'])->toBeFalse();
    $this->postJson("/office-api/journals/{$new['id']}/reverse", ['date' => now()->toDateString(), 'reason' => 'Again'])->assertStatus(422);
});

it('posts opening balances, and keeps journals from people without finance access', function () {
    $this->actingAs($this->fm, 'web');
    $o = $this->postJson('/office-api/journals', ['opening' => true, 'date' => now()->toDateString(), 'lines' => [['account_id' => $this->bank, 'debit' => '500']]])->assertOk()->json('journal');
    expect(JournalEntry::find($o['id'])->kind)->toBe('opening');

    $this->actingAs(withMfa(staff($this->owner, 'esi', 4, 'accountant')), 'web'); // an accountant posts journals too
    $this->postJson('/office-api/journals', ['date' => now()->toDateString(), 'memo' => 'Bank charges', 'lines' => [
        ['account_id' => $this->rent, 'debit' => '5'], ['account_id' => $this->bank, 'credit' => '5']]])->assertOk();
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/journals')->assertForbidden();
    $this->getJson('/office-api/journals/options')->assertForbidden();
});

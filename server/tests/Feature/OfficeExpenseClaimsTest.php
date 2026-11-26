<?php

use App\Domain\Banking\BankingService;
use App\Models\Account;
use App\Models\ExpenseClaim;
use App\Security\Reconfirm;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Expense claims in the new back office (release 6, part 1): New claim with its receipt, the list, and Approve, Reject
// and Pay through ExpenseService (paying above the limit asks to confirm it is you).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = $this->b['HQ'];
    $this->sup = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    $this->bm = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->hq->id, ['expense_approve_minor' => 50000]);
    $this->acc = fn (string $key) => Account::where('system_key', $key)->firstOrFail();
    app(BankingService::class)->transfer(actorFor(staff($this->owner, 'esi', 4, 'accountant')), ($this->acc)('bank'), ($this->acc)('petty_cash'), $this->hq, $this->hq,
        100000, now()->toDateString());
    $this->fuel = Account::where('code', '6500')->value('id');
    $this->claim = fn (array $v = []) => $v + ['branch_id' => $this->hq->id, 'spent_on' => now()->toDateString(), 'account_id' => $this->fuel, 'amount' => '300',
        'description' => 'Fuel for delivery van', 'payee' => 'Goil Osu'];
});

it('takes a claim with its receipt from New claim to paid back, approved by someone else', function () {
    Storage::fake('local');
    $this->actingAs(withMfa($this->sup), 'web');
    $form = $this->getJson('/office-api/expense-claims/form')->assertOk()->json();
    expect(collect($form['branches'])->pluck('id'))->toContain($this->hq->id)->and(collect($form['accounts'])->pluck('id'))->toContain($this->fuel);
    $id = $this->post('/office-api/expense-claims', ($this->claim)(['receipt' => UploadedFile::fake()->image('receipt.jpg')]), ['Accept' => 'application/json'])
        ->assertOk()->json('id');
    $this->post('/office-api/expense-claims', ($this->claim)(['amount' => '0']), ['Accept' => 'application/json'])->assertStatus(422);
    $show = $this->getJson("/office-api/expense-claims/{$id}")->assertOk()->json();
    expect($show)->toMatchArray(['status' => 'submitted', 'amount' => 30000, 'payee' => 'Goil Osu'])->and($show['receipt'])->toContain('/expense-receipt/')
        ->and($show['actions']['approve'])->toBeFalse(); // not one's own
    expect($this->getJson('/office-api/expense-claims?mine=1')->json('total'))->toBe(1);

    $this->actingAs(withMfa($this->bm), 'web');
    expect($this->getJson('/office-api/expense-claims')->json('waiting'))->toBe(1);
    expect($this->postJson("/office-api/expense-claims/{$id}/action", ['action' => 'approve'])->assertOk()->json('status'))->toBe('approved');
    $paid = $this->postJson("/office-api/expense-claims/{$id}/action", ['action' => 'pay', 'account_id' => ($this->acc)('petty_cash')->id, 'paid_on' => now()->toDateString()])
        ->assertOk()->json();
    expect($paid['status'])->toBe('paid')->and($paid['paid']['by'])->toBe('Ama');

    $csv = $this->get('/office-api/export/expense-claims?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Fuel for delivery van');
});

it('rejects with a reason, and asks to confirm it is you before paying above the limit', function () {
    $this->actingAs(withMfa($this->sup), 'web');
    $a = $this->post('/office-api/expense-claims', ($this->claim)(['description' => 'Generator part']), ['Accept' => 'application/json'])->json('id');
    $b = $this->post('/office-api/expense-claims', ($this->claim)(['description' => 'Taxi']), ['Accept' => 'application/json'])->json('id');
    $this->actingAs(withMfa($this->bm), 'web');
    $this->postJson("/office-api/expense-claims/{$a}/action", ['action' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->postJson("/office-api/expense-claims/{$a}/action", ['action' => 'reject', 'reason' => 'No receipt'])->json('rejection_reason'))->toBe('No receipt');
    $this->postJson("/office-api/expense-claims/{$b}/action", ['action' => 'approve'])->assertOk();

    app(Settings::class)->set(actorFor($this->owner), Reconfirm::SETTING, 10000); // confirm before paying out above GHS 100
    Reconfirm::$assumeConfirmed = false;
    $this->postJson("/office-api/expense-claims/{$b}/action", ['action' => 'pay', 'account_id' => ($this->acc)('petty_cash')->id, 'paid_on' => now()->toDateString()])
        ->assertStatus(428)->assertJsonPath('error', 'reconfirm');
    Reconfirm::$assumeConfirmed = true;
    expect(ExpenseClaim::find($b)->status)->toBe('approved');
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->hq->id);
    $this->actingAs(withMfa($teller), 'web')->getJson('/office-api/expense-claims')->assertForbidden();
});

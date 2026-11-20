<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\JournalService;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\MoneyTransfer;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// Bank & cash in the new back office (release 8, part 3): accounts, Move money, Import statement, reconciliation and
// Money moved, through BankingService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    $this->accountant = withMfa(staff($this->owner, 'esi', 4, 'accountant'));
    $this->acc = fn (string $key) => Account::where('system_key', $key)->firstOrFail();
    app(JournalService::class)->opening(actorFor($this->accountant), '2026-10-01', [['account_id' => ($this->acc)('bank')->id, 'debit_minor' => 100000],
        ['account_id' => ($this->acc)('cash_till')->id, 'debit_minor' => 250000]]);
});

it('adds an account, moves money, imports a statement and reconciles it', function () {
    $this->actingAs($this->accountant, 'web');
    $f = $this->getJson('/office-api/bank-accounts/forms')->assertOk()->json();
    expect($f['can_manage'])->toBeTrue()->and(array_column($f['money_accounts'], 'id'))->toContain(($this->acc)('bank')->id);

    $this->postJson('/office-api/bank-accounts', ['name' => '', 'kind' => 'bank'])->assertStatus(422);
    $id = $this->postJson('/office-api/bank-accounts', ['name' => 'GCB current', 'kind' => 'bank', 'provider' => 'GCB', 'number' => '1234', 'account_id' => ($this->acc)('bank')->id])
        ->assertOk()->json('bank.id');

    $this->postJson('/office-api/money-moved', ['from_account_id' => ($this->acc)('cash_till')->id, 'branch_id' => $this->hq->id, 'to_account_id' => ($this->acc)('cash_till')->id,
        'to_branch_id' => $this->hq->id, 'amount' => '10', 'moved_on' => '2026-10-03'])->assertStatus(422); // the same account twice
    $this->postJson('/office-api/money-moved', ['from_account_id' => ($this->acc)('cash_till')->id, 'branch_id' => $this->hq->id, 'to_account_id' => ($this->acc)('bank')->id,
        'to_branch_id' => $this->hq->id, 'amount' => '2500', 'moved_on' => '2026-10-03', 'reference' => 'DEP-77'])->assertOk();
    app(AutoPoster::class)->run($this->tenant->id);
    $moved = $this->getJson('/office-api/money-moved?q=DEP-77')->assertOk()->json();
    expect($moved['total'])->toBe(1)->and($moved['rows'][0])->toMatchArray(['amount' => 250000, 'reference' => 'DEP-77']);

    $csv = "Txn Date,Narration,Ref,Debit,Credit,Balance\n"
        ."03/10/2026,CASH DEPOSIT HQ,DEP-77,,\"2,500.00\",\"3,500.00\"\n"
        ."05/10/2026,SMS ALERT CHARGES,,12.50,,\"3,487.50\"\n";
    $map = ['header_rows' => 1, 'date' => 'Txn Date', 'date_format' => 'auto', 'description' => 'Narration', 'reference' => 'Ref', 'money_in' => 'Credit', 'money_out' => 'Debit', 'balance' => 'Balance'];
    $file = fn () => UploadedFile::fake()->createWithContent('oct.csv', $csv);
    expect($this->post("/office-api/bank-accounts/{$id}/import", $map + ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('added'))->toBe(2);
    expect($this->post("/office-api/bank-accounts/{$id}/import", $map + ['file' => $file()], ['Accept' => 'application/json'])->json('message'))->toBe('0 line(s) added, 2 already there.');
    $row = collect($this->getJson('/office-api/bank-accounts')->json('rows'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['to_match' => 2, 'kind_label' => 'Bank'])->and($row['mapping']['money_in'])->toBe('Credit');

    $r = $this->getJson("/office-api/bank-accounts/{$id}/reconcile?as_at=2026-10-31")->assertOk()->json();
    expect($r['suggestions'])->toBe(1)->and($r['statement'])->toHaveCount(2)->and(collect($r['statement'])->firstWhere('suggested', true)['amount'])->toBe(250000);
    expect($this->postJson("/office-api/bank-accounts/{$id}/reconcile/auto")->assertOk()->json('message'))->toBe('1 line(s) matched.');

    $charge = BankStatementLine::where('status', 'open')->sole();
    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/post", ['statement' => [$charge->id]])->assertStatus(422); // which account
    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/post", ['statement' => [$charge->id], 'account_id' => ($this->acc)('bank_charges')->id])->assertOk();
    $opening = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.kind', 'opening')->where('account_id', ($this->acc)('bank')->id)->value('journal_lines.id');
    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/earlier", ['ledger' => [$opening]])->assertOk();
    $done = $this->getJson("/office-api/bank-accounts/{$id}/reconcile?as_at=2026-10-31")->json();
    expect($done['summary'])->toMatchArray(['ledger' => 348750, 'statement' => 348750, 'difference' => 0])->and($done['statement'])->toBe([])->and($done['matches'])->not->toBeEmpty();

    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/unmatch", ['match_id' => $done['matches'][0]['id']])->assertOk();
    $undone = $this->getJson("/office-api/bank-accounts/{$id}/reconcile?as_at=2026-10-31")->json(); // both lines are back to match; the difference does not move
    expect($undone['statement'])->toHaveCount(1)->and($undone['ledger'])->not->toBeEmpty()->and($undone['summary']['difference'])->toBe(0);
});

it('ignores a statement line with a reason, and keeps banking from a teller', function () {
    $this->actingAs($this->accountant, 'web');
    $id = $this->postJson('/office-api/bank-accounts', ['name' => 'MoMo merchant', 'kind' => 'momo', 'code' => '1145'])->assertOk()->json('bank.id');
    expect(BankAccount::find($id)->account->code)->toBe('1145'); // a new ledger account with the code given
    $csv = "Date,Details,Amount\n04/10/2026,TEST CREDIT,1.00\n";
    $this->post("/office-api/bank-accounts/{$id}/import", ['header_rows' => 1, 'date' => 'Date', 'date_format' => 'auto', 'description' => 'Details', 'amount' => 'Amount',
        'file' => UploadedFile::fake()->createWithContent('m.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
    $line = BankStatementLine::where('bank_account_id', $id)->sole();
    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/ignore", ['statement' => [$line->id]])->assertStatus(422);
    $this->postJson("/office-api/bank-accounts/{$id}/reconcile/ignore", ['statement' => [$line->id], 'reason' => 'Provider test credit'])->assertOk();
    expect($line->fresh()->status)->not->toBe('open');

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->hq->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/bank-accounts')->assertForbidden();
    $this->getJson('/office-api/money-moved')->assertForbidden();
    $this->getJson("/office-api/bank-accounts/{$id}/reconcile")->assertForbidden();
    expect(MoneyTransfer::count())->toBe(0);
});

<?php

use App\Domain\Finance\AutoPoster;
use App\Filament\Pages\BankReconciliation;
use App\Filament\Pages\Budgets;
use App\Filament\Pages\FinancialReports;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Resources\ExpenseClaims\Pages\CreateExpenseClaim;
use App\Filament\Resources\ExpenseClaims\Pages\ViewExpenseClaim;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\MoneyTransfers\MoneyTransferResource;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\BankAccount;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->actingAs($this->fm);
    Storage::fake('local');
});

it('sets up a bank account, moves money, imports a statement and matches it on screen', function () {
    Livewire::test(ListBankAccounts::class)->callAction('create', ['name' => 'Ecobank current', 'kind' => 'bank', 'code' => '1131', 'is_active' => true])->assertHasNoActionErrors();
    $bank = BankAccount::sole();
    expect($bank->account->code)->toBe('1131');
    Livewire::test(ListBankAccounts::class)->callAction('transfer', ['from_account_id' => Account::where('system_key', 'cash_till')->value('id'), 'branch_id' => $this->branches['HQ']->id,
        'to_account_id' => $bank->account_id, 'to_branch_id' => $this->branches['HQ']->id, 'amount' => '1500', 'moved_on' => now()->toDateString(), 'reference' => 'DEP-1'])
        ->assertNotified('Money moved');

    $file = UploadedFile::fake()->createWithContent('stmt.csv', "Date,Details,Amount,Balance\n".now()->format('d/m/Y').",Cash deposit DEP-1,1500.00,1500.00\n");
    Livewire::test(ListBankAccounts::class)->callTableAction('import', $bank, ['file' => $file, 'header_rows' => 1, 'date' => 'Date', 'date_format' => 'auto',
        'description' => 'Details', 'amount' => 'Amount', 'balance' => 'Balance'])->assertNotified('1 line(s) added');
    $this->get(BankAccountResource::getUrl())->assertOk()->assertSee('Ecobank current');
    $this->get(MoneyTransferResource::getUrl())->assertOk()->assertSee('DEP-1');

    app(AutoPoster::class)->run($this->tenant->id);
    Livewire::test(BankReconciliation::class, ['bank' => $bank->id])->assertSee('Cash deposit DEP-1')->assertSee('suggested')->call('autoMatch')->assertNotified('1 line(s) matched')
        ->assertSee('Nothing left to match');
    expect(DB::table('bank_statement_lines')->value('status'))->toBe('matched');
});

it('takes a claim with its receipt photo through approval and payment on screen', function () {
    $sup = withMfa(staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id));
    $this->flushSession();
    $this->actingAs($sup);
    Livewire::test(CreateExpenseClaim::class)->fillForm(['branch_id' => $this->branches['HQ']->id, 'spent_on' => now()->toDateString(), 'account_id' => Account::where('code', '6500')->value('id'),
        'amount' => '45.50', 'description' => 'Taxi with stock from the market', 'receipt_path' => UploadedFile::fake()->image('receipt.jpg')])->call('create')->assertHasNoFormErrors();
    $claim = ExpenseClaim::sole();
    expect($claim->amount_minor)->toBe(4550)->and($claim->receipt_path)->not->toBeNull();
    $this->get(route('expense.receipt', $claim))->assertOk();

    $this->flushSession();
    $this->actingAs($this->fm);
    $this->get(ExpenseClaimResource::getUrl())->assertOk()->assertSee($claim->number);
    Livewire::test(ViewExpenseClaim::class, ['record' => $claim->id])->callAction('approve')->assertNotified('Claim approved');
    Livewire::test(ViewExpenseClaim::class, ['record' => $claim->id])->callAction('pay', ['account_id' => Account::where('system_key', 'petty_cash')->value('id'), 'paid_on' => now()->toDateString()])
        ->assertNotified('Claim paid');
    expect($claim->fresh()->status)->toBe('paid');

    // Someone else's claim and its receipt stay private from a colleague without approval rights.
    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id)));
    $this->get(route('expense.receipt', $claim))->assertForbidden();
    $this->get(ExpenseClaimResource::getUrl())->assertOk()->assertDontSee($claim->number);
});

it('registers an asset, keeps budgets and shows the new reports', function () {
    Livewire::test(CreateFixedAsset::class)->fillForm(['name' => 'Toyota Hiace van', 'asset_class_id' => AssetClass::where('code', 'VEH')->value('id'),
        'branch_id' => $this->branches['HQ']->id, 'acquired_on' => now()->subMonths(2)->toDateString(), 'cost' => '180000', 'residual' => '30000', 'serial_no' => 'GR 1234-24'])
        ->call('create')->assertHasNoFormErrors();
    $van = FixedAsset::sole();
    expect($van->number)->toStartWith('FA-')->and($van->life_months)->toBe(60);
    $this->get(FixedAssetResource::getUrl('view', ['record' => $van]))->assertOk()->assertSee('GR 1234-24');

    $rent = Account::where('code', '6200')->value('id');
    Livewire::test(Budgets::class)->set("annual.{$rent}", '24000')->call('spread', $rent)->assertSet("amounts.{$rent}.0", '2000.00')->call('save')->assertNotified('Budget saved');
    expect((int) DB::table('budget_amounts')->where('account_id', $rent)->sum('amount_minor'))->toBe(2400000);

    foreach (['budget', 'vat'] as $r) {
        $this->get(FinancialReports::getUrl(['report' => $r]))->assertOk();
    }
    $this->get(FinancialReports::getUrl(['report' => 'budget']))->assertSee('6200 Rent');
});

it('keeps banking, assets and budgets from people without access', function () {
    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'it', 4, 'it_admin')));
    $this->get(BankAccountResource::getUrl())->assertForbidden();
    $this->get(FixedAssetResource::getUrl())->assertForbidden();
    $this->get(Budgets::getUrl())->assertForbidden();
    $this->get(ExpenseClaimResource::getUrl())->assertForbidden();
});

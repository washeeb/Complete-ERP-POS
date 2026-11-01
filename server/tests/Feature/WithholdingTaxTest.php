<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Payables\BillService;
use App\Domain\Payables\SupplierLedger;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\TaxGroup;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->hq = setUpBranches($this->owner)['HQ'];
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    app(Settings::class)->set(actorFor($this->owner), 'payables.wht', true);
    $this->cleaners = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Sparkle Cleaning', 'tax_no' => 'P0001112223', 'supply_kind' => 'services']);
    $this->bank = Account::where('system_key', 'bank')->first();
    // A bill for GHS 4,000.00 before VAT and levies.
    $this->bill = app(BillService::class)->create(actorFor($this->accountant), $this->cleaners, $this->hq, ['supplier_invoice_no' => 'SC-101', 'bill_date' => now()->toDateString(),
        'lines' => [['account_id' => Account::where('code', '6200')->value('id'), 'description' => 'Office cleaning, October', 'amount_minor' => 400000,
            'tax_group_id' => TaxGroup::where('code', 'STD')->value('id')]]]);
    $this->pay = fn (int $amount, array $extra = []) => app(SupplierPaymentService::class)->pay(actorFor($this->fm), $this->cleaners->fresh(), $this->hq, $this->bank, 'bank',
        $amount, 'TRF-1', ...$extra);
});

function whtGl(string $key): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)->sum('journal_lines.amount_minor');
}

it('keeps back 7.5 % on services, on the bill before VAT, settles the bill in full and owes the tax to GRA', function () {
    $total = $this->bill->total_minor;
    expect($total)->toBeGreaterThan(400000); // VAT and levies on top

    $p = ($this->pay)($total);
    expect($p)->wht_minor->toBe(30000)->wht_base_minor->toBe(400000)->wht_rate_bp->toBe(750)
        ->and($p->wht_certificate_no)->toMatch('/^WHT-/')->and($p->cashMinor())->toBe($total - 30000)
        ->and(app(SupplierLedger::class)->openItems($this->cleaners->id))->toHaveCount(0); // the bill is settled in full

    app(AutoPoster::class)->run($this->tenant->id);
    expect(whtGl('wht_payable'))->toBe(-30000)
        ->and((int) DB::table('journal_lines')->where('account_id', $this->bank->id)->sum('amount_minor'))->toBe(-($total - 30000));

    // The certificate prints for people who see payables.
    $this->actingAs($this->fm)->get(route('print.wht', $p))->assertOk()->assertSee('WITHHOLDING TAX CREDIT CERTIFICATE')->assertSee('P0001112223')->assertSee($p->wht_certificate_no);
});

it('keeps nothing back below the threshold, for an exempt supplier, or when told not to; and takes the accountant\'s figure', function () {
    expect(($this->pay)(100000)->wht_minor)->toBe(0); // GHS 1,000 of the bill: about GHS 833 before VAT, below GHS 2,000

    app(SupplierOnboarding::class)->addDocument(actorFor($this->owner), $this->cleaners, ['kind' => 'wht_exemption', 'number' => 'EX-9', 'expires_on' => now()->addYear()->toDateString()], null, null);
    expect(($this->pay)(300000)->wht_minor)->toBe(0);
    DB::table('supplier_documents')->delete();

    expect(($this->pay)(50000, ['withhold' => false])->wht_minor)->toBe(0);
    expect(fn () => ($this->pay)(50000, ['whtMinor' => 50000]))->toThrow(DomainException::class, 'less than the amount');
    expect(($this->pay)(1000, ['whtMinor' => 75])->wht_minor)->toBe(75);
});

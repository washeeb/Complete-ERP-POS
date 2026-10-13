<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\FinancialReports;
use App\Domain\Finance\GlCheck;
use App\Domain\Finance\JournalService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockRequestService;
use App\Domain\Inventory\TransferService;
use App\Domain\Org\IndustryService;
use App\Domain\Org\OrgService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\FinancialReports as ReportsPage;
use App\Filament\Resources\LegalEntities\LegalEntityResource;
use App\Models\Account;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 0, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 0, 'modules' => ['grocery']],
    ]);
    $org = app(OrgService::class);
    $this->group = LegalEntity::primary() ?? $org->saveLegalEntity(actorFor($this->owner), new LegalEntity, ['name' => 'Demo Group', 'tin' => 'C0000000099']);
    $this->pharmaCo = $org->saveLegalEntity(actorFor($this->owner), new LegalEntity, ['name' => 'Demo Pharmacy Ltd', 'tin' => 'C0000000001', 'vat_no' => 'V1']);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    app(IndustryService::class)->save(actorFor($this->owner), $this->pharmacy, ['code' => $this->pharmacy->code, 'name' => $this->pharmacy->name,
        'module_key' => 'pharmacy', 'legal_entity_id' => $this->pharmaCo->id]);
    $this->accountant = staff($this->owner, 'abena', 4, 'accountant');
    $this->bank = Account::where('system_key', 'bank')->value('id');
    $this->day = now()->startOfMonth()->addDays(9)->toDateString();
});

function entityBalance(string $entity, string $key): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)
        ->where('journal_lines.legal_entity_id', $entity)->sum('journal_lines.amount_minor');
}

it('keeps one primary company, and gives a registered industry its own books and TIN on its branches', function () {
    expect($this->group->fresh()->is_primary)->toBeTrue()->and($this->pharmaCo->fresh()->is_primary)->toBeFalse()
        ->and($this->b['P1']->fresh()->legal_entity_id)->toBe($this->pharmaCo->id)
        ->and($this->b['P1']->fresh()->legalEntity->tin)->toBe('C0000000001');
    expect(fn () => app(OrgService::class)->saveLegalEntity(actorFor($this->owner), $this->group->fresh(), ['is_primary' => false]))
        ->toThrow(DomainException::class, 'always one');
    expect(fn () => app(OrgService::class)->saveLegalEntity(actorFor($this->owner), $this->pharmaCo->fresh(), ['is_active' => false]))
        ->toThrow(DomainException::class, 'in use');
});

it('balances every entry inside each company and books what they owe each other', function () {
    $j = app(JournalService::class);
    $power = Account::where('code', '6300')->value('id');
    $sales = Account::where('code', '4100')->value('id');
    $j->manual(actorFor($this->accountant), $this->day, 'Pharmacy sales', [['account_id' => $this->bank, 'debit_minor' => 300000, 'branch_id' => $this->b['P1']->id],
        ['account_id' => $sales, 'credit_minor' => 300000, 'branch_id' => $this->b['P1']->id]]);
    // Head office pays the pharmacy's electricity from its own bank: the pharmacy now owes head office.
    $paid = $j->manual(actorFor($this->accountant), $this->day, 'Pharmacy power paid by HQ', [['account_id' => $power, 'debit_minor' => 20000, 'branch_id' => $this->b['P1']->id],
        ['account_id' => $this->bank, 'credit_minor' => 20000, 'branch_id' => $this->b['HQ']->id]]);

    expect(entityBalance($this->pharmaCo->id, 'intercompany'))->toBe(-20000)
        ->and(entityBalance($this->group->id, 'intercompany'))->toBe(20000);
    $r = app(FinancialReports::class);
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();
    expect($r->trialBalance($this->tenant->id, $from, $to, entity: $this->pharmaCo->id)['totals']['closing'])->toBe(0)
        ->and($r->trialBalance($this->tenant->id, $from, $to, entity: $this->group->id)['totals']['closing'])->toBe(0)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to, entity: $this->pharmaCo->id)['net_profit'])->toBe(280000)
        ->and($r->profitAndLoss($this->tenant->id, $from, $to, entity: $this->group->id)['net_profit'])->toBe(0)
        ->and($r->balanceSheet($this->tenant->id, $to, entity: $this->pharmaCo->id)['balanced'])->toBeTrue()
        ->and($r->balanceSheet($this->tenant->id, $to)['balanced'])->toBeTrue() // the whole group: what they owe each other cancels out
        ->and(app(GlCheck::class)->run($this->tenant->id)['problems'])->toBe([]);

    // Reversing keeps each company's lines where they were.
    $j->reverse(actorFor($this->accountant), $paid, $this->day, 'Paid twice');
    expect(entityBalance($this->pharmaCo->id, 'intercompany'))->toBe(0)->and(app(GlCheck::class)->run($this->tenant->id)['problems'])->toBe([]);
});

it('moves stock between companies at cost through what they owe each other, leaving nothing in transit', function () {
    $pharmacyId = $this->pharmacy->id;
    $groceryId = Industry::where('module_key', 'grocery')->value('id');
    $wh = collect($this->b)->map(fn ($b) => Warehouse::where('branch_id', $b->id)->first());
    $item = function (string $sku, ?string $industry, ?Warehouse $at) {
        $p = makeProduct($this->owner, $sku, $sku, retail: '5.00');
        $p->forceFill(['industry_id' => $industry])->saveQuietly();
        $p = $p->fresh();
        if ($at) {
            app(InventoryService::class)->adjust(actorFor($this->owner), $at, $p, $p->units->first(), '50', 'opening', '', null, '2.50');
        }

        return $p->fresh();
    };
    $waterPh = $item('PH-WATER', $pharmacyId, $wh['P1']);
    $waterGr = $item('GR-WATER', $groceryId, null);
    $svc = app(StockRequestService::class);
    $r = $svc->request(actorFor(staff($this->owner, 'ama', 2, 'storekeeper', ScopeType::Branch, $this->b['G1']->id)), ['from_warehouse_id' => $wh['P1']->id,
        'to_warehouse_id' => $wh['G1']->id, 'lines' => [['product_uom_id' => $waterGr->units->first()->id, 'qty' => '10']]]);
    $svc->approve(actorFor(staff($this->owner, 'kwesi', 4, 'branch_manager', ScopeType::Branch, $this->b['G1']->id)), $r);
    $svc->approve(actorFor(staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->b['P1']->id)), $r->fresh());
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['P1']->id);
    $svc->supply(actorFor($keeper), $r->fresh(), [$r->lines[0]->id => ['send_product_id' => $waterPh->id]]);
    app(TransferService::class)->receive(actorFor($this->owner), StockTransfer::findOrFail($r->fresh()->stock_transfer_id));
    app(AutoPoster::class)->run($this->tenant->id);

    // 10 × 2.50 leaves the pharmacy company's stock and joins the group's; the group owes the pharmacy 25.00.
    expect(DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', 'stock_in_transit')->sum('amount_minor'))->toEqual(0)
        ->and(entityBalance($this->pharmaCo->id, 'intercompany'))->toBe(2500)
        ->and(entityBalance($this->group->id, 'intercompany'))->toBe(-2500)
        ->and(entityBalance($this->group->id, 'inventory'))->toBe(2500)
        ->and(app(GlCheck::class)->run($this->tenant->id)['problems'])->toBe([]);
});

it('offers the books of each company and its own VAT return on the reports screen', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->accountant), 'web');
    Livewire::test(ReportsPage::class, ['report' => 'tb'])->assertSee('Whole group (consolidated)')->assertSee('Demo Pharmacy Ltd (TIN C0000000001)');
    Livewire::test(ReportsPage::class, ['report' => 'vat', 'books' => $this->pharmaCo->id])->assertSee('files its own VAT return')->assertSee('Demo Pharmacy Ltd');
    $this->get(LegalEntityResource::getUrl('index'))->assertOk()->assertSee('C0000000001');
});

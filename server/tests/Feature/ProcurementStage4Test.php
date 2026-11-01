<?php

use App\Domain\Budgets\BudgetService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Payables\BillService;
use App\Domain\Purchasing\AgreementService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\PurchaseRequestService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Models\Account;
use App\Models\Department;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Services']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->gm = staff($this->owner, 'abena', 5, 'general_manager');
    $this->repairs = Account::where('type', 'expense')->where('is_header', false)->whereNull('control')->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->dept = (new Department)->forceFill(['tenant_id' => $this->tenant->id, 'code' => 'OPS', 'name' => 'Operations']);
    $this->dept->save();
    $this->orders = app(PurchaseOrderService::class);
    $this->service = fn (string $cost = '500.00') => $this->orders->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->hq->id, 'lines' => [['account_id' => $this->repairs->id, 'description' => 'Servicing of the generator', 'uom_code' => 'JOB',
            'qty' => '1', 'unit_cost' => $cost, 'department_id' => $this->dept->id]]]);
});

it('buys a service: ordered, confirmed delivered without stock, billed against the order line and charged to the department', function () {
    $po = ($this->service)();
    expect($po->lines[0])->product_id->toBeNull()->account_id->toBe($this->repairs->id)->name->toBe('Servicing of the generator')->unit_cost_minor->toBe(50000);
    $this->orders->submit(actorFor($this->buyer), $po);
    $po = $this->orders->approve(actorFor($this->manager), $po->fresh());

    expect(fn () => app(GoodsReceiptService::class)->receive(actorFor($this->keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '1']]]))
        ->toThrow(DomainException::class, 'confirm it as delivered');
    $bills = app(BillService::class);
    $bill = fn (string $no) => $bills->create(actorFor($this->accountant), $this->supplier, $this->branches['HQ'], ['supplier_invoice_no' => $no, 'bill_date' => today()->toDateString(),
        'lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '1', 'unit_cost_minor' => 50000]]]);
    expect(fn () => $bill('INV-1'))->toThrow(DomainException::class, 'confirmed delivered and not yet billed');

    $po = $this->orders->confirmDelivered(actorFor($this->keeper), $po, [$po->lines[0]->id => '1'], 'Job card 77');
    expect($po->status)->toBe('received')->and(DB::table('stock_movements')->count())->toBe(0);

    $b = $bill('INV-1');
    expect($b->status)->toBe('posted')->and($b->lines[0])->department_id->toBe($this->dept->id)->account_id->toBe($this->repairs->id)
        ->and(fn () => $bill('INV-2'))->toThrow(DomainException::class, 'only 0');

    app(AutoPoster::class)->run($this->tenant->id);
    expect((int) DB::table('journal_lines')->where('account_id', $this->repairs->id)->where('department_id', $this->dept->id)->sum('amount_minor'))->toBe(50000);
});

it('checks the budget: spent plus ordered plus this order, and an order over budget needs a second approval when set so', function () {
    $year = app(GeneralLedger::class)->period($this->tenant->id, today()->toDateString())->year;
    app(BudgetService::class)->save(actorFor($this->owner), $year, $this->branches['HQ'], [$this->repairs->id => BudgetService::spread(80000)]);
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.budget_check', 'approval');

    $first = ($this->service)('500.00'); // within: 800 budget
    $this->orders->submit(actorFor($this->buyer), $first);
    expect($first->fresh()->over_budget)->toBeFalse();

    $second = ($this->service)('400.00'); // 500 committed + 400 = 900 > 800
    $this->orders->submit(actorFor($this->buyer), $second);
    $second = $second->fresh();
    expect($second->over_budget)->toBeTrue()->and($second->budget_note)->toContain('over by 100.00');
    expect(fn () => $this->orders->approve(actorFor($this->manager), $second))->toThrow(DomainException::class, 'Approve orders over budget');
    expect($this->orders->approve(actorFor($this->gm), $second)->status)->toBe('approved');

    // A request shows the same check at its estimated price.
    $r = app(PurchaseRequestService::class)->request(actorFor($this->owner), ['warehouse_id' => $this->hq->id, 'department_id' => $this->dept->id,
        'lines' => [['account_id' => $this->repairs->id, 'description' => 'Air conditioner service', 'qty' => '2', 'est_unit_cost' => '50.00']]]);
    $check = app(PurchaseRequestService::class)->budget($r->fresh('lines'));
    expect($check[0])->budget->toBe(80000)->committed->toBe(90000)->this->toBe(10000)->left->toBe(-20000)
        ->and($r->lines[0]->department_id)->toBe($this->dept->id);
    $po = app(PurchaseRequestService::class)->order(actorFor($this->buyer), $r->fresh('lines'), $this->supplier);
    expect($po->lines[0])->account_id->toBe($this->repairs->id)->unit_cost_minor->toBe(5000)->and($po->department_id)->toBe($this->dept->id);
});

it('prices orders from a blanket order, never above it, and stops at its limits', function () {
    $svc = app(AgreementService::class);
    $a = $svc->save(actorFor($this->buyer), new PurchaseAgreement, ['supplier_id' => $this->supplier->id, 'kind' => 'blanket', 'title' => 'Milo 2027',
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(20)->toDateString(), 'value_cap' => '5000',
        'lines' => [['product_uom_id' => $this->ctn->id, 'unit_cost' => '230.00', 'max_qty' => '15']]]);
    expect($a->number)->toMatch('/^AGR-/')->and($a->status)->toBe('draft')
        ->and(fn () => $svc->activate(actorFor($this->buyer), $a))->toThrow(Exception::class);
    $svc->activate(actorFor($this->manager), $a);

    // No price typed: the agreed price. A higher price is refused.
    $po = $this->orders->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '4']]]);
    expect($po->lines[0])->unit_cost_minor->toBe(23000)->agreement_line_id->toBe($a->lines[0]->id);
    expect(fn () => $this->orders->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '1', 'unit_cost' => '250.00']]]))->toThrow(DomainException::class, 'the agreed price is');

    $released = $svc->release(actorFor($this->buyer), $a->fresh('lines'), $this->hq, [$a->lines[0]->id => '10']);
    expect($released)->agreement_id->toBe($a->id)->and($released->lines[0]->qty)->toBe('10.000');
    expect(fn () => $svc->release(actorFor($this->buyer), $a->fresh('lines'), $this->hq, [$a->lines[0]->id => '2']))->toThrow(DomainException::class, 'only 1.000');
    expect($svc->usage($a)['value'])->toBe(322000);

    $this->travel(15)->days();
    expect($svc->endAlerts($this->tenant->id))->toBe(1)->and($svc->endAlerts($this->tenant->id))->toBe(0);
    $this->travel(10)->days();
    expect($svc->endAlerts($this->tenant->id))->toBe(1)
        ->and(fn () => $svc->release(actorFor($this->buyer), $a->fresh('lines'), $this->hq, [$a->lines[0]->id => '1']))->toThrow(DomainException::class, 'not in force');
});

it('shows the agreement, order and request screens with service lines', function () {
    $a = app(AgreementService::class)->save(actorFor($this->buyer), new PurchaseAgreement, ['supplier_id' => $this->supplier->id, 'kind' => 'contract', 'title' => 'Generator care 2027',
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString(),
        'lines' => [['account_id' => $this->repairs->id, 'description' => 'Quarterly service', 'uom_code' => 'JOB', 'unit_cost' => '450.00']]]);
    $po = ($this->service)();
    $this->actingAs(withMfa($this->owner));
    foreach ([PurchaseAgreementResource::getUrl('index'), PurchaseAgreementResource::getUrl('create'), PurchaseAgreementResource::getUrl('edit', ['record' => $a]),
        PurchaseOrderResource::getUrl('edit', ['record' => $po]), PurchaseRequestResource::getUrl('create'), SupplierBillResource::getUrl('create')] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(PurchaseAgreementResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('Generator care 2027')->assertSee('Quarterly service');
    $this->get(PurchaseOrderResource::getUrl('view', ['record' => $po]))->assertOk()->assertSee('Servicing of the generator')->assertSee('Operations');
});

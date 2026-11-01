<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Finance\AutoPoster;
use App\Domain\Payables\BillService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SpendAnalytics;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Pages\SpendAnalyticsPage;
use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Wholesale', 'tax_no' => 'C0009998887']);
    $this->other = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Accra Traders']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->repairs = Account::where('type', 'expense')->where('is_header', false)->whereNull('control')->where('is_active', true)->orderBy('code')->firstOrFail();
    $orders = app(PurchaseOrderService::class);
    $approve = function (PurchaseOrder $po) use ($orders) {
        $orders->submit(actorFor($this->buyer), $po);

        return $orders->approve(actorFor($this->manager), $po->fresh());
    };
    // Goods: 10 cartons of Milo received; a service: generator repair confirmed.
    $this->goods = $approve($orders->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->milo->units->firstWhere('is_base', false)->id, 'qty' => '10', 'unit_cost' => '240.00']]]));
    app(GoodsReceiptService::class)->receive(actorFor($this->keeper), $this->goods, ['lines' => [['purchase_order_line_id' => $this->goods->lines[0]->id, 'qty' => '10']]]);
    $this->repair = $approve($orders->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['account_id' => $this->repairs->id, 'description' => 'Generator repair', 'uom_code' => 'JOB', 'qty' => '1', 'unit_cost' => '600.00']]]));
    $orders->confirmDelivered(actorFor($this->keeper), $this->repair, [$this->repair->lines[0]->id => '1']);
    AiGateway::fake(null);
});

afterEach(fn () => AiGateway::fake(null));

function readInvoice(array $data): FakeAiProvider
{
    $fake = new FakeAiProvider([new AiReply(json_encode($data))]);
    AiGateway::fake($fake);

    return $fake;
}

it('reads an invoice quoting the order: goods to the delivery, the repair to the confirmed service, with checks to look at', function () {
    Storage::fake('local');
    $path = InvoiceScanner::store(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), 'png');
    readInvoice(['supplier_name' => 'KOFI WHOLESALE', 'supplier_tax_no' => 'C000 999 8887', 'invoice_number' => 'KW-501', 'order_number' => ltrim(substr($this->goods->number, -6), '0'),
        'invoice_date' => today()->toDateString(), 'subtotal' => 3500, 'tax_total' => 0, 'total' => 3500, 'lines' => [
            ['description' => 'Milo 400g carton', 'sku' => 'MILO', 'qty' => 10, 'unit_price' => 240, 'line_total' => 2400],
            ['description' => 'Generator repair', 'sku' => null, 'qty' => 1, 'unit_price' => 600, 'line_total' => 600],
            ['description' => 'Transport', 'sku' => null, 'qty' => 1, 'unit_price' => 100, 'line_total' => 100],
        ]]);
    $doc = app(InvoiceScanner::class)->scan(actorFor($this->accountant), $this->branches['HQ'], $path, 'kw-501.png');

    expect($doc->form['supplier_id'])->toBe($this->supplier->id)
        ->and($doc->form['stock_lines'])->toHaveCount(1)
        ->and($doc->form['order_lines'][0])->toMatchArray(['purchase_order_line_id' => $this->repair->lines[0]->id, 'qty' => '1', 'unit_cost' => '600.00'])
        ->and($doc->form['expense_lines'][0]['description'])->toBe('Transport')
        ->and(InvoiceScanner::orderOf($this->supplier, (string) $doc->extracted['order_number']))->toBe($this->goods->number);
    $checks = InvoiceScanner::checks($doc);
    expect(implode(' ', $checks))->toContain('add up to 3,100.00 but the invoice says 3,500.00')->toContain('matched nothing received or ordered');

    // Entered as is (with the transport charged to an account), then read again: the duplicate is pointed out.
    $this->actingAs($this->accountant);
    app(BillService::class)->create(actorFor($this->accountant), $this->supplier, $this->branches['HQ'], SupplierBillResource::toDomain(array_merge($doc->form, [
        'expense_lines' => [['account_id' => $this->repairs->id, 'description' => 'Transport', 'amount' => '100.00']]])));
    expect(implode(' ', InvoiceScanner::checks($doc->fresh())))->toContain('already entered as BILL-');
});

it('enters an invoice sent on the supplier portal as a bill and marks it entered', function () {
    Storage::fake('local');
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_portal', true);
    $portal = app(SupplierPortal::class);
    $sent = $portal->submitInvoice($this->supplier, ['invoice_no' => 'KW-777', 'invoice_date' => today()->toDateString(), 'amount' => '600', 'purchase_order_id' => $this->repair->id],
        'supplier-invoices/kw.pdf', 'kw.pdf', 'Kofi');
    $this->actingAs(withMfa($this->owner));
    $this->get(SupplierInvoiceResource::getUrl('index'))->assertOk()->assertSee('Enter as bill');
    $this->get(SupplierBillResource::getUrl('create', ['invoice' => $sent->id]))->assertOk()->assertSee('KW-777')->assertSee('marked entered');

    Livewire\Livewire::test(CreateSupplierBill::class, ['invoice' => $sent->id])
        ->set('data.order_lines', [['purchase_order_line_id' => $this->repair->lines[0]->id, 'qty' => '1', 'unit_cost' => '600.00']])
        ->call('create')->assertHasNoErrors();
    expect($sent->fresh())->status->toBe('entered')->supplier_bill_id->not->toBeNull();
});

it('analyses spend: by supplier, category and month, bought on order or not, and against order prices', function () {
    $bills = app(BillService::class);
    $grnLine = collect($bills->billable(GoodsReceipt::firstOrFail()))->first();
    $bills->create(actorFor($this->accountant), $this->supplier, $this->branches['HQ'], ['supplier_invoice_no' => 'A1', 'bill_date' => today()->toDateString(), 'lines' => [
        ['goods_receipt_line_id' => $grnLine['goods_receipt_line_id'], 'qty' => '10', 'unit_cost_minor' => 24100], // 10 above the order price
        ['purchase_order_line_id' => $this->repair->lines[0]->id, 'qty' => '1', 'unit_cost_minor' => 60000],
    ]]);
    $bills->create(actorFor($this->accountant), $this->other, $this->branches['HQ'], ['supplier_invoice_no' => 'B1', 'bill_date' => today()->toDateString(),
        'lines' => [['account_id' => $this->repairs->id, 'description' => 'Cleaning', 'amount_minor' => 40000]]]);
    app(AutoPoster::class)->run($this->tenant->id);

    $a = SpendAnalytics::for($this->tenant->id, today()->startOfMonth()->toDateString(), today()->toDateString());
    expect($a->totals())->toBe(['spend' => 341000, 'bills' => 2, 'suppliers' => 2])
        ->and($a->bySupplier()[0])->toMatchArray(['name' => $this->supplier->name, 'spend' => 301000, 'share' => 88.3])
        ->and($a->concentration())->toBe(['top1' => 88.3, 'top5' => 100.0])
        ->and($a->compliance())->toMatchArray(['on_order' => 301000, 'no_order' => 40000, 'on_order_pct' => 88.3])
        ->and($a->priceVariance())->toMatchArray(['variance' => 1000, 'bills_above' => 1])
        ->and(collect($a->byCategory())->pluck('spend')->sum())->toBe(341000)
        ->and(collect($a->byMonth())->last())->toMatchArray(['spend' => 341000]);

    $this->actingAs(withMfa($this->owner));
    $this->get(SpendAnalyticsPage::getUrl())->assertOk()->assertSee('Spend analytics')->assertSee('88.3 %')->assertSee($this->other->name);
});

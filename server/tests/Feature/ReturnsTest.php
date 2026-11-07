<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\OfficeReturns;
use App\Domain\Pos\ReturnService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ReturnsReport;
use App\Filament\Resources\OfficeReturns\Pages\ListOfficeReturns;
use App\Filament\Resources\OfficeReturns\Pages\ViewOfficeReturn;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Account;
use App\Models\OfficeReturn;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** A posted sale of $qty of a product, with its payment. Built directly: this test is about what comes back. */
function soldReceipt($test, $product, int $qty = 2, string $method = 'cash'): Sale
{
    $unit = $product->units->firstWhere('is_base', true);
    $id = (string) Str::uuid7();
    $total = 1000 * $qty;
    $sale = (new Sale)->forceFill(['id' => $id, 'tenant_id' => $test->tenant->id, 'branch_id' => $test->hq->branch_id, 'warehouse_id' => $test->hq->id, 'receipt_no' => 'R-'.substr($id, -8), 'cashier_id' => $test->owner->id, 'cashier_name' => 'Ama', 'price_list_id' => DB::table('price_lists')->where('is_default', true)->value('id'),
        'prices_include_tax' => true, 'currency_code' => 'GHS', 'net_minor' => $total, 'tax_minor' => 0, 'total_minor' => $total, 'paid_minor' => $total, 'change_minor' => 0, 'tax_breakdown' => [], 'status' => 'posted',
        'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(), 'received_at' => now(), 'kind' => 'sale']);
    $sale->save();
    DB::table('sale_lines')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'line_no' => 1, 'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'product_uom_id' => $unit->id,
        'uom_code' => 'PC', 'factor' => 1, 'qty' => $qty, 'unit_price_minor' => 1000, 'line_total_minor' => $total, 'net_minor' => $total, 'tax_minor' => 0, 'tax_breakdown' => '[]']);
    DB::table('sale_payments')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'method' => $method, 'amount_minor' => $total, 'tendered_minor' => $total, 'status' => 'approved']);

    return $sale->fresh(['lines', 'payments']);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->item = makeProduct($this->owner, 'ITEM', 'Vitamin C', retail: '10.00')->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->item, $this->item->units->first(), '10', 'opening', '', 'x', '4.00');
    $this->returns = app(OfficeReturns::class);
    $this->gm = withMfa(staff($this->owner, 'gm', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id));
    $this->clerk = withMfa(staff($this->owner, 'clerk', 4, 'accountant', ScopeType::Tenant, $this->tenant->id));
    $this->money = Account::where('system_key', 'bank')->sole();
    $this->qty = fn ($p, $w) => (float) DB::table('stock_levels')->where('warehouse_id', $w->id)->where('product_id', $p->id)->value('on_hand');
});

it('requires a manager on every return by default', function () {
    expect(Settings::DEFINITIONS['returns.manager_approval']['default'] ?? null)->toBe('require')
        ->and(array_keys(Settings::DEFINITIONS['returns.manager_approval']['options']))->toBe(['require', 'allow', 'off'])
        ->and(Settings::DEFINITIONS['returns.medicines_to_quarantine']['default'])->toBeFalse();
});

it('books a back-office return with a second person, into the shelf, refunded from an account', function () {
    $sale = soldReceipt($this, $this->item);
    $doc = $this->returns->submit(actorFor($this->clerk), $sale, ['lines' => [['line_id' => $sale->lines[0]->id, 'qty' => '1']], 'reason_code' => 'wrong_item', 'refund_method' => 'money',
        'account_id' => $this->money->id, 'reference' => 'MOMO-9']);
    expect($doc->status)->toBe('submitted')->and($doc->total_minor)->toBe(1000)->and(($this->qty)($this->item, $this->hq))->toBe(10.0);

    expect(fn () => $this->returns->approve(actorFor($this->clerk), $doc))->toThrow(DomainException::class);
    $posted = $this->returns->approve(actorFor($this->gm), $doc);
    $return = $posted->sale;
    expect($posted->status)->toBe('posted')->and($return->kind)->toBe('return')->and($return->total_minor)->toBe(-1000)->and($return->till_id)->toBeNull()
        ->and($return->discount_approved_by_name)->toBe($this->gm->name)->and($return->return_reason_code)->toBe('wrong_item')
        ->and(($this->qty)($this->item, $this->hq))->toBe(11.0)
        ->and(app(ReturnService::class)->returnable($sale->fresh(['lines', 'payments']))['lines'][0]['returnable_qty'])->toBe('1.000');

    app(AutoPoster::class)->run($this->tenant->id);
    $check = app(GlCheck::class)->run($this->tenant->id);
    expect(collect($check['ties'])->firstWhere('control', 'inventory')['difference'])->toBe(0)
        ->and((int) DB::table('journal_lines')->where('account_id', $this->money->id)->sum('amount_minor'))->toBe(-1000);
});

it('refunds as store credit, and refuses more than can come back or an account refund with no customer', function () {
    $sale = soldReceipt($this, $this->item);
    $line = ['line_id' => $sale->lines[0]->id, 'qty' => '1'];
    expect(fn () => $this->returns->submit(actorFor($this->clerk), $sale, ['lines' => [['qty' => '3'] + $line], 'reason_code' => 'other', 'refund_method' => 'store_credit']))->toThrow(DomainException::class, 'can still be returned');
    expect(fn () => $this->returns->submit(actorFor($this->clerk), $sale, ['lines' => [$line], 'reason_code' => 'other', 'refund_method' => 'account']))->toThrow(DomainException::class, 'no customer');

    $doc = $this->returns->approve(actorFor($this->gm), $this->returns->submit(actorFor($this->clerk), $sale, ['lines' => [$line], 'reason_code' => 'other', 'refund_method' => 'store_credit']));
    expect($doc->sale->payments->first()->method)->toBe('store_credit')->and($doc->sale->payments->first()->reference)->not->toBeNull();
    $it = staff($this->owner, 'itperson', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id);
    expect(fn () => $this->returns->submit(actorFor($it), $sale, ['lines' => [$line], 'reason_code' => 'other', 'refund_method' => 'store_credit']))->toThrow(AccessDenied::class);
});

it('sends damaged goods to quarantine, where no till sells them, and medicines only when set to', function () {
    $sale = soldReceipt($this, $this->item);
    $damaged = $this->returns->approve(actorFor($this->gm), $this->returns->submit(actorFor($this->clerk), $sale,
        ['lines' => [['line_id' => $sale->lines[0]->id, 'qty' => '1', 'damaged' => true]], 'reason_code' => 'other', 'refund_method' => 'store_credit']));
    $q = Warehouse::where('branch_id', $this->hq->branch_id)->where('is_quarantine', true)->sole();
    expect(($this->qty)($this->item, $q))->toBe(1.0)->and(($this->qty)($this->item, $this->hq))->toBe(10.0)->and($damaged->sale->lines[0]->returned_to)->toBe('quarantine');

    $med = makeProduct($this->owner, 'MED', 'Amoxicillin', retail: '10.00');
    app(CatalogService::class)->saveProduct($this->a, $med, ['drug_schedule' => 'pom', 'track_batches' => true]);
    $med = $med->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $med, $med->units->first(), '5', 'opening', '', null, '4.00', 'B1', now()->addYear()->toDateString());
    $sold = soldReceipt($this, $med);
    $back = fn (string $code = 'changed_mind') => $this->returns->approve(actorFor($this->gm), $this->returns->submit(actorFor($this->clerk), $sold->fresh(['lines', 'payments']), ['lines' => [['line_id' => $sold->lines[0]->id, 'qty' => '1']], 'reason_code' => $code, 'refund_method' => 'store_credit']));

    // By default a returned medicine goes back on sale.
    expect($back()->sale->lines[0]->returned_to)->toBe('shelf')->and(($this->qty)($med, $q))->toBe(0.0)->and(($this->qty)($med, $this->hq))->toBe(6.0);

    // With the setting on it goes to quarantine.
    app(Settings::class)->set($this->a, 'returns.medicines_to_quarantine', true);
    expect($back()->sale->lines[0]->returned_to)->toBe('quarantine')->and(($this->qty)($med, $q))->toBe(1.0)->and(($this->qty)($med, $this->hq))->toBe(6.0);
});

it('shows the screens: return from a sale, approve, slip and report', function () {
    $sale = soldReceipt($this, $this->item);
    $this->actingAs($this->clerk, 'web');
    Livewire::test(ViewSale::class, ['record' => $sale->id])->assertSuccessful()->callAction('officeReturn', ['reason_code' => 'wrong_item', 'refund_method' => 'store_credit',
        'lines' => [['line_id' => $sale->lines[0]->id, 'qty' => '1', 'damaged' => false]]])->assertHasNoActionErrors();
    $doc = OfficeReturn::sole();
    Livewire::test(ListOfficeReturns::class)->assertSuccessful()->assertSee($doc->number);
    $this->actingAs($this->gm, 'web');
    Livewire::test(ViewOfficeReturn::class, ['record' => $doc->id])->assertSuccessful()->callAction('approve')->assertHasNoActionErrors();
    expect($doc->fresh()->status)->toBe('posted');

    Livewire::test(ViewOfficeReturn::class, ['record' => $doc->id])->callAction('slip')->assertFileDownloaded();
    Livewire::test(ReturnsReport::class)->assertSuccessful()->assertSee('1 return(s) in the last 30 days')->assertCountTableRecords(1)
        ->filterTable('return_reason_code', ['other'])->assertCountTableRecords(0);
    Livewire::test(ReturnsReport::class)->callAction(TestAction::make('slip')->table($doc->fresh()->sale))->assertFileDownloaded();
});

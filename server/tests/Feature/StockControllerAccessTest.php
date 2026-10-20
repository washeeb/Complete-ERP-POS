<?php

use App\Domain\Inventory\BinService;
use App\Domain\Purchasing\PurchaseRequestService;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Models\Bin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->controller = withMfa(staff($this->owner, 'ama', 3, 'stock_controller', ScopeType::Tenant, $this->tenant->id));
    $this->buyer = withMfa(staff($this->owner, 'kofi', 2, 'purchasing_officer', ScopeType::Branch, $this->branches['HQ']->id));
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana', 'phone' => '0302000000']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lets a stock controller set reorder levels and create bins, but not switch a store to bins', function () {
    $a = actorFor($this->controller);
    $rule = app(ReorderService::class)->saveRule($a, $this->hq, $this->milo, '24', '96');
    expect((string) $rule->min_qty)->toStartWith('24');

    expect(fn () => app(BinService::class)->setUseBins($a, $this->hq, true))->toThrow(AccessDenied::class);
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    app(BinService::class)->saveBin($a, new Bin, ['warehouse_id' => $this->hq->id, 'code' => 'A-01-01', 'name' => 'Front shelf']);
    expect(Bin::where('code', 'A-01-01')->exists())->toBeTrue();

    $this->actingAs($this->controller, 'web')->get(ReorderRuleResource::getUrl('index'))->assertOk();

    // The morning low-stock alert reaches whoever sets reorder levels, as well as the buyers.
    Artisan::call('erp:reorder-levels', ['--alerts' => true]);
    expect(DB::table('notifications')->where('notifiable_id', $this->controller->id)->where('data', 'like', '%at or below their minimum%')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('notifications')->where('notifiable_id', $this->buyer->id)->where('data', 'like', '%at or below their minimum%')->count())->toBe(1);
});

it('asks the buyers to order: a buyer turns the request into a draft purchase order, or rejects it', function () {
    $ctn = $this->milo->units->firstWhere('is_base', false);
    $this->actingAs($this->controller, 'web');
    Livewire::test(ListPurchaseRequests::class)->assertOk();
    Livewire::test(CreatePurchaseRequest::class)
        ->fillForm(['warehouse_id' => $this->hq->id, 'needed_by' => now()->addDays(3)->toDateString(), 'note' => 'Running low before the weekend',
            'lines' => [['product_uom_id' => $ctn->id, 'qty' => '4', 'note' => 'Large tins']]])
        ->assertSee('In stock now')->call('create')->assertHasNoFormErrors();
    $r = PurchaseRequest::sole();
    expect($r->number)->toStartWith('PRQ')->and($r->status)->toBe('requested')->and($r->lines()->sole()->uom_code)->toBe('CTN')
        ->and(DB::table('notifications')->where('notifiable_id', $this->buyer->id)->where('data', 'like', "%{$r->number}%")->count())->toBe(1);

    // The person who asked cannot order it themselves.
    expect(fn () => app(PurchaseRequestService::class)->order(actorFor($this->controller), $r, $this->supplier))->toThrow(AccessDenied::class);

    app('auth')->forgetGuards();
    $this->actingAs($this->buyer, 'web');
    Livewire::test(ViewPurchaseRequest::class, ['record' => $r->id])->assertActionVisible('order')->assertActionHidden('cancel')
        ->callAction('order', ['supplier_id' => $this->supplier->id]);
    $po = PurchaseOrder::sole();
    expect($r->fresh()->status)->toBe('ordered')->and($r->fresh()->purchase_order_id)->toBe($po->id)
        ->and($po->status)->toBe('draft')->and($po->lines()->sole()->product_uom_id)->toBe($ctn->id)->and((string) $po->lines()->sole()->qty)->toStartWith('4');

    // A second request, rejected with the reason.
    $r2 = app(PurchaseRequestService::class)->request(actorFor($this->controller), ['warehouse_id' => $this->hq->id, 'lines' => [['product_uom_id' => $ctn->id, 'qty' => '1']]]);
    app(PurchaseRequestService::class)->reject(actorFor($this->buyer), $r2, 'Enough on the way');
    expect($r2->fresh()->status)->toBe('rejected')->and($r2->fresh()->closed_reason)->toBe('Enough on the way');
});

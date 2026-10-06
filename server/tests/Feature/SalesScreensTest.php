<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\CustomerOrderService;
use App\Filament\Pages\AgedReceivables;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Resources\CustomerOrders\Pages\CreateCustomerOrder;
use App\Filament\Resources\CustomerOrders\Pages\ViewCustomerOrder;
use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00'], wholesale: '13.00');
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->ctn, '40', 'opening', '');
    $this->wholesale = PriceList::where('code', 'WHOLESALE')->first();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('creates a credit customer with consent from the screen and shows the account', function () {
    Livewire::test(CreateCustomer::class)->fillForm(['type' => 'business', 'name' => 'Kofi Provisions', 'phone' => '024 555 1234', 'price_list_id' => $this->wholesale->id,
        'consents' => ['sms' => true], 'consent_source' => 'paper form', 'credit_limit' => '10000', 'payment_terms_days' => 30])
        ->call('create')->assertHasNoFormErrors();
    $c = Customer::sole();
    expect($c->phone)->toBe('+233245551234')->and($c->credit_limit_minor)->toBe(1_000_000)->and($c->consents['sms']['source'])->toBe('paper form');

    $this->get(CustomerResource::getUrl('view', ['record' => $c]))->assertOk()->assertSee('Kofi Provisions')->assertSee('10,000.00')->assertSee('Nothing outstanding')->assertSee('SMS');
    $this->get(CustomerResource::getUrl())->assertOk()->assertSee('CUS-');
});

it('runs quote → order → delivery → invoice → payment → statement from the screens', function () {
    $c = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['type' => 'business', 'name' => 'Kofi Provisions', 'price_list_id' => $this->wholesale->id,
        'credit_limit_minor' => 1_000_000, 'payment_terms_days' => 30]);

    Livewire::test(CreateCustomerOrder::class)->fillForm(['customer_id' => $c->id, 'warehouse_id' => $this->hq->id, 'customer_ref' => 'KP-88',
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '10']]])->call('create')->assertHasNoFormErrors();
    $o = CustomerOrder::sole();
    $this->get(route('print.order', $o))->assertOk()->assertSee('QUOTATION')->assertSee($o->quote_number)->assertSee('3,300.00');

    Livewire::test(ViewCustomerOrder::class, ['record' => $o->id])->callAction('confirm')->assertNotified();
    Livewire::test(ViewCustomerOrder::class, ['record' => $o->id])
        ->callAction('deliver', ['lines' => [['line_id' => $o->lines[0]->id, 'qty' => '10']], 'delivered_to' => 'Kofi'])->assertNotified();
    $inv = Invoice::sole();
    expect($o->fresh()->status)->toBe('delivered');
    $this->get(InvoiceResource::getUrl('view', ['record' => $inv]))->assertOk()->assertSee($inv->number)->assertSee('3,300.00');
    $this->get(route('print.invoice', $inv))->assertOk()->assertSee('TAX INVOICE')->assertSee('KP-88')->assertSee('Milo 400g');

    Livewire::test(ViewCustomer::class, ['record' => $c->id])
        ->callAction('pay', ['branch_id' => $this->branches['HQ']->id, 'amount' => '1000', 'method' => 'cash'])->assertNotified('Payment recorded');
    $pay = CustomerPayment::sole();
    $this->get(CustomerPaymentResource::getUrl())->assertOk()->assertSee($pay->number);
    $this->get(route('print.payment', $pay))->assertOk()->assertSee('PAYMENT RECEIPT')->assertSee($inv->number);
    $this->get(route('print.statement', ['customer' => $c->id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()->assertSee('STATEMENT')->assertSee($inv->number)->assertSee('2,300.00');

    $this->travel(40)->days();
    Livewire::test(AgedReceivables::class)->assertSee('Kofi Provisions')->assertSee('2,300.00');
    Livewire::test(ViewInvoice::class, ['record' => $inv->id])->callAction('credit', ['reason' => 'Wrong delivery'])->assertNotified('Credit note issued');
    expect($inv->fresh()->status)->toBe('credited');
});

it('shows a held order and lets a manager release it', function () {
    $c = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Small Shop', 'price_list_id' => $this->wholesale->id,
        'credit_limit_minor' => 100_000, 'payment_terms_days' => 14]);
    $rep = withMfa(staff($this->owner, 'abena', 2, 'sales_rep', ScopeType::Branch, $this->branches['HQ']->id));
    $o = app(CustomerOrderService::class)->saveQuotation(actorFor($rep), new CustomerOrder, ['customer_id' => $c->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '5']]]);
    app(CustomerOrderService::class)->confirm(actorFor($rep), $o);

    $this->get(CustomerOrderResource::getUrl('view', ['record' => $o]))->assertOk()->assertSee('On credit hold')->assertSee('over the limit');
    Livewire::test(ViewCustomerOrder::class, ['record' => $o->id])->assertActionVisible('release')->callAction('release', ['reason' => 'Cheque received'])->assertNotified('Released');
    expect($o->fresh()->status)->toBe('confirmed');
});

it('hides sales and receivables from people without access', function () {
    $keeper = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($keeper);
    $this->get(CustomerResource::getUrl())->assertForbidden();
    $this->get(AgedReceivables::getUrl())->assertForbidden();
});

it('gives a newly installed owner unlimited approval limits', function () {
    expect($this->owner->roleAssignments()->first()->limits)->toMatchArray(['po_approve_minor' => 999_999_999_999, 'credit_override_minor' => 999_999_999_999]);
});

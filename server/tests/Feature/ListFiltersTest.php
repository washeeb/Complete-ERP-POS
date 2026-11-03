<?php

use App\Filament\Resources\CustomerOrders\Pages\ListCustomerOrders;
use App\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\MomoCharges\Pages\ListMomoCharges;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Resources\TillDrawerEvents\Pages\ListTillDrawerEvents;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->actingAs(withMfa($this->owner), 'web');
});

// Each list takes the new filters and sorts without a database error (the till sales list is tested with sales in PosSaleTest).
it('filters and sorts the sales and payment lists by person, amount and dates', function (string $page, array $filters, array $sorts) {
    $list = Livewire::test($page);
    foreach ($filters as $name => $value) {
        $list->filterTable($name, $value)->assertSuccessful()->resetTableFilters();
    }
    foreach ($sorts as $column) {
        $list->sortTable($column)->assertSuccessful();
    }
})->with([
    'till shifts' => [ListShifts::class, ['opened_by' => [], 'opened_at_dates' => ['from' => '2026-11-01', 'until' => '2026-11-30'], 'over_short_minor_range' => ['from' => '-5', 'to' => '5']], ['over_short_minor']],
    'mobile money' => [ListMomoCharges::class, ['requested_by_name' => [], 'amount_minor_range' => ['from' => '10', 'to' => '500'], 'created_at_dates' => ['from' => '2026-11-01']], ['amount_minor', 'requested_by_name']],
    'customer payments' => [ListCustomerPayments::class, ['method' => ['cash', 'momo'], 'received_by_name' => [], 'amount_minor_range' => ['from' => '1'], 'received_on_dates' => ['until' => '2026-11-30']], ['amount_minor', 'received_by_name']],
    'invoices' => [ListInvoices::class, ['total_minor_range' => ['to' => '1000'], 'issued_on_dates' => ['from' => '2026-11-01']], ['total_minor']],
    'online orders' => [ListSalesOrders::class, ['total_minor_range' => ['from' => '20'], 'placed_at_dates' => ['until' => '2026-11-30']], ['total_minor']],
    'quotes and orders' => [ListCustomerOrders::class, ['total_minor_range' => ['from' => '20'], 'created_by' => []], ['total_minor']],
    'drawer openings' => [ListTillDrawerEvents::class, ['user_id' => []], []],
]);

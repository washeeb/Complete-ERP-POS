<?php

namespace App\Domain\Office;

use App\Models\Customer;
use App\Models\DirectPurchase;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TillDrawerEvent;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Export" on the new back office's lists, as on every list of the current one (TableExport): what the list shows — its
 * search, filters and order, all pages — as Excel, CSV or PDF, through the same Exporter (licence check, audit row,
 * CSV-injection guard, PDF row limit). Each list's rows come from the same query and row as the screen itself
 * (filtered / sorted / row of its screen class), so the file matches the screen.
 */
final class ListExport
{
    public const LISTS = ['sales', 'shifts', 'drawer', 'products', 'stock', 'movements', 'adjustments', 'counts', 'transfers', 'requests', 'customers', 'invoices', 'suppliers', 'purchase-orders', 'goods-receipts', 'direct-purchases', 'supplier-invoices', 'reorder-rules', 'expense-claims', 'supplier-bills', 'supplier-payments', 'returns', 'journals', 'fixed-assets', 'price-list'];

    public function __construct(private readonly User $user) {}

    /** Whether this person may see the list at all (the same check as the screen). */
    public static function canView(string $list): bool
    {
        return match ($list) {
            'sales' => SalesScreen::canView(),
            'shifts', 'drawer' => ShiftsScreen::canView(),
            'products' => ProductsScreen::canView(),
            'stock', 'movements' => StockScreen::canView(),
            'adjustments' => AdjustmentsScreen::canView(),
            'counts' => CountsScreen::canView(),
            'transfers' => TransfersScreen::canView(),
            'requests' => RequestsScreen::canView(),
            'customers' => CustomersScreen::canView(),
            'invoices' => InvoicesScreen::canView(),
            'suppliers' => SuppliersScreen::canView(),
            'purchase-orders' => PurchaseOrdersScreen::canView(),
            'goods-receipts' => GoodsReceiptsScreen::canView(),
            'direct-purchases' => DirectPurchasesScreen::canView(),
            'supplier-invoices' => SupplierInvoicesScreen::canView(),
            'reorder-rules' => ReorderScreen::canRules(),
            'expense-claims' => ExpenseClaimsScreen::canView(),
            'supplier-bills' => SupplierBillsScreen::canView(),
            'supplier-payments' => SupplierPaymentsScreen::canView(),
            'returns' => ReturnsScreen::canView(),
            'journals' => JournalsScreen::canView(),
            'fixed-assets' => AssetsScreen::canView(),
            'price-list' => PriceListScreen::canView(),
            default => false,
        };
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    private function at(?string $iso): string
    {
        return $iso ? CarbonImmutable::parse($iso)->setTimezone($this->user->tenant?->timezone ?: 'UTC')->format('Y-m-d H:i') : '';
    }

    private function money(?int $minor, ?string $currency = null): string
    {
        return $minor === null ? '' : Money::display($minor, $currency ?? $this->currency());
    }

    /**
     * The sheet for the Exporter, and how many rows it has.
     *
     * @return array{title: string, sheet: array, count: int, filters: list<string>}
     */
    public function sheet(string $list, array $p): array
    {
        unset($p['page'], $p['per'], $p['format'], $p['r']);
        [$title, $headings, $numeric, $query, $row] = match ($list) {
            'sales' => $this->sales($p),
            'shifts' => $this->shifts($p),
            'drawer' => $this->drawer($p),
            'products' => $this->products($p),
            'stock' => $this->stock($p),
            'movements' => $this->movements($p),
            'adjustments' => $this->adjustments($p),
            'counts' => $this->counts($p),
            'transfers' => $this->transfers($p),
            'requests' => $this->requests($p),
            'customers' => $this->customers($p),
            'invoices' => $this->invoices($p),
            'suppliers' => $this->suppliers($p),
            'purchase-orders' => $this->purchaseOrders($p),
            'goods-receipts' => $this->goodsReceipts($p),
            'direct-purchases' => $this->directPurchases($p),
            'supplier-invoices' => $this->supplierInvoices($p),
            'reorder-rules' => $this->reorderRules($p),
            'expense-claims' => $this->expenseClaims($p),
            'supplier-bills' => $this->supplierBills($p),
            'supplier-payments' => $this->supplierPayments($p),
            'returns' => $this->returns($p),
            'journals' => $this->journals($p),
            'fixed-assets' => $this->fixedAssets($p),
            'price-list' => $this->priceList($p),
        };
        /** @var Builder $query */
        $count = (clone $query)->toBase()->getCountForPagination();
        $rows = (function () use ($query, $row) {
            foreach ($query->lazy(500) as $record) {
                yield array_map(fn ($v) => $v === null ? '' : (string) $v, $row($record));
            }
        })();

        return ['title' => $title, 'count' => $count, 'filters' => $this->describe($p),
            'sheet' => ['title' => $title, 'headings' => $headings, 'rows' => $rows, 'numeric' => $numeric]];
    }

    private function sales(array $p): array
    {
        $screen = new SalesScreen($this->user);

        return ['Till sales', ['Receipt', 'Kind', 'Sold at', 'Branch', 'Till', 'Teller', 'Paid by', 'Total'], [7], $screen->sorted($p),
            function (Sale $s) use ($screen) {
                $r = $screen->rowOf($s);

                return [$r['receipt_no'], $r['kind'] === 'return' ? 'Return' : 'Sale', $this->at($r['occurred_at']), $r['branch'], $r['till'], $r['cashier'],
                    implode(' + ', $r['paid_by']), $this->money($r['total'], $s->currency_code)];
            }];
    }

    private function shifts(array $p): array
    {
        $screen = new ShiftsScreen($this->user);

        return ['Till shifts', ['Till', 'Shift', 'Opened by', 'Also sold', 'Opened', 'Closed', 'Sales', 'Sales total', 'Float', 'Expected', 'Counted', 'Over / short', 'Drawer', 'MoMo, card & vouchers', 'Note'],
            [6, 7, 8, 9, 10, 11], $screen->shiftsSorted($p),
            function (Shift $s) use ($screen) {
                $r = $screen->row($s);
                $closed = $r['status'] === 'open' ? ($r['left_open'] ? "Left open since {$r['left_open']}" : 'Open') : $this->at($r['closed_at']);

                return [$r['where'], $r['shift_name'], $r['opened_by'], $r['sellers'], $this->at($r['opened_at']), $closed, $r['sales'], $this->money($r['takings']),
                    $this->money($r['float']), $this->money($r['expected']), $this->money($r['counted']).($r['corrected'] ? ' (corrected)' : ''), $this->money($r['over_short']),
                    $r['drawer'], $r['checks'], $r['note']];
            }];
    }

    private function drawer(array $p): array
    {
        $screen = new ShiftsScreen($this->user);

        return ['Till drawer', ['When', 'Till', 'What', 'Amount', 'Spent on', 'Reason', 'Paid to', 'By', 'Approved by'], [3], $screen->drawerSorted($p),
            function (TillDrawerEvent $e) use ($screen) {
                $r = $screen->drawerRow($e);

                return [$this->at($r['occurred_at']), $r['where'], $r['kind_label'], $this->money($r['amount']), $r['spent_on'], $r['reason'], $r['payee'], $r['by'], $r['approved_by']];
            }];
    }

    private function products(array $p): array
    {
        $screen = new ProductsScreen($this->user);
        $default = PriceList::where('is_default', true)->first();
        $stock = $screen->stockShown();
        $schedules = ['otc' => 'OTC', 'pom' => 'POM', 'controlled' => 'Controlled'];

        return ['Products', ['SKU', 'Name', 'Category', 'Location', 'Medicine schedule', 'Industry', ($default?->name ?? 'Standard').' price', ...($stock ? ['On hand', 'Unit'] : []), 'For sale'],
            $stock ? [6, 7] : [6], $screen->sorted($p),
            function (Product $x) use ($screen, $default, $stock, $schedules) {
                $r = $screen->rowOf($x, $default, $stock);

                return [$r['sku'], $r['name'], $r['category'], $r['location'], $schedules[$r['schedule']] ?? '', $r['industry'] ?? 'Shared',
                    $this->money($r['price'], $default?->currency_code), ...($stock ? [$r['tracked'] ? $r['on_hand'] : 'not tracked', $r['unit']] : []), $r['active'] ? 'Yes' : 'No'];
            }];
    }

    private function stock(array $p): array
    {
        $screen = new StockScreen($this->user);

        return ['Stock on hand', ['SKU', 'Product', 'Warehouse', 'On hand', 'Unit', 'Last movement'], [3], $screen->levelsSorted($p),
            function (StockLevel $l) use ($screen) {
                $r = $screen->levelRow($l);

                return [$r['sku'], $r['name'], $r['warehouse'], $r['on_hand'], $r['unit'], $this->at($r['updated_at'])];
            }];
    }

    private function movements(array $p): array
    {
        $screen = new StockScreen($this->user);

        return ['Stock ledger', ['When', 'Warehouse', 'SKU', 'Product', 'What', 'Entered', 'Base qty', 'Unit', 'Reason', 'By'], [6], $screen->movementsSorted($p),
            function (StockMovement $m) use ($screen) {
                $r = $screen->movementRow($m);

                return [$this->at($r['at']), $r['warehouse'], $r['sku'], $r['product'], $r['type'], $r['entered'], $r['qty'], $r['unit'], $r['reason'], $r['by']];
            }];
    }

    private function adjustments(array $p): array
    {
        $screen = new AdjustmentsScreen($this->user);

        return ['Stock adjustments', ['Number', 'Date', 'Warehouse', 'Reason', 'Reference', 'Lines', 'Value', 'Status', 'Prepared by'], [5, 6], $screen->sorted($p),
            function (StockAdjustment $a) use ($screen) {
                $r = $screen->rowOf($a);

                return [$r['number'], $this->at($r['created_at']), $r['warehouse'], $r['reason'].($r['disposal'] ? ' (disposal)' : ''), $r['reference'], $r['lines'],
                    $this->money($r['value']), $r['status_label'], $r['by']];
            }];
    }

    private function counts(array $p): array
    {
        $screen = new CountsScreen($this->user);

        return ['Stock counts', ['Count', 'Warehouse', 'Bin', 'Type', 'Status', 'Started', 'Started by', 'Lines', 'Difference value'], [7, 8], $screen->sorted($p),
            function (StockCount $c) use ($screen) {
                $r = $screen->rowOf($c);

                return [$r['number'], $r['warehouse'], $r['bin'] ?? 'Whole warehouse', $r['blind'] ? 'Blind' : 'Expected shown', $r['status_label'],
                    $this->at($r['started_at']), $r['started_by'], $r['lines'], $this->money($r['difference'])];
            }];
    }

    private function transfers(array $p): array
    {
        $screen = new TransfersScreen($this->user);

        return ['Transfers', ['Transfer', 'From', 'To', 'Status', 'Difference on arrival', 'Lines', 'Dispatched', 'Created', 'Created by'], [5], $screen->sorted($p),
            function (StockTransfer $t) use ($screen) {
                $r = $screen->rowOf($t);

                return [$r['number'], $r['from'], $r['to'], $r['status_label'], $r['difference'] ? 'Yes' : '', $r['lines'], $this->at($r['dispatched_at']), $this->at($r['created_at']), $r['by']];
            }];
    }

    private function requests(array $p): array
    {
        $screen = new RequestsScreen($this->user);

        return ['Stock requests', ['Request', 'For', 'Asked of', 'Between industries', 'Status', 'Lines', 'Asked', 'Asked by'], [5], $screen->sorted($p),
            function (StockRequest $r) use ($screen) {
                $row = $screen->rowOf($r);

                return [$row['number'], $row['for'], $row['asked_of'], $row['cross'] ? 'Yes' : '', $row['status_label'], $row['lines'], $this->at($row['created_at']), $row['by']];
            }];
    }

    private function customers(array $p): array
    {
        $screen = new CustomersScreen($this->user);

        return ['Customers', ['Code', 'Name', 'Contact person', 'Phone', 'Type', 'Price list', 'Balance', 'Credit limit', 'Credit hold', 'Status'], [6, 7], $screen->sorted($p),
            function (Customer $c) use ($screen) {
                $r = $screen->rowOf($c);

                return [$r['code'], $r['name'], $r['contact'], $r['phone'], Customer::TYPES[$r['type']] ?? $r['type'], $r['price_list'] ?? 'default',
                    $this->money($r['balance']), $r['credit_limit'] === null ? 'cash' : $this->money($r['credit_limit']), $r['hold'] ? 'On hold' : '', $r['archived'] ? 'Archived' : 'In use'];
            }];
    }

    private function invoices(array $p): array
    {
        $screen = new InvoicesScreen($this->user);

        return ['Invoices', ['Invoice', 'Customer', 'Customer ref.', 'Issued', 'Due', 'Total', 'Still owed', 'Status'], [5, 6], $screen->sorted($p),
            function (Invoice $i) use ($screen) {
                $r = $screen->rowOf($i);

                return [$r['number'], $r['customer'], $r['ref'] ?? '', $r['issued_on'], $r['due_on'], $this->money($r['total'], $r['currency']),
                    $r['open'] ? $this->money($r['open'], $r['currency']) : 'paid', Invoice::STATUSES[$r['status']] ?? $r['status']];
            }];
    }

    private function suppliers(array $p): array
    {
        $screen = new SuppliersScreen($this->user);
        $owed = SuppliersScreen::seesOwed();

        return ['Suppliers', ['Code', 'Name', 'Contact person', 'Phone', 'Status', 'What is missing', 'Score', 'Terms', 'Products', ...($owed ? ['We owe'] : []), 'In use'],
            $owed ? [8, 9] : [8], $screen->sorted($p),
            function (Supplier $s) use ($screen, $owed) {
                $r = $screen->rowOf($s);

                return [$r['code'], $r['name'], $r['contact'] ?? '', $r['phone'] ?? '', Supplier::STATUSES[$r['status']] ?? $r['status'], $r['note'] ?? '',
                    $r['score'] ?? '', $r['terms'], $r['products'], ...($owed ? [$r['owed'] ? $this->money($r['owed']) : ''] : []), $r['archived'] ? 'Archived' : 'In use'];
            }];
    }

    private function purchaseOrders(array $p): array
    {
        $screen = new PurchaseOrdersScreen($this->user);

        return ['Purchase orders', ['Order', 'Supplier', 'To', 'Total', 'Expected', 'Status', 'Created'], [3], $screen->sorted($p),
            function (PurchaseOrder $po) use ($screen) {
                $r = $screen->rowOf($po);

                return [$r['number'], $r['supplier'] ?? '', $r['to'] ?? '', $this->money($r['total'], $r['currency']), $r['expected_on'] ?? '',
                    PurchaseOrder::STATUSES[$r['status']] ?? $r['status'], $this->at($r['created_at'])];
            }];
    }

    private function goodsReceipts(array $p): array
    {
        $screen = new GoodsReceiptsScreen($this->user);

        return ['Goods received', ['GRN', 'Order', 'Supplier', 'Delivery note', 'Warehouse', 'Value', 'Received', 'Received by', 'Status'], [5], $screen->sorted($p),
            function (GoodsReceipt $g) use ($screen) {
                $r = $screen->rowOf($g);

                return [$r['number'], $r['order'] ?? '', $r['supplier'] ?? '', $r['ref'] ?? '', $r['warehouse'] ?? '', $this->money($r['value'], $r['currency']),
                    $this->at($r['received_at']), $r['received_by'] ?? '', GoodsReceipt::STATUSES[$r['status']] ?? $r['status']];
            }];
    }

    private function directPurchases(array $p): array
    {
        $screen = new DirectPurchasesScreen($this->user);

        return ['Direct purchases', ['No.', 'Date', 'Supplier', 'Invoice', 'Payment', 'Total', 'Status', 'By'], [5], $screen->sorted($p),
            function (DirectPurchase $d) use ($screen) {
                $r = $screen->rowOf($d);

                return [$r['number'], $r['invoice_date'] ?? '', $r['supplier'] ?? '', $r['invoice_no'], DirectPurchase::SETTLEMENTS[$r['settlement']] ?? $r['settlement'],
                    $this->money($r['total'], $r['currency']), DirectPurchase::STATUSES[$r['status']] ?? $r['status'], $r['by'] ?? ''];
            }];
    }

    private function supplierInvoices(array $p): array
    {
        $screen = new SupplierInvoicesScreen($this->user);

        return ['Invoices from suppliers', ['Received', 'Supplier', 'Their invoice', 'Dated', 'Amount', 'For order', 'Sent by', 'Status', 'Bill or reason'], [4], $screen->sorted($p),
            function (SupplierInvoice $i) use ($screen) {
                $r = $screen->rowOf($i);

                return [$this->at($r['received_at']), $r['supplier'] ?? '', $r['invoice_no'], $r['invoice_date'] ?? '', $this->money($r['amount'], $r['currency']),
                    $r['order']['number'] ?? '', $r['sent_by'] ?? '', SupplierInvoice::STATUSES[$r['status']] ?? $r['status'], $r['note'] ?? ''];
            }];
    }

    private function reorderRules(array $p): array
    {
        $screen = new ReorderScreen($this->user);
        $query = $screen->rulesSorted($p);

        return ['Reorder levels', ['Product', 'SKU', 'Store', 'On hand', 'Min', 'Max', 'Set by'], [], $query,
            function (ReorderRule $r) use ($screen) {
                $row = $screen->ruleRow($r, $screen->rulePage([$r]));

                return [$row['product'] ?? '', $row['sku'] ?? '', $row['store'] ?? '', $row['on_hand'], $row['min'], $row['max'], $row['source_label']];
            }];
    }

    private function expenseClaims(array $p): array
    {
        $screen = new ExpenseClaimsScreen($this->user);

        return ['Expense claims', ['Number', 'Date', 'By', 'Branch', 'Description', 'What for', 'Amount', 'Status'], [6], $screen->sorted($p),
            function (ExpenseClaim $c) use ($screen) {
                $r = $screen->rowOf($c);

                return [$r['number'], $r['spent_on'] ?? '', $r['by'] ?? '', $r['branch'] ?? '', $r['description'], $r['for'] ?? '', $this->money($r['amount']),
                    ExpenseClaim::STATUSES[$r['status']] ?? $r['status']];
            }];
    }

    private function supplierBills(array $p): array
    {
        $screen = new SupplierBillsScreen($this->user);

        return ['Supplier bills', ['Number', 'Supplier', 'Their invoice', 'Date', 'Due', 'Total', 'vs order', 'Status'], [5, 6], $screen->sorted($p),
            function (SupplierBill $b) use ($screen) {
                $r = $screen->rowOf($b);

                return [$r['number'], $r['supplier'] ?? '', $r['invoice_no'], $r['bill_date'] ?? '', $r['due_on'] ?? '', $this->money($r['total']),
                    $r['variance'] ? $this->money($r['variance']) : '', SupplierBill::STATUSES[$r['status']] ?? $r['status']];
            }];
    }

    private function supplierPayments(array $p): array
    {
        $screen = new SupplierPaymentsScreen($this->user);

        return ['Supplier payments', ['Number', 'Date', 'Supplier', 'Method', 'Paid from', 'Reference', 'Settles', 'Withholding tax', 'Certificate', 'Paid out', 'Recorded by'],
            [6, 7, 9], $screen->sorted($p),
            function (SupplierPayment $s) use ($screen) {
                $r = $screen->rowOf($s);

                return [$r['number'], $r['paid_on'] ?? '', $r['supplier'] ?? '', SupplierPayment::METHODS[$r['method']] ?? $r['method'], $r['from'] ?? '', $r['reference'] ?? '',
                    $this->money($r['amount']), $r['wht'] ? $this->money($r['wht']) : '', $r['certificate_no'] ?? '', $this->money($r['paid']), $r['by'] ?? ''];
            }];
    }

    private function fixedAssets(array $p): array
    {
        $screen = new AssetsScreen($this->user);
        [$sort, $dir] = [in_array($p['sort'] ?? null, AssetsScreen::SORTS, true) ? $p['sort'] : 'number', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];

        return ['Fixed assets', ['Number', 'Asset', 'Serial', 'Kind', 'At', 'Bought', 'Cost', 'Book value', 'Status'], [6, 7],
            $screen->filtered($p)->orderBy("fixed_assets.{$sort}", $dir)->orderBy('fixed_assets.id', $dir),
            function (FixedAsset $a) use ($screen) {
                $r = $screen->rowOf($a);

                return [$r['number'], $r['name'], $r['serial_no'] ?? '', $r['kind'] ?? '', $r['branch'] ?? '', $r['acquired_on'] ?? '', $this->money($r['cost']), $this->money($r['book']),
                    $r['status'] === 'active' ? 'Active' : 'Disposed'];
            }];
    }

    /** The price list as the screen shows it; cost and margin only for people who may set prices. */
    private function priceList(array $p): array
    {
        $screen = new PriceListScreen($this->user);
        $cost = PriceListScreen::mayCost();

        return ['Price list: '.($screen->list($p)?->name ?? '—'), array_merge(['SKU', 'Product', 'Category', 'Brand', 'Unit', 'Before tax', 'VAT and levies', 'Customer pays', 'In stock'],
            $cost ? ['Cost', 'Margin'] : []), $cost ? [5, 6, 7, 8, 9] : [5, 6, 7, 8], $screen->query($p),
            function (ProductUom $u) use ($screen, $p, $cost) {
                $r = $screen->rowOf($u, $p);

                return array_merge([$r['sku'], $r['product'], $r['category'] ?? '', $r['brand'] ?? '', $r['unit'], $this->money($r['net']), $this->money($r['tax']), $this->money($r['gross']),
                    $r['on_hand'] ?? ''], $cost ? [$this->money($r['cost']), $r['margin'] ?? ''] : []);
            }];
    }

    private function journals(array $p): array
    {
        $screen = new JournalsScreen($this->user);

        return ['Journals', ['Number', 'Date', 'Kind', 'Description', 'Amount', 'Posted by'], [4], $screen->sorted($p),
            function (JournalEntry $j) use ($screen) {
                $r = $screen->rowOf($j);

                return [$r['number'], $r['date'] ?? '', $r['kind_label'], $r['memo'] ?? '', $this->money($r['amount']), $r['by'] ?? ''];
            }];
    }

    private function returns(array $p): array
    {
        $screen = new ReturnsScreen($this->user);

        return ['Returns', ['Return', 'When', 'Branch', 'By', 'Approved by', 'Reason', 'Details', 'Items', 'Went to', 'Refund', 'Refunded as'], [9], $screen->sorted($p),
            function (Sale $s) use ($screen) {
                $r = $screen->rowOf($s);

                return [$r['receipt_no'], $this->at($r['occurred_at']), $r['branch'] ?? '', $r['by'] ?? '', $r['approved_by'] ?? 'Not approved', $r['reason'] ?? '',
                    $r['details'] ?? '', $r['items'], $r['to'], $this->money($r['refund'], $r['currency']), $r['paid_by']];
            }];
    }

    /** "Search: rice", "Period: 7 days", "From: 2026-11-01" … for the PDF header and the audit row. */
    private function describe(array $p): array
    {
        $names = ['q' => 'Search', 'period' => 'Period', 'from' => 'From', 'to' => 'Until', 'status' => 'Status', 'kind' => 'Kind', 'level' => 'Stock',
            'stock' => 'Stock', 'reason' => 'Reason', 'warehouse' => 'Warehouse', 'difference' => 'Difference on arrival', 'from_wh' => 'From warehouse', 'to_wh' => 'To warehouse', 'active' => 'For sale', 'schedule' => 'Medicine schedule', 'min' => 'Total from', 'max' => 'Total up to',
            'os_min' => 'Over/short from', 'os_max' => 'Over/short up to', 'vault_waiting' => 'Vaults waiting', 'waiting' => 'Waiting on you', 'cross' => 'Between industries', 'owing' => 'Owes money', 'credit' => 'Credit customers', 'show' => 'Show', 'overdue' => 'Past due date', 'owed' => 'Still owed', 'unverified' => 'Bank details not checked', 'supplier' => 'Supplier', 'settlement' => 'Payment', 'set_by' => 'Set by', 'mine' => 'My claims', 'method' => 'Method', 'list' => null, 'only' => null, 'category' => 'Category', 'brand' => 'Brand', 'product' => 'Product', 'in_stock' => 'In stock only', 'priced' => 'With a price only', 'sort' => null, 'dir' => null];
        $periods = ['today' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days'];
        $out = [];
        foreach ($p as $k => $v) {
            if (($names[$k] ?? false) === null || $v === '' || $v === [] || $v === null) {
                continue;
            }
            $value = is_array($v) ? count($v).' chosen' : ($k === 'period' ? ($periods[$v] ?? $v) : ($k === 'active' ? ($v === '1' ? 'Yes' : 'No') : (string) $v));
            if (preg_match('/^[0-9a-f-]{36}$/', $value)) {
                $value = 'chosen';
            }
            $out[] = ($names[$k] ?? ucfirst(str_replace('_', ' ', $k))).': '.$value;
        }

        return $out;
    }
}

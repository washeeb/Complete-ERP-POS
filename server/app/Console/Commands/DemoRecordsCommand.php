<?php

namespace App\Console\Commands;

use App\Domain\Access\AccessService;
use App\Domain\Assets\AssetService;
use App\Domain\Banking\BankingService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockRequestService;
use App\Domain\Messaging\Messenger;
use App\Domain\Payables\BillService;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Pharmacy\HealthReadings;
use App\Domain\Pharmacy\NhisClaims;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pharmacy\RefillReminders;
use App\Domain\Pos\CashControl;
use App\Domain\Pos\DayCloseService;
use App\Domain\Pos\DrawerService;
use App\Domain\Pos\OfficeReturns;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Purchasing\DirectPurchases;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Reports\ReportService;
use App\Domain\Support\Actor;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\Device;
use App\Models\DirectPurchase;
use App\Models\DrugInteraction;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\HealthReading;
use App\Models\Industry;
use App\Models\Markdown;
use App\Models\Message;
use App\Models\MoneyTransfer;
use App\Models\NhisClaim;
use App\Models\OfficeReturn;
use App\Models\Prescription;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SavedReport;
use App\Models\StockAdjustment;
use App\Models\StockRequest;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\TaxGroup;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\TillDrawerEvent;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultSession;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A sample of each kind of record a demo or training company has none of yet (owner request 2026-11-21), so every
 * screen shows rows and opens one: a bank account with a statement, money moved, an expense claim, a supplier bill and
 * payment, a fixed asset, a direct purchase, a stock adjustment and request, a return, a saved report, markdowns, a
 * return to a supplier, the drug-interaction starter, pharmacy sales with prescriptions, a controlled medicine, NHIS and a
 * refill reminder, the health-check services with a reading each, a float issued and a day closed. Each goes through
 * the same service as the screens (rules, audit and outbox as usual); a kind that already has a record is left alone,
 * so it can be run again. Never on a live system.
 */
class DemoRecordsCommand extends Command
{
    protected $signature = 'erp:demo-records {--as= : E-mail of the owner loading the sample data}';

    protected $description = 'Add a sample of each kind of record the company has none of yet (demos and training only, never on a live system)';

    /** The health-check services and their fees (sample prices). */
    private const HEALTH = [['HB test', 'hb', '20.00'], ['HIV test', 'hiv', '30.00'], ['Typhoid test', 'typhoid', '40.00'], ['Wound dressing', 'wound', '25.00']];

    private Actor $actor;

    private User $owner;

    private int $seq = 0;

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This adds made-up records: never on a live system.');

            return self::FAILURE;
        }
        $owner = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $owner) {
            $this->error('Pass --as=<email> of the company owner.');

            return self::FAILURE;
        }
        $this->owner = $owner;
        app(TenantContext::class)->set($owner->tenant_id);
        auth()->login($owner);
        $this->actor = Actor::user($owner, 'cli');
        $hq = Branch::where('is_hq', true)->firstOrFail();
        $hqWh = Warehouse::where('branch_id', $hq->id)->orderBy('code')->firstOrFail();

        $this->step('Bank account and statement', fn () => $this->bank($hq));
        $this->step('Expense claim', fn () => $this->expense($hq));
        $this->step('Supplier bill and payment', fn () => $this->bill($hq));
        $this->step('Fixed asset', fn () => $this->asset($hq));
        $this->step('Direct purchase', fn () => $this->directPurchase($hqWh));
        $this->step('Stock adjustment', fn () => $this->adjustment($hqWh));
        $this->step('Stock request', fn () => $this->stockRequest($hqWh));
        $this->step('Return of a sale', fn () => $this->officeReturn());
        $this->step('Saved report', fn () => $this->report());
        $this->step('Markdown', fn () => $this->markdown());
        $this->step('Return to a supplier', fn () => $this->supplierReturn());
        $this->step('Drug interactions', fn () => DrugInteraction::exists() ? 'already there' : app(PharmacyService::class)->addStarter($this->actor).' added');
        $this->step('Health-check services and readings', fn () => $this->health());
        $this->step('Pharmacy sales: prescriptions, controlled medicine, NHIS, refill reminder', fn () => $this->pharmacy());
        $this->step('Till drawer, a closed shift and a handover', fn () => $this->handover());
        $this->step('Float issued at the cash desk', fn () => $this->float($hq));
        $this->step('Invoice sent by a supplier', fn () => $this->supplierInvoice());
        $this->step('Message log', fn () => $this->message());
        $this->step('Day closed', fn () => $this->dayClose($hq));

        return self::SUCCESS;
    }

    /** Run one part; a failure is reported and the rest still run. */
    private function step(string $label, callable $fn): void
    {
        try {
            $this->line(str_pad($label, 72, '.').' '.DB::transaction(fn () => (string) $fn()));
        } catch (\Throwable $e) {
            $this->warn(str_pad($label, 72, '.').' not added: '.$e->getMessage());
        }
    }

    private function account(string $key): Account
    {
        return Account::where('system_key', $key)->firstOrFail();
    }

    private function bank(Branch $hq): string
    {
        if (BankAccount::where('kind', 'bank')->exists()) {
            return 'already there';
        }
        $banking = app(BankingService::class);
        $bank = $banking->saveAccount($this->actor, new BankAccount, ['kind' => 'bank', 'name' => 'Demo Bank current account', 'provider' => 'Demo Bank', 'number' => '0000000001',
            'account_id' => $this->account('bank')->id]);
        $day = now()->subDays(2);
        if (! MoneyTransfer::exists()) {
            $banking->transfer($this->actor, $this->account('cash_till'), $this->account('bank'), $hq, $hq, 150000, $day->toDateString(), 'DEMO-SLIP-1', 'Cash taken to the bank');
        }
        $d = $day->format('d/m/Y');
        $csv = "Txn Date,Narration,Ref,Debit,Credit,Balance\n{$d},CASH DEPOSIT,DEMO-SLIP-1,,\"1,500.00\",\"1,500.00\"\n{$d},SMS ALERT CHARGES,,12.50,,\"1,487.50\"\n";
        $banking->import($this->actor, $bank, 'demo-statement.csv', $csv, ['header_rows' => 1, 'date' => 'Txn Date', 'date_format' => 'auto', 'description' => 'Narration',
            'reference' => 'Ref', 'money_in' => 'Credit', 'money_out' => 'Debit', 'balance' => 'Balance']);

        return 'added (with money moved and a statement to reconcile)';
    }

    private function expense(Branch $hq): string
    {
        if (ExpenseClaim::exists()) {
            return 'already there';
        }
        $acc = Account::where('type', 'expense')->where('code', '6500')->first() ?? Account::where('type', 'expense')->orderBy('code')->firstOrFail();
        app(ExpenseService::class)->submit($this->actor, $hq, ['spent_on' => now()->toDateString(), 'account_id' => $acc->id, 'description' => 'Fuel for the delivery van (sample)',
            'amount_minor' => 25000]);

        return 'added (waiting for someone else to approve it)';
    }

    private function bill(Branch $hq): string
    {
        $supplier = Supplier::where('is_active', true)->orderBy('code')->firstOrFail();
        $out = [];
        if (! SupplierBill::exists()) {
            $rent = Account::where('code', '6200')->first() ?? Account::where('type', 'expense')->orderBy('code')->firstOrFail();
            app(BillService::class)->create($this->actor, $supplier, $hq, ['supplier_invoice_no' => 'DEMO-INV-1', 'bill_date' => now()->subDays(20)->toDateString(),
                'due_on' => now()->addDays(10)->toDateString(), 'lines' => [['account_id' => $rent->id, 'description' => 'Delivery charges (sample)', 'amount_minor' => 120000]]]);
            $out[] = 'bill';
        }
        if (! SupplierPayment::exists()) {
            app(SupplierPaymentService::class)->pay($this->actor, $supplier, $hq, $this->account('bank'), 'bank', 50000, 'DEMO-PAY-1', now()->toDateString(), null, 'Part payment (sample)');
            $out[] = 'payment';
        }

        return $out ? implode(' and ', $out).' added' : 'already there';
    }

    private function asset(Branch $hq): string
    {
        if (FixedAsset::exists()) {
            return 'already there';
        }
        $class = AssetClass::where('code', 'COMP')->first() ?? AssetClass::firstOrFail();
        app(AssetService::class)->register($this->actor, ['name' => 'Office laptop (sample)', 'asset_class_id' => $class->id, 'branch_id' => $hq->id,
            'acquired_on' => now()->subMonths(3)->startOfMonth()->toDateString(), 'cost_minor' => 800000, 'residual_minor' => 0]);

        return 'added';
    }

    /** A product with stock in the store, for the stock samples. */
    private function stocked(Warehouse $wh): Product
    {
        $id = DB::table('stock_levels')->where('warehouse_id', $wh->id)->where('on_hand', '>', 10)->orderBy('product_id')->value('product_id')
            ?? throw new \RuntimeException("{$wh->name} has nothing in stock.");

        return Product::with('units')->findOrFail($id);
    }

    private function directPurchase(Warehouse $wh): string
    {
        if (DirectPurchase::exists()) {
            return 'already there';
        }
        $p = $this->stocked($wh);
        $doc = app(DirectPurchases::class)->save($this->actor, new DirectPurchase, ['supplier_id' => Supplier::where('is_active', true)->orderBy('code')->value('id'),
            'warehouse_id' => $wh->id, 'invoice_no' => 'DEMO-DP-1', 'invoice_date' => now()->toDateString(), 'settlement' => 'credit',
            'lines' => [['product_uom_id' => $p->units->firstWhere('is_base', true)->id, 'qty' => '6', 'unit_price' => '1.50', 'tax_group_id' => null]]]);
        app(DirectPurchases::class)->submit($this->actor, $doc);

        return 'added (waiting for approval)';
    }

    private function adjustment(Warehouse $wh): string
    {
        if (StockAdjustment::exists()) {
            return 'already there';
        }
        $svc = app(StockAdjustmentService::class);
        $p = $this->stocked($wh);
        $a = $svc->create($this->actor, $wh, ['reason_code' => 'damaged', 'note' => 'Dropped carton (sample)']);
        $svc->addLine($this->actor, $a, ['product_id' => $p->id, 'product_uom_id' => $p->units->firstWhere('is_base', true)->id, 'mode' => 'remove', 'qty' => '1']);

        return 'added (draft)';
    }

    private function stockRequest(Warehouse $hqWh): string
    {
        if (StockRequest::exists()) {
            return 'already there';
        }
        $to = Warehouse::where('id', '<>', $hqWh->id)->where('is_active', true)->orderBy('code')->firstOrFail();
        $p = $this->stocked($hqWh);
        app(StockRequestService::class)->request($this->actor, ['from_warehouse_id' => $hqWh->id, 'to_warehouse_id' => $to->id,
            'lines' => [['product_uom_id' => $p->units->firstWhere('is_base', true)->id, 'qty' => '4']]]);

        return 'added';
    }

    private function officeReturn(): string
    {
        if (OfficeReturn::exists()) {
            return 'already there';
        }
        $sale = Sale::with('lines')->where('status', 'posted')->where('kind', 'sale')->whereHas('lines')->latest('occurred_at')->firstOrFail();
        app(OfficeReturns::class)->submit($this->actor, $sale, ['lines' => [['line_id' => $sale->lines[0]->id, 'qty' => '1']], 'reason_code' => 'wrong_item',
            'refund_method' => 'money', 'account_id' => $this->account('bank')->id, 'reference' => 'DEMO-REFUND-1']);

        return 'added (waiting for approval)';
    }

    private function report(): string
    {
        if (SavedReport::exists()) {
            return 'already there';
        }
        app(ReportService::class)->save($this->actor, new SavedReport, ['name' => 'Sales by product, last 7 days (sample)', 'dataset' => 'sales_lines',
            'definition' => ['dimensions' => ['product'], 'measures' => ['qty', 'net'], 'period' => 'last_7']]);

        return 'added';
    }

    /** A batch on hand that has expired, or expires within 60 days, as Expiry & returns lists it (key, warehouse_id, batch_id). */
    private function batch(bool $expired): ?array
    {
        $rows = app(ExpiryService::class)->batches($this->owner->tenant_id, ['warehouse_ids' => Warehouse::pluck('id')->all(), 'expired' => $expired,
            'within_days' => $expired ? null : 60]);

        return collect($rows)->first(fn ($r) => $expired ? $r['days_left'] < 0 : $r['days_left'] >= 1);
    }

    private function markdown(): string
    {
        if (Markdown::exists()) {
            return 'already there';
        }
        $b = $this->batch(false) ?? throw new \RuntimeException('No batch expires in the next 60 days.');
        app(MarkdownService::class)->apply($this->actor, Warehouse::findOrFail($b['warehouse_id']), $b['batch_id'], 3000);

        return 'added (30% off a batch that expires soon)';
    }

    private function supplierReturn(): string
    {
        if (SupplierReturn::exists()) {
            return 'already there';
        }
        $b = $this->batch(true) ?? $this->batch(false) ?? throw new \RuntimeException('No batch on hand has expired or expires soon.');
        app(ExpiryService::class)->prepareReturn($this->actor, [$b['key']], Supplier::where('is_active', true)->orderBy('code')->value('id'), 'none', $b['days_left'] < 0 ? 'expired' : 'near_expiry',
            'Expired stock going back (sample)');

        return 'added (waiting for approval)';
    }

    private function pharmacyBranch(): Branch
    {
        $id = DB::table('branch_modules')->where('module_key', 'pharmacy')->value('branch_id') ?? throw new \RuntimeException('No branch has pharmacy on.');

        return Branch::findOrFail($id);
    }

    private function patient(): Customer
    {
        $c = Customer::where('name', 'DEMO PATIENT')->first()
            ?? app(CustomerService::class)->save($this->actor, new Customer, ['name' => 'Demo Patient', 'phone' => '0200000001']);
        app(PharmacyService::class)->savePatient($this->actor, $c, ['nhis_no' => 'DEMO-NHIS-001', 'nhis_expires_on' => now()->addYear()->toDateString()]);

        return $c->fresh();
    }

    private function health(): string
    {
        $catalog = app(CatalogService::class);
        $list = PriceList::where('is_default', true)->firstOrFail();
        $uom = Uom::where('code', 'SVC')->first() ?? Uom::where('code', 'PC')->firstOrFail();
        $tax = TaxGroup::where('code', 'EXEMPT')->value('id') ?? TaxGroup::orderBy('name')->value('id');
        $industry = Industry::where('module_key', 'pharmacy')->value('id');
        $added = [];
        foreach (self::HEALTH as [$name, $kind, $fee]) {
            if (! Product::where('reading_kind', $kind)->exists()) {
                $catalog->saveProduct($this->actor, new Product, ['name' => $name, 'industry_id' => $industry, 'base_uom_id' => $uom->id, 'tax_group_id' => $tax, 'track_stock' => false,
                    'is_active' => true, 'reading_kind' => $kind, 'prices' => [['price_list_id' => $list->id, 'uom_id' => $uom->id, 'min_qty' => '1', 'amount' => $fee]]], 'Sample data');
                $added[] = $name;
            }
        }
        if (! HealthReading::exists()) {
            $branch = $this->pharmacyBranch()->id;
            $who = $this->patient()->id;
            $svc = app(HealthReadings::class);
            foreach ([['kind' => 'bp', 'systolic' => 132, 'diastolic' => 84, 'pulse' => 76], ['kind' => 'hb', 'value' => 11.4], ['kind' => 'typhoid', 'result' => 'negative'],
                ['kind' => 'hiv', 'result' => 'non_reactive'], ['kind' => 'wound', 'note' => 'Right hand: cleaned and dressed (sample)']] as $r) {
                $svc->record($this->actor, $r + ['branch_id' => $branch, 'customer_id' => $who]);
            }
            $added[] = 'a reading of each';
        }

        return $added ? implode(', ', $added).' added' : 'already there';
    }

    /** A pharmacy medicine with stock in the pharmacy branch's store. */
    private function medicine(Warehouse $wh, string $sku, string $name, string $price, array $fields): Product
    {
        $p = Product::with('units')->where('sku', $sku)->first();
        if (! $p) {
            $list = PriceList::where('is_default', true)->firstOrFail();
            $pc = Uom::where('code', 'PC')->firstOrFail();
            $p = app(CatalogService::class)->saveProduct($this->actor, new Product, $fields + ['sku' => $sku, 'name' => $name, 'industry_id' => Industry::where('module_key', 'pharmacy')->value('id'),
                'base_uom_id' => $pc->id, 'tax_group_id' => TaxGroup::where('code', 'EXEMPT')->value('id') ?? TaxGroup::orderBy('name')->value('id'), 'track_stock' => true,
                'track_batches' => true, 'is_active' => true, 'prices' => [['price_list_id' => $list->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $price]]], 'Sample data');
            $p = $p->fresh('units');
        }
        if ((float) DB::table('stock_levels')->where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand') < 100) {
            app(InventoryService::class)->adjust($this->actor, $wh, $p, $p->units->firstWhere('is_base', true), '200', 'opening', '', null, '1.00', "{$sku}-DEMO",
                now()->addYear()->toDateString());
        }

        return $p->fresh(['units', 'taxGroup.rates']);
    }

    private function pharmacy(): string
    {
        if (Prescription::exists() && NhisClaim::exists()) {
            return 'already there';
        }
        $branch = $this->pharmacyBranch();
        $till = Till::where('branch_id', $branch->id)->where('is_active', true)->orderBy('code')->firstOrFail();
        $wh = Warehouse::findOrFail($till->warehouse_id);
        $settings = app(Settings::class);
        $settings->set($this->actor, 'refill.reminders', true);
        $settings->set($this->actor, 'pharmacy.nhis_at_till', true);

        // The till: an enrolled device, and a pharmacist who sells there (the owner issues the float: nobody issues their own).
        $device = Device::where('till_id', $till->id)->where('kind', 'pos')->where('status', 'active')->first();
        if (! $device) {
            [, $code] = app(DeviceService::class)->register($this->actor, ['branch_id' => $branch->id, 'till_id' => $till->id, 'name' => 'Pharmacy till (sample)', 'kind' => 'pos']);
            $device = Device::findOrFail(app(DeviceService::class)->enroll($code, Str::random(64))['device_id']);
        }
        $pharmacist = User::where('username', 'demo.pharmacist')->first();
        if (! $pharmacist) {
            // A sample person who never signs in: the password is random and kept nowhere.
            $access = app(AccessService::class);
            $pharmacist = $access->createUser($this->actor, ['name' => 'Akosua Pharmacist (sample)', 'username' => 'demo.pharmacist', 'email' => 'demo.pharmacist@example.invalid',
                'password' => Str::random(48), 'security_level' => 4]);
            $access->grant($this->actor, $pharmacist, Role::where('key', 'pharmacist')->firstOrFail(), ScopeType::Branch, $branch->id);
            $pharmacist = $pharmacist->fresh();
        }
        $shifts = app(ShiftService::class);
        $shift = $shifts->current($device);
        if (! $shift) {
            $session = null;
            if (app(CashControl::class)->enabled($this->owner->tenant_id)) {
                $vault = Vault::where('till_id', $till->id)->whereNotNull('shift_template_id')->where('is_active', true)->orderBy('name')->firstOrFail();
                $session = VaultSession::where('vault_id', $vault->id)->where('teller_id', $pharmacist->id)->where('status', 'issued')->first()
                    ?? app(CashControl::class)->issueFloat($this->actor, $vault, $pharmacist, 20000, 'Sample float');
            }
            $shift = $shifts->open($device, $pharmacist, 0, vaultSessionId: $session?->id);
        }

        $amox = $this->medicine($wh, 'DEMO-AMX', 'Amoxicillin 500mg caps (sample)', '2.00', ['drug_schedule' => 'pom', 'generic_name' => 'amoxicillin',
            'active_ingredients' => 'amoxicillin', 'daily_use' => '3']);
        $tram = $this->medicine($wh, 'DEMO-TRM', 'Tramadol 50mg caps (sample)', '3.00', ['drug_schedule' => 'controlled', 'generic_name' => 'tramadol', 'active_ingredients' => 'tramadol']);
        $para = $this->medicine($wh, 'DEMO-PCM', 'Paracetamol 500mg tabs (sample)', '0.50', ['drug_schedule' => 'otc', 'generic_name' => 'paracetamol',
            'active_ingredients' => 'paracetamol', 'nhis_price_minor' => 40]);
        $patient = $this->patient();
        $rx = ['prescriber_name' => 'Dr. Sample Prescriber', 'prescriber_reg_no' => 'MDC/0000', 'facility' => 'Sample Clinic', 'issued_on' => now()->toDateString()];
        $this->seq = (int) DB::table('sales')->where('device_id', $device->id)->max('device_seq');
        $this->sell($device, $pharmacist, $shift->id, [[$amox, '21']], ['customer_id' => $patient->id, 'prescription' => $rx]);
        $this->sell($device, $pharmacist, $shift->id, [[$tram, '10']], ['customer_id' => $patient->id, 'prescription' => $rx]);
        $this->sell($device, $pharmacist, $shift->id, [[$para, '20']], ['customer_id' => $patient->id], nhis: 800);
        app(RefillReminders::class)->collect(Tenant::findOrFail($this->owner->tenant_id));
        if (! NhisClaim::exists()) {
            app(NhisClaims::class)->prepare($this->actor, $branch, now()->toDateString());
        }

        return 'added (3 sales at '.$branch->code.', a prescription, a controlled medicine, an NHIS claim, a refill reminder)';
    }

    /** One till sale, built as the till builds it, through SaleService. */
    private function sell(Device $device, User $seller, string $shiftId, array $items, array $extra, int $nhis = 0): void
    {
        $list = PriceList::where('is_default', true)->firstOrFail();
        $lines = $engine = [];
        foreach ($items as $i => [$p, $qty]) {
            $unit = $p->units->firstWhere('is_base', true);
            $price = app(PriceResolver::class)->resolve($list, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
            $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
                'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
            $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
        }
        $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => (bool) $list->prices_include_tax, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
        foreach ($calc['lines'] as $i => $r) {
            $lines[$i]['line_total_minor'] = $r['total'];
        }
        $total = $calc['total'];
        $payments = array_values(array_filter([$nhis ? ['method' => 'nhis', 'amount_minor' => $nhis, 'tendered_minor' => $nhis] : null,
            $total > $nhis ? ['method' => 'cash', 'amount_minor' => $total - $nhis, 'tendered_minor' => $total - $nhis] : null]));
        app(SaleService::class)->post($device, $seller, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(),
            'price_list_id' => $list->id, 'shift_id' => $shiftId, 'lines' => $lines, 'payments' => $payments,
            'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $total]] + $extra);
    }

    /**
     * At the pharmacy till: a paid-out from the drawer, then the pharmacist closes the shift with a count a little short;
     * the owner receives the tray (a difference to decide) and hands it over, so Income control has one to receive.
     */
    private function handover(): string
    {
        if (CashHandover::exists() || TillDrawerEvent::exists()) {
            return 'already there';
        }
        $till = Till::where('branch_id', $this->pharmacyBranch()->id)->where('is_active', true)->orderBy('code')->firstOrFail();
        $device = Device::where('till_id', $till->id)->where('kind', 'pos')->where('status', 'active')->firstOrFail();
        $shift = app(ShiftService::class)->current($device) ?? throw new \RuntimeException('No shift is open on the pharmacy till.');
        $seller = User::findOrFail($shift->opened_by);
        app(DrawerService::class)->record($device, $seller, ['id' => (string) Str::uuid7(), 'kind' => 'payout', 'amount_minor' => 1500, 'reason' => 'Cleaning materials (sample)', 'account_id' => array_key_first(DrawerService::payoutAccounts())]);
        $count = max(0, app(ShiftService::class)->expectedCash($shift->fresh()) - 500);
        app(ShiftService::class)->close($device, $seller, $count, 'Sample close');
        $session = VaultSession::find($shift->fresh()->vault_session_id);
        if (! $session) {
            return 'drawer paid-out and closed shift added (no cash control, so no handover)';
        }
        app(CashControl::class)->receiveVault($this->actor, $session->fresh(), null, $count);
        $h = app(CashControl::class)->handOver($this->actor, Branch::findOrFail($session->branch_id), [$session->id], 'Sample handover');

        return "drawer paid-out, closed shift (5.00 short), handover {$h->number} waiting at Income control";
    }

    private function supplierInvoice(): string
    {
        if (SupplierInvoice::exists()) {
            return 'already there';
        }
        app(SupplierPortal::class)->submitInvoice(Supplier::where('is_active', true)->orderBy('code')->firstOrFail(), ['invoice_no' => 'DEMO-SI-1', 'amount' => '350.00',
            'invoice_date' => now()->toDateString()], null, null, 'Supplier staff (sample)');

        return 'added (waiting to be entered as a bill)';
    }

    /** A message to a customer; with no provider set up it is logged as not sent, as a real one would be. */
    private function message(): string
    {
        if (Message::exists()) {
            return 'already there';
        }
        $m = app(Messenger::class)->send($this->owner->tenant_id, 'sms', '0200000001', 'Your prescription is ready to collect (sample).', null, 'DEMO', $this->owner->name);

        return "added ({$m->status})";
    }

    private function float(Branch $hq): string
    {
        if (! app(CashControl::class)->enabled($this->owner->tenant_id)) {
            return 'skipped: cash control is off';
        }
        if (VaultSession::where('branch_id', $hq->id)->exists()) {
            return 'already there';
        }
        $till = Till::where('branch_id', $hq->id)->orderBy('code')->firstOrFail();
        $vault = Vault::where('till_id', $till->id)->whereNotNull('shift_template_id')->where('is_active', true)->orderBy('name')->firstOrFail();
        $teller = User::where('is_active', true)->where('id', '<>', $this->owner->id)->get()
            ->first(fn (User $u) => app(Authorizer::class)->allows($u, 'pos.access', $till)) ?? throw new \RuntimeException('Nobody but the owner sells at '.$till->code.'.');
        app(CashControl::class)->issueFloat($this->actor, $vault, $teller, 20000, 'Sample float');

        return "issued to {$teller->name} on {$vault->name}";
    }

    private function dayClose(Branch $hq): string
    {
        if (DayClose::exists()) {
            return 'already there';
        }
        app(DayCloseService::class)->close($this->actor, $hq, now()->subDay()->toDateString(), ['safe' => '200.00',
            'note' => 'Sample close: the checks that are not right yet were looked at.']);

        return 'yesterday closed at '.$hq->code;
    }
}

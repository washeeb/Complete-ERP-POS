<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Branding\Branding;
use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceSignature;
use App\Domain\Fashion\AlterationService;
use App\Domain\Fiscal\FiscalService;
use App\Domain\Grocery\MarkdownService;
use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\Watermark;
use App\Domain\Messaging\ReceiptSender;
use App\Domain\Messaging\Templates;
use App\Domain\Modules\ModuleService;
use App\Domain\Pos\CashControl;
use App\Domain\Pos\PosSyncService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Restaurant\DiningSales;
use App\Domain\Sales\ReceivablesService;
use App\Domain\Services\AppointmentService;
use App\Domain\Services\LicenceKeyService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\DrugInteraction;
use App\Models\FiscalSubmission;
use App\Models\LegalEntity;
use App\Models\ModifierGroup;
use App\Models\PatientProfile;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Style;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\VaultSession;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Endpoints a signed-in till uses. Device + staff session are checked by the device.token:pos middleware. */
class PosController extends Controller
{
    /** Everything the till needs to start: who, where, which lists, receipt header/footer, open shift. */
    public function bootstrap(Request $request, ShiftService $shifts, Settings $settings): JsonResponse
    {
        $device = $this->device($request);
        $till = $device->till_id ? Till::with('warehouse')->find($device->till_id) : null;
        $branch = Branch::with('legalEntity')->find($device->branch_id);
        $tenant = Tenant::find($device->tenant_id);
        $entity = $branch->legalEntity ?? LegalEntity::first();

        return response()->json([
            'company' => ['name' => $tenant->name, 'currency' => $tenant->currency_code, 'exponent' => Money::exponent($tenant->currency_code), 'practice' => (bool) $tenant->is_practice,
                'timezone' => $branch->timezone ?? $tenant->timezone, 'legal_name' => $entity?->name, 'tin' => $entity?->tin, 'vat_no' => $entity?->vat_no],
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address, 'phone' => $branch->phone],
            'till' => $till ? ['id' => $till->id, 'code' => $till->code, 'name' => $till->name, 'warehouse' => $till->warehouse->name] : null,
            'device' => ['id' => $device->id, 'name' => $device->name, 'receipt_series' => $device->receipt_series],
            'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'price_lists' => $this->lists($branch)->map(fn (PriceList $l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'prices_include_tax' => $l->prices_include_tax, 'is_default' => $l->is_default,
            ])->values(),
            'receipt_footer' => $settings->get('receipt.footer', $device->tenant_id),
            // The company's brand (Company → Branding): logo for the screen and the printed receipt, colour, and the product line.
            'brand' => (fn ($b) => ['color' => $b['color'], 'on_color' => $b['on_color'], 'logo_url' => $b['logo_url'], 'logo_dark_url' => $b['logo_dark_url'],
                'icon_url' => $b['icon_url'], 'receipt_logo' => $b['receipt_logo'], 'powered_by' => $b['powered_by'], 'product' => config('brand.name')])(Branding::forTenant($tenant)),
            'send_receipt' => array_values(array_filter(['sms', 'whatsapp', 'email'], fn ($ch) => (bool) Templates::find($device->tenant_id, 'receipt', $ch)?->is_active)),
            'license' => (fn ($s) => ['state' => $s['state'], 'read_only' => $s['read_only'], 'message' => $s['message'], 'ref' => Watermark::reference()])(app(LicenseManager::class)->status()),
            'shift' => $this->shiftJson($shifts->current($device), $shifts),
            // Cash control: the vaults the cashier issued to this teller at this till, and the notes and coins to count.
            'cash' => app(CashControl::class)->enabled($device->tenant_id) ? [
                'vaults' => true,
                'sessions' => $till ? app(CashControl::class)->sessionsFor($request->user(), $till)->map(fn (VaultSession $s) => [
                    'id' => $s->id, 'vault' => $s->vault->name, 'float_minor' => $s->float_minor, 'status' => $s->status,
                    'issued_by' => $s->issued_by_name, 'business_date' => $s->business_date->toDateString(),
                ])->values() : [],
                'denominations' => collect(CashControl::denominations($tenant->currency_code))->map(fn ($d, $k) => ['key' => $k, 'label' => $d[0], 'value' => $d[1]])->values(),
            ] : ['vaults' => false, 'sessions' => [], 'denominations' => collect(CashControl::denominations($tenant->currency_code))->map(fn ($d, $k) => ['key' => $k, 'label' => $d[0], 'value' => $d[1]])->values()],
            'last_device_seq' => (int) Sale::where('device_id', $device->id)->max('device_seq'),
            'catalog_version' => $this->catalogVersion(),
            // Live updates (Reverb). null = not configured on this server; the till then polls.
            'realtime' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.client.host'),
                'port' => (int) config('broadcasting.connections.reverb.client.port'),
                'scheme' => config('broadcasting.connections.reverb.client.scheme'),
                'channels' => ['tenant.'.$device->tenant_id.'.catalog', $till ? 'tenant.'.$device->tenant_id.'.stock.'.$till->warehouse_id : null,
                    app(ModuleService::class)->isEnabled($branch, 'restaurant') ? 'tenant.'.$device->tenant_id.'.dining.'.$branch->id : null],
            ] : null,
            // Distribution: the van behind this till, today's route and this month's target (null when not a van).
            'van' => $till && app(ModuleService::class)->isEnabled($branch, 'distribution') ? VanController::today($till, $request->user()) : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Sellable catalog for this till: units, barcodes, tax components, prices on usable lists, stock at the till's warehouse. */
    public function catalog(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $version = $this->catalogVersion();
        // The till caches the catalog for offline use and asks only when something changed.
        if ($request->header('If-None-Match') === '"'.$version.'"') {
            return response()->json(null, 304)->setEtag($version);
        }
        $till = Till::find($device->till_id);
        $lists = $this->lists(Branch::find($device->branch_id))->pluck('id');
        $now = now();

        $products = Product::where('is_active', true)
            ->with(['taxGroup.rates' => fn ($q) => $q->where('is_active', true), 'units' => fn ($q) => $q->where('is_sellable', true),
                'units.uom', 'units.barcodes',
                'prices' => fn ($q) => $q->whereIn('price_list_id', $lists)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))])
            ->orderBy('name')->limit(20000)->get();
        $stock = $till ? StockLevel::where('warehouse_id', $till->warehouse_id)->pluck('on_hand', 'product_id') : collect();

        return response()->json([
            'version' => $version,
            'generated_at' => $now->toIso8601String(),
            // Every active promotion; the till picks the running ones (dates, days, happy hours, branch, list) like the server.
            'promotions' => Promotion::where('is_active', true)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->get()
                ->map(fn (Promotion $p) => $p->toEngine())->values(),
            'utc_offset_minutes' => (int) round(Carbon::now(Branch::find($device->branch_id)?->timezone ?: config('app.timezone'))->utcOffset()),
            // Grocery: weighted-barcode scale labels and near-expiry markdown stickers this branch's tills recognise.
            'grocery' => app(ModuleService::class)->isEnabled($device->branch_id, 'grocery') ? [
                'scale_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) app(Settings::class)->get('grocery.scale_prefixes', $device->tenant_id))))),
                'markdown_prefix' => (string) app(Settings::class)->get('grocery.markdown_prefix', $device->tenant_id),
                'markdowns' => MarkdownService::activeFor($device->branch_id),
            ] : null,
            'electronics' => app(ModuleService::class)->isEnabled($device->branch_id, 'electronics'), // trade-ins and repairs at this till
            // Fashion: styles for the size × colour picker, and the alteration product (null when the module is off here).
            'fashion' => app(ModuleService::class)->isEnabled($device->branch_id, 'fashion') ? [
                'styles' => Style::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'sizes', 'colours', 'season', 'collection'])->values(),
                'alteration_product_id' => app(AlterationService::class)->product($device->tenant_id)?->id,
            ] : null,
            // Services: staff who take appointments (the "done by" list) and the booking day (null when the module is off here).
            'services' => app(ModuleService::class)->isEnabled($device->branch_id, 'services') ? [
                'staff' => AppointmentService::staff($device->branch_id)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'job_title' => $e->job_title])->values(),
                'slot_minutes' => (int) app(Settings::class)->get('services.slot_minutes', $device->tenant_id),
                'day_start' => (string) app(Settings::class)->get('services.day_start', $device->tenant_id),
                'day_end' => (string) app(Settings::class)->get('services.day_end', $device->tenant_id),
            ] : null,
            // Restaurant: modifier choices, the service charge and tips (null when the module is off at this branch).
            'restaurant' => app(ModuleService::class)->isEnabled($device->branch_id, 'restaurant') ? [
                'modifier_groups' => ModifierGroup::with('options')->where('is_active', true)->orderBy('name')->get()->map(fn ($g) => ['id' => $g->id, 'name' => $g->name,
                    'min' => $g->min_choices, 'max' => $g->max_choices, 'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'product_id' => $o->product_id])->values()])->values(),
                'service_charge' => ($sc = app(DiningSales::class)->serviceCharge($device->tenant_id, Branch::find($device->branch_id))) ? ['product_id' => $sc[0], 'bp' => $sc[1]] : null,
                'tips' => (bool) app(Settings::class)->get('restaurant.tips_enabled', $device->tenant_id),
            ] : null,
            // Pharmacy: prescription-only medicines need a pharmacist; the interaction table drives the till's warnings.
            'pharmacy' => app(ModuleService::class)->isEnabled($device->branch_id, 'pharmacy') ? [
                'interactions' => DrugInteraction::orderBy('ingredient_a')->get(['ingredient_a', 'ingredient_b', 'severity', 'note'])->toArray(),
            ] : null,
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'category_id' => $p->category_id, 'track_stock' => $p->track_stock, 'plu' => $p->plu,
                'station' => $p->station, 'modifier_group_ids' => $p->modifier_group_ids ?? [], 'track_serials' => $p->track_serials, 'warranty_months' => $p->warranty_months,
                'style_id' => $p->style_id, 'size' => $p->size, 'colour' => $p->colour,
                'service_minutes' => $p->service_minutes, 'is_pack' => $p->pack !== null, 'licence_keys' => $p->licence_keys,
                'is_container' => $p->is_container, 'deposit_product_id' => $p->deposit_product_id, 'deposit_per_unit' => $p->deposit_per_unit === null ? null : (string) $p->deposit_per_unit,
                'drug' => $p->drug_schedule ? ['schedule' => $p->drug_schedule, 'generic_name' => $p->generic_name, 'strength' => $p->strength,
                    'dosage_form' => $p->dosage_form, 'active_ingredients' => $p->active_ingredients ?? []] : null,
                'on_hand' => $p->track_stock ? (string) ($stock[$p->id] ?? '0.000') : null,
                'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values(),
                'units' => $p->units->map(fn ($u) => [
                    'id' => $u->id, 'uom_code' => $u->uom->code, 'uom_name' => $u->uom->name, 'decimals' => $u->uom->decimals,
                    'factor' => (string) $u->factor, 'is_base' => $u->is_base, 'barcodes' => $u->barcodes->pluck('code'),
                ])->values(),
                'prices' => $p->prices->map(fn ($x) => [
                    'price_list_id' => $x->price_list_id, 'product_uom_id' => $x->product_uom_id, 'min_qty' => (string) $x->min_qty,
                    'amount_minor' => $x->amount_minor, 'starts_at' => $x->starts_at?->toIso8601String(), 'ends_at' => $x->ends_at?->toIso8601String(),
                ])->values(),
            ])->values(),
        ])->setEtag($version);
    }

    /** Changes whenever anything the till caches changes (catalog, prices, tax, lists, stock). */
    /**
     * Customers for lookup at the till (cached for offline use; ETag). Credit figures are a snapshot: the server
     * re-checks every credit sale it receives online.
     */
    public function customers(Request $request, CustomerService $credit, CustomerLedger $ledger, Authorizer $authz): JsonResponse
    {
        if (! $authz->allowsAnywhere($request->user(), 'customers.view')) {
            return response()->json(['version' => 'none', 'customers' => []]);
        }
        $tenant = app(TenantContext::class)->id();
        // Allergies and NHIS numbers are health data: only pharmacy staff's tills receive them.
        $health = $authz->allowsAnywhere($request->user(), 'pharmacy.patients') || $authz->allowsAnywhere($request->user(), 'pharmacy.dispense');
        $version = md5(implode('|', [
            $health ? 'health:'.json_encode(DB::table('patient_profiles')->where('tenant_id', $tenant)->selectRaw('count(*) as c, max(updated_at) as u')->first()) : 'no-health',
            DB::table('customers')->where('tenant_id', $tenant)->count(), DB::table('customers')->where('tenant_id', $tenant)->max('updated_at'),
            DB::table('customer_balances')->where('tenant_id', $tenant)->max('updated_at'),
            DB::table('customer_orders')->where('tenant_id', $tenant)->max('updated_at'),
            DB::table('loyalty_balances')->where('tenant_id', $tenant)->max('updated_at'),
            now()->toDateString(), // overdue status changes with the date
        ]));
        if ($request->header('If-None-Match') === '"'.$version.'"') {
            return response()->json(null, 304)->setEtag($version);
        }

        $points = DB::table('loyalty_balances')->pluck('points', 'customer_id');
        $patients = $health ? PatientProfile::get(['customer_id', 'allergies', 'nhis_no', 'nhis_expires_on'])->keyBy('customer_id') : collect();

        return response()->json(['version' => $version, 'customers' => Customer::where('is_active', true)->with('balance')->orderBy('name')->limit(50000)->get()
            ->map(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'price_list_id' => $c->price_list_id,
                'credit_limit_minor' => $c->credit_limit_minor, 'credit_hold' => $c->credit_hold, 'balance_minor' => $c->balanceMinor(),
                'available_minor' => $c->credit_limit_minor === null ? null : $credit->availableCredit($c),
                'overdue_minor' => $c->credit_limit_minor === null && $c->balanceMinor() <= 0 ? 0 : $ledger->overdueMinor($c->id),
                'points' => (int) ($points[$c->id] ?? 0)]
                + ($health ? self::patient($patients->get($c->id)) : []))->values()])->setEtag($version);
    }

    private static function patient(?PatientProfile $p): array
    {
        return ['allergies' => $p?->allergies ?? [], 'nhis_no' => $p?->nhis_no, 'nhis_expires_on' => $p?->nhis_expires_on?->toDateString()];
    }

    private function catalogVersion(): string
    {
        $tenant = app(TenantContext::class)->id();
        $parts = [];
        foreach (['products', 'product_uoms', 'barcodes', 'prices', 'price_lists', 'tax_rates', 'tax_groups', 'uoms', 'promotions', 'markdowns', 'drug_interactions', 'modifier_groups', 'settings', 'styles'] as $t) {
            $row = DB::table($t)->where('tenant_id', $tenant)->selectRaw('count(*) as c, max(updated_at) as u')->first();
            $parts[] = $t.':'.$row->c.':'.$row->u;
        }
        $parts[] = 'tax_group_rates:'.DB::table('tax_group_rates')->count();
        $parts[] = 'stock:'.DB::table('stock_levels')->where('tenant_id', $tenant)->max('updated_at');

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    public function openShift(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'opening_float_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'id' => ['nullable', 'uuid'], // till-generated, so the same shift can later be referenced offline
            'vault_session_id' => ['nullable', 'uuid'], // cash control: the vault the cashier issued to this teller
        ]);
        $shift = $shifts->open($this->device($request), $request->user(), (int) $data['opening_float_minor'], $data['id'] ?? null,
            vaultSessionId: $data['vault_session_id'] ?? null);

        return response()->json(['shift' => $this->shiftJson($shift, $shifts)], 201);
    }

    public function closeShift(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'counted_cash_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'denoms' => ['nullable', 'array'], // the count note by note: {key: count}
            'denoms.*' => ['integer', 'min:0', 'max:1000000'],
        ]);
        $shift = $shifts->close($this->device($request), $request->user(), (int) $data['counted_cash_minor'], $data['note'] ?? null,
            denoms: $data['denoms'] ?? null);

        // On a vault the result stays blind too: the cashier counts it next, against the teller's count.
        return response()->json(['shift' => $this->shiftJson($shift, $shifts, reveal: ! $shift->vault_session_id)]);
    }

    public function postSale(Request $request, SaleService $sales): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'uuid'],
            'device_seq' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date'],
            'price_list_id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.product_uom_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'max:20'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'lines.*.line_total_minor' => ['required', 'integer'], // negative for empties handed back
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.serials' => ['nullable', 'array', 'max:500'], 'lines.*.serials.*' => ['string', 'max:80'],
            'lines.*.repair_job_id' => ['nullable', 'uuid'],
            'lines.*.alteration_id' => ['nullable', 'uuid'],
            'lines.*.staff_id' => ['nullable', 'uuid'], 'lines.*.service_pass_id' => ['nullable', 'uuid'], 'lines.*.pass_item' => ['nullable', 'integer', 'min:0', 'max:50'],
            'lines.*.appointment_id' => ['nullable', 'uuid'],
            'trade_ins' => ['nullable', 'array', 'max:10'], 'trade_ins.*.product_id' => ['required', 'uuid'], 'trade_ins.*.value_minor' => ['required', 'integer', 'min:1'],
            'trade_ins.*.serial' => ['nullable', 'string', 'max:80'], 'trade_ins.*.condition' => ['nullable', 'string', 'max:255'],
            'lines.*.dining_item_id' => ['nullable', 'uuid'],
            'lines.*.service_charge' => ['nullable', 'boolean'],
            'dining_order_id' => ['nullable', 'uuid'],
            'tip_minor' => ['nullable', 'integer', 'min:0'],
            'totals.net_minor' => ['required', 'integer'],
            'totals.tax_minor' => ['required', 'integer'],
            'totals.total_minor' => ['required', 'integer'],
            'payments' => ['present', 'array', 'max:10'], // empty only when nothing is due (e.g. sessions from a prepaid pass)
            'payments.*.method' => ['required', 'string', 'max:20'],
            'payments.*.provider' => ['nullable', 'string', 'max:20'],
            'payments.*.amount_minor' => ['required', 'integer'], // negative only to pay out the deposit of empties
            'payments.*.tendered_minor' => ['nullable', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
            'payments.*.payer_phone' => ['nullable', 'string', 'max:20'],
        ]);
        $device = $this->device($request);
        $result = $sales->post($device, $request->user(), $request->all());
        if (! $result['replayed']) {
            app(FiscalService::class)->submitSaleNow($result['sale']); // E-VAT realtime: the GRA code on the receipt
        }

        return response()->json(['sale' => $this->saleJson($result['sale']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    /**
     * Queued work from a till that was offline (or still has a queue). Authenticated by the DEVICE
     * token (the people who sold may have signed out) and signed by the device's own key; each
     * event carries the offline grant of the person who did it.
     */
    public function sync(Request $request, PosSyncService $sync, DeviceSignature $signature): JsonResponse
    {
        $device = $this->device($request);
        $signature->verify($device, $request->getContent(), $request->header('X-Device-Signature'));
        $data = $request->validate(['events' => ['required', 'array', 'min:1', 'max:200']]);
        $license = app(LicenseManager::class)->status();
        if ($license['read_only']) {
            // Refuse the whole batch without judging it: the till keeps its queue and sends it once the licence is renewed.
            return response()->json(['error' => 'license_read_only', 'detail' => $license['message']], 423);
        }
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['results' => $sync->apply($device, $data['events'])]);
    }

    public function showSale(Request $request, string $id): JsonResponse
    {
        $sale = Sale::where('device_id', $this->device($request)->id)->with('lines', 'payments')->find($id)
            ?? throw new DomainException('Sale not found on this device.', 'not_found');

        return response()->json(['sale' => $this->saleJson($sale)]);
    }

    /** Send a receipt by SMS, WhatsApp or e-mail (the customer asked for it). */
    public function sendReceipt(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:sms,whatsapp,email'], 'to' => ['required', 'string', 'max:160']]);
        $m = app(ReceiptSender::class)->send($this->device($request), $request->user(), $id, $data['channel'], $data['to']);

        return response()->json(['status' => $m->status, 'detail' => $m->status === 'sent' ? 'Sent.' : ($m->status === 'queued' ? 'Will be sent shortly.' : (string) $m->error)]);
    }

    /** "Report a problem" from the till: the cashier's words, the screen, the till app's version and its last errors. */
    public function reportProblem(Request $request): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:5', 'max:5000'], 'screen' => ['nullable', 'string', 'max:120'],
            'context' => ['nullable', 'array', 'max:20']]);
        $device = $this->device($request);
        $r = app(SupportReports::class)->report(Actor::user($request->user(), 'pos', $device->id), 'till', $data['message'], $data['screen'] ?? null,
            ($data['context'] ?? []) + ['device' => $device->name, 'till_id' => $device->till_id], $device->id, $device->branch_id);

        return response()->json(['number' => $r->number, 'sent' => $r->sent_at !== null], 201);
    }

    // -------------------------------------------------------------------

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function lists(Branch $branch)
    {
        return PriceList::where('is_active', true)
            ->where(fn ($q) => $q->where('pos_selectable', true)->orWhere('is_default', true)
                ->orWhereIn('id', Customer::where('is_active', true)->whereNotNull('price_list_id')->select('price_list_id'))) // customers' own lists
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderByDesc('is_default')->orderBy('name')->get();
    }

    private function shiftJson(?Shift $shift, ShiftService $shifts, bool $reveal = false): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id, 'status' => $shift->status, 'opened_at' => $shift->opened_at->toIso8601String(),
            'opened_by' => $shift->opened_by_name, 'opening_float_minor' => $shift->opening_float_minor,
            // Blind count: expected cash is only revealed after the count is submitted.
            'expected_cash_minor' => $reveal ? $shift->expected_cash_minor : null,
            'counted_cash_minor' => $reveal ? $shift->counted_cash_minor : null,
            'over_short_minor' => $reveal ? $shift->over_short_minor : null,
        ];
    }

    /** A receipt to take goods back against (by receipt number): lines, what can still be returned, how it was paid. */
    public function lookupSale(Request $request, ReturnService $returns): JsonResponse
    {
        $no = strtoupper(trim((string) $request->query('receipt', '')));
        $sale = Sale::with('lines', 'payments')->where('kind', 'sale')->where('receipt_no', $no)->first();
        if (! $sale) {
            return response()->json(['error' => 'not_found', 'detail' => "No sale with receipt number {$no}."], 404);
        }

        return response()->json(['sale' => $this->saleJson($sale), 'returnable' => $returns->returnable($sale), 'customer_id' => $sale->customer_id]);
    }

    public function postReturn(Request $request, ReturnService $returns): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'uuid'], 'device_seq' => ['required', 'integer', 'min:1'], 'occurred_at' => ['required', 'date'], 'shift_id' => ['required', 'uuid'],
            'original_sale_id' => ['nullable', 'uuid'], 'reason' => ['required', 'string', 'max:255'], 'customer_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.qty' => ['required', 'string', 'max:20'],
            'refunds' => ['nullable', 'array', 'max:10'], 'refunds.*.method' => ['required', 'string', 'max:20'], 'refunds.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);
        $r = $returns->post($this->device($request), $request->user(), $request->all());

        return response()->json(['sale' => $this->saleJson($r['sale']), 'store_credit_code' => $r['store_credit_code']], 201);
    }

    /** Take money from a customer at the till against their account (counts in this shift's cash). */
    public function accountPayment(Request $request, ReceivablesService $receivables, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,momo,card'], 'provider' => ['nullable', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:80']]);
        $device = $this->device($request);
        $shift = $shifts->current($device) ?? throw new DomainException('Open a shift on this till first.', 'no_shift');
        $till = Till::findOrFail($device->till_id);
        $p = $receivables->recordPayment(Actor::user($request->user(), 'pos', $device->id), Customer::findOrFail($data['customer_id']), Branch::findOrFail($till->branch_id),
            $data['method'], $data['amount_minor'], $data['reference'] ?? null, null, null, $data['provider'] ?? null, 'Taken at till '.$till->code, $shift->id);

        return response()->json(['number' => $p->number, 'amount_minor' => $p->amount_minor,
            'balance_minor' => (int) DB::table('customer_balances')->where('customer_id', $p->customer_id)->value('balance_minor')], 201);
    }

    private function saleJson(Sale $s): array
    {
        return [
            'id' => $s->id, 'receipt_no' => $s->receipt_no, 'device_seq' => $s->device_seq, 'status' => $s->status,
            'occurred_at' => $s->occurred_at->toIso8601String(), 'cashier' => $s->cashier_name, 'currency' => $s->currency_code,
            'prices_include_tax' => $s->prices_include_tax, 'net_minor' => $s->net_minor, 'tax_minor' => $s->tax_minor,
            'total_minor' => $s->total_minor, 'paid_minor' => $s->paid_minor, 'change_minor' => $s->change_minor,
            'tax_breakdown' => $s->tax_breakdown,
            'lines' => $s->lines->map(fn ($l) => ['line_no' => $l->line_no, 'sku' => $l->sku, 'name' => $l->name, 'uom' => $l->uom_code,
                'qty' => Qty::format(Qty::toMilli((string) $l->qty)), 'unit_price_minor' => $l->unit_price_minor, 'line_total_minor' => $l->line_total_minor,
                'discount_minor' => (int) $l->discount_minor, 'promotions' => $l->promotions ?? [], 'note' => $l->note, 'serials' => $l->serials ?? [],
                'id' => $l->id, 'staff_id' => $l->staff_id, 'service_pass_id' => $l->service_pass_id,
                'warranty_until' => $l->serials ? SerialNumber::where('sale_id', $s->id)->whereIn('serial', $l->serials)->max('warranty_until') : null])->values(),
            'tip_minor' => (int) $s->tip_minor,
            'licence_keys' => LicenceKeyService::forSale($s->id), // Services: printed on the receipt
            'order_number' => $s->dining_order_id ? DiningOrder::whereKey($s->dining_order_id)->value('number') : null,
            'kind' => $s->kind, 'discount_minor' => (int) $s->discount_minor, 'points_earned' => (int) $s->points_earned,
            'payments' => $s->payments->map(fn ($p) => ['method' => $p->method, 'provider' => $p->provider, 'amount_minor' => $p->amount_minor,
                'tendered_minor' => $p->tendered_minor, 'reference' => $p->reference, 'status' => $p->status])->values(),
            'fiscal' => ($f = FiscalSubmission::where('doc_type', 'sale')->where('doc_id', $s->id)->first(['status', 'gra_reference', 'qr_code', 'mode']))
                ? ['status' => $f->status, 'code' => $f->gra_reference, 'qr' => $f->qr_code, 'mode' => $f->mode] : null,
        ];
    }
}

<?php

namespace App\Domain\Pos;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Electronics\ElectronicsSales;
use App\Domain\Fashion\AlterationService;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockLedger;
use App\Domain\Messaging\PaymentAlerts;
use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\PharmacyRules;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\PromotionService;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Restaurant\DiningSales;
use App\Domain\Services\ServiceSales;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Markdown;
use App\Models\Prescription;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Caps;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Posts a till sale. The till proposes; the server decides.
 *
 * ONLINE (the till is talking to us now): every price, tax and total is recomputed and must match
 * what the customer was shown; a changed price is refused so the cashier can re-ring it.
 *
 * OFFLINE (queued on the till, arriving later under a signed offline grant): money and goods have
 * already changed hands, so the sale is never refused for an old price, an expired grant, a wrong
 * clock or missing stock — it is posted as it happened and each anomaly becomes a review item.
 * Arithmetic must still be internally consistent, and the grant must be genuinely ours.
 *
 * Both are idempotent on the sale id the till generated.
 */
final class SaleService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly PriceResolver $prices,
        private readonly StockLedger $ledger,
        private readonly ShiftService $shifts,
        private readonly OfflineGrant $grants,
        private readonly ReviewQueue $reviews,
        private readonly CustomerService $customers,
        private readonly CustomerLedger $receivables,
        private readonly SupervisorApproval $approvals,
        private readonly Loyalty $loyalty,
        private readonly StoredValues $storedValues,
        private readonly Settings $settings,
        private readonly ModuleService $modules,
        private readonly DocumentNumbers $numbers,
        private readonly DiningSales $dining,
        private readonly ElectronicsSales $electronics,
        private readonly AlterationService $alterations,
        private readonly ConsignmentService $consignment,
        private readonly ServiceSales $services,
    ) {}

    /** @return array{sale: Sale, replayed: bool} */
    public function post(Device $device, ?User $user, array $p, ?array $grant = null): array
    {
        $offline = $grant !== null;
        $hash = hash('sha256', json_encode($this->canonical($p)));
        if ($existing = Sale::withoutGlobalScopes()->find($p['id'])) {
            if ($existing->tenant_id !== $device->tenant_id || $existing->payload_hash !== $hash) {
                throw new DomainException('A different sale with this id was already recorded.', 'conflict');
            }

            return ['sale' => $existing->load('lines', 'payments'), 'replayed' => true];
        }

        $till = $device->till_id ? Till::find($device->till_id) : null;
        if (! $till || ! $device->receipt_series) {
            throw new DomainException('This device is not assigned to a till.', 'no_till');
        }
        $occurredAt = Carbon::parse($p['occurred_at']);
        $flags = [];

        if ($offline) {
            $grant = $this->grants->verify($grant);
            if ($grant['device_id'] !== $device->id) {
                throw new DomainException('That offline sign-in belongs to another device.', 'invalid_grant');
            }
            $user = User::where('tenant_id', $device->tenant_id)->find($grant['user_id'])
                ?? throw new DomainException('The person on the offline sign-in no longer exists.', 'invalid_grant');
            if ($occurredAt->gt($this->grants->expiresAt($grant))) {
                $flags[] = ['grant_expired', "Sold {$occurredAt->diffForHumans($this->grants->expiresAt($grant), true)} after {$user->name}'s offline sign-in expired"];
            }
            if (! $user->is_active || ! $this->authz->allows($user, 'pos.access', $till)) {
                $flags[] = ['grant_revoked', "{$user->name} no longer has till access here"];
            }
            if ($occurredAt->isAfter(now()->addMinutes(10))) {
                $flags[] = ['clock_skew', 'Till clock was ahead of the server by '.now()->diffForHumans($occurredAt, true)];
            }
            $shift = Shift::where('device_id', $device->id)->find($p['shift_id'] ?? null);
            if (! $shift || ($shift->closed_at && $shift->closed_at->lt($occurredAt))) {
                throw new DomainException('The shift for this offline sale was not received.', 'no_shift');
            }
        } else {
            if (! $user || ! $this->authz->allows($user, 'pos.access', $till)) {
                throw new AccessDenied('You are not set up to sell on this till.');
            }
            $shift = $this->shifts->current($device);
            if (! $shift || $shift->id !== ($p['shift_id'] ?? null)) {
                throw new DomainException('Open a shift on this till before selling.', 'no_shift');
            }
            if ($occurredAt->isAfter(now()->addMinutes(10))) {
                throw new DomainException('The till clock is ahead of the server. Fix the device time and try again.', 'clock_skew');
            }
        }

        $branch = Branch::findOrFail($till->branch_id);
        $warehouse = Warehouse::findOrFail($till->warehouse_id);
        $customer = null;
        if (! empty($p['customer_id'])) {
            $customer = Customer::find($p['customer_id']) ?? throw new DomainException('That customer is not known here. Refresh the till.', 'unknown_customer');
        }
        $list = PriceList::find($p['price_list_id'] ?? null);
        $listUsable = $list && (! $list->branch_id || $list->branch_id === $branch->id)
            && ($list->pos_selectable || $list->is_default || ($customer && $customer->price_list_id === $list->id)); // a customer's own list
        if (! $list || (! $offline && (! $list->is_active || ! $listUsable))) {
            throw new DomainException('That price list cannot be used on this till.');
        }
        if (! is_int($p['device_seq'] ?? null) || $p['device_seq'] < 1) {
            throw new DomainException('Missing receipt sequence from the till.');
        }

        // --- lines: list price (from the price list), then promotions, discounts and tax ---------------------------------
        $lines = [];
        $engineLines = [];
        $priceDiffs = [];
        $service = $this->dining->serviceCharge($device->tenant_id, $branch); // Restaurant: [product id, basis points] or null
        foreach (array_values($p['lines'] ?? []) as $i => $l) {
            $product = Product::with('taxGroup.rates')->when(! $offline, fn ($q) => $q->where('is_active', true))->find($l['product_id'] ?? null)
                ?? throw new DomainException('A product in the cart is no longer for sale.', 'product_unavailable');
            $unit = ProductUom::with('uom')->where('product_id', $product->id)->when(! $offline, fn ($q) => $q->where('is_sellable', true))
                ->find($l['product_uom_id'] ?? null) ?? throw new DomainException("{$product->name}: that unit is not sold.", 'product_unavailable');
            $qty = Qty::toMilli((string) $l['qty']);
            // Distribution: empties (returnable containers) handed back come in as a line with a minus quantity.
            if ($qty === 0 || ($qty < 0 && ! $product->is_container)) {
                throw new DomainException("{$product->name}: quantity must be above zero.");
            }
            if ($qty % (10 ** (3 - $unit->uom->decimals)) !== 0) {
                throw new DomainException("{$product->name}: {$unit->uom->name} allows {$unit->uom->decimals} decimal place(s).");
            }
            $unitPrice = (int) $l['unit_price_minor'];
            $note = isset($l['note']) ? mb_substr(trim((string) $l['note']), 0, 255) ?: null : null;
            $dosage = isset($l['dosage']) ? Caps::upper(mb_substr(trim((string) $l['dosage']), 0, 255)) ?: null : null; // the directions given to the patient
            $diningItem = isset($l['dining_item_id']) ? (string) $l['dining_item_id'] : null;
            if (! empty($l['service_charge'])) {
                // Restaurant service charge: one line of the service product, priced from the rest of the bill below.
                if (! $service || $product->id !== $service[0] || $qty !== 1000 || ! empty($l['manual'])) {
                    throw new DomainException('Service charge is not set up for this branch. Refresh the till.', 'service_charge_invalid');
                }
                $rates = $product->taxGroup->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
                $lines[] = compact('product', 'unit', 'qty', 'unitPrice', 'rates', 'note') + ['manual' => null, 'key' => (string) $i, 'markdown' => null, 'no' => $i + 1,
                    'given' => (int) ($l['line_total_minor'] ?? -1), 'service' => true, 'dining_item' => null];

                continue;
            }
            // A near-expiry markdown sticker (Grocery): its own price and batch, outside promotions.
            $markdown = ! empty($l['markdown_id']) ? Markdown::where('branch_id', $branch->id)->where('product_uom_id', $unit->id)->find($l['markdown_id']) : null;
            if (! empty($l['markdown_id']) && ! $offline && (! $markdown || $markdown->status !== 'active' || $markdown->valid_until->lt($occurredAt->copy()->startOfDay()))) {
                throw new DomainException("{$product->name}: that markdown sticker is no longer valid.", 'markdown_invalid');
            }
            // Fashion: an alteration job at its agreed charge (outside price lists and promotions, like a sticker).
            $alteration = $this->alterations->forLine($l, $branch, $product, $offline);
            // Services: who did it, a session paid from the customer's pass (no charge), the appointment being paid for.
            $svc = $this->services->line($l, $customer, $product, $qty, $branch, $occurredAt, $offline, $flags);
            $pass = $svc['pass'];
            $current = match (true) {
                (bool) $markdown => (object) ['amount_minor' => $markdown->price_minor],
                (bool) $alteration => (object) ['amount_minor' => $alteration->charge_minor],
                (bool) $pass => (object) ['amount_minor' => 0],
                default => $this->prices->resolve($list, $product->id, $unit->id, abs($qty), $occurredAt),
            };
            if ($current?->amount_minor !== $unitPrice) {
                if (! $offline) {
                    throw new DomainException(
                        $current ? "The price of {$product->name} changed. Refresh the till and try again." : "{$product->name} has no {$list->name} price.",
                        'price_changed');
                }
                $priceDiffs[] = ['product' => $product->name, 'sku' => $product->sku, 'unit' => $unit->uom->code,
                    'charged_minor' => $unitPrice, 'current_minor' => $current?->amount_minor];
            }
            // Tax set-up as it is now. (Rates rarely change; a change during an outage shows as a totals mismatch → review.)
            $rates = $product->taxGroup->rates->where('is_active', true)
                ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
            $manual = self::manualDiscount($l['manual'] ?? null, $unitPrice, $product->name);
            $key = (string) $i;
            $engineLines[] = ['key' => $key, 'product_id' => match (true) {
                (bool) $markdown => 'markdown:'.$markdown->id, (bool) $alteration => 'alteration:'.$alteration->id,
                (bool) $pass => 'pass:'.$pass['pass']->id, $product->is_container => 'container:'.$product->id, default => $product->id,
            }, 'category_id' => $markdown || $alteration || $pass || $product->is_container ? null : $product->category_id, 'qty_milli' => $qty,
                'unit_price_minor' => $unitPrice, 'tax' => $rates, 'manual' => $manual];
            $serials = $product->track_serials ? SerialRegistry::normalise($l['serials'] ?? []) : [];
            $lines[] = compact('product', 'unit', 'qty', 'unitPrice', 'manual', 'rates', 'key', 'markdown', 'note', 'dosage', 'serials') + ['no' => $i + 1, 'given' => (int) ($l['line_total_minor'] ?? -1),
                'service' => false, 'dining_item' => $diningItem, 'repair_job' => isset($l['repair_job_id']) ? (string) $l['repair_job_id'] : null, 'alteration' => $alteration, 'svc' => $svc];
        }
        if ($lines === [] || $engineLines === []) {
            throw new DomainException('The cart is empty.');
        }
        if (count(array_filter($lines, fn ($l) => $l['service'])) > 1) {
            throw new DomainException('Only one service charge per bill.', 'service_charge_invalid');
        }
        $saleBp = (int) ($p['sale_discount_bp'] ?? 0);
        if ($saleBp < 0 || $saleBp > 10000) {
            throw new DomainException('A sale discount is between 0 and 100 %.');
        }
        $coupon = isset($p['coupon_code']) && trim((string) $p['coupon_code']) !== '' ? strtoupper(trim((string) $p['coupon_code'])) : null;

        // --- promotions and discounts: the same engine as the till (PromotionEngine ⇄ pos/src/promotions.ts) ---------------
        $calc = PromotionEngine::compute(['lines' => $engineLines, 'inclusive' => $list->prices_include_tax, 'coupon' => $coupon, 'sale_discount_bp' => $saleBp,
            'promotions' => PromotionService::active($branch->id, $list->id, $occurredAt)]);
        $results = array_column($calc['lines'], null, 'key');
        foreach ($lines as $l) {
            if (! $l['service']) {
                continue;
            }
            // Service charge = its rate × the bill after discounts (gross on tax-inclusive lists, net otherwise).
            $amount = Qty::divRound(($list->prices_include_tax ? $calc['total'] : $calc['net']) * $service[1], 10000);
            if ($amount !== $l['unitPrice'] && ! $offline) {
                throw new DomainException('The service charge on the till differs from the server’s. Refresh the till and try again.', 'totals_mismatch');
            }
            $t = TaxCalculator::line($l['unitPrice'], $l['rates'], $list->prices_include_tax);
            $results[$l['key']] = ['key' => $l['key'], 'list_total' => $l['unitPrice'], 'item_discount' => 0, 'manual_discount' => 0, 'basket_discount' => 0, 'discount' => 0,
                'total' => $t['gross'], 'net' => $t['net'], 'tax' => $t['tax'], 'breakdown' => $t['breakdown'], 'promotions' => []];
            $calc['total'] += $t['gross'];
            $calc['net'] += $t['net'];
            $calc['tax'] += $t['tax'];
        }
        $tot = $p['totals'] ?? [];
        $agrees = collect($lines)->every(fn ($l) => $results[$l['key']]['total'] === $l['given'])
            && (int) ($tot['total_minor'] ?? -1) === $calc['total'] && (int) ($tot['tax_minor'] ?? -1) === $calc['tax'] && (int) ($tot['net_minor'] ?? -1) === $calc['net'];
        if (! $offline && $coupon && $calc['coupon_error']) {
            throw new DomainException("Coupon {$coupon}: {$calc['coupon_error']}", 'coupon_invalid');
        }
        if (! $agrees && ! $offline) {
            throw new DomainException('Till and server totals differ (prices or promotions changed). Refresh the till and try again.', 'totals_mismatch');
        }
        if (! $agrees) {
            // Offline: money has changed hands at the till's figures — keep them (if plausible), work out tax on them, and flag.
            foreach ($lines as $l) {
                $list0 = Qty::divRound($l['qty'] * $l['unitPrice'], 1000);
                if ($l['given'] < 0 || $l['given'] > $list0) {
                    throw new DomainException('Till sent an impossible line total.', 'totals_mismatch');
                }
                $t = TaxCalculator::line($l['given'], $l['rates'], $list->prices_include_tax);
                $results[$l['key']] = ['key' => $l['key'], 'list_total' => $list0, 'discount' => $list0 - $l['given'], 'manual_discount' => 0,
                    'total' => $t['gross'], 'net' => $t['net'], 'tax' => $t['tax'], 'breakdown' => $t['breakdown'], 'promotions' => []];
            }
            $flags[] = ['offline_price', 'Promotions or discounts on the till differed from the server’s'];
        }
        $lineBreakdowns = array_map(fn ($l) => $results[$l['key']]['breakdown'], $lines);
        $net = array_sum(array_map(fn ($l) => $results[$l['key']]['net'], $lines));
        $tax = array_sum(array_map(fn ($l) => $results[$l['key']]['tax'], $lines));
        $total = array_sum(array_map(fn ($l) => $results[$l['key']]['total'], $lines));
        if ((int) ($tot['total_minor'] ?? -1) !== $total || (int) ($tot['tax_minor'] ?? -1) !== $tax || (int) ($tot['net_minor'] ?? -1) !== $net) {
            throw new DomainException('Till and server totals differ. Refresh the till and try again.', 'totals_mismatch');
        }
        $discount = array_sum(array_map(fn ($l) => $results[$l['key']]['discount'], $lines));
        if ($priceDiffs) {
            $flags[] = ['offline_price', count($priceDiffs).' line(s) sold at a price different from the current one'];
        }

        // Manual discounts within the cashier's own limit (owner: cashier 0 %, supervisor 10 %, manager 25 %), else a supervisor PIN.
        $needBp = $saleBp;
        foreach ($lines as $l) {
            $r = $results[$l['key']];
            if ($l['manual'] && $r['list_total'] > 0) {
                $needBp = max($needBp, (int) ceil(($r['manual_discount'] ?? 0) * 10000 / $r['list_total']), (int) ($l['manual']['bp'] ?? 0));
            }
        }
        $discountApprovedBy = null;
        if ($needBp > 0 && ! $this->authz->allowsAmount($user, 'pos.discount', $till, 'discount_bp', $needBp)) {
            // Offline the till checked a supervisor's PIN against their offline sign-in; their permission and limit are checked here.
            $offlineApprover = $offline && ! empty($p['discount_approved_offline_by']) ? User::where('tenant_id', $device->tenant_id)->find($p['discount_approved_offline_by']) : null;
            if ($offlineApprover && $offlineApprover->id !== $user->id && $this->authz->allowsAmount($offlineApprover, 'pos.discount', $till, 'discount_bp', $needBp)) {
                $discountApprovedBy = $offlineApprover->name;
            } elseif ($offline) {
                $flags[] = ['discount_over_limit', sprintf('%s gave a %.2f %% discount while offline, above their limit', $user->name, $needBp / 100)];
            } elseif (empty($p['discount_approval'])) {
                throw new DomainException(sprintf('A supervisor must approve a %.2f %% discount.', $needBp / 100), 'discount_needs_approval');
            } else {
                $discountApprovedBy = $this->approvals->verify($device, $p['discount_approval'], 'pos.discount', $till, 'discount_bp', $needBp, $user->id)->name;
            }
        }

        // Restaurant: the bill (dining order) being paid, and a tip on top of the total for the waiter.
        $diningOrder = $this->dining->checkPayment($p, $lines, $branch, $offline, $flags);
        $tip = (int) ($p['tip_minor'] ?? 0);
        if ($tip < 0 || ($tip > 0 && $tip > $total && ! $offline)) {
            throw new DomainException('Check the tip: it cannot be negative or more than the bill.', 'tip_invalid');
        }
        $tipUserId = $tip > 0 ? ($diningOrder?->waiter_id ?? $user->id) : null;

        // Electronics: used items handed in as part-payment (tender "trade_in").
        $tradeIns = $this->electronics->checkTradeIns($device, $user, $p, $branch, $offline, $flags);

        [$payments, $paid, $change] = $this->payments($p['payments'] ?? [], $total + $tip);
        $tradeTender = array_sum(array_map(fn ($x) => $x['method'] === 'trade_in' ? $x['amount_minor'] : 0, $payments));
        if ($tradeTender !== $tradeIns['total']) {
            throw new DomainException('The trade-in payment must equal the value of the items handed in.', 'trade_in_invalid');
        }

        // On account (credit sale): within the customer's credit, or approved by a supervisor's PIN (owner decision: hold for approval).
        $onAccount = array_sum(array_map(fn ($x) => $x['method'] === 'account' ? $x['amount_minor'] : 0, $payments));
        $creditApprovedBy = null;
        if ($onAccount > 0) {
            if (! $customer) {
                throw new DomainException('Choose the customer to put this sale on their account.', 'no_customer');
            }
            $problems = $this->customers->creditProblems($customer, $onAccount);
            if ($problems && ! $offline) {
                if (empty($p['credit_approval'])) {
                    throw new DomainException('A supervisor must approve this credit sale: '.implode('; ', $problems).'.', 'credit_needs_approval');
                }
                $creditApprovedBy = $this->approvals->verify($device, $p['credit_approval'], 'customers.credit.override', $branch, 'credit_override_minor',
                    $this->customers->overrideAmount($customer, $onAccount), $user->id)->name;
            } elseif ($problems) {
                $flags[] = ['credit_over_limit', "Credit sale to {$customer->name} while offline: ".implode('; ', $problems)];
            }
        }

        $rx = $this->prescription($device, $user, $p, $lines, $customer, $branch, $offline, $flags);
        $details = SaleDetails::from($p, $customer, $branch, $offline, $flags); // comment, walk-in name and phone, industry details

        $receiptNo = sprintf('%s-%s%s-%06d', $branch->code, $till->code, $device->receipt_series, $p['device_seq']);
        $actor = Actor::user($user, 'pos', $device->id);

        try {
            $sale = $this->recorder->transaction(function () use ($p, $device, $till, $branch, $warehouse, $shift, $user, $list, $occurredAt, $hash, $receiptNo, $lines, $lineBreakdowns, $net, $tax, $total, $paid, $change, $payments, $actor, $offline, $grant, &$flags, $priceDiffs, $customer, $onAccount, $creditApprovedBy, $results, $discount, $coupon, $saleBp, $discountApprovedBy, $calc, $rx, $diningOrder, $tip, $tipUserId, $tradeIns, $details) {
                $sale = new Sale;
                $sale->forceFill([
                    'id' => $p['id'], 'tenant_id' => $device->tenant_id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id,
                    'till_id' => $till->id, 'device_id' => $device->id, 'shift_id' => $shift->id, 'device_seq' => $p['device_seq'],
                    'receipt_no' => $receiptNo, 'cashier_id' => $user->id, 'cashier_name' => $user->name, 'price_list_id' => $list->id,
                    'prices_include_tax' => $list->prices_include_tax, 'currency_code' => $list->currency_code,
                    'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total, 'paid_minor' => $paid, 'change_minor' => $change,
                    'tax_breakdown' => TaxCalculator::sum($lineBreakdowns), 'status' => 'posted', 'payload_hash' => $hash,
                    'offline' => $offline, 'grant_expires_at' => $offline ? $this->grants->expiresAt($grant) : null,
                    'catalog_version' => isset($p['catalog_version']) ? mb_substr((string) $p['catalog_version'], 0, 64) : null,
                    'occurred_at' => $occurredAt, 'received_at' => now(), 'customer_id' => $customer?->id,
                    'discount_minor' => $discount, 'coupon_code' => $calc['coupon_applied'] ? $coupon : null, 'sale_discount_bp' => $saleBp,
                    'discount_approved_by_name' => $discountApprovedBy,
                    'dining_order_id' => $diningOrder?->id, 'tip_minor' => $tip, 'tip_user_id' => $tipUserId,
                ] + $details)->save();
                if ($rx) {
                    $rx->forceFill(['number' => $this->numbers->next($device->tenant_id, 'prescription'), 'sale_id' => $sale->id])->save();
                    $this->recorder->audit->record($actor, 'prescription.dispensed', $rx, ['number' => [null, $rx->number], 'pharmacist_name' => [null, $rx->pharmacist_name]], null, $branch->id);
                    $this->recorder->outbox->publish($actor, $rx, 'prescription.dispensed');
                }

                foreach ($lines as $l) {
                    SaleLine::create([
                        'sale_id' => $sale->id, 'line_no' => $l['no'], 'product_id' => $l['product']->id, 'sku' => $l['product']->sku,
                        'name' => $l['product']->name, 'product_uom_id' => $l['unit']->id, 'uom_code' => $l['unit']->uom->code,
                        'factor' => $l['unit']->factor, 'qty' => Qty::format($l['qty']), 'unit_price_minor' => $l['unitPrice'],
                        'line_total_minor' => $results[$l['key']]['total'], 'net_minor' => $results[$l['key']]['net'], 'tax_minor' => $results[$l['key']]['tax'],
                        'tax_breakdown' => $results[$l['key']]['breakdown'], 'list_total_minor' => $results[$l['key']]['list_total'],
                        'discount_minor' => $results[$l['key']]['discount'], 'promotions' => $results[$l['key']]['promotions'], 'manual_discount' => $l['manual'],
                        'markdown_id' => $l['markdown']?->id,
                        'prescription_id' => $rx && $l['product']->needsPrescription() ? $rx->id : null,
                        'dining_item_id' => $l['dining_item'], 'note' => $l['note'], 'dosage' => $l['dosage'] ?? null, 'is_service_charge' => $l['service'], 'serials' => ($l['serials'] ?? []) ?: null,
                        'repair_job_id' => $l['repair_job'] ?? null, 'alteration_id' => ($l['alteration'] ?? null)?->id,
                        'staff_id' => $l['svc']['staff_id'] ?? null, 'service_pass_id' => ($l['svc']['pass'] ?? null)['pass']->id ?? null,
                        'pass_item' => ($l['svc']['pass'] ?? null)['item'] ?? null, 'appointment_id' => $l['svc']['appointment_id'] ?? null,
                    ]);
                }
                foreach ($payments as $pay) {
                    SalePayment::create(['id' => (string) Str::uuid7(), 'sale_id' => $sale->id, 'approved_by_name' => $pay['method'] === 'account' ? $creditApprovedBy : null] + $pay);
                }
                $this->tenders($actor, $sale, $payments, $customer, $offline, $flags, $receiptNo, $occurredAt);
                if ($onAccount > 0) {
                    $this->receivables->post($actor, $customer, 'pos_sale', $onAccount, $list->currency_code, 'sale', $sale->id, $receiptNo,
                        $occurredAt->copy()->startOfDay()->addDays((int) $customer->payment_terms_days), $branch->id, $occurredAt);
                    $this->receivables->applyCredits($actor, $customer);
                }

                $stockEntries = $this->dining->stockEntries($lines, $customer?->id, $sale->id); // dishes with a recipe use up their ingredients
                $this->ledger->post($actor, $warehouse, 'sale', $stockEntries, "Sale {$receiptNo}", 'sale', $sale->id, $occurredAt, alreadyHappened: $offline);
                foreach ($this->ledger->serialAnomalies() as $problem) {
                    $flags[] = ['serial_problem', "Sold offline: {$problem}"]; // serial register to be put right
                }
                if ($offline) {
                    $expired = DB::table('stock_movements as m')->join('stock_batches as b', 'b.id', '=', 'm.batch_id')->where('m.source_type', 'sale')
                        ->where('m.source_id', $sale->id)->where('b.expires_on', '<', $occurredAt->toDateString())->pluck('b.batch_no')->unique();
                    if ($expired->isNotEmpty()) {
                        $flags[] = ['expired_sold', 'expired batch(es) sold while offline: '.$expired->implode(', ')];
                    }
                }

                $this->recorder->audit->record($actor, $offline ? 'sale.posted_offline' : 'sale.posted', $sale, [
                    'receipt_no' => [null, $receiptNo], 'total_minor' => [null, $total], 'lines' => [null, count($lines)],
                ], null, $branch->id);
                $this->dining->settle($actor, $sale, $lines, $diningOrder, $branch, $offline, $flags);
                $this->electronics->settle($actor, $sale, $warehouse, $tradeIns, $p, $offline, $flags);
                $this->alterations->paidBySale($actor, $sale, array_values(array_filter(array_map(fn ($l) => ($l['alteration'] ?? null)?->id, $lines))), $offline, $flags);
                $this->consignment->record($actor, $sale); // consignment stock: the consignor's share of each line
                $this->services->settle($actor, $sale, array_values(array_filter(array_map(fn ($l) => $l['svc']['appointment_id'] ?? null, $lines))), $customer, $offline, $flags);
                $this->recorder->outbox->publish($actor, $sale, 'sale.posted', ['receipt_no' => $receiptNo, 'offline' => $offline]);
                DB::afterCommit(fn () => app(PaymentAlerts::class)->forSale($sale->id)); // MoMo / card alerts, once the sale is safely saved

                if ($offline) {
                    foreach ($flags as [$type, $summary]) {
                        $this->reviews->add($actor, $type, 'sale', $sale->id, "{$receiptNo}: {$summary}",
                            $type === 'offline_price' ? ['lines' => $priceDiffs] : [], $branch->id);
                    }
                    $negative = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)
                        ->whereIn('product_id', array_map(fn ($e) => $e['product']->id, $stockEntries))->where('on_hand', '<', 0)
                        ->pluck('on_hand', 'product_id');
                    foreach ($negative as $productId => $onHand) {
                        $name = collect($stockEntries)->first(fn ($e) => $e['product']->id === $productId)['product']->name;
                        $this->reviews->add($actor, 'negative_stock', 'product', $productId,
                            "{$name} is at {$onHand} in {$warehouse->code} after offline sale {$receiptNo} — count it", ['sale_id' => $sale->id], $branch->id);
                    }
                }

                return $sale;
            });
        } catch (UniqueConstraintViolationException) {
            $again = Sale::find($p['id']);
            if ($again && $again->payload_hash === $hash) {
                return ['sale' => $again->load('lines', 'payments'), 'replayed' => true];
            }
            throw new DomainException("Receipt number {$receiptNo} is already used on this device.", 'conflict');
        }

        return ['sale' => $sale->load('lines', 'payments'), 'replayed' => false];
    }

    /**
     * Prescription-only and controlled medicines. How strict depends on the branch (PharmacyRules, Settings → Pharmacy):
     * a pharmacist approves with the prescription (default; owner decision 2026-10-16), the teller records the prescription,
     * the patient only, or sells freely; above the quantity limit or with a major warning a pharmacist is needed anyway.
     * The pharmacist approves with their username and PIN, or is the cashier. Offline the till checked the pharmacist's PIN
     * against their offline sign-in; anything missing becomes a review item instead of a refusal.
     */
    private function prescription(Device $device, User $user, array $p, array $lines, ?Customer $customer, Branch $branch, bool $offline, array &$flags): ?Prescription
    {
        $medicines = array_values(array_filter($lines, fn ($l) => $l['product']->needsPrescription()));
        if ($medicines === [] || ! $this->modules->isEnabled($branch, 'pharmacy')) {
            return null;
        }
        $names = implode(', ', array_unique(array_map(fn ($l) => $l['product']->name, $medicines)));
        $warnings = PharmacyService::warningsFor(array_map(fn ($l) => $l['product']->id, $lines), $customer?->id);
        $level = PharmacyRules::saleLevel(app(PharmacyRules::class)->forBranch($branch),
            array_map(fn ($l) => ['schedule' => $l['product']->drug_schedule, 'qty_base' => Qty::toBaseMilli($l['qty'], Qty::factorMicro((string) $l['unit']->factor))], $medicines),
            collect($warnings)->contains('severity', 'major'));
        $data = is_array($p['prescription'] ?? null) ? $p['prescription'] : [];
        $prescriber = trim((string) ($data['prescriber_name'] ?? ''));
        $issued = ($data['issued_on'] ?? null) ? Carbon::parse($data['issued_on'])->toDateString() : null;
        $needsPatient = $level !== 'free';
        $needsDetails = in_array($level, ['approve', 'details'], true);
        if (! $offline) {
            if ($needsPatient && ! $customer) {
                throw new DomainException("{$names}: choose the patient.", 'prescription_required');
            }
            if ($needsDetails && ($prescriber === '' || ! $issued)) {
                throw new DomainException("{$names} need a prescription: enter the prescriber and the date it was written.", 'prescription_required');
            }
            if ($issued && $issued > now()->toDateString()) {
                throw new DomainException('The prescription date cannot be in the future.', 'prescription_required');
            }
        } elseif (($needsPatient && ! $customer) || ($needsDetails && ($prescriber === '' || ! $issued))) {
            $flags[] = ['pharmacy_unapproved', "{$names} sold offline without the patient or prescription details"];
        }

        $pharmacist = null;
        $approvedOffline = false;
        if ($this->authz->allows($user, 'pharmacy.dispense', $branch)) {
            $pharmacist = $user;
        } elseif ($offline) {
            $by = ! empty($p['pharmacist_offline_by']) ? User::where('tenant_id', $device->tenant_id)->find($p['pharmacist_offline_by']) : null;
            if ($by && $this->authz->allows($by, 'pharmacy.dispense', $branch)) {
                [$pharmacist, $approvedOffline] = [$by, true];
            } elseif ($level === 'approve') {
                $flags[] = ['pharmacy_unapproved', "{$names} sold offline by {$user->name} without a pharmacist's approval"];
            }
        } elseif (! empty($p['pharmacist_approval'])) { // asked for, or given though the branch lets tellers sell
            $pharmacist = $this->approvals->verify($device, $p['pharmacist_approval'], 'pharmacy.dispense', $branch, null, 0, '');
        } elseif ($level === 'approve') {
            throw new DomainException("A pharmacist must approve {$names}.", 'pharmacist_needed');
        }
        if (! $customer) {
            return null; // offline without a patient: nothing to file the prescription under; the review item covers it
        }

        return (new Prescription)->forceFill([
            'id' => (string) Str::uuid7(), 'tenant_id' => $device->tenant_id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'prescriber_name' => $prescriber !== '' ? mb_substr($prescriber, 0, 255) : 'Not recorded',
            'prescriber_reg_no' => self::short($data['prescriber_reg_no'] ?? null, 60), 'facility' => self::short($data['facility'] ?? null, 255),
            'reference' => self::short($data['reference'] ?? null, 60), 'issued_on' => $issued ?? Carbon::parse($p['occurred_at'])->toDateString(),
            'pharmacist_name' => $pharmacist?->name ?? 'Not approved', 'pharmacist_id' => $pharmacist?->id, 'approved_offline' => $approvedOffline,
            'warnings' => $warnings,
        ]);
    }

    private static function short(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** A manual line discount the till sent: a percentage (basis points) or a lower unit price. */
    private static function manualDiscount(mixed $m, int $unitPrice, string $name): ?array
    {
        if (! is_array($m) || $m === []) {
            return null;
        }
        if (isset($m['price_minor'])) {
            $price = (int) $m['price_minor'];
            if ($price < 0 || $price >= $unitPrice) {
                throw new DomainException("{$name}: a changed price must be below the list price.");
            }

            return ['price_minor' => $price];
        }
        $bp = (int) ($m['bp'] ?? 0);
        if ($bp <= 0 || $bp > 10000) {
            throw new DomainException("{$name}: a discount is between 0.01 and 100 %.");
        }

        return ['bp' => $bp];
    }

    /**
     * Points, gift cards and store credit used to pay, and the points the customer earns. Online, a short balance is refused
     * (the whole sale rolls back); offline it is kept and flagged (the goods have gone).
     */
    private function tenders(Actor $actor, Sale $sale, array $payments, ?Customer $customer, bool $offline, array &$flags, string $receiptNo, $occurredAt): void
    {
        foreach ($payments as $pay) {
            if ($pay['method'] === 'deposit') {
                $this->dining->useDeposit($actor, $sale, $pay, $offline, $flags);
            }
            if (in_array($pay['method'], ['gift_card', 'store_credit'], true)) {
                $sv = $this->storedValues->find((string) $pay['reference']);
                if (! $sv) {
                    $offline ? $flags[] = ['stored_value_overdrawn', "Card {$pay['reference']} is not known here"] : throw new DomainException("Card {$pay['reference']} is not known.", 'stored_value_refused');

                    continue;
                }
                if ($problem = $this->storedValues->redeem($actor, $sv, $pay['amount_minor'], 'sale', $sale->id, $receiptNo, $offline, $occurredAt)) {
                    $flags[] = ['stored_value_overdrawn', "Paid with {$sv->code} offline but {$problem}"];
                }
            }
            if ($pay['method'] === 'points') {
                if (! $customer) {
                    throw new DomainException('Choose the customer whose points pay for this.', 'no_customer');
                }
                $balance = $this->loyalty->balance($customer->id);
                $min = (int) $this->settings->get('loyalty.min_redeem_points', $customer->tenant_id);
                $problem = $pay['amount_minor'] > $balance ? "{$customer->name} has only {$balance} points" : ($balance < $min ? "points can be used from {$min}" : null);
                if ($problem && ! $offline) {
                    throw new DomainException(ucfirst($problem).'.', 'points_refused');
                }
                if ($problem) {
                    $flags[] = ['points_overdrawn', "Points used offline: {$problem}"];
                }
                $this->loyalty->post($actor, $customer, 'redeem', -$pay['amount_minor'], 'sale', $sale->id, $receiptNo, $occurredAt); // 1 point = 1 pesewa
            }
        }
        if ($customer && $this->loyalty->enabled($customer->tenant_id)) {
            $paid = array_sum(array_map(fn ($x) => in_array($x['method'], ['cash', 'momo', 'card', 'account'], true) ? $x['amount_minor'] : 0, $payments));
            if (($points = $this->loyalty->pointsFor($paid)) > 0) {
                $this->loyalty->post($actor, $customer, 'earn', $points, 'sale', $sale->id, $receiptNo, $occurredAt);
                $sale->forceFill(['points_earned' => $points])->save();
            }
        }
    }

    /** @return array{0: list<array>, 1: int, 2: int} payments, paid, change */
    private function payments(array $input, int $total): array
    {
        $paid = $change = 0;
        $payments = [];
        foreach ($input as $pay) {
            $method = $pay['method'] ?? '';
            if (! array_key_exists($method, SalePayment::METHODS)) {
                throw new DomainException('Unknown payment method.');
            }
            $amount = (int) ($pay['amount_minor'] ?? 0);
            $tendered = (int) ($pay['tendered_minor'] ?? $amount);
            if ($total < 0 && $method === 'cash' && $amount === $total && count($input) === 1) {
                // Distribution: only empties came back — their deposit is paid out in cash.
                $payments[] = ['method' => 'cash', 'provider' => null, 'amount_minor' => $amount, 'tendered_minor' => $amount, 'reference' => null, 'payer_phone' => null, 'status' => 'paid_out'];

                return [$payments, $amount, 0];
            }
            if ($amount <= 0) {
                throw new DomainException('Each payment must be above zero.');
            }
            if ($method === 'cash') {
                if ($tendered < $amount) {
                    throw new DomainException('Cash handed over is less than the amount applied.');
                }
                $change += $tendered - $amount;
            } else {
                $tendered = $amount;
            }
            // Restaurant: a booking deposit (its reservation number), or a delivery order the platform pays us for later.
            if ($method === 'trade_in') { // Electronics: the value of used items handed in (checked against the trade-ins above)
                $paid += $amount;
                $payments[] = ['method' => 'trade_in', 'provider' => null, 'amount_minor' => $amount, 'tendered_minor' => $amount, 'reference' => null, 'payer_phone' => null,
                    'status' => 'received_goods'];

                continue;
            }
            if ($method === 'deposit' || $method === 'aggregator') {
                $reference = strtoupper(trim((string) ($pay['reference'] ?? '')));
                if ($method === 'aggregator' && ! array_key_exists((string) ($pay['provider'] ?? ''), SalePayment::PLATFORMS)) {
                    throw new DomainException('Choose the delivery app.');
                }
                if (strlen($reference) < 2) {
                    throw new DomainException($method === 'deposit' ? 'Enter the booking number.' : 'Enter the delivery app’s order number.');
                }
                $paid += $amount;
                $payments[] = ['method' => $method, 'provider' => $method === 'aggregator' ? $pay['provider'] : null, 'amount_minor' => $amount, 'tendered_minor' => $amount,
                    'reference' => mb_substr($reference, 0, 80), 'payer_phone' => null, 'status' => $method === 'deposit' ? 'redeemed' : 'due_from_platform'];

                continue;
            }
            if (in_array($method, ['account', 'points', 'gift_card', 'store_credit'], true)) {
                $code = $method === 'gift_card' || $method === 'store_credit' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($pay['reference'] ?? ''))) : null;
                if (($method === 'gift_card' || $method === 'store_credit') && strlen((string) $code) < 6) {
                    throw new DomainException('Enter the card code.');
                }
                $paid += $amount;
                $payments[] = ['method' => $method, 'provider' => null, 'amount_minor' => $amount, 'tendered_minor' => $amount,
                    'reference' => $code, 'payer_phone' => null, 'status' => $method === 'account' ? 'on_account' : 'redeemed'];

                continue;
            }
            $reference = trim((string) ($pay['reference'] ?? ''));
            if ($method === 'momo') {
                if (! array_key_exists($pay['provider'] ?? '', SalePayment::MOMO_PROVIDERS)) {
                    throw new DomainException('Choose the mobile money network.');
                }
                if (strlen($reference) < 4) {
                    throw new DomainException('Enter the mobile money transaction ID from the confirmation message.');
                }
            }
            $paid += $amount;
            $payments[] = [
                'method' => $method,
                'provider' => $method === 'cash' ? null : ($pay['provider'] ?? null),
                'amount_minor' => $amount,
                'tendered_minor' => $tendered,
                'reference' => $reference ?: null,
                'payer_phone' => isset($pay['payer_phone']) ? preg_replace('/[^0-9+]/', '', (string) $pay['payer_phone']) : null,
                'status' => $method === 'cash' ? 'received' : 'confirmed_by_cashier',
            ];
        }
        if ($paid !== $total) {
            throw new DomainException('Payments must add up exactly to the sale total.', 'payment_mismatch');
        }

        return [$payments, $paid, $change];
    }

    private function canonical(array $p): array
    {
        $keep = fn (array $a, array $keys) => array_intersect_key($a, array_flip($keys));
        $out = $keep($p, ['id', 'device_seq', 'occurred_at', 'price_list_id', 'shift_id', 'customer_id', 'coupon_code', 'sale_discount_bp', 'dining_order_id', 'tip_minor', 'trade_ins']);
        $out['lines'] = array_map(fn ($l) => $keep($l, ['product_id', 'product_uom_id', 'qty', 'unit_price_minor', 'line_total_minor', 'manual', 'dining_item_id', 'service_charge', 'serials',
            'staff_id', 'service_pass_id', 'pass_item', 'appointment_id']), $p['lines'] ?? []);
        $out['payments'] = array_map(fn ($x) => array_map('strval', $keep($x, ['method', 'provider', 'amount_minor', 'tendered_minor', 'reference'])), $p['payments'] ?? []);
        array_walk_recursive($out, fn (&$v) => $v = (string) $v);
        ksort($out);

        return $out;
    }
}

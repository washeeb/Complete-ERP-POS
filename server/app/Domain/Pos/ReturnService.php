<?php

namespace App\Domain\Pos;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Returns at the till (owner decision 2026-10-11): within the return period and against the receipt, the cashier takes the
 * goods back and refunds to the original tenders (cash, MoMo/card with a reference, the customer's account, points, the
 * gift card). Without a receipt or after the period: store credit only, approved by a supervisor's PIN. A return is a
 * sale of kind "return" with negative quantities and amounts, numbered on the till's own series; returns need the server.
 */
final class ReturnService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly StockLedger $stock,
        private readonly ShiftService $shifts,
        private readonly SupervisorApproval $approvals,
        private readonly CustomerLedger $receivables,
        private readonly Loyalty $loyalty,
        private readonly StoredValues $storedValues,
        private readonly PriceResolver $prices,
        private readonly Settings $settings,
        private readonly ReviewQueue $reviews,
    ) {}

    /** What can still be returned from a receipt: lines with returnable quantity, and refundable amount per tender. */
    public function returnable(Sale $sale): array
    {
        $returned = SaleLine::whereIn('sale_id', Sale::where('original_sale_id', $sale->id)->select('id'))
            ->groupBy('original_line_id')->selectRaw('original_line_id, SUM(-qty) AS q')->pluck('q', 'original_line_id');
        $refunded = SalePayment::whereIn('sale_id', Sale::where('original_sale_id', $sale->id)->select('id'))
            ->groupBy('method')->selectRaw('method, SUM(-amount_minor) AS a')->pluck('a', 'method');
        $days = (int) $this->settings->get('returns.days', $sale->tenant_id);

        return [
            'within_period' => $sale->occurred_at->copy()->addDays($days)->endOfDay()->isFuture(),
            'lines' => $sale->lines->map(fn (SaleLine $l) => ['id' => $l->id, 'product_id' => $l->product_id, 'product_uom_id' => $l->product_uom_id,
                'name' => $l->name, 'sku' => $l->sku, 'uom' => $l->uom_code, 'qty' => (string) $l->qty, 'line_total_minor' => $l->line_total_minor,
                'returnable_qty' => Qty::format(max(0, Qty::toMilli((string) $l->qty) - Qty::toMilli(number_format((float) ($returned[$l->id] ?? 0), 3, '.', ''))))])->values()->all(),
            'tenders' => $sale->payments->groupBy('method')->map(fn ($ps, $m) => ['method' => $m,
                'paid_minor' => $ps->sum('amount_minor'), 'refundable_minor' => $ps->sum('amount_minor') - (int) ($refunded[$m] ?? 0),
                'reference' => $ps->first()->reference])->values()->all(),
        ];
    }

    /**
     * @param array{id: string, device_seq: int, occurred_at: string, shift_id: string, original_sale_id?: ?string, reason: string,
     *   lines: list<array{original_line_id?: ?string, product_id?: string, product_uom_id?: string, qty: string}>,
     *   refunds?: list<array{method: string, amount_minor: int, reference?: ?string}>, approval?: ?array, customer_id?: ?string} $p
     * @return array{sale: Sale, store_credit_code: ?string}
     */
    public function post(Device $device, User $user, array $p): array
    {
        if ($existing = Sale::find($p['id'] ?? '')) {
            return ['sale' => $existing->load('lines', 'payments'), 'store_credit_code' => null];
        }
        $till = Till::findOrFail($device->till_id);
        if (! $this->authz->allows($user, 'pos.access', $till)) {
            throw new AccessDenied;
        }
        $shift = $this->shifts->current($device);
        if (! $shift || $shift->id !== ($p['shift_id'] ?? null)) {
            throw new DomainException('Open a shift on this till first.', 'no_shift');
        }
        $reason = trim((string) ($p['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('Give the reason for the return.');
        }
        $original = ! empty($p['original_sale_id']) ? Sale::with('lines', 'payments')->where('kind', 'sale')->findOrFail($p['original_sale_id']) : null;
        $info = $original ? $this->returnable($original) : null;
        $withReceipt = $original && $info['within_period'];

        // --- lines and what they are worth now --------------------------------------------------------------------------
        $lines = [];
        foreach (array_values($p['lines'] ?? []) as $i => $l) {
            $qty = Qty::toMilli((string) ($l['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException('Enter what is coming back.');
            }
            if ($original) {
                $ol = $original->lines->firstWhere('id', $l['original_line_id'] ?? null) ?? throw new DomainException('That line is not on the receipt.');
                $left = Qty::toMilli(collect($info['lines'])->firstWhere('id', $ol->id)['returnable_qty']);
                if ($qty > $left) {
                    throw new DomainException("{$ol->name}: only ".Qty::format($left)." {$ol->uom_code} can still be returned.", 'over_return');
                }
                $gross = Qty::divRound($ol->line_total_minor * $qty, Qty::toMilli((string) $ol->qty)); // the price actually paid
                $rates = array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'rate_bp' => $b['rate_bp']], $ol->tax_breakdown ?? []);
                // Serial-numbered: the units named, else the first ones sold on that line.
                $serials = $ol->serials ? (SerialRegistry::normalise($l['serials'] ?? []) ?: array_slice($ol->serials, 0, intdiv($qty, 1000))) : null;
                if ($serials && array_diff($serials, $ol->serials)) {
                    throw new DomainException('A serial number given was not sold on that line.', 'serial_conflict');
                }
                $lines[] = ['no' => $i + 1, 'product' => Product::findOrFail($ol->product_id), 'unit' => ProductUom::with('uom')->findOrFail($ol->product_uom_id),
                    'qty' => $qty, 'unit_price' => $ol->unit_price_minor, 'gross' => $gross, 'rates' => $rates, 'original_line_id' => $ol->id, 'serials' => $serials];
            } else {
                $unit = ProductUom::with('uom')->findOrFail($l['product_uom_id'] ?? null);
                $product = Product::with('taxGroup.rates')->findOrFail($unit->product_id);
                $list = PriceList::where('is_default', true)->firstOrFail();
                $price = $this->prices->resolve($list, $product->id, $unit->id, $qty, now())?->amount_minor
                    ?? throw new DomainException("{$product->name} has no price to refund at.");
                $rates = $product->taxGroup->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
                $lines[] = ['no' => $i + 1, 'product' => $product, 'unit' => $unit, 'qty' => $qty, 'unit_price' => $price,
                    'gross' => Qty::divRound($qty * $price, 1000), 'rates' => $rates, 'original_line_id' => null, 'serials' => SerialRegistry::normalise($l['serials'] ?? []) ?: null];
            }
        }
        if ($lines === []) {
            throw new DomainException('Enter what is coming back.');
        }
        $inclusive = $original ? $original->prices_include_tax : PriceList::where('is_default', true)->value('prices_include_tax');
        foreach ($lines as &$l) {
            $l['t'] = TaxCalculator::line($l['gross'], $l['rates'], (bool) $inclusive);
        }
        unset($l);
        $total = array_sum(array_map(fn ($l) => $l['t']['gross'], $lines));

        // --- how the money goes back ---------------------------------------------------------------------------------------
        $approvedBy = null;
        $refunds = [];
        if ($withReceipt) {
            foreach ($p['refunds'] ?? [] as $r) {
                $tender = collect($info['tenders'])->firstWhere('method', $r['method'] ?? null) ?? throw new DomainException('Refunds go back to how the customer paid.');
                $amount = (int) ($r['amount_minor'] ?? 0);
                if ($amount <= 0 || $amount > $tender['refundable_minor']) {
                    throw new DomainException('A refund is more than was paid that way.');
                }
                if (in_array($r['method'], ['momo', 'card'], true) && trim((string) ($r['reference'] ?? '')) === '') {
                    throw new DomainException('Enter the reference of the MoMo/card refund.');
                }
                $refunds[] = ['method' => $r['method'], 'amount_minor' => $amount, 'reference' => in_array($r['method'], ['gift_card', 'store_credit'], true)
                    ? $tender['reference'] : (isset($r['reference']) ? mb_substr(trim((string) $r['reference']), 0, 80) : null), 'provider' => $original->payments->firstWhere('method', $r['method'])?->provider];
            }
            if (array_sum(array_column($refunds, 'amount_minor')) !== $total) {
                throw new DomainException('Refunds must add up to the value of the goods returned.', 'refund_mismatch');
            }
        } else {
            // No receipt, or too late: store credit only, with a supervisor's PIN.
            if (empty($p['approval'])) {
                throw new DomainException($original ? 'This receipt is past the return period: store credit only, a supervisor must approve.'
                    : 'Without a receipt the refund is store credit, and a supervisor must approve.', 'return_needs_approval');
            }
            $approvedBy = $this->approvals->verify($device, $p['approval'], 'pos.return.no_receipt', $till, null, $total, $user->id)->name;
            $refunds = [['method' => 'store_credit', 'amount_minor' => $total, 'reference' => null, 'provider' => null]];
        }
        $customer = $original?->customer_id ? Customer::find($original->customer_id) : (! empty($p['customer_id']) ? Customer::find($p['customer_id']) : null);
        $branch = Branch::findOrFail($till->branch_id);
        $warehouse = Warehouse::findOrFail($till->warehouse_id);
        $receiptNo = sprintf('%s-%s%s-%06d', $branch->code, $till->code, $device->receipt_series, $p['device_seq']);
        $actor = Actor::user($user, 'pos', $device->id);
        $occurredAt = Carbon::parse($p['occurred_at'] ?? now());

        return $this->recorder->transaction(function () use ($p, $device, $till, $shift, $user, $original, $lines, $total, $refunds, $customer, $branch, $warehouse, $receiptNo, $actor, $occurredAt, $reason, $approvedBy, $inclusive) {
            $sale = new Sale;
            $sale->forceFill([
                'id' => $p['id'], 'tenant_id' => $device->tenant_id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'till_id' => $till->id,
                'device_id' => $device->id, 'shift_id' => $shift->id, 'device_seq' => $p['device_seq'], 'receipt_no' => $receiptNo, 'cashier_id' => $user->id,
                'cashier_name' => $user->name, 'price_list_id' => $original?->price_list_id ?? PriceList::where('is_default', true)->value('id'),
                'prices_include_tax' => (bool) $inclusive, 'currency_code' => $original?->currency_code ?? PriceList::where('is_default', true)->value('currency_code'),
                'net_minor' => -array_sum(array_map(fn ($l) => $l['t']['net'], $lines)), 'tax_minor' => -array_sum(array_map(fn ($l) => $l['t']['tax'], $lines)),
                'total_minor' => -$total, 'paid_minor' => -$total, 'change_minor' => 0,
                'tax_breakdown' => array_map(fn ($b) => ['amount_minor' => -$b['amount_minor']] + $b, TaxCalculator::sum(array_map(fn ($l) => $l['t']['breakdown'], $lines))),
                'status' => 'posted', 'payload_hash' => hash('sha256', json_encode($p)), 'offline' => false, 'occurred_at' => $occurredAt, 'received_at' => now(),
                'customer_id' => $customer?->id, 'kind' => 'return', 'original_sale_id' => $original?->id, 'return_reason' => mb_substr($reason, 0, 255),
                'discount_approved_by_name' => $approvedBy,
            ])->save();
            foreach ($lines as $l) {
                SaleLine::create(['sale_id' => $sale->id, 'line_no' => $l['no'], 'product_id' => $l['product']->id, 'sku' => $l['product']->sku, 'name' => $l['product']->name,
                    'product_uom_id' => $l['unit']->id, 'uom_code' => $l['unit']->uom->code, 'factor' => $l['unit']->factor, 'qty' => Qty::format(-$l['qty']),
                    'unit_price_minor' => $l['unit_price'], 'line_total_minor' => -$l['t']['gross'], 'net_minor' => -$l['t']['net'], 'tax_minor' => -$l['t']['tax'],
                    'tax_breakdown' => array_map(fn ($b) => ['amount_minor' => -$b['amount_minor']] + $b, $l['t']['breakdown']), 'original_line_id' => $l['original_line_id'],
                    'serials' => $l['serials']]);
            }
            // Goods come back into the batch they were sold from (batch-tracked products).
            $soldFrom = $original ? DB::table('stock_movements')->where('source_type', 'sale')->where('source_id', $original->id)->whereNotNull('batch_id')
                ->orderByDesc('qty_base')->pluck('batch_id', 'product_id') : collect(); // pluck keeps the last row: the batch most was sold from
            app(ConsignmentService::class)->record($actor, $sale); // consignment stock: the consignor's share comes back
            $this->stock->post($actor, $warehouse, 'sale_return', array_map(fn ($l) => ['product' => $l['product'], 'unit' => $l['unit'], 'qty_milli' => $l['qty'], 'serials' => $l['serials']]
                + (isset($soldFrom[$l['product']->id]) ? ['batch_id' => $soldFrom[$l['product']->id]] : []), $lines),
                "Return {$receiptNo}".($original ? " of {$original->receipt_no}" : ''), 'sale', $sale->id, $occurredAt);

            $storeCredit = null;
            foreach ($refunds as $r) {
                if ($r['method'] === 'account') {
                    $open = (int) (collect($this->receivables->openItems($customer->id))->firstWhere('doc_id', $original->id)?->open_minor ?? 0);
                    $this->receivables->post($actor, $customer, 'pos_return', -$r['amount_minor'], $sale->currency_code, 'sale', $sale->id, $receiptNo, null, $branch->id,
                        $occurredAt, $open > 0 ? [['to_type' => 'sale', 'to_id' => $original->id, 'amount_minor' => min($open, $r['amount_minor'])]] : []);
                } elseif ($r['method'] === 'points') {
                    $this->loyalty->post($actor, $customer, 'refund', $r['amount_minor'], 'sale', $sale->id, $receiptNo, $occurredAt);
                } elseif ($r['method'] === 'gift_card' || ($r['method'] === 'store_credit' && $r['reference'])) {
                    $this->storedValues->refund($actor, $this->storedValues->find($r['reference']), $r['amount_minor'], 'sale', $sale->id, $receiptNo);
                } elseif ($r['method'] === 'store_credit') {
                    $storeCredit = $this->storedValues->issue($actor, 'store_credit', $r['amount_minor'], 'sale', $sale->id, $receiptNo, $customer);
                    $r['reference'] = $storeCredit->code;
                }
                SalePayment::create(['id' => (string) Str::uuid7(), 'sale_id' => $sale->id, 'method' => $r['method'], 'provider' => $r['provider'],
                    'amount_minor' => -$r['amount_minor'], 'tendered_minor' => -$r['amount_minor'], 'reference' => $r['reference'], 'status' => 'refunded',
                    'approved_by_name' => $approvedBy]);
            }
            // Points earned on the goods coming back are taken back (in proportion).
            if ($original && $customer && $original->points_earned > 0 && $original->total_minor > 0) {
                $back = intdiv($original->points_earned * $total, $original->total_minor);
                $this->loyalty->post($actor, $customer, 'reverse', -$back, 'sale', $sale->id, $receiptNo, $occurredAt);
            }
            if (! $original) {
                $this->reviews->add($actor, 'return_no_receipt', 'sale', $sale->id, "{$receiptNo}: goods taken back without a receipt for store credit (approved by {$approvedBy}): {$reason}", [], $branch->id);
            }
            $this->recorder->audit->record($actor, 'sale.returned', $sale, ['receipt_no' => [null, $receiptNo], 'total_minor' => [null, -$total],
                'original' => [null, $original?->receipt_no]], $reason, $branch->id);
            $this->recorder->outbox->publish($actor, $sale, 'sale.returned', ['receipt_no' => $receiptNo]);

            return ['sale' => $sale->load('lines', 'payments'), 'store_credit_code' => $storeCredit?->code];
        });
    }
}

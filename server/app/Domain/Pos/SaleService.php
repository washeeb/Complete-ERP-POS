<?php

namespace App\Domain\Pos;

use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Posts a till sale. The till proposes; the server decides: every price, tax and total is
 * recomputed here and must match what the till showed the customer, to the pesewa.
 * Posting is idempotent on the sale id the till generated — a retry returns the first result.
 */
final class SaleService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly PriceResolver $prices,
        private readonly StockLedger $ledger,
        private readonly ShiftService $shifts,
    ) {}

    /** @return array{sale: Sale, replayed: bool} */
    public function post(Device $device, User $user, array $p): array
    {
        $hash = hash('sha256', json_encode($this->canonical($p)));
        if ($existing = Sale::find($p['id'])) {
            if ($existing->tenant_id !== $device->tenant_id || $existing->payload_hash !== $hash) {
                throw new DomainException('A different sale with this id was already recorded.', 'conflict');
            }

            return ['sale' => $existing->load('lines', 'payments'), 'replayed' => true];
        }

        $till = $device->till_id ? Till::find($device->till_id) : null;
        if (! $till) {
            throw new DomainException('This device is not assigned to a till.', 'no_till');
        }
        if (! $this->authz->allows($user, 'pos.access', $till)) {
            throw new AccessDenied('You are not set up to sell on this till.');
        }
        $shift = $this->shifts->current($device);
        if (! $shift || $shift->id !== ($p['shift_id'] ?? null)) {
            throw new DomainException('Open a shift on this till before selling.', 'no_shift');
        }
        $branch = Branch::findOrFail($till->branch_id);
        $warehouse = Warehouse::findOrFail($till->warehouse_id);
        $list = PriceList::where('is_active', true)->find($p['price_list_id'] ?? null);
        if (! $list || ($list->branch_id && $list->branch_id !== $branch->id) || ! ($list->pos_selectable || $list->is_default)) {
            throw new DomainException('That price list cannot be used on this till.');
        }
        $occurredAt = Carbon::parse($p['occurred_at']);
        if ($occurredAt->isAfter(now()->addMinutes(10))) {
            throw new DomainException('The till clock is ahead of the server. Fix the device time and try again.', 'clock_skew');
        }
        if (! is_int($p['device_seq'] ?? null) || $p['device_seq'] < 1) {
            throw new DomainException('Missing receipt sequence from the till.');
        }

        // --- lines: server-side price, total and tax ---------------------------------
        $lines = [];
        $lineBreakdowns = [];
        $net = $tax = $total = 0;
        foreach (array_values($p['lines'] ?? []) as $i => $l) {
            $product = Product::where('is_active', true)->with('taxGroup.rates')->find($l['product_id'] ?? null)
                ?? throw new DomainException('A product in the cart is no longer for sale.', 'product_unavailable');
            $unit = ProductUom::with('uom')->where('product_id', $product->id)->where('is_sellable', true)->find($l['product_uom_id'] ?? null)
                ?? throw new DomainException("{$product->name}: that unit is not sold.", 'product_unavailable');
            $qty = Qty::toMilli((string) $l['qty']);
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: quantity must be above zero.");
            }
            if ($qty % (10 ** (3 - $unit->uom->decimals)) !== 0) {
                throw new DomainException("{$product->name}: {$unit->uom->name} allows {$unit->uom->decimals} decimal place(s).");
            }
            $price = $this->prices->resolve($list, $product->id, $unit->id, $qty, $occurredAt)
                ?? throw new DomainException("{$product->name} has no {$list->name} price.", 'price_changed');
            if ((int) $l['unit_price_minor'] !== $price->amount_minor) {
                throw new DomainException("The price of {$product->name} changed. Refresh the till and try again.", 'price_changed');
            }
            $lineTotal = Qty::divRound($qty * $price->amount_minor, 1000);
            $rates = $product->taxGroup->rates->where('is_active', true)
                ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
            $t = TaxCalculator::line($lineTotal, $rates, $list->prices_include_tax);
            if ((int) ($l['line_total_minor'] ?? -1) !== $t['gross']) {
                throw new DomainException('Till and server totals differ. Refresh the till and try again.', 'totals_mismatch');
            }
            $lines[] = compact('product', 'unit', 'qty', 'price', 't') + ['no' => $i + 1];
            $lineBreakdowns[] = $t['breakdown'];
            $net += $t['net'];
            $tax += $t['tax'];
            $total += $t['gross'];
        }
        if ($lines === []) {
            throw new DomainException('The cart is empty.');
        }
        $tot = $p['totals'] ?? [];
        if ((int) ($tot['total_minor'] ?? -1) !== $total || (int) ($tot['tax_minor'] ?? -1) !== $tax || (int) ($tot['net_minor'] ?? -1) !== $net) {
            throw new DomainException('Till and server totals differ. Refresh the till and try again.', 'totals_mismatch');
        }

        // --- payments --------------------------------------------------------------
        $paid = $change = 0;
        $payments = [];
        foreach ($p['payments'] ?? [] as $pay) {
            $method = $pay['method'] ?? '';
            if (! array_key_exists($method, SalePayment::METHODS)) {
                throw new DomainException('Unknown payment method.');
            }
            $amount = (int) ($pay['amount_minor'] ?? 0);
            $tendered = (int) ($pay['tendered_minor'] ?? $amount);
            if ($amount <= 0) {
                throw new DomainException('Each payment must be above zero.');
            }
            if ($method === 'cash') {
                if ($tendered < $amount) {
                    throw new DomainException('Cash handed over is less than the amount applied.');
                }
                $change += $tendered - $amount;
            } else {
                $tendered = $amount; // no change on electronic payments
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
                // Manual entry until the Paystack/Hubtel integration verifies payments itself.
                'status' => $method === 'cash' ? 'received' : 'confirmed_by_cashier',
            ];
        }
        if ($paid !== $total) {
            throw new DomainException('Payments must add up exactly to the sale total.', 'payment_mismatch');
        }

        $receiptNo = sprintf('%s-%s-%06d', $branch->code, $till->code, $p['device_seq']);
        $actor = Actor::user($user, 'pos', $device->id);

        try {
            $sale = $this->recorder->transaction(function () use ($p, $device, $till, $branch, $warehouse, $shift, $user, $list, $occurredAt, $hash, $receiptNo, $lines, $lineBreakdowns, $net, $tax, $total, $paid, $change, $payments, $actor) {
                $sale = new Sale;
                $sale->forceFill([
                    'id' => $p['id'], 'tenant_id' => $device->tenant_id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id,
                    'till_id' => $till->id, 'device_id' => $device->id, 'shift_id' => $shift->id, 'device_seq' => $p['device_seq'],
                    'receipt_no' => $receiptNo, 'cashier_id' => $user->id, 'cashier_name' => $user->name, 'price_list_id' => $list->id,
                    'prices_include_tax' => $list->prices_include_tax, 'currency_code' => $list->currency_code,
                    'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total, 'paid_minor' => $paid, 'change_minor' => $change,
                    'tax_breakdown' => TaxCalculator::sum($lineBreakdowns), 'status' => 'posted', 'payload_hash' => $hash,
                    'occurred_at' => $occurredAt, 'received_at' => now(),
                ])->save();

                foreach ($lines as $l) {
                    SaleLine::create([
                        'sale_id' => $sale->id, 'line_no' => $l['no'], 'product_id' => $l['product']->id, 'sku' => $l['product']->sku,
                        'name' => $l['product']->name, 'product_uom_id' => $l['unit']->id, 'uom_code' => $l['unit']->uom->code,
                        'factor' => $l['unit']->factor, 'qty' => Qty::format($l['qty']), 'unit_price_minor' => $l['price']->amount_minor,
                        'line_total_minor' => $l['t']['gross'], 'net_minor' => $l['t']['net'], 'tax_minor' => $l['t']['tax'],
                        'tax_breakdown' => $l['t']['breakdown'],
                    ]);
                }
                foreach ($payments as $pay) {
                    SalePayment::create(['id' => (string) Str::uuid7(), 'sale_id' => $sale->id] + $pay);
                }

                $this->ledger->post($actor, $warehouse, 'sale', array_map(fn ($l) => [
                    'product' => $l['product'], 'unit' => $l['unit'], 'qty_milli' => -$l['qty'],
                ], $lines), "Sale {$receiptNo}", 'sale', $sale->id, $occurredAt);

                $this->recorder->audit->record($actor, 'sale.posted', $sale, [
                    'receipt_no' => [null, $receiptNo], 'total_minor' => [null, $total], 'lines' => [null, count($lines)],
                ], null, $branch->id);
                $this->recorder->outbox->publish($actor, $sale, 'sale.posted', $sale->load('lines', 'payments')->toArray());

                return $sale;
            });
        } catch (UniqueConstraintViolationException) {
            // Raced with itself (double tap / retry while the first request was still running), or a reused sequence.
            $again = Sale::find($p['id']);
            if ($again && $again->payload_hash === $hash) {
                return ['sale' => $again->load('lines', 'payments'), 'replayed' => true];
            }
            throw new DomainException("Receipt number {$receiptNo} is already used on this device.", 'conflict');
        }

        return ['sale' => $sale->load('lines', 'payments'), 'replayed' => false];
    }

    /** Only what defines the sale; key order and number formatting don't matter. */
    private function canonical(array $p): array
    {
        $keep = fn (array $a, array $keys) => array_intersect_key($a, array_flip($keys));
        $out = $keep($p, ['id', 'device_seq', 'occurred_at', 'price_list_id', 'shift_id']);
        $out['lines'] = array_map(fn ($l) => array_map('strval', $keep($l, ['product_id', 'product_uom_id', 'qty', 'unit_price_minor', 'line_total_minor'])), $p['lines'] ?? []);
        $out['payments'] = array_map(fn ($x) => array_map('strval', $keep($x, ['method', 'provider', 'amount_minor', 'tendered_minor', 'reference'])), $p['payments'] ?? []);
        array_walk_recursive($out, fn (&$v) => $v = (string) $v);
        ksort($out);

        return $out;
    }
}

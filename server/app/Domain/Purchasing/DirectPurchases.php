<?php

namespace App\Domain\Purchasing;

use App\Domain\Payables\BillService;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Pricing\PricingRules;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\DirectPurchase;
use App\Models\DirectPurchaseLine;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Models\TaxGroup;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Direct purchases (owner request 2026-11-05): goods that arrive with a supplier's invoice and no purchase order. One
 * document with the invoice's details, batches, expiry dates, prices (with or without tax) and new selling prices;
 * posting makes the usual documents behind it, so stock, the supplier's account, VAT and the money all come out right:
 *
 *  - paid: goods received, a supplier bill and a payment from the chosen account;
 *  - credit: goods received and a bill, due on the date given;
 *  - consignment: goods received at no cost (they stay the supplier's); each sale owes the agreed cost per unit, and
 *    that is billed when settled. A product is either own stock or consigned, so stock of our own refuses it.
 *
 * Small invoices post directly; above Settings → Purchasing they go to a manager, who may not approve their own. A
 * mistake after posting is put right the way a delivery is: a return to the supplier, or a corrected selling price.
 */
final class DirectPurchases
{
    /** A difference with the printed total within this is tax rounding. */
    private const ROUNDING_MINOR = 10;

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers,
        private readonly GoodsReceiptService $receipts, private readonly BillService $bills, private readonly SupplierPaymentService $payments,
        private readonly Settings $settings, private readonly PricingRules $pricing) {}

    /**
     * @param  array{supplier_id: string, warehouse_id: string, invoice_no: string, invoice_date: string, due_on?: ?string, settlement: string, prices_include_tax?: bool,
     *     printed_total?: string|float|null, price_list_id?: ?string, account_id?: ?string, method?: ?string, reference?: ?string, note?: ?string, attachments?: list<string>,
     *     lines: list<array{product_uom_id: string, qty: string|float, unit_price: string|float, tax_group_id?: ?string, batch_no?: ?string, expires_on?: ?string,
     *     supplier_sku?: ?string, sell_uom_id?: ?string, new_price?: string|float|null}>}  $d
     */
    public function save(Actor $actor, DirectPurchase $doc, array $d): DirectPurchase
    {
        $warehouse = Warehouse::find($d['warehouse_id'] ?? null) ?? throw new DomainException('Choose the store.');
        // Waiting for approval, it can still be corrected (owner 2026-11-10: no typing it all again): by whoever made or
        // sent it, or by an approver for the store. It stays waiting; the correction is audited.
        $correcting = $doc->exists && $doc->status === 'submitted';
        if ($correcting) {
            if (! $this->mayCorrect($actor, $doc, $warehouse)) {
                throw new AccessDenied('Only the person who prepared it, or an approver for the store, can correct it while it waits for approval.');
            }
        } else {
            $this->require($actor, 'purchasing.direct', $warehouse);
            if ($doc->exists && $doc->status !== 'draft') {
                throw new DomainException("{$doc->number} is {$this->statusWord($doc)}: it can no longer be changed.");
            }
        }
        $supplier = Supplier::find($d['supplier_id'] ?? null) ?? throw new DomainException('Choose the supplier.');
        $settlement = (string) ($d['settlement'] ?? '');
        if (! array_key_exists($settlement, DirectPurchase::SETTLEMENTS)) {
            throw new DomainException('Choose how this is paid.');
        }
        $invoice = mb_substr(trim((string) ($d['invoice_no'] ?? '')), 0, 60);
        if ($invoice === '') {
            throw new DomainException('Enter the supplier’s invoice number.');
        }
        $date = Carbon::parse($d['invoice_date'] ?? now());
        if ($date->isAfter(now()->endOfDay())) {
            throw new DomainException('An invoice cannot be dated in the future.');
        }
        $this->refuseDuplicate($supplier, $invoice, $doc);
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $inclusive = (bool) ($d['prices_include_tax'] ?? false);
        $account = null;
        if ($settlement === 'paid') {
            $account = Account::find($d['account_id'] ?? null) ?? throw new DomainException('Choose the account it was paid from.');
            if (! array_key_exists((string) ($d['method'] ?? ''), SupplierPayment::METHODS)) {
                throw new DomainException('Choose how the supplier was paid.');
            }
        }
        $list = ($d['price_list_id'] ?? null) ? PriceList::find($d['price_list_id']) : PriceList::where('is_default', true)->first();
        $lines = [];
        foreach (array_values($d['lines'] ?? []) as $i => $l) {
            $lines[] = $this->line($l, $i + 1, $inclusive, $currency);
        }
        if ($lines === []) {
            throw new DomainException('Add the products on the invoice.');
        }
        $printed = isset($d['printed_total']) && $d['printed_total'] !== '' ? Money::toMinor((string) $d['printed_total'], $currency) : null;

        return $this->recorder->transaction(function () use ($actor, $doc, $d, $warehouse, $supplier, $settlement, $invoice, $date, $inclusive, $account, $list, $lines, $printed, $currency, $correcting) {
            $new = ! $doc->exists;
            if ($new) {
                $doc->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'direct_purchase'), 'status' => 'draft',
                    'currency_code' => $currency, 'created_by' => $actor->id, 'created_by_name' => $actor->name]);
            }
            $doc->forceFill(['settlement' => $settlement, 'supplier_id' => $supplier->id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'invoice_no' => $invoice, 'invoice_date' => $date->toDateString(), 'due_on' => $settlement === 'credit' && ! empty($d['due_on']) ? Carbon::parse($d['due_on'])->toDateString() : null,
                'prices_include_tax' => $inclusive, 'price_list_id' => $list?->id, 'printed_total_minor' => $printed, 'account_id' => $account?->id,
                'method' => $settlement === 'paid' ? $d['method'] : null, 'reference' => $settlement === 'paid' ? (mb_substr(trim((string) ($d['reference'] ?? '')), 0, 80) ?: null) : null,
                'attachments' => array_values($d['attachments'] ?? []), 'note' => trim((string) ($d['note'] ?? '')) ?: null,
                'net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')), 'total_minor' => array_sum(array_column($lines, 'total_minor'))]);
            $this->recorder->save($actor, $doc, match (true) {
                $new => 'direct_purchase.created',
                $correcting => 'direct_purchase.corrected',
                default => 'direct_purchase.updated',
            }, $correcting ? "Corrected while waiting for approval ({$invoice} from {$supplier->name})" : "{$invoice} from {$supplier->name}", $warehouse->branch_id);
            DirectPurchaseLine::where('direct_purchase_id', $doc->id)->delete();
            foreach ($lines as $l) {
                (new DirectPurchaseLine)->forceFill($l + ['tenant_id' => $doc->tenant_id, 'direct_purchase_id' => $doc->id])->save();
            }

            return $doc->fresh('lines');
        });
    }

    /** @return array<string, mixed> a line, worked out */
    private function line(array $l, int $no, bool $inclusive, string $currency): array
    {
        $unit = ProductUom::with('uom')->find($l['product_uom_id'] ?? null) ?? throw new DomainException("Line {$no}: choose the product and unit.");
        $product = Product::findOrFail($unit->product_id);
        if ($product->track_serials) {
            throw new DomainException("{$product->name} has serial numbers: receive it on Goods received, where each number is entered.");
        }
        $qty = Qty::toMilli((string) ($l['qty'] ?? '0'));
        if ($qty <= 0) {
            throw new DomainException("{$product->name}: enter the quantity.");
        }
        $price = Money::toMinor((string) ($l['unit_price'] ?? ''), $currency);
        if ($price < 0) {
            throw new DomainException("{$product->name}: the price cannot be negative.");
        }
        $groupId = ($l['tax_group_id'] ?? null) ?: $product->tax_group_id;
        $rates = $groupId ? TaxGroup::with('rates')->find($groupId)?->rates->where('is_active', true)
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [] : [];
        $t = TaxCalculator::line(Qty::divRound($qty * $price, 1000), $rates, $inclusive);
        $batch = trim((string) ($l['batch_no'] ?? ''));
        $expires = ($l['expires_on'] ?? null) ? Carbon::parse($l['expires_on'])->toDateString() : null;
        if ($product->track_batches && ($batch === '' || ! $expires)) {
            throw new DomainException("{$product->name}: enter the batch number and expiry date from the pack.", 'batch_required');
        }
        if ($expires && $expires < now()->toDateString()) {
            throw new DomainException("{$product->name}: batch {$batch} has already expired; do not receive it.", 'expired_stock');
        }
        $newPrice = isset($l['new_price']) && $l['new_price'] !== '' ? Money::toMinor((string) $l['new_price'], $currency) : null;
        if ($newPrice !== null && $newPrice <= 0) {
            throw new DomainException("{$product->name}: enter a selling price above zero, or leave it empty.");
        }
        $sell = ($l['sell_uom_id'] ?? null) ? ProductUom::where('product_id', $product->id)->find($l['sell_uom_id']) : null;

        return ['line_no' => $no, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'qty' => Qty::format($qty), 'unit_price_minor' => $price, 'tax_group_id' => $groupId,
            'unit_cost_minor' => Qty::divRound($t['net'] * 1000, $qty), 'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown'],
            'batch_no' => $batch !== '' ? mb_substr($batch, 0, 60) : null, 'expires_on' => $expires, 'supplier_sku' => mb_substr(trim((string) ($l['supplier_sku'] ?? '')), 0, 60) ?: null,
            'sell_uom_id' => $newPrice !== null ? ($sell?->id ?? $unit->id) : null, 'new_price_minor' => $newPrice];
    }

    /** The same invoice number from the same supplier twice would pay it twice. */
    private function refuseDuplicate(Supplier $supplier, string $invoice, DirectPurchase $doc): void
    {
        $again = DirectPurchase::where('supplier_id', $supplier->id)->whereRaw('lower(invoice_no) = lower(?)', [$invoice])->where('status', '<>', 'cancelled')
            ->when($doc->exists, fn ($q) => $q->whereKeyNot($doc->id))->value('number')
            ?? SupplierBill::where('supplier_id', $supplier->id)->whereRaw('lower(supplier_invoice_no) = lower(?)', [$invoice])->value('number');
        if ($again) {
            throw new DomainException("Invoice {$invoice} from {$supplier->name} is already entered ({$again}).", 'duplicate_invoice');
        }
    }

    public function submit(Actor $actor, DirectPurchase $doc): DirectPurchase
    {
        $this->require($actor, 'purchasing.direct', $doc->warehouse);
        if ($doc->status !== 'draft') {
            throw new DomainException('Only a draft can be sent for approval.');
        }
        $doc->forceFill(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name, 'submitted_at' => now()]);

        return $this->recorder->save($actor, $doc, 'direct_purchase.submitted', $doc->invoice_no, $doc->branch_id);
    }

    public function cancel(Actor $actor, DirectPurchase $doc, string $reason): DirectPurchase
    {
        $this->require($actor, 'purchasing.direct', $doc->warehouse);
        if (! $doc->isOpen() || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'Only a draft or one waiting for approval can be cancelled.');
        }
        $doc->forceFill(['status' => 'cancelled', 'reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $doc, 'direct_purchase.cancelled', $reason, $doc->branch_id);
    }

    /** Above the limit, someone who may approve and did not prepare it. A reason why not, or null. */
    public function whyNotPost(Actor $actor, DirectPurchase $doc): ?string
    {
        $limit = (int) $this->settings->get('purchasing.direct_approval_minor', $doc->tenant_id);
        if ($doc->total_minor <= $limit) {
            return null;
        }
        if ($doc->status === 'draft') {
            return 'Above the limit: send it for approval.';
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, 'purchasing.direct.approve', $doc->warehouse)) {
            return 'A manager must approve this.';
        }
        if (in_array($actor->id, array_filter([$doc->created_by, $doc->submitted_by]), true)) {
            return 'Someone else must approve it: nobody approves their own.';
        }

        return null;
    }

    /** What is worth a second look before posting: list of warnings. */
    public function warnings(DirectPurchase $doc): array
    {
        $out = [];
        if ($doc->printed_total_minor !== null && abs($doc->printed_total_minor - $doc->total_minor) > self::ROUNDING_MINOR) {
            $out[] = 'The lines add up to '.Money::display($doc->total_minor, $doc->currency_code).' but the invoice says '.Money::display($doc->printed_total_minor, $doc->currency_code).'.';
        }
        $months = (int) $this->settings->get('purchasing.short_dated_months', $doc->tenant_id);
        $minMargin = (int) $this->settings->get('purchasing.direct_min_margin_pct', $doc->tenant_id);
        $soon = now()->addMonths($months)->toDateString();
        foreach ($doc->lines()->with('product', 'unit', 'sellUnit')->get() as $l) {
            if ($months > 0 && $l->expires_on && $l->expires_on->toDateString() < $soon) {
                $out[] = "{$l->product->name}: batch {$l->batch_no} expires ".$l->expires_on->format('j M Y').", within {$months} months.";
            }
            if ($l->batch_no && ! $l->product->track_batches) {
                $out[] = "{$l->product->name} does not track batches: batch {$l->batch_no} stays on this document only.";
            }
            $margin = self::margin($l);
            if ($margin !== null && $margin < $minMargin) {
                $out[] = "{$l->product->name}: margin {$margin}% at the new price, below {$minMargin}%.";
            }
        }

        return $out;
    }

    /** Profit as a percentage of the new selling price, before tax on the cost side; null without a new price. */
    public static function margin(DirectPurchaseLine $l): ?int
    {
        if ($l->new_price_minor === null || ! $l->sellUnit || ! $l->unit) {
            return null;
        }
        $cost = $l->unit_cost_minor * (float) $l->sellUnit->factor / (float) $l->unit->factor;

        return (int) round(($l->new_price_minor - $cost) * 100 / $l->new_price_minor);
    }

    public function post(Actor $actor, DirectPurchase $doc): DirectPurchase
    {
        $this->require($actor, 'purchasing.direct', $doc->warehouse);
        if (! $doc->isOpen()) {
            throw new DomainException("{$doc->number} is finished.");
        }
        if ($why = $this->whyNotPost($actor, $doc)) {
            throw new DomainException($why, 'approval_needed');
        }

        return $this->recorder->transaction(function () use ($actor, $doc) {
            $doc = DirectPurchase::with('supplier', 'branch', 'lines.product')->lockForUpdate()->findOrFail($doc->id);
            if (! $doc->isOpen()) {
                throw new DomainException("{$doc->number} is finished.");
            }
            $this->refuseDuplicate($doc->supplier, $doc->invoice_no, $doc);
            $consignment = $doc->settlement === 'consignment';
            if ($consignment) {
                foreach ($doc->lines as $l) {
                    $this->makeConsigned($actor, $doc, $l);
                }
            }
            $grn = $this->receipts->receive($actor, null, ['warehouse_id' => $doc->warehouse_id, 'supplier_id' => $doc->supplier_id, 'supplier_ref' => $doc->invoice_no,
                'note' => "{$doc->number}", 'lines' => $doc->lines->map(fn (DirectPurchaseLine $l) => ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty,
                    'unit_cost_minor' => $consignment ? 0 : $l->unit_cost_minor, 'batch_no' => $l->batch_no, 'expires_on' => $l->expires_on?->toDateString()])->all()], authorised: true);
            $links = ['goods_receipt_id' => $grn->id];
            if (! $consignment) {
                $received = DB::table('goods_receipt_lines')->where('goods_receipt_id', $grn->id)->orderBy('line_no')->pluck('id');
                $bill = $this->bills->create($actor, $doc->supplier, $doc->branch, ['supplier_invoice_no' => $doc->invoice_no, 'bill_date' => $doc->invoice_date->toDateString(),
                    'due_on' => $doc->settlement === 'paid' ? $doc->invoice_date->toDateString() : $doc->due_on?->toDateString(), 'note' => $doc->number,
                    'lines' => $doc->lines->values()->map(fn (DirectPurchaseLine $l, $i) => ['goods_receipt_line_id' => $received[$i], 'qty' => (string) $l->qty,
                        'unit_cost_minor' => $l->unit_cost_minor, 'rates' => $this->rates($l)])->all()], authorised: true);
                $links['supplier_bill_id'] = $bill->id;
                if ($doc->settlement === 'paid') {
                    $links['supplier_payment_id'] = $this->payments->pay($actor, $doc->supplier, $doc->branch, Account::findOrFail($doc->account_id), $doc->method, $bill->total_minor,
                        $doc->reference, $doc->invoice_date->toDateString(), [$bill->id], $doc->number, withhold: false, authorised: true)->id;
                }
            }
            foreach ($doc->lines as $l) {
                $this->remember($doc, $l);
                $this->newPrice($actor, $doc, $l);
            }
            $doc->forceFill($links + ['status' => 'posted', 'posted_by' => $actor->id, 'posted_by_name' => $actor->name, 'posted_at' => now()]);

            return $this->recorder->save($actor, $doc, 'direct_purchase.posted', "{$doc->invoice_no}: {$doc->total_minor}", $doc->branch_id);
        });
    }

    private function rates(DirectPurchaseLine $l): array
    {
        return array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'rate_bp' => (int) $b['rate_bp']], $l->tax_breakdown ?? []);
    }

    /**
     * Consigned stock has no cost of ours, so it cannot share a product with stock that has: the average would be wrong
     * for both. The product is the supplier's from here on, at the agreed cost per base unit.
     */
    private function makeConsigned(Actor $actor, DirectPurchase $doc, DirectPurchaseLine $l): void
    {
        $product = Product::lockForUpdate()->findOrFail($l->product_id);
        if ($product->consignor_id && $product->consignor_id !== $doc->supplier_id) {
            throw new DomainException("{$product->name} is already on consignment from another supplier.");
        }
        $own = DB::table('stock_levels')->where('product_id', $product->id)->where('on_hand', '>', 0)->exists();
        if (! $product->consignor_id && $own) {
            throw new DomainException("{$product->name}: there is stock of your own, and a product is either your own or consigned. Sell it first, or enter this as a purchase.", 'own_stock');
        }
        $unit = ProductUom::findOrFail($l->product_uom_id);
        $product->forceFill(['consignor_id' => $doc->supplier_id, 'consignment_bp' => null, 'consignment_cost_minor' => (int) round($l->unit_cost_minor / (float) $unit->factor)]);
        $this->recorder->save($actor, $product, 'product.consignment_set', "{$doc->number} from {$doc->supplier->name}");
    }

    /** The supplier's code for the item and what it last cost, for the next order or scan. */
    private function remember(DirectPurchase $doc, DirectPurchaseLine $l): void
    {
        $row = SupplierProduct::firstOrNew(['supplier_id' => $doc->supplier_id, 'product_uom_id' => $l->product_uom_id], ['product_id' => $l->product_id]);
        $row->last_cost_minor = $l->unit_cost_minor;
        $row->supplier_sku = $l->supplier_sku ?? $row->supplier_sku;
        $row->save();
    }

    private function newPrice(Actor $actor, DirectPurchase $doc, DirectPurchaseLine $l): void
    {
        if ($l->new_price_minor === null || ! $doc->price_list_id) {
            return;
        }
        $list = PriceList::findOrFail($doc->price_list_id);
        $unit = ProductUom::findOrFail($l->sell_uom_id);
        $price = Price::where('price_list_id', $list->id)->where('product_uom_id', $unit->id)->where('min_qty', 1)->whereNull('starts_at')->whereNull('ends_at')->first();
        if ($price?->amount_minor !== $l->new_price_minor) {
            $this->pricing->setPrice($actor, $l->product, $unit, $list, $price, $l->new_price_minor, "From {$doc->number} ({$doc->invoice_no})", 'price.set_on_purchase', byHand: true);
        } else {
            $price->markSetByHand(); // the same price, chosen again: the rules leave it
            $price->saveQuietly();
        }
    }

    /** Waiting for approval: who may still correct it. */
    public function mayCorrect(Actor $actor, DirectPurchase $doc, ?Warehouse $warehouse = null): bool
    {
        $warehouse ??= $doc->warehouse;
        if (! $actor->user || $doc->status !== 'submitted' || ! $warehouse) {
            return false;
        }
        $own = in_array($actor->id, array_filter([$doc->created_by, $doc->submitted_by]), true) && $this->authz->allows($actor->user, 'purchasing.direct', $warehouse);

        return $own || $this->authz->allows($actor->user, 'purchasing.direct.approve', $warehouse);
    }

    private function statusWord(DirectPurchase $doc): string
    {
        return mb_strtolower(DirectPurchase::STATUSES[$doc->status] ?? $doc->status);
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}

<?php

namespace App\Domain\Purchasing;

use App\Domain\Payables\BillService;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Department;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\TaxGroup;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;

/**
 * Purchase orders: draft → submitted for approval → approved (by someone else, within their limit) → received
 * through goods received notes. Costs are before tax; tax is worked out from each product's tax group.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param array{supplier_id?: string, warehouse_id?: string, expected_on?: ?string, note?: ?string, department_id?: ?string, agreement_id?: ?string,
     *   lines?: list<array{product_uom_id?: ?string, account_id?: ?string, description?: ?string, uom_code?: ?string, qty: string, unit_cost?: ?string,
     *   department_id?: ?string, tax_group_id?: ?string}>} $data a line is a product (product_uom_id) or a service / non-stock item (account_id + description)
     */
    public function saveDraft(Actor $actor, PurchaseOrder $po, array $data): PurchaseOrder
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $po->warehouse_id);
        $this->require($actor, 'purchasing.manage', $warehouse);
        if ($po->exists && $po->status !== 'draft') {
            throw new DomainException('Only a draft purchase order can be changed. Ask the approver to reject it back to draft.');
        }
        $supplier = Supplier::findOrFail($data['supplier_id'] ?? $po->supplier_id);
        if (! $supplier->is_active) {
            throw new DomainException("{$supplier->name} is inactive.");
        }
        app(SupplierOnboarding::class)->assertCanOrder($supplier); // approved, not blocked, documents in date
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $agreementId = array_key_exists('agreement_id', $data) ? ($data['agreement_id'] ?: null) : $po->agreement_id;
        $agreement = $agreementId ? PurchaseAgreement::findOrFail($agreementId) : null;
        if ($agreement && ($agreement->supplier_id !== $supplier->id || ! $agreement->inForce())) {
            throw new DomainException("Agreement {$agreement->number} is not in force with {$supplier->name}.");
        }
        $lines = array_key_exists('lines', $data) ? $this->priceLines($supplier, $data['lines'] ?? [], $currency, $agreement, $po->id) : null;

        return $this->recorder->transaction(function () use ($actor, $po, $data, $warehouse, $supplier, $currency, $lines, $agreement) {
            $isNew = ! $po->exists;
            $po->fill(['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id]
                + array_intersect_key($data, array_flip(['expected_on', 'note', 'department_id'])) + ['agreement_id' => $agreement?->id]);
            if ($isNew) {
                $po->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'purchase_order'),
                    'status' => 'draft', 'currency_code' => $currency, 'created_by' => $actor->id, 'created_by_name' => $actor->name]);
            }
            if ($lines !== null) {
                $po->forceFill(['net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
                    'total_minor' => array_sum(array_column($lines, 'total_minor'))]);
            }
            $this->recorder->save($actor, $po, $isNew ? 'purchase_order.created' : 'purchase_order.updated');
            if ($lines !== null) {
                PurchaseOrderLine::where('purchase_order_id', $po->id)->delete();
                foreach ($lines as $l) {
                    PurchaseOrderLine::create(['purchase_order_id' => $po->id] + $l);
                }
                $this->recorder->audit->record($actor, 'purchase_order.lines_changed', $po, ['lines' => [null, count($lines)], 'total_minor' => [null, $po->total_minor]]);
                $this->recorder->outbox->publish($actor, $po->fresh(), 'purchase_order.lines_changed');
            }

            return $po->fresh('lines');
        });
    }

    /**
     * Order again: a new draft with the same supplier, store and lines. Costs are the supplier's latest known cost
     * (goods received update it), else the old order's; inactive products are left out.
     */
    public function copy(Actor $actor, PurchaseOrder $from): PurchaseOrder
    {
        $currency = $from->currency_code;
        $lines = $from->lines()->get()->filter(fn ($l) => $l->isNonStock() || Product::where('id', $l->product_id)->where('is_active', true)->exists())
            ->map(fn ($l) => $l->isNonStock() ? ['account_id' => $l->account_id, 'description' => $l->name, 'uom_code' => $l->uom_code, 'qty' => (string) $l->qty,
                'unit_cost' => Money::format((int) $l->unit_cost_minor, $currency), 'department_id' => $l->department_id, 'project_id' => $l->project_id] : ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty,
                    'unit_cost' => SupplierProduct::where('supplier_id', $from->supplier_id)->where('product_uom_id', $l->product_uom_id)->whereNotNull('last_cost_minor')->exists()
                        ? null : Money::format((int) $l->unit_cost_minor, $currency)])
            ->values()->all();
        if ($lines === []) {
            throw new DomainException("None of the products on {$from->number} can be ordered any more.");
        }

        return $this->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $from->supplier_id, 'warehouse_id' => $from->warehouse_id,
            'note' => "Ordered again from {$from->number}", 'lines' => $lines]);
    }

    public function submit(Actor $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['draft']);
        if ($po->lines()->count() === 0) {
            throw new DomainException('Add at least one line before submitting.');
        }
        app(SupplierOnboarding::class)->assertCanOrder($po->supplier()->firstOrFail());
        // The budget as it stands now, for the service and non-stock lines: recorded on the order when over.
        $budget = app(BudgetCheck::class);
        $rows = $budget->mode($po->tenant_id) === 'off' ? [] : $budget->check($po->tenant_id, $po->branch_id,
            $po->lines()->get(['account_id', 'net_minor'])->map(fn ($l) => ['account_id' => $l->account_id, 'net_minor' => (int) $l->net_minor])->all(), $po->id);
        $over = collect($rows)->contains('over', true);
        $po->forceFill(['status' => 'pending_approval', 'submitted_at' => now(), 'rejected_reason' => null, 'over_budget' => $over,
            'budget_note' => $over ? mb_substr(BudgetCheck::describe(array_values(array_filter($rows, fn ($r) => $r['over'])), $po->currency_code), 0, 500) : null]);

        return $this->recorder->save($actor, $po, 'purchase_order.submitted', $over ? 'Over budget: '.$po->budget_note : null);
    }

    /** Creator never approves their own order (segregation of duties); the total must be within the approver's limit. */
    public function approve(Actor $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->require($actor, 'purchasing.approve', $po->warehouse);
        $this->expect($po, ['pending_approval']);
        if ($po->created_by && $po->created_by === $actor->id) {
            throw new DomainException('You created this purchase order, so someone else must approve it.', 'own_document');
        }
        app(SupplierOnboarding::class)->assertCanOrder($po->supplier()->firstOrFail()); // documents may have expired since it was sent
        if (! $this->authz->allowsAmount($actor->user, 'purchasing.approve', $po->warehouse, 'po_approve_minor', $po->total_minor)) {
            $limit = $this->authz->limit($actor->user, 'purchasing.approve', $po->warehouse, 'po_approve_minor') ?? 0;
            throw new DomainException('This order ('.Money::display($po->total_minor, $po->currency_code).') is above your approval limit ('
                .Money::display($limit, $po->currency_code).'). Someone with a higher limit must approve it.', 'over_limit');
        }
        if ($po->over_budget && app(BudgetCheck::class)->mode($po->tenant_id) === 'approval' && ! $this->authz->allowsAnywhere($actor->user, 'purchasing.approve_over_budget')) {
            throw new DomainException("This order is over budget ({$po->budget_note}). Someone with \"Approve orders over budget\" must approve it.", 'over_budget');
        }
        $po->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.approved', $po->over_budget ? 'Approved over budget' : null);
    }

    public function reject(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.approve', $po->warehouse);
        $this->expect($po, ['pending_approval']);
        $po->forceFill(['status' => 'draft', 'rejected_reason' => $this->reason($reason), 'submitted_at' => null]);

        return $this->recorder->save($actor, $po, 'purchase_order.rejected', $reason);
    }

    public function cancel(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['draft', 'pending_approval', 'approved']);
        if ($po->receipts()->where('status', '<>', 'reversed')->exists()) {
            throw new DomainException('Goods were already received on this order; close it short instead.');
        }
        $po->forceFill(['status' => 'cancelled', 'close_reason' => $this->reason($reason), 'closed_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.cancelled', $reason);
    }

    /** Nothing more is expected from the supplier: the rest of the order lapses. */
    public function closeShort(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['approved', 'partially_received']);
        $po->forceFill(['status' => 'closed', 'close_reason' => $this->reason($reason), 'closed_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.closed', $reason);
    }

    /**
     * A service done or non-stock items handed over: confirmed on the order (nothing enters stock), so the bill can be
     * matched to it. By the store's receivers or the buyers.
     *
     * @param  array<string, string>  $qtys  order line id => quantity delivered now
     */
    public function confirmDelivered(Actor $actor, PurchaseOrder $po, array $qtys, ?string $note = null): PurchaseOrder
    {
        if (! $actor->user || ! ($this->authz->allows($actor->user, 'stock.receive', $po->warehouse) || $this->authz->allows($actor->user, 'purchasing.manage', $po->warehouse))) {
            throw new AccessDenied;
        }
        $this->expect($po, ['approved', 'partially_received']);
        $lines = $po->lines()->get()->keyBy('id');
        $done = [];
        foreach ($qtys as $id => $q) {
            $qty = Qty::toMilli((string) ($q ?: '0'));
            if ($qty === 0) {
                continue;
            }
            $l = $lines[$id] ?? throw new DomainException('That line is not on this order.');
            if (! $l->isNonStock()) {
                throw new DomainException("{$l->name} is stock: receive it with a goods received note.");
            }
            $open = Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->received_qty);
            if ($qty < 0 || $qty > $open) {
                throw new DomainException("{$l->name}: only ".Qty::format(max(0, $open))." {$l->uom_code} is still to be delivered.");
            }
            $done[$id] = $qty;
        }
        if ($done === []) {
            throw new DomainException('Enter what was delivered.');
        }

        return $this->recorder->transaction(function () use ($actor, $po, $lines, $done, $note) {
            foreach ($done as $id => $qty) {
                $lines[$id]->forceFill(['received_qty' => Qty::format(Qty::toMilli((string) $lines[$id]->received_qty) + $qty)])->save();
            }
            $summary = implode('; ', array_map(fn ($id) => $lines[$id]->name.' '.Qty::format($done[$id]).' '.$lines[$id]->uom_code, array_keys($done)));
            $this->recorder->audit->record($actor, 'purchase_order.delivery_confirmed', $po, ['delivered' => [null, $summary]], $note, $po->branch_id);
            $po->forceFill(['status' => self::statusFromLines($po)]);
            $po->isDirty() ? $this->recorder->save($actor, $po, 'purchase_order.received') : $this->recorder->outbox->publish($actor, $po->fresh(), 'purchase_order.lines_changed');

            return $po->fresh('lines');
        });
    }

    /** Recomputes the receiving status from the lines (called by goods received). */
    public static function statusFromLines(PurchaseOrder $po): string
    {
        $lines = $po->lines()->get();
        $any = $lines->contains(fn ($l) => Qty::toMilli((string) $l->received_qty) > 0);
        $all = $lines->every(fn ($l) => Qty::toMilli((string) $l->received_qty) >= Qty::toMilli((string) $l->qty));

        return $all ? 'received' : ($any ? 'partially_received' : 'approved');
    }

    /** @return list<array<string, mixed>> */
    private function priceLines(Supplier $supplier, array $rows, string $currency, ?PurchaseAgreement $agreement = null, ?string $poId = null): array
    {
        $agreements = app(AgreementService::class);
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            $department = ($r['department_id'] ?? null) ? Department::findOrFail($r['department_id'])->id : null;
            $given = isset($r['unit_cost']) && $r['unit_cost'] !== '' && $r['unit_cost'] !== null ? Money::toMinor((string) $r['unit_cost'], $currency) : null;
            if (empty($r['product_uom_id'])) {
                // A service or non-stock item: an account and a description, never stock.
                $account = Account::find($r['account_id'] ?? null) ?? throw new DomainException('Choose a product, or the account for a service or non-stock line.');
                if ($account->is_header || $account->control || ! $account->is_active || ! in_array($account->type, ['expense', 'asset'], true)) {
                    throw new DomainException("{$account->label()} cannot take an order line: choose an expense (or asset) account.");
                }
                $name = trim((string) ($r['description'] ?? ''));
                if ($name === '') {
                    throw new DomainException('Describe each service or non-stock line (e.g. "Repair of the generator").');
                }
                if ($qty <= 0) {
                    throw new DomainException("{$name}: enter a quantity above zero.");
                }
                $agreed = $agreements->priceFor($supplier, $agreement, null, $account->id, $r['agreement_line_id'] ?? null);
                $cost = $given ?? $agreed?->unit_cost_minor ?? throw new DomainException("{$name}: enter the price.");
                $rates = ! empty($r['tax_group_id']) ? TaxGroup::with('rates')->findOrFail($r['tax_group_id'])->rates->where('is_active', true)
                    ->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'rate_bp' => $x->rate_bp])->values()->all() : [];
                $base = ['product_id' => null, 'product_uom_id' => null, 'sku' => null, 'name' => mb_substr($name, 0, 160),
                    'uom_code' => mb_substr(trim((string) ($r['uom_code'] ?? '')) ?: 'EA', 0, 10), 'factor' => null, 'account_id' => $account->id];
            } else {
                $unit = ProductUom::with('uom')->find($r['product_uom_id']) ?? throw new DomainException('Choose a product and unit on every line.');
                $product = Product::with('taxGroup.rates')->findOrFail($unit->product_id);
                if ($qty <= 0) {
                    throw new DomainException("{$product->name}: enter a quantity above zero.");
                }
                if (! $unit->is_purchasable) {
                    throw new DomainException("{$product->name} is not bought in {$unit->uom->code}: choose its buying unit (e.g. the box).");
                }
                // The supplier's pack rules: at least their minimum, in their steps (e.g. 5 boxes at a time).
                $sp = SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $unit->id)->first();
                $min = $sp ? Qty::toMilli((string) $sp->min_order_qty) : 0;
                $step = $sp ? Qty::toMilli((string) $sp->order_multiple) : 0;
                if ($min > 0 && $qty < $min) {
                    throw new DomainException("{$product->name}: {$supplier->name} sells at least ".Qty::format($min)." {$unit->uom->code}.");
                }
                if ($step > 0 && $qty % $step !== 0) {
                    throw new DomainException("{$product->name}: {$supplier->name} sells in multiples of ".Qty::format($step)." {$unit->uom->code} (e.g. ".Qty::format((intdiv($qty, $step) + 1) * $step).').');
                }
                $agreed = $agreements->priceFor($supplier, $agreement, $unit->id, null, $r['agreement_line_id'] ?? null);
                $cost = $given ?? $agreed?->unit_cost_minor ?? SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $unit->id)->value('last_cost_minor');
                if ($cost === null) {
                    throw new DomainException("{$product->name}: enter the cost (no previous cost from {$supplier->name}).");
                }
                $name = $product->name;
                $rates = $product->taxGroup?->rates->where('is_active', true)->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'rate_bp' => $x->rate_bp])->values()->all() ?? [];
                $base = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                    'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'account_id' => null];
            }
            if ($cost < 0) {
                throw new DomainException('Costs cannot be negative.');
            }
            if ($agreement && ! $agreed) {
                throw new DomainException("{$name} is not on agreement {$agreement->number}.");
            }
            if ($agreed && $cost > $agreed->unit_cost_minor) { // the contract price is the most that is paid
                throw new DomainException("{$name}: the agreed price is ".Money::display($agreed->unit_cost_minor, $currency).' under '
                    .PurchaseAgreement::whereKey($agreed->purchase_agreement_id)->value('number').'; order at that price or less.');
            }
            $net = Qty::divRound($qty * $cost, 1000);
            $t = TaxCalculator::line($net, $rates, false);
            $out[] = ['line_no' => $i + 1] + $base + ['qty' => Qty::format($qty), 'received_qty' => '0.000', 'unit_cost_minor' => $cost,
                'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown'],
                'department_id' => $department, 'agreement_line_id' => $agreed?->id,
                'project_id' => empty($r['product_uom_id']) ? BillService::openProject($r['project_id'] ?? null) : null];
        }
        if ($out === []) {
            throw new DomainException('Add at least one line.');
        }
        $agreements->assertWithinCaps($out, $poId);

        return $out;
    }

    private function expect(PurchaseOrder $po, array $statuses): void
    {
        if (! in_array($po->status, $statuses, true)) {
            throw new DomainException("Purchase order {$po->number} is ".(PurchaseOrder::STATUSES[$po->status] ?? $po->status).'; that is not possible now.', 'wrong_status');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give a reason.');
        }

        return mb_substr($reason, 0, 255);
    }

    private function require(Actor $actor, string $permission, $target): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $target)) {
            throw new AccessDenied;
        }
    }
}

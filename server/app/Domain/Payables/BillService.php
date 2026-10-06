<?php

namespace App\Domain\Payables;

use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\TaxGroup;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills with a 3-way match (owner decision 2026-10-12): each stock line is matched to goods received
 * (never more than was received and not yet billed) and priced against the purchase order. When the bill differs
 * from the order prices by more than the tolerance it waits for someone with payables.approve (never its creator).
 * Lines without goods (rent, electricity) are charged to an expense account.
 */
final class BillService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly SupplierLedger $ledger,
        private readonly Settings $settings,
    ) {}

    /**
     * What can still be billed on a goods received note.
     *
     * @return list<array{goods_receipt_line_id: string, name: string, sku: string, uom: string, open_qty: string, grn_cost_minor: int, order_cost_minor: int, rates: list<array>}>
     */
    public function billable(GoodsReceipt $grn): array
    {
        if ($grn->status !== 'posted') {
            return [];
        }
        $out = [];
        foreach (DB::table('goods_receipt_lines')->where('goods_receipt_id', $grn->id)->orderBy('line_no')->get() as $l) {
            $open = Qty::toMilli((string) $l->qty) - $this->billedMilli($l->id);
            if ($open <= 0) {
                continue;
            }
            $po = $l->purchase_order_line_id ? DB::table('purchase_order_lines')->where('id', $l->purchase_order_line_id)->first() : null;
            $rates = $po ? array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'rate_bp' => (int) $b['rate_bp']], json_decode($po->tax_breakdown, true) ?? [])
                : $this->productRates($l->product_id);
            $out[] = ['goods_receipt_line_id' => $l->id, 'name' => $l->name, 'sku' => $l->sku, 'uom' => $l->uom_code, 'open_qty' => Qty::format($open),
                'grn_cost_minor' => (int) $l->unit_cost_minor, 'order_cost_minor' => (int) ($po->unit_cost_minor ?? $l->unit_cost_minor), 'rates' => $rates];
        }

        return $out;
    }

    /**
     * Enter a supplier's bill.
     *
     * @param  array{supplier_invoice_no: string, bill_date: string, due_on?: ?string, note?: ?string, lines: list<array>}  $data
     *                                                                                                                             stock line: {goods_receipt_line_id, qty, unit_cost_minor}; expense line: {account_id, description, amount_minor, tax_group_id?}
     */
    public function create(Actor $actor, Supplier $supplier, Branch $branch, array $data): SupplierBill
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payables.manage', $branch)) {
            throw new AccessDenied;
        }
        $invoiceNo = trim((string) ($data['supplier_invoice_no'] ?? ''));
        if ($invoiceNo === '') {
            throw new DomainException('Enter the supplier’s invoice number.');
        }
        $day = Carbon::parse($data['bill_date'] ?? now()->toDateString());
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('A bill cannot be dated in the future.');
        }
        $due = ! empty($data['due_on']) ? Carbon::parse($data['due_on']) : $day->copy()->addDays((int) $supplier->payment_terms_days);
        if ($due->lt($day)) {
            throw new DomainException('The due date is before the bill date.');
        }
        if (empty($data['lines'])) {
            throw new DomainException('Add at least one line.');
        }

        return $this->recorder->transaction(function () use ($actor, $supplier, $branch, $data, $invoiceNo, $day, $due) {
            if (SupplierBill::where('supplier_id', $supplier->id)->where('supplier_invoice_no', $invoiceNo)->exists()) {
                throw new DomainException("Invoice {$invoiceNo} from {$supplier->name} is already entered.");
            }
            $lines = [];
            $variance = 0;
            $orderValue = 0;
            foreach (array_values($data['lines']) as $i => $in) {
                if (! empty($in['goods_receipt_line_id'])) {
                    $l = DB::table('goods_receipt_lines')->where('id', $in['goods_receipt_line_id'])->lockForUpdate()->first()
                        ?? throw new DomainException('That goods received line does not exist.');
                    $grn = GoodsReceipt::findOrFail($l->goods_receipt_id);
                    if ($grn->supplier_id !== $supplier->id || $grn->status !== 'posted') {
                        throw new DomainException("{$grn->number} is not a posted delivery from {$supplier->name}.");
                    }
                    $qty = Qty::toMilli((string) $in['qty']);
                    $open = Qty::toMilli((string) $l->qty) - $this->billedMilli($l->id);
                    if ($qty <= 0 || $qty > $open) {
                        throw new DomainException("{$l->name}: only ".Qty::format(max(0, $open))." {$l->uom_code} on {$grn->number} can still be billed.");
                    }
                    $cost = (int) $in['unit_cost_minor'];
                    if ($cost < 0) {
                        throw new DomainException("{$l->name}: the price cannot be negative.");
                    }
                    $line = collect($this->billable($grn))->firstWhere('goods_receipt_line_id', $l->id);
                    $net = Qty::divRound($qty * $cost, 1000);
                    $ordered = Qty::divRound($qty * $line['order_cost_minor'], 1000);
                    $t = TaxCalculator::line($net, $line['rates'], false);
                    $variance += $net - $ordered;
                    $orderValue += $ordered;
                    $lines[] = ['line_no' => $i + 1, 'goods_receipt_line_id' => $l->id, 'product_id' => $l->product_id, 'description' => "{$grn->number} {$l->name}",
                        'qty' => Qty::format($qty), 'unit_cost_minor' => $cost, 'order_cost_minor' => $line['order_cost_minor'],
                        'received_value_minor' => Qty::divRound($qty * (int) $l->unit_cost_minor, 1000), 'net_minor' => $t['net'], 'tax_minor' => $t['tax'],
                        'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
                } else {
                    $account = Account::find($in['account_id'] ?? null) ?? throw new DomainException('Choose the account for each expense line.');
                    if ($account->is_header || $account->control || ! $account->is_active || ! in_array($account->type, ['expense', 'asset'], true)) {
                        throw new DomainException("{$account->label()} cannot take a bill line: choose an expense (or asset) account.");
                    }
                    $amount = (int) ($in['amount_minor'] ?? 0);
                    if ($amount <= 0) {
                        throw new DomainException('Enter the amount (before tax) for each expense line.');
                    }
                    $rates = ! empty($in['tax_group_id']) ? TaxGroup::with('rates')->findOrFail($in['tax_group_id'])->rates->where('is_active', true)
                        ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() : [];
                    $t = TaxCalculator::line($amount, $rates, false);
                    $lines[] = ['line_no' => $i + 1, 'account_id' => $account->id, 'description' => mb_substr(trim((string) ($in['description'] ?? '')) ?: $account->name, 0, 255),
                        'qty' => '1.000', 'unit_cost_minor' => $amount, 'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'],
                        'tax_breakdown' => $t['breakdown']];
                }
            }

            $pct = (int) $this->settings->get('payables.match_tolerance_pct', $supplier->tenant_id);
            $fixed = (int) $this->settings->get('payables.match_tolerance_minor', $supplier->tenant_id);
            $allowed = min(Qty::divRound($orderValue * $pct, 100), $fixed);
            $needsApproval = abs($variance) > $allowed;

            $bill = new SupplierBill;
            $bill->forceFill(['tenant_id' => $supplier->tenant_id, 'number' => $this->numbers->next($supplier->tenant_id, 'supplier_bill'), 'supplier_id' => $supplier->id,
                'branch_id' => $branch->id, 'supplier_invoice_no' => mb_substr($invoiceNo, 0, 60), 'bill_date' => $day->toDateString(), 'due_on' => $due->toDateString(),
                'currency_code' => Tenant::find($supplier->tenant_id)->currency_code, 'net_minor' => array_sum(array_column($lines, 'net_minor')),
                'tax_minor' => array_sum(array_column($lines, 'tax_minor')), 'total_minor' => array_sum(array_column($lines, 'total_minor')),
                'tax_breakdown' => TaxCalculator::sum(array_column($lines, 'tax_breakdown')), 'variance_minor' => $variance,
                'status' => $needsApproval ? 'pending_approval' : 'posted', 'created_by_name' => $actor->name, 'created_by' => $actor->id,
                'note' => $data['note'] ?? null]);
            $this->recorder->save($actor, $bill, $needsApproval ? 'supplier_bill.held' : 'supplier_bill.entered', branchId: $branch->id);
            foreach ($lines as $l) {
                SupplierBillLine::create($l + ['supplier_bill_id' => $bill->id]);
            }
            if (! $needsApproval) {
                $this->post($actor, $bill);
            }

            return $bill->fresh('lines');
        });
    }

    /** Accept a bill whose prices differ from the order by more than the tolerance. */
    public function approve(Actor $actor, SupplierBill $bill, ?string $reason = null): SupplierBill
    {
        $this->requireApprover($actor, $bill);

        return $this->recorder->transaction(function () use ($actor, $bill, $reason) {
            $bill = SupplierBill::lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== 'pending_approval') {
                throw new DomainException('This bill is not waiting for approval.');
            }
            $bill->forceFill(['approved_by_name' => $actor->name, 'variance_reason' => $reason ? mb_substr(trim($reason), 0, 255) : null]);
            $this->post($actor, $bill);

            return $bill;
        });
    }

    /** Send a bill back (the supplier must correct it); its quantities can be billed again. */
    public function reject(Actor $actor, SupplierBill $bill, string $reason): SupplierBill
    {
        $this->requireApprover($actor, $bill);
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $bill, $reason) {
            $bill = SupplierBill::lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== 'pending_approval') {
                throw new DomainException('This bill is not waiting for approval.');
            }
            $bill->forceFill(['status' => 'rejected', 'approved_by_name' => $actor->name, 'variance_reason' => mb_substr(trim($reason), 0, 255)]);

            return $this->recorder->save($actor, $bill, 'supplier_bill.rejected', $reason, $bill->branch_id);
        });
    }

    private function post(Actor $actor, SupplierBill $bill): void
    {
        $bill->forceFill(['status' => 'posted', 'posted_at' => now()]);
        $this->recorder->save($actor, $bill, 'supplier_bill.posted', branchId: $bill->branch_id);
        $this->ledger->post($actor, Supplier::findOrFail($bill->supplier_id), 'bill', $bill->total_minor, $bill->currency_code, 'supplier_bill', $bill->id,
            $bill->number, $bill->due_on, $bill->branch_id, $bill->bill_date->copy()->setTimeFrom(now()));
    }

    private function requireApprover(Actor $actor, SupplierBill $bill): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payables.approve', Branch::findOrFail($bill->branch_id))) {
            throw new AccessDenied;
        }
        if ($bill->created_by === $actor->id) {
            throw new DomainException('Someone else must approve a bill you entered.', 'own_document');
        }
    }

    private function billedMilli(string $grnLineId): int
    {
        return Qty::toMilli((string) DB::table('supplier_bill_lines')->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_bill_lines.supplier_bill_id')
            ->where('goods_receipt_line_id', $grnLineId)->where('supplier_bills.status', '<>', 'rejected')->sum('supplier_bill_lines.qty'));
    }

    private function productRates(string $productId): array
    {
        $group = DB::table('products')->where('id', $productId)->value('tax_group_id');

        return $group ? TaxGroup::with('rates')->find($group)?->rates->where('is_active', true)
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [] : [];
    }
}

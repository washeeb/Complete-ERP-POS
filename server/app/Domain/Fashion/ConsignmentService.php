<?php

namespace App\Domain\Fashion;

use App\Domain\Payables\BillService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ConsignmentSale;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consignment (Fashion, owner default 2026-10-19): stock that belongs to a supplier (the consignor) comes in at no cost;
 * each sale records the consignor's share of the price before tax (a return takes it back). Settling a period raises
 * one supplier bill for the shares not yet settled — booked to cost of sales and paid through Payables as usual.
 */
final class ConsignmentService
{
    public function __construct(private readonly Recorder $recorder, private readonly BillService $bills) {}

    /** Inside the sale's (or return's) transaction: what each consignment line owes its consignor. */
    public function record(Actor $actor, Sale $sale): void
    {
        $lines = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->whereNotNull('p.consignor_id')
            ->where('l.sale_id', $sale->id)->get(['l.id', 'l.product_id', 'l.qty', 'l.net_minor', 'p.consignor_id', 'p.consignment_bp']);
        foreach ($lines as $l) {
            $row = (new ConsignmentSale)->forceFill(['id' => (string) Str::uuid7(), 'tenant_id' => $sale->tenant_id, 'branch_id' => $sale->branch_id, 'sale_id' => $sale->id,
                'sale_line_id' => $l->id, 'product_id' => $l->product_id, 'consignor_id' => $l->consignor_id, 'qty' => $l->qty, 'net_minor' => (int) $l->net_minor,
                'share_minor' => Qty::divRound((int) $l->net_minor * (int) $l->consignment_bp, 10000), 'occurred_at' => $sale->occurred_at, 'created_at' => now()]);
            $row->save();
            $this->recorder->outbox->publish($actor, $row, 'consignment.sold');
        }
    }

    /** What is owed to each consignor (not yet settled) at a branch, up to a date. */
    public static function owed(?string $branchId = null, ?string $upTo = null)
    {
        return DB::table('consignment_sales as c')->join('suppliers as s', 's.id', '=', 'c.consignor_id')->whereNull('c.supplier_bill_id')
            ->when($branchId, fn ($q) => $q->where('c.branch_id', $branchId))->when($upTo, fn ($q) => $q->where('c.occurred_at', '<', Carbon::parse($upTo)->addDay()->startOfDay()))
            ->groupBy('c.consignor_id', 's.name', 'c.branch_id')->orderBy('s.name')
            ->selectRaw('c.consignor_id, s.name, c.branch_id, SUM(c.qty) AS qty, SUM(c.net_minor) AS sold_minor, SUM(c.share_minor) AS owed_minor')->get();
    }

    /** Settle a consignor's shares at a branch up to a date: one supplier bill (cost of sales), the rows marked settled. */
    public function settle(Actor $actor, Supplier $consignor, Branch $branch, string $upTo, string $statementNo): SupplierBill
    {
        $rows = ConsignmentSale::where('consignor_id', $consignor->id)->where('branch_id', $branch->id)->whereNull('supplier_bill_id')
            ->where('occurred_at', '<', Carbon::parse($upTo)->addDay()->startOfDay())->get();
        $total = (int) $rows->sum('share_minor');
        if ($rows->isEmpty() || $total <= 0) {
            throw new DomainException("Nothing is owed to {$consignor->name} at {$branch->code} for that period.");
        }
        $cogs = Account::where('system_key', 'cogs')->firstOrFail();

        return $this->recorder->transaction(function () use ($actor, $consignor, $branch, $upTo, $statementNo, $rows, $total, $cogs) {
            $bill = $this->bills->create($actor, $consignor, $branch, ['supplier_invoice_no' => mb_substr(trim($statementNo), 0, 60), 'bill_date' => now()->toDateString(),
                'note' => "Consignment sales to {$upTo}", 'lines' => [['account_id' => $cogs->id, 'amount_minor' => $total,
                    'description' => sprintf('Consignment: %s item(s) sold to %s', Qty::format(Qty::toMilli((string) $rows->sum('qty'))), $upTo)]]]);
            ConsignmentSale::whereIn('id', $rows->pluck('id'))->update(['supplier_bill_id' => $bill->id]);
            $this->recorder->audit->record($actor, 'consignment.settled', $bill, ['lines' => [null, $rows->count()], 'owed_minor' => [null, $total]], null, $branch->id);

            return $bill;
        });
    }

    /** Products a consignor owns (for statements of stock held for them). */
    public static function stockHeld(string $consignorId)
    {
        return DB::table('stock_levels as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('p.consignor_id', $consignorId)->where('l.on_hand', '<>', 0)
            ->orderBy('p.name')->get(['p.sku', 'p.name', 'l.warehouse_id', 'l.on_hand']);
    }
}

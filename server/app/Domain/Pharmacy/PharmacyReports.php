<?php

namespace App\Domain\Pharmacy;

use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * The controlled-drug register and the NHIS claims list. Both are read from records that are already append-only
 * (stock ledger, posted sales, prescriptions), so they cannot disagree with the stock or the till.
 */
final class PharmacyReports
{
    /**
     * Every movement of controlled medicines in a warehouse over a period, per product with a running balance,
     * and for sales the patient, prescriber and pharmacist.
     *
     * @return list<array{product: object, opening: string, closing: string, rows: list<object>}>
     */
    public static function controlledRegister(string $warehouseId, string $from, string $to): array
    {
        $products = DB::table('products')->where('drug_schedule', 'controlled')->orderBy('name')->get(['id', 'sku', 'name', 'strength', 'dosage_form', 'fda_reg_no']);
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59.999999';
        $out = [];
        foreach ($products as $p) {
            $opening = Qty::toMilli(number_format((float) DB::table('stock_movements')->where('warehouse_id', $warehouseId)->where('product_id', $p->id)
                ->where('occurred_at', '<', $start)->sum('qty_base'), 3, '.', ''));
            $rows = DB::table('stock_movements as m')
                ->leftJoin('stock_batches as b', 'b.id', '=', 'm.batch_id')
                ->leftJoin('sales as s', fn ($j) => $j->on('s.id', '=', 'm.source_id')->where('m.source_type', '=', 'sale'))
                ->leftJoin('prescriptions as rx', 'rx.sale_id', '=', 's.id')
                ->leftJoin('customers as c', 'c.id', '=', 'rx.customer_id')
                ->where('m.warehouse_id', $warehouseId)->where('m.product_id', $p->id)->whereBetween('m.occurred_at', [$start, $end])
                ->orderBy('m.occurred_at')->orderBy('m.id')
                ->get(['m.occurred_at', 'm.type', 'm.reason', 'm.qty_base', 'm.actor_name', 'b.batch_no', 's.receipt_no', 'rx.number as rx_number',
                    'rx.prescriber_name', 'rx.prescriber_reg_no', 'rx.pharmacist_name', 'c.name as patient']);
            $balance = $opening;
            foreach ($rows as $r) {
                $balance += Qty::toMilli((string) $r->qty_base);
                $r->balance = Qty::format($balance);
            }
            if ($opening !== 0 || $rows->isNotEmpty()) {
                $out[] = ['product' => $p, 'opening' => Qty::format($opening), 'closing' => Qty::format($balance), 'rows' => $rows->all()];
            }
        }

        return $out;
    }

    /** Prescription lines dispensed to NHIS members over a period (one row per medicine), for the claims return. */
    public static function nhisClaims(?array $branchIds, string $from, string $to)
    {
        return DB::table('prescriptions as rx')
            ->join('patient_profiles as pp', 'pp.customer_id', '=', 'rx.customer_id')
            ->join('customers as c', 'c.id', '=', 'rx.customer_id')
            ->join('sales as s', 's.id', '=', 'rx.sale_id')
            ->join('sale_lines as l', 'l.prescription_id', '=', 'rx.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereNotNull('pp.nhis_no')->whereBetween('s.occurred_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('rx.branch_id', $branchIds))
            ->orderBy('s.occurred_at')->orderBy('l.line_no')
            ->get(['rx.number', 's.occurred_at', 's.receipt_no', 'c.name as patient', 'pp.nhis_no', 'pp.nhis_expires_on', 'rx.prescriber_name', 'rx.prescriber_reg_no',
                'rx.facility', 'rx.reference', 'rx.pharmacist_name', 'p.name as medicine', 'p.generic_name', 'p.strength', 'p.fda_reg_no', 'l.qty', 'l.uom_code',
                'l.line_total_minor']);
    }
}

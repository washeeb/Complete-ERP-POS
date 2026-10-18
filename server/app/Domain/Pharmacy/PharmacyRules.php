<?php

namespace App\Domain\Pharmacy;

use App\Models\Branch;
use App\Support\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How strictly prescription-only (POM) and controlled medicines are sold in a branch (Settings → Pharmacy, a branch may
 * differ). The strictest level among a sale's medicines applies; a line above the quantity limit, or a major allergy or
 * interaction warning, needs a pharmacist however relaxed the branch is. The till works this out the same way
 * (pos/src/pharmacy.ts saleLevel) so it asks only for what the server will check.
 */
final class PharmacyRules
{
    public const LEVELS = ['approve' => 'Pharmacist approves, with the prescription (recommended)', 'details' => 'Prescription details only (the teller sells)',
        'patient' => 'Patient only (the teller sells)', 'free' => 'Sell freely'];

    private const ORDER = ['free' => 0, 'patient' => 1, 'details' => 2, 'approve' => 3];

    public function __construct(private readonly Settings $settings) {}

    /** @return array{pom: string, controlled: string, max_qty: int, major_needs_pharmacist: bool, after: string} max_qty in base units (thousandths), 0 = none */
    public function forBranch(Branch $branch): array
    {
        $get = fn (string $key) => $this->settings->forBranch("pharmacy.{$key}", $branch, $branch->tenant_id);
        $controlled = $get('controlled_always_strict') ? 'approve' : (string) $get('controlled_rule');

        return ['pom' => (string) $get('pom_rule'), 'controlled' => $controlled, 'max_qty' => 1000 * (int) $get('max_qty_without_pharmacist'),
            'major_needs_pharmacist' => (bool) $get('major_warning_needs_pharmacist'), 'after' => (string) $get('after_sale_check')];
    }

    /**
     * What a sale needs: 'approve' | 'details' | 'patient' | 'free' (null: no prescription-only or controlled medicine in it).
     *
     * @param  list<array{schedule: ?string, qty_base: int}>  $lines  qty in base units (thousandths)
     */
    public static function saleLevel(array $rules, array $lines, bool $majorWarning): ?string
    {
        $level = null;
        foreach ($lines as $l) {
            if (! in_array($l['schedule'], ['pom', 'controlled'], true)) {
                continue;
            }
            $lineLevel = $rules[$l['schedule']] ?? 'approve';
            if ($rules['max_qty'] > 0 && abs($l['qty_base']) > $rules['max_qty']) {
                $lineLevel = 'approve';
            }
            $level = $level === null || self::ORDER[$lineLevel] > self::ORDER[$level] ? $lineLevel : $level;
        }
        if ($level !== null && $majorWarning && $rules['major_needs_pharmacist']) {
            $level = 'approve';
        }

        return $level;
    }

    /**
     * Posted sales with a prescription-only or controlled medicine and no pharmacist's approval, one row per sale.
     *
     * @param  list<string>|null  $branchIds  null = every branch
     */
    public static function unapproved(string $tenantId, ?array $branchIds, string $from, string $to): Builder
    {
        return DB::table('sales as s')->join('sale_lines as l', 'l.sale_id', '=', 's.id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('prescriptions as rx', 'rx.id', '=', 'l.prescription_id')
            ->leftJoin('pharmacy_signoffs as so', 'so.sale_id', '=', 's.id')
            ->where('s.tenant_id', $tenantId)->where('s.status', 'posted')->whereIn('p.drug_schedule', ['pom', 'controlled'])->whereNull('rx.pharmacist_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('s.branch_id', $branchIds))
            ->whereBetween('s.occurred_at', [$from, $to])
            ->groupBy('s.id', 's.receipt_no', 's.occurred_at', 's.branch_id', 's.cashier_name', 'so.signed_by_name', 'so.signed_at', 'so.note')
            ->selectRaw("s.id, s.receipt_no, s.occurred_at, s.branch_id, s.cashier_name, so.signed_by_name, so.signed_at, so.note,
                string_agg(DISTINCT p.name || CASE WHEN p.drug_schedule = 'controlled' THEN ' (controlled)' ELSE '' END, ', ') AS medicines,
                bool_or(p.drug_schedule = 'controlled') AS controlled");
    }
}

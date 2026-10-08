<?php

namespace App\Domain\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Human document numbers, one gap-free sequence per node and document type (spec §3.2): "PO-HQ-000123",
 * "GRN-B01-000007". Taken inside the document's transaction, so a failed save never burns a number.
 */
final class DocumentNumbers
{
    public const PREFIXES = ['purchase_order' => 'PO', 'goods_receipt' => 'GRN', 'stock_transfer' => 'TRF', 'stock_count' => 'CNT',
        'customer' => 'CUS', 'quotation' => 'QT', 'customer_order' => 'SO', 'delivery' => 'DN', 'invoice' => 'INV', 'credit_note' => 'CRN', 'customer_payment' => 'RCT',
        'journal' => 'JE', 'supplier_bill' => 'BILL', 'supplier_payment' => 'SPAY',
        'money_transfer' => 'MT', 'expense_claim' => 'EXP', 'fixed_asset' => 'FA',
        'employee' => 'EMP', 'employee_loan' => 'LN', 'payroll_run' => 'PAYR',
        'prescription' => 'RX', 'dining_order' => 'ORD', 'reservation' => 'RES', 'repair_job' => 'JOB', 'alteration' => 'ALT',
        'appointment' => 'APT', 'service_pass' => 'PASS', 'membership' => 'MEM',
        'stock_adjustment' => 'ADJ', 'van_settlement' => 'VS',
        'work_order' => 'WO', 'qc_inspection' => 'QC'];

    public function next(string $tenantId, string $docType): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers are taken inside the document transaction.');
        }
        $prefix = self::PREFIXES[$docType] ?? throw new LogicException("No numbering for {$docType}.");
        $node = (string) config('erp.node.id');
        DB::table('document_sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'node' => $node, 'doc_type' => $prefix, 'last_value' => 0]);
        $value = DB::selectOne('UPDATE document_sequences SET last_value = last_value + 1 WHERE tenant_id = ? AND node = ? AND doc_type = ? RETURNING last_value',
            [$tenantId, $node, $prefix])->last_value;

        return sprintf('%s-%s-%06d', $prefix, $node, $value);
    }
}

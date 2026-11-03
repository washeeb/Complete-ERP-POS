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
        'customer' => 'CUS', 'supplier' => 'SUP', 'quotation' => 'QT', 'customer_order' => 'SO', 'delivery' => 'DN', 'invoice' => 'INV', 'credit_note' => 'CRN', 'customer_payment' => 'RCT',
        'journal' => 'JE', 'nhis_claim' => 'NHC', 'supplier_bill' => 'BILL', 'supplier_payment' => 'SPAY', 'wht_certificate' => 'WHT', 'landed_cost' => 'LC', 'rfq' => 'RFQ', 'purchase_agreement' => 'AGR',
        'money_transfer' => 'MT', 'expense_claim' => 'EXP', 'fixed_asset' => 'FA',
        'employee' => 'EMP', 'employee_loan' => 'LN', 'payroll_run' => 'PAYR',
        'prescription' => 'RX', 'dining_order' => 'ORD', 'reservation' => 'RES', 'repair_job' => 'JOB', 'alteration' => 'ALT',
        'appointment' => 'APT', 'service_pass' => 'PASS', 'membership' => 'MEM',
        'stock_adjustment' => 'ADJ', 'supplier_return' => 'RTS', 'waybill' => 'WB', 'van_settlement' => 'VS',
        'work_order' => 'WO', 'qc_inspection' => 'QC', 'support_report' => 'SUP', 'cash_handover' => 'CH', 'stock_request' => 'SRQ', 'purchase_request' => 'PRQ', 'crm_lead' => 'LD', 'project' => 'PRJ', 'project_issue' => 'PIS', 'ticket' => 'TCK', 'maintenance_asset' => 'AST', 'maintenance_order' => 'MWO', 'iot_sensor' => 'SNS', 'iot_alarm' => 'ALM', 'crm_deal' => 'DL', 'crm_campaign' => 'CMP',
        'whatsapp_order' => 'WA'];

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

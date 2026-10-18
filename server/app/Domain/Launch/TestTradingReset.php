<?php

namespace App\Domain\Launch;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\GoLivePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Before go-live only: remove the trading done while testing (till shifts and sales, cash, stock movements and levels,
 * stock documents, purchasing, sales documents, loyalty and gift cards, and every accounting entry), so the company
 * starts trading with clean books and zero stock, ready for the opening stock. Products, prices, barcodes, customers,
 * suppliers, staff, roles, devices, branches and settings are kept.
 *
 * The audit log is never cleared: it keeps the test period and a row saying the test trading was cleared, by whom and
 * how much. Refused once the company has gone live, and on a company with branch servers (each node would differ).
 */
final class TestTradingReset
{
    /** The trading tables (with their lines). The order of deleting is worked out from the foreign keys. */
    public const TABLES = [
        // Tills and cash
        'sales', 'sale_lines', 'sale_payments', 'trade_ins', 'shifts', 'shift_count_corrections', 'vault_sessions', 'cash_movements', 'cash_handovers', 'cash_variances',
        'prescriptions', 'review_items', 'fiscal_submissions', 'commissions',
        // Stock
        'stock_movements', 'stock_movement_serials', 'stock_levels', 'stock_batch_levels', 'stock_bin_levels', 'stock_batches', 'serial_numbers', 'stock_reservations',
        'stock_adjustments', 'stock_adjustment_lines', 'stock_counts', 'stock_count_lines', 'stock_transfers', 'stock_transfer_lines', 'stock_requests', 'stock_request_lines', 'markdowns',
        'supplier_returns', 'supplier_return_lines',
        // Purchasing and suppliers' accounts
        'purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines', 'supplier_bills', 'supplier_bill_lines', 'supplier_payments', 'supplier_ledger',
        'supplier_balances', 'payable_allocations', 'consignment_sales',
        // Selling on account, orders and online orders
        'customer_orders', 'customer_order_lines', 'deliveries', 'delivery_lines', 'invoices', 'invoice_lines', 'credit_notes', 'customer_payments', 'customer_ledger',
        'customer_balances', 'receivable_allocations', 'sales_orders', 'sales_order_lines',
        // Loyalty, gift cards, passes
        'loyalty_ledger', 'loyalty_balances', 'stored_values', 'stored_value_ledger', 'stored_value_balances', 'service_passes', 'service_pass_uses', 'memberships',
        // Industry work
        'dining_orders', 'dining_order_items', 'reservations', 'deposit_movements', 'repair_jobs', 'repair_job_lines', 'appointments', 'alterations', 'visits',
        'van_settlements', 'van_settlement_lines', 'work_orders', 'work_order_materials', 'work_order_costs', 'qc_inspections',
        // Accounting and banking
        'journal_entries', 'journal_lines', 'gl_postings', 'bank_statement_imports', 'bank_statement_lines', 'bank_matches', 'bank_matched_lines', 'asset_depreciation',
    ];

    /** Tables linked to their parent without a foreign key: table => [column, parent table]. */
    private const PARENTS = ['stock_movement_serials' => ['stock_movement_id', 'stock_movements']];

    /** Document numbers that start again (customers, staff, assets and the like keep counting: those records stay). */
    public const RESTART_NUMBERS = ['PO', 'GRN', 'TRF', 'CNT', 'QT', 'SO', 'DN', 'INV', 'CRN', 'RCT', 'JE', 'BILL', 'SPAY', 'MT', 'RX', 'ORD', 'RES', 'JOB', 'ALT',
        'APT', 'PASS', 'ADJ', 'RTS', 'VS', 'WO', 'QC', 'CH', 'SRQ'];

    public function __construct(private readonly AuditLog $audit) {}

    /** @return array<string, int> table => rows of this company that would go (only tables with rows) */
    public function counts(string $tenantId): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            if ($n = $this->scoped($t, $tenantId)->count()) {
                $out[$t] = $n;
            }
        }

        return $out;
    }

    /** @return array<string, int> what was removed */
    public function clear(Actor $actor): array
    {
        $tenantId = $actor->tenantId;
        if (GoLivePlan::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'live')->exists()) {
            throw new DomainException('This company has gone live: its trading is real and cannot be cleared.');
        }
        if (Schema::hasTable('sync_peers') && DB::table('sync_peers')->exists()) {
            throw new DomainException('This company has branch servers: clearing here would leave them different. Ask SyncLytics.');
        }
        $tables = $this->tables();
        $order = $this->deleteOrder($tables);
        $counts = $this->counts($tenantId);

        DB::transaction(function () use ($actor, $tenantId, $tables, $order, $counts) {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // checks still waiting (e.g. balanced journals) run now, before the guards go off
            $withTriggers = array_values(array_intersect($tables, array_column(DB::select('SELECT DISTINCT event_object_table AS t FROM information_schema.triggers'), 't')));
            foreach ($withTriggers as $t) {
                DB::statement("ALTER TABLE \"{$t}\" DISABLE TRIGGER USER"); // the append-only guards, for this one clean-up
            }
            // Rows of kept tables that point at trading rows (optional links) let go first.
            foreach ($this->outsideLinks($tables) as [$table, $column, $target]) {
                DB::table($table)->where('tenant_id', $tenantId)->whereIn($column, $this->scoped($target, $tenantId)->select('id'))->update([$column => null]);
            }
            foreach ($this->links() as $l) { // optional links between trading rows (e.g. a transfer and its request point at each other)
                if ($l->tbl !== $l->target && ! $l->notnull && in_array($l->tbl, $tables, true) && in_array($l->target, $tables, true)) {
                    $this->scoped($l->tbl, $tenantId)->whereNotNull($l->col)->update([$l->col => null]);
                }
            }
            foreach ($order as $t) {
                $this->scoped($t, $tenantId)->delete();
            }
            DB::table('document_sequences')->where('tenant_id', $tenantId)->where('node', '<>', 'catalog')->whereIn('doc_type', self::RESTART_NUMBERS)->delete();
            foreach ($withTriggers as $t) {
                DB::statement("ALTER TABLE \"{$t}\" ENABLE TRIGGER USER");
            }
            $this->audit->recordRef($actor, 'company.test_trading_cleared', 'tenant', $tenantId, ['cleared' => [null, $counts]],
                'Test trading cleared before go-live');
        });

        return $counts;
    }

    /** The trading tables that exist in this installation. */
    private function tables(): array
    {
        return array_values(array_filter(self::TABLES, fn ($t) => Schema::hasTable($t)));
    }

    /** This company's rows of a table: by tenant_id, or through the parent document for line tables without one. */
    private function scoped(string $table, string $tenantId)
    {
        if (Schema::hasColumn($table, 'tenant_id')) {
            return DB::table($table)->where('tenant_id', $tenantId);
        }
        if (isset(self::PARENTS[$table])) {
            [$col, $parent] = self::PARENTS[$table];

            return DB::table($table)->whereIn($col, DB::table($parent)->where('tenant_id', $tenantId)->select('id'));
        }
        foreach ($this->links() as $l) {
            if ($l->tbl === $table && $l->target !== $table && Schema::hasColumn($l->target, 'tenant_id') && in_array($l->target, self::TABLES, true)) {
                return DB::table($table)->whereIn($l->col, DB::table($l->target)->where('tenant_id', $tenantId)->select('id'));
            }
        }
        throw new DomainException("Cannot tell which company the rows of {$table} belong to.");
    }

    /** Children before parents: a table goes once nothing left in the set refers to it. */
    private function deleteOrder(array $tables): array
    {
        $refs = [];
        foreach ($this->links() as $l) {
            if ($l->tbl !== $l->target && ! $l->notnull && in_array($l->tbl, $tables, true) && in_array($l->target, $tables, true)) {
                continue; // optional links between trading rows are cleared first (some point both ways)
            }
            if ($l->tbl !== $l->target && in_array($l->tbl, $tables, true) && in_array($l->target, $tables, true)) {
                $refs[$l->target][$l->tbl] = true;
            }
        }
        foreach (self::PARENTS as $child => [, $parent]) {
            if (in_array($child, $tables, true) && in_array($parent, $tables, true)) {
                $refs[$parent][$child] = true;
            }
        }
        $order = [];
        $left = $tables;
        while ($left) {
            $ready = array_values(array_filter($left, fn ($t) => ! array_diff(array_keys($refs[$t] ?? []), $order)));
            if (! $ready) {
                throw new DomainException('The trading tables refer to each other in a circle: '.implode(', ', $left));
            }
            array_push($order, ...$ready);
            $left = array_values(array_diff($left, $ready));
        }

        return $order;
    }

    /** Links from kept tables to trading tables (they must be optional: they are emptied). */
    private function outsideLinks(array $tables): array
    {
        $out = [];
        foreach ($this->links() as $l) {
            if (! in_array($l->tbl, $tables, true) && in_array($l->target, $tables, true)) {
                if ($l->notnull) {
                    throw new DomainException("{$l->tbl} must refer to {$l->target}; clearing would break it. Ask SyncLytics.");
                }
                $out[] = [$l->tbl, $l->col, $l->target];
            }
        }

        return $out;
    }

    private function links(): array
    {
        static $links = null;

        return $links ??= DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl, a.attname AS col, c.confrelid::regclass::text AS target, a.attnotnull AS notnull
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f'
            SQL);
    }
}

<?php

namespace App\Domain\Finance;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Posts every money-moving record from the other modules into the general ledger, exactly once (gl_postings),
 * at head office. Records arrive in any order (branch servers and offline tills sync late), so each run simply
 * takes whatever is not posted yet. High-volume sources (till sales, stock, points) are summarised per branch and
 * day; documents (invoices, payments, bills) get one entry each so they can be traced.
 *
 * Something dated in a closed month posts on the first day of the next open month (owner decision 2026-10-12).
 */
final class AutoPoster
{
    public const KINDS = [
        'sales' => 'Till sales and returns', 'stock' => 'Stock movements (at cost)', 'invoices' => 'Customer invoices', 'credit_notes' => 'Credit notes',
        'customer_payments' => 'Customer payments', 'customer_openings' => 'Customer opening balances', 'online_orders' => 'Online orders',
        'shifts' => 'Till cash over / short', 'loyalty' => 'Loyalty points', 'stored_value' => 'Gift cards and store credit',
        'supplier_bills' => 'Supplier bills', 'supplier_payments' => 'Supplier payments', 'supplier_openings' => 'Supplier opening balances',
    ];

    /** How each kind of stock movement is balanced against the stock account. */
    public const STOCK_COUNTER = [
        'sale' => 'cogs', 'sale_return' => 'cogs', 'delivery' => 'cogs', 'customer_return' => 'cogs',
        'receipt' => 'grni', 'receipt_reversal' => 'grni',
        'transfer_out' => 'stock_in_transit', 'transfer_in' => 'stock_in_transit',
        'adjustment' => 'stock_adjustment', 'count' => 'stock_adjustment',
        'opening' => 'opening_equity',
    ];

    /** Till tenders → the account the money (or claim) sits in. */
    public const TENDER = ['cash' => 'cash_till', 'momo' => 'momo', 'card' => 'card_clearing', 'account' => 'receivables',
        'points' => 'loyalty', 'gift_card' => 'stored_value', 'store_credit' => 'stored_value'];

    /** Money received or paid elsewhere (back office, online). */
    public const METHOD = ['cash' => 'cash_office', 'momo' => 'momo', 'card' => 'card_clearing', 'bank' => 'bank', 'cheque' => 'bank',
        'online' => 'online_clearing', 'free' => 'marketing'];

    private string $tz = 'UTC';

    private ?string $hq = null;

    public function __construct(private readonly GeneralLedger $gl) {}

    /**
     * @return array{posted: array<string, int>, errors: array<string, string>}
     */
    public function run(string $tenantId, int $batch = 2000): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $this->tz = $tenant->timezone ?: 'UTC';
        $this->hq = DB::table('branches')->where('tenant_id', $tenantId)->orderByDesc('is_hq')->orderBy('code')->value('id');
        $this->gl->forget();
        $actor = Actor::system($tenantId, 'Automatic posting', 'system');
        $posted = [];
        $errors = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $posted[$kind] = 0;
            try {
                do {
                    $n = $this->{$kind}($actor, $batch);
                    $posted[$kind] += $n;
                } while ($n >= $batch);
            } catch (DomainException $e) {
                $errors[$kind] = $e->getMessage();
            }
        }

        return ['posted' => $posted, 'errors' => $errors];
    }

    /** Count of source rows not posted yet, per kind (for the month-end checks). */
    public function unposted(string $tenantId, ?string $upTo = null): array
    {
        $this->tz = Tenant::findOrFail($tenantId)->timezone ?: 'UTC';
        $out = [];
        foreach ($this->sources($tenantId) as $kind => [$q, $dateExpr]) {
            if ($upTo) {
                $q->whereRaw("{$dateExpr} <= ?", [$upTo]);
            }
            $out[$kind] = $q->count();
        }

        return $out;
    }

    /** @return array<string, array{0: Builder, 1: string}> each kind's unposted rows and its date expression */
    private function sources(string $tenantId): array
    {
        $d = fn (string $col) => $this->localDate($col);

        return [
            'sales' => [$this->unpostedQuery('sales', 'sale', $tenantId)->where('s.status', 'posted'), $d('s.occurred_at')],
            'stock' => [$this->unpostedQuery('stock_movements', 'stock_movement', $tenantId)->where('s.type', '<>', 'putaway'), $d('s.occurred_at')],
            'invoices' => [$this->unpostedQuery('invoices', 'invoice', $tenantId), 's.issued_on'],
            'credit_notes' => [$this->unpostedQuery('credit_notes', 'credit_note', $tenantId), $d('s.created_at')],
            'customer_payments' => [$this->unpostedQuery('customer_payments', 'customer_payment', $tenantId), 's.received_on'],
            'customer_openings' => [$this->unpostedQuery('customer_ledger', 'customer_ledger', $tenantId)->where('s.type', 'opening'), $d('s.occurred_at')],
            'online_orders' => [$this->unpostedQuery('sales_orders', 'sales_order', $tenantId)->where('s.status', 'fulfilled'), $d('s.fulfilled_at')],
            'shifts' => [$this->unpostedQuery('shifts', 'shift', $tenantId)->where('s.status', 'closed')->where('s.over_short_minor', '<>', 0), $d('s.closed_at')],
            'loyalty' => [$this->unpostedQuery('loyalty_ledger', 'loyalty_ledger', $tenantId)->whereIn('s.type', ['earn', 'reverse', 'expire']), $d('s.occurred_at')],
            'stored_value' => [$this->unpostedQuery('stored_value_ledger', 'stored_value_ledger', $tenantId)
                ->where(fn ($w) => $w->where('s.type', 'expire')->orWhere(fn ($i) => $i->where('s.type', 'issue')->where('s.doc_type', 'gift_card_sale'))), $d('s.occurred_at')],
            'supplier_bills' => [$this->unpostedQuery('supplier_bills', 'supplier_bill', $tenantId)->where('s.status', 'posted'), 's.bill_date'],
            'supplier_payments' => [$this->unpostedQuery('supplier_payments', 'supplier_payment', $tenantId), 's.paid_on'],
            'supplier_openings' => [$this->unpostedQuery('supplier_ledger', 'supplier_ledger', $tenantId)->where('s.type', 'opening'), $d('s.occurred_at')],
        ];
    }

    private function unpostedQuery(string $table, string $sourceType, string $tenantId): Builder
    {
        return DB::table("{$table} as s")->where('s.tenant_id', $tenantId)
            ->whereNotExists(fn ($q) => $q->from('gl_postings as g')->where('g.source_type', $sourceType)->whereColumn('g.source_id', 's.id'));
    }

    private function localDate(string $col): string
    {
        $tz = preg_replace('/[^A-Za-z_\/+-]/', '', $this->tz);

        return "(({$col} AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')::date";
    }

    private function rows(string $kind, string $tenantId, int $batch, array $select): array
    {
        [$q, $dateExpr] = $this->sources($tenantId)[$kind];

        return $q->selectRaw(implode(', ', [...$select, "{$dateExpr}::text AS d"]))->orderByRaw($dateExpr)->orderBy('s.id')->limit($batch)->get()->all();
    }

    /** Tax lines (credit on sales) from a stored breakdown. */
    private function taxLines(string|array|null $breakdown, int $sign, ?string $branchId, string $prefix = 'output_tax'): array
    {
        $rows = is_string($breakdown) ? json_decode($breakdown, true) : ($breakdown ?? []);

        return array_map(fn ($b) => ['account' => "{$prefix}:{$b['code']}", 'amount' => $sign * (int) $b['amount_minor'], 'branch_id' => $branchId], $rows);
    }

    private function sales(Actor $actor, int $batch): int
    {
        $rows = $this->rows('sales', $actor->tenantId, $batch, ['s.id', 's.branch_id', 's.net_minor', 's.tax_breakdown', 's.customer_id', 's.kind']);
        if (! $rows) {
            return 0;
        }
        $payments = DB::table('sale_payments')->whereIn('sale_id', array_column($rows, 'id'))->get(['sale_id', 'method', 'amount_minor'])->groupBy('sale_id');
        $codes = DB::table('branches')->pluck('code', 'id');
        foreach (collect($rows)->groupBy(fn ($r) => "{$r->branch_id}|{$r->d}") as $group) {
            $lines = [];
            $first = $group->first();
            foreach ($group as $s) {
                $lines[] = ['account' => 'sales_pos', 'amount' => -(int) $s->net_minor, 'branch_id' => $s->branch_id];
                array_push($lines, ...$this->taxLines($s->tax_breakdown, -1, $s->branch_id));
                foreach ($payments[$s->id] ?? [] as $p) {
                    $key = self::TENDER[$p->method] ?? throw new DomainException("Till payment method “{$p->method}” has no account.", 'gl_account_missing');
                    $lines[] = ['account' => $key, 'amount' => (int) $p->amount_minor, 'branch_id' => $s->branch_id]
                        + ($key === 'receivables' ? ['party_type' => 'customer', 'party_id' => $s->customer_id] : []);
                }
            }
            $this->gl->post($actor, $first->d, 'auto', "Till sales {$codes[$first->branch_id]} {$first->d} ({$group->count()} receipts)", $lines,
                $group->map(fn ($s) => ['sale', $s->id])->all(), 'sales', late: true);
        }

        return count($rows);
    }

    private function stock(Actor $actor, int $batch): int
    {
        $rows = $this->rows('stock', $actor->tenantId, $batch, ['s.id', 's.branch_id', 's.type', 'ROUND(s.qty_entered * COALESCE(s.unit_cost_minor, 0))::bigint AS value']);
        if (! $rows) {
            return 0;
        }
        $codes = DB::table('branches')->pluck('code', 'id');
        foreach (collect($rows)->groupBy(fn ($r) => "{$r->branch_id}|{$r->d}") as $group) {
            $lines = [];
            $first = $group->first();
            foreach ($group as $m) {
                $counter = self::STOCK_COUNTER[$m->type] ?? throw new DomainException("Stock movement type “{$m->type}” has no account.", 'gl_account_missing');
                $lines[] = ['account' => 'inventory', 'amount' => (int) $m->value, 'branch_id' => $m->branch_id];
                $lines[] = ['account' => $counter, 'amount' => -(int) $m->value, 'branch_id' => $m->branch_id];
            }
            $this->gl->post($actor, $first->d, 'auto', "Stock at cost {$codes[$first->branch_id]} {$first->d}", $lines,
                $group->map(fn ($m) => ['stock_movement', $m->id])->all(), 'stock', late: true);
        }

        return count($rows);
    }

    private function invoices(Actor $actor, int $batch): int
    {
        $rows = $this->rows('invoices', $actor->tenantId, $batch, ['s.id', 's.number', 's.branch_id', 's.customer_id', 's.customer_name', 's.net_minor',
            's.total_minor', 's.tax_breakdown']);
        foreach ($rows as $i) {
            $this->gl->post($actor, $i->d, 'auto', "Invoice {$i->number} {$i->customer_name}", [
                ['account' => 'receivables', 'amount' => (int) $i->total_minor, 'branch_id' => $i->branch_id, 'party_type' => 'customer', 'party_id' => $i->customer_id],
                ['account' => 'sales_invoice', 'amount' => -(int) $i->net_minor, 'branch_id' => $i->branch_id],
                ...$this->taxLines($i->tax_breakdown, -1, $i->branch_id),
            ], [['invoice', $i->id]], 'invoices', late: true);
        }

        return count($rows);
    }

    private function credit_notes(Actor $actor, int $batch): int
    {
        $rows = $this->rows('credit_notes', $actor->tenantId, $batch, ['s.id', 's.number', 's.branch_id', 's.customer_id', 's.net_minor', 's.total_minor', 's.invoice_id']);
        foreach ($rows as $c) {
            $inv = DB::table('invoices')->where('id', $c->invoice_id)->first(['number', 'tax_breakdown']);
            $this->gl->post($actor, $c->d, 'auto', "Credit note {$c->number} on {$inv->number}", [
                ['account' => 'receivables', 'amount' => -(int) $c->total_minor, 'branch_id' => $c->branch_id, 'party_type' => 'customer', 'party_id' => $c->customer_id],
                ['account' => 'sales_invoice', 'amount' => (int) $c->net_minor, 'branch_id' => $c->branch_id],
                ...$this->taxLines($inv->tax_breakdown, 1, $c->branch_id),
            ], [['credit_note', $c->id]], 'credit_notes', late: true);
        }

        return count($rows);
    }

    private function customer_payments(Actor $actor, int $batch): int
    {
        $rows = $this->rows('customer_payments', $actor->tenantId, $batch, ['s.id', 's.number', 's.branch_id', 's.customer_id', 's.method', 's.amount_minor', 's.shift_id']);
        foreach ($rows as $p) {
            $key = $p->method === 'cash' && $p->shift_id ? 'cash_till' : (self::METHOD[$p->method] ?? 'bank');
            $this->gl->post($actor, $p->d, 'auto', "Customer payment {$p->number}", [
                ['account' => $key, 'amount' => (int) $p->amount_minor, 'branch_id' => $p->branch_id],
                ['account' => 'receivables', 'amount' => -(int) $p->amount_minor, 'branch_id' => $p->branch_id, 'party_type' => 'customer', 'party_id' => $p->customer_id],
            ], [['customer_payment', $p->id]], 'customer_payments', late: true);
        }

        return count($rows);
    }

    private function customer_openings(Actor $actor, int $batch): int
    {
        $rows = $this->rows('customer_openings', $actor->tenantId, $batch, ['s.id', 's.customer_id', 's.branch_id', 's.amount_minor']);
        foreach ($rows as $o) {
            $branch = $o->branch_id ?? $this->hq;
            $this->gl->post($actor, $o->d, 'auto', 'Customer opening balance', [
                ['account' => 'receivables', 'amount' => (int) $o->amount_minor, 'branch_id' => $branch, 'party_type' => 'customer', 'party_id' => $o->customer_id],
                ['account' => 'opening_equity', 'amount' => -(int) $o->amount_minor, 'branch_id' => $branch],
            ], [['customer_ledger', $o->id]], 'customer_openings', late: true);
        }

        return count($rows);
    }

    private function online_orders(Actor $actor, int $batch): int
    {
        $rows = $this->rows('online_orders', $actor->tenantId, $batch, ['s.id', 's.number', 's.branch_id', 's.net_minor', 's.total_minor', 's.tax_breakdown', 's.payment_method']);
        foreach ($rows as $o) {
            $this->gl->post($actor, $o->d, 'auto', "Online order {$o->number}", [
                ['account' => self::METHOD[$o->payment_method ?? 'online'] ?? 'online_clearing', 'amount' => (int) $o->total_minor, 'branch_id' => $o->branch_id],
                ['account' => 'sales_online', 'amount' => -(int) $o->net_minor, 'branch_id' => $o->branch_id],
                ...$this->taxLines($o->tax_breakdown, -1, $o->branch_id),
            ], [['sales_order', $o->id]], 'online_orders', late: true);
        }

        return count($rows);
    }

    private function shifts(Actor $actor, int $batch): int
    {
        $rows = $this->rows('shifts', $actor->tenantId, $batch, ['s.id', 's.branch_id', 's.over_short_minor']);
        foreach ($rows as $s) {
            $this->gl->post($actor, $s->d, 'auto', ((int) $s->over_short_minor > 0 ? 'Cash over' : 'Cash short').' at shift close', [
                ['account' => 'cash_till', 'amount' => (int) $s->over_short_minor, 'branch_id' => $s->branch_id],
                ['account' => 'cash_over_short', 'amount' => -(int) $s->over_short_minor, 'branch_id' => $s->branch_id],
            ], [['shift', $s->id]], 'shifts', late: true);
        }

        return count($rows);
    }

    /** Points earned are a cost and a debt to the customer (1 point = 1 pesewa); points taken back or expired reverse it. */
    private function loyalty(Actor $actor, int $batch): int
    {
        $rows = $this->rows('loyalty', $actor->tenantId, $batch, ['s.id', 's.points']);
        foreach (collect($rows)->groupBy('d') as $d => $group) {
            $sum = (int) $group->sum('points');
            $this->gl->post($actor, $d, 'auto', "Loyalty points {$d}", [
                ['account' => 'loyalty_expense', 'amount' => $sum, 'branch_id' => $this->hq],
                ['account' => 'loyalty', 'amount' => -$sum, 'branch_id' => $this->hq],
            ], $group->map(fn ($r) => ['loyalty_ledger', $r->id])->all(), 'loyalty', late: true);
        }

        return count($rows);
    }

    /** Gift cards sold in the back office (money in, debt to the holder) and balances that expired (income). */
    private function stored_value(Actor $actor, int $batch): int
    {
        $rows = $this->rows('stored_value', $actor->tenantId, $batch, ['s.id', 's.type', 's.amount_minor', 's.doc_number']);
        foreach (collect($rows)->groupBy('d') as $d => $group) {
            $lines = [];
            foreach ($group as $r) {
                $counter = $r->type === 'expire' ? 'breakage' : (self::METHOD[strtok((string) $r->doc_number, ' ')] ?? 'cash_office');
                $lines[] = ['account' => 'stored_value', 'amount' => -(int) $r->amount_minor, 'branch_id' => $this->hq];
                $lines[] = ['account' => $counter, 'amount' => (int) $r->amount_minor, 'branch_id' => $this->hq];
            }
            $this->gl->post($actor, $d, 'auto', "Gift cards and store credit {$d}", $lines, $group->map(fn ($r) => ['stored_value_ledger', $r->id])->all(),
                'stored_value', late: true);
        }

        return count($rows);
    }

    /**
     * Stock lines clear goods-received-not-billed at the receipt cost; any price difference goes to purchase price
     * differences. Recoverable tax is claimable input tax; tax that is not recoverable is a cost of the line.
     */
    private function supplier_bills(Actor $actor, int $batch): int
    {
        $rows = $this->rows('supplier_bills', $actor->tenantId, $batch, ['s.id', 's.number', 's.supplier_id', 's.branch_id', 's.total_minor', 's.supplier_invoice_no']);
        $recoverable = DB::table('tax_rates')->where('tenant_id', $actor->tenantId)->pluck('input_recoverable', 'code');
        foreach ($rows as $b) {
            $lines = [['account' => 'payables', 'amount' => -(int) $b->total_minor, 'branch_id' => $b->branch_id, 'party_type' => 'supplier', 'party_id' => $b->supplier_id]];
            foreach (DB::table('supplier_bill_lines')->where('supplier_bill_id', $b->id)->get() as $l) {
                $costAccount = $l->account_id ? Account::withoutGlobalScopes()->findOrFail($l->account_id) : 'price_variance';
                if ($l->goods_receipt_line_id) {
                    $lines[] = ['account' => 'grni', 'amount' => (int) $l->received_value_minor, 'branch_id' => $b->branch_id];
                    $lines[] = ['account' => $costAccount, 'amount' => (int) $l->net_minor - (int) $l->received_value_minor, 'branch_id' => $b->branch_id];
                } else {
                    $lines[] = ['account' => $costAccount, 'amount' => (int) $l->net_minor, 'branch_id' => $b->branch_id];
                }
                foreach (json_decode($l->tax_breakdown, true) ?? [] as $t) {
                    $lines[] = ['account' => ($recoverable[$t['code']] ?? true) ? "input_tax:{$t['code']}" : $costAccount, 'amount' => (int) $t['amount_minor'], 'branch_id' => $b->branch_id];
                }
            }
            $this->gl->post($actor, $b->d, 'auto', "Supplier bill {$b->number} ({$b->supplier_invoice_no})", $lines, [['supplier_bill', $b->id]], 'supplier_bills', late: true);
        }

        return count($rows);
    }

    private function supplier_payments(Actor $actor, int $batch): int
    {
        $rows = $this->rows('supplier_payments', $actor->tenantId, $batch, ['s.id', 's.number', 's.supplier_id', 's.branch_id', 's.account_id', 's.amount_minor']);
        foreach ($rows as $p) {
            $this->gl->post($actor, $p->d, 'auto', "Supplier payment {$p->number}", [
                ['account' => 'payables', 'amount' => (int) $p->amount_minor, 'branch_id' => $p->branch_id, 'party_type' => 'supplier', 'party_id' => $p->supplier_id],
                ['account' => Account::withoutGlobalScopes()->findOrFail($p->account_id), 'amount' => -(int) $p->amount_minor, 'branch_id' => $p->branch_id],
            ], [['supplier_payment', $p->id]], 'supplier_payments', late: true);
        }

        return count($rows);
    }

    private function supplier_openings(Actor $actor, int $batch): int
    {
        $rows = $this->rows('supplier_openings', $actor->tenantId, $batch, ['s.id', 's.supplier_id', 's.branch_id', 's.amount_minor']);
        foreach ($rows as $o) {
            $branch = $o->branch_id ?? $this->hq;
            $this->gl->post($actor, $o->d, 'auto', 'Supplier opening balance', [
                ['account' => 'payables', 'amount' => -(int) $o->amount_minor, 'branch_id' => $branch, 'party_type' => 'supplier', 'party_id' => $o->supplier_id],
                ['account' => 'opening_equity', 'amount' => (int) $o->amount_minor, 'branch_id' => $branch],
            ], [['supplier_ledger', $o->id]], 'supplier_openings', late: true);
        }

        return count($rows);
    }
}

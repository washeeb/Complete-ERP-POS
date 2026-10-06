<?php

namespace App\Domain\Customers;

use App\Domain\Support\Actor;
use App\Domain\Support\Outbox;
use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The receivables sub-ledger: the only writer of what customers owe. Entries are append-only
 * (+ the customer owes more, − less); the balance projection moves in the same transaction, and
 * allocations record which invoices a payment or credit note settles. Other nodes receive the
 * entries and allocations themselves and apply them idempotently.
 */
final class CustomerLedger
{
    /** Documents a customer owes on (open items). */
    public const DEBIT_TYPES = ['invoice', 'pos_sale', 'opening'];

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @param  list<array{to_type: string, to_id: string, amount_minor: int}>  $allocations  settles open items (from this payment/credit note)
     */
    public function post(Actor $actor, Customer $customer, string $type, int $amountMinor, string $currency, string $docType, string $docId,
        string $docNumber, ?CarbonInterface $dueOn = null, ?string $branchId = null, ?CarbonInterface $occurredAt = null, array $allocations = []): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer ledger entries are posted inside the document transaction.');
        }
        $now = now();
        $entry = [
            'id' => (string) Str::uuid7(), 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'branch_id' => $branchId,
            'type' => $type, 'amount_minor' => $amountMinor, 'currency_code' => $currency, 'doc_type' => $docType, 'doc_id' => $docId,
            'doc_number' => $docNumber, 'due_on' => $dueOn?->toDateString(), 'actor_name' => $actor->name, 'origin_node' => (string) config('erp.node.id'),
            'occurred_at' => ($occurredAt ?? $now)->format('Y-m-d H:i:s.u'), 'received_at' => $now->format('Y-m-d H:i:s.u'),
        ];
        $allocRows = array_map(fn ($a) => ['id' => (string) Str::uuid7(), 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id,
            'from_type' => $docType, 'from_id' => $docId, 'to_type' => $a['to_type'], 'to_id' => $a['to_id'], 'amount_minor' => $a['amount_minor'],
            'origin_node' => (string) config('erp.node.id'), 'created_at' => $now->format('Y-m-d H:i:s.u')], array_values(array_filter($allocations, fn ($a) => $a['amount_minor'] > 0)));

        $this->apply([$entry], $allocRows);
        $this->outbox->publishRaw($actor, 'customer_ledger_batch', $entry['id'], 1, "customer_ledger.{$type}", ['entries' => [$entry], 'allocations' => $allocRows]);
    }

    /** Insert entries/allocations not seen before and move the balance for the new entries only (also used by sync). */
    public function apply(array $entries, array $allocations = []): void
    {
        foreach ($entries as $e) {
            if (DB::table('customer_ledger')->insertOrIgnore($e) === 0) {
                continue;
            }
            DB::table('customer_balances')->insertOrIgnore(['customer_id' => $e['customer_id'], 'tenant_id' => $e['tenant_id'], 'balance_minor' => 0, 'updated_at' => now()]);
            DB::table('customer_balances')->where('customer_id', $e['customer_id'])->lockForUpdate()->first();
            DB::table('customer_balances')->where('customer_id', $e['customer_id'])
                ->update(['balance_minor' => DB::raw('balance_minor + '.(int) $e['amount_minor']), 'updated_at' => now()]);
        }
        if ($allocations) {
            DB::table('receivable_allocations')->insertOrIgnore($allocations);
        }
    }

    /**
     * Open items (what is still owed, per document), oldest due first.
     *
     * @return list<object{type: string, doc_type: string, doc_id: string, doc_number: string, amount_minor: int, open_minor: int, due_on: ?string, occurred_at: string}>
     */
    public function openItems(string $customerId): array
    {
        return array_values(array_filter(DB::select(<<<'SQL'
            SELECT l.type, l.doc_type, l.doc_id, l.doc_number, l.amount_minor, l.due_on, l.occurred_at,
                   l.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM receivable_allocations a WHERE a.to_type = l.doc_type AND a.to_id = l.doc_id), 0) AS open_minor
            FROM customer_ledger l
            WHERE l.customer_id = ? AND l.type IN ('invoice', 'pos_sale', 'opening')
            ORDER BY COALESCE(l.due_on, l.occurred_at::date), l.occurred_at
        SQL, [$customerId]), fn ($r) => (int) $r->open_minor > 0));
    }

    /** Unapplied money (payments and credit notes not yet matched to an invoice). */
    public function unallocatedCredits(string $customerId): array
    {
        return DB::select(<<<'SQL'
            SELECT l.doc_type, l.doc_id, -l.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM receivable_allocations a WHERE a.from_type = l.doc_type AND a.from_id = l.doc_id), 0) AS free_minor
            FROM customer_ledger l
            WHERE l.customer_id = ? AND l.type IN ('payment', 'credit_note')
            ORDER BY l.occurred_at
        SQL, [$customerId]);
    }

    /** Use money already on the account (overpayments, credit notes on paid invoices) against open items, oldest first. */
    public function applyCredits(Actor $actor, Customer $customer): int
    {
        $rows = [];
        $now = now()->format('Y-m-d H:i:s.u');
        foreach ($this->unallocatedCredits($customer->id) as $credit) {
            foreach ($this->plan($customer->id, max(0, (int) $credit->free_minor)) as $a) {
                $row = ['id' => (string) Str::uuid7(), 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'from_type' => $credit->doc_type,
                    'from_id' => $credit->doc_id, 'to_type' => $a['to_type'], 'to_id' => $a['to_id'], 'amount_minor' => $a['amount_minor'],
                    'origin_node' => (string) config('erp.node.id'), 'created_at' => $now];
                $this->apply([], [$row]); // immediately, so the next credit sees the item as settled
                $rows[] = $row;
            }
        }
        if ($rows) {
            $this->outbox->publishRaw($actor, 'customer_ledger_batch', $rows[0]['id'], 1, 'customer_ledger.credit_applied', ['entries' => [], 'allocations' => $rows]);
        }

        return array_sum(array_column($rows, 'amount_minor'));
    }

    /** Oldest-first allocation of an amount to open items (optionally only the given documents). */
    public function plan(string $customerId, int $amountMinor, ?array $onlyDocIds = null): array
    {
        $out = [];
        foreach ($this->openItems($customerId) as $item) {
            if ($amountMinor <= 0) {
                break;
            }
            if ($onlyDocIds !== null && ! in_array($item->doc_id, $onlyDocIds, true)) {
                continue;
            }
            $take = min($amountMinor, (int) $item->open_minor);
            $out[] = ['to_type' => $item->doc_type, 'to_id' => $item->doc_id, 'amount_minor' => $take];
            $amountMinor -= $take;
        }

        return $out;
    }

    /** Amounts past their due date (strictly before the given day). */
    public function overdueMinor(string $customerId, ?CarbonInterface $asOf = null): int
    {
        $day = ($asOf ?? now())->toDateString();

        return array_sum(array_map(fn ($i) => (int) $i->open_minor, array_filter($this->openItems($customerId), fn ($i) => $i->due_on !== null && $i->due_on < $day)));
    }

    /**
     * Aged receivables per customer: buckets by days past due as at a date.
     *
     * @return array<string, array{current: int, d1_30: int, d31_60: int, d61_90: int, d90_plus: int, total: int}>
     */
    public function aging(?CarbonInterface $asOf = null, ?array $customerIds = null): array
    {
        $asOf ??= now();
        $ids = $customerIds ?? DB::table('customer_balances')->where('balance_minor', '<>', 0)->pluck('customer_id')->all();
        $out = [];
        foreach ($ids as $id) {
            $b = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0];
            foreach ($this->openItems($id) as $i) {
                $days = $i->due_on ? (int) Carbon::parse($i->due_on)->diffInDays($asOf->copy()->startOfDay(), false) : 0;
                $key = match (true) {
                    $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus',
                };
                $b[$key] += (int) $i->open_minor;
                $b['total'] += (int) $i->open_minor;
            }
            // Money received but not matched to anything reduces the total (shown as current credit).
            $free = array_sum(array_map(fn ($c) => max(0, (int) $c->free_minor), $this->unallocatedCredits($id)));
            $b['current'] -= $free;
            $b['total'] -= $free;
            if ($b['total'] !== 0 || $b['d1_30'] || $b['d31_60'] || $b['d61_90'] || $b['d90_plus']) {
                $out[$id] = $b;
            }
        }

        return $out;
    }

    /**
     * Statement for a period: opening balance, entries, closing balance.
     *
     * @return array{opening: int, entries: list<object>, closing: int}
     */
    public function statement(string $customerId, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = (int) DB::table('customer_ledger')->where('customer_id', $customerId)->where('occurred_at', '<', $from->copy()->startOfDay())->sum('amount_minor');
        $entries = DB::table('customer_ledger')->where('customer_id', $customerId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('occurred_at')->orderBy('received_at')->get()->all();
        $running = $opening;
        foreach ($entries as $e) {
            $running += (int) $e->amount_minor;
            $e->running_minor = $running;
        }

        return ['opening' => $opening, 'entries' => $entries, 'closing' => $running];
    }

    /** Customers whose balance projection disagrees with their entries (should always be empty). */
    public function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT b.customer_id, b.balance_minor, COALESCE(SUM(l.amount_minor), 0) AS ledger
            FROM customer_balances b LEFT JOIN customer_ledger l ON l.customer_id = b.customer_id
            WHERE (?::uuid IS NULL OR b.tenant_id = ?::uuid)
            GROUP BY b.customer_id, b.balance_minor
            HAVING b.balance_minor <> COALESCE(SUM(l.amount_minor), 0)
        SQL, [$tenantId, $tenantId]);
    }
}

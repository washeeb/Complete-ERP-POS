<?php

namespace App\Domain\Payables;

use App\Domain\Support\Actor;
use App\Domain\Support\Outbox;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The payables sub-ledger: the only writer of what the company owes each supplier. Entries are append-only
 * (+ we owe more, − less); the balance projection moves in the same transaction, and allocations record which
 * bills a payment settles. Kept at head office.
 */
final class SupplierLedger
{
    public function __construct(private readonly Outbox $outbox) {}

    /** @param list<array{to_type: string, to_id: string, amount_minor: int}> $allocations */
    public function post(Actor $actor, Supplier $supplier, string $type, int $amountMinor, string $currency, string $docType, string $docId, string $docNumber,
        ?CarbonInterface $dueOn = null, ?string $branchId = null, ?CarbonInterface $occurredAt = null, array $allocations = []): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Supplier ledger entries are posted inside the document transaction.');
        }
        $now = now();
        $entry = ['id' => (string) Str::uuid7(), 'tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'branch_id' => $branchId, 'type' => $type,
            'amount_minor' => $amountMinor, 'currency_code' => $currency, 'doc_type' => $docType, 'doc_id' => $docId, 'doc_number' => $docNumber,
            'due_on' => $dueOn?->toDateString(), 'actor_name' => $actor->name, 'origin_node' => (string) config('erp.node.id'),
            'occurred_at' => ($occurredAt ?? $now)->format('Y-m-d H:i:s.u'), 'received_at' => $now->format('Y-m-d H:i:s.u')];
        DB::table('supplier_ledger')->insert($entry);
        DB::table('supplier_balances')->insertOrIgnore(['supplier_id' => $supplier->id, 'tenant_id' => $supplier->tenant_id, 'balance_minor' => 0, 'updated_at' => $now]);
        DB::table('supplier_balances')->where('supplier_id', $supplier->id)->lockForUpdate()->first();
        DB::table('supplier_balances')->where('supplier_id', $supplier->id)->update(['balance_minor' => DB::raw('balance_minor + '.$amountMinor), 'updated_at' => $now]);
        $rows = array_map(fn ($a) => ['id' => (string) Str::uuid7(), 'tenant_id' => $supplier->tenant_id, 'supplier_id' => $supplier->id, 'from_type' => $docType,
            'from_id' => $docId, 'to_type' => $a['to_type'], 'to_id' => $a['to_id'], 'amount_minor' => $a['amount_minor'], 'origin_node' => (string) config('erp.node.id'),
            'created_at' => $now->format('Y-m-d H:i:s.u')], array_values(array_filter($allocations, fn ($a) => $a['amount_minor'] > 0)));
        if ($rows) {
            DB::table('payable_allocations')->insert($rows);
        }
        $this->outbox->publishRaw($actor, 'supplier_ledger_entry', $entry['id'], 1, "supplier_ledger.{$type}", ['entry' => $entry, 'allocations' => $rows]);
    }

    /** Bills and opening balances still owed, oldest due first. */
    public function openItems(string $supplierId): array
    {
        return array_values(array_filter(DB::select(<<<'SQL'
            SELECT l.type, l.doc_type, l.doc_id, l.doc_number, l.amount_minor, l.due_on, l.occurred_at,
                   l.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM payable_allocations a WHERE a.to_type = l.doc_type AND a.to_id = l.doc_id), 0) AS open_minor
            FROM supplier_ledger l
            WHERE l.supplier_id = ? AND l.type IN ('bill', 'opening')
            ORDER BY COALESCE(l.due_on, l.occurred_at::date), l.occurred_at
        SQL, [$supplierId]), fn ($r) => (int) $r->open_minor > 0));
    }

    /** Payments not yet matched to a bill (overpayments). */
    public function unallocated(string $supplierId): int
    {
        return (int) DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(-l.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM payable_allocations a WHERE a.from_type = l.doc_type AND a.from_id = l.doc_id), 0)), 0) AS free
            FROM supplier_ledger l WHERE l.supplier_id = ? AND l.type = 'payment'
        SQL, [$supplierId])->free;
    }

    /** Oldest-first allocation of an amount to open bills (optionally only the given ones). */
    public function plan(string $supplierId, int $amountMinor, ?array $onlyDocIds = null): array
    {
        $out = [];
        foreach ($this->openItems($supplierId) as $item) {
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

    /**
     * Aged payables per supplier as at a date.
     *
     * @return array<string, array{current: int, d1_30: int, d31_60: int, d61_90: int, d90_plus: int, total: int}>
     */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $out = [];
        foreach (DB::table('supplier_balances')->where('balance_minor', '<>', 0)->pluck('supplier_id') as $id) {
            $b = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0];
            foreach ($this->openItems($id) as $i) {
                $days = $i->due_on ? (int) Carbon::parse($i->due_on)->diffInDays($asOf->copy()->startOfDay(), false) : 0;
                $key = match (true) {
                    $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus',
                };
                $b[$key] += (int) $i->open_minor;
                $b['total'] += (int) $i->open_minor;
            }
            $free = $this->unallocated($id);
            $b['current'] -= $free;
            $b['total'] -= $free;
            $out[$id] = $b;
        }

        return $out;
    }

    /** Suppliers whose balance projection disagrees with their entries (should always be empty). */
    public function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT b.supplier_id, b.balance_minor, COALESCE(SUM(l.amount_minor), 0) AS ledger
            FROM supplier_balances b LEFT JOIN supplier_ledger l ON l.supplier_id = b.supplier_id
            WHERE (?::uuid IS NULL OR b.tenant_id = ?::uuid)
            GROUP BY b.supplier_id, b.balance_minor
            HAVING b.balance_minor <> COALESCE(SUM(l.amount_minor), 0)
        SQL, [$tenantId, $tenantId]);
    }
}

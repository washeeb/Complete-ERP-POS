<?php

namespace App\Domain\Customers;

use App\Domain\Support\Actor;
use App\Domain\Support\Outbox;
use App\Models\Customer;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Loyalty points (owner decision 2026-10-10: 1 point per GHS 1 spent, 100 points = GHS 1, redeem from 500 points,
 * points expire 12 months after they were earned). An append-only ledger with a balance projection; other nodes apply
 * the entries themselves. Points are whole numbers; one point is worth one minor unit (pesewa).
 */
final class Loyalty
{
    public function __construct(private readonly Outbox $outbox, private readonly Settings $settings) {}

    public function enabled(string $tenantId): bool
    {
        return (bool) $this->settings->get('loyalty.enabled', $tenantId);
    }

    /** Points earned on an amount paid with money (not points, gift cards or store credit): 1 per whole currency unit. */
    public function pointsFor(int $paidMinor): int
    {
        return intdiv(max(0, $paidMinor), 100);
    }

    public function balance(string $customerId): int
    {
        return (int) DB::table('loyalty_balances')->where('customer_id', $customerId)->value('points');
    }

    /** Post an entry once per (document, type). Negative points spend or expire them. */
    public function post(Actor $actor, Customer $customer, string $type, int $points, string $docType, string $docId, string $docNumber,
        ?CarbonInterface $occurredAt = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Loyalty entries are posted inside the document transaction.');
        }
        if ($points === 0) {
            return;
        }
        $at = $occurredAt ?? now();
        $months = (int) $this->settings->get('loyalty.expiry_months', $customer->tenant_id);
        $row = ['id' => (string) Str::uuid7(), 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'type' => $type, 'points' => $points,
            'doc_type' => $docType, 'doc_id' => $docId, 'doc_number' => $docNumber,
            'expires_on' => $type === 'earn' && $months > 0 ? $at->copy()->addMonthsNoOverflow($months)->toDateString() : null,
            'actor_name' => $actor->name, 'origin_node' => (string) config('erp.node.id'),
            'occurred_at' => $at->format('Y-m-d H:i:s.u'), 'received_at' => now()->format('Y-m-d H:i:s.u')];
        $this->apply([$row]);
        $this->outbox->publishRaw($actor, 'loyalty_batch', $row['id'], 1, "loyalty.{$type}", ['entries' => [$row]]);
    }

    /** Insert unseen entries and move the balance for them only (also used by sync). */
    public function apply(array $rows): void
    {
        foreach ($rows as $r) {
            if (DB::table('loyalty_ledger')->insertOrIgnore($r) === 0) {
                continue;
            }
            DB::table('loyalty_balances')->insertOrIgnore(['customer_id' => $r['customer_id'], 'tenant_id' => $r['tenant_id'], 'points' => 0, 'updated_at' => now()]);
            DB::table('loyalty_balances')->where('customer_id', $r['customer_id'])->lockForUpdate()->first();
            DB::table('loyalty_balances')->where('customer_id', $r['customer_id'])->update(['points' => DB::raw('points + '.(int) $r['points']), 'updated_at' => now()]);
        }
    }

    /**
     * Points that have passed their expiry date and were not spent (first earned, first used). Returns points to expire.
     */
    public function expirable(string $customerId, CarbonInterface $asOf): int
    {
        $earnedExpired = (int) DB::table('loyalty_ledger')->where('customer_id', $customerId)->where('type', 'earn')->where('expires_on', '<=', $asOf->toDateString())->sum('points');
        $used = -(int) DB::table('loyalty_ledger')->where('customer_id', $customerId)->whereIn('type', ['redeem', 'expire', 'reverse', 'refund'])->sum('points');

        return max(0, $earnedExpired - $used);
    }

    /** Daily: expire points past their date for every customer (erp:loyalty-expire). */
    public function expireAll(Actor $actor, CarbonInterface $asOf): int
    {
        $n = 0;
        foreach (DB::table('loyalty_balances')->where('points', '>', 0)->pluck('customer_id') as $id) {
            $points = min($this->expirable($id, $asOf), $this->balance($id));
            if ($points > 0) {
                DB::transaction(fn () => $this->post($actor, Customer::findOrFail($id), 'expire', -$points, 'loyalty_expiry', (string) Str::uuid7(), 'Expired '.$asOf->toDateString(), $asOf));
                $n += $points;
            }
        }

        return $n;
    }

    public function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT b.customer_id, b.points, COALESCE(SUM(l.points), 0) AS ledger
            FROM loyalty_balances b LEFT JOIN loyalty_ledger l ON l.customer_id = b.customer_id
            WHERE (?::uuid IS NULL OR b.tenant_id = ?::uuid)
            GROUP BY b.customer_id, b.points HAVING b.points <> COALESCE(SUM(l.points), 0)
        SQL, [$tenantId, $tenantId]);
    }
}

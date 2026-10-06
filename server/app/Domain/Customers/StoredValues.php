<?php

namespace App\Domain\Customers;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Outbox;
use App\Domain\Support\Recorder;
use App\Models\Customer;
use App\Models\StoredValue;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Gift cards and store credit. A code, and an append-only ledger with a balance projection. A balance expires 12 months
 * after the card was last used (owner decision 2026-10-11). Redeeming online never overdraws; an offline redemption is
 * kept (the goods have gone) and an overdraft is flagged.
 */
final class StoredValues
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Outbox $outbox,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
    ) {}

    /** Issue a new card (back office: sold or given) or store credit (returns). */
    public function issue(Actor $actor, string $kind, int $amountMinor, string $docType, string $docId, string $docNumber, ?Customer $customer = null,
        ?string $code = null): StoredValue
    {
        if (! array_key_exists($kind, StoredValue::KINDS)) {
            throw new DomainException('Unknown kind of stored value.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('Enter an amount above zero.');
        }
        $code = $code ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)) : self::newCode($kind);
        if (strlen($code) < 6) {
            throw new DomainException('A card code needs at least 6 letters or digits.');
        }
        if (StoredValue::where('code', $code)->exists()) {
            throw new DomainException("Card {$code} already exists.");
        }

        return $this->recorder->transaction(function () use ($actor, $kind, $amountMinor, $docType, $docId, $docNumber, $customer, $code) {
            $sv = new StoredValue;
            $sv->forceFill(['tenant_id' => $actor->tenantId, 'kind' => $kind, 'code' => $code, 'customer_id' => $customer?->id,
                'currency_code' => Tenant::find($actor->tenantId)->currency_code, 'status' => 'active', 'last_used_at' => now()]);
            $this->recorder->save($actor, $sv, "{$kind}.issued");
            $this->post($actor, $sv, 'issue', $amountMinor, $docType, $docId, $docNumber);

            return $sv;
        });
    }

    /** Back office: sell or give a gift card (needs giftcards.manage). The payment itself is recorded by the reference. */
    public function sellCard(Actor $actor, int $amountMinor, string $paidBy, ?string $reference, ?Customer $customer = null, ?string $code = null): StoredValue
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'giftcards.manage')) {
            throw new AccessDenied;
        }

        return $this->issue($actor, 'gift_card', $amountMinor, 'gift_card_sale', (string) Str::uuid7(), mb_substr(trim("{$paidBy} {$reference}"), 0, 40), $customer, $code);
    }

    public function find(string $code): ?StoredValue
    {
        return StoredValue::where('code', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)))->first();
    }

    /**
     * Spend from a card on a sale. Online: refused if the balance is short, the card is void or expired.
     * Offline: always posted (money already accepted); returns a problem description for the review queue, or null.
     */
    public function redeem(Actor $actor, StoredValue $sv, int $amountMinor, string $docType, string $docId, string $docNumber, bool $offline,
        ?CarbonInterface $at = null): ?string
    {
        $problem = null;
        $bal = $this->lockedBalance($sv);
        if ($sv->status !== 'active') {
            $problem = "card {$sv->code} is void";
        } elseif ($this->isExpired($sv, $at ?? now())) {
            $problem = "card {$sv->code} expired";
        } elseif ($amountMinor > $bal) {
            $problem = "card {$sv->code} holds only ".number_format($bal / 100, 2);
        }
        if ($problem && ! $offline) {
            throw new DomainException(ucfirst($problem).'.', 'stored_value_refused');
        }
        $this->post($actor, $sv, 'redeem', -$amountMinor, $docType, $docId, $docNumber, $at);

        return $problem;
    }

    /** Put money back on a card (a returned sale paid with it). */
    public function refund(Actor $actor, StoredValue $sv, int $amountMinor, string $docType, string $docId, string $docNumber): void
    {
        $this->post($actor, $sv, 'refund', $amountMinor, $docType, $docId, $docNumber);
    }

    public function isExpired(StoredValue $sv, CarbonInterface $at): bool
    {
        $months = (int) $this->settings->get('stored_value.expiry_months', $sv->tenant_id);

        return $months > 0 && $sv->last_used_at && $sv->last_used_at->copy()->addMonthsNoOverflow($months)->lt($at);
    }

    /** Daily: zero balances unused for the expiry period (erp:stored-value-expire). */
    public function expireAll(Actor $actor, CarbonInterface $asOf): int
    {
        $n = 0;
        foreach (StoredValue::where('status', 'active')->get() as $sv) {
            $bal = $sv->balanceMinor();
            if ($bal > 0 && $this->isExpired($sv, $asOf)) {
                DB::transaction(fn () => $this->post($actor, $sv, 'expire', -$bal, 'stored_value_expiry', (string) Str::uuid7(), 'Expired '.$asOf->toDateString(), $asOf));
                $n++;
            }
        }

        return $n;
    }

    public function post(Actor $actor, StoredValue $sv, string $type, int $amountMinor, string $docType, string $docId, string $docNumber, ?CarbonInterface $at = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stored value entries are posted inside the document transaction.');
        }
        $row = ['id' => (string) Str::uuid7(), 'tenant_id' => $sv->tenant_id, 'stored_value_id' => $sv->id, 'type' => $type, 'amount_minor' => $amountMinor,
            'doc_type' => $docType, 'doc_id' => $docId, 'doc_number' => mb_substr($docNumber, 0, 40), 'actor_name' => $actor->name,
            'origin_node' => (string) config('erp.node.id'), 'occurred_at' => ($at ?? now())->format('Y-m-d H:i:s.u'), 'received_at' => now()->format('Y-m-d H:i:s.u')];
        $this->apply([$row]);
        if (in_array($type, ['issue', 'redeem', 'refund'], true)) { // use keeps the card alive (expiry runs from last use)
            DB::table('stored_values')->where('id', $sv->id)->update(['last_used_at' => $at ?? now()]);
        }
        $this->outbox->publishRaw($actor, 'stored_value_batch', $row['id'], 1, "stored_value.{$type}", ['entries' => [$row], 'touch' => [$sv->id => ($at ?? now())->toIso8601String()]]);
    }

    public function apply(array $rows, array $touch = []): void
    {
        foreach ($rows as $r) {
            if (DB::table('stored_value_ledger')->insertOrIgnore($r) === 0) {
                continue;
            }
            DB::table('stored_value_balances')->insertOrIgnore(['stored_value_id' => $r['stored_value_id'], 'tenant_id' => $r['tenant_id'], 'balance_minor' => 0, 'updated_at' => now()]);
            DB::table('stored_value_balances')->where('stored_value_id', $r['stored_value_id'])->lockForUpdate()->first();
            DB::table('stored_value_balances')->where('stored_value_id', $r['stored_value_id'])
                ->update(['balance_minor' => DB::raw('balance_minor + '.(int) $r['amount_minor']), 'updated_at' => now()]);
        }
        foreach ($touch as $id => $at) {
            DB::table('stored_values')->where('id', $id)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $at))->update(['last_used_at' => $at]);
        }
    }

    public function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT b.stored_value_id, b.balance_minor, COALESCE(SUM(l.amount_minor), 0) AS ledger
            FROM stored_value_balances b LEFT JOIN stored_value_ledger l ON l.stored_value_id = b.stored_value_id
            WHERE (?::uuid IS NULL OR b.tenant_id = ?::uuid)
            GROUP BY b.stored_value_id, b.balance_minor HAVING b.balance_minor <> COALESCE(SUM(l.amount_minor), 0)
        SQL, [$tenantId, $tenantId]);
    }

    private function lockedBalance(StoredValue $sv): int
    {
        DB::table('stored_value_balances')->insertOrIgnore(['stored_value_id' => $sv->id, 'tenant_id' => $sv->tenant_id, 'balance_minor' => 0, 'updated_at' => now()]);

        return (int) DB::table('stored_value_balances')->where('stored_value_id', $sv->id)->lockForUpdate()->value('balance_minor');
    }

    /** 12 characters from an unambiguous alphabet, prefixed GC / SC. */
    public static function newCode(string $kind): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return ($kind === 'gift_card' ? 'GC' : 'SC').$s;
    }
}

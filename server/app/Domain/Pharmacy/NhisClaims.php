<?php

namespace App\Domain\Pharmacy;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\NhisClaim;
use App\Security\Authorizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NHIS claims (owner request 2026-10-24). The till's "NHIS" tender is the scheme's part of a sale, owed by the NHIA
 * (account "Due from NHIS"). A claim gathers a branch's NHIS parts not claimed yet, up to a day; it is sent to the
 * NHIA, and settled with what the NHIA paid: the money comes in, the part it did not pay is written off ("NHIS
 * claims not paid"). Each sale's part is on one claim only; a return's negative part goes on the next claim.
 */
final class NhisClaims
{
    public const PERMISSION = 'pharmacy.nhis';

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /** NHIS parts of a branch's posted sales and returns not on a claim, up to the end of a day (branch time). */
    public static function unclaimed(Branch $branch, ?string $upTo = null): Builder
    {
        return DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.method', 'nhis')->where('s.branch_id', $branch->id)->where('s.status', 'posted')
            ->whereNotExists(fn ($q) => $q->from('nhis_claim_lines as cl')->whereColumn('cl.sale_payment_id', 'p.id'))
            ->when($upTo, fn ($q) => $q->where('s.occurred_at', '<=', Carbon::parse($upTo, $branch->timezone ?: config('app.timezone'))->endOfDay()->utc()));
    }

    public function prepare(Actor $actor, Branch $branch, string $upTo): NhisClaim
    {
        $this->require($actor, $branch);

        return $this->recorder->transaction(function () use ($actor, $branch, $upTo) {
            $rows = self::unclaimed($branch, $upTo)->orderBy('s.occurred_at')->lockForUpdate()->get(['p.id', 'p.sale_id', 'p.amount_minor', 's.occurred_at']);
            $total = (int) $rows->sum('amount_minor');
            if ($rows->isEmpty() || $total <= 0) {
                throw new DomainException('Nothing to claim at '.$branch->name.' up to that day.');
            }
            $tz = $branch->timezone ?: config('app.timezone');
            $claim = (new NhisClaim)->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'number' => $this->numbers->next($actor->tenantId, 'nhis_claim'),
                'period_from' => Carbon::parse($rows->first()->occurred_at)->setTimezone($tz)->toDateString(), 'period_to' => $upTo, 'status' => 'open',
                'claimed_minor' => $total, 'prepared_by_name' => $actor->name]);
            $this->recorder->save($actor, $claim, 'nhis_claim.prepared', branchId: $branch->id, payload: ['lines' => $rows->count()]);
            DB::table('nhis_claim_lines')->insert($rows->map(fn ($r) => ['id' => (string) Str::uuid7(), 'nhis_claim_id' => $claim->id, 'sale_payment_id' => $r->id,
                'sale_id' => $r->sale_id, 'amount_minor' => (int) $r->amount_minor, 'created_at' => now(), 'updated_at' => now()])->all());

            return $claim;
        });
    }

    /** Sent to the NHIA, with its reference. */
    public function submit(Actor $actor, NhisClaim $claim, string $reference): NhisClaim
    {
        $this->require($actor, $claim->branch);
        if ($claim->status !== 'open') {
            throw new DomainException("Claim {$claim->number} has already been sent.");
        }
        $claim->forceFill(['status' => 'submitted', 'reference' => mb_substr(trim($reference), 0, 80) ?: null, 'submitted_by_name' => $actor->name, 'submitted_at' => now()]);

        return $this->recorder->save($actor, $claim, 'nhis_claim.submitted', branchId: $claim->branch_id);
    }

    /** Taken back before it is sent: its sales are free for the next claim. */
    public function withdraw(Actor $actor, NhisClaim $claim, string $reason): NhisClaim
    {
        $this->require($actor, $claim->branch);
        if ($claim->status !== 'open') {
            throw new DomainException('Only a claim not sent yet can be withdrawn.');
        }

        return $this->recorder->transaction(function () use ($actor, $claim, $reason) {
            DB::table('nhis_claim_lines')->where('nhis_claim_id', $claim->id)->delete();
            $claim->forceFill(['status' => 'withdrawn', 'note' => mb_substr($reason, 0, 255)]);

            return $this->recorder->save($actor, $claim, 'nhis_claim.withdrawn', $reason, $claim->branch_id);
        });
    }

    /** What the NHIA paid; the rest of the claim is written off. Posted to the books by the automatic posting. */
    public function settle(Actor $actor, NhisClaim $claim, int $paidMinor, string $paidOn, string $method, ?string $note = null): NhisClaim
    {
        $this->require($actor, $claim->branch);
        if ($claim->status !== 'submitted') {
            throw new DomainException($claim->status === 'settled' ? "Claim {$claim->number} is already settled." : 'Mark the claim as sent to the NHIA first.');
        }
        if ($paidMinor < 0 || $paidMinor > $claim->claimed_minor) {
            throw new DomainException('The amount paid must be between nothing and the amount claimed.');
        }
        if (! array_key_exists($method, NhisClaim::METHODS)) {
            throw new DomainException('Choose how the NHIA paid.');
        }
        if (Carbon::parse($paidOn)->isAfter(now()->endOfDay())) {
            throw new DomainException('The payment date cannot be in the future.');
        }
        $claim->forceFill(['status' => 'settled', 'paid_minor' => $paidMinor, 'rejected_minor' => $claim->claimed_minor - $paidMinor, 'paid_on' => $paidOn,
            'paid_method' => $method, 'settled_by_name' => $actor->name, 'note' => $note ? mb_substr(trim($note), 0, 255) : $claim->note]);

        return $this->recorder->save($actor, $claim, 'nhis_claim.settled', branchId: $claim->branch_id);
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, self::PERMISSION, $branch)) {
            throw new AccessDenied('NHIS claims need the NHIS claims permission for this branch.');
        }
    }
}

<?php

namespace App\Domain\Finance;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\FiscalPeriod;
use App\Security\Authorizer;

/**
 * Month-end (owner decision 2026-10-12): finance closes months in order once everything dated in them is posted
 * and the ledger ties to its sub-ledgers; nothing can post into a closed month (late documents land in the next
 * open one). Only the general manager or owner reopens, latest month first, with a reason.
 */
final class PeriodService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly AutoPoster $poster,
        private readonly GlCheck $check,
    ) {}

    /** What stands in the way of closing a month (empty = it can be closed). */
    public function blockers(FiscalPeriod $period): array
    {
        $out = [];
        $earlier = FiscalPeriod::where('starts_on', '<', $period->starts_on)->where('status', 'open')->orderBy('starts_on')->first();
        if ($earlier) {
            $out[] = "Close {$earlier->label()} first.";
        }
        foreach ($this->poster->unposted($period->tenant_id, $period->ends_on->toDateString()) as $kind => $n) {
            if ($n > 0) {
                $out[] = "{$n} ".mb_strtolower(AutoPoster::KINDS[$kind]).' dated up to '.$period->ends_on->format('j M').' are not posted yet.';
            }
        }
        $check = $this->check->run($period->tenant_id);
        array_push($out, ...$check['problems']);

        return $out;
    }

    public function close(Actor $actor, FiscalPeriod $period): FiscalPeriod
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.close')) {
            throw new AccessDenied;
        }
        $period = $period->fresh();
        if ($period->status !== 'open') {
            throw new DomainException("{$period->label()} is already closed.");
        }
        $this->poster->run($period->tenant_id);
        if ($blockers = $this->blockers($period)) {
            throw new DomainException("{$period->label()} cannot be closed yet: ".implode(' ', $blockers), 'close_blocked');
        }

        return $this->recorder->transaction(function () use ($actor, $period) {
            $period = FiscalPeriod::lockForUpdate()->findOrFail($period->id);
            $period->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_name' => $actor->name]);

            return $this->recorder->save($actor, $period, 'fiscal_period.closed');
        });
    }

    public function reopen(Actor $actor, FiscalPeriod $period, string $reason): FiscalPeriod
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.reopen')) {
            throw new AccessDenied;
        }
        $period = $period->fresh();
        if (trim($reason) === '') {
            throw new DomainException('Give a reason for reopening.');
        }
        if ($period->status !== 'closed') {
            throw new DomainException("{$period->label()} is open.");
        }
        if ($later = FiscalPeriod::where('starts_on', '>', $period->starts_on)->where('status', 'closed')->orderByDesc('starts_on')->first()) {
            throw new DomainException("Reopen {$later->label()} first (latest month first).");
        }

        return $this->recorder->transaction(function () use ($actor, $period, $reason) {
            $period = FiscalPeriod::lockForUpdate()->findOrFail($period->id);
            $period->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by_name' => null]);

            return $this->recorder->save($actor, $period, 'fiscal_period.reopened', $reason);
        });
    }
}

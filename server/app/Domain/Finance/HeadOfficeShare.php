<?php

namespace App\Domain\Finance;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Security\Authorizer;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sharing head office costs between industries (owner decision 2026-10-13: off by default, an option per installation).
 *
 * For one month, the costs posted at company level (no industry) are shared between the industries in proportion to
 * each industry's sales that month: one journal per month moves each cost account's amount from "Company" to the
 * industries. Amounts are split by largest remainder, so the shares add up to the pesewa. Reversing the journal (Finance →
 * Journals) undoes it, and the month can then be shared again.
 */
final class HeadOfficeShare
{
    public const KIND = 'allocation';

    public function __construct(private readonly GeneralLedger $gl, private readonly Settings $settings, private readonly Authorizer $authz) {}

    public function enabled(string $tenantId): bool
    {
        return (bool) $this->settings->get('finance.share_head_office_costs', $tenantId);
    }

    /**
     * What sharing the month would post.
     *
     * @return array{month: string, from: string, to: string, basis: array<string, int>, names: array<string, string>, lines: list<array{account: Account, amount: int, shares: array<string, int>}>, total: int, done: ?JournalEntry}
     */
    public function proposal(string $tenantId, string $anyDay): array
    {
        $from = CarbonImmutable::parse($anyDay)->startOfMonth();
        $to = $from->endOfMonth();
        $names = DB::table('industries')->where('tenant_id', $tenantId)->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();
        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->get()->keyBy('id');
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $tenantId)->whereBetween('l.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('e.kind', '!=', self::KIND)->whereNotIn('e.id', JournalEntry::withoutGlobalScopes()->where('kind', 'reversal')->whereNotNull('reverses_id')
            ->whereIn('reverses_id', JournalEntry::withoutGlobalScopes()->where('kind', self::KIND)->select('id'))->select('id'))
            ->groupBy('l.account_id', 'l.industry_id')->selectRaw('l.account_id, l.industry_id, SUM(l.amount_minor) AS s')->get();

        // Basis: each industry's sales (income) this month.
        $basis = array_fill_keys(array_keys($names), 0);
        foreach ($lines as $l) {
            if ($l->industry_id && isset($basis[$l->industry_id]) && ($accounts[$l->account_id]->type ?? null) === 'income') {
                $basis[$l->industry_id] += -(int) $l->s;
            }
        }
        $basis = array_filter($basis, fn ($v) => $v > 0);
        $sharesFor = function (int $amount) use ($basis): array {
            $total = array_sum($basis);
            $out = [];
            $rest = [];
            foreach ($basis as $id => $b) {
                $exact = $amount * $b / $total;
                $out[$id] = (int) floor($exact);
                $rest[$id] = $exact - $out[$id];
            }
            arsort($rest);
            foreach (array_slice(array_keys($rest), 0, $amount - array_sum($out)) as $id) {
                $out[$id]++;
            }

            return $out;
        };

        $out = [];
        if ($basis) {
            foreach ($lines as $l) {
                $a = $accounts[$l->account_id] ?? null;
                if ($l->industry_id !== null || ! $a || $a->type !== 'expense' || (int) $l->s <= 0) {
                    continue;
                }
                $out[] = ['account' => $a, 'amount' => (int) $l->s, 'shares' => $sharesFor((int) $l->s)];
            }
            usort($out, fn ($x, $y) => strcmp($x['account']->code, $y['account']->code));
        }

        return ['month' => $from->format('F Y'), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => $basis, 'names' => $names,
            'lines' => $out, 'total' => array_sum(array_column($out, 'amount')), 'done' => $this->done($tenantId, $from)];
    }

    public function post(Actor $actor, string $anyDay): JournalEntry
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.journal')) {
            throw new AccessDenied;
        }
        if (! $this->enabled($actor->tenantId)) {
            throw new DomainException('Sharing head office costs is switched off (System → Settings → Finance).');
        }

        return DB::transaction(function () use ($actor, $anyDay) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ["ho_share|{$actor->tenantId}"]); // one month shared once
            $p = $this->proposal($actor->tenantId, $anyDay);
            if ($p['done']) {
                throw new DomainException("{$p['month']} is already shared ({$p['done']->number}). Reverse that journal first to share it again.");
            }
            if (! $p['basis']) {
                throw new DomainException("No industry has sales in {$p['month']}, so there is nothing to share by.");
            }
            if (! $p['lines']) {
                throw new DomainException("There are no head office costs in {$p['month']} to share.");
            }
            $journal = [];
            foreach ($p['lines'] as $l) {
                $journal[] = ['account' => $l['account'], 'amount' => -$l['amount'], 'industry_id' => null, 'memo' => 'Shared out'];
                foreach ($l['shares'] as $industryId => $share) {
                    $journal[] = ['account' => $l['account'], 'amount' => $share, 'industry_id' => $industryId, 'memo' => 'Head office share'];
                }
            }

            return $this->gl->post($actor, $p['to'], self::KIND, "Head office costs shared by sales, {$p['month']}", $journal, sourceType: $this->tag($p['from']));
        });
    }

    private function done(string $tenantId, CarbonImmutable $from): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kind', self::KIND)->where('source_type', $this->tag($from->toDateString()))
            ->whereNotIn('id', JournalEntry::withoutGlobalScopes()->whereNotNull('reverses_id')->select('reverses_id'))->first();
    }

    private function tag(string $monthStart): string
    {
        return 'ho_share:'.substr($monthStart, 0, 7);
    }
}

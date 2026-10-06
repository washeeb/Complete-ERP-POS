<?php

namespace App\Domain\Finance;

use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only writer of the general ledger. An entry is posted whole (balanced, at least two lines, into an open
 * month) together with the links to the source rows it covers, in one transaction with its audit row and outbox
 * event. The database enforces the same rules (balance, open month, append-only) as a second line of defence.
 */
final class GeneralLedger
{
    /** @var array<string, array<string, Account>> */
    private array $accounts = [];

    public function __construct(private readonly Recorder $recorder, private readonly DocumentNumbers $numbers, private readonly Settings $settings) {}

    /**
     * Post an entry.
     *
     * @param  list<array{account: Account|string, amount: int, branch_id?: ?string, party_type?: ?string, party_id?: ?string, memo?: ?string}>  $lines  + debit, − credit
     * @param  list<array{0: string, 1: string}>  $sources  [source type, source id] rows this entry posts (exactly once)
     * @param  bool  $late  allow a closed month: post on the first day of the next open month instead (automatic posting)
     * @return JournalEntry|null null when every line is zero (the sources are still marked as posted)
     */
    public function post(Actor $actor, string $date, string $kind, string $memo, array $lines, array $sources = [], ?string $sourceType = null,
        ?string $reversesId = null, bool $late = false): ?JournalEntry
    {
        $tenantId = $actor->tenantId;
        $merged = [];
        foreach ($lines as $l) {
            $account = $l['account'] instanceof Account ? $l['account'] : $this->account($tenantId, $l['account']);
            if ($account->is_header) {
                throw new DomainException("{$account->label()} is a heading; post to one of the accounts under it.");
            }
            $key = implode('|', [$account->id, $l['branch_id'] ?? '', $l['party_type'] ?? '', $l['party_id'] ?? '', $l['memo'] ?? '']);
            $merged[$key] ??= ['account_id' => $account->id, 'branch_id' => $l['branch_id'] ?? null, 'party_type' => $l['party_type'] ?? null,
                'party_id' => $l['party_id'] ?? null, 'memo' => isset($l['memo']) ? mb_substr($l['memo'], 0, 255) : null, 'amount_minor' => 0];
            $merged[$key]['amount_minor'] += (int) $l['amount'];
        }
        $merged = array_values(array_filter($merged, fn ($l) => $l['amount_minor'] !== 0));
        $sum = array_sum(array_column($merged, 'amount_minor'));
        if ($sum !== 0) {
            throw new LogicException("Journal \"{$memo}\" does not balance ({$sum}).");
        }

        return $this->recorder->transaction(function () use ($actor, $tenantId, $date, $kind, $memo, $merged, $sources, $sourceType, $reversesId, $late) {
            $link = fn (?string $entryId) => $sources ? DB::table('gl_postings')->insert(array_map(fn ($s) => ['source_type' => $s[0], 'source_id' => $s[1],
                'journal_entry_id' => $entryId, 'tenant_id' => $tenantId], $sources)) : null;
            if (count($merged) < 2) {
                $link(null);

                return null;
            }
            [$period, $entryDate, $moved] = $this->postingDate($tenantId, $date, $late);
            $entry = new JournalEntry;
            $entry->forceFill(['tenant_id' => $tenantId, 'number' => $this->numbers->next($tenantId, 'journal'), 'entry_date' => $entryDate,
                'period_id' => $period->id, 'kind' => $kind, 'source_type' => $sourceType,
                'memo' => mb_substr($moved ? "{$memo} (dated {$date}; that month is closed)" : $memo, 0, 255), 'reverses_id' => $reversesId,
                'currency_code' => Tenant::find($tenantId)->currency_code, 'total_minor' => array_sum(array_filter(array_column($merged, 'amount_minor'), fn ($a) => $a > 0)),
                'posted_by_name' => $actor->name, 'created_at' => now()]);
            $entry->save();
            DB::table('journal_lines')->insert(array_map(fn ($l, $i) => $l + ['id' => (string) Str::uuid7(), 'journal_entry_id' => $entry->id,
                'tenant_id' => $tenantId, 'line_no' => $i + 1, 'entry_date' => $entryDate], $merged, array_keys($merged)));
            $link($entry->id);
            $this->recorder->audit->record($actor, 'journal.posted', $entry, ['number' => [null, $entry->number], 'total_minor' => [null, $entry->total_minor],
                'entry_date' => [null, $entryDate]], $kind === 'auto' ? null : $memo);
            $this->recorder->outbox->publishRaw($actor, 'journal_entry', $entry->id, 1, "journal.{$kind}", ['number' => $entry->number, 'entry_date' => $entryDate,
                'total_minor' => $entry->total_minor]);

            return $entry;
        });
    }

    /** The account automatic posting uses for a purpose; tax accounts fall back to the general tax account. */
    public function account(string $tenantId, string $key): Account
    {
        if (isset($this->accounts[$tenantId][$key])) {
            return $this->accounts[$tenantId][$key];
        }
        $find = fn (string $k) => Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('system_key', $k)->where('is_active', true)->first();
        $account = $find($key) ?? (str_contains($key, ':') ? $find(strstr($key, ':', true)) : null)
            ?? throw new DomainException("No account is set up for “{$key}”. Choose one under Finance → Chart of accounts.", 'gl_account_missing');

        return $this->accounts[$tenantId][$key] = $account;
    }

    public function forget(): void
    {
        $this->accounts = [];
    }

    /** The month a date falls in (months of its financial year are created on first use). */
    public function period(string $tenantId, string $date): FiscalPeriod
    {
        $find = fn () => FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
        if ($p = $find()) {
            return $p;
        }
        $endMonth = (int) $this->settings->get('finance.year_end_month', $tenantId);
        $d = Carbon::parse($date);
        $year = $d->month > $endMonth ? $d->year + 1 : $d->year; // named by the calendar year it ends in
        $start = Carbon::create($year, $endMonth, 1)->addMonthNoOverflow()->subYear();
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonthsNoOverflow($i);
            $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'year' => $year, 'period' => $i + 1, 'starts_on' => $m->toDateString(),
                'ends_on' => $m->copy()->endOfMonth()->toDateString(), 'status' => 'open', 'origin_node' => (string) config('erp.node.id'),
                'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('fiscal_periods')->insertOrIgnore($rows);

        return $find() ?? throw new LogicException("No month covers {$date}.");
    }

    /**
     * Where something dated $date posts: its own month if open; with $late, else the first day of the next open month.
     *
     * @return array{0: FiscalPeriod, 1: string, 2: bool} [month, entry date, moved]
     */
    public function postingDate(string $tenantId, string $date, bool $late = false): array
    {
        $p = $this->period($tenantId, $date);
        if ($p->status === 'open') {
            return [$p, $date, false];
        }
        if (! $late) {
            throw new DomainException("{$p->label()} is closed. Date the entry in an open month, or ask the general manager to reopen it.", 'period_closed');
        }
        for ($i = 0; $i < 240 && $p->status !== 'open'; $i++) {
            $p = $this->period($tenantId, $p->ends_on->copy()->addDay()->toDateString());
        }

        return [$p, $p->starts_on->toDateString(), true];
    }
}

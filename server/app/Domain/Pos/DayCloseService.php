<?php

namespace App\Domain\Pos;

use App\Domain\Banking\BankingService;
use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\PharmacyRules;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\BackupRun;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\DayClose;
use App\Models\Device;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day close (owner request 2026-10-23). Before the owner or manager leaves, one screen says whether the branch's
 * day is wrapped up — every till closed and counted, the cash over / short, money handed over and banked (with cash
 * control), sales from the tills all received, the night's backup, medicine sales signed off and approvals waiting — and
 * the day is closed with the cash taken to the bank (and slip number) or kept in the safe. A day with a problem can
 * still be closed, with the reason written down. Closing again later (after fixing something) updates the record; every
 * close is in the audit log. Nothing is locked: late sales still post, and show on the next look.
 */
final class DayCloseService
{
    public const PERMISSION = 'day.close';

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly CashControl $cash) {}

    /**
     * @return array{date: string, currency: string, cash_counted: int, over_short: int, checks: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function checks(Branch $branch, string $date): array
    {
        $tenant = Tenant::findOrFail($branch->tenant_id);
        $tz = $branch->timezone ?: ($tenant->timezone ?: 'UTC');
        $from = Carbon::parse($date, $tz)->startOfDay()->utc();
        $to = Carbon::parse($date, $tz)->endOfDay()->utc();
        $m = fn (int $v) => Money::display($v, $tenant->currency_code);
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail');
        };

        // Tills: every shift opened by the end of the day is closed.
        $open = DB::table('shifts')->join('tills', 'tills.id', '=', 'shifts.till_id')->where('shifts.branch_id', $branch->id)->where('shifts.status', 'open')
            ->where('shifts.opened_at', '<=', $to)->get(['tills.name as till', 'shifts.opened_by_name as who']);
        $add('tills', 'Every till closed', $open->isEmpty() ? 'ok' : 'fail', $open->isEmpty() ? 'All shifts are closed and counted.'
            : 'Still open: '.$open->map(fn ($s) => "{$s->till} ({$s->who})")->implode(', ').'. Close them on the till (Close shift).');

        // Cash: counted at the closes of the day, and over / short per cashier.
        $closes = DB::table('shifts')->where('branch_id', $branch->id)->where('status', 'closed')->whereBetween('closed_at', [$from, $to]);
        $counted = (int) (clone $closes)->sum('counted_cash_minor');
        $overShort = (int) (clone $closes)->sum('over_short_minor');
        $short = (clone $closes)->where('over_short_minor', '<', 0)->groupBy('closed_by_name')->selectRaw('closed_by_name, SUM(over_short_minor) AS v')->pluck('v', 'closed_by_name');
        $add('cash', 'Cash counted', $overShort === 0 ? 'ok' : ($overShort < 0 ? 'warn' : 'ok'), (clone $closes)->count().' close(s), '.$m($counted).' counted; '
            .($overShort === 0 ? 'balanced.' : ($overShort > 0 ? 'over '.$m($overShort).'.' : 'short '.$m(-$overShort).': '.$short->map(fn ($v, $who) => "{$who} {$m(-(int) $v)}")->implode(', ').'.')));

        // Cash control (vaults, handovers to a cashier, deposits): only where the company uses it.
        if ($this->cash->enabled($branch->tenant_id)) {
            $handovers = DB::table('cash_handovers')->where('branch_id', $branch->id)->where('business_date', $date);
            $notReceived = (clone $handovers)->where('status', '!=', 'received')->count();
            $notBanked = (clone $handovers)->where('status', 'received')->whereNull('deposit_transfer_id')->count();
            $add('handovers', 'Cash handed over and banked', $notReceived ? 'fail' : ($notBanked ? 'warn' : 'ok'), $notReceived
                ? "{$notReceived} handover(s) not yet received by the controller (Cash → Cash desk)."
                : ($notBanked ? "{$notBanked} handover(s) received but not yet deposited." : 'Handed over, received and banked.'));
            $variances = DB::table('cash_variances')->where('branch_id', $branch->id)->where('status', 'open')->count();
            $add('variances', 'Cash differences decided', $variances ? 'warn' : 'ok', $variances ? "{$variances} difference(s) waiting for a decision (Cash → Cash desk)." : 'None waiting.');
        }

        // Sales from the tills: nothing waiting on a till, nothing refused.
        $waiting = Device::where('branch_id', $branch->id)->where('status', 'active')->whereIn('kind', ['pos', 'handheld'])->get()
            ->filter(fn (Device $d) => (int) (($d->health ?? [])['queue_pending'] ?? 0) > 0)->pluck('name');
        $refused = DB::table('review_items')->where('branch_id', $branch->id)->where('status', 'open')->count();
        $add('sync', 'Every sale received', $waiting->isEmpty() && ! $refused ? 'ok' : 'warn', match (true) {
            $waiting->isNotEmpty() => 'Still to be sent from: '.$waiting->implode(', ').'. Keep those tills switched on and connected.',
            $refused > 0 => "{$refused} item(s) in the review queue (Sales → Review queue).",
            default => 'All tills have sent everything.',
        });

        // Pharmacy: medicine sales still to be signed off by a pharmacist.
        if (app(ModuleService::class)->isEnabled($branch, 'pharmacy')) {
            $unsigned = PharmacyRules::unapproved($branch->tenant_id, [$branch->id], $from->toDateTimeString(), $to->toDateTimeString())->whereNull('so.id')->distinct()->count('s.id');
            $add('signoffs', 'Medicine sales signed off', $unsigned ? 'warn' : 'ok', $unsigned ? "{$unsigned} sale(s) to sign off (Pharmacy → Sales to sign off)." : 'Nothing to sign off.');
        }

        // Approvals waiting at this branch.
        $approvals = array_filter([
            'purchase order(s)' => DB::table('purchase_orders')->where('branch_id', $branch->id)->where('status', 'pending_approval')->count(),
            'stock adjustment(s)' => DB::table('stock_adjustments')->where('branch_id', $branch->id)->where('status', 'submitted')->count(),
            'stock count(s)' => DB::table('stock_counts')->where('branch_id', $branch->id)->where('status', 'submitted')->count(),
            'expense claim(s)' => DB::table('expense_claims')->where('branch_id', $branch->id)->where('status', 'submitted')->count(),
        ]);
        $add('approvals', 'Approvals', $approvals ? 'warn' : 'ok', $approvals ? 'Waiting: '.collect($approvals)->map(fn ($n, $w) => "{$n} {$w}")->implode(', ').'.' : 'Nothing waiting.');

        // Earlier days' deposits: on the bank statement yet? (Bank reconciliation, statements imported from the bank.)
        $late = DayClose::where('branch_id', $branch->id)->whereNotNull('deposit_transfer_id')->where('business_date', '<', $date)
            ->where('business_date', '>=', Carbon::parse($date)->subDays(30)->toDateString())->orderBy('business_date')->get()
            ->filter(fn (DayClose $c) => in_array(self::depositStatus($c)['status'], ['missing', 'waiting'], true) && $c->business_date->lt(Carbon::parse($date)->subDays(2)));
        if (DayClose::where('branch_id', $branch->id)->whereNotNull('deposit_transfer_id')->exists()) {
            $add('deposits', 'Deposits on the bank statement', $late->isEmpty() ? 'ok' : 'warn', $late->isEmpty() ? 'Every deposit of the last 30 days is on the statement (or too recent to check).'
                : $late->map(fn (DayClose $c) => $m((int) $c->banked_minor).' of '.$c->business_date->format('j M').' (slip '.$c->bank_ref.'): '.self::depositStatus($c)['text'])->implode('; ').'.');
        }

        // The server's backup (the whole company's data).
        $backup = BackupRun::where('status', 'ok')->latest('started_at')->first();
        $add('backup', 'Backup', $backup && $backup->started_at->gt(now()->subHours(26)) ? 'ok' : 'fail',
            $backup ? 'Last good backup '.$backup->started_at->diffForHumans().'.' : 'No good backup yet (System → Backups).');

        return ['date' => $date, 'currency' => $tenant->currency_code, 'cash_counted' => $counted, 'over_short' => $overShort, 'checks' => $checks];
    }

    /**
     * Is the day's deposit on the bank statement? matched (cleared in Bank reconciliation), found (a statement line of
     * that amount within 5 days, not matched yet), missing (the statement goes past the day but has no such line),
     * waiting (no statement imported that far yet).
     *
     * @return array{status: string, text: string}
     */
    public static function depositStatus(DayClose $c): array
    {
        if (! $c->deposit_transfer_id) {
            return ['status' => 'none', 'text' => '—'];
        }
        $bank = BankAccount::find($c->bank_account_id);
        $entry = DB::table('gl_postings')->where('source_type', 'money_transfer')->where('source_id', $c->deposit_transfer_id)->value('journal_entry_id');
        $line = $entry && $bank ? DB::table('journal_lines')->where('journal_entry_id', $entry)->where('account_id', $bank->account_id)->value('id') : null;
        if ($line && DB::table('bank_matched_lines')->where('journal_line_id', $line)->exists()) {
            return ['status' => 'matched', 'text' => 'on the statement ✓'];
        }
        $day = $c->business_date->copy();
        if ($bank && DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->where('status', 'open')->where('amount_minor', $c->banked_minor)
            ->whereBetween('line_date', [$day->toDateString(), $day->copy()->addDays(5)->toDateString()])->exists()) {
            return ['status' => 'found', 'text' => 'on the statement — match it in Finance → Bank reconciliation'];
        }
        $last = $bank ? DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->max('line_date') : null;

        return $last && $last >= $day->copy()->addDays(3)->toDateString()
            ? ['status' => 'missing', 'text' => 'NOT on the bank statement — check with the bank']
            : ['status' => 'waiting', 'text' => 'import the bank statement to check'];
    }

    /** @param array{banked?: ?string, bank_ref?: ?string, bank_account_id?: ?string, safe?: ?string, note?: ?string} $data amounts in currency units */
    public function close(Actor $actor, Branch $branch, string $date, array $data): DayClose
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, self::PERMISSION, $branch)) {
            throw new AccessDenied;
        }
        $today = Carbon::now($branch->timezone ?: (Tenant::find($branch->tenant_id)?->timezone ?: 'UTC'))->toDateString();
        if ($date > $today) {
            throw new DomainException('A day can be closed from that day on, not before.');
        }
        $state = $this->checks($branch, $date);
        $problems = collect($state['checks'])->where('status', 'fail')->count();
        $note = trim((string) ($data['note'] ?? ''));
        if ($problems && $note === '') {
            throw new DomainException('Something is not right yet ('.collect($state['checks'])->where('status', 'fail')->pluck('label')->implode(', ').'). Fix it, or write why the day is closed anyway.');
        }
        $money = fn ($v) => filled($v) ? Money::toMinor((string) $v, $state['currency']) : null;
        $banked = $money($data['banked'] ?? null);
        $ref = trim((string) ($data['bank_ref'] ?? ''));
        if ($banked && $ref === '') {
            throw new DomainException('Enter the deposit slip number for the cash taken to the bank.');
        }
        $close = DayClose::firstOrNew(['branch_id' => $branch->id, 'business_date' => $date]);
        // The deposit goes into the books as money moved from the tills' cash to the bank (not with cash control: each
        // handover's deposit is recorded there). Once recorded it is not changed here.
        $bank = null;
        if ($banked && ! $this->cash->enabled($branch->tenant_id) && ! $close->deposit_transfer_id) {
            $bank = BankAccount::where('is_active', true)->where('kind', 'bank')->find($data['bank_account_id'] ?? null)
                ?? throw new DomainException('Choose the bank account the cash was paid into (Finance → Bank & cash lists them).');
        }
        if ($close->deposit_transfer_id && $banked !== $close->banked_minor) {
            throw new DomainException('The deposit of '.Money::display((int) $close->banked_minor, $state['currency']).' is in the books already. Correct it in Finance (money moved), not here.');
        }
        $close->forceFill(['tenant_id' => $branch->tenant_id, 'cash_counted_minor' => $state['cash_counted'], 'over_short_minor' => $state['over_short'],
            'banked_minor' => $banked, 'bank_ref' => $ref ?: null, 'safe_minor' => $money($data['safe'] ?? null), 'checks' => $state['checks'], 'problems' => $problems,
            'note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'closed_by' => $actor->id, 'closed_by_name' => mb_substr($actor->name, 0, 120), 'closed_at' => now()]);

        return $this->recorder->transaction(function () use ($actor, $close, $branch, $bank, $banked, $ref, $date, $note) {
            if ($bank) {
                $till = Account::where('system_key', 'cash_till')->first() ?? throw new DomainException('Set up the chart of accounts first (Finance).');
                $t = app(BankingService::class)->transfer($actor, $till, $bank->account, $branch, $branch, (int) $banked, $date, "Deposit slip {$ref}",
                    "Close the day {$date}", permission: self::PERMISSION);
                $close->forceFill(['bank_account_id' => $bank->id, 'deposit_transfer_id' => $t->id]);
            }

            return $this->recorder->save($actor, $close, $close->exists ? 'day.closed_again' : 'day.closed', $note ?: null, $branch->id);
        });
    }
}

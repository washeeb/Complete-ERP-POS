<?php

namespace App\Domain\Banking;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\MoneyTransfer;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bank, MoMo and cash accounts: statements in (CSV), matched against the ledger lines on the account, and money
 * moved between the company's own accounts. Matching is many-to-many as long as the amounts agree (one bank
 * deposit for three days' takings; one day's MoMo sales against many wallet credits).
 */
final class BankingService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly GeneralLedger $gl,
    ) {}

    /** Money accounts: posting asset accounts outside any sub-ledger. */
    public static function isMoneyAccount(Account $a): bool
    {
        return $a->type === 'asset' && ! $a->is_header && ! $a->control && $a->is_active;
    }

    /** @param array{account_id?: ?string, code?: ?string, kind: string, name: string, provider?: ?string, number?: ?string, is_active?: bool} $data */
    public function saveAccount(Actor $actor, BankAccount $bank, array $data): BankAccount
    {
        $this->require($actor);
        if (! array_key_exists($data['kind'] ?? '', BankAccount::KINDS) || trim((string) ($data['name'] ?? '')) === '') {
            throw new DomainException('Give the account a name and say whether it is a bank, MoMo or cash account.');
        }

        return $this->recorder->transaction(function () use ($actor, $bank, $data) {
            $account = ! empty($data['account_id']) ? Account::findOrFail($data['account_id']) : null;
            if (! $account && ! $bank->exists) {
                $code = trim((string) ($data['code'] ?? ''));
                if ($code === '' || Account::where('code', $code)->exists()) {
                    throw new DomainException('Choose its ledger account, or give a new, unused account code.');
                }
                $account = (new Account)->forceFill(['tenant_id' => $actor->tenantId, 'code' => $code, 'name' => mb_substr(trim($data['name']), 0, 255), 'type' => 'asset',
                    'is_header' => false, 'is_active' => true, 'parent_id' => Account::where('system_key', 'bank')->value('parent_id')]);
                $this->recorder->save($actor, $account, 'account.created');
            }
            $account ??= Account::findOrFail($bank->account_id);
            if (! self::isMoneyAccount($account)) {
                throw new DomainException("{$account->label()} cannot hold money: choose an asset posting account that no sub-ledger uses.");
            }
            if (BankAccount::where('account_id', $account->id)->when($bank->exists, fn ($q) => $q->whereKeyNot($bank->id))->exists()) {
                throw new DomainException("{$account->label()} is already linked to another bank or MoMo account.");
            }
            $bank->forceFill(['tenant_id' => $actor->tenantId, 'account_id' => $account->id, 'kind' => $data['kind'], 'name' => mb_substr(trim($data['name']), 0, 255),
                'provider' => ($data['provider'] ?? null) ?: null, 'number' => ($data['number'] ?? null) ?: null, 'is_active' => (bool) ($data['is_active'] ?? true)]);

            return $this->recorder->save($actor, $bank, $bank->exists ? 'bank_account.updated' : 'bank_account.created');
        });
    }

    /** Money moved between the company's own accounts (e.g. till cash banked, petty cash topped up, MoMo swept to the bank). */
    public function transfer(Actor $actor, Account $from, Account $to, Branch $fromBranch, Branch $toBranch, int $amountMinor, string $date,
        ?string $reference = null, ?string $note = null): MoneyTransfer
    {
        $this->require($actor);
        if ($from->id === $to->id) {
            throw new DomainException('Choose two different accounts.');
        }
        foreach ([$from, $to] as $a) {
            if (! self::isMoneyAccount($a)) {
                throw new DomainException("{$a->label()} is not a cash, bank or mobile money account.");
            }
        }
        if ($amountMinor <= 0) {
            throw new DomainException('Enter an amount above zero.');
        }
        $day = Carbon::parse($date);
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('A transfer cannot be dated in the future.');
        }

        return $this->recorder->transaction(function () use ($actor, $from, $to, $fromBranch, $toBranch, $amountMinor, $day, $reference, $note) {
            $t = (new MoneyTransfer)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'money_transfer'),
                'from_account_id' => $from->id, 'to_account_id' => $to->id, 'branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id, 'amount_minor' => $amountMinor,
                'moved_on' => $day->toDateString(), 'reference' => $reference ? mb_substr(trim($reference), 0, 80) : null, 'note' => $note ? mb_substr($note, 0, 255) : null,
                'moved_by_name' => $actor->name]);

            return $this->recorder->save($actor, $t, 'money_transfer.made', branchId: $fromBranch->id);
        });
    }

    /**
     * Add a statement file's lines; lines already imported (same date, amount, text and position among identical lines) are skipped.
     *
     * @return array{added: int, skipped: int}
     */
    public function import(Actor $actor, BankAccount $bank, string $fileName, string $contents, array $mapping): array
    {
        $this->require($actor);
        $rows = StatementCsv::parse($contents, $mapping);
        if ($rows === []) {
            throw new DomainException('No statement lines found with that column mapping.');
        }

        return $this->recorder->transaction(function () use ($actor, $bank, $fileName, $rows, $mapping) {
            $importId = (string) Str::uuid7();
            DB::table('bank_statement_imports')->insert(['id' => $importId, 'tenant_id' => $bank->tenant_id, 'bank_account_id' => $bank->id,
                'file_name' => mb_substr($fileName, 0, 255), 'lines_added' => 0, 'lines_skipped' => 0, 'imported_by_name' => $actor->name, 'created_at' => now()]);
            $seen = [];
            $added = 0;
            foreach ($rows as $r) {
                $key = implode('|', [$r['line_date'], $r['amount_minor'], $r['description'], $r['reference'], $r['balance_minor']]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $added += DB::table('bank_statement_lines')->insertOrIgnore($r + ['id' => (string) Str::uuid7(), 'tenant_id' => $bank->tenant_id,
                    'bank_account_id' => $bank->id, 'import_id' => $importId, 'hash' => hash('sha256', $key.'#'.$seen[$key]), 'status' => 'open', 'created_at' => now()]);
            }
            DB::table('bank_statement_imports')->where('id', $importId)->update(['lines_added' => $added, 'lines_skipped' => count($rows) - $added,
                'from_date' => min(array_column($rows, 'line_date')), 'to_date' => max(array_column($rows, 'line_date'))]);
            $bank->csv_mapping = array_intersect_key($mapping, array_flip(['delimiter', 'header_rows', 'date', 'date_format', 'description', 'reference', 'amount',
                'money_in', 'money_out', 'balance']));
            $this->recorder->save($actor, $bank, 'bank_statement.imported', null, null, ['file' => $fileName, 'added' => $added]);

            return ['added' => $added, 'skipped' => count($rows) - $added];
        });
    }

    /** Ledger lines on the account not yet matched to the statement (newest last). */
    public function unmatchedLedger(BankAccount $bank, ?string $upTo = null): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_matched_lines as m', 'm.journal_line_id', '=', 'l.id')
            ->where('l.account_id', $bank->account_id)->whereNull('m.journal_line_id')->when($upTo, fn ($q) => $q->where('l.entry_date', '<=', $upTo))
            ->orderBy('l.entry_date')->orderBy('e.number')
            ->get(['l.id', 'l.entry_date', 'l.amount_minor', 'e.number', 'e.memo', 'l.memo as line_memo'])->all();
    }

    /** One-to-one suggestions: same amount, within 5 days, and the only candidate both ways. @return array<string, string> statement line id → ledger line id */
    public function suggestions(BankAccount $bank): array
    {
        $ledger = collect($this->unmatchedLedger($bank));
        $lines = BankStatementLine::where('bank_account_id', $bank->id)->where('status', 'open')->get();
        $out = [];
        foreach ($lines as $s) {
            $near = $ledger->filter(fn ($l) => (int) $l->amount_minor === $s->amount_minor && abs(Carbon::parse($l->entry_date)->diffInDays($s->line_date, false)) <= 5);
            if ($near->count() !== 1) {
                continue;
            }
            $l = $near->first();
            $rivals = $lines->filter(fn ($o) => $o->amount_minor === $s->amount_minor && abs(Carbon::parse($l->entry_date)->diffInDays($o->line_date, false)) <= 5);
            if ($rivals->count() === 1) {
                $out[$s->id] = $l->id;
            }
        }

        return $out;
    }

    public function autoMatch(Actor $actor, BankAccount $bank): int
    {
        $n = 0;
        foreach ($this->suggestions($bank) as $statementId => $ledgerId) {
            $this->match($actor, $bank, [$statementId], [$ledgerId]);
            $n++;
        }

        return $n;
    }

    /** Clear statement lines against ledger lines whose amounts add up to the same total. */
    public function match(Actor $actor, BankAccount $bank, array $statementIds, array $ledgerIds): string
    {
        $this->require($actor);
        if ($statementIds === [] || $ledgerIds === []) {
            throw new DomainException('Tick at least one statement line and one ledger line.');
        }

        return $this->recorder->transaction(function () use ($actor, $bank, $statementIds, $ledgerIds) {
            $lines = BankStatementLine::where('bank_account_id', $bank->id)->whereIn('id', $statementIds)->where('status', 'open')->lockForUpdate()->get();
            $ledger = DB::table('journal_lines')->where('account_id', $bank->account_id)->whereIn('id', $ledgerIds)->lockForUpdate()->get(['id', 'amount_minor']);
            if ($lines->count() !== count(array_unique($statementIds)) || $ledger->count() !== count(array_unique($ledgerIds))
                || DB::table('bank_matched_lines')->whereIn('journal_line_id', $ledgerIds)->exists()) {
                throw new DomainException('Some of those lines are already matched or belong to another account.');
            }
            $a = (int) $lines->sum('amount_minor');
            $b = (int) $ledger->sum('amount_minor');
            if ($a !== $b) {
                throw new DomainException('The statement lines ('.number_format($a / 100, 2).') and the ledger lines ('.number_format($b / 100, 2).') do not add up to the same amount.');
            }
            $id = (string) Str::uuid7();
            DB::table('bank_matches')->insert(['id' => $id, 'tenant_id' => $bank->tenant_id, 'bank_account_id' => $bank->id, 'amount_minor' => $a,
                'matched_by_name' => $actor->name, 'created_at' => now()]);
            DB::table('bank_matched_lines')->insert(array_map(fn ($l) => ['journal_line_id' => $l, 'match_id' => $id], array_values(array_unique($ledgerIds))));
            BankStatementLine::whereIn('id', $statementIds)->update(['status' => 'matched', 'match_id' => $id]);
            $this->recorder->audit->recordRef($actor, 'bank.matched', 'bank_account', $bank->id, ['amount_minor' => [null, $a], 'statement_lines' => [null, count($statementIds)],
                'ledger_lines' => [null, count($ledgerIds)]]);

            return $id;
        });
    }

    /** Ledger lines from before the first imported statement (e.g. the opening balance) are cleared without a statement line. */
    public function clearAsEarlier(Actor $actor, BankAccount $bank, array $ledgerIds): void
    {
        $this->require($actor);
        $first = BankStatementLine::where('bank_account_id', $bank->id)->min('line_date');
        $lines = DB::table('journal_lines')->where('account_id', $bank->account_id)->whereIn('id', $ledgerIds)->get(['id', 'amount_minor', 'entry_date']);
        if ($ledgerIds === [] || $lines->count() !== count(array_unique($ledgerIds)) || ! $first || $lines->contains(fn ($l) => $l->entry_date >= $first)) {
            throw new DomainException('Only ledger lines dated before the first imported statement line can be cleared this way.');
        }
        $this->recorder->transaction(function () use ($actor, $bank, $lines) {
            if (DB::table('bank_matched_lines')->whereIn('journal_line_id', $lines->pluck('id'))->exists()) {
                throw new DomainException('Some of those lines are already matched.');
            }
            $id = (string) Str::uuid7();
            DB::table('bank_matches')->insert(['id' => $id, 'tenant_id' => $bank->tenant_id, 'bank_account_id' => $bank->id, 'amount_minor' => (int) $lines->sum('amount_minor'),
                'matched_by_name' => $actor->name, 'created_at' => now()]);
            DB::table('bank_matched_lines')->insert($lines->map(fn ($l) => ['journal_line_id' => $l->id, 'match_id' => $id])->all());
            $this->recorder->audit->recordRef($actor, 'bank.cleared_earlier', 'bank_account', $bank->id, ['amount_minor' => [null, (int) $lines->sum('amount_minor')]]);
        });
    }

    public function unmatch(Actor $actor, BankAccount $bank, string $matchId): void
    {
        $this->require($actor);
        $this->recorder->transaction(function () use ($actor, $bank, $matchId) {
            BankStatementLine::where('bank_account_id', $bank->id)->where('match_id', $matchId)->update(['status' => 'open', 'match_id' => null]);
            DB::table('bank_matches')->where('id', $matchId)->where('bank_account_id', $bank->id)->delete(); // matched ledger lines go with it
            $this->recorder->audit->recordRef($actor, 'bank.unmatched', 'bank_account', $bank->id, ['match' => [$matchId, null]]);
        });
    }

    /** A statement line with nothing behind it in the books (bank charges, interest, a MoMo fee): post it and match it in one go. */
    public function postFromStatement(Actor $actor, BankAccount $bank, BankStatementLine $line, Account $other, Branch $branch, ?string $memo = null): void
    {
        $this->require($actor);
        if ($line->bank_account_id !== $bank->id || $line->status !== 'open') {
            throw new DomainException('That statement line is already dealt with.');
        }
        if ($other->is_header || $other->control || ! $other->is_active || $other->id === $bank->account_id) {
            throw new DomainException("Post it to an expense, income or other posting account (not {$other->label()}).");
        }
        $this->recorder->transaction(function () use ($actor, $bank, $line, $other, $branch, $memo) {
            $entry = $this->gl->post($actor, $line->line_date->toDateString(), 'manual', mb_substr($memo ?: ($line->description ?: 'Bank statement line'), 0, 255), [
                ['account' => Account::findOrFail($bank->account_id), 'amount' => $line->amount_minor, 'branch_id' => $branch->id],
                ['account' => $other, 'amount' => -$line->amount_minor, 'branch_id' => $branch->id],
            ], [['bank_statement_line', $line->id]], 'bank_statement');
            $ledgerLine = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('account_id', $bank->account_id)->value('id');
            $this->match($actor, $bank, [$line->id], [$ledgerLine]);
        });
    }

    public function ignore(Actor $actor, BankStatementLine $line, string $reason): void
    {
        $this->require($actor);
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }
        $this->recorder->transaction(function () use ($actor, $line, $reason) {
            $line->forceFill(['status' => 'ignored'])->save();
            $this->recorder->audit->recordRef($actor, 'bank.line_ignored', 'bank_account', $line->bank_account_id,
                ['line' => [null, "{$line->line_date->toDateString()} {$line->amount_minor} {$line->description}"]], $reason);
        });
    }

    /**
     * Reconciliation at a date: ledger balance, what is in the books but not yet on the statement (outstanding),
     * what is on the statement but not yet in the books, and the statement balance they should explain.
     *
     * @return array{ledger: int, outstanding: int, unrecorded: int, expected_statement: int, statement: ?int, difference: ?int}
     */
    public function reconciliation(BankAccount $bank, string $asAt): array
    {
        $ledger = (int) DB::table('journal_lines')->where('account_id', $bank->account_id)->where('entry_date', '<=', $asAt)->sum('amount_minor');
        $outstanding = array_sum(array_map(fn ($l) => (int) $l->amount_minor, $this->unmatchedLedger($bank, $asAt)));
        $unrecorded = (int) BankStatementLine::where('bank_account_id', $bank->id)->where('status', 'open')->where('line_date', '<=', $asAt)->sum('amount_minor');
        $last = BankStatementLine::where('bank_account_id', $bank->id)->where('line_date', '<=', $asAt)->whereNotNull('balance_minor')
            ->orderByDesc('line_date')->orderByDesc('id')->first(); // uuid7: file order
        $expected = $ledger - $outstanding + $unrecorded;

        return ['ledger' => $ledger, 'outstanding' => $outstanding, 'unrecorded' => $unrecorded, 'expected_statement' => $expected,
            'statement' => $last?->balance_minor, 'difference' => $last ? $last->balance_minor - $expected : null];
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'banking.manage')) {
            throw new AccessDenied;
        }
    }
}

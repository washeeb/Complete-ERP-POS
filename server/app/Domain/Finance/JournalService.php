<?php

namespace App\Domain\Finance;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;

/**
 * Journals typed by finance: ordinary journals, the opening trial balance at go-live, and reversals. Control
 * accounts (customers, suppliers, stock, gift cards, points) are posted only by their sub-ledgers — a journal there
 * would make the trial balance disagree with the sub-ledger — so balances for those come in through the customer,
 * supplier and stock opening screens.
 */
final class JournalService
{
    public function __construct(private readonly GeneralLedger $gl, private readonly Authorizer $authz) {}

    /**
     * @param  list<array{account_id: string, debit_minor?: int, credit_minor?: int, branch_id?: ?string, memo?: ?string}>  $lines
     */
    public function manual(Actor $actor, string $date, string $memo, array $lines): JournalEntry
    {
        $this->require($actor);

        return $this->gl->post($actor, $this->day($date), 'manual', $this->memo($memo), $this->lines($lines))
            ?? throw new DomainException('Enter amounts on at least two lines.');
    }

    /**
     * Opening trial balance on the go-live date. Lines need not balance: the difference goes to "Opening balances",
     * which should come to zero once customer, supplier and stock balances are in too.
     */
    public function opening(Actor $actor, string $date, array $lines): JournalEntry
    {
        $this->require($actor);
        $lines = $this->lines($lines, mustBalance: false);
        $diff = -array_sum(array_column($lines, 'amount'));
        if ($diff !== 0) {
            $lines[] = ['account' => 'opening_equity', 'amount' => $diff, 'branch_id' => $lines[0]['branch_id'] ?? null];
        }

        return $this->gl->post($actor, $this->day($date), 'opening', 'Opening balances', $lines)
            ?? throw new DomainException('Enter the opening balances.');
    }

    /** Cancel a typed journal with an equal and opposite one (automatic entries are corrected through their documents). */
    public function reverse(Actor $actor, JournalEntry $entry, string $date, string $reason): JournalEntry
    {
        $this->require($actor);
        if (! in_array($entry->kind, ['manual', 'opening'], true)) {
            throw new DomainException('Automatic entries are corrected by reversing the document they came from (credit note, goods-received reversal, return).');
        }
        if (JournalEntry::where('reverses_id', $entry->id)->exists()) {
            throw new DomainException("{$entry->number} is already reversed.");
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }
        $lines = $entry->lines->map(fn ($l) => ['account' => Account::findOrFail($l->account_id), 'amount' => -$l->amount_minor, 'branch_id' => $l->branch_id,
            'party_type' => $l->party_type, 'party_id' => $l->party_id, 'memo' => $l->memo])->all();

        return $this->gl->post($actor, $this->day($date), 'reversal', $this->memo("Reversal of {$entry->number}: {$reason}"), $lines, reversesId: $entry->id);
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.journal')) {
            throw new AccessDenied;
        }
    }

    private function day(string $date): string
    {
        $d = Carbon::parse($date);
        if ($d->isAfter(now()->endOfDay())) {
            throw new DomainException('A journal cannot be dated in the future.');
        }

        return $d->toDateString();
    }

    private function memo(string $memo): string
    {
        $memo = trim($memo);
        if ($memo === '') {
            throw new DomainException('Describe what the journal is for.');
        }

        return mb_substr($memo, 0, 255);
    }

    private function lines(array $lines, bool $mustBalance = true): array
    {
        $out = [];
        foreach ($lines as $l) {
            $dr = (int) ($l['debit_minor'] ?? 0);
            $cr = (int) ($l['credit_minor'] ?? 0);
            if ($dr === 0 && $cr === 0) {
                continue;
            }
            if ($dr < 0 || $cr < 0 || ($dr > 0 && $cr > 0)) {
                throw new DomainException('Each line is either a debit or a credit, above zero.');
            }
            $account = Account::find($l['account_id'] ?? null) ?? throw new DomainException('Choose an account on every line.');
            if ($account->is_header || ! $account->is_active) {
                throw new DomainException("{$account->label()} cannot be posted to.");
            }
            if ($account->control) {
                throw new DomainException("{$account->label()} is kept by its own ledger (".Account::CONTROLS[$account->control].'); use that screen instead.');
            }
            $out[] = ['account' => $account, 'amount' => $dr - $cr, 'branch_id' => $l['branch_id'] ?? null, 'memo' => $l['memo'] ?? null];
        }
        if ($mustBalance) {
            if (count($out) < 2) {
                throw new DomainException('A journal needs at least two lines.');
            }
            $diff = array_sum(array_column($out, 'amount'));
            if ($diff !== 0) {
                throw new DomainException('Debits and credits differ by '.number_format(abs($diff) / 100, 2).'.');
            }
        }

        return $out;
    }
}

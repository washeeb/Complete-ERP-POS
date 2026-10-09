<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Finance\JournalService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening trial balance (data migration): the old books' balances on the go-live date, posted as one opening journal.
 * Customers, suppliers, stock, gift cards and loyalty points are kept by their own ledgers, so their rows are skipped
 * here and come in through their own imports; whatever does not balance goes to "Opening balances (to clear)", which
 * comes to zero once everything is loaded (Go-live → Check the figures).
 */
final class OpeningBalancesImporter extends Importer
{
    /** @var list<array{account_id: string, debit_minor: int, credit_minor: int, branch_id: ?string, memo: ?string}> */
    private array $lines = [];

    public function __construct(private readonly JournalService $journals) {}

    public function key(): string
    {
        return 'opening_balances';
    }

    public function label(): string
    {
        return 'Opening trial balance (migration)';
    }

    public function description(): string
    {
        return 'One row per account with its balance on the go-live date (the "as of" date below), from the old trial balance. '
            .'account is the code, "1010 Cash at bank", or the name. Rows for customers, suppliers, stock, gift cards and loyalty points are skipped: '
            .'load those with their own imports. Everything is posted as one opening journal; load it once (reverse that journal to load again).';
    }

    public function permission(): string
    {
        return 'finance.journal';
    }

    public function optionDefaults(): array
    {
        return ['as_of' => now()->toDateString()];
    }

    public function columns(): array
    {
        return [
            ['name' => 'account', 'required' => true, 'help' => 'Account code, "code name", or the account name.', 'example' => '1010'],
            ['name' => 'debit', 'required' => false, 'help' => 'Debit balance (assets, expenses).', 'example' => '12500.00'],
            ['name' => 'credit', 'required' => false, 'help' => 'Credit balance (liabilities, capital, income).', 'example' => ''],
            ['name' => 'branch', 'required' => false, 'help' => 'Branch code, when the balance belongs to one branch.', 'example' => 'HQ'],
            ['name' => 'memo', 'required' => false, 'help' => 'Note on the line.', 'example' => ''],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['account'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $account = Lookup::account(self::need($row, 'account', 'the account'));
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $dr = self::has($row, 'debit') ? Money::toMinor(self::number($row['debit'], 'debit'), $currency) : 0;
        $cr = self::has($row, 'credit') ? Money::toMinor(self::number($row['credit'], 'credit'), $currency) : 0;
        if ($dr < 0 || $cr < 0) { // a negative debit is a credit, and the other way round
            [$dr, $cr] = [max($dr, 0) + max(-$cr, 0), max($cr, 0) + max(-$dr, 0)];
        }
        $net = $dr - $cr;
        if ($account->control) {
            return 'skipped'; // kept by its own ledger: customers, suppliers, stock, gift cards, points
        }
        if ($account->is_header || ! $account->is_active) {
            throw new DomainException("{$account->label()} is a heading or switched off: put the balance on a posting account.");
        }
        if ($net === 0) {
            return 'unchanged';
        }
        $branch = self::has($row, 'branch') ? (Branch::where('code', strtoupper(trim($row['branch'])))->value('id') ?? throw new DomainException("No branch {$row['branch']}.")) : null;
        $this->lines[] = ['account_id' => $account->id, 'debit_minor' => max($net, 0), 'credit_minor' => max(-$net, 0), 'branch_id' => $branch,
            'memo' => self::has($row, 'memo') ? mb_substr(trim($row['memo']), 0, 255) : null];

        return 'added';
    }

    public function finish(Actor $actor, array $options, ?Model $context): void
    {
        if ($this->lines === []) {
            throw new DomainException('There are no balances to post.');
        }
        $loaded = JournalEntry::where('kind', 'opening')->whereNotIn('id', JournalEntry::whereNotNull('reverses_id')->select('reverses_id'))->value('number');
        if ($loaded) {
            throw new DomainException("Opening balances were already loaded ({$loaded}). Reverse that journal first (Finance → Journals) to load them again.");
        }
        $this->journals->opening($actor, (string) ($options['as_of'] ?? now()->toDateString()), $this->lines);
    }
}

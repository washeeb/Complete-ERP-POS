<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Finance\AccountService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Chart of accounts (data migration): the accounts the old books used, so the opening trial balance can be loaded.
 * Matched by code, else by exact name; a match only changes the name and description (an account with postings keeps
 * its type). Types can be ours or QuickBooks' ("Bank", "Other Current Liabilities"…). Without a number, the next free
 * code in the type's range is used (assets 1xxx, liabilities 2xxx, equity 3xxx, income 4xxx, expenses 5xxx–6xxx).
 */
final class AccountsImporter extends Importer
{
    /** QuickBooks (and common) account types → ours. Keys are lower-case without punctuation. */
    private const TYPE_NAMES = [
        'asset' => 'asset', 'assets' => 'asset', 'bank' => 'asset', 'cash' => 'asset', 'accounts receivable' => 'asset', 'ar' => 'asset',
        'other current asset' => 'asset', 'other current assets' => 'asset', 'current asset' => 'asset', 'fixed asset' => 'asset', 'fixed assets' => 'asset',
        'other asset' => 'asset', 'other assets' => 'asset', 'inventory' => 'asset', 'stock' => 'asset',
        'liability' => 'liability', 'liabilities' => 'liability', 'accounts payable' => 'liability', 'ap' => 'liability', 'credit card' => 'liability',
        'other current liability' => 'liability', 'other current liabilities' => 'liability', 'current liability' => 'liability',
        'long term liability' => 'liability', 'long term liabilities' => 'liability', 'loan' => 'liability',
        'equity' => 'equity', 'capital' => 'equity',
        'income' => 'income', 'revenue' => 'income', 'sales' => 'income', 'other income' => 'income',
        'expense' => 'expense', 'expenses' => 'expense', 'cost of goods sold' => 'expense', 'cost of sales' => 'expense', 'cogs' => 'expense',
        'other expense' => 'expense', 'other expenses' => 'expense',
    ];

    private const RANGES = ['asset' => [1000, 1999], 'liability' => [2000, 2999], 'equity' => [3000, 3999], 'income' => [4000, 4999], 'expense' => [5000, 6999]];

    public function __construct(private readonly AccountService $accounts) {}

    public function key(): string
    {
        return 'accounts';
    }

    public function label(): string
    {
        return 'Chart of accounts (migration)';
    }

    public function description(): string
    {
        return 'One row per account from the old books, matched by code, else by the exact name. A match only gets the new name and description. '
            .'type can be asset, liability, equity, income, expense, or the QuickBooks type (Bank, Accounts Receivable, Other Current Liabilities, Cost of Goods Sold…). '
            .'Without a code, the next free number for the type is used. Your accountant should check the chart afterwards (Finance → Chart of accounts).';
    }

    public function permission(): string
    {
        return 'finance.accounts.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => false, 'help' => 'Account number. Empty = the next free number for the type.', 'example' => '1015'],
            ['name' => 'name', 'required' => true, 'help' => 'Account name.', 'example' => 'Ecobank current account'],
            ['name' => 'type', 'required' => 'new', 'help' => 'asset, liability, equity, income, expense — or the QuickBooks type.', 'example' => 'Bank'],
            ['name' => 'parent', 'required' => false, 'help' => 'Code of the heading account it belongs under.', 'example' => '1000'],
            ['name' => 'is_header', 'required' => false, 'help' => 'yes = a heading that groups other accounts (no postings).', 'example' => 'no'],
            ['name' => 'description', 'required' => false, 'help' => 'What the account is for.', 'example' => ''],
        ];
    }

    public function reference(array $row): string
    {
        return trim(($row['code'] ?? '').' '.($row['name'] ?? ''));
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $name = self::need($row, 'name', 'the account name');
        $account = self::has($row, 'code') ? Account::where('code', trim($row['code']))->first() : null;
        $account ??= Account::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($account) {
            $description = self::has($row, 'description') ? trim($row['description']) : $account->description;
            if ($account->name === $name && $account->description === $description) {
                return 'unchanged';
            }
            $this->accounts->save($actor, $account, ['code' => $account->code, 'name' => $name, 'type' => $account->type, 'parent_id' => $account->parent_id,
                'is_header' => $account->is_header, 'system_key' => $account->system_key, 'is_active' => $account->is_active, 'description' => $description]);

            return 'updated';
        }
        $type = self::type(self::need($row, 'type', 'the account type (new account)'));
        $parent = self::has($row, 'parent') ? (Account::where('code', trim($row['parent']))->first() ?? throw new DomainException("No heading account {$row['parent']}.")) : null;
        $this->accounts->save($actor, new Account, ['code' => self::has($row, 'code') ? trim($row['code']) : self::nextCode($type), 'name' => $name, 'type' => $type,
            'parent_id' => $parent?->id, 'is_header' => self::has($row, 'is_header') && self::bool($row['is_header'], 'is_header'), 'is_active' => true,
            'description' => $row['description'] ?? null]);

        return 'created';
    }

    public static function type(string $value): string
    {
        $k = trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]+/', ' ', strtolower($value))));

        return self::TYPE_NAMES[$k] ?? throw new DomainException("type: “{$value}” is not an account type we know (asset, liability, equity, income, expense).");
    }

    private static function nextCode(string $type): string
    {
        [$from, $to] = self::RANGES[$type];
        $max = Account::pluck('code')->filter(fn ($c) => ctype_digit($c) && (int) $c >= $from && (int) $c <= $to)->map(fn ($c) => (int) $c)->max();
        $next = $max ? $max + 1 : $from + 900;
        if ($next > $to) {
            throw new DomainException('No free account number left for '.Account::TYPES[$type].' accounts: give the code yourself.');
        }

        return (string) $next;
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        foreach (Account::with('parent')->orderBy('code')->get() as $a) {
            yield [$a->code, $a->name, $a->type, $a->parent?->code, $a->is_header ? 'yes' : 'no', $a->description];
        }
    }
}

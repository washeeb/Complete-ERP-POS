<?php

namespace App\Domain\Finance;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\TaxRate;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;

/**
 * Changes to the chart of accounts. An account that has postings keeps its type and stays a posting account; a
 * control account stays tied to its sub-ledger. Which account automatic posting uses for a purpose (its system key)
 * can be moved to another account of the same type.
 */
final class AccountService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** Purposes automatic posting needs, with the account type each must be. */
    public static function systemKeys(string $tenantId): array
    {
        $keys = [];
        foreach (ChartTemplate::GHANA_SME as [, $name, $type, , , , $key]) {
            if ($key) {
                $keys[$key] = [$name, $type];
            }
        }
        foreach (TaxRate::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'code') as $code => $name) {
            $keys["output_tax:{$code}"] ??= ["{$name} on sales", 'liability'];
            $keys["input_tax:{$code}"] ??= ["{$name} on purchases", 'asset'];
        }

        return $keys;
    }

    /** @param array{code: string, name: string, type: string, parent_id?: ?string, is_header?: bool, system_key?: ?string, is_active?: bool, description?: ?string} $data */
    public function save(Actor $actor, Account $account, array $data): Account
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.accounts.manage')) {
            throw new AccessDenied;
        }
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if (! preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $code) || $name === '') {
            throw new DomainException('Enter a code (letters, digits, dots or dashes) and a name.');
        }
        if (! array_key_exists($data['type'] ?? '', Account::TYPES)) {
            throw new DomainException('Choose the account type.');
        }
        $hasLines = $account->exists && DB::table('journal_lines')->where('account_id', $account->id)->exists();
        $header = (bool) ($data['is_header'] ?? false);
        if ($hasLines && ($data['type'] !== $account->type || $header)) {
            throw new DomainException('This account has postings: its type cannot change and it cannot become a heading.');
        }
        if ($account->control && ($data['type'] !== $account->type || $header || ! ($data['is_active'] ?? true))) {
            throw new DomainException('This account belongs to a sub-ledger and must stay an active posting account of the same type.');
        }
        $parent = ! empty($data['parent_id']) ? Account::find($data['parent_id']) : null;
        if ($parent && (! $parent->is_header || $parent->id === $account->id)) {
            throw new DomainException('Group an account under a heading account.');
        }
        $key = ($data['system_key'] ?? null) ?: null;
        if ($key) {
            $known = self::systemKeys($actor->tenantId);
            if (! isset($known[$key])) {
                throw new DomainException('Unknown purpose.');
            }
            if ($known[$key][1] !== $data['type'] || $header) {
                throw new DomainException("“{$known[$key][0]}” needs a posting account of type ".Account::TYPES[$known[$key][1]].'.');
            }
        }
        if ($account->system_key && $account->system_key !== $key && in_array($account->system_key, ['receivables', 'payables', 'inventory', 'stored_value', 'loyalty'], true)) {
            throw new DomainException('A sub-ledger account keeps its purpose.');
        }
        if ($key && ! ($data['is_active'] ?? true)) {
            throw new DomainException('Move its purpose to another account before deactivating it.');
        }

        return $this->recorder->transaction(function () use ($actor, $account, $data, $code, $name, $header, $parent, $key) {
            if (Account::where('code', $code)->when($account->exists, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
                throw new DomainException("Account code {$code} is already used.");
            }
            if ($key && ($other = Account::where('system_key', $key)->when($account->exists, fn ($q) => $q->whereKeyNot($account->id))->first())) {
                $other->system_key = null; // the purpose moves to this account
                $this->recorder->save($actor, $other, 'account.updated', "Purpose {$key} moved to {$code}");
            }
            $account->forceFill(['tenant_id' => $account->tenant_id ?? $actor->tenantId, 'code' => $code, 'name' => mb_substr($name, 0, 255), 'type' => $data['type'],
                'parent_id' => $parent?->id, 'is_header' => $header, 'system_key' => $key, 'is_active' => (bool) ($data['is_active'] ?? true),
                'description' => ($data['description'] ?? null) ?: null]);

            return $this->recorder->save($actor, $account, $account->exists ? 'account.updated' : 'account.created');
        });
    }
}

<?php

namespace App\Filament\Support;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\RequestMemo;

/** Shared bits for the finance screens. */
final class FinanceUi
{
    public static function currency(): string
    {
        $tenantId = Erp::user()->tenant_id;

        return RequestMemo::get('currency:'.$tenantId, fn () => Tenant::find($tenantId)->currency_code); // once per request, not per row
    }

    public static function money(?int $minor): string
    {
        return $minor === null ? '—' : Money::display($minor, self::currency());
    }

    public static function toMinor(string|int|float|null $amount): int
    {
        return $amount === null || $amount === '' ? 0 : Money::toMinor((string) $amount, self::currency());
    }

    /** Posting accounts, "1110 Cash on hand – tills"; optionally without sub-ledger (control) accounts. */
    public static function accounts(bool $withControl = true, ?array $types = null): array
    {
        return Account::where('is_header', false)->where('is_active', true)->when(! $withControl, fn ($q) => $q->whereNull('control'))
            ->when($types, fn ($q) => $q->whereIn('type', $types))->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all();
    }

    /** Cash, bank and mobile money accounts (under the same heading as the bank account). */
    public static function moneyAccounts(): array
    {
        $parent = Account::where('system_key', 'bank')->value('parent_id');

        return Account::where('is_header', false)->where('is_active', true)->whereNull('control')->where('type', 'asset')
            ->when($parent, fn ($q) => $q->where('parent_id', $parent))->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all();
    }

    public static function branches(): array
    {
        return Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all();
    }

    public static function hq(): ?string
    {
        return Branch::where('is_hq', true)->value('id');
    }
}

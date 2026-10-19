<?php

namespace App\Filament\Vendor;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/** Small helpers for the licence generator screens. */
final class VendorUi
{
    public static function money(?int $minor, ?string $currency = 'GHS'): string
    {
        return $minor === null ? '—' : Money::display($minor, $currency ?: 'GHS');
    }

    public static function toMinor(mixed $amount, string $currency = 'GHS'): int
    {
        return $amount === null || $amount === '' ? 0 : Money::toMinor((string) $amount, $currency);
    }

    /** Your own password again before anything that signs or withdraws a licence (sudo mode). */
    public static function confirmPassword(): TextInput
    {
        return TextInput::make('confirm_password')->label('Your password (to confirm)')->password()->revealable()->required()->rule('current_password')
            ->dehydrated(false);
    }

    /** Run a vendor action as the signed-in person; a refusal is shown, not thrown. */
    public static function run(callable $call, ?string $success = null): mixed
    {
        try {
            $result = $call(Actor::user(auth()->user()));
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
        if ($success) {
            Notification::make()->success()->title($success)->send();
        }

        return $result;
    }

    /** @return list<string>|null from a "everything" toggle and a list */
    public static function listOrAll(bool $all, ?array $list): ?array
    {
        return $all ? null : array_values($list ?? []);
    }
}

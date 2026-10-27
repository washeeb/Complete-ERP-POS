<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
use App\Filament\Support\MiniTable;
use App\Support\People;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(AccessService::class)->updateUser($actor, $record, $data, $data['reason'] ?? null));
    }

    protected function getHeaderActions(): array
    {
        return [
            // Every rename of the account (name or username), so a re-used account is visible.
            Action::make('nameHistory')->label('Name history')->icon(Heroicon::OutlinedClock)->color('gray')
                ->modalHeading(fn () => 'Name history: '.$this->record->name)->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => MiniTable::html(['When', 'Changed by', 'Name', 'Username'], array_map(fn ($h) => [$h['at'], $h['by'],
                    $h['name'] ? "{$h['name'][0]} → {$h['name'][1]}" : '—', $h['username'] ? "{$h['username'][0]} → {$h['username'][1]}" : '—'],
                    People::history($this->record->id)), [], 'This account has never been renamed.')),
            Action::make('setPin')->label('Set POS PIN')->icon(Heroicon::OutlinedKey)
                ->modalDescription('4–8 digits. Tell the person privately; they sign in on a till with their username and this PIN. Setting a PIN also clears a PIN lockout.')
                ->schema([
                    TextInput::make('pin')->label('New PIN')->password()->revealable()->autocomplete('new-password')->required()->regex('/^\d{4,8}$/'),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(AccessService::class)
                    ->setPin($actor, $this->record, $data['pin']), 'PIN set')),
            // A lost or deleted authenticator app: cleared here, set up again by the person at their next sign-in.
            Action::make('resetMfa')->label('Reset two-step sign-in')->icon(Heroicon::OutlinedShieldExclamation)->color('danger')
                ->visible(fn () => $this->record->app_authentication_secret !== null && $this->record->id !== Erp::user()->id)
                ->modalDescription('For someone who lost their phone or deleted the app. Their authenticator and recovery codes are cleared and they are signed out; '
                    .'at their next sign-in (username and password) they scan a new code. Check it is really them first, by phone or in person.')
                ->schema([TextInput::make('reason')->label('Why (kept in the audit log)')->required()->maxLength(255)])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(AccessService::class)
                    ->resetMfa($actor, $this->record, $data['reason']) ?? true, 'Two-step sign-in reset')),
        ];
    }
}

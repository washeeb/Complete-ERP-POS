<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
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
            Action::make('setPin')->label('Set POS PIN')->icon(Heroicon::OutlinedKey)
                ->modalDescription('4–8 digits. Tell the person privately; they sign in on a till with their username and this PIN. Setting a PIN also clears a PIN lockout.')
                ->schema([
                    TextInput::make('pin')->label('New PIN')->password()->revealable()->autocomplete('new-password')->required()->regex('/^\d{4,8}$/'),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(AccessService::class)
                    ->setPin($actor, $this->record, $data['pin']), 'PIN set')),
        ];
    }
}

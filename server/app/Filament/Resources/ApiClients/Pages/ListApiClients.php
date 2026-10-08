<?php

namespace App\Filament\Resources\ApiClients\Pages;

use App\Domain\Api\ApiClients;
use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    public function getSubheading(): ?string
    {
        return 'API description for developers: '.url('/api/public/v1/openapi.json');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New API client')->icon(Heroicon::OutlinedPlus)
                ->modalDescription('The key acts as the person you choose: it never sees more than they may. Tip: create a user just for this system (e.g. "Shop website") with a narrow role.')
                ->schema([
                    TextInput::make('name')->label('System (e.g. "Website", "Delivery partner")')->required()->maxLength(120),
                    CheckboxList::make('scopes')->options(array_map(fn ($s) => $s[0], ApiClients::SCOPES))->required()->columns(2),
                    Select::make('user_id')->label('Acts as')->required()->searchable()->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    Select::make('branch_id')->label('Only this branch (optional)')->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
                ])
                ->action(function (array $data) {
                    $r = Erp::run(fn ($actor) => app(ApiClients::class)->create($actor, $data), 'API client created');
                    if ($r) {
                        ApiClientResource::showKey($r[0]->name, $r[1]);
                    }
                }),
        ];
    }
}

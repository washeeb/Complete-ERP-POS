<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Domain\Org\OrgService;
use App\Filament\Support\ArchiveUi;
use App\Filament\Support\Erp;
use App\Models\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WarehousesRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouses';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false)->placeholder('Given automatically when saved'),
            TextInput::make('name')->required()->maxLength(120),
        ]);
    }

    public function table(Table $table): Table
    {
        return ArchiveUi::apply($table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
            ])
            ->pushHeaderActions([
                CreateAction::make()->using(fn (array $data): Model => Erp::run(fn ($actor) => app(OrgService::class)
                    ->saveWarehouse($actor, new Warehouse, $data + ['branch_id' => $this->getOwnerRecord()->id]))),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Warehouse $record, array $data): Model => Erp::run(fn ($actor) => app(OrgService::class)
                    ->saveWarehouse($actor, $record, $data))),
            ]));
    }
}

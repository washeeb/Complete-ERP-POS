<?php

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Domain\Org\OrgService;
use App\Filament\Support\ArchiveUi;
use App\Filament\Support\Erp;
use App\Models\Till;
use App\Models\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TillsRelationManager extends RelationManager
{
    protected static string $relationship = 'tills';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->disabled()->dehydrated(false)->placeholder('Given automatically when saved')
                ->helperText('Appears in receipt numbers, e.g. T01'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('warehouse_id')->label('Sells stock from')->required()
                ->options(fn () => Warehouse::where('branch_id', $this->getOwnerRecord()->id)->pluck('name', 'id')),
        ]);
    }

    public function table(Table $table): Table
    {
        return ArchiveUi::apply($table
            ->columns([
                TextColumn::make('code')->sortable(),
                TextColumn::make('name'),
                TextColumn::make('warehouse.name')->label('Stock from'),
            ])
            ->defaultSort('code')
            ->pushHeaderActions([
                CreateAction::make()->using(fn (array $data): Model => Erp::run(fn ($actor) => app(OrgService::class)
                    ->saveTill($actor, new Till, $data + ['branch_id' => $this->getOwnerRecord()->id]))),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Till $record, array $data): Model => Erp::run(fn ($actor) => app(OrgService::class)
                    ->saveTill($actor, $record, $data))),
            ]));
    }
}

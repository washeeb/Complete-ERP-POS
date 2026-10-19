<?php

namespace App\Filament\Resources\Uoms;

use App\Filament\Resources\Uoms\Pages\ListUoms;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\ProductUom;
use App\Models\Uom;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UomResource extends SimpleCatalogResource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Code' => $record->code]);
    }

    protected static ?string $model = Uom::class;

    protected static bool $deletable = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Units of measure';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(10)->regex('/^[A-Za-z0-9]{1,10}$/')->disabledOn('edit')
                ->helperText('Short code printed on receipts, e.g. PC, CTN, KG'),
            TextInput::make('name')->required()->maxLength(60),
            Select::make('decimals')->label('Quantity decimals')->required()->default(0)
                ->options([0 => '0 — whole units only', 1 => '1', 2 => '2', 3 => '3 — e.g. 1.250 kg'])
                ->helperText('How precisely this unit can be sold or counted.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([
            TextColumn::make('code')->sortable()->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('decimals')->label('Decimals'),
            TextColumn::make('products_count')->label('Products')->state(fn ($record) => ProductUom::where('uom_id', $record->id)->distinct()->count('product_id')),
        ])->defaultSort('code'));
    }

    public static function getPages(): array
    {
        return ['index' => ListUoms::route('/')];
    }
}
